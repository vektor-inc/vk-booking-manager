<?php
/**
 * Tests for restricted user-management capabilities of owner roles.
 *
 * サイトオーナー・事業者が、ユーザー作成・削除・昇格や、管理者ユーザーへの操作で
 * 権限昇格できないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Capabilities;

use VKBookingManager\Capabilities\Roles_Manager;
use WP_UnitTestCase;
use function get_editable_roles;
use function get_role;
use function user_can;
use function wp_set_current_user;

/**
 * Owner roles user-management restriction test.
 *
 * @group capabilities
 */
class Roles_Manager_User_Caps_Test extends WP_UnitTestCase {

	/**
	 * 各テストの前にロール同期とフィルター登録を行う。
	 */
	public function set_up(): void {
		parent::set_up();
		$manager = new Roles_Manager();
		$manager->sync_roles();
		$manager->register();
	}

	/**
	 * 両ロールが外す権限を持たず、顧客管理に必要な権限は持つことを検証する。
	 */
	public function test_sync_roles(): void {
		$removed = array( 'promote_users', 'create_users', 'delete_users', 'remove_users', 'update_core', 'edit_files' );
		$kept    = array( 'edit_users', 'list_users' );

		$test_cases = array(
			array(
				'test_condition_name' => 'サイトオーナーは外す6権限を持たない',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'caps'                => $removed,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーは外す6権限を持たない',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'caps'                => $removed,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーは edit_users / list_users を持つ',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'caps'                => $kept,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンオーナーは edit_users / list_users を持つ',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'caps'                => $kept,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$role = get_role( $case['role'] );
			$this->assertNotNull( $role, $case['test_condition_name'] );
			foreach ( $case['caps'] as $cap ) {
				$this->assertSame( $case['expected'], $role->has_cap( $cap ), $case['test_condition_name'] . ' : ' . $cap );
			}
		}
	}

	/**
	 * 管理者以外の操作者による edit_user / promote_user / delete_user / remove_user の可否を検証する（対象が管理者、または操作者より強い権限を持つ場合は拒否、本人・顧客・管理者の操作は許可）。
	 */
	public function test_restrict_admin_user_meta_caps(): void {
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin2      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_owner  = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_SITE_OWNER ) );
		$owner       = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$site_owner2 = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_SITE_OWNER ) );
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$customer    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'サロンオーナーは管理者を edit_user できない',
				'actor'               => $owner,
				'cap'                 => 'edit_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーは管理者を promote_user できない',
				'actor'               => $owner,
				'cap'                 => 'promote_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーは管理者を delete_user できない',
				'actor'               => $owner,
				'cap'                 => 'delete_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーは管理者を edit_user できない',
				'actor'               => $site_owner,
				'cap'                 => 'edit_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーは管理者を promote_user できない',
				'actor'               => $site_owner,
				'cap'                 => 'promote_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーは管理者を delete_user できない',
				'actor'               => $site_owner,
				'cap'                 => 'delete_user',
				'target'              => $admin,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーは購読者（顧客）を edit_user できる',
				'actor'               => $owner,
				'cap'                 => 'edit_user',
				'target'              => $customer,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンオーナーは自分自身を edit_user できる',
				'actor'               => $owner,
				'cap'                 => 'edit_user',
				'target'              => $owner,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者は別の管理者を edit_user できる',
				'actor'               => $admin2,
				'cap'                 => 'edit_user',
				'target'              => $admin,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンオーナーはサイトオーナーを edit_user できない',
				'actor'               => $owner,
				'cap'                 => 'edit_user',
				'target'              => $site_owner,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーはサイトオーナーを delete_user できない',
				'actor'               => $owner,
				'cap'                 => 'delete_user',
				'target'              => $site_owner,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーはサイトオーナーを remove_user できない',
				'actor'               => $owner,
				'cap'                 => 'remove_user',
				'target'              => $site_owner,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーはサイトオーナーを promote_user できない',
				'actor'               => $owner,
				'cap'                 => 'promote_user',
				'target'              => $site_owner,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーは編集者を edit_user できない',
				'actor'               => $owner,
				'cap'                 => 'edit_user',
				'target'              => $editor,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーは自分自身を edit_user できる',
				'actor'               => $site_owner,
				'cap'                 => 'edit_user',
				'target'              => $site_owner,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サイトオーナーは別のサイトオーナーを edit_user できる',
				'actor'               => $site_owner,
				'cap'                 => 'edit_user',
				'target'              => $site_owner2,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サイトオーナーは購読者（顧客）を edit_user できる',
				'actor'               => $site_owner,
				'cap'                 => 'edit_user',
				'target'              => $customer,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				user_can( $case['actor'], $case['cap'], $case['target'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 割り当て可能ロールの一覧から administrator / provider_site_owner が外れることを検証する。
	 */
	public function test_filter_editable_roles(): void {
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_owner = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_SITE_OWNER ) );
		$owner      = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'サロンオーナーには administrator を出さない',
				'actor'               => $owner,
				'role'                => 'administrator',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーには provider_site_owner を出さない',
				'actor'               => $owner,
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーには administrator を出さない',
				'actor'               => $site_owner,
				'role'                => 'administrator',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーには provider_site_owner を出さない',
				'actor'               => $site_owner,
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーには購読者を出す',
				'actor'               => $owner,
				'role'                => 'subscriber',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者には administrator を出す',
				'actor'               => $admin,
				'role'                => 'administrator',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者には provider_site_owner を出す',
				'actor'               => $admin,
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['actor'] );
			$this->assertSame(
				$case['expected'],
				array_key_exists( $case['role'], get_editable_roles() ),
				$case['test_condition_name']
			);
		}
	}
}
