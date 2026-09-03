/**
 * PR #316: 予約者による貸し切り予約指定機能（#305）の e2e 検証。
 *
 * 検証内容:
 *  A. 管理画面（サービスメニュー詳細）
 *     - Pro版・指名OFF・複数人予約ON のときだけ「予約者による貸切指定を受け付ける」チェックが出る。
 *     - そのチェック ON で「貸切料金 ￥/人」「貸切料金を適用しない申込人数」の第二段が表示される。
 *     - 値が保存・復元される。複数人予約 OFF にすると貸切系メタが削除される。
 *  B. フロント予約画面
 *     - 人数入力直下に「この時間帯を貸切にする」チェック。空き枠＋申込人数≧最小催行人数 で有効。
 *     - ON で料金サマリーに貸切料金行がリアルタイム加算（aria-live）。
 *     - 適用外人数到達で ¥0＋「◯名以上のお申し込みのため貸切料金はかかりません」に切替。
 *  C. 既存予約のある枠ではチェックが無効化＋理由表示。
 *  D. サーバ側ガード: 貸切確定済みの枠は他ユーザーが予約できない（availability で exclusive_closed）。
 *
 * 実行環境:
 *  - playwright.config の baseURL（テスト用 wp-env）に対して実行する。page.goto は相対パス。
 *  - 貸し切り指定は Pro版・指名OFF・複数人予約ON でのみ意味を持つため、describe の間だけ指名OFFに切り替える。
 *  - DB 破壊操作（import/reset/export）は行わない。テストデータは作成系 wp-cli / wp eval で用意する。
 */
import { test, expect, Page } from '@playwright/test';
import {
	loginAsAdmin,
	wpEvalPhp,
	wpCliArgs,
	getStaffId,
	getServiceMenuId,
} from '../utils/helpers';

// 貸切料金・適用外人数の検証用パラメータ（PR 本文の確認手順に準拠）。
const FEE_PER_PERSON = 1000; // 1人あたり貸切料金
const FEE_EXEMPT_GUESTS = 4; // この人数以上で貸切料金を適用しない
const MIN_CAPACITY = 2; // 最小催行人数（貸切指定の下限）
const MAX_CAPACITY = 5; // 最大受付数

/**
 * フロント検証で使う対象日（東京時刻の翌月1日）を動的に算出する。
 * 日付ハードコードは過去日化で恒常 FAIL する時限バグになるため、実行時に算出する。
 *
 * @return year/month（翌月）と ISO 日付（翌月1日, YYYY-MM-DD）。
 */
function resolveNextMonthFirstDay(): {
	year: number;
	month: number;
	iso: string;
} {
	const parts = new Intl.DateTimeFormat( 'en', {
		timeZone: 'Asia/Tokyo',
		year: 'numeric',
		month: 'numeric',
	} ).formatToParts( new Date() );
	const curYear = Number( parts.find( ( p ) => p.type === 'year' )?.value );
	const curMonth = Number( parts.find( ( p ) => p.type === 'month' )?.value );
	// 当月(1-12)を 0-based(curMonth-1) に直し +1 した index で「翌月1日」を作る。
	const nextFirst = new Date( Date.UTC( curYear, curMonth, 1 ) );
	const year = nextFirst.getUTCFullYear();
	const month = nextFirst.getUTCMonth() + 1;
	const iso = `${ year }-${ String( month ).padStart( 2, '0' ) }-01`;
	return { year, month, iso };
}

const NEXT_MONTH_FIRST = resolveNextMonthFirstDay();
const FRONT_TEST_DATE = NEXT_MONTH_FIRST.iso;
const FRONT_TEST_YEAR = NEXT_MONTH_FIRST.year;
const FRONT_TEST_MONTH = NEXT_MONTH_FIRST.month;

/**
 * 対象メニューを「ユーザー貸し切り指定が有効になる構成」にセットアップする。
 * provider ゲート（指名OFF・複数人予約ON）＋メニューメタ（最大5・最小2・複数人予約ON）＋
 * ユーザー貸切指定ON（単価 1000・適用外 4）。
 *
 * @param menuId  サービスメニューの投稿ID
 * @param staffId 割り当てるスタッフの投稿ID
 */
