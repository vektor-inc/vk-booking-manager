/**
 * issue #503: 複数人一括予約で、予約画面と確認画面の「基本料金合計」を揃える
 *
 * 検証内容:
 * - 料金区分なし・指名料あり・2名: 予約画面の見出し「サービス基本料金（¥15,000 × 2名）」、
 *   小計 ¥30,000、合計 ¥36,000 が人数変更でその場で変わり、確認画面と一致する。
 *   確認画面の内訳（合計以外の行）を足すと合計になる。合計の行に aria-live / aria-atomic が付く。
 * - 料金区分あり（大人・子供）＋指名: 予約画面の区分ごとの行と合計が確認画面と一致し、
 *   区分入力欄の下の「合計（Total）」は出ない。
 * - 複数人一括予約を使わないメニュー: 見出しに「× 1名」が付かず、金額も従来どおり。
 *
 * テストデータ:
 * - 専用スタッフ（指名料 6,000）と当月＋翌月のシフト、メニュー A / B / C を beforeAll で作成し、
 *   afterAll で予約 → シフト → メニュー → スタッフの順に削除する
 *   （予約が残るとスタッフ削除ガードで止まる事例 #491 があるため、予約から先に消す）。
 * - メニューは専用スタッフだけを担当させ、タイトルに固有の接頭辞を付けて他 spec と干渉しないようにする。
 * - 予約は確定させない（確認画面まで）。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E503';
const BASE_PRICE = 15000;
const NOMINATION_FEE = 6000;
const ADULT_PRICE = 5000;
const CHILD_PRICE = 3000;

let originalStaffEnabled = true;
let staffId = '';
let menuA = ''; // 料金区分なし・複数人一括予約あり
let menuB = ''; // 料金区分あり（大人・子供）・複数人一括予約あり
let menuC = ''; // 複数人一括予約なし

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
 * 専用スタッフ（指名料あり）を作成する。
 *
 * @return スタッフの post ID
 */
