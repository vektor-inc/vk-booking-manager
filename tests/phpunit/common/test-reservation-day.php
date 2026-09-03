<?php
/**
 * 予約可能日ユーティリティ Reservation_Day のテスト。
 *
 * 種別の正規化（sanitize_type）・曜日番号に対する可否判定（is_weekday_allowed）・
 * 種別→ラベルの写像（label）が期待どおりに動作することを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Reservation_Day;
use WP_UnitTestCase;

/**
 * Reservation_Day の静的メソッドを検証するテスト。
 *
 * @group common
 */
class Reservation_Day_Test extends WP_UnitTestCase {

	/**
	 * 予約可能日種別の正規化を確認する。
	 *
	 * 許容値（''|weekend|weekday）はそのまま、許容外は指定なし（''）へ丸められること。
	 */
	public function test_sanitize_type(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '指定なし（空文字）はそのまま => ""',
				'input'               => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'weekend はそのまま => "weekend"',
				'input'               => 'weekend',
				'expected'            => 'weekend',
			),
			array(
				'test_condition_name' => 'weekday はそのまま => "weekday"',
				'input'               => 'weekday',
				'expected'            => 'weekday',
			),
			array(
				'test_condition_name' => 'custom_weekday はそのまま => "custom_weekday"',
				'input'               => 'custom_weekday',
				'expected'            => 'custom_weekday',
			),
			array(
				'test_condition_name' => 'custom_date はそのまま => "custom_date"',
				'input'               => 'custom_date',
				'expected'            => 'custom_date',
			),
			array(
				'test_condition_name' => '未知の値は指定なしへ丸められる => ""',
				'input'               => 'holiday',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '大文字違い（WEEKEND）は許容外なので指定なしへ => ""',
				'input'               => 'WEEKEND',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Reservation_Day::sanitize_type( $case['input'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 曜日番号（N: 1=月〜7=日）に対する可否判定を確認する。
	 */
	public function test_is_weekday_allowed(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '指定なしは月曜(1)を許可 => true',
				'reservation_day_type' => '',
				'weekday'              => 1,
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '指定なしは日曜(7)を許可 => true',
				'reservation_day_type' => '',
				'weekday'              => 7,
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定は土曜(6)を許可 => true',
				'reservation_day_type' => 'weekend',
				'weekday'              => 6,
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定は日曜(7)を許可 => true',
				'reservation_day_type' => 'weekend',
				'weekday'              => 7,
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定は金曜(5)を不許可 => false',
				'reservation_day_type' => 'weekend',
				'weekday'              => 5,
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定は月曜(1)を許可 => true',
				'reservation_day_type' => 'weekday',
				'weekday'              => 1,
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '平日限定は土曜(6)を不許可 => false',
				'reservation_day_type' => 'weekday',
				'weekday'              => 6,
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定は日曜(7)を不許可 => false',
				'reservation_day_type' => 'weekday',
				'weekday'              => 7,
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '境界値：未知の種別はフォールバックで許可 => true',
				'reservation_day_type' => 'holiday',
				'weekday'              => 6,
				'expected'             => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Reservation_Day::is_weekday_allowed( $case['reservation_day_type'], $case['weekday'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 種別→表示用ラベルの写像を確認する。
	 *
	 * 既知の種別は翻訳済みラベル、未知の値は入力値そのままを返すこと。
	 */
	public function test_label(): void {
		// 期待ラベルは実装と同じ翻訳関数で組み立てる（ロケール差の影響を避ける）。
		$weekend_label        = __( 'Saturdays and Sundays only', 'vk-booking-manager' );
		$weekday_label        = __( 'Weekdays only', 'vk-booking-manager' );
		$custom_weekday_label = __( 'Specified days of the week', 'vk-booking-manager' );
		$custom_date_label    = __( 'Specified dates', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name'  => 'weekend => 土日限定ラベル',
				'reservation_day_type' => 'weekend',
				'expected'             => $weekend_label,
			),
			array(
				'test_condition_name'  => 'weekday => 平日限定ラベル',
				'reservation_day_type' => 'weekday',
				'expected'             => $weekday_label,
			),
			array(
				'test_condition_name'  => 'custom_weekday => 曜日指定ラベル',
				'reservation_day_type' => 'custom_weekday',
				'expected'             => $custom_weekday_label,
			),
			array(
				'test_condition_name'  => 'custom_date => 日付指定ラベル',
				'reservation_day_type' => 'custom_date',
				'expected'             => $custom_date_label,
			),
			array(
				'test_condition_name'  => '未知の値は入力値そのまま => "holiday"',
				'reservation_day_type' => 'holiday',
				'expected'             => 'holiday',
			),
			array(
				'test_condition_name'  => '空文字は空文字のまま => ""',
				'reservation_day_type' => '',
				'expected'             => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Reservation_Day::label( $case['reservation_day_type'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 実際の日付に対する予約可否判定（is_date_allowed）を確認する。
	 *
	 * '' / weekend / weekday は曜日番号ベースの従来判定、custom_weekday は「頻度 × 曜日」、
	 * custom_date は「単日・期間」の突き合わせで判定されること。空設定は不可（false）になること。
	 */
	public function test_is_date_allowed(): void {
		// テスト用のタイムゾーン（東京固定）。判定は曜日・日付のみを見るため TZ に依存しないが、
		// 実際の呼び出し箇所と同様にタイムゾーン付きの日付で検証する。
		$tokyo = new \DateTimeZone( 'Asia/Tokyo' );

		// 2026年7月2日は第1木曜、7月16日は第3木曜、7月4日は土曜、7月6日は月曜。
		$test_cases = array(
			array(
				'test_condition_name' => '指定なしは任意の日付を許可 => true',
				'type'                => '',
				'date'                => '2026-07-06',
				'config'              => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '土日限定は土曜(2026-07-04)を許可 => true',
				'type'                => 'weekend',
				'date'                => '2026-07-04',
				'config'              => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '平日限定は土曜(2026-07-04)を不許可 => false',
				'type'                => 'weekday',
				'date'                => '2026-07-04',
				'config'              => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '曜日指定：毎週木曜は第1木曜(2026-07-02)を許可 => true',
				'type'                => 'custom_weekday',
				'date'                => '2026-07-02',
				'config'              => array(
					'weekdays' => array(
						array(
							'frequency' => 'weekly',
							'weekday'   => 'thu',
						),
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '曜日指定：毎週木曜は月曜(2026-07-06)を不許可 => false',
				'type'                => 'custom_weekday',
				'date'                => '2026-07-06',
				'config'              => array(
					'weekdays' => array(
						array(
							'frequency' => 'weekly',
							'weekday'   => 'thu',
						),
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '曜日指定：第3木曜(2026-07-16)は第1木曜(2026-07-02)を不許可 => false',
				'type'                => 'custom_weekday',
				'date'                => '2026-07-02',
				'config'              => array(
					'weekdays' => array(
						array(
							'frequency' => 'nth-3',
							'weekday'   => 'thu',
						),
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '曜日指定：第3木曜は第3木曜(2026-07-16)を許可 => true',
				'type'                => 'custom_weekday',
				'date'                => '2026-07-16',
				'config'              => array(
					'weekdays' => array(
						array(
							'frequency' => 'nth-3',
							'weekday'   => 'thu',
						),
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '曜日指定：設定が空なら不可（誤設定防御）=> false',
				'type'                => 'custom_weekday',
				'date'                => '2026-07-02',
				'config'              => array( 'weekdays' => array() ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付指定：単日一致(2026-07-10)を許可 => true',
				'type'                => 'custom_date',
				'date'                => '2026-07-10',
				'config'              => array(
					'dates' => array(
						array(
							'type' => 'single',
							'date' => '2026-07-10',
						),
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '日付指定：単日不一致(2026-07-11)を不許可 => false',
				'type'                => 'custom_date',
				'date'                => '2026-07-11',
				'config'              => array(
					'dates' => array(
						array(
							'type' => 'single',
							'date' => '2026-07-10',
						),
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付指定：期間内(2026-07-15)を許可 => true',
				'type'                => 'custom_date',
				'date'                => '2026-07-15',
				'config'              => array(
					'dates' => array(
						array(
							'type'  => 'range',
							'start' => '2026-07-10',
							'end'   => '2026-07-20',
						),
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '日付指定：境界（開始日 2026-07-10）を許可 => true',
				'type'                => 'custom_date',
				'date'                => '2026-07-10',
				'config'              => array(
					'dates' => array(
						array(
							'type'  => 'range',
							'start' => '2026-07-10',
							'end'   => '2026-07-20',
						),
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '日付指定：境界外（終了日翌日 2026-07-21）を不許可 => false',
				'type'                => 'custom_date',
				'date'                => '2026-07-21',
				'config'              => array(
					'dates' => array(
						array(
							'type'  => 'range',
							'start' => '2026-07-10',
							'end'   => '2026-07-20',
						),
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付指定：設定が空なら不可（誤設定防御）=> false',
				'type'                => 'custom_date',
				'date'                => '2026-07-10',
				'config'              => array( 'dates' => array() ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$date   = new \DateTimeImmutable( $case['date'] . ' 12:00:00', $tokyo );
			$actual = Reservation_Day::is_date_allowed( $case['type'], $date, $case['config'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 日付指定（単日・期間）のサニタイズを確認する。
	 *
	 * 妥当な単日・期間は正規化され、不正な形式・start>end・存在しない日付は破棄されること。
	 * $today を渡した場合は過去日（単日は date<today、期間は end<today）が破棄されること。
	 */
	public function test_sanitize_custom_dates(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '妥当な単日はそのまま残る',
				'raw'                 => array(
					array(
						'type' => 'single',
						'date' => '2026-07-10',
					),
				),
				'today'               => null,
				'expected'            => array(
					array(
						'type' => 'single',
						'date' => '2026-07-10',
					),
				),
			),
			array(
				'test_condition_name' => '妥当な期間（start<=end）はそのまま残る',
				'raw'                 => array(
					array(
						'type'  => 'range',
						'start' => '2026-07-10',
						'end'   => '2026-07-20',
					),
				),
				'today'               => null,
				'expected'            => array(
					array(
						'type'  => 'range',
						'start' => '2026-07-10',
						'end'   => '2026-07-20',
					),
				),
			),
			array(
				'test_condition_name' => 'start>end の期間は破棄 => 空配列',
				'raw'                 => array(
					array(
						'type'  => 'range',
						'start' => '2026-07-20',
						'end'   => '2026-07-10',
					),
				),
				'today'               => null,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '存在しない日付（2026-02-30）は破棄 => 空配列',
				'raw'                 => array(
					array(
						'type' => 'single',
						'date' => '2026-02-30',
					),
				),
				'today'               => null,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '不正な形式（2026/07/10）は破棄 => 空配列',
				'raw'                 => array(
					array(
						'type' => 'single',
						'date' => '2026/07/10',
					),
				),
				'today'               => null,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'today 指定時、過去の単日は破棄 => 空配列',
				'raw'                 => array(
					array(
						'type' => 'single',
						'date' => '2026-07-01',
					),
				),
				'today'               => '2026-07-10',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'today 指定時、終了日が過去の期間は破棄 => 空配列',
				'raw'                 => array(
					array(
						'type'  => 'range',
						'start' => '2026-06-01',
						'end'   => '2026-07-01',
					),
				),
				'today'               => '2026-07-10',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'today 指定時、開始が過去でも終了が当日以降の期間は残る',
				'raw'                 => array(
					array(
						'type'  => 'range',
						'start' => '2026-07-01',
						'end'   => '2026-07-20',
					),
				),
				'today'               => '2026-07-10',
				'expected'            => array(
					array(
						'type'  => 'range',
						'start' => '2026-07-01',
						'end'   => '2026-07-20',
					),
				),
			),
			array(
				'test_condition_name' => '配列でない入力は空配列を返す',
				'raw'                 => 'invalid',
				'today'               => null,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Reservation_Day::sanitize_custom_dates( $case['raw'], $case['today'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
