/**
 * issue #1131: 宿泊カレンダーで、次に押せる日に「○」が付き、凡例が段階で切り替わることを検証する。
 */
// @category 宿泊予約
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getTokyoDateParts,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E1131';
const OPTION_NAME = 'vkbm_provider_settings';
let originalSettingsJson: string | null = null;
let roomId = '';
let menuId = '';

// 凡例の文（日本語）。チェックイン日を選ぶ段階と、チェックアウト日を選ぶ段階で切り替わる。
const CHECK_IN_LEGEND = [
	'○ : チェックインできる日',
	'× : チェックインできない日（満室など）。チェックアウト日に選べる場合は、チェックイン日を選んだあとに ○ に変わります。',
];
const CHECK_OUT_LEGEND = [
	'○ : チェックアウトに選べる日',
	'× : チェックインできない日（満室など）',
];

/**
 * 今日（東京時刻）から offset 日後の日付を返す。
 *
 * @param offset 今日からの日数（負の値で過去）。
 */
function dateAfter( offset: number ) {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return {
		year: Number( year ),
		month: Number( month ),
		day: Number( day ),
		iso: `${ year }-${ month }-${ day }`,
	};
}

/**
 * wp eval で投稿を作り、作った ID を返す（数値でなければ失敗にする）。
 *
 * @param php   実行する PHP。
 * @param label 失敗時の表示名。
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 今日から offset 日後の日付ボタンを返す。表示月が違えば前月・翌月へ動かす。
 *
 * @param page   Playwright のページ。
 * @param offset 今日からの日数。
 */
async function dayButton( page: Page, offset: number ) {
	const target = dateAfter( offset );
	const calendar = page.locator( '.vkbm-stay-calendar' );
	const targetMonthIndex = target.year * 12 + target.month;
	// 見出し（例: 「2026年10月」）から表示中の年月を読み、対象の月まで移動する。
	for ( let i = 0; i < 6; i++ ) {
		const heading = await calendar
			.locator( '.vkbm-calendar__current' )
			.innerText();
		const matched = heading.match( /(\d{4})\D+(\d{1,2})/ );
		if ( ! matched ) {
			throw new Error( `Unexpected calendar heading: "${ heading }"` );
		}
		const difference =
			targetMonthIndex -
			( Number( matched[ 1 ] ) * 12 + Number( matched[ 2 ] ) );
		if ( difference === 0 ) {
			break;
		}
		const nav = calendar.locator( '.vkbm-calendar__nav' );
		await ( difference < 0 ? nav.first() : nav.last() ).click();
	}
	return calendar.locator( 'button.vkbm-calendar__day' ).filter( {
		has: page.locator( '.vkbm-calendar__day-label', {
			hasText: new RegExp( `^${ target.day }$` ),
		} ),
	} );
}

/**
 * 凡例の各行の文を返す。
 *
 * @param page Playwright のページ。
 */
function legendItems( page: Page ) {
	return page.locator( 'ul.vkbm-lodging__availability-legend li' );
}

/**
 * 事業者設定を、テスト前の値に戻す。
 */
function restoreSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs( [
			'option',
			'update',
			OPTION_NAME,
			originalSettingsJson,
			'--format=json',
		] );
		return;
	}
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ] );
	} catch {
		// 元から無ければ何もしない。
	}
}

/**
 * このテストで作った客室・プラン・予約・シフトを消す。
 */
function cleanup(): void {
	wpEvalPhp( `
		foreach ( get_posts( array( 'post_type' => 'vkbm_shift', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_vkbm_shift_resource_id', 'meta_value' => ${ Number(
			roomId
		) } ) ) as $id ) { wp_delete_post( $id, true ); }
		foreach ( get_posts( array( 'post_type' => array( 'vkbm_booking', 'vkbm_shift', 'vkbm_service_menu', 'vkbm_resource' ), 'post_status' => 'any', 'numberposts' => -1, 's' => '${ PREFIX }' ) ) as $post ) {
			if ( 0 === strpos( $post->post_title, '${ PREFIX }' ) ) { wp_delete_post( $post->ID, true ); }
		}
	` );
}