function seedStaff(): string {
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'publish',
			'post_title'  => '${ PREFIX } Staff X',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_nomination_fee', ${ NOMINATION_FEE } );
		echo $id;
	`,
		'Staff'
	);
}

/**
 * サービスメニューを作成し、専用スタッフを割り当てる。
 *
 * @param title      メニュー名
 * @param multiGuest 複数人一括予約を許可するか
 * @param withTiers  料金区分（大人・子供）を設定するか
 * @return メニューの post ID
 */
function seedMenu(
	title: string,
	multiGuest: boolean,
	withTiers: boolean
): string {
	const multiLines = multiGuest
		? `update_post_meta( $id, '_vkbm_allow_multiple_guests', 1 );
		   update_post_meta( $id, '_vkbm_max_capacity', 6 );`
		: '';
	const tierLines = withTiers
		? `update_post_meta( $id, '_vkbm_price_tiers', array(
			array( 'label' => '大人', 'price' => ${ ADULT_PRICE } ),
			array( 'label' => '子供', 'price' => ${ CHILD_PRICE } ),
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
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( staffId ) } ) );
		update_post_meta( $id, '_vkbm_base_price', ${ BASE_PRICE } );
		update_post_meta( $id, '_vkbm_duration_minutes', 60 );
		${ multiLines }
		${ tierLines }
		echo $id;
	`,
		title
	);
}

/**
 * このspecで作ったデータを削除する（予約 → シフト → メニュー → スタッフの順）。
 * 途中で失敗しても残りの削除を続けられるよう、PHP 側で1件ずつ処理する。
 */
function cleanupSeededData(): void {
	const staff = Number( staffId ) || 0;
	const menus = [ menuA, menuB, menuC ]
		.map( ( id ) => Number( id ) || 0 )
		.filter( Boolean );
	wpEvalPhp( `
		$staff = ${ staff };
		$menus = array( ${ menus.join( ', ' ) } );
		// 念のため、対象メニュー・スタッフに紐づく予約を先に削除する（スタッフ削除ガード対策）。
		$meta_or = array( 'relation' => 'OR' );
		if ( $menus ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_service_id', 'value' => $menus, 'compare' => 'IN' );
		}
		if ( $staff ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_resource_id', 'value' => $staff );
		}
		if ( count( $meta_or ) > 1 ) {
			$bookings = get_posts( array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_or,
			) );
			foreach ( $bookings as $bid ) { wp_delete_post( $bid, true ); }
		}
		// 専用スタッフのシフトを削除する。
		if ( $staff ) {
			$shifts = get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_vkbm_shift_resource_id',
				'meta_value'  => $staff,
			) );
			foreach ( $shifts as $sid ) { wp_delete_post( $sid, true ); }
		}
		foreach ( $menus as $mid ) { wp_delete_post( $mid, true ); }
		if ( $staff ) { wp_delete_post( $staff, true ); }
		echo 'ok';
	` );
}

/**
 * 予約ページでメニューを開き、空き日と最初の時間枠を選ぶ。
 * スタッフはメニューの担当が1人だけなので自動で選ばれ、料金欄に指名料が出る。
 *
 * @param page   Playwright の Page
 * @param menuId 対象メニューの post ID
 */
async function openMenuAndPickSlot( page: Page, menuId: string ) {
	// カレンダーの読み込み（calendar-meta）はページ表示直後に始まるため、
	// 取りこぼさないよう page.goto() より前に待ち受けを仕込み、後で await する。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	await page.goto( `/booking/?menu_id=${ menuId }` );
	await page.waitForLoadState( 'networkidle' );

	await selectAvailableCalendarDay( page, calendarMetaResponse );
	await page.waitForTimeout( 800 );

	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();

	// 料金欄が出るまで待つ。
	await page
		.locator( '.vkbm-plan-summary__pricing-row' )
		.first()
		.waitFor( { state: 'visible', timeout: 10000 } );
}

/**
 * 料金欄の行を「見出し」「金額（数値）」の配列で返す。
 *
 * @param page     Playwright の Page
 * @param rowClass 行のセレクタ
 * @return 見出しと金額の配列
 */
async function readRows(
	page: Page,
	rowClass: string
): Promise< Array< { label: string; amount: number | null } > > {
	const texts = await page.locator( rowClass ).allInnerTexts();
	return texts.map( ( text ) => {
		const [ label, ...rest ] = text.split( '\n' );
		// 金額欄（2行目以降）の最初の「¥n,nnn」を数値にする。
		const match = rest.join( ' ' ).match( /¥\s*([\d,]+)/ );
		return {
			label: label.trim(),
			amount: match ? Number( match[ 1 ].replace( /,/g, '' ) ) : null,
		};
	} );
}

/**
 * 予約画面から「予約に進む」で確認画面へ進み、サマリの表示を待つ。
 *
 * @param page Playwright の Page
 */
async function proceedToConfirm( page: Page ) {
	await page.locator( '.vkbm-plan-summary__action' ).first().click();
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
	await page
		.locator( '.vkbm-confirm__summary' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}

/**
 * 確認画面の料金行（見出しに料金・指名料・合計を含む行）だけを取り出す。
 *
 * @param page Playwright の Page
 * @return 料金行の見出しと金額
 */
async function readConfirmPriceRows( page: Page ) {
	const rows = await readRows( page, '.vkbm-confirm__summary-item' );
	// 金額を持つ行だけ（メニュー・人数・スタッフの行を除く）。
	return rows.filter( ( row ) => row.amount !== null );
}

test.describe( 'issue #503: 複数人一括予約の予約画面と確認画面の料金を揃える', () => {
	// 未ログインのまま確認画面まで進む（予約は確定しない）。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		// サイト全体の指名機能を ON にする（終了時に元へ戻す）。
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( true );

		staffId = seedStaff();
		// 当月＋翌月のシフト（月境界でも空き枠が残るように両方）。
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( staffId, year, month );
		}
		menuA = seedMenu( `${ PREFIX } Menu A`, true, false );
		menuB = seedMenu( `${ PREFIX } Menu B`, true, true );
		menuC = seedMenu( `${ PREFIX } Menu C`, false, false );
	} );

	test.afterAll( () => {
		// 片付けが例外で止まっても、指名機能の設定は必ず元へ戻す（次の spec への状態漏れ防止）。
		try {
			cleanupSeededData();
		} finally {
			setStaffEnabled( originalStaffEnabled );
		}
	} );

	test( '料金区分なし・2名・指名料あり: 予約画面の小計・合計が人数でその場で変わり、確認画面と一致する', async ( {
		page,
	} ) => {
		await openMenuAndPickSlot( page, menuA );

		const rowSelector = '.vkbm-plan-summary__pricing-row';

		// 1名: 見出しに「× 1名」が付き、合計は 15,000 + 6,000。
		await expect( page.locator( rowSelector ).first() ).toContainText(
			'サービス基本料金（¥15,000 × 1名）'
		);
		await expect( page.locator( rowSelector ).last() ).toContainText(
			'¥21,000'
		);

		// 2名にする → 見出し・小計・合計がその場で変わる。
		await page.locator( '#vkbm-reservation-guests' ).fill( '2' );
		await expect( page.locator( rowSelector ).first() ).toContainText(
			'サービス基本料金（¥15,000 × 2名）'
		);
		await expect( page.locator( rowSelector ).first() ).toContainText(
			'¥30,000'
		);
		await expect( page.locator( rowSelector ).last() ).toContainText(
			'¥36,000'
		);

		// 合計の行（最後の行）に読み上げ用の属性が付く。
		const totalRow = page.locator( rowSelector ).last();
		await expect( totalRow ).toContainText( '基本料金合計' );
		await expect( totalRow ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( totalRow ).toHaveAttribute( 'aria-atomic', 'true' );

		const reservationRows = await readRows( page, rowSelector );
		expect( reservationRows ).toEqual( [
			{ label: 'サービス基本料金（¥15,000 × 2名）', amount: 30000 },
			{ label: '指名料', amount: NOMINATION_FEE },
			{ label: '基本料金合計', amount: 36000 },
		] );

		// 確認画面: 予約画面と同じ行・金額になり、内訳を足すと合計になる。
		await proceedToConfirm( page );
		const confirmRows = await readConfirmPriceRows( page );
		expect( confirmRows ).toEqual( reservationRows );
		const total = confirmRows[ confirmRows.length - 1 ];
		const sum = confirmRows
			.slice( 0, -1 )
			.reduce( ( acc, row ) => acc + ( row.amount ?? 0 ), 0 );
		expect( sum ).toBe( total.amount );
	} );

	test( '料金区分あり（大人・子供）＋指名: 区分ごとの行と合計が確認画面と一致し、区分入力欄の下の合計は出ない', async ( {
		page,
	} ) => {
		await openMenuAndPickSlot( page, menuB );

		// 大人2・子供1。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '2' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '1' );

		const rowSelector = '.vkbm-plan-summary__pricing-row';
		await expect( page.locator( rowSelector ).last() ).toContainText(
			'¥19,000'
		);

		const reservationRows = await readRows( page, rowSelector );
		expect( reservationRows ).toEqual( [
			{ label: '大人（¥5,000 × 2名）', amount: 10000 },
			{ label: '子供（¥3,000 × 1名）', amount: 3000 },
			{ label: '指名料', amount: NOMINATION_FEE },
			{ label: '基本料金合計', amount: 19000 },
		] );

		// 指名を使うメニューでは、区分入力欄の下の合計（指名料を含まない金額）は出ない。
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tiers-total' )
		).toHaveCount( 0 );

		// 確認画面の区分の行・合計が予約画面と一致する。
		await proceedToConfirm( page );
		const confirmRows = await readConfirmPriceRows( page );
		expect( confirmRows ).toEqual( reservationRows );
	} );

	test( '複数人一括予約を使わないメニュー: 見出しに「× 1名」が付かず、金額も従来どおり', async ( {
		page,
	} ) => {
		await openMenuAndPickSlot( page, menuC );

		const rowSelector = '.vkbm-plan-summary__pricing-row';
		// 人数欄は出ない。
		await expect( page.locator( '#vkbm-reservation-guests' ) ).toHaveCount(
			0
		);

		const expected = [
			{ label: 'サービス基本料金', amount: BASE_PRICE },
			{ label: '指名料', amount: NOMINATION_FEE },
			{ label: '基本料金合計', amount: BASE_PRICE + NOMINATION_FEE },
		];
		const reservationRows = await readRows( page, rowSelector );
		expect( reservationRows ).toEqual( expected );
		await expect( page.locator( rowSelector ).first() ).not.toContainText(
			'×'
		);

		await proceedToConfirm( page );
		const confirmRows = await readConfirmPriceRows( page );
		expect( confirmRows ).toEqual( expected );
	} );
} );
