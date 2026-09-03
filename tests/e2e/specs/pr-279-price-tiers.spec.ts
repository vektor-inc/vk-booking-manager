/**
 * PR #279 (issue #252): 複数人予約の料金区分（大人料金・子供料金など）フロント検証
 *
 * 検証内容:
 * - 予約フォームで区分ごと（一般／子供）の人数セレクトが表示される
 * - 一般2・子供1 で概算合計 11000 円が表示される
 * - 合計が残枠を超えると警告メッセージが表示される
 * - 一般3・子供0（合計1名以上・特定区分0）で「予約へ進む」が押せる
 * - 全区分0（合計0名）では「予約へ進む」が押せない
 * - 確認画面で区分内訳＋合計が表示される（count が落ちず人数が正しい＝CodeRabbit 指摘の回帰確認）
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env / ポート 8889）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - テストデータ（料金区分メニュー・指名OFF）は beforeAll で WP-CLI / wpEvalPhp により
 *   セットアップし、afterAll で元の状態へ復元する（グローバルセットアップは指名ON・
 *   料金区分なしのため、本 spec が自前で用意する）。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// 料金区分メニューの想定値（テスト本文の期待値と対応）。
// Tier menu fixture values (correspond to the expectations in the test bodies).
const TIER_MENU_TITLE = 'Tier Menu';
const TIER_GENERAL_PRICE = 4000; // 一般
const TIER_CHILD_PRICE = 3000; // 子供
const TIER_MAX_CAPACITY = 10; // 残枠超過テスト（一般10＋子供1=11>10）の基準

let originalStaffEnabled = true;
let tierMenuId = '';

/**
 * 料金区分メニュー（一般 4000 / 子供 3000・容量 10・複数人予約ON）を作成し、
 * グローバルセットアップ済みのスタッフを割り当てる。同名メニューがあれば作り直す（冪等）。
 *
 * Create the price-tier service menu (一般 4000 / 子供 3000, capacity 10, multi-guest ON)
 * and assign the staff created by global setup. Re-create if a menu with the same title
 * already exists (idempotent).
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedTierMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };

		// 既存の同名メニューを削除して決定的な状態にする。
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ TIER_MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}

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
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Tier menu seeding failed: "${ result }"` );
	}
	return result;
}

// 予約ページを開き、料金区分メニューを選んで日付・スロットまで進めるヘルパー。
// Open the booking page, pick the tier menu, then select a date and slot.
async function gotoTierPlanSummary( page: Page ) {
	// baseURL（playwright.config）に対する相対パスで開く。
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'networkidle' );

	// 料金区分メニュー（Tier Menu）の予約ボタンをクリックする。
	const reserveButtons = page.locator( '.vkbm-menu-loop__button--reserve' );
	await reserveButtons
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	// Tier Menu のカードを名前で特定してその予約ボタンを押す。
	const tierCard = page
		.locator( '.vkbm-menu-loop__item', { hasText: TIER_MENU_TITLE } )
		.first();
	// 予約ボタンのクリック前に calendar-meta のレスポンス待ちをセットアップする。
	// クリックで発火する Ajax を取りこぼさないよう、クリックより前に promise を仕込むのが肝。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	await tierCard
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first()
		.click();

	// カレンダーのロード完了（再レンダリング収束）を待ってから、空き枠のある日を選ぶ。
	// 共有ヘルパーがレスポンス待ち＋スピナー detach＋--available 出現待ちを束ねてフレークを防ぐ。
	await selectAvailableCalendarDay( page, calendarMetaResponse );
	await page.waitForTimeout( 1000 );

	// 最初の時間枠を選ぶ。
	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();
	await page.waitForTimeout( 1000 );

	// 区分ごとの人数セレクトが表示されるまで待つ。
	await page.waitForSelector( '#vkbm-reservation-tier-0', {
		state: 'visible',
		timeout: 10000,
	} );
}

test.describe( 'PR #279: 料金区分フロント', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	// 料金区分は「指名OFF＋複数人予約ON」でのみ有効。グローバルセットアップは指名ON・
	// 料金区分なしのため、この describe の間だけ指名OFFに切り替え、メニューを用意する。
	// Price tiers are active only when nomination is OFF and multi-guest is ON.
	// Global setup leaves nomination ON with no tier menu, so toggle nomination OFF
	// and seed the menu for the duration of this describe block.
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		tierMenuId = seedTierMenu();
	} );

	test.afterAll( () => {
		// テストデータを削除し、指名設定を元の状態へ復元してテスト間の状態漏れを防ぐ。
		if ( tierMenuId ) {
			wpCliArgs( [ 'post', 'delete', tierMenuId, '--force' ], {
				stdio: 'ignore',
			} );
			tierMenuId = '';
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '区分ごとの人数セレクトが表示され、一般2・子供1で概算11000円', async ( {
		page,
	} ) => {
		await gotoTierPlanSummary( page );

		// 区分は2件（一般／子供）→ tier-0, tier-1 が存在する。
		await expect(
			page.locator( '#vkbm-reservation-tier-0' )
		).toBeVisible();
		await expect(
			page.locator( '#vkbm-reservation-tier-1' )
		).toBeVisible();
		// 3件目（tier-2）は存在しない。
		await expect( page.locator( '#vkbm-reservation-tier-2' ) ).toHaveCount(
			0
		);

		// 区分ラベル（一般・子供）と料金（4,000円・3,000円）が表示されている。
		const tierBlock = page.locator( '.vkbm-plan-summary__guest-tiers' );
		await expect( tierBlock ).toContainText( '一般' );
		await expect( tierBlock ).toContainText( '子供' );

		// 一般2・子供1 を入力する。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '2' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '1' );
		await page.waitForTimeout( 300 );

		// 概算合計 11,000 が表示される（4000*2 + 3000*1）。
		const total = page.locator( '.vkbm-plan-summary__guest-tiers-total' );
		await expect( total ).toContainText( '11,000' );

		// スクリーンショット（フロント予約フォーム・区分別人数）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/after-front-tier-form.png',
			fullPage: true,
		} );

		// 「予約へ進む」が押せる（合計1名以上・残枠内）。
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeEnabled();
	} );

	test( '残枠超過で警告メッセージが表示される', async ( { page } ) => {
		await gotoTierPlanSummary( page );

		// 容量は 10。一般10・子供1 = 11 で残枠超過させる。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '10' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '1' );
		await page.waitForTimeout( 300 );

		// 残枠超過の警告が表示される（無言で進めない、ではなく理由が出る）。
		const warning = page.locator( '.vkbm-plan-summary__hint--warning' );
		await expect( warning ).toBeVisible();
		// 「予約へ進む」は押せない。
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeDisabled();

		await page.screenshot( {
			path: '/tmp/vkbm-shots/after-front-overcapacity-warning.png',
			fullPage: true,
		} );
	} );

	test( '一般3・子供0で進める／全区分0では進めない', async ( { page } ) => {
		await gotoTierPlanSummary( page );

		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();

		// 初期は全区分0 → 進めない。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '0' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '0' );
		await page.waitForTimeout( 300 );
		await expect( proceed ).toBeDisabled();

		// 一般3・子供0（合計1名以上・特定区分0）→ 進める。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '3' );
		await page.waitForTimeout( 300 );
		await expect( proceed ).toBeEnabled();

		// 概算合計 12,000（4000*3）。
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tiers-total' )
		).toContainText( '12,000' );
	} );

	test( '確認画面に区分内訳と合計が表示される（count 回帰確認）', async ( {
		page,
	} ) => {
		await gotoTierPlanSummary( page );

		// 一般2・子供1 を入力。
		await page.locator( '#vkbm-reservation-tier-0' ).fill( '2' );
		await page.locator( '#vkbm-reservation-tier-1' ).fill( '1' );
		await page.waitForTimeout( 300 );

		// 予約へ進む → 確認画面へ。
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		await page.waitForTimeout( 2000 );

		// 確認画面のサマリに区分内訳が表示される。
		const summary = page.locator( '.vkbm-confirm__summary, .vkbm-confirm' );
		await summary.first().waitFor( { state: 'visible', timeout: 15000 } );

		// 区分名（一般・子供）が確認サマリに出る。
		await expect( page.locator( 'body' ) ).toContainText( '一般' );
		await expect( page.locator( 'body' ) ).toContainText( '子供' );

		// 各区分の人数が 0 に落ちていないこと（一般=2名, 子供=1名）。
		// confirm の summary-item は dt=ラベル, dd=人数。
		const generalItem = page
			.locator( '.vkbm-confirm__summary-item', { hasText: '一般' } )
			.first();
		const childItem = page
			.locator( '.vkbm-confirm__summary-item', { hasText: '子供' } )
			.first();
		await expect( generalItem ).toContainText( '2' );
		await expect( childItem ).toContainText( '1' );

		// 合計（基本料金合計）に 11,000 が出る。
		await expect( page.locator( 'body' ) ).toContainText( '11,000' );

		await page.screenshot( {
			path: '/tmp/vkbm-shots/after-front-confirm-breakdown.png',
			fullPage: true,
		} );
	} );
} );
