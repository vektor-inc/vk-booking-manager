<?php

/**
 * Term order manager for custom taxonomies.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\TermOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use WP_Error;
use WP_Taxonomy;

use function __;
use function absint;
use function add_action;
use function add_filter;
use function admin_url;
use function array_shift;
use function array_values;
use function check_ajax_referer;
use function current_time;
use function current_user_can;
use function get_current_screen;
use function get_option;
use function get_taxonomy;
use function get_term_meta;
use function get_terms;
use function is_admin;
use function is_array;
use function is_wp_error;
use function plugins_url;
use function sanitize_key;
use function update_option;
use function update_term_meta;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_cache_delete;

/**
 * Provides drag-and-drop ordering for selected taxonomies.
 */
class Term_Order_Manager {
	private const AJAX_ACTION          = 'vkbm_update_term_order';
	private const ORDER_VERSION_OPTION = 'vkbm_term_order_version';

	public const META_KEY = 'vkbm_term_order';

	/**
	 * Target taxonomies.
	 *
	 * @var string[]
	 */
	private $taxonomies;

	/**
	 * Constructor.
	 *
	 * @param string[] $taxonomies Supported taxonomy slugs.
	 */
	public function __construct( array $taxonomies ) {
		$this->taxonomies = $taxonomies;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'created_term', array( $this, 'ensure_created_term_order' ), 10, 3 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_update_order' ) );
		add_action( 'pre_get_terms', array( $this, 'apply_default_order' ) );
	}

