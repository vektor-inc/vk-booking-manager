<?php
/**
 * 担当者はメニューを問わず同時に1組だけ受け持つ仕様（#656）のテスト。
 *
 * 空き枠の計算（Availability_Service）・予約確定（Booking_Confirmation_Controller）・
 * 管理画面の競合判定（Staff_Conflict_Detector）の3か所が、同じ判定基準で動くことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Common\Staff_Conflict_Detector;
use VKBookingManager\Common\Staff_Load_Calculator;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;
use function get_option;
use function update_option;
use function update_post_meta;

/**
 * 別メニューの予約がある担当者を、定員2以上のメニューでも空き無しとして扱うことを検証する。
 *
 * @group bookings
 * @group capacity
 */
class Staff_Availability_Across_Menus_Test extends WP_UnitTestCase {
	/** @var string */
	private $original_timezone_string = '';

	/**
	 * テスト前にタイムゾーンを固定し、指名OFF・予約枠の定員機能ONにする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * テスト後に設定を戻す。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 予約枠の定員は Pro 版限定のため、無料版ではスキップする。
	 */
	private function skip_on_free_edition(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '予約枠の定員は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	/**
	 * summarize_staff_bookings() と calculate_remaining() の共通判定を検証する。
	 */
	public function test_calculate_remaining(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約なし・定員4 => 残席4',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => false,
				'bookings'            => array(),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '同一メニュー1名・定員4 => 相乗りで残席3',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => false,
				'bookings'            => array(
					array(
						'service_id' => 10,
						'guests'     => 1,
					),
				),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '別メニューの予約あり・定員4 => 残席0',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => false,
				'bookings'            => array(
					array(
						'service_id' => 20,
						'guests'     => 1,
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '同一メニューと別メニューが混在・定員4 => 残席0',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => false,
				'bookings'            => array(
					array(
						'service_id' => 10,
						'guests'     => 1,
					),
					array(
						'service_id' => 20,
						'guests'     => 1,
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '別メニューの予約がキャンセル済み => 数えず残席4',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => false,
				'bookings'            => array(
					array(
						'service_id' => 20,
						'guests'     => 1,
						'status'     => 'cancelled',
					),
				),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '1枠1組のメニューで同一メニューの予約あり => 残席0',
				'menu_id'             => 10,
				'capacity'            => 4,
				'single_party'        => true,
				'bookings'            => array(
					array(
						'service_id' => 10,
						'guests'     => 1,
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '定員を超える人数が入っている => 残席は負にならず0',
				'menu_id'             => 10,
				'capacity'            => 2,
				'single_party'        => false,
				'bookings'            => array(
					array(
						'service_id' => 10,
						'guests'     => 3,
					),
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$summary = Staff_Load_Calculator::summarize_staff_bookings( $case['menu_id'], $case['bookings'] );
			$actual  = Staff_Load_Calculator::calculate_remaining( $case['capacity'], $case['single_party'], $summary );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 3つの統合テストで共通の予約パターン。
	 *
	 * menu は a（検証対象の定員4・指名なし）、b（定員1）、c（定員3）のいずれか。
	 * 時刻はいずれも検証する 10:00-11:00 の枠に対する開始・終了（サイトのタイムゾーン）。
	 *
	 * @return array<int, array<string, mixed>> ケース一覧。
	 *               test_condition_name / bookings（担当Sの既存予約）/ with_free_second_staff /
	 *               expected_remaining（空き枠のA残席）/ expected_staff（確定の割当先: none / first / second）/
	 *               expected_conflict（管理画面でSが競合するか）。
	 */
	private function get_scenarios(): array {
		$full_slot = array(
			'start' => '10:00',
			'end'   => '11:00',
		);

		return array(
			array(
				'test_condition_name'    => '担当Sが別メニューB（定員1）で予約済み => Aは残席0・割当不可・競合（#656）',
				'bookings'               => array( array_merge( array( 'menu' => 'b' ), $full_slot ) ),
				'with_free_second_staff' => false,
				'expected_remaining'     => 0,
				'expected_staff'         => 'none',
				'expected_conflict'      => true,
			),
			array(
				'test_condition_name'    => '担当Sが別メニューC（定員3＝2以上）で予約済み => Aは残席0・割当不可・競合',
				'bookings'               => array( array_merge( array( 'menu' => 'c' ), $full_slot ) ),
				'with_free_second_staff' => false,
				'expected_remaining'     => 0,
				'expected_staff'         => 'none',
				'expected_conflict'      => true,
			),
			array(
				'test_condition_name'    => '担当SがA自身と別メニューBの予約が混在 => Aは残席0・割当不可・競合',
				'bookings'               => array(
					array_merge( array( 'menu' => 'a' ), $full_slot ),
					array_merge( array( 'menu' => 'b' ), $full_slot ),
				),
				'with_free_second_staff' => false,
				'expected_remaining'     => 0,
				'expected_staff'         => 'none',
				'expected_conflict'      => true,
			),
			array(
				'test_condition_name'    => '別メニューBの予約が 10:30-11:30 で枠の一部だけ重なる => Aは残席0・割当不可・競合',
				'bookings'               => array(
					array(
						'menu'  => 'b',
						'start' => '10:30',
						'end'   => '11:30',
					),
				),
				'with_free_second_staff' => false,
				'expected_remaining'     => 0,
				'expected_staff'         => 'none',
				'expected_conflict'      => true,
			),
			array(
				'test_condition_name'    => '別メニューの予約が枠の終了と同時刻に始まる（11:00-12:00）=> 重ならず従来どおり残席4・割当可・競合なし',
				'bookings'               => array(
					array(
						'menu'  => 'b',
						'start' => '11:00',
						'end'   => '12:00',
					),
				),
				'with_free_second_staff' => false,
				'expected_remaining'     => 4,
				'expected_staff'         => 'first',
				'expected_conflict'      => false,
			),
			array(
				'test_condition_name'    => '担当Sが別メニューBで予約済みでも、空いている担当Tがいれば残席4・Tへ割当',
				'bookings'               => array( array_merge( array( 'menu' => 'b' ), $full_slot ) ),
				'with_free_second_staff' => true,
				'expected_remaining'     => 4,
				'expected_staff'         => 'second',
				'expected_conflict'      => true,
			),
			array(
				'test_condition_name'    => '担当SがA自身で1名予約済み => 相乗りで残席3・Sへ割当・競合なし（従来どおり）',
				'bookings'               => array( array_merge( array( 'menu' => 'a' ), $full_slot ) ),
				'with_free_second_staff' => false,
				'expected_remaining'     => 3,
				'expected_staff'         => 'first',
				'expected_conflict'      => false,
			),
			array(
				'test_condition_name'    => '予約なし => 残席4・Sへ割当・競合なし',
				'bookings'               => array(),
				'with_free_second_staff' => false,
				'expected_remaining'     => 4,
				'expected_staff'         => 'first',
				'expected_conflict'      => false,
			),
		);
	}

	/**
	 * シナリオの予約を担当Sに登録する。
	 *
	 * @param array<int, array<string, mixed>> $bookings 予約（menu / start / end）。
	 * @param array<string, int>               $menus    a / b / c => メニューID。
	 * @param int                              $staff_id 担当S。
	 * @param string                           $day      日付（Y-m-d。サイトのタイムゾーン）。
	 */
	private function create_scenario_bookings( array $bookings, array $menus, int $staff_id, string $day ): void {
		foreach ( $bookings as $booking ) {
			$this->create_booking_post(
				$staff_id,
				$menus[ $booking['menu'] ],
				$day . ' ' . $booking['start'] . ':00',
				$day . ' ' . $booking['end'] . ':00'
			);
		}
	}

	/**
	 * 検証用のメニューA（定員4）・B（定員1）・C（定員3）を作る。
	 *
	 * @return array<string, int> a / b / c => メニューID。
	 */
	private function create_menus(): array {
		return array(
			'a' => $this->create_menu( 4 ),
			'b' => $this->create_menu( 1 ),
			'c' => $this->create_menu( 3 ),
		);
	}

	/**
	 * 10:00 開始の自動割当スロットを取得する。
	 *
	 * @param int    $menu_id  メニューID。
	 * @param string $slot_day 日付（Y-m-d）。
	 * @return array<string, mixed>|null スロット。無ければ null。
	 */
	private function get_ten_o_clock_slot( int $menu_id, string $slot_day ): ?array {
		$service = new Availability_Service();
		$result  = $service->get_daily_slots(
			array(
				'menu_id'  => $menu_id,
				'date'     => $slot_day,
				'timezone' => 'Asia/Tokyo',
			)
		);

		foreach ( (array) ( $result['slots'] ?? array() ) as $slot ) {
			if ( str_contains( (string) ( $slot['start_at'] ?? '' ), $slot_day . 'T10:00:00' ) ) {
				return $slot;
			}
		}

		return null;
	}

	/**
	 * 空き枠の計算: 別メニューの予約がある担当者の残席が0になり、同一メニューの相乗りは従来どおり残ることを検証する。
	 */
	public function test_get_daily_slots_remaining_across_menus(): void {
		$this->skip_on_free_edition();

		$future_year = (int) wp_date( 'Y' ) + 1;
		$day         = 9;
		$slot_day    = sprintf( '%04d-12-%02d', $future_year, $day );

		foreach ( $this->get_scenarios() as $case ) {
			$menus   = $this->create_menus();
			$staff_s = $this->create_staff();
			$staff_t = $this->create_staff();
			$staffs  = $case['with_free_second_staff'] ? array( $staff_s, $staff_t ) : array( $staff_s );
			update_post_meta( $menus['a'], '_vkbm_staff_ids', $staffs );
			foreach ( $staffs as $staff_id ) {
				$this->create_shift( $staff_id, $future_year, 12, $day, '10:00', '12:00' );
			}

			$this->create_scenario_bookings( $case['bookings'], $menus, $staff_s, $slot_day );

			// 指名なし（自動割当）の空き枠を取得する。
			$target = $this->get_ten_o_clock_slot( $menus['a'], $slot_day );
			$this->assertNotNull( $target, $case['test_condition_name'] . ' / 10:00 のスロットが存在するべき' );
			$this->assertSame( $case['expected_remaining'], (int) $target['remaining'], $case['test_condition_name'] );
		}
	}

	/**
	 * 空き枠の計算: 催行表示用の booked_guests は、別メニューの予約を数えず同一メニューの人数だけを合計することを検証する。
	 */
	public function test_get_daily_slots_booked_guests_counts_same_menu_only(): void {
		$this->skip_on_free_edition();

		$future_year = (int) wp_date( 'Y' ) + 1;
		$day         = 9;
		$slot_day    = sprintf( '%04d-12-%02d', $future_year, $day );

		$test_cases = array(
			array(
				'test_condition_name' => '別メニューBの予約のみ => booked_guests=0',
				'bookings'            => array(
					array(
						'menu'  => 'b',
						'start' => '10:00',
						'end'   => '11:00',
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '同一メニューAの予約1件と別メニューCの予約 => booked_guests=1（Aの人数のみ）',
				'bookings'            => array(
					array(
						'menu'  => 'a',
						'start' => '10:00',
						'end'   => '11:00',
					),
					array(
						'menu'  => 'c',
						'start' => '10:00',
						'end'   => '11:00',
					),
				),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '予約なし => booked_guests=0',
				'bookings'            => array(),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$menus   = $this->create_menus();
			$staff_s = $this->create_staff();
			update_post_meta( $menus['a'], '_vkbm_staff_ids', array( $staff_s ) );
			$this->create_shift( $staff_s, $future_year, 12, $day, '10:00', '12:00' );
			$this->create_scenario_bookings( $case['bookings'], $menus, $staff_s, $slot_day );

			$target = $this->get_ten_o_clock_slot( $menus['a'], $slot_day );
			$this->assertNotNull( $target, $case['test_condition_name'] . ' / 10:00 のスロットが存在するべき' );
			$this->assertSame( $case['expected'], (int) $target['booked_guests'], $case['test_condition_name'] );
		}
	}

	/**
	 * 予約確定: 別メニューの予約がある担当者へは割り当てず、同一メニューの相乗りは従来どおり割り当てられることを検証する。
	 */
	public function test_check_capacity_with_mutex_across_menus(): void {
		$this->skip_on_free_edition();

		// 確定側は ISO8601 を受け取りサイトのタイムゾーンの保存形式へ変換する。予約は壁時計表記で保存する。
		$day   = '2026-11-04';
		$start = $day . 'T10:00:00+09:00';
		$end   = $day . 'T11:00:00+09:00';

		$controller = new Booking_Confirmation_Controller(
			new Staff_Availability_Notification_Test_Double(),
			new Settings_Repository()
		);
		$method     = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_capacity_with_mutex' );
		$method->setAccessible( true );
		$release = new ReflectionMethod( Booking_Confirmation_Controller::class, 'release_slot_mutex' );
		$release->setAccessible( true );

		foreach ( $this->get_scenarios() as $case ) {
			$menus   = $this->create_menus();
			$staff_s = $this->create_staff();
			$staff_t = $this->create_staff();
			$staffs  = $case['with_free_second_staff'] ? array( $staff_s, $staff_t ) : array( $staff_s );

			$this->create_scenario_bookings( $case['bookings'], $menus, $staff_s, $day );

			$result = $method->invoke( $controller, $menus['a'], 0, $start, $end, 1, false, $staffs, false );

			if ( 'none' === $case['expected_staff'] ) {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( 'capacity_exceeded', $result->get_error_code(), $case['test_condition_name'] );
				continue;
			}

			if ( $result instanceof WP_Error ) {
				$this->fail( $case['test_condition_name'] . ' / WP_Error: ' . $result->get_error_message() );
			}
			$expected_staff_id = 'first' === $case['expected_staff'] ? $staff_s : $staff_t;
			$this->assertSame( $expected_staff_id, $result['staff_id'], $case['test_condition_name'] );
			$release->invoke( $controller, $result['name'], $result['token'] );
		}
	}

	/**
	 * 管理画面の競合判定: 空き枠の計算・予約確定と同じシナリオで、同じ結論になることを検証する。
	 *
	 * 別メニューの予約があれば競合、同一メニューの相乗り（定員内）は競合しない。
	 */
	public function test_get_conflicting_staff_ids_across_menus(): void {
		$this->skip_on_free_edition();

		$day   = '2026-11-04';
		$start = $day . ' 10:00:00';
		$end   = $day . ' 11:00:00';

		foreach ( $this->get_scenarios() as $case ) {
			$menus   = $this->create_menus();
			$staff_s = $this->create_staff();

			$this->create_scenario_bookings( $case['bookings'], $menus, $staff_s, $day );

			$conflicts = Staff_Conflict_Detector::get_conflicting_staff_ids( 0, $start, $end, $menus['a'], 1 );
			$this->assertSame( $case['expected_conflict'], in_array( $staff_s, $conflicts, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * メニューを作成する。
	 *
	 * @param int $capacity 予約枠の定員。
	 * @return int メニュー投稿ID。
	 */
	private function create_menu( int $capacity ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_max_capacity', $capacity );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		if ( $capacity > 1 ) {
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		}

		return $menu_id;
	}

	/**
	 * スタッフ（リソース）を作成する。
	 *
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 確定済みの予約投稿を作成する（日時はサイトのタイムゾーンの壁時計表記で保存する）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $menu_id  メニューID。
	 * @param string $start_at 開始日時（Y-m-d H:i:s）。
	 * @param string $end_at   終了日時（Y-m-d H:i:s）。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $staff_id, int $menu_id, string $start_at, string $end_at ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_at );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_at );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_at );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );

		return $booking_id;
	}

	/**
	 * スタッフのシフト投稿を作成する（指定日に1スロット営業）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $year     年。
	 * @param int    $month    月。
	 * @param int    $day      日。
	 * @param string $start    営業開始（HH:MM）。
	 * @param string $end      営業終了（HH:MM）。
	 * @return int シフト投稿ID。
	 */
	private function create_shift( int $staff_id, int $year, int $month, int $day, string $start, string $end ): int {
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta(
			$shift_id,
			'_vkbm_shift_days',
			array(
				$day => array(
					'status' => 'open',
					'slots'  => array(
						array(
							'start' => $start,
							'end'   => $end,
						),
					),
				),
			)
		);

		return $shift_id;
	}
}

/**
 * 通知を送らないテスト用ダブル。
 */
class Staff_Availability_Notification_Test_Double extends Booking_Notification_Service {
	/**
	 * 設定リポジトリを渡して初期化する。
	 */
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	/**
	 * 確定通知は何もしない。
	 *
	 * @param int $booking_id 予約ID。
	 */
	public function handle_confirmed_creation( int $booking_id ): void {
		// テストでは何もしない。
	}

	/**
	 * 保留通知は何もしない。
	 *
	 * @param int $booking_id 予約ID。
	 */
	public function handle_pending_creation( int $booking_id ): void {
		// テストでは何もしない。
	}
}
