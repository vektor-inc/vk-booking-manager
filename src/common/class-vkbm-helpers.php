<?php

declare( strict_types=1 );

namespace VKBookingManager\Common;

use WP_Post;

/**
 * Common helper utilities for VK Booking Manager.
 */
class VKBM_Helper {
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
	 * @param string               $check_type 'direct' ignores filters by using the stored attachment ID,
	 *                                        'hook' uses WordPress thumbnail APIs (filters may apply).
	 * @param array<string,mixed>  $attr       Optional image attributes.
	 * @return string
	 */
	public static function get_thumbnail_html( $post, $size = 'thumbnail', string $check_type = 'direct', array $attr = [] ): string {
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
