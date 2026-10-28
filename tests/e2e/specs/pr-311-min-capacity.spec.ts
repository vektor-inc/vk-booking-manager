/**
 * PR #311: サービスメニューに「最小催行人数（グループ開催型）」設定を追加するテスト
 *
 * 同じ時間枠の合計予約人数が最小催行人数に達したら「開催決定」とみなす表示専用機能。
 * Pro版・指名OFF・複数人予約ON のときだけ有効。既定 0 は制約なし（完全後方互換）。
 *
 * PR #311: Tests for the "minimum participants to confirm" (group-session) setting.
 *
 * このスペックは大きく 4 つの観点を検証する:
 *  1. 管理UI: サービスメニュー編集での最小催行人数欄の表示／保存／クランプ／ゲート
 *  2. フロント表示: 空きスロットの「あと N 名で開催」「開催決定」バッジ（REST + 実機ブラウザ）
 *  3. 管理ダッシュボード: 未達枠の可視化と既存ステータス色の非破壊（CodeRabbit #1）／
 *     別開始時刻セッションの非合算（CodeRabbit #2）
 *  4. 後方互換: 最小0／指名ON では催行関連の表示・欄が一切出ない
 *
 * 確定画面の注記（CodeRabbit #3）は availability_service が返す min_capacity/booked_guests に
 * 対する PHP ロジック（BookingConfirmApp の showMinCapacityNote 相当）を REST レスポンス検証で確認する。
 */
import { test, expect, type Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getServiceMenuId,
	getStaffId,
	loginAsAdmin,
	extractOpeningTagById,
	extractElementTextById,
} from '../utils/helpers';

/**
 * render_conditions_meta_box の HTML 出力を WP-CLI 経由で取得するヘルパー。
 * 指名・複数人予約の有効/無効を切り替えた後の管理UI出力を文字列で検証する。
 *
 * @param menuId サービスメニューの投稿ID（数値文字列）
 * @return メタボックスの HTML 出力
 */
function getConditionsMetaboxHtml( menuId: string ): string {
	const phpCode = `
		set_current_screen( 'post' );
		$post = get_post( ${ menuId } );
		if ( ! $post ) {
			echo 'ERROR: Post not found';
			return;
		}
		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		ob_start();
		$editor->render_conditions_meta_box( $post );
		echo ob_get_clean();
	`;
	return wpEvalPhp( phpCode );
}

/**
 * provider 設定（指名・複数人予約）をまとめて切り替えるヘルパー。
 * Staff_Editor のキャッシュは WP-CLI eval が毎回新プロセスのため気にしなくてよい。
 *
 * @param staffEnabled          指名機能（true で有効）
 * @param multipleGuestsEnabled 複数人予約機能（true で有効）
 */
function setProviderGates(
	staffEnabled: boolean,
	multipleGuestsEnabled: boolean
): void {
	const staff = staffEnabled ? 1 : 0;
	const multi = multipleGuestsEnabled ? 1 : 0;
	wpEvalPhp(
		`
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['staff_enabled'] = ${ staff };
		// 呼称統一（#326）で親スイッチは新キー slot_capacity_enabled に改名。
		// 本番サニタイザは旧キーを消して新キー一本にするため、テストも新キーで制御し旧キーは残さない。
		$s['slot_capacity_enabled'] = ${ multi };
		unset( $s['multiple_guests_enabled'] );
		update_option( 'vkbm_provider_settings', $s );
		`
	);
}

/**
 * 実際の Service_Menu_Editor::save_post() を経由してメニュー設定を保存するヘルパー。
 *
 * テスト側でクランプ式を再現してしまうと「製品のクランプ実装」を検証できないため、
 * 本物の save_post() を呼ぶ。save_post は nonce・権限・$_POST を参照するので、
 * 管理ユーザーになりすまし・正しい nonce を作成・$_POST を組み立ててから実行する。
 * 入力値はすべて整数化して PHP リテラルに埋め込む（PHP 脱出リスクを排除）。
 *
 * @param menuId        サービスメニューの投稿ID（数値文字列）
 * @param maxCapacity   最大受付数（入力値）
 * @param minCapacity   最小催行人数（クランプ前の入力値）
 * @param allowMulti    メニュー個別の複数人一括予約許可フラグ
 * @param useNomination このメニューで指名を使うか（チェックボックス相当）。
 *                      save_post は未送信を「使わない」として解釈し
 *                      _vkbm_disable_nomination を立てるため、指名ONの状態を
 *                      検証したい呼び出し側は明示的に true を渡すこと。
 * @return 保存後の _vkbm_min_capacity の値（数値文字列）
 */
function savePostViaEditor(
	menuId: string,
	maxCapacity: number,
	minCapacity: number,
	allowMulti: boolean,
	useNomination: boolean
): string {
	const max = Number.parseInt( String( maxCapacity ), 10 );
	const min = Number.parseInt( String( minCapacity ), 10 );
	const id = Number.parseInt( menuId, 10 );
	const allowLine = allowMulti
		? "$_POST['vkbm_service_menu']['allow_multiple_guests'] = '1';"
		: '';
	// サイト全体の指名機能がOFFのときはこのフィールド自体がフォームに存在せず
	// save_post 側もこの値を読まないため、site全体OFFの呼び出しでは無害。
	const nominationLine = useNomination
		? "$_POST['vkbm_service_menu']['use_nomination'] = '1';"
		: '';
	const phpCode = `
		// 管理ユーザーになりすまし（current_user_can チェックを満たす）。
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( empty( $admin ) ) { echo 'ERROR:no-admin'; return; }
		wp_set_current_user( $admin[0]->ID );

		// 本物の save_post が検証する nonce を生成して $_POST に入れる。
		// NONCE_NAME / NONCE_ACTION は private 定数のためリフレクションで取得する。
		$ref_class   = new ReflectionClass( '\\\\VKBookingManager\\\\Admin\\\\Service_Menu_Editor' );
		$nonce_name  = $ref_class->getConstant( 'NONCE_NAME' );
		$nonce_action = $ref_class->getConstant( 'NONCE_ACTION' );

		// 既存の staff_ids を維持する（save_post は staff_ids も保存し、
		// $_POST に無いと空配列で上書きされてフロントの予約が壊れるため）。
		$existing_staff_ids = get_post_meta( ${ id }, '_vkbm_staff_ids', true );
		if ( ! is_array( $existing_staff_ids ) ) { $existing_staff_ids = array(); }

		$_POST = array();
		$_POST[ $nonce_name ] = wp_create_nonce( $nonce_action );
		$_POST['vkbm_service_menu'] = array(
			'max_capacity' => '${ max }',
			'min_capacity' => '${ min }',
			'staff_ids'    => array_map( 'strval', $existing_staff_ids ),
		);
		${ allowLine }
		${ nominationLine }

		$post   = get_post( ${ id } );
		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		$editor->save_post( ${ id }, $post );

		echo (int) get_post_meta( ${ id }, '_vkbm_min_capacity', true );
	`;
	return wpEvalPhp( phpCode );
}

