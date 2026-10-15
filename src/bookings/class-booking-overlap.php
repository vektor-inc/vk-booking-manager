<?php
/**
 * 予約が指定の期間と重なるかの判定条件（予約確定の防御と泊の空室判定で共有する）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 予約の重なり判定の条件を1か所にまとめたクラス。
 *
 * 条件: 公開済みで、キャンセル・無断キャンセルでなく、
 * 予約の開始 < 期間の終了、かつ、予約の終了（バッファ込みの終了または終了）> 期間の開始。
 * 比較はどちらも厳密な不等号。終了と開始が同じ時刻の予約同士は重ならない。
 * Booking_Confirmation_Controller::has_staff_conflict()（SQL）と、
 * 泊戦略（Night_Strategy。PHP での判定）が、この条件を共有する。条件を変えるときは両方の結果が揃うことを確認すること。
 */
class Booking_Overlap {
	public const META_START     = '_vkbm_booking_service_start';
	public const META_END       = '_vkbm_booking_service_end';
	public const META_TOTAL_END = '_vkbm_booking_total_end';
	public const META_RESOURCE  = '_vkbm_booking_resource_id';
	public const META_STATUS    = '_vkbm_booking_status';

	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_NO_SHOW   = 'no_show';

	/**
	 * 重なる予約を探す WP_Query の meta_query を組み立てる。
	 *
	 * 投稿の状態は呼び出し側で publish のみにすること（下書きなどは重なりに含めない）。
	 *
	 * @param array<int, int> $resource_ids 対象のリソース ID。1件なら「=」、複数なら「IN」で比較する。
	 * @param string          $start        期間の開始（Y-m-d H:i:s。サイトのタイムゾーン）。
	 * @param string          $end          期間の終了（Y-m-d H:i:s。サイトのタイムゾーン）。
	 * @return array<int|string, mixed> meta_query。
	 */
	public static function build_meta_query( array $resource_ids, string $start, string $end ): array {
		$is_single       = 1 === count( $resource_ids );
		$resource_clause = array(
			'key'     => self::META_RESOURCE,
			'value'   => $is_single ? (int) reset( $resource_ids ) : array_values( $resource_ids ),
			'compare' => $is_single ? '=' : 'IN',
		);

		return array(
			'relation' => 'AND',
			// キャンセル・無断キャンセルの予約は競合に含めない（実枠が空いていれば割り当て可能）。
			array(
				'relation' => 'OR',
				array(
					'key'     => self::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_STATUS,
					'value'   => array(
						self::STATUS_CANCELLED,
						self::STATUS_NO_SHOW,
					),
					'compare' => 'NOT IN',
				),
			),
			$resource_clause,
			array(
				'key'     => self::META_START,
				'value'   => $end,
				'compare' => '<',
				'type'    => 'DATETIME',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => self::META_TOTAL_END,
					'value'   => $start,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => self::META_END,
					'value'   => $start,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);
	}

	/**
	 * 予約が期間と重なるかを、build_meta_query() と同じ条件で判定する。
	 *
	 * 日時はすべて Y-m-d H:i:s（サイトのタイムゾーン）。同じ書式なので文字列のまま比較できる。
	 * 予約の開始が空（またはメタなし）の予約は SQL の条件でも一致しないため、重ならない扱いにする。
	 *
	 * @param string $booking_start     予約の開始。
	 * @param string $booking_end       予約の終了（バッファ前）。空なら無いものとして扱う。
	 * @param string $booking_total_end 予約の終了（バッファ込み）。空なら無いものとして扱う。
	 * @param string $window_start      期間の開始。
	 * @param string $window_end        期間の終了。
	 * @return bool 重なるなら true。
	 */
	public static function overlaps( string $booking_start, string $booking_end, string $booking_total_end, string $window_start, string $window_end ): bool {
		if ( '' === $booking_start || $booking_start >= $window_end ) {
			return false;
		}

		return ( '' !== $booking_total_end && $booking_total_end > $window_start )
			|| ( '' !== $booking_end && $booking_end > $window_start );
	}
}
