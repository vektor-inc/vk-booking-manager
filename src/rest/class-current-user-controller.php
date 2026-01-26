<?php
/**
 * REST controller for current user data.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function admin_url;
use function current_user_can;
use function esc_url_raw;
use function home_url;
use function is_ssl;
use function is_user_logged_in;
use function str_starts_with;
use function wp_logout_url;
use function wp_unslash;
use function wp_validate_redirect;

/**
 * REST controller that exposes current user capability flags.
 */
class Current_User_Controller extends WP_REST_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';
	/**
	 * Provider settings service.
	 *
	 * @var Settings_Service
	 */
	private $settings_service;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service $settings_service Provider settings service.
	 */
	public function __construct( Settings_Service $settings_service ) {
		$this->settings_service = $settings_service;
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
			self::REST_NAMESPACE,
			'/current-user',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_current_user_flags' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return capability flags for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_current_user_flags( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$reservation_redirect = $this->get_reservation_page_redirect();
		$redirect             = '' !== $reservation_redirect ? $reservation_redirect : $this->determine_redirect_target( $request );

		return new WP_REST_Response(
			array(
				'can_manage_reservations' => current_user_can( Capabilities::MANAGE_RESERVATIONS ),
				'shift_dashboard_url'     => admin_url( 'admin.php?page=vkbm-shift-dashboard' ),
				'logout_url'              => wp_logout_url( $redirect ),
			)
		);
	}

	/**
	 * Sanitize and validate redirect parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function determine_redirect_target( WP_REST_Request $request ): string {
		$raw = $request->get_param( 'redirect' );
		if ( empty( $raw ) ) {
			return home_url();
		}

		$url = esc_url_raw( (string) wp_unslash( $raw ) );
		if ( empty( $url ) ) {
			return home_url();
		}

		return wp_validate_redirect( $url, home_url() );
	}

	/**
	 * Returns the reservation page URL from provider settings if available.
	 *
	 * @return string
	 */
	private function get_reservation_page_redirect(): string {
		$settings   = $this->settings_service->get_settings();
		$url        = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		$normalized = $this->normalize_reservation_page_url( $url );

		if ( '' === $normalized ) {
			return '';
		}

		return wp_validate_redirect( $normalized, '' );
	}

	/**
	 * Normalize reservation page URL to an absolute URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_reservation_page_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) ) {
			return esc_url_raw( $url );
		}

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return esc_url_raw( $scheme . $url );
		}

		if ( ! str_starts_with( $url, '/' ) ) {
			$url = '/' . $url;
		}

		return esc_url_raw( home_url( $url ) );
	}
}
