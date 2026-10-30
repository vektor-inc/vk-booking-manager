<?php
/**
 * 予約の受付方法（booking_customer_mode）の初期値・値の検査・保存（#958）のテスト。
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
 * booking_customer_mode の読み込み・サニタイズ・保存を検証するテスト。
 *
 * @group provider-settings
 */
class Settings_Booking_Customer_Mode_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings_Repository::get_default_settings() の初期値を検証する。
	 */
	public function test_get_default_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertSame( 'member', $defaults['booking_customer_mode'], '初期値は member（会員のみ）' );
	}

	/**
	 * Settings_Repository::get_settings() が、保存状況によらず許可された値だけを返すことを検証する。
	 */
	public function test_get_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない（新規インストール） => member',
				'saved_option'        => null,
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '基本設定は保存済みでキーが無い（既存サイト） => member',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '保存値が member => member',
				'saved_option'        => array( 'booking_customer_mode' => 'member' ),
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
				'test_condition_name' => '保存値が許可外の文字列 => member',
				'saved_option'        => array( 'booking_customer_mode' => 'anyone' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '保存値が文字列以外 => member',
				'saved_option'        => array( 'booking_customer_mode' => array( 'guest' ) ),
				'expected'            => 'member',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$settings = ( new Settings_Repository() )->get_settings();

			$this->assertSame( $case['expected'], $settings['booking_customer_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Repository::normalize_booking_customer_mode() の許可値の絞り込みを検証する。
	 */
	public function test_normalize_booking_customer_mode(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'member => member',
				'value'               => 'member',
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => 'guest => guest',
				'value'               => 'guest',
				'expected'            => 'guest',
			),
			array(
				'test_condition_name' => 'both => both',
				'value'               => 'both',
				'expected'            => 'both',
			),
			array(
				'test_condition_name' => '大文字など許可外の文字列 => member',
				'value'               => 'GUEST',
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '空文字 => member',
				'value'               => '',
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => 'null => member',
				'value'               => null,
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '真偽値 => member',
				'value'               => true,
				'expected'            => 'member',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Settings_Repository::normalize_booking_customer_mode( $case['value'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Settings_Sanitizer::sanitize() の許可値の絞り込みを検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'member が送信された場合 => member',
				'input'               => array( 'booking_customer_mode' => 'member' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => 'guest が送信された場合 => guest',
				'input'               => array( 'booking_customer_mode' => 'guest' ),
				'expected'            => 'guest',
			),
			array(
				'test_condition_name' => 'both が送信された場合 => both',
				'input'               => array( 'booking_customer_mode' => 'both' ),
				'expected'            => 'both',
			),
			array(
				'test_condition_name' => '許可外の値が送信された場合 => member',
				'input'               => array( 'booking_customer_mode' => 'popup' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '配列が送信された場合 => member',
				'input'               => array( 'booking_customer_mode' => array( 'guest' ) ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '送信されなかった場合は既定値 => member',
				'input'               => array(),
				'expected'            => 'member',
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitized = ( new Settings_Sanitizer() )->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected'], $sanitized['booking_customer_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Service::save_settings() で、受付方法が送られない保存でも保存済みの値を保つことを検証する。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '保存値が guest のサイトに、受付方法を含まない保存 => guest のまま',
				'saved_option'        => array( 'booking_customer_mode' => 'guest' ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => 'guest',
			),
			array(
				'test_condition_name' => '保存値が both のサイトで guest が送信された保存 => guest',
				'saved_option'        => array( 'booking_customer_mode' => 'both' ),
				'raw_settings'        => array( 'booking_customer_mode' => 'guest' ),
				'expected'            => 'guest',
			),
			array(
				'test_condition_name' => '保存値が guest のサイトで許可外の値が送信された保存 => member',
				'saved_option'        => array( 'booking_customer_mode' => 'guest' ),
				'raw_settings'        => array( 'booking_customer_mode' => 'popup' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '既存サイトで受付方法を含まない保存 => member のまま',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => 'member',
			),
			array(
				'test_condition_name' => '新規インストールで受付方法を含まない保存 => member',
				'saved_option'        => null,
				'raw_settings'        => array( 'provider_name' => '新しい店舗' ),
				'expected'            => 'member',
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
				$repository->get_settings()['booking_customer_mode'],
				$case['test_condition_name']
			);
		}
	}
}
