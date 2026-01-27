<?php

/**
 * Hides WordPress admin menus that should not be visible to salon owners.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Roles_Manager;
use WP_User;
use function add_action;
use function admin_url;
use function in_array;
use function is_network_admin;
use function remove_menu_page;
use function wp_doing_ajax;
use function wp_get_current_user;
use function wp_safe_redirect;

/**
 * Hides WordPress admin menus that should not be visible to salon owners.
 */
class Owner_Admin_Menu_Filter {
	/**
	 * Remove menu pages that salon owners should not see.
	 */
	public function remove_restricted_menus(): void {
		$current_user = wp_get_current_user();

		if ( ! $this->is_owner( $current_user ) ) {
			return;
		}

		remove_menu_page( 'tools.php' );
		remove_menu_page( 'post_type_manage' );
		if ( $this->is_salon_owner( $current_user ) ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Redirect owners away from restricted admin pages.
	 */
	public function block_restricted_pages(): void {
		$current_user = wp_get_current_user();

		if ( ! $this->is_owner( $current_user ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.

		if ( 'post_type_manage' === $page ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Redirect salon owners to the shift dashboard instead of the default dashboard.
	 */
	public function redirect_salon_owner_dashboard(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$current_user = wp_get_current_user();

		if ( ! $this->is_salon_owner( $current_user ) ) {
			return;
		}

		if ( is_network_admin() ) {
			return;
		}

		global $pagenow;

		$get_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		if ( 'admin.php' === $pagenow && 'vkbm-shift-dashboard' === $get_page ) {
			return;
		}

		if ( 'index.php' !== $pagenow ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vkbm-shift-dashboard' ) );
		exit;
	}

	/**
	 * Check if the current user belongs to the salon owner role.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private function is_owner( WP_User $user ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if (
			in_array( Roles_Manager::ROLE_PROVIDER_OWNER, $user->roles, true ) ||
			in_array( Roles_Manager::ROLE_PROVIDER_SITE_OWNER, $user->roles, true )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'remove_restricted_menus' ), 20 );
		add_action( 'admin_init', array( $this, 'block_restricted_pages' ), 20 );
		add_action( 'admin_init', array( $this, 'redirect_salon_owner_dashboard' ), 5 );
	}

	/**
	 * Check if the current user has the salon owner role.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private function is_salon_owner( WP_User $user ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return in_array( Roles_Manager::ROLE_PROVIDER_OWNER, $user->roles, true );
	}
}
