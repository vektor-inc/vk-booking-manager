<?php

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

use VKBookingManager\Bookings\Customer_Name_Resolver;
use VKBookingManager\Capabilities\Capabilities;
use WP_User;

/**
 * Registers the Booking custom post type.
 */
class Booking_Post_Type {
	public const POST_TYPE = 'vkbm_booking';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'move_author_meta_box_to_side' ], 99 );
	}

	/**
	 * Register the booking custom post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$labels = [
			'name'                  => __( 'Reservation', 'vk-booking-manager' ),
			'singular_name'         => __( 'Reservation', 'vk-booking-manager' ),
			'menu_name'             => __( 'BM Reservation', 'vk-booking-manager' ),
			'name_admin_bar'        => __( 'Reservation', 'vk-booking-manager' ),
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			'add_new_item'          => __( 'Add reservation', 'vk-booking-manager' ),
			'edit_item'             => __( 'Edit reservation', 'vk-booking-manager' ),
			'new_item'              => __( 'New reservation', 'vk-booking-manager' ),
			'view_item'             => __( 'View reservation', 'vk-booking-manager' ),
			'search_items'          => __( 'Search for reservations', 'vk-booking-manager' ),
			'not_found'             => __( 'No reservations found.', 'vk-booking-manager' ),
			'not_found_in_trash'    => __( 'There are no reservations in the trash can.', 'vk-booking-manager' ),
			'all_items'             => __( 'All reservations', 'vk-booking-manager' ),
			'archives'              => __( 'Reservation archive', 'vk-booking-manager' ),
			'attributes'            => __( 'Reserved attributes', 'vk-booking-manager' ),
		];

			$args = [
				'labels'             => $labels,
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_admin_bar'  => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'supports'           => [ 'title', 'author', 'content' ],
				'has_archive'        => false,
				'hierarchical'       => false,
				'rewrite'            => false,
				'menu_position'      => 28,
				'menu_icon'          => 'dashicons-edit',
			'capabilities'       => $this->get_capabilities(),
			'map_meta_cap'       => false,
		];

			register_post_type( self::POST_TYPE, $args );
		}

		/**
		 * Move the core author meta box to the sidebar for booking posts.
		 *
		 * @param \WP_Post $post Current booking post.
		 */
	public function move_author_meta_box_to_side( \WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

			remove_meta_box( 'authordiv', self::POST_TYPE, 'normal' );
			remove_meta_box( 'authordiv', self::POST_TYPE, 'advanced' );

			add_meta_box(
				'authordiv',
				__( 'Posted by', 'vk-booking-manager' ),
				[ $this, 'render_author_meta_box' ],
				self::POST_TYPE,
				'side',
				'default'
			);
		}

	/**
	 * Render booking author selection with all users.
	 *
	 * @param \WP_Post $post Booking post.
	 */
	public function render_author_meta_box( \WP_Post $post ): void {
		$roles      = wp_roles();
		$role_names = $roles ? array_keys( $roles->roles ) : [];

		// Include every role so subscribers are selectable.
		// 購読者を含む全ロールを対象にする。
		$users = get_users(
			[
				'role__in'         => $role_names,
				'orderby'          => 'display_name',
				'order'            => 'ASC',
			]
		);

		$selected_author_id = (int) $post->post_author;
		$has_selected       = false;
		$name_resolver      = new Customer_Name_Resolver();

		echo '<select name="post_author_override" id="post_author_override">';

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$label = $this->resolve_author_label( $user, $name_resolver );
			$value = (int) $user->ID;
			if ( $value === $selected_author_id ) {
				$has_selected = true;
			}

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $selected_author_id, $value, false ),
				esc_html( $label )
			);
		}

		if ( $selected_author_id > 0 && ! $has_selected ) {
			$selected_user = get_user_by( 'id', $selected_author_id );
			if ( $selected_user instanceof WP_User ) {
				$label = $this->resolve_author_label( $selected_user, $name_resolver );
				printf(
					'<option value="%1$s" selected="selected">%2$s</option>',
					esc_attr( (string) $selected_author_id ),
					esc_html( $label )
				);
			}
		}

		echo '</select>';
	}

	/**
	 * Resolve the author label with name priority.
	 *
	 * @param WP_User               $user          User instance.
	 * @param Customer_Name_Resolver $name_resolver Name resolver.
	 * @return string
	 */
	private function resolve_author_label( WP_User $user, Customer_Name_Resolver $name_resolver ): string {
		// Prefer full name > kana > display name, and fall back to user ID.
		// 姓名 > ふりがな > 表示名 を優先し、なければユーザーIDにする。
		$label = trim( $name_resolver->resolve_for_user( $user ) );
		if ( '' !== $label ) {
			return $label;
		}

		return (string) $user->ID;
	}

	/**
	 * Capability map for the booking post type.
	 *
	 * @return array<string, string>
	 */
	private function get_capabilities(): array {
		return [
			'edit_post'              => Capabilities::MANAGE_RESERVATIONS,
			'read_post'              => Capabilities::VIEW_RESERVATIONS,
			'delete_post'            => Capabilities::MANAGE_RESERVATIONS,
			'edit_posts'             => Capabilities::MANAGE_RESERVATIONS,
			'edit_others_posts'      => Capabilities::MANAGE_RESERVATIONS,
			'publish_posts'          => Capabilities::MANAGE_RESERVATIONS,
			'read_private_posts'     => Capabilities::VIEW_RESERVATIONS,
			'delete_posts'           => Capabilities::MANAGE_RESERVATIONS,
			'delete_private_posts'   => Capabilities::MANAGE_RESERVATIONS,
			'delete_published_posts' => Capabilities::MANAGE_RESERVATIONS,
			'delete_others_posts'    => Capabilities::MANAGE_RESERVATIONS,
			'edit_private_posts'     => Capabilities::MANAGE_RESERVATIONS,
			'edit_published_posts'   => Capabilities::MANAGE_RESERVATIONS,
			'create_posts'           => Capabilities::MANAGE_RESERVATIONS,
		];
	}
}
