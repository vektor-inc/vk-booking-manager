<?php
/**
 * 泊単位のプランの空室を判定する戦略（泊戦略）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use DateTimeZone;
use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\Common\Booking_Datetime;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\Shifts\Shift_Sale_Days;
use WP_Error;
use WP_Query;

/**
 * チェックイン日〜チェックアウト日の各泊について、客室が空いているかを判定する。
 *
 * 客室が空室とは、期間のすべての泊で「販売日である（Shift_Sale_Days。シフト未登録の日は販売しない）」かつ
 * 「滞在全体の時間帯（最初のチェックイン日時〜最後のチェックアウト日時）に重なる予約が1件も無い」こと。
 * 重なりの条件は予約確定の防御（Booking_Confirmation_Controller::has_staff_conflict()）と同じ
 * Booking_Overlap を共有する。チェックアウトと次のチェックインが同じ時刻でも重ならない。
 *
 * 予約は期間全体を1回の問い合わせで取得し、メタはまとめて読む（客室数・泊数が増えても問い合わせは増えない）。
 * 宿泊が有効か・泊単位のメニューかの判定は呼び出し側で行う（このクラスはデータを読むだけ）。
 */
class Night_Strategy {

	/**
	 * 1回で問い合わせられる泊数の上限。受付上限日数が 0（無制限）のメニューでも効く。
	 */
	public const MAX_NIGHTS = 30;

	/**
	 * 販売日の判定。
	 *
	 * @var Shift_Sale_Days
	 */
	private Shift_Sale_Days $sale_days;

	/**
	 * Constructor.
	 *
	 * @param Shift_Sale_Days|null $sale_days 販売日の判定。省略時は標準の判定を使う。
	 */
	public function __construct( ?Shift_Sale_Days $sale_days = null ) {
		$this->sale_days = $sale_days ?? new Shift_Sale_Days();
	}

