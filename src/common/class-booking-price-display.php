<?php
/**
 * 1ツアーあたりの料金の表示用の整形（#1002）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 料金の単位が「1ツアーあたり」のメニューの金額表記を作る表示用クラス。
 *
 * 金額の計算は Booking_Price に集約している。ここは通貨記号つきの文字列を組み立てるだけで、価格の判断はしない。
 */
class Booking_Price_Display {
	/**
	 * 「1ツアー」の単位表記を返す（金額の後ろに添える）。
	 *
	 * @return string 単位表記（エスケープ前のテキスト）。
	 */
	public static function get_unit_label(): string {
		return __( '1 tour', 'vk-booking-manager' );
	}

	/**
	 * 追加料金の1区分分の表記（「子供 ¥1,500 × 1名」）を返す（#1003）。
	 *
	 * 管理画面の予約詳細・メールで、区分ごとの追加料金の単価と超過人数を示す。人数の単位は基本設定の人数の単位に従う。
	 *
	 * @param array{label: string, price: int, count: int} $tier 追加料金の区分（count は超過人数）。
	 * @return string 表記（エスケープ前のテキスト）。
	 */
	public static function format_extra_guest_tier_text( array $tier ): string {
		return sprintf(
			/* translators: 1: price category name (e.g. Child), 2: extra fee per guest, 3: number of extra guests with unit (e.g. 1 guest). */
			__( '%1$s %2$s × %3$s', 'vk-booking-manager' ),
			(string) ( $tier['label'] ?? '' ),
			VKBM_Helper::format_currency( max( 0, (int) ( $tier['price'] ?? 0 ) ) ),
			vkbm_format_guests_count( max( 0, (int) ( $tier['count'] ?? 0 ) ) )
		);
	}

	/**
	 * 人数の合計に区分ごとの構成を添えた表記（「5名（大人4名・子供1名）」）を返す（#1003）。
	 *
	 * 1ツアーあたりでは区分の単価が追加料金のため、区分ごとの小計の行を出さない。人数の構成は人数の行で伝える。
	 * 人数が0名の区分は構成に含めない。構成にする区分が無いときは人数の合計だけを返す。
	 *
	 * @param int                                                       $guests      人数の合計。
	 * @param array<int, array{label: string, price?: int, count: int}> $guest_tiers 区分ごとの人数内訳。
	 * @return string 表記（エスケープ前のテキスト）。
	 */
	public static function format_guest_composition( int $guests, array $guest_tiers ): string {
		$total       = vkbm_format_guests_count( max( 0, $guests ) );
		$composition = '';

		foreach ( $guest_tiers as $tier ) {
			$count = max( 0, (int) ( $tier['count'] ?? 0 ) );
			if ( $count < 1 ) {
				continue;
			}

			$part = sprintf(
				/* translators: 1: price category name (e.g. Adult), 2: number of guests with unit (e.g. 4 guests). */
				__( '%1$s %2$s', 'vk-booking-manager' ),
				(string) ( $tier['label'] ?? '' ),
				vkbm_format_guests_count( $count )
			);

			$composition = '' === $composition
				? $part
				: sprintf(
					/* translators: 1: price categories listed so far, 2: next price category. */
					__( '%1$s, %2$s', 'vk-booking-manager' ),
					$composition,
					$part
				);
		}

		if ( '' === $composition ) {
			return $total;
		}

		return sprintf(
			/* translators: 1: total number of guests with unit (e.g. 5 guests), 2: breakdown by price category (e.g. Adult 4 guests, Child 1 guest). */
			__( '%1$s (%2$s)', 'vk-booking-manager' ),
			$total,
			$composition
		);
	}

	/**
	 * 金額と単位を並べた表記（「¥30,000 / 1ツアー」）を、折り返さない1つの塊のマークアップで返す。
	 *
	 * 全体を1つの塊（.vkbm-price-chunk。white-space: nowrap）にして、幅の狭い場所でも
	 * 「/ 1ツアー」だけが次の行に落ちず、金額と単位が一緒に折り返されるようにする。
	 *
	 * @param int $price 金額。
	 * @return string エスケープ済みのマークアップ。
	 */
	public static function format_price_with_unit_html( int $price ): string {
		return sprintf(
			'<span class="vkbm-price-chunk">%1$s / %2$s</span>',
			esc_html( VKBM_Helper::format_currency( $price ) ),
			esc_html( self::get_unit_label() )
		);
	}
}
