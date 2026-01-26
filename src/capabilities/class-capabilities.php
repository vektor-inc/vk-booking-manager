<?php
/**
 * Defines reusable capability identifiers for the plugin.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Capabilities;

/**
 * Defines reusable capability identifiers for the plugin.
 */
final class Capabilities {
	public const MANAGE_PROVIDER_SETTINGS = 'vkbm_manage_provider_settings';
	public const MANAGE_RESERVATIONS      = 'vkbm_manage_reservations';
	public const VIEW_RESERVATIONS        = 'vkbm_view_reservations';
	public const MANAGE_OWN_RESERVATIONS  = 'vkbm_manage_own_reservations';
	public const MANAGE_SERVICE_MENUS     = 'vkbm_manage_service_menus';
	public const VIEW_SERVICE_MENUS       = 'vkbm_view_service_menus';
	public const MANAGE_STAFF             = 'vkbm_manage_staff';
	public const EDIT_OWN_STAFF_PROFILE   = 'vkbm_edit_own_staff_profile';
	public const MANAGE_NOTIFICATIONS     = 'vkbm_manage_notifications';
	public const MANAGE_SYSTEM_SETTINGS   = 'vkbm_manage_system_settings';

	/**
	 * Returns the default capability map for salon owner role.
	 *
	 * @return array<string, bool>
	 */
	public static function owner_caps(): array {
		return array(
			self::MANAGE_PROVIDER_SETTINGS => true,
			self::MANAGE_RESERVATIONS      => true,
			self::VIEW_RESERVATIONS        => true,
			self::MANAGE_SERVICE_MENUS     => true,
			self::VIEW_SERVICE_MENUS       => true,
			self::MANAGE_STAFF             => true,
			self::EDIT_OWN_STAFF_PROFILE   => true,
			self::MANAGE_NOTIFICATIONS     => true,
			self::MANAGE_SYSTEM_SETTINGS   => true,
		);
	}

	/**
	 * Returns the default capability map for salon staff role.
	 *
	 * @return array<string, bool>
	 */
	public static function staff_caps(): array {
		return array(
			self::VIEW_RESERVATIONS       => true,
			self::MANAGE_OWN_RESERVATIONS => true,
			self::VIEW_SERVICE_MENUS      => true,
			self::EDIT_OWN_STAFF_PROFILE  => true,
		);
	}
}
