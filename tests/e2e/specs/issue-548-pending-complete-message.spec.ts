import { test, expect, Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp, loginAsAdmin } from '../utils/helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// 設定オプション名（Settings_Repository::OPTION_KEY）。
const OPTION_NAME = 'vkbm_provider_settings';

/**
 * Issue #548: 仮予約の完了画面に「見出し＋案内文」を表示する。
 *
 * 検証内容:
 * - 「仮予約にする」＋案内文を設定して予約すると、完了画面に見出し「仮予約を受け付けました」と
 *   設定した案内文（改行を保持）が表示され、完了ブロックにフォーカスが移る。
 * - 案内文が空欄なら標準の案内文が表示される。
 * - 即時確定の場合は従来どおり「予約が完了しました。」のみ（案内文なし）。
 * - 管理画面の案内文入力欄は「仮予約にする」選択時のみ表示される。
 */
test.describe( 'Issue #548: tentative reservation completion message', () => {
	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const username = `user_pending_${ Date.now() }`;

	// 元の設定値（テスト終了時に復元する）。JSON 文字列で保持する。
	let originalSettingsJson = '';

	/**
	 * 設定オプションの一部のキーだけを上書きする（他のキーは維持する）。
	 *
	 * @param overrides 上書きするキーと値。
	 */
	const updateSettings = ( overrides: Record< string, string > ) => {
		// 値は JSON → base64 で PHP へ渡し、PHP リテラル外への脱出を防ぐ。
		const encoded = Buffer.from( JSON.stringify( overrides ) ).toString(
			'base64'
		);
		wpEvalPhp(
			`$o = json_decode( base64_decode( "${ encoded }" ), true );` +
				`$s = get_option( "${ OPTION_NAME }", array() );` +
				'if ( ! is_array( $s ) ) { $s = array(); }' +
				`update_option( "${ OPTION_NAME }", array_merge( $s, $o ) );`
		);
	};

	test.beforeAll( async () => {
		// 元の設定値を控える（未保存の場合は空配列）。
		originalSettingsJson = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ OPTION_NAME }", array() ) );`
		);

		// 予約確定時に一般ユーザーとして予約するためのユーザーを作成する。
		wpCliArgs( [
			'user',
			'create',
			username,
			`${ username }@example.com`,
			'--role=subscriber',
			`--user_pass=${ password }`,
			'--porcelain',
		] );
	} );

	test.afterAll( async () => {
		// テスト中に作った予約投稿とテスト用ユーザーを削除する（予約を先に削除する）。
		wpEvalPhp(
			`$u = get_user_by( "login", "${ username }" );` +
				'if ( $u ) {' +
				'$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => $u->ID, "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );' +
				'foreach ( $ids as $id ) { wp_delete_post( $id, true ); }' +
				'require_once ABSPATH . "wp-admin/includes/user.php";' +
				'wp_delete_user( $u->ID );' +
				'}'
		);

		// 元の設定値へ戻す。
		const encoded = Buffer.from( originalSettingsJson || '[]' ).toString(
			'base64'
		);
		wpEvalPhp(
			`update_option( "${ OPTION_NAME }", json_decode( base64_decode( "${ encoded }" ), true ) );`
		);
	} );

	// 各テストは独自にログインするため、保存済みのログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	/**
	 * wp-login.php 経由で作成済みユーザーとしてログインする。
	 *
	 * @param page Playwright ページ。
	 */
	const login = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
	};

	/**
	 * 予約フォームでメニュー→日付→枠を選び、予約確認画面で同意して予約を確定する。
	 *
	 * @param page Playwright ページ。
	 */
	const reserve = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );

		// メニュー選択（新スタイル→旧スタイルの順でフォールバック）。
		const reserveButton = page.locator(
			'.vkbm-menu-loop__button--reserve'
		);
		if ( await reserveButton.count() ) {
			await reserveButton.first().click();
		} else {
			await page.locator( '.vkbm-service-menu-card' ).first().click();
		}

		// 空き枠ロード完了後にのみ付与される「空きあり」日を選ぶ。
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );
		const availableDays = page.locator(
			'.vkbm-calendar__day--available:not(:disabled)'
		);
		await expect
			.poll( async () => availableDays.count(), { timeout: 15000 } )
			.toBeGreaterThan( 0 );
		await availableDays.first().click();

		// 時間枠を選んで予約確認画面へ進む。
		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect
			.poll( async () => slots.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );
		await slots.first().click();
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible( { timeout: 15000 } );

		// 同意チェック（表示されている場合のみ）。
		const cancelPolicy = page.locator(
			'#vkbm-confirm-cancellation-policy'
		);
		if ( await cancelPolicy.isVisible().catch( () => false ) ) {
			await cancelPolicy.check();
		}
		const terms = page.locator( '#vkbm-confirm-terms' );
		if ( await terms.isVisible().catch( () => false ) ) {
			await terms.check();
		}

		// 予約を確定する。
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();
	};

	test( 'pending reservation shows the heading and the custom guidance text', async ( {
		page,
	} ) => {
		// 仮予約＋案内文（2行）を設定する。
		updateSettings( {
			provider_booking_status_mode: 'pending',
			provider_booking_complete_message_pending:
				'24時間以内にメールでご連絡します。\nしばらくお待ちください。',
		} );

		await login( page );
		await reserve( page );

		const complete = page.locator( '.vkbm-confirm__complete' );
		await expect( complete ).toBeVisible( { timeout: 15000 } );

		// 見出しが表示されること。
		await expect(
			complete.locator( '.vkbm-confirm__complete-title' )
		).toHaveText(
			/仮予約を受け付けました|Your tentative reservation has been received\./
		);

		// 設定した案内文が表示され、改行が保持される（white-space: pre-line）こと。
		const message = complete.locator( '.vkbm-confirm__complete-message' );
		await expect( message ).toContainText(
			'24時間以内にメールでご連絡します。'
		);
		await expect( message ).toContainText( 'しばらくお待ちください。' );
		await expect( message ).toHaveCSS( 'white-space', 'pre-line' );

		// 完了ブロックにフォーカスが移り、二重読み上げを避けるため role="status" は付かないこと。
		await expect( complete ).toBeFocused();
		await expect( complete ).not.toHaveAttribute( 'role', 'status' );
	} );

	test( 'pending reservation with empty guidance text shows the standard text', async ( {
		page,
	} ) => {
		// 仮予約＋案内文は空欄。
		updateSettings( {
			provider_booking_status_mode: 'pending',
			provider_booking_complete_message_pending: '',
		} );

		await login( page );
		await reserve( page );

		const complete = page.locator( '.vkbm-confirm__complete' );
		await expect( complete ).toBeVisible( { timeout: 15000 } );
		await expect(
			complete.locator( '.vkbm-confirm__complete-title' )
		).toHaveText(
			/仮予約を受け付けました|Your tentative reservation has been received\./
		);

		// 標準の案内文が表示されること。
		await expect(
			complete.locator( '.vkbm-confirm__complete-message' )
		).toContainText(
			/確定のメールが届くまで、しばらくお待ちください|Please wait for that email/
		);
	} );

	test( 'instant confirmation shows only the completion message without guidance text', async ( {
		page,
	} ) => {
		// 即時確定。案内文を保存していても表示されないこと。
		updateSettings( {
			provider_booking_status_mode: 'confirmed',
			provider_booking_complete_message_pending: '表示されてはいけない案内文',
		} );

		await login( page );
		await reserve( page );

		const complete = page.locator( '.vkbm-confirm__complete' );
		await expect( complete ).toBeVisible( { timeout: 15000 } );
		await expect(
			complete.locator( '.vkbm-confirm__complete-title' )
		).toHaveText(
			/予約が完了しました。|Your reservation has been completed\./
		);
		await expect(
			complete.locator( '.vkbm-confirm__complete-message' )
		).toHaveCount( 0 );
	} );

	test( 'admin guidance text field is shown only when tentative reservation is selected', async ( {
		browser,
	} ) => {
		// 管理者として設定画面を開く（ログイン済み状態を持たない前提のため新規コンテキストを使う）。
		const context = await browser.newContext( { baseURL: WP_BASE_URL } );
		const page = await context.newPage();
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		const statusSelect = page.locator( '#vkbm-provider-booking-status-mode' );
		const textarea = page.locator(
			'#vkbm-provider-booking-complete-message-pending'
		);

		// 「仮予約にする」を選ぶと表示され、「即時確定」に戻すと非表示になる。
		await statusSelect.selectOption( 'pending' );
		await expect( textarea ).toBeVisible();
		await statusSelect.selectOption( 'confirmed' );
		await expect( textarea ).toBeHidden();

		await context.close();
	} );
} );
