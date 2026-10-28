<?php
/**
 * 予約ステータス（provider_booking_status_mode）の初期値と既存サイトの扱い（#924）のテスト。
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
 * provider_booking_status_mode の読み込み・サニタイズ・保存を検証するテスト。
 *
 * @group provider-settings
 */
class Settings_Booking_Status_Mode_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings_Repository::get_settings() の初期値と既存サイトの判定を検証する。
	 */
	public function test_get_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない（新規インストール） => pending',
				'saved_option'        => null,
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '基本設定は保存済みでキーが無い（既存サイト） => confirmed',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'expected'            => 'confirmed',
			),
			array(
				'test_condition_name' => '保存値が confirmed => confirmed',
				'saved_option'        => array( 'provider_booking_status_mode' => 'confirmed' ),
				'expected'            => 'confirmed',
			),
			array(
				'test_condition_name' => '保存値が pending => pending',
				'saved_option'        => array( 'provider_booking_status_mode' => 'pending' ),
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '保存値が許可外の文字列 => pending（確認なしで確定させない）',
				'saved_option'        => array( 'provider_booking_status_mode' => 'unknown' ),
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '保存値が文字列以外 => pending',
				'saved_option'        => array( 'provider_booking_status_mode' => array( 'confirmed' ) ),
				'expected'            => 'pending',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$settings = ( new Settings_Repository() )->get_settings();

			$this->assertSame( $case['expected'], $settings['provider_booking_status_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Sanitizer::sanitize() の許可値の絞り込みを検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'confirmed が送信された場合 => confirmed',
				'input'               => array( 'provider_booking_status_mode' => 'confirmed' ),
				'expected'            => 'confirmed',
			),
			array(
				'test_condition_name' => 'pending が送信された場合 => pending',
				'input'               => array( 'provider_booking_status_mode' => 'pending' ),
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '許可外の値が送信された場合 => pending',
				'input'               => array( 'provider_booking_status_mode' => 'popup' ),
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '送信されなかった場合は既定値 => pending',
				'input'               => array(),
				'expected'            => 'pending',
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitized = ( new Settings_Sanitizer() )->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected'], $sanitized['provider_booking_status_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Service::save_settings() で、予約ステータスが送られない保存でも既存サイトの値を保つことを検証する。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '保存済みでキーが無い既存サイトに、予約ステータスを含まない保存 => confirmed のまま',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => 'confirmed',
			),
			array(
				'test_condition_name' => '保存値が pending のサイトに、予約ステータスを含まない保存 => pending のまま',
				'saved_option'        => array( 'provider_booking_status_mode' => 'pending' ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => 'pending',
			),
			array(
				'test_condition_name' => '既存サイトで confirmed が送信された保存 => confirmed',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'raw_settings'        => array( 'provider_booking_status_mode' => 'confirmed' ),
				'expected'            => 'confirmed',
			),
			array(
				'test_condition_name' => '新規インストールで予約ステータスを含まない保存 => pending',
				'saved_option'        => null,
				'raw_settings'        => array( 'provider_name' => '新しい店舗' ),
				'expected'            => 'pending',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$repository = new Settings_Repository();
			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$service->save_settings( $case['raw_settings'] );

			$this->assertSame(
				$case['expected'],
				$repository->get_settings()['provider_booking_status_mode'],
				$case['test_condition_name']
			);
		}
	}
}
