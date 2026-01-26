<?php
/**
 * Coordinates repository interactions and data sanitization.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

use WP_Error;

/**
 * Coordinates repository interactions and data sanitization.
 */
class Settings_Service {
	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private $repository;

	/**
	 * Settings sanitizer.
	 *
	 * @var Settings_Sanitizer
	 */
	private $sanitizer;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $repository Settings repository instance.
	 * @param Settings_Sanitizer  $sanitizer  Sanitizer instance.
	 */
	public function __construct( Settings_Repository $repository, Settings_Sanitizer $sanitizer ) {
		$this->repository = $repository;
		$this->sanitizer  = $sanitizer;
	}

	/**
	 * Retrieve current settings with defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->repository->get_settings();
	}

	/**
	 * Retrieve default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return $this->repository->get_default_settings();
	}

	/**
	 * Return missing required setting keys for the provider settings screen.
	 *
	 * @return array<int, string>
	 */
	public function get_missing_required_settings(): array {
		$settings = $this->repository->get_settings();
		$missing  = array();

		$provider_name = isset( $settings['provider_name'] ) ? trim( (string) $settings['provider_name'] ) : '';
		if ( '' === $provider_name ) {
			$missing[] = 'provider_name';
		}

		$regular_holidays_disabled = ! empty( $settings['provider_regular_holidays_disabled'] );
		$regular_holidays          = $settings['provider_regular_holidays'] ?? array();
		if ( ! $regular_holidays_disabled && ( ! is_array( $regular_holidays ) || array() === $regular_holidays ) ) {
			$missing[] = 'provider_regular_holidays';
		}

		$basic = $settings['provider_business_hours_basic'] ?? array();
		if ( ! is_array( $basic ) || array() === $basic ) {
			$missing[] = 'provider_business_hours_basic';
		}

		$reservation_page_url = isset( $settings['reservation_page_url'] ) ? trim( (string) $settings['reservation_page_url'] ) : '';
		if ( '' === $reservation_page_url ) {
			$missing[] = 'reservation_page_url';
		}

		return $missing;
	}

	/**
	 * Whether provider settings has any missing required values.
	 */
	public function has_missing_required_settings(): bool {
		return array() !== $this->get_missing_required_settings();
	}

	/**
	 * Sanitize and persist settings data.
	 *
	 * @param array<string, mixed> $raw_settings Raw settings payload.
	 * @return bool|WP_Error
	 */
	public function save_settings( array $raw_settings ) {
		$defaults  = $this->repository->get_default_settings();
		$sanitized = $this->sanitizer->sanitize( $raw_settings, $defaults );

		$errors       = $this->sanitizer->get_errors();
		$field_errors = $this->sanitizer->get_field_errors();

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'vkbm_settings_invalid',
				__( 'There is a problem with the input content.', 'vk-booking-manager' ),
				array(
					'messages' => $errors,
					'fields'   => $field_errors,
				)
			);
		}

		$this->repository->update_settings( $sanitized );

		return true;
	}
}
