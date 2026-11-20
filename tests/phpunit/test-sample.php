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

	/**
	 * 本体ファイルのヘッダーと readme.txt の「Requires at least」「Requires PHP」が一致することを確認する。
	 *
	 * 有効化のときは本体ファイルのヘッダー、更新のときは readme.txt の値が使われるため、ずれると
	 * 有効化と更新で必要なバージョンの扱いが食い違う。
	 */
	public function test_plugin_header_matches_readme() {
		$plugin_dir = dirname( __DIR__, 2 );
		$headers    = array(
			'Requires at least' => 'Requires at least',
			'Requires PHP'      => 'Requires PHP',
		);

		$plugin_data = get_file_data( $plugin_dir . '/vk-booking-manager.php', $headers );
		$readme_data = get_file_data( $plugin_dir . '/readme.txt', $headers );

		foreach ( $headers as $name ) {
			$this->assertNotSame( '', $plugin_data[ $name ], "本体ファイルのヘッダーに {$name} がある" );
			$this->assertSame( $readme_data[ $name ], $plugin_data[ $name ], "{$name} が readme.txt と一致する" );
		}
	}
}
