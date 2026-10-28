<?php
/**
 * 利用者名の設定と入力値を扱う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * 利用者名の共通処理。
 */
class Attendee_Name {
	public const META_KEY   = '_vkbm_booking_attendee_name';
	public const MAX_LENGTH = 200;

	/**
	 * 設定値から利用モードを返す。
	 *
	 * @param array<string, mixed>|null $settings 設定値。
	 * @return string disabled、optional、required のいずれか。
	 */
	public static function get_mode( ?array $settings = null ): string {
		$settings = $settings ?? ( new Settings_Repository() )->get_settings();
		$mode     = sanitize_key( (string) ( $settings['booking_attendee_name_mode'] ?? 'disabled' ) );
		return in_array( $mode, array( 'disabled', 'optional', 'required' ), true ) ? $mode : 'disabled';
	}

	/**
	 * 表示用の見出しを返す。
	 *
	 * @param array<string, mixed>|null $settings 設定値。
	 * @return string 見出し。
	 */
	public static function get_label( ?array $settings = null ): string {
		$settings = $settings ?? ( new Settings_Repository() )->get_settings();
		$label    = trim( (string) ( $settings['booking_attendee_name_label'] ?? '' ) );
		return '' !== $label ? $label : __( 'Name of the person receiving the service', 'vk-booking-manager' );
	}

	/**
	 * 入力値をサニタイズし、上限文字数に収める。
	 *
	 * @param mixed $value 入力値。
	 * @return string サニタイズ済みの値。
	 */
	public static function sanitize( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return mb_strlen( $value ) > self::MAX_LENGTH ? mb_substr( $value, 0, self::MAX_LENGTH ) : $value;
	}
}
