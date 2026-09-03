import { test, expect, Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp } from '../utils/helpers';

// プロフィール画面でパスワード変更エラー表示のテスト（issue #74）
test.describe( 'Profile password change error display (#74)', () => {
	// テスト環境のセットアップ（グローバルセットアップでテストデータは作成済み）
	// Setup test environment (test data already created by global setup)
	test.beforeAll( async () => {
		// テスト用ユーザーを作成（既存の場合は削除して再作成）
		try {
			wpCliArgs( [ 'user', 'delete', 'profile_e2e_user', '--yes' ], {
				stdio: 'ignore',
			} );
		} catch ( e ) {
			// ユーザーが存在しない場合は無視
		}

		try {
			wpCliArgs(
				[
					'user',
					'create',
					'profile_e2e_user',
					'profile_e2e@example.com',
					'--user_pass=OldPassword123',
					'--role=subscriber',
				],
				{ stdio: 'inherit' }
			);
		} catch ( e: unknown ) {
			console.error(
				'Failed to create test user:',
				e instanceof Error ? e.message : String( e )
			);
			throw e;
		}

		// テストユーザーにメタ情報を設定（プロフィールフォームのバリデーションに必要）
		const setMetaCode = `
			$user = get_user_by('login', 'profile_e2e_user');
			if ($user) {
				update_user_meta($user->ID, 'vkbm_kana_name', 'テストユーザー');
				update_user_meta($user->ID, 'phone_number', '09012345678');
				update_user_meta($user->ID, 'vkbm_birth_date', '1990-01-01');
				update_user_meta($user->ID, 'gender', 'male');
			}
		`;
		try {
			// wpEvalPhp が base64 ラップ＋execFileSync 実行をまとめて行う
			wpEvalPhp( setMetaCode, { stdio: 'inherit' } );
		} catch ( e: unknown ) {
			console.error(
				'Failed to set user meta:',
				e instanceof Error ? e.message : String( e )
			);
			throw e;
		}
	} );

	// テスト完了後にテストユーザーを削除してクリーンアップ
	// Clean up test user after all tests
	test.afterAll( async () => {
		try {
			wpCliArgs( [ 'user', 'delete', 'profile_e2e_user', '--yes' ], {
				stdio: 'ignore',
			} );
		} catch ( e ) {
			// クリーンアップ失敗は無視
		}
	} );

	// 各テスト前にレート制限用トランジェントのみを削除してレート制限を回避
	test.beforeEach( async () => {
		// vkbm_rl_ プレフィックスのトランジェントのみ削除（他テストへの影響を防止）
		try {
			// 複数文の PHP を base64 ラップして実行するため wpEvalPhp を使用する
			const cleanupCode = `
				global $wpdb;
				$keys = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_vkbm_rl_%'" );
				foreach ( $keys as $key ) {
					$name = str_replace( '_transient_', '', $key );
					delete_transient( $name );
				}
				echo count( $keys ) . ' transients deleted';
			`;
			const result = wpEvalPhp( cleanupCode );
			console.log( `Rate limit transients cleanup: ${ result }` );
		} catch ( e ) {
			// 削除失敗は無視（トランジェントが存在しない場合など）
		}
	} );

	// テスト用ユーザーとしてログインするヘルパー関数
	const loginAsTestUser = async ( page: Page ) => {
		// WordPress のログインページでログイン
		await page.goto( '/wp-login.php' );
		await page
			.getByLabel( /ユーザー名|Username/ )
			.fill( 'profile_e2e_user' );
		await page
			.getByLabel( /パスワード|Password/, { exact: false } )
			.first()
			.fill( 'OldPassword123' );
		await page.getByRole( 'button', { name: /ログイン|Log In/ } ).click();
		// ログイン完了を待つ
		await page.waitForURL( /wp-admin|booking/ );
	};

	test( 'パスワード不一致時にエラーメッセージが表示される', async ( {
		page,
	} ) => {
		// テストユーザーとしてログイン
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// 「新しいパスワード」に値を入力
		await page.locator( '#vkbm-profile-password' ).fill( 'NewPassword123' );

		// 「新しいパスワード（確認）」に異なる値を入力
		await page
			.locator( '#vkbm-profile-password-confirm' )
			.fill( 'DifferentPassword456' );

		// 保存ボタンをクリック
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// ページ遷移を待つ（リダイレクト後）
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// エラーメッセージが表示されることを確認
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).toBeVisible( { timeout: 10000 } );

		// エラーメッセージの内容を確認（日本語 or 英語）
		const errorText = await errorAlert.textContent();
		const hasPasswordMismatchError =
			errorText?.includes( '新しいパスワードが一致しません' ) ||
			errorText?.includes( 'New passwords do not match' );
		expect( hasPasswordMismatchError ).toBeTruthy();
	} );

	test( 'パスワードが短すぎる場合にエラーメッセージが表示される', async ( {
		page,
	} ) => {
		// テストユーザーとしてログイン
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// 「新しいパスワード」に短いパスワードを入力
		await page.locator( '#vkbm-profile-password' ).fill( 'short' );

		// 「新しいパスワード（確認）」に同じ短いパスワードを入力
		await page.locator( '#vkbm-profile-password-confirm' ).fill( 'short' );

		// 保存ボタンをクリック
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// ページ遷移を待つ（リダイレクト後）
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// エラーメッセージが表示されることを確認
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).toBeVisible( { timeout: 10000 } );

		// エラーメッセージの内容を確認（日本語 or 英語）
		const errorText = await errorAlert.textContent();
		const hasPasswordShortError =
			errorText?.includes( '8文字以上' ) ||
			errorText?.includes(
				'Please enter a password of 8 characters or more'
			);
		expect( hasPasswordShortError ).toBeTruthy();
	} );

	test( 'パスワード未入力で他のフィールドを変更して保存が成功する（デグレ確認）', async ( {
		page,
	} ) => {
		// テストユーザーとしてログイン
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// パスワード欄は空のまま、他のフィールドを確認（変更しない）
		// 保存ボタンをクリック
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// リダイレクト後にプロフィールフォームが再表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// 成功メッセージが表示されることを確認
		const successAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__success'
		);
		await expect( successAlert ).toBeVisible( { timeout: 10000 } );

		// エラーメッセージが表示されていないことを確認
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).not.toBeVisible();
	} );
} );
