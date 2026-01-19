<?php

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

/**
 * Persists provider settings to the WordPress options table.
 */
class Settings_Repository {
	public const OPTION_KEY = 'vkbm_provider_settings';

	/**
	 * Option key used for persistence.
	 *
	 * @var string
	 */
	private $option_key;

	/**
	 * Constructor.
	 *
	 * @param string $option_key Option key to use.
	 */
	public function __construct( string $option_key = self::OPTION_KEY ) {
		$this->option_key = $option_key;
	}

	/**
	 * Returns the default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'provider_name'             => '',
			'provider_address'          => '',
			'provider_phone'            => '',
			'provider_payment_method'  => "Please pay at the store when you visit. \\nYou can use cash, credit cards, and transportation ICs.",
			'resource_label_singular'   => 'Staff',
			'resource_label_plural'     => 'Staff',
			'resource_label_menu'       => 'Staff available',
				'provider_business_hours'   => '',
			'provider_reservation_deadline_hours' => 3,
				'provider_service_menu_buffer_after_minutes' => 0,
				'provider_booking_status_mode' => 'confirmed',
			'provider_booking_cancel_mode' => 'hours',
			'provider_booking_cancel_deadline_hours' => 24,
			'provider_allow_staff_overlap_admin' => false,
			'provider_website_url'      => '',
			'provider_email'            => '',
			'shift_alert_months'        => 1,
			'design_primary_color'      => '',
			'design_reservation_button_color' => '',
			'design_radius_md'          => 8,
			'provider_logo_id'          => 0,
			'provider_cancellation_policy' => "For cancellations or changes, please contact us during business hours the day before your reservation date. \\nIf you cancel on the day, 100% of the treatment fee will be charged as a cancellation fee. \\nIn case of cancellation without notice, 100% of the treatment fee will be charged. \\nPlease be sure to contact us if you will be late for your reservation time. If you are late, your treatment time may be shortened (the price will not change).",
			'provider_terms_of_service' => "[System Terms of Use]\\n\\nThese Terms set forth the terms of use of the reservation system (hereinafter referred to as the \"Service\") provided by our store. Customers who use this service (hereinafter referred to as \"users\") must agree to these terms before using the service. \\n\\n1. Application\\nThese Terms apply to all relationships related to the use of this service between users and our store. \\n\\n2. Registration for use\\nWhen using this service, you may be required to register for use as necessary. If there are any falsehoods, errors, or omissions in the registered information, our store may cancel the approval of registration. \\n\\n3. Account Management\\nUsers shall manage their account information at their own risk. We are not responsible for any damage caused by unauthorized use of your account, unless it is intentional or grossly negligent on our part. \\n\\n4. Reservations/Changes/Cancellations\\nHandling of reservations, changes, cancellations, late arrivals, etc. shall be in accordance with the \"Cancellation Policy\" separately established by our store. \\n\\n5. Prohibited matters\\nUsers must not engage in the following acts when using this service. \\n- Acts that violate laws and regulations or public order and morals\\n- Acts that make reservations using false information\\n- Acts that infringe on the rights and interests of our store or third parties\\n- Acts that interfere with the operation of this service (excessive access, unauthorized access to the system, etc.)\\n- Other acts that our store deems inappropriate\\n\\n6.Disclaimer\\nOur store does not guarantee the accuracy, completeness, usefulness, etc. of the content of this service. This service may be unavailable due to communication line/equipment/system failures, maintenance, force majeure, etc., and we will not be responsible for any damage caused to users as a result, unless there is intentional or gross negligence on our part. \\n\\n7. Handling of personal information\\nOur store handles users' personal information appropriately in accordance with our privacy policy. \\n\\n8. Changes to Terms\\nOur store may change the contents of these Terms as necessary. The revised Terms will be made known by posting on the Service or any other method that the Company deems appropriate, and if the User uses the Service after being made aware, the User will be deemed to have agreed to the changes. \\n\\n9. Governing law/jurisdiction\\nJapanese law shall be the governing law for the interpretation of these Terms, and in the event of any dispute regarding this service, the court with jurisdiction over the location of our store shall have exclusive jurisdiction. \\n",
			'provider_privacy_policy_mode' => 'none',
			'provider_privacy_policy_url'  => '',
			'provider_privacy_policy_content' => "[Privacy Policy]\\n\\nOur store uses customers' personal information for the following purposes. \\n1. Management and communication of reservations\\n2. Information on service provision and improvement\\n3. Response based on laws and regulations\\n\\nInformation to be acquired: name, email address, telephone number, date of birth, etc.\\n\\nProvision to third parties: Unless required by law, information will not be provided to third parties without the consent of the individual. \\n\\nStorage and management: We will take necessary safety management measures to prevent unauthorized access, etc. \\n\\nDisclosure/Correction/Deletion: If there is a request from the person in question, we will respond according to the prescribed method. \\n\\nContact us: Please contact our store.",
			'reservation_page_url'      => '',
			'reservation_show_menu_list' => true,
			'reservation_menu_list_display_mode' => 'card',
			'reservation_show_provider_logo' => false,
			'reservation_show_provider_name' => false,
			'provider_regular_holidays' => [],
			'provider_regular_holidays_disabled' => false,
			'provider_business_hours_basic'  => [],
			'provider_business_hours_weekly' => $this->get_default_business_hours_weekly(),
			'registration_email_verification_enabled' => true,
			'membership_redirect_wp_register' => true,
			'membership_redirect_wp_login'  => true,
			'auth_rate_limit_enabled'        => true,
			'auth_rate_limit_register_max'   => 5,
			'auth_rate_limit_login_max'      => 10,
		];
	}

	/**
	 * Fetches persisted settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( $this->option_key, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$defaults = $this->get_default_settings();
		$settings = array_merge( $defaults, $stored );

		$settings['provider_business_hours_basic'] = $this->normalize_business_hours_basic(
			$settings['provider_business_hours_basic'] ?? []
		);

		$settings['provider_business_hours_weekly'] = $this->normalize_business_hours_weekly(
			$settings['provider_business_hours_weekly'] ?? []
		);

		return $settings;
	}

	/**
	 * Persists the provided settings array.
	 *
	 * @param array<string, mixed> $settings Sanitized settings array.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( array $settings ): bool {
		return update_option( $this->option_key, $settings );
	}

	/**
	 * Returns the default weekly business hours structure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_business_hours_weekly(): array {
		$keys = [
			'mon',
			'tue',
			'wed',
			'thu',
			'fri',
			'sat',
			'sun',
			'holiday',
			'holiday_eve',
		];

		$defaults = [];

		foreach ( $keys as $key ) {
			$defaults[ $key ] = [
				'use_custom' => false,
				'time_slots' => [],
			];
		}

		return $defaults;
	}

	/**
	 * Normalize basic business hour payloads.
	 *
	 * @param mixed $raw Raw value from the options table.
	 * @return array<int, array{start:string,end:string}>
	 */
	private function normalize_business_hours_basic( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $raw as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
			$end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';

			if ( '' === $start || '' === $end ) {
				continue;
			}

			$normalized[] = [
				'start' => $start,
				'end'   => $end,
			];
		}

