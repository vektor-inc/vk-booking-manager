<?php

/**
 * REST controller for authentication forms.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Auth\Auth_Shortcodes;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function add_action;
use function esc_url_raw;
use function get_option;
use function sanitize_text_field;
use function is_user_logged_in;
use function wp_validate_redirect;

/**
 * Provides login / registration form markup for the frontend block.
 */
class Auth_Form_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Auth shortcodes handler.
	 *
	 * @var Auth_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Constructor.
	 *
	 * @param Auth_Shortcodes $shortcodes Auth shortcodes handler.
	 */
	public function __construct( Auth_Shortcodes $shortcodes ) {
		$this->shortcodes = $shortcodes;
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
			'/auth-form',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return form markup for the requested mode.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_form( WP_REST_Request $request ): WP_REST_Response {
		$type         = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$redirect     = $this->sanitize_url_param( (string) $request->get_param( 'redirect' ) );
		$action_url   = $this->sanitize_url_param( (string) $request->get_param( 'action_url' ) );
		$login_url    = $this->sanitize_url_param( (string) $request->get_param( 'login_url' ) );
		$register_url = $this->sanitize_url_param( (string) $request->get_param( 'register_url' ) );

		if ( '' === $action_url ) {
			$action_url = $redirect;
		}

		$markup = '';

		if ( 'register' === $type ) {
			if ( ! get_option( 'users_can_register' ) ) {
				return new WP_REST_Response(
					array(
						'html'    => '',
						'message' => __( 'We are currently not accepting user registration.', 'vk-booking-manager' ),
					),
					403
				);
			}

			$markup = $this->shortcodes->render_registration_form(
				array_filter(
					array(
						'redirect'   => $redirect,
						'login_url'  => $login_url,
						'auto_login' => 'false',
						'action_url' => $action_url,
					)
				)
			);
		} elseif ( 'profile' === $type ) {
			if ( ! is_user_logged_in() ) {
				return new WP_REST_Response(
					array(
						'html' => '',
					),
					401
				);
			}

			$markup = $this->shortcodes->render_profile_form(
				array_filter(
					array(
						'redirect'   => $redirect,
						'action_url' => $action_url,
					)
				)
			);
		} else {
			$markup = $this->shortcodes->render_login_form(
				array_filter(
					array(
						'redirect'                => $redirect,
						'register_url'            => $register_url,
						'show_lost_password_link' => 'true',
						'lost_password_url'       => wp_lostpassword_url( $redirect ),
						'action_url'              => $action_url,
					)
				)
			);
		}

		$response = new WP_REST_Response(
			array(
				'html' => $markup,
			)
		);
		$response->set_headers(
			array(
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			)
		);

		return $response;
	}

	/**
	 * Sanitize URL parameters passed from the frontend.
	 *
	 * @param string $url Raw URL value.
	 * @return string
	 */
	private function sanitize_url_param( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );
		if ( '' === $sanitized ) {
			return '';
		}

		$validated = wp_validate_redirect( $sanitized, '' );

		return '' !== $validated ? $validated : '';
	}
}
