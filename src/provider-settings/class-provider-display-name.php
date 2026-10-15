<?php
/**
 * 事業者名の表示用の解決をまとめたユーティリティクラス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function get_bloginfo;
use function wp_specialchars_decode;

/**
 * お客様向けの文言に差し込む事業者名を返す。
 */
class Provider_Display_Name {
	/**
	 * 基本設定の事業者名を返す。未設定ならサイト名に落とす。
	 *
	 * @param array<string, mixed> $settings 基本設定（`provider_name` を参照する）。
	 * @return string 事業者名。未設定ならサイト名。
	 */
	public static function resolve( array $settings ): string {
		// 基本設定の事業者名を取り出す。
		$provider_name = isset( $settings['provider_name'] ) ? trim( (string) $settings['provider_name'] ) : '';

		// 未設定ならサイト名を使う。
		if ( '' === $provider_name ) {
			$provider_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		return $provider_name;
	}
}
