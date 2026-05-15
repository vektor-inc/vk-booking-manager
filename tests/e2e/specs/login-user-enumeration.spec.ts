import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * PR #206 / issue #194: ログイン失敗メッセージ統一によるユーザー列挙対策
 * Login error unification to mitigate user enumeration (PR #206 / issue #194)
 *
 * 修正後は invalid_username / invalid_email / incorrect_password が
 * すべて同一の「ユーザー名またはパスワードが正しくありません。」に統一される。
 * After the fix, all three error codes return the unified message.
 */

const TEST_USER_LOGIN = 'enum_test_user';
const TEST_USER_EMAIL = 'enum_test_user@example.com';
const TEST_USER_PASSWORD = 'CorrectPassword123!';
const LOGIN_PAGE_SLUG = 'enum-test-login';

// 元のサイト言語を保存するための変数（afterAll で復元するため）
// Stores the original site locale so afterAll can restore it.
// 既定値は WordPress のデフォルトロケール `en_US`。
// Defaults to WordPress's default locale `en_US`.
let ORIGINAL_LOCALE = 'en_US';

// 統一メッセージ（日本語環境を想定。英語環境でも fallback で検出する）
// Unified message text (Japanese expected; English fallback covered as well)
const UNIFIED_MESSAGE_JA = 'ユーザー名またはパスワードが正しくありません。';
const UNIFIED_MESSAGE_EN = 'Username or password is incorrect.';

// 旧仕様（修正前）に存在していた「存在しないユーザー」固有の文言。
// PR で削除されているため、絶対に表示されてはいけない。
// Legacy "non-existent user" wording removed by this PR – must never appear.
const LEGACY_NON_EXISTENT_JA = '存在しないユーザー';
const LEGACY_NON_EXISTENT_EN = 'non-existent user';

/**
 * `npx wp-env run cli` 経由で WP-CLI コマンドを実行するヘルパー
 * Helper to run WP-CLI through wp-env
 */
function runWpCli( cmd: string, silent = false ): string {
	return execSync( `npx wp-env run cli ${ cmd }`, {
		encoding: 'utf-8',
		stdio: silent ? 'pipe' : 'pipe',
	} ).trim();
}

/**
 * テスト用ログインページ（[vkbm_login_form] ショートコードのみ）を確実に用意する
 * Ensure a dedicated test login page exists that only renders [vkbm_login_form]
 */
function ensureLoginPage(): void {
	const phpCode = `
		$post = get_page_by_path("${ LOGIN_PAGE_SLUG }");
		$content = "[vkbm_login_form]";
		$post_data = array(
			"post_type"    => "page",
			"post_title"   => "Login Enumeration Test",
			"post_name"    => "${ LOGIN_PAGE_SLUG }",
			"post_content" => $content,
			"post_status"  => "publish",
		);
		if ( $post ) {
			$post_data["ID"] = $post->ID;
			wp_update_post( $post_data );
		} else {
			wp_insert_post( $post_data );
		}
	`;
	const flat = phpCode.replace( /\s+/g, ' ' ).trim();
	execSync( `npx wp-env run cli wp eval '${ flat }'`, { stdio: 'pipe' } );
	execSync( 'npx wp-env run cli wp rewrite flush --hard', { stdio: 'pipe' } );
}

/**
 * テスト用ユーザーを確実に用意する（既存なら一度削除して作り直す）
 * Ensure a known test user exists (delete first if present, then re-create)
 */
function ensureTestUser(): void {
	try {
		execSync(
			`npx wp-env run cli wp user delete ${ TEST_USER_LOGIN } --yes`,
			{ stdio: 'pipe' }
		);
	} catch ( _e ) {
		// 存在しなければ無視 / ignore when user does not exist
	}
	execSync(
		`npx wp-env run cli wp user create ${ TEST_USER_LOGIN } ${ TEST_USER_EMAIL } --user_pass=${ TEST_USER_PASSWORD } --role=subscriber --porcelain`,
		{ stdio: 'pipe' }
	);
}

/**
 * 日本語ロケールに切り替える。
 * Switch the site language to Japanese (required for the translation check).
 */
function ensureJapaneseLocale(): void {
	try {
		execSync( 'npx wp-env run cli wp language core install ja', {
			stdio: 'pipe',
		} );
	} catch ( _e ) {
		// already installed
	}
	execSync( 'npx wp-env run cli wp site switch-language ja', {
		stdio: 'pipe',
	} );
}

/**
 * `vkbm_rl_*` トランジェントを全削除してログイン試行カウンタをリセットする。
 * Flush all `vkbm_rl_*` transients so the login attempt counter is reset.
 */
function flushRateLimitTransients(): void {
	try {
		execSync(
			`npx wp-env run cli wp eval 'global $wpdb; $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \\'_transient_vkbm_rl_%\\'"); foreach ($rows as $k) { delete_transient(str_replace("_transient_", "", $k)); }'`,
			{ stdio: 'pipe' }
		);
	} catch ( _e ) {
		// ignore
	}
}

/**
 * `vkbm_provider_settings` の `auth_rate_limit_enabled` を 0 にしてレート制限機能自体を無効化する。
 * このプラグインのデフォルトは ON のため、明示的に OFF にしないと連続テストでロックされる。
 * Disable the rate limit feature itself by setting `auth_rate_limit_enabled` to 0
 * in `vkbm_provider_settings`. The plugin ships with this ON by default, which
 * locks consecutive test attempts unless explicitly turned off.
 */
function disableRateLimitSetting(): void {
	try {
		// 既存の vkbm_provider_settings を取得し、マージして JSON で書き戻す
		// Read current settings, merge with the rate-limit-disabling values, and write back.
		let current: any = {};
		try {
			const raw = execSync(
				'npx wp-env run cli wp option get vkbm_provider_settings --format=json',
				{ encoding: 'utf-8', stdio: 'pipe' }
			).trim();
			const parsed = JSON.parse( raw );
			if (
				typeof parsed === 'object' &&
				parsed !== null &&
				! Array.isArray( parsed )
			) {
				current = parsed;
			}
		} catch ( _e ) {
			current = {};
		}

		const merged = {
			...current,
			auth_rate_limit_enabled: 0,
			registration_rate_limit_enabled: 0,
		};

		// シェル経由の JSON エスケープ問題を避けるため base64 を経由する
		// Use base64 to safely pass JSON through the shell.
		const b64 = Buffer.from( JSON.stringify( merged ) ).toString(
			'base64'
		);
		execSync(
			`npx wp-env run cli bash -c "echo '${ b64 }' | base64 -d | wp option update vkbm_provider_settings --format=json"`,
			{ stdio: 'pipe' }
		);
	} catch ( _e ) {
		// ignore
	}
}

/**
 * ログインフォームに値を入れて送信し、エラーアラートの本文を返す。
 * Fill the login form, submit, and return the rendered error message text.
 */
async function submitLoginAndGetError(
	page: Page,
	usernameOrEmail: string,
	password: string
): Promise< string > {
	await page.goto( `/${ LOGIN_PAGE_SLUG }/` );

	// レンダリング待ち / wait for the login form to be ready
	await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );

	await page.locator( '#vkbm-login-username' ).fill( usernameOrEmail );
	await page.locator( '#vkbm-login-password' ).fill( password );

	// 送信ボタンをクリック / submit the form
	await page
		.locator( '.vkbm-auth-card--login .vkbm-auth-button[type="submit"]' )
		.click();

	// リダイレクト後の表示を待つ / wait for redirect-back with error
	await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );

	const alert = page.locator(
		'.vkbm-auth-card--login .vkbm-alert__danger'
	);
	await expect( alert ).toBeVisible( { timeout: 10000 } );

	const text = ( await alert.textContent() ) || '';
	return text.replace( /\s+/g, ' ' ).trim();
}

