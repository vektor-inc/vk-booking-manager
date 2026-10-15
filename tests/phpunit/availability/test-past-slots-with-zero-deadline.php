<?php
/**
 * 予約受付締切が 0（締切なし）でも、過去の枠が予約可能として返らないことのテスト（issue #655）。
 *
 * 修正前は締切 0 のとき空き枠計算に「現在時刻より前を弾く」下限が無く、当日の過ぎた時刻や
 * 過去日の枠が予約可能として返っていた（確定前の再検証も同じ計算を呼ぶため、REST から
 * 過去時刻で確定できた）。
 *
 * - get_daily_slots(): 当日は現在時刻より前の枠を返さない／過去日は枠を返さない
 * - get_calendar_meta(): 今日より前の日は受付不可（is_disabled=true・available_slots=0）として返す
 * - resolve_booking_cutoff(): 締切 0 でも現在時刻、N 時間なら現在時刻 + N 時間を返す
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use WP_UnitTestCase;
use function current_datetime;
use function update_option;
use function update_post_meta;

/**
 * 締切 0 のメニューで過去の枠が返らないことを検証するテストクラス。
 *
 * @group availability
 * @group past-slots-zero-deadline
 */
class Past_Slots_With_Zero_Deadline_Test extends WP_UnitTestCase {

	/**
	 * 元のサイトタイムゾーン文字列（テスト後に復元する）。
	 *
	 * @var string
	 */
	private string $original_timezone_string = '';

