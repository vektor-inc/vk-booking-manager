<?php
/**
 * SiteGuard WP Plugin が予約画面の REST 通信を止めているかを、SiteGuard の設定値から判定する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\SiteGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\REST\Reservation_Content_Routes;

/**
 * SiteGuard の「ユーザー名漏えい防御」が予約画面の通信を止めているかを判定するクラス（#1154）。
 *
 * サイトが自分自身へ通信して確かめる方式（ループバック）は使わず、SiteGuard の設定値（option `siteguard_config`）を
 * 読んで判定する。ステージングの Basic 認証などで誤検知し、閉じられない通知が出続けるのを避けるため。
 * SiteGuard の設定は読むだけで、書き換えない。
 */
class SiteGuard_Rest_Api_Check {

	/**
	 * SiteGuard が設定値を保存している option 名。
	 */
	public const OPTION_NAME = 'siteguard_config';

	/**
	 * SiteGuard の「ユーザー名漏えい防御」の設定画面の page 引数。
	 */
	public const SETTINGS_PAGE_SLUG = 'siteguard_author_query';

	/**
	 * BM 設定 > よくある質問タブの、SiteGuard の項目の見出しに付けるアンカー id。
	 */
	public const FAQ_ANCHOR_ID = 'vkbm-faq-siteguard-rest-api';

	/**
	 * SiteGuard が有効化されているか。null のときは実行中のサイトで判定する。
	 *
	 * @var bool|null
	 */
	private ?bool $is_siteguard_active;

	/**
	 * コンストラクタ。
	 *
	 * @param bool|null $is_siteguard_active SiteGuard が有効化されているか。null のときは実行中のサイトで判定する（テストで差し替えるための引数）。
	 */
	public function __construct( ?bool $is_siteguard_active = null ) {
		$this->is_siteguard_active = $is_siteguard_active;
	}

	/**
	 * SiteGuard の除外リスト（除外する REST API ネームスペース）に書いてもらう行を返す。
	 *
	 * よくある質問・管理画面の通知で表示する行は、すべてこの値から組み立てる。
	 *
	 * @return string 除外に書く行（`vkbm/v1`）。
	 */
	public static function get_required_exclude_line(): string {
		return Reservation_Content_Routes::REST_NAMESPACE;
	}

	/**
	 * SiteGuard の「ユーザー名漏えい防御」の設定画面の URL を返す。
	 *
	 * @return string 管理画面の URL。
	 */
	public static function get_settings_url(): string {
		return admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG );
	}

	/**
	 * BM 設定 > よくある質問タブの、SiteGuard の項目の URL を返す。
	 *
	 * @return string 管理画面の URL（項目の見出しへのアンカー付き）。
	 */
	public static function get_faq_url(): string {
		return admin_url( 'admin.php?page=vkbm-provider-settings&tab=faq#' . self::FAQ_ANCHOR_ID );
	}

	/**
	 * 通信先が SiteGuard の除外リストで止められないかを、SiteGuard と同じ一致判定で返す。
	 *
	 * SiteGuard（1.8.11 の `rest_pre_dispatch` 側の処理）と同じく、除外リストをカンマで区切り、各行の前後の空白と
	 * スラッシュを除いたうえで、通信先が `/<行>` と完全に一致するか `/<行>/` で始まれば除外とみなす。空の行は無視する。
	 *
	 * @param string $route          通信先のルート（例: `/vkbm/v1/service-menus`）。
	 * @param string $exclude_option SiteGuard の `disable_restapi_exclude` の値（カンマ区切り）。
	 * @return bool 除外リストのいずれかの行に一致すれば true。
	 */
	public static function is_route_excluded( string $route, string $exclude_option ): bool {
		$route = '/' . ltrim( $route, '/' );

		foreach ( explode( ',', $exclude_option ) as $line ) {
			// SiteGuard と同じく、前後の空白とスラッシュを除いてから比べる。
			$namespace = trim( trim( $line ), '/' );
			if ( '' === $namespace ) {
				continue;
			}

			if ( '/' . $namespace === $route || 0 === strpos( $route, '/' . $namespace . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * SiteGuard の設定値が、予約画面の通信を止める状態かを返す。
	 *
	 * 「ユーザー名漏えい防御」と「REST API 無効化」が両方有効で、ネームスペース `/vkbm/v1` そのものが除外リストで
	 * 覆われていないときだけ true。個別のルート（例: `vkbm/v1/service-menus`）だけを除外していても、
	 * ほかの vkbm/v1 の通信は止められるため true にする。設定値の形が想定と違う（配列でない・値が文字列でない）ときは false（通知を出さない側）にする。
	 *
	 * @param mixed $config option `siteguard_config` の値。
	 * @return bool 止められる状態なら true。
	 */
	public static function is_blocking_config( $config ): bool {
		if ( ! is_array( $config ) ) {
			return false;
		}

		// 「ユーザー名漏えい防御」と「REST API 無効化」が両方有効なときだけ、SiteGuard は REST 通信を止める。
		if ( '1' !== ( $config['block_author_query_enable'] ?? null ) || '1' !== ( $config['disable_restapi_enable'] ?? null ) ) {
			return false;
		}

		$exclude_option = $config['disable_restapi_exclude'] ?? null;
		if ( ! is_string( $exclude_option ) ) {
			return false;
		}

		// ネームスペースの入口 `/vkbm/v1` が除外されていれば、`/vkbm/v1/` で始まる通信はすべて除外される（SiteGuard と同じ判定）。
		return ! self::is_route_excluded( '/' . self::get_required_exclude_line(), $exclude_option );
	}

	/**
	 * 実行中のサイトで、SiteGuard が予約画面の通信を止めているかを返す。
	 *
	 * SiteGuard が有効化されていないとき（設定値だけ残っているときを含む）は false。
	 *
	 * @return bool 止められているなら true。
	 */
	public function is_blocking_reservation_requests(): bool {
		if ( ! $this->is_siteguard_active() ) {
			return false;
		}

		return self::is_blocking_config( get_option( self::OPTION_NAME ) );
	}

	/**
	 * SiteGuard が有効化されているかを返す。
	 *
	 * SiteGuard は読み込み時に定数 `SITEGUARD_VERSION` を定義するため、その有無で判定する。
	 *
	 * @return bool 有効化されていれば true。
	 */
	private function is_siteguard_active(): bool {
		if ( null !== $this->is_siteguard_active ) {
			return $this->is_siteguard_active;
		}

		return defined( 'SITEGUARD_VERSION' );
	}
}
