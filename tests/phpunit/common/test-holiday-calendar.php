<?php
/**
 * 祝日判定 Holiday_Calendar（#733）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Holiday_Calendar;
use WP_UnitTestCase;

/**
 * 同梱の祝日データ（2026 年分は内閣府 CSV 由来）を使った判定のテスト。
 *
 * @group common
 * @group holiday
 */
class Holiday_Calendar_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前にキャッシュを破棄する。
	 */
	protected function setUp(): void {
		parent::setUp();
		Holiday_Calendar::reset_cache();
	}

	/**
	 * 各テスト後にキャッシュを破棄し、フィルタの影響を残さない。
	 */
	protected function tearDown(): void {
		remove_all_filters( 'vkbm_holiday_calendar_data' );
		Holiday_Calendar::reset_cache();
		parent::tearDown();
	}

	/**
	 * is_holiday() が祝日・振替休日・非祝日・不正値・範囲外を正しく判定することを検証する。
	 */
	public function test_is_holiday(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '成人の日（2026-01-12） => 祝日',
				'ymd'                 => '2026-01-12',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '振替休日（2026-05-06） => 祝日',
				'ymd'                 => '2026-05-06',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '祝日の前日（2026-01-11） => 祝日ではない',
				'ymd'                 => '2026-01-11',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '平日（2026-01-13） => 祝日ではない',
				'ymd'                 => '2026-01-13',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'データ範囲外の年（2099-01-01） => false',
				'ymd'                 => '2099-01-01',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ゼロ埋めなし（2026-1-12） => false',
				'ymd'                 => '2026-1-12',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '存在しない日付（2026-02-30） => false',
				'ymd'                 => '2026-02-30',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付でない文字列 => false',
				'ymd'                 => 'abc',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空文字 => false',
				'ymd'                 => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Holiday_Calendar::is_holiday( $case['ymd'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_holiday_eve() が翌日基準で判定し、翌日が日曜というだけでは祝日前日にせず、年またぎも扱うことを検証する。
	 */
	public function test_is_holiday_eve(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '成人の日の前日（2026-01-11、日曜） => 祝日前日',
				'ymd'                 => '2026-01-11',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '祝日かつ祝日前日（2026-05-04、翌日がこどもの日） => 祝日前日',
				'ymd'                 => '2026-05-04',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '振替休日の前日（2026-05-05、こどもの日） => 祝日前日',
				'ymd'                 => '2026-05-05',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '振替休日の当日（2026-05-06、翌日は平日） => 祝日前日ではない',
				'ymd'                 => '2026-05-06',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '翌日が日曜だけの土曜（2026-01-10） => 祝日前日ではない',
				'ymd'                 => '2026-01-10',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '年またぎ（2025-12-31、翌日が元日） => 祝日前日',
				'ymd'                 => '2025-12-31',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'データ範囲外の年（2099-12-31） => false',
				'ymd'                 => '2099-12-31',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '不正な文字列 => false',
				'ymd'                 => '2026/01/11',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Holiday_Calendar::is_holiday_eve( $case['ymd'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_name() が祝日名を返し、祝日でない場合は null を返すことを検証する。
	 */
	public function test_get_name(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '元日 => 元日',
				'ymd'                 => '2026-01-01',
				'expected'            => '元日',
			),
			array(
				'test_condition_name' => '振替休日 => 休日',
				'ymd'                 => '2026-05-06',
				'expected'            => '休日',
			),
			array(
				'test_condition_name' => '平日 => null',
				'ymd'                 => '2026-01-13',
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'データ範囲外 => null',
				'ymd'                 => '2099-01-01',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '不正な文字列 => null',
				'ymd'                 => 'not-a-date',
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Holiday_Calendar::get_name( $case['ymd'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_covered_until() が最終年の 12/31 を返すこと（最後の祝日の日付ではないこと）を検証する。
	 */
	public function test_get_covered_until(): void {
		// 同梱データは内閣府 CSV の最終年までを含む。年末であることを確認する。
		$covered_until = (string) Holiday_Calendar::get_covered_until();
		$this->assertMatchesRegularExpression( '/^\d{4}-12-31$/', $covered_until, '同梱データ => 最終年の 12/31' );
		$this->assertGreaterThanOrEqual( '2027-12-31', $covered_until, '同梱データ => 2027 年以降をカバーする' );

		// 最終の祝日が 11/23 でも、その年の 12/31 を返す。
		$this->set_data_filter(
			array(
				'2030-01-01' => '元日',
				'2031-11-23' => '勤労感謝の日',
			)
		);
		$this->assertSame( '2031-12-31', Holiday_Calendar::get_covered_until(), '最終の祝日が 11/23 => その年の 12/31' );

		// データが空なら null。
		$this->set_data_filter( array() );
		$this->assertNull( Holiday_Calendar::get_covered_until(), 'データが空 => null' );
	}

	/**
	 * フィルタ vkbm_holiday_calendar_data でデータを差し替えられることを検証する。
	 */
	public function test_vkbm_holiday_calendar_data_filter(): void {
		$this->set_data_filter(
			array(
				'2026-03-03' => 'テスト祝日',
				'2026-01-01' => '元日（テスト）',
			)
		);

		$this->assertTrue( Holiday_Calendar::is_holiday( '2026-03-03' ), '差し替えた祝日 => 祝日' );
		$this->assertSame( 'テスト祝日', Holiday_Calendar::get_name( '2026-03-03' ), '差し替えた祝日 => 祝日名' );
		$this->assertTrue( Holiday_Calendar::is_holiday_eve( '2026-03-02' ), '差し替えた祝日の前日 => 祝日前日' );
		$this->assertFalse( Holiday_Calendar::is_holiday( '2026-01-12' ), '差し替えで消えた祝日 => 祝日ではない' );
		$this->assertSame( '2026-12-31', Holiday_Calendar::get_covered_until(), '順不同のデータでも最終年を取れる' );
	}

	/**
	 * フィルタが不正な値を返しても、有効な項目だけが使われることを検証する。
	 */
	public function test_vkbm_holiday_calendar_data_filter_invalid_values(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '配列でない値 => 祝日なし',
				'filtered'            => 'not-an-array',
				'holiday_ymd'         => '2026-01-12',
				'expected_holiday'    => false,
				'expected_covered'    => null,
			),
			array(
				'test_condition_name' => '0 始まりの番号配列 => 祝日なし',
				'filtered'            => array( '2026-01-12', '2026-01-13' ),
				'holiday_ymd'         => '2026-01-12',
				'expected_holiday'    => false,
				'expected_covered'    => null,
			),
			array(
				'test_condition_name' => '祝日名が配列・空文字 => その項目は捨てる',
				'filtered'            => array(
					'2026-01-12' => array( '成人の日' ),
					'2026-01-13' => '',
					'2026-02-11' => '建国記念の日',
				),
				'holiday_ymd'         => '2026-01-12',
				'expected_holiday'    => false,
				'expected_covered'    => '2026-12-31',
			),
			array(
				'test_condition_name' => '日付として不正なキー => その項目は捨てる',
				'filtered'            => array(
					'2026-1-12'  => '成人の日',
					'2026-02-30' => '存在しない日',
					'2027-02-11' => '建国記念の日',
				),
				'holiday_ymd'         => '2026-1-12',
				'expected_holiday'    => false,
				'expected_covered'    => '2027-12-31',
			),
			array(
				'test_condition_name' => '祝日名が数値 => 文字列にして使う',
				'filtered'            => array( '2026-01-12' => 123 ),
				'holiday_ymd'         => '2026-01-12',
				'expected_holiday'    => true,
				'expected_covered'    => '2026-12-31',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_data_filter( $case['filtered'] );

			$this->assertSame( $case['expected_holiday'], Holiday_Calendar::is_holiday( $case['holiday_ymd'] ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_covered'], Holiday_Calendar::get_covered_until(), $case['test_condition_name'] );
		}

		$this->assertSame( '123', Holiday_Calendar::get_name( '2026-01-12' ), '数値の祝日名 => 文字列で返る' );
	}

	/**
	 * 祝日データをフィルタで差し替え、キャッシュを破棄する。
	 *
	 * @param mixed $data フィルタが返す値.
	 */
	private function set_data_filter( $data ): void {
		remove_all_filters( 'vkbm_holiday_calendar_data' );
		add_filter(
			'vkbm_holiday_calendar_data',
			static function () use ( $data ) {
				return $data;
			}
		);
		Holiday_Calendar::reset_cache();
	}
}
