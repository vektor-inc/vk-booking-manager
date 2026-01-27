<?php
/**
 * Load plugin textdomain.
 * This file is excluded from free edition GitHub releases for WordPress.org compliance.
 *
 * @package VKBookingManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

load_plugin_textdomain( 'vk-booking-manager', false, dirname( plugin_basename( VKBM_PLUGIN_FILE ) ) . '/languages' );
