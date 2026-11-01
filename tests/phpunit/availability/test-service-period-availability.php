<?php
/**
 * サービスメニューの提供期間が、空き状況の計算に効くことのテスト（#972）。
 *
 * 時間枠のプラン（月カレンダー・日別の時間枠・終了済みメニューの案内）と、
 * 泊のプラン（チェックイン日が期間内なら可。終了日をまたぐ連泊も可）を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Common\Service_Period;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;

/**
 * 提供期間と空き状況のテストクラス。
 *
 * @group availability
 * @group service-period
 */
class Service_Period_Availability_Test extends WP_UnitTestCase {

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
	private $original_timezone_string = '';

	/**
	 * サイトのタイムゾーンを固定し、全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->trash_all_resources();
	}

	/**
	 * 設定・タイムゾーン・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
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
	 * 公開中のリソースを全て削除する（無料版の「公開中のリソースは1件」の前提に揃える）。
	 */
	private function trash_all_resources(): void {
		$existing = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $existing as $resource_id ) {
			wp_delete_post( (int) $resource_id, true );
		}
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
	 * 指定した年月の全日に、同じ状態・同じ勤務時間のシフトを作る。
	 *
	 * @param int    $resource_id リソース ID。
	 * @param string $month       年月（Y-m）。
	 * @param string $status      シフトの状態。
	 * @param array  $slots       勤務時間帯。
	 */
	private function create_month_shift( int $resource_id, string $month, string $status, array $slots ): void {
		$days = array();
		for ( $day = 1; $day <= 31; $day++ ) {
			$days[ $day ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $resource_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', (int) substr( $month, 0, 4 ) );
		update_post_meta( $shift_id, '_vkbm_shift_month', (int) substr( $month, 5, 2 ) );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );
	}

	/**
	 * 時間枠のプランのメニューを作る（来月から数か月の全日 10:00〜11:00 に枠がある）。
	 *
	 * @param array<string, mixed> $meta 追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_slot_menu( array $meta = array() ): int {
		$this->trash_all_resources();
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$today    = new DateTimeImmutable( 'now', wp_timezone() );
		foreach ( array( 1, 2, 3 ) as $offset ) {
			$this->create_month_shift(
				$staff_id,
				$today->modify( sprintf( 'first day of +%d month', $offset ) )->format( 'Y-m' ),
				'open',
				array(
					array(
						'start' => '10:00',
						'end'   => '11:00',
					),
				)
			);
		}

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 2か月先の月の「日」→ 予約できる枠の数を、月カレンダーから取り出す。
	 *
	 * @param int $menu_id メニュー ID。
	 * @return array<string, int> 日付（Y-m-d）をキーにした予約できる枠の数。
	 */
	private function get_calendar_slot_counts( int $menu_id ): array {
		$target = ( new DateTimeImmutable( 'first day of +2 month', wp_timezone() ) );
		$result = ( new Availability_Service() )->get_calendar_meta(
			array(
				'menu_id' => $menu_id,
				'year'    => (int) $target->format( 'Y' ),
				'month'   => (int) $target->format( 'n' ),
			)
		);
		$this->assertIsArray( $result );

		$counts = array();
		foreach ( $result['days'] as $day ) {
			$counts[ $day['date'] ] = (int) $day['available_slots'];
		}

		return $counts;
	}

	/**
	 * 2か月先の月の指定日（Y-m-d）を返す。
	 *
	 * @param int $day 日。
	 * @return string Y-m-d。
	 */
	private function target_month_date( int $day ): string {
		return ( new DateTimeImmutable( 'first day of +2 month', wp_timezone() ) )->format( 'Y-m-' ) . sprintf( '%02d', $day );
	}

	/**
	 * 月カレンダーと日別の時間枠: 提供期間の外の日は枠が出ず、両端の日は出る。
	 */
	public function test_calendar_and_daily_slots_respect_service_period(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日・終了日とも指定（10日〜20日） => 10日と20日は予約でき、9日と21日は予約できない',
				'meta'                => array(
					Service_Period::META_START => $this->target_month_date( 10 ),
					Service_Period::META_END   => $this->target_month_date( 20 ),
				),
				'bookable'            => array( 10, 15, 20 ),
				'not_bookable'        => array( 1, 9, 21, 28 ),
			),
			array(
				'test_condition_name' => '開始日のみ（10日から） => 9日は予約できず、10日以降は予約できる',
				'meta'                => array( Service_Period::META_START => $this->target_month_date( 10 ) ),
				'bookable'            => array( 10, 28 ),
				'not_bookable'        => array( 1, 9 ),
			),
			array(
				'test_condition_name' => '終了日のみ（20日まで） => 20日までは予約でき、21日以降は予約できない',
				'meta'                => array( Service_Period::META_END => $this->target_month_date( 20 ) ),
				'bookable'            => array( 1, 20 ),
				'not_bookable'        => array( 21, 28 ),
			),
			array(
				'test_condition_name' => '期間の指定なし => 全日予約できる（従来どおり）',
				'meta'                => array(),
				'bookable'            => array( 1, 15, 28 ),
				'not_bookable'        => array(),
			),
			array(
				'test_condition_name' => '提供期間（10日〜20日）と予約可能日（平日のみ）の両方を満たす日だけ予約できる',
				'meta'                => array(
					Service_Period::META_START   => $this->target_month_date( 10 ),
					Service_Period::META_END     => $this->target_month_date( 20 ),
					'_vkbm_reservation_day_type' => 'weekday',
				),
				'bookable'            => array(),
				'not_bookable'        => array( 1, 9, 21 ),
				'weekday_rule'        => array( 10, 20 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_slot_menu( $case['meta'] );
			$counts  = $this->get_calendar_slot_counts( $menu_id );
			$service = new Availability_Service();

			foreach ( $case['bookable'] as $day ) {
				$date = $this->target_month_date( $day );
				$this->assertGreaterThan( 0, $counts[ $date ] ?? 0, $case['test_condition_name'] . ' / ' . $date . ' はカレンダーで予約できる' );
				$daily = $service->get_daily_slots(
					array(
						'menu_id' => $menu_id,
						'date'    => $date,
					)
				);
				$this->assertIsArray( $daily, $case['test_condition_name'] . ' / ' . $date . ' の日別' );
				$this->assertNotEmpty( $daily['slots'], $case['test_condition_name'] . ' / ' . $date . ' は日別の時間枠が出る' );
			}

			foreach ( $case['not_bookable'] as $day ) {
				$date = $this->target_month_date( $day );
				$this->assertSame( 0, $counts[ $date ] ?? 0, $case['test_condition_name'] . ' / ' . $date . ' はカレンダーで予約できない' );
				$daily = $service->get_daily_slots(
					array(
						'menu_id' => $menu_id,
						'date'    => $date,
					)
				);
				$this->assertIsArray( $daily, $case['test_condition_name'] . ' / ' . $date . ' の日別' );
				$this->assertEmpty( $daily['slots'], $case['test_condition_name'] . ' / ' . $date . ' は日別の時間枠が出ない' );
			}

			// 平日のみの場合は、期間内の日でも土日は予約できない（両方の条件を満たす日だけ）。
			foreach ( $case['weekday_rule'] ?? array() as $day ) {
				$date    = $this->target_month_date( $day );
				$is_week = (int) ( new DateTimeImmutable( $date ) )->format( 'N' ) <= 5;
				$this->assertSame( $is_week, $counts[ $date ] > 0, $case['test_condition_name'] . ' / ' . $date . ' は平日のときだけ予約できる' );
			}
		}
	}

	/**
	 * 提供期間が終了したメニューは、直リンクなどで開かれても選べない案内（エラー）になる。
	 */
	public function test_ended_menu_cannot_be_selected(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '終了日が昨日 => menu_service_period_ended',
				'meta'                => array( Service_Period::META_END => $this->date_after( -1 ) ),
				'expected'            => 'menu_service_period_ended',
			),
			array(
				'test_condition_name' => '終了日が今日 => まだ選べる',
				'meta'                => array( Service_Period::META_END => $this->date_after( 0 ) ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '開始日が未来（開始前） => 選べる（日付だけが期間外）',
				'meta'                => array( Service_Period::META_START => $this->date_after( 30 ) ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_slot_menu( $case['meta'] );
			$result  = ( new Availability_Service() )->get_calendar_meta(
				array(
					'menu_id' => $menu_id,
					'year'    => (int) substr( $this->date_after( 31 ), 0, 4 ),
					'month'   => (int) substr( $this->date_after( 31 ), 5, 2 ),
				)
			);

			if ( '' === $case['expected'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 宿泊のサイトに切り替える。
	 */
	private function set_lodging_site(): void {
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
	}

	/**
	 * 泊のプランのメニューと客室を作る（今日から数か月の全日が空室）。
	 *
	 * @param array<string, mixed> $meta 追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_night_menu( array $meta ): int {
		$this->trash_all_resources();
		$room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$today   = new DateTimeImmutable( 'now', wp_timezone() );
		foreach ( array( 0, 1, 2 ) as $offset ) {
			$this->create_month_shift( $room_id, $today->modify( sprintf( 'first day of +%d month', $offset ) )->format( 'Y-m' ), 'open', array() );
		}

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $room_id ) );
		update_post_meta( $menu_id, '_vkbm_base_price', 10000 );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', 0 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 宿泊: チェックイン日が提供期間の中なら可（終了日を含む）。終了日をまたぐ連泊も可。
	 */
	public function test_lodging_availability_respects_service_period(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->set_lodging_site();

		// 期間は「今日の5日後〜10日後」。
		$menu_id = $this->create_night_menu(
			array(
				Service_Period::META_START => $this->date_after( 5 ),
				Service_Period::META_END   => $this->date_after( 10 ),
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '終了日にチェックインし、翌日以降（終了日をまたぐ）にチェックアウト => 受け付ける',
				'check_in'            => $this->date_after( 10 ),
				'check_out'           => $this->date_after( 12 ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '終了日の翌日にチェックイン => service_period_out_of_range',
				'check_in'            => $this->date_after( 11 ),
				'check_out'           => $this->date_after( 12 ),
				'expected'            => 'service_period_out_of_range',
			),
			array(
				'test_condition_name' => '開始日の前日にチェックインし、開始日にチェックアウト => service_period_out_of_range',
				'check_in'            => $this->date_after( 4 ),
				'check_out'           => $this->date_after( 5 ),
				'expected'            => 'service_period_out_of_range',
			),
			array(
				'test_condition_name' => '開始日にチェックイン => 受け付ける（境界）',
				'check_in'            => $this->date_after( 5 ),
				'check_out'           => $this->date_after( 6 ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $case['check_in'],
					'check_out_date' => $case['check_out'],
				)
			);
			if ( '' === $case['expected'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 宿泊カレンダーのチェックイン日を選ぶ段階: 提供期間の外の日は選べない。
	 */
	public function test_lodging_calendar_check_in_stage_respects_service_period(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->set_lodging_site();

		$menu_id = $this->create_night_menu(
			array(
				Service_Period::META_START => $this->date_after( 5 ),
				Service_Period::META_END   => $this->date_after( 10 ),
			)
		);

		// 検証する 4 日（開始日の前日・開始日・終了日・終了日の翌日）が入る月を、重複なしで全部取得する。
		$offsets   = array(
			4  => false,
			5  => true,
			10 => true,
			11 => false,
		);
		$available = array();
		foreach ( array_unique( array_map( fn( $offset ) => substr( $this->date_after( $offset ), 0, 7 ), array_keys( $offsets ) ) ) as $month_key ) {
			$result = ( new Availability_Service() )->get_lodging_calendar_meta(
				array(
					'menu_id' => $menu_id,
					'year'    => (int) substr( $month_key, 0, 4 ),
					'month'   => (int) substr( $month_key, 5, 2 ),
				)
			);
			$this->assertIsArray( $result, $month_key . ' の宿泊カレンダー' );
			foreach ( $result['days'] as $day ) {
				$available[ $day['date'] ] = $day['available'];
			}
		}

		// 4 日とも必ず取得できていて、期間の外は選べず、期間の端は選べる。
		foreach ( $offsets as $offset => $expected ) {
			$date = $this->date_after( $offset );
			$this->assertArrayHasKey( $date, $available, $date . ' が宿泊カレンダーに含まれる' );
			$this->assertSame( $expected, $available[ $date ], $date . ( $expected ? ' は提供期間の端なのでチェックイン日に選べる' : ' は提供期間の外なのでチェックイン日に選べない' ) );
		}
	}
}
