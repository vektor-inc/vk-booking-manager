<?php
/**
 * フロント予約カレンダー（月次空き状況）の予約可否判定のテスト。
 *
 * Availability_Service::is_date_allowed_for_menu() が、予約可能日種別
 * （''=指定なし / weekend=土日限定 / weekday=平日限定）と対象日から
 * 正しく可否（bool）を返すことを検証する。集約リファクタ前後で挙動が
 * 変わらないことを保証するための回帰（characterization）テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeZone;
use ReflectionClass;
use VKBookingManager\Availability\Availability_Service;
use WP_UnitTestCase;

/**
 * 予約可能日種別による日付可否判定を検証するテスト。
 *
 * @group availability
 */
class Is_Date_Allowed_For_Menu_Test extends WP_UnitTestCase {

	/**
	 * private メソッド is_date_allowed_for_menu をリフレクション経由で呼び出す。
	 *
	 * @param string               $reservation_day_type 予約可能日種別（''|weekend|weekday|custom_weekday|custom_date）。
	 * @param string               $date                 対象日（Y-m-d）。
	 * @param array<string, mixed> $config               custom 種別の詳細設定（reservation_custom_weekdays / reservation_custom_dates）。
	 * @return bool 予約可否。
	 */
	private function call_is_date_allowed_for_menu( string $reservation_day_type, string $date, array $config = array() ): bool {
		$service    = new Availability_Service();
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'is_date_allowed_for_menu' );
		$method->setAccessible( true );
		// メソッドは get_menu_settings() の戻り値（設定配列）を第1引数に取る。
		$menu_settings = array_merge(
			array(
				'reservation_day_type'        => $reservation_day_type,
				'reservation_custom_weekdays' => array(),
				'reservation_custom_dates'    => array(),
			),
			$config
		);
		// タイムゾーンは月次空き状況と同じく明示的に Asia/Tokyo を渡す。
		return (bool) $method->invoke( $service, $menu_settings, $date, new DateTimeZone( 'Asia/Tokyo' ) );
	}

	/**
	 * 予約可能日種別と対象日の組み合わせで期待どおりの可否を返すことを確認する。
	 *
	 * 対象日は曜日が確定している固定日付を使用する。
	 * 2026-04-01 は水曜（平日）、2026-04-04 は土曜、2026-04-05 は日曜。
	 */
	public function test_is_date_allowed_for_menu(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '指定なし（空）で平日（水曜） => 許可（true）',
				'reservation_day_type' => '',
				'date'                 => '2026-04-01',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '指定なし（空）で土曜 => 許可（true）',
				'reservation_day_type' => '',
				'date'                 => '2026-04-04',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で土曜 => 許可（true）',
				'reservation_day_type' => 'weekend',
				'date'                 => '2026-04-04',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で日曜 => 許可（true）',
				'reservation_day_type' => 'weekend',
				'date'                 => '2026-04-05',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で平日（水曜） => 不許可（false）',
				'reservation_day_type' => 'weekend',
				'date'                 => '2026-04-01',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で平日（水曜） => 許可（true）',
				'reservation_day_type' => 'weekday',
				'date'                 => '2026-04-01',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で土曜 => 不許可（false）',
				'reservation_day_type' => 'weekday',
				'date'                 => '2026-04-04',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で日曜 => 不許可（false）',
				'reservation_day_type' => 'weekday',
				'date'                 => '2026-04-05',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '境界値：パースできない不正な日付文字列 => フォールバックで許可（true）',
				'reservation_day_type' => 'weekend',
				'date'                 => 'not-a-date',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '曜日指定（毎週水曜）で水曜（2026-04-01） => 許可（true）',
				'reservation_day_type' => 'custom_weekday',
				'date'                 => '2026-04-01',
				'config'               => array(
					'reservation_custom_weekdays' => array(
						array(
							'frequency' => 'weekly',
							'weekday'   => 'wed',
						),
					),
				),
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '曜日指定（毎週水曜）で土曜（2026-04-04） => 不許可（false）',
				'reservation_day_type' => 'custom_weekday',
				'date'                 => '2026-04-04',
				'config'               => array(
					'reservation_custom_weekdays' => array(
						array(
							'frequency' => 'weekly',
							'weekday'   => 'wed',
						),
					),
				),
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '日付指定（単日 2026-04-04）で同日 => 許可（true）',
				'reservation_day_type' => 'custom_date',
				'date'                 => '2026-04-04',
				'config'               => array(
					'reservation_custom_dates' => array(
						array(
							'type' => 'single',
							'date' => '2026-04-04',
						),
					),
				),
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '日付指定（単日 2026-04-04）で別日（2026-04-05） => 不許可（false）',
				'reservation_day_type' => 'custom_date',
				'date'                 => '2026-04-05',
				'config'               => array(
					'reservation_custom_dates' => array(
						array(
							'type' => 'single',
							'date' => '2026-04-04',
						),
					),
				),
				'expected'             => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->call_is_date_allowed_for_menu( $case['reservation_day_type'], $case['date'], $case['config'] ?? array() );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
