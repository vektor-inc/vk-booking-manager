<?php
/**
 * VK Booking Manager が送信するメールの送信元を解決する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メール送信元の解決とフリーメールドメイン判定を提供する。
 */
class Mail_From {
	/**
	 * 設定値またはサイトドメインから送信元メールアドレスを解決する。
	 *
	 * @param string $fallback 解決できない場合に返す既存値。
	 * @return string 送信元メールアドレス。
	 */
	public static function resolve( string $fallback = '' ): string {
		$settings = get_option( 'vkbm_provider_settings', array() );
		$saved    = is_array( $settings ) ? ( $settings['mail_from_email'] ?? '' ) : '';
		$email    = is_string( $saved ) ? sanitize_email( $saved ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			$email = self::resolve_default();
		}

		if ( '' === $email && is_email( $fallback ) ) {
			$email = $fallback;
		}

		/**
		 * VK Booking Manager が送信するメールの最終的な送信元アドレスを変更する。
		 *
		 * @param string $email    解決後のメールアドレス。
		 * @param string $fallback 解決できない場合の既存値。
		 */
		$filtered = apply_filters( 'vkbm_mail_from_email', $email, $fallback );

		return is_string( $filtered ) && is_email( $filtered ) ? $filtered : $email;
	}

	/**
	 * 保存値とフィルターを通さず、既定の送信元メールアドレスを解決する。
	 *
	 * @return string 既定の送信元メールアドレス。解決できない場合は空文字。
	 */
	public static function resolve_default(): string {
		$email = self::resolve_default_from_url( network_home_url() );
		if ( '' !== $email && is_email( $email ) ) {
			return $email;
		}

		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );

		return is_email( $admin_email ) ? $admin_email : '';
	}

	/**
	 * URL のホストから既定の no-reply アドレスを組み立てる。
	 *
	 * @param string $url サイト URL。
	 * @return string 有効な既定アドレス。解決できない場合は空文字。
	 */
	public static function resolve_default_from_url( string $url ): string {
		$host  = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$host  = preg_replace( '/^www\./i', '', $host );
		$email = is_string( $host ) ? sanitize_email( 'no-reply@' . $host ) : '';

		return is_email( $email ) ? $email : '';
	}

	/**
	 * メールアドレスがフリーメールのドメインか判定する。
	 *
	 * @param string $email 判定対象のメールアドレス。
	 * @return bool フリーメールの場合 true。
	 */
	public static function is_free_email( string $email ): bool {
		$domains = array(
			'gmail.com',
			'googlemail.com',
			'yahoo.co.jp',
			'yahoo.com',
			'ymail.com',
			'hotmail.com',
			'hotmail.co.jp',
			'outlook.com',
			'outlook.jp',
			'live.com',
			'live.jp',
			'icloud.com',
			'me.com',
			'mac.com',
			'aol.com',
		);

		/**
		 * フリーメールとして警告するドメイン一覧を変更する。
		 *
		 * @param array<int,string> $domains ドメイン一覧。
		 */
		$filtered = apply_filters( 'vkbm_free_email_domains', $domains );
		if ( ! is_array( $filtered ) ) {
			$filtered = $domains;
		}

		$valid_domains = array();
		foreach ( $filtered as $domain ) {
			if ( is_string( $domain ) && '' !== trim( $domain ) ) {
				$valid_domains[] = strtolower( trim( $domain ) );
			}
		}

		$parts  = explode( '@', strtolower( $email ) );
		$domain = 2 === count( $parts ) ? $parts[1] : '';

		return in_array( $domain, $valid_domains, true );
	}

	/**
	 * メールヘッダー内の From を共通送信元へ置き換える。
	 *
	 * 既存の From に差出人名があれば維持し、明示された場合だけ上書きする。
	 *
	 * @param array<string,mixed> $email     メール設定。
	 * @param string              $from_name 明示する差出人名。空文字なら既存名を維持する。
	 * @return array<string,mixed> From を更新したメール設定。
	 */
	public static function apply_to_email( array $email, string $from_name = '' ): array {
		$from_email = self::resolve();
		if ( '' === $from_email ) {
			return $email;
		}

		$headers = $email['headers'] ?? array();
		if ( is_string( $headers ) ) {
			$headers = preg_split( "/\r\n|\n|\r/", $headers );
		}
		$headers       = is_array( $headers ) ? $headers : array();
		$kept_headers  = array();
		$existing_name = '';
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) ) {
				continue;
			}

			$header = trim( $header );
			if ( 0 === stripos( $header, 'From:' ) ) {
				if ( preg_match( '/^From:\s*(.*?)\s*<[^>]+>$/i', $header, $matches ) ) {
					$existing_name = trim( $matches[1], " \t\n\r\0\x0B\"'" );
				}
				continue;
			}

			if ( '' !== $header ) {
				$kept_headers[] = $header;
			}
		}

		$effective_name   = '' !== $from_name ? $from_name : $existing_name;
		$kept_headers[]   = '' !== $effective_name
			? sprintf( 'From: %s <%s>', $effective_name, $from_email )
			: sprintf( 'From: <%s>', $from_email );
		$headers          = $kept_headers;
		$email['headers'] = $headers;

		return $email;
	}
}
