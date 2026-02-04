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

use VKBookingManager\Blocks\Reservation_Block;
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
		$block = new Reservation_Block();
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
