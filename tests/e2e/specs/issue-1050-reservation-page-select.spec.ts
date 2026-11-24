/**
 * issue #1050: 予約ページを固定ページの一覧から選べるようにする。
 *
 * 検証するシナリオ:
 *   1. BM 設定のシステムタブで固定ページを選んで保存すると、予約ボタンのリンク先がそのページになる。
 *   2. 「その他（URL を入力）」を選んだときだけ URL 欄が出て、入力した URL が使われる。
 *   3. 選んだ固定ページをゴミ箱に入れると、設定画面の一覧に「（ゴミ箱）」付きで残り、警告が出て、
 *      予約ボタンは出なくなる。ゴミ箱から戻すと下書きになるため選択は残ったまま警告も残り、
 *      公開すると警告が消える。
 *
 * 注: 語句は言語設定で変わるため、id・クラス名を中心にセレクトする。
 */
// @category 管理画面
import { test, expect } from '@playwright/test';
import {
	getServiceMenuId,
	loginAsAdmin,
	wpCliArgs,
	wpEvalPhp,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const SETTINGS_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system';
const PAGE_SELECT = '#vkbm-reservation-page-url';
const URL_FIELD = '#vkbm-reservation-page-url-field';
const URL_INPUT = '#vkbm-reservation-page-url-input';
const PAGE_INFO = '#vkbm-reservation-page-info';
const FIELD_WARNING = '#vkbm-reservation-page-problem';
const TOP_NOTICE = '#vkbm-reservation-page-problem-notice';
const SAVED_MESSAGE = '#setting-error-vkbm_provider_settings_save';
const SAVE_BUTTON = '#submit';
const RESERVATION_BUTTON_LINK = 'a.vkbm-button[href*="menu_id="]';

const RESERVATION_PAGE_TITLE = 'Reservation Select E2E Page';
const RESERVATION_PAGE_SLUG = 'reservation-select-e2e';
const BUTTON_PAGE_TITLE = 'Reservation Select E2E Button Page';
const BUTTON_PAGE_SLUG = 'reservation-select-e2e-button';

/**
 * 保存済みの基本設定から、このテストが書き換える2項目の現在値を取得する。
 *
 * @return 予約ページの固定ページ ID（未保存なら 0）と予約ページ URL（未保存なら空文字）
 */
function readOriginalSettings(): { pageId: number; url: string } {
	const raw = wpEvalPhp( `
		$settings = get_option( 'vkbm_provider_settings', array() );
		echo wp_json_encode( array(
			'pageId' => isset( $settings['reservation_page_id'] ) ? (int) $settings['reservation_page_id'] : 0,
			'url'    => isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '',
		) );
	` );
	return JSON.parse( raw.trim() );
}

/**
 * 同じタイトルの固定ページを消してから、指定の本文で公開済みの固定ページを作る。
 *
 * @param title   ページのタイトル
 * @param slug    ページのスラッグ
 * @param content ブロックのマークアップ
 * @return 固定ページ ID とパーマリンク
 */
function createPage(
	title: string,
	slug: string,
	content: string
): { id: number; permalink: string } {
	deletePages( title );
	const base64Content = Buffer.from( content ).toString( 'base64' );
	const raw = wpEvalPhp( `
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => '${ title }',
			'post_name'    => '${ slug }',
			'post_content' => base64_decode( '${ base64Content }' ),
		) );
		echo wp_json_encode( array(
			'id'        => (int) $id,
			'permalink' => get_permalink( $id ),
		) );
	` );
	const result = JSON.parse( raw.trim() );
	if ( ! result.id ) {
		throw new Error( `Failed to create the page "${ title }".` );
	}
	return result;
}

/**
 * 指定のタイトルの固定ページを（ゴミ箱のものも含めて）完全に削除する。
 *
 * @param title ページのタイトル
 */
function deletePages( title: string ): void {
	wpEvalPhp( `
		$existing = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'title'       => '${ title }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( (int) $eid, true );
		}
	` );
}

test.describe( 'issue #1050: 予約ページを固定ページから選ぶ', () => {
	let original: { pageId: number; url: string };
	let reservationPage: { id: number; permalink: string };
	let buttonPage: { id: number; permalink: string };

	test.beforeAll( async () => {
		original = readOriginalSettings();

		// @step 予約ブロックを置いた固定ページ「Reservation Select E2E Page」と、既存のサービスメニューの「予約に進む」ボタンを置いた固定ページ「Reservation Select E2E Button Page」を公開しておく
		reservationPage = createPage(
			RESERVATION_PAGE_TITLE,
			RESERVATION_PAGE_SLUG,
			'<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->'
		);
		buttonPage = createPage(
			BUTTON_PAGE_TITLE,
			BUTTON_PAGE_SLUG,
			`<!-- wp:vk-booking-manager/reservation-button {"menuId":${ Number(
				getServiceMenuId()
			) }} /-->`
		);
		wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );
	} );

	test.beforeEach( async ( { page } ) => {
		// @step BM 設定の予約ページを未設定に戻してから、管理者でログインする
		await configureProviderSettings( {
			reservation_page_id: 0,
			reservation_page_url: '',
		} );
		await loginAsAdmin( page );
	} );

	test.afterAll( async () => {
		// 書き換えた2項目を元へ戻し、作った固定ページを消す。
		await configureProviderSettings( {
			reservation_page_id: original.pageId,
			reservation_page_url: original.url,
		} );
		deletePages( RESERVATION_PAGE_TITLE );
		deletePages( BUTTON_PAGE_TITLE );
	} );

	// @title 固定ページを選んで保存すると予約ボタンのリンク先がそのページになる
	test( 'selecting a page changes the reservation button link', async ( {
		page,
	} ) => {
		// @step BM 設定の「システム」タブを開き、「予約ページ」のプルダウンで「Reservation Select E2E Page」を選ぶ
		await page.goto( SETTINGS_URL );
		await page
			.locator( PAGE_SELECT )
			.selectOption( String( reservationPage.id ) );
		// @check URL の入力欄は表示されない
		await expect( page.locator( URL_FIELD ) ).toBeHidden();

		// @step 「変更を保存」を押す
		await page.locator( SAVE_BUTTON ).click();
		await expect( page.locator( SAVED_MESSAGE ) ).toBeVisible();

		// @check 保存後もそのページが選ばれたままで、プルダウンの下にページの URL が新しいタブで開くリンクとして出る
		await expect( page.locator( PAGE_SELECT ) ).toHaveValue(
			String( reservationPage.id )
		);
		const pageInfoLink = page.locator( `${ PAGE_INFO } a` );
		await expect( pageInfoLink ).toBeVisible();
		await expect( pageInfoLink ).toHaveAttribute(
			'href',
			reservationPage.permalink
		);
		await expect( pageInfoLink ).toHaveAttribute( 'target', '_blank' );
		await expect( page.locator( FIELD_WARNING ) ).toHaveCount( 0 );

		// @step 「予約に進む」ボタンを置いた固定ページを開く
		await page.goto( buttonPage.permalink );
		// @check 予約ボタンのリンク先が、選んだ固定ページの URL（メニュー指定付き）になっている
		const buttonLink = page.locator( RESERVATION_BUTTON_LINK ).first();
		await expect( buttonLink ).toBeVisible();
		const href = await buttonLink.getAttribute( 'href' );
		expect( href ).not.toBeNull();
		expect( String( href ).startsWith( reservationPage.permalink ) ).toBe(
			true
		);
	} );

	// @title 「その他（URL を入力）」を選んだときだけ URL 欄が出て、その URL が使われる
	test( 'the "Other" option shows the URL field and uses the entered URL', async ( {
		page,
	} ) => {
		// @step BM 設定の「システム」タブを開き、「予約ページ」のプルダウンで「その他（URL を入力）」を選ぶ
		await page.goto( SETTINGS_URL );
		await expect( page.locator( URL_FIELD ) ).toBeHidden();
		await page.locator( PAGE_SELECT ).selectOption( 'other' );
		// @check URL の入力欄が表示される
		await expect( page.locator( URL_FIELD ) ).toBeVisible();

		// @step URL 欄に予約ブロックを置いた固定ページの URL を入力して「変更を保存」を押す
		await page.locator( URL_INPUT ).fill( reservationPage.permalink );
		await page.locator( SAVE_BUTTON ).click();
		await expect( page.locator( SAVED_MESSAGE ) ).toBeVisible();

		// @check 保存後も「その他（URL を入力）」が選ばれ、入力した URL が残っている
		await expect( page.locator( PAGE_SELECT ) ).toHaveValue( 'other' );
		await expect( page.locator( URL_FIELD ) ).toBeVisible();
		await expect( page.locator( URL_INPUT ) ).toHaveValue(
			reservationPage.permalink
		);

		// 保存後の画面は page.goto() を通らず開くため、フッターの provider-settings.js が
		// プルダウンの変更を拾えるようになる前に操作しないよう、読み込み完了を待つ。
		await page.waitForLoadState( 'load' );

		// @step プルダウンで固定ページを選び直す
		await page
			.locator( PAGE_SELECT )
			.selectOption( String( reservationPage.id ) );
		// @check URL の入力欄が隠れる
		await expect( page.locator( URL_FIELD ) ).toBeHidden();

		// @step 保存せずに「予約に進む」ボタンを置いた固定ページを開く
		await page.goto( buttonPage.permalink );
		// @check 予約ボタンのリンク先が、入力した URL になっている
		const href = await page
			.locator( RESERVATION_BUTTON_LINK )
			.first()
			.getAttribute( 'href' );
		expect( String( href ).startsWith( reservationPage.permalink ) ).toBe(
			true
		);
	} );

	// @title 選んだ固定ページをゴミ箱に入れると警告が出て予約ボタンが消え、戻して公開すると直る
	test( 'trashing the selected page shows a warning and hides the button', async ( {
		page,
	} ) => {
		// @step 予約ページに「Reservation Select E2E Page」を選び、「その他」用の URL にも同じページの URL が残っている状態で、そのページをゴミ箱に入れる
		await configureProviderSettings( {
			reservation_page_id: reservationPage.id,
			reservation_page_url: reservationPage.permalink,
		} );
		wpCliArgs( [ 'post', 'delete', String( reservationPage.id ) ] );

		try {
			// @step BM 設定の「システム」タブを開く
			await page.goto( SETTINGS_URL );
			// @check プルダウンにはそのページが「ゴミ箱」の印付きで残って選ばれていて、プルダウンの下に警告が出る。画面上部の通知は出ない
			await expect( page.locator( PAGE_SELECT ) ).toHaveValue(
				String( reservationPage.id )
			);
			const selectedLabel = await page
				.locator(
					`${ PAGE_SELECT } option[value="${ reservationPage.id }"]`
				)
				.textContent();
			expect( String( selectedLabel ) ).toContain(
				RESERVATION_PAGE_TITLE
			);
			expect( String( selectedLabel ).trim() ).not.toBe(
				RESERVATION_PAGE_TITLE
			);
			await expect( page.locator( FIELD_WARNING ) ).toBeVisible();
			await expect( page.locator( FIELD_WARNING ) ).toContainText(
				RESERVATION_PAGE_TITLE
			);
			await expect( page.locator( TOP_NOTICE ) ).toHaveCount( 0 );
			await expect( page.locator( PAGE_INFO ) ).toHaveCount( 0 );

			// @step ダッシュボードを開く
			await page.goto( '/wp-admin/' );
			// @check 画面上部に「予約ページを確認してください」の通知が1件だけ出る
			await expect( page.locator( TOP_NOTICE ) ).toHaveCount( 1 );
			await expect( page.locator( TOP_NOTICE ) ).toContainText(
				RESERVATION_PAGE_TITLE
			);

			// @step 「予約に進む」ボタンを置いた固定ページを開く
			await page.goto( buttonPage.permalink );
			// @check 予約ボタンは表示されない（残っている URL へは移動させない）
			await expect( page.locator( RESERVATION_BUTTON_LINK ) ).toHaveCount(
				0
			);
			await expect(
				page.locator( `a[href^="${ reservationPage.permalink }"]` )
			).toHaveCount( 0 );
		} finally {
			// @step ゴミ箱から固定ページを戻す（WordPress 5.6 以降、戻したページは下書きになる）
			wpEvalPhp( `wp_untrash_post( ${ reservationPage.id } );` );
		}

		try {
			// @check 戻した固定ページは下書きになっている（公開し直すまで予約ページとしては使えない）
			expect(
				wpCliArgs( [
					'post',
					'get',
					String( reservationPage.id ),
					'--field=post_status',
				] )
			).toBe( 'draft' );

			// @step もう一度 BM 設定の「システム」タブを開く
			await page.goto( SETTINGS_URL );
			// @check そのページが選ばれたまま「下書き」などの印付きで残り、まだ公開されていないので警告も残る（URL の行は出ない）
			await expect( page.locator( PAGE_SELECT ) ).toHaveValue(
				String( reservationPage.id )
			);
			const restoredLabel = await page
				.locator(
					`${ PAGE_SELECT } option[value="${ reservationPage.id }"]`
				)
				.textContent();
			expect( String( restoredLabel ).trim() ).not.toBe(
				RESERVATION_PAGE_TITLE
			);
			await expect( page.locator( FIELD_WARNING ) ).toBeVisible();
			await expect( page.locator( FIELD_WARNING ) ).toContainText(
				RESERVATION_PAGE_TITLE
			);
			await expect( page.locator( PAGE_INFO ) ).toHaveCount( 0 );
		} finally {
			// @step 固定ページを公開する
			wpCliArgs( [
				'post',
				'update',
				String( reservationPage.id ),
				'--post_status=publish',
			] );
		}

		// @step もう一度 BM 設定の「システム」タブを開く
		await page.goto( SETTINGS_URL );
		// @check 警告が消え、そのページが選ばれたまま URL の行が出る
		await expect( page.locator( FIELD_WARNING ) ).toHaveCount( 0 );
		await expect( page.locator( PAGE_SELECT ) ).toHaveValue(
			String( reservationPage.id )
		);
		await expect( page.locator( PAGE_INFO ) ).toBeVisible();

		// @step ダッシュボードを開く
		await page.goto( '/wp-admin/' );
		// @check 画面上部の通知も消えている
		await expect( page.locator( TOP_NOTICE ) ).toHaveCount( 0 );
	} );
} );
