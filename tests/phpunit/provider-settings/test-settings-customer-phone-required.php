<?php
/**
 * お客様の電話番号を必須にする設定（customer_phone_required）の既定値・サニタイズ・保存・REST 公開（#1053）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * customer_phone_required の読み込み・サニタイズ・保存・REST 応答を検証するテスト。
 *
 * @group provider-settings
 */
class Settings_Customer_Phone_Required_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 既定値が必須（true）であることを検証する。
	 */
	public function test_get_default_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertTrue( $defaults['customer_phone_required'], '既定は必須（既存サイトの挙動を変えない）' );
	}

	/**
	 * Settings_Repository::is_customer_phone_required() が、未設定を必須とみなすことを検証する。
	 */
	public function test_is_customer_phone_required(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '設定のキーが無い => 必須',
				'settings'            => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'true => 必須',
				'settings'            => array( 'customer_phone_required' => true ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'false => 任意',
				'settings'            => array( 'customer_phone_required' => false ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Settings_Repository::is_customer_phone_required( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Sanitizer::sanitize() が、送信値に応じた真偽値にそろえることを検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'チェックあり（1）=> true',
				'input'               => array( 'customer_phone_required' => '1' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'チェックなし（hidden の 0）=> false',
				'input'               => array( 'customer_phone_required' => '0' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '送信されなかった場合は既定値 => true',
				'input'               => array(),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitized = ( new Settings_Sanitizer() )->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected'], $sanitized['customer_phone_required'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Service::save_settings() で、電話番号の設定が送られない保存でも保存済みの値を保つことを検証する。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '任意（false）のサイトに、この設定を含まない保存 => false のまま',
				'saved_option'        => array( 'customer_phone_required' => false ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '必須のサイトで 0 が送信された保存 => false',
				'saved_option'        => array( 'customer_phone_required' => true ),
				'raw_settings'        => array( 'customer_phone_required' => '0' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '任意のサイトで 1 が送信された保存 => true',
				'saved_option'        => array( 'customer_phone_required' => false ),
				'raw_settings'        => array( 'customer_phone_required' => '1' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '既存サイト（キー無し）でこの設定を含まない保存 => true',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'raw_settings'        => array( 'provider_name' => '更新後の店舗' ),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );

			$repository = new Settings_Repository();
			$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
			$service->save_settings( $case['raw_settings'] );

			$this->assertSame( $case['expected'], $repository->get_settings()['customer_phone_required'], $case['test_condition_name'] );
		}
	}

	/**
	 * GET /vkbm/v1/provider-settings の応答が、保存状況に応じた customer_phone_required を返すことを検証する。
	 */
	public function test_rest_response(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない => true',
				'saved_option'        => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '保存済みでキーが無い既存サイト => true',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '保存値が false => false',
				'saved_option'        => array( 'customer_phone_required' => false ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$data = ( new Provider_Settings_Controller( new Settings_Repository() ) )->get_settings()->get_data();

			$this->assertArrayHasKey( 'customer_phone_required', $data, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $data['customer_phone_required'], $case['test_condition_name'] );
		}
	}
}
