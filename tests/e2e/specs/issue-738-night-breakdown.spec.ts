/**
 * 宿泊プランの泊ごとの内訳の表示（#738）
 *
 * 検証内容:
 * 1. 休前日の料金を設定したプランで、金曜から 2 泊（金曜 = 平日、土曜 = 休前日）の予約を確認画面まで進める。
 *    - 日程を選んだ直後、客室一覧の上に泊ごとの区分（平日・休前日）が出る。
 *    - 客室を選ぶと、まとめ欄に「泊ごとの内訳」が畳まずに出て、日付・区分・単価が泊の数だけ並ぶ（#797 で開閉欄から常時表示に変更）。
 *    - 確認画面は「1泊あたりの料金」の行の代わりに、泊ごとの内訳（常に開いた状態）が出る。
 *    - 合計金額が、泊ごとの単価の合計（10,000 + 15,000 = 25,000円）になっている。
 * 2. 休前日・特定日の料金を設定していないプランは、区分も内訳も出さず、「1泊あたりの料金」の行のまま。
 *
 * テストデータと後片付け:
 * - 客室・プランは固有の接頭辞（E2E738）を付けて作り、afterAll で接頭辞を手がかりに削除する。
 * - BM 設定（vkbm_provider_settings）は beforeAll で控え、afterAll の finally で必ず元へ戻す
 *   （業種プリセットが宿泊のまま次の spec へ残ると、他の spec が壊れるため）。
 * - 予約は確定させない（確認画面まで）。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E738';
const OPTION_NAME = 'vkbm_provider_settings';

// 泊のプランの料金（1名1泊あたり）。金曜は平日の料金、土曜は休前日の料金になる。
const WEEKDAY_PRICE = 10000;
const PRE_HOLIDAY_PRICE = 15000;
const NIGHTS = 2;

let originalSettingsJson: string | null = null;
let roomId = '';
let breakdownMenuId = '';
let plainMenuId = '';
// 金曜日のチェックイン日（Asia/Tokyo。Y-m-d）。
let fridayIso = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP コード（最後に ID を echo すること）
 * @param label エラーメッセージ用の名前
 * @return 作成した post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 泊のプランを 1 件作る。
 *
 * @param title           プラン名
 * @param room            担当の客室 ID
 * @param preHolidayPrice 休前日の料金（null なら設定しない）
 * @return プランの post ID
 */
