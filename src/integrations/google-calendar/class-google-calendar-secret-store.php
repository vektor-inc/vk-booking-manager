<?php
/**
 * Google カレンダー連携で受け取ったアクセス許可（トークン）を暗号化して保存・復号するためのクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## なぜ暗号化するのか
 * 親 issue #94 で「中継サーバーにはアクセス許可を保存せず、各サイトの DB に、外部から
 * 読めない形で持つ」と決めている。wp_options に平文で置くと、同じサイトに入っている他の
 * プラグイン・テーマや、DB のバックアップファイルを手に入れた第三者が、そのままオーナーの
 * Google カレンダーへ書き込める状態になるため、暗号化した上で保存する。
 *
 * ## 鍵の作り方
 * WordPress が各サイトごとに持っている秘密の文字列（`wp_salt( 'secure_auth' )`。wp-config.php の
 * SECURE_AUTH_KEY / SECURE_AUTH_SALT から作られる）から、`hash_hkdf()` でこの用途専用の鍵を
 * 導出する。サイトごとに異なる鍵になり、かつ DB の中だけを見ても鍵は分からない
 * （wp-config.php を別途読めないと復号できない）。
 *
 * 定数 `VKBM_GOOGLE_CALENDAR_ENCRYPTION_KEY` を wp-config.php で定義した場合は、そちらを
 * 鍵の材料として優先する。salt を入れ替える運用をしているサイトで、入れ替えのたびに
 * 再連携が必要になるのを避けたい場合に使う。
 *
 * ## 復号できなくなる場合
 * salt を入れ替えると、それ以前に保存したトークンは復号できなくなる。その場合 `decrypt()` は
 * null を返し、呼び出し側（Google_Calendar_Connection）は「連携が切れている」状態として扱い、
 * オーナーに再接続を促す。データが壊れたのではなく再接続で直るため、この扱いで問題ない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function hash_equals;
use function hash_hkdf;
use function wp_salt;

/**
 * トークンの暗号化・復号を担当するクラス。
 *
 * 状態を持たない静的メソッドだけで構成し、単体テストしやすくしている。
 */
class Google_Calendar_Secret_Store {

	/**
	 * 使用する暗号化方式。
	 *
	 * 認証付き暗号（AEAD）である GCM を使い、復号時に改ざんを検出できるようにする。
	 *
	 * @var string
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * 保存する文字列の先頭に付ける版番号。
	 *
	 * 将来、暗号化方式を変えたときに、保存済みの値がどの方式で暗号化されたものかを
	 * 判別できるようにするために付けている。
	 *
	 * @var string
	 */
	private const FORMAT_VERSION = 'v1';

	/**
	 * 鍵を導出するときの用途識別子（HKDF の info）。
	 *
	 * 同じ salt から別用途の鍵を作っても衝突しないようにするための文字列。
	 *
	 * @var string
	 */
	private const KEY_INFO = 'vkbm-google-calendar-token';

	/**
	 * 暗号化が利用できる環境かどうかを判定する。
	 *
	 * PHP の openssl 拡張が無い、または AES-256-GCM が使えないサーバーでは暗号化できない。
	 * その場合は平文で保存せず、連携そのものを開始させない（呼び出し側で案内を出す）。
	 *
	 * @return bool 暗号化が使えるなら true。
	 */
	public static function is_available(): bool {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_cipher_iv_length' ) ) {
			return false;
		}

		$methods = function_exists( 'openssl_get_cipher_methods' ) ? openssl_get_cipher_methods() : array();

		return in_array( self::CIPHER, $methods, true );
	}

	/**
	 * 文字列を暗号化し、保存用の1本の文字列にまとめて返す。
	 *
	 * 戻り値の形式は `v1:{base64(初期化ベクトル)}:{base64(認証タグ)}:{base64(暗号文)}`。
	 * 3つの値を別々のカラムに持たせず1本にまとめているのは、保存先が wp_options の
	 * 配列1つであり、値の対応付けを呼び出し側に意識させないため。
	 *
	 * @param string $plain 暗号化したい平文（アクセストークン等）。
	 * @return string|null 暗号化済み文字列。暗号化できない環境・失敗時は null。
	 */
	public static function encrypt( string $plain ): ?string {
		if ( '' === $plain || ! self::is_available() ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_length || $iv_length <= 0 ) {
			return null;
		}

		$iv  = random_bytes( $iv_length );
		$tag = '';

		$cipher_text = openssl_encrypt( $plain, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher_text ) {
			return null;
		}

		return implode(
			':',
			array(
				self::FORMAT_VERSION,
				base64_encode( $iv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- バイナリを option へ保存するためのエンコード（難読化目的ではない）.
				base64_encode( $tag ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 同上.
				base64_encode( $cipher_text ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 同上.
			)
		);
	}

	/**
	 * encrypt() で作った文字列を復号して平文へ戻す。
	 *
	 * 形式が違う・改ざんされている・鍵（salt）が変わって復号できない場合はいずれも null を返す。
	 * 呼び出し側は null を「連携が切れている」として扱い、オーナーに再接続を促す。
	 *
	 * @param string $stored encrypt() が返した保存用の文字列。
	 * @return string|null 復号した平文。復号できない場合は null。
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored || ! self::is_available() ) {
			return null;
		}

		$parts = explode( ':', $stored );
		if ( 4 !== count( $parts ) ) {
			return null;
		}

		list( $version, $iv_encoded, $tag_encoded, $cipher_encoded ) = $parts;

		if ( ! hash_equals( self::FORMAT_VERSION, $version ) ) {
			return null;
		}

		$iv          = base64_decode( $iv_encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypt() が base64 で保存した値を戻すためのデコード.
		$tag         = base64_decode( $tag_encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 同上.
		$cipher_text = base64_decode( $cipher_encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 同上.

		if ( false === $iv || false === $tag || false === $cipher_text ) {
			return null;
		}

		$plain = openssl_decrypt( $cipher_text, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plain || '' === $plain ) {
			return null;
		}

		return $plain;
	}

	/**
	 * 暗号化に使う鍵（32バイト）を導出する。
	 *
	 * wp-config.php で `VKBM_GOOGLE_CALENDAR_ENCRYPTION_KEY` が定義されていればそれを、
	 * 無ければ WordPress の salt（`wp_salt( 'secure_auth' )`）を材料にする。
	 * どちらの場合も、材料をそのまま鍵にせず HKDF でこの用途専用の鍵へ変換する。
	 *
	 * @return string 32バイトのバイナリ鍵。
	 */
	private static function get_key(): string {
		$material = '';

		if ( defined( 'VKBM_GOOGLE_CALENDAR_ENCRYPTION_KEY' ) && is_string( VKBM_GOOGLE_CALENDAR_ENCRYPTION_KEY ) ) {
			$material = (string) VKBM_GOOGLE_CALENDAR_ENCRYPTION_KEY;
		}

		if ( '' === $material ) {
			$material = wp_salt( 'secure_auth' );
		}

		return hash_hkdf( 'sha256', $material, 32, self::KEY_INFO );
	}
}