	/**
	 * 期間の泊の日付（チェックイン日〜チェックアウト日の前日）を返す。
	 *
	 * 暦の日付のまま扱う（タイムゾーンで日がずれないよう UTC の暦計算で進める）。
	 * 日付が不正・チェックアウトがチェックイン以前・泊数が上限超えの場合は空配列。
	 *
	 * @param string $check_in_date  チェックイン日（Y-m-d）。
	 * @param string $check_out_date チェックアウト日（Y-m-d）。
	 * @return array<int, string> 泊の日付（Y-m-d）。
	 */
	public function get_night_dates( string $check_in_date, string $check_out_date ): array {
		$utc       = new DateTimeZone( 'UTC' );
		$check_in  = DateTimeImmutable::createFromFormat( '!Y-m-d', $check_in_date, $utc );
		$check_out = DateTimeImmutable::createFromFormat( '!Y-m-d', $check_out_date, $utc );

		if ( ! $check_in || ! $check_out || $check_in->format( 'Y-m-d' ) !== $check_in_date || $check_out->format( 'Y-m-d' ) !== $check_out_date || $check_in >= $check_out ) {
			return array();
		}

		$nights = array();
		$cursor = $check_in;
		while ( $cursor < $check_out ) {
			$nights[] = $cursor->format( 'Y-m-d' );
			if ( count( $nights ) > self::MAX_NIGHTS ) {
				return array();
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		return $nights;
	}

	/**
	 * 宿泊の日程（チェックイン日・チェックアウト日）の順序と泊数の上限に矛盾が無いかを調べる。
	 *
	 * チェックアウト日がチェックイン日の翌日以降で、泊数が1回に予約できる上限（MAX_NIGHTS）以内なら null。
	 * 日付そのものの形式は呼び出し側で先に確認しておく。予約の検証（Availability_Service）と
	 * 絞り込み検索（Menu_Date_Filter）が同じ判定・同じエラーを使う。
	 *
	 * @param string $check_in_date  チェックイン日（Y-m-d）。
	 * @param string $check_out_date チェックアウト日（Y-m-d）。
	 * @return WP_Error|null 矛盾があればエラー（400）。なければ null。
	 */
	public static function validate_stay_range( string $check_in_date, string $check_out_date ): ?WP_Error {
		if ( $check_out_date <= $check_in_date ) {
			return new WP_Error( 'invalid_date_range', __( 'The check-out date must be after the check-in date.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		if ( array() === ( new self() )->get_night_dates( $check_in_date, $check_out_date ) ) {
			return new WP_Error(
				'too_many_nights',
				sprintf(
					/* translators: %d: maximum number of nights per booking. */
					__( 'You can book up to %d nights at a time. Please shorten your stay.', 'vk-booking-manager' ),
					self::MAX_NIGHTS
				),
				array(
					'status'     => 400,
					'max_nights' => self::MAX_NIGHTS,
				)
			);
		}

		return null;
	}

	/**
	 * 期間のすべての泊で空いている客室の ID を返す。
	 *
	 * @param array<int, int> $room_ids        候補の客室（リソース）ID。
	 * @param string          $check_in_date   チェックイン日（Y-m-d）。
	 * @param string          $check_out_date  チェックアウト日（Y-m-d）。
	 * @param string          $check_in_time   チェックイン時刻（HH:MM）。
	 * @param string          $check_out_time  チェックアウト時刻（HH:MM。最終泊の翌日の時刻）。
	 * @return array<int, int> 空室の客室 ID（入力の並びのまま）。
	 */
	public function get_available_room_ids( array $room_ids, string $check_in_date, string $check_out_date, string $check_in_time, string $check_out_time ): array {
		$room_ids = array_values( array_unique( array_filter( array_map( 'intval', $room_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$nights   = $this->get_night_dates( $check_in_date, $check_out_date );
		if ( empty( $room_ids ) || empty( $nights ) ) {
			return array();
		}

		// 滞在全体の時間帯（最初のチェックイン日時〜最後のチェックアウト日時）を作る。
		$range_start = $nights[0] . ' ' . $check_in_time . ':00';
		$range_end   = $check_out_date . ' ' . $check_out_time . ':00';

		// 販売日の判定: すべての泊が販売日の客室だけを残す（期間全体を1回で取得する）。
		$sale_dates = $this->sale_days->get_sale_dates( $room_ids, $nights[0], $nights[ count( $nights ) - 1 ] );
		$candidates = array_values(
			array_filter(
				$room_ids,
				static fn( int $room_id ): bool => array() === array_diff( $nights, $sale_dates[ $room_id ] ?? array() )
			)
		);
		if ( empty( $candidates ) ) {
			return array();
		}

		// 予約の重なり: 滞在全体の時間帯に重なる予約を1回で取得する（中日の昼間に入る予約も見落とさない）。
		$occupied = $this->find_occupied_room_ids( $candidates, $range_start, $range_end );

		return array_values( array_diff( $candidates, $occupied ) );
	}

	/**
	 * 月内の1泊、または指定チェックイン日からのチェックアウト候補を一括判定する。
	 *
	 * 販売日と予約は対象期間ごとに1回だけ取得し、各候補はメモリ上で滞在全体の重なりを判定する。
	 *
	 * @param array<int, int> $room_ids       候補の客室 ID。
	 * @param string          $first_date     最初の候補日（Y-m-d）。
	 * @param string          $last_date      最後の候補日（Y-m-d）。
	 * @param string          $check_in_time  チェックイン時刻（HH:MM）。
	 * @param string          $check_out_time チェックアウト時刻（HH:MM）。
	 * @param string          $check_in_date  指定時はチェックアウト候補を判定するチェックイン日。
	 * @return array<string, array{available:bool, reason:string}> 日付ごとの判定。
	 */
	public function get_calendar_availability( array $room_ids, string $first_date, string $last_date, string $check_in_time, string $check_out_time, string $check_in_date = '' ): array {
		$room_ids = array_values( array_unique( array_filter( array_map( 'intval', $room_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$dates    = array();
		$cursor   = new DateTimeImmutable( $first_date );
		$last     = new DateTimeImmutable( $last_date );
		while ( $cursor <= $last ) {
			$dates[] = $cursor->format( 'Y-m-d' );
			$cursor  = $cursor->modify( '+1 day' );
		}
		if ( empty( $room_ids ) || empty( $dates ) ) {
			return array();
		}

		// 月末の1泊、または最後のチェックアウト候補までを含めてまとめて読む。
		$query_start = ( '' !== $check_in_date ? $check_in_date : $first_date ) . ' ' . $check_in_time . ':00';
		$query_end   = ( new DateTimeImmutable( $last_date ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' ' . $check_out_time . ':00';
		$sale_start  = '' !== $check_in_date ? $check_in_date : $first_date;
		$sale_dates  = $this->sale_days->get_sale_dates( $room_ids, $sale_start, $last_date );
		$bookings    = $this->find_overlapping_bookings( $room_ids, $query_start, $query_end );
		$result      = array();

		foreach ( $dates as $date ) {
			$stay_start = '' !== $check_in_date ? $check_in_date : $date;
			$stay_end   = '' !== $check_in_date ? $date : ( new DateTimeImmutable( $date ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$nights     = $this->get_night_dates( $stay_start, $stay_end );
			$has_sale   = false;
			$available  = false;

			foreach ( $room_ids as $room_id ) {
				if ( array() !== array_diff( $nights, $sale_dates[ $room_id ] ?? array() ) ) {
					continue;
				}
				$has_sale    = true;
				$range_start = $stay_start . ' ' . $check_in_time . ':00';
				$range_end   = $stay_end . ' ' . $check_out_time . ':00';
				$conflict    = false;
				foreach ( $bookings[ $room_id ] ?? array() as $booking ) {
					if ( Booking_Overlap::overlaps( $booking['start'], $booking['end'], $booking['total_end'], $range_start, $range_end ) ) {
						$conflict = true;
						break;
					}
				}
				if ( ! $conflict ) {
					$available = true;
					break;
				}
			}

			$result[ $date ] = array(
				'available' => $available,
				'reason'    => $available ? '' : ( $has_sale ? 'fully_booked' : 'unavailable_date' ),
			);
		}

		return $result;
	}

	/**
	 * 対象期間に重なる予約を客室別にまとめて返す。
	 *
	 * @param array<int, int> $room_ids    客室 ID。
	 * @param string          $range_start 開始日時。
	 * @param string          $range_end   終了日時。
	 * @return array<int, array<int, array{start:string,end:string,total_end:string}>>
	 */
	private function find_overlapping_bookings( array $room_ids, string $range_start, string $range_end ): array {
		$query = new WP_Query(
			array(
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => Booking_Overlap::build_meta_query( $room_ids, $range_start, $range_end ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		update_meta_cache( 'post', $query->posts );
		$bookings = array();
		foreach ( $query->posts as $booking_id ) {
			$room_id                = (int) get_post_meta( (int) $booking_id, Booking_Overlap::META_RESOURCE, true );
			$bookings[ $room_id ][] = array(
				'start'     => Booking_Datetime::normalize( get_post_meta( (int) $booking_id, Booking_Overlap::META_START, true ) ),
				'end'       => Booking_Datetime::normalize( get_post_meta( (int) $booking_id, Booking_Overlap::META_END, true ) ),
				'total_end' => Booking_Datetime::normalize( get_post_meta( (int) $booking_id, Booking_Overlap::META_TOTAL_END, true ) ),
			);
		}
		return $bookings;
	}

	/**
	 * 滞在全体の時間帯に重なる予約を持つ客室の ID を返す。
	 *
	 * @param array<int, int> $room_ids    候補の客室 ID。
	 * @param string          $range_start 滞在の開始（Y-m-d H:i:s）。
	 * @param string          $range_end   滞在の終了（Y-m-d H:i:s）。
	 * @return array<int, int> 埋まっている客室 ID。
	 */
	private function find_occupied_room_ids( array $room_ids, string $range_start, string $range_end ): array {
		// 期間全体に重なる予約を1回の問い合わせで取得する（客室ごと・日ごとには問い合わせない）。
		$query = new WP_Query(
			array(
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => Booking_Overlap::build_meta_query( $room_ids, $range_start, $range_end ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		// 予約のメタを1回の問い合わせでまとめて読む。
		update_meta_cache( 'post', $query->posts );

		$occupied = array();
		foreach ( $query->posts as $booking_id ) {
			$booking_id = (int) $booking_id;
			$room_id    = (int) get_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, true );
			if ( isset( $occupied[ $room_id ] ) ) {
				continue;
			}

			$start     = Booking_Datetime::normalize( get_post_meta( $booking_id, Booking_Overlap::META_START, true ) );
			$end       = Booking_Datetime::normalize( get_post_meta( $booking_id, Booking_Overlap::META_END, true ) );
			$total_end = Booking_Datetime::normalize( get_post_meta( $booking_id, Booking_Overlap::META_TOTAL_END, true ) );

			if ( Booking_Overlap::overlaps( $start, $end, $total_end, $range_start, $range_end ) ) {
				$occupied[ $room_id ] = $room_id;
			}
		}

		return array_values( $occupied );
	}
}
