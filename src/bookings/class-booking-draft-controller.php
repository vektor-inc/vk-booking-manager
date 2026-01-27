<?php

/**
 * REST controller for booking draft persistence.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function get_current_user_id;
use function number_format_i18n;
use function get_post_meta;
use function is_user_logged_in;
use function is_ssl;
use function wp_unslash;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function wp_generate_password;

/**
 * Handles reservation draft persistence via REST API.
 */
class Booking_Draft_Controller {
	private const REST_NAMESPACE   = 'vkbm/v1';
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const TTL_SECONDS      = 1800; // 30 minutes.
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null $settings_repository Provider settings repository.
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();
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
			'/drafts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_draft' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/drafts/(?P<token>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_draft' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_draft' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Persist reservation draft data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_draft( WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$menu_id     = isset( $params['menu_id'] ) ? (int) $params['menu_id'] : 0;
		$resource_id = isset( $params['resource_id'] ) ? max( 0, (int) $params['resource_id'] ) : 0;
		$date        = isset( $params['date'] ) ? sanitize_text_field( (string) $params['date'] ) : '';

		if ( $menu_id <= 0 ) {
			return new WP_Error( 'invalid_menu_id', __( 'Menu ID is invalid.', 'vk-booking-manager' ) );
		}

		$tax_enabled = true;
		$tax_rate    = 0.0;

		$memo                = isset( $params['memo'] ) ? sanitize_textarea_field( (string) $params['memo'] ) : '';
		$agreed              = ! empty( $params['agree_terms'] );
		$agreed_cancellation = array_key_exists( 'agree_cancellation_policy', $params )
			? ! empty( $params['agree_cancellation_policy'] )
			: $agreed;
		$agreed_tos          = array_key_exists( 'agree_terms_of_service', $params )
			? ! empty( $params['agree_terms_of_service'] )
			: $agreed;
		$menu_label          = isset( $params['menu_label'] ) ? sanitize_text_field( (string) $params['menu_label'] ) : '';
		$staff_label         = isset( $params['staff_label'] ) ? sanitize_text_field( (string) $params['staff_label'] ) : '';
		$is_staff_preferred  = ! empty( $params['is_staff_preferred'] );
		$slot                = isset( $params['slot'] ) && is_array( $params['slot'] ) ? $params['slot'] : array();
		$slot_id             = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$start_at            = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$end_at              = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';
		$service_end         = isset( $slot['service_end_at'] ) ? sanitize_text_field( (string) $slot['service_end_at'] ) : '';
		$duration            = isset( $slot['duration_minutes'] ) ? max( 0, (int) $slot['duration_minutes'] ) : 0;
		$slot_staff          = isset( $slot['staff'] ) && is_array( $slot['staff'] ) ? $slot['staff'] : null;
		$slot_staff          = $slot_staff
			? array(
				'id'   => isset( $slot_staff['id'] ) ? (int) $slot_staff['id'] : 0,
				'name' => sanitize_text_field( (string) ( $slot_staff['name'] ?? '' ) ),
			)
			: null;
		$slot_staff_label    = isset( $slot['staff_label'] ) ? sanitize_text_field( (string) $slot['staff_label'] ) : '';
		$assignable_staff    = array();

		if ( isset( $slot['assignable_staff_ids'] ) && is_array( $slot['assignable_staff_ids'] ) ) {
			foreach ( $slot['assignable_staff_ids'] as $candidate ) {
				$candidate = (int) $candidate;
				if ( $candidate > 0 && ! in_array( $candidate, $assignable_staff, true ) ) {
					$assignable_staff[] = $candidate;
				}
			}
		}

		$auto_assign = isset( $slot['auto_assign'] ) ? (bool) $slot['auto_assign'] : false;

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error( 'invalid_slot', __( 'Reservation slot information is incorrect.', 'vk-booking-manager' ) );
		}

		$meta     = isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : array();
		$timezone = isset( $meta['timezone'] ) ? sanitize_text_field( (string) $meta['timezone'] ) : '';

		$token = $this->sanitize_token( isset( $params['token'] ) ? (string) $params['token'] : '' );
		if ( '' === $token ) {
			$token = $this->generate_token();
		}

		if ( '' === $staff_label && $resource_id <= 0 ) {
			$staff_label = __( 'No preference', 'vk-booking-manager' );
		}

		$effective_slot_staff_label = '' !== $slot_staff_label ? $slot_staff_label : $staff_label;

			$nomination_fee         = 0;
			$disable_nomination_fee = (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true );
		if ( '1' !== $disable_nomination_fee && $is_staff_preferred && $resource_id > 0 ) {
			$nomination_fee = $this->get_staff_nomination_fee( $resource_id );
		}

		$payload = array(
			'menu_id'                   => $menu_id,
			'resource_id'               => $resource_id,
			'date'                      => $date,
			'slot'                      => array(
				'slot_id'              => $slot_id,
				'start_at'             => $start_at,
				'end_at'               => $end_at,
				'service_end_at'       => $service_end,
				'duration_minutes'     => $duration,
				'staff'                => $slot_staff,
				'staff_label'          => $effective_slot_staff_label,
				'assignable_staff_ids' => $assignable_staff,
				'auto_assign'          => $auto_assign || ( $resource_id <= 0 ),
			),
			'meta'                      => array(
				'timezone' => $timezone,
			),
			'memo'                      => $memo,
			'agree_terms'               => ( $agreed_cancellation && $agreed_tos ),
			'agree_cancellation_policy' => $agreed_cancellation,
			'agree_terms_of_service'    => $agreed_tos,
			'menu_label'                => $menu_label,
			'staff_label'               => $staff_label,
			'is_staff_preferred'        => $is_staff_preferred,
			'nomination_fee'            => $nomination_fee,
			'owner_user_id'             => (int) get_current_user_id(),
		);

		if ( 0 === $payload['owner_user_id'] ) {
			$payload['owner_key'] = $this->ensure_owner_cookie();
		}

		set_transient( $this->build_transient_key( $token ), $payload, self::TTL_SECONDS );

		return new WP_REST_Response(
			array(
				'token'      => $token,
				'expires_in' => self::TTL_SECONDS,
			)
		);
	}

