<?php
/**
 * 管理画面の描画スモークテスト。
 *
 * 画面を実際に描画して、致命的エラーが発生していないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 管理画面が致命的エラーなしで描画できることを保証するテスト群。
 */
class Admin_Page_Rendering_Test extends WP_UnitTestCase {
	/**
	 * 管理者ユーザーID。
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * テスト前に管理者ユーザーを用意し、管理画面の描画権限を満たす。
	 */
	protected function setUp(): void {
		parent::setUp();
		// 管理者ユーザーでログイン状態を作る.
		$this->admin_user_id = $this->factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * テスト終了後にユーザー状態をクリアする。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * シフト・予約表ページの描画が落ちないことを確認する。
	 */
	public function test_shift_dashboard_renders_without_fatal_error(): void {
		// 画面クラスを直接作って描画を行う.
		$page   = new Shift_Dashboard_Page();
		$output = $this->capture_render_output(
			static function () use ( $page ): void {
				$page->render_page();
			}
		);

		// 期待するDOMクラスが含まれているか確認する.
		$this->assertStringContainsString( 'vkbm-shift-dashboard', $output );
		// 致命的エラーの痕跡が出力に含まれていないことを確認する.
		$this->assertNoFatalOutput( $output );
	}

	/**
	 * 基本設定ページの描画が落ちないことを確認する。
	 */
	public function test_provider_settings_renders_without_fatal_error(): void {
		// 設定ページはサービス経由で依存を組み立てる必要がある.
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$page    = new Provider_Settings_Page( $service );
		$output  = $this->capture_render_output(
			static function () use ( $page ): void {
				$page->render_page();
			}
		);

		// 期待するDOMクラスが含まれているか確認する.
		$this->assertStringContainsString( 'vkbm-provider-settings', $output );
		// 致命的エラーの痕跡が出力に含まれていないことを確認する.
		$this->assertNoFatalOutput( $output );
	}

	/**
	 * Echo を伴う描画処理の出力をバッファで捕捉する。
	 *
	 * @param callable $callback 描画コールバック.
	 * @return string
	 */
	private function capture_render_output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * 出力に致命的エラーの文字列が含まれないことを確認する。
	 *
	 * @param string $output 描画結果のHTML.
	 */
	private function assertNoFatalOutput( string $output ): void {
		// 典型的なPHPエラー出力を検出対象にする.
		$this->assertStringNotContainsString( 'Fatal error', $output );
		$this->assertStringNotContainsString( 'Allowed memory size', $output );
		$this->assertStringNotContainsString( 'Parse error', $output );
	}
}
