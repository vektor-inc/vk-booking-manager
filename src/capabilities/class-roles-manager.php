<?php
/**
 * Handles registering and maintaining custom roles and capabilities.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Role;

/**
 * Handles registering and maintaining custom roles and capabilities.
 */
class Roles_Manager {
	public const ROLE_PROVIDER_SITE_OWNER = 'provider_site_owner';
	public const ROLE_PROVIDER_OWNER      = 'provider_owner';

	/**
	 * ロール定義のバージョン番号。
	 *
	 * ロールの表示名や、SITE_OWNER_RESTRICTED_CAPABLES / OWNER_CONTENT_RESTRICTIONS /
	 * LEGACY_CAPABILITIES / Capabilities::owner_caps() などの権限の付け方を変えたら必ず上げること。
	 * 上げ忘れると、プラグインを更新した既存サイトで同期が走らず、変更が反映されない。
	 */
	public const ROLES_DEFINITION_VERSION = 2;

	/**
	 * 前回の同期後の状態を表す「同期の目印」を保存する option 名。
	 * 毎リクエスト読むため autoload を有効にして保存する。
	 */
	public const SYNC_SIGNATURE_OPTION = 'vkbm_roles_sync_signature';

	/**
	 * Capabilities that site owners should not inherit from administrators.
	 *
	 * @var string[]
	 */
	private const SITE_OWNER_RESTRICTED_CAPABLES = array(
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'edit_themes',
		'install_themes',
		'update_themes',
		'switch_themes',
		'delete_themes',
		'edit_theme_options',
		'manage_options',
		'import',
		'export',
		'create_vk_block_patterns',
		// ユーザーの作成・削除・昇格や、コア更新・ファイル編集による権限昇格を防ぐ。
		// edit_users / list_users は顧客情報の編集・メール認証の手動承認で使うため残す。
		'promote_users',
		'create_users',
		'delete_users',
		'remove_users',
		'update_core',
		'edit_files',
	);

	/**
	 * Roles that non-administrators must not assign to users.
	 *
	 * @var string[]
	 */
	private const NON_ADMIN_UNASSIGNABLE_ROLES = array(
		'administrator',
		self::ROLE_PROVIDER_SITE_OWNER,
	);

	/**
	 * Meta capabilities that operate on another user (the target user ID is $args[0]).
	 *
	 * @var string[]
	 */
	private const USER_TARGET_META_CAPS = array(
		'edit_user',
		'delete_user',
		'remove_user',
		'promote_user',
	);

	/**
	 * Additional capabilities to strip from salon owners (remove post/page editing).
	 *
	 * @var string[]
	 */
	private const OWNER_CONTENT_RESTRICTIONS = array(
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'publish_posts',
		'delete_posts',
		'delete_published_posts',
		'delete_others_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'publish_pages',
		'delete_pages',
		'delete_published_pages',
		'manage_categories',
		'manage_tags',
		'edit_terms',
		'delete_terms',
		'manage_link_categories',
		'manage_post_tags',
	);

	/**
	 * 旧バージョンで付与していたが、現在は使っていない権限。
	 * sync のたびに全ロールから取り除き、DB に残さない。
	 *
	 * @var string[]
	 */
	private const LEGACY_CAPABILITIES = array(
		'vkbm_manage_notifications',
	);

