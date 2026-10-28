<?php
/**
 * 利用者名設定の保存経路を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 利用者名設定の部分保存を検証する。
 *
 * @group provider-settings
 */
class Settings_Service_Attendee_Name_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings_Service::save_settings() が未送信の利用者名設定を引き継ぐことを検証する。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '見出しを送らず mode を disabled に変更 => 保存済みの見出しを維持する',
				'saved_option'        => array(
					'booking_attendee_name_mode'  => 'optional',
					'booking_attendee_name_label' => 'ペットのお名前',
				),
				'raw_settings'        => array( 'booking_attendee_name_mode' => 'disabled' ),
				'expected_mode'       => 'disabled',
				'expected_label'      => 'ペットのお名前',
			),
			array(
				'test_condition_name' => '利用者名設定を送らず別項目だけを保存 => required と見出しを維持する',
				'saved_option'        => array(
					'booking_attendee_name_mode'  => 'required',
					'booking_attendee_name_label' => 'ご利用者名',
				),
				'raw_settings'        => array( 'provider_name' => 'テスト店舗' ),
				'expected_mode'       => 'required',
				'expected_label'      => 'ご利用者名',
			),
			array(
				'test_condition_name' => '見出しを空文字で明示して保存 => 見出しを空にできる',
				'saved_option'        => array(
					'booking_attendee_name_mode'  => 'optional',
					'booking_attendee_name_label' => 'ペットのお名前',
				),
				'raw_settings'        => array( 'booking_attendee_name_label' => '' ),
				'expected_mode'       => 'optional',
				'expected_label'      => '',
			),
		);

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );

			$result = $service->save_settings( $case['raw_settings'] );
			$this->assertNotWPError( $result, $case['test_condition_name'] );

			$stored = get_option( Settings_Repository::OPTION_KEY );
			$this->assertSame( $case['expected_mode'], $stored['booking_attendee_name_mode'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_label'], $stored['booking_attendee_name_label'], $case['test_condition_name'] );
		}
	}
}
