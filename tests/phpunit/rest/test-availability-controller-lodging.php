<?php
/**
 * 泊の空き状況の REST（/vkbm/v1/lodging-availabilities）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use DateTimeImmutable;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Availability\Availability_Service;
use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ルートの登録・拒否・マスク・レスポンスの形を検証する。
 *
 * @group rest
 * @group lodging
 */
class Availability_Controller_Lodging_Test extends WP_UnitTestCase {

	private const ROUTE          = '/vkbm/v1/lodging-availabilities';
	private const CALENDAR_ROUTE = '/vkbm/v1/lodging-calendar-meta';

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
	 * テスト前に設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * テスト後に設定・フィルター・REST サーバーを元に戻す。
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- REST サーバーをテストごとに作り直す。
		wp_set_current_user( 0 );
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
	 * 業種プリセットを設定し、REST サーバーを作り直す（ルートの登録は起動時の判定のため）。
	 *
	 * @param string $preset 保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $preset,
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- REST サーバーをテストごとに作り直す。
		rest_get_server();
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
	 * 客室とシフトを作る。
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
		$today   = new DateTimeImmutable( 'now', wp_timezone() );
		foreach ( array( $today->format( 'Y-m' ), $today->modify( '+1 month' )->format( 'Y-m' ) ) as $month ) {
			$days = array();
			for ( $day = 1; $day <= 31; $day++ ) {
				$days[ $day ] = array(
					'status' => 'open',
					'slots'  => array(),
				);
			}
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

		return $room_id;
	}

