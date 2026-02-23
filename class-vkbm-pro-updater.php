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
		private const METADATA_URL = 'https://license.vektor-inc.co.jp/check/?action=get_metadata&slug=vk-booking-manager-pro';

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

			YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				self::METADATA_URL,
				$plugin_file,
				self::PLUGIN_SLUG
			);
		}
	}
}
