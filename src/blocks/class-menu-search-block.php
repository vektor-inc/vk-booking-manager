<?php

/**
 * Registers and renders the booking search form block.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Block;
use WP_Post;
use WP_Term;
use function generate_block_asset_handle;
use function sanitize_key;
use function wp_set_script_translations;

/**
 * Registers and renders the booking search form block.
 */
class Menu_Search_Block {
	private const METADATA_PATH     = 'build/blocks/menu-search';
	private const FIELD_BLOCKS      = array(
		'build/blocks/menu-search-staff',
		'build/blocks/menu-search-category',
		'build/blocks/menu-search-keyword',
	);
	private const FIELD_BLOCK_NAMES = array(
		'vk-booking-manager/menu-search-field-staff',
		'vk-booking-manager/menu-search-field-category',
		'vk-booking-manager/menu-search-field-keyword',
	);
	private const REQUEST_KEY       = 'vkbm_menu_search';

	/**
	 * Staff cache.
	 *
	 * @var array|null
	 */
	private ?array $staff_cache = null;

	/**
	 * Category cache.
	 *
	 * @var array|null
	 */
	private ?array $category_cache = null;

	/**
	 * Loop cache.
	 *
	 * @var array<string, array>
	 */
	private static array $loop_cache = array();

	/**
	 * Whether blocks are registered.
	 *
	 * @var bool
	 */
	private static bool $blocks_registered = false;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register block metadata for the form and child fields.
	 */
	public function register_blocks(): void {
		// Prevent duplicate registration in test environments.
		if ( self::$blocks_registered ) {
			return;
		}

		$metadata_path = trailingslashit( plugin_dir_path( VKBM_PLUGIN_FILE ) ) . self::METADATA_PATH;

		register_block_type_from_metadata(
			$metadata_path,
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
		$this->register_script_translations( 'vk-booking-manager/menu-search', array( 'editorScript' ) );

		foreach ( self::FIELD_BLOCKS as $relative_path ) {
			$path = trailingslashit( plugin_dir_path( VKBM_PLUGIN_FILE ) ) . $relative_path;
			register_block_type_from_metadata( $path );
		}

		foreach ( self::FIELD_BLOCK_NAMES as $block_name ) {
			$this->register_script_translations( $block_name, array( 'editorScript' ) );
		}

		self::$blocks_registered = true;
	}

	/**
	 * Render the booking search block.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @param string              $content    Saved markup (unused).
	 * @param WP_Block            $block      Block instance.
	 * @return string
	 */
	public function render_block( array $attributes, string $content = '', WP_Block $block = null ): string {
		$target_id = $this->sanitize_identifier( (string) ( $attributes['targetId'] ?? '' ) );

		if ( '' === $target_id ) {
			return $this->render_notice( __( 'The menu loop ID to be searched has not been set.', 'vk-booking-manager' ) );
		}

		if ( ! $this->loop_exists_on_current_page( $target_id ) ) {
			return $this->render_notice( __( 'The menu loop block to be displayed was not found.', 'vk-booking-manager' ) );
		}

		$fields       = $this->extract_field_definitions( $block );
		$values       = $this->get_filters_from_request( $target_id );
		$action_url   = $this->get_form_action_url();
		$submit_label = trim( (string) ( $attributes['submitLabel'] ?? '' ) );
		if ( '' === $submit_label ) {
			$submit_label = __( 'Search with these conditions', 'vk-booking-manager' );
		}

		ob_start();
		?>
		<form class="vkbm-menu-search wp-block-vk-booking-manager-menu-search" method="get" action="<?php echo esc_url( $action_url ); ?>" data-target-id="<?php echo esc_attr( $target_id ); ?>">
			<div class="vkbm-menu-search__fields">
				<?php foreach ( $fields as $field ) : ?>
					<?php echo $this->render_field( $field, $target_id, $values ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
			<div class="vkbm-menu-search__actions vkbm-buttons vkbm-buttons__center">
				<button type="submit" class="vkbm-button vkbm-button__primary">
					<?php echo esc_html( $submit_label ); ?>
				</button>
			</div>
		</form>
		<?php

		return (string) ob_get_clean();
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

		$translation_path = trailingslashit( plugin_dir_path( VKBM_PLUGIN_FILE ) ) . 'languages';

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
	 * Render notice only.
	 *
	 * @param string $message Message text.
	 * @return string
	 */
	private function render_notice( string $message ): string {
		return sprintf(
			'<div class="vkbm-menu-search vkbm-menu-search--notice wp-block-vk-booking-manager-menu-search"><p class="vkbm-alert vkbm-alert__warning text-center">%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Build the form action URL pointing to the current page without existing search params.
	 *
	 * @return string
	 */
	private function get_form_action_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$url         = '';

		if ( '' !== $host && '' !== $request_uri ) {
			$scheme = is_ssl() ? 'https://' : 'http://';
			$url    = $scheme . $host . $request_uri;
		} elseif ( '' !== $request_uri ) {
			$url = $request_uri;
		} else {
			$url = home_url( '/' );
		}

		$url = remove_query_arg( self::REQUEST_KEY, $url );

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		return $url;
	}

	/**
	 * Determine whether any filters are active.
	 *
	 * @param array{staff:int,category:string,keyword:string} $values Filters.
	 * @return bool
	 */
	private function has_active_filters( array $values ): bool {
		return ( $values['staff'] > 0 ) || '' !== $values['category'] || '' !== $values['keyword'];
	}

	/**
	 * Render individual field markup.
	 *
	 * @param array<string,mixed>                             $field     Field definition.
	 * @param string                                          $target_id Loop ID.
	 * @param array{staff:int,category:string,keyword:string} $values    Current values.
	 * @return string
	 */
	private function render_field( array $field, string $target_id, array $values ): string {
		switch ( $field['type'] ) {
			case 'staff':
				return $this->render_staff_field( $field, $target_id, $values['staff'] );
			case 'category':
				return $this->render_category_field( $field, $target_id, $values['category'] );
			case 'keyword':
				return $this->render_keyword_field( $field, $target_id, $values['keyword'] );
			default:
				return '';
		}
	}

	/**
	 * Render staff selector.
	 *
	 * @param array<string,mixed> $field     Field definition.
	 * @param string              $target_id Loop ID.
	 * @param int                 $current   Selected staff ID.
	 * @return string
	 */
	private function render_staff_field( array $field, string $target_id, int $current ): string {
		$options  = $this->get_staff_options();
		$field_id = sprintf( 'vkbm-menu-search-%s-staff', $target_id );

		$markup  = '<div class="vkbm-menu-search__field">';
		$markup .= sprintf(
			'<label for="%1$s">%2$s</label>',
			esc_attr( $field_id ),
			esc_html( $field['label'] )
		);
		$markup .= sprintf(
			'<select id="%1$s" name="%2$s[%3$s][staff]">',
			esc_attr( $field_id ),
			esc_attr( self::REQUEST_KEY ),
			esc_attr( $target_id )
		);

		foreach ( $options as $option ) {
			$markup .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option['value'] ),
				selected( (int) $option['value'], $current, false ),
				esc_html( $option['label'] )
			);
		}

		$markup .= '</select></div>';

		return $markup;
	}

	/**
	 * Render category selector.
	 *
	 * @param array<string,mixed> $field     Field definition.
	 * @param string              $target_id Target ID.
	 * @param string              $current   Selected slug.
	 * @return string
	 */
	private function render_category_field( array $field, string $target_id, string $current ): string {
		$options  = $this->get_category_options();
		$field_id = sprintf( 'vkbm-menu-search-%s-category', $target_id );

		$markup  = '<div class="vkbm-menu-search__field">';
		$markup .= sprintf(
			'<label for="%1$s">%2$s</label>',
			esc_attr( $field_id ),
			esc_html( $field['label'] )
		);
		$markup .= sprintf(
			'<select id="%1$s" name="%2$s[%3$s][category]">',
			esc_attr( $field_id ),
			esc_attr( self::REQUEST_KEY ),
			esc_attr( $target_id )
		);

		foreach ( $options as $option ) {
			$markup .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option['value'] ),
				selected( (string) $option['value'], (string) $current, false ),
				esc_html( $option['label'] )
			);
		}

		$markup .= '</select></div>';

		return $markup;
	}

