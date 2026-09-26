<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Auth\Email_Verification;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * Test subclass that prevents redirect_and_exit from calling exit().
 * テスト用サブクラス。redirect_and_exit で exit() を呼ばないようにする。
 */
class Testable_Auth_Shortcodes extends Auth_Shortcodes {
	/**
	 * Last redirect URL captured during tests.
	 * テスト中にキャプチャされた最後のリダイレクト URL。
	 *
	 * @var string|null
	 */
	public $last_redirect_url = null;

	/**
	 * Override redirect_and_exit to capture the URL without exiting.
	 * リダイレクトURLをキャプチャし、exit を呼ばないようにオーバーライドする。
	 *
	 * @param string $url Redirect URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		$this->last_redirect_url = $url;
	}
}

/**
 * @group auth
 */
class Auth_Shortcodes_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		// wp_delete_user() を使うテスト（issue #507 のログイン系テスト等）のために読み込んでおく。
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( 'ja' );
		}
		// Load the .mo file directly without calling unload_textdomain first.
		// unload_textdomain( $domain, true ) sets $l10n_unloaded which prevents
		// subsequent load_textdomain calls from working in WordPress 6.5+.
		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( file_exists( $mo_path ) && function_exists( 'load_textdomain' ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		parent::tearDown();
	}

	public function test_japanese_translations_are_loaded(): void {
		if ( getenv( 'VK_BOOKING_MANAGER_SKIP_I18N_TESTS' ) ) {
			$this->markTestSkipped( 'Skipping i18n tests for free distribution.' );
		}

		$original = 'Please enter the same password twice.';
		$expected = '同じパスワードを2回入力してください。';

		$mo_path  = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		$php_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.l10n.php';

		$debug = [
			'locale'           => get_locale(),
			'.mo exists'       => file_exists( $mo_path ) ? 'yes' : 'no',
			'.l10n.php exists' => file_exists( $php_path ) ? 'yes' : 'no',
		];
		if ( file_exists( $php_path ) ) {
			$l10n              = include $php_path;
			$debug['l10n key'] = isset( $l10n['messages'][ $original ] ) ? $l10n['messages'][ $original ] : '(not found)';
		}

		// setUp() already switches to locale 'ja' and loads the .mo file directly.
		$translated           = __( $original, 'vk-booking-manager' );
		$debug['__() result'] = $translated;

		$this->assertSame(
			$expected,
			$translated,
			'Debug: ' . wp_json_encode( $debug )
		);
	}

	public function test_registration_password_mismatch_sets_error(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Snapshot globals so we can restore them after the test. / グローバルの状態を退避。
		$previous_post   = $_POST;
		$previous_server = $_SERVER;

		// Simulate a POST registration request with mismatched passwords. / パスワード不一致のPOSTを再現。
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vkbm_registration_form'  => '1',
			'vkbm_registration_nonce' => wp_create_nonce( 'vkbm_registration_form' ),
			'user_login'              => 'newuser',
			'user_email'              => 'newuser@example.com',
			'user_pass'               => 'password123',
			'user_pass_confirm'       => 'password456',
			'kana_name'               => 'たろう',
			'phone_number'            => '090-0000-0000',
			// Provide consent fields to mirror the real registration flow. / 実際の登録フローに合わせて同意フィールドを付与。
			'vkbm_agree_terms_of_service' => '1',
			'vkbm_agree_privacy_policy'   => '1',
		];

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// Run the handler to populate registration errors. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( $shortcodes, 'registration_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'Please enter the same password twice.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

		// Restore globals to avoid side effects. / 退避した状態を復元。
		$_POST = $previous_post;
		$_SERVER = $previous_server;
		update_option( 'users_can_register', $original_registration );
	}

	public function test_profile_password_mismatch_sets_error(): void {
		// Prepare a logged-in user. / ログイン済みユーザーを用意。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'profile_user',
				'user_email' => 'profile_user@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// Snapshot globals so we can restore them after the test. / グローバルの状態を退避。
		$previous_post   = $_POST;
		$previous_server = $_SERVER;

		// Simulate a POST profile update with mismatched passwords. / パスワード不一致のプロフィール更新を再現。
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vkbm_profile_form'  => '1',
			'vkbm_profile_nonce' => wp_create_nonce( 'vkbm_profile_form' ),
			'user_email'         => 'profile_user@example.com',
			'kana_name'          => 'たろう',
			'phone_number'       => '090-0000-0000',
			'new_password'       => 'password123',
			'new_password_confirm' => 'password456',
		];

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		// Use testable subclass to prevent exit() on redirect.
		// テスト用サブクラスを使い、リダイレクト時の exit() を回避する。
		$shortcodes = new Testable_Auth_Shortcodes( $service );

		// Run the handler to populate profile errors. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( Auth_Shortcodes::class, 'profile_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'New passwords do not match.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

		// Verify that a redirect was attempted. / リダイレクトが試行されたことを確認。
		$this->assertNotNull( $shortcodes->last_redirect_url, 'Redirect should have been triggered on profile validation error.' );

		// Restore globals to avoid side effects. / 退避した状態を復元。
		$_POST = $previous_post;
		$_SERVER = $previous_server;
	}

	public function test_profile_password_too_short_sets_error(): void {
		// Prepare a logged-in user. / ログイン済みユーザーを用意。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'profile_short',
				'user_email' => 'profile_short@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// Snapshot globals so we can restore them after the test. / グローバルの状態を退避。
		$previous_post   = $_POST;
		$previous_server = $_SERVER;

		// Simulate a POST profile update with short password. / 短すぎるパスワードで更新を再現。
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vkbm_profile_form'  => '1',
			'vkbm_profile_nonce' => wp_create_nonce( 'vkbm_profile_form' ),
			'user_email'         => 'profile_short@example.com',
			'kana_name'          => 'たろう',
			'phone_number'       => '090-0000-0000',
			'new_password'       => 'short',
			'new_password_confirm' => 'short',
		];

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		// Use testable subclass to prevent exit() on redirect.
		// テスト用サブクラスを使い、リダイレクト時の exit() を回避する。
		$shortcodes = new Testable_Auth_Shortcodes( $service );

		// Run the handler to populate profile errors. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( Auth_Shortcodes::class, 'profile_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'Please enter a password of 8 characters or more.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

		// Verify that a redirect was attempted. / リダイレクトが試行されたことを確認。
		$this->assertNotNull( $shortcodes->last_redirect_url, 'Redirect should have been triggered on profile validation error.' );

		// Restore globals to avoid side effects. / 退避した状態を復元。
		$_POST = $previous_post;
		$_SERVER = $previous_server;
	}

	public function test_registration_errors_persist_after_post_for_existing_email(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Create an existing user to trigger the "email exists" validation. / 既存メールでエラーを発生させる。
		$this->factory()->user->create(
			[
				'user_login' => 'existing_user',
				'user_email' => 'existing@example.com',
			]
		);

		// Snapshot globals so we can restore them after the test. / グローバルの状態を退避。
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE['vkbm_registration_errors'] ?? null;

		// Simulate a POST registration request. / 登録フォームのPOSTを擬似的に実行。
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vkbm_registration_form'  => '1',
			'vkbm_registration_nonce' => wp_create_nonce( 'vkbm_registration_form' ),
			'user_login'              => 'newuser',
			'user_email'              => 'existing@example.com',
			'user_pass'               => 'password123',
			'user_pass_confirm'       => 'password123',
			'kana_name'               => 'たろう',
			'phone_number'            => '090-0000-0000',
			// Provide consent fields to mirror the real registration flow. / 実際の登録フローに合わせて同意フィールドを付与。
			'vkbm_agree_terms_of_service' => '1',
			'vkbm_agree_privacy_policy'   => '1',
		];

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// Run the handler to fill registration_errors internally. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the expected message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( $shortcodes, 'registration_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'This email address is already registered.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

		// Restore globals to avoid side effects. / 退避した状態を復元。
		$_POST = $previous_post;
		$_SERVER = $previous_server;
		if ( null === $previous_cookie ) {
			unset( $_COOKIE['vkbm_registration_errors'] );
		} else {
			$_COOKIE['vkbm_registration_errors'] = $previous_cookie;
		}
		update_option( 'users_can_register', $original_registration );
	}

	public function test_registration_errors_are_rendered_from_cookie(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Seed an error cookie to emulate a previous failed submission. / 失敗後のcookie状態を再現。
		$payload = [
			'messages' => [ 'このメールアドレスは既に登録済みです。' ],
			'posted'   => [
				'user_email' => 'sample@example.com',
			],
			'raw'      => [],
		];

		$_COOKIE['vkbm_registration_errors'] = rawurlencode( wp_json_encode( $payload ) );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// Rendering should include the error message from the cookie. / cookieの内容がHTMLに出ることを確認。
		$html = $shortcodes->render_registration_form();

		$this->assertStringContainsString( 'このメールアドレスは既に登録済みです。', $html );

		// Cleanup to keep global state isolated. / グローバル状態の後始末。
		unset( $_COOKIE['vkbm_registration_errors'] );
		update_option( 'users_can_register', $original_registration );
	}

	public function test_reservation_page_has_block(): void {
		// Create test pages. / テスト用のページを作成。
		$page_without_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Test Page Without Block',
				'post_content' => '<!-- wp:paragraph --><p>Some content</p><!-- /wp:paragraph -->',
			)
		);

		$page_with_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Test Page With Block',
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '空URLの場合 => false',
				'url'                 => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '無効なURLの場合 => false',
				'url'                 => 'https://example.com/nonexistent-page',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約ブロックがないページのURLの場合 => false',
				'url'                 => get_permalink( $page_without_block_id ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約ブロックがあるページのURLの場合 => true',
				'url'                 => get_permalink( $page_with_block_id ),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Auth_Shortcodes::reservation_page_has_block( $case['url'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	public function test_redirect_wp_login_to_vkbm(): void {
		// リダイレクトするケース（リダイレクトON・予約ブロックあり）は未テスト。リダイレクト時に wp_safe_redirect() の直後で exit が呼ばれテストプロセスが終了するため、アサートまで到達できない。
		// Ensure user is not logged in. / ユーザーがログインしていないことを確認。
		wp_set_current_user( 0 );

		// Create test pages. / テスト用のページを作成。
		$page_without_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Reservation Page Without Block',
				'post_content' => '<!-- wp:paragraph --><p>Some content</p><!-- /wp:paragraph -->',
			)
		);

		$page_with_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Reservation Page With Block',
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'リダイレクトON・予約ブロックなしのページURL => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => true,
					'reservation_url'   => get_permalink( $page_without_block_id ),
					'action'            => 'login',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リダイレクトOFF・予約ブロックありのページURL => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => false,
					'reservation_url'   => get_permalink( $page_with_block_id ),
					'action'            => 'login',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リダイレクトON・予約ページURLが空 => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => true,
					'reservation_url'   => '',
					'action'            => 'login',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// Set up settings. / 設定をセットアップ。
			$repository = new Settings_Repository();
			$settings   = $repository->get_settings();
			$settings['membership_redirect_wp_login'] = $case['conditions']['redirect_enabled'];
			$settings['reservation_page_url']           = $case['conditions']['reservation_url'];
			$repository->update_settings( $settings );

			// Mock $_REQUEST to simulate login action. / ログインアクションをシミュレート。
			$previous_request = $_REQUEST;
			$_REQUEST['action'] = $case['conditions']['action'];

			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$shortcodes = new Auth_Shortcodes( $service );

			// Capture output to verify redirect is not called. / リダイレクトが呼ばれないことを確認するため出力をキャプチャ。
			ob_start();
			$shortcodes->redirect_wp_login_to_vkbm();
			$output = ob_get_clean();

			$this->assertEmpty( $output, $case['test_condition_name'] );

			// Restore globals. / グローバルを復元。
			$_REQUEST = $previous_request;
		}
	}

	public function test_redirect_wp_register_to_vkbm(): void {
		// リダイレクトするケース（リダイレクトON・予約ブロックあり）は未テスト。リダイレクト時に wp_safe_redirect() の直後で exit が呼ばれテストプロセスが終了するため、アサートまで到達できない。
		// Ensure registration is enabled. / ユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Create test pages. / テスト用のページを作成。
		$page_without_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Reservation Page Without Block',
				'post_content' => '<!-- wp:paragraph --><p>Some content</p><!-- /wp:paragraph -->',
			)
		);

		$page_with_block_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Reservation Page With Block',
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'リダイレクトON・予約ブロックなしのページURL => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => true,
					'reservation_url'   => get_permalink( $page_without_block_id ),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リダイレクトOFF・予約ブロックありのページURL => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => false,
					'reservation_url'   => get_permalink( $page_with_block_id ),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リダイレクトON・予約ページURLが空 => リダイレクトしない',
				'conditions'          => array(
					'redirect_enabled'  => true,
					'reservation_url'   => '',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// Set up settings. / 設定をセットアップ。
			$repository = new Settings_Repository();
			$settings   = $repository->get_settings();
			$settings['membership_redirect_wp_register'] = $case['conditions']['redirect_enabled'];
			$settings['reservation_page_url']              = $case['conditions']['reservation_url'];
			$repository->update_settings( $settings );

			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$shortcodes = new Auth_Shortcodes( $service );

			// Capture output to verify redirect is not called. / リダイレクトが呼ばれないことを確認するため出力をキャプチャ。
			ob_start();
			$shortcodes->redirect_wp_register_to_vkbm();
			$output = ob_get_clean();

			$this->assertEmpty( $output, $case['test_condition_name'] );
		}

		// Restore settings. / 設定を復元。
		update_option( 'users_can_register', $original_registration );
	}

	/**
	 * is_booking_customer() のテスト。
	 * 予約顧客判定が正しく動作することを確認します。
	 */
	public function test_is_booking_customer(): void {
		$repository = new Settings_Repository();
		$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// テスト用のユーザーを作成する。
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor_id     = $this->factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id      = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		// VKBM スタッフ権限を持つユーザーを作成する。
		$vkbm_staff_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$vkbm_staff    = get_user_by( 'id', $vkbm_staff_id );
		$vkbm_staff->add_cap( \VKBookingManager\Capabilities\Capabilities::VIEW_RESERVATIONS );

		$test_cases = array(
			array(
				'test_condition_name' => 'subscriber ロールのユーザー => 予約顧客',
				'user_id'             => $subscriber_id,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'editor ロールのユーザー（edit_posts あり） => 予約顧客ではない',
				'user_id'             => $editor_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'administrator ロールのユーザー（edit_posts あり） => 予約顧客ではない',
				'user_id'             => $admin_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'VKBM 予約閲覧権限を持つユーザー => 予約顧客ではない',
				'user_id'             => $vkbm_staff_id,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$user   = get_user_by( 'id', $case['user_id'] );
			$actual = $shortcodes->is_booking_customer( $user );
			$this->assertEquals( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * redirect_free_user_from_admin() のテスト。
	 * リダイレクトが行われない（早期リターンする）ケースを確認します。
	 * ※リダイレクトが実行されるケースは exit() が呼ばれるためテスト不可。
	 */
	public function test_redirect_free_user_from_admin(): void {
		// テスト前のカレントユーザーを記録する。
		$original_user_id = get_current_user_id();

		// テスト用のページを作成する。
		$page_id = $this->factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Reservation Page',
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
				'post_status'  => 'publish',
			)
		);

		// テスト用のユーザーを作成する。
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_id      = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'ログインなし => リダイレクトしない',
				'current_user_id'     => 0,
				'reservation_url'     => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '管理者ログイン => リダイレクトしない',
				'current_user_id'     => $admin_id,
				'reservation_url'     => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => 'subscriber ログイン・予約ページURLが空 => リダイレクトしない',
				'current_user_id'     => $subscriber_id,
				'reservation_url'     => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// カレントユーザーを設定する。
			wp_set_current_user( $case['current_user_id'] );

			// 設定をセットアップする。
			$repository = new Settings_Repository();
			$settings   = $repository->get_settings();
			$settings['reservation_page_url'] = $case['reservation_url'];
			$repository->update_settings( $settings );

			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$shortcodes = new Auth_Shortcodes( $service );

			// リダイレクトが呼ばれないことを確認するため出力をキャプチャする。
			ob_start();
			$shortcodes->redirect_free_user_from_admin();
			$output = ob_get_clean();

			$this->assertEmpty( $output, $case['test_condition_name'] );
		}

		// カレントユーザーを復元する。
		wp_set_current_user( $original_user_id );
	}

	/**
	 * Test that profile errors stored in a cookie are displayed in the profile form.
	 * Cookie に保存されたプロフィールエラーがフォーム表示時に復元されることを確認する。
	 */
	public function test_profile_errors_are_rendered_from_cookie(): void {
		// Prepare a logged-in user. / ログイン済みユーザーを用意。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'cookie_error_user',
				'user_email' => 'cookie_error_user@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// Seed a profile error cookie to emulate a previous failed submission.
		// 失敗後の Cookie 状態を再現する。
		$error_messages = [ 'New passwords do not match.' ];
		$_COOKIE['vkbm_profile_errors'] = rawurlencode( wp_json_encode( $error_messages ) );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// Rendering should include the error message from the cookie.
		// Cookie の内容が HTML に出力されることを確認する。
		$html = $shortcodes->render_profile_form();

		$this->assertStringContainsString( 'New passwords do not match.', $html );
		$this->assertStringContainsString( 'vkbm-alert vkbm-alert__danger', $html );

		// Cleanup to keep global state isolated. / グローバル状態の後始末。
		unset( $_COOKIE['vkbm_profile_errors'] );
	}

	/**
	 * Test that profile form renders without errors when no cookie is set.
	 * Cookie がない場合にプロフィールフォームがエラーなしで描画されることを確認する。
	 */
	public function test_profile_form_renders_without_errors_when_no_cookie(): void {
		// Prepare a logged-in user. / ログイン済みユーザーを用意。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'no_error_user',
				'user_email' => 'no_error_user@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// Make sure there is no error cookie. / エラー Cookie がないことを確認。
		unset( $_COOKIE['vkbm_profile_errors'] );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		$html = $shortcodes->render_profile_form();

		// The form should render but without the danger alert.
		// フォームは描画されるがエラーアラートは含まない。
		$this->assertStringContainsString( 'vkbm-auth-card--profile', $html );
		$this->assertStringNotContainsString( 'vkbm-alert__danger', $html );
	}

	/**
	 * Test that set_profile_errors_cookie stores errors and consume_profile_errors_cookie restores them.
	 * set_profile_errors_cookie でエラーを保存し consume_profile_errors_cookie で復元できることを確認する。
	 */
	public function test_profile_errors_cookie_round_trip(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		// Use reflection to access private methods.
		// リフレクションでプライベートメソッドにアクセスする。
		$set_method = new \ReflectionMethod( $shortcodes, 'set_profile_errors_cookie' );
		$set_method->setAccessible( true );

		$consume_method = new \ReflectionMethod( $shortcodes, 'consume_profile_errors_cookie' );
		$consume_method->setAccessible( true );

		// Create an error and store it. / エラーを作成して保存する。
		$errors = new \WP_Error();
		$errors->add( 'password_mismatch', 'New passwords do not match.' );
		$errors->add( 'password_short', 'Please enter a password of 8 characters or more.' );

		// In PHPUnit, setcookie does not actually set $_COOKIE, so we simulate
		// the cookie read by setting $_COOKIE manually after calling the setter.
		// PHPUnit では setcookie が $_COOKIE を設定しないため、手動でシミュレートする。
		$set_method->invoke( $shortcodes, $errors );

		// Simulate the browser sending the cookie back.
		// ブラウザが Cookie を送り返す状態をシミュレートする。
		$messages = [ 'New passwords do not match.', 'Please enter a password of 8 characters or more.' ];
		$_COOKIE['vkbm_profile_errors'] = rawurlencode( wp_json_encode( $messages ) );

		// Consume the cookie and verify. / Cookie を消費して検証する。
		$restored = $consume_method->invoke( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $restored );
		$restored_messages = $restored->get_error_messages();
		$this->assertContains( 'New passwords do not match.', $restored_messages );
		$this->assertContains( 'Please enter a password of 8 characters or more.', $restored_messages );

		// Cleanup. / 後始末。
		unset( $_COOKIE['vkbm_profile_errors'] );
	}

	/**
	 * Test that consume_profile_errors_cookie returns null when no cookie is present.
	 * Cookie がない場合に consume_profile_errors_cookie が null を返すことを確認する。
	 */
	public function test_consume_profile_errors_cookie_returns_null_when_empty(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		$consume_method = new \ReflectionMethod( $shortcodes, 'consume_profile_errors_cookie' );
		$consume_method->setAccessible( true );

		// Ensure no cookie exists. / Cookie が存在しないことを確認。
		unset( $_COOKIE['vkbm_profile_errors'] );

		$result = $consume_method->invoke( $shortcodes );
		$this->assertNull( $result );
	}

	/**
	 * Test that the registration flow stores the email verification token as a SHA-256 hash and never in plain text.
	 * 登録フロー経由でメール認証トークンが SHA-256 ハッシュとして保存され、平文では保存されないことを確認する。
	 */
	public function test_registration_flow_stores_email_verify_token_as_sha256_hash(): void {
		// Snapshot all mutable state up-front so the finally block can restore everything,
		// even if an assertion fails mid-way. / 失敗しても finally で完全復元できるよう状態を先に退避する。
		$original_registration = get_option( 'users_can_register' );
		$repository            = new Settings_Repository();
		$original_settings     = $repository->get_settings();
		$previous_post         = $_POST;
		$previous_server       = $_SERVER;

		// Short-circuit wp_mail so send_verification_email() succeeds without an SMTP server.
		// SMTP サーバなしでも send_verification_email() を成功させるため wp_mail をショートサーキットする。
		$mail_filter = static function () {
			return true;
		};

		try {
			// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
			update_option( 'users_can_register', 1 );

			// Ensure email verification is required so the token is generated.
			// メール認証を必須化し、トークン生成パスを通す。
			$settings = $original_settings;
			$settings['registration_email_verification_enabled'] = true;
			$repository->update_settings( $settings );

			add_filter( 'pre_wp_mail', $mail_filter );

			// Simulate a POST registration request with valid data. / 正しい登録 POST を再現する。
			$user_login                = 'verify_hash_flow_user';
			$user_email                = 'verify_hash_flow_user@example.com';
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = [
				'vkbm_registration_form'  => '1',
				'vkbm_registration_nonce' => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'              => $user_login,
				'user_email'              => $user_email,
				'user_pass'               => 'password123',
				'user_pass_confirm'       => 'password123',
				'kana_name'               => 'たろう',
				'phone_number'            => '090-0000-0000',
				// Default settings ship with a non-empty terms of service, so the
				// registration handler requires explicit agreement to succeed.
				// デフォルト設定には利用規約が含まれるため、登録成功には同意が必須。
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			];

			$service = new Settings_Service( $repository, new Settings_Sanitizer() );
			// Use the testable subclass so the trailing redirect_and_exit() does not terminate the test.
			// 末尾の redirect_and_exit() でテストプロセスを終了させないよう testable サブクラスを使う。
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			// Run the actual registration handler (handle_form_submission -> process_registration_request).
			// 実際の登録ハンドラを走らせる。
			$shortcodes->handle_form_submission();

			// The user must have been created. / ユーザーが実際に作成されていることを確認する。
			$user = get_user_by( 'email', $user_email );
			$this->assertInstanceOf( \WP_User::class, $user, 'Registration flow should have created the user.' );

			// A redirect to the login page should have been requested. / ログインページへのリダイレクトが要求されているはず。
			$this->assertNotNull( $shortcodes->last_redirect_url );

			// The hash meta must be a 64-char lowercase hex SHA-256 digest.
			// ハッシュメタが 64 文字の小文字 hex で保存されていることを確認する。
			$stored_hash = get_user_meta( $user->ID, 'vkbm_email_verify_token_hash', true );
			$this->assertIsString( $stored_hash );
			$this->assertSame(
				1,
				preg_match( '/^[0-9a-f]{64}$/', (string) $stored_hash ),
				'Stored token meta must be a SHA-256 hex digest.'
			);

			// The legacy plain-text meta key must not be present after registration.
			// 登録後、旧仕様の平文メタキーが残っていないことを確認する。
			$this->assertSame( '', (string) get_user_meta( $user->ID, 'vkbm_email_verify_token', true ) );

			// The email verification flag must be 0 (waiting for verification).
			// メール認証フラグが 0（未認証）になっていることを確認する。
			$this->assertSame( '0', (string) get_user_meta( $user->ID, 'vkbm_email_verified', true ) );
		} finally {
			// Restore every mutated piece of state regardless of assertion success or failure.
			// アサート成功・失敗にかかわらず、変更した状態を全て元に戻す。
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			update_option( 'users_can_register', $original_registration );
			$repository->update_settings( $original_settings );
		}
	}

	/**
	 * #510: send_verification_email() がメールログへ種類「registration_confirmation」を
	 * 付けて記録すること、メールログ無効時は記録しないことを確認する。
	 */
	public function test_send_verification_email_records_type_in_email_log(): void {
		$repository        = new Settings_Repository();
		$original_settings = $repository->get_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'メールログ有効・送信成功 => type=registration_confirmation で記録される（正常系）',
				'email_log_enabled'   => true,
				'mail_result'         => true,
				'expected_log_count'  => 1,
				'expected_status'     => \VKBookingManager\Admin\Email_Log_Repository::STATUS_SENT,
			),
			array(
				'test_condition_name' => 'メールログ有効・送信失敗 => failed で記録される（異常系）',
				'email_log_enabled'   => true,
				'mail_result'         => false,
				'expected_log_count'  => 1,
				'expected_status'     => \VKBookingManager\Admin\Email_Log_Repository::STATUS_FAILED,
			),
			array(
				'test_condition_name' => 'メールログ無効 => 記録されない（境界値）',
				'email_log_enabled'   => false,
				'mail_result'         => true,
				'expected_log_count'  => 0,
				'expected_status'     => null,
			),
		);

		try {
			$log_repository = new \VKBookingManager\Admin\Email_Log_Repository();

			foreach ( $test_cases as $case ) {
				$log_repository->clear_logs();

				$settings                       = $original_settings;
				$settings['email_log_enabled']  = $case['email_log_enabled'];
				$repository->update_settings( $settings );

				$mail_result = $case['mail_result'];
				$mail_filter = static function () use ( $mail_result ) {
					if ( $mail_result ) {
						return true;
					}
					do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'Simulated SMTP failure' ) );
					return false;
				};
				add_filter( 'pre_wp_mail', $mail_filter );

				$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
				$shortcodes = new Auth_Shortcodes( $service );
				$method     = new \ReflectionMethod( $shortcodes, 'send_verification_email' );
				$method->setAccessible( true );
				$method->invoke( $shortcodes, 'verify_type_user@example.com', '', 'dummy-token' );

				remove_filter( 'pre_wp_mail', $mail_filter );

				$logs = $log_repository->get_logs();
				$this->assertCount( $case['expected_log_count'], $logs, $case['test_condition_name'] );

				if ( $case['expected_log_count'] > 0 ) {
					$this->assertSame( 'registration_confirmation', $logs[0]['type'], $case['test_condition_name'] );
					$this->assertSame( $case['expected_status'], $logs[0]['status'], $case['test_condition_name'] );
				}
			}
		} finally {
			$repository->update_settings( $original_settings );
			( new \VKBookingManager\Admin\Email_Log_Repository() )->clear_logs();
		}
	}

	/**
	 * Test that handle_email_verification cleans up the legacy plain-text token meta as well.
	 * 認証完了時に旧仕様の平文トークンメタも一緒に掃除されることを確認する。
	 */
	public function test_handle_email_verification_purges_legacy_plain_token_meta(): void {
		// Create a user with both the new hash meta and the legacy plain-text meta.
		// 新ハッシュメタと旧平文メタの両方を持つユーザーを作成する。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'verify_purge_user',
				'user_email' => 'verify_purge_user@example.com',
			]
		);

		$raw_token = 'purge-token-' . wp_generate_password( 16, false );
		update_user_meta( $user_id, 'vkbm_email_verified', '0' );
		update_user_meta( $user_id, 'vkbm_email_verify_token_hash', hash( 'sha256', $raw_token ) );
		update_user_meta( $user_id, 'vkbm_email_verify_expires', time() + DAY_IN_SECONDS );
		// Simulate a leftover plain-text token from before the migration.
		// ハッシュ化前の旧仕様で残った平文トークンを再現する。
		update_user_meta( $user_id, 'vkbm_email_verify_token', $raw_token );

		// Snapshot globals so we can restore them after the test. / グローバルの状態を退避。
		$previous_get    = $_GET;
		$previous_server = $_SERVER;

		// Simulate the email verification request carrying the raw token.
		// 生トークンを持ったメール認証リクエストを再現する。
		$_GET                   = [ 'vkbm_verify_email' => $raw_token ];
		$_SERVER['REQUEST_URI'] = '/?vkbm_verify_email=' . rawurlencode( $raw_token );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Testable_Auth_Shortcodes( $service );

		$shortcodes->handle_email_verification();

		// Both meta keys must be removed after successful verification.
		// 認証完了後、新旧両方のメタキーが削除されていること。
		$this->assertSame( '1', (string) get_user_meta( $user_id, 'vkbm_email_verified', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'vkbm_email_verify_token_hash', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'vkbm_email_verify_token', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'vkbm_email_verify_expires', true ) );

		// Restore globals. / グローバルを復元する。
		$_GET    = $previous_get;
		$_SERVER = $previous_server;
	}

	/**
	 * Test that handle_email_verification accepts a raw token whose SHA-256 hash matches user meta.
	 * 生トークンの SHA-256 ハッシュとユーザーメタが一致した場合に認証が成功することを確認する。
	 */
	public function test_handle_email_verification_succeeds_with_hashed_token_lookup(): void {
		// Create a user that is awaiting email verification.
		// メール認証待ちのユーザーを作成する。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'verify_lookup_user',
				'user_email' => 'verify_lookup_user@example.com',
			]
		);

		// Seed verification metadata with a hash stored under the new key.
		// 新キーにハッシュとして認証メタデータを保存する。
		$raw_token = 'lookup-token-' . wp_generate_password( 16, false );
		update_user_meta( $user_id, 'vkbm_email_verified', '0' );
		update_user_meta( $user_id, 'vkbm_email_verify_token_hash', hash( 'sha256', $raw_token ) );
		update_user_meta( $user_id, 'vkbm_email_verify_expires', time() + DAY_IN_SECONDS );

		// Snapshot globals so we can restore them after the test.
		// テスト後に復元できるようにグローバルを退避する。
		$previous_get    = $_GET;
		$previous_server = $_SERVER;

		// Simulate the email verification request carrying the raw token.
		// 生トークンを持ったメール認証リクエストを再現する。
		$_GET                  = [ 'vkbm_verify_email' => $raw_token ];
		$_SERVER['REQUEST_URI'] = '/?vkbm_verify_email=' . rawurlencode( $raw_token );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Testable_Auth_Shortcodes( $service );

		// Run the verification handler. / 認証ハンドラを実行する。
		$shortcodes->handle_email_verification();

		// The user should now be marked as verified, and the hash meta should be removed.
		// ユーザーが認証済みになり、ハッシュメタは削除される。
		$this->assertSame( '1', (string) get_user_meta( $user_id, 'vkbm_email_verified', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'vkbm_email_verify_token_hash', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, 'vkbm_email_verify_expires', true ) );

		// A redirect should have been triggered. / リダイレクトが試行されたことを確認する。
		$this->assertNotNull( $shortcodes->last_redirect_url );

		// Restore globals. / グローバルを復元する。
		$_GET    = $previous_get;
		$_SERVER = $previous_server;
	}

	/**
	 * Test that handle_email_verification rejects a token whose hash is not stored anywhere.
	 * ハッシュが保存されていないトークンでは認証が成立しないことを確認する。
	 */
	public function test_handle_email_verification_rejects_unknown_token(): void {
		// Create a user that is awaiting email verification with a known hash.
		// 既知のハッシュを持つメール認証待ちユーザーを作成する。
		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'verify_reject_user',
				'user_email' => 'verify_reject_user@example.com',
			]
		);

		$stored_raw = 'stored-token-value';
		update_user_meta( $user_id, 'vkbm_email_verified', '0' );
		update_user_meta( $user_id, 'vkbm_email_verify_token_hash', hash( 'sha256', $stored_raw ) );
		update_user_meta( $user_id, 'vkbm_email_verify_expires', time() + DAY_IN_SECONDS );

		// Snapshot globals so we can restore them after the test.
		// テスト後に復元できるようにグローバルを退避する。
		$previous_get    = $_GET;
		$previous_server = $_SERVER;

		// Simulate the email verification request with an unrelated token value.
		// 関係のないトークンでメール認証リクエストを再現する。
		$bogus_token            = 'bogus-token-value';
		$_GET                   = [ 'vkbm_verify_email' => $bogus_token ];
		$_SERVER['REQUEST_URI'] = '/?vkbm_verify_email=' . rawurlencode( $bogus_token );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Testable_Auth_Shortcodes( $service );

		// Run the verification handler. / 認証ハンドラを実行する。
		$shortcodes->handle_email_verification();

		// The user must remain unverified and the hash meta must be retained.
		// ユーザーは未認証のままで、ハッシュメタも保持されているはず。
		$this->assertSame( '0', (string) get_user_meta( $user_id, 'vkbm_email_verified', true ) );
		$this->assertSame( hash( 'sha256', $stored_raw ), get_user_meta( $user_id, 'vkbm_email_verify_token_hash', true ) );

		// No redirect should have been triggered. / リダイレクトは行われない。
		$this->assertNull( $shortcodes->last_redirect_url );

		// Restore globals. / グローバルを復元する。
		$_GET    = $previous_get;
		$_SERVER = $previous_server;
	}

	/**
	 * ログイン判定（issue #507）: 4つの保存状態 × メール認証必須設定のオン/オフ。
	 * 正しいパスワードでログインを試み、Email_Verification::is_login_allowed() の
	 * 結果どおりに許可・拒否されることを、リダイレクトの有無で検証する。
	 */
	public function test_process_login_request_respects_email_verification_status(): void {
		$repository         = new Settings_Repository();
		$original_settings  = $repository->get_settings();
		$previous_post      = $_POST;
		$previous_server    = $_SERVER;
		$previous_cookie    = $_COOKIE;
		$password           = 'CorrectPass123!';

		$test_cases = [
			[
				'test_condition_name'    => '状態が保存されていない（旧ユーザー）かつ設定オンの場合 => ログイン許可',
				'status'                 => null,
				'verification_required'  => true,
				'expect_login'           => true,
				'expect_resend_grant'    => false,
			],
			[
				'test_condition_name'    => '状態 "1"（メールで認証済み）かつ設定オンの場合 => ログイン許可',
				'status'                 => Email_Verification::STATUS_VERIFIED,
				'verification_required'  => true,
				'expect_login'           => true,
				'expect_resend_grant'    => false,
			],
			[
				'test_condition_name'    => '状態 "manual"（手動承認）かつ設定オンの場合 => ログイン許可',
				'status'                 => Email_Verification::STATUS_MANUAL,
				'verification_required'  => true,
				'expect_login'           => true,
				'expect_resend_grant'    => false,
			],
			[
				'test_condition_name'    => '状態 "0"（未認証）かつ設定オンの場合 => ログイン拒否・再送許可を発行',
				'status'                 => Email_Verification::STATUS_UNVERIFIED,
				'verification_required'  => true,
				'expect_login'           => false,
				'expect_resend_grant'    => true,
			],
			[
				'test_condition_name'    => '状態 "0"（未認証）かつ設定オフの場合（論点1） => ログイン許可',
				'status'                 => Email_Verification::STATUS_UNVERIFIED,
				'verification_required'  => false,
				'expect_login'           => true,
				'expect_resend_grant'    => false,
			],
		];

		try {
			foreach ( $test_cases as $index => $case ) {
				$settings                                             = $original_settings;
				$settings['registration_email_verification_enabled'] = $case['verification_required'];
				$repository->update_settings( $settings );

				$user_login = 'login_state_user_' . $index;
				$user_id    = wp_insert_user(
					[
						'user_login' => $user_login,
						'user_email' => $user_login . '@example.com',
						'user_pass'  => $password,
					]
				);
				$this->assertIsInt( $user_id, $case['test_condition_name'] );

				if ( null !== $case['status'] ) {
					update_user_meta( $user_id, Email_Verification::META_STATUS, $case['status'] );
				}

				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_COOKIE                   = [];
				$_POST                     = [
					'vkbm_login_form'  => '1',
					'vkbm_login_nonce' => wp_create_nonce( 'vkbm_login_form' ),
					'log'              => $user_login,
					'pwd'              => $password,
				];

				$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
				$shortcodes = new Testable_Auth_Shortcodes( $service );
				$shortcodes->handle_form_submission();

				if ( $case['expect_login'] ) {
					$this->assertNotNull( $shortcodes->last_redirect_url, $case['test_condition_name'] );
				} else {
					$this->assertNull( $shortcodes->last_redirect_url, $case['test_condition_name'] );
				}

				$this->assertSame(
					$case['expect_resend_grant'],
					$shortcodes->has_resend_grant(),
					$case['test_condition_name']
				);

				wp_delete_user( $user_id );
			}
		} finally {
			$repository->update_settings( $original_settings );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 再送許可の発行条件（issue #507）: パスワードを間違えた場合は再送許可を発行しないこと
	 * （#194 と同じ観点。存在確認に使われないようにする）。
	 */
	public function test_resend_grant_is_not_issued_on_wrong_password(): void {
		$repository        = new Settings_Repository();
		$original_settings = $repository->get_settings();
		$previous_post     = $_POST;
		$previous_server   = $_SERVER;
		$previous_cookie   = $_COOKIE;

		try {
			$settings                                             = $original_settings;
			$settings['registration_email_verification_enabled'] = true;
			$repository->update_settings( $settings );

			$user_login = 'wrong_password_user';
			$user_id    = wp_insert_user(
				[
					'user_login' => $user_login,
					'user_email' => $user_login . '@example.com',
					'user_pass'  => 'CorrectPass123!',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_COOKIE                   = [];
			$_POST                     = [
				'vkbm_login_form'  => '1',
				'vkbm_login_nonce' => wp_create_nonce( 'vkbm_login_form' ),
				'log'              => $user_login,
				'pwd'              => 'WrongPassword!',
			];

			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );
			$shortcodes->handle_form_submission();

			$this->assertNull( $shortcodes->last_redirect_url );
			$this->assertFalse( $shortcodes->has_resend_grant(), 'パスワードを間違えた場合は再送許可を発行しない' );

			wp_delete_user( $user_id );
		} finally {
			$repository->update_settings( $original_settings );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 再送処理（issue #507）: 使い捨て許可で再送すると、新しいトークンに置き換わり
	 * （古いリンクが無効化され）、許可は消費されて再度は使えなくなること。
	 */
	public function test_process_resend_verification_request_rotates_token_and_consumes_grant(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		$mail_filter = static function () {
			return true;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_rotate_user',
					'user_email' => 'resend_rotate_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );
			$old_hash = hash( 'sha256', 'old-token' );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, $old_hash );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_EXPIRES, time() + DAY_IN_SECONDS );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			// issue_resend_grant() 相当を、ログイン失敗経由と同じ transient + クッキーの
			// 形で再現する（private メソッドのため、公開 API 経由の同等の状態を用意する）。
			$grant_token = 'grant-token-value';
			set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $grant_token ), $user_id, 600 );

			$_SERVER['REQUEST_METHOD']            = 'POST';
			$_COOKIE['vkbm_resend_grant']          = $grant_token;
			$_POST                                = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			$shortcodes->handle_form_submission();

			// 新しいトークンに置き換わり、古いハッシュはもう保存されていないこと。
			$new_hash = (string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true );
			$this->assertNotSame( $old_hash, $new_hash, '再送で新しいトークンハッシュに置き換わること' );
			$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $new_hash ), 'ハッシュがSHA-256形式であること' );

			// 植草さんレビュー指摘（issue #507 PR）: 成功後も「もう一度お試しください」の
			// 手段を残すため、新しい許可が発行し直されて再送ボタンが出続けること。
			$this->assertTrue( $shortcodes->has_resend_grant(), '再送成功後も新しい許可が発行し直されること' );

			// リダイレクトが行われていること（成功フィードバック用の通知クッキー経由）。
			$this->assertNotNull( $shortcodes->last_redirect_url );

			wp_delete_user( $user_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 安藤さんレビュー指摘（issue #507 PR）: 再送許可を再発行する際、直前の許可の
	 * transient は無効化されること（古い許可の使い回し防止）。
	 */
	public function test_issue_resend_grant_invalidates_previous_grant(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		$mail_filter = static function () {
			return true;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_invalidate_prev_user',
					'user_email' => 'resend_invalidate_prev_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			$old_grant_token = 'old-grant-token';
			$old_transient_key = 'vkbm_resend_grant_' . hash( 'sha256', $old_grant_token );
			set_transient( $old_transient_key, $user_id, 600 );

			$_SERVER['REQUEST_METHOD']    = 'POST';
			$_COOKIE['vkbm_resend_grant'] = $old_grant_token;
			$_POST                        = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			// この呼び出しで古い許可が消費され、成功後に新しい許可が発行し直される。
			$shortcodes->handle_form_submission();

			$this->assertTrue( $shortcodes->has_resend_grant(), '新しい許可が発行し直されること' );
			$this->assertFalse(
				get_transient( $old_transient_key ),
				'古い許可の transient は再発行時に無効化されていること'
			);

			wp_delete_user( $user_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 再送処理（issue #507）: 同一利用者への再送は60秒に1回まで（論点4）。
	 */
	public function test_process_resend_verification_request_enforces_per_user_cooldown(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		$mail_filter = static function () {
			return true;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_cooldown_user',
					'user_email' => 'resend_cooldown_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );
			// 59秒前に再送済みという状態を再現する（60秒未満）。
			update_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, time() - 59 );
			$old_hash = hash( 'sha256', 'old-token-cooldown' );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, $old_hash );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			$grant_token = 'grant-token-cooldown';
			set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $grant_token ), $user_id, 600 );

			$_SERVER['REQUEST_METHOD']   = 'POST';
			$_COOKIE['vkbm_resend_grant'] = $grant_token;
			$_POST                       = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			$shortcodes->handle_form_submission();

			// 60秒以内の再送は拒否され、トークンハッシュは変わらないこと。
			$this->assertSame(
				$old_hash,
				(string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true ),
				'60秒以内の再送はトークンを発行し直さないこと'
			);

			// 植草さんレビュー指摘（issue #507 PR）: 上限到達（クールダウン中）でも
			// 「もう一度お試しください」の手段を残すため、許可が発行し直されること。
			$this->assertTrue( $shortcodes->has_resend_grant(), 'クールダウン中でも新しい許可が発行し直されること' );

			wp_delete_user( $user_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 安藤さんレビュー指摘（issue #507 PR）: 利用者単位の1日上限（24時間で5回まで）。
	 * 上限到達時は rate_limited 表示になり、上限未満なら再送できること。
	 */
	public function test_process_resend_verification_request_enforces_daily_limit(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		$mail_filter = static function () {
			return true;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_daily_limit_user',
					'user_email' => 'resend_daily_limit_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );
			// 1日上限（5回）に既に達している状態を再現する。60秒クールダウンに
			// 引っかからないよう、最終送信は61秒以上前にしておく。
			update_user_meta( $user_id, Email_Verification::META_RESEND_COUNT, 5 );
			update_user_meta( $user_id, Email_Verification::META_RESEND_WINDOW_START, time() - 3600 );
			update_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, time() - 61 );
			$old_hash = hash( 'sha256', 'old-token-daily-limit' );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, $old_hash );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			$grant_token = 'grant-token-daily-limit';
			set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $grant_token ), $user_id, 600 );

			$_SERVER['REQUEST_METHOD']    = 'POST';
			$_COOKIE['vkbm_resend_grant'] = $grant_token;
			$_POST                        = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			$shortcodes->handle_form_submission();

			// 1日上限に達している場合はメールを再送せず、トークンハッシュは変わらないこと。
			$this->assertSame(
				$old_hash,
				(string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true ),
				'1日上限に達している場合はトークンを発行し直さないこと'
			);
			$this->assertTrue( $shortcodes->has_resend_grant(), '1日上限到達でも新しい許可が発行し直されること' );

			wp_delete_user( $user_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 安藤さんレビュー指摘（issue #507 PR）: メール送信に失敗した場合も、再挑戦の
	 * 手段を残すため新しい許可が発行し直されること。
	 */
	public function test_process_resend_verification_request_reissues_grant_on_send_failure(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		$mail_filter = static function () {
			return false;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_send_failure_user',
					'user_email' => 'resend_send_failure_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			$grant_token = 'grant-token-send-failure';
			set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $grant_token ), $user_id, 600 );

			$_SERVER['REQUEST_METHOD']    = 'POST';
			$_COOKIE['vkbm_resend_grant'] = $grant_token;
			$_POST                        = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			$shortcodes->handle_form_submission();

			$this->assertTrue( $shortcodes->has_resend_grant(), '送信失敗でも新しい許可が発行し直されること' );

			wp_delete_user( $user_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 安藤さんレビュー指摘（issue #507 PR）: 許可が無効・期限切れの場合はサイレントに
	 * 何もしないのではなく、共通文言（状態を推測されない）でリダイレクトすること。
	 * この場合は新しい許可を発行し直さない（有効な未認証ユーザーと確認できていないため）。
	 */
	public function test_process_resend_verification_request_shows_expired_notice_for_invalid_grant(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;
		$previous_cookie = $_COOKIE;

		try {
			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			// 存在しないトークン（transient が無い）。
			$_SERVER['REQUEST_METHOD']    = 'POST';
			$_COOKIE['vkbm_resend_grant'] = 'never-issued-token';
			$_POST                        = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			$shortcodes->handle_form_submission();

			// サイレントに終わらず、リダイレクト（＝案内表示）が行われること。
			$this->assertNotNull( $shortcodes->last_redirect_url, '無効な許可でもサイレントに終わらずリダイレクトされること' );
			// 有効な未認証ユーザーと確認できていないため、許可は発行し直さないこと。
			$this->assertFalse( $shortcodes->has_resend_grant(), '無効な許可では新しい許可を発行し直さないこと' );
		} finally {
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * 安藤さんレビュー指摘（issue #507 PR）: 再送の IP レート制限は会員登録用の
	 * 'register' 枠と別バケットにする。再送で上限に達しても、新規登録は別枠のため
	 * 影響を受けないこと。
	 */
	public function test_resend_rate_limit_does_not_share_bucket_with_registration(): void {
		$repository          = new Settings_Repository();
		$original_settings   = $repository->get_settings();
		$original_can_register = get_option( 'users_can_register' );
		$previous_post       = $_POST;
		$previous_server     = $_SERVER;
		$previous_cookie     = $_COOKIE;

		$mail_filter = static function () {
			return true;
		};

		try {
			add_filter( 'pre_wp_mail', $mail_filter );

			// テスト用にユーザー登録を有効化する（既定は無効のため）。
			update_option( 'users_can_register', 1 );

			// IP単位のレート制限を検証可能にするため、固定のクライアントIPを与える。
			$_SERVER['REMOTE_ADDR'] = '203.0.113.77';

			$settings                                             = $original_settings;
			$settings['auth_rate_limit_enabled']                  = true;
			// 再送用の上限を1にして、1回で使い切れるようにする（登録用の設定値を流用する仕様）。
			$settings['auth_rate_limit_register_max']             = 1;
			$settings['registration_email_verification_enabled']  = true;
			$repository->update_settings( $settings );

			$user_id = $this->factory()->user->create(
				[
					'user_login' => 'resend_bucket_user',
					'user_email' => 'resend_bucket_user@example.com',
				]
			);
			update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );

			$grant_token = 'grant-token-bucket';
			set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $grant_token ), $user_id, 600 );

			$_SERVER['REQUEST_METHOD']    = 'POST';
			$_COOKIE['vkbm_resend_grant'] = $grant_token;
			$_POST                        = [
				'vkbm_resend_verification_form'  => '1',
				'vkbm_resend_verification_nonce' => wp_create_nonce( 'vkbm_resend_verification_form' ),
			];

			// 再送用IPレート制限（上限1）を使い切る。
			$shortcodes->handle_form_submission();

			$new_hash = (string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true );
			$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $new_hash ), '再送1回目は成功しトークンが発行されること' );

			// 同じIPからの新規登録が、再送のレート制限に巻き添えにされず成功すること
			// （'register' と 'resend_verification' が別バケットである証明）。
			$register_user_login = 'resend_bucket_register_user';
			$_POST = [
				'vkbm_registration_form'  => '1',
				'vkbm_registration_nonce' => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'              => $register_user_login,
				'user_email'              => 'resend_bucket_register_user@example.com',
				'user_pass'               => 'password123',
				'user_pass_confirm'       => 'password123',
				'kana_name'               => 'たろう',
				'phone_number'            => '090-0000-0000',
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			];

			$shortcodes->handle_form_submission();

			$registered_user = get_user_by( 'login', $register_user_login );
			$this->assertInstanceOf(
				\WP_User::class,
				$registered_user,
				'再送のレート制限を使い切っていても、別バケットの新規登録は成功すること'
			);

			wp_delete_user( $user_id );
			if ( $registered_user instanceof \WP_User ) {
				wp_delete_user( $registered_user->ID );
			}
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$repository->update_settings( $original_settings );
			update_option( 'users_can_register', $original_can_register );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			$_COOKIE = $previous_cookie;
		}
	}

	/**
	 * after_password_reset（issue #507 やること3）: '0' / 'manual' のユーザーが
	 * パスワード再設定を完了すると '1' に切り替わり、トークン系メタが削除されること。
	 */
	public function test_handle_after_password_reset_marks_verified(): void {
		$test_cases = [
			[
				'test_condition_name' => '状態 "0"（未認証）の場合 => "1" に切り替わる',
				'status'               => Email_Verification::STATUS_UNVERIFIED,
			],
			[
				'test_condition_name' => '状態 "manual"（手動承認）の場合 => "1" に切り替わる',
				'status'               => Email_Verification::STATUS_MANUAL,
			],
		];

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create();
			update_user_meta( $user_id, Email_Verification::META_STATUS, $case['status'] );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, hash( 'sha256', 'token' ) );
			update_user_meta( $user_id, Email_Verification::META_TOKEN_EXPIRES, time() + DAY_IN_SECONDS );

			$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$shortcodes = new Testable_Auth_Shortcodes( $service );
			$user       = get_userdata( $user_id );

			$shortcodes->handle_after_password_reset( $user, 'new-password' );

			$this->assertSame(
				Email_Verification::STATUS_VERIFIED,
				Email_Verification::get_status( $user_id ),
				$case['test_condition_name']
			);
			$this->assertSame(
				'',
				(string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * issue #512: get_login_error_message() は固定のホワイトリストに載っているコードだけ
	 * 文言を返し、それ以外（`<script>` 等の任意文字列を含む）は空文字を返して
	 * 「何も表示しない」を呼び出し側に伝えること。
	 */
	public function test_get_login_error_message_only_returns_whitelisted_codes(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		$test_cases = [
			[
				'test_condition_name' => 'auth_failed => 統一文言',
				'code'                => 'auth_failed',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => 'invalid_nonce => セキュリティチェック失敗の文言',
				'code'                => 'invalid_nonce',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => 'rate_limited => 試行回数超過の文言',
				'code'                => 'rate_limited',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => 'unverified_email => メール認証未完了の文言',
				'code'                => 'unverified_email',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => 'empty_username => ユーザー名未入力の文言',
				'code'                => 'empty_username',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => 'empty_password => パスワード未入力の文言',
				'code'                => 'empty_password',
				'expect_empty'        => false,
			],
			[
				'test_condition_name' => '一覧に無いコード（script混入）=> 空文字（何も表示しない）',
				'code'                => '<script>alert(1)</script>',
				'expect_empty'        => true,
			],
			[
				'test_condition_name' => '一覧に無いコード（未知の英数字）=> 空文字（何も表示しない）',
				'code'                => 'some_unknown_code',
				'expect_empty'        => true,
			],
		];

		foreach ( $test_cases as $case ) {
			$message = $shortcodes->get_login_error_message( $case['code'] );

			if ( $case['expect_empty'] ) {
				$this->assertSame( '', $message, $case['test_condition_name'] );
			} else {
				$this->assertNotSame( '', $message, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * issue #512: process_login_request() が確定させたログイン失敗コードを
	 * get_current_login_error_code() 経由で取得できること（Cookie を使わず
	 * render_block フィルタへ橋渡しするための入口）。
	 */
	public function test_get_current_login_error_code_reflects_process_login_request_result(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;

		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'login_error_code_user',
				'user_pass'  => 'CorrectPass123!',
			]
		);

		$test_cases = [
			[
				'test_condition_name' => 'nonce不正 => invalid_nonce',
				'post'                => [
					'vkbm_login_form'  => '1',
					'vkbm_login_nonce' => 'invalid-nonce',
					'log'              => 'login_error_code_user',
					'pwd'              => 'CorrectPass123!',
				],
				'expected_code'       => 'invalid_nonce',
			],
			[
				'test_condition_name' => 'ユーザー名未入力 => empty_username',
				'post'                => [
					'vkbm_login_form'  => '1',
					'vkbm_login_nonce' => null, // 実行時に発行する。
					'log'              => '',
					'pwd'              => 'CorrectPass123!',
				],
				'expected_code'       => 'empty_username',
			],
			[
				'test_condition_name' => 'パスワード不一致 => auth_failed（統一文言に丸める）',
				'post'                => [
					'vkbm_login_form'  => '1',
					'vkbm_login_nonce' => null,
					'log'              => 'login_error_code_user',
					'pwd'              => 'WrongPassword!!',
				],
				'expected_code'       => 'auth_failed',
			],
			[
				// issue #512 レビュー対応（安藤さん指摘）: 存在しないユーザー名でも
				// invalid_username のままではなく auth_failed に丸まること
				// （#194 のユーザー列挙対策を、コード経由でも崩さないことの確認）。
				'test_condition_name' => '存在しないユーザー名 => auth_failed（統一文言に丸める）',
				'post'                => [
					'vkbm_login_form'  => '1',
					'vkbm_login_nonce' => null,
					'log'              => 'no_such_user_xyz_512',
					'pwd'              => 'WhateverPass123!',
				],
				'expected_code'       => 'auth_failed',
			],
		];

		try {
			foreach ( $test_cases as $case ) {
				$post = $case['post'];
				if ( null === $post['vkbm_login_nonce'] ) {
					$post['vkbm_login_nonce'] = wp_create_nonce( 'vkbm_login_form' );
				}

				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_POST                     = $post;

				$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
				$shortcodes = new Auth_Shortcodes( $service );
				$shortcodes->handle_form_submission();

				$this->assertSame(
					$case['expected_code'],
					$shortcodes->get_current_login_error_code(),
					$case['test_condition_name']
				);
			}
		} finally {
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			wp_delete_user( $user_id );
		}
	}

	/**
	 * issue #512: 未実行・未失敗の Auth_Shortcodes インスタンスでは
	 * get_current_login_error_code() が空文字を返すこと（render_block フィルタが
	 * ログイン導線以外のリクエストで誤って何かを埋め込まないようにするための前提）。
	 */
	public function test_get_current_login_error_code_returns_empty_when_no_attempt_made(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		$this->assertSame( '', $shortcodes->get_current_login_error_code() );
	}

	/**
	 * issue #512: render_login_form() の error_code att が、ホワイトリストのコードなら
	 * 既存の文言をエラー欄に出し、ホワイトリスト外のコードでは何も出さないこと
	 * （REST コントローラー・render_block フィルタが同じ経路を通る）。
	 */
	public function test_render_login_form_error_code_attribute(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );

		$test_cases = [
			[
				'test_condition_name' => 'ホワイトリストのコード => 統一文言がエラー欄に出る',
				'error_code'          => 'auth_failed',
				'expect_error_box'    => true,
			],
			[
				'test_condition_name' => 'ホワイトリスト外のコード => 何も出ない',
				'error_code'          => '<script>alert(1)</script>',
				'expect_error_box'    => false,
			],
			[
				'test_condition_name' => '空文字 => 何も出ない（従来どおり）',
				'error_code'          => '',
				'expect_error_box'    => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$html = $shortcodes->render_login_form( [ 'error_code' => $case['error_code'] ] );

			if ( $case['expect_error_box'] ) {
				$this->assertStringContainsString( 'vkbm-alert__danger', $html, $case['test_condition_name'] );
				$this->assertStringContainsString(
					__( 'Username or password is incorrect.', 'vk-booking-manager' ),
					$html,
					$case['test_condition_name']
				);
			} else {
				$this->assertStringNotContainsString( 'vkbm-alert__danger', $html, $case['test_condition_name'] );
				$this->assertStringNotContainsString( '<script>', $html, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * issue #512 レビュー対応（安藤さん指摘）: 他プラグイン等が `authenticate` フィルタで
	 * 独自コード・文言の WP_Error を返しても、process_login_request() は
	 * 確認2（承認済み仕様）どおり統一文言（auth_failed）に丸めること。ユーザー名の
	 * 存在を推測させない（#194）方針が、想定外のエラー種別でも崩れないことの確認。
	 */
	public function test_process_login_request_unifies_custom_authenticate_filter_errors_to_auth_failed(): void {
		$previous_post   = $_POST;
		$previous_server = $_SERVER;

		$user_id = $this->factory()->user->create(
			[
				'user_login' => 'custom_authenticate_filter_user',
				'user_pass'  => 'CorrectPass123!',
			]
		);

		// wp_signon() の内部で呼ばれる authenticate フィルタへ、コア認証より後ろの
		// 優先度で割り込み、正しい認証情報でも独自コード・文言の WP_Error を返す
		// 「他プラグインが認証結果を上書きする」状況を再現する。
		$inject_custom_error = static function () {
			return new \WP_Error( 'some_third_party_plugin_error', 'このプラグイン独自のエラー文言です。' );
		};
		add_filter( 'authenticate', $inject_custom_error, 30 );

		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = [
				'vkbm_login_form'  => '1',
				'vkbm_login_nonce' => wp_create_nonce( 'vkbm_login_form' ),
				'log'              => 'custom_authenticate_filter_user',
				'pwd'              => 'CorrectPass123!',
			];

			$shortcodes = new Auth_Shortcodes( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
			$shortcodes->handle_form_submission();

			$this->assertSame( 'auth_failed', $shortcodes->get_current_login_error_code() );
			$this->assertStringNotContainsString(
				'このプラグイン独自のエラー文言です。',
				$shortcodes->get_login_error_message( $shortcodes->get_current_login_error_code() )
			);
		} finally {
			remove_filter( 'authenticate', $inject_custom_error, 30 );
			$_POST   = $previous_post;
			$_SERVER = $previous_server;
			wp_delete_user( $user_id );
		}
	}

	/**
	 * issue #512 レビュー対応（安藤さん指摘）: フォールバック案内リンク
	 * （wp-login.php + vkbm_native_login=1）経由のアクセスは、他の条件が
	 * すべて「転送する」を満たしていても redirect_wp_login_to_vkbm() が
	 * 転送しないこと（堂々巡り防止）。
	 *
	 * 実際に転送するケースは wp_safe_redirect() 直後に exit() が呼ばれ
	 * テストプロセスごと終了してしまうため検証できない（既存の
	 * test_redirect_wp_login_to_vkbm() と同じ制約）。このテストは
	 * 「転送条件を満たしていても、バイパスクエリがあれば転送されない
	 * （＝テストが正常に完了する）」ことを確認する。
	 */
	public function test_redirect_wp_login_to_vkbm_does_not_redirect_with_native_login_bypass(): void {
		wp_set_current_user( 0 );

		$page_with_block_id = $this->factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			]
		);

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$settings['membership_redirect_wp_login'] = true;
		$settings['reservation_page_url']           = get_permalink( $page_with_block_id );
		$repository->update_settings( $settings );

		$previous_request = $_REQUEST;
		// バイパスクエリ以外は、転送されるべき条件を満たしている。
		$_REQUEST['action']            = 'login';
		$_REQUEST['vkbm_native_login'] = '1';

		try {
			$shortcodes = new Auth_Shortcodes( new Settings_Service( $repository, new Settings_Sanitizer() ) );

			ob_start();
			$shortcodes->redirect_wp_login_to_vkbm();
			$output = ob_get_clean();

			// ここに到達できた時点で exit() が呼ばれなかった（＝転送しなかった）ことの証明になる。
			$this->assertEmpty( $output );
		} finally {
			$_REQUEST = $previous_request;
		}
	}

	/**
	 * issue #512 レビュー対応（安藤さん指摘）: render_native_login_bypass_field() は
	 * バイパスクエリがある時だけ隠しフィールドを出力し、無い時は何も出さないこと。
	 */
	public function test_render_native_login_bypass_field_only_outputs_when_flag_present(): void {
		$previous_request = $_REQUEST;

		$test_cases = [
			[
				'test_condition_name' => 'バイパスクエリあり => 隠しフィールドを出力する',
				'flag'                => '1',
				'expect_output'       => true,
			],
			[
				'test_condition_name' => 'バイパスクエリ無し => 何も出力しない',
				'flag'                => null,
				'expect_output'       => false,
			],
		];

		try {
			foreach ( $test_cases as $case ) {
				$_REQUEST = [];
				if ( null !== $case['flag'] ) {
					$_REQUEST['vkbm_native_login'] = $case['flag'];
				}

				$shortcodes = new Auth_Shortcodes( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );

				ob_start();
				$shortcodes->render_native_login_bypass_field();
				$output = ob_get_clean();

				if ( $case['expect_output'] ) {
					$this->assertStringContainsString( 'name="vkbm_native_login"', $output, $case['test_condition_name'] );
					$this->assertStringContainsString( 'type="hidden"', $output, $case['test_condition_name'] );
				} else {
					$this->assertSame( '', $output, $case['test_condition_name'] );
				}
			}
		} finally {
			$_REQUEST = $previous_request;
		}
	}
}