test.describe( 'PR #206 ログイン失敗メッセージ統一（ユーザー列挙対策）', () => {
	test.beforeAll( () => {
		// 元の言語を保存しておき afterAll で復元する（後続スペックへの副作用回避）
		// Save the current locale so we can restore it in afterAll to avoid
		// leaking the `ja` switch into subsequent specs.
		try {
			const currentLocale = runWpCli( 'wp option get WPLANG', true );
			// 空文字は en_US 相当（WPLANG 未設定）
			// Empty string means default (en_US).
			ORIGINAL_LOCALE = currentLocale || 'en_US';
		} catch ( _e ) {
			ORIGINAL_LOCALE = 'en_US';
		}

		ensureJapaneseLocale();
		ensureLoginPage();
		ensureTestUser();
		// レート制限機能自体を無効化（1 回だけで OK）
		// Disable the rate limit feature itself (only needed once)
		disableRateLimitSetting();
		flushRateLimitTransients();
	} );

	test.beforeEach( () => {
		// 各テスト前に試行回数カウンタ（transient）を毎回クリア
		// Clear the attempt counter transients before each test
		flushRateLimitTransients();
	} );

	test.afterAll( () => {
		// 元の言語に戻す（このスペックが他テストの環境を汚染しないようにするため）
		// Restore the original locale to avoid polluting other specs.
		try {
			execSync(
				`npx wp-env run cli wp site switch-language ${ ORIGINAL_LOCALE }`,
				{ stdio: 'pipe' }
			);
		} catch ( _e ) {
			// ignore
		}

		try {
			execSync(
				`npx wp-env run cli wp user delete ${ TEST_USER_LOGIN } --yes`,
				{ stdio: 'pipe' }
			);
		} catch ( _e ) {
			// ignore
		}
	} );

	test( '存在しないユーザー名 + 任意のパスワード → 統一メッセージが表示される', async ( {
		page,
	} ) => {
		const msg = await submitLoginAndGetError(
			page,
			'nonexistent_user_xyz',
			'whatever_pass_123'
		);

		// 統一メッセージのいずれか（日本語 or 英語）が含まれる
		expect( msg ).toMatch(
			new RegExp( `${ UNIFIED_MESSAGE_JA }|${ UNIFIED_MESSAGE_EN }` )
		);
		// 旧仕様の「存在しないユーザー」文言は含まれない
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_JA );
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_EN );
		// 入力ユーザー名がメッセージ内に echo back されていない（列挙対策）
		expect( msg ).not.toContain( 'nonexistent_user_xyz' );
	} );

	test( '存在するユーザー名 + 誤ったパスワード → 同じ統一メッセージが表示される', async ( {
		page,
	} ) => {
		const msg = await submitLoginAndGetError(
			page,
			TEST_USER_LOGIN,
			'wrong_password_xxx'
		);

		expect( msg ).toMatch(
			new RegExp( `${ UNIFIED_MESSAGE_JA }|${ UNIFIED_MESSAGE_EN }` )
		);
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_JA );
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_EN );
	} );

	test( '存在しないメールアドレス + 任意のパスワード → 同じ統一メッセージ（invalid_email も統一）', async ( {
		page,
	} ) => {
		const msg = await submitLoginAndGetError(
			page,
			'never_existed_xyz@example.com',
			'whatever_pass_123'
		);

		expect( msg ).toMatch(
			new RegExp( `${ UNIFIED_MESSAGE_JA }|${ UNIFIED_MESSAGE_EN }` )
		);
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_JA );
		expect( msg ).not.toContain( LEGACY_NON_EXISTENT_EN );
		// メールアドレスもメッセージに echo back されていないこと
		expect( msg ).not.toContain( 'never_existed_xyz@example.com' );
	} );

	test( '3 パターンのエラーメッセージが完全一致する（差分なし＝列挙不可）', async ( {
		page,
	} ) => {
		const invalidUsernameMsg = await submitLoginAndGetError(
			page,
			'nonexistent_user_xyz',
			'whatever_pass_123'
		);
		const wrongPasswordMsg = await submitLoginAndGetError(
			page,
			TEST_USER_LOGIN,
			'wrong_password_xxx'
		);
		const invalidEmailMsg = await submitLoginAndGetError(
			page,
			'never_existed_xyz@example.com',
			'whatever_pass_123'
		);

		// 3 つの応答メッセージが完全一致することで、攻撃者が応答差分から
		// ユーザー存在有無を推測できない（=列挙不可）
		expect( wrongPasswordMsg ).toBe( invalidUsernameMsg );
		expect( invalidEmailMsg ).toBe( invalidUsernameMsg );
	} );

	test( '日本語環境では翻訳された日本語メッセージが表示される', async ( {
		page,
	} ) => {
		const msg = await submitLoginAndGetError(
			page,
			'nonexistent_user_xyz',
			'whatever_pass_123'
		);

		// 日本語 PO に翻訳が追加されているはず
		expect( msg ).toContain( UNIFIED_MESSAGE_JA );
	} );

	test( '正しい認証情報ではログイン成功する（デグレなし）', async ( {
		page,
	} ) => {
		await page.goto( `/${ LOGIN_PAGE_SLUG }/` );
		await page.waitForSelector( '.vkbm-auth-card--login', {
			timeout: 15000,
		} );

		await page.locator( '#vkbm-login-username' ).fill( TEST_USER_LOGIN );
		await page.locator( '#vkbm-login-password' ).fill( TEST_USER_PASSWORD );

		await page
			.locator(
				'.vkbm-auth-card--login .vkbm-auth-button[type="submit"]'
			)
			.click();

		// ログイン成功時はリダイレクト後にエラーアラートが出ない
		// On success, no error alert should appear.
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

		const errorAlert = page.locator(
			'.vkbm-auth-card--login .vkbm-alert__danger'
		);
		await expect( errorAlert ).toHaveCount( 0 );

		// ログイン状態は body の logged-in クラスで確認する
		// Verify logged-in state via body class
		const bodyClass = await page
			.locator( 'body' )
			.getAttribute( 'class' );
		expect( bodyClass ).toContain( 'logged-in' );
	} );
} );
