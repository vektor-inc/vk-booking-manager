<?php

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Query;
use WP_User;
use function __;
use function delete_post_meta;
use function delete_transient;
use function get_post_meta;
use function get_transient;
use function get_users;
use function is_user_logged_in;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function current_user_can;
use function get_current_user_id;
use function vkbm_get_resource_label_singular;
use function wp_get_current_user;
use function wp_insert_post;
use function update_post_meta;
use function wp_date;
use function strtotime;
use function wp_unslash;

/**
 * REST controller that finalizes reservation drafts into confirmed bookings.
 */
class Booking_Confirmation_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';
	private const DRAFT_PREFIX   = 'vkbm_draft_';

	private const META_DATE_START                = '_vkbm_booking_service_start';
	private const META_DATE_END                  = '_vkbm_booking_service_end';
	private const META_RESOURCE_ID               = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID                = '_vkbm_booking_service_id';
	private const META_CUSTOMER                  = '_vkbm_booking_customer_name';
	private const META_CUSTOMER_TEL              = '_vkbm_booking_customer_tel';
	private const META_CUSTOMER_MAIL             = '_vkbm_booking_customer_email';
	private const META_STATUS                    = '_vkbm_booking_status';
	private const META_NOTE                      = '_vkbm_booking_note';
	private const META_INTERNAL_NOTE             = '_vkbm_booking_internal_note';
	private const META_IS_PREFERRED              = '_vkbm_booking_is_staff_preferred';
	private const META_NOMINATION_FEE            = '_vkbm_booking_nomination_fee';
	private const META_DATE_TOTAL_END            = '_vkbm_booking_total_end';
	private const META_SERVICE_BASE_PRICE        = '_vkbm_booking_service_base_price';
	private const META_BASE_TOTAL_PRICE          = '_vkbm_booking_base_total_price';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	private const BOOKING_STATUS_CONFIRMED       = 'confirmed';
	private const BOOKING_STATUS_PENDING         = 'pending';
	private const OWNER_COOKIE                   = 'vkbm_draft_owner';

	/**
	 * @var Availability_Service
	 */
	private $availability_service;

	/**
	 * @var Booking_Notification_Service
	 */
	private $notification_service;

	/**
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * @var Customer_Name_Resolver
	 */
	private $customer_name_resolver;

	/**
	 * Constructor.
	 *
	 * @param Booking_Notification_Service $notification_service Notification handler.
	 * @param Settings_Repository          $settings_repository  Provider settings repository.
	 * @param Customer_Name_Resolver|null  $customer_name_resolver Customer name resolver.
	 * @param Availability_Service|null    $availability_service Availability service.
	 */
	public function __construct(
		Booking_Notification_Service $notification_service,
		Settings_Repository $settings_repository,
		?Customer_Name_Resolver $customer_name_resolver = null,
		?Availability_Service $availability_service = null
	) {
		$this->notification_service   = $notification_service;
		$this->settings_repository    = $settings_repository;
		$this->customer_name_resolver = $customer_name_resolver ?: new Customer_Name_Resolver();
		$this->availability_service   = $availability_service ?: new Availability_Service( $settings_repository );
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
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Finalize a reservation temporary data into a booking post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$token = $this->sanitize_token( $request['token'] ?? '' );
		if ( '' === $token ) {
			return new WP_Error(
				'missing_token',
				__( 'Temporary reservation data token not found.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = get_transient( $this->build_transient_key( $token ) );
		if ( false === $draft ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		if ( ! is_array( $draft ) ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = $this->backfill_draft_owner( $token, $draft );
		if ( ! $this->can_access_draft( $draft ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		$menu_id  = isset( $draft['menu_id'] ) ? (int) $draft['menu_id'] : 0;
		$staff_id = isset( $draft['resource_id'] ) ? (int) $draft['resource_id'] : 0;
		$slot     = isset( $draft['slot'] ) && is_array( $draft['slot'] ) ? $draft['slot'] : array();
		$start_at = isset( $slot['start_at'] ) ? (string) $slot['start_at'] : '';
		$end_at   = isset( $slot['end_at'] ) ? (string) $slot['end_at'] : '';

		if ( $menu_id <= 0 || '' === $start_at ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$reservation_day_type = (string) get_post_meta( $menu_id, self::MENU_META_RESERVATION_DAY_TYPE, true );
		if ( '' !== $reservation_day_type && ! $this->is_reservation_day_allowed( $reservation_day_type, $start_at ) ) {
			return new WP_Error(
				'invalid_reservation_day',
				__( 'The selected date cannot be reserved. Please choose another date.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$timezone = '';
		if ( isset( $draft['meta'] ) && is_array( $draft['meta'] ) ) {
			$timezone = sanitize_text_field( (string) ( $draft['meta']['timezone'] ?? '' ) );
		}

		// Re-check availability for the selected slot before confirming. / 予約確定前に空きを再検証します。
		$available_slot = $this->revalidate_draft_slot( $menu_id, $staff_id, $slot, $timezone );
		if ( is_wp_error( $available_slot ) ) {
			return $available_slot;
		}

		// Use the latest slot snapshot for staff assignment checks. / 最新の空き情報に基づいて指名判定を行います。
		if ( isset( $available_slot['staff'] ) && is_array( $available_slot['staff'] ) ) {
			$slot['staff'] = $available_slot['staff'];
		}

		$assignable_staff = $this->normalize_assignable_staff_ids( $available_slot['assignable_staff_ids'] ?? array() );

		$is_staff_preferred = ! empty( $draft['is_staff_preferred'] );

		if ( $staff_id <= 0 && isset( $slot['staff']['id'] ) ) {
			$staff_id = (int) $slot['staff']['id'];
		}

		if ( $staff_id <= 0 && ! empty( $assignable_staff ) ) {
			$staff_id           = $this->select_auto_assigned_staff( $assignable_staff, $start_at, $end_at );
			$is_staff_preferred = false;
		}

		if ( $staff_id > 0 && ! empty( $assignable_staff ) && ! in_array( $staff_id, $assignable_staff, true ) ) {
			return new WP_Error(
				'staff_unavailable',
				__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $staff_id <= 0 ) {
			$singular = vkbm_get_resource_label_singular();
			return new WP_Error(
				'staff_assignment_failed',
				sprintf(
					/* translators: %s: Resource label (singular). */
					__( 'Could not assign person %s. Please try a different frame.', 'vk-booking-manager' ),
					$singular
				),
				array( 'status' => 409 )
			);
		}

			$user               = wp_get_current_user();
			$memo               = sanitize_textarea_field( (string) ( $request['memo'] ?? '' ) );
			$agree              = ! empty( $request['agree_terms'] );
			$agree_cancellation = $request->has_param( 'agree_cancellation_policy' )
				? ! empty( $request['agree_cancellation_policy'] )
				: $agree;
			$agree_tos          = $request->has_param( 'agree_terms_of_service' )
				? ! empty( $request['agree_terms_of_service'] )
				: $agree;
		$can_override_contact   = current_user_can( Capabilities::MANAGE_RESERVATIONS );
		$customer_name_override = $can_override_contact
			? sanitize_text_field( (string) ( $request['customer_name'] ?? '' ) )
			: '';
		$customer_phone         = $can_override_contact
			? sanitize_text_field( (string) ( $request['customer_phone'] ?? '' ) )
			: $this->get_user_phone_number( $user->ID );
		$internal_note          = $can_override_contact
			? sanitize_textarea_field( (string) ( $request['internal_note'] ?? '' ) )
			: '';
		$customer_name_value    = $customer_name_override ?: $this->customer_name_resolver->resolve_for_user( $user );
		$booking_author_id      = (int) $user->ID;
		$customer_email         = (string) $user->user_email;
		$matched_user_id        = 0;

		if ( $can_override_contact ) {
			$normalized_phone = VKBM_Helper::normalize_phone_number( $customer_phone );
			if ( '' !== $normalized_phone ) {
				// Assign booking author by matching phone number when possible.
				// 電話番号が一致するユーザーがいれば予約投稿者を割り当てる。
				$matched_user = $this->get_user_by_phone_number( $normalized_phone );
				if ( $matched_user instanceof WP_User ) {
					$booking_author_id = (int) $matched_user->ID;
					$customer_email    = (string) $matched_user->user_email;
					$matched_user_id   = (int) $matched_user->ID;
				}
			}

			if ( 0 === $matched_user_id ) {
				$customer_email = '';
			}
		}

		$settings              = $this->settings_repository->get_settings();
		$requires_cancellation = '' !== trim( (string) ( $settings['provider_cancellation_policy'] ?? '' ) );
		$requires_tos          = '' !== trim( (string) ( $settings['provider_terms_of_service'] ?? '' ) );
		$status_mode           = sanitize_key( (string) ( $settings['provider_booking_status_mode'] ?? self::BOOKING_STATUS_CONFIRMED ) );
		$initial_status        = self::BOOKING_STATUS_PENDING === $status_mode ? self::BOOKING_STATUS_PENDING : self::BOOKING_STATUS_CONFIRMED;

		if ( $can_override_contact ) {
			$requires_cancellation = false;
			$requires_tos          = false;
			$initial_status        = self::BOOKING_STATUS_CONFIRMED;
		}

		if ( $requires_cancellation && ! $agree_cancellation ) {
			return new WP_Error(
				'cancellation_policy_required',
				__( 'You must agree to the cancellation policy.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( $requires_tos && ! $agree_tos ) {
			return new WP_Error(
				'terms_required',
				__( 'You must agree to the terms of use.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $can_override_contact && $this->has_user_conflict( (int) $user->ID, $start_at, $end_at ) ) {
			return new WP_Error(
				'booking_time_conflict',
				__( 'A reservation for the same date and time already exists. Please change the date and time.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}
		if ( $can_override_contact && $matched_user_id > 0 && $this->has_user_conflict( $matched_user_id, $start_at, $end_at ) ) {
			return new WP_Error(
				'booking_time_conflict',
				__( 'A reservation for the same date and time already exists. Please change the date and time.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		$booking_id = wp_insert_post(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $this->generate_booking_title( $customer_name_value, $start_at ),
				'post_author' => $booking_author_id,
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		$start_for_storage     = $this->format_datetime_for_storage( $start_at );
		$end_for_storage       = $this->format_datetime_for_storage( $end_at );
		$service_end_at        = isset( $slot['service_end_at'] ) ? (string) $slot['service_end_at'] : '';
		$service_end_for_store = $this->format_datetime_for_storage( $service_end_at );

		update_post_meta( $booking_id, self::META_DATE_START, $start_for_storage ?: $start_at );

		$service_end_value = $service_end_for_store ?: ( $service_end_at ?: ( $end_for_storage ?: $end_at ) );
		if ( $service_end_value ) {
			update_post_meta( $booking_id, self::META_DATE_END, $service_end_value );
		}
		if ( $end_for_storage || $end_at ) {
			update_post_meta( $booking_id, self::META_DATE_TOTAL_END, $end_for_storage ?: $end_at );
		}
		if ( $staff_id > 0 ) {
			update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
		}
			update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
			$service_base_price = (int) get_post_meta( $menu_id, '_vkbm_base_price', true );
			$service_base_price = max( 0, $service_base_price );
			update_post_meta( $booking_id, self::META_SERVICE_BASE_PRICE, $service_base_price );
			update_post_meta( $booking_id, self::META_CUSTOMER, $customer_name_value );
			update_post_meta( $booking_id, self::META_CUSTOMER_MAIL, $customer_email );
		if ( '' !== $customer_phone ) {
			update_post_meta( $booking_id, self::META_CUSTOMER_TEL, $customer_phone );
		} else {
			delete_post_meta( $booking_id, self::META_CUSTOMER_TEL );
		}
		update_post_meta( $booking_id, self::META_STATUS, $initial_status );
		update_post_meta( $booking_id, self::META_IS_PREFERRED, $is_staff_preferred ? '1' : '' );
		update_post_meta( $booking_id, '_vkbm_booking_agreed_cancellation_policy', $agree_cancellation ? '1' : '' );
		update_post_meta( $booking_id, '_vkbm_booking_agreed_terms_of_service', $agree_tos ? '1' : '' );
		$nomination_fee = isset( $draft['nomination_fee'] ) ? (int) $draft['nomination_fee'] : 0;
		if ( ! Staff_Editor::is_enabled() ) {
			$nomination_fee = 0;
		}
		$disable_nomination_fee = (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true );
		if ( '1' === $disable_nomination_fee ) {
			$nomination_fee = 0;
		} elseif ( $nomination_fee <= 0 && $is_staff_preferred && $staff_id > 0 ) {
			$nomination_fee = $this->get_staff_nomination_fee( $staff_id );
		}

		if ( $nomination_fee > 0 ) {
			update_post_meta( $booking_id, self::META_NOMINATION_FEE, $nomination_fee );
		} else {
			delete_post_meta( $booking_id, self::META_NOMINATION_FEE );
		}

		// 基本料金合計 = 予約時のサービス基本料金 + 指名料（予約確定時に数値で保存する）.
		$base_total_price = max( 0, $service_base_price + max( 0, $nomination_fee ) );
		update_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, $base_total_price );
		if ( '' !== $memo ) {
			update_post_meta( $booking_id, self::META_NOTE, $memo );
		}
		if ( '' !== $internal_note ) {
			update_post_meta( $booking_id, self::META_INTERNAL_NOTE, $internal_note );
		} else {
			delete_post_meta( $booking_id, self::META_INTERNAL_NOTE );
		}

		delete_transient( $this->build_transient_key( $token ) );

		if ( self::BOOKING_STATUS_CONFIRMED === $initial_status ) {
			$this->notification_service->handle_confirmed_creation( (int) $booking_id );
		} else {
			$this->notification_service->handle_pending_creation( (int) $booking_id );
		}

		return new WP_REST_Response(
			array(
				'booking_id' => $booking_id,
				'status'     => $initial_status,
			)
		);
	}

	/**
	 * Select an available staff member from assignable candidates.
	 *
	 * @param array<int> $staff_ids Candidate staff IDs.
	 * @param string     $start_at  Slot start (ISO8601).
	 * @param string     $end_at    Slot end (ISO8601).
	 * @return int
	 */
	private function select_auto_assigned_staff( array $staff_ids, string $start_at, string $end_at ): int {
		foreach ( $staff_ids as $staff_id ) {
			if ( $staff_id <= 0 ) {
				continue;
			}

			if ( ! $this->has_staff_conflict( $staff_id, $start_at, $end_at ) ) {
				return (int) $staff_id;
			}
		}

		return 0;
	}

	/**
	 * Determine if the staff already has a booking overlapping the slot.
	 *
	 * @param int    $staff_id Staff ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_staff_conflict( int $staff_id, string $start_at, string $end_at ): bool {
		if ( $staff_id <= 0 ) {
			return true;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );

		if ( '' === $start_for_storage ) {
			return true;
		}

		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_RESOURCE_ID,
						'value'   => $staff_id,
						'compare' => '=',
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Determine if the user already has a booking overlapping the slot.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_user_conflict( int $user_id, string $start_at, string $end_at ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );

		if ( '' === $start_for_storage ) {
			return false;
		}

		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Retrieve nomination fee for staff.
	 *
	 * @param int $staff_id Staff ID.
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

		if ( $fee <= 0 ) {
			return 0;
		}
			return $fee;
	}

	/**
	 * Build transient key name.
	 */
	private function build_transient_key( string $token ): string {
		return self::DRAFT_PREFIX . $token;
	}

	/**
	 * Sanitize token input.
	 */
	private function sanitize_token( string $token ): string {
		$token = sanitize_key( $token );
		return $token ? $token : '';
	}

	/**
	 * Check whether the current requester can access the temporary reservation data payload.
	 *
	 * @param array<string, mixed> $payload Temporary reservation data payload.
	 * @return bool
	 */
	private function can_access_draft( array $payload ): bool {
		if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return true;
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $owner_user_id > 0 ) {
			return (int) get_current_user_id() === $owner_user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' === $owner_key ) {
			return false;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return false;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return false;
		}

		return hash_equals( $owner_key, $cookie_value );
	}

	/**
	 * Backfill ownership data for older temporary reservation data.
	 *
	 * @param string              $token Temporary reservation data token.
	 * @param array<string,mixed> $payload Temporary reservation data payload.
	 * @return array<string, mixed>
	 */
	private function backfill_draft_owner( string $token, array $payload ): array {
		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		$owner_key     = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';

		if ( $owner_user_id > 0 || '' !== $owner_key ) {
			return $payload;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return $payload;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return $payload;
		}

		$payload['owner_key'] = $cookie_value;
		return $payload;
	}

	/**
	 * Generate readable booking title.
	 */
	private function generate_booking_title( string $customer, string $start_at ): string {
		$label           = $customer ? $customer : __( 'Reservation', 'vk-booking-manager' );
		$formatted_start = $this->format_datetime_for_title( $start_at );
		return sprintf( '%s / %s', $label, $formatted_start );
	}

	/**
	 * Convert ISO8601 datetime to a compact booking title format.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
	 */
	private function format_datetime_for_title( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( 'Y.n.j H:i', $timestamp, wp_timezone() );
	}

	/**
	 * Convert ISO8601 datetime to site-local Y-m-d H:i:s string.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
	 */
	private function format_datetime_for_storage( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Retrieve stored phone number for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_user_phone_number( int $user_id ): string {
		$phone = trim( (string) get_user_meta( $user_id, 'phone_number', true ) );
		return $phone;
	}

	/**
	 * Retrieve a user by normalized phone number.
	 *
	 * @param string $phone Normalized phone number.
	 * @return WP_User|null
	 */
	private function get_user_by_phone_number( string $phone ): ?WP_User {
		if ( '' === $phone ) {
			return null;
		}

		// Match on normalized phone number to avoid formatting differences.
		// 表記揺れを避けるため正規化済みの電話番号で検索する。
		$users = get_users(
			array(
				'meta_key'    => 'phone_number',
				'meta_value'  => $phone,
				'number'      => 1,
				'count_total' => false,
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		$user = $users[0];
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Determine if booking date matches menu restriction.
	 *
	 * @param string $reservation_day_type Restriction type (weekend|weekday).
	 * @param string $start_at             Slot start (ISO8601).
	 * @return bool
	 */
	private function is_reservation_day_allowed( string $reservation_day_type, string $start_at ): bool {
		if ( '' === $reservation_day_type ) {
			return true;
		}

		$timestamp = strtotime( $start_at );
		if ( false === $timestamp ) {
			return true;
		}

		$weekday    = (int) wp_date( 'N', $timestamp, wp_timezone() );
		$is_weekend = ( 6 === $weekday || 7 === $weekday );

		if ( 'weekend' === $reservation_day_type ) {
			return $is_weekend;
		}

		if ( 'weekday' === $reservation_day_type ) {
			return ! $is_weekend;
		}

		return true;
	}

	/**
	 * Revalidate temporary reservation data slot availability before booking confirmation.
	 *
	 * 予約確定前に予約一時データの枠がまだ空いているか再検証します。
	 *
	 * @param int                  $menu_id            Menu post ID.
	 * @param int                  $preferred_staff_id Preferred staff ID (0 for auto).
	 * @param array<string, mixed> $slot               Temporary reservation data slot payload.
	 * @param string               $timezone           Timezone string (optional).
	 * @return array<string, mixed>|WP_Error
	 */
	private function revalidate_draft_slot(
		int $menu_id,
		int $preferred_staff_id,
		array $slot,
		string $timezone
	) {
		$slot_id  = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$start_at = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$end_at   = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// Resolve timezone from draft or slot data. / 予約一時データまたは枠情報からタイムゾーンを補正します。
		$timezone = sanitize_text_field( $timezone );
		if ( '' === $timezone ) {
			$timezone = $this->extract_timezone_from_datetime( $start_at );
		}

		$date = $this->extract_slot_date( $start_at, $timezone );
		if ( '' === $date ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$availability = $this->availability_service->get_daily_slots(
			array(
				'menu_id'     => $menu_id,
				'resource_id' => $preferred_staff_id,
				'date'        => $date,
				'timezone'    => $timezone,
			)
		);

		if ( is_wp_error( $availability ) ) {
			return new WP_Error(
				$availability->get_error_code(),
				$availability->get_error_message(),
				array( 'status' => 409 )
			);
		}

		$slots = isset( $availability['slots'] ) && is_array( $availability['slots'] ) ? $availability['slots'] : array();
		$match = $this->find_matching_available_slot( $slots, $slot_id, $start_at, $end_at );
		if ( null === $match ) {
			return new WP_Error(
				'slot_unavailable',
				__( 'The selected slot is no longer available. Please choose another slot.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $preferred_staff_id > 0 ) {
			$matched_staff_id = isset( $match['staff']['id'] ) ? (int) $match['staff']['id'] : 0;
			if ( 0 === $matched_staff_id || $matched_staff_id !== $preferred_staff_id ) {
				return new WP_Error(
					'staff_unavailable',
					__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		return $match;
	}

	/**
	 * Extract slot date string from ISO datetime.
	 *
	 * ISO日時から営業日の文字列を取得します。
	 *
	 * @param string $start_at Slot start datetime.
	 * @param string $timezone Timezone string (optional).
	 * @return string
	 */
	private function extract_slot_date( string $start_at, string $timezone ): string {
		try {
			$datetime = new DateTimeImmutable( $start_at );
		} catch ( Exception $e ) {
			return '';
		}

		if ( '' !== $timezone ) {
			try {
				$datetime = $datetime->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				// Ignore invalid timezone and use original. / タイムゾーンが不正な場合は元の値を使います。
			}
		}

		return $datetime->format( 'Y-m-d' );
	}

	/**
	 * Extract timezone name from ISO datetime if possible.
	 *
	 * ISO日時からタイムゾーン名を抽出します。
	 *
	 * @param string $value ISO datetime.
	 * @return string
	 */
	private function extract_timezone_from_datetime( string $value ): string {
		try {
			$datetime = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return '';
		}

		return $datetime->getTimezone()->getName();
	}

	/**
	 * Normalize staff ID list to unique positive integers.
	 *
	 * 指名候補IDを正の整数に正規化します。
	 *
	 * @param mixed $raw_ids Raw staff IDs.
	 * @return array<int>
	 */
	private function normalize_assignable_staff_ids( $raw_ids ): array {
		if ( ! is_array( $raw_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw_ids as $candidate ) {
			$candidate = (int) $candidate;
			if ( $candidate > 0 && ! in_array( $candidate, $ids, true ) ) {
				$ids[] = $candidate;
			}
		}

		return $ids;
	}

	/**
	 * Find matching slot from the availability snapshot.
	 *
	 * 空き一覧から一致する枠を取得します。
	 *
	 * @param array<int, array<string, mixed>> $slots   Available slots.
	 * @param string                           $slot_id Temporary reservation data slot ID.
	 * @param string                           $start_at Temporary reservation data start datetime.
	 * @param string                           $end_at Temporary reservation data end datetime.
	 * @return array<string, mixed>|null
	 */
	private function find_matching_available_slot( array $slots, string $slot_id, string $start_at, string $end_at ): ?array {
		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_id = isset( $candidate['slot_id'] ) ? (string) $candidate['slot_id'] : '';
			if ( '' !== $slot_id && $candidate_id === $slot_id ) {
				return $candidate;
			}
		}

		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_start = isset( $candidate['start_at'] ) ? (string) $candidate['start_at'] : '';
			$candidate_end   = isset( $candidate['end_at'] ) ? (string) $candidate['end_at'] : '';

			if ( $candidate_start === $start_at && ( '' === $end_at || $candidate_end === $end_at ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
