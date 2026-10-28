<?php
/**
 * Registers the Shift custom post type.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Industry_Presets;

/**
 * Registers the Shift custom post type.
 */
class Shift_Post_Type {
	public const POST_TYPE = 'vkbm_shift';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the shift post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$is_lodging = Industry_Presets::supports( 'lodging' );
		$labels     = array(
			'name'               => $is_lodging ? __( 'Days on Sale', 'vk-booking-manager' ) : __( 'Shift', 'vk-booking-manager' ),
			'singular_name'      => $is_lodging ? __( 'Day on Sale', 'vk-booking-manager' ) : __( 'Shift', 'vk-booking-manager' ),
			'menu_name'          => $is_lodging ? __( 'BM Days on Sale', 'vk-booking-manager' ) : __( 'BM Shift', 'vk-booking-manager' ),
			'name_admin_bar'     => $is_lodging ? __( 'Day on Sale', 'vk-booking-manager' ) : __( 'Shift', 'vk-booking-manager' ),
			'add_new'            => __( 'Add New', 'vk-booking-manager' ),
			'add_new_item'       => $is_lodging ? __( 'Add Day on Sale', 'vk-booking-manager' ) : __( 'Add Shift', 'vk-booking-manager' ),
			'edit_item'          => $is_lodging ? __( 'Edit Day on Sale', 'vk-booking-manager' ) : __( 'Edit Shift', 'vk-booking-manager' ),
			'new_item'           => $is_lodging ? __( 'New Day on Sale', 'vk-booking-manager' ) : __( 'New Shift', 'vk-booking-manager' ),
			'view_item'          => $is_lodging ? __( 'View Day on Sale', 'vk-booking-manager' ) : __( 'Show Shift', 'vk-booking-manager' ),
			'search_items'       => $is_lodging ? __( 'Search Days on Sale', 'vk-booking-manager' ) : __( 'Search Shift', 'vk-booking-manager' ),
			'not_found'          => $is_lodging ? __( 'No days on sale found.', 'vk-booking-manager' ) : __( 'Shift not found.', 'vk-booking-manager' ),
			'not_found_in_trash' => $is_lodging ? __( 'There are no days on sale in the trash.', 'vk-booking-manager' ) : __( 'There are no shifts in the trash.', 'vk-booking-manager' ),
			'all_items'          => $is_lodging ? __( 'All Days on Sale', 'vk-booking-manager' ) : __( 'All Shifts', 'vk-booking-manager' ),
			'archives'           => $is_lodging ? __( 'Days on Sale Archives', 'vk-booking-manager' ) : __( 'Shift Archive', 'vk-booking-manager' ),
			'attributes'         => $is_lodging ? __( 'Days on Sale Attributes', 'vk-booking-manager' ) : __( 'Shift Attribute', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_admin_bar' => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => false,
			'supports'          => array( 'title' ),
			'has_archive'       => false,
			'hierarchical'      => false,
			'rewrite'           => false,
			'menu_position'     => 27,
			'menu_icon'         => 'dashicons-calendar',
			'capabilities'      => $this->get_capabilities(),
			'map_meta_cap'      => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Capability map for the post type.
	 *
	 * @return array<string, string>
	 */
	private function get_capabilities(): array {
		return array(
			'edit_post'              => Capabilities::MANAGE_STAFF,
			'read_post'              => Capabilities::MANAGE_STAFF,
			'delete_post'            => Capabilities::MANAGE_STAFF,
			'edit_posts'             => Capabilities::MANAGE_STAFF,
			'edit_others_posts'      => Capabilities::MANAGE_STAFF,
			'publish_posts'          => Capabilities::MANAGE_STAFF,
			'read_private_posts'     => Capabilities::MANAGE_STAFF,
			'delete_posts'           => Capabilities::MANAGE_STAFF,
			'delete_private_posts'   => Capabilities::MANAGE_STAFF,
			'delete_published_posts' => Capabilities::MANAGE_STAFF,
			'delete_others_posts'    => Capabilities::MANAGE_STAFF,
			'edit_private_posts'     => Capabilities::MANAGE_STAFF,
			'edit_published_posts'   => Capabilities::MANAGE_STAFF,
			'create_posts'           => Capabilities::MANAGE_STAFF,
		);
	}
}
