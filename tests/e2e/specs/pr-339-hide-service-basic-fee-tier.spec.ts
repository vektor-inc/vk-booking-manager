/**
 * PR #339 (issue #338): 料金区分メニューで「サービス基本料金」行を非表示にする変更のフロント/管理画面検証。
 *
 * 変更内容:
 * - 料金区分（guest_tiers）を使うメニューでは、区分ごとの小計が別途表示されるため
 *   常に0円で冗長になる「サービス基本料金（Service basic fee）」行を非表示にする。
 * - 区分なしの単一料金メニューでは従来どおり「サービス基本料金」行を表示する（デグレなし）。
 * - 「基本料金合計（Total basic fee）」「区分ごとの小計」「貸切料金」は据え置き。
 *
 * 対象コード:
 * - フロント確認画面: src/blocks/reservation/booking-confirm-app.js
 *   （hasGuestTiers のとき SummaryRow「サービス基本料金」を描画しない）
 * - 管理画面 予約編集メタボックス: src/bookings/class-booking-admin.php
 *   （$guest_tiers が空でないとき「サービス基本料金」の <tr> を出力しない）
 *
 * 本 spec の検証内容:
 * - フロント / 区分あり: 確認画面で「サービス基本料金」行が出ない・区分内訳/基本料金合計は出る
 * - フロント / 区分なし: 確認画面で「サービス基本料金」行が出る（デグレ確認）
 * - 管理画面 / 区分あり予約: メタボックスで「サービス基本料金」行が出ない・基本料金合計は出る
 * - 管理画面 / 区分なし予約: メタボックスで「サービス基本料金」行が出る（デグレ確認）
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	setStaffEnabled,
	getStaffEnabled,
	loginAsAdmin,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// 料金区分メニューの想定値。
// Tier menu fixture values.
const TIER_MENU_TITLE = 'Tier Menu 339';
const TIER_GENERAL_PRICE = 4000; // 一般
const TIER_CHILD_PRICE = 3000; // 子供
const TIER_MAX_CAPACITY = 10;

// 単一料金メニュー（区分なし）の想定値。
// Single-price menu fixture values (no tiers).
const SINGLE_MENU_TITLE = 'Single Menu 339';
const SINGLE_BASE_PRICE = 5000; // メニュー基本料金

// 確認画面 / メタボックスの料金行ラベル（ja ロケール、po 定義済み）。
// Fee row labels (ja locale, defined in the .po files).
const LABEL_BASE = 'サービス基本料金'; // Service basic fee
const LABEL_TOTAL = '基本料金合計'; // Total basic fee

let originalStaffEnabled = true;
let tierMenuId = '';
let singleMenuId = '';

/**
 * 指定した投稿タイプ・タイトルの既存投稿をすべて force delete する PHP スニペットを返す。
 * 各シード関数の冒頭で呼び、同名投稿を消して決定的（冪等）な初期状態を作るために使う。
 * Return a PHP snippet that force-deletes every existing post of the given
 * post type and title, so each seeder starts from a deterministic (idempotent) state.
 *
 * 注意: postType / title は PHP の単一引用符リテラルに埋め込まれる。呼び出し側は
 * 固定文字列（定数 or テスト内で定義したタイトル）のみを渡すこと。
 *
 * @param postType 対象の投稿タイプ（例: vkbm_service_menu / vkbm_booking）
 * @param title    削除対象のタイトル
 * @return 同名投稿削除の PHP スニペット
 */
function deletePostsByTitlePhp( postType: string, title: string ): string {
	return `
		// 同名の既存投稿を削除して決定的な状態にする。
		$existing = get_posts( array(
			'post_type'   => '${ postType }',
			'post_status' => 'any',
			'title'       => '${ title }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
	`;
}

/**
 * シード PHP が echo した post ID を検証し、正の整数でなければ throw する。
 * 各シード関数の戻り値検証（/^\d+$/ チェック＋throw）を共通化したもの。
 * Validate the post ID echoed by a seeding PHP snippet and throw on failure.
 *
 * @param result  wpEvalPhp の戻り値（trim 済みの文字列）
 * @param context 失敗メッセージに含める文脈（例: 'Tier menu' / `Booking (${title})`）
 * @return 検証済みの post ID（数値文字列）
 */