	/**
	 * メニューを作る。
	 *
	 * @param array<int, int> $room_ids 担当の客室 ID。
	 * @param bool            $is_night 泊単位にするか。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( array $room_ids, bool $is_night = true ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', $room_ids );
		update_post_meta( $menu_id, '_vkbm_base_price', 10000 );
		if ( $is_night ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		}

		return $menu_id;
	}

	/**
	 * REST のリクエストを実行する。
	 *
	 * @param array<string, mixed> $params クエリのパラメータ。
	 * @return \WP_REST_Response
	 */
	private function request( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * 宿泊カレンダーの REST リクエストを実行する。
	 *
	 * @param array<string, mixed> $params クエリのパラメータ。
	 * @return \WP_REST_Response
	 */
	private function calendar_request( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::CALENDAR_ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * 宿泊が有効なサイトでだけルートが登録されることを検証する。
	 */
	public function test_register_routes_registers_lodging_route_only_when_lodging_is_enabled(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト => ルートが登録される',
				'preset'              => 'lodging',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンのサイト => ルートは登録されない',
				'preset'              => 'salon',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'カスタムのサイト => ルートは登録されない',
				'preset'              => 'custom',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$routes = rest_get_server()->get_routes();
			$this->assertSame( $case['expected'], isset( $routes[ self::ROUTE ] ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], isset( $routes[ self::CALENDAR_ROUTE ] ), $case['test_condition_name'] . '（宿泊カレンダー）' );
			// 既存の空き状況のルートは常に登録されたまま。
			$this->assertArrayHasKey( '/vkbm/v1/availabilities', $routes, $case['test_condition_name'] . '（既存のルート）' );
			$this->assertArrayHasKey( '/vkbm/v1/calendar-meta', $routes, $case['test_condition_name'] . '（既存のルート）' );
		}
	}

	/**
	 * 宿泊カレンダーの一時保存の名前が、世代番号とメニュー ID が大きくても transient 名の上限（172 文字）に収まり、条件が違えば別の名前になることを検証する。
	 */
	public function test_lodging_calendar_cache_key_fits_transient_name_limit(): void {
		$method = new ReflectionMethod( Availability_Service::class, 'build_lodging_calendar_cache_key' );
		$method->setAccessible( true );
		$service = new Availability_Service();

		$key = $method->invoke( $service, 9999999999, array( 1234567890, 2234567890, 3234567890 ), '2026-11-01', '2026-11-30', '2026-11-15', 'Asia/Tokyo' );
		// 世代番号が最大桁数のときも収まることを、プレフィックス込みの最大長で確かめる（g + 10 桁の世代番号を想定）。
		$max_length = strlen( 'vkbm_lodging_calendar_g' ) + 10 + strlen( '_bg' ) + 32 + strlen( '_' ) + 10 + strlen( '_' ) + 32;
		$this->assertLessThanOrEqual( 172, strlen( $key ) );
		$this->assertLessThanOrEqual( 172, $max_length );

		$other_check_in = $method->invoke( $service, 9999999999, array( 1234567890, 2234567890, 3234567890 ), '2026-11-01', '2026-11-30', '', 'Asia/Tokyo' );
		$other_rooms    = $method->invoke( $service, 9999999999, array( 1234567890 ), '2026-11-01', '2026-11-30', '2026-11-15', 'Asia/Tokyo' );
		$this->assertNotSame( $key, $other_check_in );
		$this->assertNotSame( $key, $other_rooms );
	}

	/**
	 * 宿泊カレンダーが月の日ごとの空き状況を返すことを検証する。
	 */
	public function test_lodging_calendar_meta_returns_monthly_days(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->set_site( 'lodging' );
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );
		$target  = new DateTimeImmutable( $this->date_after( 10 ), wp_timezone() );
		$request = new WP_REST_Request( 'GET', self::CALENDAR_ROUTE );
		$request->set_param( 'menu_id', $menu_id );
		$request->set_param( 'year', (int) $target->format( 'Y' ) );
		$request->set_param( 'month', (int) $target->format( 'm' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['days'] ?? array() );
		$this->assertArrayHasKey( 'available', $response->get_data()['days'][0] );
	}

	/**
	 * チェックイン日が受付範囲外なら、公開 REST でも入力エラーとして 400 を返すことを検証する。
	 */
	public function test_lodging_calendar_meta_rejects_check_in_outside_booking_range(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->set_site( 'lodging' );
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 30 );

		$test_cases = array(
			array(
				'test_condition_name' => '昨日 => 400 invalid_date',
				'check_in_date'       => $this->date_after( -1 ),
			),
			array(
				'test_condition_name' => '受付上限より先 => 400 invalid_date',
				'check_in_date'       => $this->date_after( 31 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$target   = new DateTimeImmutable( $case['check_in_date'], wp_timezone() );
			$response = $this->calendar_request(
				array(
					'menu_id'       => $menu_id,
					'year'          => (int) $target->format( 'Y' ),
					'month'         => (int) $target->format( 'm' ),
					'check_in_date' => $case['check_in_date'],
				)
			);
			$this->assertSame( 400, $response->get_status(), $case['test_condition_name'] );
			$this->assertSame( 'invalid_date', $response->get_data()['code'], $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊が有効でないサイトで呼ぶと 404 で拒否されることを検証する。
	 */
	public function test_handle_lodging_availabilities_is_rejected_when_lodging_is_not_enabled(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );

		foreach ( array( 'salon', 'custom' ) as $preset ) {
			$this->set_site( $preset );
			$response = $this->request(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				)
			);
			$this->assertSame( 404, $response->get_status(), $preset . ' のサイトは 404' );
		}

		// ルートが登録された後に業種プリセットが変わっても、ハンドラー側の判定で拒否する。
		$this->set_site( 'lodging' );
		$this->assertSame(
			200,
			$this->request(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				)
			)->get_status(),
			'宿泊のサイトは 200'
		);
		// ルート登録後に宿泊以外の業種へ変わった状態を作る（REST サーバーの作り直しでルートは再登録されない前提で、ハンドラーを直接呼ぶ）。
		$this->set_site( 'custom' );
		$controller = new \VKBookingManager\REST\Availability_Controller( new \VKBookingManager\Availability\Availability_Service() );
		$request    = new WP_REST_Request( 'GET', self::ROUTE );
		foreach ( array(
			'menu_id'        => $menu_id,
			'check_in_date'  => $this->date_after( 10 ),
			'check_out_date' => $this->date_after( 11 ),
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$result = $controller->handle_lodging_availabilities( $request );
		$this->assertWPError( $result, 'ルート登録後に無効になった場合のハンドラー' );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
	}

	/**
	 * 無料版では宿泊が使えず、ルートが登録されず拒否されることを検証する（無料版のテストスイートでだけ実行）。
	 */
	public function test_lodging_route_is_rejected_on_free_edition(): void {
		if ( ! Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '無料版の拒否を確かめるテストのため、Pro 版ではスキップする。' );
		}

		$this->set_site( 'lodging' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey( self::ROUTE, $routes, '無料版ではルートを登録しない' );

		$response = $this->request(
			array(
				'menu_id'        => 1,
				'check_in_date'  => $this->date_after( 10 ),
				'check_out_date' => $this->date_after( 11 ),
			)
		);
		$this->assertSame( 404, $response->get_status(), '無料版は 404' );
	}

	/**
	 * 成功時のレスポンスの形と、引数のスキーマによる拒否を検証する。
	 */
	public function test_handle_lodging_availabilities_response_and_schema(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site( 'lodging' );
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );
		// 2名まで受け付ける（複数人一括予約の設定）。
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', '1' );
		update_post_meta( $menu_id, '_vkbm_max_capacity', '4' );

		$response = $this->request(
			array(
				'menu_id'        => $menu_id,
				'check_in_date'  => $this->date_after( 10 ),
				'check_out_date' => $this->date_after( 12 ),
				'guests'         => 2,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array( 'check_in_date', 'check_out_date', 'nights', 'timezone', 'check_in_time', 'check_out_time', 'rooms', 'rate_tiers_enabled', 'night_rates', 'meta' ),
			array_keys( $data ),
			'レスポンスのキー'
		);
		$this->assertSame(
			array(
				'id'                       => $room_id,
				'name'                     => '客室A',
				'price_unit'               => 'per_guest',
				'plan_price_per_night'     => 20000,
				'room_surcharge_per_night' => 0,
				'price_per_night'          => 20000,
				'night_breakdown'          => array(
					array(
						'date'           => $this->date_after( 10 ),
						'rate'           => 'weekday',
						'plan_price'     => 20000,
						'room_surcharge' => 0,
						'price'          => 20000,
					),
					array(
						'date'           => $this->date_after( 11 ),
						'rate'           => 'weekday',
						'plan_price'     => 20000,
						'room_surcharge' => 0,
						'price'          => 20000,
					),
				),
				'total_price'              => 40000,
			),
			$data['rooms'][0],
			'客室の形'
		);
		$this->assertSame( 2, $data['meta']['guests'] );
		$this->assertNull( $data['meta']['resource_id'] );

		$invalid_cases = array(
			array(
				'test_condition_name' => 'メニュー ID が無い => 400',
				'params'              => array(
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 12 ),
				),
			),
			array(
				'test_condition_name' => '日付の形式違い => 400（スキーマ）',
				'params'              => array(
					'menu_id'        => $menu_id,
					'check_in_date'  => 'abc',
					'check_out_date' => $this->date_after( 12 ),
				),
			),
			array(
				'test_condition_name' => '人数が 0 => 400（スキーマ）',
				'params'              => array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 12 ),
					'guests'         => 0,
				),
			),
		);
		foreach ( $invalid_cases as $case ) {
			$this->assertSame( 400, $this->request( $case['params'] )->get_status(), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約受付締切（reservation_deadline_hours）を空室の問い合わせでも見ることを、匿名ユーザーの REST 経由で検証する。
	 *
	 * 締切の内側のチェックイン日は空室を返さず、訪問者の入力に起因するエラーとしてマスクせずに返す。
	 */
	public function test_handle_lodging_availabilities_reservation_deadline(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site( 'lodging' );
		$room_id = $this->create_room( '客室A' );
		wp_set_current_user( 0 );

		$test_cases = array(
			array(
				'test_condition_name' => '締切 72 時間・チェックインが 10 日後（締切の外） => 空室を返す',
				'deadline_hours'      => 72,
				'check_in_after'      => 10,
				'status'              => 200,
				'code'                => '',
			),
			array(
				'test_condition_name' => '締切 72 時間・チェックインが 5 日後（締切の外） => 空室を返す',
				'deadline_hours'      => 72,
				'check_in_after'      => 5,
				'status'              => 200,
				'code'                => '',
			),
			array(
				'test_condition_name' => '締切 72 時間・チェックインが 1 日後（締切の内側） => reservation_deadline_passed（マスクしない）',
				'deadline_hours'      => 72,
				'check_in_after'      => 1,
				'status'              => 400,
				'code'                => 'reservation_deadline_passed',
			),
			array(
				'test_condition_name' => '締切 0 時間・チェックインが 1 日後 => 空室を返す',
				'deadline_hours'      => 0,
				'check_in_after'      => 1,
				'status'              => 200,
				'code'                => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件ごとに締切時間の違うメニューを作る。
			$menu_id = $this->create_menu( array( $room_id ) );
			update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', $case['deadline_hours'] );

			$response = $this->request(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( $case['check_in_after'] ),
					'check_out_date' => $this->date_after( $case['check_in_after'] + 1 ),
				)
			);

			$this->assertSame( $case['status'], $response->get_status(), $case['test_condition_name'] );
			if ( '' !== $case['code'] ) {
				$this->assertSame( $case['code'], $response->get_data()['code'], $case['test_condition_name'] );
			} else {
				$this->assertNotEmpty( $response->get_data()['rooms'], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 人数の引数（guest_tiers のスキーマ違反は 400、上限超過はマスクされずそのまま返る）を REST 経由で検証する。
	 */
	public function test_handle_lodging_availabilities_guests_validation(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site( 'lodging' );
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', '1' );
		update_post_meta( $menu_id, '_vkbm_max_capacity', '3' );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '大人',
					'price' => 12000,
				),
				array(
					'label' => '子供',
					'price' => 6000,
				),
			)
		);
		$base = array(
			'menu_id'        => $menu_id,
			'check_in_date'  => $this->date_after( 10 ),
			'check_out_date' => $this->date_after( 12 ),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'guest_tiers に負の数 => 400（スキーマ）',
				'params'              => array( 'guest_tiers' => array( -1, 2 ) ),
				'expected_status'     => 400,
				'expected_code'       => 'rest_invalid_param',
			),
			array(
				'test_condition_name' => 'guest_tiers に 100 => 400（スキーマ）',
				'params'              => array( 'guest_tiers' => array( 100, 1 ) ),
				'expected_status'     => 400,
				'expected_code'       => 'rest_invalid_param',
			),
			array(
				'test_condition_name' => 'guest_tiers に文字列 => 400（スキーマ）',
				'params'              => array( 'guest_tiers' => array( 'abc', 1 ) ),
				'expected_status'     => 400,
				'expected_code'       => 'rest_invalid_param',
			),
			array(
				'test_condition_name' => '合計が最大人数を超える => 400 guests_exceeded（マスクされない）',
				'params'              => array( 'guest_tiers' => array( 3, 1 ) ),
				'expected_status'     => 400,
				'expected_code'       => 'guests_exceeded',
			),
			array(
				'test_condition_name' => '区分の人数なし => 400 guests_required（マスクされない）',
				'params'              => array( 'guests' => 2 ),
				'expected_status'     => 400,
				'expected_code'       => 'guests_required',
			),
			array(
				'test_condition_name' => '正しい区分の人数 => 200',
				'params'              => array( 'guest_tiers' => array( 2, 1 ) ),
				'expected_status'     => 200,
				'expected_code'       => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$response = $this->request( array_merge( $base, $case['params'] ) );
			$this->assertSame( $case['expected_status'], $response->get_status(), $case['test_condition_name'] );
			if ( '' !== $case['expected_code'] ) {
				$this->assertSame( $case['expected_code'], $response->as_error()->get_error_code(), $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 入力に起因するエラーはそのまま返し、内部設定のエラーは権限のない訪問者へマスクすることを検証する。
	 */
	public function test_handle_lodging_availabilities_masks_internal_errors_only(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->set_site( 'lodging' );
		$room_id   = $this->create_room( '客室A' );
		$night     = $this->create_menu( array( $room_id ) );
		$time_slot = $this->create_menu( array( $room_id ), false );

		$test_cases = array(
			array(
				'test_condition_name' => '匿名・逆順の日付 => 入力エラーはそのまま（invalid_date_range・400）',
				'user'                => '',
				'params'              => array(
					'menu_id'        => $night,
					'check_in_date'  => $this->date_after( 12 ),
					'check_out_date' => $this->date_after( 10 ),
				),
				'status'              => 400,
				'code'                => 'invalid_date_range',
			),
			array(
				'test_condition_name' => '匿名・実在しない日付 => invalid_date',
				'params'              => array(
					'menu_id'        => $night,
					'check_in_date'  => '2098-02-30',
					'check_out_date' => '2098-03-02',
				),
				'user'                => '',
				'status'              => 400,
				'code'                => 'invalid_date',
			),
			array(
				'test_condition_name' => '匿名・時間枠のプラン => 内部設定のためマスク（reservation_unavailable）',
				'user'                => '',
				'params'              => array(
					'menu_id'        => $time_slot,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'status'              => 400,
				'code'                => 'reservation_unavailable',
			),
			array(
				'test_condition_name' => '管理者・時間枠のプラン => 詳細なエラー（menu_not_night_unit）',
				'user'                => 'administrator',
				'params'              => array(
					'menu_id'        => $time_slot,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'status'              => 400,
				'code'                => 'menu_not_night_unit',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( '' === $case['user'] ? 0 : $this->factory()->user->create( array( 'role' => $case['user'] ) ) );
			$response = $this->request( $case['params'] );
			$this->assertSame( $case['status'], $response->get_status(), $case['test_condition_name'] );
			$this->assertSame( $case['code'], $response->get_data()['code'], $case['test_condition_name'] );
		}
	}
}
