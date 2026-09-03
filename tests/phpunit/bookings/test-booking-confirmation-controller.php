<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Common\VKBM_Helper;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function delete_transient;
use function get_post;
use function get_user_by;
use function get_post_meta;
use function set_transient;
use function update_post_meta;
use function update_user_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * @group bookings
 */
class Booking_Confirmation_Controller_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var array<int, string> */
	private array $tokens = [];

	/** @var array<string, mixed> */
	private array $cookie_backup = [];

	/**
	 * テスト開始時の基本設定オプション値（復元用）。未設定時は false。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup = $_COOKIE;
		// disable_nomination() が変更する基本設定オプションを保存しておく。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = [];
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
		// テスト中に変更した基本設定オプションを元の状態へ復元する。
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		// 指名機能の静的キャッシュをクリアして他テストへの影響を防ぐ。
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 指名機能を無効化する（複数人予約は指名OFF時のみ有効なため）。
	 */
	private function disable_nomination(): void {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$settings['staff_enabled'] = false;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	public function test_admin_booking_conflict_when_phone_matches_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$matched_user_id = $this->factory()->user->create();
		$phone           = '090-1234-5678';
		update_user_meta( $matched_user_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );

		$this->create_booking_post(
			$matched_user_id,
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_phone', $phone );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'booking_time_conflict', $response->get_error_code() );
	}

	public function test_admin_booking_author_matches_user_when_phone_matches(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$matched_user_id = $this->factory()->user->create(
			[ 'user_email' => 'matched@example.com' ]
		);
		$phone = '090-1111-2222';
		update_user_meta( $matched_user_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-02T11:00:00+09:00',
			'2024-02-02T11:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-02T11:00:00+09:00',
			'2024-02-02T11:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_phone', $phone );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data       = $response->get_data();
		$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
		$this->assertGreaterThan( 0, $booking_id );

		$booking = get_post( $booking_id );
		$this->assertSame( $matched_user_id, (int) $booking->post_author );
		$this->assertSame( 'matched@example.com', (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ) );
	}

	/**
	 * 管理者予約時に様々なフォーマットの電話番号を入力しても、
	 * 正規化済み番号で保存されたユーザーに正しく紐づくことを検証する。
	 */
	public function test_admin_booking_phone_format_variations_match_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		// ユーザーの電話番号は正規化済み（数字のみ）でDBに保存されている.
		$matched_user_id = $this->factory()->user->create(
			[ 'user_email' => 'phone-match@example.com' ]
		);
		update_user_meta( $matched_user_id, 'phone_number', '09012345678' );

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$test_cases = [
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が数字のみの場合 => ユーザーに紐づく',
				'input_phone'         => '09012345678',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が全角数字の場合 => ユーザーに紐づく',
				'input_phone'         => '０９０１２３４５６７８',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力がハイフン付き半角の場合 => ユーザーに紐づく',
				'input_phone'         => '090-1234-5678',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力がハイフン付き全角の場合 => ユーザーに紐づく',
				'input_phone'         => '０９０−１２３４−５６７８',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が括弧付き全角の場合 => ユーザーに紐づく',
				'input_phone'         => '（０９０）１２３４−５６７８',
				'expected_author'     => $matched_user_id,
			],
		];

		foreach ( $test_cases as $index => $case ) {
			// テストケース毎に異なる時間帯を使用して予約の重複を避ける.
			$hour     = 10 + $index;
			$start_at = sprintf( '2024-03-01T%02d:00:00+09:00', $hour );
			$end_at   = sprintf( '2024-03-01T%02d:30:00+09:00', $hour );

			$token = $this->store_temporary_reservation_data(
				$menu_id,
				$staff_id,
				$start_at,
				$end_at
			);

			$controller = $this->build_controller(
				$staff_id,
				$start_at,
				$end_at
			);

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'customer_phone', $case['input_phone'] );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf(
				WP_REST_Response::class,
				$response,
				$case['test_condition_name'] . ' - レスポンスが WP_REST_Response であること'
			);

			$data       = $response->get_data();
			$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] . ' - booking_id が 0 より大きいこと' );

			$booking = get_post( $booking_id );
			$this->assertSame(
				$case['expected_author'],
				(int) $booking->post_author,
				$case['test_condition_name'] . ' - 予約の投稿者がユーザーに紐づいていること'
			);
			$this->assertSame(
				'phone-match@example.com',
				(string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ),
				$case['test_condition_name'] . ' - 顧客メールが紐づいたユーザーのメールであること'
			);
		}
	}

	public function test_admin_booking_author_is_admin_when_phone_missing_or_unmatched(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-03T12:00:00+09:00',
			'2024-02-03T12:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-03T12:00:00+09:00',
			'2024-02-03T12:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_phone', '090-9999-9999' );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data       = $response->get_data();
		$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
		$this->assertGreaterThan( 0, $booking_id );

		$booking = get_post( $booking_id );
		$this->assertSame( $admin_id, (int) $booking->post_author );
		$this->assertSame( '', (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ) );
	}

	public function test_confirmation_allows_same_browser_temporary_data_without_owner_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$_COOKIE[ self::OWNER_COOKIE ] = 'owner123';
		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-04T10:00:00+09:00',
			'2024-02-04T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-04T10:00:00+09:00',
			'2024-02-04T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	public function test_confirmation_rejects_temporary_data_without_owner_cookie(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-05T10:00:00+09:00',
			'2024-02-05T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-05T10:00:00+09:00',
			'2024-02-05T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'forbidden_draft', $response->get_error_code() );
	}

	/**
	 * create_booking: 自動割当で人数が複数スタッフへ分割保存されることを検証する（要望Dの中核）。
	 */
	public function test_create_booking_auto_assignment_single_staff(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		$this->disable_nomination();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-08-01T10:00:00+09:00';
		$end   = '2026-08-01T11:00:00+09:00';

		// 自動割当（resource_id=0）・6人の下書きを作成する。
		// 1予約は分割せず単一スタッフに割り当てるため、人数は max_capacity(5) にクランプされる。
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => 0,
			'guests'               => 6,
			'slot'                 => array(
				'slot_id'  => 'auto-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'assignable_staff_ids' => array( $staff_a, $staff_b ),
			'meta'                 => array( 'timezone' => 'Asia/Tokyo' ),
		);
		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		// 2スタッフの自動割当スロット（capacity = max_capacity = 5、remaining = 最も空きの大きい単一スタッフの残り = 5）。
		$availability = new Availability_Service_Test_Double(
			array(
				'slot_id'              => 'auto-1',
				'start_at'             => $start,
				'end_at'               => $end,
				'service_end_at'       => $end,
				'staff'                => null,
				'assignable_staff_ids' => array( $staff_a, $staff_b ),
				'auto_assign'          => true,
				'capacity'             => 5,
				'remaining'            => 5,
			)
		);
		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			$availability
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
		$this->assertGreaterThan( 0, $booking_id );

		// 6人は max_capacity(5) にクランプされ、単一スタッフ A（空きが同じため候補順で先頭）へ割り当てられる。
		$this->assertSame( 5, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), '人数は max_capacity(5) にクランプされるべき' );
		$this->assertSame( $staff_a, (int) get_post_meta( $booking_id, '_vkbm_booking_resource_id', true ), '単一スタッフ A に割り当てられるべき' );
		$this->assertSame(
			'',
			(string) get_post_meta( $booking_id, '_vkbm_booking_staff_distribution', true ),
			'分配メタは保存されないべき（分割しない）'
		);
	}

	/**
	 * get_staff_loads_for_slot: スロット内の既存予約からスタッフ別の担当人数を集計する処理を検証する。
	 *
	 * 1予約は単一スタッフに割り当てられるため、担当スタッフ（resource_id）へ全人数を計上する
	 * （人数メタが無い予約は1名扱い）。旧データの分配メタ（_vkbm_booking_staff_distribution）は
	 * 参照せず無視する。キャンセル・無断キャンセル予約は集計から除外する。
	 *
	 * Verify per-staff load aggregation: each booking counts its full guest count toward its single
	 * staff (resource_id); missing guest meta counts as 1. The legacy distribution meta is ignored.
	 * Cancelled / no-show bookings are excluded.
	 */
	public function test_get_staff_loads_for_slot(): void {
		$staff_a    = $this->create_staff();
		$staff_b    = $this->create_staff();
		$slot_start = '2024-05-01 10:00:00';
		$slot_end   = '2024-05-01 11:00:00';

		$controller = $this->build_controller( $staff_a, $slot_start, $slot_end );
		$method     = new ReflectionMethod( Booking_Confirmation_Controller::class, 'get_staff_loads_for_slot' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'レガシー予約（配分メタなし・代表 A・人数メタ3） => A に3名計上（正常系・後方互換）',
				'bookings'            => array(
					array(
						'status'      => 'confirmed',
						'resource_id' => $staff_a,
						'guests'      => 3,
					),
				),
				'expected'            => array( $staff_a => 3 ),
			),
			array(
				'test_condition_name' => 'レガシー予約（配分メタ・人数メタともになし・代表 A） => A に1名計上（境界値・後方互換）',
				'bookings'            => array(
					array(
						'status'      => 'confirmed',
						'resource_id' => $staff_a,
					),
				),
				'expected'            => array( $staff_a => 1 ),
			),
			array(
				'test_condition_name' => '旧分配メタ（A:5 / B:1）付き予約 => 分配メタは無視し代表 A に全人数6を計上（正常系・後方互換）',
				'bookings'            => array(
					array(
						'status'       => 'confirmed',
						'resource_id'  => $staff_a,
						'guests'       => 6,
						'distribution' => array(
							array(
								'staff_id' => $staff_a,
								'guests'   => 5,
							),
							array(
								'staff_id' => $staff_b,
								'guests'   => 1,
							),
						),
					),
				),
				'expected'            => array(
					$staff_a => 6,
				),
			),
			array(
				'test_condition_name' => 'キャンセル済みレガシー予約 => 残数集計から除外され空（異常系）',
				'bookings'            => array(
					array(
						'status'      => 'cancelled',
						'resource_id' => $staff_a,
						'guests'      => 3,
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '無断キャンセル（no_show）レガシー予約 => 残数集計から除外され空（異常系）',
				'bookings'            => array(
					array(
						'status'      => 'no_show',
						'resource_id' => $staff_a,
						'guests'      => 2,
					),
				),
				'expected'            => array(),
			),
		);

		$start_storage = wp_date( 'Y-m-d H:i:s', strtotime( $slot_start ) );
		$end_storage   = wp_date( 'Y-m-d H:i:s', strtotime( $slot_end ) );

		foreach ( $test_cases as $case ) {
			// ケース毎にメニューを分け、対象スロットの予約だけを集計対象にする。
			$menu_id = $this->create_menu();

			foreach ( $case['bookings'] as $booking ) {
				$booking_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Booking_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);

				update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
				update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_storage );
				update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_storage );
				update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_storage );
				update_post_meta( $booking_id, '_vkbm_booking_status', $booking['status'] );
				update_post_meta( $booking_id, '_vkbm_booking_resource_id', $booking['resource_id'] );

				if ( isset( $booking['guests'] ) ) {
					update_post_meta( $booking_id, '_vkbm_booking_guests', $booking['guests'] );
				}
				if ( isset( $booking['distribution'] ) ) {
					update_post_meta( $booking_id, '_vkbm_booking_staff_distribution', $booking['distribution'] );
				}
			}

			$this->assertSame(
				$case['expected'],
				$method->invoke( $controller, $menu_id, $slot_start, $slot_end ),
				$case['test_condition_name']
			);
		}
	}

	private function build_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		$notification_service = new Booking_Notification_Service_Test_Double();
		$settings_repository  = new Settings_Repository();
		$availability_service = new Availability_Service_Test_Double(
			[
				'slot_id'              => 'slot-1',
				'start_at'             => $start_at,
				'end_at'               => $end_at,
				'service_end_at'       => $end_at,
				'staff'                => [
					'id' => $staff_id,
				],
				'assignable_staff_ids' => [ $staff_id ],
			]
		);

		return new Booking_Confirmation_Controller(
			$notification_service,
			$settings_repository,
			$availability_service
		);
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	private function create_admin_user(): int {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
		}
		return (int) $user_id;
	}

	private function create_booking_post( int $author_id, int $staff_id, string $start, string $end ): int {
		$booking_id = (int) $this->factory()->post->create(
			[
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			]
		);

		$start_storage = wp_date( 'Y-m-d H:i:s', strtotime( $start ) );
		$end_storage   = wp_date( 'Y-m-d H:i:s', strtotime( $end ) );

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_storage );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}

	private function store_temporary_reservation_data(
		int $menu_id,
		int $staff_id,
		string $start_at,
		string $end_at,
		int $guests = 1
	): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = [
			'menu_id'      => $menu_id,
			'resource_id'  => $staff_id,
			'guests'       => $guests,
			'slot'         => [
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			],
			'assignable_staff_ids' => [ $staff_id ],
			'meta'         => [
				'timezone' => 'Asia/Tokyo',
			],
		];

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * create_booking: 単一スタッフ自動割当での複数人予約（人数の解決・料金の人数倍・クランプ・満枠エラー）を検証する。
	 *
	 * 指名OFF・スタッフ1名のメニューに対し、要求人数に応じて人数・基本料金合計が保存されること、
	 * 最大受付数を超える人数はクランプされること、複数人予約無効時は1名へフォールバックすること、
	 * 残数を超える人数は capacity_exceeded エラーになることを確認する。
	 */
	public function test_create_booking_resolves_guests(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name'         => '複数人許可・最大4・要求3 => 人数3 / 単価1000 / 基本料金 1000×3=3000（正常系）',
				'date'                        => '2024-05-01',
				'allow_multiple'              => true,
				'max_capacity'                => 4,
				'base_price'                  => 1000,
				'requested_guests'            => 3,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 3,
				'expected_service_base_price' => 1000,
				'expected_base_total'         => 3000,
			),
			array(
				'test_condition_name'         => '複数人許可・最大2・要求5 => 最大2にクランプ / 基本料金 1000×2=2000（境界値）',
				'date'                        => '2024-05-02',
				'allow_multiple'              => true,
				'max_capacity'                => 2,
				'base_price'                  => 1000,
				'requested_guests'            => 5,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 2,
				'expected_service_base_price' => null,
				'expected_base_total'         => 2000,
			),
			array(
				'test_condition_name'         => '複数人無効・要求4 => 1名へフォールバック（後方互換・境界値）',
				'date'                        => '2024-05-03',
				'allow_multiple'              => false,
				'max_capacity'                => null,
				'base_price'                  => 1000,
				'requested_guests'            => 4,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 1,
				'expected_service_base_price' => null,
				'expected_base_total'         => null,
			),
			array(
				'test_condition_name'         => '複数人許可・最大3・既存2名 + 今回2名=4>3 => capacity_exceeded（異常系）',
				'date'                        => '2024-05-04',
				'allow_multiple'              => true,
				'max_capacity'                => 3,
				'base_price'                  => 1000,
				'requested_guests'            => 2,
				'existing_guests'             => 2,
				'expect_error'                => 'capacity_exceeded',
				'expected_guests'             => null,
				'expected_service_base_price' => null,
				'expected_base_total'         => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();

			if ( null !== $case['base_price'] ) {
				update_post_meta( $menu_id, '_vkbm_base_price', $case['base_price'] );
			}
			if ( $case['allow_multiple'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
				if ( null !== $case['max_capacity'] ) {
					update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
				}
				// 複数人予約はスタッフ割当が前提のため、メニューにスタッフを割り当てる。
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			}

			$start = $case['date'] . 'T10:00:00+09:00';
			$end   = $case['date'] . 'T10:30:00+09:00';

			// 既存予約で枠を消費する（満枠検証用）。
			if ( $case['existing_guests'] > 0 ) {
				$other_user = $this->factory()->user->create();
				$existing   = $this->create_booking_post( $other_user, $staff_id, $start, $end );
				update_post_meta( $existing, '_vkbm_booking_service_id', $menu_id );
				update_post_meta( $existing, '_vkbm_booking_guests', $case['existing_guests'] );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, $case['requested_guests'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
				wp_set_current_user( 0 );
				continue;
			}

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ),
				$case['test_condition_name']
			);
			if ( null !== $case['expected_service_base_price'] ) {
				$this->assertSame(
					$case['expected_service_base_price'],
					(int) get_post_meta( $booking_id, '_vkbm_booking_service_base_price', true ),
					$case['test_condition_name']
				);
			}
			if ( null !== $case['expected_base_total'] ) {
				$this->assertSame(
					$case['expected_base_total'],
					(int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ),
					$case['test_condition_name']
				);
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 料金区分（大人料金・子供料金）の合計計算・人数合計・スナップショット保存と、
	 * クライアント改竄に対するサーバ側再計算（セキュリティ回帰）を検証する。
	 *
	 * - 区分定義メニューでは Σ(区分料金 × 区分人数) で基本料金合計を保存する。
	 * - 区分人数の合計が予約人数（_vkbm_booking_guests）として保存される。
	 * - 区分内訳（_vkbm_booking_guest_tiers）がラベル・料金・人数で保存される。
	 * - 特定区分が0名でも合計1名以上なら申込できる。
	 * - 下書きに改竄した料金・ラベルが入っていても、サーバ保存メタの料金で再計算される。
	 */
	public function test_create_booking_resolves_guest_tiers(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$menu_tiers = array(
			array(
				'label' => '一般',
				'price' => 4000,
			),
			array(
				'label' => '子供',
				'price' => 3000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '一般3名・子供2名 => 合計5名 / 基本料金合計 4000×3+3000×2=18000（正常系）',
				'date'                => '2024-07-01',
				'stored_tiers'        => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
				'expected_guests'     => 5,
				'expected_base_total' => 18000,
				'expected_counts'     => array( 3, 2 ),
			),
			array(
				'test_condition_name' => '一般3名・子供0名 => 合計3名 / 基本料金合計 12000（正常系・特定区分0名）',
				'date'                => '2024-07-02',
				'stored_tiers'        => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 0,
					),
				),
				'expected_guests'     => 3,
				'expected_base_total' => 12000,
				'expected_counts'     => array( 3, 0 ),
			),
			array(
				'test_condition_name' => 'クライアントが料金0・ラベル改竄を送っても保存メタ料金で再計算（セキュリティ回帰）',
				'date'                => '2024-07-03',
				'stored_tiers'        => array(
					array(
						'label' => 'ハッカー',
						'price' => 0,
						'count' => 2,
					),
					array(
						'label' => 'ハッカー',
						'price' => 0,
						'count' => 1,
					),
				),
				'expected_guests'     => 3,
				// 改竄料金0は無視され、サーバ保存メタ 4000/3000 で 4000×2+3000×1=11000 となる。
				'expected_base_total' => 11000,
				'expected_counts'     => array( 2, 1 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();

			// 区分料金で計算するため基本料金は別の値にしておき、区分料金が優先されることを確認する。
			update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_price_tiers', $menu_tiers );

			$start = $case['date'] . 'T10:00:00+09:00';
			$end   = $case['date'] . 'T10:30:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data_with_tiers( $menu_id, $staff_id, $start, $end, $case['stored_tiers'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ),
				$case['test_condition_name']
			);
			$this->assertSame(
				$case['expected_base_total'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ),
				$case['test_condition_name']
			);

			// 区分内訳スナップショットが保存され、ラベル・料金がサーバ保存メタと一致することを確認する。
			$saved_tiers = get_post_meta( $booking_id, '_vkbm_booking_guest_tiers', true );
			$this->assertIsArray( $saved_tiers, $case['test_condition_name'] );
			$this->assertSame( '一般', $saved_tiers[0]['label'] ?? '', $case['test_condition_name'] );
			$this->assertSame( 4000, (int) ( $saved_tiers[0]['price'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( '子供', $saved_tiers[1]['label'] ?? '', $case['test_condition_name'] );
			$this->assertSame( 3000, (int) ( $saved_tiers[1]['price'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_counts'][0], (int) ( $saved_tiers[0]['count'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_counts'][1], (int) ( $saved_tiers[1]['count'] ?? -1 ), $case['test_condition_name'] );

			wp_set_current_user( 0 );
		}
	}

	/**
	 * 料金区分の人数内訳を含む下書きを transient に保存する（テスト用ヘルパー）。
	 *
	 * @param int                                  $menu_id  サービスメニューID。
	 * @param int                                  $staff_id スタッフID。
	 * @param string                               $start_at スロット開始（ISO8601）。
	 * @param string                               $end_at   スロット終了（ISO8601）。
	 * @param array<int, array<string, mixed>>     $tiers    区分人数内訳。
	 * @return string 下書きトークン。
	 */
	private function store_temporary_reservation_data_with_tiers(
		int $menu_id,
		int $staff_id,
		string $start_at,
		string $end_at,
		array $tiers
	): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );

		// 合計人数を guests として保存する（下書き保存時の挙動を模す）。
		$guests = 0;
		foreach ( $tiers as $tier ) {
			$guests += max( 0, (int) ( $tier['count'] ?? 0 ) );
		}

		$payload = [
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => max( 1, $guests ),
			'guest_tiers'          => $tiers,
			'slot'                 => [
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			],
			'assignable_staff_ids' => [ $staff_id ],
			'meta'                 => [
				'timezone' => 'Asia/Tokyo',
			],
		];

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * create_booking: 料金区分メニューで全区分0名（合計0）の予約確定は guests_required(400) で拒否する。
	 *
	 * max(1, total) で1名へ丸めると0名予約が成立してしまうため、合計1名未満を明示的にエラーにする。
	 */
	public function test_create_booking_rejects_zero_guest_tiers(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
				array(
					'label' => '子供',
					'price' => 3000,
				),
			)
		);

		$start = '2024-08-01T10:00:00+09:00';
		$end   = '2024-08-01T10:30:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		// 全区分0名の内訳を保存する。
		$token = $this->store_temporary_reservation_data_with_tiers(
			$menu_id,
			$staff_id,
			$start,
			$end,
			array(
				array(
					'label' => '一般',
					'price' => 4000,
					'count' => 0,
				),
				array(
					'label' => '子供',
					'price' => 3000,
					'count' => 0,
				),
			)
		);

		$controller = $this->build_controller( $staff_id, $start, $end );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );

		$this->assertInstanceOf( WP_Error::class, $response, '全区分0名は予約確定できない' );
		$this->assertSame( 'guests_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? 0 );

		wp_set_current_user( 0 );
	}

	/**
	 * create_booking: wp_insert_post() が 0 を返した場合に booking_creation_failed エラーになることを検証する。
	 *
	 * wp_insert_post() は失敗時に WP_Error だけでなく 0 を返す場合がある。
	 * その際に無効な投稿IDでメタ保存に進んだり、成功レスポンスを返したりせず、
	 * 500 の booking_creation_failed エラーを返すことを確認する。
	 */
	public function test_create_booking_returns_error_when_insert_returns_zero(): void {
		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$start = '2024-06-01T10:00:00+09:00';
		$end   = '2024-06-01T10:30:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
		$controller = $this->build_controller( $staff_id, $start, $end );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		// wp_insert_post() を強制的に 0（失敗）で返させる。
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		try {
			$response = $controller->create_booking( $request );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'booking_creation_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] ?? 0 );

		wp_set_current_user( 0 );
	}
}

class Booking_Notification_Service_Test_Double extends Booking_Notification_Service {
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	public function handle_confirmed_creation( int $booking_id ): void {
		// Do nothing in tests.
	}

	public function handle_pending_creation( int $booking_id ): void {
		// Do nothing in tests.
	}
}

class Availability_Service_Test_Double extends Availability_Service {
	/** @var array<string, mixed> */
	private array $slot;

	/**
	 * @param array<string, mixed> $slot
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	public function get_daily_slots( array $args ) {
		return [
			'slots' => [ $this->slot ],
		];
	}
}
