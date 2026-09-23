<?php
/**
 * Settings_Sanitizer の shift_auto_register_months（シフトの自動登録）に関するテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use WP_UnitTestCase;

/**
 * shift_auto_register_months のサニタイズ結果を検証する。
 *
 * @group provider-settings
 */
class Settings_Sanitizer_Shift_Auto_Register_Test extends WP_UnitTestCase {
	/**
	 * 許可値（0〜3）はそのまま保持され、範囲外・非数値は無効（0）へフォールバックすることを検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '未送信の場合 => 既定値の0（無効）を維持',
				'conditions'          => array( 'input' => array() ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '1（翌月まで）を送信 => 1を維持',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => '1' ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '2（2ヶ月先まで）を送信 => 2を維持',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => '2' ) ),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '3（3ヶ月先まで）を送信 => 3を維持',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => '3' ) ),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '範囲外の4を送信 => 無効（0）へフォールバック',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => '4' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '負の値（-1）を送信 => 無効（0）へフォールバック',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => '-1' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '非数値文字列を送信 => 無効（0）へフォールバック',
				'conditions'          => array( 'input' => array( 'shift_auto_register_months' => 'abc' ) ),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitizer = new Settings_Sanitizer();
			$result    = $sanitizer->sanitize( $case['conditions']['input'], $defaults );

			$this->assertSame( $case['expected'], $result['shift_auto_register_months'], $case['test_condition_name'] );
		}
	}

	/**
	 * デフォルト設定（未保存状態）の shift_auto_register_months が0（無効）であることを検証する。
	 *
	 * 未保存時に自動登録が動き出すと既存サイトの挙動が変わってしまうため、既定値が無効であることは重要な前提。
	 */
	public function test_default_settings_shift_auto_register_months_is_disabled(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertSame( 0, $defaults['shift_auto_register_months'] );
	}
}