	/**
	 * Retrieve existing draft data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_draft( WP_REST_Request $request ) {
		$token = $this->sanitize_token( (string) $request['token'] );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', __( 'Token is invalid.', 'vk-booking-manager' ) );
		}

		$payload = get_transient( $this->build_transient_key( $token ) );
		if ( false === $payload ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$payload = $this->backfill_draft_owner( $token, $payload );

		if ( ! $this->can_access_draft( $payload ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		unset( $payload['owner_user_id'], $payload['owner_key'] );

		$price_snapshot = $this->build_menu_price_snapshot( isset( $payload['menu_id'] ) ? (int) $payload['menu_id'] : 0 );
		$tax_enabled    = (bool) ( $price_snapshot['tax_enabled'] ?? false );

		if ( empty( $price_snapshot ) ) {
			$tax_enabled = true;
		}

		if ( ! empty( $price_snapshot ) ) {
			$payload['menu_price']              = $price_snapshot['display_price'];
			$payload['menu_price_base']         = $price_snapshot['base_price'];
			$payload['menu_price_formatted']    = $price_snapshot['formatted'];
			$payload['menu_price_tax_included'] = $price_snapshot['tax_enabled'];
			$payload['menu_price_tax_rate']     = $price_snapshot['tax_rate'];
			$payload['menu_price_currency']     = $price_snapshot['currency'];
		}

		if ( ! Staff_Editor::is_enabled() ) {
			$payload['nomination_fee'] = 0;
		}

		$payload['nomination_fee']           = (int) ( $payload['nomination_fee'] ?? 0 );
		$payload['nomination_fee_formatted'] = $this->format_currency_label(
			$payload['nomination_fee'],
			$tax_enabled
		);

		$base_display_price               = (int) ( $price_snapshot['display_price'] ?? 0 );
		$total_price                      = $base_display_price + $payload['nomination_fee'];
		$payload['total_price']           = $total_price;
		$payload['total_price_formatted'] = $this->format_currency_label( $total_price, $tax_enabled );

		return new WP_REST_Response( $payload );
	}

	/**
	 * Delete an existing draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_draft( WP_REST_Request $request ) {
		$token = $this->sanitize_token( (string) $request['token'] );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', __( 'Token is invalid.', 'vk-booking-manager' ) );
		}

		$payload = get_transient( $this->build_transient_key( $token ) );
		if ( false === $payload ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$payload = $this->backfill_draft_owner( $token, $payload );

		if ( ! $this->can_access_draft( $payload ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		delete_transient( $this->build_transient_key( $token ) );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Build a price snapshot for the selected menu.
	 *
	 * @param int $menu_id Menu ID.
	 * @return array<string, mixed>
	 */
	private function build_menu_price_snapshot( int $menu_id ): array {
		if ( $menu_id <= 0 ) {
			return array();
		}

		$raw_price = get_post_meta( $menu_id, '_vkbm_base_price', true );

		if ( '' === $raw_price || ! is_numeric( $raw_price ) ) {
			return array();
		}

		$base_price = max( 0, (int) $raw_price );

		$tax_enabled = true;
		$tax_rate    = 0.0;

		$display_price = $base_price;

		$formatted = VKBM_Helper::format_currency( (int) $display_price );

		if ( $tax_enabled ) {
			$tax_label = VKBM_Helper::get_tax_included_label();
			if ( '' !== $tax_label ) {
				$formatted .= $tax_label;
			}
		}

		return array(
			'base_price'    => $base_price,
			'display_price' => $display_price,
			'formatted'     => $formatted,
			'tax_enabled'   => $tax_enabled,
			'tax_rate'      => $tax_rate,
			'currency'      => 'JPY',
		);
	}

