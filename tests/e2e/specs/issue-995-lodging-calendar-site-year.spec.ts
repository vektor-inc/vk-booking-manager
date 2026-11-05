/**
 * issue #995: 宿泊カレンダーの「次の月」の上限年を、ブラウザの今年ではなくサイト時刻の今年から決める。
 *
 * サーバー（PHP）はサイトのタイムゾーンの「今年 + 2 年」の 12 月までしか空き状況を返さない。
 * 画面側がブラウザの今年から上限を決めると、サイトとブラウザで年が違う日に
 * サーバーが受け付けない月へ進み、空き状況の取得エラーになっていた。
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

const PREFIX = 'E2E995';
const OPTION_NAME = 'vkbm_provider_settings';
// サーバー側の上限年数（LODGING_CALENDAR_MAX_YEARS_AHEAD と同じ値）。
const MAX_YEARS_AHEAD = 2;
let originalSettingsJson: string | null = null;
let roomId = '';
let menuId = '';

function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

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

function cleanup(): void {
	// 客室の作成前に準備が失敗して roomId が空のときは、客室 ID 0 のシフトまで消さないよう、シフトの削除を飛ばす。
	const shiftCleanupPhp = /^\d+$/.test( roomId )
		? `foreach ( get_posts( array( 'post_type' => 'vkbm_shift', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_vkbm_shift_resource_id', 'meta_value' => ${ Number(
				roomId
		  ) } ) ) as $id ) { wp_delete_post( $id, true ); }`
		: '';
	wpEvalPhp( `
		${ shiftCleanupPhp }
		foreach ( get_posts( array( 'post_type' => array( 'vkbm_service_menu', 'vkbm_resource' ), 'post_status' => 'any', 'numberposts' => -1, 's' => '${ PREFIX }' ) ) as $post ) {
			if ( 0 === strpos( $post->post_title, '${ PREFIX }' ) ) { wp_delete_post( $post->ID, true ); }
		}
	` );
}

/**
 * カレンダー見出しから年と月を取り出す。
 * @param page 対象画面。
 */
async function readHeading( page: Page ) {
	const heading = await page
		.locator( '.vkbm-stay-calendar .vkbm-calendar__current' )
		.innerText();
	const matched = heading.match( /(\d{4})\D+(\d{1,2})/ );
	if ( ! matched ) {
		throw new Error( `Unexpected calendar heading: "${ heading }"` );
	}
	return { year: Number( matched[ 1 ] ), month: Number( matched[ 2 ] ) };
}

/**
 * ナビボタンが無効になるまで押す。
 * @param page  対象画面。
 * @param which 移動方向。
 */
async function clickUntilDisabled( page: Page, which: 'prev' | 'next' ) {
	const nav = page.locator( '.vkbm-stay-calendar .vkbm-calendar__nav' );
	const button = which === 'prev' ? nav.first() : nav.last();
	for ( let i = 0; i < 40; i++ ) {
		if ( ( await button.getAttribute( 'aria-disabled' ) ) === 'true' ) {
			return;
		}
		await button.click();
		// 月の切り替えと空き状況の取得が落ち着くのを待つ。
		await page.waitForLoadState( 'networkidle' );
	}
	throw new Error( `${ which } button never became disabled` );
}

/**
 * 宿泊カレンダーへの失敗応答を集める。
 * @param page 対象画面。
 */
function collectFailedMetaResponses( page: Page ): string[] {
	const failures: string[] = [];
	page.on( 'response', ( response ) => {
		if (
			response.url().includes( 'lodging-calendar-meta' ) &&
			! response.ok()
		) {
			failures.push( `${ response.status() } ${ response.url() }` );
		}
	} );
	return failures;
}

