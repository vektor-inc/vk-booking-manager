<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Auth_Form_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group rest
 */
class Auth_Form_Controller_Test extends WP_UnitTestCase {
	public function test_registration_form_response_includes_errors_and_no_cache_header(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Seed an error cookie to emulate a failed registration. / 失敗時のcookieを再現。
		$payload = [
			'messages' => [ 'このメールアドレスは既に登録済みです。' ],
			'posted'   => [
				'user_email' => 'sample@example.com',
			],
			'raw'      => [],
		];

		$_COOKIE['vkbm_registration_errors'] = rawurlencode( wp_json_encode( $payload ) );

		// Build controller with real shortcodes service. / 実際の依存を使ってRESTレスポンスを生成。
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );
		$controller = new Auth_Form_Controller( $shortcodes );

		// Request the register form via REST. / REST経由で登録フォームを取得。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/auth-form' );
		$request->set_param( 'type', 'register' );
		$request->set_param( 'redirect', home_url( '/' ) );

		$response = $controller->get_form( $request );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		// Ensure no-cache is set and the error message is in HTML. / no-cacheとエラー表示を検証。
		$this->assertNotEmpty( $headers['Cache-Control'] ?? '' );
		$this->assertStringContainsString( 'no-store', (string) $headers['Cache-Control'] );
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'このメールアドレスは既に登録済みです。', (string) ( $data['html'] ?? '' ) );

		// Cleanup to keep global state isolated. / グローバル状態の後始末。
		unset( $_COOKIE['vkbm_registration_errors'] );
		update_option( 'users_can_register', $original_registration );
	}

	/**
	 * issue #512: `vkbm_login_error` Cookie の廃止に伴い、ログイン失敗コードは REST の
	 * `error` パラメータで受け渡す。ホワイトリストのコードは既存の統一文言に変換され、
	 * 一覧に無いコードでは何も表示されないこと（`vkbm_login_error` Cookie 自体が
	 * 発行されないことも合わせて確認する）。
	 */
	public function test_login_form_response_converts_error_param_and_ignores_unknown_codes(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );
		$controller = new Auth_Form_Controller( $shortcodes );

		$test_cases = [
			[
				'test_condition_name' => 'ホワイトリストのコード（auth_failed）=> 統一文言が出る',
				'error_param'         => 'auth_failed',
				'expect_message'      => true,
			],
			[
				'test_condition_name' => 'ホワイトリスト外のコード（未知の値）=> 何も出ない',
				'error_param'         => 'not_a_real_code',
				'expect_message'      => false,
			],
			[
				'test_condition_name' => 'error パラメータ省略時 => 何も出ない（従来どおり）',
				'error_param'         => '',
				'expect_message'      => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$request = new WP_REST_Request( 'GET', '/vkbm/v1/auth-form' );
			$request->set_param( 'type', 'login' );
			$request->set_param( 'redirect', home_url( '/' ) );
			if ( '' !== $case['error_param'] ) {
				$request->set_param( 'error', $case['error_param'] );
			}

			$response = $controller->get_form( $request );
			$html     = (string) ( $response->get_data()['html'] ?? '' );

			if ( $case['expect_message'] ) {
				$this->assertStringContainsString(
					__( 'Username or password is incorrect.', 'vk-booking-manager' ),
					$html,
					$case['test_condition_name']
				);
			} else {
				$this->assertStringNotContainsString( 'vkbm-alert__danger', $html, $case['test_condition_name'] );
			}

			// #512 の対応前まで発行されていた一時 Cookie が、この経路では一切
			// 発行されないことを確認する。
			$this->assertArrayNotHasKey( 'vkbm_login_error', $_COOKIE, $case['test_condition_name'] );
		}
	}
}
