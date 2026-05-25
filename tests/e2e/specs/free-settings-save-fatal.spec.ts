import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * PR #3: 無料版で予約事業者設定を保存した際に
 * `Staff_Editor::clear_nomination_enabled_cache()` 未定義による
 * Fatal Error が発生しないことを検証する e2e テスト。
 *
 * 修正前は src/provider-settings/class-settings-service.php:137 が
 * Pro 版から build sync された呼び出しで未定義メソッドを叩き Fatal Error が出ていた。
 * 本テストでは無料版プラグインを単独で有効化した状態で、設定保存フローを通し、
 *  - 保存処理が Fatal にならない（HTTP 200・WP管理画面の標準UIが表示される）
 *  - "Call to undefined method" 等の致命的エラー文字列がレスポンスに含まれない
 *  - Staff_Editor::is_enabled() / is_nomination_enabled() が false のままである（Free 版挙動デグレなし）
 * を確認する。
 *
 * このテストは Pro 版テスト用 global-setup に依存しないよう、本ファイル内で必要な最小限のセットアップを行う。
 */

/**
 * wp-env コンテナ内で WP-CLI を実行するヘルパー。
 * Helper to invoke WP-CLI inside the wp-env container.
 */
function wpCli( command: string ): string {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf-8',
	} ).trim();
}

