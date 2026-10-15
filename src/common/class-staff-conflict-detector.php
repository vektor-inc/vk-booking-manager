<?php
/**
 * 担当スタッフの予約競合を一括判定する共通ユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Query;

/**
 * 予約編集画面とシフト・予約表で共有する、状態を持たない競合判定クラス。
 *
 * 同一メニューの残席は、空き枠の計算・予約確定と共通の Staff_Load_Calculator::summarize_staff_bookings() /
 * calculate_remaining() で求める（#656）。残席の判定仕様を変えるときはその2つを直すこと。
 * 単純な時間重複（overlap）の判定はこのクラスが持つ。
 */
class Staff_Conflict_Detector {
	/** 枠を消費しないステータス（担当スタッフのチェック対象外）。Booking_Admin の同名定数もここを参照する。 */
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_NO_SHOW   = 'no_show';

	public const REASON_OVERLAP               = 'overlap';
	public const REASON_INSUFFICIENT_CAPACITY = 'insufficient_capacity';

	private const META_START       = '_vkbm_booking_service_start';
	private const META_SERVICE_END = '_vkbm_booking_service_end';
	private const META_TOTAL_END   = '_vkbm_booking_total_end';
	private const META_RESOURCE    = '_vkbm_booking_resource_id';
	private const META_SERVICE     = '_vkbm_booking_service_id';
	private const META_STATUS      = '_vkbm_booking_status';
	private const META_GUESTS      = '_vkbm_booking_guests';

	/**
	 * 指定期間と交差する予約を、メタキャッシュを温めて一括取得する。
	 *
	 * @param string $range_start 期間開始。
	 * @param string $range_end   期間終了。
	 * @return array<int, array<string, mixed>> 予約データ。
	 */
	public static function get_bookings_intersecting_range( string $range_start, string $range_end ): array {
		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'pending' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_START,
						'value'   => $range_end,
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_TOTAL_END,
							'value'   => $range_start,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_SERVICE_END,
							'value'   => $range_start,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);
		// 投稿ステータスとメタを一括でキャッシュし、予約件数に比例する問い合わせを防ぐ。
		_prime_post_caches( $query->posts, false, false );
		update_meta_cache( 'post', $query->posts );

		$bookings = array();
		foreach ( $query->posts as $post_id ) {
			$resource_id = (int) get_post_meta( (int) $post_id, self::META_RESOURCE, true );
			if ( $resource_id <= 0 ) {
				continue;
			}
			$bookings[] = array(
				'id'          => (int) $post_id,
				'start'       => self::normalize_datetime( (string) get_post_meta( (int) $post_id, self::META_START, true ) ),
				'service_end' => self::normalize_datetime( (string) get_post_meta( (int) $post_id, self::META_SERVICE_END, true ) ),
				'total_end'   => self::normalize_datetime( (string) get_post_meta( (int) $post_id, self::META_TOTAL_END, true ) ),
				'resource_id' => $resource_id,
				'service_id'  => (int) get_post_meta( (int) $post_id, self::META_SERVICE, true ),
				'status'      => (string) get_post_meta( (int) $post_id, self::META_STATUS, true ),
				'guests'      => max( 1, (int) get_post_meta( (int) $post_id, self::META_GUESTS, true ) ),
				'post_status' => (string) get_post_status( (int) $post_id ),
			);
		}

