<?php
/**
 * 予約ブロックが出力する、会員登録なしの予約の確認リンクの結果（window.vkbmReservationConfig.guestVerifyResult）のテスト.
 *
 * 確認リンクを開いたリクエストでだけ結果を出力し、予約を受け付けなかった理由（code・message）は出力しない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use ReflectionProperty;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\Bookings\Guest_Booking_Controller;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * window.vkbmReservationConfig の guestVerifyResult を検証するテスト.
 *
 * @group blocks
 * @group guest-booking
 */
class Reservation_Block_Guest_Verify_Config_Test extends WP_UnitTestCase {
	/**
	 * テスト後に、このリクエストの結果を空に戻す.
	 */
	protected function tearDown(): void {
		Guest_Booking_Controller::reset_current_result();
		parent::tearDown();
	}

	/**
	 * 確認リンクを処理していないリクエストでは出力せず、処理したリクエストでは決まったキーだけを出力することを検証する.
	 */
	public function test_guest_verify_result_in_config(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '確認リンクを処理していない => guestVerifyResult を出力しない',
				'current'             => null,
				'expected_result'     => null,
			),
			array(
				'test_condition_name' => '期限切れ => result だけを出力する',
				'current'             => array(
					'result'         => 'expired',
					'booking_id'     => 0,
					'booking_status' => '',
					'repeat'         => false,
					'code'           => '',
					'message'        => '',
				),
				'expected_result'     => 'expired',
			),
			array(
				'test_condition_name' => '予約できなかった（rejected） => 理由（code・message）を出力しない',
				'current'             => array(
					'result'         => 'rejected',
					'booking_id'     => 0,
					'booking_status' => '',
					'repeat'         => false,
					'code'           => 'customer_flag_blocked',
					'message'        => '内部の理由の文言',
				),
				'expected_result'     => 'rejected',
			),
		);

		$property = new ReflectionProperty( Guest_Booking_Controller::class, 'current_result' );
		$property->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$property->setValue( null, $case['current'] );
			$config = $this->get_reservation_config();

			if ( null === $case['expected_result'] ) {
				$this->assertArrayNotHasKey( 'guestVerifyResult', $config, $case['test_condition_name'] );
				continue;
			}

			$this->assertArrayHasKey( 'guestVerifyResult', $config, $case['test_condition_name'] );
			$this->assertSame( $case['expected_result'], $config['guestVerifyResult']['result'], $case['test_condition_name'] );
			$this->assertSame(
				array( 'result', 'bookingStatus', 'repeat', 'booking' ),
				array_keys( $config['guestVerifyResult'] ),
				$case['test_condition_name'] . '（渡すキーは決まっている）'
			);
			$encoded = (string) wp_json_encode( $config );
			$this->assertStringNotContainsString( '内部の理由の文言', $encoded, $case['test_condition_name'] );
			$this->assertStringNotContainsString( 'customer_flag_blocked', $encoded, $case['test_condition_name'] );
		}
	}

	/**
	 * 予約ブロックを含む投稿をレンダリング対象の投稿として設定し、出力されたインライン JSON（window.vkbmReservationConfig）をデコードして返す.
	 *
	 * @return array<string, mixed> デコード済みの設定配列.
	 */
	private function get_reservation_config(): array {
		global $post;

		// 他のテストや前の案件で登録済みのスクリプトハンドルが残っていると、インラインスクリプトが累積するため、毎回まっさらな WP_Scripts を使う.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストごとにスクリプトレジストリを初期化するため。

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:vk-booking-manager/reservation /-->',
			)
		);
		$post    = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ブロック判定対象のグローバル投稿を差し替えるため。

		$settings_service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$block            = new Reservation_Block( new Auth_Shortcodes( $settings_service ) );
		$block->register_block();
		$block->maybe_enqueue_menu_loop_styles();

		$inline_scripts = wp_scripts()->get_data( 'vkbm-reservation-config', 'before' );
		$this->assertIsArray( $inline_scripts );
		$this->assertNotEmpty( $inline_scripts );

		$this->assertSame(
			1,
			preg_match( '/window\.vkbmReservationConfig\s*=\s*(\{.*\});/s', implode( '', $inline_scripts ), $matches ),
			'window.vkbmReservationConfig のインラインスクリプトが期待した形式で出力されていること。'
		);

		$config = json_decode( $matches[1], true );
		$this->assertIsArray( $config );

		return $config;
	}
}
