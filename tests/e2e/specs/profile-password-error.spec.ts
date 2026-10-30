import { test, expect, Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp } from '../utils/helpers';

// プロフィール画面でパスワード変更エラー表示のテスト（issue #74）
// @title プロフィール画面のパスワード変更エラー表示 (#74)
test.describe( 'Profile password change error display (#74)', () => {
	// テスト環境のセットアップ（グローバルセットアップでテストデータは作成済み）
	// Setup test environment (test data already created by global setup)
	test.beforeAll( async () => {
		// @step 管理画面で購読者のテスト用ユーザー（パスワード OldPassword123）を作成し、フリガナ・電話番号・生年月日・性別を入れておく
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
		// wp-login.js は load の少し後（setTimeout）にユーザー名欄へフォーカスして全選択する。
		// 先にパスワード欄へ入力するとフォーカスを奪われ、入力がユーザー名欄に入るため、
		// フォーカスが移るのを待ってから入力し、入力値を確かめてから送信する。
		const userField = page.locator( '#user_login' );
		const passField = page.locator( '#user_pass' );
		await expect( userField )
			.toBeFocused( { timeout: 5000 } )
			.catch( () => {
				// フォーカス処理が無い画面では待たずに進む。
			} );
		await userField.fill( 'profile_e2e_user' );
		await passField.fill( 'OldPassword123' );
		await expect( userField ).toHaveValue( 'profile_e2e_user' );
		await expect( passField ).toHaveValue( 'OldPassword123' );
		await page.getByRole( 'button', { name: /ログイン|Log In/ } ).click();
		// ログイン完了を待つ。ログイン画面から離れたことで判定する。
		// 離れた先（ダッシュボード）の load までは待たない（重い環境で時間切れになりやすく、
		// この後すぐ別の画面へ移動するため不要。ログイン Cookie は離れた時点で設定済み）。
		await page.waitForURL(
			( url ) => ! url.pathname.includes( 'wp-login.php' ),
			{ waitUntil: 'domcontentloaded', timeout: 30000 }
		);
	};

	test( 'パスワード不一致時にエラーメッセージが表示される', async ( {
		page,
	} ) => {
		// テストユーザーとしてログイン
		// @step テスト用ユーザーでログインし、予約ページのプロフィール画面を開く
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// 「新しいパスワード」に値を入力
		// @step 「新しいパスワード」に NewPassword123、「新しいパスワード（確認）」に DifferentPassword456 を入れる
		await page.locator( '#vkbm-profile-password' ).fill( 'NewPassword123' );

		// 「新しいパスワード（確認）」に異なる値を入力
		await page
			.locator( '#vkbm-profile-password-confirm' )
			.fill( 'DifferentPassword456' );

		// 保存ボタンをクリック
		// @step 「保存」ボタンを押す
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// 保存は POST → リダイレクトで画面が読み込み直される。保存前のフォームは通知を持たないため、
		// 下の通知の表示待ち（リダイレクト後にだけ出る）がそのまま保存完了の待ちになる。

		// エラーメッセージが表示されることを確認
		// @check 「新しいパスワードが一致しません」のエラーが表示される
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).toBeVisible( { timeout: 20000 } );

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
		// @step テスト用ユーザーでログインし、予約ページのプロフィール画面を開く
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// 「新しいパスワード」に短いパスワードを入力
		// @step 「新しいパスワード」と「新しいパスワード（確認）」の両方に short を入れる
		await page.locator( '#vkbm-profile-password' ).fill( 'short' );

		// 「新しいパスワード（確認）」に同じ短いパスワードを入力
		await page.locator( '#vkbm-profile-password-confirm' ).fill( 'short' );

		// 保存ボタンをクリック
		// @step 「保存」ボタンを押す
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// 保存は POST → リダイレクトで画面が読み込み直される。保存前のフォームは通知を持たないため、
		// 下の通知の表示待ち（リダイレクト後にだけ出る）がそのまま保存完了の待ちになる。

		// エラーメッセージが表示されることを確認
		// @check 「8文字以上」のパスワードを求めるエラーが表示される
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).toBeVisible( { timeout: 20000 } );

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
		// @step テスト用ユーザーでログインし、予約ページのプロフィール画面を開く
		await loginAsTestUser( page );

		// 予約ページ（プロフィール画面）に移動
		await page.goto( '/booking/?vkbm_auth=profile' );

		// プロフィールフォームが表示されるまで待つ
		await page.waitForSelector( '.vkbm-auth-card--profile', {
			timeout: 15000,
		} );

		// パスワード欄は空のまま、他のフィールドを確認（変更しない）
		// 保存ボタンをクリック
		// @step パスワード欄は空のまま「保存」ボタンを押す
		await page
			.locator( '.vkbm-auth-card--profile' )
			.getByRole( 'button', { name: /保存|Save/ } )
			.click();

		// 保存は POST → リダイレクトで画面が読み込み直される。保存前のフォームは通知を持たないため、
		// 下の通知の表示待ち（リダイレクト後にだけ出る）がそのまま保存完了の待ちになる。

		// 成功メッセージが表示されることを確認
		// @check 保存成功のメッセージが表示され、エラーは表示されない
		const successAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__success'
		);
		await expect( successAlert ).toBeVisible( { timeout: 20000 } );

		// エラーメッセージが表示されていないことを確認
		const errorAlert = page.locator(
			'.vkbm-auth-card--profile .vkbm-alert__danger'
		);
		await expect( errorAlert ).not.toBeVisible();
	} );
} );
