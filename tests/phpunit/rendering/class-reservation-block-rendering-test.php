<?php
/**
 * フロント予約ブロックの描画スモークテスト。
 *
 * ブロックのレンダリングが致命的エラーなく完了するかを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 予約ブロックが致命的エラーなしで描画できることを保証するテスト。
 */
class Reservation_Block_Rendering_Test extends WP_UnitTestCase {
	/**
	 * 予約ブロックをレンダリングし、致命的エラーの痕跡を確認する。
	 */
	public function test_reservation_block_renders_without_fatal_error(): void {
		// テスト環境でブロックを登録してレンダリング可能にする.
		// issue #512: render_block フィルタ（ログイン失敗フォールバック）から参照するため
		// コンストラクタで必須になった依存。
		$settings_service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes  = new Auth_Shortcodes( $settings_service );

		$block = new Reservation_Block( $auth_shortcodes );
		$block->register_block();

		// ショートコード相当のブロックコメントをレンダリングする.
		$output = do_blocks( '<!-- wp:vk-booking-manager/reservation /-->' );

		// 出力が文字列で返り、エラー出力が混在していないことを確認する.
		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );
		$this->assertStringNotContainsString( 'Allowed memory size', $output );
		$this->assertStringNotContainsString( 'Parse error', $output );
	}
}