test.describe( 'PR #311: 最小催行人数 - 管理UI（欄表示・保存・クランプ・ゲート）', () => {
	// 各テスト後、provider ゲートを「指名OFF・複数人予約ON」（=有効になる構成）に戻す。
	test.afterEach( () => {
		setProviderGates( false, true );
	} );

	test( '指名OFF・複数人予約ON のとき: 最大受付数欄の直下に最小催行人数欄が出る', () => {
		// --- 準備: 指名OFF・複数人予約ON（最小催行人数が有効になる構成） ---
		setProviderGates( false, true );
		const menuId = getServiceMenuId();

		const html = getConditionsMetaboxHtml( menuId );

		// 最小催行人数の入力欄が出力されていること
		expect( html ).toContain( 'id="vkbm_service_menu_min_capacity"' );
		expect( html ).toContain(
			'name="vkbm_service_menu[min_capacity]"'
		);

		// 最大受付数欄も同時に出ていること（直下に配置される前提）
		expect( html ).toContain( 'id="vkbm_service_menu_max_capacity"' );

		// 配置順: 最大受付数欄 → 最小催行人数欄 の順で出力されること（直下確認）
		const maxIndex = html.indexOf(
			'id="vkbm_service_menu_max_capacity"'
		);
		const minIndex = html.indexOf(
			'id="vkbm_service_menu_min_capacity"'
		);
		expect( maxIndex ).toBeGreaterThan( -1 );
		expect( minIndex ).toBeGreaterThan( maxIndex );

		// 「0=制約なし」の説明文（HTML 構造で確認）
		expect( html ).toContain( 'class="description"' );
		// min="0" の number 入力であること
		expect( html ).toMatch(
			/id="vkbm_service_menu_min_capacity"[^>]*min="0"/
		);
	} );

	test( '最大5・最小3 を保存すると 3 が保持される', () => {
		setProviderGates( false, true );
		const menuId = getServiceMenuId();

		// 指名機能は site 全体で OFF（setProviderGates( false, true )）のため
		// use_nomination はこの save_post 呼び出しに影響しないが、呼び出し規約を
		// 統一するため明示的に指定する。
		const saved = savePostViaEditor( menuId, 5, 3, true, true );
		// クランプされず 3 がそのまま保存される
		expect( saved ).toBe( '3' );

		// 管理UIの value 属性にも 3 が反映される
		const html = getConditionsMetaboxHtml( menuId );
		expect( html ).toMatch(
			/id="vkbm_service_menu_min_capacity"[^>]*value="3"/
		);
	} );

	test( '最大5・最小10（超過）を保存すると 5 にクランプされる', () => {
		setProviderGates( false, true );
		const menuId = getServiceMenuId();

		// 指名機能は site 全体で OFF（setProviderGates( false, true )）のため
		// use_nomination はこの save_post 呼び出しに影響しないが、呼び出し規約を
		// 統一するため明示的に指定する。
		const saved = savePostViaEditor( menuId, 5, 10, true, true );
		// 最大受付数 5 を上限にクランプされる
		expect( saved ).toBe( '5' );

		const html = getConditionsMetaboxHtml( menuId );
		expect( html ).toMatch(
			/id="vkbm_service_menu_min_capacity"[^>]*value="5"/
		);
	} );

	test( '指名ON・複数人一括予約ON・定員2以上のとき: 最少催行人数／最低申し込み人数欄が表示される（#393）', () => {
		// --- 準備: 指名ON・複数人一括予約ON・定員3（#393の受付制限が有効になる構成） ---
		// #393: この欄は、指名を使うメニューでは「1組の最低人数（受付制限）」として意味を持つため、
		// #392時点の「指名ONなら常にhidden」という挙動は変わった。表示条件は指名の有無ではなく
		// 「複数人一括予約ON かつ 定員2以上」のみになった（class-service-menu-editor.php の
		// $show_min_capacity_field を参照）。
		setProviderGates( true, true );
		const menuId = getServiceMenuId();
		// このテストの前提は「指名ON」なので、use_nomination を明示的に送る。
		// save_post は未送信を「使わない」と解釈して _vkbm_disable_nomination を
		// 立ててしまうため、送らないとこのメニュー自身は指名OFF扱いになり、
		// 「指名ONのラベル」を検証しているつもりが実際は指名OFF状態を検証して
		// しまう（実装漏れ。麗美の e2e で判明）。
		savePostViaEditor( menuId, 3, 0, true, true );

		const html = getConditionsMetaboxHtml( menuId );

		// #393: 最少催行人数／最低申し込み人数欄は、指名の有無に関わらず
		// 「複数人一括予約ON かつ 定員2以上」なら表示される（hidden が付かない）。
		expect(
			extractOpeningTagById( html, 'vkbm-min-capacity-field' )
		).not.toContain( 'hidden' );

		// #393: 指名を使うメニューではラベルが「最低申し込み人数」に切り替わる
		// （id="vkbm-min-capacity-label" の中身。テストサイトは日本語ロケールのため、
		// __() の翻訳後テキストで検証する）。
		// 同欄の説明文には両方のラベル名（「最少催行人数」「最低申し込み人数」）が
		// 出るため、HTML 全体ではなく見出し要素（id="vkbm-min-capacity-label"）の
		// 中身だけに絞って判定する。
		const minCapacityLabel = extractElementTextById(
			html,
			'vkbm-min-capacity-label'
		);
		expect( minCapacityLabel ).toContain( '最低申し込み人数' );
		expect( minCapacityLabel ).not.toContain( '最少催行人数' );

		// #392: 最大受付数（予約枠の定員）欄は、指名を使うメニューでも「1組の最大人数」として
		// 使われるため、指名ONでも hidden が付かず表示される（#392より前は hidden が付いていた）。
		// #392: the max capacity (time slot capacity) field is now used as the
		// "max group size per booking" even for nomination-enabled menus, so it
		// is no longer hidden when nomination is on (unlike before #392).
		const maxCapacityTag = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTag ).not.toBe( '' );
		expect( maxCapacityTag ).not.toContain( 'hidden' );

		// #392: 旧仕様（PR #163・#412）の案内メッセージ要素（vkbm-nomination-enabled-notice）は
		// 撤去済みで、DOM に存在しない。
		// #392: the old guidance message element has been removed entirely and
		// is absent from the DOM.
		expect(
			extractOpeningTagById( html, 'vkbm-nomination-enabled-notice' )
		).toBe( '' );
	} );

	test( '指名ON・複数人一括予約OFF のとき: 最少催行人数／最低申し込み人数欄は非表示のまま（#393）', () => {
		// #393: 複数人一括予約OFFのメニューは申込人数が常に1名固定になるため、指名の有無に
		// 関わらずこの欄は表示しない（表示条件「複数人一括予約ON かつ 定員2以上」が未達のため）。
		setProviderGates( true, true );
		const menuId = getServiceMenuId();
		// このテストの前提も「指名ON」なので use_nomination を明示的に送る
		// （このテストの hidden 判定自体は allowMulti=false による「複数人一括予約OFF」
		// が理由で、指名の有無では変わらないため、送らなくても本テストは偶然通って
		// しまう。ただしタイトルどおり「指名ON」を実際に検証する状態にするため
		// 明示する）。
		savePostViaEditor( menuId, 3, 0, false, true );

		const html = getConditionsMetaboxHtml( menuId );

		expect(
			extractOpeningTagById( html, 'vkbm-min-capacity-field' )
		).toContain( 'hidden' );
	} );

	test( '予約枠の定員機能（サイト全体の親スイッチ）OFF のとき: 最小催行人数欄が出ない', () => {
		// #412 F-6: このテストは _vkbm_allow_multiple_guests（メニュー単位）ではなく、
		// setProviderGates() 第2引数＝サイト全体の親スイッチ slot_capacity_enabled を
		// OFF にする構成。この場合はフィールドが「常時描画+hidden」方式の対象外の分岐
		// （render_conditions_meta_box() の外側のif）に入り、フィールド自体が DOM から
		// 完全に消えるため、他の hidden 属性ベースの検証とは異なり not.toContain のままでよい。
		// --- 準備: 指名OFF・予約枠の定員機能OFF（最小催行人数が無効になる構成） ---
		setProviderGates( false, false );
		const menuId = getServiceMenuId();

		const html = getConditionsMetaboxHtml( menuId );

		// 最小催行人数欄が出力されないこと
		expect( html ).not.toContain( 'id="vkbm_service_menu_min_capacity"' );
		// 最大受付数欄も案内メッセージに置き換わる既存仕様
		expect( html ).not.toContain( 'id="vkbm_service_menu_max_capacity"' );
	} );
} );

