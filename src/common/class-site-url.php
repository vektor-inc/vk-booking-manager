<?php
/**
 * リクエストの URL を、サイトの設定（home_url）を基準にして組み立てる共通処理。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use function home_url;
use function is_string;
use function ltrim;
use function wp_parse_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 */
class Site_Url {

	/**
	 * リクエストの URI（パスとクエリ）から、サイトのホストを使った絶対 URL を返す。
	 *
	 * スキーム・ホスト・ポートは `home_url()` から取り、パスとクエリだけをリクエストから引き継ぐ。
	 * `home_url( $request_uri )` にすると、サイトをサブディレクトリに設置している場合に、
	 * REQUEST_URI に含まれるサブディレクトリが二重になるため使わない。
	 * REQUEST_URI が `//evil.example.com/` のようにホストに見える形でも、ホストはサイトのものになる。
	 *
	 * @param string $request_uri リクエストの URI（パスとクエリ）。空の場合は `/` として扱う。
	 * @return string 絶対 URL。
	 */
	public static function from_request_uri( string $request_uri ): string {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return home_url( '/' );
		}

		// スキーム・ホスト・ポートだけをサイトの設定から取る。
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'http' ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		// パスとクエリはリクエストから引き継ぐ。
		return $origin . '/' . ltrim( $request_uri, '/' );
	}
}
