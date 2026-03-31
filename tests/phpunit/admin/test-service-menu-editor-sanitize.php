<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionClass;
use VKBookingManager\Admin\Service_Menu_Editor;
use WP_UnitTestCase;

/**
 * Tests for Service_Menu_Editor::sanitize_fixed_start_times().
 *
 * @group admin
 */
class Service_Menu_Editor_Sanitize_Test extends WP_UnitTestCase {

	/**
	 * Invoke the private sanitize_fixed_start_times method via Reflection.
	 * / プライベートメソッド sanitize_fixed_start_times をリフレクションで呼び出す.
	 *
	 * @param mixed $hours   Hour values.
	 * @param mixed $minutes Minute values.
	 * @return array<string>
	 */
	private function call_sanitize_fixed_start_times( $hours, $minutes ): array {
		$editor     = new Service_Menu_Editor();
		$reflection = new ReflectionClass( $editor );
		$method     = $reflection->getMethod( 'sanitize_fixed_start_times' );
		$method->setAccessible( true );
		return $method->invoke( $editor, $hours, $minutes );
	}

	/**
	 * Invoke the private sanitize_integer_value method via Reflection.
	 * / プライベートメソッド sanitize_integer_value をリフレクションで呼び出す.
	 *
	 * @param array<string, mixed> $data Submitted data.
	 * @param string               $key  Array key.
	 * @return string
	 */
	private function call_sanitize_integer_value( array $data, string $key ): string {
		$editor     = new Service_Menu_Editor();
		$reflection = new ReflectionClass( $editor );
		$method     = $reflection->getMethod( 'sanitize_integer_value' );
		$method->setAccessible( true );
		return $method->invoke( $editor, $data, $key );
	}

	/**
	 * Test sanitize_fixed_start_times().
	 * / sanitize_fixed_start_times() のテスト.
	 */
	public function test_sanitize_fixed_start_times(): void {
		$test_cases = array(
			// 正常系: 有効な時刻が正しく HH:MM 形式で返る.
			array(
				'test_condition_name' => '有効な時刻（9:00 と 14:30）が HH:MM 形式でソートされて返る',
				'hours'               => array( '14', '9' ),
				'minutes'             => array( '30', '00' ),
				'expected'            => array( '09:00', '14:30' ),
			),
			// 正常系: 重複が除去される.
			array(
				'test_condition_name' => '重複する時刻が除去されて1件になる',
				'hours'               => array( '10', '10' ),
				'minutes'             => array( '00', '00' ),
				'expected'            => array( '10:00' ),
			),
			// 正常系: 時刻が空の場合は空配列を返す.
			array(
				'test_condition_name' => '空配列を渡すと空配列が返る',
				'hours'               => array(),
				'minutes'             => array(),
				'expected'            => array(),
			),
			// 異常系: 時間が範囲外（24以上）の場合はスキップされる.
			array(
				'test_condition_name' => '時間が 24 以上の場合はスキップされる',
				'hours'               => array( '24', '10' ),
				'minutes'             => array( '00', '00' ),
				'expected'            => array( '10:00' ),
			),
			// 異常系: 許容外の分（例: 15分）の場合はスキップされる.
			array(
				'test_condition_name' => '分が許容値（10分刻み）以外の場合はスキップされる',
				'hours'               => array( '10', '11' ),
				'minutes'             => array( '15', '00' ),
				'expected'            => array( '11:00' ),
			),
			// 異常系: 配列以外を渡すと空配列が返る.
			array(
				'test_condition_name' => '配列でない値を渡すと空配列が返る',
				'hours'               => 'invalid',
				'minutes'             => null,
				'expected'            => array(),
			),
			// 境界値: 時間が 0 と 23 は有効.
			array(
				'test_condition_name' => '時間が 0 と 23 の境界値は有効で返る',
				'hours'               => array( '0', '23' ),
				'minutes'             => array( '00', '50' ),
				'expected'            => array( '00:00', '23:50' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->call_sanitize_fixed_start_times( $case['hours'], $case['minutes'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * Test sanitize_integer_value().
	 * / sanitize_integer_value() のテスト.
	 */
	public function test_sanitize_integer_value(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'キー未送信は未設定として空文字を返す',
				'data'                => array(),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '整数文字列はそのまま保存用文字列になる',
				'data'                => array( 'max_advance_booking_days' => '14' ),
				'expected'            => '14',
			),
			array(
				'test_condition_name' => '前後空白付きの整数文字列はトリムされて保存用文字列になる',
				'data'                => array( 'max_advance_booking_days' => ' 14 ' ),
				'expected'            => '14',
			),
			array(
				'test_condition_name' => '0 は無制限指定として 0 のまま保存用文字列になる',
				'data'                => array( 'max_advance_booking_days' => '0' ),
				'expected'            => '0',
			),
			array(
				'test_condition_name' => '空文字は未設定として空文字を返す',
				'data'                => array( 'max_advance_booking_days' => '' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '小数は無効値として空文字を返す',
				'data'                => array( 'max_advance_booking_days' => '0.5' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '負の整数は 0 に丸める',
				'data'                => array( 'max_advance_booking_days' => '-3' ),
				'expected'            => '0',
			),
			array(
				'test_condition_name' => '数値以外は無効値として空文字を返す',
				'data'                => array( 'max_advance_booking_days' => 'abc' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->call_sanitize_integer_value( $case['data'], 'max_advance_booking_days' );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
