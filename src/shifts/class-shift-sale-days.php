<?php
/**
 * 客室（リソース）の販売日を、シフトのデータから判定するクラス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Shifts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Shift_Post_Type;
use WP_Query;

/**
 * 販売日の判定。
 *
 * 販売日とは「公開済みのシフトがあり、その日の状態が open か temporary_open の日」。
 * シフトが無い月、データに無い日、下書き・非公開のシフトは販売しない扱いにする。
 * 宿泊かどうかは見ない（データを読むだけ）。宿泊の判定は呼び出し側で行う。
 */
class Shift_Sale_Days {
	private const META_RESOURCE = '_vkbm_shift_resource_id';
	private const META_YEAR     = '_vkbm_shift_year';
	private const META_MONTH    = '_vkbm_shift_month';
	private const META_DAYS     = '_vkbm_shift_days';

	/**
	 * 販売日として扱う日の状態。
	 *
	 * 画面側の JS（assets/js/shift-lodging-editor.js の flagsFromDays()）と同じ値にそろえること。
	 *
	 * @var array<int, string>
	 */
	public const SALE_STATUSES = array( 'open', 'temporary_open' );

	/**
	 * 1回で調べられる期間の上限（日数）。超えたら空を返す。
	 */
	public const MAX_DAYS = 366;

	/**
	 * 受け付ける年の範囲（Shift_Editor の年の検証と同じ）。
	 */
	private const MIN_YEAR = 2000;
	private const MAX_YEAR = 2100;