		return $normalized;
	}

	/**
	 * Normalize weekly business hour payloads into the current structure.
	 *
	 * @param mixed $raw Raw value from the options table.
	 * @return array<string, array{use_custom:bool,time_slots:array<int, array{start:string,end:string}>}>
	 */
	private function normalize_business_hours_weekly( $raw ): array {
		$normalized = $this->get_default_business_hours_weekly();

		if ( ! is_array( $raw ) ) {
			return $normalized;
		}

		foreach ( $normalized as $day_key => $default_value ) {
			$day_value = $raw[ $day_key ] ?? null;

			if ( ! is_array( $day_value ) ) {
				// Try to map the legacy format directly from merged array.
				$day_value = $this->convert_legacy_day_value( $day_value );
			}

			if ( ! is_array( $day_value ) ) {
				continue;
			}

			$use_custom = isset( $day_value['use_custom'] ) ? (bool) $day_value['use_custom'] : false;
			$time_slots = [];

			if ( isset( $day_value['time_slots'] ) && is_array( $day_value['time_slots'] ) ) {
				foreach ( $day_value['time_slots'] as $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}

					$start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
					$end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';

					if ( '' === $start || '' === $end ) {
						continue;
					}

					$time_slots[] = [
						'start' => $start,
						'end'   => $end,
					];
				}
			} elseif ( isset( $day_value['start'], $day_value['end'] ) ) {
				// Legacy single slot format.
				$start = (string) $day_value['start'];
				$end   = (string) $day_value['end'];

				if ( '' !== $start && '' !== $end ) {
					$time_slots[] = [
						'start' => $start,
						'end'   => $end,
					];
				}
			}

			$normalized[ $day_key ] = [
				'use_custom' => $use_custom,
				'time_slots' => $time_slots,
			];
		}

		return $normalized;
	}

	/**
	 * Attempt to convert legacy day values into the new structure.
	 *
	 * @param mixed $value Raw value for a day.
	 * @return array<string, mixed>|null
	 */
	private function convert_legacy_day_value( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( array_key_exists( 'use_custom', $value ) || array_key_exists( 'time_slots', $value ) ) {
			if ( array_key_exists( 'use_basic', $value ) && ! array_key_exists( 'use_custom', $value ) ) {
				$value['use_custom'] = ! empty( $value['use_basic'] );
				unset( $value['use_basic'] );
			}

			return $value;
		}

		if ( array_key_exists( 'use_basic', $value ) ) {
			$use_basic   = ! empty( $value['use_basic'] );
			$time_slots = isset( $value['time_slots'] ) && is_array( $value['time_slots'] ) ? $value['time_slots'] : [];

			return [
				'use_custom' => ! $use_basic,
				'time_slots' => $time_slots,
			];
		}

		$enabled = ! empty( $value['enabled'] );
		$start   = isset( $value['start'] ) ? (string) $value['start'] : '';
		$end     = isset( $value['end'] ) ? (string) $value['end'] : '';

		if ( ! $enabled || '' === $start || '' === $end ) {
			return [
				'use_custom' => false,
				'time_slots' => [],
			];
		}

		return [
			'use_custom' => true,
			'time_slots' => [
				[
					'start' => $start,
					'end'   => $end,
				],
			],
		];
	}
}
