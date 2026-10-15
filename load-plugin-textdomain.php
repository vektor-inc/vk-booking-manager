<?php
/**
 * Load plugin textdomain.
 * 無料版・Pro 版のどちらの配布物にも含まれる。
 *
 * @package VKBookingManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

load_plugin_textdomain( 'vk-booking-manager', false, dirname( plugin_basename( VKBM_PLUGIN_FILE ) ) . '/languages' );
