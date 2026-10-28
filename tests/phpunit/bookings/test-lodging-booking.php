<?php
/**
 * 泊単位のプランの予約（下書き・確定）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Booking_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Holiday_Calendar;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * 泊の下書き・確定（日付・客室・料金・締切・販売日・重複・宿泊が無効なサイトの拒否）を検証する。
 *
 * @group bookings
 * @group lodging
 */
class Lodging_Booking_Test extends WP_UnitTestCase {

	private const TRANSIENT_PREFIX = 'vkbm_draft_';

	/**
	 * 作った下書きのトークン（tearDown で削除する）。
	 *
	 * @var array<int, string>
	 */
	private array $tokens = array();

	/**
	 * テスト前の Cookie。
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

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

		$this->cookie_backup            = $_COOKIE;
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->set_site( 'lodging' );
		$this->reset_booking_cache_state();
	}

	/**
	 * テスト後に設定・フィルター・下書きを元に戻す。
	 */
	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
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
	 * 業種プリセットを設定する。
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
	}

	/**
	 * 客室（リソース）を作成する。
	 *
	 * @param string $surcharge    客室の追加料金（空なら設定しない）。
	 * @param string $post_status  投稿の状態。
	 * @param string $shift_status シフトの状態（空ならシフトを作らない）。
	 * @return int 客室の投稿 ID。
	 */
	private function create_room( string $surcharge = '', string $post_status = 'publish', string $shift_status = 'open' ): int {
		$room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_title'  => '客室',
				'post_status' => $post_status,
			)
		);
		if ( '' !== $surcharge ) {
			update_post_meta( $room_id, Staff_Editor::META_NOMINATION_FEE, $surcharge );
		}
		if ( '' !== $shift_status ) {
			$today = new DateTimeImmutable( 'now', wp_timezone() );
			foreach ( array( '-1 month', 'now', '+1 month', '+2 month' ) as $offset ) {
				$month = $today->modify( $offset );
				$days  = array();
				for ( $day = 1; $day <= 31; $day++ ) {
					$days[ $day ] = array(
						'status' => $shift_status,
						'slots'  => array(),
					);
				}
				$this->factory()->post->create(
					array(
						'post_type'   => Shift_Post_Type::POST_TYPE,
						'post_status' => 'publish',
						'meta_input'  => array(
							'_vkbm_shift_resource_id' => $room_id,
							'_vkbm_shift_year'        => (int) $month->format( 'Y' ),
							'_vkbm_shift_month'       => (int) $month->format( 'n' ),
							'_vkbm_shift_days'        => $days,
						),
					)
				);
			}
		}

		return $room_id;
	}

	/**
	 * 客室のシフトの、指定日の状態を休みにする。
	 *
	 * @param int    $room_id 客室 ID。
	 * @param string $date    日付（Y-m-d）。
	 */
	private function close_day( int $room_id, string $date ): void {
		$year  = (int) substr( $date, 0, 4 );
		$month = (int) substr( $date, 5, 2 );
		$day   = (int) substr( $date, 8, 2 );

		$shift_ids = get_posts(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- テストでシフトを特定するため。
					array(
						'key'   => '_vkbm_shift_resource_id',
						'value' => $room_id,
					),
					array(
						'key'   => '_vkbm_shift_year',
						'value' => $year,
					),
					array(
						'key'   => '_vkbm_shift_month',
						'value' => $month,
					),
				),
			)
		);
		foreach ( $shift_ids as $shift_id ) {
			$days = get_post_meta( (int) $shift_id, '_vkbm_shift_days', true );
			$days = is_array( $days ) ? $days : array();

			$days[ $day ] = array(
				'status' => 'regular_holiday',
				'slots'  => array(),
			);
			update_post_meta( (int) $shift_id, '_vkbm_shift_days', $days );
		}
	}

	/**
	 * 泊単位のメニューを作成する。
	 *
	 * @param array<int, int>      $room_ids 担当の客室 ID。
	 * @param array<string, mixed> $meta     追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( array $room_ids, array $meta = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', $room_ids );
		update_post_meta( $menu_id, '_vkbm_base_price', 10000 );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
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
	 * 泊の下書きを保存する（エラーも含めて結果をそのまま返す）。
	 *
	 * @param array<string, mixed> $payload リクエスト本文。
	 * @return WP_REST_Response|WP_Error
	 */
	private function post_draft( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = ( new Booking_Draft_Controller() )->save_draft( $request );
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( isset( $data['token'] ) ) {
				$this->tokens[] = (string) $data['token'];
			}
		}

		return $response;
	}

	/**
	 * 泊の下書きのリクエスト本文を作る。
	 *
	 * @param int                  $menu_id メニュー ID。
	 * @param int                  $room_id 客室 ID。
	 * @param int                  $in_days チェックインまでの日数。
	 * @param int                  $nights  泊数。
	 * @param array<string, mixed> $extra   追加・上書きする項目。
	 * @return array<string, mixed>
	 */
	private function lodging_payload( int $menu_id, int $room_id, int $in_days, int $nights, array $extra = array() ): array {
		return array_merge(
			array(
				'menu_id'        => $menu_id,
				'resource_id'    => $room_id,
				'check_in_date'  => $this->date_after( $in_days ),
				'check_out_date' => $this->date_after( $in_days + $nights ),
				'guests'         => 1,
			),
			$extra
		);
	}

	/**
	 * 泊の下書きを保存し、トークンを返す（保存できなければテストを失敗させる）。
	 *
	 * @param array<string, mixed> $payload リクエスト本文。
	 * @return string トークン。
	 */
	private function save_draft_token( array $payload ): string {
		$response = $this->post_draft( $payload );
		$this->assertInstanceOf( WP_REST_Response::class, $response, $response instanceof WP_Error ? $response->get_error_code() : '' );

		return (string) $response->get_data()['token'];
	}

	/**
	 * 下書きを確定する（ログイン済みの利用者として）。
	 *
	 * @param string $token 下書きのトークン。
	 * @return WP_REST_Response|WP_Error
	 */
	private function confirm( string $token ) {
		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			new Availability_Service()
		);
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		return $controller->create_booking( $request );
	}

	/**
	 * 1室1泊あたりでは、保存済みの最低申し込み人数を下書き・確定の受付制限に使わない。
	 */
	public function test_per_room_booking_ignores_nomination_min_guests(): void {
		$room_id = $this->create_room();
		$menu_id = $this->create_menu(
			array( $room_id ),
			array(
				Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
				'_vkbm_allow_multiple_guests' => true,
				'_vkbm_max_capacity'          => 3,
				'_vkbm_min_capacity'          => 2,
			)
		);

		wp_set_current_user( $this->factory()->user->create() );
		$token  = $this->save_draft_token( $this->lodging_payload( $menu_id, $room_id, 10, 1 ) );
		$result = $this->confirm( $token );

		$this->assertInstanceOf( WP_REST_Response::class, $result, $result instanceof WP_Error ? $result->get_error_code() : '' );
		$this->assertGreaterThan( 0, (int) ( $result->get_data()['booking_id'] ?? 0 ) );
	}

	/**
	 * 結果がエラーなら、エラーコードと HTTP ステータスを返す。成功なら ok。
	 *
	 * @param WP_REST_Response|WP_Error $result 結果。
	 * @return string `コード:ステータス` または ok。
	 */
	private function code_of( $result ): string {
		if ( $result instanceof WP_Error ) {
			$data = $result->get_error_data();

			return $result->get_error_code() . ':' . ( is_array( $data ) ? (string) ( $data['status'] ?? '' ) : '' );
		}

		return 'ok';
	}

	/**
	 * ロックの行（options テーブルの mutex）の件数を数える。
	 *
	 * @return int ロック行の件数。
	 */
	private function count_slot_lock_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストでロック行の有無を DB から直接確かめるため。
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_vkbm_booking_mutex_' ) . '%'
			)
		);
	}

	/**
	 * 泊の下書き保存: 日付・客室・締切・販売日の検証と、保存される値（サーバー計算）を検証する。
	 */
	public function test_save_draft(): void {
		$room     = $this->create_room( '3000' );
		$menu_id  = $this->create_menu( array( $room ), array( '_vkbm_reservation_deadline_hours' => 72 ) );
		$menu_nod = $this->create_menu( array( $room ) );
		$other    = $this->create_room();
		$draft    = $this->create_room( '', 'draft' );
		$closed   = $this->create_room();
		$this->close_day( $closed, $this->date_after( 11 ) );
		$menu_closed = $this->create_menu( array( $closed ) );
		$menu_other  = $this->create_menu( array( $other, $draft ) );
		wp_set_current_user( $this->factory()->user->create() );

		$test_cases = array(
			array(
				'test_condition_name' => '締切の外・正常な日付 => ok',
				'payload'             => $this->lodging_payload( $menu_id, $room, 10, 2 ),
				'expected'            => 'ok',
			),
			array(
				'test_condition_name' => '締切（72時間）の内側のチェックイン => reservation_deadline_passed',
				'payload'             => $this->lodging_payload( $menu_id, $room, 1, 1 ),
				'expected'            => 'reservation_deadline_passed:400',
			),
			array(
				'test_condition_name' => '存在しない日付 2026-02-31 => invalid_date',
				'payload'             => $this->lodging_payload( $menu_nod, $room, 10, 2, array( 'check_in_date' => '2026-02-31' ) ),
				'expected'            => 'invalid_date:400',
			),
			array(
				'test_condition_name' => 'チェックアウトがチェックインと同じ日 => invalid_date_range',
				'payload'             => $this->lodging_payload( $menu_nod, $room, 10, 0 ),
				'expected'            => 'invalid_date_range:400',
			),
			array(
				'test_condition_name' => '泊数の上限（30泊）超え => too_many_nights',
				'payload'             => $this->lodging_payload( $menu_nod, $room, 10, 31 ),
				'expected'            => 'too_many_nights:400',
			),
			array(
				'test_condition_name' => '過去のチェックイン => date_in_past',
				'payload'             => $this->lodging_payload( $menu_nod, $room, -3, 1 ),
				'expected'            => 'date_in_past:400',
			),
			array(
				'test_condition_name' => '客室の指名なし => room_required',
				'payload'             => $this->lodging_payload( $menu_nod, 0, 10, 1 ),
				'expected'            => 'room_required:400',
			),
			array(
				'test_condition_name' => 'メニューの担当ではない客室（予約の管理権限なし） => 汎用エラー（reservation_unavailable）',
				'payload'             => $this->lodging_payload( $menu_other, $room, 10, 1 ),
				'expected'            => 'reservation_unavailable:400',
			),
			array(
				'test_condition_name' => '公開されていない客室（予約の管理権限なし） => 汎用エラー（reservation_unavailable）',
				'payload'             => $this->lodging_payload( $menu_other, $draft, 10, 1 ),
				'expected'            => 'reservation_unavailable:400',
			),
			array(
				'test_condition_name' => '販売しない日（休み）を含む期間 => stay_not_on_sale',
				'payload'             => $this->lodging_payload( $menu_closed, $closed, 10, 3 ),
				'expected'            => 'stay_not_on_sale:409',
			),
			array(
				'test_condition_name' => '泊単位のプランを時間枠の経路で送る => lodging_dates_required',
				'payload'             => array(
					'menu_id' => $menu_nod,
					'slot'    => array(
						'slot_id'  => 'slot-1',
						'start_at' => $this->date_after( 10 ) . 'T10:00:00+09:00',
						'end_at'   => $this->date_after( 10 ) . 'T10:30:00+09:00',
					),
				),
				'expected'            => 'lodging_dates_required:400',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $this->post_draft( $case['payload'] );
			$this->assertSame( $case['expected'], $this->code_of( $result ), $case['test_condition_name'] );
		}

		// 予約の管理権限のある利用者には、元のエラーをそのまま返す。
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $admin_id );
		$this->assertSame( 'staff_not_assigned:400', $this->code_of( $this->post_draft( $this->lodging_payload( $menu_other, $room, 10, 1 ) ) ), '予約の管理権限あり・担当外の客室' );
		$this->assertSame( 'resource_not_found:400', $this->code_of( $this->post_draft( $this->lodging_payload( $menu_other, $draft, 10, 1 ) ) ), '予約の管理権限あり・未公開の客室' );
		wp_set_current_user( $this->factory()->user->create() );

		// 保存される値はサーバーで計算した値。送られてきた金額・泊数・日時・タイムゾーンは使わない。
		$token  = $this->save_draft_token(
			$this->lodging_payload(
				$menu_nod,
				$room,
				10,
				2,
				array(
					'total_price'     => 1,
					'nights'          => 99,
					'nomination_fee'  => 99999,
					'price_per_night' => 1,
					'slot'            => array(
						'slot_id'  => 'x',
						'start_at' => '2000-01-01T00:00:00+09:00',
						'end_at'   => '2000-01-02T00:00:00+09:00',
					),
					'meta'            => array( 'timezone' => 'America/New_York' ),
				)
			)
		);
		$stored = get_transient( self::TRANSIENT_PREFIX . $token );
		$this->assertSame( 2, $stored['lodging']['nights'] );
		$this->assertSame( $this->date_after( 10 ) . 'T15:00:00+09:00', $stored['slot']['start_at'] );
		$this->assertSame( $this->date_after( 12 ) . 'T10:00:00+09:00', $stored['slot']['end_at'] );
		$this->assertSame( 'Asia/Tokyo', $stored['meta']['timezone'] );
		$this->assertSame( $this->date_after( 10 ), $stored['date'], '下書きの日付はチェックイン日' );
		$this->assertSame( $room, $stored['resource_id'] );

		// 読み出しの合計は10000 × 2泊 = 20000（宿泊のサイトは客室の追加料金が保存されていても 0 円。送られた金額は使わない）。
		$draft_response = ( new Booking_Draft_Controller() )->get_draft(
			( function () use ( $token ) {
				$request = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
				$request->set_url_params( array( 'token' => $token ) );
				return $request;
			} )()
		);
		$this->assertInstanceOf( WP_REST_Response::class, $draft_response );
		$data = $draft_response->get_data();
		$this->assertSame( 20000, $data['total_price'] );
		$this->assertSame( 10000, $data['lodging']['price_per_night'] );
		$this->assertSame( 0, $data['lodging']['room_surcharge_per_night'] );
	}

	/**
	 * 泊の確定: 保存される日時・泊数・金額（改ざんされた下書きの値は無視）と、宿泊が無効なサイトの拒否を検証する。
	 */
	public function test_create_booking(): void {
		$room       = $this->create_room( '3000' );
		$menu_id    = $this->create_menu( array( $room ) );
		$menu_deadl = $this->create_menu( array( $room ) );
		$user_id    = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// 正常: チェックイン日時・チェックアウト日時・泊数・金額が保存される（金額は下書きの値を使わず再計算）。
		$token                       = $this->save_draft_token( $this->lodging_payload( $menu_id, $room, 10, 2 ) );
		$stored                      = get_transient( self::TRANSIENT_PREFIX . $token );
		$stored['lodging']['nights'] = 99;
		$stored['total_price']       = 1;
		$stored['nomination_fee']    = 99999;
		$stored['slot']['start_at']  = '2000-01-01T00:00:00+09:00';
		set_transient( self::TRANSIENT_PREFIX . $token, $stored, 1800 );

		$result = $this->confirm( $token );
		$this->assertSame( 'ok', $this->code_of( $result ) );
		$booking_id = (int) $result->get_data()['booking_id'];

		$this->assertSame( $this->date_after( 10 ) . ' 15:00:00', get_post_meta( $booking_id, Booking_Overlap::META_START, true ) );
		$this->assertSame( $this->date_after( 12 ) . ' 10:00:00', get_post_meta( $booking_id, Booking_Overlap::META_END, true ) );
		$this->assertSame( $this->date_after( 12 ) . ' 10:00:00', get_post_meta( $booking_id, Booking_Overlap::META_TOTAL_END, true ) );
		$this->assertSame( (string) $room, (string) get_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, true ) );
		$this->assertSame( '2', (string) get_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, true ) );
		$this->assertSame( '10000', (string) get_post_meta( $booking_id, Lodging_Booking::META_PRICE_PER_NIGHT, true ) );
		$this->assertSame( '20000', (string) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ) );
		$this->assertSame( 0, $this->count_slot_lock_rows(), '確定後にロックが残らない' );
		$this->assertFalse( get_transient( self::TRANSIENT_PREFIX . $token ), '確定後は下書きが消える' );

		// 締切の内側のチェックインは確定で拒否される（下書きの保存後に締切が厳しくなった場合も拒否）。
		$token_deadline = $this->save_draft_token( $this->lodging_payload( $menu_deadl, $room, 1, 1 ) );
		update_post_meta( $menu_deadl, '_vkbm_reservation_deadline_hours', 72 );
		$this->assertSame( 'reservation_deadline_passed:400', $this->code_of( $this->confirm( $token_deadline ) ), '締切の内側のチェックイン' );
		$this->assertSame( 0, $this->count_slot_lock_rows() );

		// 宿泊が有効でないサイトでは、泊の下書き・確定を拒否する。
		$token_disabled = $this->save_draft_token( $this->lodging_payload( $menu_id, $room, 14, 1 ) );
		$this->set_site( 'custom' );
		$this->assertSame( 'rest_no_route:404', $this->code_of( $this->post_draft( $this->lodging_payload( $menu_id, $room, 14, 1 ) ) ), '宿泊が無効なサイトの下書き' );
		$this->assertSame( 'rest_no_route:404', $this->code_of( $this->confirm( $token_disabled ) ), '宿泊が無効なサイトの確定' );
		$this->assertSame( 0, $this->count_slot_lock_rows() );

		// 泊単位のプランの下書きを時間枠の下書きとして確定しようとしても、宿泊が有効なサイトでは拒否する。
		$this->set_site( 'lodging' );
		$slot_token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		set_transient(
			self::TRANSIENT_PREFIX . $slot_token,
			array(
				'menu_id'       => $menu_id,
				'resource_id'   => $room,
				'guests'        => 1,
				'owner_user_id' => $user_id,
				'slot'          => array(
					'slot_id'  => 'slot-1',
					'start_at' => $this->date_after( 10 ) . 'T10:00:00+09:00',
					'end_at'   => $this->date_after( 10 ) . 'T10:30:00+09:00',
				),
			),
			1800
		);
		$this->tokens[] = $slot_token;
		$this->assertSame( 'lodging_dates_required:400', $this->code_of( $this->confirm( $slot_token ) ), '泊単位のプランを時間枠の下書きで確定' );

		// 未ログインは確定できない。
		wp_set_current_user( 0 );
		$this->assertSame( 'not_logged_in:401', $this->code_of( $this->confirm( $token_disabled ) ), '未ログイン' );
	}

	/**
	 * 休前日の料金がある泊のプラン（#737）: 金曜から 2 泊で、金曜は平日・土曜は休前日の単価になり、
	 * 空室一覧・下書き・確定が同じ合計になる。泊ごとの内訳は予約に固定で保存され、単価が違うため 1泊あたりの合計は保存しない。
	 */
	public function test_night_rates_are_the_same_for_availability_draft_and_confirm(): void {
		$room = $this->create_room( '3000' );
		wp_set_current_user( $this->factory()->user->create() );

		// 今日から 10 日後以降で、翌日が祝日でない最初の金曜を探す（金曜の泊が平日の単価になる日）。
		$in_days = 10;
		while ( '5' !== ( new DateTimeImmutable( $this->date_after( $in_days ), wp_timezone() ) )->format( 'N' ) || Holiday_Calendar::is_holiday_eve( $this->date_after( $in_days ) ) ) {
			++$in_days;
		}
		$friday   = $this->date_after( $in_days );
		$saturday = $this->date_after( $in_days + 1 );

		$test_cases = array(
			array(
				'test_condition_name' => '休前日の料金あり: 金曜 10,000 + 土曜 15,000 = 25,000円（客室の追加料金は 0 円）',
				'meta'                => array( Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 15000 ),
				'special_dates'       => array(),
				'expected_total'      => 25000,
				'expected_per_night'  => null,
				'expected_rates'      => array(
					array( $friday, 'weekday', '' ),
					array( $saturday, 'pre_holiday', '' ),
				),
				'expected_breakdown'  => array(
					array(
						'date'           => $friday,
						'rate'           => 'weekday',
						'plan_price'     => 10000,
						'room_surcharge' => 0,
						'price'          => 10000,
					),
					array(
						'date'           => $saturday,
						'rate'           => 'pre_holiday',
						'plan_price'     => 15000,
						'room_surcharge' => 0,
						'price'          => 15000,
					),
				),
			),
			array(
				'test_condition_name' => '土曜を特定日に登録: 土曜は特定日の料金 20,000 が休前日より優先 => 10,000 + 20,000 = 30,000円',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 15000,
					Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL     => 20000,
				),
				'special_dates'       => array(
					array(
						'start' => $saturday,
						'end'   => $saturday,
						'label' => '連休',
					),
				),
				'expected_total'      => 30000,
				'expected_per_night'  => null,
				'expected_rates'      => array(
					array( $friday, 'weekday', '' ),
					array( $saturday, 'special', '連休' ),
				),
				'expected_breakdown'  => array(
					array(
						'date'           => $friday,
						'rate'           => 'weekday',
						'plan_price'     => 10000,
						'room_surcharge' => 0,
						'price'          => 10000,
					),
					array(
						'date'           => $saturday,
						'rate'           => 'special',
						'plan_price'     => 20000,
						'room_surcharge' => 0,
						'price'          => 20000,
					),
				),
			),
			array(
				'test_condition_name' => '既存プラン（休前日・特定日が空欄）: 10,000円 × 2 = 20,000円。1泊あたりの合計も保存する',
				'meta'                => array(),
				'special_dates'       => array(),
				'expected_total'      => 20000,
				'expected_per_night'  => 10000,
				'expected_rates'      => null,
				'expected_breakdown'  => array(
					array(
						'date'           => $friday,
						'rate'           => 'weekday',
						'plan_price'     => 10000,
						'room_surcharge' => 0,
						'price'          => 10000,
					),
					array(
						'date'           => $saturday,
						'rate'           => 'weekday',
						'plan_price'     => 10000,
						'room_surcharge' => 0,
						'price'          => 10000,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$settings                          = get_option( Settings_Repository::OPTION_KEY );
			$settings['lodging_special_dates'] = $case['special_dates'];
			update_option( Settings_Repository::OPTION_KEY, $settings );

			$menu_id = $this->create_menu( array( $room ), $case['meta'] );

			// 空室一覧: 客室の合計と泊ごとの内訳。
			$availability = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $friday,
					'check_out_date' => $this->date_after( $in_days + 2 ),
					'guests'         => 1,
				)
			);
			$this->assertIsArray( $availability, $case['test_condition_name'] . ': 空室一覧が返る' );
			$this->assertSame( $case['expected_total'], $availability['rooms'][0]['total_price'], $case['test_condition_name'] . ': 空室一覧の合計' );
			$this->assertSame( $case['expected_per_night'], $availability['rooms'][0]['price_per_night'], $case['test_condition_name'] . ': 空室一覧の1泊あたり' );
			$this->assertSame( $case['expected_breakdown'], $availability['rooms'][0]['night_breakdown'], $case['test_condition_name'] . ': 空室一覧の内訳' );
			$this->assertSame( null !== $case['expected_rates'], $availability['rate_tiers_enabled'], $case['test_condition_name'] . ': 空室一覧の区分表示の有無' );
			$this->assertSame( $case['expected_rates'] ?? array(), $this->to_rate_rows( $availability['night_rates'] ), $case['test_condition_name'] . ': 空室一覧の泊ごとの区分（客室を選ぶ前に出す）' );

			// 下書き: 読み出しの合計。単価が違う予約は1泊あたりを返さない（確認画面の「1泊あたり」の行を出さない）。
			$token = $this->save_draft_token( $this->lodging_payload( $menu_id, $room, $in_days, 2 ) );
			$data  = $this->get_draft_data( $token );
			$this->assertSame( $case['expected_total'], $data['total_price'], $case['test_condition_name'] . ': 下書きの合計' );
			$this->assertSame( $case['expected_per_night'], $data['lodging']['price_per_night'], $case['test_condition_name'] . ': 下書きの1泊あたり' );
			$this->assertSame( $case['expected_breakdown'], $data['lodging']['night_breakdown'], $case['test_condition_name'] . ': 下書きの内訳' );
			$this->assertSame( null !== $case['expected_rates'], $data['lodging']['rate_tiers_enabled'], $case['test_condition_name'] . ': 下書きの区分表示の有無' );
			$this->assertSame( $case['expected_rates'] ?? array(), $this->to_rate_rows( $data['lodging']['night_rates'] ), $case['test_condition_name'] . ': 下書きの泊ごとの区分' );

			// 確定: 合計と内訳が予約に保存される。
			$result = $this->confirm( $token );
			$this->assertSame( 'ok', $this->code_of( $result ), $case['test_condition_name'] . ': 確定できる' );
			$booking_id = (int) $result->get_data()['booking_id'];
			$this->assertSame( (string) $case['expected_total'], (string) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $case['test_condition_name'] . ': 保存した合計' );
			$this->assertSame( $case['expected_breakdown'], get_post_meta( $booking_id, Lodging_Booking::META_NIGHT_BREAKDOWN, true ), $case['test_condition_name'] . ': 保存した内訳' );
			if ( null === $case['expected_per_night'] ) {
				$this->assertFalse( metadata_exists( 'post', $booking_id, Lodging_Booking::META_PRICE_PER_NIGHT ), $case['test_condition_name'] . ': 単価が違う予約は1泊あたりを保存しない' );
			} else {
				$this->assertSame( (string) $case['expected_per_night'], (string) get_post_meta( $booking_id, Lodging_Booking::META_PRICE_PER_NIGHT, true ), $case['test_condition_name'] . ': 1泊あたりを保存する' );
			}

			// 確定後に料金設定を変えても、予約の合計と内訳は変わらない。
			update_post_meta( $menu_id, '_vkbm_base_price', 99999 );
			$this->assertSame( $case['expected_breakdown'], get_post_meta( $booking_id, Lodging_Booking::META_NIGHT_BREAKDOWN, true ), $case['test_condition_name'] . ': 料金設定を変えても内訳は固定' );

			// 次のケースが同じ客室・同じ期間で重ならないよう、確定した予約を消す。
			wp_delete_post( $booking_id, true );
		}
	}

	/**
	 * 泊ごとの区分（night_rates）を、比較しやすい [ 日付, 区分, 特定日の名称 ] の並びにする。
	 *
	 * @param array<int, array<string, string>> $night_rates 泊ごとの区分。
	 * @return array<int, array{0: string, 1: string, 2: string}> 並び替えた区分。
	 */
	private function to_rate_rows( array $night_rates ): array {
		return array_map(
			static fn( array $night ): array => array( $night['date'], $night['rate'], $night['special_label'] ),
			$night_rates
		);
	}

	/**
	 * 同じ客室への確定: 別プラン・同じ期間、一部が重なる期間は2件目が 409。販売日が確定までに変わった期間も拒否する。
	 */
	public function test_create_booking_rejects_overlapping_stays_for_same_room(): void {
		$room   = $this->create_room();
		$plan_a = $this->create_menu( array( $room ) );
		$plan_b = $this->create_menu( array( $room ), array( '_vkbm_base_price' => 20000 ) );
		$room2  = $this->create_room();
		$plan_c = $this->create_menu( array( $room2 ) );
		wp_set_current_user( $this->factory()->user->create() );

		// 1件目: プラン A で 10日後から2泊。
		$first = $this->confirm( $this->save_draft_token( $this->lodging_payload( $plan_a, $room, 10, 2 ) ) );
		$this->assertSame( 'ok', $this->code_of( $first ), '1件目' );

		// 下書きは1件目の確定前に取ったものとして、期間が埋まった後に確定する（同時に取った下書きの確定を再現する）。
		$test_cases = array(
			array(
				'test_condition_name' => '別プラン・同じ期間 => 409',
				'payload'             => $this->lodging_payload( $plan_b, $room, 10, 2 ),
				'expected'            => 'staff_unavailable:409',
			),
			array(
				'test_condition_name' => '同じプラン・一部が重なる期間（2泊目から） => 409',
				'payload'             => $this->lodging_payload( $plan_a, $room, 11, 2 ),
				'expected'            => 'staff_unavailable:409',
			),
			array(
				'test_condition_name' => '別プラン・手前から重なる期間（前日から2泊） => 409',
				'payload'             => $this->lodging_payload( $plan_b, $room, 9, 2 ),
				'expected'            => 'staff_unavailable:409',
			),
			array(
				'test_condition_name' => '別プラン・期間を内側に含む1泊 => 409',
				'payload'             => $this->lodging_payload( $plan_b, $room, 10, 1 ),
				'expected'            => 'staff_unavailable:409',
			),
			array(
				'test_condition_name' => '別の客室の同じ期間 => ok',
				'payload'             => $this->lodging_payload( $plan_c, $room2, 10, 2 ),
				'expected'            => 'ok',
			),
			array(
				'test_condition_name' => '同じ客室でチェックアウト日にチェックインする連泊（境界） => ok',
				'payload'             => $this->lodging_payload( $plan_b, $room, 12, 1 ),
				'expected'            => 'ok',
			),
		);

		// 1件目の確定より前に作られた下書きを再現するため、下書きは重なりを検証しない（確定時だけが重なりの権威）。
		foreach ( $test_cases as $case ) {
			// 同じ利用者の重なる予約は利用者単位の重複で先に拒否されるため、ケースごとに別の利用者として確定する。
			wp_set_current_user( $this->factory()->user->create() );
			$token = $this->save_draft_token( $case['payload'] );
			$this->assertSame( $case['expected'], $this->code_of( $this->confirm( $token ) ), $case['test_condition_name'] );
			$this->assertSame( 0, $this->count_slot_lock_rows(), $case['test_condition_name'] . '（ロックが残らない）' );
		}

		// 客室のロックは、客室 ID だけで決まる（メニュー・期間が違っても、同じ客室の確定は1件ずつ処理される）。
		// 別のリクエストが同じ客室のロックを保持している状態を再現し、重ならない期間・別プランでも確定が待たされることを確認する。
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで別リクエストのロックを再現するため。
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				'_transient_vkbm_booking_mutex_room_' . $room,
				'other-request-token|' . time()
			)
		);
		wp_set_current_user( $this->factory()->user->create() );
		$busy_token = $this->save_draft_token( $this->lodging_payload( $plan_b, $room, 14, 1 ) );
		$this->assertSame( 'booking_busy:409', $this->code_of( $this->confirm( $busy_token ) ), '同じ客室のロックを別のリクエストが保持している間' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで再現したロックを片付けるため。
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_vkbm_booking_mutex_room_' . $room ) );
		$this->assertSame( 'ok', $this->code_of( $this->confirm( $busy_token ) ), 'ロックが解放された後は確定できる' );

		// ロック中の販売日の再確認: 事前の検証（prepare()）を通った後に休みが登録された期間は、ロック中の再確認で拒否され、ロックも残らない。
		$closed_room = $this->create_room();
		$plan_d      = $this->create_menu( array( $closed_room ) );
		$stay        = Lodging_Booking::prepare(
			new Availability_Service(),
			array(
				'menu_id'        => $plan_d,
				'resource_id'    => $closed_room,
				'check_in_date'  => $this->date_after( 10 ),
				'check_out_date' => $this->date_after( 13 ),
			)
		);
		$this->assertIsArray( $stay, '休みの登録前は事前の検証を通る' );
		$this->close_day( $closed_room, $this->date_after( 11 ) );

		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			new Availability_Service()
		);
		$method     = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_lodging_room_with_mutex' );
		$method->setAccessible( true );
		$this->assertSame( 'stay_not_on_sale:409', $this->code_of( $method->invoke( $controller, $stay ) ), 'ロック中に休みの日を含むと分かった期間' );
		$this->assertSame( 0, $this->count_slot_lock_rows(), 'ロックを解放してから拒否する' );

		// 確定された予約は重なる期間ごとに1件だけ（2件目以降の予約投稿が作られていない）。
		$booking_ids = get_posts(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Booking_Overlap::META_RESOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- テストの検証のため。
				'meta_value'     => $room, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- テストの検証のため。
			)
		);
		$this->assertCount( 3, $booking_ids, '同じ客室の予約は、重ならない3件（10日後から2泊・12日後から1泊・14日後から1泊）だけ' );
	}

	/**
	 * 下書きの読み出し（GET）の結果を返す。
	 *
	 * @param string $token 下書きのトークン。
	 * @return array<string, mixed> レスポンスのデータ。
	 */
	private function get_draft_data( string $token ): array {
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
		$request->set_url_params( array( 'token' => $token ) );
		$response = ( new Booking_Draft_Controller() )->get_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response->get_data();
	}

	/**
	 * 空室一覧の料金と、下書き・確定で保存される料金が一致し、人数の決め方が3か所で同じであることを検証する。
	 * 上限（複数人一括予約の設定）を超える人数は丸めずに拒否する。
	 */
	public function test_guests_are_resolved_the_same_for_availability_draft_and_confirm(): void {
		$room = $this->create_room( '3000' );
		wp_set_current_user( $this->factory()->user->create() );
		$multi = array(
			'_vkbm_allow_multiple_guests' => '1',
			'_vkbm_max_capacity'          => '3',
		);
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 12000,
			),
			array(
				'label' => '子供',
				'price' => 6000,
			),
		);
		// 1室1泊あたりで規定人数を超えた分の追加料金（#934）。1室10,000円、規定 4 名、最大 6 名。
		$extra_guest_meta = array(
			Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
			Service_Menu_Post_Type::META_NIGHT_EXTRA_GUEST_ENABLED => '1',
			Service_Menu_Post_Type::META_NIGHT_INCLUDED_GUESTS => 4,
			'_vkbm_max_capacity' => '6',
			'_vkbm_price_tiers'  => array(
				array(
					'label' => '大人',
					'price' => 3000,
				),
				array(
					'label' => '子供',
					'price' => 2000,
				),
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '複数人の設定なし・2名 => 空室一覧・下書きとも guests_exceeded（1名に丸めない）',
				'meta'                => array(),
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '複数人の設定あり（最大3名）・2名・1名1泊あたり: 10000 × 2 × 2',
				'meta'                => $multi,
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'ok',
				'expected_total'      => 40000,
			),
			array(
				'test_condition_name' => '最大3名・3名ちょうど: 10000 × 3 × 2',
				'meta'                => $multi,
				'extra'               => array( 'guests' => 3 ),
				'expected'            => 'ok',
				'expected_total'      => 60000,
			),
			array(
				'test_condition_name' => '許可フラグがOFFでも最大3名の設定あり・2名・1名1泊あたり => guests_exceeded（従来どおり1名に限る。#831）',
				'meta'                => array(
					'_vkbm_allow_multiple_guests' => '',
					'_vkbm_max_capacity'          => '3',
				),
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '1室1泊あたり・許可フラグ未保存・最大3名・3名ちょうど: 10000 × 2（#831）',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
					'_vkbm_max_capacity' => '3',
				),
				'extra'               => array( 'guests' => 3 ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・許可フラグOFF・最大3名・2名: 10000 × 2（#831）',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
					'_vkbm_allow_multiple_guests' => '',
					'_vkbm_max_capacity'          => '3',
				),
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・許可フラグOFF・最大3名・4名 => guests_exceeded（#831）',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
					'_vkbm_allow_multiple_guests' => '',
					'_vkbm_max_capacity'          => '3',
				),
				'extra'               => array( 'guests' => 4 ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '最大3名・4名 => guests_exceeded',
				'meta'                => $multi,
				'extra'               => array( 'guests' => 4 ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '1室1泊あたり・3名: 10000 × 2（人数は料金に影響しない）',
				'meta'                => array_merge( $multi, array( Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM ) ),
				'extra'               => array( 'guests' => 3 ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・区分のデータが残っている・3名: 区分は使わず 10000 × 2',
				'meta'                => array_merge(
					$multi,
					array(
						Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
						'_vkbm_price_tiers' => $tiers,
					)
				),
				'extra'               => array( 'guests' => 3 ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・規定 4 名・大人 2 名＋子供 2 名（規定人数以内）: 10000 × 2（基本料金のみ。#934）',
				'meta'                => $extra_guest_meta,
				'extra'               => array( 'guest_tiers' => array( 2, 2 ) ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・規定 4 名・大人 3 名＋子供 2 名: (10000 + 子供 1 名分 2000) × 2（#934）',
				'meta'                => $extra_guest_meta,
				'extra'               => array( 'guest_tiers' => array( 3, 2 ) ),
				'expected'            => 'ok',
				'expected_total'      => 24000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・規定 4 名・大人 5 名＋子供 1 名（最大 6 名ちょうど）: 高い大人 4 名が含まれ、大人 1 名 3000 円＋子供 1 名 2000 円を加算 (10000 + 5000) × 2（#934）',
				'meta'                => $extra_guest_meta,
				'extra'               => array( 'guest_tiers' => array( 5, 1 ) ),
				'expected'            => 'ok',
				'expected_total'      => 30000,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・合計が最大 6 名を超える（大人 6 名＋子供 1 名） => guests_exceeded（#934）',
				'meta'                => $extra_guest_meta,
				'extra'               => array( 'guest_tiers' => array( 6, 1 ) ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・区分の人数なし => guests_required（#934）',
				'meta'                => $extra_guest_meta,
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'guests_required:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金のチェックなし（区分のデータが残っている）・大人 3 名＋子供 2 名: 区分は使わず 10000 × 2（#934）',
				'meta'                => array_merge( $extra_guest_meta, array( Service_Menu_Post_Type::META_NIGHT_EXTRA_GUEST_ENABLED => '' ) ),
				'extra'               => array( 'guest_tiers' => array( 3, 2 ) ),
				'expected'            => 'ok',
				'expected_total'      => 20000,
			),
			array(
				'test_condition_name' => '1名1泊あたりに戻した（追加料金のメタは残っている）・大人 3 名＋子供 2 名: 区分の単価 × 人数 = (3000 × 3 + 2000 × 2) × 2（#934）',
				'meta'                => array_merge(
					$extra_guest_meta,
					array(
						Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => '',
						'_vkbm_allow_multiple_guests' => '1',
					)
				),
				'extra'               => array( 'guest_tiers' => array( 3, 2 ) ),
				'expected'            => 'ok',
				'expected_total'      => 26000,
			),
			array(
				'test_condition_name' => '料金区分あり・大人2名＋子供1名: (12000 × 2 + 6000) × 2',
				'meta'                => array_merge( $multi, array( '_vkbm_price_tiers' => $tiers ) ),
				'extra'               => array( 'guest_tiers' => array( 2, 1 ) ),
				'expected'            => 'ok',
				'expected_total'      => 60000,
			),
			array(
				'test_condition_name' => '料金区分あり・区分の人数なし => guests_required',
				'meta'                => array_merge( $multi, array( '_vkbm_price_tiers' => $tiers ) ),
				'extra'               => array( 'guests' => 2 ),
				'expected'            => 'guests_required:400',
				'expected_total'      => 0,
			),
			array(
				'test_condition_name' => '料金区分あり・合計が最大人数を超える（大人3名＋子供1名） => guests_exceeded',
				'meta'                => array_merge( $multi, array( '_vkbm_price_tiers' => $tiers ) ),
				'extra'               => array( 'guest_tiers' => array( 3, 1 ) ),
				'expected'            => 'guests_exceeded:400',
				'expected_total'      => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$name    = $case['test_condition_name'];
			$menu_id = $this->create_menu( array( $room ), $case['meta'] );

			// 空室一覧（REST の引数と同じ形で渡す）。
			$availability = ( new Availability_Service() )->get_lodging_availability(
				array_merge(
					array(
						'menu_id'        => $menu_id,
						'check_in_date'  => $this->date_after( 10 ),
						'check_out_date' => $this->date_after( 12 ),
					),
					$case['extra']
				)
			);
			$draft        = $this->post_draft( $this->lodging_payload( $menu_id, $room, 10, 2, $case['extra'] ) );

			if ( 'ok' !== $case['expected'] ) {
				$this->assertSame( $case['expected'], $this->code_of( $availability ), $name . '（空室一覧）' );
				$this->assertSame( $case['expected'], $this->code_of( $draft ), $name . '（下書き）' );
				continue;
			}

			$this->assertIsArray( $availability, $name );
			$this->assertSame( $case['expected_total'], $availability['rooms'][0]['total_price'], $name . '（空室一覧の料金）' );
			$this->assertInstanceOf( WP_REST_Response::class, $draft, $name );
			$token = (string) $draft->get_data()['token'];

			// 下書きは人数を丸めずに保存し、読み出しの合計は空室一覧と同じ。
			$stored = get_transient( self::TRANSIENT_PREFIX . $token );
			$data   = $this->get_draft_data( $token );
			// 要求した人数がそのまま保存されること（全段階で同じ誤った人数に丸められても検出する）。
			if ( isset( $case['extra']['guests'] ) ) {
				$this->assertSame( (int) $case['extra']['guests'], (int) $stored['guests'], $name . '（要求した人数が保存される）' );
			}
			$this->assertSame( $stored['guests'], $data['guests'], $name . '（保存した人数）' );
			$this->assertSame( $case['expected_total'], $data['total_price'], $name . '（下書きの合計）' );

			// 確定で保存される合計・人数は空室一覧・下書きと同じ。
			$result = $this->confirm( $token );
			$this->assertSame( 'ok', $this->code_of( $result ), $name . '（確定）' );
			$booking_id = (int) $result->get_data()['booking_id'];
			$this->assertSame( (string) $case['expected_total'], (string) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $name . '（確定で保存される合計）' );
			$this->assertSame( (string) $stored['guests'], (string) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), $name . '（確定で保存される人数）' );

			// 次のケースの期間と重ならないよう、予約を消す。
			wp_delete_post( $booking_id, true );
		}

		// 下書きの後で上限が下がった（最大3名 → 1名）場合、確定は人数を丸めずに拒否する。
		$menu_id = $this->create_menu( array( $room ), $multi );
		$token   = $this->save_draft_token( $this->lodging_payload( $menu_id, $room, 10, 2, array( 'guests' => 3 ) ) );
		update_post_meta( $menu_id, '_vkbm_max_capacity', '1' );
		$this->assertSame( 'guests_exceeded:400', $this->code_of( $this->confirm( $token ) ), '下書きの後に上限が下がったとき' );
		$this->assertSame( 0, $this->count_slot_lock_rows() );
	}
}
