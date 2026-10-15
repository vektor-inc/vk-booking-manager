<?php
/**
 * Booking_Datetime（予約日時の正規化・読み取り）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use DateTimeZone;
use ReflectionMethod;
use VKBookingManager\Common\Booking_Datetime;
use VKBookingManager\Common\Staff_Conflict_Detector;
use WP_UnitTestCase;

/**
 * 日時の正規化と、呼び出し元ごとの受け付け範囲（回帰）を検証する。
 *
 * @group common
 */
class Booking_Datetime_Test extends WP_UnitTestCase {

	/**
	 * 保存形式のゆれを Y-m-d H:i:s に整えられること、読み取れない値は空文字になることを検証する。
	 */
	public function test_normalize(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '標準形式 => そのまま',
				'value'               => '2026-09-15 10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '秒無し => 秒 00 を補う',
				'value'               => '2026-09-15 10:30',
				'expected'            => '2026-09-15 10:30:00',
			),
			array(
				'test_condition_name' => 'T 区切り => 空白区切りにする',
				'value'               => '2026-09-15T10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => 'T 区切りかつ秒無し => 整える',
				'value'               => '2026-09-15T10:30',
				'expected'            => '2026-09-15 10:30:00',
			),
			array(
				'test_condition_name' => 'タイムゾーン表記つき => 日時部分だけ使う',
				'value'               => '2026-09-15T10:30:45+09:00',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '空文字 => 空文字（境界）',
				'value'               => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '日時でない文字列 => 空文字（境界）',
				'value'               => 'invalid',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '文字列以外（null）=> 空文字（境界）',
				'value'               => null,
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Datetime::normalize( $case['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 正規化できる形に加え、従来の厳密な読み取りで通っていた形も読み取れる（受け付け範囲が狭まらない）ことを検証する。
	 */
	public function test_parse(): void {
		$timezone = new DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => '標準形式 => 読み取れる',
				'value'               => '2026-09-15 10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '秒無し => 読み取れる',
				'value'               => '2026-09-15 10:30',
				'expected'            => '2026-09-15 10:30:00',
			),
			array(
				'test_condition_name' => 'T 区切り => 読み取れる',
				'value'               => '2026-09-15T10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '桁が揃っていない旧来の形（時が 1 桁）=> 従来どおり読み取れる（回帰）',
				'value'               => '2026-09-15 9:30:00',
				'expected'            => '2026-09-15 09:30:00',
			),
			array(
				'test_condition_name' => '日時でない文字列 => false（境界）',
				'value'               => 'invalid',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '文字列以外 => false（境界）',
				'value'               => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Booking_Datetime::parse( $case['value'], $timezone );

			if ( false === $case['expected'] ) {
				$this->assertFalse( $actual, $case['test_condition_name'] );
				continue;
			}

			$this->assertNotFalse( $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $actual->format( 'Y-m-d H:i:s' ), $case['test_condition_name'] );
		}
	}

	/**
	 * Staff_Conflict_Detector の正規化が、共通化後も従来の受け付け範囲（DateTimeImmutable が解釈できる形）を保つことを検証する。
	 */
	public function test_staff_conflict_detector_normalize_datetime(): void {
		$method = new ReflectionMethod( Staff_Conflict_Detector::class, 'normalize_datetime' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '標準形式 => そのまま',
				'value'               => '2026-09-15 10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '秒無し => 秒 00 を補う',
				'value'               => '2026-09-15 10:30',
				'expected'            => '2026-09-15 10:30:00',
			),
			array(
				'test_condition_name' => 'T 区切り => 整える',
				'value'               => '2026-09-15T10:30:45',
				'expected'            => '2026-09-15 10:30:45',
			),
			array(
				'test_condition_name' => '共通の正規化では扱わない形（日付のみ）=> 従来どおり 00:00:00 として解釈',
				'value'               => '2026-09-15',
				'expected'            => '2026-09-15 00:00:00',
			),
			array(
				'test_condition_name' => '空文字 => 空文字（境界）',
				'value'               => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '解釈できない文字列 => 空文字（境界）',
				'value'               => 'invalid',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $method->invoke( null, $case['value'] ), $case['test_condition_name'] );
		}
	}
}
