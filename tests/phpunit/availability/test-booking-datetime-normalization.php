<?php
/**
 * 予約日時の形式ゆれ（秒無し・T 区切り）と、営業日の状態の空値が空き計算で扱えることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeZone;
use ReflectionMethod;
use ReflectionProperty;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 空き計算が予約日時の形式ゆれを拾うこと、営業日の状態に空値が混ざってもエラーにならないことを検証する。
 *
 * @group availability
 */
class Booking_Datetime_Normalization_Test extends WP_UnitTestCase {

	/**
	 * 既存のタイムゾーン設定。
	 *
	 * @var string
	 */
	private string $original_timezone_string = '';

	/**
	 * タイムゾーンを Asia/Tokyo に固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * タイムゾーン設定とキャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 予約の投稿を作成する。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param string $start    開始日時（保存形式そのまま）。
	 * @param string $end      終了日時（保存形式そのまま）。
	 * @param int    $author   投稿者（会員）ID。
	 * @return int
	 */
	private function create_booking_post( int $staff_id, string $start, string $end, int $author = 0 ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}

	/**
	 * シフトの投稿を作成する。
	 *
	 * @param int                              $staff_id スタッフID。
	 * @param int                              $year     年。
	 * @param int                              $month    月。
	 * @param array<int, array<string, mixed>> $days     日ごとのデータ。
	 */
	private function create_shift( int $staff_id, int $year, int $month, array $days ): void {
		$this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array(
					'_vkbm_shift_resource_id' => $staff_id,
					'_vkbm_shift_year'        => $year,
					'_vkbm_shift_month'       => $month,
					'_vkbm_shift_days'        => $days,
				),
			)
		);
	}

	/**
	 * 秒無し・T 区切りの予約も、スタッフの日別予約として拾えることを検証する。
	 */
	public function test_get_bookings_for_staff_date(): void {
		$timezone = new DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => '標準形式（Y-m-d H:i:s）の予約 => 拾う',
				'start'               => '2026-09-15 10:00:00',
				'end'                 => '2026-09-15 11:00:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => '秒無し（Y-m-d H:i）の予約 => 秒 00 として拾う',
				'start'               => '2026-09-15 10:00',
				'end'                 => '2026-09-15 11:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => 'T 区切り（Y-m-dTH:i:s）の予約 => 拾う',
				'start'               => '2026-09-15T10:00:00',
				'end'                 => '2026-09-15T11:00:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => 'T 区切りかつ秒無し（Y-m-dTH:i）の予約 => 拾う',
				'start'               => '2026-09-15T10:00',
				'end'                 => '2026-09-15T11:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => '日時として読み取れない値の予約 => 拾わない（境界）',
				'start'               => 'invalid',
				'end'                 => '2026-09-15 11:00:00',
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$staff_id = (int) $this->factory()->post->create( array( 'post_type' => 'post' ) );
			$this->create_booking_post( $staff_id, $case['start'], $case['end'] );

			$service  = new Availability_Service();
			$bookings = $service->get_bookings_for_staff_date( $staff_id, '2026-09-15', $timezone );

			if ( null === $case['expected'] ) {
				$this->assertCount( 0, $bookings, $case['test_condition_name'] );
				continue;
			}

			$this->assertCount( 1, $bookings, $case['test_condition_name'] );
			$this->assertSame( $case['expected'][0], $bookings[0]['start']->format( 'Y-m-d H:i:s' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'][1], $bookings[0]['end']->format( 'Y-m-d H:i:s' ), $case['test_condition_name'] );
		}
	}

	/**
	 * 秒無し・T 区切りの予約も、会員の日別予約として拾えることを検証する。
	 */
	public function test_get_bookings_for_user_date(): void {
		$timezone = new DateTimeZone( 'Asia/Tokyo' );
		$method   = new ReflectionMethod( Availability_Service::class, 'get_bookings_for_user_date' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '標準形式の予約 => 拾う',
				'start'               => '2026-09-15 10:00:00',
				'end'                 => '2026-09-15 11:00:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => '秒無しの予約 => 拾う',
				'start'               => '2026-09-15 10:00',
				'end'                 => '2026-09-15 11:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => 'T 区切りの予約 => 拾う',
				'start'               => '2026-09-15T10:00:00',
				'end'                 => '2026-09-15T11:00:00',
				'expected'            => array( '2026-09-15 10:00:00', '2026-09-15 11:00:00' ),
			),
			array(
				'test_condition_name' => '日時として読み取れない値の予約 => 拾わない（境界）',
				'start'               => 'invalid',
				'end'                 => '2026-09-15 11:00:00',
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = (int) $this->factory()->user->create();
			$this->create_booking_post( 0, $case['start'], $case['end'], $user_id );

			$service  = new Availability_Service();
			$bookings = $method->invoke( $service, $user_id, '2026-09-15', $timezone );

			if ( null === $case['expected'] ) {
				$this->assertCount( 0, $bookings, $case['test_condition_name'] );
				continue;
			}

			$this->assertCount( 1, $bookings, $case['test_condition_name'] );
			$this->assertSame( $case['expected'][0], $bookings[0]['start']->format( 'Y-m-d H:i:s' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'][1], $bookings[0]['end']->format( 'Y-m-d H:i:s' ), $case['test_condition_name'] );
		}
	}

	/**
	 * 営業日の状態に空・未知の値が混ざっても、エラーにならず営業日として扱うことを検証する。
	 */
	public function test_resolve_day_status(): void {
		$method = new ReflectionMethod( Availability_Service::class, 'resolve_day_status' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '全員が定休日 => regular_holiday（正常系）',
				'statuses'            => array( 'regular_holiday', 'regular_holiday' ),
				'expected'            => 'regular_holiday',
			),
			array(
				'test_condition_name' => '営業と定休日が混在 => open（正常系）',
				'statuses'            => array( 'open', 'regular_holiday' ),
				'expected'            => 'open',
			),
			array(
				'test_condition_name' => '先頭が空で、次が定休日 => 空は読み込み時に open へ正規化されるため open',
				'statuses'            => array( '', 'regular_holiday' ),
				'expected'            => 'open',
			),
			array(
				'test_condition_name' => '空の状態だけ => open',
				'statuses'            => array( '' ),
				'expected'            => 'open',
			),
			array(
				'test_condition_name' => '未知の状態だけ => open',
				'statuses'            => array( 'unknown_status' ),
				'expected'            => 'open',
			),
			array(
				'test_condition_name' => '状態が配列で保存されている => open（境界）',
				'statuses'            => array( array( 'x' ), 'regular_holiday' ),
				'expected'            => 'open',
			),
		);

		foreach ( $test_cases as $case ) {
			$staff_ids = array();
			foreach ( $case['statuses'] as $status ) {
				$staff_id    = (int) $this->factory()->post->create( array( 'post_type' => 'post' ) );
				$staff_ids[] = $staff_id;
				$this->create_shift( $staff_id, 2026, 9, array( 15 => array( 'status' => $status ) ) );
			}

			$service = new Availability_Service();
			$actual  = $method->invoke( $service, $staff_ids, 2026, 9, 15 );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 読み込み時の正規化を通さず空の状態が直接渡されても、resolve_day_status() 単体でエラーにならないことを検証する。
	 *
	 * shift_cache に空の状態を直接入れ、array_values() によるキー番号の詰め直しだけを確かめる。
	 */
	public function test_resolve_day_status_with_empty_status_in_cache(): void {
		$method = new ReflectionMethod( Availability_Service::class, 'resolve_day_status' );
		$method->setAccessible( true );
		$cache = new ReflectionProperty( Availability_Service::class, 'shift_cache' );
		$cache->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '先頭が空で、次が定休日 => regular_holiday',
				'statuses'            => array( '', 'regular_holiday' ),
				'expected'            => 'regular_holiday',
			),
			array(
				'test_condition_name' => '先頭が空で、次が営業 => open',
				'statuses'            => array( '', 'open' ),
				'expected'            => 'open',
			),
			array(
				'test_condition_name' => '先頭が空で、営業を含まない2種類が続く => 先頭の状態（regular_holiday）',
				'statuses'            => array( '', 'regular_holiday', 'temporary_closed' ),
				'expected'            => 'regular_holiday',
			),
			array(
				'test_condition_name' => '全員が空 => unavailable（境界）',
				'statuses'            => array( '', '' ),
				'expected'            => 'unavailable',
			),
		);

		foreach ( $test_cases as $case ) {
			$service   = new Availability_Service();
			$staff_ids = array();
			$entries   = array();
			foreach ( $case['statuses'] as $index => $status ) {
				$staff_id    = 9000 + $index;
				$staff_ids[] = $staff_id;

				$entries[ sprintf( '%d-2026-09', $staff_id ) ] = array(
					15 => array(
						'status' => $status,
						'slots'  => array(),
					),
				);
			}
			$cache->setValue( $service, $entries );

			$actual = $method->invoke( $service, $staff_ids, 2026, 9, 15 );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
