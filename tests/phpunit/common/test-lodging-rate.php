<?php
/**
 * 泊の料金区分（平日・休前日・特定日）の判定 Lodging_Rate のテスト（#737）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Holiday_Calendar;
use VKBookingManager\Common\Lodging_Rate;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Lodging_Rate の正規化・日付判定・設定の読み取りのテスト。
 *
 * @group common
 * @group lodging
 */
class Lodging_Rate_Test extends WP_UnitTestCase {

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
	 * テスト後に全体設定を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Holiday_Calendar::reset_cache();
		parent::tearDown();
	}

	/**
	 * normalize_weekdays: 有効な曜日キーだけを、月曜始まりの順に重複なく返す。
	 */
	public function test_normalize_weekdays(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '土曜のみ => sat',
				'raw'                 => array( 'sat' ),
				'expected'            => array( 'sat' ),
			),
			array(
				'test_condition_name' => '順不同・重複・大文字・空文字・不正値を含む => 有効なものだけを月曜始まりの順に',
				'raw'                 => array( '', 'SUN', 'fri', 'fri', 'holiday', 'mon', 123 ),
				'expected'            => array( 'mon', 'fri', 'sun' ),
			),
			array(
				'test_condition_name' => '空文字だけ（チェックが 1 つも無い保存） => 空配列',
				'raw'                 => array( '' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列でない値 => 空配列',
				'raw'                 => 'sat',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Rate::normalize_weekdays( $case['raw'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * normalize_special_periods: 有効な行だけを開始日の昇順で返す。
	 */
	public function test_normalize_special_periods(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日の降順で渡しても、開始日の昇順で返る。名称は任意',
				'raw'                 => array(
					array(
						'start' => '2026-12-28',
						'end'   => '2027-01-03',
						'label' => '年末年始',
					),
					array(
						'start' => '2026-08-13',
						'end'   => '2026-08-16',
					),
				),
				'expected'            => array(
					array(
						'start' => '2026-08-13',
						'end'   => '2026-08-16',
						'label' => '',
					),
					array(
						'start' => '2026-12-28',
						'end'   => '2027-01-03',
						'label' => '年末年始',
					),
				),
			),
			array(
				'test_condition_name' => '開始日と終了日が同じ（1 日だけ）は有効',
				'raw'                 => array(
					array(
						'start' => '2026-05-03',
						'end'   => '2026-05-03',
						'label' => '',
					),
				),
				'expected'            => array(
					array(
						'start' => '2026-05-03',
						'end'   => '2026-05-03',
						'label' => '',
					),
				),
			),
			array(
				'test_condition_name' => '終了日が開始日より前・実在しない日付・片方が空の行は除く',
				'raw'                 => array(
					array(
						'start' => '2026-05-06',
						'end'   => '2026-05-03',
					),
					array(
						'start' => '2026-02-30',
						'end'   => '2026-03-02',
					),
					array(
						'start' => '2026-05-03',
						'end'   => '',
					),
					'not-an-array',
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '名称のタグは除き、50 文字までに切り詰める',
				'raw'                 => array(
					array(
						'start' => '2026-05-03',
						'end'   => '2026-05-05',
						'label' => '<b>GW</b>' . str_repeat( 'あ', 60 ),
					),
				),
				'expected'            => array(
					array(
						'start' => '2026-05-03',
						'end'   => '2026-05-05',
						'label' => mb_substr( 'GW' . str_repeat( 'あ', 60 ), 0, 50 ),
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Rate::normalize_special_periods( $case['raw'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_special_date: 開始日・終了日を含む期間に入るかを判定する。
	 */
	public function test_is_special_date(): void {
		$periods = array(
			array(
				'start' => '2026-12-28',
				'end'   => '2027-01-03',
				'label' => '',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '開始日の泊 => 特定日',
				'date'                => '2026-12-28',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了日の泊 => 特定日（終了日の泊も含む）',
				'date'                => '2027-01-03',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '年をまたぐ期間の途中 => 特定日',
				'date'                => '2027-01-01',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '開始日の前日 => 特定日ではない',
				'date'                => '2026-12-27',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了日の翌日 => 特定日ではない',
				'date'                => '2027-01-04',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Rate::is_special_date( $case['date'], $periods ), $case['test_condition_name'] );
		}
		$this->assertFalse( Lodging_Rate::is_special_date( '2026-12-28', array() ), '特定日が未登録 => 特定日ではない' );
	}

	/**
	 * is_pre_holiday_date: 選んだ曜日、または翌日が祝日の日が休前日。祝日データの範囲外は曜日だけで決める。
	 */
	public function test_is_pre_holiday_date(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '土曜を休前日にした設定で土曜（2026-10-10）=> 休前日',
				'date'                => '2026-10-10',
				'weekdays'            => array( 'sat' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '土曜を休前日にした設定で金曜（2026-10-09）=> 平日',
				'date'                => '2026-10-09',
				'weekdays'            => array( 'sat' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '金曜も休前日にした設定で金曜 => 休前日',
				'date'                => '2026-10-09',
				'weekdays'            => array( 'fri', 'sat' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '曜日を 1 つも選ばない設定でも、祝日の前日（2026-05-02 土曜）は休前日',
				'date'                => '2026-05-02',
				'weekdays'            => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '祝日の前日（2026-11-02 月曜。11/3 が文化の日）は曜日に関係なく休前日',
				'date'                => '2026-11-02',
				'weekdays'            => array( 'sat' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '祝日の当日（2026-11-03）は休前日ではない',
				'date'                => '2026-11-03',
				'weekdays'            => array( 'sat' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '祝日データの範囲外（2099-12-31 木曜）は曜日だけで決める => 平日（土曜のみの設定）',
				'date'                => '2099-12-31',
				'weekdays'            => array( 'sat' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '祝日データの範囲外でも、選んだ曜日（木曜を選択）なら休前日',
				'date'                => '2099-12-31',
				'weekdays'            => array( 'thu' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '不正な日付 => 休前日ではない',
				'date'                => 'invalid',
				'weekdays'            => array( 'sat' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Rate::is_pre_holiday_date( $case['date'], $case['weekdays'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * build_night_dates: チェックイン日から泊数ぶんの日付を返す（チェックアウト日は含めない）。
	 */
	public function test_build_night_dates(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '金曜から 2 泊 => 金曜・土曜',
				'check_in_date'       => '2026-10-09',
				'nights'              => 2,
				'expected'            => array( '2026-10-09', '2026-10-10' ),
			),
			array(
				'test_condition_name' => '年またぎの 3 泊',
				'check_in_date'       => '2026-12-30',
				'nights'              => 3,
				'expected'            => array( '2026-12-30', '2026-12-31', '2027-01-01' ),
			),
			array(
				'test_condition_name' => '泊数が 0 => 空配列',
				'check_in_date'       => '2026-10-09',
				'nights'              => 0,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '不正な日付 => 空配列',
				'check_in_date'       => '2026-13-01',
				'nights'              => 2,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Lodging_Rate::build_night_dates( $case['check_in_date'], $case['nights'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_settings: BM設定の休前日の曜日と特定日を読む。未保存のサイトは土曜のみ。
	 */
	public function test_get_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '休前日の曜日が未保存のサイト => 土曜のみ・特定日なし',
				'stored'              => array(),
				'expected'            => array(
					'weekdays'        => array( 'sat' ),
					'special_periods' => array(),
				),
			),
			array(
				'test_condition_name' => '曜日を 1 つも選ばずに保存したサイト => 空配列（既定の土曜に戻さない）',
				'stored'              => array( 'lodging_pre_holiday_weekdays' => array() ),
				'expected'            => array(
					'weekdays'        => array(),
					'special_periods' => array(),
				),
			),
			array(
				'test_condition_name' => '保存した曜日と特定日を返す',
				'stored'              => array(
					'lodging_pre_holiday_weekdays' => array( 'fri', 'sat' ),
					'lodging_special_dates'        => array(
						array(
							'start' => '2026-05-01',
							'end'   => '2026-05-06',
							'label' => 'GW',
						),
					),
				),
				'expected'            => array(
					'weekdays'        => array( 'fri', 'sat' ),
					'special_periods' => array(
						array(
							'start' => '2026-05-01',
							'end'   => '2026-05-06',
							'label' => 'GW',
						),
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$settings = array_merge( $defaults, $case['stored'] );
			if ( array() === $case['stored'] ) {
				// 未保存のサイトは、キーそのものが保存されていない状態にする。
				unset( $settings['lodging_pre_holiday_weekdays'], $settings['lodging_special_dates'] );
			}
			update_option( Settings_Repository::OPTION_KEY, $settings );

			$this->assertSame( $case['expected'], Lodging_Rate::get_settings(), $case['test_condition_name'] );
		}
	}
}
