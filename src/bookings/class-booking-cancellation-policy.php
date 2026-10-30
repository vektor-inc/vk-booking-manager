<?php
/**
 * 予約が今キャンセルできるかの判定。
 *
 * マイページのキャンセルなど、複数の入口から同じ判定を使うために切り出している。
 * 無料版・Pro 版の両方で使うため、Pro 版だけのファイルとは分けてここに置く。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * キャンセル可否の判定。
 */
class Booking_Cancellation_Policy {
	/**
	 * キャンセル済みを表す予約ステータス。
	 */
	private const STATUS_CANCELLED = 'cancelled';

	/**
	 * 予約が今キャンセルできるかを返す。
	 *
	 * 次のいずれかならキャンセルできない。
	 * キャンセル済み／開始時刻を過ぎている／キャンセルモードが 'none'／開始までの時間が締め切り（時間）未満。
	 *
	 * @param int    $start_timestamp 予約の開始時刻（Unix タイムスタンプ）。
	 * @param int    $now             現在時刻（Unix タイムスタンプ）。
	 * @param string $status          現在の予約ステータス。
	 * @param string $cancel_mode     キャンセルモード（'hours'|'none'）。
	 * @param int    $cancel_hours    キャンセル締め切り（開始の何時間前まで）。負の値は 0 として扱う。
	 * @return bool キャンセルできるなら true。
	 */
	public static function can_cancel( int $start_timestamp, int $now, string $status, string $cancel_mode, int $cancel_hours ): bool {
		// キャンセル済み・開始済み・オンラインキャンセル不可の設定は、締め切りに関係なく不可。
		if ( self::STATUS_CANCELLED === $status ) {
			return false;
		}

		if ( $start_timestamp <= $now ) {
			return false;
		}

		if ( 'none' === $cancel_mode ) {
			return false;
		}

		// 開始までの残り時間が締め切り以上なら可（ちょうど締め切りの時刻は可）。
		$cancel_hours = max( 0, $cancel_hours );

		return ( $start_timestamp - $now ) >= ( $cancel_hours * 3600 );
	}
}
