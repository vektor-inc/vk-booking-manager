<?php
/**
 * 泊単位のプランの料金区分・内訳の表示用データと、メニュー一覧の料金表記（#738）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 料金の計算結果（Lodging_Price::calculate）を、予約画面とメニュー一覧の表示に使える形に整えるクラス。
 *
 * 金額の計算は Lodging_Price が行い、ここでは計算し直さない。
 * - 予約画面: 泊ごとの区分・内訳を出すか（休前日・特定日の料金を設定したプランだけ）と、特定日の名称を足す。
 * - メニュー一覧: 休前日・特定日のどちらかが平日と違う金額のプランを、最安＋「〜」で表記する。
 */
class Lodging_Price_Display {
	/**
	 * 予約画面に出す、泊ごとの区分の表示用データを作る。
	 *
	 * 休前日・特定日の料金を設定していないプランは enabled が false・night_rates が空配列になり、画面は区分も内訳も出さない。
	 * enabled のときの night_rates は、泊ごとの日付・料金区分・特定日の名称（special_label）。
	 * 金額は計算結果の night_breakdown のまま使い、ここでは計算し直さない（night_breakdown に名称は足さない。予約に保存する内訳と同じ形を保つため）。
	 *
	 * @param array<string, mixed>                                                                            $plan_inputs     Lodging_Price::get_plan_inputs() の結果。
	 * @param array<int, array<mixed>>                                                                        $guest_tiers     料金区分の内訳（無ければ空配列）。
	 * @param array<int, array{date: string, rate: string, plan_price: int, room_surcharge: int, price: int}> $night_breakdown Lodging_Price::calculate() の night_breakdown。
	 * @return array{enabled: bool, night_rates: array<int, array{date: string, rate: string, special_label: string}>} 表示用データ。
	 */
	public static function build_stay_display( array $plan_inputs, array $guest_tiers, array $night_breakdown ): array {
		$enabled = Lodging_Price::has_rate_tiers( array_merge( $plan_inputs, array( 'guest_tiers' => $guest_tiers ) ) );
		if ( ! $enabled ) {
			return array(
				'enabled'     => false,
				'night_rates' => array(),
			);
		}

		$special_periods = isset( $plan_inputs['special_periods'] ) && is_array( $plan_inputs['special_periods'] ) ? $plan_inputs['special_periods'] : array();
		$night_rates     = array();
		foreach ( $night_breakdown as $night ) {
			$date = (string) ( $night['date'] ?? '' );
			$rate = (string) ( $night['rate'] ?? Lodging_Rate::RATE_WEEKDAY );

			// 名称は、特定日の料金を実際に使った泊にだけ付ける（特定日の期間でも、料金が空欄で休前日・平日になった泊には付けない）。
			$night_rates[] = array(
				'date'          => $date,
				'rate'          => $rate,
				'special_label' => Lodging_Rate::RATE_SPECIAL === $rate ? Lodging_Rate::find_special_label( $date, $special_periods ) : '',
			);
		}

		return array(
			'enabled'     => true,
			'night_rates' => $night_rates,
		);
	}

	/**
	 * メニュー一覧の料金表記に使う行を作る。休前日・特定日のどちらも平日と同じ金額なら空配列。
	 *
	 * 行ごとに、平日・休前日・特定日のうち入っている金額の最安を price に入れる。
	 * 休前日・特定日のどちらかが平日と違う金額の行は is_from を true にする（「〜」を付ける行）。
	 * 1室あたり・料金区分なしは基本料金の 1 行（label は空）、料金区分ありは区分ごとの行。
	 * 1行も平日と違う金額が無ければ、一覧は今までの表示のままにするため空配列を返す。
	 *
	 * @param string                                                                                       $price_unit        料金の単位（Service_Menu_Post_Type::NIGHT_PRICE_UNIT_*）。
	 * @param int                                                                                          $base_price        基本料金（平日）。
	 * @param mixed                                                                                        $base_pre_holiday  基本料金の休前日。空欄・null は未設定。
	 * @param mixed                                                                                        $base_special      基本料金の特定日。空欄・null は未設定。
	 * @param array<int, array{label?: string, price?: int, pre_holiday_price?: int, special_price?: int}> $tiers            料金区分（1室あたりでは無視する）。
	 * @param bool                                                                                         $has_special_periods BM設定に特定日の期間があるか。false なら特定日の金額は予約で使われないため、最安の候補にしない。
	 * @return array<int, array{label: string, price: int, is_from: bool}> 表記する行。
	 */
	public static function build_menu_price_rows( string $price_unit, int $base_price, $base_pre_holiday, $base_special, array $tiers, bool $has_special_periods = true ): array {
		$entries = array();
		if ( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $price_unit || array() === $tiers ) {
			$entries[] = array(
				'label'       => '',
				'weekday'     => $base_price,
				'pre_holiday' => $base_pre_holiday,
				'special'     => $has_special_periods ? $base_special : null,
			);
		} else {
			foreach ( $tiers as $tier ) {
				$entries[] = array(
					'label'       => (string) ( $tier['label'] ?? '' ),
					'weekday'     => (int) ( $tier['price'] ?? 0 ),
					'pre_holiday' => $tier['pre_holiday_price'] ?? null,
					'special'     => $has_special_periods ? ( $tier['special_price'] ?? null ) : null,
				);
			}
		}

		$rows       = array();
		$has_varied = false;
		foreach ( $entries as $entry ) {
			$weekday = max( 0, (int) $entry['weekday'] );
			$prices  = array( $weekday );
			$varies  = false;
			foreach ( array( $entry['pre_holiday'], $entry['special'] ) as $optional ) {
				$price = Lodging_Price::to_optional_int( $optional );
				if ( null === $price ) {
					continue;
				}
				$prices[] = $price;
				$varies   = $varies || $price !== $weekday;
			}

			$has_varied = $has_varied || $varies;
			$rows[]     = array(
				'label'   => $entry['label'],
				'price'   => min( $prices ),
				'is_from' => $varies,
			);
		}

		return $has_varied ? $rows : array();
	}

