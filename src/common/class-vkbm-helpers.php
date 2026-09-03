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
use WP_User;

/**
 * Common helper utilities for VK Booking Manager.
 */
class VKBM_Helper {
	/**
	 * Format a currency amount with a translatable symbol.
	 *
	 * 通貨記号を翻訳可能な形式で付与します。
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
	 * 設定値またはロケールから通貨記号を取得します。
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
	 * 税込み表示のラベルを取得します。
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
	 * Resolve the display name for a WordPress user.
	 *
	 * Priority: 姓 + 名 > ふりがな (vkbm_kana_name) > user_login.
	 *
	 * @param WP_User $user User instance.
	 * @return string
	 */
	public static function get_user_display_name( WP_User $user ): string {
		$first_name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		$last_name  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );

		$full_name = trim( sprintf( '%s %s', $last_name, $first_name ) );
		if ( '' !== $full_name ) {
			return $full_name;
		}

		$kana_name = trim( (string) get_user_meta( $user->ID, 'vkbm_kana_name', true ) );
		if ( '' !== $kana_name ) {
			return $kana_name;
		}

		return $user->user_login;
	}

	/**
	 * 最小催行人数（グループ開催型）の催行状態を算出する。
	 *
	 * 同じ日時・対応スタッフのスロットに相乗りした合計予約人数が
	 * 最小催行人数に達したら「開催決定」とみなす表示用ロジック。
	 * 表示・可視化のみを目的とし、自動中止や通知などの判定は行わない。
	 *
	 * - 最小催行人数が 0 以下（未設定）の場合は制約なしとして state='none' を返し、
	 *   従来挙動（催行判定なし）を維持する。
	 * - 合計予約人数が最小催行人数以上なら state='fulfilled'（開催決定）。
	 * - 未達なら state='pending'、shortfall に「あと何名で開催か」を返す。
	 *
	 * 表示文言の生成は呼び出し側（フロント JS・管理画面 PHP）に委ね、
	 * このメソッドは判定に必要な数値とフラグのみを返す純粋関数とする。
	 *
	 * @param int $min_capacity  最小催行人数（0 以下は制約なし）。
	 * @param int $booked_guests 当該スロットの合計予約人数（0 以上）。
	 * @return array{state:string, min_capacity:int, booked_guests:int, shortfall:int}
	 *               state: 'none'（制約なし）/'pending'（未達）/'fulfilled'（達成）。
	 *               shortfall: 開催までに不足している人数（達成・制約なし時は 0）。
	 */
	public static function get_min_capacity_status( int $min_capacity, int $booked_guests ): array {
		// 負の入力は防御的に 0 へ丸める（メタ未設定・不正値対策）。
		$min_capacity  = max( 0, $min_capacity );
		$booked_guests = max( 0, $booked_guests );

		// 最小催行人数が未設定（0）なら制約なし。従来どおり催行判定をしない。
		if ( 0 === $min_capacity ) {
			return array(
				'state'         => 'none',
				'min_capacity'  => 0,
				'booked_guests' => $booked_guests,
				'shortfall'     => 0,
			);
		}

		// 合計予約人数が最小催行人数に達していれば開催決定。
		if ( $booked_guests >= $min_capacity ) {
			return array(
				'state'         => 'fulfilled',
				'min_capacity'  => $min_capacity,
				'booked_guests' => $booked_guests,
				'shortfall'     => 0,
			);
		}

		// 未達。あと何名で開催かを算出する。
		return array(
			'state'         => 'pending',
			'min_capacity'  => $min_capacity,
			'booked_guests' => $booked_guests,
			'shortfall'     => $min_capacity - $booked_guests,
		);
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
