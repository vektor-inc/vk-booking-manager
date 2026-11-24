/**
 * issue #1128: BM 設定「表記」タブの「サービスメニューの呼び方」を、JavaScript で描く予約画面・確認画面の
 * 「メニュー」「サービスメニュー」の表記へ反映する変更の検証。
 *
 * 確かめること:
 * - 呼び方を「プラン」にすると、予約画面・確認画面の予約ブロックの中に「メニュー」が残らず「プラン」と出る
 * - 呼び方が空欄のサイトは、今までどおり「メニュー」「サービスメニュー」と出る
 *
 * 実行環境: playwright.config の baseURL（テスト用 wp-env、サイトの言語は日本語）に対して実行する。
 */
// @category 予約画面・カレンダー
import { test, expect, Page } from '@playwright/test';
import { getServiceMenuId } from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// 予約ブロックの外側（テーマのナビゲーションなど）に出る「メニュー」を拾わないよう、予約ブロックの中だけを見る。
const RESERVATION_BLOCK = '.vkbm-reservation-block';

/**
 * 予約ページを開き、global-setup の「Service Menu 1」を選んでカレンダーを開くところまで進める。
 *
 * @param page Playwright の Page。
 */
async function openReservationForServiceMenu( page: Page ): Promise< void > {
	// baseURL に対する相対パスで予約ページを開く。
	await page.goto( '/booking/' );

	// 他のテストが残したメニューを巻き込まないよう、data-menu-id で「Service Menu 1」の予約ボタンを指定する（#566）。
	const menuId = getServiceMenuId();
	const reserveButton = page.locator(
		`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
	);
	await reserveButton.waitFor( { state: 'visible', timeout: 15000 } );

	// 予約ボタンを押す前に、カレンダーの応答の待ち受けを作っておく。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	await reserveButton.click();

	// 空き枠のある日を選ぶ（共有ヘルパーで日付のずれによる失敗を避ける）。
	await selectAvailableCalendarDay( page, calendarMetaResponse );
}

/**
 * 予約画面で最初の時間枠を選び、確認画面へ進む。
 *
 * @param page Playwright の Page。
 */
async function proceedToConfirm( page: Page ): Promise< void > {
	// 最初の時間枠を選ぶ。
	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();

	// 「予約へ進む」が押せるようになるのを待って押す。
	const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( proceed ).toBeEnabled( { timeout: 10000 } );
	await proceed.click();

	// 確認画面の予約内容が出るまで待つ。
	await page
		.locator( '.vkbm-confirm__summary' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}

test.describe( 'issue #1128: サービスメニューの呼び方を予約画面・確認画面に反映する', () => {
	// 予約画面・確認画面はログインしていないお客様の状態で確かめる。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeEach( async () => {
		// @step BM 設定の「サービスメニューの呼び方」を空欄に戻しておく
		await configureProviderSettings( {
			menu_label: '',
			menu_label_plural: '',
		} );
	} );

	test.afterEach( async () => {
		// 他の spec へ設定を持ち越さないよう、空欄（既定）へ戻す。
		await configureProviderSettings( {
			menu_label: '',
			menu_label_plural: '',
		} );
	} );

	test( '呼び方を「プラン」にすると、予約画面・確認画面に「メニュー」が残らない', async ( {
		page,
	} ) => {
		// @step BM 設定の「表記」タブで「サービスメニューの呼び方」に「プラン」と入れて保存しておく
		await configureProviderSettings( { menu_label: 'プラン' } );

		// @step ログインせずに予約ページを開き、「Service Menu 1」の予約ボタンを押し、空きのある日を選ぶ
		await openReservationForServiceMenu( page );

		// @check 予約画面の選択欄の見出しが「プラン」になり、予約ブロックの中に「メニュー」の文字が残っていない
		const block = page.locator( RESERVATION_BLOCK ).first();
		const planSummary = page.locator( '.vkbm-plan-summary' ).first();
		await expect( planSummary ).toContainText( 'プラン' );
		await expect( block ).not.toContainText( 'メニュー' );

		// @step 最初の時間枠を選び、確認画面へ進む
		await proceedToConfirm( page );

		// @check 確認画面の予約内容の見出しが「プラン」になり、予約ブロックの中に「メニュー」の文字が残っていない
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toContainText( 'プラン' );
		await expect(
			page.locator( RESERVATION_BLOCK ).first()
		).not.toContainText( 'メニュー' );
	} );

	test( '呼び方が空欄のサイトは、今までどおり「メニュー」と表示される', async ( {
		page,
	} ) => {
		// 呼び方は beforeEach で空欄にしてある。
		// @step ログインせずに予約ページを開き、「Service Menu 1」の予約ボタンを押し、空きのある日を選ぶ
		await openReservationForServiceMenu( page );

		// @check 予約画面の選択欄の見出しが「メニュー」のまま表示され、「プラン」とは表示されない
		const planSummary = page.locator( '.vkbm-plan-summary' ).first();
		await expect( planSummary ).toContainText( 'メニュー' );
		await expect( planSummary ).not.toContainText( 'プラン' );

		// @step 最初の時間枠を選び、確認画面へ進む
		await proceedToConfirm( page );

		// @check 確認画面の予約内容の見出しが「メニュー」のまま表示される
		const summary = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( summary ).toContainText( 'メニュー' );
		await expect( summary ).not.toContainText( 'プラン' );
	} );
} );
