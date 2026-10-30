<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Cancellation_Policy;
use WP_UnitTestCase;

/**
 * @group bookings
 */
class Booking_Cancellation_Policy_Test extends WP_UnitTestCase {

	/**
	 * 予約が今キャンセルできるかの判定（境界値を含む）。
	 */
	public function test_can_cancel(): void {
		$now  = 1800000000;
		$hour = 3600;

		$test_cases = array(
			array(
				'test_condition_name' => '開始まで 48 時間・締め切り 24 時間 => キャンセルできる',
				'args'                => array( $now + 48 * $hour, $now, 'confirmed', 'hours', 24 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '保留中の予約 => キャンセルできる',
				'args'                => array( $now + 48 * $hour, $now, 'pending', 'hours', 24 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始までちょうど締め切り時間 => キャンセルできる（境界）',
				'args'                => array( $now + 24 * $hour, $now, 'confirmed', 'hours', 24 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始まで締め切り時間より 1 秒短い => キャンセルできない（境界）',
				'args'                => array( $now + 24 * $hour - 1, $now, 'confirmed', 'hours', 24 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '締め切り 0 時間で開始の 1 秒前 => キャンセルできる（境界）',
				'args'                => array( $now + 1, $now, 'confirmed', 'hours', 0 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始時刻ちょうど => キャンセルできない（境界）',
				'args'                => array( $now, $now, 'confirmed', 'hours', 0 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始時刻を過ぎている => キャンセルできない',
				'args'                => array( $now - 1, $now, 'confirmed', 'hours', 0 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '締め切りが負の値 => 0 時間として扱いキャンセルできる',
				'args'                => array( $now + 1, $now, 'confirmed', 'hours', -5 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'キャンセル済み => キャンセルできない',
				'args'                => array( $now + 48 * $hour, $now, 'cancelled', 'hours', 24 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'キャンセルモードが none => 開始まで十分あってもキャンセルできない',
				'args'                => array( $now + 480 * $hour, $now, 'confirmed', 'none', 24 ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Booking_Cancellation_Policy::can_cancel( ...$case['args'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
