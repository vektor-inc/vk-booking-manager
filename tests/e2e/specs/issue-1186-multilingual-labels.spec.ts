/**
 * issue #1186: BM 設定に「多言語設定」タブを追加し、言語ごとに登録した呼び方を、
 * 表示しているページの言語（Bogo の英語ページなど）に合わせて予約画面に出し分ける変更の検証。
 *
 * 確かめること（PR #1189 の「確認手順」1〜9 と「言語を追加していないサイト」）:
 * - 多言語設定タブ: 言語が無いときの文言、言語を選ぶまで「+ 言語を追加」が押せないこと、
 *   追加後のフォーカスと薄い文字（プレースホルダー）、保存後の値、別のタブで保存しても値が消えないこと
 * - 予約画面: 英語版のページでは登録した呼び方、日本語版のページでは基本の値が出ること。
 *   英語版のページから送る予約画面の通信（/vkbm/v1/）にページの言語（vkbm_locale）が付くこと。
 *   言語ごとの値を空欄にすると、英語版のページでも基本の値に戻ること
 * - 削除: 入力がある言語は確認ダイアログを出し、保存すると一覧から消えて、選択欄の候補に戻ること
 * - 言語を追加していないサイト: 英語版のページでも基本の値のまま変わらないこと
 *
 * 実行環境: playwright.config の baseURL（テスト用 wp-env、サイトの言語は日本語）に対して実行する。
 * ページを言語ごとに作るため、WordPress.org から Bogo（多言語化プラグイン）を入れて有効化し、
 * 終了後に停止する（他の spec に影響させないため）。
 */
// @category 管理画面
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getServiceMenuId,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// BM 設定の多言語設定タブ・表記設定タブの URL（baseURL に対する相対パス）。
const MULTILINGUAL_TAB_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=multilingual';
const NOTATION_TAB_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=notation';

// 日本語の予約ページ（global-setup が作る）と、Bogo で作る英語版の予約ページ。
const JA_BOOKING_URL = '/booking/';
const EN_BOOKING_URL = '/en/booking/';

// 言語ごとの呼び方を保存する option 名。
const MULTILINGUAL_OPTION = 'vkbm_multilingual_labels';

// 英語版のページを作ったときの投稿 ID（後片付けで消す）。
let enPageId = 0;

/**
 * 言語ごとの呼び方の option を消し、「言語を追加していない」状態に戻す。
 */
function resetMultilingualLabels(): void {
	try {
		wpCliArgs( [ 'option', 'delete', MULTILINGUAL_OPTION ], {
			stdio: 'pipe',
		} );
	} catch ( e ) {
		// option が無いとき（初回）は WP-CLI がエラー終了するが、消えている状態なので問題ない。
	}
}

/**
 * 言語ごとの呼び方の option を JSON で読む。無ければ空のオブジェクトを返す。
 *
 * @return ロケール => （キー => 値）。
 */
function readMultilingualLabels(): Record< string, Record< string, string > > {
	try {
		const out = wpCliArgs(
			[ 'option', 'get', MULTILINGUAL_OPTION, '--format=json' ],
			{ stdio: 'pipe' }
		);
		const parsed = JSON.parse( out );
		return parsed && typeof parsed === 'object' ? parsed : {};
	} catch ( e ) {
		return {};
	}
}

/**
 * 予約ページを開き、global-setup の「Service Menu 1」を選んでカレンダーを開くところまで進める。
 *
 * @param page Playwright の Page。
 * @param url  開く予約ページの相対パス。
 */
