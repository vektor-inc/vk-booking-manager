<?php
/**
 * 「頻度 × 曜日」ルール共通ユーティリティ Weekday_Rule のテスト。
 *
 * ルールのサニタイズ（sanitize_rules）・日付マッチング判定（matches）・
 * 月内の同曜日出現回数（occurrence_in_month）が期待どおりに動作することを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Weekday_Rule;
use WP_UnitTestCase;

/**
 * Weekday_Rule の静的メソッドを検証するテスト。
 *
 * @group common
 */
class Weekday_Rule_Test extends WP_UnitTestCase {

	/**
	 * 「頻度 × 曜日」ルール配列のサニタイズを確認する。
	 *
	 * 許容外の頻度・曜日キーを含む行は破棄し、正規化した行だけを返すこと。
	 */
	public function test_sanitize_rules(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '妥当な行（毎週・月曜）はそのまま残る',
				'raw'                 => array(
					array(
						'frequency' => 'weekly',
						'weekday'   => 'mon',
					),
				),
				'expected'            => array(
					array(
						'frequency' => 'weekly',
						'weekday'   => 'mon',
					),
				),
			),
			array(
				'test_condition_name' => '妥当な行（第3・木曜）はそのまま残る',
				'raw'                 => array(
					array(
						'frequency' => 'nth-3',
						'weekday'   => 'thu',
					),
				),
				'expected'            => array(
					array(
						'frequency' => 'nth-3',
						'weekday'   => 'thu',
					),
				),
			),
			array(
				'test_condition_name' => '許容外の頻度（nth-9）を含む行は破棄 => 空配列',
				'raw'                 => array(
					array(
						'frequency' => 'nth-9',
						'weekday'   => 'mon',
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '許容外の曜日キー（holiday）を含む行は破棄 => 空配列',
				'raw'                 => array(
					array(
						'frequency' => 'weekly',
						'weekday'   => 'holiday',
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列でない入力は空配列を返す',
				'raw'                 => 'invalid',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Weekday_Rule::sanitize_rules( $case['raw'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 日付が「頻度 × 曜日」ルールに該当するかの判定を確認する。
	 *
	 * 毎週は曜日一致で該当、第N曜は曜日一致かつ月内の出現回数一致で該当すること。
	 */
	public function test_matches(): void {
		$tokyo = new \DateTimeZone( 'Asia/Tokyo' );

		// 2026年7月2日は第1木曜、7月16日は第3木曜、7月6日は月曜。
		$test_cases = array(
			array(
				'test_condition_name' => '毎週木曜は第1木曜(2026-07-02)に該当 => true',
				'date'                => '2026-07-02',
				'rules'               => array(
					array(
						'frequency' => 'weekly',
						'weekday'   => 'thu',
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '毎週木曜は月曜(2026-07-06)に非該当 => false',
				'date'                => '2026-07-06',
				'rules'               => array(
					array(
						'frequency' => 'weekly',
						'weekday'   => 'thu',
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '第3木曜は第3木曜(2026-07-16)に該当 => true',
				'date'                => '2026-07-16',
				'rules'               => array(
					array(
						'frequency' => 'nth-3',
						'weekday'   => 'thu',
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '第3木曜は第1木曜(2026-07-02)に非該当 => false',
				'date'                => '2026-07-02',
				'rules'               => array(
					array(
						'frequency' => 'nth-3',
						'weekday'   => 'thu',
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '複数ルール（第2・第4月曜）は第2月曜(2026-07-13)に該当 => true',
				'date'                => '2026-07-13',
				'rules'               => array(
					array(
						'frequency' => 'nth-2',
						'weekday'   => 'mon',
					),
					array(
						'frequency' => 'nth-4',
						'weekday'   => 'mon',
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '空ルールはどの日にも非該当 => false',
				'date'                => '2026-07-02',
				'rules'               => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$date   = new \DateTimeImmutable( $case['date'] . ' 12:00:00', $tokyo );
			$actual = Weekday_Rule::matches( $date, $case['rules'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 月内の同曜日出現回数（第何週目か）の算出を確認する。
	 */
	public function test_occurrence_in_month(): void {
		$tokyo = new \DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => '2026-07-02（第1木曜）=> 1',
				'date'                => '2026-07-02',
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '2026-07-16（第3木曜）=> 3',
				'date'                => '2026-07-16',
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '2026-07-01（1日）=> 1（境界値）',
				'date'                => '2026-07-01',
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '2026-07-29（5週目）=> 5（境界値）',
				'date'                => '2026-07-29',
				'expected'            => 5,
			),
		);

		foreach ( $test_cases as $case ) {
			$date   = new \DateTimeImmutable( $case['date'] . ' 12:00:00', $tokyo );
			$actual = Weekday_Rule::occurrence_in_month( $date );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