function setupUserExclusiveMenu( menuId: string, staffId: string ): void {
	const id = Number.parseInt( menuId, 10 );
	const sid = Number.parseInt( staffId, 10 );
	const phpCode = `
		// provider ゲート: 指名OFF・予約枠の定員ON（#326 で新キー slot_capacity_enabled に改名）
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['staff_enabled'] = 0;
		$s['slot_capacity_enabled'] = 1;
		unset( $s['multiple_guests_enabled'] );
		update_option( 'vkbm_provider_settings', $s );

		// メニュー: 複数人一括予約ON・最大${ MAX_CAPACITY }・最小${ MIN_CAPACITY }・所要60分・締切/事前制限なし・スタッフ割当
		update_post_meta( ${ id }, '_vkbm_max_capacity', ${ MAX_CAPACITY } );
		update_post_meta( ${ id }, '_vkbm_min_capacity', ${ MIN_CAPACITY } );
		update_post_meta( ${ id }, '_vkbm_allow_multiple_guests', '1' );
		update_post_meta( ${ id }, '_vkbm_duration_minutes', 60 );
		update_post_meta( ${ id }, '_vkbm_reservation_deadline_hours', 0 );
		update_post_meta( ${ id }, '_vkbm_max_advance_booking_days', 0 );
		update_post_meta( ${ id }, '_vkbm_staff_ids', array( ${ sid } ) );
		delete_post_meta( ${ id }, '_vkbm_fixed_start_times' );
		// 料金区分は使わない（基本料金×人数の通常メニュー）。区分があると人数UIが変わるため削除する。
		delete_post_meta( ${ id }, '_vkbm_price_tiers' );

		// ユーザー貸し切り指定ON（単価・適用外人数）。
		update_post_meta( ${ id }, '_vkbm_exclusive_user_selectable', true );
		update_post_meta( ${ id }, '_vkbm_exclusive_fee_per_person', ${ FEE_PER_PERSON } );
		update_post_meta( ${ id }, '_vkbm_exclusive_fee_exempt_guests', ${ FEE_EXEMPT_GUESTS } );
		echo 'ok';
	`;
	wpEvalPhp( phpCode );
}

/**
 * 対象月のシフト（毎日 09:00-18:00 open）を用意する（翌月分を補う）。
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
 * vkbm_booking を1件作成して特定スロットに予約人数を投入する。
 * 値はすべて整数・固定書式の文字列のみ埋め込む（PHP 脱出リスクを排除）。
 *
 * @param serviceId サービスメニューID
 * @param staffId   スタッフID
 * @param date      対象日（Y-m-d）
 * @param startTime 開始時刻（HH:MM）
 * @param endTime   終了時刻（HH:MM）
 * @param guests    予約人数
 * @param exclusive 貸し切りフラグ（true で _vkbm_booking_exclusive を付与）
 * @return 作成した予約の投稿ID
 */
function createBooking(
	serviceId: string,
	staffId: string,
	date: string,
	startTime: string,
	endTime: string,
	guests: number,
	exclusive: boolean
): string {
	const svc = Number.parseInt( serviceId, 10 );
	const sid = Number.parseInt( staffId, 10 );
	const g = Number.parseInt( String( guests ), 10 );
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		throw new Error( `Invalid date: ${ date }` );
	}
	if (
		! /^\d{2}:\d{2}$/.test( startTime ) ||
		! /^\d{2}:\d{2}$/.test( endTime )
	) {
		throw new Error( `Invalid time: ${ startTime }-${ endTime }` );
	}
	const exclusiveLine = exclusive
		? `update_post_meta( $bid, '_vkbm_booking_exclusive', true );`
		: '';
	const phpCode = `
		$bid = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => 'E2E PR316 booking',
		) );
		update_post_meta( $bid, '_vkbm_booking_service_start', '${ date } ${ startTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_service_end', '${ date } ${ endTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_total_end', '${ date } ${ endTime }:00' );
		update_post_meta( $bid, '_vkbm_booking_resource_id', ${ sid } );
		update_post_meta( $bid, '_vkbm_booking_service_id', ${ svc } );
		update_post_meta( $bid, '_vkbm_booking_guests', ${ g } );
		update_post_meta( $bid, '_vkbm_booking_status', 'confirmed' );
		${ exclusiveLine }
		echo $bid;
	`;
	return wpEvalPhp( phpCode );
}

/**
 * テストで作成した vkbm_booking を全削除する（テスト間の干渉防止）。
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
		wpCliArgs( [
			'post',
			'delete',
			...ids.split( /\s+/ ).filter( Boolean ),
			'--force',
		] );
	}
}

/**
 * render_conditions_meta_box の HTML 出力を WP-CLI 経由で取得する。
 *
 * @param menuId サービスメニューの投稿ID（数値文字列）
 * @return メタボックスの HTML 出力
 */
function getConditionsMetaboxHtml( menuId: string ): string {
	const id = Number.parseInt( menuId, 10 );
	const phpCode = `
		set_current_screen( 'post' );
		$post = get_post( ${ id } );
		if ( ! $post ) { echo 'ERROR: Post not found'; return; }
		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		ob_start();
		$editor->render_conditions_meta_box( $post );
		echo ob_get_clean();
	`;
	return wpEvalPhp( phpCode );
}

