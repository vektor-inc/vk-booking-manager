<?php

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use VKBookingManager\Assets\Common_Styles;
/**
 * Development-only style guide page.
 *
 * The menu is only registered when the style guide HTML file exists under docs/.
 */
class Style_Guide_Page {
	private const MENU_SLUG_FRONT = 'vkbm-style-guide';
	private const MENU_SLUG_ADMIN = 'vkbm-style-guide-admin';

	/**
	 * @var string
	 */
	private $capability;

	/**
	 * @var array<string, string>
	 */
	private $page_hooks = [];

	public function __construct( string $capability ) {
		$this->capability = $capability;
	}

	public function register(): void {
		// Register after the main "BM settings" menu is created so we don't accidentally create
		// an orphan submenu entry that can confuse WordPress' menu URL handling.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register style guide submenu if docs/ui/style-guide.html exists.
	 */
	public function register_menu(): void {
		$pages = [
			[
				'slug'     => self::MENU_SLUG_FRONT,
				'title'    => __( 'style guide', 'vk-booking-manager' ),
				'menu'     => __( 'style guide', 'vk-booking-manager' ),
				'filename' => 'style-guide.html',
				'callback' => [ $this, 'render_front_page' ],
			],
			[
				'slug'     => self::MENU_SLUG_ADMIN,
				'title'    => __( 'Style guide (management screen)', 'vk-booking-manager' ),
				'menu'     => __( 'Style guide (management screen)', 'vk-booking-manager' ),
				'filename' => 'style-guide-admin.html',
				'callback' => [ $this, 'render_admin_page' ],
			],
		];

		foreach ( $pages as $page ) {
			$html_path = $this->docs_html_path( (string) $page['filename'] );
			if ( '' === $html_path || ! file_exists( $html_path ) ) {
				continue;
			}

			$hook = add_submenu_page(
				'vkbm-provider-settings',
				(string) $page['title'],
				(string) $page['menu'],
				$this->capability,
				(string) $page['slug'],
				$page['callback']
			);

			if ( is_string( $hook ) && '' !== $hook ) {
				$this->page_hooks[ (string) $page['slug'] ] = $hook;
			}
		}
	}

	/**
	 * Enqueue additional styles for the style guide page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( empty( $this->page_hooks ) || ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );
		wp_enqueue_style( Common_Styles::FRONTEND_HANDLE );
		$this->enqueue_reservation_block_style();
	}

	/**
	 * Enqueue reservation block styles for the style guide page.
	 */
	private function enqueue_reservation_block_style(): void {
		$style_handle = 'vkbm-style-guide-reservation';
		$style_path   = plugin_dir_path( VKBM_PLUGIN_FILE ) . 'build/blocks/reservation/style-index.css';

		if ( ! file_exists( $style_path ) ) {
			return;
		}

		$style_url = plugins_url( 'build/blocks/reservation/style-index.css', VKBM_PLUGIN_FILE );
		$version   = (string) filemtime( $style_path );

		wp_enqueue_style( $style_handle, $style_url, [], $version );
	}

	/**
	 * Render the style guide page (front/common).
	 */
	public function render_front_page(): void {
		$this->render_docs_page(
			__( 'style guide', 'vk-booking-manager' ),
			$this->docs_html_path( 'style-guide.html' )
		);
	}

	/**
	 * Render the style guide page (admin).
	 */
	public function render_admin_page(): void {
		$this->render_docs_page(
			__( 'Style guide (management screen)', 'vk-booking-manager' ),
			$this->docs_html_path( 'style-guide-admin.html' )
		);
	}

	private function render_docs_page( string $title, string $html_path ): void {
		if ( '' === $html_path || ! file_exists( $html_path ) ) {
			echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html__( 'Style guide file not found.', 'vk-booking-manager' ) . '</p>';
			$paths = $this->docs_html_candidate_paths( basename( $html_path ) ?: 'style-guide.html' );
			if ( ! empty( $paths ) ) {
				echo '<p><strong>Checked paths</strong></p><ul>';
				foreach ( $paths as $path ) {
					echo '<li><code>' . esc_html( $path ) . '</code></li>';
				}
				echo '</ul>';
			}
			echo '</div>';
			return;
		}

		$html = (string) file_get_contents( $html_path );
		?>
		<div class="wrap vkbm-style-guide">
			<h1><?php echo esc_html( $title ); ?></h1>
			<div class="vkbm-style-guide__content">
				<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Development-only HTML snippet. ?>
			</div>
		</div>
		<?php
	}

	private function docs_html_path( string $filename ): string {
		foreach ( $this->docs_html_candidate_paths( $filename ) as $candidate ) {
			if ( '' !== $candidate && file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Returns possible docs HTML paths.
	 *
	 * @param string $filename HTML filename under docs/ui/.
	 * @return string[]
	 */
	private function docs_html_candidate_paths( string $filename ): array {
		$paths = [];

		$filename = ltrim( $filename, '/\\' );

		// Primary: relative to the main plugin file.
		if ( defined( 'VKBM_PLUGIN_FILE' ) ) {
			$paths[] = trailingslashit( plugin_dir_path( VKBM_PLUGIN_FILE ) ) . 'docs/ui/' . $filename;
		}

		// Fallback: relative to this class file (src/admin/ -> plugin root).
		$paths[] = trailingslashit( dirname( __DIR__, 2 ) ) . 'docs/ui/' . $filename;

		return array_values( array_unique( array_filter( $paths ) ) );
	}

}
