<?php

/**
 * Common helper utilities for VK Booking Manager.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;

/**
 * Common helper utilities for VK Booking Manager.
 */
class VKBM_Helper {
	/**
	 * Format a currency amount with a translatable symbol.
	 *
	 * 日本語: 通貨記号を翻訳可能な形式で付与します。
	 *
	 * @param int $amount Amount in base currency.
	 * @return string
	 */
	public static function format_currency( int $amount ): string {
		$formatted = number_format_i18n( max( 0, $amount ) );

		$currency_symbol = self::get_currency_symbol();

		return sprintf( '%s%s', $currency_symbol, $formatted );
	}

	/**
	 * Resolve currency symbol from settings or locale.
	 *
	 * 日本語: 設定値またはロケールから通貨記号を取得します。
	 *
	 * @return string
	 */
	public static function get_currency_symbol(): string {
		$settings        = ( new Settings_Repository() )->get_settings();
		$currency_symbol = isset( $settings['currency_symbol'] ) ? trim( (string) $settings['currency_symbol'] ) : '';

		if ( '' !== $currency_symbol ) {
			return $currency_symbol;
		}

		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		if ( '' !== $locale && 0 === strpos( $locale, 'ja' ) ) {
			return '¥';
		}

		return '$';
	}

	/**
	 * Get the tax-included label from provider settings.
	 *
	 * 日本語: 税込み表示のラベルを取得します。
	 *
	 * @return string
	 */
	public static function get_tax_included_label(): string {
		$settings  = ( new Settings_Repository() )->get_settings();
		$label     = isset( $settings['tax_label_text'] ) ? (string) $settings['tax_label_text'] : '';
		$has_label = '' !== trim( $label );

		if ( ! $has_label ) {
			return '';
		}

		return $label;
	}

	/**
	 * Normalize phone number to digits only.
	 *
	 * @param string $value Raw phone number.
	 * @return string
	 */
	public static function normalize_phone_number( string $value ): string {
		$normalized = trim( $value );

		if ( function_exists( 'mb_convert_kana' ) ) {
			$normalized = (string) mb_convert_kana( $normalized, 'n', 'UTF-8' );
		}

		return preg_replace( '/\D+/', '', $normalized ) ?? '';
	}
	/**
	 * Determine whether a post has a thumbnail.
	 *
	 * @param int|WP_Post $post       Post ID or post instance.
	 * @param string      $check_type 'direct' checks the raw _thumbnail_id stored on the post,
	 *                               'hook' uses WordPress thumbnail APIs (filters may apply).
	 * @return bool
	 */
	public static function has_thumbnail( $post, string $check_type = 'direct' ): bool {
		return self::get_thumbnail_id( $post, $check_type ) > 0;
	}

	/**
	 * Get thumbnail attachment ID.
	 *
	 * @param int|WP_Post $post       Post ID or post instance.
	 * @param string      $check_type 'direct' checks the raw _thumbnail_id stored on the post,
	 *                               'hook' uses WordPress thumbnail APIs (filters may apply).
	 * @return int Attachment ID, or 0 if not available.
	 */
	public static function get_thumbnail_id( $post, string $check_type = 'direct' ): int {
		$post_id = self::normalize_post_id( $post );
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( 'hook' === $check_type ) {
			if ( has_post_thumbnail( $post_id ) ) {
				return (int) get_post_thumbnail_id( $post_id );
			}

			return 0;
		}

		// 'direct' mode: ignore plugins that pretend thumbnails exist.
		$raw = null;
		if ( function_exists( 'get_metadata_raw' ) ) {
			$raw = get_metadata_raw( 'post', $post_id, '_thumbnail_id', true );
		} else {
			// Fallback for older WordPress versions.
			$raw = get_post_meta( $post_id, '_thumbnail_id', true );
		}

		if ( '' === $raw || null === $raw ) {
			return 0;
		}

		$thumbnail_id = is_numeric( $raw ) ? (int) $raw : 0;
		if ( $thumbnail_id <= 0 ) {
			return 0;
		}

		$attachment = get_post( $thumbnail_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return 0;
		}

		return $thumbnail_id;
	}

	/**
	 * Get thumbnail HTML.
	 *
	 * @param int|WP_Post           $post       Post ID or post instance.
	 * @param string|array<int,int> $size       Image size.
	 * @param string                $check_type 'direct' ignores filters by using the stored attachment ID,
	 *                                         'hook' uses WordPress thumbnail APIs (filters may apply).
	 * @param array<string,mixed>   $attr       Optional image attributes.
	 * @return string
	 */
	public static function get_thumbnail_html( $post, $size = 'thumbnail', string $check_type = 'direct', array $attr = array() ): string {
		$post_id = self::normalize_post_id( $post );
		if ( $post_id <= 0 ) {
			return '';
		}

		if ( 'hook' === $check_type ) {
			return get_the_post_thumbnail( $post_id, $size, $attr );
		}

		$thumbnail_id = self::get_thumbnail_id( $post_id, 'direct' );
		if ( $thumbnail_id <= 0 ) {
			return '';
		}

		return (string) wp_get_attachment_image( $thumbnail_id, $size, false, $attr );
	}

	/**
	 * Normalize post ID from int or WP_Post.
	 *
	 * @param int|WP_Post $post Post ID or post.
	 * @return int
	 */
	private static function normalize_post_id( $post ): int {
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}

		return (int) $post;
	}
}