/**
 * provider 設定（指名・複数人予約）をまとめて切り替える。
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
		// 呼称統一（#326）で親スイッチは新キー slot_capacity_enabled に改名。旧キーは残さない。
		$s['slot_capacity_enabled'] = ${ multi };
		unset( $s['multiple_guests_enabled'] );
		update_option( 'vkbm_provider_settings', $s );
		`
	);
}

/**
 * 共有フィクスチャ汚染防止用のスナップショット文字列を保持する。
 * beforeAll で取得し afterAll で復元する（後続 spec へ provider 設定・メニューメタを引き継がせない）。
 */
let providerSettingsSnapshot = '';
const menuMetaSnapshot: Record< string, string > = {};

/**
 * provider 設定（vkbm_provider_settings オプション）全体を base64 シリアライズして退避する。
 * staff_enabled / slot_capacity_enabled など spec が触る全項目をまとめて元へ戻すため、
 * 個別キーではなくオプション全体をスナップショットする。
 */
function snapshotProviderSettings(): void {
	providerSettingsSnapshot = wpEvalPhp(
		`echo base64_encode( maybe_serialize( get_option( 'vkbm_provider_settings', array() ) ) );`
	).trim();
}

/**
 * snapshotProviderSettings() で退避した provider 設定をそのまま書き戻す。
 */
function restoreProviderSettings(): void {
	if ( '' === providerSettingsSnapshot ) {
		return;
	}
	wpEvalPhp(
		`update_option( 'vkbm_provider_settings', maybe_unserialize( base64_decode( '${ providerSettingsSnapshot }' ) ) );`
	);
}

/**
 * 対象メニュー投稿の全 postmeta を base64 シリアライズして退避する。
 * setupUserExclusiveMenu()/savePostViaEditor() が共有メニューのメタを永続変更するため、
 * 全メタを退避し afterAll で完全復元して後続 spec への汚染を防ぐ。
 *
 * @param menuId サービスメニューの投稿ID（数値文字列）
 */
function snapshotMenuMeta( menuId: string ): void {
	const id = Number.parseInt( menuId, 10 );
	menuMetaSnapshot[ menuId ] = wpEvalPhp(
		`echo base64_encode( maybe_serialize( get_post_meta( ${ id } ) ) );`
	).trim();
}

/**
 * snapshotMenuMeta() で退避した postmeta を完全復元する。
 * 既存メタを一旦全削除してから退避値を書き戻し、spec 実行中に増えたメタも残さない。
 *
 * @param menuId サービスメニューの投稿ID（数値文字列）
 */
function restoreMenuMeta( menuId: string ): void {
	const snap = menuMetaSnapshot[ menuId ];
	if ( ! snap ) {
		return;
	}
	const id = Number.parseInt( menuId, 10 );
	// 現在のメタを全削除 → 退避時点の値（get_post_meta の単一メタは配列で返るため [0] を書き戻す）を再投入する。
	wpEvalPhp(
		`
		$id = ${ id };
		$saved = maybe_unserialize( base64_decode( '${ snap }' ) );
		$current = get_post_meta( $id );
		if ( is_array( $current ) ) {
			foreach ( array_keys( $current ) as $k ) { delete_post_meta( $id, $k ); }
		}
		if ( is_array( $saved ) ) {
			foreach ( $saved as $k => $vals ) {
				if ( is_array( $vals ) ) {
					foreach ( $vals as $v ) { add_post_meta( $id, $k, maybe_unserialize( $v ) ); }
				}
			}
		}
		echo 'restored';
		`
	);
}

/**
 * spec が用意する対象月シフトの、変更前状態を退避するスナップショット。
 * existed=false の場合は ensureShiftForMonth が新規作成した shift を afterAll で削除し、
 * existed=true の場合は退避した _vkbm_shift_days を書き戻して元の内容へ戻す。
 */
let shiftSnapshot: {
	staffId: string;
	year: number;
	month: number;
	postId: string;
	existed: boolean;
	daysB64: string;
} | null = null;

/**
 * 対象（スタッフ・年月）のシフトの現状を退避する。ensureShiftForMonth を呼ぶ前に実行すること。
 *
 * @param staffId スタッフの投稿ID（数値文字列）
 * @param year    対象年
 * @param month   対象月（1-12）
 */
