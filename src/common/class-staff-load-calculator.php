<?php
/**
 * 指定スロット（メニュー・時間帯）内のスタッフ別予約人数（負荷）を集計するユーティリティ。
 *
 * フロントの自動割当（Booking_Confirmation_Controller::select_best_fit_staff() が使う
 * 負荷集計）と、管理画面の担当スタッフ候補の絞り込み（Booking_Admin::get_conflicting_staff_ids()）が
 * 「同じメニュー・同じ時間帯に、このスタッフは既に何名担当しているか」という同一の判定ロジックを
 * 必要とするため、写経を避けてここに集約する（#394）。
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
 * 状態を持たない静的メソッドのみで構成し、フロント・管理画面のどちらからも
 * 同じ判定ロジックを再利用できるようにする。
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
	 * 指定メニュー・時間帯に重なる予約から、スタッフごとの担当人数の合計を集計する。
	 *
	 * 対象は確定・保留中の予約のみ（キャンセル・無断キャンセルは枠を消費しないため除外）。
	 * 1予約は分割せず単一スタッフに割り当てられる前提のため、担当スタッフ（resource_id）ごとに
	 * その予約の人数（guests。未設定は1名として後方互換）を加算する。
	 *
	 * @param int    $menu_id         サービスメニューID。
	 * @param string $start_at        スロット開始日時（サイトのタイムゾーンの 'Y-m-d H:i:s'）。
	 * @param string $end_at          スロット終了日時（同上）。
	 * @param int    $exclude_post_id 集計から除外する予約投稿ID（編集中の予約自身を除外する用途。0なら除外なし）。
	 * @return array<int, int> staff_id => 担当人数の合計。
	 */
	public static function get_staff_loads_for_slot( int $menu_id, string $start_at, string $end_at, int $exclude_post_id = 0 ): array {
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
					'key'     => self::META_SERVICE_ID,
					'value'   => $menu_id,
					'compare' => '=',
				),
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
		// get_post_meta() を件数分（本メソッドは1件につき最大3回）呼ぶと N+1 になる。
		// 3経路（管理画面の候補絞り込み・保存時の競合判定・フロントの自動割当）から呼ばれるため、
		// ここでまとめて1回のクエリでメタキャッシュへ乗せる（#394 レビュー対応）。
		update_meta_cache( 'post', $query->posts );

		$loads = array();
		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			if ( self::STATUS_CANCELLED === $status || self::STATUS_NO_SHOW === $status ) {
				continue;
			}

			$resource_id = (int) get_post_meta( (int) $post_id, self::META_RESOURCE_ID, true );
			if ( $resource_id <= 0 ) {
				continue;
			}

			$guests_raw            = get_post_meta( (int) $post_id, self::META_GUESTS, true );
			$guests                = '' === $guests_raw ? 1 : max( 1, (int) $guests_raw );
			$loads[ $resource_id ] = ( $loads[ $resource_id ] ?? 0 ) + $guests;
		}

		return $loads;
	}
}
