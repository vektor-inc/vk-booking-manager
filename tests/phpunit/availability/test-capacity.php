<?php
/**
 * Tests for capacity (multiple booking) feature.
 *
 * 複数予約機能（capacity）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * Tests for capacity feature in Availability_Service.
 *
 * @group availability
 * @group capacity
 */
class Capacity_Test extends WP_UnitTestCase {

	/**
	 * count_bookings_for_slot が重複する予約をカウントすることを検証する。
	 * Verify count_bookings_for_slot counts overlapping bookings.
	 */
	public function test_count_bookings_for_slot_counts_overlapping_bookings(): void {
		$tz       = new DateTimeZone( 'Asia/Tokyo' );
		$service  = new Availability_Service();
		$start    = new DateTimeImmutable( '2026-04-01 10:00:00', $tz );
		$end      = new DateTimeImmutable( '2026-04-01 11:00:00', $tz );

		// 2件の重複予約をセットアップ。
		// Set up 2 overlapping bookings.
		$bookings = array(
			array(
				'start' => new DateTimeImmutable( '2026-04-01 09:30:00', $tz ),
				'end'   => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
			),
			array(
				'start' => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
				'end'   => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
			),
		);

		$count = $service->count_bookings_for_slot( $start, $end, $bookings );
		$this->assertSame( 2, $count, '重複する2件の予約をカウントすべき / Should count 2 overlapping bookings.' );
	}

	/**
	 * count_bookings_for_slot が重複しない予約をカウントしないことを検証する。
	 * Verify count_bookings_for_slot does not count non-overlapping bookings.
	 */
	public function test_count_bookings_for_slot_ignores_non_overlapping(): void {
		$tz       = new DateTimeZone( 'Asia/Tokyo' );
		$service  = new Availability_Service();
		$start    = new DateTimeImmutable( '2026-04-01 10:00:00', $tz );
		$end      = new DateTimeImmutable( '2026-04-01 11:00:00', $tz );

		// 重複しない予約。
		// Non-overlapping booking.
		$bookings = array(
			array(
				'start' => new DateTimeImmutable( '2026-04-01 11:00:00', $tz ),
				'end'   => new DateTimeImmutable( '2026-04-01 12:00:00', $tz ),
			),
		);

		$count = $service->count_bookings_for_slot( $start, $end, $bookings );
		$this->assertSame( 0, $count, '重複しない予約はカウントしないべき / Should not count non-overlapping bookings.' );
	}

	/**
	 * count_bookings_for_slot が空の予約リストで0を返すことを検証する。
	 * Verify count_bookings_for_slot returns 0 for empty bookings.
	 */
	public function test_count_bookings_for_slot_empty_bookings(): void {
		$tz      = new DateTimeZone( 'Asia/Tokyo' );
		$service = new Availability_Service();
		$start   = new DateTimeImmutable( '2026-04-01 10:00:00', $tz );
		$end     = new DateTimeImmutable( '2026-04-01 11:00:00', $tz );

		$count = $service->count_bookings_for_slot( $start, $end, array() );
		$this->assertSame( 0, $count, '空の予約リストでは0を返すべき / Should return 0 for empty booking list.' );
	}

