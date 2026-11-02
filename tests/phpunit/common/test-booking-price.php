<?php
/**
 * 時間枠のメニューの料金の単位（1名あたり／1ツアーあたり）と基本料金合計の計算のテスト（#1002）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Booking_Price;
use WP_UnitTestCase;

/**
 * Booking_Price（データを読まない純粋な計算クラス）のテスト。
 *
 * @group common
 */
class Booking_Price_Test extends WP_UnitTestCase {

	/**
	 * calculate_service_total: 料金の単位・人数・料金区分ごとのサービス基本料金の合計。
	 */
	public function test_calculate_service_total(): void {
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 5000,
				'count' => 2,
			),
			array(
				'label' => '子供',
				'price' => 3000,
				'count' => 1,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '1名あたり・区分なし・3名 => 基本料金 × 人数',
				'args'                => array(
					'price_unit' => 'per_guest',
					'base_price' => 1000,
					'guests'     => 3,
				),
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => '料金の単位が未指定 => 1名あたりとして 基本料金 × 人数',
				'args'                => array(
					'base_price' => 1000,
					'guests'     => 2,
				),
				'expected'            => 2000,
			),
			array(
				'test_condition_name' => '1名あたり・区分あり => 区分の単価 × 区分の人数の合計（基本料金は使わない）',
				'args'                => array(
					'price_unit'  => 'per_guest',
					'base_price'  => 99999,
					'guests'      => 3,
					'guest_tiers' => $tiers,
				),
				'expected'            => 13000,
			),
			array(
				'test_condition_name' => '1ツアーあたり・1名 => 基本料金そのまま',
				'args'                => array(
					'price_unit' => 'per_booking',
					'base_price' => 30000,
					'guests'     => 1,
				),
				'expected'            => 30000,
			),
			array(
				'test_condition_name' => '1ツアーあたり・5名 => 人数を掛けず基本料金そのまま',
				'args'                => array(
					'price_unit' => 'per_booking',
					'base_price' => 30000,
					'guests'     => 5,
				),
				'expected'            => 30000,
			),
			array(
				'test_condition_name' => '1ツアーあたり・区分が残っていても => 区分は使わず基本料金そのまま',
				'args'                => array(
					'price_unit'  => 'per_booking',
					'base_price'  => 30000,
					'guests'      => 3,
					'guest_tiers' => $tiers,
				),
				'expected'            => 30000,
			),
			array(
				'test_condition_name' => '基本料金が負数 => 0 に丸める',
				'args'                => array(
					'price_unit' => 'per_booking',
					'base_price' => -500,
					'guests'     => 2,
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '1名あたり・人数が 0 => 1名として扱う（0円にしない）',
				'args'                => array(
					'price_unit' => 'per_guest',
					'base_price' => 1000,
					'guests'     => 0,
				),
				'expected'            => 1000,
			),
			array(
				'test_condition_name' => '数値でない基本料金 => 0',
				'args'                => array(
					'price_unit' => 'per_booking',
					'base_price' => 'abc',
					'guests'     => 2,
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '不正な料金の単位 => 1名あたりとして扱う',
				'args'                => array(
					'price_unit' => 'per_room',
					'base_price' => 1000,
					'guests'     => 2,
				),
				'expected'            => 2000,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Price::calculate_service_total( $case['args'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * calculate_total: サービス基本料金に指名料・貸し切り料金を足す（指名料は人数にかかわらず1回分）。
	 */
	public function test_calculate_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・5名・指名料3000 => 基本料金 + 指名料（指名料は1回分）',
				'args'                => array(
					'price_unit'     => 'per_booking',
					'base_price'     => 30000,
					'guests'         => 5,
					'nomination_fee' => 3000,
				),
				'expected'            => 33000,
			),
			array(
				'test_condition_name' => '1名あたり・2名・指名料6000 => 基本料金 × 人数 + 指名料',
				'args'                => array(
					'price_unit'     => 'per_guest',
					'base_price'     => 15000,
					'guests'         => 2,
					'nomination_fee' => 6000,
				),
				'expected'            => 36000,
			),
			array(
				'test_condition_name' => '1名あたり・区分あり・貸し切り料金あり => 区分の合計 + 指名料 + 貸し切り料金',
				'args'                => array(
					'price_unit'     => 'per_guest',
					'guest_tiers'    => array(
						array(
							'label' => '大人',
							'price' => 5000,
							'count' => 2,
						),
					),
					'nomination_fee' => 1000,
					'exclusive_fee'  => 2000,
				),
				'expected'            => 13000,
			),
			array(
				'test_condition_name' => '指名料・貸し切り料金が負数や未指定 => 0 として足す',
				'args'                => array(
					'price_unit'     => 'per_booking',
					'base_price'     => 30000,
					'nomination_fee' => -100,
				),
				'expected'            => 30000,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Price::calculate_total( $case['args'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * normalize_unit / is_per_booking: 料金の単位の正規化。
	 */
	public function test_normalize_unit_and_is_per_booking(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'per_booking => per_booking',
				'value'               => 'per_booking',
				'expected_unit'       => 'per_booking',
				'expected_flag'       => true,
			),
			array(
				'test_condition_name' => 'per_guest => per_guest',
				'value'               => 'per_guest',
				'expected_unit'       => 'per_guest',
				'expected_flag'       => false,
			),
			array(
				'test_condition_name' => '空文字（保存なし） => 1名あたり',
				'value'               => '',
				'expected_unit'       => 'per_guest',
				'expected_flag'       => false,
			),
			array(
				'test_condition_name' => 'null => 1名あたり',
				'value'               => null,
				'expected_unit'       => 'per_guest',
				'expected_flag'       => false,
			),
			array(
				'test_condition_name' => '宿泊の料金の単位（per_room）は流用しない => 1名あたり',
				'value'               => 'per_room',
				'expected_unit'       => 'per_guest',
				'expected_flag'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected_unit'], Booking_Price::normalize_unit( $case['value'] ), $case['test_condition_name'] . ' / normalize_unit' );
			$this->assertSame( $case['expected_flag'], Booking_Price::is_per_booking( $case['value'] ), $case['test_condition_name'] . ' / is_per_booking' );
		}
	}

	/**
	 * calculate_service_total / calculate_total: 1ツアーあたりは基本料金に超過人数分の追加料金を足す（#1003）。
	 */
	public function test_calculate_total_with_extra_guest_tiers(): void {
		$extra_child = array(
			array(
				'label' => '子供',
				'price' => 1500,
				'count' => 1,
			),
		);
		$extra_mixed = array(
			array(
				'label' => '大人',
				'price' => 3000,
				'count' => 2,
			),
			array(
				'label' => '子供',
				'price' => 1500,
				'count' => 2,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・超過の内訳なし => 基本料金のまま',
				'args'                => array(
					'price_unit' => 'per_booking',
					'base_price' => 30000,
				),
				'expected'            => 30000,
			),
			array(
				'test_condition_name' => '1ツアーあたり・子供1名が超過 => 30000 + 1500',
				'args'                => array(
					'price_unit'        => 'per_booking',
					'base_price'        => 30000,
					'extra_guest_tiers' => $extra_child,
				),
				'expected'            => 31500,
			),
			array(
				'test_condition_name' => '1ツアーあたり・複数の区分が超過 => 30000 + 3000 × 2 + 1500 × 2',
				'args'                => array(
					'price_unit'        => 'per_booking',
					'base_price'        => 30000,
					'extra_guest_tiers' => $extra_mixed,
				),
				'expected'            => 39000,
			),
			array(
				'test_condition_name' => '1ツアーあたり・人数や区分の人数内訳が渡されても追加料金の内訳だけを使う',
				'args'                => array(
					'price_unit'        => 'per_booking',
					'base_price'        => 30000,
					'guests'            => 8,
					'guest_tiers'       => $extra_mixed,
					'extra_guest_tiers' => $extra_child,
				),
				'expected'            => 31500,
			),
			array(
				'test_condition_name' => '1名あたりでは追加料金の内訳を無視する（基本料金 × 人数）',
				'args'                => array(
					'price_unit'        => 'per_guest',
					'base_price'        => 1000,
					'guests'            => 2,
					'extra_guest_tiers' => $extra_child,
				),
				'expected'            => 2000,
			),
			array(
				'test_condition_name' => '追加料金の内訳が配列でない => 無視する',
				'args'                => array(
					'price_unit'        => 'per_booking',
					'base_price'        => 30000,
					'extra_guest_tiers' => 'invalid',
				),
				'expected'            => 30000,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Price::calculate_service_total( $case['args'] ), $case['test_condition_name'] . ' / calculate_service_total' );
		}

		// 指名料・貸し切り料金は追加料金とは別に足す。
		$this->assertSame(
			34500,
			Booking_Price::calculate_total(
				array(
					'price_unit'        => 'per_booking',
					'base_price'        => 30000,
					'extra_guest_tiers' => $extra_child,
					'nomination_fee'    => 3000,
				)
			),
			'指名料は追加料金とは別に1回分だけ足す'
		);
	}

	/**
	 * resolve_extra_guest_tiers: 単価の高い区分の人から順に基本料金に含め、残りを超過扱いにする（#1003）。
	 */
	public function test_resolve_extra_guest_tiers(): void {
		$adult_child = static function ( int $adult, int $child ): array {
			return array(
				array(
					'label' => '大人',
					'price' => 3000,
					'count' => $adult,
				),
				array(
					'label' => '子供',
					'price' => 1500,
					'count' => $child,
				),
			);
		};

		$test_cases = array(
			array(
				'test_condition_name' => '大人4名＋子供1名・4名まで含む => 子供1名が超過',
				'tiers'               => $adult_child( 4, 1 ),
				'included_guests'     => 4,
				'expected'            => array(
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 1,
					),
				),
			),
			array(
				'test_condition_name' => '大人3名＋子供3名・4名まで含む => 単価の高い大人3名から含め、子供1名を含めるので子供2名が超過',
				'tiers'               => $adult_child( 3, 3 ),
				'included_guests'     => 4,
				'expected'            => array(
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => '大人6名＋子供2名・4名まで含む => 大人2名と子供2名が超過（区分の並び順を保つ）',
				'tiers'               => $adult_child( 6, 2 ),
				'included_guests'     => 4,
				'expected'            => array(
					array(
						'label' => '大人',
						'price' => 3000,
						'count' => 2,
					),
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => '人数の合計が含まれる人数以内 => 超過なし',
				'tiers'               => $adult_child( 2, 2 ),
				'included_guests'     => 4,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '単価が低い区分が先に並んでいても、単価の高い区分から含める',
				'tiers'               => array_reverse( $adult_child( 2, 3 ) ),
				'included_guests'     => 3,
				'expected'            => array(
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => '区分が空 => 超過なし',
				'tiers'               => array(),
				'included_guests'     => 4,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Price::resolve_extra_guest_tiers( $case['tiers'], $case['included_guests'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * normalize_extra_guests: 予約に保存した追加料金の内訳を正規化する（#1003）。
	 */
	public function test_normalize_extra_guests(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい内訳 => そのまま返す',
				'raw'                 => array(
					'included_guests' => 4,
					'tiers'           => array(
						array(
							'label' => '子供',
							'price' => 1500,
							'count' => 1,
						),
					),
				),
				'expected'            => array(
					'included_guests' => 4,
					'tiers'           => array(
						array(
							'label' => '子供',
							'price' => 1500,
							'count' => 1,
						),
					),
				),
			),
			array(
				'test_condition_name' => '超過人数が0名の区分は除く。全区分が0名なら空配列',
				'raw'                 => array(
					'included_guests' => 4,
					'tiers'           => array(
						array(
							'label' => '子供',
							'price' => 1500,
							'count' => 0,
						),
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '含まれる人数が不正（0）=> 1 以上にする',
				'raw'                 => array(
					'included_guests' => 0,
					'tiers'           => array(
						array(
							'label' => '大人',
							'price' => 3000,
							'count' => 2,
						),
					),
				),
				'expected'            => array(
					'included_guests' => 1,
					'tiers'           => array(
						array(
							'label' => '大人',
							'price' => 3000,
							'count' => 2,
						),
					),
				),
			),
			array(
				'test_condition_name' => '配列でない（メタなし） => 空配列',
				'raw'                 => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '区分名が空の行は除く',
				'raw'                 => array(
					'included_guests' => 4,
					'tiers'           => array(
						array(
							'label' => '',
							'price' => 1500,
							'count' => 1,
						),
					),
				),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Price::normalize_extra_guests( $case['raw'] ), $case['test_condition_name'] );
		}
	}
}