test.describe( 'PR #311: 最小催行人数 - 催行状態ロジック（VKBM_Helper / availability）', () => {
	test( 'get_min_capacity_status: none / pending / fulfilled を正しく返す', () => {
		// 制約なし（min=0）
		const none = wpEvalPhp(
			`echo \\VKBookingManager\\Common\\VKBM_Helper::get_min_capacity_status( 0, 2 )['state'];`
		);
		expect( none ).toBe( 'none' );

		// 未達（min=3, booked=2）→ pending, shortfall=1
		const pendingState = wpEvalPhp(
			`echo \\VKBookingManager\\Common\\VKBM_Helper::get_min_capacity_status( 3, 2 )['state'];`
		);
		expect( pendingState ).toBe( 'pending' );
		const shortfall = wpEvalPhp(
			`echo \\VKBookingManager\\Common\\VKBM_Helper::get_min_capacity_status( 3, 2 )['shortfall'];`
		);
		expect( shortfall ).toBe( '1' );

		// 達成（min=3, booked=3）→ fulfilled, shortfall=0
		const fulfilled = wpEvalPhp(
			`echo \\VKBookingManager\\Common\\VKBM_Helper::get_min_capacity_status( 3, 3 )['state'];`
		);
		expect( fulfilled ).toBe( 'fulfilled' );
	} );
} );

/**
 * フロント表示テスト用の共通定数とヘルパー。
 *
 * 既存スタッフ・メニュー・シフトを使い、対象メニューを「Pro版・指名OFF・複数人予約ON・
 * 最大5・最小3」に設定して、空きスロットの催行状態（あと N 名／開催決定）を検証する。
 * 予約は WP-CLI で vkbm_booking を直接作成して投入する（DB 破壊操作は行わない）。
 */

/**
 * フロント検証で使う対象日（東京時刻の「翌月1日」）を動的に算出する。
 *
 * 日付をハードコードすると、その月を過ぎた時点で過去日になり、予約締切・
 * 当日以降のみ予約可といったゲートに掛かってフロント検証ケースが恒常的に
 * FAIL する時限バグになる。これを避けるため、実行時の東京時刻から翌月1日を
 * 動的に計算する。
 * - 当月末日に実行しても「翌月1日」なら確実に未来日になる（当日除外ゲートを回避）。
 * - 翌月のため、カレンダー UI は現在月から「次の月」へ1回送れば必ず到達できる。
 * - 当月分しかシフトが無い環境を考慮し、対象月のシフトはテスト側で必ず用意する
 *   （後述の ensureShiftForMonth を beforeAll で呼ぶ）。
 *
 * @return year/month（翌月）と ISO 日付（翌月1日, YYYY-MM-DD）。
 */
function resolveNextMonthFirstDay(): {
	year: number;
	month: number;
	iso: string;
} {
	// 実行時点の東京時刻の「当月」を取得する（ロケール表記に依存しないよう数値で取る）。
	const parts = new Intl.DateTimeFormat( 'en', {
		timeZone: 'Asia/Tokyo',
		year: 'numeric',
		month: 'numeric',
	} ).formatToParts( new Date() );
	const curYear = Number(
		parts.find( ( p ) => p.type === 'year' )?.value
	);
	// month は 1-12。Date.UTC は 0-11 なので、当月の数値（1-12）をそのまま
	// 月インデックスに渡すと「翌月」を指す（例: 当月7 → index 7 = 8月ではなく、
	// 当月7を 0-based に直すと 6、+1 して 7 = 翌月の 0-based index）。
	const curMonth = Number(
		parts.find( ( p ) => p.type === 'month' )?.value
	);

	// 当月(1-12)を 0-based(curMonth-1) に直し +1 した index で「翌月1日」を作る。
	// Date.UTC は 12（範囲外）を翌年1月へ正しく繰り上げてくれる。
	const nextFirst = new Date( Date.UTC( curYear, curMonth, 1 ) );
	const year = nextFirst.getUTCFullYear();
	const month = nextFirst.getUTCMonth() + 1; // 1-12 に戻す
	const iso = `${ year }-${ String( month ).padStart( 2, '0' ) }-01`;
	return { year, month, iso };
}

// フロント検証で使う対象日（東京時刻の翌月1日）。実行時に動的算出する。
const NEXT_MONTH_FIRST = resolveNextMonthFirstDay();
const FRONT_TEST_DATE = NEXT_MONTH_FIRST.iso;
const FRONT_TEST_YEAR = NEXT_MONTH_FIRST.year;
const FRONT_TEST_MONTH = NEXT_MONTH_FIRST.month;
const FRONT_BASE_HOUR_FULFILLED = '09:00'; // 充足させる枠
const FRONT_BASE_HOUR_PENDING = '11:00'; // 未達のまま残す枠

/**
 * 対象メニューを「最小催行人数が有効になる構成」にセットアップするヘルパー。
 * provider ゲート（指名OFF・複数人予約ON）＋メニューメタ（最大5・最小3・複数人予約ON）。
 *
 * @param menuId  サービスメニューの投稿ID
 * @param staffId 割り当てるスタッフの投稿ID
 */
function setupGroupSessionMenu( menuId: string, staffId: string ): void {
	const id = Number.parseInt( menuId, 10 );
	const sid = Number.parseInt( staffId, 10 );
	const phpCode = `
		// provider ゲート: 指名OFF・予約枠の定員ON（#326 で新キー slot_capacity_enabled に改名）
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['staff_enabled'] = 0;
		$s['slot_capacity_enabled'] = 1;
		unset( $s['multiple_guests_enabled'] );
		update_option( 'vkbm_provider_settings', $s );

		// メニュー: 最大5・最小3・複数人予約ON・所要60分・スタッフ割当・締切/事前制限なし
		update_post_meta( ${ id }, '_vkbm_max_capacity', 5 );
		update_post_meta( ${ id }, '_vkbm_min_capacity', 3 );
		update_post_meta( ${ id }, '_vkbm_allow_multiple_guests', '1' );
		update_post_meta( ${ id }, '_vkbm_duration_minutes', 60 );
		update_post_meta( ${ id }, '_vkbm_reservation_deadline_hours', 0 );
		update_post_meta( ${ id }, '_vkbm_max_advance_booking_days', 0 );
		update_post_meta( ${ id }, '_vkbm_staff_ids', array( ${ sid } ) );
		delete_post_meta( ${ id }, '_vkbm_fixed_start_times' );
		echo 'ok';
	`;
	wpEvalPhp( phpCode );
}

