<?php
/**
 * 泊戦略（Night_Strategy）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use VKBookingManager\Availability\Night_Strategy;
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use WP_UnitTestCase;

/**
 * 販売日・予約の重なりから、期間に空いている客室を判定できることを検証する。
 *
 * @group availability
 * @group lodging
 */
class Night_Strategy_Test extends WP_UnitTestCase {

	/**
	 * シフトの投稿を作成する。
	 *
	 * @param int                              $room_id 客室（リソース）ID。
	 * @param string                           $month   年月（Y-m）。
	 * @param array<int, array<string, mixed>> $days    日ごとのデータ。
	 */
	private function create_shift( int $room_id, string $month, array $days ): void {
		$this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array(
					'_vkbm_shift_resource_id' => $room_id,
					'_vkbm_shift_year'        => (int) substr( $month, 0, 4 ),
					'_vkbm_shift_month'       => (int) substr( $month, 5, 2 ),
					'_vkbm_shift_days'        => $days,
				),
			)
		);
	}

	/**
	 * 1か月ぶんの日のデータを作る（すべて同じ状態。例外の日だけ状態を変える）。
	 *
	 * @param string             $month     年月（Y-m）。
	 * @param string             $status    既定の状態。
	 * @param array<int, string> $overrides 日番号 => 状態。
	 * @return array<int, array<string, mixed>>
	 */
	private function month_days( string $month, string $status, array $overrides = array() ): array {
		$days = array();
		$last = (int) gmdate( 't', (int) strtotime( $month . '-01' ) );
		for ( $day = 1; $day <= $last; $day++ ) {
			$days[ $day ] = array(
				'status' => $overrides[ $day ] ?? $status,
				'slots'  => array(),
			);
		}

		return $days;
	}

	/**
	 * 予約の投稿を作成する。
	 *
	 * @param int    $room_id   客室 ID。
	 * @param string $start     開始（Y-m-d H:i:s）。
	 * @param string $end       終了（Y-m-d H:i:s）。
	 * @param string $status    予約の状態。
	 * @param string $post_status 投稿の状態。
	 */
	private function create_booking( int $room_id, string $start, string $end, string $status = 'confirmed', string $post_status = 'publish' ): void {
		$this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => $post_status,
				'meta_input'  => array(
					Booking_Overlap::META_START     => $start,
					Booking_Overlap::META_END       => $end,
					Booking_Overlap::META_TOTAL_END => $end,
					Booking_Overlap::META_RESOURCE  => $room_id,
					Booking_Overlap::META_STATUS    => $status,
				),
			)
		);
	}

	/**
	 * get_night_dates: 泊の日付の一覧と、不正な入力・泊数の上限を検証する。
	 */
	public function test_get_night_dates(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1泊 => チェックイン日だけ',
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'expected'            => array( '2098-10-10' ),
			),
			array(
				'test_condition_name' => '月末をまたぐ3泊',
				'check_in'            => '2098-10-30',
				'check_out'           => '2098-11-02',
				'expected'            => array( '2098-10-30', '2098-10-31', '2098-11-01' ),
			),
			array(
				'test_condition_name' => '年末をまたぐ3泊',
				'check_in'            => '2098-12-30',
				'check_out'           => '2099-01-02',
				'expected'            => array( '2098-12-30', '2098-12-31', '2099-01-01' ),
			),
			array(
				'test_condition_name' => '閏日を含む2泊（2096-02-28 から）',
				'check_in'            => '2096-02-28',
				'check_out'           => '2096-03-01',
				'expected'            => array( '2096-02-28', '2096-02-29' ),
			),
			array(
				'test_condition_name' => '泊数がちょうど上限 => 受け付ける',
				'check_in'            => '2098-10-01',
				'check_out'           => '2098-10-31',
				'expected'            => array_map( static fn( int $day ): string => sprintf( '2098-10-%02d', $day ), range( 1, Night_Strategy::MAX_NIGHTS ) ),
			),
			array(
				'test_condition_name' => '泊数が上限を1つ超える => 空',
				'check_in'            => '2098-10-01',
				'check_out'           => '2098-11-01',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'チェックアウトがチェックインと同じ日 => 空',
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-10',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '逆順 => 空',
				'check_in'            => '2098-10-12',
				'check_out'           => '2098-10-10',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '実在しない日付 => 空',
				'check_in'            => '2098-02-30',
				'check_out'           => '2098-03-02',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '形式違い => 空',
				'check_in'            => '2098/10/10',
				'check_out'           => '2098-10-12',
				'expected'            => array(),
			),
		);

		$strategy = new Night_Strategy();
		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $strategy->get_night_dates( $case['check_in'], $case['check_out'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_available_room_ids: 販売日・シフト未登録・予約の重なりによる空室判定を検証する。
	 */
	public function test_get_available_room_ids(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '連泊（3泊）・予約なし・全日販売 => 2室とも空室',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
					102 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-13',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101, 102 ),
			),
			array(
				'test_condition_name' => '連泊の真ん中の泊だけ予約済みの客室 => 空室から外れる',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
					102 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-11 15:00:00', '2098-10-12 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-13',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 102 ),
			),
			array(
				'test_condition_name' => '満室（全客室が期間の一部に予約済み） => 空室なし',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
					102 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-10 15:00:00', '2098-10-11 10:00:00', 'confirmed', 'publish' ),
					array( 102, '2098-10-12 15:00:00', '2098-10-13 10:00:00', 'pending', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-13',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '期間の途中に販売しない日（定休日）がある客室 => 空室から外れる',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array( 11 => 'regular_holiday' ) ) ),
					102 => array( '2098-10' => array( 'temporary_open', array() ) ),
				),
				'bookings'            => array(),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-13',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 102 ),
			),
			array(
				'test_condition_name' => 'シフト未登録の客室 => 空室から外れる',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-12',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '月末をまたぐ期間: 翌月のシフトが未登録の客室は外れる',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array(
						'2098-10' => array( 'open', array() ),
						'2098-11' => array( 'open', array() ),
					),
					102 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(),
				'check_in'            => '2098-10-30',
				'check_out'           => '2098-11-02',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '年末をまたぐ期間: 年が替わる日の予約が重なる客室は外れる',
				'rooms'               => array( 101, 102 ),
				'shifts'              => array(
					101 => array(
						'2098-12' => array( 'open', array() ),
						'2099-01' => array( 'open', array() ),
					),
					102 => array(
						'2098-12' => array( 'open', array() ),
						'2099-01' => array( 'open', array() ),
					),
				),
				'bookings'            => array(
					array( 102, '2099-01-01 15:00:00', '2099-01-02 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-12-30',
				'check_out'           => '2099-01-02',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '2泊の中日の 12:00〜13:00 だけの予約がある客室 => 満室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-11 12:00:00', '2098-10-11 13:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-12',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '前の予約のチェックアウトが、この期間のチェックインと同じ時刻 => 空室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-08 10:00:00', '2098-10-10 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '10:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '前の予約のチェックアウトが、この期間のチェックインの1分後 => 満室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-08 10:00:00', '2098-10-10 10:01:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '10:00', '10:00' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '次の予約のチェックインが、この期間のチェックアウトと同じ時刻 => 空室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-11 10:00:00', '2098-10-12 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '次の予約のチェックインが、この期間のチェックアウトの1分前 => 満室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-11 09:59:00', '2098-10-12 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'キャンセル・無断キャンセル・下書きの予約 => 重ならず空室',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 101, '2098-10-10 15:00:00', '2098-10-11 10:00:00', 'cancelled', 'publish' ),
					array( 101, '2098-10-10 15:00:00', '2098-10-11 10:00:00', 'no_show', 'publish' ),
					array( 101, '2098-10-10 15:00:00', '2098-10-11 10:00:00', 'confirmed', 'draft' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '別の客室の予約は影響しない',
				'rooms'               => array( 101 ),
				'shifts'              => array(
					101 => array( '2098-10' => array( 'open', array() ) ),
				),
				'bookings'            => array(
					array( 999, '2098-10-10 15:00:00', '2098-10-11 10:00:00', 'confirmed', 'publish' ),
				),
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'times'               => array( '15:00', '10:00' ),
				'expected'            => array( 101 ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 前のケースのデータが残らないよう、投稿を消す。
			foreach ( array( Shift_Post_Type::POST_TYPE, Booking_Post_Type::POST_TYPE ) as $post_type ) {
				foreach ( get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				) as $post_id ) {
					wp_delete_post( (int) $post_id, true );
				}
			}

			foreach ( $case['shifts'] as $room_id => $months ) {
				foreach ( $months as $month => $definition ) {
					$this->create_shift( $room_id, $month, $this->month_days( $month, $definition[0], $definition[1] ) );
				}
			}
			foreach ( $case['bookings'] as $booking ) {
				$this->create_booking( $booking[0], $booking[1], $booking[2], $booking[3], $booking[4] );
			}

			$strategy = new Night_Strategy();
			$actual   = $strategy->get_available_room_ids( $case['rooms'], $case['check_in'], $case['check_out'], $case['times'][0], $case['times'][1] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_available_room_ids: 日の時間帯（slots）の有無は、泊の空室判定に影響しないことを検証する。
	 */
	public function test_get_available_room_ids_regardless_of_slots(): void {
		$slots = array(
			array(
				'start' => '09:00',
				'end'   => '18:00',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '全日 open で時間帯が空 => 空室',
				'status'              => 'open',
				'slots'               => array(),
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '全日 open で時間帯あり => 時間帯が空のときと同じく空室',
				'status'              => 'open',
				'slots'               => $slots,
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '全日 temporary_open で時間帯あり => 空室',
				'status'              => 'temporary_open',
				'slots'               => $slots,
				'expected'            => array( 101 ),
			),
			array(
				'test_condition_name' => '全日 unavailable で時間帯あり => 時間帯があっても販売しない日は空室から外れる',
				'status'              => 'unavailable',
				'slots'               => $slots,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			// 前のケースのシフトが残らないよう、投稿を消す。
			foreach ( get_posts(
				array(
					'post_type'      => Shift_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}

			// 1か月ぶんを同じ状態・同じ時間帯で作る。
			$days = array();
			for ( $day = 1; $day <= 31; $day++ ) {
				$days[ $day ] = array(
					'status' => $case['status'],
					'slots'  => $case['slots'],
				);
			}
			$this->create_shift( 101, '2098-10', $days );

			$actual = ( new Night_Strategy() )->get_available_room_ids( array( 101 ), '2098-10-10', '2098-10-13', '15:00', '10:00' );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_calendar_availability: 月内の1泊とチェックアウト候補をまとめて判定する。
	 */
	public function test_get_calendar_availability(): void {
		$this->create_shift( 101, '2098-10', $this->month_days( '2098-10', 'open', array( 13 => 'unavailable' ) ) );
		$this->create_booking( 101, '2098-10-11 15:00:00', '2098-10-12 10:00:00' );

		$strategy = new Night_Strategy();
		$monthly  = $strategy->get_calendar_availability( array( 101 ), '2098-10-10', '2098-10-13', '15:00', '10:00' );
		$this->assertSame(
			array(
				'2098-10-10' => array(
					'available' => true,
					'reason'    => '',
				),
				'2098-10-11' => array(
					'available' => false,
					'reason'    => 'fully_booked',
				),
				'2098-10-12' => array(
					'available' => true,
					'reason'    => '',
				),
				'2098-10-13' => array(
					'available' => false,
					'reason'    => 'unavailable_date',
				),
			),
			$monthly,
			'月内の1泊ごとに満室と販売停止を区別する'
		);

		$checkout_candidates = $strategy->get_calendar_availability( array( 101 ), '2098-10-11', '2098-10-14', '15:00', '10:00', '2098-10-10' );
		$this->assertSame(
			array(
				'2098-10-11' => array(
					'available' => true,
					'reason'    => '',
				),
				'2098-10-12' => array(
					'available' => false,
					'reason'    => 'fully_booked',
				),
				'2098-10-13' => array(
					'available' => false,
					'reason'    => 'fully_booked',
				),
				'2098-10-14' => array(
					'available' => false,
					'reason'    => 'unavailable_date',
				),
			),
			$checkout_candidates,
			'最初の満室日まではチェックアウト候補になり、それより後は滞在全体で空室なしになる'
		);
	}
}
