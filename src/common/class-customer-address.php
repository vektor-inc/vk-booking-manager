<?php
/**
 * プロフィールと予約時点の住所の入力規則。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/** 住所の保存先と検証をまとめる。 */
class Customer_Address {
	public const USER_META    = 'vkbm_address_full';
	public const BOOKING_META = '_vkbm_booking_customer_address_full';
	public const MAX_LENGTH   = 255;

	/**
	 * 訂正用の入力を最大1024文字に制限し、巨大な transient を保存しない。
	 *
	 * @param mixed $value 入力値。
	 * @return string 訂正用の住所（保存可能な住所とは限らない）。
	 */
	public static function sanitize_for_redisplay( $value ): string {
		return is_string( $value ) ? sanitize_text_field( mb_substr( $value, 0, 1024 ) ) : '';
	}

	/**
	 * 操作者と予約ごとの住所復元キーを返す。
	 *
	 * @param int $booking_id 予約 ID。
	 * @return string transient キー。
	 */
	public static function booking_input_key( int $booking_id ): string {
		return 'vkbm_booking_address_input_' . get_current_user_id() . '_' . $booking_id;
	}

	/**
	 * 住所を検証し、保存用にサニタイズする（長すぎる入力は拒否）。
	 *
	 * @param mixed $value 入力値。
	 * @param bool  $required 空欄を許可しないか。
	 * @return string|WP_Error 正規化済み住所または入力エラー。
	 */
	public static function sanitize_and_validate( $value, bool $required = false ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'customer_address_invalid', __( 'Please enter an address as text.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}
		// 長い入力をサニタイズで短くして受け付けない。
		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			return new WP_Error( 'customer_address_too_long', __( 'Please enter an address of 255 characters or fewer.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}
		$address = sanitize_text_field( $value );
		if ( '' === preg_replace( '/[\s\p{Z}]+/u', '', $address ) ) {
			if ( $required ) {
				return new WP_Error( 'customer_address_required', __( 'Please enter your address.', 'vk-booking-manager' ), array( 'status' => 400 ) );
			}
			return '';
		}
		return $address;
	}
}
