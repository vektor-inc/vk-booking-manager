<?php
/**
 * 予約ブロックがページに埋め込む、未ログインのお客様のログイン・新規登録の出し分け用の値（#1121）のテスト.
 *
 * window.vkbmReservationConfig の bookingCustomerMode（予約の受付方法）と
 * usersCanRegister（ユーザー登録を受け付けているか）を確かめる。
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
 * window.vkbmReservationConfig の bookingCustomerMode / usersCanRegister を検証するテスト.
 *
 * @group blocks
 */
class Reservation_Block_Guest_Auth_Config_Test extends WP_UnitTestCase {
	/**
	 * テスト後に設定を消す（他のテストへ持ち越さない）。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( 'users_can_register' );
		parent::tear_down();
	}

	/**
	 * maybe_enqueue_reservation_config() が、受付方法の保存値とユーザー登録の設定ごとに埋め込む値を確かめる。
	 */
	public function test_maybe_enqueue_reservation_config(): void {
		$test_data = array(
			array(
				'test_condition_name' => '受付方法が未保存・ユーザー登録あり => member / true',
				'customer_mode'       => null,
				'users_can_register'  => '1',
				'expected'            => array(
					'bookingCustomerMode' => 'member',
					'usersCanRegister'    => true,
				),
			),
			array(
				'test_condition_name' => '受付方法が会員登録なしのみ・ユーザー登録あり => guest / true',
				'customer_mode'       => 'guest',
				'users_can_register'  => '1',
				'expected'            => array(
					'bookingCustomerMode' => 'guest',
					'usersCanRegister'    => true,
				),
			),
			array(
				'test_condition_name' => '受付方法がどちらでも・ユーザー登録なし => both / false',
				'customer_mode'       => 'both',
				'users_can_register'  => '0',
				'expected'            => array(
					'bookingCustomerMode' => 'both',
					'usersCanRegister'    => false,
				),
			),
			array(
				'test_condition_name' => '受付方法が許可外の値 => member に倒す',
				'customer_mode'       => 'unknown',
				'users_can_register'  => '0',
				'expected'            => array(
					'bookingCustomerMode' => 'member',
					'usersCanRegister'    => false,
				),
			),
		);

		foreach ( $test_data as $case ) {
			// 受付方法を保存（null は未保存の状態にする）。
			if ( null === $case['customer_mode'] ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, array( 'booking_customer_mode' => $case['customer_mode'] ) );
			}
			update_option( 'users_can_register', $case['users_can_register'] );

			// 予約ブロックが埋め込む設定を取り出して確かめる。
			$config = $this->get_reservation_config();

			$this->assertSame( $case['expected']['bookingCustomerMode'], $config['bookingCustomerMode'] ?? null, $case['test_condition_name'] );
			$this->assertSame( $case['expected']['usersCanRegister'], $config['usersCanRegister'] ?? null, $case['test_condition_name'] );
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
