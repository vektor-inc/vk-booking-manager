<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use VKBookingManager\Availability\Availability_Service;
use WP_UnitTestCase;

/**
 * Tests for Availability_Service::build_slots_from_entry() with fixed_start_times parameter.
 *
 * @group availability
 */
class Build_Slots_Fixed_Start_Times_Test extends WP_UnitTestCase {

	/**
	 * Invoke the private build_slots_from_entry method via Reflection.
	 *
	 * @param array               $slots             Shift slots (start/end pairs).
	 * @param string              $date              Date (Y-m-d).
	 * @param DateTimeZone        $timezone          Timezone.
	 * @param int                 $block_minutes     Block duration including buffer.
	 * @param int                 $service_minutes   Service duration.
	 * @param DateTimeImmutable|null $deadline_cutoff Deadline cutoff.
	 * @param array               $bookings          Existing bookings.
	 * @param int                 $slot_step_minutes Slot step in minutes.
	 * @param array               $fixed_start_times Fixed start times (HH:MM).
	 * @return array
	 */
	private function call_build_slots_from_entry(
		array $slots,
		string $date,
		DateTimeZone $timezone,
		int $block_minutes,
		int $service_minutes,
		?DateTimeImmutable $deadline_cutoff,
		array $bookings,
		int $slot_step_minutes,
		array $fixed_start_times = array()
	): array {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'build_slots_from_entry' );
		$method->setAccessible( true );
		return $method->invoke(
			$service,
			$slots,
			$date,
			$timezone,
			$block_minutes,
			$service_minutes,
			$deadline_cutoff,
			$bookings,
			$slot_step_minutes,
			$fixed_start_times
		);
	}

	/**
	 * Extract start time strings (HH:MM) from slot result array.
	 *
	 * @param array $slots Slot result array.
	 * @return array<string>
	 */
	private function extract_start_times( array $slots ): array {
		return array_map(
			static function ( array $slot ): string {
				return $slot['start']->format( 'H:i' );
			},
			$slots
		);
	}

	public function test_build_slots_from_entry(): void {
		$tz   = new DateTimeZone( 'Asia/Tokyo' );
		$date = '2026-04-01';

		// 共通のシフト枠: 09:00〜18:00.
		$shift_slots = array(
			array( 'start' => '09:00', 'end' => '18:00' ),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '固定開始時刻 10:00, 14:00 を指定 → 2スロットのみ返る',
				'fixed_start_times'  => array( '10:00', '14:00' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => null,
				'expected_starts'    => array( '10:00', '14:00' ),
			),
			array(
				'test_condition_name' => '固定開始時刻が空 → スロット刻み（15分）で複数スロットが返る',
				'fixed_start_times'  => array(),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => null,
				'expected_starts'    => array(
					'09:00', '09:15', '09:30', '09:45',
					'10:00', '10:15', '10:30', '10:45',
					'11:00', '11:15', '11:30', '11:45',
					'12:00', '12:15', '12:30', '12:45',
					'13:00', '13:15', '13:30', '13:45',
					'14:00', '14:15', '14:30', '14:45',
					'15:00', '15:15', '15:30', '15:45',
					'16:00', '16:15', '16:30', '16:45',
					'17:00',
				),
			),
			array(
				'test_condition_name' => '固定開始時刻がシフト範囲外（07:00）→ 範囲外はスキップされる',
				'fixed_start_times'  => array( '07:00', '10:00' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => null,
				'expected_starts'    => array( '10:00' ),
			),
			array(
				'test_condition_name' => '固定開始時刻でブロック時間がシフト終了を超える（17:30 + 60分 > 18:00）→ スキップ',
				'fixed_start_times'  => array( '10:00', '17:30' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => null,
				'expected_starts'    => array( '10:00' ),
			),
			array(
				'test_condition_name' => '固定開始時刻で既存予約と重複 → 重複スロットはスキップされる',
				'fixed_start_times'  => array( '10:00', '14:00' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(
					array(
						'start' => DateTimeImmutable::createFromFormat( 'Y-m-d H:i', '2026-04-01 09:30', $tz ),
						'end'   => DateTimeImmutable::createFromFormat( 'Y-m-d H:i', '2026-04-01 10:30', $tz ),
					),
				),
				'deadline_cutoff'    => null,
				'expected_starts'    => array( '14:00' ),
			),
			array(
				'test_condition_name' => '固定開始時刻で締切を過ぎた時刻はスキップされる',
				'fixed_start_times'  => array( '10:00', '14:00' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => DateTimeImmutable::createFromFormat( 'Y-m-d H:i', '2026-04-01 12:00', $tz ),
				'expected_starts'    => array( '14:00' ),
			),
			array(
				'test_condition_name' => '固定開始時刻が締切と同一時刻（境界値）→ 同一時刻は許可される',
				'fixed_start_times'  => array( '10:00', '14:00' ),
				'block_minutes'      => 60,
				'service_minutes'    => 60,
				'bookings'           => array(),
				'deadline_cutoff'    => DateTimeImmutable::createFromFormat( 'Y-m-d H:i', '2026-04-01 14:00', $tz ),
				'expected_starts'    => array( '14:00' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $this->call_build_slots_from_entry(
				$shift_slots,
				$date,
				$tz,
				$case['block_minutes'],
				$case['service_minutes'],
				$case['deadline_cutoff'],
				$case['bookings'],
				15, // slot_step_minutes.
				$case['fixed_start_times']
			);

			$actual_starts = $this->extract_start_times( $result );
			$this->assertSame( $case['expected_starts'], $actual_starts, $case['test_condition_name'] );
		}
	}
}
