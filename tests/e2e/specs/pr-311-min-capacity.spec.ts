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
import { test, expect } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getServiceMenuId,
	getStaffId,
	loginAsAdmin,
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
 * @param menuId      サービスメニューの投稿ID（数値文字列）
 * @param maxCapacity 最大受付数（入力値）
 * @param minCapacity 最小催行人数（クランプ前の入力値）
 * @param allowMulti  メニュー個別の複数人予約許可フラグ
 * @return 保存後の _vkbm_min_capacity の値（数値文字列）
 */
function savePostViaEditor(
	menuId: string,
	maxCapacity: number,
	minCapacity: number,
	allowMulti: boolean
): string {
	const max = Number.parseInt( String( maxCapacity ), 10 );
	const min = Number.parseInt( String( minCapacity ), 10 );
	const id = Number.parseInt( menuId, 10 );
	const allowLine = allowMulti
		? "$_POST['vkbm_service_menu']['allow_multiple_guests'] = '1';"
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

		const saved = savePostViaEditor( menuId, 5, 3, true );
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

		const saved = savePostViaEditor( menuId, 5, 10, true );
		// 最大受付数 5 を上限にクランプされる
		expect( saved ).toBe( '5' );

		const html = getConditionsMetaboxHtml( menuId );
		expect( html ).toMatch(
			/id="vkbm_service_menu_min_capacity"[^>]*value="5"/
		);
	} );

	test( '指名ON のとき: 最小催行人数欄が出ない（最大受付数欄も非表示の案内に変わる）', () => {
		// --- 準備: 指名ON（最大受付数・最小催行人数ともに無効化される構成） ---
		setProviderGates( true, true );
		const menuId = getServiceMenuId();

		const html = getConditionsMetaboxHtml( menuId );

		// 最小催行人数欄が出力されないこと
		expect( html ).not.toContain( 'id="vkbm_service_menu_min_capacity"' );
		// 最大受付数欄も出ない（指名ON時は案内メッセージに置き換わる既存仕様）
		expect( html ).not.toContain( 'id="vkbm_service_menu_max_capacity"' );
	} );

	test( '複数人予約OFF のとき: 最小催行人数欄が出ない', () => {
		// --- 準備: 指名OFF・複数人予約OFF（最小催行人数が無効になる構成） ---
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
		echo $bid;
	`;
	return wpEvalPhp( phpCode );
}

/**
 * テストで作成した vkbm_booking を全削除するヘルパー（テスト間の干渉防止）。
 */
function deleteAllBookings(): void {
	const ids = wpCliArgs( [
		'post',
		'list',
		'--post_type=vkbm_booking',
		'--post_status=any',
		'--format=ids',
	] ).trim();
	if ( ids ) {
		wpCliArgs( [ 'post', 'delete', ...ids.split( /\s+/ ).filter( Boolean ), '--force' ] );
	}
}

/**
 * availability REST（/vkbm/v1/availabilities）を叩いて指定開始時刻のスロットを返すヘルパー。
 * Availability はトランジェントでキャッシュされるため、毎回キャッシュをクリアしてから取得する。
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
	// availability のトランジェントキャッシュをクリア（予約投入が即時反映されるように）。
	wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	const res = await page.request.get(
		`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ date }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`
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
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test.afterAll( () => {
		deleteAllBookings();
	} );

	test( '予約ページのスロット一覧に「開催決定」「あと N 名で開催」バッジが表示される', async ( {
		page,
	} ) => {
		// 予約ページ（/booking/）を開く。ここには menu-loop ブロックのメニューカードが並ぶ。
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'domcontentloaded' );

		// メニューカードの「予約に進む」リンク（vkbm-menu-loop__button--reserve）をクリックすると、
		// 同ページ内で予約ブロックがプラン要約＋カレンダー表示に切り替わる。
		const reserveLink = page
			.locator( '.vkbm-menu-loop__button--reserve' )
			.first();
		await reserveLink.waitFor( { state: 'visible', timeout: 15000 } );
		await reserveLink.click();

		// カレンダーが描画されるのを待つ
		await page
			.locator( '.vkbm-calendar' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

		// 対象日（7/1）は翌月のため「次の月（›）」へ1回進める。
		// カレンダーのナビゲーションは「前の月」「次の月」の2ボタン。最後の方が「次の月」。
		const nextBtn = page.locator( '.vkbm-calendar__nav' ).last();
		if ( ( await nextBtn.count() ) > 0 ) {
			await nextBtn.click();
			await page.waitForTimeout( 1000 );
		}

		// 予約を投入した 7/1（ラベル「1」・当月内・予約可能）の日セルをクリックする。
		// 全日 available のため、ラベルが「1」かつ muted でない（当月）セルを厳密に選ぶ。
		await page
			.locator( '.vkbm-calendar__day--available' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );
		const dayOne = page
			.locator(
				'.vkbm-calendar__day--available:not(.vkbm-calendar__day--muted)'
			)
			.filter( {
				has: page
					.locator( '.vkbm-calendar__day-label' )
					.filter( { hasText: /^1$/ } ),
			} );
		await dayOne.first().click();

		// スロット一覧が描画されるのを待つ
		await page
			.locator( '.vkbm-slot-list__item' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

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
		const staffId = getStaffId();
		const menuId = getServiceMenuId();
		setupGroupSessionMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		deleteAllBookings();
		// 予約ゼロ＝7/1 09:00 枠は booked_guests=0, min=3。今回1名で合計1<3 → 注記が出るべき。
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		await loginAsAdmin( page );
		await gotoConfirmScreenForFirstSlot( page );

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
		const staffId = getStaffId();
		const menuId = getServiceMenuId();
		setupGroupSessionMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
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
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		await loginAsAdmin( page );
		await gotoConfirmScreenForFirstSlot( page );

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

/**
 * 予約フロントで 7/1 の先頭スロット（09:00）を選び、確定画面まで進む共通ヘルパー。
 * ログイン済みの page を前提とする。確定ボタンが見えるまで待って戻る。
 *
 * @param page ログイン済みの Playwright Page
 */
async function gotoConfirmScreenForFirstSlot(
	page: import('@playwright/test').Page
): Promise< void > {
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'domcontentloaded' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await page
		.locator( '.vkbm-calendar' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	await page.locator( '.vkbm-calendar__nav' ).last().click();
	await page.waitForTimeout( 1000 );
	const dayOne = page
		.locator(
			'.vkbm-calendar__day--available:not(.vkbm-calendar__day--muted)'
		)
		.filter( {
			has: page
				.locator( '.vkbm-calendar__day-label' )
				.filter( { hasText: /^1$/ } ),
		} );
	await dayOne.first().click();
	await page
		.locator( '.vkbm-slot-list__item' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	// 09:00 枠（先頭）を選択
	await page.locator( '.vkbm-slot-list__item' ).first().click();
	await page.waitForTimeout( 1500 );
	const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( proceed ).toBeEnabled( { timeout: 15000 } );
	await proceed.click();
	await page
		.locator( '.vkbm-confirm__button' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}
