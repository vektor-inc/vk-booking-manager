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
	/**
	 * issue #516: `vkbm_registration_errors` Cookie の廃止に伴い、会員登録エラーは
	 * サーバー側 transient と、REST の `registration_error_key` パラメータ（同一
	 * リクエスト内で発行されたランダムトークン）で受け渡す。
	 */
	public function test_registration_form_response_includes_errors_and_no_cache_header(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		// Seed a transient to emulate a failed registration. / 失敗時のtransientを再現。
		$token         = 'test-rest-registration-token-0001';
		$transient_key = 'vkbm_registration_error_' . hash( 'sha256', $token );
		$payload       = [
			'messages' => [ 'このメールアドレスは既に登録済みです。' ],
			'posted'   => [
				'user_email' => 'sample@example.com',
			],
			'raw'      => [],
		];

		set_transient( $transient_key, $payload, 120 );
		unset( $_COOKIE['vkbm_registration_errors'] );

		// Build controller with real shortcodes service. / 実際の依存を使ってRESTレスポンスを生成。
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$shortcodes = new Auth_Shortcodes( $service );
		$controller = new Auth_Form_Controller( $shortcodes );

		// Request the register form via REST. / REST経由で登録フォームを取得。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/auth-form' );
		$request->set_param( 'type', 'register' );
		$request->set_param( 'redirect', home_url( '/' ) );
		$request->set_param( 'registration_error_key', $token );

		$response = $controller->get_form( $request );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		// Ensure no-cache is set and the error message is in HTML. / no-cacheとエラー表示を検証。
		$this->assertNotEmpty( $headers['Cache-Control'] ?? '' );
		$this->assertStringContainsString( 'no-store', (string) $headers['Cache-Control'] );
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'このメールアドレスは既に登録済みです。', (string) ( $data['html'] ?? '' ) );

		// 完了条件1: この経路でも vkbm_registration_errors Cookie は一切発行されない。
		$this->assertArrayNotHasKey( 'vkbm_registration_errors', $_COOKIE );

		// Cleanup to keep global state isolated. / グローバル状態の後始末。
		delete_transient( $transient_key );
		update_option( 'users_can_register', $original_registration );
	}

	/**
	 * issue #516: `registration_error_key` が不一致・省略のときは何も復元されず、
	 * かつどちらの場合も Cookie は発行されないことを確認する。
	 */
	public function test_registration_form_response_ignores_wrong_or_missing_key(): void {
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		$token         = 'test-rest-registration-token-0002';
		$transient_key = 'vkbm_registration_error_' . hash( 'sha256', $token );
		$payload       = [
			'messages' => [ '他人には見えないはずのエラーメッセージ' ],
			'posted'   => [
				'user_email' => 'victim@example.com',
			],
			'raw'      => [],
		];

		set_transient( $transient_key, $payload, 120 );
		unset( $_COOKIE['vkbm_registration_errors'] );

		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$controller = new Auth_Form_Controller( new Auth_Shortcodes( $service ) );

		$test_cases = [
			[
				'test_condition_name' => 'registration_error_key が不一致（推測） => 何も復元されない',
				'key'                 => 'guessed-wrong-token',
			],
			[
				'test_condition_name' => 'registration_error_key を省略 => 何も復元されない（従来どおり）',
				'key'                 => '',
			],
		];

		foreach ( $test_cases as $case ) {
			$request = new WP_REST_Request( 'GET', '/vkbm/v1/auth-form' );
			$request->set_param( 'type', 'register' );
			$request->set_param( 'redirect', home_url( '/' ) );
			if ( '' !== $case['key'] ) {
				$request->set_param( 'registration_error_key', $case['key'] );
			}

			$response = $controller->get_form( $request );
			$html     = (string) ( $response->get_data()['html'] ?? '' );

			$this->assertStringNotContainsString( '他人には見えないはずのエラーメッセージ', $html, $case['test_condition_name'] );
			$this->assertStringNotContainsString( 'victim@example.com', $html, $case['test_condition_name'] );
			$this->assertArrayNotHasKey( 'vkbm_registration_errors', $_COOKIE, $case['test_condition_name'] );
		}

		delete_transient( $transient_key );
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

	/**
	 * issue #516 安藤さんレビュー指摘（LOW）: `registration_error_key` は空文字、または
	 * `wp_generate_password( 32, false, false )` の出力形式（半角英数字32文字）だけを
	 * 許可し、それ以外は DB（get_transient()）に触れる前に弾くこと。
	 */
	public function test_validate_registration_error_key_accepts_only_empty_or_32_char_alnum_token(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$controller = new Auth_Form_Controller( new Auth_Shortcodes( $service ) );

		$valid_32_char_token = str_repeat( 'a1B2', 8 ); // 4文字 × 8 = 32文字ちょうど。

		$test_cases = [
			[
				'test_condition_name' => '空文字（未指定を模す） => 許可',
				'value'               => '',
				'expected'            => true,
			],
			[
				'test_condition_name' => '英数字32文字 => 許可',
				'value'               => $valid_32_char_token,
				'expected'            => true,
			],
			[
				'test_condition_name' => 'ハイフンを含む（旧テスト用トークンのような値） => 拒否',
				'value'               => 'test-rest-registration-token-0001',
				'expected'            => false,
			],
			[
				'test_condition_name' => '31文字（1文字短い） => 拒否',
				'value'               => substr( $valid_32_char_token, 0, 31 ),
				'expected'            => false,
			],
			[
				'test_condition_name' => '33文字（1文字長い） => 拒否',
				'value'               => $valid_32_char_token . 'y',
				'expected'            => false,
			],
			[
				'test_condition_name' => 'SQLインジェクションを模した文字列 => 拒否',
				'value'               => "' OR '1'='1",
				'expected'            => false,
			],
		];

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$controller->validate_registration_error_key( $case['value'] ),
				$case['test_condition_name']
			);
		}

		// 32文字であることのテスト前提（テストコード自体の検算）。
		$this->assertSame( 32, strlen( $valid_32_char_token ) );
	}
}
