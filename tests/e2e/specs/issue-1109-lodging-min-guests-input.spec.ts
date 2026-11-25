/**
 * issue #1109: 宿泊の人数欄の下限・初期値・説明文を、サーバーが受付で断る最低申し込み人数にそろえる。
 *
 * 検証内容（宿泊のサイト・Pro 版のみ。客室指定は ON）:
 *   1. 「1名1泊あたり」・定員 6・最低 3 名: 人数欄の下限と初期値が 3、説明文が「3名 から 6名」。
 *   2. 同じ設定で料金区分あり: 最初の区分の初期値が 3。日程を選んだあと合計を 2 にすると警告が出て、
 *      空室の照会（lodging-availabilities）が送られず「予約へ進む」が押せなくなる。3 に戻すと照会が再開し、押せる状態に戻る。
 *   3. 「1室1泊あたり」・同じ保存値: 人数で受付を制限しないため、人数欄は従来どおり 1 から。
 *
 * テストデータは接頭辞（E2E1109）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
// @category 宿泊予約
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E1109';
const OPTION_NAME = 'vkbm_provider_settings';

let originalSettingsJson: string | null = null;
let roomId = '';
let perGuestMenuId = '';
let perGuestTierMenuId = '';
let perRoomMenuId = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP（最後に ID を echo する）
 * @param label エラーメッセージ用の名前
 * @return post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 定員 6・最低 3 名の泊のプランを作る。
 *
 * @param title     プラン名（接頭辞を含める）
 * @param priceUnit 料金の単位（per_guest / per_room）
 * @param withTiers 料金区分（大人・子供）を付けるか
 * @return post ID（数値文字列）
 */
function createNightMenu(
	title: string,
	priceUnit: 'per_guest' | 'per_room',
	withTiers: boolean
): string {
	const tierLines = withTiers
		? `update_post_meta( $id, '_vkbm_price_tiers', array(
			array( 'label' => '大人', 'price' => 8000 ),
			array( 'label' => '子供', 'price' => 4000 ),
		) );`
		: '';
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
		update_post_meta( $id, '_vkbm_base_price', 8000 );
		update_post_meta( $id, '_vkbm_booking_unit', 'night' );
		update_post_meta( $id, '_vkbm_night_price_unit', '${ priceUnit }' );
		update_post_meta( $id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $id, '_vkbm_max_capacity', 6 );
		update_post_meta( $id, '_vkbm_min_capacity', 3 );
		${ tierLines }
		echo $id;
	`,
		title
	);
}

/**
 * このスペックが作ったデータを接頭辞で探して消す（シフト → プラン → 客室の順）。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		$find = static function ( $post_type ) use ( $prefix ) {
			$ids = array();
			foreach ( get_posts( array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				's'           => $prefix,
			) ) as $p ) {
				if ( 0 === strpos( $p->post_title, $prefix ) ) { $ids[] = $p->ID; }
			}
			return $ids;
		};
		$rooms = $find( 'vkbm_resource' );
		$menus = $find( 'vkbm_service_menu' );
		if ( $rooms ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_vkbm_shift_resource_id', 'value' => $rooms, 'compare' => 'IN' ) ),
			) ) as $sid ) { wp_delete_post( $sid, true ); }
		}
		foreach ( $menus as $mid ) { wp_delete_post( $mid, true ); }
		foreach ( $rooms as $rid ) { wp_delete_post( $rid, true ); }
		echo 'ok';
	` );
}

/**
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ option ごと消す。
 */
function restoreProviderSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs(
			[
				'option',
				'update',
				OPTION_NAME,
				originalSettingsJson,
				'--format=json',
			],
			{ stdio: 'pipe' }
		);
		return;
	}
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
	} catch {
		// 元から無いなら何もしない。
	}
}

/**
 * 予約ページで泊のプランを開き、宿泊の画面が出るまで待つ。
 *
 * @param page   Playwright の Page
 * @param menuId プランの post ID
 */
async function openPlan( page: Page, menuId: string ) {
	await page.goto( `/booking/?menu_id=${ menuId }` );
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
		timeout: 15000,
	} );
}

/**
 * 今日（Asia/Tokyo）から offset 日後の年・月・日を返す。
 *
 * @param offset 日数
 * @return 年・月・日（数値）
 */
