<?php
/**
 * Pro edition update checker.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'VKBM_Pro_Updater', false ) ) {
	/**
	 * Pro版アップデートチェッカーの初期化クラス。
	 */
	class VKBM_Pro_Updater {
		/**
		 * メタデータ配信URL。
		 *
		 * @var string
		 */
		private const METADATA_URL = 'https://license.vektor-inc.co.jp/check/';

		/**
		 * プラグインスラッグ。
		 *
		 * @var string
		 */
		private const PLUGIN_SLUG = 'vk-booking-manager-pro';

		/**
		 * 更新チェッカーを登録する。
		 *
		 * @param string $plugin_file プラグインメインファイルのパス。
		 * @return void
		 */
		public static function register( string $plugin_file ): void {
			if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
				return;
			}

			// METADATA_URL に引数を追加
			$license_raw  = get_option( 'vk-booking-manager-pro-license-key', '' );
			$license      = is_scalar( $license_raw ) ? sanitize_text_field( (string) $license_raw ) : '';
			$metadata_url = add_query_arg(
				array(
					'action' => 'get_metadata',
					'slug'   => self::PLUGIN_SLUG,
					'vk-booking-manager-pro-license-key' => $license,
					'url'    => home_url(),
				),
				self::METADATA_URL
			);

			YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$metadata_url,
				$plugin_file,
				self::PLUGIN_SLUG
			);
		}
	}
}
