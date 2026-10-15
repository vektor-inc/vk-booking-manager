<?php
/**
 * Scheduled_Events（プラグインの予定処理をまとめて解除する処理）と、無効化時の解除のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use ReflectionClassConstant;
use VKBookingManager\Common\Scheduled_Events;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\Shifts\Shift_Editor;
use WP_UnitTestCase;

/**
 * 無効化時に、プラグインの予定処理（引数付きの単発処理を含む）がすべて解除されることを検証する。
 *
 * @group common
 */
class Scheduled_Events_Test extends WP_UnitTestCase {

	/**
	 * プラグインと無関係な予定処理の名前。解除の巻き添えにならないことの確認に使う。
	 */
	private const UNRELATED_HOOK = 'vkbm_test_unrelated_hook';

	/**
	 * 各テストの後に、登録した予定処理を掃除する。
	 */
	protected function tearDown(): void {
		foreach ( array_merge( Scheduled_Events::HOOKS, array( self::UNRELATED_HOOK ) ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		parent::tearDown();
	}

	/**
	 * clear_all() が、引数の有無に関わらずプラグインの予定処理だけを解除すること。
	 */
	public function test_clear_all(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '引数なしで登録した予定処理（シフトの自動登録・リマインダー） => 解除される',
				'conditions'          => array(
					'schedule' => array(
						array( 'vkbm_shift_auto_register_daily', array() ),
						array( 'vkbm_send_booking_reminders', array() ),
					),
				),
				'expected'            => array(
					'vkbm_shift_auto_register_daily' => false,
					'vkbm_send_booking_reminders'    => false,
				),
			),
			array(
				'test_condition_name' => '予約 ID を引数にして複数登録した予定処理（メール再送・Google カレンダー反映） => すべて解除される',
				'conditions'          => array(
					'schedule' => array(
						array( 'vkbm_retry_booking_email', array( 'customer', 11, 2 ) ),
						array( 'vkbm_retry_booking_email', array( 'owner', 12, 2 ) ),
						array( 'vkbm_google_calendar_sync_booking', array( 21, 1 ) ),
						array( 'vkbm_google_calendar_sync_booking', array( 22, 3 ) ),
					),
				),
				'expected'            => array(
					'vkbm_retry_booking_email'          => false,
					'vkbm_google_calendar_sync_booking' => false,
				),
			),
			array(
				'test_condition_name' => 'プラグインと無関係な予定処理 => 残る',
				'conditions'          => array(
					'schedule' => array(
						array( self::UNRELATED_HOOK, array() ),
						array( 'vkbm_send_booking_reminders', array() ),
					),
				),
				'expected'            => array(
					self::UNRELATED_HOOK          => true,
					'vkbm_send_booking_reminders' => false,
				),
			),
			array(
				'test_condition_name' => '何も登録されていない => エラーにならず何も起きない',
				'conditions'          => array( 'schedule' => array() ),
				'expected'            => array(
					'vkbm_send_booking_reminders' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			foreach ( $case['conditions']['schedule'] as $event ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, $event[0], $event[1] );
			}

			Scheduled_Events::clear_all();

			foreach ( $case['expected'] as $hook => $is_still_scheduled ) {
				$this->assertSame( $is_still_scheduled, $this->has_event_with_any_args( $hook ), $case['test_condition_name'] . ' : ' . $hook );
			}

			wp_unschedule_hook( self::UNRELATED_HOOK );
		}
	}

	/**
	 * プラグインを無効化すると、予定処理が解除されること。
	 */
	public function test_vkbm_deactivate_plugin(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vkbm_shift_auto_register_daily' );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vkbm_send_booking_reminders' );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vkbm_retry_booking_email', array( 'customer', 31, 2 ) );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vkbm_google_calendar_sync_booking', array( 41, 1 ) );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::UNRELATED_HOOK );

		vkbm_deactivate_plugin();

		foreach ( Scheduled_Events::HOOKS as $hook ) {
			$this->assertFalse( $this->has_event_with_any_args( $hook ), '無効化後に残っていない : ' . $hook );
		}
		$this->assertNotFalse( wp_next_scheduled( self::UNRELATED_HOOK ), '無関係な予定処理は残る' );
	}

	/**
	 * HOOKS の名前が、各クラスが実際に登録する予定処理の名前と一致していること。
	 *
	 * 片方だけ名前を変えると、無効化・削除時に解除されずに残るため。
	 */
	public function test_hooks_match_registered_actions(): void {
		$sources = array(
			array( Shift_Editor::class, 'AUTO_REGISTER_ACTION' ),
			array( Booking_Notification_Service::class, 'REMINDER_ACTION' ),
			array( Booking_Notification_Service::class, 'RETRY_ACTION' ),
		);

		// Google カレンダー連携は Pro 版の配布物にしか含まれない。
		if ( class_exists( Google_Calendar_Event_Sync::class ) ) {
			$sources[] = array( Google_Calendar_Event_Sync::class, 'CRON_ACTION' );
		}

		foreach ( $sources as $source ) {
			$value = ( new ReflectionClassConstant( $source[0], $source[1] ) )->getValue();
			$this->assertContains( $value, Scheduled_Events::HOOKS, $source[0] . '::' . $source[1] );
		}
	}

	/**
	 * 引数の違いに関わらず、指定した名前の予定処理が 1 件でも登録されているかを返す。
	 *
	 * @param string $hook 予定処理の名前。
	 * @return bool 登録されていれば true。
	 */
	private function has_event_with_any_args( string $hook ): bool {
		foreach ( _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}

		return false;
	}
}
