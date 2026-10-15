<?php
/**
 * 1日の中の時間枠（10:00〜11:00 など）で空き枠を計算する戦略。
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
use WP_Post;
use function current_datetime;

/**
 * 時間枠で予約する方式の空き枠計算。
 *
 * Availability_Service から切り出した計算で、シフト・予約・メニュー設定の取得は
 * Availability_Service のメソッドを経由する。これにより、Availability_Service を継承して
 * それらのメソッドを差し替えた場合も、この戦略の計算に反映される。
 */
class Time_Slot_Strategy implements Slot_Strategy {

	/**
	 * 設定・シフト・予約の取得元。
	 *
	 * @var Availability_Service
	 */
	private Availability_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Availability_Service $service 設定・シフト・予約の取得元。
	 */
	public function __construct( Availability_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Generate available slots for a single day.
	 *
	 * @param WP_Post      $menu_post         Menu post.
	 * @param array<int>   $staff_ids         Staff IDs.
	 * @param string       $date              Date string (Y-m-d).
	 * @param DateTimeZone $timezone          Timezone.
	 * @param bool         $is_staff_preferred Whether staff is preferred.
	 * @return array<int, array<string, mixed>>
	 */
	public function generate_slots_for_date( WP_Post $menu_post, array $staff_ids, string $date, DateTimeZone $timezone, bool $is_staff_preferred ): array {
		$menu_settings = $this->service->get_menu_settings( $menu_post );
		if ( ! $this->service->is_date_allowed_for_menu( $menu_settings, $date, $timezone ) ) {
			return array();
		}

		// 予約可能期間を超える日付はスロットを返さない。
		// Return no slots for dates beyond the max advance booking period.
		// #411: 診断用の is_within_max_advance_days() と判定ロジックを共有する（重複排除）。
		if ( ! $this->service->is_within_max_advance_days( $menu_settings, $date, $timezone ) ) {
			return array();
		}

		$slot_step_minutes = $this->service->get_slot_step_minutes();
		$total_block_min   = max( $slot_step_minutes, $menu_settings['total_duration'] );
		$service_minutes   = $menu_settings['duration'];
		$fixed_start_times = $menu_settings['fixed_start_times'] ?? array();
		// #655: 締切が0（締切なし）でも「現在時刻より前」は予約できないため、常に下限を持つ。
		// Always keep a lower bound (now + deadline hours) so past slots are never returned.
		$deadline_cutoff = $this->service->resolve_booking_cutoff( $menu_settings, $timezone );

		$year  = (int) substr( $date, 0, 4 );
		$month = (int) substr( $date, 5, 2 );
		$day   = (int) substr( $date, 8, 2 );

		// 最大同時予約人数を取得（Pro版でのみ2以上が設定可能、未設定時はデフォルト1）。
		// Retrieve max capacity (configurable to 2+ in Pro, defaults to 1).
		$max_capacity = $this->service->get_menu_max_capacity( $menu_post );

		// 最小催行人数（グループ開催型）を取得（0=制約なし）。フロント・管理画面の催行状態表示に用いる。
		$min_capacity = $this->service->get_menu_min_capacity( $menu_post );

		$staff_info = $this->service->get_staff_snapshot( $staff_ids );
		$all_slots  = array();

		foreach ( $staff_ids as $staff_id ) {
			if ( empty( $staff_info[ $staff_id ] ) ) {
				continue;
			}

			$day_entry = $this->service->get_shift_entry( $staff_id, $year, $month, $day );

			if ( empty( $day_entry['slots'] ) || $this->service->is_closed_status( (string) ( $day_entry['status'] ?? '' ) ) ) {
				continue;
			}

			$bookings = $this->service->get_bookings_for_staff_date( $staff_id, $date, $timezone );
			// スタッフ指名時は予約済みスロットをスキップし、自動割り当て時は予約済みも含めて返す。
			$staff_slots = $this->build_slots_from_entry(
				$day_entry['slots'],
				$date,
				$timezone,
				$total_block_min,
				$service_minutes,
				$deadline_cutoff,
				$bookings,
				$slot_step_minutes,
				$fixed_start_times,
				$is_staff_preferred,
				(int) $menu_post->ID
			);

			if ( empty( $staff_slots ) ) {
				continue;
			}

			$last_index = count( $staff_slots ) - 1;
			foreach ( $staff_slots as $index => $slot ) {
				// #392: スタッフ指名ありの場合、capacity はメニューの最大同時予約人数（1組の最大人数）を反映する。
				// 予約済み（guest_count>0）のスロットは build_slots_from_entry() が既に除外しているため
				// （skip_booked_slots=true。上のスタッフ指名スロット構築を参照）、ここに来る guest_count は
				// 実質常に0だが、式としては max_capacity - guest_count を保持し安全側に倒す。
				// これにより「指名を使うメニューでは1枠1組（貸切）。定員は1組の最大人数」という仕様（#392）を
				// 満たしつつ、指名を使わないメニューで万一この経路を通っても（前提上は起きない）
				// 従来の1対1相当の挙動から大きく外れない。
				$guest_count = isset( $slot['guest_count'] ) ? (int) $slot['guest_count'] : 0;
				// 貸し切り予約で閉じた枠は、残席があっても受付停止（remaining=0）にする。
				$exclusive_closed = ! empty( $slot['exclusive_closed'] );
				$remaining        = $exclusive_closed ? 0 : max( 0, $max_capacity - $guest_count );
				$all_slots[]      = array(
					'slot_id'          => sprintf( '%d-%s', $staff_id, gmdate( 'YmdHis', $slot['start']->getTimestamp() ) ),
					'start_at'         => $slot['start']->format( DATE_ATOM ),
					'end_at'           => $slot['end']->format( DATE_ATOM ),
					'service_end_at'   => $slot['service_end']->format( DATE_ATOM ),
					'duration_minutes' => $service_minutes,
					'staff'            => $staff_info[ $staff_id ],
					'capacity'         => $max_capacity,
					'remaining'        => $remaining,
					'guest_count'      => $guest_count,
					// 最小催行人数と当該枠の合計予約人数（指名枠は単一スタッフ=合計）。フロント・管理画面の催行状態表示用。
					'min_capacity'     => $min_capacity,
					'booked_guests'    => $guest_count,
					'exclusive_closed' => $exclusive_closed,
					'flags'            => array(
						'is_last_slot_of_day'   => ( $index === $last_index ),
						'requires_confirmation' => false,
					),
					'auto_assign'      => ! $is_staff_preferred,
				);
				// 自動割当では、同一メニューの人数と別メニューの予約の有無を集約処理へ渡す（#656）。
				// 集約後のスロットには含まれない（collapse_slots_for_auto_assignment() が必要な値だけを引き継ぐ）。
				if ( ! $is_staff_preferred ) {
					$last_key                                   = array_key_last( $all_slots );
					$all_slots[ $last_key ]['same_menu_guests'] = (int) ( $slot['same_menu_guests'] ?? $guest_count );
					$all_slots[ $last_key ]['has_other_menu']   = ! empty( $slot['has_other_menu'] );
				}
			}
		}

		if ( ! $is_staff_preferred ) {
			return $this->service->collapse_slots_for_auto_assignment( $all_slots, (int) $menu_post->ID, $max_capacity, $min_capacity );
		}

		usort(
			$all_slots,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start_at'], $b['start_at'] );
			}
		);

		return $all_slots;
	}

