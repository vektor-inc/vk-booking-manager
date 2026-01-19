<?php

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

use VKBookingManager\Capabilities\Capabilities;

/**
 * Registers the Shift custom post type.
 */
class Shift_Post_Type {
	public const POST_TYPE = 'vkbm_shift';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	/**
	 * Register the shift post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$labels = [
			'name'                  => __( 'shift', 'vk-booking-manager' ),
			'singular_name'         => __( 'shift', 'vk-booking-manager' ),
			'menu_name'             => __( 'BM shift', 'vk-booking-manager' ),
			'name_admin_bar'        => __( 'shift', 'vk-booking-manager' ),
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			'add_new_item'          => __( 'add shift', 'vk-booking-manager' ),
			'edit_item'             => __( 'Edit shift', 'vk-booking-manager' ),
			'new_item'              => __( 'new shift', 'vk-booking-manager' ),
			'view_item'             => __( 'show shift', 'vk-booking-manager' ),
			'search_items'          => __( 'search shift', 'vk-booking-manager' ),
			'not_found'             => __( 'Shift not found.', 'vk-booking-manager' ),
			'not_found_in_trash'    => __( 'There are no shifts in the trash.', 'vk-booking-manager' ),
			'all_items'             => __( 'all shifts', 'vk-booking-manager' ),
			'archives'              => __( 'shift archive', 'vk-booking-manager' ),
			'attributes'            => __( 'shift attribute', 'vk-booking-manager' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_admin_bar'  => false,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'supports'           => [ 'title' ],
			'has_archive'        => false,
			'hierarchical'       => false,
			'rewrite'            => false,
			'menu_position'      => 27,
			'menu_icon'          => 'dashicons-calendar',
			'capabilities'       => $this->get_capabilities(),
			'map_meta_cap'       => false,
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Capability map for the post type.
	 *
	 * @return array<string, string>
	 */
	private function get_capabilities(): array {
		return [
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
		];
	}
}
