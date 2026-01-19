<?php

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

use VKBookingManager\Capabilities\Capabilities;
use function vkbm_get_resource_label_plural;
use function vkbm_get_resource_label_singular;

/**
 * Registers the Resource (スタッフ) custom post type.
 */
class Resource_Post_Type {
	public const POST_TYPE = 'vkbm_resource';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	/**
	 * Register the resource post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$singular = vkbm_get_resource_label_singular();
		$plural   = vkbm_get_resource_label_plural();

		$labels = [
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => sprintf( 'BM %s', $plural ),
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			/* translators: %s: Resource label (singular). */
			'add_new_item'          => sprintf( __( 'Add %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'edit_item'             => sprintf( __( 'Edit %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'new_item'              => sprintf( __( 'new %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'view_item'             => sprintf( __( 'Show %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'search_items'          => sprintf( __( 'Search for %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'not_found'             => sprintf( __( '%s not found.', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'not_found_in_trash'    => sprintf( __( 'There is no %s in the trash.', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (plural). */
			'all_items'             => sprintf( __( 'All %s', 'vk-booking-manager' ), $plural ),
			/* translators: %s: Resource label (plural). */
			'archives'              => sprintf( __( '%s Archive', 'vk-booking-manager' ), $plural ),
			/* translators: %s: Resource label (singular). */
			'attributes'            => sprintf( __( '%s attribute', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'insert_into_item'      => sprintf( __( 'Insert into %s', 'vk-booking-manager' ), $singular ),
			/* translators: %s: Resource label (singular). */
			'uploaded_to_this_item' => sprintf( __( 'Upload to this %s', 'vk-booking-manager' ), $singular ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_admin_bar'  => false,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => true,
			'supports'           => [ 'title' ],
			'has_archive'        => false,
			'hierarchical'       => false,
			'rewrite'            => false,
			'menu_position'      => 26,
			'menu_icon'          => 'dashicons-groups',
			'capability_type'    => 'post',
			'capabilities'       => $this->get_capabilities(),
			'map_meta_cap'       => false,
		];

		$config = $this->get_config();
		if ( [] !== $config ) {
			$args = array_merge( $args, $config );
		}

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Load build-specific configuration overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function get_config(): array {
		$config_path = __DIR__ . '/resource-post-type-config.php';
		if ( ! file_exists( $config_path ) ) {
			return [];
		}

		$config = require $config_path;
		return is_array( $config ) ? $config : [];
	}

	/**
	 * Capabilities for the resource post type.
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