	/**
	 * get_menu_max_capacity のデフォルト値（未設定時に1）を検証する。
	 * Verify get_menu_max_capacity returns 1 when meta is not set.
	 */
	public function test_get_menu_max_capacity_default(): void {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		$service    = new Availability_Service();
		$menu_post  = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity   = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 1, $capacity, '未設定時はデフォルト1を返すべき / Should return default 1 when not set.' );
	}

	/**
	 * get_menu_max_capacity が設定値を返すことを検証する。
	 * Verify get_menu_max_capacity returns the configured value.
	 */
	public function test_get_menu_max_capacity_configured(): void {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );

		$service    = new Availability_Service();
		$menu_post  = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity   = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 5, $capacity, '設定値5を返すべき / Should return configured value 5.' );
	}

	/**
	 * get_menu_max_capacity が0以下の値を1に補正することを検証する。
	 * Verify get_menu_max_capacity clamps values below 1 to 1.
	 */
	public function test_get_menu_max_capacity_clamps_to_minimum(): void {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		update_post_meta( $menu_id, '_vkbm_max_capacity', 0 );

		$service    = new Availability_Service();
		$menu_post  = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity   = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 1, $capacity, '0以下の値は1に補正すべき / Should clamp 0 to 1.' );
	}

	/**
	 * collapse_slots_for_auto_assignment が max_capacity を反映することを検証する。
	 * Verify collapse_slots_for_auto_assignment reflects max_capacity.
	 */
	public function test_collapse_slots_with_capacity(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// 2人のスタッフが同じ時間帯に空いている場合。
		// 2 staff members available at the same time slot.
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 1, 'name' => 'Staff A' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 2, 'name' => 'Staff B' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
		);

		// max_capacity = 3 で collapse する。
		// Collapse with max_capacity = 3.
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は max_capacity の 3 であるべき / capacity should be max_capacity value 3.' );
		// remaining は max_capacity - total_booking_count = 3 - 0 = 3。
		// remaining = max_capacity - total_booking_count = 3 - 0 = 3.
		$this->assertSame( 3, $result[0]['remaining'], 'remaining は max_capacity(3) - 予約数(0) = 3 であるべき / remaining should be max_capacity(3) - bookings(0) = 3.' );
	}

	/**
	 * collapse_slots_for_auto_assignment で max_capacity=1 の場合（デフォルト動作）を検証する。
	 * Verify collapse_slots_for_auto_assignment default behavior with max_capacity=1.
	 */
	public function test_collapse_slots_default_capacity(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// 3人のスタッフが同じ時間帯に空いている場合。
		// 3 staff members available at the same time slot.
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 1, 'name' => 'Staff A' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 2, 'name' => 'Staff B' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '3-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 3, 'name' => 'Staff C' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
		);

		// max_capacity = 1（デフォルト）で collapse する。
		// Collapse with max_capacity = 1 (default).
		$result = $method->invoke( $service, $slots, 100, 1 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		$this->assertSame( 1, $result[0]['capacity'], 'デフォルトの capacity は 1 であるべき / Default capacity should be 1.' );
		$this->assertSame( 1, $result[0]['remaining'], 'remaining も 1 であるべき / remaining should also be 1.' );
	}

	/**
	 * build_slots_from_entry が booking_count をスロットに含むことを検証する。
	 * Verify build_slots_from_entry includes booking_count in slot data.
	 */
	public function test_build_slots_includes_booking_count(): void {
		$tz   = new DateTimeZone( 'Asia/Tokyo' );
		$date = '2026-04-01';

		// 10:00-10:30 に既存予約がある場合。
		// Existing booking at 10:00-10:30.
		$bookings = array(
			array(
				'start' => new DateTimeImmutable( '2026-04-01 10:00:00', $tz ),
				'end'   => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
			),
		);

		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'build_slots_from_entry' );
		$method->setAccessible( true );

		$shift_slots = array(
			array(
				'start' => '10:00',
				'end'   => '12:00',
			),
		);

		$result = $method->invoke(
			$service,
			$shift_slots,
			$date,
			$tz,
			30, // block_minutes
			30, // service_minutes
			null, // deadline_cutoff
			$bookings,
			10, // slot_step_minutes
			array() // fixed_start_times
		);

		// 10:00 スロットは既存予約と重複するのでスキップされる。
		// 10:00 slot conflicts and is skipped.
		// block_minutes=30 なので、次の利用可能スロットは 10:30 のはず。
		$this->assertNotEmpty( $result, '結果は空でないべき / Result should not be empty.' );

		// 最初のスロットが 10:30 から始まることを検証する。
		// Verify the first available slot starts at 10:30 (block_minutes=30).
		$first_slot_start = $result[0]['start']->format( 'H:i' );
		$this->assertSame( '10:30', $first_slot_start, '最初のスロットは 10:30 開始のはず / First slot should start at 10:30.' );

		// booking_count が含まれることを確認。
		// Verify booking_count is present.
		foreach ( $result as $slot ) {
			$this->assertArrayHasKey( 'booking_count', $slot, 'booking_count キーが存在すべき / booking_count key should exist.' );
		}
	}

	/**
	 * collapse_slots_for_auto_assignment で全スタッフに予約がある場合、
	 * remaining は max_capacity - total_booking_count になることを検証する。
	 * Verify that when all staff have bookings, remaining = max_capacity - total_bookings.
	 */
	public function test_collapse_slots_all_staff_booked(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// 2人のスタッフが同じ時間帯にそれぞれ1件ずつ予約済み（合計2件）。
		// 2 staff members each with 1 booking at the same time slot (total 2).
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 1, 'name' => 'Staff A' ),
				'capacity'         => 1,
				'remaining'        => 0,
				'booking_count'    => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 2, 'name' => 'Staff B' ),
				'capacity'         => 1,
				'remaining'        => 0,
				'booking_count'    => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
		);

		// max_capacity=3, 予約合計2件 → remaining = 3 - 2 = 1。
		// max_capacity=3, total bookings=2 → remaining = 3 - 2 = 1.
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は max_capacity の 3 であるべき / capacity should be max_capacity value 3.' );
		$this->assertSame( 1, $result[0]['remaining'], 'remaining は max_capacity(3) - 予約数(2) = 1 であるべき / remaining should be max_capacity(3) - bookings(2) = 1.' );
		// 予約済みスタッフも assignable_staff_ids に含まれる。
		// Booked staff are also included in assignable_staff_ids.
		$this->assertCount( 2, $result[0]['assignable_staff_ids'], '予約済みスタッフも含め2人が割り当て候補 / Both staff should be assignable.' );
	}

	/**
	 * collapse_slots_for_auto_assignment で一部のスタッフのみ予約がある場合を検証する。
	 * Verify partial bookings: some staff booked, some free.
	 */
	public function test_collapse_slots_partial_bookings(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// 3人のスタッフ：1人は予約あり、2人は空き。
		// 3 staff: 1 booked, 2 free.
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 1, 'name' => 'Staff A' ),
				'capacity'         => 1,
				'remaining'        => 0,
				'booking_count'    => 1,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 2, 'name' => 'Staff B' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'booking_count'    => 0,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '3-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 3, 'name' => 'Staff C' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'booking_count'    => 0,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
		);

		// max_capacity=3, 予約1件 → remaining = 3 - 1 = 2。
		// max_capacity=3, 1 booking → remaining = 3 - 1 = 2.
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は 3 であるべき / capacity should be 3.' );
		$this->assertSame( 2, $result[0]['remaining'], 'remaining は max_capacity(3) - 予約数(1) = 2 であるべき / remaining should be max_capacity(3) - bookings(1) = 2.' );
	}

	/**
	 * collapse_slots_for_auto_assignment でスタッフ1人・max_capacity=3 の場合を検証する。
	 * Verify single staff with max_capacity=3: remaining equals max_capacity.
	 */
	public function test_collapse_slots_single_staff_high_capacity(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// スタッフ1人、予約なし。
		// 1 staff member, no bookings.
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array( 'id' => 1, 'name' => 'Staff A' ),
				'capacity'         => 1,
				'remaining'        => 1,
				'booking_count'    => 0,
				'flags'            => array( 'is_last_slot_of_day' => false, 'requires_confirmation' => false ),
				'auto_assign'      => true,
			),
		);

		// max_capacity=3, スタッフ1人でも remaining=3。
		// max_capacity=3, even with 1 staff remaining should be 3.
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は 3 であるべき / capacity should be 3.' );
		$this->assertSame( 3, $result[0]['remaining'], 'スタッフ1人でも remaining は max_capacity(3) であるべき / remaining should be max_capacity(3) even with 1 staff.' );
	}
}