function tokyoDateAfter( offset: number ) {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return {
		year: Number( year ),
		month: Number( month ),
		day: Number( day ),
	};
}

/**
 * 宿泊のカレンダーで、指定した日を押す（表示月が違えば翌月へ送る）。
 *
 * @param page       Playwright の Page
 * @param date       選ぶ日（Asia/Tokyo の年・月・日）
 * @param date.year  年
 * @param date.month 月
 * @param date.day   日
 */
async function clickStayDay(
	page: Page,
	date: { year: number; month: number; day: number }
) {
	const calendar = page.locator( '.vkbm-stay-calendar' );
	await calendar.waitFor( { state: 'visible', timeout: 15000 } );
	const monthPattern = new RegExp( `${ date.year }\\D+0?${ date.month }\\D` );
	for ( let i = 0; i < 3; i++ ) {
		const heading =
			( await calendar
				.locator( '.vkbm-calendar__current' )
				.innerText() ) + ' ';
		if ( monthPattern.test( heading ) ) {
			break;
		}
		await calendar.locator( '.vkbm-calendar__nav' ).last().click();
	}
	await calendar
		.locator( 'button.vkbm-calendar__day' )
		.filter( {
			has: page.locator( '.vkbm-calendar__day-label', {
				hasText: new RegExp( `^${ date.day }$` ),
			} ),
		} )
		.click();
}

/**
 * 空室の照会（/vkbm/v1/lodging-availabilities）のリクエストかを返す。
 * カレンダーの空き状況（lodging-calendar-meta）は数えない。
 *
 * @param url リクエストの URL
 * @return 空室の照会なら true
 */
function isAvailabilityRequest( url: string ): boolean {
	return url.includes( 'lodging-availabilities' );
}

/**
 * 空室の照会の URL から、送った人数（guests）を数値で返す。
 *
 * @param url リクエストの URL
 * @return guests パラメータの値（無ければ NaN）
 */
function requestedGuests( url: string ): number {
	return Number( new URL( url ).searchParams.get( 'guests' ) );
}

