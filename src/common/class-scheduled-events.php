<?php
/**
 * プラグインが WP-Cron に登録する予定処理を、まとめて解除する処理。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use function wp_unschedule_hook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 */
class Scheduled_Events {

	/**
	 * プラグインが WP-Cron に登録する処理の名前（フック名）の一覧。
	 *
	 * 各クラスの private 定数（AUTO_REGISTER_ACTION / REMINDER_ACTION / RETRY_ACTION / CRON_ACTION）と
	 * 値をそろえる必要がある。新しい予定処理を登録したら、ここにも足すこと（足さないと無効化・削除時に残る）。
	 */
	public const HOOKS = array(
		// シフトの自動登録（毎日）。
		'vkbm_shift_auto_register_daily',
		// 予約リマインダーメールの送信（15 分ごと）。
		'vkbm_send_booking_reminders',
		// 予約通知メールの再送（失敗時に 1 回ずつ登録される単発の処理）。
		'vkbm_retry_booking_email',
		// Google カレンダーへの予約の反映（予約ごとに登録される単発の処理）。
		'vkbm_google_calendar_sync_booking',
		// Google カレンダー連携が切れていた間の予約を、再接続後に順に反映するまとめ役（単発の処理）。
		'vkbm_google_calendar_flush_pending_sync',
	);

	/**
	 * プラグインが登録した予定処理を、引数の違うものも含めてすべて解除する。
	 *
	 * `wp_clear_scheduled_hook()` は引数が空の処理しか解除しない。再送と Google カレンダーへの反映は
	 * 予約 ID などを引数にして登録するため、引数に関わらず解除できる `wp_unschedule_hook()` を使う。
	 *
	 * @return void
	 */
	public static function clear_all(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
