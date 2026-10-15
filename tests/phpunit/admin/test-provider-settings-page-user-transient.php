<?php
/**
 * BM 設定画面の入力エラー時の一時保存（transient）がユーザーごとに分かれていることのテスト。
 *
 * 複数の管理者が同時に BM 設定を操作したとき、別の管理者が保存に失敗した入力内容や
 * 項目エラーが、自分の画面に復元されないことを確認する（#697）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use RuntimeException;
use WP_UnitTestCase;

/**
 * 一時保存のキーがユーザー単位であることを検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_User_Transient_Test extends WP_UnitTestCase {

	/**
	 * 入力エラー時の送信値の一時保存キーの接頭辞（末尾にユーザー ID を付ける）。
	 */
	private const OLD_INPUT_PREFIX = 'vkbm_provider_settings_previous_input_';

	/**
	 * 項目エラーの一時保存キーの接頭辞（末尾にユーザー ID を付ける）。
	 */
	private const FIELD_ERRORS_PREFIX = 'vkbm_provider_settings_field_errors_';

	/**
	 * ユーザー A の ID。
	 *
	 * @var int
	 */
	private $user_a = 0;

	/**
	 * ユーザー B の ID。
	 *
	 * @var int
	 */
	private $user_b = 0;

	/**
	 * 管理者を 2 人用意し、設定画面を描画できる状態にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->user_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_GET         = array( 'tab' => 'email' );
	}

	/**
	 * 保存済みの設定と一時保存を掃除し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		foreach ( array( $this->user_a, $this->user_b ) as $user_id ) {
			delete_transient( self::OLD_INPUT_PREFIX . $user_id );
			delete_transient( self::FIELD_ERRORS_PREFIX . $user_id );
		}
		delete_transient( 'vkbm_provider_settings_previous_input' );
		delete_transient( 'vkbm_provider_settings_field_errors' );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * ユーザー A が保存した送信値の一時保存を、ユーザー B の画面が読まないことを検証する。
	 */
	public function test_render_page_previous_input(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'mail_from_email' => 'saved@example.com' ) );
		set_transient( self::OLD_INPUT_PREFIX . $this->user_a, array( 'mail_from_email' => 'user-a-input@example.com' ), 30 );

		// ユーザー B の画面には、A の送信値が出ず、A の一時保存も消えない。
		wp_set_current_user( $this->user_b );
		$html_b = $this->render();
		$this->assertStringNotContainsString( 'user-a-input@example.com', $html_b, 'ユーザー B の画面にユーザー A の送信値が出ない' );
		$this->assertStringContainsString( 'saved@example.com', $html_b, 'ユーザー B の画面は保存済みの値で表示される' );
		$this->assertNotFalse( get_transient( self::OLD_INPUT_PREFIX . $this->user_a ), 'ユーザー B の表示でユーザー A の一時保存が消えない' );

		// ユーザー A の画面には送信値が復元され、一度表示したら消える。
		wp_set_current_user( $this->user_a );
		$html_a = $this->render();
		$this->assertStringContainsString( 'user-a-input@example.com', $html_a, 'ユーザー A の画面には自分の送信値が復元される' );
		$this->assertFalse( get_transient( self::OLD_INPUT_PREFIX . $this->user_a ), '表示後はユーザー A の一時保存が消える' );
	}

	/**
	 * ユーザー A の項目エラーを、ユーザー B の画面が表示しないことを検証する。
	 */
	public function test_render_page_field_errors(): void {
		set_transient( self::FIELD_ERRORS_PREFIX . $this->user_a, array( 'mail_from_email' => 'ユーザー A 向けの項目エラー' ), 30 );

		wp_set_current_user( $this->user_b );
		$html_b = $this->render();
		$this->assertStringNotContainsString( 'ユーザー A 向けの項目エラー', $html_b, 'ユーザー B の画面にユーザー A の項目エラーが出ない' );
		$this->assertNotFalse( get_transient( self::FIELD_ERRORS_PREFIX . $this->user_a ), 'ユーザー B の表示でユーザー A の項目エラーが消えない' );

		wp_set_current_user( $this->user_a );
		$html_a = $this->render();
		$this->assertStringContainsString( 'ユーザー A 向けの項目エラー', $html_a, 'ユーザー A の画面には自分の項目エラーが表示される' );
		$this->assertFalse( get_transient( self::FIELD_ERRORS_PREFIX . $this->user_a ), '表示後はユーザー A の項目エラーが消える' );
	}

	/**
	 * ユーザー A の保存失敗で作られた一時保存を、ユーザー B の画面が表示しないことを、実際の保存処理から検証する。
	 */
	public function test_handle_form_submission(): void {
		wp_set_current_user( $this->user_a );
		$this->submit_settings( array( 'mail_from_email' => 'not-an-email-from-user-a' ) );

		$this->assertNotFalse( get_transient( self::FIELD_ERRORS_PREFIX . $this->user_a ), 'ユーザー A の項目エラーがユーザー A のキーに保存される' );
		$this->assertNotFalse( get_transient( self::OLD_INPUT_PREFIX . $this->user_a ), 'ユーザー A の送信値がユーザー A のキーに保存される' );

		wp_set_current_user( $this->user_b );
		$html_b = $this->render();
		$this->assertStringNotContainsString( 'not-an-email-from-user-a', $html_b, 'ユーザー B の画面にユーザー A の送信値が出ない' );
		$this->assertStringNotContainsString( 'vkbm-mail-from-email-error', $html_b, 'ユーザー B の画面にユーザー A の項目エラーが出ない' );
	}

	/**
	 * 保存に成功したとき、ユーザー A の一時保存（送信値・項目エラー）が消えることを検証する。
	 */
	public function test_handle_form_submission_success(): void {
		set_transient( self::OLD_INPUT_PREFIX . $this->user_a, array( 'mail_from_email' => 'old@example.com' ), 30 );
		set_transient( self::FIELD_ERRORS_PREFIX . $this->user_a, array( 'mail_from_email' => '古い項目エラー' ), 30 );

		wp_set_current_user( $this->user_a );
		$this->submit_settings( array( 'mail_from_email' => 'valid@example.com' ) );

		$this->assertFalse( get_transient( self::OLD_INPUT_PREFIX . $this->user_a ), '保存成功後はユーザー A の送信値の一時保存が消える' );
		$this->assertFalse( get_transient( self::FIELD_ERRORS_PREFIX . $this->user_a ), '保存成功後はユーザー A の項目エラーの一時保存が消える' );
	}

	/**
	 * 設定の保存処理を実行する（リダイレクトは例外で止める）。
	 *
	 * @param array<string, mixed> $payload 送信する設定値。
	 */
	private function submit_settings( array $payload ): void {
		$_POST    = array(
			'vkbm_provider_settings_nonce' => wp_create_nonce( 'vkbm_provider_settings_save' ),
			'vkbm_provider_settings'       => $payload,
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() が参照するリクエストをテスト用に同期する。

		$intercept_redirect = static function (): void {
			throw new RuntimeException( 'wp_redirect intercepted for test' );
		};
		add_filter( 'wp_redirect', $intercept_redirect );
		try {
			$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
			$page->handle_form_submission();
			$this->fail( 'handle_form_submission() はリダイレクトのため wp_redirect フックで止まる想定' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted for test', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $intercept_redirect );
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	/**
	 * 設定画面を描画して HTML を返す。
	 *
	 * @return string
	 */
	private function render(): string {
		$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
		ob_start();
		$page->render_page();
		return (string) ob_get_clean();
	}
}
