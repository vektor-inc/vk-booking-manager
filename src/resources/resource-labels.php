<?php
/**
 * Resource label helper functions.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * Get the configured singular label for resources (default: Staff).
 *
 * @return string
 */
function vkbm_get_resource_label_singular(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : 'Staff';
	$label      = trim( $label );

	return '' !== $label ? $label : 'Staff';
}

/**
 * Get the configured plural label for resources (default: Staff).
 *
 * @return string
 */
function vkbm_get_resource_label_plural(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : 'Staff';
	$label      = trim( $label );

	if ( '' === $label ) {
		return vkbm_get_resource_label_singular();
	}

	$singular = vkbm_get_resource_label_singular();
	if ( 'Staff' === $label && 'Staff' !== $singular ) {
		return $singular;
	}

	return $label;
}