test.describe.serial( 'issue #1109: 宿泊の人数欄と最低申し込み人数', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// @step 客室「E2E1109 Room」を作成し、今月と来月のシフトを登録しておく
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Room',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			echo $id;
		`,
			'room'
		);
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}

		// @step 定員 6・最低 3 名で、「1名1泊あたり」（料金区分なし・あり）と「1室1泊あたり」の泊のプランを作成しておく
		perGuestMenuId = createNightMenu(
			`${ PREFIX } Per Guest`,
			'per_guest',
			false
		);
		perGuestTierMenuId = createNightMenu(
			`${ PREFIX } Per Guest Tiers`,
			'per_guest',
			true
		);
		perRoomMenuId = createNightMenu(
			`${ PREFIX } Per Room`,
			'per_room',
			false
		);

		// @step 管理画面で業種を宿泊にし、客室指定（指名機能）を ON にしておく
		await configureProviderSettings( {
			industry_preset: 'lodging',
			staff_enabled: 1,
			resource_label_singular: '客室',
			resource_label_plural: '客室',
			provider_reservation_deadline_hours: 0,
			provider_max_advance_booking_days: 0,
		} );
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '1名1泊あたりでは、人数欄の下限・初期値・説明文が最低申し込み人数にそろう', async ( {
		page,
	} ) => {
		// @step 「1名1泊あたり」のプランの予約ページを開く
		await openPlan( page, perGuestMenuId );

		// @check 人数欄の下限と初期値が 3 で、説明文が「宿泊人数は 3名 から 6名 までです。」になる
		const guestsInput = page.locator(
			'input.vkbm-plan-summary__guests-select'
		);
		await expect( guestsInput ).toBeVisible( { timeout: 15000 } );
		await expect( guestsInput ).toHaveAttribute( 'min', '3' );
		await expect( guestsInput ).toHaveValue( '3' );
		await expect(
			page.locator( '.vkbm-lodging .vkbm-plan-summary__hint' ).first()
		).toHaveText( '宿泊人数は 3名 から 6名 までです。' );

		// @step 人数に 1 を入れて欄を離れる
		await guestsInput.fill( '1' );
		await guestsInput.blur();
		// @check 下限の 3 に戻る
		await expect( guestsInput ).toHaveValue( '3' );
	} );

	test( '料金区分があるときは、最初の区分が下限の人数で始まり、合計が下限未満なら警告を出して空室の照会と予約へ進むのを止める', async ( {
		page,
	} ) => {
		// 空室の照会（lodging-availabilities）の URL を送った順に控える。
		const availabilityUrls: string[] = [];
		page.on( 'request', ( request ) => {
			if ( isAvailabilityRequest( request.url() ) ) {
				availabilityUrls.push( request.url() );
			}
		} );

		// @step 料金区分のある「1名1泊あたり」のプランの予約ページを開く
		await openPlan( page, perGuestTierMenuId );

		const tierInputs = page.locator(
			'.vkbm-plan-summary__guest-tier input'
		);
		await expect( tierInputs.first() ).toBeVisible( { timeout: 15000 } );
		// @check 大人の初期値が 3、子供が 0 で、警告は出ていない
		await expect( tierInputs.nth( 0 ) ).toHaveValue( '3' );
		await expect( tierInputs.nth( 1 ) ).toHaveValue( '0' );
		const warning = page.locator(
			'.vkbm-plan-summary__guest-tiers .vkbm-plan-summary__hint--warning'
		);
		await expect( warning ).toHaveCount( 0 );

		// @step 2 日後にチェックイン、4 日後にチェックアウトの日程を選ぶ
		await clickStayDay( page, tokyoDateAfter( 2 ) );
		await clickStayDay( page, tokyoDateAfter( 4 ) );
		const proceedButton = page
			.locator( '.vkbm-plan-summary__action' )
			.first();
		// @check 3 名で空室が照会され、「予約へ進む」が押せる
		await expect( proceedButton ).toBeEnabled( { timeout: 15000 } );
		expect( availabilityUrls.map( requestedGuests ) ).toContain( 3 );

		// ここまでに送られた照会の件数を控え、以降に送られた照会だけを見る。
		const sentBeforeChange = availabilityUrls.length;

		// @step 大人を 2 にする（合計 2 名）
		await tierInputs.nth( 0 ).fill( '2' );
		// @check 「人数を 3 名以上にしてください。」の警告が出て、「予約へ進む」が押せなくなる
		await expect( warning ).toHaveText( '人数を 3 名以上にしてください。' );
		await expect( proceedButton ).toBeDisabled();

		// @step 子供を 1 にする（合計 3 名）
		const resumedRequest = page.waitForRequest(
			( request ) =>
				isAvailabilityRequest( request.url() ) &&
				requestedGuests( request.url() ) === 3,
			{ timeout: 15000 }
		);
		await tierInputs.nth( 1 ).fill( '1' );
		// @check 警告が消え、3 名で空室の照会が再開し、「予約へ進む」が押せる状態に戻る
		await resumedRequest;
		await expect( warning ).toHaveCount( 0 );
		await expect( proceedButton ).toBeEnabled( { timeout: 15000 } );

		// @check 合計 2 名の間は空室の照会が 1 件も送られていない（人数を変えてから送られた照会は、3 名に戻したあとの分だけ）
		// 照会は人数の変更ごとに順に送られるため、3 名に戻したあとの照会を待ってから数えれば、2 名の間の照会の有無を取りこぼさない。
		const sentAfterChange = availabilityUrls
			.slice( sentBeforeChange )
			.map( requestedGuests );
		expect( sentAfterChange.length ).toBeGreaterThan( 0 );
		expect( sentAfterChange.every( ( guests ) => guests === 3 ) ).toBe(
			true
		);
	} );

	test( '1室1泊あたりでは、保存値が残っていても人数欄は 1 から', async ( {
		page,
	} ) => {
		// @step 「1室1泊あたり」のプランの予約ページを開く
		await openPlan( page, perRoomMenuId );

		// @check 人数欄の下限と初期値が 1 で、説明文が「宿泊人数は 1名 から 6名 までです。」になる
		const guestsInput = page.locator(
			'input.vkbm-plan-summary__guests-select'
		);
		await expect( guestsInput ).toBeVisible( { timeout: 15000 } );
		await expect( guestsInput ).toHaveAttribute( 'min', '1' );
		await expect( guestsInput ).toHaveValue( '1' );
		await expect(
			page.locator( '.vkbm-lodging .vkbm-plan-summary__hint' ).first()
		).toHaveText( '宿泊人数は 1名 から 6名 までです。' );
	} );
} );
