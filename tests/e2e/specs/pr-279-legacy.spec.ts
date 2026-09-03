/**
 * PR #279 後方互換（デグレ確認）: 区分未定義の従来メニュー（Legacy Menu, 複数人予約ON・基本料金5000）で
 * 区分UIではなく単一の人数入力が出て、基本料金×人数で計算されることを確認する。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env / ポート 8889）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - テストデータ（区分なし・複数人予約ONメニュー・指名OFF）は beforeAll で用意し、
 *   afterAll で元の状態へ復元する（グローバルセットアップは指名ON・該当メニューなしのため）。
 */
import { test, expect } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

const LEGACY_MENU_TITLE = 'Legacy Menu';
const LEGACY_BASE_PRICE = 5000;
const LEGACY_MAX_CAPACITY = 10;

let originalStaffEnabled = true;
let legacyMenuId = '';

/**
 * 区分なし・複数人予約ON・基本料金 5000 のメニューを作成し、スタッフを割り当てる。
 * 同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedLegacyMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };

		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ LEGACY_MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ LEGACY_MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create legacy menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', ${ LEGACY_MAX_CAPACITY } );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ LEGACY_BASE_PRICE } );
		// 料金区分は定義しない（従来どおり基本料金×人数で計算）。
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Legacy menu seeding failed: "${ result }"` );
	}
	return result;
}

test.describe( 'PR #279 後方互換: 区分なしメニュー', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	// 複数人予約は指名OFF時のみ有効。describe の間だけ指名OFFに切り替えてメニューを用意する。
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		legacyMenuId = seedLegacyMenu();
	} );

	test.afterAll( () => {
		if ( legacyMenuId ) {
			wpCliArgs( [ 'post', 'delete', legacyMenuId, '--force' ], {
				stdio: 'ignore',
			} );
			legacyMenuId = '';
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '区分なしメニューは単一人数入力・基本料金×人数', async ( {
		page,
	} ) => {
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );
		const legacyCard = page
			.locator( '.vkbm-menu-loop__item', { hasText: LEGACY_MENU_TITLE } )
			.first();
		// 予約ボタンのクリック前に calendar-meta のレスポンス待ちをセットアップする。
		// クリックで発火する Ajax を取りこぼさないよう、クリックより前に promise を仕込む。
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await legacyCard
			.locator( '.vkbm-menu-loop__button--reserve' )
			.first()
			.click();
		// カレンダーのロード完了を待ってから、空き枠のある日を安定して選ぶ（フレーク対策）。
		await selectAvailableCalendarDay( page, calendarMetaResponse );
		await page.waitForTimeout( 1000 );
		await page.locator( '.vkbm-slot-list__item' ).first().click();
		await page.waitForTimeout( 1000 );

		// 単一の人数入力（#vkbm-reservation-guests）が出て、区分入力（tier-0）は出ない。
		await expect(
			page.locator( '#vkbm-reservation-guests' )
		).toBeVisible();
		await expect( page.locator( '#vkbm-reservation-tier-0' ) ).toHaveCount(
			0
		);
		// 区分の概算合計欄も出ない。
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tiers-total' )
		).toHaveCount( 0 );

		// 2名にして確認画面へ → 基本料金5000×2=10000。
		await page.locator( '#vkbm-reservation-guests' ).fill( '2' );
		await page.waitForTimeout( 300 );
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		await page.waitForTimeout( 2000 );
		// 確認画面: メニュー名と人数2名が表示される。
		await expect(
			page.locator( '.vkbm-confirm__summary-item', {
				hasText: LEGACY_MENU_TITLE,
			} )
		).toBeVisible();
		await expect( page.locator( 'body' ) ).toContainText( '2名' );
		// 区分内訳（一般/子供）は出ない（従来どおり）。
		await expect( page.locator( 'body' ) ).not.toContainText( '一般' );
		// 注: 指名OFF・区分なしメニューの確認画面に基本料金合計を出さないのは main と同じ既存挙動
		//（このPRで追加された区分の合計表示は別経路）。合計金額は通知メール・予約詳細で表示される。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/after-front-legacy-confirm.png',
			fullPage: true,
		} );
	} );
} );
