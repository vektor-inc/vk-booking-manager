<?php
/**
 * Tests for the sync signature that limits when Roles_Manager syncs roles.
 *
 * ロールの同期を「同期の目印が変わったときだけ」行い、必要なときには確実に同期されることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Capabilities;

use VKBookingManager\Capabilities\Roles_Manager;
use WP_UnitTestCase;
use function get_option;
use function get_role;
use function __;
use function remove_role;
use function update_option;
use function wp_roles;

/**
 * Roles sync signature test.
 *
 * @group capabilities
 */
class Roles_Manager_Sync_Signature_Test extends WP_UnitTestCase {

	/**
	 * ロール情報の option が更新された回数。
	 *
	 * @var int
	 */
	private $role_option_updates = 0;

	/**
	 * 目印が無い状態から始め、ロール情報の option の更新回数を数える。
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->role_option_updates = 0;
		add_filter(
			'pre_update_option_' . $wpdb->prefix . 'user_roles',
			function ( $value ) {
				++$this->role_option_updates;
				return $value;
			}
		);

		delete_option( 'vkbm_roles_sync_signature' );
		wp_roles()->for_site();
	}

	/**
	 * option とロールを元に戻す。
	 */
	public function tear_down(): void {
		delete_option( 'vkbm_roles_sync_signature' );
		wp_roles()->for_site();

		parent::tear_down();
	}

	/**
	 * Roles_Manager だけを init に登録して init を実行する。
	 *
	 * @param Roles_Manager $manager 登録する Roles_Manager。
	 */
	private function run_init( Roles_Manager $manager ): void {
		remove_all_actions( 'init' );
		$manager->register();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core のフックを発火する。
	}

	/**
	 * 目印が一致する2回目以降の init ではロール情報が1回も更新されず、不一致に戻ると同期されることを検証する。
	 */
	public function test_register_skips_sync_when_signature_matches(): void {
		$manager = new Roles_Manager();

		// 1回目の init は目印が無いため同期され、目印が保存される。
		$this->run_init( $manager );
		$saved_signature = get_option( 'vkbm_roles_sync_signature' );
		$this->assertGreaterThan( 0, $this->role_option_updates, '目印が無い1回目の init では同期される' );

		// 目印が一致する2回目・3回目の init では、同期（権限の追加・削除）が走らない。
		$this->role_option_updates = 0;
		$this->run_init( $manager );
		$this->run_init( $manager );
		$this->assertSame( 0, $this->role_option_updates, '目印が一致する init ではロール情報の option が更新されない' );
		$this->assertNotEmpty( $saved_signature, '1回目の init の後に目印が保存される' );
		$this->assertSame( $saved_signature, get_option( 'vkbm_roles_sync_signature' ), '目印は変わらない' );

		// 保存済みの目印が古い値（ロール定義のバージョンが上がった場合などと同じ）なら同期される。
		update_option( 'vkbm_roles_sync_signature', 'outdated' );
		$this->run_init( $manager );
		$this->assertGreaterThan( 0, $this->role_option_updates, '目印が一致しなければ同期される' );
		$this->assertSame( $saved_signature, get_option( 'vkbm_roles_sync_signature' ), '同期後の状態から作った目印が保存される' );
	}

