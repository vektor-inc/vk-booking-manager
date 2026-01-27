<?php

/**
 * Post order manager for custom post types.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use WP_Error;
use WP_Post_Type;
use WP_Query;

use function __;
use function absint;
use function add_action;
use function admin_url;
use function array_intersect;
use function array_shift;
use function array_values;
use function check_ajax_referer;
use function clean_post_cache;
use function current_time;
use function current_user_can;
use function get_current_screen;
use function get_option;
use function get_post_field;
use function get_post_type_object;
use function is_array;
use function is_admin;
use function plugins_url;
use function register_rest_field;
use function sanitize_key;
use function update_option;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Provides drag-and-drop ordering for selected post types.
 */
class Post_Order_Manager {
	private const AJAX_ACTION          = 'vkbm_update_post_order';
	private const ORDER_VERSION_OPTION = 'vkbm_post_order_version';

	/**
	 * Target post types.
	 *
	 * @var string[]
	 */
	private $post_types;

	/**
	 * Constructor.
	 *
	 * @param string[] $post_types Supported post type slugs.
	 */
	public function __construct( array $post_types ) {
		$this->post_types = $post_types;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'apply_default_order' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_update_order' ) );
	}

	/**
	 * Force default ordering for supported post type queries.
	 *
	 * @param WP_Query $query Current query.
	 */
	public function apply_default_order( WP_Query $query ): void {
		if ( ! $this->should_force_order( $query ) ) {
			return;
		}

		$query->set(
			'orderby',
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			)
		);
		$query->set( 'order', 'ASC' );
	}

	/**
	 * Register REST field to expose menu order.
	 */
	public function register_rest_fields(): void {
		foreach ( $this->post_types as $post_type ) {
			register_rest_field(
				$post_type,
				'order',
				array(
					'get_callback' => static function ( array $object ): int {
						$post_id = isset( $object['id'] ) ? (int) $object['id'] : 0;
						return $post_id > 0 ? (int) get_post_field( 'menu_order', $post_id ) : 0;
					},
					'schema'       => array(
						'description' => __( 'Menu order', 'vk-booking-manager' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
				)
			);
		}
	}

	/**
	 * Enqueue admin assets when viewing supported list tables.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->post_types, true ) ) {
			return;
		}

		if ( ! $this->current_user_can_edit_post_type( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'vkbm-post-order-admin',
			plugins_url( 'assets/js/post-order.js', VKBM_PLUGIN_FILE ),
			array( 'jquery', 'jquery-ui-sortable' ),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-post-order-admin',
			'vkbmPostOrder',
			array(
				'action'   => self::AJAX_ACTION,
				'postType' => $screen->post_type,
				'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'i18n'     => array(
					'saving' => __( 'Saving sort order...', 'vk-booking-manager' ),
					'saved'  => __( 'Sort order saved', 'vk-booking-manager' ),
					'error'  => __( 'Saving failed. Please try again later.', 'vk-booking-manager' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX request to persist a new order.
	 */
	public function handle_update_order(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$post_type = isset( $_POST['postType'] ) ? sanitize_key( wp_unslash( $_POST['postType'] ) ) : '';
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unsupported post type.', 'vk-booking-manager' ),
				),
				400
			);
		}

		if ( ! $this->current_user_can_edit_post_type( $post_type ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this operation.', 'vk-booking-manager' ),
				),
				403
			);
		}

		$ordered_ids = $this->sanitize_ids(
			isset( $_POST['orderedIds'] ) ? (array) wp_unslash( $_POST['orderedIds'] ) : array() // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_ids() below.
		);

		if ( empty( $ordered_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No sorting targets found.', 'vk-booking-manager' ),
				),
				400
			);
		}

		$result = $this->persist_order( $post_type, $ordered_ids );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'updated' => $result,
				'version' => (int) get_option( self::ORDER_VERSION_OPTION, 0 ),
			)
		);
	}

	/**
	 * Persist a new order for a post type.
	 *
	 * @param string $post_type   Target post type.
	 * @param array  $ordered_ids Ordered IDs for the current view.
	 * @return int|WP_Error Updated post count or error.
	 */
	private function persist_order( string $post_type, array $ordered_ids ) {
		global $wpdb;

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$all_ids = $query->posts;

		if ( empty( $all_ids ) ) {
			return new WP_Error(
				'vkbm_post_order_empty',
				__( 'No sortable posts found.', 'vk-booking-manager' )
			);
		}

		$subset_lookup = array_flip( $ordered_ids );
		$replacement   = $ordered_ids;

		foreach ( $all_ids as $index => $post_id ) {
			if ( isset( $subset_lookup[ $post_id ] ) ) {
				if ( empty( $replacement ) ) {
					break;
				}

				$all_ids[ $index ] = array_shift( $replacement );
			}
		}

		if ( ! empty( $replacement ) ) {
			return new WP_Error(
				'vkbm_post_order_missing_posts',
				__( 'Some posts were not found. Please reload the page.', 'vk-booking-manager' )
			);
		}

		$updated_ids = array();

		foreach ( $all_ids as $position => $post_id ) {
			$new_order     = $position + 1;
			$current_order = (int) get_post_field( 'menu_order', $post_id );

			if ( $current_order === $new_order ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk update of menu_order requires direct query for performance.
			$updated = $wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $new_order ),
				array( 'ID' => $post_id ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error(
					'vkbm_post_order_db_error',
					__( 'An error occurred while saving the sort order.', 'vk-booking-manager' )
				);
			}

			$updated_ids[] = $post_id;
		}

		if ( ! empty( $updated_ids ) ) {
			clean_post_cache( $updated_ids );
			update_option( self::ORDER_VERSION_OPTION, current_time( 'timestamp' ) );
		}

		return count( $updated_ids );
	}

	/**
	 * Determine whether menu order should be enforced for the query.
	 *
	 * @param WP_Query $query Query to inspect.
	 * @return bool
	 */
	private function should_force_order( WP_Query $query ): bool {
		if ( is_admin() && ! $query->is_main_query() && ! $query->get( 'vkbm_force_menu_order' ) ) {
			return false;
		}

		if ( ! $this->is_supported_query( $query ) ) {
			return false;
		}

		$order_by = $query->get( 'orderby' );

		if ( is_array( $order_by ) ) {
			return false;
		}

		if ( empty( $order_by ) ) {
			return true;
		}

		$order_by = strtolower( (string) $order_by );

		return in_array( $order_by, array( 'date', 'post_date', 'post_date_gmt' ), true );
	}

	/**
	 * Check whether the query targets supported post types.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool
	 */
	private function is_supported_query( WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return false;
		}

		if ( is_array( $post_type ) ) {
			return ! empty( array_intersect( $this->post_types, $post_type ) );
		}

		return in_array( (string) $post_type, $this->post_types, true );
	}

	/**
	 * Sanitize an array of IDs.
	 *
	 * @param array $ids Raw IDs.
	 * @return int[]
	 */
	private function sanitize_ids( array $ids ): array {
		$sanitized = array();

		foreach ( $ids as $id ) {
			$value = absint( $id );

			if ( $value > 0 ) {
				$sanitized[] = $value;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Determine whether the current user can edit the given post type.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function current_user_can_edit_post_type( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$capability = $post_type_object->cap->edit_posts ?? 'edit_posts';

		return current_user_can( $capability );
	}
}
