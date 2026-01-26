<?php
/**
 * REST controller for availability data.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

use VKBookingManager\Availability\Availability_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;

/**
 * REST controller for availability endpoints.
 */
class Availability_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Availability service.
	 *
	 * @var Availability_Service
	 */
	private Availability_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Availability_Service $service Availability service.
	 */
	public function __construct( Availability_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/calendar-meta',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_calendar_meta' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_calendar_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_daily_slots' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_daily_args(),
			)
		);
	}

	/**
	 * Handle calendar meta request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_calendar_meta( WP_REST_Request $request ) {
		$data = $this->service->get_calendar_meta(
			array(
				'menu_id'     => (int) $request['menu_id'],
				'resource_id' => isset( $request['resource_id'] ) ? (int) $request['resource_id'] : null,
				'year'        => (int) $request['year'],
				'month'       => (int) $request['month'],
				'timezone'    => (string) $request['timezone'],
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Handle daily slot request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_daily_slots( WP_REST_Request $request ) {
		$data = $this->service->get_daily_slots(
			array(
				'menu_id'     => (int) $request['menu_id'],
				'resource_id' => isset( $request['resource_id'] ) ? (int) $request['resource_id'] : null,
				'date'        => (string) $request['date'],
				'timezone'    => (string) $request['timezone'],
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Arguments for calendar endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_calendar_args(): array {
		return array(
			'menu_id'     => array(
				'required'          => true,
				'type'              => 'integer',
				'description'       => __( 'Service menu post ID', 'vk-booking-manager' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'resource_id' => array(
				'required'          => false,
				'type'              => 'integer',
				'description'       => __( 'Nominated resource ID (optional)', 'vk-booking-manager' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'year'        => array(
				'required'    => true,
				'type'        => 'integer',
				'description' => __( 'Target year (YYYY)', 'vk-booking-manager' ),
				'minimum'     => 2000,
				'maximum'     => 2100,
			),
			'month'       => array(
				'required'    => true,
				'type'        => 'integer',
				'description' => __( 'Target month (1-12)', 'vk-booking-manager' ),
				'minimum'     => 1,
				'maximum'     => 12,
			),
			'timezone'    => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Time zone name (Site setting if not specified)', 'vk-booking-manager' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Arguments for daily endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_daily_args(): array {
		return array(
			'menu_id'     => $this->get_calendar_args()['menu_id'],
			'resource_id' => $this->get_calendar_args()['resource_id'],
			'date'        => array(
				'required'    => true,
				'type'        => 'string',
				'description' => __( 'Target date (YYYY-MM-DD)', 'vk-booking-manager' ),
				'pattern'     => '^\d{4}-\d{2}-\d{2}$',
			),
			'timezone'    => $this->get_calendar_args()['timezone'],
		);
	}
}