test.describe.serial( 'issue #1131: 宿泊カレンダーの ○ の印', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs( [
				'option',
				'get',
				OPTION_NAME,
				'--format=json',
			] );
		} catch {
			originalSettingsJson = null;
		}
		// @step 客室「E2E1131 Room」を作成し、今日から 5 日後までを含む月のシフトを登録しておく
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_resource', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Room' ) );
			echo $id;
		`,
			'room'
		);
		const months = new Set< string >();
		for ( const offset of [ -1, 0, 1, 2, 3, 4, 5 ] ) {
			const { year, month } = dateAfter( offset );
			months.add( `${ year }-${ month }` );
		}
		for ( const key of months ) {
			const [ year, month ] = key.split( '-' ).map( Number );
			createShiftForMonth( roomId, year, month );
		}
		// @step 予約の単位が「泊」のプラン「E2E1131 Plan」を作成し、その客室を割り当てておく
		menuId = insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_service_menu', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Plan' ) );
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			echo $id;
		`,
			'menu'
		);
		// @step 3 日後チェックイン・4 日後チェックアウトの確定済み予約を登録し、3 日後を満室にしておく
		insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Booking' ) );
			update_post_meta( $id, '_vkbm_booking_service_start', '${
				dateAfter( 3 ).iso
			} 15:00:00' );
			update_post_meta( $id, '_vkbm_booking_service_end', '${
				dateAfter( 4 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_total_end', '${
				dateAfter( 4 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_resource_id', ${ Number( roomId ) } );
			update_post_meta( $id, '_vkbm_booking_service_id', ${ Number( menuId ) } );
			update_post_meta( $id, '_vkbm_booking_status', 'confirmed' );
			echo $id;
		`,
			'booking'
		);
		// @step 管理画面で業種を宿泊にしておく
		await configureProviderSettings( { industry_preset: 'lodging' } );
		wpCliArgs( [ 'transient', 'delete', '--all' ] );
	} );

	test.afterAll( () => {
		try {
			cleanup();
		} finally {
			restoreSettings();
		}
	} );

	test( 'チェックインできる日に ○、満室の日に × が出て、チェックイン日を選ぶとチェックアウトに選べる日が ○ になり凡例も切り替わる', async ( {
		page,
	} ) => {
		// @step プランの予約ページを開く
		await page.goto( `/booking/?menu_id=${ menuId }` );
		await expect( page.locator( '.vkbm-stay-calendar' ) ).toBeVisible();

		// @check 凡例が「○ : チェックインできる日」「× : チェックインできない日（満室など）。…」の 2 行になっている
		await expect( legendItems( page ) ).toHaveText( CHECK_IN_LEGEND );

		// @check 2 日後（空きあり）の日に ○ が付き、押せる状態になっている
		const availableDay = await dayButton( page, 2 );
		await expect(
			availableDay.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveText( '○' );
		await expect(
			availableDay.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveAttribute( 'aria-hidden', 'true' );
		await expect( availableDay ).toHaveAttribute(
			'aria-disabled',
			'false'
		);

		// @check 3 日後（満室）の日は × が付き、○ は付かない
		const fullDay = await dayButton( page, 3 );
		await expect(
			fullDay.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '×' );
		await expect(
			fullDay.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveCount( 0 );
		await expect( fullDay ).toHaveAttribute( 'aria-disabled', 'true' );

		// @check 過去の日には ○ が付かない
		const yesterday = dateAfter( -1 );
		if ( yesterday.month === dateAfter( 0 ).month ) {
			const pastDay = await dayButton( page, -1 );
			await expect(
				pastDay.locator( '.vkbm-calendar__day-available-mark' )
			).toHaveCount( 0 );
		}
		await expect(
			page.locator(
				'.vkbm-stay-calendar .vkbm-calendar__day--past .vkbm-calendar__day-available-mark'
			)
		).toHaveCount( 0 );

		// @step 1 日後をチェックイン日に選ぶ
		const checkIn = await dayButton( page, 1 );
		const checkoutMetaResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/lodging-calendar-meta' ) &&
				response.url().includes( 'check_in_date=' ) &&
				response.ok()
		);
		await checkIn.click();
		await checkoutMetaResponse;

		// @check 凡例が「○ : チェックアウトに選べる日」「× : チェックインできない日（満室など）」に切り替わる
		await expect( legendItems( page ) ).toHaveText( CHECK_OUT_LEGEND );

		// @check 1 日後（チェックイン日）は IN と表示され、○ は付かない
		const selectedCheckIn = await dayButton( page, 1 );
		await expect(
			selectedCheckIn.locator( '.vkbm-calendar__day-badge' )
		).toHaveText( 'IN' );
		await expect(
			selectedCheckIn.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveCount( 0 );

		// @check 2 日後はチェックアウトに選べる日として ○ のまま
		await expect(
			( await dayButton( page, 2 ) ).locator(
				'.vkbm-calendar__day-available-mark'
			)
		).toHaveText( '○' );

		// @check 3 日後（満室）はチェックアウトに選べるので、× が ○ に変わる
		const checkoutOnFullDay = await dayButton( page, 3 );
		await expect(
			checkoutOnFullDay.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveText( '○' );
		await expect(
			checkoutOnFullDay.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveCount( 0 );
		await expect( checkoutOnFullDay ).toHaveAttribute(
			'aria-label',
			/チェックアウト日に選べます/
		);

		// @check 4 日後は途中に満室の夜を含むので選べず、○ も × も付かない
		const blocked = await dayButton( page, 4 );
		await expect( blocked ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect(
			blocked.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveCount( 0 );
		await expect(
			blocked.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '' );

		// @step 3 日後をチェックアウト日に選ぶ
		await checkoutOnFullDay.click();

		// @check 3 日後は OUT と表示され、凡例はチェックイン日を選ぶ段階の文に戻る
		await expect(
			( await dayButton( page, 3 ) ).locator(
				'.vkbm-calendar__day-badge'
			)
		).toHaveText( 'OUT' );
		await expect( legendItems( page ) ).toHaveText( CHECK_IN_LEGEND );
	} );

	test( '空き状況を読み込めなかったときは ○ も × も出さず、凡例の位置に案内を出す', async ( {
		page,
	} ) => {
		// @step 空き状況の取得がサーバーエラーになる状態にして、プランの予約ページを開く
		await page.route(
			( url ) => url.toString().includes( 'lodging-calendar-meta' ),
			( route ) =>
				route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'internal_error',
						message: 'error',
						data: { status: 500 },
					} ),
				} )
		);
		await page.goto( `/booking/?menu_id=${ menuId }` );
		const calendar = page.locator( '.vkbm-stay-calendar' );
		await expect( calendar ).toBeVisible();

		// @check 凡例の位置に「空き状況を読み込めませんでした」の案内が出て、○ / × の凡例は出ない
		await expect(
			page.locator( 'p.vkbm-lodging__availability-legend' )
		).toContainText( '空き状況を読み込めませんでした' );
		await expect( legendItems( page ) ).toHaveCount( 0 );

		// @check カレンダーのどの日にも ○ も × も付かない
		await expect(
			calendar.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveCount( 0 );
		await expect(
			calendar.locator( '.vkbm-calendar__day-unavailable-mark', {
				hasText: '×',
			} )
		).toHaveCount( 0 );
		// @check 3 日後（満室）も押せる状態のまま
		await expect( await dayButton( page, 3 ) ).toHaveAttribute(
			'aria-disabled',
			'false'
		);
	} );

	test( 'チェックアウトに選べる日を読み込めなかったときは ○ も × も出さず、凡例の位置に案内を出す', async ( {
		page,
	} ) => {
		// @step チェックイン日を選んだあとの、チェックアウトに選べる日の取得だけがサーバーエラーになる状態にする
		await page.route(
			( url ) =>
				url.toString().includes( 'lodging-calendar-meta' ) &&
				url.toString().includes( 'check_in_date=' ),
			( route ) =>
				route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'internal_error',
						message: 'error',
						data: { status: 500 },
					} ),
				} )
		);
		// @step プランの予約ページを開く
		await page.goto( `/booking/?menu_id=${ menuId }` );
		const calendar = page.locator( '.vkbm-stay-calendar' );
		await expect( calendar ).toBeVisible();
		// @check チェックイン日を選ぶ前は、○ / × の凡例と、満室の日の × が出ている
		await expect( legendItems( page ) ).toHaveText( CHECK_IN_LEGEND );
		await expect(
			( await dayButton( page, 3 ) ).locator(
				'.vkbm-calendar__day-unavailable-mark'
			)
		).toHaveText( '×' );

		// @step 1 日後をチェックイン日に選ぶ
		const checkoutMetaResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/lodging-calendar-meta' ) &&
				response.url().includes( 'check_in_date=' )
		);
		await ( await dayButton( page, 1 ) ).click();
		await checkoutMetaResponse;

		// @check 凡例の位置に「空き状況を読み込めませんでした」の案内が出て、○ / × の凡例は出ない
		await expect(
			page.locator( 'p.vkbm-lodging__availability-legend' )
		).toContainText( '空き状況を読み込めませんでした' );
		await expect( legendItems( page ) ).toHaveCount( 0 );
		// @check カレンダーのどの日にも ○ も × も付かない（満室の 3 日後の × も出ない）
		await expect(
			calendar.locator( '.vkbm-calendar__day-available-mark' )
		).toHaveCount( 0 );
		await expect(
			calendar.locator( '.vkbm-calendar__day-unavailable-mark', {
				hasText: '×',
			} )
		).toHaveCount( 0 );
	} );
} );
