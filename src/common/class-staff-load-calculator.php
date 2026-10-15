<?php
/**
 * 指定スロット（メニュー・時間帯）内のスタッフ別予約人数（負荷）を集計するユーティリティ。
 *
 * フロントの自動割当（Booking_Confirmation_Controller::select_best_fit_staff()）が使う負荷集計。
 * 管理画面の判定は Staff_Conflict_Detector が同じ数え方で行う（#394 / #449）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Query;

/**
 * スタッフ別の予約人数（負荷）集計ロジックをまとめたユーティリティクラス。
 *
 * 状態を持たない静的メソッドのみで構成し、フロントの自動割当から再利用できるようにする。
 * 空き枠の計算（Availability_Service）・予約確定（Booking_Confirmation_Controller）・管理画面の競合判定（Staff_Conflict_Detector）は、
 * summarize_staff_bookings() / calculate_remaining() で同じ判定基準にそろえている（#656）。判定仕様を変更する場合はこの2つを直すこと。
 */
class Staff_Load_Calculator {
	private const META_SERVICE_ID  = '_vkbm_booking_service_id';
	private const META_DATE_START  = '_vkbm_booking_service_start';
	private const META_DATE_END    = '_vkbm_booking_service_end';
	private const META_TOTAL_END   = '_vkbm_booking_total_end';
	private const META_STATUS      = '_vkbm_booking_status';
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';
	private const META_GUESTS      = '_vkbm_booking_guests';
	private const STATUS_CANCELLED = 'cancelled';
	private const STATUS_NO_SHOW   = 'no_show';

	/**
	 * 指定メニュー・時間帯に重なる有効な予約を、担当スタッフごとに要約して返す。
	 *
	 * 対象は確定・保留中の予約のみ（キャンセル・無断キャンセルは枠を消費しないため除外）。
	 * 1予約は分割せず単一スタッフに割り当てられる前提のため、担当スタッフ（resource_id）ごとに
	 * 同一メニューの予約人数（guests。未設定は1名として後方互換）と、別メニューの予約の有無を求める。
	 * 予約の取得は1回のクエリで済ませる。予約が無いスタッフは結果に含まれない。
	 *
	 * @param int    $menu_id         サービスメニューID。
	 * @param string $start_at        スロット開始日時（サイトのタイムゾーンの 'Y-m-d H:i:s'）。
	 * @param string $end_at          スロット終了日時（同上）。
	 * @param int    $exclude_post_id 集計から除外する予約投稿ID（編集中の予約自身を除外する用途。0なら除外なし）。
	 * @return array<int, array{same_menu_guests:int, has_other_menu:bool}> staff_id => 要約（summarize_staff_bookings() の結果）。
	 */
	public static function get_staff_summaries_for_slot( int $menu_id, string $start_at, string $end_at, int $exclude_post_id = 0 ): array {
		$by_staff = array();
		foreach ( self::get_active_bookings_for_slot( $start_at, $end_at, $exclude_post_id ) as $booking ) {
			$by_staff[ $booking['resource_id'] ][] = $booking;
		}

		$summaries = array();
		foreach ( $by_staff as $staff_id => $bookings ) {
			$summaries[ $staff_id ] = self::summarize_staff_bookings( $menu_id, $bookings );
		}

		return $summaries;
	}

	/**
	 * 1人のスタッフに重なっている予約を、対象メニューの相乗り人数と別メニューの有無に要約する。
	 *
	 * 空き枠の計算・予約確定・管理画面の競合判定が同じ数え方をするための純粋関数。
	 * status が 'cancelled' / 'no_show' の予約は枠を消費しないため数えない。
	 *
	 * @param int                                                             $menu_id  判定対象のサービスメニューID。
	 * @param array<int, array{service_id?:int, guests?:int, status?:string}> $bookings スタッフに重なっている予約。
	 * @return array{same_menu_guests:int, has_other_menu:bool} 同一メニューの合計人数と、別メニューの予約の有無。
	 */
	public static function summarize_staff_bookings( int $menu_id, array $bookings ): array {
		$same_menu_guests = 0;
		$has_other_menu   = false;
		foreach ( $bookings as $booking ) {
			$status = (string) ( $booking['status'] ?? '' );
			if ( self::STATUS_CANCELLED === $status || self::STATUS_NO_SHOW === $status ) {
				continue;
			}
			if ( (int) ( $booking['service_id'] ?? 0 ) === $menu_id ) {
				$same_menu_guests += max( 1, (int) ( $booking['guests'] ?? 1 ) );
			} else {
				$has_other_menu = true;
			}
		}

		return array(
			'same_menu_guests' => $same_menu_guests,
			'has_other_menu'   => $has_other_menu,
		);
	}