function assertSeededId( result: string, context: string ): string {
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ context } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 料金区分メニュー（一般 4000 / 子供 3000・容量 10・複数人予約ON）を作成する。
 * グローバルセットアップ済みのスタッフを割り当てる。同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedTierMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };
		${ deletePostsByTitlePhp( 'vkbm_service_menu', TIER_MENU_TITLE ) }
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ TIER_MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create tier menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', ${ TIER_MAX_CAPACITY } );
		update_post_meta( $menu_id, '_vkbm_price_tiers', array(
			array( 'label' => '一般', 'price' => ${ TIER_GENERAL_PRICE } ),
			array( 'label' => '子供', 'price' => ${ TIER_CHILD_PRICE } ),
		) );
		echo $menu_id;
	`;
	return assertSeededId( wpEvalPhp( phpCode ).trim(), 'Tier menu' );
}

/**
 * 単一料金メニュー（区分なし・基本料金 5000）を作成する。
 * グローバルセットアップ済みのスタッフを割り当てる。同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedSingleMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };
		${ deletePostsByTitlePhp( 'vkbm_service_menu', SINGLE_MENU_TITLE ) }
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ SINGLE_MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create single menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ SINGLE_BASE_PRICE } );
		echo $menu_id;
	`;
	return assertSeededId( wpEvalPhp( phpCode ).trim(), 'Single menu' );
}

// 指定ラベル（dt のタイトル）を持つ確認サマリ行のロケータを返す。
// Locator for confirm summary rows whose title (dt) equals the given label.
function summaryRowByTitle( page: Page, title: string ) {
	return page.locator( '.vkbm-confirm__summary-item', {
		has: page.locator( '.vkbm-confirm__summary-item-title', {
			hasText: title,
		} ),
	} );
}

// 予約ページを開き、指定タイトルのメニューを選んで日付・スロットまで進めるヘルパー。
// Open the booking page, pick the menu by title, then select a date and slot.
async function gotoMenuPlanSummary( page: Page, menuTitle: string ) {
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'networkidle' );

	const reserveButtons = page.locator( '.vkbm-menu-loop__button--reserve' );
	await reserveButtons
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	// 対象メニューのカードを名前で特定してその予約ボタンを押す。
	const menuCard = page
		.locator( '.vkbm-menu-loop__item', { hasText: menuTitle } )
		.first();
	// クリックで発火する calendar-meta の Ajax を取りこぼさないよう、クリック前に promise を仕込む。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	await menuCard
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first()
		.click();

	// 空き枠のある日を選ぶ（共有ヘルパーでフレーク回避）。
	await selectAvailableCalendarDay( page, calendarMetaResponse );
	await page.waitForTimeout( 1000 );

	// 最初の時間枠を選ぶ。
	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();
	await page.waitForTimeout( 1000 );
}

