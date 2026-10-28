<?php
/**
 * 1室1泊あたりのプランで、規定人数を超えた分を1名ごとに加算する料金計算のテスト（#934）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Lodging_Price;
use WP_UnitTestCase;

/**
 * Lodging_Price の「規定人数を超えた分の追加料金」の計算を検証する。
 *
 * 休前日の曜日は土曜のみ。日付は 2026-10-09（金）・2026-10-10（土）・2026-10-12（月）を使う。
 *
 * @group common
 * @group lodging
 */
class Lodging_Price_Extra_Guest_Test extends WP_UnitTestCase {

	/**
	 * 計算に渡す共通の引数（1室あたり・追加料金あり）を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値。
	 * @return array<string, mixed> calculate() に渡す引数。
	 */
	private function build_args( array $override ): array {
		return array_merge(
			array(
				'price_unit'           => 'per_room',
				'extra_guest_enabled'  => true,
				'included_guests'      => 4,
				'base_price'           => 10000,
				'night_dates'          => array( '2026-10-12' ),
				'pre_holiday_weekdays' => array( 'sat' ),
			),
			$override
		);
	}

	/**
	 * 区分の行を作る。
	 *
	 * @param string $label    区分名。
	 * @param int    $price    平日の追加料金。
	 * @param int    $count    人数。
	 * @param array  $optional 休前日・特定日の追加料金（pre_holiday_price / special_price）。
	 * @return array<string, mixed> 区分の行。
	 */
	private function tier( string $label, int $price, int $count, array $optional = array() ): array {
		return array_merge(
			array(
				'label' => $label,
				'price' => $price,
				'count' => $count,
			),
			$optional
		);
	}