	/**
	 * サイトタイムゾーンを固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * サイトタイムゾーンを元に戻す。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tearDown();
	}

	/**
	 * get_daily_slots() が現在時刻より前の枠・過去日の枠を返さないことを検証する。
	 */
	public function test_get_daily_slots(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$now       = current_datetime()->setTimezone( $tz );
		$yesterday = $now->modify( '-1 day' );
		$future    = $now->modify( '+2 months' );

		$test_cases = array(
			array(
				'test_condition_name' => '締切0・今日 => 現在時刻より前の枠を返さず、未来の枠は返す（正常系）',
				'deadline_hours'      => '0',
				'date'                => $now->format( 'Y-m-d' ),
				'expect_all_future'   => true,
				'expect_any_slot'     => null,
			),
			array(
				'test_condition_name' => '締切0・昨日 => 枠を1件も返さない（正常系）',
				'deadline_hours'      => '0',
				'date'                => $yesterday->format( 'Y-m-d' ),
				'expect_all_future'   => true,
				'expect_any_slot'     => false,
			),
			array(
				'test_condition_name' => '締切0・2ヶ月後 => 枠を返す（境界値：下限が未来の枠を消さない）',
				'deadline_hours'      => '0',
				'date'                => $future->format( 'Y-m-d' ),
				'expect_all_future'   => true,
				'expect_any_slot'     => true,
			),
			array(
				'test_condition_name' => '締切2時間・今日 => 現在時刻 + 2時間より前の枠を返さない（既存挙動の維持）',
				'deadline_hours'      => '2',
				'date'                => $now->format( 'Y-m-d' ),
				'expect_all_future'   => true,
				'expect_any_slot'     => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$prepared = $this->prepare_menu_and_shifts( $case['deadline_hours'], array( $yesterday, $now, $future ) );
			$menu_id  = $prepared['menu_id'];
			$service  = new Availability_Service();

			$result = $service->get_daily_slots(
				array(
					'menu_id'  => $menu_id,
					'date'     => $case['date'],
					'timezone' => 'Asia/Tokyo',
				)
			);

			$this->assertIsArray( $result, $case['test_condition_name'] . ' : 配列を返すべき' );

			$lower_bound = $now->modify( sprintf( '+%d hours', (int) $case['deadline_hours'] ) );
			foreach ( $result['slots'] as $slot ) {
				$start = new DateTimeImmutable( (string) $slot['start_at'] );
				$this->assertGreaterThanOrEqual(
					$lower_bound->getTimestamp(),
					$start->getTimestamp(),
					$case['test_condition_name'] . ' : 下限より前の枠 ' . $slot['start_at'] . ' が返っている'
				);
			}

			if ( null !== $case['expect_any_slot'] ) {
				$this->assertSame(
					$case['expect_any_slot'],
					! empty( $result['slots'] ),
					$case['test_condition_name']
				);
			}

			// 基本スタッフを使い回す無料版でも、次のケースへ勤務日を持ち越さない。
			foreach ( $prepared['shift_ids'] as $shift_id ) {
				wp_delete_post( $shift_id, true );
			}
		}
	}

	/**
	 * get_calendar_meta() が今日より前の日を受付不可として返すことを検証する。
	 */
	public function test_get_calendar_meta(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$now       = current_datetime()->setTimezone( $tz );
		$yesterday = $now->modify( '-1 day' );
		$future    = $now->modify( '+2 months' );

		$test_cases = array(
			array(
				'test_condition_name' => '締切0・昨日 => 受付不可（is_disabled=true・枠0件・first_start_at=null）',
				'deadline_hours'      => '0',
				'target'              => $yesterday,
				'expected'            => array(
					'available_slots' => 0,
					'is_disabled'     => true,
					'first_start_at'  => null,
				),
			),
			array(
				'test_condition_name' => '締切2時間・昨日 => 受付不可（既存の締切ありでも同じ）',
				'deadline_hours'      => '2',
				'target'              => $yesterday,
				'expected'            => array(
					'available_slots' => 0,
					'is_disabled'     => true,
					'first_start_at'  => null,
				),
			),
			array(
				'test_condition_name' => '締切0・2ヶ月後 => 受付可能（境界値：未来日は影響を受けない）',
				'deadline_hours'      => '0',
				'target'              => $future,
				'expected'            => array(
					'is_disabled' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$prepared = $this->prepare_menu_and_shifts( $case['deadline_hours'], array( $yesterday, $now, $future ) );
			$menu_id  = $prepared['menu_id'];
			$service  = new Availability_Service();

			$calendar = $service->get_calendar_meta(
				array(
					'menu_id'  => $menu_id,
					'year'     => (int) $case['target']->format( 'Y' ),
					'month'    => (int) $case['target']->format( 'n' ),
					'timezone' => 'Asia/Tokyo',
				)
			);

			$this->assertIsArray( $calendar, $case['test_condition_name'] . ' : 配列を返すべき' );

			$target_date = $case['target']->format( 'Y-m-d' );
			$day         = null;
			foreach ( $calendar['days'] as $entry ) {
				if ( $target_date === $entry['date'] ) {
					$day = $entry;
				}
			}
			$this->assertIsArray( $day, $case['test_condition_name'] . ' : 対象日が含まれるべき' );

			foreach ( $case['expected'] as $key => $expected_value ) {
				$this->assertSame( $expected_value, $day[ $key ], $case['test_condition_name'] . ' : ' . $key );
			}

			// 基本スタッフを使い回す無料版でも、次のケースへ勤務日を持ち越さない。
			foreach ( $prepared['shift_ids'] as $shift_id ) {
				wp_delete_post( $shift_id, true );
			}
		}
	}

	/**
	 * resolve_booking_cutoff() が締切の有無に関わらず現在時刻以降の下限を返すことを検証する。
	 */
	public function test_resolve_booking_cutoff(): void {
		$service = new Availability_Service();
		$tz      = new DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => '締切0 => 現在時刻（+0時間）',
				'deadline_hours'      => 0,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '締切3時間 => 現在時刻 + 3時間',
				'deadline_hours'      => 3,
				'expected'            => 3 * HOUR_IN_SECONDS,
			),
		);

		foreach ( $test_cases as $case ) {
			$before = time();
			$cutoff = $service->resolve_booking_cutoff( array( 'deadline_hours' => $case['deadline_hours'] ), $tz );
			$after  = time();

			$this->assertInstanceOf( DateTimeImmutable::class, $cutoff, $case['test_condition_name'] );
			$this->assertSame( 'Asia/Tokyo', $cutoff->getTimezone()->getName(), $case['test_condition_name'] . ' : タイムゾーン' );
			$this->assertGreaterThanOrEqual( $before + $case['expected'], $cutoff->getTimestamp(), $case['test_condition_name'] );
			$this->assertLessThanOrEqual( $after + $case['expected'], $cutoff->getTimestamp(), $case['test_condition_name'] );
		}
	}

	/**
	 * get_unavailability_reason() が、締切 0 のメニューを「締切が長すぎる」と誤診断せず、
	 * 過去日を分析対象から外すことを検証する。
	 */
	public function test_get_unavailability_reason(): void {
		$tz         = new DateTimeZone( 'Asia/Tokyo' );
		$now        = current_datetime()->setTimezone( $tz );
		$last_month = $now->modify( 'first day of last month' );

		$test_cases = array(
			array(
				'test_condition_name' => '締切0・今月は今日以外すべて休業で、今日の勤務は 00:00〜00:30 のみ => deadline_hours_too_long にならない（null）',
				'deadline_hours'      => '0',
				'target'              => $now,
				'only_today_short'    => true,
				'expected'            => null,
			),
			array(
				'test_condition_name' => '締切0・今月が全日休業 => all_days_closed（締切のせいにしない）',
				'deadline_hours'      => '0',
				'target'              => $now,
				'only_today_short'    => false,
				'expected'            => 'all_days_closed',
			),
			array(
				'test_condition_name' => '締切0・表示月がすべて過去 => 診断しない（null）',
				'deadline_hours'      => '0',
				'target'              => $last_month,
				'only_today_short'    => false,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 30 );
			update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
			update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', $case['deadline_hours'] );
			update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );

			$year  = (int) $case['target']->format( 'Y' );
			$month = (int) $case['target']->format( 'n' );

			if ( $case['only_today_short'] ) {
				$days = array();
				$last = (int) $case['target']->format( 't' );
				for ( $day = 1; $day <= $last; $day++ ) {
					$is_today     = $day === (int) $now->format( 'j' );
					$days[ $day ] = array(
						'status' => $is_today ? 'open' : 'regular_holiday',
						'slots'  => array(
							array(
								'start' => '00:00',
								'end'   => '00:30',
							),
						),
					);
				}
				$shift_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Shift_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);
				update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
				update_post_meta( $shift_id, '_vkbm_shift_year', $year );
				update_post_meta( $shift_id, '_vkbm_shift_month', $month );
				update_post_meta( $shift_id, '_vkbm_shift_days', $days );
			} else {
				$shift_id = $this->create_full_month_shift( $staff_id, $year, $month, 'regular_holiday' );
			}

			$reason = ( new Availability_Service() )->get_unavailability_reason(
				array(
					'menu_id'  => $menu_id,
					'year'     => $year,
					'month'    => $month,
					'timezone' => 'Asia/Tokyo',
				)
			);

			if ( null === $case['expected'] ) {
				$this->assertNull( $reason, $case['test_condition_name'] );
			} else {
				$this->assertIsArray( $reason, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $reason['code'] ?? null, $case['test_condition_name'] );
			}

			// 基本スタッフを使い回す無料版でも、次のケースへ勤務日を持ち越さない。
			wp_delete_post( $shift_id, true );
		}
	}

	/**
	 * 確定前の再検証が、キャッシュ済みの空き枠に残っていても受付下限より前の枠を通さないことを検証する。
	 */
	public function test_revalidate_draft_slot(): void {
		$tz  = new DateTimeZone( 'Asia/Tokyo' );
		$now = current_datetime()->setTimezone( $tz );

		$test_cases = array(
			array(
				'test_condition_name' => '締切0・1時間前の枠が空き枠キャッシュに残っている => slot_unavailable（409）',
				'deadline_hours'      => '0',
				'start'               => $now->modify( '-1 hour' ),
				'expected_error'      => 'slot_unavailable',
			),
			array(
				'test_condition_name' => '締切2時間・1時間後の枠が空き枠キャッシュに残っている => slot_unavailable（409）',
				'deadline_hours'      => '2',
				'start'               => $now->modify( '+1 hour' ),
				'expected_error'      => 'slot_unavailable',
			),
			array(
				'test_condition_name' => '締切0・2ヶ月後の枠 => 通る（正常系）',
				'deadline_hours'      => '0',
				'start'               => $now->modify( '+2 months' ),
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '締切2時間・3時間後の枠 => 通る（境界値の外側）',
				'deadline_hours'      => '2',
				'start'               => $now->modify( '+3 hours' ),
				'expected_error'      => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', $case['deadline_hours'] );

			$start_at = $case['start']->format( DATE_ATOM );
			$end_at   = $case['start']->modify( '+30 minutes' )->format( DATE_ATOM );
			$slot     = array(
				'slot_id'  => 'slot-655',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			);

			$controller = new Booking_Confirmation_Controller(
				$this->getMockBuilder( Booking_Notification_Service::class )->disableOriginalConstructor()->getMock(),
				new Settings_Repository(),
				new Cached_Slot_Availability_Service( $slot )
			);

			$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'revalidate_draft_slot' );
			$method->setAccessible( true );
			$result = $method->invoke( $controller, $menu_id, 0, $slot, 'Asia/Tokyo' );

			if ( '' === $case['expected_error'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error'], $result->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( 409, $result->get_error_data()['status'] ?? null, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 無料版では基本スタッフを再利用し、Pro 版ではスタッフを作成する。
	 *
	 * @return int スタッフ投稿 ID。
	 */
	private function create_staff(): int {
		if ( Pro_Upsell::is_free_edition() ) {
			$default_staff_id = Resource_Post_Type::get_default_staff_id();
			if ( $default_staff_id > 0 ) {
				return $default_staff_id;
			}
		}

		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * スタッフ・メニュー・終日シフトを用意して、メニューとシフトの ID を返す。
	 *
	 * @param string                   $deadline_hours 予約受付締切（時間）。
	 * @param array<DateTimeImmutable> $dates          シフトを用意する日付が属する月の基準日。
	 * @return array{menu_id: int, shift_ids: int[]} メニュー投稿 ID と後片付け用のシフト投稿 ID。
	 */
	private function prepare_menu_and_shifts( string $deadline_hours, array $dates ): array {
		$staff_id = $this->create_staff();

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 30 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', $deadline_hours );
		// 「2ヶ月後」のケースが予約受付上限日数の既定値に左右されないよう、上限なし（0）を明示する。
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );

		$months = array();
		foreach ( $dates as $date ) {
			$months[ $date->format( 'Y-n' ) ] = array( (int) $date->format( 'Y' ), (int) $date->format( 'n' ) );
		}
		$shift_ids = array();
		foreach ( $months as $ym ) {
			$shift_ids[] = $this->create_full_month_shift( $staff_id, $ym[0], $ym[1] );
		}

		return array(
			'menu_id'   => $menu_id,
			'shift_ids' => $shift_ids,
		);
	}

	/**
	 * 対象月の全日に 00:00〜23:59 の勤務スロットを持つシフトを作成する。
	 *
	 * @param int                               $staff_id スタッフ ID。
	 * @param int                               $year     年。
	 * @param int                               $month    月。
	 * @param string                            $status 1日ごとのステータス。
	 * @param array<int, array<string, string>> $slots 勤務スロット（空なら 00:00〜23:59）。
	 * @return int シフト投稿 ID。
	 */
	private function create_full_month_shift( int $staff_id, int $year, int $month, string $status = 'open', array $slots = array() ): int {
		$days_in_month = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );

		$days = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = array(
				'status' => $status,
				'slots'  => ! empty( $slots ) ? $slots : array(
					array(
						'start' => '00:00',
						'end'   => '23:59',
					),
				),
			);
		}

		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );

		return $shift_id;
	}
}

/**
 * 1分キャッシュ済みの空き枠が残っている状況を再現するダブル（常に指定の枠を返す）。
 */
class Cached_Slot_Availability_Service extends Availability_Service {

	/**
	 * 返す枠。
	 *
	 * @var array<string, mixed>
	 */
	private array $slot;

	/**
	 * コンストラクタ。
	 *
	 * @param array<string, mixed> $slot 返す枠。
	 */
	public function __construct( array $slot ) {
		parent::__construct();
		$this->slot = $slot;
	}

	/**
	 * キャッシュ済みの枠をそのまま返す。
	 *
	 * @param array<string, mixed> $args 引数。
	 * @return array<string, mixed>
	 */
	public function get_daily_slots( array $args ) {
		return array( 'slots' => array( $this->slot ) );
	}
}
