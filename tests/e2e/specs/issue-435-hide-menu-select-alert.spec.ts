import { test, expect } from '@playwright/test';
import {
	getMenuListDisplayEnabled,
	setMenuListDisplayEnabled,
	getMenuSearchDisplayEnabled,
	setMenuSearchDisplayEnabled,
} from '../utils/helpers';

/**
 * Issue #435: 「予約ページの表示要素」で「サービスメニュー一覧」を表示する設定にしている
 * ときは「メニューを選択してください。」のアラートを表示しない。
 *
 * 対象コード:
 * - src/blocks/reservation/booking-ui/selected-plan-summary.js
 *   （showMenuList props が true のとき、menuId 未選択時の
 *   `.vkbm-plan-summary__pricing--alert` を描画しない）
 * - src/blocks/reservation/app.js
 *   （SelectedPlanSummary へ providerSettings.showMenuList を渡す）
 *
 * app.js の shouldShowMenuSearch は
 * `providerSettings.showMenuSearch || ! providerSettings.showMenuList` で決まるため、
 * SelectedPlanSummary 自体（絞り込み検索の UI）は「サービスメニュー一覧」が OFF の
 * ときは自動的に表示される。逆に ON のときに SelectedPlanSummary を表示させるには、
 * 「絞り込み検索」も明示的に ON にする必要がある。そのため2ケースとも
 * SelectedPlanSummary が描画されている前提で、アラートの有無だけを検証する。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 */
test.describe( 'Issue #435: hide "Please select a menu" alert when the menu list is shown', () => {
	let originalMenuListDisplayEnabled = false;
	let originalMenuSearchDisplayEnabled = false;

	test.beforeAll( () => {
		// 後片付けで正確に元へ戻すため、既存値を控えておく。
		originalMenuListDisplayEnabled = getMenuListDisplayEnabled();
		originalMenuSearchDisplayEnabled = getMenuSearchDisplayEnabled();
	} );

	test.afterAll( () => {
		setMenuListDisplayEnabled( originalMenuListDisplayEnabled );
		setMenuSearchDisplayEnabled( originalMenuSearchDisplayEnabled );
	} );

	test( '「サービスメニュー一覧」ON＋「絞り込み検索」ON のとき、メニュー未選択でアラートが出ない', async ( {
		page,
	} ) => {
		setMenuListDisplayEnabled( true );
		setMenuSearchDisplayEnabled( true );

		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// 絞り込み検索（SelectedPlanSummary）自体は表示されていることを先に確認する
		// （表示条件を満たせていないまま「アラートが無い」と誤判定しないため）。
		await expect(
			page.locator( '.vkbm-plan-summary__selectors' )
		).toBeVisible( { timeout: 10000 } );

		// サービスメニュー一覧のカードが1件以上表示されていることを確認する
		// （.vkbm-reservation-content__menu-list は読み込み中・0件でも描画される枠のため、
		// カード本体 .vkbm-menu-loop__item の件数で確認する。
		// global-setup が最低1件の vkbm_service_menu を作成済み）。
		await expect( page.locator( '.vkbm-menu-loop__item' ) ).not.toHaveCount(
			0,
			{ timeout: 10000 }
		);

		// 「メニューを選択してください。」のアラートが出ていないこと。
		await expect(
			page.locator( '.vkbm-plan-summary__pricing--alert' )
		).toHaveCount( 0 );
	} );

	test( '「サービスメニュー一覧」OFF のとき、メニュー未選択でアラートが出る', async ( {
		page,
	} ) => {
		setMenuListDisplayEnabled( false );

		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// 「サービスメニュー一覧」が OFF のときは shouldShowMenuSearch が自動的に true になり
		// （app.js: providerSettings.showMenuSearch || ! providerSettings.showMenuList）、
		// 絞り込み検索（SelectedPlanSummary）自体は表示される。
		await expect(
			page.locator( '.vkbm-plan-summary__selectors' )
		).toBeVisible( { timeout: 10000 } );

		// 「メニューを選択してください。」のアラートが従来どおり出ていること。
		await expect(
			page.locator( '.vkbm-plan-summary__pricing--alert' )
		).toBeVisible( { timeout: 10000 } );
	} );
} );