	/**
	 * Render keyword field.
	 *
	 * @param array<string,mixed> $field     Field definition.
	 * @param string              $target_id Loop ID.
	 * @param string              $current   Current value.
	 * @return string
	 */
	private function render_keyword_field( array $field, string $target_id, string $current ): string {
		$field_id = sprintf( 'vkbm-menu-search-%s-keyword', $target_id );

		return sprintf(
			'<div class="vkbm-menu-search__field"><label for="%1$s">%2$s</label><input type="search" id="%1$s" name="%3$s[%4$s][keyword]" value="%5$s" placeholder="%6$s" /></div>',
			esc_attr( $field_id ),
			esc_html( $field['label'] ),
			esc_attr( self::REQUEST_KEY ),
			esc_attr( $target_id ),
			esc_attr( $current ),
			esc_attr( (string) ( $field['placeholder'] ?? '' ) )
		);
	}

	/**
	 * Extract requested filters from query vars.
	 *
	 * @param string $target_id Loop ID.
	 * @return array{staff:int,category:string,keyword:string}
	 */
	private function get_filters_from_request( string $target_id ): array {
		$staff    = 0;
		$category = '';
		$keyword  = '';

		$raw = isset( $_GET[ self::REQUEST_KEY ] ) && is_array( $_GET[ self::REQUEST_KEY ] ) ? wp_unslash( $_GET[ self::REQUEST_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Individual fields are sanitized below.
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		if ( ! is_array( $raw ) || ! isset( $raw[ $target_id ] ) || ! is_array( $raw[ $target_id ] ) ) {
			return compact( 'staff', 'category', 'keyword' );
		}

		$data = $raw[ $target_id ];

		if ( isset( $data['staff'] ) ) {
			$staff = (int) $data['staff'];
			if ( $staff < 0 ) {
				$staff = 0;
			}
		}

		if ( isset( $data['category'] ) ) {
			$category = (string) absint( $data['category'] );
			if ( '0' === $category ) {
				$category = '';
			}
		}

		if ( isset( $data['keyword'] ) ) {
			$keyword = sanitize_text_field( wp_unslash( (string) $data['keyword'] ) );
		}

		return array(
			'staff'    => $staff,
			'category' => $category,
			'keyword'  => $keyword,
		);
	}

	/**
	 * Determine loop existence for current page content.
	 *
	 * @param string $target_id Loop identifier.
	 * @return bool
	 */
	private function loop_exists_on_current_page( string $target_id ): bool {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return true;
		}

		if ( ! isset( self::$loop_cache[ $post_id ] ) ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( ! is_string( $content ) ) {
				$content = '';
			}

			$blocks                       = parse_blocks( $content );
			self::$loop_cache[ $post_id ] = $this->collect_loop_ids( $blocks );
		}

		$loops = self::$loop_cache[ $post_id ];

		if ( empty( $loops ) ) {
			// テンプレート化などで検出できないケースでは警告を出さない.
			return true;
		}

		return in_array( $target_id, $loops, true );
	}

	/**
	 * Collect loop IDs from parsed block tree.
	 *
	 * @param array<int|string,mixed> $blocks Parsed blocks.
	 * @return array<int,string>
	 */
	private function collect_loop_ids( array $blocks ): array {
		$ids = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = $block['blockName'] ?? '';

			if ( 'vk-booking-manager/menu-loop' === $name ) {
				$attrs = $block['attrs'] ?? array();
				$id    = $this->sanitize_identifier( (string) ( $attrs['loopId'] ?? '' ) );

				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, $this->collect_loop_ids( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Extract field definitions from the inner blocks.
	 *
	 * @param WP_Block|null $block Block instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_field_definitions( ?WP_Block $block ): array {
		if ( ! $block instanceof WP_Block ) {
			return $this->default_fields();
		}

		$inner_blocks = $block->parsed_block['innerBlocks'] ?? array();
		if ( empty( $inner_blocks ) ) {
			return $this->default_fields();
		}

		$fields = array();
		foreach ( $inner_blocks as $inner_block ) {
			if ( ! is_array( $inner_block ) ) {
				continue;
			}

			$name  = $inner_block['blockName'] ?? '';
			$attrs = $inner_block['attrs'] ?? array();

			switch ( $name ) {
				case 'vk-booking-manager/menu-search-field-staff':
					$fields[] = array(
						'type'  => 'staff',
						'label' => $this->resolve_label( $attrs['label'] ?? '', __( 'Staff', 'vk-booking-manager' ) ),
					);
					break;
				case 'vk-booking-manager/menu-search-field-category':
					$fields[] = array(
						'type'  => 'category',
						'label' => $this->resolve_label( $attrs['label'] ?? '', __( 'Service tag', 'vk-booking-manager' ) ),
					);
					break;
				case 'vk-booking-manager/menu-search-field-keyword':
					$fields[] = array(
						'type'        => 'keyword',
						'label'       => $this->resolve_label( $attrs['label'] ?? '', __( 'Keyword', 'vk-booking-manager' ) ),
						'placeholder' => $attrs['placeholder'] ?? '',
					);
					break;
				default:
					break;
			}
		}

		return ! empty( $fields ) ? $fields : $this->default_fields();
	}

	/**
	 * Default field set.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function default_fields(): array {
		return array(
			array(
				'type'  => 'staff',
				'label' => __( 'Staff', 'vk-booking-manager' ),
			),
			array(
				'type'  => 'category',
				'label' => __( 'Service tag', 'vk-booking-manager' ),
			),
			array(
				'type'        => 'keyword',
				'label'       => __( 'Keyword', 'vk-booking-manager' ),
				'placeholder' => '',
			),
		);
	}

	/**
	 * Resolve label from attribute or fallback.
	 *
	 * @param string $raw      Raw label.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function resolve_label( string $raw, string $fallback ): string {
		$raw = trim( $raw );
		return '' === $raw ? $fallback : $raw;
	}

	/**
	 * Retrieve staff options.
	 *
	 * @return array<int,array{value:int,label:string}>
	 */
	private function get_staff_options(): array {
		if ( null !== $this->staff_cache ) {
			return $this->staff_cache;
		}

		$options = array(
			array(
				'value' => 0,
				'label' => __( 'All', 'vk-booking-manager' ),
			),
		);

		$staff_posts = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 100,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		foreach ( $staff_posts as $staff ) {
			if ( ! $staff instanceof WP_Post ) {
				continue;
			}

			$options[] = array(
				'value' => (int) $staff->ID,
				'label' => get_the_title( $staff ),
			);
		}

		$this->staff_cache = $options;

		return $options;
	}

	/**
	 * Retrieve category options.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function get_category_options(): array {
		if ( null !== $this->category_cache ) {
			return $this->category_cache;
		}

		$options = array(
			array(
				'value' => '',
				'label' => __( 'all', 'vk-booking-manager' ),
			),
		);

		$terms = get_terms(
			array(
				'taxonomy'   => Service_Menu_Post_Type::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$options[] = array(
					'value' => (string) $term->term_id,
					'label' => $term->name,
				);
			}
		}

		$this->category_cache = $options;

		return $options;
	}

	/**
	 * Sanitize identifier for request.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_identifier( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '';
	}
}
