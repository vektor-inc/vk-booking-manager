<?php
/**
 * 泊単位のプランの空き状況（Availability_Service::get_lodging_availability）のテスト。
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
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;

/**
 * 日付の検証・客室の検証・宿泊が有効でないサイトの拒否・料金・キャッシュの無効化を検証する。
 *
 * @group availability
 * @group lodging
 */
class Lodging_Availability_Test extends WP_UnitTestCase {

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
		$this->set_site( 'lodging' );
		$this->reset_booking_cache_state();
	}

	/**
	 * テスト後に設定・フィルター・キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
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
	 * @param string $title         客室名。
	 * @param string $surcharge     客室の追加料金（空なら設定しない）。
	 * @param string $post_status   投稿の状態。
	 * @param string $shift_status  シフトの状態（空ならシフトを作らない）。
	 * @return int 客室の投稿 ID。
	 */
	private function create_room( string $title, string $surcharge = '', string $post_status = 'publish', string $shift_status = 'open' ): int {
		$room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $post_status,
			)
		);
		if ( '' !== $surcharge ) {
			update_post_meta( $room_id, Staff_Editor::META_NOMINATION_FEE, $surcharge );
		}
		if ( '' !== $shift_status ) {
			// 検証に使う年月（来月から数か月）の全日を同じ状態にする。
			foreach ( $this->get_shift_months() as $month ) {
				$days = array();
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
							'_vkbm_shift_year'        => (int) substr( $month, 0, 4 ),
							'_vkbm_shift_month'       => (int) substr( $month, 5, 2 ),
							'_vkbm_shift_days'        => $days,
						),
					)
				);
			}
		}

		return $room_id;
	}

	/**
	 * シフトを作る年月（今日の前後と、検証に使う遠い将来）の一覧を返す。
	 *
	 * @return array<int, string> 年月（Y-m）。
	 */
	private function get_shift_months(): array {
		$today = new DateTimeImmutable( 'now', wp_timezone() );

		return array(
			$today->modify( '-1 month' )->format( 'Y-m' ),
			$today->format( 'Y-m' ),
			$today->modify( '+1 month' )->format( 'Y-m' ),
			$today->modify( '+2 month' )->format( 'Y-m' ),
		);
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
	 * 今日のチェックインが締切に掛からないメニューのメタ（締切 0 時間・チェックイン 23:59）を返す。
	 *
	 * @return array<string, mixed> メタ。
	 */
	private function today_check_in_meta(): array {
		return array(
			Service_Menu_Post_Type::META_CHECK_IN_TIME => '23:59',
			'_vkbm_reservation_deadline_hours'         => 0,
		);
	}

	/**
	 * サイトの現在時刻が 23:59 台なら、今日のチェックイン（23:59）が締切を過ぎるためスキップする。
	 */
	private function skip_if_local_time_is_end_of_day(): void {
		if ( '23:59' === ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'H:i' ) ) {
			$this->markTestSkipped( 'サイトの現在時刻が 23:59 台のため、今日のチェックイン（23:59）が締切を過ぎる。' );
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
	 * 予約を作成する。
	 *
	 * @param int    $room_id 客室 ID。
	 * @param string $start   開始（Y-m-d H:i:s）。
	 * @param string $end     終了（Y-m-d H:i:s）。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( int $room_id, string $start, string $end ): int {
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

		return $booking_id;
	}

	/**
	 * 返ったレスポンスから空室の客室 ID の一覧を取り出す。
	 *
	 * @param array<string, mixed>|WP_Error $response レスポンス。
	 * @return array<int, int> 客室 ID。
	 */
	private function room_ids_of( $response ): array {
		$this->assertIsArray( $response, $response instanceof WP_Error ? $response->get_error_code() : '' );

		return array_map( static fn( array $room ): int => (int) $room['id'], $response['rooms'] );
	}

	/**
	 * 空室の一覧・料金（1名1泊あたり／1室1泊あたり）を検証する。宿泊のサイトは客室に追加料金が保存されていても 0 円になる（#736）。
	 */
	public function test_get_lodging_availability_returns_rooms_with_price(): void {
		$room_a = $this->create_room( '客室A', '3000' );
		$room_b = $this->create_room( '客室B' );

		$test_cases = array(
			array(
				'test_condition_name' => '1名1泊あたり・2名・2泊: (10000 × 2) × 2（追加料金は 0 円）',
				'meta'                => array(
					'_vkbm_allow_multiple_guests' => '1',
					'_vkbm_max_capacity'          => '4',
				),
				'guests'              => 2,
				'expected'            => array(
					$room_a => array( 20000, 0, 20000, 40000, 'per_guest' ),
					$room_b => array( 20000, 0, 20000, 40000, 'per_guest' ),
				),
			),
			array(
				'test_condition_name' => '1室1泊あたり・人数は無視・2泊: 10000 × 2（追加料金は 0 円）',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
					'_vkbm_allow_multiple_guests' => '1',
					'_vkbm_max_capacity'          => '4',
				),
				'guests'              => 4,
				'expected'            => array(
					$room_a => array( 10000, 0, 10000, 20000, 'per_room' ),
					$room_b => array( 10000, 0, 10000, 20000, 'per_room' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( array( $room_a, $room_b ), $case['meta'] );
			$result  = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 12 ),
					'guests'         => $case['guests'],
				)
			);

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$this->assertSame( 2, $result['nights'], $case['test_condition_name'] );
			$this->assertSame( '15:00', $result['check_in_time'], $case['test_condition_name'] );
			$this->assertSame( '10:00', $result['check_out_time'], $case['test_condition_name'] );
			$this->assertSame( 'Asia/Tokyo', $result['timezone'], $case['test_condition_name'] );
			$this->assertCount( 2, $result['rooms'], $case['test_condition_name'] );
			foreach ( $result['rooms'] as $room ) {
				$expected = $case['expected'][ $room['id'] ];
				$this->assertSame(
					$expected,
					array( $room['plan_price_per_night'], $room['room_surcharge_per_night'], $room['price_per_night'], $room['total_price'], $room['price_unit'] ),
					$case['test_condition_name'] . '（客室 ' . $room['name'] . '）'
				);
			}
			$this->assertSame( array( '客室A', '客室B' ), array_column( $result['rooms'], 'name' ), $case['test_condition_name'] . '（客室名）' );
		}
	}

	/**
	 * 予約・販売しない日・シフト未登録の客室が、空室から外れることを検証する。
	 */
	public function test_get_lodging_availability_excludes_unavailable_rooms(): void {
		$free_room    = $this->create_room( '空室' );
		$booked_room  = $this->create_room( '予約済み' );
		$closed_room  = $this->create_room( '休み', '', 'publish', 'regular_holiday' );
		$no_shift     = $this->create_room( 'シフト未登録', '', 'publish', '' );
		$menu_id      = $this->create_menu( array( $free_room, $booked_room, $closed_room, $no_shift ) );
		$check_in     = $this->date_after( 10 );
		$check_out    = $this->date_after( 13 );
		$middle_night = $this->date_after( 11 );

		// 連泊の真ん中の泊に、1件だけ予約を入れる。
		$this->create_booking( $booked_room, $middle_night . ' 15:00:00', $this->date_after( 12 ) . ' 10:00:00' );

		$result = ( new Availability_Service() )->get_lodging_availability(
			array(
				'menu_id'        => $menu_id,
				'check_in_date'  => $check_in,
				'check_out_date' => $check_out,
			)
		);

		$this->assertSame( array( $free_room ), $this->room_ids_of( $result ), '予約済み・販売しない日・シフト未登録の客室は外れる' );
		$this->assertSame( 3, $result['nights'], '3泊' );
	}

	/**
	 * 空室が無いときはエラーではなく空の一覧を返すことを検証する。
	 */
	public function test_get_lodging_availability_returns_empty_rooms_when_full(): void {
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );
		$this->create_booking( $room_id, $this->date_after( 10 ) . ' 15:00:00', $this->date_after( 11 ) . ' 10:00:00' );

		$result = ( new Availability_Service() )->get_lodging_availability(
			array(
				'menu_id'        => $menu_id,
				'check_in_date'  => $this->date_after( 10 ),
				'check_out_date' => $this->date_after( 11 ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['rooms'], '満室' );
	}

	/**
	 * 日付・客室・メニュー・サイトの検証で拒否されることを検証する。
	 */
	public function test_get_lodging_availability_rejects_invalid_requests(): void {
		$this->skip_if_local_time_is_end_of_day();
		$room_a     = $this->create_room( '客室A' );
		$room_b     = $this->create_room( '客室B' );
		$draft_room = $this->create_room( '下書き客室', '', 'draft' );
		// 今日のチェックインのケースが締切に掛からないよう、締切 0 時間・チェックイン 23:59 にする。
		$other_menu   = $this->create_menu( array( $room_a, $draft_room ), $this->today_check_in_meta() );
		$limited_menu = $this->create_menu( array( $room_a ), array( '_vkbm_max_advance_booking_days' => 30 ) );
		$slot_menu    = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $slot_menu, '_vkbm_staff_ids', array( $room_a ) );
		$no_staff_menu = $this->create_menu( array() );
		$draft_menu    = $this->create_menu( array( $room_a ) );
		wp_update_post(
			array(
				'ID'          => $draft_menu,
				'post_status' => 'draft',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '実在しない日付（2098-02-30） => invalid_date',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => '2098-02-30',
					'check_out_date' => '2098-03-02',
				),
				'expected'            => 'invalid_date',
			),
			array(
				'test_condition_name' => '形式違いの日付 => invalid_date',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => '2098/10/10',
					'check_out_date' => '2098-10-12',
				),
				'expected'            => 'invalid_date',
			),
			array(
				'test_condition_name' => '空の日付 => invalid_date',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => '',
					'check_out_date' => '',
				),
				'expected'            => 'invalid_date',
			),
			array(
				'test_condition_name' => 'チェックアウトがチェックインより前（逆順） => invalid_date_range',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( 12 ),
					'check_out_date' => $this->date_after( 10 ),
				),
				'expected'            => 'invalid_date_range',
			),
			array(
				'test_condition_name' => 'チェックアウトがチェックインと同じ日 => invalid_date_range',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 10 ),
				),
				'expected'            => 'invalid_date_range',
			),
			array(
				'test_condition_name' => '泊数の上限を超える（31泊・受付上限日数は無制限） => too_many_nights',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 41 ),
				),
				'expected'            => 'too_many_nights',
			),
			array(
				'test_condition_name' => 'チェックインが昨日 => date_in_past',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( -1 ),
					'check_out_date' => $this->date_after( 1 ),
				),
				'expected'            => 'date_in_past',
			),
			array(
				'test_condition_name' => '受付上限日数（30日）の外のチェックイン => date_beyond_max_advance',
				'args'                => array(
					'menu_id'        => $limited_menu,
					'check_in_date'  => $this->date_after( 40 ),
					'check_out_date' => $this->date_after( 42 ),
				),
				'expected'            => 'date_beyond_max_advance',
			),
			array(
				'test_condition_name' => '時間枠のプラン（泊単位でないメニュー） => menu_not_night_unit',
				'args'                => array(
					'menu_id'        => $slot_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'menu_not_night_unit',
			),
			array(
				'test_condition_name' => '存在しないメニュー => menu_not_found',
				'args'                => array(
					'menu_id'        => 99999999,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'menu_not_found',
			),
			array(
				'test_condition_name' => '非公開のメニュー => menu_not_public',
				'args'                => array(
					'menu_id'        => $draft_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'menu_not_public',
			),
			array(
				'test_condition_name' => 'メニューの担当に含まれない客室の指名 => staff_not_assigned',
				'args'                => array(
					'menu_id'        => $other_menu,
					'resource_id'    => $room_b,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'staff_not_assigned',
			),
			array(
				'test_condition_name' => '担当に含まれていても公開されていない客室の指名 => resource_not_found',
				'args'                => array(
					'menu_id'        => $other_menu,
					'resource_id'    => $draft_room,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'resource_not_found',
			),
			array(
				'test_condition_name' => '担当が空のメニューへ客室を指名 => staff_not_assigned（指名された ID をそのまま受け付けない）',
				'args'                => array(
					'menu_id'        => $no_staff_menu,
					'resource_id'    => $room_a,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'staff_not_assigned',
			),
			array(
				'test_condition_name' => '担当が空のメニュー => staff_not_configured',
				'args'                => array(
					'menu_id'        => $no_staff_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				),
				'expected'            => 'staff_not_configured',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Availability_Service() )->get_lodging_availability( $case['args'] );
			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
		}

		// 受付できる境界: 泊数の上限ちょうど・受付上限日数ちょうど・今日のチェックイン。
		$accepted_cases = array(
			array(
				'test_condition_name' => '泊数が上限ちょうど（30泊） => 受け付ける',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 40 ),
				),
			),
			array(
				'test_condition_name' => 'チェックインが受付上限日数ちょうど（30日後） => 受け付ける',
				'args'                => array(
					'menu_id'        => $limited_menu,
					'check_in_date'  => $this->date_after( 30 ),
					'check_out_date' => $this->date_after( 31 ),
				),
			),
			array(
				'test_condition_name' => 'チェックインが今日 => 受け付ける',
				'args'                => array(
					'menu_id'        => $other_menu,
					'check_in_date'  => $this->date_after( 0 ),
					'check_out_date' => $this->date_after( 1 ),
				),
			),
		);
		foreach ( $accepted_cases as $case ) {
			$result = ( new Availability_Service() )->get_lodging_availability( $case['args'] );
			$this->assertIsArray( $result, $case['test_condition_name'] );
		}
	}

	/**
	 * 日付はサイトのタイムゾーンで解釈し、送られてきたタイムゾーンは使わないことを検証する（タイムゾーンの境界）。
	 */
	public function test_get_lodging_availability_uses_site_timezone(): void {
		$room_id = $this->create_room( '客室A' );
		// 締切 0 時間・チェックイン 23:59 にして、今日のチェックインが締切に掛からないようにする（既定の締切は 3 時間前）。
		$menu_id = $this->create_menu( array( $room_id ), $this->today_check_in_meta() );

		// UTC+14 と UTC-11 では「今日」の日付がずれる。それぞれのサイトの今日を境界にする。
		$test_cases = array(
			array(
				'test_condition_name' => 'UTC+14（Pacific/Kiritimati）',
				'timezone'            => 'Pacific/Kiritimati',
			),
			array(
				'test_condition_name' => 'UTC-11（Pacific/Pago_Pago）',
				'timezone'            => 'Pacific/Pago_Pago',
			),
			array(
				'test_condition_name' => 'UTC+9（Asia/Tokyo）',
				'timezone'            => 'Asia/Tokyo',
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'timezone_string', $case['timezone'] );
			$this->skip_if_local_time_is_end_of_day();
			$today     = $this->date_after( 0 );
			$yesterday = $this->date_after( -1 );
			$tomorrow  = $this->date_after( 1 );

			$accepted = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $today,
					'check_out_date' => $tomorrow,
					'timezone'       => 'America/New_York',
				)
			);
			$this->assertIsArray( $accepted, $case['test_condition_name'] . ': サイトの今日のチェックインは受け付ける' );
			$this->assertSame( $case['timezone'], $accepted['timezone'], $case['test_condition_name'] . ': 送られたタイムゾーンは使わない' );

			$rejected = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $yesterday,
					'check_out_date' => $today,
				)
			);
			$this->assertInstanceOf( WP_Error::class, $rejected, $case['test_condition_name'] . ': サイトの昨日のチェックインは拒否する' );
			$this->assertSame( 'date_in_past', $rejected->get_error_code(), $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊が有効でないサイトでは拒否されることを検証する。
	 */
	public function test_get_lodging_availability_rejects_when_lodging_is_not_enabled(): void {
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu( array( $room_id ) );

		$test_cases = array(
			array(
				'test_condition_name' => '業種プリセットがサロン => 404',
				'preset'              => 'salon',
			),
			array(
				'test_condition_name' => '業種プリセットがカスタム => 404',
				'preset'              => 'custom',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );

			$result = ( new Availability_Service() )->get_lodging_availability(
				array(
					'menu_id'        => $menu_id,
					'check_in_date'  => $this->date_after( 10 ),
					'check_out_date' => $this->date_after( 11 ),
				)
			);
			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( 'rest_no_route', $result->get_error_code(), $case['test_condition_name'] );
			$this->assertSame( 404, $result->get_error_data()['status'], $case['test_condition_name'] );
		}
	}

	/**
	 * 期間の途中の日に予約が入ると、キャッシュが無効になることを検証する。
	 */
	public function test_get_lodging_availability_cache_is_invalidated_by_booking_in_the_middle_of_period(): void {
		$room_a  = $this->create_room( '客室A' );
		$room_b  = $this->create_room( '客室B' );
		$menu_id = $this->create_menu( array( $room_a, $room_b ) );
		$args    = array(
			'menu_id'        => $menu_id,
			'check_in_date'  => $this->date_after( 10 ),
			'check_out_date' => $this->date_after( 14 ),
		);

		// 1回目: 2室とも空室。結果がキャッシュされる。
		$first = ( new Availability_Service() )->get_lodging_availability( $args );
		$this->assertSame( array( $room_a, $room_b ), $this->room_ids_of( $first ), '1回目は2室とも空室' );

		// チェックイン日でもチェックアウト日でもない、期間の途中の日（12日後の夜）に予約が入る。
		$this->create_booking( $room_a, $this->date_after( 12 ) . ' 15:00:00', $this->date_after( 13 ) . ' 10:00:00' );

		// 予約の世代番号を進める前は、保存済みのキャッシュが返る（キャッシュが効いていることの確認）。
		$cached = ( new Availability_Service() )->get_lodging_availability( $args );
		$this->assertSame( array( $room_a, $room_b ), $this->room_ids_of( $cached ), '世代番号が進む前はキャッシュが返る' );

		// 予約の世代番号を進めると、キャッシュ名が変わり、埋まった客室が外れる。
		Availability_Booking_Cache_Generation::flush_bump();
		$after = ( new Availability_Service() )->get_lodging_availability( $args );
		$this->assertSame( array( $room_b ), $this->room_ids_of( $after ), '期間の途中の日の予約でキャッシュが無効になる' );
	}

	/**
	 * 人数が違っても空室の一覧のキャッシュを共有し、料金は毎回計算されることを検証する。
	 */
	public function test_get_lodging_availability_recalculates_price_after_cache(): void {
		$room_id = $this->create_room( '客室A' );
		$menu_id = $this->create_menu(
			array( $room_id ),
			array(
				'_vkbm_allow_multiple_guests' => '1',
				'_vkbm_max_capacity'          => '4',
			)
		);
		$args    = array(
			'menu_id'        => $menu_id,
			'check_in_date'  => $this->date_after( 10 ),
			'check_out_date' => $this->date_after( 11 ),
		);

		$totals = array();
		foreach ( array( 1, 3 ) as $guests ) {
			$result   = ( new Availability_Service() )->get_lodging_availability( array_merge( $args, array( 'guests' => $guests ) ) );
			$totals[] = $result['rooms'][0]['total_price'];
		}
		$this->assertSame( array( 10000, 30000 ), $totals, '2回目はキャッシュを使っても人数に応じた料金になる' );
	}

	/**
	 * キャッシュ名が既存の calendar / daily とは別の区分であることを検証する。
	 */
	public function test_build_lodging_cache_key_uses_its_own_prefix(): void {
		$service = new Availability_Service();
		$method  = new \ReflectionMethod( Availability_Service::class, 'build_lodging_cache_key' );
		$method->setAccessible( true );
		$key = $method->invoke( $service, 5, array( 1, 2 ), '2098-10-10', '2098-10-12', 'Asia/Tokyo' );

		$this->assertStringStartsWith( 'vkbm_lodging_', $key, '泊は別の区分' );
		$this->assertStringNotContainsString( 'vkbm_calendar_', $key );
		$this->assertStringNotContainsString( 'vkbm_daily_', $key );
		$this->assertLessThanOrEqual( 172, strlen( $key ), 'transient 名の長さの上限に収まる' );

		$other_dates = $method->invoke( $service, 5, array( 1, 2 ), '2098-10-10', '2098-10-13', 'Asia/Tokyo' );
		$this->assertNotSame( $key, $other_dates, '期間が違えば別のキャッシュ' );
	}

	/**
	 * 日付エラーの data に、直し方を示す上限値が入ることを検証する。
	 */
	public function test_get_lodging_availability_error_data_has_limits(): void {
		$room_id      = $this->create_room( '客室A' );
		$menu_id      = $this->create_menu( array( $room_id ) );
		$limited_menu = $this->create_menu( array( $room_id ), array( '_vkbm_max_advance_booking_days' => 30 ) );

		$too_many = ( new Availability_Service() )->get_lodging_availability(
			array(
				'menu_id'        => $menu_id,
				'check_in_date'  => $this->date_after( 10 ),
				'check_out_date' => $this->date_after( 41 ),
			)
		);
		$this->assertSame(
			array(
				'status'     => 400,
				'max_nights' => 30,
			),
			$too_many->get_error_data(),
			'too_many_nights の data'
		);
		$this->assertStringContainsString( '30', $too_many->get_error_message() );

		$beyond = ( new Availability_Service() )->get_lodging_availability(
			array(
				'menu_id'        => $limited_menu,
				'check_in_date'  => $this->date_after( 40 ),
				'check_out_date' => $this->date_after( 42 ),
			)
		);
		$this->assertSame(
			array(
				'status'           => 400,
				'max_advance_days' => 30,
			),
			$beyond->get_error_data(),
			'date_beyond_max_advance の data'
		);
		$this->assertStringContainsString( '30', $beyond->get_error_message() );
	}
}
