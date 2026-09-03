<?php

/**
 * 無料版でプロ版への誘導リンクを表示するためのヘルパー。
 *
 * Pro 版では何も表示しない（無料版のみで動作する）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 無料版でのプロ版誘導リンクをまとめて扱うクラス。
 *
 * 誘導リンクの遷移先 URL や表示用 HTML を一元管理し、
 * プラグイン一覧・設定ページ・各機能の無効箇所から再利用できるようにする。
 */
class Pro_Upsell {

	/**
	 * プロ版製品ページの URL。
	 *
	 * @var string
	 */
	public const PRO_URL = 'https://vws.vektor-inc.co.jp/product/vk-booking-manager-pro';

	/**
	 * フックを登録する。
	 *
	 * 無料版のときのみプラグイン一覧へアップグレードリンクを追加する。
	 * Pro 版では何も登録しない。
	 *
	 * @return void
	 */
	public function register(): void {
		// Pro 版では誘導リンクを表示しない。
		if ( ! self::is_free_edition() ) {
			return;
		}

		// プラグイン一覧の操作リンクにアップグレードリンクを追加する。
		add_filter(
			'plugin_action_links_' . plugin_basename( VKBM_PLUGIN_FILE ),
			array( $this, 'add_action_link' )
		);
	}

	/**
	 * 現在のエディションが無料版かどうかを判定する。
	 *
	 * Free_Version_Deactivator クラスは無料版ビルドには含まれないため、
	 * クラスが存在しない場合も無料版として扱う。
	 *
	 * @return bool 無料版なら true。
	 */
	public static function is_free_edition(): bool {
		// Pro 版判定が真の場合のみ Pro 版。それ以外（判定不能含む）は無料版扱い。
		$is_pro = class_exists( 'Free_Version_Deactivator' )
			&& \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );

		return ! $is_pro;
	}

	/**
	 * プロ版誘導リンクの遷移先 URL を取得する。
	 *
	 * 必要に応じてフィルターで上書きできるようにしておく。
	 *
	 * @return string 遷移先 URL。
	 */
	public static function get_pro_url(): string {
		/**
		 * プロ版誘導リンクの遷移先 URL を変更するためのフィルター。
		 *
		 * @param string $url 遷移先 URL。
		 */
		return (string) apply_filters( 'vkbm_pro_upsell_url', self::PRO_URL );
	}

	/**
	 * プラグイン一覧の操作リンクにアップグレードリンクを追加する。
	 *
	 * @param array<string,string> $links 既存の操作リンク。
	 * @return array<string,string> アップグレードリンクを先頭に追加したリンク配列。
	 */
	public function add_action_link( $links ): array {
		// 想定外の型が渡された場合は安全に配列化する。
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		// 目立たせるためにアップグレードリンクを先頭へ差し込む。
		$upgrade_link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color:#d54e21;font-weight:bold;">%2$s</a>',
			esc_url( self::get_pro_url() ),
			esc_html__( 'Upgrade to Pro', 'vk-booking-manager' )
		);

		array_unshift( $links, $upgrade_link );

		return $links;
	}

	/**
	 * プロ版機能が無効化されている箇所に表示する案内文の HTML を取得する。
	 *
	 * @param string $message 案内文（プロ版で利用できる機能の説明など）。
	 * @return string 案内文＋誘導リンクの HTML。無料版以外では空文字。
	 */
	public static function get_feature_notice_html( string $message ): string {
		// Pro 版では案内を出さない。
		if ( ! self::is_free_edition() ) {
			return '';
		}

		return sprintf(
			'<p class="description vkbm-pro-upsell"><span class="dashicons dashicons-lock" aria-hidden="true" style="vertical-align:middle;"></span> %1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>',
			esc_html( $message ),
			esc_url( self::get_pro_url() ),
			esc_html__( 'Learn more about Pro', 'vk-booking-manager' )
		);
	}

	/**
	 * 設定ページの先頭などに表示する誘導バナーの HTML を取得する。
	 *
	 * @return string バナーの HTML。無料版以外では空文字。
	 */
	public static function get_banner_html(): string {
		// Pro 版ではバナーを出さない。
		if ( ! self::is_free_edition() ) {
			return '';
		}

		return sprintf(
			'<div class="notice notice-info inline vkbm-pro-upsell-banner" style="margin:12px 0;"><p>%1$s <a href="%2$s" class="button button-primary" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">%3$s</a></p></div>',
			esc_html__( 'Staff nomination, auto-assignment, and other advanced features are available in the Pro edition.', 'vk-booking-manager' ),
			esc_url( self::get_pro_url() ),
			esc_html__( 'Upgrade to Pro', 'vk-booking-manager' )
		);
	}
}