		return $bookings;
	}

	/**
	 * 1件の予約について、同じスタッフが担当できない理由を判定する。
	 *
	 * @param array<string, mixed>             $target         判定対象予約。
	 * @param array<int, array<string, mixed>> $bookings       比較対象を含む予約一覧。
	 * @param array<int, int>                  $menu_capacities メニューID別定員。
	 * @param array<int, bool>                 $nominations    メニューID別指名有効状態。
	 * @param bool                             $sorted_by_start true の場合、$target と $bookings は get_bookings_intersecting_range() で Y-m-d H:i:s に正規化済みで、
	 *                                                          $bookings は開始日時の昇順であること。
	 * @return array<int, string> 理由コード。
	 */
	public static function detect_reasons( array $target, array $bookings, array $menu_capacities, array $nominations, bool $sorted_by_start = false ): array {
		$target_id       = (int) ( $target['id'] ?? 0 );
		$resource_id     = (int) ( $target['resource_id'] ?? 0 );
		$service_id      = (int) ( $target['service_id'] ?? 0 );
		$start           = $sorted_by_start ? (string) ( $target['start'] ?? '' ) : self::normalize_datetime( (string) ( $target['start'] ?? '' ) );
		$end             = self::get_effective_end( $target, $sorted_by_start );
		$guests          = max( 1, (int) ( $target['guests'] ?? 1 ) );
		$capacity        = max( 1, (int) ( $menu_capacities[ $service_id ] ?? 1 ) );
		$is_single_party = 1 === $capacity || ! empty( $nominations[ $service_id ] );
		$has_overlap     = false;
		$active_overlaps = array();

		if ( $resource_id <= 0 || '' === $start || '' === $end ) {
			return array();
		}

		foreach ( $bookings as $other ) {
			$other_start = $sorted_by_start ? (string) ( $other['start'] ?? '' ) : self::normalize_datetime( (string) ( $other['start'] ?? '' ) );
			// 開始順の場合、以降の予約も重ならないため比較を終える。
			if ( $sorted_by_start && '' !== $other_start && $other_start >= $end ) {
				break;
			}
			if ( (int) ( $other['id'] ?? 0 ) === $target_id || (int) ( $other['resource_id'] ?? 0 ) !== $resource_id ) {
				continue;
			}
			if ( ! self::overlaps( $start, $end, $other_start, self::get_effective_end( $other, $sorted_by_start ), $sorted_by_start ) ) {
				continue;
			}

			$status = (string) ( $other['status'] ?? '' );
			// #394 より前の管理画面の重複判定を踏襲し、公開済みの確定・仮予約だけを対象にする。
			if ( 'publish' === (string) ( $other['post_status'] ?? 'publish' ) && in_array( $status, array( 'confirmed', 'pending' ), true ) ) {
				if ( $is_single_party || (int) ( $other['service_id'] ?? 0 ) !== $service_id ) {
					$has_overlap = true;
				}
			}
			// 残席の判定に使う候補。キャンセル・無断キャンセルの除外は summarize_staff_bookings() が行う。
			$active_overlaps[] = $other;
		}

		$reasons = array();
		if ( $has_overlap ) {
			$reasons[] = self::REASON_OVERLAP;
		}
		// 残席は空き枠の計算・予約確定と同じ基準（#656。別メニューの予約があれば残席0）で数える。
		// 旧実装の Staff_Load_Calculator と同様、同一メニューの負荷があるスタッフだけを残数判定する。
		$summary = Staff_Load_Calculator::summarize_staff_bookings( $service_id, $active_overlaps );
		if ( ! $is_single_party && $summary['same_menu_guests'] > 0 && Staff_Load_Calculator::calculate_remaining( $capacity, false, $summary ) < $guests ) {
			$reasons[] = self::REASON_INSUFFICIENT_CAPACITY;
		}
		return $reasons;
	}

	/**
	 * 予約編集画面向けに競合スタッフIDを返す。
	 *
	 * @param int    $post_id   除外する予約ID。
	 * @param string $start     開始日時。
	 * @param string $end       終了日時。
	 * @param int    $service_id メニューID。
	 * @param int    $guests    人数。
	 * @return array<int, int> 競合スタッフID。
	 */
	public static function get_conflicting_staff_ids( int $post_id, string $start, string $end, int $service_id = 0, int $guests = 1 ): array {
		if ( '' === $start ) {
			return array();
		}
		$end       = '' === $end ? $start : $end;
		$bookings  = self::get_bookings_intersecting_range( $start, $end );
		$capacity  = self::get_menu_capacity( $service_id );
		$staff_ids = array();
		foreach ( array_unique( array_map( static fn( array $booking ): int => (int) $booking['resource_id'], $bookings ) ) as $resource_id ) {
			$reasons = self::detect_reasons(
				array(
					'id'          => $post_id,
					'start'       => $start,
					'service_end' => $end,
					'total_end'   => $end,
					'resource_id' => $resource_id,
					'service_id'  => $service_id,
					'guests'      => $guests,
				),
				$bookings,
				array( $service_id => $capacity ),
				array( $service_id => Staff_Editor::is_nomination_enabled_for_menu( $service_id ) )
			);
			if ( ! empty( $reasons ) ) {
				$staff_ids[] = $resource_id;
			}
		}
		return $staff_ids;
	}

	/**
	 * 指定ステータスが、担当スタッフの未割当チェック・競合チェックの対象かどうかを判定する。
	 *
	 * キャンセル・無断キャンセルの予約は枠を消費しないため対象外。編集画面の保存・クイック編集・
	 * シフト・予約表の「確定」ボタン（#652）が同じ基準を使うよう、判定をここに集約している。
	 *
	 * @param string $status 予約ステータス。
	 * @return bool チェック対象なら true。
	 */
	public static function is_staff_check_target_status( string $status ): bool {
		return self::STATUS_CANCELLED !== $status && self::STATUS_NO_SHOW !== $status;
	}

	/**
	 * チェック対象のステータスで、担当スタッフが未割当かどうかを判定する。
	 *
	 * @param string $status      予約ステータス。
	 * @param int    $resource_id 担当スタッフID（0 以下なら未割当）。
	 * @return bool 保存・確定を止めるべき未割当なら true。
	 */
	public static function is_staff_unassigned( string $status, int $resource_id ): bool {
		return self::is_staff_check_target_status( $status ) && $resource_id <= 0;
	}

	/**
	 * 指定スタッフが、その日時・メニュー・人数で他の予約と競合するかどうかを判定する。
	 *
	 * @param int    $resource_id 担当スタッフID。
	 * @param int    $post_id     判定から除外する予約ID。
	 * @param string $start       開始日時。
	 * @param string $end         終了日時。
	 * @param int    $service_id  メニューID。
	 * @param int    $guests      人数。
	 * @return bool 競合するなら true。
	 */
	public static function is_staff_in_conflict( int $resource_id, int $post_id, string $start, string $end, int $service_id = 0, int $guests = 1 ): bool {
		return in_array( $resource_id, self::get_conflicting_staff_ids( $post_id, $start, $end, $service_id, $guests ), true );
	}

	/**
	 * 基本設定「管理画面でのスタッフ重複を許可する」が ON かどうかを返す。
	 *
	 * @return bool 許可されていれば true。
	 */
	public static function is_staff_overlap_allowed_in_admin(): bool {
		$settings = ( new Settings_Repository() )->get_settings();
		return ! empty( $settings['provider_allow_staff_overlap_admin'] );
	}

	/**
	 * 担当スタッフ未割当で保存・確定を止めたときの利用者向けメッセージを返す。
	 *
	 * @return string メッセージ。
	 */
	public static function get_staff_required_message(): string {
		$label = vkbm_get_resource_label_singular();

		$message = sprintf(
			/* translators: %s: resource label. */
			__( 'No %s is assigned, so the reservation was not saved.', 'vk-booking-manager' ),
			$label
		);
		// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。
		$message .= ' ' . sprintf(
			/* translators: %s: resource label. */
			__( 'Please assign at least one %s.', 'vk-booking-manager' ),
			$label
		);

		return $message;
	}

	/**
	 * 担当スタッフの競合で保存・確定を止めたときの利用者向けメッセージを返す。
	 *
	 * @param int $staff_id 競合したスタッフID。
	 * @return string メッセージ。
	 */
	public static function get_staff_conflict_error_message( int $staff_id ): string {
		$label = vkbm_get_resource_label_singular();
		$name  = $staff_id > 0 ? (string) get_the_title( $staff_id ) : '';

		if ( '' !== $name ) {
			return sprintf(
				/* translators: 1: resource label, 2: resource name */
				__( 'The selected %1$s (%2$s) cannot be specified because there is another reservation in the same time slot.', 'vk-booking-manager' ),
				$label,
				$name
			);
		}

		return sprintf(
			/* translators: %s: resource label */
			__( 'The selected %1$s cannot be specified because there is another reservation in the same time slot.', 'vk-booking-manager' ),
			$label
		);
	}

	/**
	 * メニューの有効な予約枠定員を取得する。
	 *
	 * 定員機能が OFF のときは、保存済みの古い定員値を使うと、一対一予約のスタッフが
	 * 重複して選択できてしまうため定員 1 に固定する（#394）。
	 *
	 * @param int $service_id メニューID。
	 * @return int 定員。
	 */
	public static function get_menu_capacity( int $service_id ): int {
		if ( $service_id <= 0 || ! Staff_Editor::is_slot_capacity_enabled() ) {
			return 1;
		}
		$value = get_post_meta( $service_id, '_vkbm_max_capacity', true );
		return max( 1, '' === $value ? 1 : (int) $value );
	}

	/**
	 * total_end と service_end を日時として比較し、遅い方を正規化して返す。
	 *
	 * @param array<string, mixed> $booking 予約。
	 * @param bool                 $normalized 日時が正規化済みか。
	 * @return string 有効な終了日時。
	 */
	private static function get_effective_end( array $booking, bool $normalized = false ): string {
		$total_end   = (string) ( $booking['total_end'] ?? '' );
		$service_end = (string) ( $booking['service_end'] ?? '' );
		if ( ! $normalized ) {
			$total_end   = self::normalize_datetime( $total_end );
			$service_end = self::normalize_datetime( $service_end );
		}
		return $total_end > $service_end ? $total_end : $service_end;
	}

	/**
	 * 2つの半開区間が重なるか判定する。
	 *
	 * @param string $start_a 区間Aの開始。
	 * @param string $end_a   区間Aの終了。
	 * @param string $start_b 区間Bの開始。
	 * @param string $end_b   区間Bの終了。
	 * @param bool   $normalized 日時が正規化済みか。
	 * @return bool 重なる場合 true。
	 */
	private static function overlaps( string $start_a, string $end_a, string $start_b, string $end_b, bool $normalized = false ): bool {
		if ( ! $normalized ) {
			$start_a = self::normalize_datetime( $start_a );
			$end_a   = self::normalize_datetime( $end_a );
			$start_b = self::normalize_datetime( $start_b );
			$end_b   = self::normalize_datetime( $end_b );
		}
		return '' !== $start_a && '' !== $end_a && '' !== $start_b && '' !== $end_b && $end_a > $start_b && $start_a < $end_b;
	}

	/**
	 * 保存形式に秒がない日時も、比較用の共通形式へ正規化する。
	 *
	 * @param string $value 保存日時。
	 * @return string Y-m-d H:i:s。解釈できない場合は空文字列。
	 */
	private static function normalize_datetime( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		// 一括取得時に正規化済みの値は、DateTimeImmutable を再生成せずそのまま比較に使う。
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return $value;
		}

		// 秒無し・T 区切りなどは共通の正規化で整える。整えられない形だけ従来どおり DateTimeImmutable で解釈する。
		$normalized = Booking_Datetime::normalize( $value );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		try {
			return ( new \DateTimeImmutable( $value, wp_timezone() ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}
}
