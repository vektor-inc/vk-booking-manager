<?php
/**
 * Handles registering and maintaining custom roles and capabilities.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Capabilities;

use WP_Role;

/**
 * Handles registering and maintaining custom roles and capabilities.
 */
class Roles_Manager {
	public const ROLE_PROVIDER_SITE_OWNER = 'provider_site_owner';
	public const ROLE_PROVIDER_OWNER      = 'provider_owner';

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
	 * Register hooks to keep roles and capabilities in sync.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'sync_roles' ) );
	}

	/**
	 * Ensure roles and capabilities exist.
	 */
	public function sync_roles(): void {
		$this->ensure_provider_site_owner_role();
		$this->ensure_provider_owner_role();
		$this->ensure_administrator_capabilities();
		$this->ensure_editor_capabilities();
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
				__( 'Salon Owner', 'vk-booking-manager' ),
				$admin_role->capabilities
			);
			$owner_role = get_role( self::ROLE_PROVIDER_OWNER );
		}

		if ( ! $owner_role instanceof WP_Role ) {
			return;
		}

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
			if ( strpos( $cap, 'post_type_manage' ) !== false ) {
				$owner_role->remove_cap( $cap );
			}
		}
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