	/**
	 * 客室ごとに、期間内の販売日の一覧を返す。
	 *
	 * 期間全体を1回の問い合わせで取得する（客室数・日数が増えても問い合わせは増えない）。
	 * 開始日・終了日のどちらかが存在しない日付、または開始日が終了日より後の場合は、
	 * どの客室も空の一覧を返す。
	 *
	 * @param array<int, int> $resource_ids 客室（リソース）の投稿 ID の一覧。
	 * @param string          $start_date   開始日（Y-m-d。この日を含む）。
	 * @param string          $end_date     終了日（Y-m-d。この日を含む）。
	 * @return array<int, array<int, string>> 客室 ID => 販売日（Y-m-d）の昇順の一覧。
	 */
	public function get_sale_dates( array $resource_ids, string $start_date, string $end_date ): array {
		// 客室 ID を正の整数に整え、重複を除く。
		$resource_ids = array_values( array_unique( array_filter( array_map( 'intval', $resource_ids ), static fn( int $id ): bool => $id > 0 ) ) );

		$result = array();
		foreach ( $resource_ids as $resource_id ) {
			$result[ $resource_id ] = array();
		}

		$dates = $this->build_date_list( $start_date, $end_date );
		if ( empty( $resource_ids ) || empty( $dates ) ) {
			return $result;
		}

		// 期間にかかる年月ごとに、日の一覧をまとめる。
		$dates_by_month = array();
		foreach ( $dates as $date ) {
			$dates_by_month[ substr( $date, 0, 7 ) ][] = $date;
		}

		$days_map = $this->load_days_map( $resource_ids, array_keys( $dates_by_month ) );

		// 客室ごと・月ごとに、日の状態が販売日の日だけを集める。
		foreach ( $resource_ids as $resource_id ) {
			foreach ( $dates_by_month as $year_month => $month_dates ) {
				$days = $days_map[ $resource_id ][ $year_month ] ?? null;
				if ( null === $days ) {
					continue;
				}

				foreach ( $month_dates as $date ) {
					if ( self::is_sale_entry( $days[ (int) substr( $date, 8, 2 ) ] ?? null ) ) {
						$result[ $resource_id ][] = $date;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * 指定した客室のその日が販売日かを返す。
	 *
	 * @param int    $resource_id 客室（リソース）の投稿 ID。
	 * @param string $date        日付（Y-m-d）。存在しない日付は false。
	 * @return bool
	 */
	public function is_sale_date( int $resource_id, string $date ): bool {
		$dates = $this->get_sale_dates( array( $resource_id ), $date, $date );

		return in_array( $date, $dates[ $resource_id ] ?? array(), true );
	}

	/**
	 * 開始日から終了日まで（両端を含む）の日付の一覧を作る。
	 *
	 * @param string $start_date 開始日（Y-m-d）。
	 * @param string $end_date   終了日（Y-m-d）。
	 * @return array<int, string> 日付の一覧。入力が不正なら空。
	 */
	private function build_date_list( string $start_date, string $end_date ): array {
		$start = $this->parse_date( $start_date );
		$end   = $this->parse_date( $end_date );

		if ( null === $start || null === $end || $start > $end ) {
			return array();
		}

		// 期間が長すぎる指定は、問い合わせを重くしないよう受け付けない。
		if ( (int) ( ( $end - $start ) / DAY_IN_SECONDS ) + 1 > self::MAX_DAYS ) {
			return array();
		}

		// 日付はサイトの暦の値のまま扱う（タイムゾーンで日がずれないよう UTC の暦計算で進める）。
		$dates   = array();
		$current = $start;
		while ( $current <= $end ) {
			$dates[]  = gmdate( 'Y-m-d', $current );
			$current += DAY_IN_SECONDS;
		}

		return $dates;
	}

	/**
	 * Y-m-d 形式の日付を検証し、UTC 0時のタイムスタンプにして返す。
	 *
	 * @param string $date 日付。
	 * @return int|null 存在しない日付・形式違い・範囲外は null。
	 */
	private function parse_date( string $date ): ?int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return null;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];

		if ( $year < self::MIN_YEAR || $year > self::MAX_YEAR || ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return gmmktime( 0, 0, 0, $month, $day, $year );
	}

	/**
	 * 客室・年月ごとの日のデータを、1回の問い合わせでまとめて読む。
	 *
	 * 同じ客室・月に公開済みのシフトが複数あるときは、Availability_Service の
	 * get_shift_days_for_month() と同じく、問い合わせの並び（既定の日付の新しい順）で最初の1件を使う。
	 *
	 * @param array<int, int>    $resource_ids 客室 ID の一覧。
	 * @param array<int, string> $year_months  対象の年月（Y-m）の一覧。
	 * @return array<int, array<string, array<int, mixed>>> 客室 ID => 年月（Y-m）=> 日番号 => 日のデータ。
	 */
	private function load_days_map( array $resource_ids, array $year_months ): array {
		$years  = array_values( array_unique( array_map( static fn( string $year_month ): int => (int) substr( $year_month, 0, 4 ), $year_months ) ) );
		$months = array_values( array_unique( array_map( static fn( string $year_month ): int => (int) substr( $year_month, 5, 2 ), $year_months ) ) );

		// 客室 IN・年 IN・月 IN で取得する（期間が長くなっても条件が増えないようにするため）。年と月の組み合わせの余りは後で PHP で除外する。
		$query = new WP_Query(
			array(
				'post_type'              => Shift_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_RESOURCE,
						'value'   => $resource_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_YEAR,
						'value'   => $years,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_MONTH,
						'value'   => $months,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		// 取得した投稿のメタを1回の問い合わせでまとめて読む。
		update_meta_cache( 'post', $query->posts );

		$map = array();
		foreach ( $query->posts as $post_id ) {
			$post_id     = (int) $post_id;
			$resource_id = (int) get_post_meta( $post_id, self::META_RESOURCE, true );
			$year_month  = sprintf( '%04d-%02d', (int) get_post_meta( $post_id, self::META_YEAR, true ), (int) get_post_meta( $post_id, self::META_MONTH, true ) );

			// 期間にかからない月、および同じ客室・月の2件目以降は使わない。
			if ( ! in_array( $year_month, $year_months, true ) || isset( $map[ $resource_id ][ $year_month ] ) ) {
				continue;
			}

			$raw                                = get_post_meta( $post_id, self::META_DAYS, true );
			$map[ $resource_id ][ $year_month ] = is_array( $raw ) ? $raw : array();
		}

		return $map;
	}

	/**
	 * 日のデータが販売日を表すかを返す。
	 *
	 * 状態が無いデータは、Availability_Service と同じく open として扱う。
	 *
	 * @param mixed $entry 日のデータ。
	 * @return bool
	 */
	public static function is_sale_entry( $entry ): bool {
		if ( ! is_array( $entry ) ) {
			return false;
		}

		return in_array( (string) ( $entry['status'] ?? 'open' ), self::SALE_STATUSES, true );
	}
}
