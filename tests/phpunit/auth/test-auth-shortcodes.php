<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * @group auth
 */
class Auth_Shortcodes_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( 'ja' );
		}
		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( 'vk-booking-manager' );
		}
		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( file_exists( $mo_path ) && function_exists( 'load_textdomain' ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( 'vk-booking-manager' );
		}
		if ( function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		parent::tearDown();
	}

	public function test_japanese_translations_are_loaded(): void {
		if ( getenv( 'VK_BOOKING_MANAGER_SKIP_I18N_TESTS' ) ) {
			$this->markTestSkipped( 'Skipping i18n tests for free distribution.' );
		}

		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		$this->assertFileExists( $mo_path );

		$mo = new \MO();
		$this->assertTrue( $mo->import_from_file( $mo_path ) );

		$translated = $mo->translate( 'Please enter the same password twice.' );

		$this->assertSame( '同じパスワードを2回入力してください。', $translated );
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
		$shortcodes = new Auth_Shortcodes( $service );

		// Run the handler to populate profile errors. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( $shortcodes, 'profile_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'New passwords do not match.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

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
		$shortcodes = new Auth_Shortcodes( $service );

		// Run the handler to populate profile errors. / 送信処理でエラーを発生させる。
		$shortcodes->handle_form_submission();

		// Access the internal error bag to confirm the message. / 反射で内部エラーを検証。
		$property = new \ReflectionProperty( $shortcodes, 'profile_errors' );
		$property->setAccessible( true );
		$errors = $property->getValue( $shortcodes );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$expected = __( 'Please enter a password of 8 characters or more.', 'vk-booking-manager' );
		$this->assertContains( $expected, $errors->get_error_messages() );

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
}
