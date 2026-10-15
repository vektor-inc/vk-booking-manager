<?php
/**
 * 泊単位のプランの料金計算 Lodging_Price のテスト（#572）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * Lodging_Price の計算・適用判定・客室の追加料金の解決のテスト。
 *
 * @group common
 * @group lodging
 */
class Lodging_Price_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * テスト後に全体設定・フィルター・キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する。
	 *
	 * @param string $preset 保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $preset ) )
		);
	}

	/**
	 * calculate: 1名1泊あたり・1室1泊あたりの合計を検証する。
	 */
	public function test_calculate(): void {
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 12000,
				'count' => 2,
			),
			array(
				'label' => '子供',
				'price' => 6000,
				'count' => 1,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '1名あたり・区分なし・1泊: 基本料金 × 人数',
				'args'                => array(
					'nights'     => 1,
					'guests'     => 3,
					'price_unit' => 'per_guest',
					'base_price' => 10000,
				),
				'expected'            => array(
					'plan_price_per_night' => 30000,
					'price_per_night'      => 30000,
					'total'                => 30000,
				),
			),
			array(
				'test_condition_name' => '1名あたり・区分なし・2泊: 基本料金 × 人数 × 泊数',
				'args'                => array(
					'nights'     => 2,
					'guests'     => 3,
					'price_unit' => 'per_guest',
					'base_price' => 10000,
				),
				'expected'            => array(
					'plan_price_per_night' => 30000,
					'price_per_night'      => 30000,
					'total'                => 60000,
				),
			),
			array(
				'test_condition_name' => '1名あたり・区分あり・客室 +3,000円・2泊: （30,000 + 3,000）× 2 = 66,000円',
				'args'                => array(
					'nights'         => 2,
					'guests'         => 3,
					'price_unit'     => 'per_guest',
					'base_price'     => 99999,
					'guest_tiers'    => $tiers,
					'room_surcharge' => 3000,
				),
				'expected'            => array(
					'plan_price_per_night'     => 30000,
					'room_surcharge_per_night' => 3000,
					'price_per_night'          => 33000,
					'total'                    => 66000,
				),
			),
			array(
				'test_condition_name' => '1名あたり・区分なし・客室の追加料金は人数を掛けない',
				'args'                => array(
					'nights'         => 1,
					'guests'         => 4,
					'price_unit'     => 'per_guest',
					'base_price'     => 5000,
					'room_surcharge' => 2000,
				),
				'expected'            => array(
					'plan_price_per_night' => 20000,
					'price_per_night'      => 22000,
					'total'                => 22000,
				),
			),
			array(
				'test_condition_name' => '1室あたり: 20,000円 + 客室 5,000円 × 2泊 = 50,000円（人数 1名）',
				'args'                => array(
					'nights'         => 2,
					'guests'         => 1,
					'price_unit'     => 'per_room',
					'base_price'     => 20000,
					'room_surcharge' => 5000,
				),
				'expected'            => array(
					'plan_price_per_night' => 20000,
					'price_per_night'      => 25000,
					'total'                => 50000,
				),
			),
			array(
				'test_condition_name' => '1室あたり: 人数を6名に変えても同額',
				'args'                => array(
					'nights'         => 2,
					'guests'         => 6,
					'price_unit'     => 'per_room',
					'base_price'     => 20000,
					'room_surcharge' => 5000,
				),
				'expected'            => array(
					'plan_price_per_night' => 20000,
					'total'                => 50000,
				),
			),
			array(
				'test_condition_name' => '1室あたり: 料金区分が渡されても無視する',
				'args'                => array(
					'nights'      => 1,
					'guests'      => 3,
					'price_unit'  => 'per_room',
					'base_price'  => 20000,
					'guest_tiers' => $tiers,
				),
				'expected'            => array(
					'plan_price_per_night' => 20000,
					'total'                => 20000,
				),
			),
			array(
				'test_condition_name' => '泊数 0 は合計 0',
				'args'                => array(
					'nights'         => 0,
					'guests'         => 2,
					'price_unit'     => 'per_guest',
					'base_price'     => 10000,
					'room_surcharge' => 3000,
				),
				'expected'            => array(
					'nights' => 0,
					'total'  => 0,
				),
			),
			array(
				'test_condition_name' => '泊数が負の値でも合計 0',
				'args'                => array(
					'nights'     => -3,
					'guests'     => 2,
					'price_unit' => 'per_room',
					'base_price' => 10000,
				),
				'expected'            => array(
					'nights' => 0,
					'total'  => 0,
				),
			),
			array(
				'test_condition_name' => '不正な単位は1名あたりとして扱う',
				'args'                => array(
					'nights'     => 1,
					'guests'     => 2,
					'price_unit' => 'week',
					'base_price' => 10000,
				),
				'expected'            => array(
					'price_unit' => 'per_guest',
					'total'      => 20000,
				),
			),
			array(
				'test_condition_name' => '負の金額・人数は 0 に丸める',
				'args'                => array(
					'nights'         => 1,
					'guests'         => -2,
					'price_unit'     => 'per_guest',
					'base_price'     => -100,
					'room_surcharge' => -500,
				),
				'expected'            => array( 'total' => 0 ),
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
	 * calculate: 泊ごとに平日・休前日・特定日の単価を決めて合計する（#737）。
	 *
	 * 休前日の曜日は土曜のみ。日付は 2026-10-09（金）・2026-10-10（土）・2026-10-11（日）などを使う。
	 */
	public function test_calculate_night_rates(): void {
		$rate_prices = array(
			'base_price'             => 10000,
			'base_price_pre_holiday' => 15000,
			'base_price_special'     => 20000,
		);
		$saturday    = array( 'sat' );

		$tiers = array(
			array(
				'label'             => '大人',
				'price'             => 12000,
				'count'             => 2,
				'pre_holiday_price' => 18000,
				'special_price'     => 24000,
			),
			array(
				'label'         => '子供',
				'price'         => 6000,
				'count'         => 1,
				'special_price' => 9000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '平日のみ（月・火の 2 泊）=> 平日の単価 × 2',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-10-12', '2026-10-13' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
					)
				),
				'expected'            => array(
					'total'                => 20000,
					'rates'                => array( 'weekday', 'weekday' ),
					'plan_price_per_night' => 10000,
					'price_per_night'      => 10000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '金曜から 2 泊（休前日をまたぐ連泊）=> 金曜は平日・土曜は休前日。単価は泊ごとに違うため null',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-10-09', '2026-10-10' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
					)
				),
				'expected'            => array(
					'total'                => 25000,
					'rates'                => array( 'weekday', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'price_per_night'      => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '特定日と休前日が重なる日（土曜が特定日）=> 特定日の単価が優先',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-10-10' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
						'special_periods'      => array(
							array(
								'start' => '2026-10-10',
								'end'   => '2026-10-10',
							),
						),
					)
				),
				'expected'            => array(
					'total'                => 20000,
					'rates'                => array( 'special' ),
					'plan_price_per_night' => 20000,
					'price_per_night'      => 20000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '特定日の期間の終了日（金曜）の泊は特定日、終了日の翌日（土曜）の泊は休前日',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-10-08', '2026-10-09', '2026-10-10' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
						'special_periods'      => array(
							array(
								'start' => '2026-10-07',
								'end'   => '2026-10-09',
							),
						),
					)
				),
				'expected'            => array(
					'total'                => 55000,
					'rates'                => array( 'special', 'special', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'price_per_night'      => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '特定日の料金が空欄の泊（土曜が特定日）=> 休前日の判定へ進み、休前日の単価',
				'args'                => array(
					'base_price'             => 10000,
					'base_price_pre_holiday' => 15000,
					'base_price_special'     => '',
					'night_dates'            => array( '2026-10-10' ),
					'guests'                 => 1,
					'pre_holiday_weekdays'   => $saturday,
					'special_periods'        => array(
						array(
							'start' => '2026-10-10',
							'end'   => '2026-10-10',
						),
					),
				),
				'expected'            => array(
					'total'                => 15000,
					'rates'                => array( 'pre_holiday' ),
					'plan_price_per_night' => 15000,
					'price_per_night'      => 15000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '特定日の料金が空欄で、休前日でもない泊（月曜が特定日）=> 平日の単価',
				'args'                => array(
					'base_price'             => 10000,
					'base_price_pre_holiday' => 15000,
					'base_price_special'     => null,
					'night_dates'            => array( '2026-10-12' ),
					'guests'                 => 1,
					'pre_holiday_weekdays'   => $saturday,
					'special_periods'        => array(
						array(
							'start' => '2026-10-12',
							'end'   => '2026-10-12',
						),
					),
				),
				'expected'            => array(
					'total'                => 10000,
					'rates'                => array( 'weekday' ),
					'plan_price_per_night' => 10000,
					'price_per_night'      => 10000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '休前日の料金が空欄 => 休前日の泊でも平日の単価',
				'args'                => array(
					'base_price'             => 10000,
					'base_price_pre_holiday' => '',
					'base_price_special'     => '',
					'night_dates'            => array( '2026-10-10' ),
					'guests'                 => 2,
					'pre_holiday_weekdays'   => $saturday,
				),
				'expected'            => array(
					'total'                => 20000,
					'rates'                => array( 'weekday' ),
					'plan_price_per_night' => 20000,
					'price_per_night'      => 20000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '休前日の料金が 0 円 => 空欄ではなく 0 円として計算する',
				'args'                => array(
					'base_price'             => 10000,
					'base_price_pre_holiday' => 0,
					'night_dates'            => array( '2026-10-10' ),
					'guests'                 => 1,
					'pre_holiday_weekdays'   => $saturday,
				),
				'expected'            => array(
					'total'                => 0,
					'rates'                => array( 'pre_holiday' ),
					'plan_price_per_night' => 0,
					'price_per_night'      => 0,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '料金区分あり（大人 2 名・子供 1 名）金曜・土曜 => 金曜は平日、土曜は休前日（子供は休前日の料金が空欄のため平日の単価）',
				'args'                => array(
					'night_dates'          => array( '2026-10-09', '2026-10-10' ),
					'guests'               => 3,
					'price_unit'           => 'per_guest',
					'base_price'           => 99999,
					'guest_tiers'          => $tiers,
					'pre_holiday_weekdays' => $saturday,
				),
				'expected'            => array(
					'total'                => 72000,
					'rates'                => array( 'weekday', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'price_per_night'      => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '料金区分あり 特定日の泊 => 大人 24,000 × 2 + 子供 9,000 = 57,000円',
				'args'                => array(
					'night_dates'     => array( '2026-10-12' ),
					'guests'          => 3,
					'price_unit'      => 'per_guest',
					'guest_tiers'     => $tiers,
					'special_periods' => array(
						array(
							'start' => '2026-10-12',
							'end'   => '2026-10-14',
						),
					),
				),
				'expected'            => array(
					'total'                => 57000,
					'rates'                => array( 'special' ),
					'plan_price_per_night' => 57000,
					'price_per_night'      => 57000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '料金区分あり 人数 0 の区分の休前日の料金は泊の区分に影響しない',
				'args'                => array(
					'night_dates'          => array( '2026-10-10' ),
					'guests'               => 1,
					'price_unit'           => 'per_guest',
					'guest_tiers'          => array(
						array(
							'label'             => '大人',
							'price'             => 12000,
							'count'             => 0,
							'pre_holiday_price' => 18000,
						),
						array(
							'label' => '子供',
							'price' => 6000,
							'count' => 1,
						),
					),
					'pre_holiday_weekdays' => $saturday,
				),
				'expected'            => array(
					'total'                => 6000,
					'rates'                => array( 'weekday' ),
					'plan_price_per_night' => 6000,
					'price_per_night'      => 6000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '既存プラン（休前日・特定日が空欄）が土曜を含む 3 泊 => 今までと同じ（基本料金 × 人数 × 泊数）',
				'args'                => array(
					'base_price'           => 10000,
					'night_dates'          => array( '2026-10-09', '2026-10-10', '2026-10-11' ),
					'guests'               => 2,
					'pre_holiday_weekdays' => $saturday,
					'special_periods'      => array(
						array(
							'start' => '2026-10-11',
							'end'   => '2026-10-11',
						),
					),
				),
				'expected'            => array(
					'total'                => 60000,
					'rates'                => array( 'weekday', 'weekday', 'weekday' ),
					'plan_price_per_night' => 20000,
					'price_per_night'      => 20000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '既存プラン（料金区分に休前日・特定日のキーが無い）=> 今までと同じ合計',
				'args'                => array(
					'night_dates'          => array( '2026-10-09', '2026-10-10' ),
					'guests'               => 3,
					'price_unit'           => 'per_guest',
					'guest_tiers'          => array(
						array(
							'label' => '大人',
							'price' => 12000,
							'count' => 2,
						),
						array(
							'label' => '子供',
							'price' => 6000,
							'count' => 1,
						),
					),
					'pre_holiday_weekdays' => $saturday,
				),
				'expected'            => array(
					'total'                => 60000,
					'rates'                => array( 'weekday', 'weekday' ),
					'plan_price_per_night' => 30000,
					'price_per_night'      => 30000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '祝日の前日（2026-05-02 土曜。曜日の設定なし）=> 休前日の単価',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-05-02' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => array(),
					)
				),
				'expected'            => array(
					'total'                => 15000,
					'rates'                => array( 'pre_holiday' ),
					'plan_price_per_night' => 15000,
					'price_per_night'      => 15000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '祝日の前日（2026-05-02 土曜）を特定日に登録 => 特定日の単価が優先',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-05-02' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
						'special_periods'      => array(
							array(
								'start' => '2026-05-01',
								'end'   => '2026-05-06',
							),
						),
					)
				),
				'expected'            => array(
					'total'                => 20000,
					'rates'                => array( 'special' ),
					'plan_price_per_night' => 20000,
					'price_per_night'      => 20000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '客室の追加料金 +3,000円は区分によらず一定（金曜・土曜の 2 泊 = 13,000 + 18,000）',
				'args'                => array_merge(
					$rate_prices,
					array(
						'night_dates'          => array( '2026-10-09', '2026-10-10' ),
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
						'room_surcharge'       => 3000,
					)
				),
				'expected'            => array(
					'total'                => 31000,
					'rates'                => array( 'weekday', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'price_per_night'      => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '1室あたり（人数・料金区分は見ない）金曜・土曜 => 基本料金の休前日の単価を使う',
				'args'                => array(
					'night_dates'            => array( '2026-10-09', '2026-10-10' ),
					'guests'                 => 5,
					'price_unit'             => 'per_room',
					'base_price'             => 30000,
					'base_price_pre_holiday' => 40000,
					'guest_tiers'            => $tiers,
					'pre_holiday_weekdays'   => $saturday,
				),
				'expected'            => array(
					'total'                => 70000,
					'rates'                => array( 'weekday', 'pre_holiday' ),
					'plan_price_per_night' => null,
					'price_per_night'      => null,
					'is_uniform_price'     => false,
				),
			),
			array(
				'test_condition_name' => '泊の日付を渡さない（泊数だけ）=> すべて平日の単価で計算する',
				'args'                => array_merge(
					$rate_prices,
					array(
						'nights'               => 2,
						'guests'               => 1,
						'pre_holiday_weekdays' => $saturday,
					)
				),
				'expected'            => array(
					'total'                => 20000,
					'rates'                => array( 'weekday', 'weekday' ),
					'plan_price_per_night' => 10000,
					'price_per_night'      => 10000,
					'is_uniform_price'     => true,
				),
			),
			array(
				'test_condition_name' => '泊数 0 => 合計 0・内訳なし（1泊あたりは平日の単価）',
				'args'                => array_merge(
					$rate_prices,
					array(
						'nights' => 0,
						'guests' => 1,
					)
				),
				'expected'            => array(
					'total'                => 0,
					'rates'                => array(),
					'plan_price_per_night' => 10000,
					'price_per_night'      => 10000,
					'is_uniform_price'     => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Lodging_Price::calculate( $case['args'] );

			$this->assertSame( $case['expected']['total'], $actual['total'], $case['test_condition_name'] . ': total' );
			$this->assertSame( $case['expected']['rates'], array_column( $actual['night_breakdown'], 'rate' ), $case['test_condition_name'] . ': rate' );
			$this->assertSame( $case['expected']['plan_price_per_night'], $actual['plan_price_per_night'], $case['test_condition_name'] . ': plan_price_per_night' );
			$this->assertSame( $case['expected']['price_per_night'], $actual['price_per_night'], $case['test_condition_name'] . ': price_per_night' );
			$this->assertSame( $case['expected']['is_uniform_price'], $actual['is_uniform_price'], $case['test_condition_name'] . ': is_uniform_price' );

			// 泊ごとの内訳の合計は、合計と一致する。各泊の金額は、プランの料金と客室の追加料金の和。
			$this->assertSame( $actual['total'], array_sum( array_column( $actual['night_breakdown'], 'price' ) ), $case['test_condition_name'] . ': 内訳の合計' );
			foreach ( $actual['night_breakdown'] as $night ) {
				$this->assertSame( $night['plan_price'] + $night['room_surcharge'], $night['price'], $case['test_condition_name'] . ': 泊の金額' );
			}
		}
	}

	/**
	 * calculate: 泊ごとの内訳に、日付・区分・プランの料金・客室の追加料金・泊の金額が入る。
	 */
	public function test_calculate_night_breakdown(): void {
		$actual = Lodging_Price::calculate(
			array(
				'night_dates'            => array( '2026-10-09', '2026-10-10' ),
				'guests'                 => 1,
				'base_price'             => 10000,
				'base_price_pre_holiday' => 15000,
				'pre_holiday_weekdays'   => array( 'sat' ),
				'room_surcharge'         => 3000,
			)
		);

		$this->assertSame(
			array(
				array(
					'date'           => '2026-10-09',
					'rate'           => 'weekday',
					'plan_price'     => 10000,
					'room_surcharge' => 3000,
					'price'          => 13000,
				),
				array(
					'date'           => '2026-10-10',
					'rate'           => 'pre_holiday',
					'plan_price'     => 15000,
					'room_surcharge' => 3000,
					'price'          => 18000,
				),
			),
			$actual['night_breakdown'],
			'泊ごとの内訳の形'
		);
	}

	/**
	 * is_applicable: 宿泊が有効なサイトの泊単位メニューだけ true。
	 */
	public function test_is_applicable(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・泊単位のメニューは true',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊のサイト・時間枠のメニューは false',
				'preset'              => 'lodging',
				'unit'                => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'カスタムのサイトでは泊のメタが残っていても false',
				'preset'              => 'custom',
				'unit'                => 'night',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンのサイトでは泊のメタが残っていても false',
				'preset'              => 'salon',
				'unit'                => 'night',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
			if ( '' !== $case['unit'] ) {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, $case['unit'] );
			}
			// 無料版では宿泊の機能が使えないため、常に false になる。
			$expected = $case['expected'] && ! Pro_Upsell::is_free_edition();
			$this->assertSame( $expected, Lodging_Price::is_applicable( $menu_id ), $case['test_condition_name'] );
		}
		$this->assertFalse( Lodging_Price::is_applicable( 0 ), 'メニューIDが0なら false' );
	}

	/**
	 * resolve_room_surcharge_per_night: 客室の追加料金の読み取りを検証する。
	 */
	public function test_resolve_room_surcharge_per_night(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '客室の追加料金は Pro 版限定のため、無料版ではスキップする。' );
		}
		// サイト全体で指名機能をオンにする（既存の指名料の判定と同じ前提）。
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'staff_enabled' => true ) )
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$test_cases = array(
			array(
				'test_condition_name' => '客室に追加料金 3,000円 → 3000',
				'room_fee'            => '3000',
				'disable_fee'         => '',
				'use_room'            => true,
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => '客室に追加料金がない → 0',
				'room_fee'            => '',
				'disable_fee'         => '',
				'use_room'            => true,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'メニューが指名料を加算しない設定 → 0',
				'room_fee'            => '3000',
				'disable_fee'         => '1',
				'use_room'            => true,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'メニューで指名を使わない設定 → 0（既存の指名料の判定に合わせる）',
				'room_fee'            => '3000',
				'disable_fee'         => '',
				'disable_nomination'  => true,
				'use_room'            => true,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '負の値は 0',
				'room_fee'            => '-500',
				'disable_fee'         => '',
				'use_room'            => true,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '客室 ID が 0 → 0',
				'room_fee'            => '3000',
				'disable_fee'         => '',
				'use_room'            => false,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
			$room_id = (int) $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
			if ( '' !== $case['room_fee'] ) {
				update_post_meta( $room_id, '_vkbm_nomination_fee', $case['room_fee'] );
			}
			if ( ! empty( $case['disable_nomination'] ) ) {
				update_post_meta( $menu_id, '_vkbm_disable_nomination', true );
			}
			if ( '' !== $case['disable_fee'] ) {
				update_post_meta( $menu_id, '_vkbm_disable_nomination_fee', $case['disable_fee'] );
			}

			$this->assertSame(
				$case['expected'],
				Lodging_Price::resolve_room_surcharge_per_night( $menu_id, $case['use_room'] ? $room_id : 0 ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 宿泊のサイトでは、客室に追加料金が保存されていても追加料金が 0 円になり、保存済みの値は残ることを検証する（#736）。
	 */
	public function test_resolve_room_surcharge_per_night_is_zero_for_lodging_site(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '客室の追加料金は Pro 版限定のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトは客室の追加料金が保存されていても 0',
				'preset'              => 'lodging',
				'expected'            => 0,
				'expected_disabled'   => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイトは従来どおり追加料金を使う',
				'preset'              => 'salon',
				'expected'            => 3000,
				'expected_disabled'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					( new Settings_Repository() )->get_default_settings(),
					array(
						'staff_enabled'   => true,
						'industry_preset' => $case['preset'],
					)
				)
			);
			Industry_Presets::clear_cache();
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id = (int) $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
			$room_id = (int) $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
			update_post_meta( $room_id, '_vkbm_nomination_fee', '3000' );

			$this->assertSame( $case['expected'], Lodging_Price::resolve_room_surcharge_per_night( $menu_id, $room_id ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_disabled'], Lodging_Price::is_nomination_fee_disabled_for_site(), $case['test_condition_name'] . ' / 判定' );
			// 保存済みの金額は消さない（プリセットを戻したときに元へ戻すため）。
			$this->assertSame( '3000', (string) get_post_meta( $room_id, '_vkbm_nomination_fee', true ), $case['test_condition_name'] . ' / 保存値' );
		}
	}
}
