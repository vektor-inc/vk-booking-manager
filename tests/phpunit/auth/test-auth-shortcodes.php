<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use VKBookingManager\Auth\Auth_Shortcodes;
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
}