/**
 * 対象月のシフト（毎日 09:00-18:00 open）を用意するヘルパー。
 * 既存があれば更新、なければ作成する（global-setup は当月分しか作らないため翌月分を補う）。
 *
 * @param staffId スタッフの投稿ID
 * @param year    対象年
 * @param month   対象月（1-12）
 */
function ensureShiftForMonth(
	staffId: string,
	year: number,
	month: number
): void {
	const sid = Number.parseInt( staffId, 10 );
	const y = Number.parseInt( String( year ), 10 );
	const m = Number.parseInt( String( month ), 10 );
	const phpCode = `
		$resource_id = ${ sid };
		$year = ${ y };
		$month = ${ m };
		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$days = array();
		for ( $d = 1; $d <= $days_in_month; $d++ ) {
			$days[ $d ] = array(
				'status' => 'open',
				'slots'  => array( array( 'start' => '09:00', 'end' => '18:00' ) ),
			);
		}
		$existing = get_posts( array(
			'post_type'   => 'vkbm_shift',
			'post_status' => 'any',
			'meta_query'  => array(
				array( 'key' => '_vkbm_shift_resource_id', 'value' => $resource_id ),
				array( 'key' => '_vkbm_shift_year', 'value' => $year ),
				array( 'key' => '_vkbm_shift_month', 'value' => $month ),
			),
			'fields'      => 'ids',
		) );
		if ( ! empty( $existing ) ) {
			$post_id = $existing[0];
			update_post_meta( $post_id, '_vkbm_shift_days', $days );
		} else {
			$post_id = wp_insert_post( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'publish',
				'post_title'  => sprintf( '%d-%02d Staff %d', $year, $month, $resource_id ),
			) );
			update_post_meta( $post_id, '_vkbm_shift_resource_id', $resource_id );
			update_post_meta( $post_id, '_vkbm_shift_year', $year );
			update_post_meta( $post_id, '_vkbm_shift_month', $month );
			update_post_meta( $post_id, '_vkbm_shift_days', $days );
		}
		echo $post_id;
	`;
	wpEvalPhp( phpCode );
}

/**
 * availability などのトランジェントキャッシュを全消去する PHP 断片（`wp transient delete --all` と同じ範囲）。
 * 予約の投入・削除と同じ WP-CLI 呼び出しの中で実行し、wp-env の呼び出し回数
 * （1 回あたり数秒かかり、重い環境ではテスト 1 本の制限時間の大半を占める）を減らす。
 */
const CLEAR_TRANSIENTS_PHP = `
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
		wp_cache_flush();
`;

/**
 * vkbm_booking を1件作成して特定スロットに予約人数を投入するヘルパー。
 * 値はすべて整数・固定書式の文字列のみ埋め込む（PHP 脱出リスクを排除）。
 *
 * @param serviceId  サービスメニューID
 * @param staffId    スタッフID
 * @param date       対象日（Y-m-d）
 * @param startTime  開始時刻（HH:MM）
 * @param endTime    終了時刻（HH:MM）
 * @param guests     予約人数
 * @param status     予約ステータス（confirmed / pending / cancelled / no_show）
 * @return 作成した予約の投稿ID
 */
function createBooking(
	serviceId: string,
	staffId: string,
	date: string,
	startTime: string,
	endTime: string,
	guests: number,
	status: string
): string {
	const svc = Number.parseInt( serviceId, 10 );
	const sid = Number.parseInt( staffId, 10 );
	const g = Number.parseInt( String( guests ), 10 );
	// date / time / status はホワイトリスト的に厳格検証してから埋め込む。
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		throw new Error( `Invalid date: ${ date }` );
	}
	if ( ! /^\d{2}:\d{2}$/.test( startTime ) || ! /^\d{2}:\d{2}$/.test( endTime ) ) {
		throw new Error( `Invalid time: ${ startTime }-${ endTime }` );
	}
	if (
		! [ 'confirmed', 'pending', 'cancelled', 'no_show' ].includes( status )
	) {
		throw new Error( `Invalid status: ${ status }` );
	}
	const phpCode = `
		$bid = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => 'E2E PR311 booking',
		) );
		update_post_meta( $bid, '_vkbm_booking_service_start', '${ date } ${ startTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_service_end', '${ date } ${ endTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_total_end', '${ date } ${ endTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_resource_id', ${ sid } );
		update_post_meta( $bid, '_vkbm_booking_service_id', ${ svc } );
		update_post_meta( $bid, '_vkbm_booking_guests', ${ g } );
		update_post_meta( $bid, '_vkbm_booking_status', '${ status }' );
		${ CLEAR_TRANSIENTS_PHP }
		echo $bid;
	`;
	return wpEvalPhp( phpCode );
}

/**
 * テストで作成した vkbm_booking を全削除するヘルパー（テスト間の干渉防止）。
 */
function deleteAllBookings(): void {
	wpEvalPhp( `
		foreach ( get_posts( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		) ) as $bid ) {
			wp_delete_post( $bid, true );
		}
		${ CLEAR_TRANSIENTS_PHP }
		echo 'ok';
	` );
}

/**
 * availability REST（/vkbm/v1/availabilities）を叩いて指定開始時刻のスロットを返すヘルパー。
 * Availability はトランジェントでキャッシュされるが、キャッシュは予約の投入・削除（createBooking /
 * deleteAllBookings）が同じ WP-CLI 呼び出しの中で消すため、ここでは消さない（nocache も付ける）。
 *
 * @param page       Playwright Page（baseURL 相対の request に使う）
 * @param menuId     サービスメニューID
 * @param date       対象日（Y-m-d）
 * @param startHHMM  取得したいスロットの開始時刻（HH:MM）
 * @return 該当スロットのオブジェクト（無ければ null）
 */
async function fetchSlotByStart(
	page: import('@playwright/test').Page,
	menuId: string,
	date: string,
	startHHMM: string
): Promise< any | null > {
	// トランジェントは予約の投入・削除の側で消去済み。キャッシュを消した直後の初回は、月分の空き状況を計算し直すため重い環境で十数秒かかる
	// （この describe の 1 本あたりの実測は 25〜55 秒）。応答待ちには余裕を持たせる。
	const res = await page.request.get(
		`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ date }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`,
		{ timeout: 90000 }
	);
	const data = await res.json();
	const slots: any[] = Array.isArray( data?.slots ) ? data.slots : [];
	return (
		slots.find( ( s ) =>
			String( s.start_at ).endsWith( `${ startHHMM }:00+09:00` )
		) ?? null
	);
}