	/**
	 * 管理者ロールの権限が変わるとサイトオーナーのロールに反映され、反映後は同期が間引かれることを検証する。
	 */
	public function test_register_resyncs_when_administrator_capabilities_change(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		$test_cases = array(
			array(
				'test_condition_name' => '他のプラグインが管理者に権限を足した場合 => サイトオーナーにも反映される',
				'capability'          => 'vkbm_test_added_by_other_plugin',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '別の権限をさらに足した場合 => こちらもサイトオーナーに反映される',
				'capability'          => 'vkbm_test_added_by_other_plugin_2',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			get_role( 'administrator' )->add_cap( $case['capability'] );
			$this->assertFalse( get_role( Roles_Manager::ROLE_PROVIDER_SITE_OWNER )->has_cap( $case['capability'] ), $case['test_condition_name'] . '（前提）' );

			$this->run_init( $manager );
			$this->assertSame( $case['expected'], get_role( Roles_Manager::ROLE_PROVIDER_SITE_OWNER )->has_cap( $case['capability'] ), $case['test_condition_name'] );

			// 反映後は目印が一致し、再び同期されない。
			$this->role_option_updates = 0;
			$this->run_init( $manager );
			$this->assertSame( 0, $this->role_option_updates, $case['test_condition_name'] . '（反映後は同期されない）' );
		}

		get_role( 'administrator' )->remove_cap( 'vkbm_test_added_by_other_plugin' );
		get_role( 'administrator' )->remove_cap( 'vkbm_test_added_by_other_plugin_2' );
	}

	/**
	 * 本プラグインのロールが削除されると、次の init で作り直されることを検証する。
	 */
	public function test_register_recreates_deleted_roles(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		$test_cases = array(
			array(
				'test_condition_name' => 'サロンオーナーのロールを削除した場合 => 作り直される',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サイトオーナーのロールを削除した場合 => 作り直される',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			remove_role( $case['role'] );
			$this->assertNull( get_role( $case['role'] ), $case['test_condition_name'] . '（前提）' );

			$this->run_init( $manager );
			$recreated_role = get_role( $case['role'] );
			$this->assertSame( $case['expected'], null !== $recreated_role, $case['test_condition_name'] );
		}
	}

	/**
	 * 既存サイトに保存された事業者ロールの旧表示名が、次の init で更新されることを検証する。
	 */
	public function test_register_updates_existing_provider_owner_display_name(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		// テスト環境では日本語の翻訳が読み込まれていることがあり、他のテストの実行順にも左右されるため、
		// 英語の固定文言ではなく本体と同じ翻訳関数の戻り値（現在の表示名）と比較する。
		$current_name = __( 'Business Owner', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => '旧英語表示名が保存されている場合 => Business Owner に更新される',
				'saved_name'          => 'Salon Owner',
				'expected'            => $current_name,
			),
			array(
				'test_condition_name' => '旧日本語表示名が保存されている場合 => 事業者に更新される',
				'saved_name'          => 'サロンオーナー',
				'expected'            => $current_name,
			),
			array(
				'test_condition_name' => '任意の古い表示名が保存されている場合 => Business Owner に更新される',
				'saved_name'          => 'Old Provider Name',
				'expected'            => $current_name,
			),
		);

		foreach ( $test_cases as $case ) {
			$roles = wp_roles();
			$roles->roles[ Roles_Manager::ROLE_PROVIDER_OWNER ]['name'] = $case['saved_name'];
			$roles->role_names[ Roles_Manager::ROLE_PROVIDER_OWNER ]    = $case['saved_name'];
			update_option( $roles->role_key, $roles->roles );

			$this->run_init( $manager );

			$this->assertSame(
				$case['expected'],
				wp_roles()->get_names()[ Roles_Manager::ROLE_PROVIDER_OWNER ],
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 本プラグインの2つのロールに制限対象の権限が足されると、次の init で取り除かれることを検証する。
	 */
	public function test_register_removes_restricted_capabilities_added_to_roles(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		$test_cases = array(
			array(
				'test_condition_name' => 'サイトオーナーに promote_users を足した場合 => 取り除かれる',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'capability'          => 'promote_users',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイトオーナーに update_core を足した場合 => 取り除かれる',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'capability'          => 'update_core',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーに create_users を足した場合 => 取り除かれる',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'capability'          => 'create_users',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンオーナーに edit_posts を足した場合 => 取り除かれる',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'capability'          => 'edit_posts',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			get_role( $case['role'] )->add_cap( $case['capability'] );
			$this->assertTrue( get_role( $case['role'] )->has_cap( $case['capability'] ), $case['test_condition_name'] . '（前提）' );

			$this->run_init( $manager );
			$this->assertSame( $case['expected'], get_role( $case['role'] )->has_cap( $case['capability'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 本プラグインの権限（vkbm_ で始まるもの）が外されると、次の init で付け直されることを検証する。
	 */
	public function test_register_regrants_plugin_capabilities_removed_from_roles(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		$test_cases = array(
			array(
				'test_condition_name' => 'サイトオーナーから vkbm_manage_reservations を外した場合 => 付け直される',
				'role'                => Roles_Manager::ROLE_PROVIDER_SITE_OWNER,
				'capability'          => 'vkbm_manage_reservations',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンオーナーから vkbm_manage_reservations を外した場合 => 付け直される',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'capability'          => 'vkbm_manage_reservations',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サロンオーナーから vkbm_manage_staff を外した場合 => 付け直される',
				'role'                => Roles_Manager::ROLE_PROVIDER_OWNER,
				'capability'          => 'vkbm_manage_staff',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			get_role( $case['role'] )->remove_cap( $case['capability'] );
			$this->assertFalse( get_role( $case['role'] )->has_cap( $case['capability'] ), $case['test_condition_name'] . '（前提）' );

			$this->run_init( $manager );
			$this->assertSame( $case['expected'], get_role( $case['role'] )->has_cap( $case['capability'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 有効化時（activate）は目印が一致していても必ず同期することを検証する。
	 */
	public function test_activate_always_syncs(): void {
		$manager = new Roles_Manager();
		$this->run_init( $manager );

		$this->role_option_updates = 0;
		$signature_before          = get_option( 'vkbm_roles_sync_signature' );

		$manager->activate();

		$this->assertGreaterThan( 0, $this->role_option_updates, '目印が一致していても activate() は同期する' );
		$this->assertSame( $signature_before, get_option( 'vkbm_roles_sync_signature' ), '同期後の目印は一致したまま' );
	}

	/**
	 * 同期を間引いても、サイトオーナー・事業者が権限昇格に使える権限を持たないことを検証する。
	 */
	public function test_register_keeps_privilege_escalation_restrictions(): void {
		$manager    = new Roles_Manager();
		$restricted = array( 'promote_users', 'create_users', 'delete_users', 'remove_users', 'update_core', 'edit_files', 'manage_options' );
		$test_cases = array(
			array(
				'test_condition_name' => '1回目の init（同期あり） => 制限が効いている',
				'init_runs'           => 1,
			),
			array(
				'test_condition_name' => '2回目の init（同期を間引いた） => 制限が効いたまま',
				'init_runs'           => 2,
			),
			array(
				'test_condition_name' => '3回目の init（同期を間引いた） => 制限が効いたまま',
				'init_runs'           => 3,
			),
		);

		$runs = 0;
		foreach ( $test_cases as $case ) {
			while ( $runs < $case['init_runs'] ) {
				$this->run_init( $manager );
				++$runs;
			}

			foreach ( array( Roles_Manager::ROLE_PROVIDER_SITE_OWNER, Roles_Manager::ROLE_PROVIDER_OWNER ) as $role_name ) {
				foreach ( $restricted as $capability ) {
					$this->assertFalse( get_role( $role_name )->has_cap( $capability ), $case['test_condition_name'] . ' : ' . $role_name . ' が ' . $capability . ' を持たない' );
				}
			}
		}
	}
}