	/**
	 * シフトエントリからスロットコレクションを構築する。
	 *
	 * @param array<int, array<string, string>>            $slots              シフトスロット（開始/終了のペア）。
	 * @param string                                       $date               日付（Y-m-d）。
	 * @param DateTimeZone                                 $timezone           タイムゾーン。
	 * @param int                                          $block_minutes      バッファ込みのスロット長（分）。
	 * @param int                                          $service_minutes    サービス提供時間（分）。
	 * @param DateTimeImmutable|null                       $deadline_cutoff    予約締切カットオフ。
	 * @param array<int, array<string, DateTimeImmutable>> $bookings           既存予約データ。
	 * @param int                                          $slot_step_minutes  スロットステップ（分）。
	 * @param array<string>                                $fixed_start_times  固定開始時間（HH:MM）。設定時はこの時間のみ提供。
	 * @param bool                                         $skip_booked_slots  予約済みスロットをスキップするか。
	 *                                                                         true: スタッフ指名時（予約済みスロットを除外）。
	 *                                                                         false: 自動割り当て時（予約済みスロットも含めて返す）。
	 * @param int                                          $menu_id            当該メニューID。貸し切り判定をこのメニューの予約に限定する（0 で全メニュー）。
	 * @return array<int, array<string, DateTimeImmutable>>
	 */
	public function build_slots_from_entry(
		array $slots,
		string $date,
		DateTimeZone $timezone,
		int $block_minutes,
		int $service_minutes,
		?DateTimeImmutable $deadline_cutoff,
		array $bookings,
		int $slot_step_minutes,
		array $fixed_start_times = array(),
		bool $skip_booked_slots = true,
		int $menu_id = 0
	): array {
		$result = array();

		foreach ( $slots as $slot ) {
			if ( empty( $slot['start'] ) || empty( $slot['end'] ) ) {
				continue;
			}

			$range_start = $this->service->create_datetime_from_time( $date, (string) $slot['start'], $timezone, false );
			$range_end   = $this->service->create_datetime_from_time( $date, (string) $slot['end'], $timezone, true );

			if ( ! $range_start || ! $range_end ) {
				continue;
			}

			if ( ! empty( $fixed_start_times ) ) {
				foreach ( $fixed_start_times as $time ) {
					$cursor = $this->service->create_datetime_from_time( $date, $time, $timezone, false );
					if ( ! $cursor ) {
						continue;
					}

					if ( $cursor < $range_start || $cursor >= $range_end ) {
						continue;
					}

					$end = $cursor->modify( sprintf( '+%d minutes', $block_minutes ) );
					if ( $end > $range_end ) {
						continue;
					}

					if ( $deadline_cutoff && $cursor < $deadline_cutoff ) {
						continue;
					}

					// 予約が既にある場合の処理。
					// スタッフ指名時はスキップし、自動割り当て時は予約人数を保持して返す。
					$guest_count = $this->service->count_guests_for_slot( $cursor, $end, $bookings );
					// 貸し切り予約がこの時間帯に重複していれば、残席があっても受付停止する。
					$exclusive_closed = $this->service->slot_has_exclusive_booking( $cursor, $end, $bookings, $menu_id );
					// スタッフ指名時は、予約済み（人数>0）または貸し切りで閉じた枠をスキップする。
					if ( $skip_booked_slots && ( $guest_count > 0 || $exclusive_closed ) ) {
						continue;
					}

					// 同一メニューの相乗り人数と、別メニューの予約の有無（#656。担当者は同時に1組だけ受け持つ）。
					$menu_summary = $this->service->summarize_menu_bookings_for_slot( $cursor, $end, $bookings, $menu_id );
					$service_end  = $cursor->modify( sprintf( '+%d minutes', $service_minutes ) );
					$result[]     = array(
						'start'            => $cursor,
						'end'              => $end,
						'service_end'      => $service_end,
						'service_duration' => $service_minutes,
						'guest_count'      => $guest_count,
						'same_menu_guests' => $menu_summary['same_menu_guests'],
						'has_other_menu'   => $menu_summary['has_other_menu'],
						'exclusive_closed' => $exclusive_closed,
					);
				}
			} else {
				$cursor = $range_start;
				while ( true ) {
					$end = $cursor->modify( sprintf( '+%d minutes', $block_minutes ) );

					if ( $end > $range_end || $cursor >= $range_end ) {
						break;
					}

					if ( $deadline_cutoff && $cursor < $deadline_cutoff ) {
						$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
						continue;
					}

					// 予約が既にある場合の処理。
					// スタッフ指名時はスキップし、自動割り当て時は予約人数を保持して返す。
					$guest_count = $this->service->count_guests_for_slot( $cursor, $end, $bookings );
					// 貸し切り予約がこの時間帯に重複していれば、残席があっても受付停止する。
					$exclusive_closed = $this->service->slot_has_exclusive_booking( $cursor, $end, $bookings, $menu_id );
					// スタッフ指名時は、予約済み（人数>0）または貸し切りで閉じた枠をスキップする。
					if ( $skip_booked_slots && ( $guest_count > 0 || $exclusive_closed ) ) {
						$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
						continue;
					}

					// 同一メニューの相乗り人数と、別メニューの予約の有無（#656。担当者は同時に1組だけ受け持つ）。
					$menu_summary = $this->service->summarize_menu_bookings_for_slot( $cursor, $end, $bookings, $menu_id );
					$service_end  = $cursor->modify( sprintf( '+%d minutes', $service_minutes ) );

					$result[] = array(
						'start'            => $cursor,
						'end'              => $end,
						'service_end'      => $service_end,
						'service_duration' => $service_minutes,
						'guest_count'      => $guest_count,
						'same_menu_guests' => $menu_summary['same_menu_guests'],
						'has_other_menu'   => $menu_summary['has_other_menu'],
						'exclusive_closed' => $exclusive_closed,
					);

					$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
				}
			}
		}

		return $result;
	}
}