test.describe
	.serial( 'issue #995: 宿泊カレンダーの上限年はサイト時刻の年', () => {
	// サイトの「今年」（サイトのタイムゾーンは global-setup と同じ Asia/Tokyo）。
	const siteYear = Number( getTokyoDateParts( new Date() ).year );
	const siteMonth = Number( getTokyoDateParts( new Date() ).month );

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
		// @step 客室「E2E995 Room」と、予約の単位が「泊」のプラン「E2E995 Plan」を作成する
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_resource', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Room' ) );
			echo $id;
		`,
			'room'
		);
		createShiftForMonth( roomId, siteYear, siteMonth );
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
		// @step 管理画面で業種を宿泊にしておく
		await configureProviderSettings( {
			industry_preset: 'lodging',
			provider_max_advance_booking_days: 0,
		} );
		wpCliArgs( [ 'transient', 'delete', '--all' ] );
	} );

	test.afterAll( () => {
		try {
			cleanup();
		} finally {
			restoreSettings();
		}
	} );

	test( '通常時: 「次の月」はサイトの今年+2年の12月で止まり、今月より前へ戻れない', async ( {
		page,
	} ) => {
		const failures = collectFailedMetaResponses( page );
		// @step プランの予約ページを開く
		await page.goto( `/booking/?menu_id=${ menuId }` );
		await expect( page.locator( '.vkbm-stay-calendar' ) ).toBeVisible();

		// @step 「次の月」を押せなくなるまで押し続ける
		await clickUntilDisabled( page, 'next' );
		// @check 今年+2年の12月で止まっている
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear + MAX_YEARS_AHEAD,
			month: 12,
		} );

		// @step 「前の月」を押せなくなるまで押し続ける
		await clickUntilDisabled( page, 'prev' );
		// @check 今月で止まり、今月より前へは戻れない
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear,
			month: siteMonth,
		} );
		// @check 空き状況の取得エラーが 1 度も起きていない
		expect( failures ).toEqual( [] );
	} );

	test( '年ずれ時（ブラウザは翌年の1月1日）: 「次の月」はサイトの今年+2年の12月で止まり、取得エラーが出ない', async ( {
		page,
	} ) => {
		const failures = collectFailedMetaResponses( page );
		// @step ブラウザの時計だけをサイトの翌年の1月1日に進める（サイト側＝サーバーの時刻は今のまま）
		await page.clock.install( {
			time: new Date( `${ siteYear + 1 }-01-01T10:00:00+09:00` ),
		} );
		// @step プランの予約ページを開く
		await page.goto( `/booking/?menu_id=${ menuId }` );
		await expect( page.locator( '.vkbm-stay-calendar' ) ).toBeVisible();
		// @check 初期表示月はブラウザの日付のまま（翌年の1月）
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear + 1,
			month: 1,
		} );

		// @step 「次の月」を押せなくなるまで押し続ける
		await clickUntilDisabled( page, 'next' );
		// @check ブラウザの年ではなくサイトの年から数えた今年+2年の12月で止まっている
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear + MAX_YEARS_AHEAD,
			month: 12,
		} );
		// @check 空き状況の取得エラーが 1 度も起きていない
		expect( failures ).toEqual( [] );
		// 取得エラー時は凡例（p.vkbm-lodging__availability-legend）がエラー文言に切り替わる。それが出ていない。
		await expect(
			page.locator( '.vkbm-lodging__availability-legend' )
		).not.toContainText( '空き状況を読み込めませんでした' );

		// @step 「前の月」を押せなくなるまで押し続ける
		await clickUntilDisabled( page, 'prev' );
		// @check ブラウザの今月（翌年の1月）より前へは戻れない
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear + 1,
			month: 1,
		} );
		// @check 戻る操作のあとも、空き状況の取得エラーが 1 度も起きていない
		expect( failures ).toEqual( [] );
		await expect(
			page.locator( '.vkbm-lodging__availability-legend' )
		).not.toContainText( '空き状況を読み込めませんでした' );
	} );
	test( '月末チェックインの同月・翌月境界と429からの再取得', async ( {
		page,
	} ) => {
		const month = `${ siteYear }-${ String( siteMonth ).padStart(
			2,
			'0'
		) }`;
		const last = new Date( siteYear, siteMonth, 0 ).getDate();
		let limited = false;
		await page.route( /lodging-calendar-meta[?]/, async ( route ) => {
			if ( limited ) {
				await route.fulfill( {
					status: 429,
					json: { code: 'rate_limited', data: { status: 429 } },
				} );
				return;
			}
			const response = await route.fetch();
			const body = await response.json();
			const checkout = new URL( route.request().url() ).searchParams.has(
				'check_in_date'
			);
			// @step 受付上限を今月末にし、月末からの候補はサーバーの実応答を使う
			if ( ! checkout ) {
				body.booking_range = {
					first_date: `${ month }-01`,
					last_date: `${ month }-${ last }`,
					in_range: true,
				};
			}
			await route.fulfill( { response, json: body } );
		} );
		await page.goto( `/booking/?menu_id=${ menuId }` );
		const calendar = page.locator( '.vkbm-stay-calendar' );
		await expect(
			calendar.locator( '.vkbm-calendar__nav' ).last()
		).toBeDisabled();
		const day = ( value: number ) =>
			calendar.locator(
				`button[aria-label^="${ siteYear }年${ siteMonth }月${ value }日"]`
			);
		// @check PageDownでも最後の受付月を越えない
		await day( last ).focus();
		await page.keyboard.press( 'PageDown' );
		expect( await readHeading( page ) ).toEqual( {
			year: siteYear,
			month: siteMonth,
		} );
		await Promise.all( [
			page.waitForResponse(
				( response ) =>
					response.url().includes( 'lodging-calendar-meta' ) &&
					response.url().includes( 'check_in_date=' )
			),
			day( last ).click(),
		] );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '.vkbm-availability-notice' ) ).toHaveCount(
			0
		);
		await expect( day( last - 1 ) ).toBeEnabled();
		// @step 429になってもチェックインを保持し、手動取得で復旧する
		limited = true;
		await calendar.locator( '.vkbm-calendar__nav' ).last().click();
		await page.waitForLoadState( 'networkidle' );
		// @check 境界で無効になる次ボタンから、日付・月送り・月見出しの優先順で戻す。
		await expect
			.poll( () =>
				calendar.evaluate( ( element ) => {
					const target =
						element.querySelector(
							'button.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"])'
						) ||
						element.querySelector(
							'.vkbm-calendar__nav:not(:disabled)'
						) ||
						element.querySelector( '.vkbm-calendar__current' );
					return document.activeElement === target;
				} )
			)
			.toBe( true );
		const retry = page.getByRole( 'button', { name: '再試行' } );
		await expect( retry ).toBeVisible();
		await page.waitForLoadState( 'networkidle' );
		await expect( retry ).toHaveAttribute( 'aria-disabled', 'false' );
		limited = false;
		await Promise.all( [
			page.waitForResponse(
				( response ) =>
					response.url().includes( 'lodging-calendar-meta' ) &&
					! response.url().includes( 'check_in_date=' )
			),
			page.waitForResponse(
				( response ) =>
					response.url().includes( 'lodging-calendar-meta' ) &&
					response.url().includes( 'check_in_date=' )
			),
			retry.click(),
		] );
		await expect( retry ).toHaveCount( 0 );
		await expect(
			calendar.locator( '.vkbm-calendar__nav' ).last()
		).toBeDisabled();
		// @check チェックアウト上限月ではキーボード操作でも先へ進めない
		const heading = await readHeading( page );
		const selectable = calendar
			.locator( '.vkbm-calendar__day:not(:disabled)' )
			.first();
		await selectable.focus();
		await page.keyboard.press( 'PageDown' );
		expect( await readHeading( page ) ).toEqual( heading );
		await page.waitForLoadState( 'networkidle' );
	} );

	test( '宿泊の月送り10回と日程選択10回の呼び出し回数を実測する', async ( {
		page,
	} ) => {
		const nextMonth = new Date( siteYear, siteMonth, 1 );
		createShiftForMonth(
			roomId,
			nextMonth.getFullYear(),
			nextMonth.getMonth() + 1
		);
		// 先行テストが取得した翌月の空室キャッシュを、追加した販売日に合わせて消す。
		wpCliArgs( [ 'transient', 'delete', '--all' ] );
		const calls: string[] = [];
		const started = Date.now();
		page.on( 'request', ( request ) => {
			if (
				/lodging-(calendar-meta|availabilities)[?]/.test(
					request.url()
				)
			) {
				calls.push( request.url() );
			}
		} );
		await page.goto( `/booking/?menu_id=${ menuId }` );
		const calendar = page.locator( '.vkbm-stay-calendar' );
		await expect( calendar ).toBeVisible();
		await expect(
			calendar.locator( '.vkbm-stay-calendar__loading' )
		).toHaveCount( 0 );
		// 月末にも同じ日程を選べるよう、販売日を用意した翌月から計測する。
		await calendar.locator( '.vkbm-calendar__nav' ).last().click();
		await expect(
			calendar.locator( '.vkbm-stay-calendar__loading' )
		).toHaveCount( 0 );
		// @step 5か月先へ進み、同じ5か月を戻る
		for ( const direction of [ 1, -1 ] ) {
			for ( let index = 0; index < 5; index++ ) {
				await calendar
					.locator( '.vkbm-calendar__nav' )
					.nth( direction > 0 ? 1 : 0 )
					.click();
				await expect(
					calendar.locator( '.vkbm-stay-calendar__loading' )
				).toHaveCount( 0 );
			}
		}
		// @step チェックインとチェックアウトを5組選び直す（計10回）
		const countYear = nextMonth.getFullYear();
		const countMonth = nextMonth.getMonth() + 1;
		for ( let index = 0; index < 5; index++ ) {
			for ( const value of [ 10, 11 ] ) {
				const label = `${ countYear }年${ countMonth }月${ value }日`;
				await Promise.all( [
					page.waitForResponse( ( response ) =>
						value === 10
							? response
									.url()
									.includes( 'lodging-calendar-meta' ) &&
							  response.url().includes( 'check_in_date=' )
							: response
									.url()
									.includes( 'lodging-availabilities' )
					),
					calendar
						.locator( `button[aria-label^="${ label }"]` )
						.click(),
				] );
				await page.waitForLoadState( 'networkidle' );
			}
		}
		console.log(
			`ISSUE1018_LODGING_CALLS elapsed_ms=${
				Date.now() - started
			} total=${ calls.length } calendar=${
				calls.filter( ( url ) => url.includes( 'calendar-meta' ) )
					.length
			} rooms=${
				calls.filter( ( url ) =>
					url.includes( 'lodging-availabilities' )
				).length
			}`
		);
		// @check 月送りと日程選択の合計が60秒あたり120回の上限に収まる。
		expect( calls.length ).toBeLessThan( 120 );
		expect( Date.now() - started ).toBeLessThan( 60000 );
		expect(
			calls.filter( ( url ) => url.includes( 'lodging-availabilities' ) )
		).toHaveLength( 5 );
	} );
} );
