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
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * Tests for capacity feature in Availability_Service.
 *
 * @group availability
 * @group capacity
 */
class Capacity_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に指名機能・複数人予約機能の静的キャッシュをクリアする。
	 */
	protected function tearDown(): void {
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 複数人予約機能を利用可能な状態（指名OFF・複数人予約ON）にする。
	 *
	 * get_menu_max_capacity はメニュー単位の最大受付数を返す前に、
	 * 指名機能OFF かつ 複数人予約機能ON のゲートを通す仕様のため、
	 * メタ読み取りの挙動を検証するテストではこの前提を整える。
	 */
	private function enable_multiple_guests_context(): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * count_guests_for_slot の重複判定・人数合計を検証する。
	 *
	 * 判定スロットは 10:00-11:00 固定。各ケースの予約リストに対し、
	 * 重複する予約の人数合計（人数未設定は1名扱い）が返ることを確認する。
	 */
	public function test_count_guests_for_slot(): void {
		$tz      = new DateTimeZone( 'Asia/Tokyo' );
		$service = new Availability_Service();
		$start   = new DateTimeImmutable( '2026-04-01 10:00:00', $tz );
		$end     = new DateTimeImmutable( '2026-04-01 11:00:00', $tz );

		$test_cases = array(
			array(
				'test_condition_name' => '重複する2件（いずれも人数未設定）の場合 => 2（正常系）',
				'bookings'            => array(
					array(
						'start' => new DateTimeImmutable( '2026-04-01 09:30:00', $tz ),
						'end'   => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
					),
					array(
						'start' => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'end'   => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
					),
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '重複する2件（3名 + 人数未設定で1名扱い）の場合 => 4（正常系・複数人）',
				'bookings'            => array(
					array(
						'start'  => new DateTimeImmutable( '2026-04-01 09:30:00', $tz ),
						'end'    => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'guests' => 3,
					),
					array(
						'start' => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'end'   => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
					),
				),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '重複しない予約のみの場合 => 0（境界値）',
				'bookings'            => array(
					array(
						'start' => new DateTimeImmutable( '2026-04-01 11:00:00', $tz ),
						'end'   => new DateTimeImmutable( '2026-04-01 12:00:00', $tz ),
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '予約リストが空の場合 => 0（境界値）',
				'bookings'            => array(),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$count = $service->count_guests_for_slot( $start, $end, $case['bookings'] );
			$this->assertSame( $case['expected'], $count, $case['test_condition_name'] );
		}
	}

	/**
	 * get_menu_max_capacity のデフォルト値（未設定時に1）を検証する。
	 * Verify get_menu_max_capacity returns 1 when meta is not set.
	 */
	public function test_get_menu_max_capacity_default(): void {
		// メタ読み取りの挙動を検証するため、複数人予約が利用可能な前提を整える。
		$this->enable_multiple_guests_context();

		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		$service   = new Availability_Service();
		$menu_post = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 1, $capacity, '未設定時はデフォルト1を返すべき / Should return default 1 when not set.' );
	}

	/**
	 * get_menu_max_capacity が設定値を返すことを検証する。
	 * Verify get_menu_max_capacity returns the configured value.
	 */
	public function test_get_menu_max_capacity_configured(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// メタ読み取りの挙動を検証するため、複数人予約が利用可能な前提を整える。
		$this->enable_multiple_guests_context();

		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );

		$service   = new Availability_Service();
		$menu_post = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 5, $capacity, '設定値5を返すべき / Should return configured value 5.' );
	}

	/**
	 * get_menu_max_capacity が0以下の値を1に補正することを検証する。
	 * Verify get_menu_max_capacity clamps values below 1 to 1.
	 */
	public function test_get_menu_max_capacity_clamps_to_minimum(): void {
		// メタ読み取りの挙動を検証するため、複数人予約が利用可能な前提を整える。
		$this->enable_multiple_guests_context();

		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Menu',
			)
		);

		update_post_meta( $menu_id, '_vkbm_max_capacity', 0 );

		$service   = new Availability_Service();
		$menu_post = get_post( $menu_id );
		$this->assertInstanceOf( \WP_Post::class, $menu_post, 'get_post() は WP_Post を返すべき / get_post() should return WP_Post.' );
		$capacity = $service->get_menu_max_capacity( $menu_post );

		$this->assertSame( 1, $capacity, '0以下の値は1に補正すべき / Should clamp 0 to 1.' );
	}

	/**
	 * get_menu_max_capacity が複数人予約機能の全体設定・指名機能の状態でゲートされることを検証する（issue #281）。
	 *
	 * メニューに最大受付数5が設定されていても、複数人予約が利用できない状態（複数人予約OFF／指名ON）では
	 * 1対1予約のため上限1に固定される。option 未設定（既存サイト相当）かつ指名OFFのときは従来どおり設定値を返す。
	 */
	public function test_get_menu_max_capacity_gated_by_settings(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'option未設定（既存サイト相当）・指名OFF → 設定値5（後方互換：未設定は有効）',
				'multiple_guests'     => 'unset',
				'nomination_enabled'  => false,
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '複数人予約ON・指名OFF → 設定値5（明示有効）',
				'multiple_guests'     => 'enabled',
				'nomination_enabled'  => false,
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '複数人予約OFF（明示無効）・指名OFF → 上限1に固定（設定で抑止）',
				'multiple_guests'     => 'disabled',
				'nomination_enabled'  => false,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '複数人予約ON・指名ON → 上限1に固定（指名ONで1対1）',
				'multiple_guests'     => 'enabled',
				'nomination_enabled'  => true,
				'expected'            => 1,
			),
		);

		$service = new Availability_Service();

		foreach ( $test_cases as $case ) {
			// 全体設定を組み立てる。
			$repository                = new Settings_Repository();
			$settings                  = $repository->get_settings();
			$settings['staff_enabled'] = $case['nomination_enabled'];
			if ( 'unset' === $case['multiple_guests'] ) {
				unset( $settings['slot_capacity_enabled'] );
			} else {
				$settings['slot_capacity_enabled'] = ( 'enabled' === $case['multiple_guests'] );
			}
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			// メニューに最大受付数5を設定する。
			$menu_id = $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );

			$menu_post = get_post( $menu_id );
			$this->assertInstanceOf( \WP_Post::class, $menu_post, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected'],
				$service->get_menu_max_capacity( $menu_post ),
				$case['test_condition_name']
			);

			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * collapse_slots_for_auto_assignment が capacity=max_capacity・remaining=最も空きの大きい単一スタッフの残り
	 * を返すことを検証する（1予約は分割せず単一スタッフへ割り当てるため）。
	 * Verify collapse uses capacity=max_capacity and remaining=max single-staff remaining.
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
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 2,
					'name' => 'Staff B',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
		);

		// max_capacity = 3 で collapse する。
		// Collapse with max_capacity = 3.
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		// capacity = max_capacity(3)（1スタッフの上限）。
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は max_capacity(3) であるべき' );
		// remaining = 最も空きの大きい単一スタッフの残り = 3-0 = 3。
		$this->assertSame( 3, $result[0]['remaining'], 'remaining は最も空きの大きい単一スタッフの残り(3) であるべき' );
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
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 2,
					'name' => 'Staff B',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '3-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 3,
					'name' => 'Staff C',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
		);

		// max_capacity = 1（デフォルト）で collapse する。
		// Collapse with max_capacity = 1 (default).
		$result = $method->invoke( $service, $slots, 100, 1 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき / Slots should be collapsed into 1.' );
		// capacity = max_capacity(1)。remaining = 最も空きの大きい単一スタッフの残り = 1-0 = 1。
		$this->assertSame( 1, $result[0]['capacity'], 'capacity は max_capacity(1) であるべき' );
		$this->assertSame( 1, $result[0]['remaining'], 'remaining は 1 であるべき' );
	}

	/**
	 * build_slots_from_entry が guest_count をスロットに含むことを検証する。
	 * Verify build_slots_from_entry includes guest_count in slot data.
	 */
	public function test_build_slots_includes_guest_count(): void {
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

		// guest_count が含まれることを確認。
		// Verify guest_count is present.
		foreach ( $result as $slot ) {
			$this->assertArrayHasKey( 'guest_count', $slot, 'guest_count キーが存在すべき / guest_count key should exist.' );
		}
	}

	/**
	 * collapse_slots_for_auto_assignment で全スタッフに予約がある場合、
	 * remaining は最も空きの大きい単一スタッフの残りになることを検証する。
	 * Verify that when all staff have bookings, remaining = max single-staff remaining.
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
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 0,
				'guest_count'      => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 2,
					'name' => 'Staff B',
				),
				'capacity'         => 1,
				'remaining'        => 0,
				'guest_count'      => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
		);

		// capacity = max_capacity(3)。各スタッフ残り = 3-1 = 2 → remaining = 最大値 2。
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は max_capacity(3) であるべき' );
		$this->assertSame( 2, $result[0]['remaining'], 'remaining は最も空きの大きい単一スタッフの残り(3-1=2) であるべき' );
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
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 0,
				'guest_count'      => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 2,
					'name' => 'Staff B',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'guest_count'      => 0,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '3-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 3,
					'name' => 'Staff C',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'guest_count'      => 0,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
		);

		// capacity = max_capacity(3)。空きスタッフ B/C の残り = 3-0 = 3 → remaining = 最大値 3。
		$result = $method->invoke( $service, $slots, 100, 3 );

		$this->assertCount( 1, $result, 'スロットは1つに集約されるべき' );
		$this->assertSame( 3, $result[0]['capacity'], 'capacity は max_capacity(3) であるべき' );
		$this->assertSame( 3, $result[0]['remaining'], 'remaining は最も空きの大きい単一スタッフの残り(空きスタッフ 3-0=3) であるべき' );
	}

	/**
	 * get_menu_min_capacity が最小催行人数（グループ開催型）を返すこと、
	 * および各種ゲート・クランプが効くことを検証する。
	 *
	 * - 未設定・最大受付数1（1対1）では 0（制約なし）。
	 * - 設定値は最大受付数を上限にクランプされる。
	 * - 複数人予約OFF／指名ONでは最大受付数が1に固定されるため 0 を返す。
	 */
	public function test_get_menu_min_capacity(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$service = new Availability_Service();

		$test_cases = array(
			array(
				'test_condition_name' => '複数人予約ON・指名OFF・最大5・最小3 => 3（正常系）',
				'multiple_guests'     => true,
				'nomination_enabled'  => false,
				'max_capacity'        => 5,
				'min_capacity'        => 3,
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '複数人予約ON・指名OFF・最大3・最小未設定 => 0（正常系：制約なし＝後方互換）',
				'multiple_guests'     => true,
				'nomination_enabled'  => false,
				'max_capacity'        => 3,
				'min_capacity'        => null,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '複数人予約ON・指名OFF・最大3・最小5（max超過） => 3にクランプ（境界値）',
				'multiple_guests'     => true,
				'nomination_enabled'  => false,
				'max_capacity'        => 3,
				'min_capacity'        => 5,
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '複数人予約OFF・指名OFF・最大5・最小3 => 0（最大受付数1固定で制約なし＝ゲート）',
				'multiple_guests'     => false,
				'nomination_enabled'  => false,
				'max_capacity'        => 5,
				'min_capacity'        => 3,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '複数人予約ON・指名ON・最大5・最小3 => 0（指名ONで1対1のため制約なし＝ゲート）',
				'multiple_guests'     => true,
				'nomination_enabled'  => true,
				'max_capacity'        => 5,
				'min_capacity'        => 3,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			// 全体設定（指名・複数人予約）を組み立てる。
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['staff_enabled']         = $case['nomination_enabled'];
			$settings['slot_capacity_enabled'] = $case['multiple_guests'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id = $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			if ( null !== $case['min_capacity'] ) {
				update_post_meta( $menu_id, '_vkbm_min_capacity', $case['min_capacity'] );
			}

			$menu_post = get_post( $menu_id );
			$this->assertInstanceOf( \WP_Post::class, $menu_post, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected'],
				$service->get_menu_min_capacity( $menu_post ),
				$case['test_condition_name']
			);

			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * collapse_slots_for_auto_assignment が min_capacity と booked_guests（スタッフ横断の合計予約人数）を
	 * スロットに含めることを検証する。
	 *
	 * グループ開催型では同一時間帯がスタッフ横断で1枠に集約されるため、
	 * booked_guests は各スタッフ枠の guest_count の合計になる。
	 */
	public function test_collapse_slots_includes_min_capacity_and_booked_guests(): void {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'collapse_slots_for_auto_assignment' );
		$method->setAccessible( true );

		// 同一時間帯に2スタッフ（A:2名予約 / B:1名予約）が相乗りしている状況。
		$slots = array(
			array(
				'slot_id'          => '1-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 0,
				'guest_count'      => 2,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
			array(
				'slot_id'          => '2-20260401100000',
				'start_at'         => '2026-04-01T10:00:00+09:00',
				'end_at'           => '2026-04-01T11:00:00+09:00',
				'service_end_at'   => '2026-04-01T10:50:00+09:00',
				'duration_minutes' => 50,
				'staff'            => array(
					'id'   => 2,
					'name' => 'Staff B',
				),
				'capacity'         => 1,
				'remaining'        => 0,
				'guest_count'      => 1,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
				'auto_assign'      => true,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '最小催行人数4・合計予約3名 => min_capacity=4, booked_guests=3（未達ケース）',
				'min_capacity'        => 4,
				'expected'            => array(
					'min_capacity'  => 4,
					'booked_guests' => 3,
				),
			),
			array(
				'test_condition_name' => '最小催行人数0（制約なし）・合計予約3名 => min_capacity=0, booked_guests=3（後方互換）',
				'min_capacity'        => 0,
				'expected'            => array(
					'min_capacity'  => 0,
					'booked_guests' => 3,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// max_capacity=5（合計3名が入れる余地あり）、min_capacity をケースごとに変える。
			$result = $method->invoke( $service, $slots, 100, 5, $case['min_capacity'] );

			$this->assertCount( 1, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected']['min_capacity'], $result[0]['min_capacity'], $case['test_condition_name'] );
			// booked_guests は A(2) + B(1) = 3（スタッフ横断の合計）。
			$this->assertSame( $case['expected']['booked_guests'], $result[0]['booked_guests'], $case['test_condition_name'] );
		}
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
				'staff'            => array(
					'id'   => 1,
					'name' => 'Staff A',
				),
				'capacity'         => 1,
				'remaining'        => 1,
				'guest_count'      => 0,
				'flags'            => array(
					'is_last_slot_of_day'   => false,
					'requires_confirmation' => false,
				),
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
