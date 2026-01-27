<?php

/**
 * Sanitizes provider settings form submissions.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes provider settings form submissions.
 */
class Settings_Sanitizer {
	private const HOLIDAY_FREQUENCIES = array(
		'weekly',
		'nth-1',
		'nth-2',
		'nth-3',
		'nth-4',
		'nth-5',
	);

	private const WEEKDAY_KEYS = array(
		'mon',
		'tue',
		'wed',
		'thu',
		'fri',
		'sat',
		'sun',
	);

	private const BUSINESS_DAY_KEYS = array(
		'mon',
		'tue',
		'wed',
		'thu',
		'fri',
		'sat',
		'sun',
		'holiday',
		'holiday_eve',
	);

	/**
	 * Collected validation errors.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Structured field errors.
	 *
	 * @var array<string, mixed>
	 */
	private $field_errors = array();

	/**
	 * Sanitize submitted settings data.
	 *
	 * @param array<string, mixed> $input    Raw input values.
	 * @param array<string, mixed> $defaults Default values to merge against.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input, array $defaults ): array {
		$this->errors       = array();
		$this->field_errors = array();

		$data = array_merge( $defaults, $input );

		$data['provider_name']                                  = sanitize_text_field( $data['provider_name'] );
		$data['provider_address']                               = sanitize_textarea_field( $data['provider_address'] );
		$data['provider_phone']                                 = sanitize_text_field( $data['provider_phone'] );
		$data['provider_payment_method']                        = sanitize_textarea_field( (string) ( $data['provider_payment_method'] ?? '' ) );
		$data['resource_label_singular']                        = sanitize_text_field( (string) ( $data['resource_label_singular'] ?? '' ) );
		$data['resource_label_plural']                          = sanitize_text_field( (string) ( $data['resource_label_plural'] ?? '' ) );
		$data['resource_label_menu']                            = sanitize_text_field( (string) ( $data['resource_label_menu'] ?? '' ) );
			$data['provider_business_hours']                    = sanitize_textarea_field( $data['provider_business_hours'] );
			$data['provider_reservation_deadline_hours']        = $this->sanitize_non_negative_int(
				$input['provider_reservation_deadline_hours'] ?? ( $data['provider_reservation_deadline_hours'] ?? 0 )
			);
			$slot_step_minutes                                  = isset( $input['provider_slot_step_minutes'] ) ? (int) $input['provider_slot_step_minutes'] : ( $data['provider_slot_step_minutes'] ?? 15 );
			$allowed_slot_steps                                 = array( 10, 15, 20, 30, 60 );
			$data['provider_slot_step_minutes']                 = in_array( $slot_step_minutes, $allowed_slot_steps, true ) ? $slot_step_minutes : 15;
			$data['provider_service_menu_buffer_after_minutes'] = $this->sanitize_non_negative_int(
				$input['provider_service_menu_buffer_after_minutes'] ?? ( $data['provider_service_menu_buffer_after_minutes'] ?? 0 )
			);
			$booking_status_mode                                = sanitize_key( (string) ( $input['provider_booking_status_mode'] ?? ( $data['provider_booking_status_mode'] ?? 'confirmed' ) ) );
			$data['provider_booking_status_mode']               = in_array( $booking_status_mode, array( 'confirmed', 'pending' ), true ) ? $booking_status_mode : 'confirmed';
		$cancel_mode                                    = sanitize_key( (string) ( $input['provider_booking_cancel_mode'] ?? ( $data['provider_booking_cancel_mode'] ?? 'hours' ) ) );
		$data['provider_booking_cancel_mode']           = in_array( $cancel_mode, array( 'hours', 'none' ), true ) ? $cancel_mode : 'hours';
		$data['provider_booking_cancel_deadline_hours'] = $this->sanitize_non_negative_int(
			$input['provider_booking_cancel_deadline_hours'] ?? ( $data['provider_booking_cancel_deadline_hours'] ?? 24 )
		);
		$data['provider_allow_staff_overlap_admin']     = ! empty( $input['provider_allow_staff_overlap_admin'] );
			$data['provider_website_url']               = $this->sanitize_url( $data['provider_website_url'] );
			$data['reservation_page_url']               = $this->sanitize_url( $data['reservation_page_url'] );
		$data['reservation_show_menu_list']             = ! empty( $input['reservation_show_menu_list'] );
		$menu_list_display_mode                         = sanitize_key( (string) ( $input['reservation_menu_list_display_mode'] ?? ( $data['reservation_menu_list_display_mode'] ?? 'card' ) ) );
		$data['reservation_menu_list_display_mode']     = in_array( $menu_list_display_mode, array( 'card', 'text' ), true ) ? $menu_list_display_mode : 'card';
		$data['reservation_show_provider_logo']         = ! empty( $input['reservation_show_provider_logo'] );
		$data['reservation_show_provider_name']         = ! empty( $input['reservation_show_provider_name'] );
		$currency_symbol_raw                            = (string) ( $input['currency_symbol'] ?? ( $data['currency_symbol'] ?? '' ) );
		$data['currency_symbol']                        = sanitize_text_field( $currency_symbol_raw );
		$tax_label_raw                                  = (string) ( $input['tax_label_text'] ?? ( $data['tax_label_text'] ?? '' ) );
		// English: Allow leading/trailing spaces, while stripping tags.
		// 日本語: 先頭・末尾の空白は維持しつつ、タグは除去します.
		$data['tax_label_text'] = wp_kses( $tax_label_raw, array() );
		$data['provider_email'] = $this->sanitize_email( $data['provider_email'] );
		if ( '' === $data['provider_email'] ) {
			$data['provider_email'] = $this->sanitize_email( (string) get_option( 'admin_email' ) );
		}
		$shift_alert_months                      = $this->sanitize_non_negative_int(
			$input['shift_alert_months'] ?? ( $data['shift_alert_months'] ?? 1 )
		);
		$data['shift_alert_months']              = min( 4, max( 1, $shift_alert_months ) );
		$data['booking_reminder_hours']          = $this->sanitize_reminder_hours(
			$input['booking_reminder_hours'] ?? ( $data['booking_reminder_hours'] ?? array() )
		);
		$data['design_primary_color']            = $this->sanitize_color( $input['design_primary_color'] ?? ( $data['design_primary_color'] ?? '' ) );
		$data['design_reservation_button_color'] = $this->sanitize_color(
			$input['design_reservation_button_color'] ?? ( $data['design_reservation_button_color'] ?? '' )
		);
		$design_radius_raw                       = $input['design_radius_md'] ?? ( $data['design_radius_md'] ?? 8 );
		$data['design_radius_md']                = '' === $design_radius_raw ? '' : $this->sanitize_non_negative_int( $design_radius_raw );
		$data['provider_logo_id']                = absint( $data['provider_logo_id'] );
		$data['provider_cancellation_policy']    = sanitize_textarea_field( $data['provider_cancellation_policy'] );
		$data['provider_terms_of_service']       = sanitize_textarea_field( $data['provider_terms_of_service'] );
		$privacy_mode                            = sanitize_key( (string) ( $input['provider_privacy_policy_mode'] ?? ( $data['provider_privacy_policy_mode'] ?? 'none' ) ) );
		$data['provider_privacy_policy_mode']    = in_array( $privacy_mode, array( 'none', 'url', 'content' ), true ) ? $privacy_mode : 'none';
		$data['provider_privacy_policy_url']     = $this->sanitize_url(
			$input['provider_privacy_policy_url'] ?? ( $data['provider_privacy_policy_url'] ?? '' )
		);
		$data['provider_privacy_policy_content'] = sanitize_textarea_field(
			(string) ( $input['provider_privacy_policy_content'] ?? ( $data['provider_privacy_policy_content'] ?? '' ) )
		);

		$data['provider_regular_holidays_disabled'] = ! empty( $input['provider_regular_holidays_disabled'] );
		$data['provider_regular_holidays']          = $data['provider_regular_holidays_disabled']
			? array()
			: $this->sanitize_regular_holidays( $input['provider_regular_holidays'] ?? array() );
		$data['provider_business_hours_basic']      = $this->sanitize_business_hours_basic(
			$input['provider_business_hours_basic'] ?? array()
		);
		$data['provider_business_hours_weekly']     = $this->sanitize_business_hours_weekly(
			$input['provider_business_hours_weekly'] ?? array(),
			$defaults['provider_business_hours_weekly'] ?? $this->get_default_business_hours_weekly(),
			$data['provider_business_hours_basic'],
			$data['provider_regular_holidays']
		);

		$data['registration_email_verification_enabled'] = ! empty( $input['registration_email_verification_enabled'] );
		$data['membership_redirect_wp_register']         = ! empty( $input['membership_redirect_wp_register'] );
		$data['membership_redirect_wp_login']            = ! empty( $input['membership_redirect_wp_login'] );
		$data['auth_rate_limit_enabled']                 = ! empty( $input['auth_rate_limit_enabled'] );
		$register_limit                                  = $this->sanitize_non_negative_int(
			$input['auth_rate_limit_register_max'] ?? ( $data['auth_rate_limit_register_max'] ?? 5 )
		);
		$data['auth_rate_limit_register_max']            = max( 1, $register_limit );
		$login_limit                                     = $this->sanitize_non_negative_int(
			$input['auth_rate_limit_login_max'] ?? ( $data['auth_rate_limit_login_max'] ?? 10 )
		);
		$data['auth_rate_limit_login_max']               = max( 1, $login_limit );
		$data['menu_loop_reserve_button_label']          = sanitize_text_field( (string) ( $input['menu_loop_reserve_button_label'] ?? ( $data['menu_loop_reserve_button_label'] ?? '' ) ) );
		$data['menu_loop_detail_button_label']           = sanitize_text_field( (string) ( $input['menu_loop_detail_button_label'] ?? ( $data['menu_loop_detail_button_label'] ?? '' ) ) );

		$data['email_log_enabled'] = ! empty( $input['email_log_enabled'] );

		$retention_raw = $input['email_log_retention_days'] ?? ( $data['email_log_retention_days'] ?? 1 );
		if ( '' === $retention_raw || null === $retention_raw ) {
			$retention_raw = $defaults['email_log_retention_days'] ?? 1;
		}
		$data['email_log_retention_days'] = max( 1, $this->sanitize_non_negative_int( $retention_raw ) );

		return $data;
	}

	/**
	 * Returns the validation errors collected during sanitization.
	 *
	 * @return array<int, string>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Returns structured field errors keyed by input path.
	 *
	 * @return array<string, mixed>
	 */
	public function get_field_errors(): array {
		return $this->field_errors;
	}

