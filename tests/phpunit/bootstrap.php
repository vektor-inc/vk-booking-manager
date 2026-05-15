<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package VKBookingManager
 */

$plugin_root = dirname( __DIR__, 2 );
$tests_dir   = getenv( 'WP_TESTS_DIR' );

require $plugin_root . '/vendor/autoload.php';

if ( ! $tests_dir ) {
	$tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $tests_dir ) {
	$tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $tests_dir . '/includes/functions.php';

/**
 * Load the plugin file.
 */
function vkbm_tests_load_plugin() {
	require dirname( __DIR__, 2 ) . '/vk-booking-manager.php';

	// テスト実行中はレート制限を無効化する（連続呼び出しテスト等の安定化）。
	// Disable rate limiting during tests for stability across repeated invocations.
	add_filter( 'vkbm_rate_limit_enabled', '__return_false' );
}
tests_add_filter( 'muplugins_loaded', 'vkbm_tests_load_plugin' );

require_once $tests_dir . '/includes/bootstrap.php';
