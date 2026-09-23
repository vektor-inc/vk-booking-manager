<?php
/**
 * フロント予約ブロックが出力する REST フォールバック用ルート URL（issue #489）のテスト.
 *
 * パーマリンク未保存サイト（サーバー側の .htaccess に WordPress の書き換えルールが
 * 反映されていない環境）でも予約ページの REST 通信が詰まないよう、
 * window.vkbmReservationConfig に restRoot（通常のルート）と restFallbackRoot
 * （?rest_route= 形式のフォールバック用ルート）を出力する。パーマリンク構造が
 * 空（基本パーマリンク）と `/%postname%/`（構造あり）の両方で値を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Blocks\Reservation_Block;
use WP_UnitTestCase;

/**
 * window.vkbmReservationConfig の restRoot / restFallbackRoot を検証するテスト.
 *
 * @group blocks
 */
class Reservation_Block_Rest_Fallback_Config_Test extends WP_UnitTestCase {
	/**
	 * パーマリンク構造が空（基本パーマリンク）のとき、restRoot と restFallbackRoot が
	 * どちらも ?rest_route= 形式で、同じ値になることを確認する。
	 *
	 * 基本パーマリンクでは get_rest_url() 自体が既に ?rest_route= 形式を返すため、
	 * フロント側で両者を比較してフォールバック不要と判断できる前提を保証する。
	 */
	public function test_config_when_permalinks_are_plain(): void {
		$this->set_permalink_structure( '' );

		$config = $this->get_reservation_config();

		$this->assertArrayHasKey( 'restRoot', $config );
		$this->assertArrayHasKey( 'restFallbackRoot', $config );
		$this->assertStringContainsString( 'rest_route=', $config['restRoot'] );
		$this->assertSame( $config['restRoot'], $config['restFallbackRoot'] );
		$this->assert_permalink_settings_url( $config );
	}

	/**
	 * パーマリンク構造が /%postname%/（構造あり）のとき、restRoot は通常の
	 * /wp-json/ 形式、restFallbackRoot はコアの非パーマリンク分岐と同じ
	 * ?rest_route= 形式になり、両者が異なる値になることを確認する。
	 */
	public function test_config_when_permalinks_are_pretty(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$config = $this->get_reservation_config();

		$this->assertArrayHasKey( 'restRoot', $config );
		$this->assertArrayHasKey( 'restFallbackRoot', $config );
		$this->assertStringNotContainsString( 'rest_route=', $config['restRoot'] );
		$this->assertStringContainsString( '/wp-json/', $config['restRoot'] );
		$this->assertStringContainsString( 'rest_route=', $config['restFallbackRoot'] );
		$this->assertStringContainsString( 'index.php', $config['restFallbackRoot'] );
		$this->assertNotSame( $config['restRoot'], $config['restFallbackRoot'] );
		$this->assert_permalink_settings_url( $config );
	}

	/**
	 * permalinkSettingsUrl（管理者向け invalid_json メッセージのリンク先。
	 * Reservation_Block::maybe_enqueue_reservation_config() が
	 * esc_url_raw( admin_url( 'options-permalink.php' ) ) を渡す）が
	 * 正しく出力されていることを確認する。
	 *
	 * @param array<string, mixed> $config デコード済みの設定配列.
	 */
	private function assert_permalink_settings_url( array $config ): void {
		$this->assertArrayHasKey( 'permalinkSettingsUrl', $config );
		$this->assertStringContainsString( 'options-permalink.php', $config['permalinkSettingsUrl'] );
		$this->assertSame(
			admin_url( 'options-permalink.php' ),
			$config['permalinkSettingsUrl']
		);
	}

	/**
	 * 予約ブロックを含む投稿をレンダリング対象の投稿として設定し、
	 * Reservation_Block::maybe_enqueue_menu_loop_styles() を呼び出した上で、
	 * 出力されたインライン JSON（window.vkbmReservationConfig）をデコードして返す。
	 *
	 * @return array<string, mixed> デコード済みの設定配列.
	 */
	private function get_reservation_config(): array {
		global $post;

		// 他テストで登録済みのスクリプトハンドルが残っていると、wp_add_inline_script() の
		// 'before' データが累積し、今回出力した分を正しく取り出せなくなるため、
		// テストごとにまっさらな WP_Scripts を使う。
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストごとにスクリプトレジストリを初期化するため。

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);
		$post    = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ブロック判定対象のグローバル投稿を差し替えるため。

		$block = new Reservation_Block();
		$block->register_block();
		$block->maybe_enqueue_menu_loop_styles();

		$inline_scripts = wp_scripts()->get_data( 'vkbm-reservation-config', 'before' );
		$this->assertIsArray( $inline_scripts );
		$this->assertNotEmpty( $inline_scripts );

		$raw = implode( '', $inline_scripts );

		$this->assertSame(
			1,
			preg_match( '/window\.vkbmReservationConfig\s*=\s*(\{.*\});/s', $raw, $matches ),
			'window.vkbmReservationConfig のインラインスクリプトが期待した形式で出力されていること。'
		);

		$config = json_decode( $matches[1], true );
		$this->assertIsArray( $config );

		return $config;
	}
}
