<?php
/**
 * /vkbm/v1/menu-loop の日付指定（date / ids_only / menu_ids）のテスト。
 *
 * 予約ブロックの絞り込み検索で日付を選ぶと、その日に予約できるサービスメニューだけが
 * 一覧とメニューのプルダウンに残る。REST の引数の検証と、応答（html / menu_ids）を確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use DateTimeImmutable;
use DateTimeZone;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Menu_Preview_Controller;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_UnitTestCase;
use function current_datetime;

/**
 * menu-loop の日付による絞り込みを検証するテストクラス。
 *
 * @group rest
 * @group menu-date-filter
 */
class Menu_Preview_Controller_Menu_Loop_Date_Filter_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）。
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
	 * タイムゾーンを固定し、時間枠のサイト（業種プリセット: カスタム）にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );

		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => 'custom',
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 全体設定・タイムゾーン・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 今日から指定日数後の日付（サイトのタイムゾーン）を返す。
	 *
	 * @param int $days 今日から何日後か。
	 * @return DateTimeImmutable その日の日時。
	 */
	private function day_from_today( int $days ): DateTimeImmutable {
		return current_datetime()->setTimezone( new DateTimeZone( 'Asia/Tokyo' ) )->modify( sprintf( '%+d days', $days ) );
	}

	/**
	 * 指定した日が休業日、それ以外が勤務日のシフトを、日付が属する月ごとに作る。
	 *
	 * @param int                      $staff_id     スタッフの投稿 ID。
	 * @param array<DateTimeImmutable> $dates        シフトを用意する日付。
	 * @param array<string>            $closed_dates 休業日にする日付（Y-m-d）。
	 */
	private function create_shifts( int $staff_id, array $dates, array $closed_dates ): void {
		$months = array();
		foreach ( $dates as $date ) {
			$months[ $date->format( 'Y-m' ) ] = $date;
		}

		foreach ( $months as $month_date ) {
			$days     = array();
			$last_day = (int) $month_date->format( 't' );
			for ( $day = 1; $day <= $last_day; $day++ ) {
				$date_key     = $month_date->format( 'Y-m' ) . sprintf( '-%02d', $day );
				$days[ $day ] = array(
					'status' => in_array( $date_key, $closed_dates, true ) ? 'regular_holiday' : 'open',
					'slots'  => array(
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
			update_post_meta( $shift_id, '_vkbm_shift_year', (int) $month_date->format( 'Y' ) );
			update_post_meta( $shift_id, '_vkbm_shift_month', (int) $month_date->format( 'n' ) );
			update_post_meta( $shift_id, '_vkbm_shift_days', $days );
		}
	}

	/**
	 * スタッフとサービスメニューを1組作る。
	 *
	 * @param string $title メニュー名。
	 * @return array{menu_id: int, staff_id: int} メニューとスタッフの投稿 ID。
	 */
	private function create_menu_with_staff( string $title ): array {
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 30 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', '0' );
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );

		return array(
			'menu_id'  => $menu_id,
			'staff_id' => $staff_id,
		);
	}

	/**
	 * menu-loop ルートに date / ids_only の引数が定義されていることを確認する。
	 */
	public function test_menu_loop_route_registers_date_args(): void {
		$routes = rest_get_server()->get_routes();
		$args   = $routes['/vkbm/v1/menu-loop'][0]['args'] ?? array();

		$this->assertArrayHasKey( 'date', $args, 'date 引数が定義されていること。' );
		$this->assertSame( 'string', $args['date']['type'], 'date 引数の type が string であること。' );
		$this->assertSame( '', $args['date']['default'], 'date 引数の既定値が空文字（絞り込みなし）であること。' );
		$this->assertFalse( $args['date']['required'], 'date 引数は必須ではないこと。' );

		$this->assertArrayHasKey( 'ids_only', $args, 'ids_only 引数が定義されていること。' );
		$this->assertSame( 'boolean', $args['ids_only']['type'], 'ids_only 引数の type が boolean であること。' );
		$this->assertFalse( $args['ids_only']['default'], 'ids_only 引数の既定値が false であること。' );
	}

	/**
	 * date の値の検証（空文字と実在する Y-m-d だけ許可）を確認する。
	 */
	public function test_validate_menu_loop_date(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '空文字 => 許可（絞り込みなし）',
				'value'               => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '実在する Y-m-d => 許可',
				'value'               => '2026-10-18',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '実在しない日付 => 拒否',
				'value'               => '2026-02-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '区切りがスラッシュ => 拒否',
				'value'               => '2026/10/18',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '文字列でない値 => 拒否',
				'value'               => 20261018,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Menu_Preview_Controller::validate_menu_loop_date( $case['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * dispatch() を通して、不正な date が 400 で拒否されることを確認する。
	 */
	public function test_dispatch_rejects_invalid_date(): void {
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', 'abc' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), '不正な date は 400 で拒否される。' );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null, 'エラーコードが rest_invalid_param であること。' );
	}

	/**
	 * date 指定で、その日に予約できないメニューが html と menu_ids から外れ、ids_only では html を作らないことを確認する。
	 */
	public function test_dispatch_filters_menus_by_date(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニューごとに担当スタッフを分けられるのは Pro 版のため、無料版ではスキップする。' );
		}

		$day_a = $this->day_from_today( 10 );
		$day_b = $this->day_from_today( 11 );

		// メニュー A のスタッフは day_b が休み、メニュー B のスタッフは day_a が休み。
		$menu_a = $this->create_menu_with_staff( '日付テストメニューA' );
		$menu_b = $this->create_menu_with_staff( '日付テストメニューB' );
		$this->create_shifts( $menu_a['staff_id'], array( $day_a, $day_b ), array( $day_b->format( 'Y-m-d' ) ) );
		$this->create_shifts( $menu_b['staff_id'], array( $day_a, $day_b ), array( $day_a->format( 'Y-m-d' ) ) );

		// 日付なし: 両方表示し、menu_ids にも両方含まれる。
		$data_without_date = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' ) )->get_data();
		$this->assertStringContainsString( '日付テストメニューA', $data_without_date['html'], '日付なしは全メニューを表示する。' );
		$this->assertStringContainsString( '日付テストメニューB', $data_without_date['html'], '日付なしは全メニューを表示する。' );
		$this->assertEqualsCanonicalizing( array( $menu_a['menu_id'], $menu_b['menu_id'] ), $data_without_date['menu_ids'], '日付なしの menu_ids は全メニュー。' );

		// day_a: A だけ。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $day_a->format( 'Y-m-d' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertStringContainsString( '日付テストメニューA', $data['html'], 'day_a は A を表示する。' );
		$this->assertStringNotContainsString( '日付テストメニューB', $data['html'], 'day_a は B を表示しない。' );
		$this->assertSame( array( $menu_a['menu_id'] ), $data['menu_ids'], 'day_a の menu_ids は A だけ。' );

		// day_b + ids_only: B だけの ID を返し、html は作らない。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $day_b->format( 'Y-m-d' ) );
		$request->set_param( 'ids_only', true );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( '', $data['html'], 'ids_only のときは html を作らない。' );
		$this->assertSame( array( $menu_b['menu_id'] ), $data['menu_ids'], 'day_b の menu_ids は B だけ。' );

		// 今日より前の日: 予約できるメニューは 0 件。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $this->day_from_today( -1 )->format( 'Y-m-d' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( '', $data['html'], '過去の日は html が空。' );
		$this->assertSame( array(), $data['menu_ids'], '過去の日は menu_ids が空。' );
	}

	/**
	 * 宿泊のサイトで、date（チェックイン日）と check_out_date（チェックアウト日）を渡すと、
	 * その日程に空室のある泊のプランだけが html と menu_ids に残ることを確認する。
	 */
	public function test_dispatch_filters_night_plans_by_stay_on_lodging_site(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

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

		$check_in  = $this->day_from_today( 10 )->format( 'Y-m-d' );
		$check_out = $this->day_from_today( 12 )->format( 'Y-m-d' );

		// 満室の客室だけを担当するプランと、空室のある客室を担当するプランを用意する。
		$full_room = $this->create_menu_with_staff( '満室プラン' );
		$open_room = $this->create_menu_with_staff( '空室ありプラン' );
		foreach ( array( $full_room, $open_room ) as $plan ) {
			update_post_meta( $plan['menu_id'], Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
			update_post_meta( $plan['menu_id'], '_vkbm_base_price', 10000 );
			$this->create_shifts( $plan['staff_id'], array( $this->day_from_today( 10 ), $this->day_from_today( 11 ) ), array() );
		}
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, Booking_Overlap::META_START, $check_in . ' 15:00:00' );
		update_post_meta( $booking_id, Booking_Overlap::META_END, $check_out . ' 10:00:00' );
		update_post_meta( $booking_id, Booking_Overlap::META_TOTAL_END, $check_out . ' 10:00:00' );
		update_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, $full_room['staff_id'] );
		update_post_meta( $booking_id, Booking_Overlap::META_STATUS, 'confirmed' );

		// 日程を渡す: 空室のあるプランだけ残る。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $check_in );
		$request->set_param( 'check_out_date', $check_out );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( array( $open_room['menu_id'] ), $data['menu_ids'], '満室のプランは menu_ids から外れる。' );
		$this->assertStringContainsString( '空室ありプラン', $data['html'], '空室のあるプランは一覧に出る。' );
		$this->assertStringNotContainsString( '満室プラン', $data['html'], '満室のプランは一覧に出ない。' );

		// チェックアウト日を渡さない: 絞り込まず全件。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $check_in );
		$request->set_param( 'ids_only', true );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertEqualsCanonicalizing( array( $full_room['menu_id'], $open_room['menu_id'] ), $data['menu_ids'], 'チェックアウト日が無ければ全件。' );

		// 日程に矛盾があるときは 400。
		$test_cases = array(
			array(
				'test_condition_name' => 'チェックアウト日がチェックイン日と同じ',
				'check_out'           => $check_in,
				'expected_code'       => 'invalid_date_range',
			),
			array(
				'test_condition_name' => 'チェックアウト日がチェックイン日より前',
				'check_out'           => $this->day_from_today( 9 )->format( 'Y-m-d' ),
				'expected_code'       => 'invalid_date_range',
			),
			array(
				'test_condition_name' => '31 泊（上限超え）',
				'check_out'           => $this->day_from_today( 41 )->format( 'Y-m-d' ),
				'expected_code'       => 'too_many_nights',
			),
		);
		foreach ( $test_cases as $case ) {
			$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
			$request->set_param( 'date', $check_in );
			$request->set_param( 'check_out_date', $case['check_out'] );
			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 400, $response->get_status(), $case['test_condition_name'] );
			$this->assertSame( $case['expected_code'], $response->get_data()['code'] ?? null, $case['test_condition_name'] . ' : エラーコード' );
		}

		// 形式が不正な check_out_date は rest_invalid_param で 400。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $check_in );
		$request->set_param( 'check_out_date', '2026-02-31' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status(), '実在しないチェックアウト日は 400。' );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
	}

	/**
	 * 時間枠のサイトでは、check_out_date を渡しても無視して date の1日で判定することを確認する。
	 */
	public function test_dispatch_ignores_check_out_date_on_time_slot_site(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニューごとに担当スタッフを分けられるのは Pro 版のため、無料版ではスキップする。' );
		}

		$closed_day = $this->day_from_today( 10 );
		$open_day   = $this->day_from_today( 11 );
		$menu       = $this->create_menu_with_staff( '時間枠メニュー' );
		$this->create_shifts( $menu['staff_id'], array( $closed_day, $open_day ), array( $closed_day->format( 'Y-m-d' ) ) );

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $closed_day->format( 'Y-m-d' ) );
		// 日程が矛盾していても（宿泊のサイトなら 400）、時間枠のサイトでは無視する。
		$request->set_param( 'check_out_date', $closed_day->format( 'Y-m-d' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), '時間枠のサイトは check_out_date を検証しない。' );
		$this->assertSame( array(), $response->get_data()['menu_ids'], '休業日の1日で判定して 0 件。' );
	}

	/**
	 * 未ログインでは、非公開メニューの ID が menu_ids に入らないことを確認する。
	 */
	public function test_dispatch_excludes_private_menu_ids_for_anonymous_visitor(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニューごとに担当スタッフを分けられるのは Pro 版のため、無料版ではスキップする。' );
		}

		$day = $this->day_from_today( 10 );

		$public_menu  = $this->create_menu_with_staff( '公開メニュー' );
		$private_menu = $this->create_menu_with_staff( '非公開メニュー' );
		wp_update_post(
			array(
				'ID'          => $private_menu['menu_id'],
				'post_status' => 'private',
			)
		);
		$this->create_shifts( $public_menu['staff_id'], array( $day ), array() );
		$this->create_shifts( $private_menu['staff_id'], array( $day ), array() );

		// 予約を管理できるユーザーが先に判定しても、結果は未ログインの応答へ混ざらない。
		wp_set_current_user( (int) $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $day->format( 'Y-m-d' ) );
		rest_get_server()->dispatch( $request );

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'date', $day->format( 'Y-m-d' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( array( $public_menu['menu_id'] ), $data['menu_ids'], '未ログインの menu_ids は公開メニューだけ。' );
		$this->assertStringNotContainsString( '非公開メニュー', $data['html'], '未ログインの一覧に非公開メニューは出ない。' );
	}
}
