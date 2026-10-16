/**
 * issue #820: 宿泊モードで、客室を選ばないと「予約に進む」が押せないことに気づけるようにする。
 *
 * 検証内容（宿泊のサイト・Pro 版のみ）:
 *   1. 日程を決めた直後（客室未選択）: ガイド行に「次に、空いている客室から1つ選んでください」が出る。
 *   2. 予約内容の枠に「客室 未選択」の行と宿泊人数の行が出て、「予約に進む」の上に
 *      「客室を選ぶと、予約に進めます。」が出る。ボタンは押せない。客室は自動で選ばれない（2室とも aria-pressed=false）。
 *   3. 客室カードの左端に丸（::before）が付き、選択前後で aria-pressed とアクセント色の背景が変わる。
 *   4. 客室を選ぶと、ガイド行の「次に〜」とボタン上の案内が消え、ボタンが押せる。
 *   5. スマホ幅では、ボタン上の案内が左寄せでボタンの上に出る。
 *
 * 注: 案内の文言は日本語で確かめる（e2e のサイトは日本語）。
 * テストデータは接頭辞（E2E820）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E820';
const OPTION_NAME = 'vkbm_provider_settings';

let originalSettingsJson: string | null = null;
let roomAId = '';
let roomBId = '';
let nightMenuId = '';

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
 * 客室を1件作る。
 *
 * @param title 客室名
 * @return 客室の post ID
 */
function seedRoom( title: string ): string {
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
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
 * 予約ページで泊のプランを開き、2 泊の日程を選んで客室の候補が出るまで進める。
 *
 * @param page Playwright の Page
 */
async function openAndPickDates( page: Page ) {
	await page.goto( `/booking/?menu_id=${ nightMenuId }` );
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
		timeout: 15000,
	} );
	await clickStayDay( page, tokyoDateAfter( 2 ) );
	await clickStayDay( page, tokyoDateAfter( 4 ) );
	await expect( page.locator( '.vkbm-lodging__room' ) ).toHaveCount( 2, {
		timeout: 15000,
	} );
}

test.describe.serial( 'issue #820: 宿泊の客室を選ぶ導線', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		roomAId = seedRoom( `${ PREFIX } Room A` );
		roomBId = seedRoom( `${ PREFIX } Room B` );
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomAId, year, month );
			createShiftForMonth( roomBId, year, month );
		}
		const staffIds = `${ Number( roomAId ) }, ${ Number( roomBId ) }`;
		nightMenuId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Night Plan',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ staffIds } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			echo $id;
		`,
			'night menu'
		);
		// 宿泊のサイトにする。「リソースの呼び方」は自由入力の値で翻訳されないため、
		// 宿泊のサイトで運営者が入れる呼び方（客室）を直接入れる。option だけを書き換える e2e では
		// 呼び方が空のままで「スタッフ」と表示され、「客室」の文言を確かめられないため。
		await configureProviderSettings( {
			industry_preset: 'lodging',
			resource_label_singular: '客室',
			resource_label_plural: '客室',
		} );
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '客室を選ぶ前は次の操作を案内し、選ぶと案内が消えて予約に進める', async ( {
		page,
	} ) => {
		await openAndPickDates( page );

		const guide = page.locator( '.vkbm-lodging__guide' );
		const summary = page.locator( '.vkbm-lodging__summary' );
		const hint = page.locator( '.vkbm-lodging__action-hint' );
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		const rooms = page.locator( '.vkbm-lodging__room' );

		// 未選択: ガイド行・予約内容の枠・ボタン上の案内。自動では選ばれない。
		await expect( guide ).toContainText(
			'次に、空いている客室から1つ選んでください。'
		);
		await expect( summary ).toContainText( '客室' );
		await expect( summary ).toContainText( '未選択' );
		// 人数の行の見出しは「人数」（既存の表示。仕様書の「宿泊人数」は行の種類を指す呼び名）。
		await expect( summary ).toContainText( '人数' );
		await expect( hint ).toHaveText( '客室を選ぶと、予約に進めます。' );
		await expect( proceed ).toBeDisabled();
		await expect( rooms.nth( 0 ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
		await expect( rooms.nth( 1 ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);

		// 丸（::before）が描かれている。
		const marker = await rooms.first().evaluate( ( el ) => {
			const style = window.getComputedStyle( el, '::before' );
			return { content: style.content, width: style.width };
		} );
		expect( marker.content ).not.toBe( 'none' );
		expect( marker.width ).toBe( '20px' );

		// 選ぶ: 選択中の見た目に変わり、案内が消えてボタンが押せる。
		const backgroundBefore = await rooms
			.first()
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
		await rooms.first().click();
		await expect( rooms.first() ).toHaveAttribute( 'aria-pressed', 'true' );
		const backgroundAfter = await rooms
			.first()
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
		expect( backgroundAfter ).not.toBe( backgroundBefore );
		await expect( guide ).not.toContainText( '次に、空いている客室' );
		await expect( guide ).toContainText( '日付を押すと選び直せます。' );
		await expect( hint ).toHaveCount( 0 );
		await expect( summary ).not.toContainText( '未選択' );
		await expect( proceed ).toBeEnabled();
	} );

	test( 'スマホ幅では、ボタン上の案内が左寄せでボタンの上に出る', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 375, height: 800 } );
		await openAndPickDates( page );

		const hint = page.locator( '.vkbm-lodging__action-hint' );
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( hint ).toBeVisible();

		const hintBox = await hint.boundingBox();
		const buttonBox = await proceed.boundingBox();
		expect( hintBox ).not.toBeNull();
		expect( buttonBox ).not.toBeNull();
		// 案内はボタンの上にあり、左端がボタンの左端にそろう。
		expect( hintBox!.y + hintBox!.height ).toBeLessThanOrEqual(
			buttonBox!.y
		);
		expect( Math.abs( hintBox!.x - buttonBox!.x ) ).toBeLessThanOrEqual(
			1
		);
		expect(
			await hint.evaluate( ( el ) => getComputedStyle( el ).textAlign )
		).toMatch( /^(left|start)$/ );
	} );
} );