	/**
	 * Sanitize URL while allowing empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_url( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		return esc_url_raw( (string) $value );
	}

	/**
	 * Sanitize email while allowing empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_email( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$email = sanitize_email( (string) $value );

		if ( ! is_email( $email ) ) {
			return '';
		}

		return $email;
	}

	/**
	 * Sanitize reminder hours array.
	 *
	 * @param mixed $raw Raw reminder hour values.
	 * @return array<int, int>
	 */
	private function sanitize_reminder_hours( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$hours = $this->sanitize_non_negative_int( $value );
			if ( $hours <= 0 ) {
				continue;
			}

			$sanitized[] = $hours;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized );

		return $sanitized;
	}

	/**
	 * Sanitize a hex color while allowing empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_color( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$color = sanitize_hex_color( (string) $value );
		if ( ! is_string( $color ) ) {
			return '';
		}

		return $color;
	}

	/**
	 * Sanitize regular holiday rules.
	 *
	 * @param mixed $raw_holidays Raw input.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_regular_holidays( $raw_holidays ): array {
		if ( ! is_array( $raw_holidays ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw_holidays as $holiday ) {
			if ( ! is_array( $holiday ) ) {
				continue;
			}

			$frequency = sanitize_text_field( $holiday['frequency'] ?? '' );
			$weekday   = sanitize_text_field( $holiday['weekday'] ?? '' );

			if ( ! in_array( $frequency, self::HOLIDAY_FREQUENCIES, true ) ) {
				continue;
			}

			if ( ! in_array( $weekday, self::WEEKDAY_KEYS, true ) ) {
				continue;
			}

			$sanitized[] = array(
				'frequency' => $frequency,
				'weekday'   => $weekday,
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize a non-negative integer value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function sanitize_non_negative_int( $value ): int {
		if ( '' === $value || null === $value ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}

		return 0;
	}

	/**
	 * Sanitize shared basic business hours slots.
	 *
	 * @param mixed $raw_basic Raw input.
	 * @return array<int, array{start:string,end:string}>
	 */
	private function sanitize_business_hours_basic( $raw_basic ): array {
		if ( ! is_array( $raw_basic ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw_basic as $index => $slot_input ) {
			if ( ! is_array( $slot_input ) ) {
				continue;
			}

			$slot_label = __( 'Basic business hours', 'vk-booking-manager' );

			$slot = $this->sanitize_time_slot(
				$slot_input,
				$slot_label,
				array(
					'basic',
					(string) $index,
				)
			);

			if ( null === $slot ) {
				continue;
			}

			$sanitized[] = $slot;
		}

		return $sanitized;
	}

	/**
	 * Sanitize weekly business hours.
	 *
	 * @param mixed                               $raw_hours Raw input.
	 * @param array<string, array<string, mixed>> $defaults Default structure.
	 * @param array<int, array<string, string>>   $basic_slots Sanitized basic slots.
	 * @param array<int, array<string, string>>   $regular_holidays Sanitized holidays.
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_business_hours_weekly(
		$raw_hours,
		array $defaults,
		array $basic_slots,
		array $regular_holidays
	): array {
		if ( ! is_array( $raw_hours ) ) {
			$raw_hours = array();
		}

		$sanitized = $this->get_default_business_hours_weekly();

		if ( ! empty( $defaults ) ) {
			$sanitized = array_merge( $sanitized, $defaults );
		}

		foreach ( $sanitized as $day_key => $default_values ) {
			$day_label = $this->get_day_label( $day_key );

			if ( $this->is_forced_regular_holiday( $day_key, $regular_holidays ) ) {
				$sanitized[ $day_key ] = array(
					'use_custom' => false,
					'time_slots' => array(),
				);
				continue;
			}

			$day_input = isset( $raw_hours[ $day_key ] ) && is_array( $raw_hours[ $day_key ] )
				? $raw_hours[ $day_key ]
				: array();

			$default_use_custom = isset( $default_values['use_custom'] ) ? (bool) $default_values['use_custom'] : false;
			$use_custom         = isset( $day_input['use_custom'] ) ? (bool) $day_input['use_custom'] : $default_use_custom;
			$time_slots         = array();

			if ( $use_custom ) {
				$raw_slots = isset( $day_input['time_slots'] ) && is_array( $day_input['time_slots'] )
					? $day_input['time_slots']
					: array();

				foreach ( $raw_slots as $index => $slot_input ) {
					if ( ! is_array( $slot_input ) ) {
						continue;
					}

					$slot_label = $this->get_time_slot_label( $day_key, (int) $index );
					$slot       = $this->sanitize_time_slot(
						$slot_input,
						$slot_label,
						array(
							'weekly',
							$day_key,
							(string) $index,
						)
					);

					if ( null === $slot ) {
						continue;
					}

					$time_slots[] = $slot;
				}
			}

			$should_use_custom = $use_custom;

			$sanitized[ $day_key ] = array(
				'use_custom' => $should_use_custom,
				'time_slots' => $should_use_custom ? $time_slots : array(),
			);
		}

		return $sanitized;
	}

	/**
	 * Convert posted time fields into HH:MM format.
	 *
	 * @param array<string, mixed> $input        Slot input array.
	 * @param string               $prefix       'start' or 'end'.
	 * @param string               $context_label Label for error messages.
	 * @param array<int, string>   $field_path   Path used for field error mapping.
	 * @return string
	 */
	private function sanitize_time_components( array $input, string $prefix, string $context_label, array $field_path ): string {
		$hour_key   = $prefix . '_hour';
		$minute_key = $prefix . '_minute';

		$hour   = sanitize_text_field( $input[ $hour_key ] ?? ( $input[ $prefix ] ?? '' ) );
		$minute = sanitize_text_field( $input[ $minute_key ] ?? '' );

		if ( '' === $minute && preg_match( '/^\d{2}:\d{2}$/', $hour ) ) {
			return $hour;
		}

		if ( '' === $hour || '' === $minute ) {
			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'Please enter the start and end times for business hours for %s.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return '';
		}

		if ( ! ctype_digit( $hour ) || ! ctype_digit( $minute ) ) {
			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'The time specification for %s is invalid.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return '';
		}

		$hour_int   = (int) $hour;
		$minute_int = (int) $minute;

		if ( 24 === $hour_int ) {
			if ( 'end' === $prefix && 0 === $minute_int ) {
				return '24:00';
			}

			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'The time specification for %s is invalid.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return '';
		}

		if ( $hour_int < 0 || $hour_int > 23 ) {
			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'The time specification for %s is invalid.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return '';
		}

		if ( ! in_array( $minute_int, array( 0, 10, 20, 30, 40, 50 ), true ) ) {
			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'Please specify the minutes for %s in 10 minute increments.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return '';
		}

		return sprintf( '%02d:%02d', $hour_int, $minute_int );
	}

	/**
	 * Sanitize a single time slot entry.
	 *
	 * @param array<string, mixed> $slot_input    Slot input.
	 * @param string               $context_label Context label for errors.
	 * @param array<int, string>   $field_path    Path used for field error mapping.
	 * @return array{start:string,end:string}|null
	 */
	private function sanitize_time_slot( array $slot_input, string $context_label, array $field_path ): ?array {
		$raw_start_hour   = isset( $slot_input['start_hour'] ) ? (string) $slot_input['start_hour'] : (string) ( $slot_input['start'] ?? '' );
		$raw_start_minute = isset( $slot_input['start_minute'] ) ? (string) $slot_input['start_minute'] : '';
		$raw_end_hour     = isset( $slot_input['end_hour'] ) ? (string) $slot_input['end_hour'] : (string) ( $slot_input['end'] ?? '' );
		$raw_end_minute   = isset( $slot_input['end_minute'] ) ? (string) $slot_input['end_minute'] : '';

		$has_start_input = '' !== $raw_start_hour || '' !== $raw_start_minute;
		$has_end_input   = '' !== $raw_end_hour || '' !== $raw_end_minute;

		if ( ! $has_start_input && ! $has_end_input ) {
			return null;
		}

		$start = $this->sanitize_time_components( $slot_input, 'start', $context_label, $field_path );
		$end   = $this->sanitize_time_components( $slot_input, 'end', $context_label, $field_path );

		if ( '' === $start || '' === $end ) {
			return null;
		}

		if ( ! $this->is_valid_time_range( $start, $end ) ) {
			$this->add_error(
				sprintf(
					/* translators: %s: context label. */
					__( 'Business hours for %s must start time before end time.', 'vk-booking-manager' ),
					$context_label
				),
				$field_path
			);

			return null;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Validate that end time is later than start time.
	 *
	 * @param string $start Start time.
	 * @param string $end   End time.
	 * @return bool
	 */
	private function is_valid_time_range( string $start, string $end ): bool {
		$start_parts = explode( ':', $start );
		$end_parts   = explode( ':', $end );

		if ( 2 !== count( $start_parts ) || 2 !== count( $end_parts ) ) {
			return false;
		}

		$start_minutes = ( (int) $start_parts[0] * 60 ) + (int) $start_parts[1];
		$end_minutes   = ( (int) $end_parts[0] * 60 ) + (int) $end_parts[1];

		return $start_minutes < $end_minutes;
	}

	/**
	 * Returns the default weekly business hours structure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_business_hours_weekly(): array {
		$defaults = array();

		foreach ( self::BUSINESS_DAY_KEYS as $key ) {
			$defaults[ $key ] = array(
				'use_custom' => false,
				'time_slots' => array(),
			);
		}

		return $defaults;
	}

	/**
	 * Returns the localized day label for business hours.
	 *
	 * @param string $day_key Day key.
	 * @return string
	 */
	private function get_day_label( string $day_key ): string {
		$labels = array(
			'mon'         => __( 'Monday', 'vk-booking-manager' ),
			'tue'         => __( 'Tuesday', 'vk-booking-manager' ),
			'wed'         => __( 'Wednesday', 'vk-booking-manager' ),
			'thu'         => __( 'Thursday', 'vk-booking-manager' ),
			'fri'         => __( 'Friday', 'vk-booking-manager' ),
			'sat'         => __( 'Saturday', 'vk-booking-manager' ),
			'sun'         => __( 'Sunday', 'vk-booking-manager' ),
			'holiday'     => __( 'Holiday', 'vk-booking-manager' ),
			'holiday_eve' => __( 'The day before a public holiday', 'vk-booking-manager' ),
		);

		return $labels[ $day_key ] ?? $day_key;
	}

	/**
	 * Returns a localized label for error messages on time slots.
	 *
	 * @param string $day_key    Day identifier.
	 * @param int    $slot_index Zero-based slot index.
	 * @return string
	 */
	private function get_time_slot_label( string $day_key, int $slot_index ): string {
		$day_label  = $this->get_day_label( $day_key );
		$slot_order = $slot_index + 1;

		return sprintf(
			/* translators: 1: Day label, 2: slot order number. */
			__( '%1$s (frame %2$d)', 'vk-booking-manager' ),
			$day_label,
			$slot_order
		);
	}

	/**
	 * Determine whether the given day is forced closed by weekly regular holidays.
	 *
	 * @param string                            $day_key Day identifier.
	 * @param array<int, array<string, string>> $regular_holidays Regular holiday rules.
	 * @return bool
	 */
	private function is_forced_regular_holiday( string $day_key, array $regular_holidays ): bool {
		if ( ! in_array( $day_key, self::WEEKDAY_KEYS, true ) ) {
			return false;
		}

		foreach ( $regular_holidays as $holiday_rule ) {
			if ( ! is_array( $holiday_rule ) ) {
				continue;
			}

			if ( ( $holiday_rule['frequency'] ?? '' ) !== 'weekly' ) {
				continue;
			}

			if ( ( $holiday_rule['weekday'] ?? '' ) === $day_key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Append an error message.
	 *
	 * @param string             $message    Error message.
	 * @param array<int, string> $field_path Field path for inline display.
	 */
	private function add_error( string $message, array $field_path = array() ): void {
		$this->errors[] = $message;

		if ( empty( $field_path ) ) {
			return;
		}

		$ref =& $this->field_errors;

		foreach ( $field_path as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = array();
			}

			$ref =& $ref[ $segment ];
		}

		if ( ! is_array( $ref ) ) {
			$ref = array();
		}

		$ref[] = $message;
	}
}
