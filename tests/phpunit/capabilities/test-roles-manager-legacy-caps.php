<?php
/**
 * Tests for removal of legacy capabilities in Roles_Manager.
 *
 * 廃止した権限 vkbm_manage_notifications が付与されず、既存ロールに残っていても取り除かれることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Capabilities;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Capabilities\Roles_Manager;
use WP_UnitTestCase;
use function add_role;
use function get_role;
use function remove_role;

/**
 * Legacy capability removal test.
 *
 * @group capabilities
 */
class Roles_Manager_Legacy_Caps_Test extends WP_UnitTestCase {

	/**
	 * 廃止した権限が owner_caps() に含まれず、sync_roles() 後にどのロールにも残らないことを検証する。
	 */
	public function test_legacy_capability_is_not_granted_and_is_removed(): void {
		$legacy = 'vkbm_manage_notifications';

		$this->assertArrayNotHasKey( $legacy, Capabilities::owner_caps(), 'owner_caps() に廃止した権限が含まれない' );

		$manager = new Roles_Manager();
		$manager->sync_roles();

		$role_names = array(
			'administrator',
			Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
			Roles_Manager::ROLE_PROVIDER_OWNER,
			'editor',
		);

		// 旧バージョンで保存済みの状態を再現するため、各ロールに旧権限を付与する。
		foreach ( $role_names as $role_name ) {
			$role = get_role( $role_name );
			$this->assertNotNull( $role, $role_name . ' ロールが存在する' );
			$role->add_cap( $legacy );
			$this->assertArrayHasKey( $legacy, get_role( $role_name )->capabilities, $role_name . ' に旧権限を付与した前提' );
		}

		// 独自ロール（read と旧権限を持つ）も除去対象になることを確かめるために作る。
		add_role(
			'vkbm_test_custom',
			'VKBM Test Custom',
			array(
				'read'  => true,
				$legacy => true,
			)
		);

		try {
			$manager->sync_roles();

			foreach ( array_merge( $role_names, array( 'vkbm_test_custom' ) ) as $role_name ) {
				$this->assertArrayNotHasKey(
					$legacy,
					get_role( $role_name )->capabilities,
					$role_name . ' から旧権限が取り除かれる'
				);
			}

			// 消しすぎていないこと。owner_caps() の全権限が残っている。
			foreach ( array( 'administrator', Roles_Manager::ROLE_PROVIDER_SITE_OWNER, Roles_Manager::ROLE_PROVIDER_OWNER ) as $role_name ) {
				$caps = get_role( $role_name )->capabilities;
				foreach ( array_keys( Capabilities::owner_caps() ) as $capability ) {
					$this->assertArrayHasKey( $capability, $caps, $role_name . ' に ' . $capability . ' が残っている' );
				}
			}
			$this->assertArrayHasKey( Capabilities::VIEW_SERVICE_MENUS, get_role( 'editor' )->capabilities, 'editor に vkbm_view_service_menus が残っている' );
			$this->assertArrayHasKey( 'read', get_role( 'vkbm_test_custom' )->capabilities, '独自ロールの read は残る' );
		} finally {
			remove_role( 'vkbm_test_custom' );
		}
	}
}