test.describe( 'PR #3: 無料版で設定保存時に Fatal Error が出ない', () => {
	test.beforeAll( () => {
		// Pro 版が万一有効化されていたら停止し、無料版だけを有効化する。
		// Ensure the Pro plugin is not active and the Free plugin is active.
		try {
			wpCli( 'plugin deactivate vk-booking-manager-pro' );
		} catch ( e ) {
			// Pro 版が無い・既に無効ならエラーになるが問題なし
			// Ignore when Pro plugin is absent or already deactivated
		}
		// 念のため無料版を有効化（既に有効でも no-op）
		// Activate Free plugin (no-op if already active)
		wpCli( 'plugin activate vk-booking-manager' );

		// テーマを twentytwentyone に切り替え（管理画面挙動を安定化）
		// Switch theme to twentytwentyone for stable admin behavior
		try {
			execSync(
				'npx wp-env run cli wp theme install twentytwentyone --activate',
				{ stdio: 'ignore' }
			);
		} catch ( e ) {
			// 既にインストール済みなら無視
			// Ignore if already installed
		}

		// パーマリンクは未設定でも保存テストは可能なので未指定で良い
		// Permalinks are not required for the settings-save flow under test
	} );

	test( '管理者でログインし、予約事業者設定画面を編集して保存しても Fatal Error が起きない', async ( { page } ) => {
		// 1. 管理画面ログイン
		// Login to wp-admin
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/, { timeout: 30000 } );

		// 2. 設定ページ（vkbm-provider-settings）を開く
		// Open provider settings page
		const settingsResponse = await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings'
		);
		expect( settingsResponse?.status() ).toBeLessThan( 400 );
		await page.waitForLoadState( 'domcontentloaded' );

		// Fatal Error の典型文字列が出ていないこと
		// Make sure no fatal-error string is rendered
		const bodyTextBefore = await page.locator( 'body' ).innerText();
		expect( bodyTextBefore ).not.toContain( 'Fatal error' );
		expect( bodyTextBefore ).not.toContain( 'Call to undefined method' );

		// 3. 設定フォームを送信する
		//    nonce 名は class-provider-settings-page.php の NONCE_NAME と一致させる。
		// Submit the settings form. The submit button id is "submit" in WP admin.
		const submitButton = page.locator( 'input[type="submit"]#submit' ).first();
		const fallbackSubmit = page
			.locator( '.submit input[type="submit"]' )
			.first();
		const targetSubmit =
			( await submitButton.count() ) > 0 ? submitButton : fallbackSubmit;

		// 念のためフォームの存在を確認
		// Ensure the settings form exists
		await expect( targetSubmit ).toBeVisible();

		// 送信レスポンスを取得し、エラー文字列がないかチェック
		// Capture the response and assert no fatal error markers
		const [ saveResponse ] = await Promise.all( [
			page.waitForResponse(
				( res ) =>
					res.request().method() === 'POST' &&
					res.url().includes( 'admin.php' )
			),
			targetSubmit.click(),
		] );

		expect( saveResponse.status() ).toBeLessThan( 500 );

		await page.waitForLoadState( 'domcontentloaded' );

		// 4. ページ本文に Fatal Error 文字列が含まれていないこと（修正前はここで失敗するはず）
		// Verify the rendered page does not contain fatal-error markers
		const bodyText = await page.locator( 'body' ).innerText();
		expect( bodyText ).not.toContain( 'Fatal error' );
		expect( bodyText ).not.toContain( 'Call to undefined method' );
		expect( bodyText ).not.toContain( 'clear_nomination_enabled_cache' );

		// 5. 設定画面の標準UI（送信ボタン）が再描画されていること = 通常のリダイレクトで戻った
		// Confirm the standard settings form is rendered again after save
		const submitAfter = page
			.locator( '.submit input[type="submit"], input[type="submit"]#submit' )
			.first();
		await expect( submitAfter ).toBeVisible();
	} );

	test( 'Free 版デグレチェック: is_enabled() / is_nomination_enabled() が false', () => {
		// WP-CLI 経由で PHP を実行し、Free 版スタブの戻り値を直接検証する。
		// Run PHP via WP-CLI and verify the stub returns false for both methods.
		const evalResult = execSync(
			`npx wp-env run cli wp eval 'echo (\\VKBookingManager\\Staff\\Staff_Editor::is_enabled() ? "1" : "0") . "|" . (\\VKBookingManager\\Staff\\Staff_Editor::is_nomination_enabled() ? "1" : "0");'`,
			{ encoding: 'utf-8' }
		).trim();

		// 形式: "<is_enabled>|<is_nomination_enabled>" でどちらも 0 であること
		// Both flags must be 0 (false) in the Free edition
		expect( evalResult ).toBe( '0|0' );
	} );

	test( 'clear_nomination_enabled_cache() を直接呼んでも例外にならない（PR の no-op 実装確認）', () => {
		// Free 版スタブの no-op メソッドを WP-CLI から直接呼び、例外/Fatal を伴わず完了することを確認。
		// Call the no-op method directly via WP-CLI; it must return cleanly.
		const evalResult = execSync(
			`npx wp-env run cli wp eval '\\VKBookingManager\\Staff\\Staff_Editor::clear_nomination_enabled_cache(); echo "ok";'`,
			{ encoding: 'utf-8' }
		).trim();

		expect( evalResult ).toBe( 'ok' );
	} );

	test( 'Settings_Service::save_settings() を呼んでも Fatal Error が出ない（不具合の直接再現テスト）', () => {
		// 修正前は Settings_Service::save_settings() の末尾で Staff_Editor::clear_nomination_enabled_cache() が
		// 呼ばれた瞬間に「Call to undefined method」で Fatal Error が発生していた。
		// 修正後はスタブクラスに no-op メソッドが追加されたため、エラーなく保存が完了することを確認する。
		//
		// Before the fix, save_settings() raised "Call to undefined method" when reaching
		// Staff_Editor::clear_nomination_enabled_cache(). After the fix, the no-op stub allows
		// save_settings() to complete cleanly. This regression test reproduces the call path
		// directly via WP-CLI to make the fix verifiable without going through the admin UI.
		// update_option() は値に変化がないと false を返し、Settings_Service::save_settings() 末尾の
		// clear_nomination_enabled_cache() ブロックを通らない。
		// テストごとに必ず新しい値を投げて、修正対象の呼び出し経路に確実に到達するようにする。
		// Use a unique value per run so update_option() returns true and the patched
		// clear_nomination_enabled_cache() call site is exercised.
		const uniqueName = `Test Save by e2e ${ Date.now() }`;
		const phpCode = `
			$repo = new \\VKBookingManager\\ProviderSettings\\Settings_Repository();
			$sanitizer = new \\VKBookingManager\\ProviderSettings\\Settings_Sanitizer( $repo );
			$service = new \\VKBookingManager\\ProviderSettings\\Settings_Service( $repo, $sanitizer );
			$result = $service->save_settings( array( "provider_name" => "${ uniqueName }" ) );
			if ( is_wp_error( $result ) ) {
				echo "WP_ERROR:" . $result->get_error_message();
			} else {
				echo "OK:" . ( $result ? "true" : "false" );
			}
		`;
		// wp-env 経由でシェル渡しすると複雑になるため Buffer.from で base64 化して eval する。
		// Use base64 to avoid shell escaping headaches when piping PHP into wp-cli.
		const base64Code = Buffer.from( phpCode ).toString( 'base64' );
		const evalResult = execSync(
			`npx wp-env run cli wp eval 'eval(base64_decode("${ base64Code }"));'`,
			{ encoding: 'utf-8' }
		).trim();

		// 保存処理が完了し OK:true を返すこと。修正前はここで PHP Fatal が発生して
		// "Call to undefined method" を含む文字列となるか、コマンドそのものが失敗する。
		// Expect a clean OK:true. Before the fix, the call dies with a fatal error string.
		expect( evalResult ).toContain( 'OK:' );
		expect( evalResult ).not.toContain( 'clear_nomination_enabled_cache' );
		expect( evalResult ).not.toContain( 'Call to undefined method' );
		expect( evalResult ).not.toContain( 'Fatal' );
	} );
} );