	/**
	 * Enqueue admin assets when viewing supported taxonomy list tables.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->taxonomy ) || ! in_array( $screen->taxonomy, $this->taxonomies, true ) ) {
			return;
		}

		if ( ! $this->current_user_can_manage_taxonomy( $screen->taxonomy ) ) {
			return;
		}

		$this->ensure_taxonomy_has_orders( $screen->taxonomy );

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'vkbm-term-order-admin',
			plugins_url( 'assets/js/term-order.js', VKBM_PLUGIN_FILE ),
			array( 'jquery', 'jquery-ui-sortable' ),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-term-order-admin',
			'vkbmTermOrder',
			array(
				'action'   => self::AJAX_ACTION,
				'taxonomy' => $screen->taxonomy,
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
	 * Apply default term ordering when viewing supported taxonomies.
	 *
	 * @param \WP_Term_Query $query Term query instance.
	 */
	public function apply_default_order( \WP_Term_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $query->query_vars['taxonomy'] ) ) {
			return;
		}

		$taxonomies = $query->query_vars['taxonomy'];
		$taxonomy   = is_array( $taxonomies ) ? (string) reset( $taxonomies ) : (string) $taxonomies;

		if ( '' === $taxonomy || ! in_array( $taxonomy, $this->taxonomies, true ) ) {
			return;
		}

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sorting override.
		if ( '' !== $orderby ) {
			return;
		}

		$this->ensure_taxonomy_has_orders( $taxonomy );

		$query->query_vars['meta_key'] = self::META_KEY;
		$query->query_vars['orderby']  = 'meta_value_num';
		$query->query_vars['order']    = 'ASC';
	}

	/**
	 * Ensure order meta exists for newly created terms.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id   Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function ensure_created_term_order( int $term_id, int $tt_id, string $taxonomy ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! in_array( $taxonomy, $this->taxonomies, true ) ) {
			return;
		}

		if ( '' !== (string) get_term_meta( $term_id, self::META_KEY, true ) ) {
			return;
		}

		$next = $this->get_next_order_value( $taxonomy );
		update_term_meta( $term_id, self::META_KEY, (string) $next );
		wp_cache_delete( $term_id, 'term_meta' );
	}

	/**
	 * Handle AJAX request to persist a new order.
	 */
	public function handle_update_order(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( '' === $taxonomy || ! in_array( $taxonomy, $this->taxonomies, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unsupported taxonomy.', 'vk-booking-manager' ),
				),
				400
			);
		}

		if ( ! $this->current_user_can_manage_taxonomy( $taxonomy ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this operation.', 'vk-booking-manager' ),
				),
				403
			);
		}

		$ordered_ids = $this->sanitize_ids(
			isset( $_POST['orderedIds'] ) ? (array) wp_unslash( $_POST['orderedIds'] ) : array()
		);

		if ( empty( $ordered_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No sorting targets found.', 'vk-booking-manager' ),
				),
				400
			);
		}

		$this->ensure_taxonomy_has_orders( $taxonomy );

		$result = $this->persist_order( $taxonomy, $ordered_ids );

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
	 * Persist a new order for a taxonomy.
	 *
	 * @param string $taxonomy    Target taxonomy.
	 * @param array  $ordered_ids Ordered IDs for the current view.
	 * @return int|WP_Error Updated term count or error.
	 */
	private function persist_order( string $taxonomy, array $ordered_ids ) {
		$all_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => self::META_KEY,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $all_ids ) || empty( $all_ids ) ) {
			return new WP_Error(
				'vkbm_term_order_empty',
				__( 'No sortable items found.', 'vk-booking-manager' )
			);
		}

		$subset_lookup = array_flip( $ordered_ids );
		$replacement   = $ordered_ids;

		foreach ( $all_ids as $index => $term_id ) {
			if ( isset( $subset_lookup[ $term_id ] ) ) {
				if ( empty( $replacement ) ) {
					break;
				}

				$all_ids[ $index ] = array_shift( $replacement );
			}
		}

		if ( ! empty( $replacement ) ) {
			return new WP_Error(
				'vkbm_term_order_missing_terms',
				__( 'Some items were not found. Please reload the page.', 'vk-booking-manager' )
			);
		}

		$updated_ids = array();

		foreach ( $all_ids as $position => $term_id ) {
			$new_order     = $position + 1;
			$current_order = (int) get_term_meta( (int) $term_id, self::META_KEY, true );

			if ( $current_order === $new_order ) {
				continue;
			}

			update_term_meta( (int) $term_id, self::META_KEY, (string) $new_order );
			wp_cache_delete( (int) $term_id, 'term_meta' );
			$updated_ids[] = (int) $term_id;
		}

		if ( ! empty( $updated_ids ) ) {
			update_option( self::ORDER_VERSION_OPTION, current_time( 'timestamp' ) );
		}

		return count( $updated_ids );
	}

	/**
	 * Ensure all terms in the taxonomy have an order meta value.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	private function ensure_taxonomy_has_orders( string $taxonomy ): void {
		global $wpdb;

		$max_value = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT MAX(CAST(tm.meta_value AS UNSIGNED))
				 FROM {$wpdb->termmeta} tm
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				 WHERE tm.meta_key = %s AND tt.taxonomy = %s",
				self::META_KEY,
				$taxonomy
			)
		);

		$missing_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT tt.term_id
				 FROM {$wpdb->term_taxonomy} tt
				 LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = %s
				 WHERE tt.taxonomy = %s AND tm.term_id IS NULL
				 ORDER BY tt.term_id ASC",
				self::META_KEY,
				$taxonomy
			)
		);

		if ( empty( $missing_ids ) ) {
			return;
		}

		foreach ( $missing_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id < 1 ) {
				continue;
			}

			++$max_value;
			update_term_meta( $term_id, self::META_KEY, (string) $max_value );
			wp_cache_delete( $term_id, 'term_meta' );
		}
	}

	/**
	 * Get next order value for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return int
	 */
	private function get_next_order_value( string $taxonomy ): int {
		global $wpdb;

		$max_value = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT MAX(CAST(tm.meta_value AS UNSIGNED))
				 FROM {$wpdb->termmeta} tm
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				 WHERE tm.meta_key = %s AND tt.taxonomy = %s",
				self::META_KEY,
				$taxonomy
			)
		);

		return max( 0, $max_value ) + 1;
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
	 * Determine whether the current user can manage terms for the taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private function current_user_can_manage_taxonomy( string $taxonomy ): bool {
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object instanceof WP_Taxonomy ) {
			return false;
		}

		$capability = $taxonomy_object->cap->manage_terms ?? 'manage_categories';

		return current_user_can( $capability );
	}
}
