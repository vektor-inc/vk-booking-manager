<?php

/**
 * Prevents Free/Pro conflicts and deactivates the Free edition when Pro is active.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Pro plugin main file path (set when scheduling deactivation via activated_plugin hook).
	 *
	 * @var string
	 */
	private static $pro_plugin_file = '';

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
		// English: Prefer plugin header to handle folders without "-pro".
		// 日本語: フォルダ名に "-pro" が含まれないケースを考慮してヘッダを優先します。
		if ( ! function_exists( 'get_file_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_file_data' ) ) {
			$plugin_data = get_file_data( $plugin_file, array( 'name' => 'Plugin Name' ) );
			$name        = isset( $plugin_data['name'] ) ? (string) $plugin_data['name'] : '';
			if ( '' !== $name && false !== stripos( $name, 'pro' ) ) {
				return self::EDITION_PRO;
			}
		}

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
	 * @param string $plugin_file     Optional. Current plugin main file path (e.g. __FILE__). When set, Free is deactivated via 'activated_plugin' hook to avoid fatals during activation.
	 * @return bool Whether the current plugin should bail out for this request.
	 */
	public static function handle_conflict( string $current_edition, string $plugin_file = '' ): bool {
		if ( defined( 'VKBM_EDITION' ) ) {
			if ( VKBM_EDITION === $current_edition ) {
				return false;
			}

			// English: Another edition already loaded; deactivate Free after this request to avoid fatals.
			// 日本語: 既に別エディションが読み込まれているため、有効化処理後に無料版を無効化します。
			if ( is_admin() && '' !== $plugin_file ) {
				self::schedule_deactivate_free_on_activated( $plugin_file );
			} elseif ( is_admin() ) {
				self::deactivate_free_plugin();
			}

			return true;
		}

		define( 'VKBM_EDITION', $current_edition );

		if ( self::EDITION_PRO !== $current_edition ) {
			return false;
		}

		// English: Only attempt deactivation on admin requests; use hook to avoid fatals during activation.
		// 日本語: 管理画面でのみ無効化を試み、有効化処理中の致命的エラーを防ぐためフックで実行します.
		if ( is_admin() ) {
			if ( '' !== $plugin_file ) {
				self::schedule_deactivate_free_on_activated( $plugin_file );
				return false;
			}
			if ( self::deactivate_free_plugin() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule deactivation of Free edition when Pro is activated (runs on 'activated_plugin').
	 *
	 * 日本語: Pro が有効化されたときに無料版を無効化するよう 'activated_plugin' で実行します。
	 *
	 * @param string $plugin_file Current plugin main file path (e.g. __FILE__).
	 * @return void
	 */
	public static function schedule_deactivate_free_on_activated( string $plugin_file ): void {
		self::$pro_plugin_file = $plugin_file;
		add_action( 'activated_plugin', array( self::class, 'on_activated_plugin' ), 10, 1 );
	}

	/**
	 * Callback for 'activated_plugin': deactivate Free if the activated plugin was Pro.
	 *
	 * 日本語: 'activated_plugin' のコールバック。有効化されたプラグインが Pro なら無料版を無効化します。
	 *
	 * @param string $activated_plugin Plugin basename that was activated.
	 * @return void
	 */
	public static function on_activated_plugin( string $activated_plugin ): void {
		if ( '' === self::$pro_plugin_file ) {
			return;
		}
		if ( plugin_basename( self::$pro_plugin_file ) !== $activated_plugin ) {
			return;
		}
		self::deactivate_free_plugin();
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
