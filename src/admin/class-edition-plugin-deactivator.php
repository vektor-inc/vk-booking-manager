<?php
/**
 * Prevents Free/Pro conflicts and deactivates the Free edition when Pro is active.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( class_exists( __NAMESPACE__ . '\\Edition_Plugin_Deactivator', false ) ) {
	// English: Avoid redeclaring the class if another edition already loaded it.
	// 日本語: 先に別エディションが読み込んだ場合は再宣言を防ぎます.
	return;
}

/**
 * Prevents Free/Pro conflicts and deactivates the Free edition when Pro is active.
 * 日本語: Free/Pro の競合を防ぎ、Proが有効な場合は無料版を自動で無効化します。
 */
class Edition_Plugin_Deactivator {
	public const EDITION_PRO  = 'pro';
	public const EDITION_FREE = 'free';

	/**
	 * Known Free edition plugin file paths.
	 *
	 * @var string[]
	 */
	private const FREE_PLUGIN_CANDIDATES = array(
		'vk-booking-manager/vk-booking-manager.php',
		'vk-booking-manager-free/vk-booking-manager.php',
	);

	/**
	 * Detect current edition from the plugin directory name.
	 *
	 * 日本語: プラグインのディレクトリ名から現在のエディションを判定します。
	 *
	 * @param string $plugin_file Plugin main file path.
	 * @return string
	 */
	public static function detect_current_edition( string $plugin_file ): string {
		$directory = basename( dirname( $plugin_file ) );

		if ( false !== strpos( $directory, '-pro' ) ) {
			return self::EDITION_PRO;
		}

		return self::EDITION_FREE;
	}

	/**
	 * Check for conflicting editions and optionally deactivate the Free edition.
	 *
	 * 日本語: エディションの競合を検知し、必要に応じて無料版を無効化します。
	 *
	 * @param string $current_edition Current edition string.
	 * @return bool Whether the current plugin should bail out for this request.
	 */
	public static function handle_conflict( string $current_edition ): bool {
		if ( defined( 'VKBM_EDITION' ) ) {
			if ( VKBM_EDITION === $current_edition ) {
				return false;
			}

			// English: Another edition already loaded; avoid class conflicts.
			// 日本語: 既に別エディションが読み込まれているため競合を避けます。
			if ( is_admin() ) {
				self::deactivate_free_plugin();
			}

			return true;
		}

		define( 'VKBM_EDITION', $current_edition );

		if ( self::EDITION_PRO !== $current_edition ) {
			return false;
		}

		// English: Only attempt deactivation on admin requests where plugin.php is available.
		// 日本語: プラグイン管理系のAPIが使える管理画面リクエストのみ無効化を試みます.
		if ( is_admin() ) {
			self::deactivate_free_plugin();
		}

		return false;
	}

	/**
	 * Deactivate the Free edition plugin if it is active.
	 *
	 * 日本語: 無料版プラグインが有効なら無効化します。
	 *
	 * @return bool Whether the Free edition was deactivated.
	 */
	private static function deactivate_free_plugin(): bool {
		// English: Load plugin APIs lazily for safety.
		// 日本語: プラグイン管理APIを必要時のみ読み込みます.
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			return false;
		}

		$free_plugin = self::find_free_plugin_file();

		if ( '' === $free_plugin ) {
			return false;
		}

		if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $free_plugin ) ) {
			deactivate_plugins( $free_plugin, true, true );
			return true;
		}

		if ( is_plugin_active( $free_plugin ) ) {
			deactivate_plugins( $free_plugin, true );
			return true;
		}

		return false;
	}

	/**
	 * Locate the Free edition plugin file.
	 *
	 * 日本語: 無料版プラグインのファイルパスを特定します。
	 *
	 * @return string
	 */
	private static function find_free_plugin_file(): string {
		$plugins = get_plugins();

		// English: Prefer explicit known plugin paths.
		// 日本語: まず既知のプラグインパスを優先して探します。
		foreach ( self::FREE_PLUGIN_CANDIDATES as $candidate ) {
			if ( isset( $plugins[ $candidate ] ) ) {
				return $candidate;
			}
		}

		// English: Fallback to matching by text domain and excluding Pro by name.
		// 日本語: テキストドメインで絞り込み、Pro表記は除外します.
		foreach ( $plugins as $plugin_file => $data ) {
			$name        = isset( $data['Name'] ) ? (string) $data['Name'] : '';
			$text_domain = isset( $data['TextDomain'] ) ? (string) $data['TextDomain'] : '';

			if ( 'vk-booking-manager' !== $text_domain ) {
				continue;
			}

			if ( '' !== $name && false !== stripos( $name, 'pro' ) ) {
				continue;
			}

			return $plugin_file;
		}

		return '';
	}
}
