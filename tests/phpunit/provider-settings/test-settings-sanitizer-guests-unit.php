<?php
/**
 * Settings_Sanitizer の数量単位（null / '' 保持）に関するテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use WP_UnitTestCase;

/**
 * 数量単位サニタイズ（null / '' 保持）のテストクラス。
 *
 * @group provider-settings
 */
class Settings_Sanitizer_Guests_Unit_Test extends WP_UnitTestCase {
	/**
	 * guests_unit_label / guests_count_label のサニタイズ結果を検証する。
	 *
	 * 単位は null（未送信＝ロケール既定）と ''（意図的な単位なし）を区別して保持する点が肝。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '単位フィールドが未送信の場合 => 既定の null を維持（ロケール既定へ解決される）',
				'conditions'          => array(
					// guests_unit_label キーを含めない（未送信）。
					'input' => array(),
				),
				'expected_unit'       => null,
			),
			array(
				'test_condition_name' => '単位フィールドに空文字が送信された場合 => 空文字を保持（単位なし）',
				'conditions'          => array(
					'input' => array( 'guests_unit_label' => '' ),
				),
				'expected_unit'       => '',
			),
			array(
				'test_condition_name' => '単位フィールドに null が送信された場合 => null を維持',
				'conditions'          => array(
					'input' => array( 'guests_unit_label' => null ),
				),
				'expected_unit'       => null,
			),
			array(
				'test_condition_name' => '単位フィールドに「台」が送信された場合 => 台 を保持',
				'conditions'          => array(
					'input' => array( 'guests_unit_label' => '台' ),
				),
				'expected_unit'       => '台',
			),
			array(
				'test_condition_name' => '単位フィールドにタグ付き値が送信された場合 => サニタイズして保持',
				'conditions'          => array(
					'input' => array( 'guests_unit_label' => '台<script>alert(1)</script>' ),
				),
				'expected_unit'       => '台',
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitizer = new Settings_Sanitizer();
			$result    = $sanitizer->sanitize( $case['conditions']['input'], $defaults );

			// 単位の出し分けを検証する。
			$this->assertArrayHasKey( 'guests_unit_label', $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected_unit'], $result['guests_unit_label'], $case['test_condition_name'] );
		}
	}

	/**
	 * 見出し（guests_count_label）は sanitize_text_field 相当でサニタイズされる。
	 */
	public function test_sanitize_count_label(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '見出しが未送信の場合 => 既定の空文字',
				'conditions'          => array( 'input' => array() ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '見出しに「数量」が送信された場合 => 数量',
				'conditions'          => array( 'input' => array( 'guests_count_label' => '数量' ) ),
				'expected'            => '数量',
			),
			array(
				'test_condition_name' => '見出しにタグ付き値が送信された場合 => サニタイズして保持',
				'conditions'          => array( 'input' => array( 'guests_count_label' => '数量<script>alert(1)</script>' ) ),
				'expected'            => '数量',
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitizer = new Settings_Sanitizer();
			$result    = $sanitizer->sanitize( $case['conditions']['input'], $defaults );

			$this->assertSame( $case['expected'], $result['guests_count_label'], $case['test_condition_name'] );
		}
	}
}
