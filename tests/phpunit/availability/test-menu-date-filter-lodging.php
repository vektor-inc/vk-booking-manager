<?php
/**
 * 宿泊のサイトの絞り込み検索（チェックイン日・チェックアウト日）で、その日程に予約できるメニューを残す処理のテスト。
 *
 * - Availability_Service::is_stay_bookable_for_menu(): 泊のプランがその日程で予約できるか（空室・締切・受付上限・客室・タグ）
 * - Menu_Date_Filter::filter_menu_ids(): 泊のプランは日程全体、時間枠のプランはチェックイン日1日で判定する
 * - Night_Strategy::validate_stay_range(): 日程の矛盾の判定
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use ReflectionClass;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Booking_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Availability\Menu_Date_Filter;
use VKBookingManager\Availability\Night_Strategy;
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;

/**
 * 宿泊のサイトの日程による絞り込みを検証するテストクラス。
 *
 * @group availability
 * @group menu-date-filter
 * @group lodging
 */
class Menu_Date_Filter_Lodging_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 元のサイトタイムゾーン文字列。
	 *
	 * @var string
	 */
	private string $original_timezone_string = '';

	/**
	 * テスト前に設定を退避し、宿泊の有効なサイトにする。
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => 'lodging',
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$this->reset_booking_cache_state();
	}

	/**
	 * テスト後に設定・キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		$_GET = array();
		Availability_Booking_Cache_Generation::flush_bump();
		$this->reset_booking_cache_state();
		update_option( 'timezone_string', $this->original_timezone_string );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 予約の世代番号クラスの未反映の状態を空にする（他のテストへ持ち越さない）。
	 */
	private function reset_booking_cache_state(): void {
		$reflection = new ReflectionClass( Availability_Booking_Cache_Generation::class );
		foreach ( array( 'pending_daily', 'pending_monthly', 'dirty_post_ids', 'captured_old_snapshot' ) as $name ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}
		$needs_flush = $reflection->getProperty( 'needs_flush' );
		$needs_flush->setAccessible( true );
		$needs_flush->setValue( null, false );
	}

	/**
	 * 今日から指定日数後の日付（サイトのタイムゾーン）を返す。
	 *
	 * @param int $days 日数。
	 * @return string Y-m-d。
	 */
	private function date_after( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * シフトを作る年月（今日から数か月分）の一覧を返す。
	 *
	 * @return array<int, string> 年月（Y-m）。
	 */
	private function get_shift_months(): array {
		$today = new DateTimeImmutable( 'now', wp_timezone() );

		return array(
			$today->format( 'Y-m' ),
			$today->modify( '+1 month' )->format( 'Y-m' ),
			$today->modify( '+2 month' )->format( 'Y-m' ),
		);
	}

	/**
	 * 客室（リソース）を作る。検証に使う期間はすべて販売日にする。
	 *
	 * @param string $title 客室名。
	 * @return int 客室の投稿 ID。
	 */
	private function create_room( string $title ): int {
		$room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		$this->create_shifts( $room_id, array(), array() );

		return $room_id;
	}

	/**
	 * リソースのシフトを検証に使う期間ぶん作る。
	 *
	 * @param int                   $resource_id  リソースの投稿 ID。
	 * @param array<string, string> $slot       勤務スロット（start / end）。空なら販売日だけで時間帯なし（客室用）。
	 * @param array<int, string>    $closed_dates 休業日にする日付（Y-m-d）。
	 */
	private function create_shifts( int $resource_id, array $slot, array $closed_dates ): void {
		foreach ( $this->get_shift_months() as $month ) {
			$days     = array();
			$last_day = (int) ( new DateTimeImmutable( $month . '-01' ) )->format( 't' );
			for ( $day = 1; $day <= $last_day; $day++ ) {
				$date_key     = sprintf( '%s-%02d', $month, $day );
				$days[ $day ] = array(
					'status' => in_array( $date_key, $closed_dates, true ) ? 'regular_holiday' : 'open',
					'slots'  => in_array( $date_key, $closed_dates, true ) || empty( $slot ) ? array() : array( $slot ),
				);
			}
			$this->factory()->post->create(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'meta_input'  => array(
						'_vkbm_shift_resource_id' => $resource_id,
						'_vkbm_shift_year'        => (int) substr( $month, 0, 4 ),
						'_vkbm_shift_month'       => (int) substr( $month, 5, 2 ),
						'_vkbm_shift_days'        => $days,
					),
				)
			);
		}
	}

	/**
	 * 泊のプランを作る。
	 *
	 * @param array<int, int>      $room_ids 担当の客室 ID。
	 * @param array<string, mixed> $meta     追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_night_plan( array $room_ids, array $meta = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', $room_ids );
		update_post_meta( $menu_id, '_vkbm_base_price', 10000 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', '0' );
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 時間枠のプラン（日帰り入浴・貸切風呂など）を作る。担当は勤務スロット（終日）を持つリソース1件。
	 *
	 * @param array<int, string> $closed_dates 担当リソースの休業日（Y-m-d）。
	 * @return int メニューの投稿 ID。
	 */
	private function create_time_slot_plan( array $closed_dates ): int {
		$resource_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->create_shifts(
			$resource_id,
			array(
				'start' => '00:00',
				'end'   => '23:59',
			),
			$closed_dates
		);

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $resource_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 30 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', '0' );
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );

		return $menu_id;
	}

	/**
	 * 客室の予約を作る。
	 *
	 * @param int    $room_id 客室 ID。
	 * @param string $start   開始（Y-m-d H:i:s）。
	 * @param string $end     終了（Y-m-d H:i:s）。
	 */
	private function create_booking( int $room_id, string $start, string $end ): void {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, Booking_Overlap::META_START, $start );
		update_post_meta( $booking_id, Booking_Overlap::META_END, $end );
		update_post_meta( $booking_id, Booking_Overlap::META_TOTAL_END, $end );
		update_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, $room_id );
		update_post_meta( $booking_id, Booking_Overlap::META_STATUS, 'confirmed' );
	}

	/**
	 * 判定に使うフィルターを作り、日程で絞り込んだメニュー ID を返す。
	 *
	 * @param array<int> $menu_ids       判定するメニュー ID。
	 * @param string     $check_in_date  チェックイン日。
	 * @param string     $check_out_date チェックアウト日。
	 * @param int        $staff_id       客室の ID（0 は指名なし）。
	 * @param array<int> $tag_ids        リソースタグのターム ID。
	 * @return array<int> 予約できるメニュー ID。
	 */
	private function filter( array $menu_ids, string $check_in_date, string $check_out_date, int $staff_id = 0, array $tag_ids = array() ): array {
		return ( new Menu_Date_Filter() )->filter_menu_ids( $menu_ids, $check_in_date, $staff_id, $tag_ids, $check_out_date );
	}

	/**
	 * 泊のプランは、その日程で空室が1室以上あるものだけ残ることを検証する。
	 */
	public function test_filter_keeps_night_plans_that_have_a_vacant_room(): void {
		$room_a  = $this->create_room( '客室A' );
		$room_b  = $this->create_room( '客室B' );
		$plan_ab = $this->create_night_plan( array( $room_a, $room_b ) );
		$plan_a  = $this->create_night_plan( array( $room_a ) );
		$plan_b  = $this->create_night_plan( array( $room_b ) );

		$check_in  = $this->date_after( 10 );
		$check_out = $this->date_after( 12 );

		// 客室Aだけ期間の途中の夜に予約が入っている。
		$this->create_booking( $room_a, $this->date_after( 11 ) . ' 15:00:00', $this->date_after( 12 ) . ' 10:00:00' );

		$test_cases = array(
			array(
				'test_condition_name' => '客室Aだけ満室 => A・Bの両方を担当するプランは残り、Aだけのプランは外れる（並びは渡した順）',
				'menu_ids'            => array( $plan_a, $plan_ab, $plan_b ),
				'expected'            => array( $plan_ab, $plan_b ),
			),
			array(
				'test_condition_name' => '客室Aだけのプランだけを渡す => 0 件',
				'menu_ids'            => array( $plan_a ),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->filter( $case['menu_ids'], $check_in, $check_out ), $case['test_condition_name'] );
		}

		// 予約と重ならない別の日程では、Aのプランも残る。
		$this->assertSame(
			array( $plan_a, $plan_ab, $plan_b ),
			$this->filter( array( $plan_a, $plan_ab, $plan_b ), $this->date_after( 20 ), $this->date_after( 22 ) ),
			'予約と重ならない日程は全プランが残る'
		);
	}

	/**
	 * すべての客室が満室の日程では、泊のプランがすべて外れることを検証する。
	 */
	public function test_filter_drops_night_plans_when_all_rooms_are_full(): void {
		$room_a  = $this->create_room( '客室A' );
		$room_b  = $this->create_room( '客室B' );
		$plan_ab = $this->create_night_plan( array( $room_a, $room_b ) );

		$this->create_booking( $room_a, $this->date_after( 10 ) . ' 15:00:00', $this->date_after( 11 ) . ' 10:00:00' );
		$this->create_booking( $room_b, $this->date_after( 10 ) . ' 15:00:00', $this->date_after( 11 ) . ' 10:00:00' );

		$this->assertSame( array(), $this->filter( array( $plan_ab ), $this->date_after( 10 ), $this->date_after( 11 ) ), '全室満室 => 0 件' );
		$this->assertSame( array( $plan_ab ), $this->filter( array( $plan_ab ), $this->date_after( 11 ), $this->date_after( 12 ) ), '満室の夜を含まない日程は残る' );
	}

	/**
	 * 予約受付の締切を過ぎた日程・受付上限日数を超えた日程は、予約できない扱いになることを検証する。
	 */
	public function test_filter_drops_night_plans_past_deadline_or_beyond_max_advance(): void {
		$room_id = $this->create_room( '客室A' );

		$test_cases = array(
			array(
				'test_condition_name' => '締切（チェックインの 24 時間前）を過ぎた日程 => 外れる',
				'meta'                => array( '_vkbm_reservation_deadline_hours' => '24' ),
				'check_in_days'       => 0,
				'expected_kept'       => false,
			),
			array(
				'test_condition_name' => '締切に間に合う日程 => 残る',
				'meta'                => array( '_vkbm_reservation_deadline_hours' => '24' ),
				'check_in_days'       => 10,
				'expected_kept'       => true,
			),
			array(
				'test_condition_name' => '受付上限 5 日を超えた日程 => 外れる',
				'meta'                => array( '_vkbm_max_advance_booking_days' => 5 ),
				'check_in_days'       => 10,
				'expected_kept'       => false,
			),
			array(
				'test_condition_name' => '受付上限 5 日の範囲内 => 残る',
				'meta'                => array(
					'_vkbm_max_advance_booking_days'   => 5,
					'_vkbm_reservation_deadline_hours' => '0',
				),
				'check_in_days'       => 3,
				'expected_kept'       => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$plan_id = $this->create_night_plan( array( $room_id ), $case['meta'] );
			$result  = $this->filter( array( $plan_id ), $this->date_after( $case['check_in_days'] ), $this->date_after( $case['check_in_days'] + 1 ) );

			$this->assertSame( $case['expected_kept'] ? array( $plan_id ) : array(), $result, $case['test_condition_name'] );
		}
	}

	/**
	 * 時間枠のプランは、チェックイン日1日で判定する（チェックアウト日が営業日でも、チェックイン日が休みなら外れる）ことを検証する。
	 */
	public function test_filter_judges_time_slot_plan_by_check_in_date_only(): void {
		$closed_day = $this->date_after( 10 );
		$open_day   = $this->date_after( 11 );

		$bath_plan  = $this->create_time_slot_plan( array( $closed_day ) );
		$night_plan = $this->create_night_plan( array( $this->create_room( '客室A' ) ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'チェックイン日が休み・チェックアウト日が営業日 => 時間枠のプランは外れ、泊のプランは残る',
				'check_in'            => $closed_day,
				'check_out'           => $open_day,
				'expected'            => array( $night_plan ),
			),
			array(
				'test_condition_name' => 'チェックイン日が営業日 => 時間枠のプランも泊のプランも残る（並びは渡した順）',
				'check_in'            => $open_day,
				'check_out'           => $this->date_after( 12 ),
				'expected'            => array( $bath_plan, $night_plan ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->filter( array( $bath_plan, $night_plan ), $case['check_in'], $case['check_out'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 客室の指定とリソースタグ（AND 条件）が、泊のプランの空室判定に反映されることを検証する。
	 */
	public function test_filter_applies_room_and_resource_tag_conditions_to_night_plans(): void {
		$room_a = $this->create_room( '客室A' );
		$room_b = $this->create_room( '客室B' );
		$plan   = $this->create_night_plan( array( $room_a, $room_b ) );

		$tag_view = (int) wp_insert_term( '露天風呂付き', Resource_Tag_Taxonomy::TAXONOMY )['term_id'];
		$tag_big  = (int) wp_insert_term( '和洋室', Resource_Tag_Taxonomy::TAXONOMY )['term_id'];
		// 客室Aは「和洋室」だけ、客室Bは「露天風呂付き」だけを持つ。
		wp_set_object_terms( $room_a, array( $tag_big ), Resource_Tag_Taxonomy::TAXONOMY );
		wp_set_object_terms( $room_b, array( $tag_view ), Resource_Tag_Taxonomy::TAXONOMY );

		$check_in  = $this->date_after( 10 );
		$check_out = $this->date_after( 11 );
		// 客室Bは満室。
		$this->create_booking( $room_b, $check_in . ' 15:00:00', $check_out . ' 10:00:00' );

		$test_cases = array(
			array(
				'test_condition_name' => '指定なし => 客室Aが空いているため残る',
				'staff_id'            => 0,
				'tag_ids'             => array(),
				'expected'            => array( $plan ),
			),
			array(
				'test_condition_name' => '客室Aを指定 => 残る',
				'staff_id'            => $room_a,
				'tag_ids'             => array(),
				'expected'            => array( $plan ),
			),
			array(
				'test_condition_name' => '満室の客室Bを指定 => 外れる',
				'staff_id'            => $room_b,
				'tag_ids'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'タグ「和洋室」（客室A）=> 残る',
				'staff_id'            => 0,
				'tag_ids'             => array( $tag_big ),
				'expected'            => array( $plan ),
			),
			array(
				'test_condition_name' => 'タグ「露天風呂付き」（満室の客室B）=> 外れる',
				'staff_id'            => 0,
				'tag_ids'             => array( $tag_view ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'タグを2つとも指定（両方を持つ客室が無い）=> 外れる',
				'staff_id'            => 0,
				'tag_ids'             => array( $tag_big, $tag_view ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '客室Aを指定しタグ「露天風呂付き」（客室Aは持たない）=> 外れる',
				'staff_id'            => $room_a,
				'tag_ids'             => array( $tag_view ),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->filter( array( $plan ), $check_in, $check_out, $case['staff_id'], $case['tag_ids'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * チェックアウト日が無いときは絞り込まず、日程に矛盾があるときは予約できるメニューなしにすることを検証する。
	 */
	public function test_filter_handles_incomplete_and_contradictory_stay(): void {
		$room_id  = $this->create_room( '客室A' );
		$plan     = $this->create_night_plan( array( $room_id ) );
		$check_in = $this->date_after( 10 );

		$test_cases = array(
			array(
				'test_condition_name' => 'チェックアウト日が空 => 絞り込まず全件',
				'check_out'           => '',
				'expected'            => array( $plan ),
			),
			array(
				'test_condition_name' => 'チェックアウト日が実在しない日付 => 絞り込まず全件',
				'check_out'           => '2026-02-31',
				'expected'            => array( $plan ),
			),
			array(
				'test_condition_name' => 'チェックアウト日がチェックイン日と同じ => 予約できるメニューなし',
				'check_out'           => $check_in,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'チェックアウト日がチェックイン日より前 => 予約できるメニューなし',
				'check_out'           => $this->date_after( 9 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '31 泊（上限超え）=> 予約できるメニューなし',
				'check_out'           => $this->date_after( 41 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '30 泊（上限ちょうど）=> 空室があれば残る',
				'check_out'           => $this->date_after( 40 ),
				'expected'            => array( $plan ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $this->filter( array( $plan ), $check_in, $case['check_out'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * validate_stay_range() が、チェックアウト日の順序と泊数の上限の矛盾をエラーコード付きで返すことを検証する。
	 */
	public function test_validate_stay_range(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1 泊 => 矛盾なし',
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-11',
				'expected_code'       => null,
			),
			array(
				'test_condition_name' => '30 泊 => 矛盾なし',
				'check_in'            => '2098-10-01',
				'check_out'           => '2098-10-31',
				'expected_code'       => null,
			),
			array(
				'test_condition_name' => '同じ日 => invalid_date_range',
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-10',
				'expected_code'       => 'invalid_date_range',
			),
			array(
				'test_condition_name' => 'チェックアウトが前 => invalid_date_range',
				'check_in'            => '2098-10-10',
				'check_out'           => '2098-10-05',
				'expected_code'       => 'invalid_date_range',
			),
			array(
				'test_condition_name' => '31 泊 => too_many_nights',
				'check_in'            => '2098-10-01',
				'check_out'           => '2098-11-01',
				'expected_code'       => 'too_many_nights',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = Night_Strategy::validate_stay_range( $case['check_in'], $case['check_out'] );

			if ( null === $case['expected_code'] ) {
				$this->assertNull( $result, $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected_code'], $result->get_error_code(), $case['test_condition_name'] );
			$this->assertSame( 400, $result->get_error_data()['status'], $case['test_condition_name'] . ' : status' );
		}
	}

	/**
	 * 判定結果が短時間キャッシュされ、期間の途中の日に予約が入って世代番号が進むと再計算されることを検証する。
	 */
	public function test_filter_caches_result_until_booking_generation_changes(): void {
		$room_id = $this->create_room( '客室A' );
		$plan    = $this->create_night_plan( array( $room_id ) );

		$check_in  = $this->date_after( 10 );
		$check_out = $this->date_after( 14 );

		$this->assertSame( array( $plan ), $this->filter( array( $plan ), $check_in, $check_out ), '1回目は空室があるため残る（結果がキャッシュされる）' );

		// チェックイン日でもチェックアウト日でもない、期間の途中の夜に予約が入る。
		$this->create_booking( $room_id, $this->date_after( 12 ) . ' 15:00:00', $this->date_after( 13 ) . ' 10:00:00' );

		$this->assertSame( array( $plan ), $this->filter( array( $plan ), $check_in, $check_out ), '世代番号が進む前はキャッシュが返る' );

		Availability_Booking_Cache_Generation::flush_bump();
		$this->assertSame( array(), $this->filter( array( $plan ), $check_in, $check_out ), '期間の途中の日の予約でキャッシュが無効になり、満室で外れる' );
	}

	/**
	 * 日程の絞り込みのキャッシュ名が、時間枠の日付絞り込みとは別の区分で、日程・客室・タグごとに分かれることを検証する。
	 */
	public function test_build_cache_key_for_stay_is_separate_from_single_date(): void {
		$filter = new Menu_Date_Filter();
		$method = new \ReflectionMethod( Menu_Date_Filter::class, 'build_cache_key' );
		$method->setAccessible( true );

		$single = $method->invoke( $filter, '2098-10-10', 0, array() );
		$stay   = $method->invoke( $filter, '2098-10-10', 0, array(), '2098-10-12' );

		$this->assertStringStartsWith( 'vkbm_menudate_', $single, '日付1つは従来のキャッシュ名' );
		$this->assertStringStartsWith( 'vkbm_menustay_', $stay, '日程は別の区分' );
		$this->assertLessThanOrEqual( 172, strlen( $stay ), 'transient 名の長さの上限に収まる' );
		$this->assertNotSame( $stay, $method->invoke( $filter, '2098-10-10', 0, array(), '2098-10-13' ), 'チェックアウト日が違えば別のキャッシュ' );
		$this->assertNotSame( $stay, $method->invoke( $filter, '2098-10-10', 5, array(), '2098-10-12' ), '客室が違えば別のキャッシュ' );
		$this->assertNotSame( $stay, $method->invoke( $filter, '2098-10-10', 0, array( 3 ), '2098-10-12' ), 'タグが違えば別のキャッシュ' );
		$this->assertSame( $stay, $method->invoke( $filter, '2098-10-10', 0, array(), '2098-10-12' ), '同じ条件は同じキャッシュ名' );
	}

	/**
	 * 存在しないリソースタグの ID は、キャッシュ名を作る前に落とされる（キャッシュ名が増え続けない）ことを検証する。
	 *
	 * 前提: 存在しないタグを含めて指定しても一覧が広がらないのは、呼び出し側が日付の判定より前にタグで絞り込んでいるため。
	 * このテストは、その前提のうえで、存在しないタグがキャッシュ名に混ざらないことを確かめる。
	 */
	public function test_filter_drops_unknown_resource_tag_ids_before_building_cache_key(): void {
		$room_id = $this->create_room( '客室A' );
		$plan    = $this->create_night_plan( array( $room_id ) );
		$bath    = $this->create_time_slot_plan( array() );

		$check_in  = $this->date_after( 10 );
		$check_out = $this->date_after( 11 );
		$unknown   = 987654;

		// 存在しないタグは「指定なし」と同じ結果になる。
		$this->assertSame(
			array( $plan, $bath ),
			$this->filter( array( $plan, $bath ), $check_in, $check_out, 0, array( $unknown ) ),
			'前提（日付の判定より前にタグで絞り込み済み）のもとで、存在しないタグは無視され、泊のプランも時間枠のプランも残る'
		);

		// キャッシュ名に存在しないタグの ID が混ざらない（タグ指定なしと同じキャッシュを使う）。
		$transient_names = $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\_transient\_vkbm\_menustay\_%'" );
		$this->assertCount( 1, $transient_names, '存在しないタグのぶん別のキャッシュが作られない' );
	}

	/**
	 * 期間の世代番号のハッシュが、期間内のどの日の予約でも変わり、期間外では変わらないことを検証する。
	 */
	public function test_get_range_generation_hash_changes_with_any_day_in_range(): void {
		$room_id = $this->create_room( '客室A' );
		$from    = $this->date_after( 10 );
		$to      = $this->date_after( 14 );

		$before = Availability_Booking_Cache_Generation::get_range_generation_hash( $from, $to );
		$this->assertSame( $before, Availability_Booking_Cache_Generation::get_range_generation_hash( $from, $to ), '同じ期間は同じハッシュ' );
		$this->assertSame( 32, strlen( $before ), 'md5 の長さ' );

		// 期間の途中の日の世代番号が進むと、ハッシュが変わる。
		$this->create_booking( $room_id, $this->date_after( 12 ) . ' 15:00:00', $this->date_after( 13 ) . ' 10:00:00' );
		Availability_Booking_Cache_Generation::flush_bump();
		$after = Availability_Booking_Cache_Generation::get_range_generation_hash( $from, $to );
		$this->assertNotSame( $before, $after, '期間内の日の世代番号が進むとハッシュが変わる' );

		// 期間の外の日の世代番号が進んでも、ハッシュは変わらない。
		$this->create_booking( $room_id, $this->date_after( 20 ) . ' 15:00:00', $this->date_after( 21 ) . ' 10:00:00' );
		Availability_Booking_Cache_Generation::flush_bump();
		$this->assertSame( $after, Availability_Booking_Cache_Generation::get_range_generation_hash( $from, $to ), '期間外の日では変わらない' );
	}

	/**
	 * Availability_Service::is_stay_bookable_for_menu() が、時間枠のプランと存在しないメニューを予約できない扱いにすることを検証する。
	 */
	public function test_is_stay_bookable_for_menu_rejects_non_night_plan(): void {
		$service   = new Availability_Service();
		$time_plan = $this->create_time_slot_plan( array() );
		$check_in  = $this->date_after( 10 );
		$check_out = $this->date_after( 11 );

		$this->assertFalse( $service->is_stay_bookable_for_menu( $time_plan, $check_in, $check_out ), '時間枠のプランは泊の判定では予約できない扱い' );
		$this->assertFalse( $service->is_stay_bookable_for_menu( 0, $check_in, $check_out ), '存在しないメニューは予約できない扱い' );

		$night_plan = $this->create_night_plan( array( $this->create_room( '客室A' ) ) );
		$this->assertTrue( $service->is_stay_bookable_for_menu( $night_plan, $check_in, $check_out ), '泊のプランは空室があれば予約できる' );
	}

	/**
	 * 宿泊のサイトでは、チェックイン日・チェックアウト日が矛盾なくそろっているときだけ両方を引き継ぐことを検証する。
	 */
	public function test_normalize_carry_dates_on_lodging_site(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'チェックイン日とチェックアウト日がそろっている => 両方残す',
				'date'                => '2098-10-20',
				'check_out_date'      => '2098-10-22',
				'expected'            => array(
					'date'           => '2098-10-20',
					'check_out_date' => '2098-10-22',
				),
			),
			array(
				'test_condition_name' => 'チェックアウト日が空 => 日程が不完全なので両方捨てる',
				'date'                => '2098-10-20',
				'check_out_date'      => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'チェックアウト日がチェックイン日以前 => 両方捨てる',
				'date'                => '2098-10-20',
				'check_out_date'      => '2098-10-20',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '泊数が上限超え => 両方捨てる',
				'date'                => '2098-10-01',
				'check_out_date'      => '2098-11-01',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'チェックアウト日が実在しない日付 => 両方捨てる',
				'date'                => '2098-10-20',
				'check_out_date'      => '2098-02-31',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'チェックイン日が実在しない日付 => 両方捨てる',
				'date'                => '2098-02-31',
				'check_out_date'      => '2098-10-22',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Menu_Date_Filter::normalize_carry_dates( $case['date'], $case['check_out_date'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 宿泊のサイトで carry_dates_from_request() が date と check_out_date を取り出すことを検証する。
	 */
	public function test_carry_dates_from_request_on_lodging_site(): void {
		$_GET = array(
			'date'           => '2098-10-20',
			'check_out_date' => '2098-10-22',
		);
		$this->assertSame(
			array(
				'date'           => '2098-10-20',
				'check_out_date' => '2098-10-22',
			),
			Menu_Date_Filter::carry_dates_from_request()
		);

		$_GET = array(
			'date'           => '2098-10-20',
			'check_out_date' => '2098-10-19',
		);
		$this->assertSame( array(), Menu_Date_Filter::carry_dates_from_request(), '矛盾する日程は引き継がない' );

		$_GET = array( 'date' => '2098-10-20' );
		$this->assertSame( array(), Menu_Date_Filter::carry_dates_from_request(), 'チェックアウト日が無い日程は引き継がない' );
		$_GET = array();
	}
}
