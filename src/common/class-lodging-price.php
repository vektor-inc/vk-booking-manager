<?php
/**
 * 泊単位のプランの料金計算（#572）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\Staff\Staff_Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 泊単位のプランの合計料金を計算するユーティリティクラス。
 *
 * 合計 ＝ 泊ごとの（プランの料金 ＋ 客室の追加料金）の和（#737）。
 * - 泊ごとの料金区分（平日・休前日・特定日）で、プランの料金の単価が変わる。客室の追加料金は区分によらず一定。
 * - 1名1泊あたり: プランの料金 ＝ 料金区分の金額 × 区分ごとの人数（区分が無ければ 基本料金 × 人数）。
 * - 1室1泊あたり: プランの料金 ＝ 基本料金（人数・料金区分は無視する）。
 * - 1室1泊あたりで規定人数を超えた分の追加料金を使うとき（#934）: プランの料金 ＝ 基本料金 ＋ 超過した人の区分の単価の合計。
 *   単価の高い区分の人から順に基本料金に含め、残った人を超過扱いにする（泊ごとに、その泊の単価で並べる）。
 *
 * calculate() は WordPress のメタを読まない純粋な計算。メタを読む処理は
 * is_applicable() と resolve_room_surcharge_per_night() に分けている。
 * 予約の下書き・確定・管理画面の処理からの呼び出しは #574 / #576 で行う。
 *
 * 客室の追加料金・指名料は、宿泊のサイトでは常に 0 円（#736。is_nomination_fee_disabled_for_site()）。
 * 合計の式に客室の追加料金の項が残っているが、宿泊のサイトでは 0 が入る。
 */
class Lodging_Price {
	/**
	 * 泊単位のプランの料金計算を使うメニューかを返す。
	 *
	 * 宿泊が有効なサイト（Pro 版かつ業種プリセットが宿泊）の、予約の単位が「泊」のメニューだけ true。
	 * 無料版・宿泊以外のサイト・時間枠のメニューでは常に false（既存の計算を変えない）。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return bool 泊単位の料金計算を使うなら true。
	 */
	public static function is_applicable( int $menu_id ): bool {
		return Industry_Presets::supports( 'lodging' ) && Service_Menu_Post_Type::is_night_unit( $menu_id );
	}

	/**
	 * 客室の追加料金・指名料を使わないサイトかを返す。
	 *
	 * 宿泊が有効なサイトは、泊のプランも同じサイトで併用する時間枠のプラン（日帰り入浴など）も、追加料金・指名料を常に 0 円にする。
	 * 画面の非表示だけに頼らず、料金を計算する処理がこの判定でサーバー側から 0 円にする。保存済みの金額は消さない。
	 *
	 * @return bool 追加料金・指名料を 0 円にするなら true。
	 */
	public static function is_nomination_fee_disabled_for_site(): bool {
		return Industry_Presets::supports( 'lodging' );
	}

