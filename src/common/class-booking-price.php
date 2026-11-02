<?php
/**
 * 時間枠のメニューの料金の単位（1名あたり／1ツアーあたり）と、サービス基本料金の合計計算（#1002）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 時間枠のメニューのサービス基本料金の合計を求める、データを読まない純粋な計算クラス。
 *
 * 予約の下書き（Booking_Draft_Controller）・確定（Booking_Confirmation_Controller）・管理画面の予約編集・
 * 通知メール・マイページが同じ合計になるよう、計算をここ1か所にまとめる。
 * WordPress のメタは読まない（メニューや予約から読んだ値を呼び出し側が渡す）ため、PHPUnit でそのまま検証できる。
 *
 * 料金の単位:
 * - 1名あたり（既定）: 料金区分があれば「区分の単価 × 区分の人数」の合計、無ければ「基本料金 × 人数」。
 * - 1ツアーあたり: 人数にかかわらず「基本料金」そのまま。規定人数を超える人数分の追加料金を使うときは、
 *   超過人数分の区分単価の合計（#1003）を基本料金に足す。
 * どちらも指名料・貸し切り料金は別に足す（指名料は人数にかかわらず1回分）。
 *
 * 宿泊プランの料金の単位（Lodging_Price。1名1泊あたり／1室1泊あたり）とは別の仕組み。保存項目・計算は共有しない。
 */
class Booking_Price {
	/**
	 * 料金の単位: 1名あたり（既定。保存なしもこの扱い）。
	 */
	public const UNIT_PER_GUEST = 'per_guest';

	/**
	 * 料金の単位: 1ツアーあたり（人数にかかわらず基本料金そのまま）。
	 */
	public const UNIT_PER_BOOKING = 'per_booking';

	/**
	 * 予約に保存する、予約時点の料金の単位のメタキー。
	 *
	 * 1ツアーあたりの予約のときだけ UNIT_PER_BOOKING を保存する（保存なし＝1名あたり）。
	 * 後からメニューの設定を変えても、予約時点の金額表示が崩れないようにするためのスナップショット。
	 */
	public const META_BOOKING_PRICE_UNIT = '_vkbm_booking_price_unit';

	/**
	 * 予約に保存する、規定人数を超えた分の追加料金の内訳のメタキー（#1003）。
	 *
	 * 1ツアーあたりの予約で、超過人数が1名以上いるときだけ保存する。
	 * 値は `[ 'included_guests' => int, 'tiers' => [ [ 'label' => string, 'price' => int, 'count' => int ], ... ] ]`
	 * （tiers は超過した人数がいる区分だけ。price は予約時点の追加料金の単価、count は超過人数）。
	 * 後からメニューの設定を変えても、予約時点のツアー料金と追加料金の表示が崩れないようにするためのスナップショット。
	 */
	public const META_BOOKING_EXTRA_GUESTS = '_vkbm_booking_extra_guests';

	/**
	 * 料金の単位の値を正規化する。
	 *
	 * @param mixed $unit 料金の単位の候補。
	 * @return string UNIT_PER_BOOKING または UNIT_PER_GUEST。
	 */
	public static function normalize_unit( $unit ): string {
		return self::UNIT_PER_BOOKING === $unit ? self::UNIT_PER_BOOKING : self::UNIT_PER_GUEST;
	}

	/**
	 * 料金の単位が「1ツアーあたり」かを返す。
	 *
	 * @param mixed $unit 料金の単位の候補。
	 * @return bool 1ツアーあたりなら true。
	 */
	public static function is_per_booking( $unit ): bool {
		return self::UNIT_PER_BOOKING === $unit;
	}

	/**
	 * サービス基本料金の合計（指名料・貸し切り料金を含まない）を計算する。
	 *
	 * $args のキー:
	 * - price_unit  (string) 料金の単位。UNIT_PER_BOOKING のとき1ツアーあたり。それ以外は1名あたり。
	 * - base_price  (int)    基本料金（1名あたりは1名分、1ツアーあたりは1ツアー分）。
	 * - guests      (int)    申込人数（1未満は1名として扱う）。1名あたりで料金区分が無いときの掛け算に使う。
	 * - guest_tiers (array)  料金区分の人数内訳 `[ [ 'label' => ..., 'price' => ..., 'count' => ... ], ... ]`。
	 *                        1名あたりで1件以上あるときは基本料金の代わりに「区分の単価 × 区分の人数」の合計を使う。
	 *                        1ツアーあたりでは無視する（追加料金は extra_guest_tiers で渡す）。
	 * - extra_guest_tiers (array) 1ツアーあたりで、基本料金に含まれる人数を超えた人数の内訳
	 *                        `[ [ 'label' => ..., 'price' => ..., 'count' => 超過人数 ], ... ]`（resolve_extra_guest_tiers() の戻り値）。
	 *                        「区分の単価 × 超過人数」の合計を基本料金に足す。1名あたりでは無視する。
	 *
	 * @param array<string, mixed> $args 計算に使う値。
	 * @return int サービス基本料金の合計（0 以上）。
	 */
	public static function calculate_service_total( array $args ): int {
		$base_price = max( 0, self::to_int( $args['base_price'] ?? 0 ) );

		// 1ツアーあたりは人数にも料金区分にもよらず基本料金そのまま。規定人数を超えた人数分の追加料金だけを足す。
		if ( self::is_per_booking( $args['price_unit'] ?? '' ) ) {
			$extra_guest_tiers = isset( $args['extra_guest_tiers'] ) && is_array( $args['extra_guest_tiers'] ) ? $args['extra_guest_tiers'] : array();

			return $base_price + Price_Tiers::total_price( $extra_guest_tiers );
		}

		// 1名あたりで料金区分があれば、区分の単価 × 区分の人数の合計を使う。
		$guest_tiers = isset( $args['guest_tiers'] ) && is_array( $args['guest_tiers'] ) ? $args['guest_tiers'] : array();
		if ( array() !== $guest_tiers ) {
			return max( 0, Price_Tiers::total_price( $guest_tiers ) );
		}

		// 区分が無ければ基本料金 × 人数。
		return $base_price * max( 1, self::to_int( $args['guests'] ?? 1 ) );
	}