test.describe( 'PR #339: 料金区分メニューで「サービス基本料金」行を非表示（フロント）', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	// 料金区分は「指名OFF＋複数人予約ON」でのみ有効。グローバルセットアップは指名ON・
	// 料金区分なしのため、この describe の間だけ指名OFFに切り替え、メニューを用意する。
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		tierMenuId = seedTierMenu();
		singleMenuId = seedSingleMenu();
	} );

	test.afterAll( () => {
		// テストデータを削除し、指名設定を元へ復元してテスト間の状態漏れを防ぐ。
		for ( const id of [ tierMenuId, singleMenuId ] ) {
			if ( id ) {
				wpCliArgs( [ 'post', 'delete', id, '--force' ], {
					stdio: 'ignore',
				} );
			}
		}
		tierMenuId = '';
		singleMenuId = '';
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '区分あり: 確認画面に「サービス基本料金」行が出ない／区分内訳・基本料金合計は出る', async ( {
		page,
	} ) => {
		await gotoMenuPlanSummary( page, TIER_MENU_TITLE );

		// 区分ごとの人数セレクトが表示されるまで待つ。
		await page.waitForSelector( '#vkbm-reservation-tier-0', {
			state: 'visible',
			timeout: 10000,
		} );

		// 一般2・子供1 を入力する。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '2' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '1' );
		await page.waitForTimeout( 300 );

		// 予約へ進む → 確認画面へ。
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeEnabled( { timeout: 10000 } );
		await proceed.click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

		// 確認サマリの表示を待つ。
		const summary = page.locator( '.vkbm-confirm__summary, .vkbm-confirm' );
		await summary.first().waitFor( { state: 'visible', timeout: 15000 } );
		// 区分内訳（一般）行の出現を待って React の再レンダリング収束を決定的に待機する。
		await summaryRowByTitle( page, '一般' )
			.first()
			.waitFor( { state: 'attached', timeout: 15000 } );

		// 本丸: 「サービス基本料金」行が描画されない（区分ありのため非表示）。
		await expect( summaryRowByTitle( page, LABEL_BASE ) ).toHaveCount( 0 );

		// 区分内訳（一般・子供）は表示される。
		await expect( summaryRowByTitle( page, '一般' ).first() ).toBeVisible();
		await expect( summaryRowByTitle( page, '子供' ).first() ).toBeVisible();

		// 基本料金合計は表示される（区分ありでも据え置き）。合計は 4000*2 + 3000*1 = 11,000。
		const totalRow = summaryRowByTitle( page, LABEL_TOTAL );
		await expect( totalRow ).toHaveCount( 1 );
		await expect( totalRow ).toBeVisible();
		await expect( totalRow ).toContainText( '11,000' );

		// スクリーンショット（区分あり・確認画面）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/pr339-after-front-tier-confirm.png',
			fullPage: true,
		} );
	} );

	test( '区分なし: 確認画面に「サービス基本料金」行が出る（デグレ確認）', async ( {
		page,
	} ) => {
		await gotoMenuPlanSummary( page, SINGLE_MENU_TITLE );

		// 単一料金メニューは区分セレクトが無く、そのまま「予約へ進む」が押せる。
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeEnabled( { timeout: 10000 } );
		await proceed.click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

		// 確認サマリの表示を待つ。
		const summary = page.locator( '.vkbm-confirm__summary, .vkbm-confirm' );
		await summary.first().waitFor( { state: 'visible', timeout: 15000 } );
		// 「サービス基本料金」行の出現を待つ（描画収束の決定的待機）。
		await summaryRowByTitle( page, LABEL_BASE )
			.first()
			.waitFor( { state: 'attached', timeout: 15000 } );

		// 本丸: 区分なしメニューでは「サービス基本料金」行が表示される（従来どおり）。
		const baseRow = summaryRowByTitle( page, LABEL_BASE );
		await expect( baseRow ).toHaveCount( 1 );
		await expect( baseRow ).toBeVisible();
		await expect( baseRow ).toContainText( '5,000' );

		// 基本料金合計も表示される。
		const totalRow = summaryRowByTitle( page, LABEL_TOTAL );
		await expect( totalRow ).toHaveCount( 1 );
		await expect( totalRow ).toBeVisible();
		await expect( totalRow ).toContainText( '5,000' );

		// スクリーンショット（区分なし・確認画面）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/pr339-after-front-single-confirm.png',
			fullPage: true,
		} );
	} );
} );

// ----------------------------------------------------------------------------
// 管理画面 予約編集メタボックスの検証
// ----------------------------------------------------------------------------

// 区分ありの予約・区分なしの予約を WP-CLI/eval で直接作成し、メタボックスの表示を検証する。
// フロントのフルフローを介さず、guest_tiers スナップショットの有無だけを切り分けて確認する。
const ADMIN_TIER_BOOKING_TITLE = 'PR339 Tier Booking';
const ADMIN_SINGLE_BOOKING_TITLE = 'PR339 Single Booking';

let adminTierBookingId = '';
let adminSingleBookingId = '';

/**
 * 予約（vkbm_booking）投稿を作成する共通ヘルパー。
 * withTiers=true のとき区分スナップショット（_vkbm_booking_guest_tiers）を付与する。
 *
 * @param title     予約投稿のタイトル
 * @param withTiers 区分スナップショットを付与するか
 * @return 作成した予約の post ID（数値文字列）
 */
