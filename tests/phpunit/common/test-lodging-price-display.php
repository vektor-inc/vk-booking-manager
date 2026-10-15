<?php
/**
 * 泊単位のプランの料金区分・内訳の表示用データ Lodging_Price_Display のテスト（#738）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\Common\Lodging_Price_Display;
use VKBookingManager\Common\Lodging_Rate;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 区分・内訳を出すかの判定、特定日の名称、メニュー一覧の最安＋「〜」の表記を検証する。
 *
 * @group common
 * @group lodging
 */
class Lodging_Price_Display_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避し、表記を固定する（通貨記号 ¥・人数の単位 名・英語の原文）。
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
		$this->set_site( 'lodging' );
	}

	/**
	 * テスト後に全体設定・ロケール・キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		restore_previous_locale();
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
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
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $preset,
					'currency_symbol' => '¥',
				)
			)
		);
	}

	/**
	 * find_special_label: 日付が入る期間の名称を返す。期間外・名称なしは空文字。
	 */
	public function test_find_special_label(): void {
		$periods = array(
			array(
				'start' => '2026-12-29',
				'end'   => '2027-01-03',
				'label' => '年末年始',
			),
			array(
				'start' => '2027-05-01',
				'end'   => '2027-05-05',
				'label' => '',
			),
		);

		$test_cases = array(
			array( '期間の開始日', '2026-12-29', '年末年始' ),
			array( '期間の終了日（終了日の泊も含む）', '2027-01-03', '年末年始' ),
			array( '期間の前日', '2026-12-28', '' ),
			array( '期間の翌日', '2027-01-04', '' ),
			array( '名称のない期間', '2027-05-02', '' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case[2], Lodging_Rate::find_special_label( $case[1], $periods ), $case[0] );
		}
		$this->assertSame( '', Lodging_Rate::find_special_label( '2026-12-30', array() ), '期間が無い' );
	}

	/**
	 * has_rate_tiers: 休前日・特定日の料金が、実際に使う料金の項目に入っているかを返す。
	 */
	public function test_has_rate_tiers(): void {
		$tiers_with_pre_holiday = array(
			array(
				'label'             => '大人',
				'price'             => 10000,
				'count'             => 1,
				'pre_holiday_price' => 12000,
			),
			array(
				'label' => '子供',
				'price' => 5000,
				'count' => 0,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '休前日・特定日が空欄（既存のプラン）: false',
				'args'                => array(
					'price_unit' => 'per_guest',
					'base_price' => 10000,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空文字の休前日・特定日: false',
				'args'                => array(
					'price_unit'             => 'per_guest',
					'base_price'             => 10000,
					'base_price_pre_holiday' => '',
					'base_price_special'     => '',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '休前日だけ設定: true',
				'args'                => array(
					'price_unit'             => 'per_guest',
					'base_price'             => 10000,
					'base_price_pre_holiday' => '12000',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '特定日だけ設定: true',
				'args'                => array(
					'price_unit'         => 'per_guest',
					'base_price'         => 10000,
					'base_price_special' => '15000',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '平日と同じ金額を入れた場合も「設定している」: true',
				'args'                => array(
					'price_unit'             => 'per_guest',
					'base_price'             => 10000,
					'base_price_pre_holiday' => '10000',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '1室あたり・特定日あり: true',
				'args'                => array(
					'price_unit'         => 'per_room',
					'base_price'         => 30000,
					'base_price_special' => 40000,
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '料金区分あり: 基本料金の休前日は使わないので false',
				'args'                => array(
					'price_unit'             => 'per_guest',
					'base_price'             => 10000,
					'base_price_pre_holiday' => 12000,
					'guest_tiers'            => array(
						array(
							'label' => '大人',
							'price' => 10000,
							'count' => 1,
						),
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '料金区分の行に休前日あり: true',
				'args'                => array(
					'price_unit'  => 'per_guest',
					'base_price'  => 10000,
					'guest_tiers' => $tiers_with_pre_holiday,
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '1室あたりは料金区分を見ない: false',
				'args'                => array(
					'price_unit'  => 'per_room',
					'base_price'  => 30000,
					'guest_tiers' => $tiers_with_pre_holiday,
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Price::has_rate_tiers( $case['args'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * build_stay_display: 設定ありなら泊ごとの区分と特定日の名称を返し、設定なしなら無効（区分なし）を返す。
	 */
	public function test_build_stay_display(): void {
		$plan_inputs = array(
			'price_unit'             => 'per_guest',
			'base_price'             => 10000,
			'base_price_pre_holiday' => 13000,
			'base_price_special'     => 20000,
			'pre_holiday_weekdays'   => array( 'sat' ),
			'special_periods'        => array(
				array(
					'start' => '2026-10-09',
					'end'   => '2026-10-09',
					'label' => '連休',
				),
			),
		);

		// 2026-10-09（金・特定日）、10-10（土・休前日）、10-14（水・平日）。
		$calculated = Lodging_Price::calculate(
			array_merge(
				$plan_inputs,
				array(
					'night_dates'    => array( '2026-10-09', '2026-10-10', '2026-10-14' ),
					'guests'         => 1,
					'room_surcharge' => 1000,
				)
			)
		);

		$display = Lodging_Price_Display::build_stay_display( $plan_inputs, array(), $calculated['night_breakdown'] );

		$this->assertTrue( $display['enabled'], '休前日・特定日の料金を設定したプランは有効' );
		$this->assertSame(
			array(
				array( '2026-10-09', 'special', '連休' ),
				array( '2026-10-10', 'pre_holiday', '' ),
				array( '2026-10-14', 'weekday', '' ),
			),
			array_map(
				static fn( array $night ): array => array( $night['date'], $night['rate'], $night['special_label'] ),
				$display['night_rates']
			),
			'区分と特定日の名称（金額は計算結果の night_breakdown を使う）'
		);
		$this->assertSame(
			array( 21000, 14000, 11000 ),
			array_column( $calculated['night_breakdown'], 'price' ),
			'単価は プラン料金＋客室の追加料金'
		);

		// 特定日の料金が空欄のプランは、特定日の期間でも休前日・平日になり、名称は付けない。
		$plan_inputs['base_price_special'] = '';
		$calculated                        = Lodging_Price::calculate(
			array_merge(
				$plan_inputs,
				array(
					'night_dates' => array( '2026-10-09' ),
					'guests'      => 1,
				)
			)
		);
		$display                           = Lodging_Price_Display::build_stay_display( $plan_inputs, array(), $calculated['night_breakdown'] );
		$this->assertSame( 'weekday', $display['night_rates'][0]['rate'], '特定日の料金が空欄なら平日の泊' );
		$this->assertSame( '', $display['night_rates'][0]['special_label'], '特定日の料金を使わない泊には名称を付けない' );

		// 休前日・特定日が空欄のプランは無効で、計算結果をそのまま返す（キーも足さない）。
		$plain_inputs = array(
			'price_unit' => 'per_guest',
			'base_price' => 10000,
		);
		$calculated   = Lodging_Price::calculate(
			array_merge(
				$plain_inputs,
				array(
					'night_dates' => array( '2026-10-10' ),
					'guests'      => 1,
				)
			)
		);
		$display      = Lodging_Price_Display::build_stay_display( $plain_inputs, array(), $calculated['night_breakdown'] );
		$this->assertFalse( $display['enabled'], '休前日・特定日が空欄のプランは無効' );
		$this->assertSame( array(), $display['night_rates'], '無効なときは区分を返さない' );
	}

	/**
	 * build_menu_price_rows: 平日と違う金額がある行だけ最安＋「〜」。年中同じ料金は空配列。
	 */
	public function test_build_menu_price_rows(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '休前日・特定日が空欄: 空配列（今の表示のまま）',
				'args'                => array( 'per_guest', 7000, '', '', array() ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '休前日が平日より高い: 平日が最安で「〜」',
				'args'                => array( 'per_guest', 7000, '9000', '', array() ),
				'expected'            => array( array( '', 7000, true ) ),
			),
			array(
				'test_condition_name' => '休前日が平日より安い: 休前日が最安で「〜」',
				'args'                => array( 'per_guest', 9000, '7000', '', array() ),
				'expected'            => array( array( '', 7000, true ) ),
			),
			array(
				'test_condition_name' => '休前日・特定日が平日と同じ金額: 空配列',
				'args'                => array( 'per_guest', 7000, '7000', '7000', array() ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '特定日だけ違う: 「〜」',
				'args'                => array( 'per_guest', 7000, '', '12000', array() ),
				'expected'            => array( array( '', 7000, true ) ),
			),
			array(
				'test_condition_name' => '1室あたりは料金区分を見ない',
				'args'                => array(
					'per_room',
					30000,
					'40000',
					'',
					array(
						array(
							'label' => '大人',
							'price' => 1,
						),
					),
				),
				'expected'            => array( array( '', 30000, true ) ),
			),
			array(
				'test_condition_name' => '料金区分あり: 区分ごとに行（違う金額のある区分だけ「〜」）',
				'args'                => array(
					'per_guest',
					99999,
					'88888',
					'',
					array(
						array(
							'label'             => '大人',
							'price'             => 10000,
							'pre_holiday_price' => 12000,
						),
						array(
							'label' => '小学生',
							'price' => 6000,
						),
					),
				),
				'expected'            => array(
					array( '大人', 10000, true ),
					array( '小学生', 6000, false ),
				),
			),
			array(
				'test_condition_name' => '特定日の期間が無い: 特定日の金額は候補から外す（休前日が無ければ空配列）',
				'args'                => array( 'per_guest', 7000, '', '5000', array(), false ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '特定日の期間が無い: 休前日の金額は残す',
				'args'                => array( 'per_guest', 7000, '9000', '5000', array(), false ),
				'expected'            => array( array( '', 7000, true ) ),
			),
			array(
				'test_condition_name' => '料金区分あり・どの区分も平日と同じ: 空配列',
				'args'                => array(
					'per_guest',
					99999,
					'',
					'',
					array(
						array(
							'label' => '大人',
							'price' => 10000,
						),
					),
				),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$rows = Lodging_Price_Display::build_menu_price_rows( ...$case['args'] );
			$this->assertSame(
				$case['expected'],
				array_map( static fn( array $row ): array => array( $row['label'], $row['price'], $row['is_from'] ), $rows ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 表記: 「¥7,000〜 / 1名・1泊」の形（英語の原文では「¥7,000~ / 1 guests, 1 night」など）。
	 */
	public function test_format_price_with_unit(): void {
		$this->assertSame( '¥7,000', Lodging_Price_Display::format_amount( 7000, false ), '「〜」なし' );
		$this->assertSame( '¥7,000~', Lodging_Price_Display::format_amount( 7000, true ), '「〜」あり' );
		// 人数の単位は業種プリセットで決まるため、1名の表記は共通関数の結果を使う。
		$one_guest = vkbm_format_guests_count( 1 );
		$this->assertSame( $one_guest . ', 1 night', Lodging_Price_Display::format_unit_label( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST ), '1名あたり' );
		$this->assertSame( '1 room, 1 night', Lodging_Price_Display::format_unit_label( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM ), '1室あたり' );
		$this->assertSame(
			'¥7,000~ / ' . $one_guest . ', 1 night',
			Lodging_Price_Display::format_price_with_unit( 7000, true, Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST )
		);
	}

	/**
	 * 折り返し制御つきの表記: 金額と単位が別々の塊（.vkbm-price-chunk）になる。
	 */
	public function test_format_price_with_unit_html(): void {
		$one_guest = vkbm_format_guests_count( 1 );
		$this->assertSame(
			'<span class="vkbm-price-chunk">¥7,000~</span> / <span class="vkbm-price-chunk">' . esc_html( $one_guest . ', 1 night' ) . '</span>',
			Lodging_Price_Display::format_price_with_unit_html( 7000, true, Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST )
		);
	}

	/**
	 * get_menu_price_rows: 宿泊が有効なサイトの泊のプランだけ行を返す。時間枠のプラン・宿泊以外のサイトは空配列。
	 */
	public function test_get_menu_price_rows(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$night_meta = array(
			Service_Menu_Post_Type::META_BOOKING_UNIT => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
			'_vkbm_base_price'                        => '7000',
			Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => '9000',
		);
		$night_id   = (int) $this->factory()->post->create(
			array(
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'meta_input' => $night_meta,
			)
		);
		$slot_id    = (int) $this->factory()->post->create(
			array(
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'meta_input' => array_merge( $night_meta, array( Service_Menu_Post_Type::META_BOOKING_UNIT => '' ) ),
			)
		);

		$this->assertSame(
			array(
				array(
					'label'   => '',
					'price'   => 7000,
					'is_from' => true,
				),
			),
			Lodging_Price_Display::get_menu_price_rows( $night_id ),
			'泊のプラン'
		);
		$this->assertSame( array(), Lodging_Price_Display::get_menu_price_rows( $slot_id ), '時間枠のプランは対象外' );

		// 特定日の金額は、BM設定に特定日の期間が無ければ予約で使われないため、最安の候補にしない。
		$special_only_id = (int) $this->factory()->post->create(
			array(
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'meta_input' => array(
					Service_Menu_Post_Type::META_BOOKING_UNIT => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
					'_vkbm_base_price' => '7000',
					Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL => '5000',
				),
			)
		);
		$this->assertSame( array(), Lodging_Price_Display::get_menu_price_rows( $special_only_id ), '特定日の期間が無いと、特定日だけ違う金額は表記に出さない' );
		$this->assertSame( 7000, Lodging_Price_Display::get_menu_price_rows( $night_id )[0]['price'], '休前日の金額は祝日の前日にも使うため、期間・曜日の設定によらず候補に残す' );

		$settings                          = get_option( Settings_Repository::OPTION_KEY );
		$settings['lodging_special_dates'] = array(
			array(
				'start' => '2026-12-29',
				'end'   => '2027-01-03',
				'label' => '',
			),
		);
		update_option( Settings_Repository::OPTION_KEY, $settings );
		$this->assertSame( 5000, Lodging_Price_Display::get_menu_price_rows( $special_only_id )[0]['price'], '特定日の期間があれば特定日の金額も最安の候補になる' );

		$this->set_site( 'salon' );
		$this->assertSame( array(), Lodging_Price_Display::get_menu_price_rows( $night_id ), '宿泊以外のサイトは対象外' );
	}
}