test.describe( 'PR #311: 最小催行人数 - フロント空きスロットの催行状態（REST データ）', () => {
	// 予約の投入（WP-CLI）と、キャッシュ消去直後の空き状況の取得が重い環境で長引くため、制限時間に余裕を持たせる。
	test.describe.configure( { timeout: 120000 } );

	let menuId: string;
	let staffId: string;

	test.beforeAll( () => {
		staffId = getStaffId();
		menuId = getServiceMenuId();
		setupGroupSessionMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		deleteAllBookings();
	} );

	test.afterAll( () => {
		deleteAllBookings();
	} );

	test( '未達（2名/最小3）の枠は pending: booked_guests=2 で「あと1名」相当', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 09:00 枠に 2 名（1件2名）を投入
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			2,
			'confirmed'
		);

		const slot = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED
		);
		expect( slot ).not.toBeNull();
		// 催行判定の元データ: min_capacity=3, booked_guests=2 → 不足1（「あと1名で開催」）
		expect( slot.min_capacity ).toBe( 3 );
		expect( slot.booked_guests ).toBe( 2 );
		// 残数は 5 - 2 = 3（満席ではない）
		expect( slot.remaining ).toBe( 3 );
	} );

	test( '充足（合計3名/最小3）の枠は fulfilled: booked_guests=3 で「開催決定」相当', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 09:00 枠に 2 名 + 1 名 = 合計3名
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			2,
			'confirmed'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			1,
			'confirmed'
		);

		const slot = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED
		);
		expect( slot ).not.toBeNull();
		// min_capacity=3, booked_guests=3 → 充足（開催決定）。満席（5）ではない。
		expect( slot.min_capacity ).toBe( 3 );
		expect( slot.booked_guests ).toBe( 3 );
		expect( slot.remaining ).toBe( 2 );
	} );

	test( '別開始時刻の枠は合算されない（CodeRabbit #2 回帰）', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 09:00 枠に 3 名、11:00 枠に 1 名を投入
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			3,
			'confirmed'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_PENDING,
			'12:00',
			1,
			'confirmed'
		);

		const slot09 = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED
		);
		const slot11 = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_PENDING
		);
		// 09:00 は 3 名で充足、11:00 は 1 名のまま（09:00 の人数が混ざらない）
		expect( slot09.booked_guests ).toBe( 3 );
		expect( slot11.booked_guests ).toBe( 1 );
	} );

	test( 'キャンセル/no-show の予約は催行人数に数えない', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 09:00 枠に confirmed 2名 + cancelled 5名 + no_show 5名
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			2,
			'confirmed'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			5,
			'cancelled'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			5,
			'no_show'
		);

		const slot = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED
		);
		// cancelled / no_show は数えないため confirmed の 2 名のみ
		expect( slot.booked_guests ).toBe( 2 );
	} );

	test( '後方互換: 最小0（既定）の枠では min_capacity=0（催行表示なし）', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 最小催行人数を 0（未設定）に戻す
		wpEvalPhp(
			`delete_post_meta( ${ Number.parseInt( menuId, 10 ) }, '_vkbm_min_capacity' );`
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			2,
			'confirmed'
		);

		const slot = await fetchSlotByStart(
			page,
			menuId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED
		);
		// min_capacity=0 → フロントは催行状態を一切表示しない（showFulfillment=false）
		expect( slot.min_capacity ).toBe( 0 );

		// 後続テストのために最小3へ戻す
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt( menuId, 10 ) }, '_vkbm_min_capacity', 3 );`
		);
	} );
} );

/**
 * 予約ページを開き、メニューを選んで翌月カレンダーの 1 日を選択し、スロット一覧が出るまで進む共通ヘルパー。
 *
 * 待ちはすべて「画面の状態」で行う（固定待ちはしない）。
 * - 「予約に進む」を押す前に、最初の月の calendar-meta 応答を待つ準備をしておき、最初の月の読み込み完了後に
 *   「次の月」を押す（読み込み途中に押すと、遅れて届いた最初の月のデータが翌月の表示を上書きしうる）。
 * - 「次の月」は応答の受信と見出しの変化、読み込み表示の消滅まで待つ。
 *
 * @param page   Playwright ページ
 * @param menuId 予約するサービスメニューの投稿 ID
 */
async function selectNextMonthDayOne(
	page: Page,
	menuId: string
): Promise< void > {
	await page.goto( '/booking/' );
	const reserveLink = page
		.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
		)
		.first();
	await reserveLink.waitFor( { state: 'visible', timeout: 15000 } );

	const isCalendarMeta = ( res: { url(): string; status(): number } ) =>
		res.url().includes( 'calendar-meta' ) && res.status() === 200;

	// 最初の月の応答を取りこぼさないよう、押す前に待ち受けを作る。
	const initialMeta = page.waitForResponse( isCalendarMeta, {
		timeout: 30000,
	} );
	await reserveLink.click();
	await page
		.locator( '.vkbm-calendar' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	await initialMeta;
	await expect( page.locator( '.vkbm-calendar__spinner' ) ).toHaveCount( 0 );

	// 対象日は翌月のため「次の月」へ 1 回進める。
	const heading = page.locator( '.vkbm-calendar__current' );
	const before = await heading.textContent();
	await Promise.all( [
		page.waitForResponse( isCalendarMeta, { timeout: 30000 } ),
		page.locator( '.vkbm-calendar__nav' ).last().click(),
	] );
	await expect( heading ).not.toHaveText( before ?? '' );
	await expect( page.locator( '.vkbm-calendar__spinner' ) ).toHaveCount( 0 );

	// 全日 available のため、ラベルが「1」かつ muted でない（当月内）セルを選ぶ。
	const dayOne = page
		.locator(
			'.vkbm-calendar__day--available:not(.vkbm-calendar__day--muted)'
		)
		.filter( {
			has: page
				.locator( '.vkbm-calendar__day-label' )
				.filter( { hasText: /^1$/ } ),
		} )
		.first();
	await dayOne.waitFor( { state: 'visible', timeout: 15000 } );
	await dayOne.click();
	await page
		.locator( '.vkbm-slot-list__item' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}

test.describe( 'PR #311: 最小催行人数 - 実機フロント表示（バッジ＋スクリーンショット）', () => {
	let menuId: string;
	let staffId: string;

	test.beforeAll( () => {
		staffId = getStaffId();
		menuId = getServiceMenuId();
		setupGroupSessionMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		deleteAllBookings();
		// 09:00 枠 = 充足（合計3名）、11:00 枠 = 未達（1名）にして両方を1画面で見せる。
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			3,
			'confirmed'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_PENDING,
			'12:00',
			1,
			'confirmed'
		);
	} );

	test.afterAll( () => {
		deleteAllBookings();
	} );

	test( '予約ページのスロット一覧に「開催決定」「あと N 名で開催」バッジが表示される', async ( {
		page,
	} ) => {
		// 予約ページ（/booking/）を開き、メニューを選んで翌月 1 日（7/1 相当・予約を投入した日）を選ぶ。
		await selectNextMonthDayOne( page, menuId );

		// 催行状態バッジ（開催決定 or あと N 名）のいずれかが少なくとも1件表示される
		const fulfillmentBadges = page.locator(
			'.vkbm-slot-list__fulfillment'
		);
		await expect( fulfillmentBadges.first() ).toBeVisible();

		// 「開催決定（confirmed）」バッジが存在する（09:00 枠＝合計3名）
		const confirmedBadge = page.locator(
			'.vkbm-slot-list__fulfillment--confirmed'
		);
		await expect( confirmedBadge.first() ).toBeVisible();
		// i18n 修正の確認: バッジ文言が日本語「開催決定」であること
		await expect( confirmedBadge.first() ).toHaveText( '開催決定' );

		// 「あと N 名（pending）」バッジが存在する（11:00 枠＝1名）
		const pendingBadge = page.locator(
			'.vkbm-slot-list__fulfillment--pending'
		);
		await expect( pendingBadge.first() ).toBeVisible();
		// i18n 修正の確認: バッジ文言が日本語「あと%d名で開催」であること（11:00 枠は1名→あと2名）
		await expect( pendingBadge.first() ).toContainText( 'あと' );
		await expect( pendingBadge.first() ).toContainText( '名で開催' );

		// スクリーンショット（フロントの催行バッジ）
		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-311/after-front-slot-fulfillment.png',
			fullPage: true,
		} );
	} );

	/**
	 * issue #844: 予約ページを開き、メニューを選んで翌月カレンダーの 1 日を選択済みにする。
	 *
	 * @param page Playwright ページ。
	 */
	const openCalendarWithDayOneSelected = async ( page: Page ) => {
		await selectNextMonthDayOne( page, menuId );
	};

	test( 'issue #844: 選択済みの日へ Tab で移動すると、2 色のフォーカス枠が出る', async ( {
		page,
	} ) => {
		await openCalendarWithDayOneSelected( page );

		// 選択済みの日は aria-pressed="true" で選択状態を伝える。
		const selectedDay = page.locator( '.vkbm-calendar__day--selected' );
		await expect( selectedDay ).toHaveAttribute( 'aria-pressed', 'true' );

		// キーボード操作として扱われるよう、「次の月」ボタンから Tab で選択済みの日まで進める。
		await page.locator( '.vkbm-calendar__nav' ).last().focus();
		await page.keyboard.press( 'Shift+Tab' );
		await page.keyboard.press( 'Tab' );
		for ( let i = 0; i < 60; i++ ) {
			await page.keyboard.press( 'Tab' );
			const reached = await page.evaluate(
				() =>
					document.activeElement?.classList.contains(
						'vkbm-calendar__day--selected'
					)
			);
			if ( reached ) {
				break;
			}
		}
		await expect( selectedDay ).toBeFocused();

		// 外側の枠は 2px の実線（outline）、内側の白は box-shadow（内側に描く）で表示される。
		const ring = await selectedDay.evaluate( ( el ) => {
			const style = window.getComputedStyle( el );
			return {
				outlineStyle: style.outlineStyle,
				outlineWidth: style.outlineWidth,
				outlineColor: style.outlineColor,
				outlineOffset: style.outlineOffset,
				boxShadow: style.boxShadow,
			};
		} );
		expect( ring.outlineStyle ).toBe( 'solid' );
		expect( ring.outlineWidth ).toBe( '2px' );
		expect( ring.outlineOffset ).toBe( '-2px' );
		expect( ring.outlineColor ).not.toBe( 'rgba(0, 0, 0, 0)' );
		expect( ring.boxShadow ).toContain( 'inset' );

		// 選択中のマスは、内側の帯が白ではなく選択色になり、選択の 1px 枠を覆われても選択中だと分かる。
		expect( ring.boxShadow ).not.toContain( 'rgb(255, 255, 255)' );

		// 選択していない隣のマスへ Tab で進むと、内側の帯は従来どおり白。選択中のマスと見分けられる。
		await page.keyboard.press( 'Tab' );
		const nextDay = page.locator( '.vkbm-calendar__day:focus' );
		await expect( nextDay ).toBeFocused();
		await expect( nextDay ).not.toHaveClass(
			/vkbm-calendar__day--selected/
		);
		const nextRing = await nextDay.evaluate(
			( el ) => window.getComputedStyle( el ).boxShadow
		);
		expect( nextRing ).toContain( 'inset' );
		expect( nextRing ).toContain( 'rgb(255, 255, 255)' );
		expect( nextRing ).not.toBe( ring.boxShadow );
	} );

	test( 'issue #844: 時間枠ボタンの背景と文字色はテーマの button の色に左右されない', async ( {
		page,
	} ) => {
		await openCalendarWithDayOneSelected( page );

		// 満席・受付終了の見た目は CSS だけで決まるため、クラスを付けて確かめる。
		await page.evaluate( () => {
			const items = document.querySelectorAll( '.vkbm-slot-list__item' );
			items[ 1 ].classList.add( 'is-full' );
			items[ 1 ]
				.querySelector( '.vkbm-slot-list__remaining' )
				?.classList.add( 'vkbm-slot-list__remaining--full' );
			items[ 2 ].classList.add( 'is-exclusive-closed' );
			items[ 2 ]
				.querySelector( '.vkbm-slot-list__remaining' )
				?.classList.add( 'vkbm-slot-list__remaining--closed' );
		} );
		await page.mouse.move( 0, 0 );

		// 通常・満席・受付終了の時刻と残枠表示の文字が、背景に対して 4.5:1 以上あること。
		const readContrasts = () =>
			page.evaluate( () => {
				const luminance = ( color: string ) => {
					const [ r, g, b ] = ( color.match( /[\d.]+/g ) || [] )
						.slice( 0, 3 )
						.map( ( v ) => {
							const c = Number( v ) / 255;
							return c <= 0.03928
								? c / 12.92
								: Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
						} );
					return 0.2126 * r + 0.7152 * g + 0.0722 * b;
				};
				const ratio = ( a: string, b: string ) => {
					const x = luminance( a );
					const y = luminance( b );
					return (
						( Math.max( x, y ) + 0.05 ) /
						( Math.min( x, y ) + 0.05 )
					);
				};
				return Array.from(
					document.querySelectorAll( '.vkbm-slot-list__item' )
				)
					.slice( 0, 3 )
					.map( ( item ) => {
						const bg =
							window.getComputedStyle( item ).backgroundColor;
						const time = item.querySelector(
							'.vkbm-slot-list__time'
						);
						const remaining = item.querySelector(
							'.vkbm-slot-list__remaining'
						);
						return {
							bg,
							time: ratio(
								window.getComputedStyle( time! ).color,
								bg
							),
							remaining: ratio(
								window.getComputedStyle( remaining! ).color,
								bg
							),
						};
					} );
			} );

		for ( const row of await readContrasts() ) {
			expect( row.time ).toBeGreaterThanOrEqual( 4.5 );
			expect( row.remaining ).toBeGreaterThanOrEqual( 4.5 );
		}

		// ホバー中もテーマ（Twenty Twenty-One は背景を透明にする）の色に変わらない。
		const first = page.locator( '.vkbm-slot-list__item' ).first();
		const before = await first.evaluate(
			( el ) => window.getComputedStyle( el ).backgroundColor
		);
		await first.hover();
		const after = await first.evaluate(
			( el ) => window.getComputedStyle( el ).backgroundColor
		);
		expect( after ).toBe( before );
		expect( after ).toBe( 'rgb(255, 255, 255)' );

		// ホバー中は枠線がアクセント色になる。満席・受付終了はクリックできないため変わらない。
		const borderColor = ( index: number ) =>
			page
				.locator( '.vkbm-slot-list__item' )
				.nth( index )
				.evaluate(
					( el ) => window.getComputedStyle( el ).borderTopColor
				);
		expect( await borderColor( 0 ) ).toBe( 'rgb(29, 143, 203)' );
		await page.locator( '.vkbm-slot-list__item' ).nth( 1 ).hover();
		expect( await borderColor( 1 ) ).toBe( 'rgb(208, 208, 208)' );
	} );

	test( 'issue #844: 残枠数の文字色が --vkbm--color--accent-text になる', async ( {
		page,
	} ) => {
		await openCalendarWithDayOneSelected( page );

		// 満席・受付終了ではない通常の残枠数表示を対象にする。
		const remaining = page
			.locator(
				'.vkbm-slot-list__remaining:not(.vkbm-slot-list__remaining--full):not(.vkbm-slot-list__remaining--closed)'
			)
			.first();
		await expect( remaining ).toBeVisible();

		// CSS 変数の値は rgb 表記で比べるため、変数を color に指定した要素の計算値と突き合わせる。
		const colors = await remaining.evaluate( ( el ) => {
			const probe = document.createElement( 'span' );
			probe.style.color = 'var(--vkbm--color--accent-text)';
			document.body.appendChild( probe );
			const expected = window.getComputedStyle( probe ).color;
			probe.remove();
			return {
				actual: window.getComputedStyle( el ).color,
				expected,
			};
		} );
		expect( colors.expected ).toBe( 'rgb(23, 114, 162)' );
		expect( colors.actual ).toBe( colors.expected );
	} );
} );

test.describe( 'PR #311: 最小催行人数 - 管理ダッシュボードの未達枠可視化（CodeRabbit #1/#2）', () => {
	let menuId: string;
	let staffId: string;

	test.beforeAll( () => {
		staffId = getStaffId();
		menuId = getServiceMenuId();
		setupGroupSessionMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		deleteAllBookings();
		// 09:00 枠 = 未達（2名/最小3）かつ status=pending（既存ステータス色との重なりを見る）。
		// 別開始時刻 11:00 枠 = 1名（09:00 と合算されないこと=CodeRabbit #2 の確認用）。
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_FULFILLED,
			'10:00',
			2,
			'pending'
		);
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			FRONT_BASE_HOUR_PENDING,
			'12:00',
			1,
			'confirmed'
		);
	} );

	test.afterAll( () => {
		deleteAllBookings();
	} );

	test( '未達枠に outline クラスと未達ラベルが付与され、既存ステータスクラスも残る（CodeRabbit #1）', async ( {
		page,
	} ) => {
		// 管理画面にログインし、対象日のシフトダッシュボード（日表示）を開く
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/admin.php?page=vkbm-shift-dashboard&vkbm_date=${ FRONT_TEST_DATE }`
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 予約カードが描画されるのを待つ
		await page
			.locator( '.vkbm-booking-card' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

		// 未達枠（09:00, status=pending, 2名/最小3）のカードに is-min-capacity-pending が付く
		const pendingCard = page.locator(
			'.vkbm-booking-card.is-min-capacity-pending'
		);
		await expect( pendingCard.first() ).toBeVisible();

		// 未達ラベル（色だけに頼らないテキスト併記）が表示される
		const minCapLabel = page.locator(
			'.vkbm-booking-card__min-capacity'
		);
		await expect( minCapLabel.first() ).toBeVisible();
		// i18n 修正の確認: ラベルが日本語「最少催行人数に未達」であること
		await expect( minCapLabel.first() ).toContainText(
			'最少催行人数に未達'
		);

		// CodeRabbit #1 の回帰: 既存ステータス色クラス（pending）が潰れていないこと。
		// 未達枠カードは is-min-capacity-pending を「追加」するだけで、
		// 既存ステータスクラス（vkbm-booking-card--pending 等）を上書きしない。
		const cardClass =
			( await pendingCard.first().getAttribute( 'class' ) ) ?? '';
		// outline 用クラスと既存ステータスクラスが共存している
		expect( cardClass ).toContain( 'is-min-capacity-pending' );
		// 何らかの既存ステータス修飾クラス（vkbm-booking-card--*）が残っている
		expect( cardClass ).toMatch( /vkbm-booking-card--[a-z-]+/ );

		// outline スタイルが実際に当たっていること（CSS で 2px dashed が適用される）
		const outlineStyle = await pendingCard
			.first()
			.evaluate(
				( el ) => window.getComputedStyle( el ).outlineStyle
			);
		expect( outlineStyle ).toBe( 'dashed' );

		// スクリーンショット（管理ダッシュボードの未達枠可視化）
		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-311/after-admin-dashboard-pending.png',
			fullPage: true,
		} );
	} );

	test( '別開始時刻の枠は合算されない（CodeRabbit #2・管理ダッシュボード側）', () => {
		// annotate_min_capacity_state を経由する get_bookings_for_day の結果を検証する。
		// 09:00 枠(2名)と 11:00 枠(1名)が別セッションとして扱われ、group_guests が混ざらないこと。
		const phpCode = `
			$page = new \\VKBookingManager\\Admin\\Shift_Dashboard_Page();
			$ref = new ReflectionMethod( $page, 'get_bookings_for_day' );
			$ref->setAccessible( true );
			$tz  = wp_timezone();
			$date = new DateTimeImmutable( '${ FRONT_TEST_DATE }', $tz );
			$map = $ref->invoke( $page, $date );
			// 09:00 開始(=9.0)と 11:00 開始(=11.0)それぞれの group_guests を集める。
			$by_start = array();
			foreach ( $map as $rows ) {
				foreach ( $rows as $row ) {
					$start = round( (float) ( $row['start_decimal'] ?? -1 ), 2 );
					$by_start[ (string) $start ] = (int) ( $row['group_guests'] ?? 0 );
				}
			}
			echo '9=' . ( $by_start['9'] ?? 'NA' ) . ';11=' . ( $by_start['11'] ?? 'NA' );
		`;
		const out = wpEvalPhp( phpCode );
		// 09:00 枠は 2 名（11:00 の 1 名が混ざらない）、11:00 枠は 1 名（09:00 が混ざらない）
		expect( out ).toContain( '9=2' );
		expect( out ).toContain( '11=1' );
	} );
} );

