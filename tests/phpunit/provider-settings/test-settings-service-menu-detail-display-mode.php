<?php
/**
 * サービスメニュー詳細ページの表示方法（#635）の設定値の互換判定・サニタイズ・保存のテスト。
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
 * service_menu_detail_display_mode の読み込み・サニタイズ・保存を検証するテスト。
 *
 * @group provider-settings
 */
class Settings_Service_Menu_Detail_Display_Mode_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings_Repository::get_settings() の互換判定を検証する。
	 */
	public function test_get_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない（新規インストール） => block',
				'saved_option'        => null,
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => '基本設定は保存済みでキーが無い（既存サイト） => template',
				'saved_option'        => array( 'provider_name' => 'テスト店舗' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '保存値が block => block',
				'saved_option'        => array( 'service_menu_detail_display_mode' => 'block' ),
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => '保存値が template => template',
				'saved_option'        => array( 'service_menu_detail_display_mode' => 'template' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '保存値が許可外の文字列 => template',
				'saved_option'        => array( 'service_menu_detail_display_mode' => 'unknown' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '保存値が文字列以外 => template',
				'saved_option'        => array( 'service_menu_detail_display_mode' => array( 'block' ) ),
				'expected'            => 'template',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$settings = ( new Settings_Repository() )->get_settings();

			$this->assertSame( $case['expected'], $settings['service_menu_detail_display_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Sanitizer::sanitize() のホワイトリスト処理を検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => 'block が送信された場合 => block',
				'input'               => array( 'service_menu_detail_display_mode' => 'block' ),
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => 'template が送信された場合 => template',
				'input'               => array( 'service_menu_detail_display_mode' => 'template' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '大文字・記号を含む block が送信された場合 => sanitize_key 後の block',
				'input'               => array( 'service_menu_detail_display_mode' => 'BLOCK' ),
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => '許可外の値が送信された場合 => template',
				'input'               => array( 'service_menu_detail_display_mode' => 'popup' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '空文字が送信された場合 => template',
				'input'               => array( 'service_menu_detail_display_mode' => '' ),
				'expected'            => 'template',
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitized = ( new Settings_Sanitizer() )->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected'], $sanitized['service_menu_detail_display_mode'], $case['test_condition_name'] );
		}
	}

	/**
	 * Settings_Service::save_settings() が、送られなかったときに現在の実効値を引き継ぐことを検証する。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既存サイトで詳細ページの表示方法を送らずに保存 => template のまま保存される',
				'saved_option'        => array( 'provider_name' => '既存店舗' ),
				'raw_settings'        => array( 'provider_name' => '既存店舗' ),
				'expected'            => 'template',
			),
			array(
				'test_condition_name' => '保存値が block のサイトで詳細ページの表示方法を送らずに保存 => block のまま保存される',
				'saved_option'        => array( 'service_menu_detail_display_mode' => 'block' ),
				'raw_settings'        => array( 'provider_name' => '店舗' ),
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => '既存サイトで block を送って保存 => block が保存される',
				'saved_option'        => array( 'provider_name' => '既存店舗' ),
				'raw_settings'        => array( 'service_menu_detail_display_mode' => 'block' ),
				'expected'            => 'block',
			),
			array(
				'test_condition_name' => '新規サイトで何も送らずに保存 => block が保存される',
				'saved_option'        => null,
				'raw_settings'        => array( 'provider_name' => '新規店舗' ),
				'expected'            => 'block',
			),
		);

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$result = $service->save_settings( $case['raw_settings'] );
			$this->assertNotWPError( $result, $case['test_condition_name'] );

			$stored = get_option( Settings_Repository::OPTION_KEY );
			$this->assertSame( $case['expected'], $stored['service_menu_detail_display_mode'], $case['test_condition_name'] );
		}
	}
}