	/**
	 * distribute_excess_guests: 単価の高い区分の人から基本料金に含め、残りを超過人数として返す。
	 */
	public function test_distribute_excess_guests(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '合計が規定人数以内 => 超過は全区分 0',
				'unit_prices'         => array( 3000, 2000 ),
				'counts'              => array( 2, 2 ),
				'included'            => 4,
				'expected'            => array( 0, 0 ),
			),
			array(
				'test_condition_name' => '規定 4 名・大人 3 名＋子供 2 名 => 高い大人 3 名＋子供 1 名が含まれ、子供 1 名が超過',
				'unit_prices'         => array( 3000, 2000 ),
				'counts'              => array( 3, 2 ),
				'included'            => 4,
				'expected'            => array( 0, 1 ),
			),
			array(
				'test_condition_name' => '区分の並びが逆（安い区分が先）でも、高い区分から含める',
				'unit_prices'         => array( 2000, 3000 ),
				'counts'              => array( 1, 2 ),
				'included'            => 2,
				'expected'            => array( 1, 0 ),
			),
			array(
				'test_condition_name' => '単価が同じ区分は並びの先から含める',
				'unit_prices'         => array( 2000, 2000 ),
				'counts'              => array( 2, 2 ),
				'included'            => 3,
				'expected'            => array( 0, 1 ),
			),
			array(
				'test_condition_name' => '規定人数 0 以下は全員が超過',
				'unit_prices'         => array( 3000, 2000 ),
				'counts'              => array( 1, 1 ),
				'included'            => 0,
				'expected'            => array( 1, 1 ),
			),
			array(
				'test_condition_name' => '人数が負の区分は 0 名として扱う',
				'unit_prices'         => array( 3000, 2000 ),
				'counts'              => array( -2, 1 ),
				'included'            => 1,
				'expected'            => array( 0, 0 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Lodging_Price::distribute_excess_guests( $case['unit_prices'], $case['counts'], $case['included'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * calculate: 規定人数以内は基本料金のみ、超えた分は高い区分から基本料金に含めて超過分を加算する。
	 */
	public function test_calculate(): void {
		$adult  = $this->tier( '大人', 3000, 3 );
		$child  = $this->tier( '子供', 2000, 2 );
		$infant = $this->tier( '幼児', 0, 1 );

		$test_cases = array(
			array(
				'test_condition_name' => '規定人数以内（大人 2 名＋子供 2 名・規定 4 名）=> 基本料金のみ',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $this->tier( '大人', 3000, 2 ), $this->tier( '子供', 2000, 2 ) ) ) ),
				'expected'            => array(
					'total'                => 10000,
					'plan_price_per_night' => 10000,
				),
			),
			array(
				'test_condition_name' => '大人 3 名＋子供 2 名・規定 4 名 => 大人 3 名＋子供 1 名が含まれ、子供 1 名分 2,000 円を加算',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $adult, $child ) ) ),
				'expected'            => array(
					'total'                => 12000,
					'plan_price_per_night' => 12000,
					'price_per_night'      => 12000,
				),
			),
			array(
				'test_condition_name' => '大人 4 名＋幼児（0 円）1 名・規定 4 名 => 幼児が超過だが 0 円なので加算なし',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $this->tier( '大人', 3000, 4 ), $this->tier( '幼児', 0, 1 ) ) ) ),
				'expected'            => array( 'total' => 10000 ),
			),
			array(
				'test_condition_name' => '大人 5 名・規定 4 名 => 大人 1 名分 3,000 円を加算',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $this->tier( '大人', 3000, 5 ) ) ) ),
				'expected'            => array( 'total' => 13000 ),
			),
			array(
				'test_condition_name' => '区分の並びが子供→大人でも、高い大人から含める（規定 2 名・子供 1 名＋大人 2 名 => 子供 1 名が超過）',
				'args'                => $this->build_args(
					array(
						'included_guests' => 2,
						'guest_tiers'     => array( $this->tier( '子供', 2000, 1 ), $this->tier( '大人', 3000, 2 ) ),
					)
				),
				'expected'            => array( 'total' => 12000 ),
			),
			array(
				'test_condition_name' => '3 区分（大人 3,000・子供 2,000・幼児 0）で規定 4 名・大人 3 名＋子供 2 名＋幼児 1 名 => 超過は子供 1 名＋幼児 1 名 = 2,000 円',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $adult, $child, $infant ) ) ),
				'expected'            => array( 'total' => 12000 ),
			),
			array(
				'test_condition_name' => '2 泊 => 泊ごとの料金 12,000 円 × 2',
				'args'                => $this->build_args(
					array(
						'night_dates' => array( '2026-10-12', '2026-10-13' ),
						'guest_tiers' => array( $adult, $child ),
					)
				),
				'expected'            => array( 'total' => 24000 ),
			),
			array(
				'test_condition_name' => '客室の追加料金は超過分と別に 1 泊ごとに加算する',
				'args'                => $this->build_args(
					array(
						'room_surcharge' => 1500,
						'guest_tiers'    => array( $adult, $child ),
					)
				),
				'expected'            => array(
					'total'                    => 13500,
					'room_surcharge_per_night' => 1500,
					'price_per_night'          => 13500,
				),
			),
			array(
				'test_condition_name' => '規定人数が 0 以下でも 1 名として扱う（大人 2 名 => 1 名が超過）',
				'args'                => $this->build_args(
					array(
						'included_guests' => 0,
						'guest_tiers'     => array( $this->tier( '大人', 3000, 2 ) ),
					)
				),
				'expected'            => array( 'total' => 13000 ),
			),
			array(
				'test_condition_name' => 'チェックなし（追加料金を使わない）=> 区分が渡されても基本料金のみ',
				'args'                => $this->build_args(
					array(
						'extra_guest_enabled' => false,
						'guest_tiers'         => array( $adult, $child ),
					)
				),
				'expected'            => array( 'total' => 10000 ),
			),
			array(
				'test_condition_name' => 'チェックありでも区分が 1 件も無い => 基本料金のみ',
				'args'                => $this->build_args( array( 'guest_tiers' => array() ) ),
				'expected'            => array( 'total' => 10000 ),
			),
			array(
				'test_condition_name' => '1 名 1 泊あたりでは extra_guest_enabled を無視し、従来どおり 区分の単価 × 人数（3,000 × 3 + 2,000 × 2）',
				'args'                => $this->build_args(
					array(
						'price_unit'  => 'per_guest',
						'base_price'  => 99999,
						'guest_tiers' => array( $adult, $child ),
					)
				),
				'expected'            => array( 'total' => 13000 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Lodging_Price::calculate( $case['args'] );
			foreach ( $case['expected'] as $key => $expected ) {
				$this->assertSame( $expected, $actual[ $key ], $case['test_condition_name'] . ': ' . $key );
			}
		}
	}

	/**
	 * calculate: 休前日・特定日をまたぐ泊は、泊ごとにその泊の単価で並べて超過する人を決める。
	 */
	public function test_calculate_night_rates(): void {
		// 大人は平日が高く休前日が安い。子供は逆に休前日が高い（休前日の泊は順番が入れ替わる）。
		$adult = $this->tier( '大人', 3000, 2, array( 'pre_holiday_price' => 1000 ) );
		$child = $this->tier( '子供', 2000, 1, array( 'pre_holiday_price' => 2500 ) );

		$test_cases = array(
			array(
				'test_condition_name' => '平日の泊（月曜）規定 2 名・大人 2 名＋子供 1 名 => 大人が含まれ、子供 2,000 円を加算',
				'args'                => $this->build_args(
					array(
						'included_guests' => 2,
						'guest_tiers'     => array( $adult, $child ),
					)
				),
				'expected'            => array(
					'total' => 12000,
					'rates' => array( 'weekday' ),
				),
			),
			array(
				'test_condition_name' => '休前日の泊（土曜）=> 単価は大人 1,000・子供 2,500。子供が先に含まれ、大人 1 名が超過（1,000 円）。基本料金は休前日の料金',
				'args'                => $this->build_args(
					array(
						'included_guests'        => 2,
						'night_dates'            => array( '2026-10-10' ),
						'base_price_pre_holiday' => 15000,
						'guest_tiers'            => array( $adult, $child ),
					)
				),
				'expected'            => array(
					'total' => 16000,
					'rates' => array( 'pre_holiday' ),
				),
			),
			array(
				'test_condition_name' => '金曜・土曜の 2 泊 => 金曜 12,000 円（大人が含まれる）＋ 土曜 16,000 円（子供が含まれる）= 28,000 円。泊ごとの金額が違うため単価は null',
				'args'                => $this->build_args(
					array(
						'included_guests'        => 2,
						'night_dates'            => array( '2026-10-09', '2026-10-10' ),
						'base_price_pre_holiday' => 15000,
						'guest_tiers'            => array( $adult, $child ),
					)
				),
				'expected'            => array(
					'total'                => 28000,
					'rates'                => array( 'weekday', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '特定日の泊で、超過する大人に特定日の追加料金がある => 基本料金は特定日の料金が空欄で平日だが、泊の区分は特定日',
				'args'                => $this->build_args(
					array(
						'included_guests' => 1,
						'night_dates'     => array( '2026-10-12' ),
						'special_periods' => array(
							array(
								'start' => '2026-10-12',
								'end'   => '2026-10-12',
							),
						),
						'guest_tiers'     => array(
							$this->tier( '大人', 3000, 1, array( 'special_price' => 6000 ) ),
							$this->tier( '子供', 2000, 1, array( 'special_price' => 5000 ) ),
						),
					)
				),
				'expected'            => array(
					'total' => 15000,
					'rates' => array( 'special' ),
				),
			),
			array(
				'test_condition_name' => '特定日の泊でも、追加料金の人が全員規定人数に含まれる => 超過なし。基本料金も特定日の料金が空欄のため、泊の区分は平日',
				'args'                => $this->build_args(
					array(
						'included_guests' => 2,
						'night_dates'     => array( '2026-10-12' ),
						'special_periods' => array(
							array(
								'start' => '2026-10-12',
								'end'   => '2026-10-12',
							),
						),
						'guest_tiers'     => array(
							$this->tier( '大人', 3000, 1, array( 'special_price' => 6000 ) ),
							$this->tier( '子供', 2000, 1, array( 'special_price' => 5000 ) ),
						),
					)
				),
				'expected'            => array(
					'total' => 10000,
					'rates' => array( 'weekday' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Lodging_Price::calculate( $case['args'] );
			foreach ( $case['expected'] as $key => $expected ) {
				$value = 'rates' === $key ? array_column( $actual['night_breakdown'], 'rate' ) : $actual[ $key ];
				$this->assertSame( $expected, $value, $case['test_condition_name'] . ': ' . $key );
			}
		}
	}

	/**
	 * has_rate_tiers: 1室あたりで追加料金を使うときは、基本料金と区分の両方の休前日・特定日の料金を見る。
	 */
	public function test_has_rate_tiers(): void {
		$tier_with_pre_holiday = $this->tier( '大人', 3000, 1, array( 'pre_holiday_price' => 4000 ) );

		$test_cases = array(
			array(
				'test_condition_name' => '追加料金あり・区分に休前日の料金あり（基本料金は平日のみ）=> true',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $tier_with_pre_holiday ) ) ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '追加料金あり・基本料金に休前日の料金あり（区分は平日のみ）=> true',
				'args'                => $this->build_args(
					array(
						'base_price_pre_holiday' => 15000,
						'guest_tiers'            => array( $this->tier( '大人', 3000, 1 ) ),
					)
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '追加料金あり・どれも平日のみ => false',
				'args'                => $this->build_args( array( 'guest_tiers' => array( $this->tier( '大人', 3000, 1 ) ) ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '追加料金なし（チェックなし）・区分に休前日の料金が残っている => 使われないため false',
				'args'                => $this->build_args(
					array(
						'extra_guest_enabled' => false,
						'guest_tiers'         => array( $tier_with_pre_holiday ),
					)
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Price::has_rate_tiers( $case['args'] ), $case['test_condition_name'] );
		}
	}
}
