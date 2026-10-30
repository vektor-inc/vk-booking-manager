<?php
/**
 * 予約の受付方法（booking_customer_mode）が provider-settings の REST 応答に含まれること（#958）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * GET /vkbm/v1/provider-settings の応答の booking_customer_mode を検証する。
 *
 * @group rest
 * @group provider-settings
 */
class Provider_Settings_Controller_Booking_Customer_Mode_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * get_settings() の応答が、保存状況に応じた booking_customer_mode を返すことを検証する。
	 */
	public function test_get_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない => member',
				'saved_option'        => null,
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '保存済みでキーが無い既存サイト => member',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '保存値が guest => guest',
				'saved_option'        => array( 'booking_customer_mode' => 'guest' ),
				'expected'            => 'guest',
			),
			array(
				'test_condition_name' => '保存値が both => both',
				'saved_option'        => array( 'booking_customer_mode' => 'both' ),
				'expected'            => 'both',
			),
			array(
				'test_condition_name' => '保存値が許可外 => member',
				'saved_option'        => array( 'booking_customer_mode' => 'anyone' ),
				'expected'            => 'member',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$data = ( new Provider_Settings_Controller( new Settings_Repository() ) )->get_settings()->get_data();

			$this->assertArrayHasKey( 'booking_customer_mode', $data, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $data['booking_customer_mode'], $case['test_condition_name'] );
		}
	}
}
