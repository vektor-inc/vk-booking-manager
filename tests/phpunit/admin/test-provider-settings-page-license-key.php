<?php
/**
 * 基本設定画面のライセンスキー表示・保存処理の単体テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use RuntimeException;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * ライセンスキーを画面へ露出せず、安全に更新・削除できることを検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_License_Key_Test extends WP_UnitTestCase {

	private const OPTION_NAME = 'vk-booking-manager-pro-license-key';

	/**
	 * 管理者でログインし、テスト間で共有される入力値を初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wp_settings_errors'] );
	}

	/**
	 * ライセンスキーとリクエスト値を削除する。
	 */
	protected function tearDown(): void {
		delete_option( self::OPTION_NAME );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wp_settings_errors'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 保存済みキーがHTMLへ出力されず、パスワード入力欄と案内だけが出ることを検証する。
	 */
	public function test_render_page(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'ライセンスキー設定は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '保存済みの場合はキーを露出せず、保存状態と削除操作を表示する',
				'saved_key'           => 'secret-license-key-459',
				'expected_saved_ui'   => true,
			),
			array(
				'test_condition_name' => '未保存の場合は保存状態・削除操作・保存済み用の関連付けを表示しない',
				'saved_key'           => '',
				'expected_saved_ui'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( self::OPTION_NAME, $case['saved_key'] );
			$_GET['tab'] = 'license';

			ob_start();
			$this->create_page()->render_page();
			$output = (string) ob_get_clean();

			$this->assertMatchesRegularExpression( '/<input[^>]+type="password"[^>]+id="vkbm-license-key"/', $output, $case['test_condition_name'] );
			$this->assertMatchesRegularExpression( '/id="vkbm-license-key"[^>]+value=""/', $output, $case['test_condition_name'] );
			$this->assertStringContainsString( 'autocomplete="new-password"', $output, $case['test_condition_name'] );
			if ( $case['expected_saved_ui'] ) {
				$this->assertStringNotContainsString( $case['saved_key'], $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'aria-describedby="vkbm-license-key-status vkbm-license-key-description"', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'License key is saved.', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'Leave empty if you do not want to change it.', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'Delete the saved license key', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( '<label for="vkbm-delete-license-key">', $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( 'aria-describedby="vkbm-license-key-status vkbm-license-key-description"', $output, $case['test_condition_name'] );
				$this->assertStringNotContainsString( 'License key is saved.', $output, $case['test_condition_name'] );
				$this->assertStringNotContainsString( 'Delete the saved license key', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 未入力・新規入力・削除指定に応じて保存済みキーが期待どおり変化することを検証する。
	 */
	public function test_handle_form_submission(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'ライセンスキー設定は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name'      => 'キーが空欄の場合は保存済みキーを維持する',
				'payload'                  => array( 'license_key' => '' ),
				'expected'                 => 'saved-license-key',
				'expected_deleted_message' => false,
			),
			array(
				'test_condition_name'      => 'キーがPOSTに無い場合は保存済みキーを維持する',
				'payload'                  => array(),
				'expected'                 => 'saved-license-key',
				'expected_deleted_message' => false,
			),
			array(
				'test_condition_name'      => '新しいキーが入力された場合は保存済みキーを更新する',
				'payload'                  => array( 'license_key' => 'new-license-key' ),
				'expected'                 => 'new-license-key',
				'expected_deleted_message' => false,
			),
			array(
				'test_condition_name'      => '削除指定の場合は保存済みキーを削除する',
				'payload'                  => array( 'delete_license_key' => '1' ),
				'expected'                 => false,
				'expected_deleted_message' => true,
			),
			array(
				'test_condition_name'      => '削除指定と新しいキーが同時にある場合は削除を優先する',
				'payload'                  => array(
					'license_key'        => 'new-license-key',
					'delete_license_key' => '1',
				),
				'expected'                 => false,
				'expected_deleted_message' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( self::OPTION_NAME, 'saved-license-key' );
			$_POST      = array(
				'vkbm_provider_settings_nonce' => wp_create_nonce( 'vkbm_provider_settings_save' ),
				'vkbm_provider_settings'       => $case['payload'],
			);
			$_REQUEST   = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() が参照するリクエストをテスト用に同期する。
			$redirected = false;

			// リダイレクト直前に例外を投げ、exit() に到達させず保存結果を検証する。
			$intercept_redirect = static function (): void {
				throw new RuntimeException( 'wp_redirect intercepted for test' );
			};
			add_filter( 'wp_redirect', $intercept_redirect );
			try {
				$this->create_page()->handle_form_submission();
			} catch ( RuntimeException $exception ) {
				$redirected = true;
				$this->assertSame( 'wp_redirect intercepted for test', $exception->getMessage() );
			} finally {
				remove_filter( 'wp_redirect', $intercept_redirect );
			}

			$this->assertTrue( $redirected, $case['test_condition_name'] . ': リダイレクトまで到達する' );
			$this->assertSame( $case['expected'], get_option( self::OPTION_NAME, false ), $case['test_condition_name'] );
			$messages = wp_list_pluck( get_settings_errors(), 'message' );
			if ( $case['expected_deleted_message'] ) {
				$this->assertContains( 'License key deleted.', $messages, $case['test_condition_name'] );
			} else {
				$this->assertNotContains( 'License key deleted.', $messages, $case['test_condition_name'] );
			}
			unset( $GLOBALS['wp_settings_errors'] );
		}
	}

	/**
	 * 権限不足のユーザーが削除を指定しても保存済みキーを変更できないことを検証する。
	 */
	public function test_handle_form_submission_without_permission(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'ライセンスキー設定は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$editor_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_user_id );
		$editor = wp_get_current_user();
		$editor->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		$this->assertTrue( current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ), '編集者は設定画面の権限を持つ' );
		$this->assertFalse( current_user_can( 'manage_options' ), '編集者はライセンスキーを変更する権限を持たない' );
		update_option( self::OPTION_NAME, 'saved-license-key' );
		$_POST      = array(
			'vkbm_provider_settings_nonce' => wp_create_nonce( 'vkbm_provider_settings_save' ),
			'vkbm_provider_settings'       => array(
				'license_key'        => 'new-license-key',
				'delete_license_key' => '1',
			),
		);
		$_REQUEST   = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 権限チェック前のテスト入力をリクエスト全体へ同期する。
		$redirected = false;

		// 設定保存後のリダイレクト直前に例外を投げ、exit() を避けながら到達を検証する。
		$intercept_redirect = static function (): void {
			throw new RuntimeException( 'wp_redirect intercepted for test' );
		};
		add_filter( 'wp_redirect', $intercept_redirect );
		try {
			$this->create_page( Capabilities::MANAGE_PROVIDER_SETTINGS )->handle_form_submission();
		} catch ( RuntimeException $exception ) {
			$redirected = true;
			$this->assertSame( 'wp_redirect intercepted for test', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $intercept_redirect );
			$editor->remove_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		}

		$this->assertTrue( $redirected, '設定保存処理がリダイレクトまで到達する' );
		$this->assertSame( 'saved-license-key', get_option( self::OPTION_NAME, false ), 'manage_options を持たない編集者は保存済みキーを削除・更新できない' );
	}

	/**
	 * 実際の依存関係で設定画面を生成する。
	 *
	 * @param string $capability 設定画面へアクセスするための権限。
	 * @return Provider_Settings_Page 設定画面。
	 */
	private function create_page( string $capability = 'manage_options' ): Provider_Settings_Page {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		return new Provider_Settings_Page( $service, $capability );
	}
}