function snapshotShiftForMonth(
	staffId: string,
	year: number,
	month: number
): void {
	const sid = Number.parseInt( staffId, 10 );
	const y = Number.parseInt( String( year ), 10 );
	const m = Number.parseInt( String( month ), 10 );
	// 既存シフトがあれば post_id と _vkbm_shift_days を base64 退避、無ければ existed=0 を返す。
	const out = wpEvalPhp(
		`
		$ex = get_posts( array(
			'post_type'   => 'vkbm_shift',
			'post_status' => 'any',
			'meta_query'  => array(
				array( 'key' => '_vkbm_shift_resource_id', 'value' => ${ sid } ),
				array( 'key' => '_vkbm_shift_year', 'value' => ${ y } ),
				array( 'key' => '_vkbm_shift_month', 'value' => ${ m } ),
			),
			'fields'      => 'ids',
		) );
		if ( ! empty( $ex ) ) {
			$pid = (int) $ex[0];
			$days = get_post_meta( $pid, '_vkbm_shift_days', true );
			echo '1|' . $pid . '|' . base64_encode( maybe_serialize( $days ) );
		} else {
			echo '0||';
		}
		`
	).trim();
	const [ existedFlag, postId, daysB64 ] = out.split( '|' );
	shiftSnapshot = {
		staffId,
		year: y,
		month: m,
		postId: postId || '',
		existed: existedFlag === '1',
		daysB64: daysB64 || '',
	};
}

/**
 * snapshotShiftForMonth() で退避したシフトを復元する。
 * 既存していたら退避した日別データを書き戻し、無かったら ensureShiftForMonth が作った shift を削除する。
 */
