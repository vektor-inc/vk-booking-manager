<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Common\VKBM_Helper;
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

	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup = $_COOKIE;
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = [];
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
		parent::tearDown();
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
			null,
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
		string $end_at
	): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = [
			'menu_id'      => $menu_id,
			'resource_id'  => $staff_id,
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
