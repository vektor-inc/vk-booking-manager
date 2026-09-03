<?php
/**
 * Registers and renders the service menu loop block.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Block;
use WP_Post;
use WP_Query;
use function current_user_can;
use function generate_block_asset_handle;
use function sanitize_key;
use function wp_set_script_translations;

/**
 * Registers and renders the service menu loop block.
 */
class Menu_Loop_Block {
	private const METADATA_PATH                    = 'build/blocks/menu-loop';
	public const REQUEST_KEY                       = 'vkbm_menu_search';
	private const META_USE_DETAIL_PAGE             = '_vkbm_use_detail_page';
	private const TERM_ORDER_META_KEY              = 'vkbm_term_order';
	private const TERM_GROUP_DISPLAY_MODE_META_KEY = 'vkbm_menu_group_display_mode';
	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Provider settings cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private $provider_settings = null;

	/**
	 * 予約ボタンの共通描画ヘルパー。
	 *
	 * @var Reservation_Button_Renderer
	 */
	private $reservation_button_renderer;

	/**
	 * Whether blocks are registered.
	 *
	 * @var bool
	 */
	private static bool $block_registered = false;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null         $settings_repository         Provider settings repository.
	 * @param Reservation_Button_Renderer|null $reservation_button_renderer 予約ボタンの共通描画ヘルパー。
	 */
	public function __construct( ?Settings_Repository $settings_repository = null, ?Reservation_Button_Renderer $reservation_button_renderer = null ) {
		$this->settings_repository         = $settings_repository ?? new Settings_Repository();
		$this->reservation_button_renderer = $reservation_button_renderer ?? new Reservation_Button_Renderer( $this->settings_repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register block metadata.
	 */
	public function register_block(): void {
		// Prevent duplicate registration in test environments.
		if ( self::$block_registered ) {
			return;
		}

		$metadata_path = VKBM_PLUGIN_DIR_PATH . self::METADATA_PATH;

		register_block_type_from_metadata(
			$metadata_path,
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
		$this->register_script_translations( 'vk-booking-manager/menu-loop', array( 'editorScript' ) );

		self::$block_registered = true;
	}

	/**
	 * Render the loop output.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Saved content (unused).
	 * @param WP_Block|null       $block      Block instance (unused).
	 *
	 * @return string
	 */
	public function render_block( array $attributes, string $content = '', ?WP_Block $block = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$loop_id = $this->sanitize_identifier( $attributes['loopId'] ?? '' );
		if ( '' === $loop_id ) {
			return $this->render_notice( __( 'Menu loop ID has not been set. Please check your ID.', 'vk-booking-manager' ) );
		}

		$query = new WP_Query( $this->build_query_args( $attributes, $loop_id ) );

		if ( ! $query->have_posts() ) {
			$message = trim( (string) ( $attributes['emptyMessage'] ?? '' ) );
			if ( '' === $message ) {
				$message = __( 'No service menus matching the criteria were found.', 'vk-booking-manager' );
			}

			return $this->render_empty_state( $message, $loop_id, $attributes );
		}

		$posts = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}
		wp_reset_postdata();

		$style_attr   = $this->build_wrapper_style( $attributes );
		$mode         = $this->normalize_display_mode( (string) ( $attributes['displayMode'] ?? 'card' ) );
		$items_markup = $this->render_grouped_items( $posts, $attributes );

		return sprintf(
			'<div class="vkbm-menu-loop vkbm-menu-loop--mode-%4$s wp-block-vk-booking-manager-menu-loop" data-loop-id="%1$s"%2$s><div class="vkbm-menu-loop__list vkbm-menu-loop__list--%4$s">%3$s</div></div>',
			esc_attr( $loop_id ),
			$style_attr,
			$items_markup,
			esc_attr( $mode )
		);
	}

	/**
	 * Register block script translations from the plugin languages directory.
	 *
	 * プラグインの languages ディレクトリからブロックスクリプトの翻訳を登録します。
	 *
	 * @param string            $block_name Block name.
	 * @param array<int,string> $fields Script fields.
	 * @return void
	 */
	private function register_script_translations( string $block_name, array $fields ): void {
		if ( ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}

		$translation_path = VKBM_PLUGIN_DIR_PATH . 'languages';

		foreach ( $fields as $field ) {
			$handle = $this->resolve_script_handle( $block_name, $field );
			if ( $handle ) {
				wp_set_script_translations( $handle, 'vk-booking-manager', $translation_path );
			}
		}
	}

	/**
	 * Resolve a block script handle without relying on core helpers.
	 *
	 * コアのヘルパーに依存せずブロックスクリプトのハンドルを解決します。
	 *
	 * @param string $block_name Block name.
	 * @param string $field Script field.
	 * @return string
	 */
	private function resolve_script_handle( string $block_name, string $field ): string {
		if ( function_exists( 'generate_block_asset_handle' ) ) {
			return (string) generate_block_asset_handle( $block_name, $field );
		}

		$base = str_replace( '/', '-', $block_name );

		switch ( $field ) {
			case 'editorScript':
				return $base . '-editor-script';
			case 'viewScript':
				return $base . '-view-script';
			case 'script':
				return $base . '-script';
			default:
				return $base . '-' . sanitize_key( $field );
		}
	}

	/**
	 * Render items grouped by the service menu group taxonomy.
	 *
	 * @param array<int,WP_Post>  $posts      Posts.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render_grouped_items( array $posts, array $attributes ): string {
		$term_groups = array();
		$ungrouped   = array();

		foreach ( $posts as $post ) {
			$terms = get_the_terms( $post, Service_Menu_Post_Type::TAXONOMY_GROUP );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				$ungrouped[] = $post;
				continue;
			}

			$term = $this->pick_primary_term_by_order( $terms );
			if ( ! isset( $term_groups[ $term->term_id ] ) ) {
				$term_groups[ $term->term_id ] = array(
					'term'  => $term,
					'posts' => array(),
				);
			}

			$term_groups[ $term->term_id ]['posts'][] = $post;
		}

		$sort_posts = static function ( array $group_posts ): array {
			usort(
				$group_posts,
				static function ( WP_Post $a, WP_Post $b ): int {
					$order = (int) $a->menu_order <=> (int) $b->menu_order;
					if ( 0 !== $order ) {
						return $order;
					}

					return strcmp( (string) $a->post_title, (string) $b->post_title );
				}
			);
			return $group_posts;
		};

		$sections = array();

		if ( ! empty( $term_groups ) ) {
			$terms = array_map(
				static function ( array $group ): object {
					return $group['term'];
				},
				$term_groups
			);

			usort(
				$terms,
				function ( $a, $b ): int {
					$order_a = $this->get_term_order_value( (int) $a->term_id );
					$order_b = $this->get_term_order_value( (int) $b->term_id );

					if ( $order_a !== $order_b ) {
						return $order_a <=> $order_b;
					}

					return strcmp( (string) $a->name, (string) $b->name );
				}
			);

			foreach ( $terms as $term ) {
				$group_posts = $sort_posts( $term_groups[ $term->term_id ]['posts'] );
				$group_mode  = $this->resolve_group_display_mode( $attributes, (int) $term->term_id );
				$sections[]  = $this->render_group_section( $term->name, $group_posts, $attributes, (int) $term->term_id, $group_mode );
			}
		}

		if ( ! empty( $ungrouped ) ) {
			$ungrouped = $sort_posts( $ungrouped );

			if ( empty( $sections ) ) {
				$group_mode = $this->resolve_group_display_mode( $attributes, null );
				$sections[] = $this->render_group_section( '', $ungrouped, $attributes, null, $group_mode );
			} else {
				$group_mode = $this->resolve_group_display_mode( $attributes, null );
				$sections[] = $this->render_group_section( __( 'Others', 'vk-booking-manager' ), $ungrouped, $attributes, null, $group_mode );
			}
		}

		return implode( '', $sections );
	}

	/**
	 * Pick primary term based on stored order (fallback: name).
	 *
	 * @param array<int,mixed> $terms Term list.
	 * @return object
	 */
	private function pick_primary_term_by_order( array $terms ): object {
		usort(
			$terms,
			function ( $a, $b ): int {
				$order_a = $this->get_term_order_value( (int) $a->term_id );
				$order_b = $this->get_term_order_value( (int) $b->term_id );

				if ( $order_a !== $order_b ) {
					return $order_a <=> $order_b;
				}

				return strcmp( (string) $a->name, (string) $b->name );
			}
		);

		return $terms[0];
	}

	/**
	 * Get term order value (smaller comes first).
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	private function get_term_order_value( int $term_id ): int {
		$value = (string) get_term_meta( $term_id, self::TERM_ORDER_META_KEY, true );
		$value = trim( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return PHP_INT_MAX;
		}

		return (int) $value;
	}

	/**
	 * Render a single group section.
	 *
	 * @param string              $title      Group title.
	 * @param array<int,WP_Post>  $posts      Group posts.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param int|null            $term_id    Term ID.
	 * @param string              $group_mode Group display mode.
	 * @return string
	 */
	private function render_group_section( string $title, array $posts, array $attributes, ?int $term_id, string $group_mode ): string {
		$title = trim( $title );

		$title_markup = '';
		if ( '' !== $title && empty( $attributes['hideGroupTitle'] ) ) {
			$title_markup = sprintf(
				'<h3 class="vkbm-menu-loop__group-title">%s</h3>',
				esc_html( $title )
			);
		}

		$group_attributes                = $attributes;
		$group_attributes['displayMode'] = $group_mode;

		$items_markup = implode(
			'',
			array_map(
				function ( WP_Post $post ) use ( $group_attributes ): string {
					return $this->render_item( $post, $group_attributes );
				},
				$posts
			)
		);

		$term_attr        = null !== $term_id ? sprintf( ' data-term-id="%s"', esc_attr( (string) $term_id ) ) : '';
		$group_mode_class = sprintf( ' vkbm-menu-loop__group--mode-%s', esc_attr( $group_mode ) );

		return sprintf(
			'<section class="vkbm-menu-loop__group%4$s"%1$s>%2$s<div class="vkbm-menu-loop__list vkbm-menu-loop__list--%5$s">%3$s</div></section>',
			$term_attr,
			$title_markup,
			$items_markup,
			$group_mode_class,
			esc_attr( $group_mode )
		);
	}

	/**
	 * Resolve display mode for a group when the loop shows all groups.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param int|null            $term_id    Term ID.
	 * @return string
	 */
	private function resolve_group_display_mode( array $attributes, ?int $term_id ): string {
		$global_mode       = $this->normalize_display_mode( (string) ( $attributes['displayMode'] ?? 'card' ) );
		$group_filter_mode = (string) ( $attributes['groupFilterMode'] ?? 'all' );

		if ( null === $term_id || 'all' !== $group_filter_mode ) {
			return $global_mode;
		}

		$value = (string) get_term_meta( $term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY, true );
		$value = strtolower( trim( $value ) );

		if ( '' === $value || 'inherit' === $value ) {
			return $global_mode;
		}

		return $this->normalize_display_mode( $value );
	}

	/**
	 * Build query args for the loop.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @param string              $loop_id    Loop identifier.
	 * @return array<string,mixed>
	 */
	private function build_query_args( array $attributes, string $loop_id ): array {
		$order    = $this->normalize_order( (string) ( $attributes['order'] ?? 'ASC' ) );
		$order_by = $this->normalize_order_by( (string) ( $attributes['orderBy'] ?? 'menu_order' ) );
		$filters  = $this->get_filters_from_request( $loop_id );

		$meta_query = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_vkbm_is_archived',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_vkbm_is_archived',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		if ( $filters['staff'] > 0 ) {
			$meta_query[] = array(
				'key'     => '_vkbm_staff_ids',
				'value'   => sprintf( 'i:%d;', $filters['staff'] ),
				'compare' => 'LIKE',
			);
		}

		$args = array(
			'post_type'           => Service_Menu_Post_Type::POST_TYPE,
			'post_status'         => $this->get_menu_post_statuses(),
			'posts_per_page'      => -1,
			'orderby'             => 'menu_order' === $order_by
				? array(
					'menu_order' => $order,
					'title'      => 'ASC',
				)
				: $order_by,
			'order'               => $order,
			'ignore_sticky_posts' => true,
			'meta_query'          => $meta_query,
		);

		$tax_query = array();

		if ( '' !== $filters['keyword'] ) {
			$args['s'] = $filters['keyword'];
		}

		if ( $filters['category'] > 0 ) {
			$tax_query[] = array(
				'taxonomy' => Service_Menu_Post_Type::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $filters['category'],
			);
		}

		$group_mode = (string) ( $attributes['groupFilterMode'] ?? 'all' );
		$group_ids  = $attributes['selectedGroupIds'] ?? array();
		if ( 'selected' === $group_mode && is_array( $group_ids ) ) {
			$group_ids = array_values(
				array_filter(
					array_map( 'intval', $group_ids ),
					static function ( int $group_id ): bool {
						return $group_id > 0;
					}
				)
			);

			if ( ! empty( $group_ids ) ) {
				$tax_query[] = array(
					'taxonomy' => Service_Menu_Post_Type::TAXONOMY_GROUP,
					'field'    => 'term_id',
					'terms'    => $group_ids,
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = count( $tax_query ) > 1
				? array_merge( array( 'relation' => 'AND' ), $tax_query )
				: $tax_query;
		}

		return $args;
	}

	/**
	 * Normalize display mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private function normalize_display_mode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );

		if ( in_array( $mode, array( 'card', 'text' ), true ) ) {
			return $mode;
		}

		return 'card';
	}

	/**
	 * Build wrapper style attribute.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	private function build_wrapper_style( array $attributes ): string {
		$styles = array(
			'--vkbm-menu-loop-gap:1.5rem',
		);

		return $styles ? ' style="' . esc_attr( implode( ';', $styles ) ) . '"' : '';
	}

	/**
	 * Render single menu card.
	 *
	 * @param WP_Post             $post       Post object.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render_item( WP_Post $post, array $attributes ): string {
		$mode = $this->normalize_display_mode( (string) ( $attributes['displayMode'] ?? 'card' ) );
		if ( 'text' === $mode ) {
			return $this->render_text_item( $post, $attributes );
		}

		$parts = array();

		if ( ! empty( $attributes['showImage'] ) && VKBM_Helper::has_thumbnail( $post, 'direct' ) ) {
			$parts[] = sprintf(
				'<div class="vkbm-menu-loop__card-media">%s</div>',
				VKBM_Helper::get_thumbnail_html( $post, 'large', 'direct' )
			);
		}

		$body_markup = $this->render_card_body( $post, $attributes );

		$parts[] = sprintf( '<div class="vkbm-menu-loop__card-body">%s</div>', $body_markup );

		return sprintf(
			'<article class="vkbm-menu-loop__item vkbm-menu-loop__card-item" data-menu-id="%1$d">%2$s</article>',
			(int) $post->ID,
			implode( '', $parts )
		);
	}

	/**
	 * Render a minimal text row item.
	 *
	 * @param WP_Post             $post       Post object.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render_text_item( WP_Post $post, array $attributes ): string {
		$segments = array();

		$title_text = esc_html( get_the_title( $post ) );
		$edit_link  = $this->get_menu_edit_link_markup( $post );

		$use_detail_page = '1' === (string) get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );
		if ( $use_detail_page ) {
			$title_markup = sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_permalink( $post ) ), $title_text );
		} else {
			$title_markup = $title_text;
		}

		if ( '' !== $edit_link ) {
			$title_markup .= ' ' . $edit_link;
		}

		$segments[] = sprintf( '<div class="vkbm-menu-loop__text-title">%s</div>', wp_kses_post( $title_markup ) );

		$price_markup = '';
		$price        = get_post_meta( $post->ID, '_vkbm_base_price', true );
		if ( is_numeric( $price ) && (int) $price >= 0 ) {
			$price_markup = sprintf(
				'<div class="vkbm-menu-loop__text-price">%s</div>',
				wp_kses_post( $this->format_price_display( (int) $price ) )
			);
		}

		$actions = $this->render_actions(
			$post,
			array_merge(
				$attributes,
				array(
					'showDetailButton' => false,
				)
			)
		);
		if ( '' !== $actions ) {
			$actions_markup = $actions;
		} else {
			$actions_markup = '';
		}

		$trailing_markup = implode( '', array_filter( array( $price_markup, $actions_markup ) ) );
		if ( '' !== $trailing_markup ) {
			$segments[] = sprintf( '<div class="vkbm-menu-loop__text-trailing">%s</div>', $trailing_markup );
		}

		return sprintf(
			'<div class="vkbm-menu-loop__item vkbm-menu-loop__text-item" data-menu-id="%1$d"><div class="vkbm-menu-loop__text-row">%2$s</div></div>',
			(int) $post->ID,
			implode( '', $segments )
		);
	}

	/**
	 * Render a standalone menu card for the specified menu.
	 *
	 * @param int                 $menu_id    Menu post ID.
	 * @param array<string,mixed> $overrides  Attribute overrides.
	 * @return string
	 */
	public function render_menu_card( int $menu_id, array $overrides = array() ): string {
		$post = get_post( $menu_id );

		if ( ! $post instanceof WP_Post || Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return '';
		}

		if ( 'private' === $post->post_status && ! current_user_can( Capabilities::VIEW_SERVICE_MENUS ) ) {
			return '';
		}

		// 公開ステータスのホワイトリスト。draft / pending / future / trash / auto-draft などを描画しない。
		// selectedMenuId 等で非公開ステータスのサービスを手動指定された場合に、
		// 下書きやゴミ箱の内容が公開フロントへ描画されるのを防ぐ。
		// （private は直前の権限チェックを通過したもののみ残る。）
		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return '';
		}

		$attributes = array_merge(
			$this->get_default_attributes(),
			$overrides
		);

		return $this->render_item( $post, $attributes );
	}

	/**
	 * Render a selection list for the reservation page.
	 *
	 * @return string
	 */
	public function render_menu_selection_list(): string {
		$query = new WP_Query(
			array(
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'post_status'         => $this->get_menu_post_statuses(),
				'posts_per_page'      => -1,
				'orderby'             => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'meta_query'          => array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vkbm_is_archived',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_vkbm_is_archived',
							'value'   => '1',
							'compare' => '!=',
						),
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return '';
		}

		$posts = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}
		wp_reset_postdata();

		$attributes = array_merge(
			$this->get_default_attributes(),
			array(
				'showDetailButton'  => true,
				'showReserveButton' => true,
				'disableTitleLink'  => true,
			)
		);

		$provider_settings         = $this->get_provider_settings();
		$mode                      = $this->normalize_display_mode(
			(string) ( $provider_settings['reservation_menu_list_display_mode'] ?? 'card' )
		);
		$attributes['displayMode'] = $mode;

		$items_markup = $this->render_grouped_items( $posts, $attributes );

		return sprintf(
			'<div class="vkbm-menu-loop vkbm-menu-loop--selection vkbm-menu-loop--mode-%2$s"><div class="vkbm-menu-loop__list vkbm-menu-loop__list--%2$s">%1$s</div></div>',
			$items_markup,
			esc_attr( $mode )
		);
	}

	/**
	 * Determine which post statuses are visible in menu lists.
	 *
	 * @return array<int, string>
	 */
	private function get_menu_post_statuses(): array {
		if ( current_user_can( Capabilities::VIEW_SERVICE_MENUS ) ) {
			return array( 'publish', 'private' );
		}

		return array( 'publish' );
	}

	/**
	 * Build card body markup.
	 *
	 * @param WP_Post             $post       Post object.
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	private function render_card_body( WP_Post $post, array $attributes ): string {
		$segments  = array();
		$edit_link = $this->get_menu_edit_link_markup( $post );

		if ( ! empty( $attributes['showCategories'] ) ) {
			$segments[] = $this->render_categories( $post );
		}

		$use_detail_page = '1' === (string) get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );
		if ( ! empty( $attributes['disableTitleLink'] ) || ! $use_detail_page ) {
			$title_markup = esc_html( get_the_title( $post ) );
		} else {
			$title_markup = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( get_permalink( $post ) ),
				esc_html( get_the_title( $post ) )
			);
		}

		if ( '' !== $edit_link ) {
			$title_markup .= ' ' . $edit_link;
		}

		$segments[] = sprintf(
			'<h3 class="vkbm-menu-loop__card-title">%s</h3>',
			wp_kses_post( $title_markup )
		);

		$catch_copy = get_post_meta( $post->ID, '_vkbm_catch_copy', true );
		if ( '' !== trim( (string) $catch_copy ) ) {
			$segments[] = sprintf(
				'<p class="vkbm-menu-loop__card-catch">%s</p>',
				esc_html( $catch_copy )
			);
		}

		if ( ! empty( $attributes['showExcerpt'] ) ) {
			$excerpt = get_the_excerpt( $post );
			if ( '' !== trim( (string) $excerpt ) ) {
				$segments[] = sprintf(
					'<div class="vkbm-menu-loop__card-excerpt">%s</div>',
					wp_kses_post( wpautop( $excerpt ) )
				);
			}
		}

		// アクションボタンをメタ情報の表示有無と独立して扱うかどうか。
		// menu-card ブロックのみ true を渡す。menu-loop は未指定（既定 false）のため従来挙動を完全維持する。
		$actions_independent_of_meta = ! empty( $attributes['actionsIndependentOfMeta'] );

		if ( $actions_independent_of_meta ) {
			// menu-card 専用パス: アクションボタンは showMeta と独立して showReserveButton 等に従う。
			$actions_markup = $this->render_actions( $post, $attributes );

			if ( ! empty( $attributes['showMeta'] ) ) {
				// メタ情報表示時は、従来どおりメタ（dl・料金）とアクションを同じラッパ内にまとめる。
				$meta_markup = $this->render_meta_information( $post, $attributes, $actions_markup );
				if ( '' !== $meta_markup ) {
					$segments[] = $meta_markup;
				}
			} elseif ( '' !== $actions_markup ) {
				// メタ情報非表示時でも、アクションボタンのみを同等のラッパ構造で出力する。
				$segments[] = sprintf(
					'<div class="vkbm-menu-loop__card-meta-wrap"><div class="vkbm-menu-loop__card-meta-side">%s</div></div>',
					$actions_markup
				);
			}
		} elseif ( ! empty( $attributes['showMeta'] ) ) {
			// menu-loop 従来パス: メタ情報・料金・アクションをまとめて showMeta で出し分ける（既存挙動）。
			$meta_markup = $this->render_meta_information( $post, $attributes );
			if ( '' !== $meta_markup ) {
				$segments[] = $meta_markup;
			}
		}

		return implode( '', array_filter( $segments ) );
	}

	/**
	 * Render meta information markup.
	 *
	 * @param WP_Post             $post           Post object.
	 * @param array<string,mixed> $attributes     Attributes.
	 * @param string|null         $actions_markup 事前生成済みのアクションボタンHTML。null の場合はこのメソッド内で生成する。
	 * @return string
	 */
	private function render_meta_information( WP_Post $post, array $attributes, ?string $actions_markup = null ): string {
		$duration             = get_post_meta( $post->ID, '_vkbm_duration_minutes', true );
		$price                = get_post_meta( $post->ID, '_vkbm_base_price', true );
		$reservation_day_type = (string) get_post_meta( $post->ID, '_vkbm_reservation_day_type', true );
		$other_conditions     = trim( (string) get_post_meta( $post->ID, '_vkbm_other_conditions', true ) );
		$staff_ids            = get_post_meta( $post->ID, '_vkbm_staff_ids', true );
		$staff_ids            = is_array( $staff_ids ) ? array_map( 'intval', $staff_ids ) : array();
		$staff_ids            = array_values(
			array_filter(
				$staff_ids,
				static function ( int $staff_id ): bool {
					return $staff_id > 0;
				}
			)
		);

		$items          = array();
		$price_markup   = '';
		$resource_label = $this->get_resource_label_menu();

		if ( is_numeric( $duration ) && (int) $duration > 0 ) {
			$duration_value = sprintf(
				/* translators: %d: duration in minutes */
				__( '%d minutes', 'vk-booking-manager' ),
				(int) $duration
			);
			// Use the shared helper for the configurable duration label.
			// 共通ヘルパーを使用して所要時間ラベルを取得します。
			$duration_heading = vkbm_get_duration_label();
			$items[]          = sprintf(
				'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html( $duration_heading ),
				esc_html( $duration_value )
			);
		}

		// 固定の開始時間（_vkbm_fixed_start_times）が設定されていれば表示する。
		// 管理画面「Fixed start times」で入力された HH:MM 形式の文字列配列を想定する。
		$start_times = get_post_meta( $post->ID, '_vkbm_fixed_start_times', true );
		$start_times = is_array( $start_times ) ? $start_times : array();
		// 各要素を文字列化・トリムし、空文字を除外して詰め直す（保存順は維持する）。
		$start_times = array_values(
			array_filter(
				array_map(
					static function ( $start_time ): string {
						return trim( (string) $start_time );
					},
					$start_times
				),
				static function ( string $start_time ): bool {
					return '' !== $start_time;
				}
			)
		);

		// 1件以上ある場合のみ開始時間の項目を追加する（自由予約メニューでは出さない）。
		if ( ! empty( $start_times ) ) {
			// 各時刻を個別にエスケープする。
			$escaped_times = array_map( 'esc_html', $start_times );
			// 区切りの直前をノーブレークスペースで固定し、狭幅で折り返した際に
			// 記号「/」だけが次行の行頭へ孤立しないようにする（改行は「/ 」の後でのみ起こる）。
			// 各時刻は esc_html 済み・区切りは固定リテラルのため、dd へはそのまま出力する。
			$start_times_markup = implode( '&nbsp;/ ', $escaped_times );
			$items[]            = sprintf(
				'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html__( 'Start time', 'vk-booking-manager' ),
				$start_times_markup
			);
		}

		// 指名機能が無効の場合、担当可能スタッフの表示をスキップする。
		// Skip staff display when nomination feature is disabled.
		if ( ! empty( $staff_ids ) && Staff_Editor::is_nomination_enabled() ) {
			$staff_posts = get_posts(
				array(
					'post_type'      => Resource_Post_Type::POST_TYPE,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'include'        => $staff_ids,
				)
			);

			$names = array_values(
				array_filter(
					array_map(
						static function ( WP_Post $staff_post ): string {
							return get_the_title( $staff_post );
						},
						array_filter(
							$staff_posts,
							static function ( $staff_post ): bool {
								return $staff_post instanceof WP_Post;
							}
						)
					),
					static function ( string $name ): bool {
						return '' !== $name;
					}
				)
			);

			if ( ! empty( $names ) ) {
				$items[] = sprintf(
					'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
					esc_html( $resource_label ),
					esc_html( implode( ', ', $names ) )
				);
			}
		}

		if ( '' !== $reservation_day_type ) {
			// 種別→ラベルの写像は共有ヘルパーに集約している。
			$reservation_day_label = Reservation_Day::label( $reservation_day_type );

			if ( '' !== $reservation_day_label ) {
				$items[] = sprintf(
					'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
					esc_html__( 'Reservation date', 'vk-booking-manager' ),
					esc_html( $reservation_day_label )
				);
			}
		}

		if ( '' !== $other_conditions ) {
			$items[] = sprintf(
				'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html( vkbm_get_other_conditions_label() ),
				wp_kses_post( nl2br( esc_html( $other_conditions ) ) )
			);
		}

		// 予約枠の定員（_vkbm_max_capacity）を表示する。
		// 1枠あたりの最大予約受付数が 2 以上の複数人前提のメニューのときだけ、
		// 最少催行人数の直前にメタ項目を追加する。max が 1 または未設定（実質 1）の
		// 単独予約前提のメニューでは従来どおり表示しない。
		$max_capacity = (int) get_post_meta( $post->ID, '_vkbm_max_capacity', true );
		$min_capacity = (int) get_post_meta( $post->ID, '_vkbm_min_capacity', true );
		if ( $max_capacity >= 2 ) {
			// 値は数量整形ヘルパーで「整数＋単位」（例: 6名 / 6 guests）に揃える。
			$items[] = sprintf(
				'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html__( 'Time slot capacity', 'vk-booking-manager' ),
				esc_html( vkbm_format_guests_count( $max_capacity ) )
			);
		}

		// 最少催行人数（_vkbm_min_capacity）を表示する。
		// 1枠あたりの最大予約受付数（_vkbm_max_capacity）が 2 以上、かつ最少催行人数が 2 以上の
		// 複数人前提のメニューのときだけ、定員の直後に項目を追加する。
		// max が 1 または未設定（実質 1）、min が 0/1（制約なし or 単独でも催行）の場合は従来どおり表示しない。
		if ( $max_capacity >= 2 && $min_capacity >= 2 ) {
			// 値は数量整形ヘルパーで「整数＋単位」（例: 2名 / 2 guests）に揃える。
			$items[] = sprintf(
				'<div class="vkbm-menu-loop__card-meta-item"><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html__( 'Minimum participants to confirm', 'vk-booking-manager' ),
				esc_html( vkbm_format_guests_count( $min_capacity ) )
			);
		}

		// 料金区分（_vkbm_price_tiers）が設定されていれば、単一料金ではなく区分一覧を表示する（排他）。
		// 区分が無い場合のみ従来どおり基本料金の単一表示にフォールバックする。
		$price_tiers_raw = get_post_meta( $post->ID, '_vkbm_price_tiers', true );
		if ( Price_Tiers::has_tiers( $price_tiers_raw ) ) {
			$price_markup = $this->render_price_tiers( Price_Tiers::normalize_tiers( $price_tiers_raw ) );
		} elseif ( is_numeric( $price ) && (int) $price >= 0 ) {
			$price_markup = sprintf(
				'<div class="vkbm-menu-loop__card-price">%s</div>',
				wp_kses_post( $this->format_price_display( (int) $price ) )
			);
		}

		$meta_markup = '';
		if ( ! empty( $items ) ) {
			$meta_markup = sprintf(
				'<dl class="vkbm-menu-loop__card-meta">%s</dl>',
				implode( '', $items )
			);
		}

		// 呼び出し元から渡されていればそれを使う（render_card_body 側で生成済み）。
		// 渡されていない場合のみここで生成する（後方互換）。
		if ( null === $actions_markup ) {
			$actions_markup = $this->render_actions( $post, $attributes );
		}

		if ( '' === $meta_markup && '' === $price_markup && '' === $actions_markup ) {
			return '';
		}

		$side_markup = implode( '', array_filter( array( $price_markup, $actions_markup ) ) );
		if ( '' !== $side_markup ) {
			$side_markup = sprintf( '<div class="vkbm-menu-loop__card-meta-side">%s</div>', $side_markup );
		}

		return sprintf(
			'<div class="vkbm-menu-loop__card-meta-wrap"><div class="vkbm-menu-loop__card-meta-main">%1$s</div>%2$s</div>',
			$meta_markup,
			$side_markup
		);
	}

	/**
	 * Render action buttons.
	 *
	 * @param WP_Post             $post       Post object.
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	private function render_actions( WP_Post $post, array $attributes ): string {
		$show_detail  = array_key_exists( 'showDetailButton', $attributes ) ? (bool) $attributes['showDetailButton'] : true;
		$show_reserve = ! empty( $attributes['showReserveButton'] );

		$buttons = array();

		$use_detail_page = '1' === (string) get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );

		if ( $show_detail && $use_detail_page ) {
			$settings = $this->get_provider_settings();
			$label    = trim( (string) ( $settings['menu_loop_detail_button_label'] ?? '' ) );
			if ( '' === $label ) {
				$label = __( 'View details', 'vk-booking-manager' );
			}

			$buttons[] = sprintf(
				'<a class="vkbm-menu-loop__button vkbm-button vkbm-button__sm vkbm-button__primary" href="%1$s">%2$s</a>',
				esc_url( get_permalink( $post ) ),
				esc_html( $label )
			);
		}

		if ( $show_reserve ) {
			// 予約ボタンは共通ヘルパーで描画し、予約ブロックと挙動・見た目を統一する。
			// メニューループ固有のクラスは extra_classes として渡す。
			// 予約ページ未設定時の代替リンクとしてプラン投稿のパーマリンクを使う。
			// 同じ「予約」リンクが複数並ぶため、アクセシブルネームにプラン名を含めて
			// リンクの目的（どのプランの予約か）を明確にする。
			$reserve_button = $this->reservation_button_renderer->render_button(
				$post,
				array(
					'label'             => trim( (string) ( $this->get_provider_settings()['menu_loop_reserve_button_label'] ?? '' ) ),
					'extra_classes'     => 'vkbm-menu-loop__button vkbm-menu-loop__button--reserve',
					'fallback_url'      => (string) get_permalink( $post ),
					'accessible_suffix' => (string) get_the_title( $post ),
				)
			);

			if ( '' !== $reserve_button ) {
				$buttons[] = $reserve_button;
			}
		}

		if ( empty( $buttons ) ) {
			return '';
		}

		return sprintf(
			'<div class="vkbm-menu-loop__actions vkbm-buttons vkbm-buttons__right">%s</div>',
			implode( '', $buttons )
		);
	}

	/**
	 * Render category chips.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function render_categories( WP_Post $post ): string {
		$terms = get_the_terms( $post, Service_Menu_Post_Type::TAXONOMY );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$list = array_map(
			static function ( $term ): string {
				return sprintf( '<li>%s</li>', esc_html( $term->name ) );
			},
			$terms
		);

		return sprintf(
			'<ul class="vkbm-menu-loop__card-categories">%s</ul>',
			implode( '', $list )
		);
	}

		/**
		 * Format the price label considering tax settings.
		 *
		 * @param int $base_price Base (tax-included) price.
		 * @return string
		 */
	private function format_price_display( int $base_price ): string {
		$formatted_price = esc_html( VKBM_Helper::format_currency( $base_price ) );
		$tax_label       = VKBM_Helper::get_tax_included_label();

		if ( '' === $tax_label ) {
			return $formatted_price;
		}

		return sprintf(
			/* translators: 1: price, 2: tax-included label */
			'%1$s<span class="vkbm-menu-loop__card-price-tax">%2$s</span>',
			$formatted_price,
			esc_html( $tax_label )
		);
	}

	/**
	 * 料金区分の一覧マークアップを生成する。
	 *
	 * 区分名（dt）と料金（dd）を両端揃えの dl リストで表示する。
	 * 金額は単一料金と同じトーンで強調し、税込みラベルは各行ではなく
	 * 一覧の末尾に 1 回だけ付ける。区分は件数によらず全件を縦に並べて表示する。
	 *
	 * @param array<int, array{label: string, price: int}> $tiers 正規化済みの料金区分。
	 * @return string 料金区分一覧の HTML。区分が無い場合は空文字列。
	 */
	private function render_price_tiers( array $tiers ): string {
		if ( empty( $tiers ) ) {
			return '';
		}

		$rows = array();
		foreach ( $tiers as $tier ) {
			// ラベル・料金はいずれも正規化済みだが、出力時に改めてエスケープする。
			$label = isset( $tier['label'] ) ? (string) $tier['label'] : '';
			$price = isset( $tier['price'] ) ? (int) $tier['price'] : 0;

			$rows[] = sprintf(
				'<div class="vkbm-menu-loop__card-prices-row"><dt class="vkbm-menu-loop__card-prices-label">%1$s</dt><dd class="vkbm-menu-loop__card-prices-value">%2$s</dd></div>',
				esc_html( $label ),
				esc_html( VKBM_Helper::format_currency( $price ) )
			);
		}

		// 税込みラベルは各行ではなく一覧の末尾に 1 回だけ表示する。
		$tax_markup = '';
		$tax_label  = VKBM_Helper::get_tax_included_label();
		if ( '' !== $tax_label ) {
			$tax_markup = sprintf(
				'<p class="vkbm-menu-loop__card-prices-tax">%s</p>',
				esc_html( $tax_label )
			);
		}

		return sprintf(
			// スクリーンリーダー向けに、この dl が料金一覧であることを aria-label で明示する。
			'<dl class="vkbm-menu-loop__card-prices" aria-label="%1$s">%2$s</dl>%3$s',
			esc_attr__( 'Prices', 'vk-booking-manager' ),
			implode( '', $rows ),
			$tax_markup
		);
	}

	/**
	 * Default attribute set matching block settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_default_attributes(): array {
		return array(
			'showImage'         => true,
			'showExcerpt'       => true,
			'showMeta'          => true,
			'showCategories'    => true,
			'displayMode'       => 'card',
			'groupFilterMode'   => 'all',
			'selectedGroupIds'  => array(),
			'hideGroupTitle'    => false,
			'showDetailButton'  => true,
			'showReserveButton' => true,
		);
	}

	/**
	 * Retrieve provider settings from the repository (cached per request).
	 *
	 * @return array<string,mixed>
	 */
	private function get_provider_settings(): array {
		if ( null === $this->provider_settings ) {
			$settings                = $this->settings_repository->get_settings();
			$this->provider_settings = is_array( $settings ) ? $settings : array();
		}

		return $this->provider_settings;
	}

	/**
	 * Build edit link markup for menu items when the current user can edit the post.
	 *
	 * @param WP_Post $post Service menu post.
	 * @return string
	 */
	private function get_menu_edit_link_markup( WP_Post $post ): string {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		$edit_link = get_edit_post_link( $post->ID, '' );
		if ( empty( $edit_link ) ) {
			return '';
		}

		return sprintf(
			'<span class="vkbm-menu-loop__edit-link"><a href="%1$s">%2$s</a></span>',
			esc_url( $edit_link ),
			esc_html__( 'Edit', 'vk-booking-manager' )
		);
	}

	/**
	 * Get resource label for staff-related UI.
	 *
	 * Returns the singular label used across staff-related UI.
	 * スタッフ関連UIで使用する単数ラベルを返します。
	 *
	 * @return string
	 */
	private function get_resource_label_singular(): string {
		$settings = $this->get_provider_settings();
		$label    = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : '';

		return '' !== trim( $label ) ? $label : __( 'Staff', 'vk-booking-manager' );
	}

	/**
	 * Get menu-loop label for staff availability.
	 *
	 * Returns the menu card label for staff availability.
	 * メニューループの担当可能スタッフ用ラベルを返します。
	 *
	 * @return string
	 */
	private function get_resource_label_menu(): string {
		$settings = $this->get_provider_settings();
		$label    = isset( $settings['resource_label_menu'] ) ? (string) $settings['resource_label_menu'] : '';

		return '' !== trim( $label ) ? $label : __( 'Staff available', 'vk-booking-manager' );
	}


	/**
	 * Render empty state markup.
	 *
	 * @param string              $message    Message.
	 * @param string              $loop_id    Loop identifier.
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	private function render_empty_state( string $message, string $loop_id, array $attributes ): string {
		$style_attr = $this->build_wrapper_style( $attributes );

		return sprintf(
			'<div class="vkbm-menu-loop wp-block-vk-booking-manager-menu-loop vkbm-menu-loop--empty" data-loop-id="%1$s"%2$s><div class="vkbm-alert vkbm-alert__warning vkbm-menu-loop__empty text-center">%3$s</div></div>',
			esc_attr( $loop_id ),
			$style_attr,
			esc_html( $message )
		);
	}

	/**
	 * Render warning notice.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function render_notice( string $message ): string {
		return sprintf(
			'<div class="vkbm-menu-loop wp-block-vk-booking-manager-menu-loop vkbm-menu-loop--notice"><p class="vkbm-alert vkbm-alert__warning vkbm-menu-loop__empty text-center">%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Extract filters from request.
	 *
	 * @param string $loop_id Loop identifier.
	 * @return array{staff:int,category:int,keyword:string}
	 */
	private function get_filters_from_request( string $loop_id ): array {
		$staff    = 0;
		$category = 0;
		$keyword  = '';

		if ( '' === $loop_id ) {
			return compact( 'staff', 'category', 'keyword' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Search params are public.
		$request_data = isset( $_GET[ self::REQUEST_KEY ] ) ? map_deep( wp_unslash( $_GET[ self::REQUEST_KEY ] ), 'sanitize_text_field' ) : null;

		if ( ! is_array( $request_data ) || ! isset( $request_data[ $loop_id ] ) || ! is_array( $request_data[ $loop_id ] ) ) {
			return compact( 'staff', 'category', 'keyword' );
		}

		$target = $request_data[ $loop_id ];
		if ( isset( $target['staff'] ) ) {
			$staff = max( 0, (int) $target['staff'] );
		}
		if ( isset( $target['category'] ) ) {
			$category = absint( $target['category'] );
		}
		if ( isset( $target['keyword'] ) ) {
			$keyword = sanitize_text_field( (string) wp_unslash( $target['keyword'] ) );
		}

		return array(
			'staff'    => $staff,
			'category' => $category,
			'keyword'  => $keyword,
		);
	}

	/**
	 * Sanitize identifier.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_identifier( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '';
	}

	/**
	 * Clamp integer to range.
	 *
	 * @param int $value   Value.
	 * @param int $min     Minimum.
	 * @param int $max     Maximum.
	 * @return int
	 */
	private function clamp_int( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Normalize ORDER BY value.
	 *
	 * @param string $order_by Raw value.
	 * @return string
	 */
	private function normalize_order_by( string $order_by ): string {
		$allowed = array( 'menu_order', 'title', 'date', 'modified', 'rand' );

		return in_array( $order_by, $allowed, true ) ? $order_by : 'menu_order';
	}

	/**
	 * Normalize order direction.
	 *
	 * @param string $order Raw value.
	 * @return string
	 */
	private function normalize_order( string $order ): string {
		$order = strtoupper( $order );

		return in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';
	}
}
