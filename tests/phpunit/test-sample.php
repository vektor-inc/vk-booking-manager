<?php
/**
 * Sample test for VK Booking Manager.
 *
 * @package VKBookingManager
 */

class VKBM_Sample_Test extends WP_UnitTestCase {
	/**
	 * Ensure base plugin constants are loaded.
	 */
	public function test_plugin_version_constant_defined() {
		$this->assertTrue( defined( 'VKBM_VERSION' ) );
	}
}
