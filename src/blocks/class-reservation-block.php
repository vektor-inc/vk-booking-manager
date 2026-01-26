<?php
/**
 * Registers the reservation block metadata.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

use VKBookingManager\Capabilities\Capabilities;
use WP_Post;
use function admin_url;
use function current_user_can;
use function esc_url_raw;
use function generate_block_asset_handle;
use function home_url;
use function is_user_logged_in;
use function sanitize_key;
use function wp_set_script_translations;
use function wp_json_encode;
use function wp_logout_url;
use function wp_unslash;
use function wp_validate_redirect;

/**
 * Registers the reservation block metadata.
 */
class Reservation_Block {
	private const METADATA_PATH                 = 'build/blocks/reservation';
	private const MENU_CARD_STYLE_HANDLE        = 'vkbm-shared-menu-card';
	private const CURRENT_USER_BOOTSTRAP_HANDLE = 'vkbm-current-user-bootstrap';

	/**
	 * Whether block is registered.
	 *
	 * @var bool
	 */
	private static bool $block_registered = false;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'maybe_enqueue_menu_loop_styles' ) );
	}

	/**
	 * Register the block metadata with WordPress.
	 */
	public function register_block(): void {
		// Prevent duplicate registration in test environments.
		if ( self::$block_registered ) {
			return;
		}

		$metadata_path = trailingslashit( plugin_dir_path( VKBM_PLUGIN_FILE ) ) . self::METADATA_PATH;
		register_block_type_from_metadata( $metadata_path );
		$this->register_menu_loop_style();
		$this->register_script_translations( 'vk-booking-manager/reservation', array( 'script', 'viewScript', 'editorScript' ) );

		self::$block_registered = true;
	}

	/**
	 * Load menu loop styles so that shared markup stays consistent.
	 */
	public function maybe_enqueue_menu_loop_styles(): void {
		if ( ! wp_style_is( self::MENU_CARD_STYLE_HANDLE, 'registered' ) ) {
			$this->register_menu_loop_style();
		}

		if ( is_admin() ) {
			wp_enqueue_style( self::MENU_CARD_STYLE_HANDLE );
			return;
		}

		global $post;

		if ( $post instanceof WP_Post && function_exists( 'has_block' ) && has_block( 'vk-booking-manager/reservation', $post ) ) {
			wp_enqueue_style( self::MENU_CARD_STYLE_HANDLE );
			$this->maybe_enqueue_current_user_bootstrap( $post );
			return;
		}
	}

	/**
	 * Enqueue a small inline script that exposes current user flags for initial render.
	 *
	 * This avoids a flicker where the frontend first renders the "customer" navigation
	 * and then switches to the "admin/provider" navigation after calling /vkbm/v1/current-user.
	 *
	 * @param WP_Post $post Current post instance.
	 */
	private function maybe_enqueue_current_user_bootstrap( WP_Post $post ): void {
		// Register an empty script handle we can attach inline JS to.
		if ( ! wp_script_is( self::CURRENT_USER_BOOTSTRAP_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::CURRENT_USER_BOOTSTRAP_HANDLE,
				'',
				array(),
				defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null,
				false
			);
		}

		$is_logged_in = is_user_logged_in();

		$current_url = $this->get_current_url( $post );
		$redirect    = wp_validate_redirect( $current_url, home_url() );

		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		$bootstrap = array(
			'canManageReservations' => $is_logged_in && current_user_can( Capabilities::MANAGE_RESERVATIONS ),
			'canViewPrivateMenus'   => $is_logged_in && current_user_can( Capabilities::VIEW_SERVICE_MENUS ),
			'shiftDashboardUrl'     => $is_logged_in ? admin_url( 'admin.php?page=vkbm-shift-dashboard' ) : '',
			'logoutUrl'             => $is_logged_in ? wp_logout_url( $redirect ) : '',
			'locale'                => $locale,
		);

		$inline = 'window.vkbmCurrentUserBootstrap = ' . wp_json_encode( $bootstrap ) . ';';
		wp_add_inline_script( self::CURRENT_USER_BOOTSTRAP_HANDLE, $inline, 'before' );
		wp_enqueue_script( self::CURRENT_USER_BOOTSTRAP_HANDLE );
	}

	/**
	 * Build current URL including query string.
	 *
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	private function get_current_url( WP_Post $post ): string {
		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
			if ( '' !== $request_uri ) {
				return esc_url_raw( home_url( $request_uri ) );
			}
			return esc_url_raw( $permalink );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return esc_url_raw( home_url( $request_uri ) );
	}

	/**
	 * Registers the shared menu card stylesheet.
	 */
	private function register_menu_loop_style(): void {
		if ( wp_style_is( self::MENU_CARD_STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::MENU_CARD_STYLE_HANDLE,
			plugins_url( 'build/blocks/menu-loop/style-index.css', VKBM_PLUGIN_FILE ),
			array(),
			defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null
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
			// For 'script' field, WordPress generates handle with '-script' suffix.
			// even though it's treated as viewScript internally.
			return (string) generate_block_asset_handle( $block_name, $field );
		}

		$base = str_replace( '/', '-', $block_name );

		switch ( $field ) {
			case 'editorScript':
				return $base . '-editor-script';
			case 'viewScript':
				return $base . '-view-script';
			case 'script': // block.json 'script' field generates '-script' handle.
				return $base . '-script';
			default:
				return $base . '-' . sanitize_key( $field );
		}
	}
}
