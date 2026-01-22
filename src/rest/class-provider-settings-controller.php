<?php

declare( strict_types=1 );

namespace VKBookingManager\REST;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Response;
use WP_REST_Server;
use function add_action;
use function esc_url_raw;
use function home_url;
use function is_ssl;
use function str_starts_with;
use function wp_get_attachment_image_url;
use function wp_login_url;
use function wp_registration_url;

/**
 * Exposes provider settings required on the frontend.
 */
class Provider_Settings_Controller {
	private const NAMESPACE = 'vkbm/v1';

	private Settings_Repository $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings_repository Settings repository.
	 */
	public function __construct( Settings_Repository $settings_repository ) {
		$this->settings_repository = $settings_repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/provider-settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Return settings used on the frontend.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings    = $this->settings_repository->get_settings();
		$resource_label_singular = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : 'Staff';
		$resource_label_plural   = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : 'Staff';
		$provider_name = isset( $settings['provider_name'] ) ? (string) $settings['provider_name'] : '';
		$provider_logo_id = isset( $settings['provider_logo_id'] ) ? (int) $settings['provider_logo_id'] : 0;
		$reservation = $this->normalize_reservation_page_url(
			isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : ''
		);
		$show_menu_list = ! empty( $settings['reservation_show_menu_list'] );
		$menu_list_display_mode = isset( $settings['reservation_menu_list_display_mode'] ) ? sanitize_key( (string) $settings['reservation_menu_list_display_mode'] ) : 'card';
		if ( ! in_array( $menu_list_display_mode, [ 'card', 'text' ], true ) ) {
			$menu_list_display_mode = 'card';
		}
		$show_provider_logo = ! empty( $settings['reservation_show_provider_logo'] );
		$show_provider_name = ! empty( $settings['reservation_show_provider_name'] );
		$currency_symbol   = isset( $settings['currency_symbol'] ) ? (string) $settings['currency_symbol'] : '';
		$tax_label_text    = isset( $settings['tax_label_text'] ) ? (string) $settings['tax_label_text'] : '';
		// Fetch provider logo URL for frontend display. / 予約画面表示用にロゴURLを取得します。
		$provider_logo_url = $provider_logo_id > 0 ? wp_get_attachment_image_url( $provider_logo_id, 'medium' ) : '';
		if ( ! is_string( $provider_logo_url ) ) {
			$provider_logo_url = '';
		}
		$provider_logo_url = '' !== $provider_logo_url ? esc_url_raw( $provider_logo_url ) : '';
		$cancellation_policy = isset( $settings['provider_cancellation_policy'] ) ? (string) $settings['provider_cancellation_policy'] : '';
		$terms_of_service    = isset( $settings['provider_terms_of_service'] ) ? (string) $settings['provider_terms_of_service'] : '';
		$payment_method      = isset( $settings['provider_payment_method'] ) ? (string) $settings['provider_payment_method'] : '';

		return new WP_REST_Response(
			[
				'tax_enabled' => true,
				'tax_rate'    => 0.0,
				'staff_enabled' => Staff_Editor::is_enabled(),
				'resource_label_singular' => $resource_label_singular,
				'resource_label_plural'   => $resource_label_plural,
				'provider_name'           => $provider_name,
				'provider_logo_url'       => $provider_logo_url,
				'reservation_page_url' => $reservation,
				'reservation_show_menu_list' => $show_menu_list,
				'reservation_menu_list_display_mode' => $menu_list_display_mode,
				'reservation_show_provider_logo' => $show_provider_logo,
				'reservation_show_provider_name' => $show_provider_name,
				'currency_symbol'    => $currency_symbol,
				'tax_label_text'    => $tax_label_text,
				'cancellation_policy'  => $cancellation_policy,
				'terms_of_service'     => $terms_of_service,
				'payment_method'       => $payment_method,
			]
		);
	}

	/**
	 * Normalize reservation page URL to an absolute path.
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