	/**
	 * Retrieve nomination fee for a staff member.
	 *
	 * @param int $staff_id Staff post ID.
	 * @return int
	 */
	private function get_staff_nomination_fee( int $staff_id ): int {
		if ( $staff_id <= 0 ) {
			return 0;
		}

		if ( ! Staff_Editor::is_enabled() ) {
			return 0;
		}

		$raw = get_post_meta( $staff_id, Staff_Editor::META_NOMINATION_FEE, true );

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		$fee = (int) $raw;

		return $fee > 0 ? $fee : 0;
	}

	/**
	 * Format a currency amount.
	 *
	 * @param int  $amount       Amount in yen.
	 * @param bool $tax_included Whether tax is included.
	 * @return string
	 */
	private function format_currency_label( int $amount, bool $tax_included ): string {
		$label = VKBM_Helper::format_currency( $amount );

		if ( $tax_included ) {
			$tax_label = VKBM_Helper::get_tax_included_label();
			if ( '' !== $tax_label ) {
				$label .= $tax_label;
			}
		}

		return $label;
	}

	/**
	 * Sanitize token string.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function sanitize_token( string $token ): string {
		$token = sanitize_key( $token );
		return ( '' !== $token ) ? $token : '';
	}

	/**
	 * Generate new token.
	 *
	 * @return string
	 */
	private function generate_token(): string {
		return strtolower( wp_generate_password( 16, false, false ) );
	}

	/**
	 * Build transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function build_transient_key( string $token ): string {
		return self::TRANSIENT_PREFIX . $token;
	}

	/**
	 * Ensure draft owner cookie exists for anonymous users.
	 *
	 * 未ログインユーザー向けに予約一時データ所有者Cookieを設定します。
	 *
	 * @return string
	 */
	private function ensure_owner_cookie(): string {
		$owner_key = $this->get_owner_cookie_value();
		if ( '' !== $owner_key ) {
			return $owner_key;
		}

		$owner_key = strtolower( wp_generate_password( 20, false, false ) );
		$this->set_owner_cookie( $owner_key );

		return $owner_key;
	}

	/**
	 * Read the current draft owner cookie value.
	 *
	 * 現在の予約一時データ所有者Cookieの値を取得します。
	 *
	 * @return string
	 */
	private function get_owner_cookie_value(): string {
		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return '';
		}

		$value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );

		return '' !== $value ? $value : '';
	}

	/**
	 * Set the draft owner cookie.
	 *
	 * 予約一時データ所有者Cookieを設定します。
	 *
	 * @param string $value Cookie value.
	 * @return void
	 */
	private function set_owner_cookie( string $value ): void {
		if ( headers_sent() ) {
			return;
		}

		$cookie_path   = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$cookie_domain = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
		setcookie(
			self::OWNER_COOKIE,
			$value,
			time() + self::TTL_SECONDS,
			$cookie_path,
			$cookie_domain,
			is_ssl(),
			true
		);
	}

	/**
	 * Backfill draft ownership data for older drafts.
	 *
	 * 既存予約一時データに所有者情報を補完します。
	 *
	 * @param string $token Temporary reservation data token.
	 * @param mixed  $payload Temporary reservation data payload.
	 * @return array<string, mixed>
	 */
	private function backfill_draft_owner( string $token, $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		$owner_key     = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';

		if ( $owner_user_id > 0 || '' !== $owner_key ) {
			return $payload;
		}

		$payload['owner_user_id'] = (int) get_current_user_id();
		if ( 0 === $payload['owner_user_id'] ) {
			$payload['owner_key'] = $this->ensure_owner_cookie();
		}

		set_transient( $this->build_transient_key( $token ), $payload, self::TTL_SECONDS );

		return $payload;
	}

	/**
	 * Check whether the current requester can access the draft.
	 *
	 * 現在のリクエストが予約一時データにアクセス可能か検証します。
	 *
	 * @param mixed $payload Temporary reservation data payload.
	 * @return bool
	 */
	private function can_access_draft( $payload ): bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $owner_user_id > 0 ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			return (int) get_current_user_id() === $owner_user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' === $owner_key ) {
			return false;
		}

		return $owner_key === $this->get_owner_cookie_value();
	}
}