/**
 * 確定画面注記（CodeRabbit #3）の出し分けロジック。
 *
 * src/blocks/reservation/booking-confirm-app.js の showMinCapacityNote と同一の式。
 * 注記は「今回の予約（draftGuests）を反映しても合計が最小催行人数に満たない」場合だけ表示する。
 * 充足済み（合計 >= 最小催行人数）の枠では表示しない（以前は常時表示だったバグの回帰防止）。
 *
 * @param minCapacity  スロットの最小催行人数（0=制約なし）
 * @param bookedGuests スロットの既存合計予約人数
 * @param draftGuests  今回の予約人数
 * @return 注記を表示するなら true
 */
function shouldShowMinCapacityNote(
	minCapacity: number,
	bookedGuests: number,
	draftGuests: number
): boolean {
	const minCapacityForSlot = Math.max( 0, Number( minCapacity ) || 0 );
	const bookedGuestsForSlot = Math.max( 0, Number( bookedGuests ) || 0 );
	const draftGuestsForSlot = Math.max( 0, Number( draftGuests ) || 0 );
	return (
		minCapacityForSlot > 0 &&
		bookedGuestsForSlot + draftGuestsForSlot < minCapacityForSlot
	);
}

test.describe( 'PR #311: 最小催行人数 - 確定画面注記の出し分け（CodeRabbit #3 回帰）', () => {
	test( '未達スロット（既存2名+今回1名=3 ＜ min4）では注記を表示する', () => {
		// 合計3名で最小4に満たない → 注記あり
		expect( shouldShowMinCapacityNote( 4, 2, 1 ) ).toBe( true );
	} );

	test( '充足スロット（既存2名+今回1名=3 ＝ min3）では注記を表示しない（回帰の核心）', () => {
		// 今回の予約を加えると充足（3>=3） → 注記なし。
		// 以前は「常時表示」だったため、ここが false になることが CodeRabbit #3 の回帰確認。
		expect( shouldShowMinCapacityNote( 3, 2, 1 ) ).toBe( false );
	} );

	test( '充足済みスロット（既存3名 min3、今回1名）でも注記を表示しない', () => {
		// 既にスロットが充足済み → 今回予約を足しても当然充足 → 注記なし
		expect( shouldShowMinCapacityNote( 3, 3, 1 ) ).toBe( false );
	} );

	test( '今回予約だけでは未達（既存0名+今回1名=1 ＜ min3）では注記を表示する', () => {
		expect( shouldShowMinCapacityNote( 3, 0, 1 ) ).toBe( true );
	} );

	test( '後方互換: 最小0（制約なし）のスロットでは常に注記を表示しない', () => {
		expect( shouldShowMinCapacityNote( 0, 0, 1 ) ).toBe( false );
		expect( shouldShowMinCapacityNote( 0, 5, 3 ) ).toBe( false );
	} );

	// 実機（ログイン〜確定画面まで）のテスト。WP-CLI の呼び出しは 1 回あたり数秒かかり、
	// 重い環境ではテスト本体の制限時間を食うため、メニュー・シフトの準備は beforeAll に出し、
	// ログイン・画面遷移・確認の分だけ制限時間に余裕を持たせる。
	test.describe( '実機の確定画面', () => {
		test.describe.configure( { timeout: 120000 } );

		let menuId: string;
		let staffId: string;

		test.beforeAll( () => {
			staffId = getStaffId();
			menuId = getServiceMenuId();
			setupGroupSessionMenu( menuId, staffId );
			ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		} );

		/**
		 * 実機での確定画面注記の出し分け（CodeRabbit #3 修正の回帰確認）。
		 *
		 * 修正により save_draft / get_draft が slot に min_capacity / booked_guests を伝搬するようになった。
		 * これにより確定画面の showMinCapacityNote が実データで評価され、
		 * - 未達スロット（今回予約を足しても合計 < 最小催行人数）→ 注記が表示される
		 * - 充足スロット（合計 >= 最小催行人数）→ 注記が表示されない
		 * の両方向が実機で正しく動くことを確認する。
		 *
		 * 予約フロントの確定画面はログインが必要なため admin で操作する。
		 * 7/1 の 09:00 枠（予約ゼロ・最小3）を選び、今回1名で合計1<3 → 注記あり。
		 */
		test( '実機: 未達スロットの確定画面に「最少催行人数に満たない場合〜」注記が日本語で表示される', async ( {
			page,
		} ) => {
			deleteAllBookings();
			// 予約ゼロ＝7/1 09:00 枠は booked_guests=0, min=3。今回1名で合計1<3 → 注記が出るべき。

			await loginAsAdmin( page );
			await gotoConfirmScreenForFirstSlot( page, menuId );

			// 注記が表示される（CodeRabbit #3 修正後の正しい挙動）
			const note = page.locator( '#vkbm-confirm-min-capacity-note' );
			await expect( note ).toBeVisible( { timeout: 15000 } );
			// 文言が日本語であること（i18n 修正の確認）
			await expect( note ).toContainText( '最少催行人数に満たない場合' );

			// 確定ボタンに aria-describedby で注記が関連付けられていること（アクセシビリティ）
			const confirmBtn = page.locator( '.vkbm-confirm__button' ).first();
			await expect( confirmBtn ).toHaveAttribute(
				'aria-describedby',
				'vkbm-confirm-min-capacity-note'
			);

			await page.screenshot( {
				path: 'tests/e2e/screenshots/pr-311/after-confirm-note-pending.png',
				fullPage: true,
			} );

			deleteAllBookings();
		} );

		test( '実機: 充足スロット（合計が最小催行人数以上）の確定画面では注記が出ない', async ( {
			page,
		} ) => {
			deleteAllBookings();
			// 7/1 09:00 枠に既存3名（最小3を満たす・最大5なので空きあり）。
			// 今回1名を足しても合計4>=3 で充足 → 注記は出ない。
			createBooking(
				menuId,
				staffId,
				FRONT_TEST_DATE,
				FRONT_BASE_HOUR_FULFILLED,
				'10:00',
				3,
				'confirmed'
			);

			await loginAsAdmin( page );
			await gotoConfirmScreenForFirstSlot( page, menuId );

			// 充足済みスロットなので注記は表示されない（CodeRabbit #3 の「常時表示しない」回帰）
			await expect(
				page.locator( '#vkbm-confirm-min-capacity-note' )
			).toHaveCount( 0 );

			await page.screenshot( {
				path: 'tests/e2e/screenshots/pr-311/after-confirm-note-fulfilled.png',
				fullPage: true,
			} );

			deleteAllBookings();
		} );
	} );
} );

/**
 * 予約フロントで 7/1 の先頭スロット（09:00）を選び、確定画面まで進む共通ヘルパー。
 * ログイン済みの page を前提とする。確定ボタンが見えるまで待って戻る。
 *
 * @param page   ログイン済みの Playwright Page
 * @param menuId 予約するサービスメニューの投稿 ID
 */
async function gotoConfirmScreenForFirstSlot(
	page: import('@playwright/test').Page,
	menuId: string
): Promise< void > {
	// 先頭カードではなく data-menu-id で「Service Menu 1」を指定する（#566）。
	await selectNextMonthDayOne( page, menuId );
	// 09:00 枠（先頭）を選択
	const firstSlot = page.locator( '.vkbm-slot-list__item' ).first();
	await firstSlot.click();
	// 選択が反映されると「選択中」のクラスが付き、次へ進むボタンが押せるようになる。
	await expect( firstSlot ).toHaveClass( /is-selected/ );
	const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( proceed ).toBeEnabled( { timeout: 15000 } );
	await proceed.click();
	await page
		.locator( '.vkbm-confirm__button' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}
