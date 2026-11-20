<?php
/**
 * 公開側に表示する料金の整形。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 公開画面の料金を表示するための整形を行う。
 */
class Public_Price_Display {
	/**
	 * 公開画面用に通貨額を整形し、0 円は翻訳可能な無料表示にする。
	 *
	 * @param int $amount 金額。
	 * @return string 表示用の金額。
	 */
	public static function format_currency( int $amount ): string {
		if ( 0 === $amount ) {
			return __( 'Free', 'vk-booking-manager' );
		}

		return VKBM_Helper::format_currency( $amount );
	}

	/**
	 * ツアー料金の金額と単位を一体の nowrap 塊で返す。
	 *
	 * @param int    $amount   金額。
	 * @param bool   $is_from  最安として幅表記を付けるか。
	 * @param string $unit     金額の単位。
	 * @return string エスケープ済みのマークアップ。
	 */
	public static function format_price_with_unit_html( int $amount, bool $is_from, string $unit ): string {
		$formatted = self::format_currency( $amount );
		if ( $is_from ) {
			$formatted = sprintf(
				/* translators: %s: 最低料金。 */
				__( '%s~', 'vk-booking-manager' ),
				$formatted
			);
		}

		return sprintf(
			'<span class="vkbm-price-chunk">%1$s / %2$s</span>',
			esc_html( $formatted ),
			esc_html( $unit )
		);
	}

	/**
	 * 宿泊料金の金額と単位を折り返し可能な別々の塊で返す。
	 *
	 * @param int    $amount  金額。
	 * @param bool   $is_from 最安として幅表記を付けるか。
	 * @param string $unit    金額の単位。
	 * @return string エスケープ済みのマークアップ。
	 */
	public static function format_lodging_price_with_unit_html( int $amount, bool $is_from, string $unit ): string {
		$formatted = self::format_currency( $amount );
		if ( $is_from ) {
			$formatted = sprintf(
				/* translators: %s: 最低料金。 */
				__( '%s~', 'vk-booking-manager' ),
				$formatted
			);
		}

		return sprintf(
			'<span class="vkbm-price-chunk">%1$s</span> / <span class="vkbm-price-chunk">%2$s</span>',
			esc_html( $formatted ),
			esc_html( $unit )
		);
	}
}