	/**
	 * 料金計算（calculate）に渡す、メニューの料金設定とサイトの休前日・特定日の設定を返す。
	 *
	 * 返す配列に、泊の日付（night_dates）・人数（guests）・料金区分の内訳（guest_tiers）・客室の追加料金（room_surcharge）を
	 * 足して calculate() に渡す。休前日・特定日の料金が空欄のメニューは該当キーが空文字になる。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @return array<string, mixed> price_unit, extra_guest_enabled, included_guests, base_price, base_price_pre_holiday, base_price_special, pre_holiday_weekdays, special_periods。
	 */
	public static function get_plan_inputs( int $menu_id ): array {
		$rate_settings = Lodging_Rate::get_settings();

		return array(
			'price_unit'             => Service_Menu_Post_Type::get_night_price_unit( $menu_id ),
			'extra_guest_enabled'    => Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $menu_id ),
			'included_guests'        => Service_Menu_Post_Type::get_night_included_guests( $menu_id ),
			'base_price'             => (int) get_post_meta( $menu_id, '_vkbm_base_price', true ),
			'base_price_pre_holiday' => get_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY, true ),
			'base_price_special'     => get_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL, true ),
			'pre_holiday_weekdays'   => $rate_settings['weekdays'],
			'special_periods'        => $rate_settings['special_periods'],
		);
	}

	/**
	 * 客室の追加料金（1室・1泊あたり）を返す。
	 *
	 * 呼び出し元は泊の予約の料金計算（Lodging_Booking）だけで、そこは宿泊のサイト（is_applicable() が true）でしか通らない。
	 * 宿泊のサイトは客室ごとの追加料金を使わないため、実際の呼ばれ方では常に 0 になる（#736）。
	 * 冒頭の早期 return（宿泊のサイトなら 0）の後ろにある「メタを読む分岐」は、現在の呼び出し経路では実行されない
	 * （呼び出し元の Lodging_Booking が宿泊のサイトでしか通らないため）。
	 * それでも分岐を残しているのは、将来この関数が宿泊以外のサイトからも呼ばれた場合に、
	 * 客室の指名料のメタ（_vkbm_nomination_fee）の保存済みの金額を従来どおり扱えるようにするため。
	 * 保存済みの金額は消さずに残してあるので、業種プリセットを宿泊以外へ戻したときも元の値が使える。
	 * その分岐では、メニュー側で指名を使わない、または指名料を加算しない設定がオンなら 0。
	 *
	 * @param int $menu_id サービスメニューの投稿ID。
	 * @param int $room_id 客室（リソース）の投稿ID。
	 * @return int 客室の追加料金（0 以上）。
	 */
	public static function resolve_room_surcharge_per_night( int $menu_id, int $room_id ): int {
		if ( $room_id <= 0 || self::is_nomination_fee_disabled_for_site() ) {
			return 0;
		}

		// 既存の指名料の判定（メニュー単位の指名機能・指名料を加算しない設定）に合わせる。
		if ( ! Staff_Editor::is_nomination_enabled_for_menu( $menu_id ) ) {
			return 0;
		}

		if ( '1' === (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true ) ) {
			return 0;
		}

		$raw = get_post_meta( $room_id, Staff_Editor::META_NOMINATION_FEE, true );
		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		return max( 0, (int) $raw );
	}

	/**
	 * 泊単位のプランの合計料金を計算する。
	 *
	 * 泊ごとに料金区分（特定日・休前日・平日）を決めて単価を出し、泊ごとの料金の和を合計にする。
	 * 区分の決め方は Lodging_Rate、空欄の扱いは次のとおり（料金の項目ごとに判定する）。
	 * - 特定日の泊: 特定日の料金が入っていればそれを使う。空欄なら休前日の判定へ進む。
	 * - 休前日の泊: 休前日の料金が入っていればそれを使う。空欄なら平日の料金を使う。
	 * - それ以外: 平日の料金を使う。
	 *
	 * $args のキー:
	 * - nights               (int)    泊数。night_dates があるときは night_dates の件数を使う。0 以下は合計 0。
	 * - night_dates          (array)  各泊の日付（チェックインする日。Y-m-d）。省略時は日付が分からないため、すべて平日の料金で計算する。
	 * - pre_holiday_weekdays (array)  休前日とする曜日キー（Lodging_Rate::WEEKDAY_KEYS）。
	 * - special_periods      (array)  特定日の期間 `[ [ 'start' => ..., 'end' => ... ], ... ]`。
	 * - guests               (int)    人数。1名あたりのときだけ使う。
	 * - price_unit           (string) Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM のとき1室あたり。それ以外は1名あたり。
	 * - extra_guest_enabled  (bool)   1室あたりで、規定人数を超えた分の追加料金を使うか（#934）。true で guest_tiers が1件以上あるときだけ追加料金を足す。
	 * - included_guests      (int)    基本料金に含まれる人数（extra_guest_enabled のときだけ使う。1 未満は 1）。
	 * - base_price           (int)    基本料金（平日。1名・1泊、または1室・1泊）。
	 * - base_price_pre_holiday (int|string|null) 基本料金の休前日。空欄・null は未設定。
	 * - base_price_special     (int|string|null) 基本料金の特定日。空欄・null は未設定。
	 * - guest_tiers          (array)  料金区分の内訳 `[ [ 'label' => ..., 'price' => ..., 'count' => ..., 'pre_holiday_price' => ..., 'special_price' => ... ], ... ]`。
	 *                                 休前日・特定日の料金は任意。1名あたりで1件以上あるときは基本料金の代わりに使う。
	 *                                 1室あたりでは、extra_guest_enabled のときだけ、超過1名・1泊あたりの追加料金として使う（それ以外は無視する）。
	 * - room_surcharge       (int)    客室の追加料金（1室・1泊）。人数にも区分にもよらず一定。
	 *
	 * 戻り値の plan_price_per_night / price_per_night は、すべての泊で同じ金額のときだけ金額が入り、
	 * 泊ごとに違うときは null（誤った単価を表示しないため）。泊ごとの内訳は night_breakdown に入る。
	 *
	 * @param array<string, mixed> $args 計算に使う値。
	 * @return array{nights: int, price_unit: string, plan_price_per_night: int|null, room_surcharge_per_night: int, price_per_night: int|null, total: int, is_uniform_price: bool, night_breakdown: array<int, array{date: string, rate: string, plan_price: int, room_surcharge: int, price: int}>} 計算結果。
	 */
	public static function calculate( array $args ): array {
		$room_surcharge = max( 0, self::to_int( $args['room_surcharge'] ?? 0 ) );

		// 泊の日付があれば日付の件数を泊数とする。無ければ泊数だけを使い、日付を判定できないため平日の料金で計算する。
		$night_dates = array();
		if ( isset( $args['night_dates'] ) && is_array( $args['night_dates'] ) ) {
			$night_dates = array_values( array_filter( $args['night_dates'], 'is_string' ) );
		}
		$nights = array() !== $night_dates ? count( $night_dates ) : max( 0, self::to_int( $args['nights'] ?? 0 ) );

		$is_per_room = Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === ( $args['price_unit'] ?? '' );
		$price_unit  = $is_per_room ? Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM : Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST;

		$weekdays        = Lodging_Rate::normalize_weekdays( $args['pre_holiday_weekdays'] ?? array() );
		$special_periods = isset( $args['special_periods'] ) && is_array( $args['special_periods'] ) ? $args['special_periods'] : array();

		$items = self::collect_items( $args, $is_per_room );

		// 規定人数を超えた分の追加料金（#934）。使うときは、泊ごとに超過する人を決めて、その人数ぶんの区分を項目に足す。
		$extra_guest_items = self::collect_extra_guest_items( $args, $is_per_room );
		$included_guests   = max( 1, self::to_int( $args['included_guests'] ?? 1 ) );

		// 泊ごとの料金を求める。泊数が 0 のときは、1泊あたりの値を返すために日付なし（平日）の1泊分だけ求める。
		$evaluated_dates = array() !== $night_dates ? $night_dates : array_fill( 0, max( 1, $nights ), '' );
		$night_breakdown = array();
		foreach ( $evaluated_dates as $date ) {
			$is_special     = '' !== $date && Lodging_Rate::is_special_date( $date, $special_periods );
			$is_pre_holiday = '' !== $date && Lodging_Rate::is_pre_holiday_date( $date, $weekdays );
			$night_items    = $items;
			if ( array() !== $extra_guest_items ) {
				$night_items = array_merge( $items, self::exclude_included_guests( $extra_guest_items, $included_guests, $is_special, $is_pre_holiday ) );
			}
			$resolved          = self::resolve_night_plan_price( $night_items, $is_special, $is_pre_holiday );
			$night_breakdown[] = array(
				'date'           => $date,
				'rate'           => $resolved['rate'],
				'plan_price'     => $resolved['plan_price'],
				'room_surcharge' => $room_surcharge,
				'price'          => $resolved['plan_price'] + $room_surcharge,
			);
		}

		// すべての泊が同じ金額なら1泊あたりの単価を返し、違うなら null（単価は表示せず内訳で示す）。
		$plan_prices      = array_unique( array_column( $night_breakdown, 'plan_price' ) );
		$is_uniform_price = 1 === count( $plan_prices );
		$plan_price       = $is_uniform_price ? (int) reset( $plan_prices ) : null;

		$total = 0;
		if ( $nights > 0 ) {
			$total = (int) array_sum( array_column( $night_breakdown, 'price' ) );
		}

		// 泊数が 0 のときの内訳は、1泊あたりの値を出すための仮の1件なので返さない。
		if ( $nights < 1 ) {
			$night_breakdown = array();
		}

		return array(
			'nights'                   => $nights,
			'price_unit'               => $price_unit,
			'plan_price_per_night'     => $plan_price,
			'room_surcharge_per_night' => $room_surcharge,
			'price_per_night'          => null === $plan_price ? null : $plan_price + $room_surcharge,
			'total'                    => $total,
			'is_uniform_price'         => $is_uniform_price,
			'night_breakdown'          => $night_breakdown,
		);
	}

	/**
	 * 休前日・特定日の料金が、この滞在に使う料金の項目に1つでも入っているかを返す。
	 *
	 * 予約画面で泊ごとの区分・内訳を出すかどうかの判定に使う。calculate() と同じ項目（1室あたりは基本料金、
	 * 料金区分があれば区分の各行、なければ基本料金。1室あたりで追加料金を使うときは基本料金と区分の各行の両方）だけを見る。人数が 0 の料金区分の行も、プランに設定された項目として見る
	 * （この滞在で人数が付いたかによらず、プランの設定で決まる）。使われない項目に金額が残っていても true にしない。
	 * 平日と同じ金額が入っている場合も「設定している」として true を返す。
	 *
	 * @param array<string, mixed> $args get_plan_inputs() の結果に guest_tiers を足したもの（calculate() と同じキー）。
	 * @return bool 休前日または特定日の料金が入っているなら true。
	 */
	public static function has_rate_tiers( array $args ): bool {
		$is_per_room = Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === ( $args['price_unit'] ?? '' );

		$items = array_merge( self::collect_items( $args, $is_per_room ), self::collect_extra_guest_items( $args, $is_per_room ) );
		foreach ( $items as $item ) {
			if ( null !== $item['pre_holiday'] || null !== $item['special'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 料金の項目（単価と人数）を並べる。
	 *
	 * 1室あたりは基本料金のみ（人数・料金区分は見ない）。1名あたりで料金区分があれば区分の各行、なければ基本料金に人数を掛ける。
	 *
	 * @param array<string, mixed> $args        calculate() と同じキー。
	 * @param bool                 $is_per_room 1室あたりの料金か。
	 * @return array<int, array{weekday: int, pre_holiday: int|null, special: int|null, count: int}> 料金の項目。
	 */
	private static function collect_items( array $args, bool $is_per_room ): array {
		$guests      = max( 0, self::to_int( $args['guests'] ?? 0 ) );
		$base_price  = max( 0, self::to_int( $args['base_price'] ?? 0 ) );
		$guest_tiers = isset( $args['guest_tiers'] ) && is_array( $args['guest_tiers'] ) ? $args['guest_tiers'] : array();

		if ( $is_per_room ) {
			return array( self::build_item( $base_price, $args['base_price_pre_holiday'] ?? null, $args['base_price_special'] ?? null, 1 ) );
		}

		if ( array() === $guest_tiers ) {
			return array( self::build_item( $base_price, $args['base_price_pre_holiday'] ?? null, $args['base_price_special'] ?? null, $guests ) );
		}

		$items = array();
		foreach ( $guest_tiers as $tier ) {
			if ( is_array( $tier ) ) {
				$items[] = self::build_item( $tier['price'] ?? 0, $tier['pre_holiday_price'] ?? null, $tier['special_price'] ?? null, $tier['count'] ?? 0 );
			}
		}

		return $items;
	}

	/**
	 * 1室あたりで規定人数を超えた分の追加料金に使う項目（料金区分の各行。人数は送られた区分ごとの人数）を並べる（#934）。
	 *
	 * 追加料金を使わないとき（1名あたり・extra_guest_enabled が false・区分が1件も無い）は空配列。
	 *
	 * @param array<string, mixed> $args        calculate() と同じキー。
	 * @param bool                 $is_per_room 1室あたりの料金か。
	 * @return array<int, array{weekday: int, pre_holiday: int|null, special: int|null, count: int}> 追加料金の項目。
	 */
	private static function collect_extra_guest_items( array $args, bool $is_per_room ): array {
		$guest_tiers = isset( $args['guest_tiers'] ) && is_array( $args['guest_tiers'] ) ? $args['guest_tiers'] : array();
		if ( ! $is_per_room || empty( $args['extra_guest_enabled'] ) || array() === $guest_tiers ) {
			return array();
		}

		$items = array();
		foreach ( $guest_tiers as $tier ) {
			if ( is_array( $tier ) ) {
				$items[] = self::build_item( $tier['price'] ?? 0, $tier['pre_holiday_price'] ?? null, $tier['special_price'] ?? null, $tier['count'] ?? 0 );
			}
		}

		return $items;
	}

	/**
	 * 1泊分の追加料金の項目から、基本料金に含まれる人数を除いた超過分の項目を返す（#934）。
	 *
	 * その泊に使う単価（特定日 > 休前日 > 平日）で区分を比べ、単価の高い区分の人から順に基本料金に含める。
	 * 返す項目の count は超過した人数。
	 *
	 * @param array<int, array{weekday: int, pre_holiday: int|null, special: int|null, count: int}> $items           追加料金の項目（count は区分ごとの人数）。
	 * @param int                                                                                   $included_guests 基本料金に含まれる人数。
	 * @param bool                                                                                  $is_special      泊の日付が特定日か。
	 * @param bool                                                                                  $is_pre_holiday  泊の日付が休前日か。
	 * @return array<int, array{weekday: int, pre_holiday: int|null, special: int|null, count: int}> 超過分の項目。
	 */
	private static function exclude_included_guests( array $items, int $included_guests, bool $is_special, bool $is_pre_holiday ): array {
		$unit_prices = array();
		$counts      = array();
		foreach ( $items as $index => $item ) {
			$unit_prices[ $index ] = self::resolve_item_unit_price( $item, $is_special, $is_pre_holiday )['unit_price'];
			$counts[ $index ]      = $item['count'];
		}

		$excess_counts = self::distribute_excess_guests( $unit_prices, $counts, $included_guests );
		foreach ( $items as $index => $item ) {
			$items[ $index ]['count'] = $excess_counts[ $index ];
		}

		return $items;
	}

	/**
	 * 区分ごとの人数から、基本料金に含まれる人数を除いた超過人数を区分ごとに返す（#934）。
	 *
	 * 単価の高い区分の人から順に基本料金に含め、残った人を超過扱いにする（客にとって一番安くなる選び方）。
	 * 単価が同じ区分は、並びの先の区分から含める。人数の合計が含まれる人数以内なら、超過は全区分 0。
	 *
	 * @param array<int, int> $unit_prices     区分ごとの単価（キーは区分の並び順）。
	 * @param array<int, int> $counts          区分ごとの人数（$unit_prices と同じキー）。
	 * @param int             $included_guests 基本料金に含まれる人数。
	 * @return array<int, int> 区分ごとの超過人数（$unit_prices と同じキー・並び順）。
	 */
	public static function distribute_excess_guests( array $unit_prices, array $counts, int $included_guests ): array {
		$order = array_keys( $unit_prices );
		usort(
			$order,
			static function ( $a, $b ) use ( $unit_prices ): int {
				// 単価の高い順。同額は元の並びの順を保つ。
				if ( $unit_prices[ $a ] === $unit_prices[ $b ] ) {
					return $a <=> $b;
				}

				return $unit_prices[ $b ] <=> $unit_prices[ $a ];
			}
		);

		$remaining_included = max( 0, $included_guests );
		$excess_counts      = array();
		foreach ( $order as $index ) {
			$count                   = max( 0, (int) ( $counts[ $index ] ?? 0 ) );
			$included_count          = min( $count, $remaining_included );
			$remaining_included     -= $included_count;
			$excess_counts[ $index ] = $count - $included_count;
		}

		// 呼び出し元が区分の並び順で受け取れるよう、キーの順に戻す。
		ksort( $excess_counts );

		return $excess_counts;
	}

	/**
	 * 料金の1項目（基本料金、または料金区分の1行）を、計算用の形に整える。
	 *
	 * @param mixed $weekday_price     平日の料金。
	 * @param mixed $pre_holiday_price 休前日の料金。空欄・null は未設定。
	 * @param mixed $special_price     特定日の料金。空欄・null は未設定。
	 * @param mixed $count             この料金を掛ける人数（1室あたりは 1）。
	 * @return array{weekday: int, pre_holiday: int|null, special: int|null, count: int}
	 */
	private static function build_item( $weekday_price, $pre_holiday_price, $special_price, $count ): array {
		return array(
			'weekday'     => max( 0, self::to_int( $weekday_price ) ),
			'pre_holiday' => self::to_optional_int( $pre_holiday_price ),
			'special'     => self::to_optional_int( $special_price ),
			'count'       => max( 0, self::to_int( $count ) ),
		);
	}

	/**
	 * 1泊分のプランの料金と、その泊に適用した料金区分を求める。
	 *
	 * 料金の項目ごとに、特定日の料金 → 休前日の料金 → 平日の料金の順で、入っている最初の金額を使う。
	 * 泊の料金区分は、人数のある項目で実際に使った区分のうち、特定日 > 休前日 > 平日の順で最も優先度が高いもの。
	 *
	 * @param array<int, array{weekday: int, pre_holiday: int|null, special: int|null, count: int}> $items          料金の項目。
	 * @param bool                                                                                  $is_special     泊の日付が特定日か。
	 * @param bool                                                                                  $is_pre_holiday 泊の日付が休前日か。
	 * @return array{rate: string, plan_price: int} 適用した料金区分と、その泊のプランの料金。
	 */
	private static function resolve_night_plan_price( array $items, bool $is_special, bool $is_pre_holiday ): array {
		$plan_price = 0;
		$rate       = Lodging_Rate::RATE_WEEKDAY;

		foreach ( $items as $item ) {
			$resolved = self::resolve_item_unit_price( $item, $is_special, $is_pre_holiday );

			$plan_price += $resolved['unit_price'] * $item['count'];

			// 人数のない項目（区分の人数が 0）は、泊の区分の判定に使わない。
			if ( $item['count'] > 0 && self::get_rate_priority( $resolved['rate'] ) > self::get_rate_priority( $rate ) ) {
				$rate = $resolved['rate'];
			}
		}

		return array(
			'rate'       => $rate,
			'plan_price' => $plan_price,
		);
	}

	/**
	 * 料金の1項目の、その泊に使う単価と料金区分を求める。
	 *
	 * 特定日の料金 → 休前日の料金 → 平日の料金の順で、入っている最初の金額を使う。
	 *
	 * @param array{weekday: int, pre_holiday: int|null, special: int|null, count: int} $item           料金の項目。
	 * @param bool                                                                      $is_special     泊の日付が特定日か。
	 * @param bool                                                                      $is_pre_holiday 泊の日付が休前日か。
	 * @return array{unit_price: int, rate: string} その泊の単価と、使った料金区分。
	 */
	private static function resolve_item_unit_price( array $item, bool $is_special, bool $is_pre_holiday ): array {
		if ( $is_special && null !== $item['special'] ) {
			return array(
				'unit_price' => $item['special'],
				'rate'       => Lodging_Rate::RATE_SPECIAL,
			);
		}

		if ( $is_pre_holiday && null !== $item['pre_holiday'] ) {
			return array(
				'unit_price' => $item['pre_holiday'],
				'rate'       => Lodging_Rate::RATE_PRE_HOLIDAY,
			);
		}

		return array(
			'unit_price' => $item['weekday'],
			'rate'       => Lodging_Rate::RATE_WEEKDAY,
		);
	}

	/**
	 * 料金区分の優先度を返す（大きいほど優先）。
	 *
	 * @param string $rate Lodging_Rate の料金区分。
	 * @return int 優先度。
	 */
	private static function get_rate_priority( string $rate ): int {
		if ( Lodging_Rate::RATE_SPECIAL === $rate ) {
			return 2;
		}

		return Lodging_Rate::RATE_PRE_HOLIDAY === $rate ? 1 : 0;
	}

	/**
	 * 任意の金額を整数に丸める。空欄・null・数値でない値は null（未設定）。
	 *
	 * @param mixed $value 入力値。
	 * @return int|null 0 以上の整数。未設定なら null。
	 */
	public static function to_optional_int( $value ): ?int {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/**
	 * 数値または数値文字列を整数に丸める。数値でなければ 0。
	 *
	 * @param mixed $value 入力値。
	 * @return int
	 */
	private static function to_int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
