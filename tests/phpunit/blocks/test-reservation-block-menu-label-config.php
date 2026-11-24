<?php
/**
 * 予約ブロックがページに埋め込む「メニューの呼び方」（#1128）のテスト.
 *
 * window.vkbmReservationConfig の menuLabel / menuLabelPlural を確かめる。
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
 * window.vkbmReservationConfig の menuLabel / menuLabelPlural を検証するテスト.
 *
 * @group blocks
 */
class Reservation_Block_Menu_Label_Config_Test extends WP_UnitTestCase {
	/**
	 * テスト後に設定を消す（他のテストへ持ち越さない）。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * maybe_enqueue_reservation_config() が、呼び方の保存値ごとに埋め込む値を確かめる。
	 */
	public function test_maybe_enqueue_reservation_config(): void {
		// 未保存のときは REST と同じく翻訳済みの既定値を埋め込むため、実行時のサイトの言語で期待値を決める。
		$default_label = __( 'Menu', 'vk-booking-manager' );
		$this->assertContains( $default_label, array( 'Menu', 'メニュー' ) );

		$test_data = array(
			array(
				'test_condition_name' => '未保存 => 翻訳済みの既定値（Menu / メニュー）を単数形・複数形とも埋め込む',
				'settings'            => null,
				'expected'            => array(
					'menuLabel'       => $default_label,
					'menuLabelPlural' => $default_label,
				),
			),
			array(
				'test_condition_name' => '単数形だけ保存 => 複数形は単数形にそろえる',
				'settings'            => array( 'menu_label' => 'プラン' ),
				'expected'            => array(
					'menuLabel'       => 'プラン',
					'menuLabelPlural' => 'プラン',
				),
			),
			array(
				'test_condition_name' => '単数形・複数形を保存 => それぞれを埋め込む',
				'settings'            => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => array(
					'menuLabel'       => 'Plan',
					'menuLabelPlural' => 'Plans',
				),
			),
		);

		foreach ( $test_data as $case ) {
			// 呼び方を保存（null は未保存の状態にする）。
			if ( null === $case['settings'] ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			}

			// 予約ブロックが埋め込む設定を取り出して確かめる。
			$config = $this->get_reservation_config();

			$this->assertSame( $case['expected']['menuLabel'], $config['menuLabel'] ?? null, $case['test_condition_name'] );
			$this->assertSame( $case['expected']['menuLabelPlural'], $config['menuLabelPlural'] ?? null, $case['test_condition_name'] );
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
