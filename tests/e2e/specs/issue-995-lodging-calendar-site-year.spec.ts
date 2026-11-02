/**
 * issue #995: 宿泊カレンダーの「次の月」の上限年を、ブラウザの今年ではなくサイト時刻の今年から決める。
 *
 * サーバー（PHP）はサイトのタイムゾーンの「今年 + 2 年」の 12 月までしか空き状況を返さない。
 * 画面側がブラウザの今年から上限を決めると、サイトとブラウザで年が違う日に
 * サーバーが受け付けない月へ進み、空き状況の取得エラーになっていた。
 */
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

/** カレンダー見出し（例: 2026年10月）から年と月を取り出す。 */
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

/** ナビボタンが aria-disabled=true になるまで押し続ける（最大 40 回）。 */
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

/** lodging-calendar-meta への失敗レスポンスを集める。 */
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
} );