	/**
	 * Register hooks to keep roles and capabilities in sync.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_sync_roles' ) );
		add_filter( 'map_meta_cap', array( $this, 'restrict_administrator_user_meta_caps' ), 10, 4 );
		add_filter( 'editable_roles', array( $this, 'filter_editable_roles' ) );
	}

	/**
	 * 管理者以外の操作者が本人以外のユーザーを操作するとき、対象が管理者、または操作者が持たない権限を1つでも持つ場合は拒否する。
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id Acting user ID.
	 * @param array    $args    Additional arguments ($args[0] is the target user ID).
	 * @return string[]
	 */
	public function restrict_administrator_user_meta_caps( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::USER_TARGET_META_CAPS, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$target_id = (int) $args[0];
		$user_id   = (int) $user_id;

		// 本人の編集（プロフィールなど）は制限しない。管理者は制限しない。
		if ( $target_id <= 0 || $target_id === $user_id || $this->is_administrator_user( $user_id ) ) {
			return $caps;
		}

		if ( $this->is_administrator_user( $target_id ) || $this->target_has_capability_actor_lacks( $target_id, $user_id ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Whether the target user holds any capability the acting user does not hold.
	 *
	 * 操作者より強い権限を持つユーザーのアカウントを書き換えて乗っ取る経路を防ぐ。
	 * ロール名のキー（allcaps に含まれる）は権限ではないので比較から除外する。
	 *
	 * @param int $target_id Target user ID.
	 * @param int $actor_id  Acting user ID.
	 */
	private function target_has_capability_actor_lacks( int $target_id, int $actor_id ): bool {
		$target = get_userdata( $target_id );
		$actor  = get_userdata( $actor_id );

		if ( ! $target instanceof \WP_User ) {
			return false;
		}

		if ( ! $actor instanceof \WP_User ) {
			return true;
		}

		$role_names = array_map( 'strval', array_keys( wp_roles()->get_names() ) );

		foreach ( $target->allcaps as $capability => $granted ) {
			$capability = (string) $capability;
			if ( ! $granted || in_array( $capability, $role_names, true ) ) {
				continue;
			}
			if ( empty( $actor->allcaps[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hide roles that non-administrators must not assign from the role choices.
	 *
	 * @param array $roles Editable roles.
	 * @return array
	 */
	public function filter_editable_roles( $roles ) {
		if ( ! is_array( $roles ) || $this->is_administrator_user( get_current_user_id() ) ) {
			return $roles;
		}

		foreach ( self::NON_ADMIN_UNASSIGNABLE_ROLES as $role ) {
			unset( $roles[ $role ] );
		}

		return $roles;
	}

	/**
	 * Whether the user is an administrator (administrator role, manage_options, or super admin).
	 *
	 * @param int $user_id User ID.
	 */
	private function is_administrator_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
			return true;
		}

		return user_can( $user, 'manage_options' );
	}

	/**
	 * 同期の目印が前回の同期後と変わっているときだけロールを同期する。
	 *
	 * 毎リクエストの add_cap / remove_cap 判定を避けるため。
	 * 目印が変わるのは、ロール定義のバージョンが上がったとき、管理者ロールの権限が変わったとき
	 * （他のプラグインが追加した場合を含む）、本プラグインのロールが無くなったときなど。
	 */
	public function maybe_sync_roles(): void {
		if ( get_option( self::SYNC_SIGNATURE_OPTION ) === $this->build_sync_signature() ) {
			return;
		}

		$this->sync_roles();
	}

	/**
	 * 現在のロールの状態から「同期の目印」を作る。
	 *
	 * wp_roles() が読み込み済みの内容だけを使い、DB への問い合わせや書き込みは行わない。
	 *
	 * @return string
	 */
	private function build_sync_signature(): string {
		$roles       = wp_roles();
		$admin_role  = $roles->get_role( 'administrator' );
		$editor_role = $roles->get_role( 'editor' );
		$admin_caps  = $admin_role instanceof WP_Role ? $admin_role->capabilities : array();
		$owner_caps  = Capabilities::owner_caps();
		$editor_caps = $editor_role instanceof WP_Role ? $editor_role->capabilities : array();

		ksort( $admin_caps );
		ksort( $owner_caps );

		// 本プラグインのロールは表示名と権限一覧を含める（変更を検出して付け直すため）。ロールが無ければ null。
		$provider_roles = array();
		foreach ( array( self::ROLE_PROVIDER_SITE_OWNER, self::ROLE_PROVIDER_OWNER ) as $role_name ) {
			$provider_role = $roles->get_role( $role_name );
			$caps          = $provider_role instanceof WP_Role ? $provider_role->capabilities : null;
			if ( is_array( $caps ) ) {
				ksort( $caps );
			}
			$provider_roles[ $role_name ] = array(
				'name'         => $roles->get_names()[ $role_name ] ?? null,
				'capabilities' => $caps,
			);
		}

		// serialize は失敗して空になることがないため、変化の検出が止まらない。
		return md5(
			serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- 目印のハッシュ用で、保存・復元には使わない。
				array(
					self::ROLES_DEFINITION_VERSION,
					$admin_caps,
					$owner_caps,
					$provider_roles,
					$editor_role instanceof WP_Role,
					! empty( $editor_caps[ Capabilities::VIEW_SERVICE_MENUS ] ),
				)
			)
		);
	}

	/**
	 * Ensure roles and capabilities exist.
	 *
	 * 目印にかかわらず必ず同期し、同期後の状態から作った目印を保存する。
	 * 同期で管理者ロール自体にも権限を足すため、目印は同期の後に計算する。
	 */
	public function sync_roles(): void {
		// 管理者ロールの権限をコピーして作るロールがあるため、先に旧権限を取り除く。
		$this->remove_legacy_capabilities();
		$this->ensure_provider_site_owner_role();
		$this->ensure_provider_owner_role();
		$this->ensure_administrator_capabilities();
		$this->ensure_editor_capabilities();

		update_option( self::SYNC_SIGNATURE_OPTION, $this->build_sync_signature(), true );
	}

	/**
	 * 使わなくなった権限を全ロールから取り除く。
	 */
	private function remove_legacy_capabilities(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::LEGACY_CAPABILITIES as $capability ) {
				if ( array_key_exists( $capability, $role->capabilities ) ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Run during plugin activation.
	 */
	public function activate(): void {
		$this->sync_roles();
	}

	/**
	 * Create or update the provider site owner role.
	 */
	private function ensure_provider_site_owner_role(): void {
		$admin_role = get_role( 'administrator' );

		if ( ! $admin_role instanceof WP_Role ) {
			return;
		}

		$site_owner_role = get_role( self::ROLE_PROVIDER_SITE_OWNER );

		if ( ! $site_owner_role instanceof WP_Role ) {
			add_role(
				self::ROLE_PROVIDER_SITE_OWNER,
				__( 'Site Owner', 'vk-booking-manager' ),
				$admin_role->capabilities
			);
			$site_owner_role = get_role( self::ROLE_PROVIDER_SITE_OWNER );
		}

		if ( ! $site_owner_role instanceof WP_Role ) {
			return;
		}

		foreach ( $admin_role->capabilities as $capability => $granted ) {
			if ( $granted && ! in_array( $capability, self::SITE_OWNER_RESTRICTED_CAPABLES, true ) ) {
				$site_owner_role->add_cap( $capability );
			} else {
				$site_owner_role->remove_cap( $capability );
			}
		}

		foreach ( $site_owner_role->capabilities as $cap => $enabled ) {
			// 数値文字列キーの capability は PHP の仕様で int にキャストされるため、string へ明示的に変換してから判定する。
			// Numeric string capability keys are auto-cast to int by PHP, so cast to string before checking.
			$cap = (string) $cap;
			if ( strpos( $cap, 'post_type_manage' ) !== false ) {
				$site_owner_role->remove_cap( $cap );
			}
		}

		foreach ( Capabilities::owner_caps() as $capability => $granted ) {
			if ( $granted ) {
				$site_owner_role->add_cap( $capability );
			}
		}
	}

	/**
	 * Create or update the provider owner role.
	 */
	private function ensure_provider_owner_role(): void {
		$admin_role = get_role( 'administrator' );

		if ( ! $admin_role instanceof WP_Role ) {
			return;
		}

		$owner_role = get_role( self::ROLE_PROVIDER_OWNER );

		if ( ! $owner_role instanceof WP_Role ) {
			add_role(
				self::ROLE_PROVIDER_OWNER,
				__( 'Business Owner', 'vk-booking-manager' ),
				$admin_role->capabilities
			);
			$owner_role = get_role( self::ROLE_PROVIDER_OWNER );
		}

		if ( ! $owner_role instanceof WP_Role ) {
			return;
		}

		$this->update_role_display_name( self::ROLE_PROVIDER_OWNER, __( 'Business Owner', 'vk-booking-manager' ) );

		foreach ( Capabilities::owner_caps() as $capability => $granted ) {
			if ( $granted ) {
				$owner_role->add_cap( $capability );
			} else {
				$owner_role->remove_cap( $capability );
			}
		}

		foreach ( self::SITE_OWNER_RESTRICTED_CAPABLES as $capability ) {
			$owner_role->remove_cap( $capability );
		}

		foreach ( self::OWNER_CONTENT_RESTRICTIONS as $capability ) {
			$owner_role->remove_cap( $capability );
		}

		foreach ( $owner_role->capabilities as $cap => $enabled ) {
			// 数値文字列キーの capability は PHP の仕様で int にキャストされるため、string へ明示的に変換してから判定する。
			// Numeric string capability keys are auto-cast to int by PHP, so cast to string before checking.
			$cap = (string) $cap;
			if ( strpos( $cap, 'post_type_manage' ) !== false ) {
				$owner_role->remove_cap( $cap );
			}
		}
	}

	/**
	 * 保存済みロールの表示名を更新する。
	 *
	 * add_role() は既存ロールの表示名を更新しないため、ロール情報を直接同期する。
	 *
	 * @param string $role_name    ロールキー。
	 * @param string $display_name 表示名。
	 */
	private function update_role_display_name( string $role_name, string $display_name ): void {
		$roles = wp_roles();

		if ( ! isset( $roles->roles[ $role_name ] ) || ( $roles->role_names[ $role_name ] ?? null ) === $display_name ) {
			return;
		}

		$roles->roles[ $role_name ]['name'] = $display_name;
		$roles->role_names[ $role_name ]    = $display_name;
		update_option( $roles->role_key, $roles->roles );
	}

	/**
	 * Ensure WordPress administrators retain plugin capabilities.
	 */
	private function ensure_administrator_capabilities(): void {
		$admin_role = get_role( 'administrator' );

		if ( ! $admin_role instanceof WP_Role ) {
			return;
		}

		foreach ( Capabilities::owner_caps() as $capability => $granted ) {
			if ( $granted ) {
				$admin_role->add_cap( $capability );
			}
		}
	}

	/**
	 * Ensure editors can view service menus (including private entries).
	 */
	private function ensure_editor_capabilities(): void {
		$editor_role = get_role( 'editor' );

		if ( ! $editor_role instanceof WP_Role ) {
			return;
		}

		$editor_role->add_cap( Capabilities::VIEW_SERVICE_MENUS );
	}
}
