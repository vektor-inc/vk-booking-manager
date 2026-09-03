<?php
/**
 * 予約確定時のサーバー側検証における予約可否判定のテスト。
 *
 * Booking_Confirmation_Controller::is_reservation_day_allowed() が、予約可能日種別
 * （''=指定なし / weekend=土日限定 / weekday=平日限定）と予約開始日時（ISO8601）から
 * 正しく可否（bool）を返すことを検証する。集約リファクタ前後で挙動が
 * 変わらないことを保証するための回帰（characterization）テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionClass;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use WP_UnitTestCase;

/**
 * 予約可能日種別による予約日時可否判定を検証するテスト。
 *
 * @group bookings
 */
class Is_Reservation_Day_Allowed_Test extends WP_UnitTestCase {

	/**
	 * private メソッド is_reservation_day_allowed をリフレクション経由で呼び出す。
	 *
	 * 当該メソッドはインスタンス状態（$this）に依存しない純粋な判定のため、
	 * 依存サービスを持たないインスタンス（コンストラクタ未実行）で呼び出す。
	 *
	 * @param string $reservation_day_type 予約可能日種別（''|weekend|weekday|custom_weekday|custom_date）。
	 * @param string $start_at             予約開始日時（ISO8601）。
	 * @param int    $menu_id              custom 種別の詳細設定を読み込む対象メニューの投稿ID。
	 * @return bool 予約可否。
	 */
	private function call_is_reservation_day_allowed( string $reservation_day_type, string $start_at, int $menu_id = 0 ): bool {
		$reflection = new ReflectionClass( Booking_Confirmation_Controller::class );
		// 依存サービス無しでインスタンス化する（判定メソッドは $this を使わないため問題ない）。
		$controller = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'is_reservation_day_allowed' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $controller, $reservation_day_type, $start_at, $menu_id );
	}

	/**
	 * 予約可能日種別と予約開始日時の組み合わせで期待どおりの可否を返すことを確認する。
	 *
	 * 日時は曜日が確定している固定日時（正午）を使用し、タイムゾーン変換で
	 * 日付境界をまたがないようにする。2026-04-01 は水曜（平日）、
	 * 2026-04-04 は土曜、2026-04-05 は日曜。
	 */
	public function test_is_reservation_day_allowed(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '指定なし（空）で平日（水曜） => 許可（true）',
				'reservation_day_type' => '',
				'start_at'             => '2026-04-01T12:00:00',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '指定なし（空）で土曜 => 許可（true）',
				'reservation_day_type' => '',
				'start_at'             => '2026-04-04T12:00:00',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で土曜 => 許可（true）',
				'reservation_day_type' => 'weekend',
				'start_at'             => '2026-04-04T12:00:00',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で日曜 => 許可（true）',
				'reservation_day_type' => 'weekend',
				'start_at'             => '2026-04-05T12:00:00',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '土日限定（weekend）で平日（水曜） => 不許可（false）',
				'reservation_day_type' => 'weekend',
				'start_at'             => '2026-04-01T12:00:00',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で平日（水曜） => 許可（true）',
				'reservation_day_type' => 'weekday',
				'start_at'             => '2026-04-01T12:00:00',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で土曜 => 不許可（false）',
				'reservation_day_type' => 'weekday',
				'start_at'             => '2026-04-04T12:00:00',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '平日限定（weekday）で日曜 => 不許可（false）',
				'reservation_day_type' => 'weekday',
				'start_at'             => '2026-04-05T12:00:00',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '境界値：パースできない不正な日時文字列 => フォールバックで許可（true）',
				'reservation_day_type' => 'weekend',
				'start_at'             => 'not-a-datetime',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '曜日指定（毎週水曜）で水曜（2026-04-01） => 許可（true）',
				'reservation_day_type' => 'custom_weekday',
				'start_at'             => '2026-04-01T12:00:00',
				'meta'                 => array(
					'_vkbm_reservation_custom_weekdays' => array(
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
				'start_at'             => '2026-04-04T12:00:00',
				'meta'                 => array(
					'_vkbm_reservation_custom_weekdays' => array(
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
				'start_at'             => '2026-04-04T12:00:00',
				'meta'                 => array(
					'_vkbm_reservation_custom_dates' => array(
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
				'start_at'             => '2026-04-05T12:00:00',
				'meta'                 => array(
					'_vkbm_reservation_custom_dates' => array(
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
			$menu_id = 0;
			// custom 種別のケースは詳細設定メタを持つメニュー投稿を用意する。
			if ( ! empty( $case['meta'] ) ) {
				$menu_id = self::factory()->post->create();
				foreach ( $case['meta'] as $meta_key => $meta_value ) {
					update_post_meta( $menu_id, $meta_key, $meta_value );
				}
			}

			$actual = $this->call_is_reservation_day_allowed( $case['reservation_day_type'], $case['start_at'], $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