function seedNightMenu(
	title: string,
	room: string,
	preHolidayPrice: number | null
): string {
	const preHolidayLine =
		preHolidayPrice === null
			? ''
			: `update_post_meta( $id, '_vkbm_night_price_pre_holiday', ${ preHolidayPrice } );`;
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( room ) } ) );
		update_post_meta( $id, '_vkbm_base_price', ${ WEEKDAY_PRICE } );
		update_post_meta( $id, '_vkbm_booking_unit', 'night' );
		${ preHolidayLine }
		echo $id;
	`,
		title
	);
}

/**
 * このspecで作ったデータを接頭辞を手がかりに削除する（予約 → シフト → プラン → 客室の順）。
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
		$meta_or = array( 'relation' => 'OR' );
		if ( $menus ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_service_id', 'value' => $menus, 'compare' => 'IN' );
		}
		if ( $rooms ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_resource_id', 'value' => $rooms, 'compare' => 'IN' );
		}
		if ( count( $meta_or ) > 1 ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_or,
			) ) as $bid ) { wp_delete_post( $bid, true ); }
		}
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
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ（元から option が無かった）option を削除して戻す。
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
		// 元から無い（削除済み）なら何もしない。
	}
}

/**
 * 宿泊のカレンダーで、指定した日をクリックする（表示月が違えば翌月へ送る）。
 *
 * @param page Playwright の Page
 * @param iso  選ぶ日（Y-m-d）
 */
async function clickStayDay( page: Page, iso: string ) {
	const [ year, month, day ] = iso.split( '-' ).map( Number );
	const calendar = page.locator( '.vkbm-stay-calendar' );
	await calendar.waitFor( { state: 'visible', timeout: 15000 } );

	// 表示月の見出し（例: 2026年10月）が目的の月になるまで翌月へ送る（最大3回）。
	const monthPattern = new RegExp( `${ year }\\D+0?${ month }\\D` );
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
				hasText: new RegExp( `^${ day }$` ),
			} ),
		} )
		.click();
}

/**
 * 予約ページでプランを開き、金曜から 2 泊（日曜チェックアウト）の日程を選ぶ。
 *
 * @param page   Playwright の Page
 * @param menuId 対象プランの post ID
 */
async function selectFridayStay( page: Page, menuId: string ) {
	await page.goto( `/booking/?menu_id=${ menuId }` );
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
		timeout: 15000,
	} );

	await clickStayDay( page, fridayIso );
	// チェックアウトは 2 日後（日曜）。月をまたぐ場合もあるため、日付から求める。
	const checkOut = new Date( `${ fridayIso }T00:00:00Z` );
	checkOut.setUTCDate( checkOut.getUTCDate() + NIGHTS );
	await clickStayDay( page, checkOut.toISOString().slice( 0, 10 ) );
}

/**
 * 「¥10,000」のような金額の文字列を数値にする。
 *
 * @param text 金額の文字列
 * @return 数値
 */
function toAmount( text: string ): number {
	return Number( text.replace( /[^\d]/g, '' ) );
}

test.describe.serial( '宿泊プランの泊ごとの内訳の表示（#738）', () => {
	test.beforeAll( () => {
		// BM 設定を控える（afterAll で必ず戻す）。
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// 宿泊のサイトにし、休前日は土曜のみ・特定日なしにする（他の設定は残す）。
		wpEvalPhp( `
			$settings = get_option( '${ OPTION_NAME }', array() );
			$settings = is_array( $settings ) ? $settings : array();
			$settings['industry_preset']             = 'lodging';
			$settings['lodging_pre_holiday_weekdays'] = array( 'sat' );
			$settings['lodging_special_dates']       = array();
			update_option( '${ OPTION_NAME }', $settings );
			echo 'ok';
		` );

		// 今日から 3〜30 日後で、金曜が平日の料金になる最初の金曜（翌日の土曜が祝日だと金曜も休前日になるため除く）を探す。
		fridayIso = wpEvalPhp( `
			$tz = new DateTimeZone( 'Asia/Tokyo' );
			for ( $i = 3; $i <= 30; $i++ ) {
				$day = ( new DateTimeImmutable( 'today', $tz ) )->modify( "+{$i} days" );
				$ymd = $day->format( 'Y-m-d' );
				if ( '5' === $day->format( 'N' ) && ! \\VKBookingManager\\Common\\Holiday_Calendar::is_holiday_eve( $ymd ) ) {
					echo $ymd;
					return;
				}
			}
		` ).trim();
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( fridayIso ) ) {
			throw new Error( `Friday lookup failed: "${ fridayIso }"` );
		}

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
		// 当月＋翌月のシフト（泊の販売日。月境界でも残るよう両方）。
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}
		breakdownMenuId = seedNightMenu(
			`${ PREFIX } Breakdown Plan`,
			roomId,
			PRE_HOLIDAY_PRICE
		);
		plainMenuId = seedNightMenu( `${ PREFIX } Plain Plan`, roomId, null );
	} );

	test.afterAll( () => {
		// 片付けが例外で止まっても、BM 設定は必ず元へ戻す（次の spec への状態漏れ防止）。
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '金曜から 2 泊: 客室一覧の上・まとめ欄・確認画面に泊ごとの内訳が出て、合計が泊ごとの単価の合計になる', async ( {
		page,
	} ) => {
		await selectFridayStay( page, breakdownMenuId );

		// 日程を選んだ直後、客室一覧の上に泊ごとの区分が出る（金曜 = 平日、土曜 = 休前日）。
		const nightRates = page.locator( '.vkbm-lodging__night-rates' );
		await expect( nightRates ).toBeVisible( { timeout: 15000 } );
		const rateRows = nightRates.locator( '.vkbm-lodging__night' );
		await expect( rateRows ).toHaveCount( NIGHTS );
		await expect( rateRows.nth( 0 ) ).toContainText( '平日' );
		await expect( rateRows.nth( 1 ) ).toContainText( '休前日' );
		// 区分の表示は客室一覧より上にある。
		const rateBox = await nightRates.boundingBox();
		const roomListBox = await page
			.locator( '.vkbm-lodging__rooms-section' )
			.boundingBox();
		expect( rateBox && roomListBox ).toBeTruthy();
		expect( rateBox!.y ).toBeLessThan( roomListBox!.y );

		// 客室を選ぶと、まとめ欄に「泊ごとの内訳」が畳まずに出る（#797 で開閉欄から常時表示に変更）。
		await page.locator( '.vkbm-lodging__room' ).first().click();
		const breakdown = page.locator(
			'.vkbm-lodging__summary .vkbm-lodging__breakdown'
		);
		await expect( breakdown ).toBeVisible();
		await expect( breakdown.locator( 'details, summary' ) ).toHaveCount(
			0
		);
		await expect(
			breakdown.locator( '.vkbm-lodging__breakdown-label' )
		).toHaveText( '泊ごとの内訳' );

		// 日付・区分・単価が泊の数だけ出る。
		const breakdownRows = breakdown.locator( '.vkbm-lodging__night' );
		await expect( breakdownRows ).toHaveCount( NIGHTS );
		await expect( breakdownRows.nth( 0 ) ).toContainText( '平日' );
		await expect( breakdownRows.nth( 0 ) ).toContainText( '¥10,000' );
		await expect( breakdownRows.nth( 1 ) ).toContainText( '休前日' );
		await expect( breakdownRows.nth( 1 ) ).toContainText( '¥15,000' );

		// 「予約に進む」で確認画面へ。
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await confirm.waitFor( { state: 'visible', timeout: 15000 } );

		// 「1泊あたりの料金」の行は無く、泊ごとの内訳が常に開いた状態で出る。
		await expect( confirm ).not.toContainText( '1泊あたりの料金' );
		await expect( confirm ).toContainText( '泊ごとの料金' );
		await expect( confirm.locator( 'details' ) ).toHaveCount( 0 );
		const confirmRows = confirm.locator( '.vkbm-lodging__night' );
		await expect( confirmRows ).toHaveCount( NIGHTS );
		await expect( confirmRows.nth( 0 ) ).toContainText( '平日' );
		await expect( confirmRows.nth( 1 ) ).toContainText( '休前日' );

		// 合計金額が、泊ごとの単価の合計になっている（10,000 + 15,000 = 25,000円）。
		const nightPrices = await confirm
			.locator( '.vkbm-lodging__night-price' )
			.allInnerTexts();
		expect( nightPrices.map( toAmount ) ).toEqual( [
			WEEKDAY_PRICE,
			PRE_HOLIDAY_PRICE,
		] );
		const total = toAmount(
			await confirm
				.locator( '.vkbm-confirm__summary-item-value--price' )
				.first()
				.innerText()
		);
		expect( total ).toBe( WEEKDAY_PRICE + PRE_HOLIDAY_PRICE );
		expect( total ).toBe(
			nightPrices.map( toAmount ).reduce( ( sum, n ) => sum + n, 0 )
		);
	} );

	test( '休前日・特定日の料金を設定していないプラン: 区分も内訳も出さず、確認画面は「1泊あたりの料金」のまま', async ( {
		page,
	} ) => {
		await selectFridayStay( page, plainMenuId );

		// 客室一覧は出るが、泊ごとの区分は出ない。
		await expect(
			page.locator( '.vkbm-lodging__room' ).first()
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-lodging__night-rates' )
		).toHaveCount( 0 );

		// 客室を選んでも、まとめ欄に泊ごとの内訳は出ない。
		await page.locator( '.vkbm-lodging__room' ).first().click();
		await expect( page.locator( '.vkbm-lodging__breakdown' ) ).toHaveCount(
			0
		);

		// 確認画面は従来どおり「1泊あたりの料金」の行で、泊ごとの内訳は出ない。
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await confirm.waitFor( { state: 'visible', timeout: 15000 } );
		await expect( confirm ).toContainText( '1泊あたりの料金' );
		await expect( confirm.locator( '.vkbm-lodging__night' ) ).toHaveCount(
			0
		);
		await expect( confirm ).toContainText(
			`¥${ ( WEEKDAY_PRICE * NIGHTS ).toLocaleString( 'en-US' ) }`
		);
	} );
} );
