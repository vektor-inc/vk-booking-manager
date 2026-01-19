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
}
