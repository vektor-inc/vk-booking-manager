<?php
/**
 * 予約ブロックがページに埋め込む事業者の電話番号（#1154）のテスト.
 *
 * window.vkbmReservationConfig の providerPhone を確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * window.vkbmReservationConfig の providerPhone を検証するテスト.
 *
 * お客様の通信がセキュリティ系プラグインに止められると provider-settings の REST も読めないため、
 * 電話での問い合わせの案内に添える番号はページに埋め込む。
 *
 * @group blocks
 */
class Reservation_Block_Provider_Phone_Config_Test extends WP_UnitTestCase {
	/**
	 * テスト後に設定を消す（他のテストへ持ち越さない）。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * maybe_enqueue_reservation_config() が、事業者の電話番号を埋め込む。
	 */
	public function test_maybe_enqueue_reservation_config(): void {
		$test_data = array(
			array(
				'test_condition_name' => '電話番号を保存 => そのまま埋め込む',
				'settings'            => array( 'provider_phone' => '03-1234-5678' ),
				'expected'            => '03-1234-5678',
			),
			array(
				'test_condition_name' => '前後に空白 => 空白を除いて埋め込む',
				'settings'            => array( 'provider_phone' => '  0120-000-000 ' ),
				'expected'            => '0120-000-000',
			),
			array(
				'test_condition_name' => '未設定 => 空文字',
				'settings'            => null,
				'expected'            => '',
			),
		);

		foreach ( $test_data as $case ) {
			// 電話番号を保存（null は未保存の状態にする）。
			if ( null === $case['settings'] ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			}

			$config = $this->get_reservation_config();

			$this->assertSame( $case['expected'], $config['providerPhone'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * 予約ブロックを含む投稿を描画対象にして、出力された window.vkbmReservationConfig をデコードして返す。
	 *
	 * @return array<string, mixed> デコード済みの設定配列.
	 */
	private function get_reservation_config(): array {
		global $post;

		// 前のケースのインラインスクリプトが残らないよう、毎回まっさらな WP_Scripts を使う。
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ケースごとにスクリプトレジストリを初期化するため。

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);
		$post    = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ブロック判定対象のグローバル投稿を差し替えるため。

		// 予約ブロックを組み立て、フロント用のスクリプトを読み込む処理を呼ぶ。
		$settings_service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$block            = new Reservation_Block( new Auth_Shortcodes( $settings_service ) );
		$block->register_block();
		$block->maybe_enqueue_menu_loop_styles();

		// インラインスクリプトから JSON 部分を取り出す。
		$inline_scripts = wp_scripts()->get_data( 'vkbm-reservation-config', 'before' );
		$this->assertIsArray( $inline_scripts );

		$this->assertSame(
			1,
			preg_match( '/window\.vkbmReservationConfig\s*=\s*(\{.*\});/s', implode( '', $inline_scripts ), $matches )
		);

		$config = json_decode( $matches[1], true );
		$this->assertIsArray( $config );

		return $config;
	}
}