function seedBooking( title: string, withTiers: boolean ): string {
	// 予約の開始/終了（未来日時・固定文字列で決定的に）。
	const start = '2099-01-01 10:00:00';
	const end = '2099-01-01 11:00:00';
	const tiersPhp = withTiers
		? `update_post_meta( $booking_id, '_vkbm_booking_guest_tiers', array(
				array( 'label' => '一般', 'price' => ${ TIER_GENERAL_PRICE }, 'count' => 2 ),
				array( 'label' => '子供', 'price' => ${ TIER_CHILD_PRICE }, 'count' => 1 ),
			) );
			update_post_meta( $booking_id, '_vkbm_booking_guests', 3 );
			// 区分予約でも基本料金スナップショットは 0 で保存される（区分小計で計上されるため）。
			update_post_meta( $booking_id, '_vkbm_booking_service_base_price', 0 );
			update_post_meta( $booking_id, '_vkbm_booking_base_total_price', ${
				TIER_GENERAL_PRICE * 2 + TIER_CHILD_PRICE
			} );`
		: `update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
			update_post_meta( $booking_id, '_vkbm_booking_service_base_price', ${ SINGLE_BASE_PRICE } );
			update_post_meta( $booking_id, '_vkbm_booking_base_total_price', ${ SINGLE_BASE_PRICE } );`;

	const phpCode = `
		${ deletePostsByTitlePhp( 'vkbm_booking', title ) }
		$booking_id = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $booking_id ) || ! $booking_id ) {
			echo 'Error: failed to create booking';
			return;
		}
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '${ start }' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '${ end }' );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'Dummy Customer' );
		${ tiersPhp }
		echo $booking_id;
	`;
	return assertSeededId(
		wpEvalPhp( phpCode ).trim(),
		`Booking (${ title })`
	);
}

// 予約編集画面を開き、料金メタボックス（vkbm-booking-details）内の行を確認するヘルパー。
async function openBookingEdit( page: Page, bookingId: string ) {
	await page.goto( `/wp-admin/post.php?post=${ bookingId }&action=edit` );
	await page.waitForLoadState( 'domcontentloaded' );
	// メタボックスが描画されるまで待つ。
	await page
		.locator( '#vkbm-booking-details' )
		.waitFor( { state: 'visible', timeout: 15000 } );
}

// メタボックス内で、指定ラベルを th に持つ行（<th scope="row">ラベル</th>）のロケータを返す。
function metaRowByTh( page: Page, label: string ) {
	return page.locator( '#vkbm-booking-details tr', {
		has: page.locator( 'th[scope="row"]', { hasText: label } ),
	} );
}

test.describe( 'PR #339: 料金区分予約で「サービス基本料金」行を非表示（管理画面メタボックス）', () => {
	test.beforeAll( () => {
		adminTierBookingId = seedBooking( ADMIN_TIER_BOOKING_TITLE, true );
		adminSingleBookingId = seedBooking( ADMIN_SINGLE_BOOKING_TITLE, false );
	} );

	test.afterAll( () => {
		for ( const id of [ adminTierBookingId, adminSingleBookingId ] ) {
			if ( id ) {
				wpCliArgs( [ 'post', 'delete', id, '--force' ], {
					stdio: 'ignore',
				} );
			}
		}
		adminTierBookingId = '';
		adminSingleBookingId = '';
	} );

	test( '区分あり予約: メタボックスに「サービス基本料金」行が出ない／基本料金合計は出る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await openBookingEdit( page, adminTierBookingId );

		// 本丸: 区分あり予約では「サービス基本料金」の行が出力されない。
		await expect( metaRowByTh( page, LABEL_BASE ) ).toHaveCount( 0 );

		// 基本料金合計の行は出る（区分ありでも据え置き）。
		await expect( metaRowByTh( page, LABEL_TOTAL ) ).toHaveCount( 1 );
		await expect( metaRowByTh( page, LABEL_TOTAL ) ).toBeVisible();

		// スクリーンショット（区分あり・メタボックス）。メタボックスに絞って撮る。
		await page.locator( '#vkbm-booking-details' ).screenshot( {
			path: '/tmp/vkbm-shots/pr339-after-admin-tier-metabox.png',
		} );
	} );

	test( '区分なし予約: メタボックスに「サービス基本料金」行が出る（デグレ確認）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await openBookingEdit( page, adminSingleBookingId );

		// 本丸: 区分なし予約では「サービス基本料金」の行が表示される（従来どおり）。
		await expect( metaRowByTh( page, LABEL_BASE ) ).toHaveCount( 1 );
		await expect( metaRowByTh( page, LABEL_BASE ) ).toBeVisible();

		// 基本料金合計の行も出る。
		await expect( metaRowByTh( page, LABEL_TOTAL ) ).toHaveCount( 1 );
		await expect( metaRowByTh( page, LABEL_TOTAL ) ).toBeVisible();

		// スクリーンショット（区分なし・メタボックス）。
		await page.locator( '#vkbm-booking-details' ).screenshot( {
			path: '/tmp/vkbm-shots/pr339-after-admin-single-metabox.png',
		} );
	} );
} );