	/**
	 * メニューの一覧に出す、最安＋「〜」の料金の行を返す。
	 *
	 * 宿泊が有効なサイトの泊単位のプランで、休前日・特定日のどちらかが平日と違う金額のときだけ行を返す。
	 * BM設定に特定日の期間が1つも無いときは、特定日の金額を最安の候補から外す（予約で使われない金額を出さないため）。
	 * それ以外（時間枠のプラン・宿泊以外のサイト・年中同じ料金のプラン）は空配列で、一覧は今までの表示のまま。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return array<int, array{label: string, price: int, is_from: bool}> 表記する行。
	 */
	public static function get_menu_price_rows( int $menu_id ): array {
		if ( ! Lodging_Price::is_applicable( $menu_id ) ) {
			return array();
		}

		// 休前日は祝日の前日も当たり、曜日が空でも祝日データがあれば発生しうるため、金額は候補に残す。特定日は期間が無ければ発生しない。
		$rate_settings = Lodging_Rate::get_settings();

		return self::build_menu_price_rows(
			Service_Menu_Post_Type::get_night_price_unit( $menu_id ),
			(int) get_post_meta( $menu_id, '_vkbm_base_price', true ),
			get_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY, true ),
			get_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL, true ),
			Lodging_Booking::get_menu_price_tiers( $menu_id ),
			array() !== $rate_settings['special_periods']
		);
	}

	/**
	 * 料金の単位の表記（「1名・1泊」「1室・1泊」）を返す。
	 *
	 * @param string $price_unit 料金の単位（Service_Menu_Post_Type::NIGHT_PRICE_UNIT_*）。
	 * @return string 単位の表記。
	 */
	public static function format_unit_label( string $price_unit ): string {
		if ( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $price_unit ) {
			return __( '1 room, 1 night', 'vk-booking-manager' );
		}

		return sprintf(
			/* translators: %s: one guest with the unit (e.g. 1 guest). */
			__( '%s, 1 night', 'vk-booking-manager' ),
			vkbm_format_guests_count( 1 )
		);
	}

	/**
	 * 金額の表記を返す。「〜」を付ける行は「¥7,000〜」の形にする。
	 *
	 * @param int  $price   金額。
	 * @param bool $is_from 最安として「〜」を付けるか。
	 * @return string 金額の表記（エスケープ前のテキスト）。
	 */
	public static function format_amount( int $price, bool $is_from ): string {
		$amount = VKBM_Helper::format_currency( $price );
		if ( ! $is_from ) {
			return $amount;
		}

		return sprintf(
			/* translators: %s: the lowest price (e.g. ¥7,000). The price differs by day, so it is shown as "from". */
			__( '%s~', 'vk-booking-manager' ),
			$amount
		);
	}

	/**
	 * 金額と単位を並べた表記（「¥7,000〜 / 1名・1泊」）を返す。
	 *
	 * @param int    $price      金額。
	 * @param bool   $is_from    最安として「〜」を付けるか。
	 * @param string $price_unit 料金の単位（Service_Menu_Post_Type::NIGHT_PRICE_UNIT_*）。
	 * @return string 表記（エスケープ前のテキスト）。
	 */
	public static function format_price_with_unit( int $price, bool $is_from, string $price_unit ): string {
		return self::format_amount( $price, $is_from ) . ' / ' . self::format_unit_label( $price_unit );
	}

	/**
	 * 金額と単位を並べた表記を、折り返し位置を制御したマークアップで返す（「¥7,000〜」と「1名・1泊」を別々の塊にする）。
	 *
	 * 幅の狭い場所でも、金額の途中や単位の途中では折り返さず、「 / 」の境目で折り返す。
	 * 塊の white-space: nowrap は各画面の CSS（.vkbm-price-chunk）で指定する。
	 *
	 * @param int    $price      金額。
	 * @param bool   $is_from    最安として「〜」を付けるか。
	 * @param string $price_unit 料金の単位（Service_Menu_Post_Type::NIGHT_PRICE_UNIT_*）。
	 * @return string エスケープ済みのマークアップ。
	 */
	public static function format_price_with_unit_html( int $price, bool $is_from, string $price_unit ): string {
		return sprintf(
			'<span class="vkbm-price-chunk">%1$s</span> / <span class="vkbm-price-chunk">%2$s</span>',
			esc_html( self::format_amount( $price, $is_from ) ),
			esc_html( self::format_unit_label( $price_unit ) )
		);
	}
}
