<?php
/**
 * 予約ページURL（BM 設定）が、このサイトのURLとして使えるかを判定する共通処理。
 *
 * サイトのドメインを移したあとに設定が旧ドメインのまま残ると、予約ボタンなどが
 * 警告なく旧ドメインへ移動してしまうため、予約ページURLを使う全箇所がここを通る。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use function esc_html;
use function esc_html__;
use function apply_filters;
use function array_filter;
use function array_values;
use function home_url;
use function function_exists;
use function idn_to_ascii;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_replace;
use function rtrim;
use function site_url;
use function sprintf;
use function strtolower;
use function trim;
use function vkbm_normalize_reservation_page_url;
use function wp_parse_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 *
 * 判定は「ホスト名」だけで行う。誤って正しい自サイトURLを弾くと予約ボタンが消えるため、
 * 次の違いは同じサイトとして扱う（弾かない）。
 * - http / https の違い
 * - ポート番号の違い
 * - 先頭の www. の有無
 * - 大文字小文字・ホスト末尾のドット
 * - サブディレクトリ・パス・末尾スラッシュ・クエリ
 * - home_url() と site_url() のどちらのホストでも可
 * - `allowed_redirect_hosts` フィルターで許可されたホスト（WPML・Polylang の言語別ドメイン等が追加する）
 */
class Reservation_Page_Url {

	/**
	 * 設定値を、リンク先・移動先に使える自サイトのURLにして返す。
	 *
	 * 相対パスは home_url() 基準の絶対URLにする。空文字・自サイト以外のURLは空文字にする。
	 *
	 * @param string $raw_url 設定された予約ページURL（未加工）。
	 * @return string 自サイトのURLとして使えるときは正規化済みURL。未設定・自サイト以外のときは空文字。
	 */
	public static function resolve_usable( string $raw_url ): string {
		$url = vkbm_normalize_reservation_page_url( $raw_url );

		if ( '' === $url || ! self::is_own_site_url( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * 絶対URLのホストが、このサイトのものかを判定する。
	 *
	 * @param string $absolute_url http(s) から始まる絶対URL。
	 * @return bool 自サイトのホストなら true。ホストが取れないときは false。
	 */
	public static function is_own_site_url( string $absolute_url ): bool {
		$host = self::extract_host( $absolute_url );
		if ( '' === $host ) {
			return false;
		}

		return in_array( $host, self::get_own_hosts( $host ), true );
	}

	/**
	 * 予約ページURLが入力済みなのに、自サイトのURLでないときの警告用情報を返す。
	 *
	 * @param string $raw_url 設定された予約ページURL（未加工）。
	 * @return array{entered_host:string,site_host:string}|null 警告が要るときだけ配列。未設定・自サイトのURLなら null。
	 */
	public static function get_mismatch( string $raw_url ): ?array {
		$url = vkbm_normalize_reservation_page_url( $raw_url );

		if ( '' === $url || self::is_own_site_url( $url ) ) {
			return null;
		}

		// 警告には、利用者が見分けやすいよう正規化前の実際のホスト名を出す。
		$entered_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'entered_host' => '' !== $entered_host ? $entered_host : $url,
			'site_host'    => $site_host,
		);
	}

	/**
	 * 予約ページURLが自サイトのURLでないときの警告文（HTML）を組み立てる。
	 *
	 * 入力欄の横と管理画面上部の通知で同じ文面を使うため、ここに集約する。
	 * 「何が起きているか → 何をすればよいか → 直すまでどうなるか」の順に並べる。
	 * 1つの翻訳関数に1文だけを入れ、文ごとに分けてから連結する。
	 * 入力中のドメインだけを `<code>` で強調する。出力側でエスケープ済みなので、そのまま出力してよい。
	 *
	 * @param array{entered_host:string,site_host:string} $mismatch get_mismatch() の戻り値。
	 * @return string 警告文（エスケープ済みの HTML）。
	 */
	public static function build_mismatch_message( array $mismatch ): string {
		$sentences = array(
			sprintf(
				/* translators: 1: the domain currently set in the reservation page URL, 2: the domain of this site. */
				esc_html__( 'The domain of the reservation page URL is "%1$s", but the domain of this site is "%2$s".', 'vk-booking-manager' ),
				'<code>' . esc_html( $mismatch['entered_host'] ) . '</code>',
				esc_html( $mismatch['site_host'] )
			),
			esc_html__( 'Please enter the URL of the reservation page of this site again.', 'vk-booking-manager' ),
			esc_html__( 'Until then, it is not used for reservation buttons and links.', 'vk-booking-manager' ),
		);

		return implode( ' ', $sentences );
	}

	/**
	 * このサイトとして扱うホストの一覧（比較用に整形済み）を返す。
	 *
	 * @param string $target_host 判定対象のホスト（`allowed_redirect_hosts` フィルターの第2引数に渡す）。
	 * @return string[] 整形済みホストの一覧。
	 */
	private static function get_own_hosts( string $target_host ): array {
		$hosts = array(
			self::extract_host( home_url() ),
			self::extract_host( site_url() ),
		);

		// 言語別ドメイン等を追加するプラグインのため、WordPress 標準のフィルターに従う。
		$allowed = apply_filters( 'allowed_redirect_hosts', array( $hosts[0] ), $target_host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフィルター。
		if ( is_array( $allowed ) ) {
			foreach ( $allowed as $allowed_host ) {
				if ( is_string( $allowed_host ) ) {
					$hosts[] = self::format_host( $allowed_host );
				}
			}
		}

		return array_values( array_filter( $hosts, static fn ( string $host ): bool => '' !== $host ) );
	}

	/**
	 * URLからホスト名を取り出し、比較用に整形して返す。
	 *
	 * @param string $url URL。
	 * @return string 整形済みホスト。取れないときは空文字。
	 */
	private static function extract_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? self::format_host( $host ) : '';
	}

	/**
	 * ホスト名を比較用に整える（小文字化・末尾ドット除去・先頭の www. 除去）。
	 *
	 * @param string $host ホスト名。
	 * @return string 整形済みホスト。
	 */
	private static function format_host( string $host ): string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );

		// Unicode ドメインと punycode 表記を同じホストとして比べられるよう、ASCII 表記にそろえる（関数が無い環境は素通し）。
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) && '' !== $ascii ? $ascii : $host;
		}

		return (string) preg_replace( '/^www\./', '', $host );
	}
}
