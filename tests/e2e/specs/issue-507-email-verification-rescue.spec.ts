import { test, expect, Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp } from '../utils/helpers';

/**
 * issue #507: メール認証が完了しないままログインできなくなった利用者の救済。
 *
 * 検証観点:
 * - パスワードが正しく未認証の場合だけ、ログイン画面（ショートコード）・予約ブロック内の
 *   どちらでも「認証メールを再送する」ボタンが出る。
 * - パスワードを間違えた場合は出ない（#194 と同じ、存在確認に使われないようにするため）。
 * - 店舗が手動承認した利用者は、ユーザー一覧にバッジが出る。
 */

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';
const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'password';

const LOGIN_PAGE_SLUG = 'issue-507-login-test';

const UNVERIFIED_USER_LOGIN = 'issue507_unverified_user';
const UNVERIFIED_USER_EMAIL = 'issue507_unverified_user@example.com';
const UNVERIFIED_USER_PASSWORD = 'CorrectPassword123!';

const MANUAL_USER_LOGIN = 'issue507_manual_user';
const MANUAL_USER_EMAIL = 'issue507_manual_user@example.com';

let ORIGINAL_LOCALE = 'en_US';

/**
 * テスト用ログインページ（[vkbm_login_form] のみ）を用意する。
 * login-user-enumeration.spec.ts と同じ考え方。
 */
function ensureLoginPage(): void {
	const phpCode = `
		$post = get_page_by_path("${ LOGIN_PAGE_SLUG }");
		$post_data = array(
			"post_type"    => "page",
			"post_title"   => "Issue 507 Login Test",
			"post_name"    => "${ LOGIN_PAGE_SLUG }",
			"post_content" => "[vkbm_login_form]",
			"post_status"  => "publish",
		);
		if ( $post ) {
			$post_data["ID"] = $post->ID;
			wp_update_post( $post_data );
		} else {
			wp_insert_post( $post_data );
		}
	`;
	wpEvalPhp( phpCode );
	wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );
}

/**
 * BM設定のレート制限を無効化する（連続テストでロックされないように）。
 */
function disableRateLimitSetting(): void {
	try {
		let current: any = {};
		try {
			const raw = wpCliArgs( [
				'option',
				'get',
				'vkbm_provider_settings',
				'--format=json',
			] );
			const parsed = JSON.parse( raw );
			if ( typeof parsed === 'object' && parsed !== null && ! Array.isArray( parsed ) ) {
				current = parsed;
			}
		} catch ( _e ) {
			current = {};
		}

		// メール認証は必須のままにしておく（未認証の再現に必要）。
		const merged = {
			...current,
			auth_rate_limit_enabled: 0,
			registration_rate_limit_enabled: 0,
			registration_email_verification_enabled: true,
		};

		const b64 = Buffer.from( JSON.stringify( merged ) ).toString( 'base64' );
		wpEvalPhp(
			`update_option( 'vkbm_provider_settings', json_decode( base64_decode( '${ b64 }' ), true ) );`
		);
	} catch ( _e ) {
		// ignore
	}
}

/**
 * `vkbm_rl_*` トランジェント・使い捨て再送許可（transient）を全削除する。
 */
function flushRateLimitAndGrantTransients(): void {
	try {
		const phpCode = `
			global $wpdb;
			$rows = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_vkbm_rl_%' OR option_name LIKE '_transient_vkbm_resend_grant_%'"
			);
			foreach ( $rows as $k ) {
				delete_transient( str_replace( '_transient_', '', $k ) );
			}
		`;
		wpEvalPhp( phpCode );
	} catch ( _e ) {
		// ignore
	}
}

function ensureJapaneseLocale(): void {
	try {
		wpCliArgs( [ 'language', 'core', 'install', 'ja' ] );
	} catch ( _e ) {
		// already installed
	}
	wpCliArgs( [ 'site', 'switch-language', 'ja' ] );
}

/**
 * メール未認証（'0'）の状態でユーザーを作り直す。
 */
function ensureUnverifiedUser(): void {
	try {
		wpCliArgs( [ 'user', 'delete', UNVERIFIED_USER_LOGIN, '--yes' ] );
	} catch ( _e ) {
		// ignore
	}
	wpCliArgs( [
		'user',
		'create',
		UNVERIFIED_USER_LOGIN,
		UNVERIFIED_USER_EMAIL,
		`--user_pass=${ UNVERIFIED_USER_PASSWORD }`,
		'--role=subscriber',
		'--porcelain',
	] );
	wpCliArgs( [ 'user', 'meta', 'update', UNVERIFIED_USER_LOGIN, 'vkbm_email_verified', '0' ] );
}

/**
 * 手動承認（'manual'）の状態でユーザーを作り直す。
 */