async function openReservationForServiceMenu(
	page: Page,
	url: string
): Promise< void > {
	await page.goto( url );

	// 他のテストが残したメニューを巻き込まないよう、data-menu-id で「Service Menu 1」の予約ボタンを指定する。
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
 * 多言語設定タブで「変更を保存」を押し、保存後の画面が出るまで待つ。
 *
 * @param page Playwright の Page。
 */
async function saveSettings( page: Page ): Promise< void > {
	await Promise.all( [
		page.waitForURL( /settings-updated=/, { timeout: 30000 } ),
		page.locator( '#submit' ).click(),
	] );
}

test.describe
	.serial( 'issue #1186: 多言語設定タブで言語ごとの呼び方を登録し、ページの言語に合わせて表示する', () => {
	test.beforeAll( async () => {
		// @step Bogo（多言語化プラグイン）を入れて有効にする
		try {
			wpCliArgs( [ 'plugin', 'is-installed', 'bogo' ], {
				stdio: 'pipe',
			} );
		} catch ( e ) {
			wpCliArgs( [ 'plugin', 'install', 'bogo' ], { stdio: 'pipe' } );
		}
		wpCliArgs( [ 'plugin', 'activate', 'bogo' ], { stdio: 'pipe' } );

		// @step 日本語の予約ページの英語版を Bogo で作り、公開する（英語版にも予約ブロックが入る）
		// 前回の実行で残った英語版があれば消してから作り直す（何度実行しても同じ状態にするため）。
		const out = wpEvalPhp( `
			$ja = get_page_by_path( 'booking', OBJECT, 'page' );
			if ( ! $ja ) { echo 'NO_JA_PAGE'; return; }
			$old = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_locale',
				'meta_value'     => 'en_US',
			) );
			foreach ( $old as $old_id ) { wp_delete_post( $old_id, true ); }
			$id = bogo_duplicate_post( $ja, 'en_US' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_name' => 'booking' ) );
			echo (int) $id;
		` );
		enPageId = Number.parseInt( out.split( /\s+/ ).pop() || '0', 10 );
		expect( enPageId ).toBeGreaterThan( 0 );

		// @step 「BM 設定 > 表記設定」で「サービスメニューの呼び方」に「プラン」を入れて保存しておく
		await configureProviderSettings( {
			menu_label: 'プラン',
			menu_label_plural: '',
		} );

		// 言語を何も追加していない状態から始める。
		resetMultilingualLabels();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test.afterAll( async () => {
		// 他の spec へ持ち越さないよう、英語版のページ・言語ごとの値・呼び方を戻し、Bogo を止める。
		if ( enPageId > 0 ) {
			wpCliArgs( [ 'post', 'delete', String( enPageId ), '--force' ], {
				stdio: 'pipe',
			} );
		}
		resetMultilingualLabels();
		await configureProviderSettings( {
			menu_label: '',
			menu_label_plural: '',
		} );
		wpCliArgs( [ 'plugin', 'deactivate', 'bogo' ], { stdio: 'pipe' } );
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test( '言語を追加していないサイトでは、英語版のページでも呼び方は基本の値のまま変わらない', async ( {
		page,
	} ) => {
		// 予約画面はログインしていないお客様の状態で確かめる。
		await page.context().clearCookies();

		// @step 多言語設定タブで言語を何も追加していない状態で、英語版の予約ページを開き、「Service Menu 1」の予約ボタンを押す
		await openReservationForServiceMenu( page, EN_BOOKING_URL );

		// @check 予約画面のメニューの呼び方は、基本の値の「プラン」で表示される
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toContainText( 'プラン' );
	} );

	test( '多言語設定タブで英語を追加し、呼び方を保存できる（別のタブで保存しても消えない）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step 「BM 設定 > 多言語設定」を開く
		await page.goto( MULTILINGUAL_TAB_URL );

		// @check 「まだ言語が追加されていません。」と表示され、「+ 言語を追加」は押せない
		const empty = page.locator( '.vkbm-multilingual__empty' );
		await expect( empty ).toBeVisible();
		await expect( empty ).toHaveText( 'まだ言語が追加されていません。' );
		const addButton = page.locator( '#vkbm-multilingual-add' );
		await expect( addButton ).toBeDisabled();

		// @step 「言語の追加」で「English (United States)」を選ぶ
		await page
			.locator( '#vkbm-multilingual-add-locale' )
			.selectOption( 'en_US' );

		// @check 「+ 言語を追加」が押せるようになる
		await expect( addButton ).toBeEnabled();

		// @step 「+ 言語を追加」を押す
		await addButton.click();

		// @check 「English (United States)（en_US）」の見出しと入力欄が出て、最初の入力欄にカーソルが移る
		const section = page.locator(
			'.vkbm-multilingual__language[data-locale="en_US"]'
		);
		await expect( section ).toBeVisible();
		await expect(
			section.locator( '.vkbm-multilingual__language-title' )
		).toHaveText( 'English (United States)（en_US）' );
		await expect( section.locator( 'input' ).first() ).toBeFocused();
		await expect( empty ).toBeHidden();

		// @check 「サービスメニューの呼び方」の薄い文字は「プラン」になっている
		const menuLabelInput = page.locator(
			'#vkbm-multilingual-en_US-menu_label'
		);
		await expect( menuLabelInput ).toHaveAttribute(
			'placeholder',
			'プラン'
		);

		// @step 「サービスメニューの呼び方」に「Plan」と入力して「変更を保存」を押す
		await menuLabelInput.fill( 'Plan' );
		await saveSettings( page );

		// @check 保存後も「Plan」が残っている
		await expect(
			page.locator( '#vkbm-multilingual-en_US-menu_label' )
		).toHaveValue( 'Plan' );
		expect( readMultilingualLabels().en_US?.menu_label ).toBe( 'Plan' );

		// @step 「表記設定」タブで「所要時間ラベル」を変えて「変更を保存」を押し、「多言語設定」タブに戻る
		await page.goto( NOTATION_TAB_URL );
		// 表記設定タブの冒頭には、多言語設定タブへのリンク付きの案内が出る。
		await expect(
			page.locator( '.vkbm-provider-settings__tab-lead a' )
		).toHaveAttribute( 'href', /tab=multilingual/ );
		const durationInput = page.locator(
			'input[name="vkbm_provider_settings[duration_label]"]'
		);
		const originalDuration = await durationInput.inputValue();
		await durationInput.fill( '所要時間e2e' );
		await saveSettings( page );
		await page.goto( MULTILINGUAL_TAB_URL );

		// @check 多言語設定タブの「Plan」が消えずに残っている
		await expect(
			page.locator( '#vkbm-multilingual-en_US-menu_label' )
		).toHaveValue( 'Plan' );

		// 表記設定タブで変えた「所要時間ラベル」を元に戻しておく。
		await configureProviderSettings( { duration_label: originalDuration } );
	} );

	test( '英語版のページでは登録した呼び方、日本語版のページでは基本の値が表示される', async ( {
		page,
	} ) => {
		// 予約画面はログインしていないお客様の状態で確かめる。
		await page.context().clearCookies();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		// 英語版のページから送る予約画面の通信（このプラグインの REST）の URL を集める。
		const restUrls: string[] = [];
		page.on( 'request', ( req ) => {
			const url = decodeURIComponent( req.url() );
			if ( url.includes( 'vkbm/v1/' ) ) {
				restUrls.push( url );
			}
		} );

		// @step 英語版の予約ページを開き、「Service Menu 1」の予約ボタンを押す
		await openReservationForServiceMenu( page, EN_BOOKING_URL );

		// @check 予約画面のメニューの呼び方が「Plan」で表示され、「プラン」は出ない
		const enSummary = page.locator( '.vkbm-plan-summary' ).first();
		await expect( enSummary ).toContainText( 'Plan' );
		await expect( enSummary ).not.toContainText( 'プラン' );

		// 予約画面の通信には、ページの言語（en_US）が付いている。
		expect( restUrls.length ).toBeGreaterThan( 0 );
		expect(
			restUrls.every( ( url ) => url.includes( 'vkbm_locale=en_US' ) )
		).toBe( true );

		// @step 日本語版の予約ページを開き、「Service Menu 1」の予約ボタンを押す
		await openReservationForServiceMenu( page, JA_BOOKING_URL );

		// @check 今までどおり「プラン」で表示され、「Plan」は出ない
		const jaSummary = page.locator( '.vkbm-plan-summary' ).first();
		await expect( jaSummary ).toContainText( 'プラン' );
		await expect( jaSummary ).not.toContainText( 'Plan' );
	} );

	test( '言語ごとの値を空欄で保存すると、英語版のページでも基本の値に戻る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step 「多言語設定」タブで「サービスメニューの呼び方」の「Plan」を消し、空欄で「変更を保存」を押す
		await page.goto( MULTILINGUAL_TAB_URL );
		await page.locator( '#vkbm-multilingual-en_US-menu_label' ).fill( '' );
		await saveSettings( page );
		await expect(
			page.locator( '#vkbm-multilingual-en_US-menu_label' )
		).toHaveValue( '' );

		// @step ログアウトした状態で英語版の予約ページを開き直し、「Service Menu 1」の予約ボタンを押す
		await page.context().clearCookies();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
		await openReservationForServiceMenu( page, EN_BOOKING_URL );

		// @check 基本の値の「プラン」で表示される
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toContainText( 'プラン' );
	} );

	test( '入力がある言語を削除すると確認ダイアログが出て、保存すると一覧から消え、候補に戻る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// 入力がある状態で削除を確かめるため、「Plan」を入れて保存しておく。
		await page.goto( MULTILINGUAL_TAB_URL );
		await page
			.locator( '#vkbm-multilingual-en_US-menu_label' )
			.fill( 'Plan' );
		await saveSettings( page );

		// 追加済みの英語は、選択欄の候補に出ない。
		const select = page.locator( '#vkbm-multilingual-add-locale' );
		await expect( select.locator( 'option[value="en_US"]' ) ).toHaveCount(
			0
		);

		// @step 「多言語設定」タブで English の「削除」を押す
		const section = page.locator(
			'.vkbm-multilingual__language[data-locale="en_US"]'
		);
		await section.locator( '.vkbm-multilingual__remove' ).click();

		// @check 入力があるため、確認ダイアログが出る
		const dialog = page.locator( '.vkbm-guard-dialog' );
		await expect( dialog ).toBeVisible();

		// @step ダイアログの「削除する」を押し、「変更を保存」を押す
		await dialog.getByRole( 'button', { name: '削除する' } ).click();
		await expect( section ).toHaveCount( 0 );
		await saveSettings( page );

		// @check 言語が一覧から消え、「まだ言語が追加されていません。」と出て、プルダウンの候補に English が戻る
		await expect(
			page.locator( '.vkbm-multilingual__language[data-locale="en_US"]' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-multilingual__empty' )
		).toBeVisible();
		await expect(
			page.locator(
				'#vkbm-multilingual-add-locale option[value="en_US"]'
			)
		).toHaveCount( 1 );
		expect( readMultilingualLabels().en_US ).toBeUndefined();
	} );
} );