	/**
	 * スタッフ1人あたりの残席数を返す（3か所共通の判定基準）。
	 *
	 * - 別メニューの予約があるスタッフは、定員に関わらず残席0（担当者はメニューを問わず同時に1組だけ受け持つ）。
	 * - 定員1または指名を使うメニュー（1枠1組）は、同一メニューを含め予約が1件でもあれば残席0。
	 * - それ以外は、定員から同一メニューの予約人数を引いた値（相乗り）。
	 *
	 * @param int                                              $capacity        メニューの定員（1以上）。
	 * @param bool                                             $is_single_party 定員1または指名を使うメニューか。
	 * @param array{same_menu_guests:int, has_other_menu:bool} $summary     summarize_staff_bookings() の結果。
	 * @return int 残席数（0以上）。
	 */
	public static function calculate_remaining( int $capacity, bool $is_single_party, array $summary ): int {
		$capacity         = max( 1, $capacity );
		$same_menu_guests = max( 0, (int) ( $summary['same_menu_guests'] ?? 0 ) );
		if ( ! empty( $summary['has_other_menu'] ) ) {
			return 0;
		}
		if ( $is_single_party && $same_menu_guests > 0 ) {
			return 0;
		}

		return max( 0, $capacity - $same_menu_guests );
	}

	/**
	 * 時間帯に重なる有効な予約（確定・保留中。キャンセル・無断キャンセルを除く）を、メニューを問わず取得する。
	 *
	 * @param string $start_at        スロット開始日時（サイトのタイムゾーンの 'Y-m-d H:i:s'）。
	 * @param string $end_at          スロット終了日時（同上）。
	 * @param int    $exclude_post_id 除外する予約投稿ID（0なら除外なし）。
	 * @return array<int, array{resource_id:int, service_id:int, guests:int}> 担当スタッフが決まっている予約。
	 */
	private static function get_active_bookings_for_slot( string $start_at, string $end_at, int $exclude_post_id = 0 ): array {
		if ( '' === $start_at ) {
			return array();
		}
		if ( '' === $end_at ) {
			$end_at = $start_at;
		}

		$query_args = array(
			'post_type'      => Booking_Post_Type::POST_TYPE,
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					// 既存の予約開始が、判定対象の終了より前なら時間帯が重なる可能性がある。
					'key'     => self::META_DATE_START,
					'value'   => $end_at,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_TOTAL_END,
						'value'   => $start_at,
						'compare' => '>',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => self::META_DATE_END,
						'value'   => $start_at,
						'compare' => '>',
						'type'    => 'DATETIME',
					),
				),
			),
		);

		if ( $exclude_post_id > 0 ) {
			$query_args['post__not_in'] = array( $exclude_post_id );
		}

		$query = new WP_Query( $query_args );

		// 'fields' => 'ids' の WP_Query はメタキャッシュを自動で温めないため、ループ内で
		// get_post_meta() を件数分呼ぶと N+1 になる。まとめて1回のクエリでメタキャッシュへ乗せる（#394 レビュー対応）。
		update_meta_cache( 'post', $query->posts );

		$bookings = array();
		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			if ( self::STATUS_CANCELLED === $status || self::STATUS_NO_SHOW === $status ) {
				continue;
			}

			$resource_id = (int) get_post_meta( (int) $post_id, self::META_RESOURCE_ID, true );
			if ( $resource_id <= 0 ) {
				continue;
			}

			$guests_raw = get_post_meta( (int) $post_id, self::META_GUESTS, true );
			$bookings[] = array(
				'resource_id' => $resource_id,
				'service_id'  => (int) get_post_meta( (int) $post_id, self::META_SERVICE_ID, true ),
				'guests'      => '' === $guests_raw ? 1 : max( 1, (int) $guests_raw ),
			);
		}

		return $bookings;
	}
}