function ensureManualUser(): void {
	try {
		wpCliArgs( [ 'user', 'delete', MANUAL_USER_LOGIN, '--yes' ] );
	} catch ( _e ) {
		// ignore
	}
	wpCliArgs( [
		'user',
		'create',
		MANUAL_USER_LOGIN,
		MANUAL_USER_EMAIL,
		'--role=subscriber',
		'--porcelain',
	] );
	wpCliArgs( [ 'user', 'meta', 'update', MANUAL_USER_LOGIN, 'vkbm_email_verified', 'manual' ] );
}

/**
 * ログインフォームへ入力して送信し、リダイレクト後の画面を返す。
 *
 * @param page     Playwright ページ。
 * @param url      遷移先URL（相対パス）。
 * @param username ユーザー名。
 * @param password パスワード。
 */
async function submitLoginForm(
	page: Page,
	url: string,
	username: string,
	password: string
): Promise< void > {
	await page.goto( url );
	await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );
	await page.locator( '#vkbm-login-username' ).fill( username );
	await page.locator( '#vkbm-login-password' ).fill( password );
	await page
		.locator( '.vkbm-auth-card--login .vkbm-auth-button[type="submit"]' )
		.click();
	await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );
}

test.describe( 'issue #507: メール認証の再送・手動承認', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		try {
			const currentLocale = wpCliArgs( [ 'option', 'get', 'WPLANG' ] );
			ORIGINAL_LOCALE = currentLocale || 'en_US';
		} catch ( _e ) {
			ORIGINAL_LOCALE = 'en_US';
		}

		ensureJapaneseLocale();
		ensureLoginPage();
		disableRateLimitSetting();
	} );

	test.beforeEach( () => {
		flushRateLimitAndGrantTransients();
	} );

	test.afterAll( () => {
		try {
			wpCliArgs( [ 'site', 'switch-language', ORIGINAL_LOCALE ] );
		} catch ( _e ) {
			// ignore
		}
		for ( const login of [ UNVERIFIED_USER_LOGIN, MANUAL_USER_LOGIN ] ) {
			try {
				wpCliArgs( [ 'user', 'delete', login, '--yes' ] );
			} catch ( _e ) {
				// ignore
			}
		}
	} );

	test( '未認証ユーザー + 正しいパスワード → ログイン用ショートコードページに再送ボタンが表示される', async ( {
		page,
	} ) => {
		ensureUnverifiedUser();

		await submitLoginForm(
			page,
			`/${ LOGIN_PAGE_SLUG }/`,
			UNVERIFIED_USER_LOGIN,
			UNVERIFIED_USER_PASSWORD
		);

		const warningBox = page.locator(
			'.vkbm-auth-card--login .vkbm-alert__warning'
		);
		await expect( warningBox ).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator(
				'.vkbm-auth-card--login .vkbm-auth-form__resend button[type="submit"]'
			)
		).toBeVisible();
	} );

	test( '未認証ユーザー + 間違ったパスワード → 再送ボタンは表示されない（存在確認防止）', async ( {
		page,
	} ) => {
		ensureUnverifiedUser();

		await submitLoginForm(
			page,
			`/${ LOGIN_PAGE_SLUG }/`,
			UNVERIFIED_USER_LOGIN,
			'WrongPassword999!'
		);

		await expect(
			page.locator( '.vkbm-auth-card--login .vkbm-auth-form__resend' )
		).toHaveCount( 0 );
	} );

	test( '予約ブロック内（REST経由）のログインフォームにも再送ボタンが表示される', async ( {
		page,
	} ) => {
		ensureUnverifiedUser();

		const bookingUrl = new URL( '/booking/', WP_BASE_URL );
		bookingUrl.searchParams.set( 'vkbm_auth', 'login' );

		await page.goto( bookingUrl.toString() );
		await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );
		await page.locator( '#vkbm-login-username' ).fill( UNVERIFIED_USER_LOGIN );
		await page.locator( '#vkbm-login-password' ).fill( UNVERIFIED_USER_PASSWORD );
		await page
			.locator( '.vkbm-auth-card--login .vkbm-auth-button[type="submit"]' )
			.click();

		// フル送信 → 同じページへ戻り、ブロックが REST でログインフォームを再取得する。
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		await page.waitForSelector( '.vkbm-auth-card--login', { timeout: 15000 } );

		await expect(
			page.locator(
				'.vkbm-auth-card--login .vkbm-auth-form__resend button[type="submit"]'
			)
		).toBeVisible( { timeout: 10000 } );
	} );

	test( '手動承認済みユーザーは、管理画面のユーザー一覧にバッジが表示される', async ( {
		page,
	} ) => {
		ensureManualUser();

		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( ADMIN_USER );
		await page.locator( '#user_pass' ).fill( ADMIN_PASSWORD );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );

		await page.goto( `${ WP_BASE_URL }/wp-admin/users.php?s=${ MANUAL_USER_LOGIN }` );
		await page.waitForLoadState( 'networkidle' );

		const badge = page.locator( '.vkbm-email-verification-badge--manual' );
		await expect( badge ).toBeVisible( { timeout: 10000 } );
	} );
} );
