/**
 * 宿泊プランの泊ごとの内訳の表示（#738）
 *
 * 検証内容:
 * 1. 休前日の料金を設定したプランで、金曜から 2 泊（金曜 = 平日、土曜 = 休前日）の予約を確認画面まで進める。
 *    - 日程を選んだ直後、客室一覧の上に泊ごとの区分（平日・休前日）が出る。
 *    - 同じ欄に泊ごとの単価と泊の合計も出る（#969 で内訳を日程選択直後の欄へ移した。単価は客室によらないため、客室を選ぶ前に出せる）。
 *    - 客室を選んでも、まとめ欄には泊ごとの内訳を重ねて出さない（泊数と合計金額だけ）。
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
		// @step 管理画面で業種を宿泊にし、休前日を土曜のみ、特定日なしにしておく
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

		// @step 客室「E2E738 Room」を作成し、今月と来月のシフトを登録しておく
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
		// @step 予約の単位「泊」・基本料金 10,000円で、休前日の料金 15,000円のプランと、休前日の料金なしのプランを作成しておく
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
		// @step 休前日の料金ありのプランの予約ページを開き、3 日後以降の金曜をチェックイン日、その 2 日後の日曜をチェックアウト日に選ぶ
		await selectFridayStay( page, breakdownMenuId );

		// 日程を選んだ直後、客室一覧の上に泊ごとの区分が出る（金曜 = 平日、土曜 = 休前日）。
		// @check 客室一覧の上に、泊ごとの区分（1 泊目「平日」、2 泊目「休前日」）が表示される
		const nightRates = page.locator( '.vkbm-lodging__night-rates' );
		await expect( nightRates ).toBeVisible( { timeout: 15000 } );
		const rateRows = nightRates.locator( '.vkbm-lodging__night' );
		await expect( rateRows ).toHaveCount( NIGHTS );
		await expect( rateRows.nth( 0 ) ).toContainText( '平日' );
		await expect( rateRows.nth( 0 ) ).toContainText( '¥10,000' );
		await expect( rateRows.nth( 1 ) ).toContainText( '休前日' );
		await expect( rateRows.nth( 1 ) ).toContainText( '¥15,000' );
		// @check 同じ欄に、泊の合計（10,000 + 15,000 = 25,000円）が出る
		await expect(
			nightRates.locator( '.vkbm-lodging__night-total' )
		).toHaveText( '2泊合計¥25,000' );
		// 区分の表示は客室一覧より上にある。
		const rateBox = await nightRates.boundingBox();
		const roomListBox = await page
			.locator( '.vkbm-lodging__rooms-section' )
			.boundingBox();
		expect( rateBox && roomListBox ).toBeTruthy();
		expect( rateBox!.y ).toBeLessThan( roomListBox!.y );

		// 客室を選んでも、まとめ欄に同じ内訳を重ねて出さない（泊数と合計金額だけ）。
		// @step 客室を選ぶ
		await page.locator( '.vkbm-lodging__room' ).first().click();
		// @check まとめ欄には泊ごとの内訳が出ず、合計金額（¥25,000）だけが出る
		const summaryBox = page.locator( '.vkbm-lodging__summary' );
		await expect(
			summaryBox.locator( '.vkbm-lodging__breakdown' )
		).toHaveCount( 0 );
		await expect(
			summaryBox.locator( '.vkbm-lodging__night' )
		).toHaveCount( 0 );
		await expect( summaryBox ).toContainText( '¥25,000' );

		// 「予約に進む」で確認画面へ。
		// @step 「予約に進む」ボタンを押して確認画面へ進む
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await confirm.waitFor( { state: 'visible', timeout: 15000 } );

		// 「1泊あたりの料金」の行は無く、泊ごとの内訳が常に開いた状態で出る。
		// @check 確認画面に「1泊あたりの料金」の行は無く、「泊ごとの料金」が開いた状態で「平日」「休前日」の 2 泊分表示される
		await expect( confirm ).not.toContainText( '1泊あたりの料金' );
		await expect( confirm ).toContainText( '泊ごとの料金' );
		await expect( confirm.locator( 'details' ) ).toHaveCount( 0 );
		const confirmRows = confirm.locator( '.vkbm-lodging__night' );
		await expect( confirmRows ).toHaveCount( NIGHTS );
		await expect( confirmRows.nth( 0 ) ).toContainText( '平日' );
		await expect( confirmRows.nth( 1 ) ).toContainText( '休前日' );

		// 合計金額が、泊ごとの単価の合計になっている（10,000 + 15,000 = 25,000円）。
		// @check 合計金額が泊ごとの料金の合計（10,000 + 15,000 = 25,000円）になっている
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
		// @step 休前日の料金なしのプランの予約ページを開き、3 日後以降の金曜をチェックイン日、その 2 日後の日曜をチェックアウト日に選ぶ
		await selectFridayStay( page, plainMenuId );

		// 客室一覧は出るが、泊ごとの区分は出ない。
		// @check 客室一覧は出るが、泊ごとの区分は出ない
		await expect(
			page.locator( '.vkbm-lodging__room' ).first()
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.vkbm-lodging__night-rates' )
		).toHaveCount( 0 );

		// 客室を選んでも、まとめ欄に泊ごとの内訳は出ない。
		// @step 客室を選ぶ
		await page.locator( '.vkbm-lodging__room' ).first().click();
		// @check まとめ欄に泊ごとの内訳は出ない
		await expect( page.locator( '.vkbm-lodging__breakdown' ) ).toHaveCount(
			0
		);

		// 確認画面は従来どおり「1泊あたりの料金」の行で、泊ごとの内訳は出ない。
		// @step 「予約に進む」ボタンを押して確認画面へ進む
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await confirm.waitFor( { state: 'visible', timeout: 15000 } );
		// @check 確認画面に「1泊あたりの料金」の行が出て、泊ごとの内訳は出ず、合計が 20,000円になっている
		await expect( confirm ).toContainText( '1泊あたりの料金' );
		await expect( confirm.locator( '.vkbm-lodging__night' ) ).toHaveCount(
			0
		);
		await expect( confirm ).toContainText(
			`¥${ ( WEEKDAY_PRICE * NIGHTS ).toLocaleString( 'en-US' ) }`
		);
	} );
	/* eslint-disable jsdoc/check-tag-names -- テスト一覧用の @title を使用する。 */
	/**
	 * @title 宿泊の区分別料金内訳と人数変更（#969）
	 */
	/* eslint-enable jsdoc/check-tag-names */
	test( '#969: 予約者区分を縦積みで表示し、人数変更で泊小計・連泊合計を更新する', async ( {
		page,
	} ) => {
		// @step 既存データと区別したプランを作り、大人・子供・乳児の料金区分を設定する
		const menuId = seedNightMenu(
			`${ PREFIX } Tier Plan`,
			roomId,
			PRE_HOLIDAY_PRICE
		);
		wpEvalPhp( `
			update_post_meta( ${ menuId }, '_vkbm_allow_multiple_guests', '1' );
			update_post_meta( ${ menuId }, '_vkbm_max_capacity', 8 );
			update_post_meta( ${ menuId }, '_vkbm_price_tiers', array(
				array( 'label' => '一般大人宿泊者区分', 'price' => 1234567, 'pre_holiday_price' => 12345678 ),
				array( 'label' => '食事と布団付きのお子様', 'price' => 1234567 ),
				array( 'label' => '乳児', 'price' => 0 )
			) );
		` );
		// @step 初期描画時から常設される料金通知が空であることを確認する
		await selectFridayStay( page, menuId );
		const priceStatus = page.locator( '.vkbm-lodging__price-status' );
		await expect( priceStatus ).toHaveText( '' );
		await page.locator( '.vkbm-lodging__room' ).first().click();
		const inputs = page.locator( '.vkbm-plan-summary__guest-tier input' );
		const summary = page.locator( '.vkbm-lodging__summary' );
		const rates = page.locator( '.vkbm-lodging__night-rates' );
		const nights = rates.locator(
			'.vkbm-lodging__nights--priced > .vkbm-lodging__night'
		);

		// @check 客室を選ぶ前から日程選択直後の欄に出ていて、まとめ欄には内訳を重ねて出さない
		await expect( summary.locator( '.vkbm-lodging__night' ) ).toHaveCount(
			0
		);
		// @check 親h3の直下に各泊のh4があり、泊のリスト項目と見出しが関連付く
		await expect( rates.getByRole( 'heading', { level: 3 } ) ).toHaveText(
			'泊ごとの料金区分'
		);
		await expect( rates.getByRole( 'heading', { level: 4 } ) ).toHaveCount(
			2
		);
		const headingId = await nights
			.first()
			.getByRole( 'heading', { level: 4 } )
			.getAttribute( 'id' );
		await expect( nights.first() ).toHaveAttribute(
			'aria-labelledby',
			headingId!
		);
		await expect( priceStatus ).toHaveAttribute( 'role', 'status' );
		await expect( priceStatus ).toHaveAttribute( 'aria-atomic', 'true' );
		await expect( priceStatus ).toHaveText( '' );

		// @check 人数欄横に最低単価を出さず、0名の子供・乳児は内訳に表示しない
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tier-price' )
		).toHaveCount( 0 );
		await expect( nights ).toHaveCount( 2 );
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-guest' )
		).toHaveCount( 1 );
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-heading' )
		).toContainText( '平日' );
		await expect(
			nights.nth( 1 ).locator( '.vkbm-lodging__night-heading' )
		).toContainText( '休前日' );

		// 表の要素に目印を付け、人数の変更による再取得の間に外れて作り直されないことを後で確かめる。
		await rates
			.locator( '.vkbm-lodging__nights--priced' )
			.evaluate( ( element ) => {
				element.setAttribute( 'data-e2e-mounted', 'yes' );
			} );

		// @step 大人2名・子供1名に変更する（子供の休前日料金は未設定）
		await inputs.nth( 0 ).fill( '2' );
		await inputs.nth( 1 ).fill( '1' );
		// @check 単価・人数・小計と泊小計・2泊合計がその場で更新される
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-guest' )
		).toHaveCount( 2 );
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-guest' ).nth( 0 )
		).toContainText( '× 2' );
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-guest' ).nth( 0 )
		).toContainText( '¥2,469,134' );
		await expect(
			nights.nth( 1 ).locator( '.vkbm-lodging__night-guest' ).nth( 1 )
		).toContainText( '¥1,234,567' );
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-subtotal' )
		).toHaveText( 'この泊の小計¥3,703,701' );
		await expect(
			nights.nth( 1 ).locator( '.vkbm-lodging__night-subtotal' )
		).toHaveText( 'この泊の小計¥25,925,923' );
		await expect(
			rates.locator( '.vkbm-lodging__night-total' )
		).toHaveText( '2泊合計¥29,629,624' );
		await expect(
			summary.locator( '.vkbm-lodging__summary-list--total' )
		).toContainText( '¥29,629,624' );
		// @check 再取得を挟んでも、泊ごとの表は作り直されない（外れると高さが跳ね、開いた欄が閉じる）
		await expect(
			rates.locator(
				'.vkbm-lodging__nights--priced[data-e2e-mounted="yes"]'
			)
		).toHaveCount( 1 );
		await expect( rates ).not.toContainText( '乳児' );

		// @check 全内訳を読み直さず、更新後の泊数と合計だけを通知する
		await expect( priceStatus ).toHaveText(
			'料金を更新しました：2泊、合計¥29,629,624。'
		);
		await expect(
			rates.locator( '[aria-live], [role="status"]' )
		).toHaveCount( 0 );

		// @step 内訳の箱の幅を、1行に並べる境目（32em）の前後と、375px・280pxの幅に変える
		// 切替は画面幅ではなく箱の幅（コンテナクエリ）で決まるため、箱の幅を直接変えて確かめる。
		for ( const em of [ 17.5, 20, 31.5, 32.5, 50 ] ) {
			const layout = await rates.evaluate( ( element, widthEm ) => {
				const fontSize = parseFloat(
					window.getComputedStyle( element ).fontSize
				);
				( element as HTMLElement ).style.width = `${
					widthEm * fontSize
				}px`;
				( element as HTMLElement ).style.maxWidth = 'none';
				const list = element.querySelector(
					'.vkbm-lodging__nights--priced'
				)!;
				const listRect = list.getBoundingClientRect();
				const rectsOf = ( node: Element ) => {
					// 読み上げ専用の「小計」（.vkbm-lodging__sr-only）は見える文字ではないので、字面の計測から除く。
					const walker = document.createTreeWalker(
						node,
						NodeFilter.SHOW_TEXT
					);
					const rects: DOMRect[] = [];
					while ( walker.nextNode() ) {
						const text = walker.currentNode;
						if (
							text.parentElement?.closest(
								'.vkbm-lodging__sr-only'
							)
						) {
							continue;
						}
						const range = document.createRange();
						range.selectNodeContents( text );
						rects.push( ...Array.from( range.getClientRects() ) );
					}
					return rects;
				};
				return {
					overflow: element.scrollWidth > element.clientWidth,
					rows: Array.from(
						list.querySelectorAll( '.vkbm-lodging__night-guest' )
					).map( ( row ) => {
						const parts = [
							row.querySelector(
								'.vkbm-lodging__night-guest-label'
							)!,
							...Array.from(
								row.querySelectorAll(
									'.vkbm-lodging__night-calc > span'
								)
							),
							row.querySelector( '.vkbm-lodging__night-price' )!,
						];
						const textRects = parts.map( rectsOf );
						const boxes = parts.map( ( part ) =>
							part.getBoundingClientRect()
						);
						const overlaps = textRects.some( ( group, index ) =>
							textRects
								.slice( index + 1 )
								.some( ( other ) =>
									group.some( ( left ) =>
										other.some(
											( right ) =>
												left.left < right.right - 0.5 &&
												left.right > right.left + 0.5 &&
												left.top < right.bottom - 0.5 &&
												left.bottom > right.top + 0.5
										)
									)
								)
						);
						return {
							// 人数の右端から小計の左端までの間隔（広い箱では単価 ↔ 人数より広げる）。
							gapCountToSubtotal:
								Math.min(
									...textRects[ 3 ].map( ( r ) => r.left )
								) -
								Math.max(
									...textRects[ 2 ].map( ( r ) => r.right )
								),
							gapUnitToCount:
								Math.min(
									...textRects[ 2 ].map( ( r ) => r.left )
								) -
								Math.max(
									...textRects[ 1 ].map( ( r ) => r.right )
								),
							overlaps,
							numericUnbroken: textRects
								.slice( 1 )
								.every( ( group ) => group.length === 1 ),
							contained: textRects
								.flat()
								.every(
									( rect ) =>
										rect.left >= listRect.left - 1 &&
										rect.right <= listRect.right + 1
								),
							// 区分名が単価の上の段にあるか（狭い幅の構造）。
							labelAbove: boxes[ 0 ].bottom <= boxes[ 1 ].top + 1,
							// 単価と小計が同じ段にあるか（広い幅の構造）。
							oneLine:
								Math.abs( boxes[ 1 ].top - boxes[ 3 ].top ) < 4,
						};
					} ),
				};
			}, em );
			// @check 長い区分名と7〜8桁金額が重ならず、箱からはみ出さない
			expect( layout.overflow ).toBe( false );
			for ( const row of layout.rows ) {
				expect( row.contained ).toBe( true );
				expect( row.overlaps ).toBe( false );
				expect( row.numericUnbroken ).toBe( true );
				// @check 箱が32em以下なら区分名を上の段に、広ければ1行に並べる
				if ( em <= 32 ) {
					expect( row.labelAbove ).toBe( true );
				} else {
					expect( row.oneLine ).toBe( true );
					// @check 広い箱では、人数 ↔ 小計の間隔が 24px 以上で、単価 ↔ 人数の間隔より広い
					expect( row.gapCountToSubtotal ).toBeGreaterThanOrEqual(
						23
					);
					expect( row.gapCountToSubtotal ).toBeGreaterThan(
						row.gapUnitToCount
					);
				}
			}
		}

		// @step 子供を0名に戻す
		await inputs.nth( 1 ).fill( '0' );
		// @check 子供の行が消え、合計も大人2名分に戻る
		await expect(
			nights.nth( 0 ).locator( '.vkbm-lodging__night-guest' )
		).toHaveCount( 1 );
		await expect(
			rates.locator( '.vkbm-lodging__night-total' )
		).toHaveText( '2泊合計¥27,160,490' );
		await expect(
			summary.locator( '.vkbm-lodging__summary-list--total' )
		).toContainText( '¥27,160,490' );
	} );
} );