	/**
	 * 基本料金合計（サービス基本料金 ＋ 指名料 ＋ 貸し切り料金）を計算する。
	 *
	 * $args は calculate_service_total() のキーに加えて次を受け取る。
	 * - nomination_fee (int) 指名料（人数にかかわらず1回分）。
	 * - exclusive_fee  (int) 貸し切り料金。
	 *
	 * @param array<string, mixed> $args 計算に使う値。
	 * @return int 基本料金合計（0 以上）。
	 */
	public static function calculate_total( array $args ): int {
		return max(
			0,
			self::calculate_service_total( $args )
				+ max( 0, self::to_int( $args['nomination_fee'] ?? 0 ) )
				+ max( 0, self::to_int( $args['exclusive_fee'] ?? 0 ) )
		);
	}

	/**
	 * 1ツアーあたりで、基本料金に含まれる人数を超えた人数の内訳を求める（#1003）。
	 *
	 * 超過扱いにする人は「単価の高い区分の人から順に基本料金に含め、残りの人を超過扱い」にする
	 * （宿泊の超過人数の割り振り Lodging_Price::distribute_excess_guests() と同じ。客にとって一番安くなる選び方）。
	 * 戻り値の count は区分ごとの超過人数で、超過した人がいない区分は含めない。区分の並び順は元のまま保つ。
	 *
	 * @param array<int, array{label: string, price: int, count: int}> $guest_tiers     区分ごとの人数内訳。price は超過1名あたりの追加料金。
	 * @param int                                                      $included_guests 基本料金に含まれる人数。
	 * @return array<int, array{label: string, price: int, count: int}> 超過した人がいる区分と、その超過人数。
	 */
	public static function resolve_extra_guest_tiers( array $guest_tiers, int $included_guests ): array {
		$tiers       = array_values( Price_Tiers::normalize_guest_tiers( $guest_tiers ) );
		$unit_prices = array();
		$counts      = array();
		foreach ( $tiers as $index => $tier ) {
			$unit_prices[ $index ] = $tier['price'];
			$counts[ $index ]      = $tier['count'];
		}

		$excess_counts = Lodging_Price::distribute_excess_guests( $unit_prices, $counts, $included_guests );

		$extra_tiers = array();
		foreach ( $tiers as $index => $tier ) {
			$excess_count = (int) ( $excess_counts[ $index ] ?? 0 );
			if ( $excess_count < 1 ) {
				continue;
			}

			$extra_tiers[] = array(
				'label' => $tier['label'],
				'price' => $tier['price'],
				'count' => $excess_count,
			);
		}

		return $extra_tiers;
	}

	/**
	 * 予約に保存済みの追加料金の内訳（META_BOOKING_EXTRA_GUESTS）を正規化して返す（#1003）。
	 *
	 * 配列でない・超過した人がいる区分が1件も無いときは空配列（追加料金なしの予約）。
	 *
	 * @param mixed $raw get_post_meta() で取得した追加料金の内訳。
	 * @return array{included_guests: int, tiers: array<int, array{label: string, price: int, count: int}>}|array{} 追加料金の内訳。
	 */
	public static function normalize_extra_guests( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$tiers = array();
		foreach ( Price_Tiers::normalize_guest_tiers( $raw['tiers'] ?? array() ) as $tier ) {
			if ( $tier['count'] < 1 ) {
				continue;
			}

			$tiers[] = array(
				'label' => $tier['label'],
				'price' => $tier['price'],
				'count' => $tier['count'],
			);
		}

		if ( array() === $tiers ) {
			return array();
		}

		return array(
			'included_guests' => max( 1, self::to_int( $raw['included_guests'] ?? 1 ) ),
			'tiers'           => $tiers,
		);
	}

	/**
	 * 値を整数へ変換する。数値として読めないものは 0。
	 *
	 * @param mixed $value 変換する値。
	 * @return int 整数。
	 */
	private static function to_int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