function restoreShiftForMonth(): void {
	if ( ! shiftSnapshot ) {
		return;
	}
	const snap = shiftSnapshot;
	const sid = Number.parseInt( snap.staffId, 10 );
	if ( snap.existed && snap.postId ) {
		// 既存シフトの _vkbm_shift_days を退避値へ戻す。
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				snap.postId,
				10
			) }, '_vkbm_shift_days', maybe_unserialize( base64_decode( '${ snap.daysB64 }' ) ) );`
		);
	} else {
		// spec が新規作成したシフトを削除する（対象スタッフ・年月で検索して force delete）。
		wpEvalPhp(
			`
			$ex = get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'meta_query'  => array(
					array( 'key' => '_vkbm_shift_resource_id', 'value' => ${ sid } ),
					array( 'key' => '_vkbm_shift_year', 'value' => ${ snap.year } ),
					array( 'key' => '_vkbm_shift_month', 'value' => ${ snap.month } ),
				),
				'fields'      => 'ids',
			) );
			foreach ( $ex as $pid ) { wp_delete_post( (int) $pid, true ); }
			echo 'cleaned';
			`
		);
	}
	shiftSnapshot = null;
}

/**
 * カレンダーの月送り後、非同期の日別空き取得（calendar-meta）の完了を
 * 固定スリープではなく決定的シグナルで待つ（#312 の selectAvailableCalendarDay と同方針）。
 *
 * 月送りボタン押下 → スピナー（calendarLoading 中のみ描画）の detach 待ち →
 * 空き枠のある日（--available。data ロード済みでしか付かない class）の出現待ち、で収束を保証する。
 * 環境差で揺れる waitForTimeout(1000) を排し、フレークを防ぐ。
 *
 * @param page Playwright Page
 */
async function goToNextMonthLoaded( page: Page ): Promise< void > {
	const nextBtn = page.locator( '.vkbm-calendar__nav' ).last();
	if ( ( await nextBtn.count() ) === 0 ) {
		return;
	}
	// 月送りで再取得される calendar-meta レスポンスをクリック前から待ち受ける（取りこぼし防止）。
	const metaResponse = page
		.waitForResponse(
			( res ) =>
				/calendar-meta/.test( res.url() ) && res.status() === 200,
			{ timeout: 15000 }
		)
		.catch( () => null );
	await nextBtn.click();
	// レスポンス着信を待つ（タイミングで取りこぼしても後続の --available 出現待ちで担保）。
	await metaResponse;
	// ローディングスピナーが消える（calendarLoading=false）のを待つ。未出現でも可。
	await page
		.locator( '.vkbm-calendar__spinner' )
		.waitFor( { state: 'detached', timeout: 15000 } )
		.catch( () => {
			// 既に detach 済み・未出現でも最終判定は --available 出現待ちで行う。
		} );
	// 空き枠のある日が出現する（= 月のデータがロード済み）まで待つ。
	await expect
		.poll( async () =>
			page.locator( '.vkbm-calendar__day--available' ).count()
		, { timeout: 15000 } )
		.toBeGreaterThan( 0 );
}

/**
 * 本物の Service_Menu_Editor::save_post() を経由してメニュー設定を保存する。
 * テスト側でクランプ・保存ロジックを再現せず、製品の保存実装を検証するため本物を呼ぶ。
 *
 * @param menuId           サービスメニューの投稿ID（数値文字列）
 * @param allowMulti       メニュー個別の複数人予約許可フラグ
 * @param userSelectable   ユーザー貸切指定チェック
 * @param feePerPerson     貸切料金（1人あたり）
 * @param feeExemptGuests  貸切料金を適用しない申込人数
 */
function savePostViaEditor(
	menuId: string,
	allowMulti: boolean,
	userSelectable: boolean,
	feePerPerson: number,
	feeExemptGuests: number
): void {
	const id = Number.parseInt( menuId, 10 );
	const fee = Number.parseInt( String( feePerPerson ), 10 );
	const exempt = Number.parseInt( String( feeExemptGuests ), 10 );
	const allowLine = allowMulti
		? "$_POST['vkbm_service_menu']['allow_multiple_guests'] = '1';"
		: '';
	const selectableLine = userSelectable
		? "$_POST['vkbm_service_menu']['exclusive_user_selectable'] = '1';"
		: '';
	const phpCode = `
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( empty( $admin ) ) { echo 'ERROR:no-admin'; return; }
		wp_set_current_user( $admin[0]->ID );

		$ref_class    = new ReflectionClass( '\\\\VKBookingManager\\\\Admin\\\\Service_Menu_Editor' );
		$nonce_name   = $ref_class->getConstant( 'NONCE_NAME' );
		$nonce_action = $ref_class->getConstant( 'NONCE_ACTION' );

		// 既存の staff_ids を維持する（save_post は staff_ids も保存するため）。
		$existing_staff_ids = get_post_meta( ${ id }, '_vkbm_staff_ids', true );
		if ( ! is_array( $existing_staff_ids ) ) { $existing_staff_ids = array(); }

		$_POST = array();
		$_POST[ $nonce_name ] = wp_create_nonce( $nonce_action );
		$_POST['vkbm_service_menu'] = array(
			'max_capacity' => '${ MAX_CAPACITY }',
			'min_capacity' => '${ MIN_CAPACITY }',
			'exclusive_fee_per_person'    => '${ fee }',
			'exclusive_fee_exempt_guests' => '${ exempt }',
		);
		${ allowLine }
		${ selectableLine }
		foreach ( $existing_staff_ids as $i => $sid_val ) {
			$_POST['vkbm_service_menu']['staff_ids'][ $i ] = (string) (int) $sid_val;
		}

		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		$editor->save_post( ${ id }, get_post( ${ id } ) );
		echo 'saved';
	`;
	wpEvalPhp( phpCode );
}

/**
 * フロント予約画面で、対象メニュー → 翌月1日 → 最初の空きスロットまで進めて
 * プランサマリー（人数入力・貸切チェックを含む領域）を表示する。
 * 「貸し切りにする」チェックは人数入力の直下、selectedSlot がある時に現れる。
 *
 * @param page Playwright Page
 */
async function gotoFrontSlotSummary( page: Page ): Promise< void > {
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'domcontentloaded' );

	// メニューカードの「予約に進む」をクリック（a タグのこともあるため role に依存しない）。
	const reserveLink = page
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first();
	await reserveLink.waitFor( { state: 'visible', timeout: 15000 } );
	await reserveLink.click();

	// カレンダー描画待ち。
	await page
		.locator( '.vkbm-calendar' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );

	// 翌月へ1回送る（固定スリープではなく calendar-meta 取得完了を待つ）。
	await goToNextMonthLoaded( page );

	// 翌月1日（ラベル「1」・当月内・予約可能）を選ぶ。
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

	// スロット一覧描画待ち → 最初の空きスロットを選択する。
	await page
		.locator( '.vkbm-slot-list__item' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	await page
		.locator( '.vkbm-slot-list__item:not([disabled])' )
		.first()
		.click();
}

// =====================================================================
// A. 管理画面（サービスメニュー詳細）
// =====================================================================
test.describe( 'PR #316: A. 管理画面 - ユーザー貸切指定UIの表示・保存', () => {
	let menuId: string;

	test.beforeAll( () => {
		menuId = getServiceMenuId();
		// 共有フィクスチャ（先頭メニュー）と provider 設定を汚染しないよう、変更前に全退避する。
		snapshotProviderSettings();
		snapshotMenuMeta( menuId );
	} );

	test.afterAll( () => {
		// 退避した provider 設定とメニューメタを完全復元し、後続 spec へ変更を引き継がせない。
		restoreMenuMeta( menuId );
		restoreProviderSettings();
	} );

	test( '指名OFF・複数人予約ON のとき: ユーザー貸切指定チェックと第二段の欄が出力される', () => {
		// Pro版・指名OFF・複数人予約ON の構成にする。
		setProviderGates( false, true );
		// 本物の save_post で「複数人予約ON・ユーザー貸切ON・単価・適用外」を保存する。
		savePostViaEditor( menuId, true, true, FEE_PER_PERSON, FEE_EXEMPT_GUESTS );

		const html = getConditionsMetaboxHtml( menuId );

		// ユーザー貸切指定の行・チェックボックスが出力されること。
		expect( html ).toContain( 'vkbm-exclusive-user-selectable-field' );
		expect( html ).toContain(
			'id="vkbm_service_menu_exclusive_user_selectable"'
		);
		// aria-describedby で説明文が紐付いていること。
		expect( html ).toContain(
			'aria-describedby="vkbm-exclusive-user-selectable-description"'
		);
		// 第二段（貸切料金・適用外人数）の入力欄が出力されること。
		expect( html ).toContain( 'id="vkbm-exclusive-fee-fields"' );
		expect( html ).toContain(
			'id="vkbm_service_menu_exclusive_fee_per_person"'
		);
		expect( html ).toContain(
			'id="vkbm_service_menu_exclusive_fee_exempt_guests"'
		);
		// 保存した値が value に反映されていること（保存→復元）。
		expect( html ).toMatch(
			new RegExp(
				`id="vkbm_service_menu_exclusive_fee_per_person"[^>]*value="${ FEE_PER_PERSON }"`
			)
		);
		expect( html ).toMatch(
			new RegExp(
				`id="vkbm_service_menu_exclusive_fee_exempt_guests"[^>]*value="${ FEE_EXEMPT_GUESTS }"`
			)
		);
	} );

	test( 'メタが保存・復元される（_vkbm_exclusive_user_selectable / 単価 / 適用外人数）', () => {
		setProviderGates( false, true );
		savePostViaEditor( menuId, true, true, FEE_PER_PERSON, FEE_EXEMPT_GUESTS );

		const selectable = wpEvalPhp(
			`echo get_post_meta( ${ Number.parseInt( menuId, 10 ) }, '_vkbm_exclusive_user_selectable', true ) ? '1' : '0';`
		);
		const fee = wpEvalPhp(
			`echo (int) get_post_meta( ${ Number.parseInt( menuId, 10 ) }, '_vkbm_exclusive_fee_per_person', true );`
		);
		const exempt = wpEvalPhp(
			`echo (int) get_post_meta( ${ Number.parseInt( menuId, 10 ) }, '_vkbm_exclusive_fee_exempt_guests', true );`
		);
		expect( selectable ).toBe( '1' );
		expect( fee ).toBe( String( FEE_PER_PERSON ) );
		expect( exempt ).toBe( String( FEE_EXEMPT_GUESTS ) );
	} );

	test( '複数人予約 OFF で保存すると貸切系メタが削除される', () => {
		setProviderGates( false, true );
		// まずON状態で保存しておく。
		savePostViaEditor( menuId, true, true, FEE_PER_PERSON, FEE_EXEMPT_GUESTS );
		// 複数人予約 OFF（フィールドは描画されるが未チェック送信）で保存する。
		savePostViaEditor( menuId, false, false, 0, 0 );

		// register_post_meta の default（false/0）が get_post_meta に効くため、=== '' では判定できない。
		// メタ行が DB から実際に消えたか（metadata_exists）を確認する。
		const id = Number.parseInt( menuId, 10 );
		const selectable = wpEvalPhp(
			`echo metadata_exists( 'post', ${ id }, '_vkbm_exclusive_user_selectable' ) ? 'present' : 'deleted';`
		);
		const fee = wpEvalPhp(
			`echo metadata_exists( 'post', ${ id }, '_vkbm_exclusive_fee_per_person' ) ? 'present' : 'deleted';`
		);
		const exempt = wpEvalPhp(
			`echo metadata_exists( 'post', ${ id }, '_vkbm_exclusive_fee_exempt_guests' ) ? 'present' : 'deleted';`
		);
		expect( selectable ).toBe( 'deleted' );
		expect( fee ).toBe( 'deleted' );
		expect( exempt ).toBe( 'deleted' );
	} );

	test( '実機エディタ: 複数人予約ON時に貸切行が表示され、チェックON で第二段が出る', async ( {
		page,
	} ) => {
		setProviderGates( false, true );
		savePostViaEditor( menuId, true, true, FEE_PER_PERSON, FEE_EXEMPT_GUESTS );

		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ Number.parseInt(
				menuId,
				10
			) }&action=edit`
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 複数人予約を ON にして JS の表示連動を起こす（管理画面メタボックスは可視判定が不安定なため DOM 経由）。
		const multiGuests = page.locator(
			'#vkbm_service_menu_allow_multiple_guests'
		);
		await multiGuests.waitFor( { state: 'attached', timeout: 15000 } );
		await multiGuests.evaluate( ( el ) => {
			const input = el as HTMLInputElement;
			if ( ! input.checked ) {
				input.checked = true;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );

		// ユーザー貸切指定の行が表示される（hidden が外れる）。
		const row = page.locator( '#vkbm-exclusive-user-selectable-field' );
		await expect
			.poll( async () =>
				row.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( false );

		// チェックは保存値で ON のはず → 第二段（貸切料金欄）が表示されている。
		const feeFields = page.locator( '#vkbm-exclusive-fee-fields' );
		await expect
			.poll( async () =>
				feeFields.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( false );

		// チェックを OFF にすると第二段が隠れる（JS 連動）。
		await page
			.locator( '#vkbm_service_menu_exclusive_user_selectable' )
			.evaluate( ( el ) => {
				const input = el as HTMLInputElement;
				input.checked = false;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		await expect
			.poll( async () =>
				feeFields.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( true );

		// スクリーンショット（管理画面・第二段表示状態）。
		await page
			.locator( '#vkbm_service_menu_exclusive_user_selectable' )
			.evaluate( ( el ) => {
				const input = el as HTMLInputElement;
				input.checked = true;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		// 第二段が表示状態であることを確認してからフルページ撮影する（行は metabox 内のため
		// scrollIntoView ではなく fullPage で確実に収める）。
		await expect
			.poll( async () =>
				feeFields.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( false );
		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-316/after-admin-exclusive-fields.png',
			fullPage: true,
		} );
	} );
} );

// =====================================================================
// B/C/D. フロント予約画面・サーバ側ガード
// =====================================================================
test.describe( 'PR #316: B/C/D. フロント貸切指定・料金加算・受付停止', () => {
	let menuId: string;
	let staffId: string;

	test.beforeAll( () => {
		staffId = getStaffId();
		menuId = getServiceMenuId();
		// 共有フィクスチャ（provider 設定・メニューメタ・対象月シフト）を変更前に全退避する。
		snapshotProviderSettings();
		snapshotMenuMeta( menuId );
		snapshotShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );

		setupUserExclusiveMenu( menuId, staffId );
		ensureShiftForMonth( staffId, FRONT_TEST_YEAR, FRONT_TEST_MONTH );
		deleteAllBookings();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test.afterAll( () => {
		// spec が作成した予約を削除し、退避したシフト・メニューメタ・provider 設定を完全復元する。
		deleteAllBookings();
		restoreShiftForMonth();
		restoreMenuMeta( menuId );
		restoreProviderSettings();
	} );

	test( 'B-1/B-2: 空き枠で貸切チェックが有効表示され、ON で貸切料金行が加算される', async ( {
		page,
	} ) => {
		deleteAllBookings();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		await gotoFrontSlotSummary( page );

		// 人数入力を 2（最小催行人数）にする。
		const guestsInput = page.locator( '#vkbm-reservation-guests' );
		await guestsInput.waitFor( { state: 'visible', timeout: 15000 } );
		await guestsInput.fill( '2' );

		// 貸切チェックが有効（disabled でない）で表示される。
		const checkbox = page.locator(
			'.vkbm-plan-summary__exclusive-checkbox'
		);
		await expect( checkbox ).toBeVisible();
		await expect( checkbox ).toBeEnabled();

		// チェックを入れる → 貸切料金行が現れて加算される（1000×2=2000）。
		await checkbox.check();
		const feeLive = page.locator(
			'.vkbm-plan-summary__exclusive-fee-live'
		);
		// aria-live="polite" 領域であること。
		await expect( feeLive ).toHaveAttribute( 'aria-live', 'polite' );
		const feeValue = page.locator(
			'.vkbm-plan-summary__exclusive-fee-value'
		);
		await expect( feeValue ).toBeVisible();
		// 通貨記号に依存せず数値「2,000」または「2000」を含むことを確認する。
		await expect( feeValue ).toContainText( /2[,，]?000/ );

		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-316/after-front-exclusive-fee-on.png',
			fullPage: true,
		} );
	} );

	test( 'B-3: 適用外人数（4名）到達で ¥0 ＋「4名以上のお申し込みのため貸切料金はかかりません」', async ( {
		page,
	} ) => {
		deleteAllBookings();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		await gotoFrontSlotSummary( page );

		const guestsInput = page.locator( '#vkbm-reservation-guests' );
		await guestsInput.waitFor( { state: 'visible', timeout: 15000 } );
		await guestsInput.fill( '2' );

		const checkbox = page.locator(
			'.vkbm-plan-summary__exclusive-checkbox'
		);
		await checkbox.check();

		// 2名のときは加算（2000）。
		const feeValue = page.locator(
			'.vkbm-plan-summary__exclusive-fee-value'
		);
		await expect( feeValue ).toContainText( /2[,，]?000/ );

		// 人数を 4（適用外人数）にする → ¥0 ＋ 注記に切替（行は消えない）。
		await guestsInput.fill( '4' );
		const mutedValue = page.locator(
			'.vkbm-plan-summary__exclusive-fee-value--muted'
		);
		await expect( mutedValue ).toBeVisible();
		// 「4名以上...貸切料金はかかりません」の注記が出る。
		const feeNote = page.locator(
			'.vkbm-plan-summary__exclusive-fee-note'
		);
		await expect( feeNote ).toContainText( '4' );
		await expect( feeNote ).toContainText( '貸切料金はかかりません' );

		// 人数を 3 に戻す → 1000×3=3000 が再加算される。
		await guestsInput.fill( '3' );
		await expect(
			page.locator( '.vkbm-plan-summary__exclusive-fee-value' )
		).toContainText( /3[,，]?000/ );

		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-316/after-front-exclusive-exempt.png',
			fullPage: true,
		} );
	} );

	test( 'C: 既存予約のある枠では貸切チェックが無効化＋理由テキスト表示', async ( {
		page,
	} ) => {
		deleteAllBookings();
		// 対象日の 09:00-10:00 に通常予約を1件入れる（残席はあるが既存予約あり）。
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			'09:00',
			'10:00',
			1,
			false
		);
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		await gotoFrontSlotSummary( page );

		const guestsInput = page.locator( '#vkbm-reservation-guests' );
		await guestsInput.waitFor( { state: 'visible', timeout: 15000 } );
		await guestsInput.fill( '2' );

		// 貸切チェックは表示されるが無効化されている。
		const checkbox = page.locator(
			'.vkbm-plan-summary__exclusive-checkbox'
		);
		await expect( checkbox ).toBeVisible();
		await expect( checkbox ).toBeDisabled();

		// 理由テキストが表示される（色だけでなくテキストで明示）。
		const disabledNote = page.locator(
			'.vkbm-plan-summary__exclusive-note--disabled'
		);
		await expect( disabledNote ).toBeVisible();
		await expect( disabledNote ).toContainText(
			'既にご予約があるため'
		);

		await page.screenshot( {
			path: 'tests/e2e/screenshots/pr-316/after-front-exclusive-occupied.png',
			fullPage: true,
		} );
	} );

	test( 'D: 貸切確定済みの枠は受付停止（is-exclusive-closed・選択不可・予約受付終了）', async ( {
		page,
		request,
	} ) => {
		deleteAllBookings();
		// 対象日の 09:00-10:00 に「貸切」予約を1件入れる（残席はあるが受付停止になるはず）。
		createBooking(
			menuId,
			staffId,
			FRONT_TEST_DATE,
			'09:00',
			'10:00',
			2,
			true
		);
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		// availabilities REST（公開エンドポイント）を直接叩いて該当スロットが exclusive_closed であることを確認する。
		// resource_id は渡さない（指名OFF＝自動割り当ての実フロントと同じ経路にする）。
		// resource_id を渡すと指名（staff-preferred）扱いで貸切枠がスキップされ受付停止枠が一覧から消えるため。
		const res = await request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ Number.parseInt(
				menuId,
				10
			) }&date=${ FRONT_TEST_DATE }&timezone=Asia/Tokyo`
		);
		expect( res.ok() ).toBeTruthy();
		const body = await res.json();
		const bodyStr = JSON.stringify( body );
		// 09:00 枠が exclusive_closed=true として返ること（貸切による受付停止）。
		expect( bodyStr ).toContain( '"exclusive_closed":true' );

		// 実機: 09:00 枠が「予約受付終了」状態（is-exclusive-closed・選択不可）であること。
		await page.context().clearCookies();
		await gotoFrontSlotSummaryUntilSlots( page );
		const closedSlot = page
			.locator( '.vkbm-slot-list__item.is-exclusive-closed' )
			.first();
		await expect( closedSlot ).toBeVisible();
		await expect( closedSlot ).toBeDisabled();
		await expect( closedSlot ).toContainText( '予約受付終了' );
	} );
} );

/**
 * D の実機確認用：メニュー → 翌月1日 → スロット一覧までを表示する（スロット選択はしない）。
 * gotoFrontSlotSummary はスロットをクリックしてしまうため、受付終了枠の確認では一覧表示までに留める。
 *
 * @param page Playwright Page
 */
async function gotoFrontSlotSummaryUntilSlots( page: Page ): Promise< void > {
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'domcontentloaded' );
	const reserveLink = page
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first();
	await reserveLink.waitFor( { state: 'visible', timeout: 15000 } );
	await reserveLink.click();
	await page
		.locator( '.vkbm-calendar' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	// 翌月へ1回送る（固定スリープではなく calendar-meta 取得完了を待つ）。
	await goToNextMonthLoaded( page );
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
	await page
		.locator( '.vkbm-slot-list__item' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}
