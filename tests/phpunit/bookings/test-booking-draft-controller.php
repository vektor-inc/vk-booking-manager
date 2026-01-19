<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function delete_transient;
use function get_transient;
use function wp_json_encode;
use function wp_set_current_user;

/**
 * @group bookings
 */
class Booking_Draft_Controller_Test extends WP_UnitTestCase {
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

	public function test_logged_in_owner_can_access_and_others_cannot(): void {
		$menu_id  = $this->create_menu();
		$owner_id = $this->factory()->user->create();
		wp_set_current_user( $owner_id );

		$controller = new Booking_Draft_Controller();
		$token      = $this->save_draft(
			$controller,
			$this->build_payload( $menu_id, '2024-02-01T10:00:00+09:00' )
		);

		$request  = $this->build_get_request( $token );
		$response = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$other_id = $this->factory()->user->create();
		wp_set_current_user( $other_id );
		$forbidden = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden );
		$this->assertSame( 'forbidden_draft', $forbidden->get_error_code() );
	}

	public function test_anonymous_owner_cookie_required(): void {
		$menu_id = $this->create_menu();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ self::OWNER_COOKIE ] );

		$controller = new Booking_Draft_Controller();
		$token      = $this->save_draft(
			$controller,
			$this->build_payload( $menu_id, '2024-02-02T10:00:00+09:00' )
		);

		$request  = $this->build_get_request( $token );
		$forbidden = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden );
		$this->assertSame( 'forbidden_draft', $forbidden->get_error_code() );

		$payload = get_transient( self::TRANSIENT_PREFIX . $token );
		$owner_key = is_array( $payload ) ? (string) ( $payload['owner_key'] ?? '' ) : '';
		$this->assertNotSame( '', $owner_key );

		$_COOKIE[ self::OWNER_COOKIE ] = $owner_key;
		$response = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$_COOKIE[ self::OWNER_COOKIE ] = 'invalid';
		$forbidden_again = $controller->get_draft( $request );
		$this->assertInstanceOf( WP_Error::class, $forbidden_again );
		$this->assertSame( 'forbidden_draft', $forbidden_again->get_error_code() );
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * @param int    $menu_id Menu ID.
	 * @param string $start_at Slot start datetime.
	 * @return array<string, mixed>
	 */
	private function build_payload( int $menu_id, string $start_at ): array {
		$date = substr( $start_at, 0, 10 );

		return [
			'menu_id' => $menu_id,
			'date'    => $date,
			'slot'    => [
				'slot_id'   => 'slot-1',
				'start_at'  => $start_at,
				'end_at'    => $date . 'T10:30:00+09:00',
			],
			'meta'    => [
				'timezone' => 'Asia/Tokyo',
			],
		];
	}

	private function save_draft( Booking_Draft_Controller $controller, array $payload ): string {
		$request  = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = $controller->save_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data  = $response->get_data();
		$token = isset( $data['token'] ) ? (string) $data['token'] : '';
		$this->assertNotSame( '', $token );
		$this->tokens[] = $token;

		return $token;
	}

	private function build_get_request( string $token ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
		$request->set_url_params(
			[
				'token' => $token,
			]
		);

		return $request;
	}
}
