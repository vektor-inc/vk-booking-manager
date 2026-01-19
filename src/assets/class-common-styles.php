<?php

declare( strict_types=1 );

namespace VKBookingManager\Assets;

use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * Enqueues common CSS for the whole plugin.
 */
class Common_Styles {
	public const FRONTEND_HANDLE = 'vkbm-frontend';
	public const AUTH_HANDLE     = 'vkbm-auth';
	public const ADMIN_HANDLE    = 'vkbm-admin';
	public const EDITOR_HANDLE   = 'vkbm-editor';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_styles' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_custom_properties' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
	}

	/**
	 * Enqueue common styles for frontend.
	 */
	public function enqueue_frontend(): void {
		$this->register_styles();
		wp_enqueue_style( self::FRONTEND_HANDLE );
	}

	/**
	 * Enqueue common styles for admin.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		unset( $hook_suffix );

		$this->register_styles();
		wp_enqueue_style( self::ADMIN_HANDLE );
		$this->apply_custom_properties( self::ADMIN_HANDLE );
	}

	/**
	 * Enqueue common styles for block editor.
	 */
	public function enqueue_editor(): void {
		$this->register_styles();
		wp_enqueue_style( self::EDITOR_HANDLE );
		$this->apply_custom_properties( self::EDITOR_HANDLE );
	}

	/**
	 * Register bundled stylesheets.
	 */
	public function register_styles(): void {
		$default_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null;
		$base    = plugin_dir_path( VKBM_PLUGIN_FILE ) . 'build/assets/css/';
		$map     = [
			self::FRONTEND_HANDLE => 'vkbm-frontend.min.css',
			self::AUTH_HANDLE     => 'vkbm-auth.min.css',
			self::EDITOR_HANDLE   => 'vkbm-editor.min.css',
			self::ADMIN_HANDLE    => 'vkbm-admin.min.css',
		];

		foreach ( $map as $handle => $file ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				continue;
			}

			$path    = $base . $file;
			$version = file_exists( $path ) ? (string) filemtime( $path ) : $default_version;

			wp_register_style(
				$handle,
				plugins_url( 'build/assets/css/' . $file, VKBM_PLUGIN_FILE ),
				[],
				$version
			);
		}
	}

	/**
	 * Enqueue custom properties after all frontend styles are registered.
	 */
	public function enqueue_frontend_custom_properties(): void {
		foreach ( [ self::FRONTEND_HANDLE, self::AUTH_HANDLE ] as $handle ) {
			if ( ! wp_style_is( $handle, 'enqueued' ) ) {
				continue;
			}

			$this->apply_custom_properties( $handle );
		}
	}

	/**
	 * Attach custom CSS variables based on provider settings.
	 *
	 * @param string $handle Target style handle.
	 */
	private function apply_custom_properties( string $handle ): void {
		$inline = $this->get_custom_css();
		if ( '' === $inline ) {
			return;
		}

		wp_add_inline_style( $handle, $inline );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_custom_properties(): array {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();

		$properties = [];

		$primary_color = isset( $settings['design_primary_color'] ) ? (string) $settings['design_primary_color'] : '';
		if ( '' !== $primary_color ) {
			$properties['--vkbm--color--primary'] = $primary_color;
		}

		$reservation_button_color = isset( $settings['design_reservation_button_color'] )
			? (string) $settings['design_reservation_button_color']
			: '';
		if ( '' !== $reservation_button_color ) {
			$properties['--vkbm--color--reservation-action'] = $reservation_button_color;
		}

		$radius_raw = $settings['design_radius_md'] ?? null;
		if ( '' !== $radius_raw && null !== $radius_raw ) {
			$radius_md = (int) $radius_raw;
			$properties['--vkbm--radius--md'] = sprintf( '%dpx', max( 0, $radius_md ) );
		}

		return $properties;
	}

	/**
	 * Build the inline CSS string for custom properties.
	 *
	 * @return string
	 */
	private function get_custom_css(): string {
		$custom_properties = $this->get_custom_properties();
		if ( [] === $custom_properties ) {
			return '';
		}

		$declarations = [];
		foreach ( $custom_properties as $name => $value ) {
			$declarations[] = sprintf( '%s: %s;', $name, $value );
		}

		return ':root{' . implode( '', $declarations ) . '}';
	}
}
