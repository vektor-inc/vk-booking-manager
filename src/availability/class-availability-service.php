<?php
/**
 * Provides calculated availability data for menus and staff resources.
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
use Exception;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;
use WP_Query;
use WP_Error;
use function __;
use function esc_url_raw;
use function current_datetime;
use function current_user_can;
use function get_current_user_id;
use function is_user_logged_in;

/**
 * Provides calculated availability data for menus and staff resources.
 */
class Availability_Service {
	private const SHIFT_META_RESOURCE = '_vkbm_shift_resource_id';
	private const SHIFT_META_YEAR     = '_vkbm_shift_year';
	private const SHIFT_META_MONTH    = '_vkbm_shift_month';
	private const SHIFT_META_DAYS     = '_vkbm_shift_days';

	private const BOOKING_META_START     = '_vkbm_booking_service_start';
	private const BOOKING_META_END       = '_vkbm_booking_service_end';
	private const BOOKING_META_TOTAL_END = '_vkbm_booking_total_end';
	private const BOOKING_META_RESOURCE  = '_vkbm_booking_resource_id';
	private const BOOKING_META_STATUS    = '_vkbm_booking_status';
	private const BOOKING_META_GUESTS    = '_vkbm_booking_guests';
	private const BOOKING_META_EXCLUSIVE = '_vkbm_booking_exclusive';
	private const BOOKING_META_SERVICE   = '_vkbm_booking_service_id';

	private const MENU_META_DURATION             = '_vkbm_duration_minutes';
	private const MENU_META_BUFFER_AFTER         = '_vkbm_buffer_after_minutes';
	private const MENU_META_DEADLINE_HOURS       = '_vkbm_reservation_deadline_hours';
	private const MENU_META_MAX_ADVANCE_DAYS     = '_vkbm_max_advance_booking_days';
	private const MENU_META_STAFF_IDS            = '_vkbm_staff_ids';
	private const MENU_META_ARCHIVED             = '_vkbm_is_archived';
	private const MENU_META_ONLINE_DISABLED      = '_vkbm_online_unavailable';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	// メタキーの定義元は Reservation_Day（single source of truth）。
	private const MENU_META_RESERVATION_CUSTOM_WEEKDAYS = Reservation_Day::META_CUSTOM_WEEKDAYS;
	private const MENU_META_RESERVATION_CUSTOM_DATES    = Reservation_Day::META_CUSTOM_DATES;
	private const MENU_META_FIXED_START_TIMES           = '_vkbm_fixed_start_times';
	private const MENU_META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const MENU_META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const MENU_META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';

	private const DAY_STATUS_OPEN            = 'open';
	private const DAY_STATUS_REGULAR_HOLIDAY = 'regular_holiday';
	private const DAY_STATUS_TEMP_OPEN       = 'temporary_open';
	private const DAY_STATUS_TEMP_CLOSED     = 'temporary_closed';
	private const DAY_STATUS_UNAVAILABLE     = 'unavailable';

	private const CLOSED_DAY_STATUSES = array(
		self::DAY_STATUS_REGULAR_HOLIDAY,
		self::DAY_STATUS_TEMP_CLOSED,
		self::DAY_STATUS_UNAVAILABLE,
	);

	private const SLOT_STEP_MINUTES_DEFAULT = 10;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * Shift cache.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $shift_cache = array();

	/**
	 * Booking cache.
	 *
	 * スタッフ別予約は guests を含み、ユーザー別予約は start/end のみを持つため、guests は任意キーとする。
	 *
	 * @var array<string, array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests?:int}>>
	 */
	private array $booking_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null $settings_repository Provider settings repository.
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();
	}

	/**
	 * Get slot step minutes from settings.
	 *
	 * @return int Slot step in minutes.
	 */
	private function get_slot_step_minutes(): int {
		$settings      = $this->settings_repository->get_settings();
		$slot_step     = isset( $settings['provider_slot_step_minutes'] ) ? (int) $settings['provider_slot_step_minutes'] : self::SLOT_STEP_MINUTES_DEFAULT;
		$allowed_steps = array( 10, 15, 20, 30, 60 );
		return in_array( $slot_step, $allowed_steps, true ) ? $slot_step : self::SLOT_STEP_MINUTES_DEFAULT;
	}

	/**
	 * Return aggregated monthly availability for a menu.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_calendar_meta( array $args ) {
		$menu = $this->validate_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$preferred_staff_id = isset( $args['resource_id'] ) ? (int) $args['resource_id'] : 0;

		$staff_ids = $this->resolve_staff_ids( $menu, $preferred_staff_id );
		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		$year  = (int) ( $args['year'] ?? 0 );
		$month = (int) ( $args['month'] ?? 0 );
		if ( $year < 2000 || $year > 2100 ) {
			return new WP_Error( 'invalid_year', __( 'The year specification is invalid.', 'vk-booking-manager' ) );
		}
		if ( $month < 1 || $month > 12 ) {
			return new WP_Error( 'invalid_month', __( 'The month specification is invalid.', 'vk-booking-manager' ) );
		}

		$timezone = $this->resolve_timezone( (string) ( $args['timezone'] ?? '' ) );

		$preferred_staff_id = isset( $args['resource_id'] ) ? (int) $args['resource_id'] : 0;

		$cache_key = $this->build_cache_key(
			'calendar',
			$menu->ID,
			$staff_ids,
			sprintf( '%04d-%02d', $year, $month ),
			$timezone->getName()
		);

		$cached = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$days_in_month = (int) wp_date( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$results       = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date       = sprintf( '%04d-%02d-%02d', $year, $month, $day );
			$slots      = $this->generate_slots_for_date( $menu, $staff_ids, $date, $timezone, $preferred_staff_id > 0 );
			$status_key = $this->resolve_day_status( $staff_ids, $year, $month, $day );
			$status     = $this->map_status_to_calendar_label( $status_key );
			$is_holiday = in_array( $status, array( 'holiday', 'off', 'special_close' ), true );

			// 貸し切り予約で閉じた枠（exclusive_closed）は「空き枠」として数えない。
			// すべての枠が貸し切りで閉じた日はカレンダー上 × 扱い（available_slots=0・is_disabled=true）になる。
			// 満席（remaining=0 だが exclusive ではない）枠は従来どおり日数に数え、フロントで「満席」として
			// 表示できるよう残す（既存の複数人予約の挙動を変えない）。
			$bookable_slots = array_values(
				array_filter(
					$slots,
					static function ( array $slot ): bool {
						return empty( $slot['exclusive_closed'] );
					}
				)
			);

			$results[] = array(
				'date'            => $date,
				'available_slots' => count( $bookable_slots ),
				'first_start_at'  => $bookable_slots ? $bookable_slots[0]['start_at'] : null,
				'shift_status'    => $status,
				'is_holiday'      => $is_holiday,
				'is_disabled'     => empty( $bookable_slots ),
				'notes'           => $this->build_day_notes( $status_key, $bookable_slots ),
			);
		}

		$payload = array(
			'year'  => $year,
			'month' => $month,
			'days'  => $results,
			'meta'  => array(
				'menu_id'      => $menu->ID,
				'resource_id'  => isset( $args['resource_id'] ) ? (int) $args['resource_id'] : null,
				'timezone'     => $timezone->getName(),
				'generated_at' => $this->current_timestamp_iso( $timezone ),
			),
		);

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

		return $payload;
	}

	/**
	 * Return concrete time slots for the requested date.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_daily_slots( array $args ) {
		$menu = $this->validate_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$date_string = (string) ( $args['date'] ?? '' );
		$date        = DateTimeImmutable::createFromFormat( 'Y-m-d', $date_string, wp_timezone() );
		if ( ! $date ) {
			return new WP_Error( 'invalid_date', __( 'Date format is incorrect.', 'vk-booking-manager' ) );
		}

		$timezone = $this->resolve_timezone( (string) ( $args['timezone'] ?? '' ) );

		$preferred_staff_id = isset( $args['resource_id'] ) ? (int) $args['resource_id'] : 0;

		$staff_ids = $this->resolve_staff_ids( $menu, $preferred_staff_id );
		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		$current_user_id   = get_current_user_id();
		$apply_user_filter = $current_user_id > 0 && ! current_user_can( Capabilities::MANAGE_RESERVATIONS );
		if ( ! $apply_user_filter ) {
			$cache_key = $this->build_cache_key(
				'daily',
				$menu->ID,
				$staff_ids,
				$date->format( 'Y-m-d' ),
				$timezone->getName()
			);

			$cached = get_transient( $cache_key );
			if ( $cached ) {
				return $cached;
			}
		}

		$slots = $this->generate_slots_for_date(
			$menu,
			$staff_ids,
			$date->format( 'Y-m-d' ),
			$timezone,
			$preferred_staff_id > 0
		);

		if ( $apply_user_filter && ! empty( $slots ) ) {
			$user_bookings = $this->get_bookings_for_user_date( $current_user_id, $date->format( 'Y-m-d' ), $timezone );
			if ( ! empty( $user_bookings ) ) {
				$slots = array_values(
					array_filter(
						$slots,
						function ( array $slot ) use ( $user_bookings ): bool {
							try {
								$start = new DateTimeImmutable( (string) ( $slot['start_at'] ?? '' ) );
								$end   = new DateTimeImmutable( (string) ( $slot['end_at'] ?? '' ) );
							} catch ( Exception $e ) {
								return true;
							}

							return ! $this->has_booking_conflict( $start, $end, $user_bookings );
						}
					)
				);
			}
		}

		$payload = array(
			'date'     => $date->format( 'Y-m-d' ),
			'timezone' => $timezone->getName(),
			'slots'    => $slots,
			'meta'     => array(
				'generated_at' => $this->current_timestamp_iso( $timezone ),
				'menu_id'      => $menu->ID,
				'resource_id'  => isset( $args['resource_id'] ) ? (int) $args['resource_id'] : null,
			),
		);

		if ( ! $apply_user_filter ) {
			set_transient( $cache_key, $payload, MINUTE_IN_SECONDS );
		}

		return $payload;
	}

	/**
	 * Validate menu post.
	 *
	 * @param int $menu_id Menu ID.
	 * @return WP_Post|WP_Error
	 */
	private function validate_menu( int $menu_id ) {
		$post = get_post( $menu_id );

		if ( ! $post || 'vkbm_service_menu' !== $post->post_type ) {
			return new WP_Error( 'menu_not_found', __( 'The specified menu was not found.', 'vk-booking-manager' ) );
		}

		if ( 'publish' !== $post->post_status && ! $this->can_book_private_menu() ) {
			return new WP_Error( 'menu_not_public', __( 'Menu has not been published.', 'vk-booking-manager' ) );
		}

		if ( '1' === get_post_meta( $post->ID, self::MENU_META_ARCHIVED, true ) ) {
			return new WP_Error( 'menu_archived', __( 'Archived menus cannot be reserved.', 'vk-booking-manager' ) );
		}

		if ( '1' === get_post_meta( $post->ID, self::MENU_META_ONLINE_DISABLED, true ) ) {
			return new WP_Error( 'menu_offline_only', __( 'This menu is not available for online reservation.', 'vk-booking-manager' ) );
		}

		return $post;
	}

	/**
	 * Check if the current user can book a non-public menu.
	 *
	 * @return bool
	 */
	private function can_book_private_menu(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( Capabilities::MANAGE_RESERVATIONS )
			|| current_user_can( Capabilities::MANAGE_SERVICE_MENUS )
			|| current_user_can( 'edit_others_posts' );
	}

	/**
	 * Resolve target staff IDs.
	 *
	 * @param WP_Post $menu_post Menu post.
	 * @param int     $preferred_staff Preferred staff ID.
	 * @return array<int>|WP_Error
	 */
	private function resolve_staff_ids( WP_Post $menu_post, int $preferred_staff ) {
		$staff_ids = get_post_meta( $menu_post->ID, self::MENU_META_STAFF_IDS, true );
		$staff_ids = is_array( $staff_ids ) ? array_values( array_unique( array_map( 'intval', $staff_ids ) ) ) : array();

		// 無料版では選択可能スタッフの制限を解除.
		$is_staff_enabled = Staff_Editor::is_enabled();

		if ( $preferred_staff > 0 ) {
			if ( empty( $staff_ids ) ) {
				$staff_ids = array( $preferred_staff );
			} elseif ( ! in_array( $preferred_staff, $staff_ids, true ) ) {
				// 無料版ではスタッフ制限チェックをスキップ.
				if ( $is_staff_enabled ) {
					return new WP_Error( 'staff_not_assigned', __( 'The specified staff member cannot be in charge of this menu.', 'vk-booking-manager' ) );
				}
				// 無料版では preferred_staff を使用.
				$staff_ids = array( $preferred_staff );
			} else {
				$staff_ids = array( $preferred_staff );
			}
		}

		if ( empty( $staff_ids ) ) {
			return new WP_Error( 'staff_not_configured', __( 'No staff members have been set up to be in charge.', 'vk-booking-manager' ) );
		}

		return $staff_ids;
	}

	/**
	 * Resolve timezone.
	 *
	 * @param string $timezone Timezone string.
	 * @return DateTimeZone
	 */
	private function resolve_timezone( string $timezone ): DateTimeZone {
		if ( $timezone ) {
			try {
				return new DateTimeZone( $timezone );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fallback to site timezone.
			}
		}

		return wp_timezone();
	}

	/**
	 * Build cache key.
	 *
	 * @param string     $prefix   Prefix.
	 * @param int        $menu_id  Menu ID.
	 * @param array<int> $staff_ids Staff IDs.
	 * @param string     $date_key Date key.
	 * @param string     $timezone Timezone name.
	 * @return string
	 */
	private function build_cache_key( string $prefix, int $menu_id, array $staff_ids, string $date_key, string $timezone ): string {
		$staff_hash = md5( implode( '-', $staff_ids ) );
		return sprintf( 'vkbm_%s_%d_%s_%s_%s', $prefix, $menu_id, $staff_hash, $date_key, md5( $timezone ) );
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
	private function generate_slots_for_date( WP_Post $menu_post, array $staff_ids, string $date, DateTimeZone $timezone, bool $is_staff_preferred ): array {
		$menu_settings = $this->get_menu_settings( $menu_post );
		if ( ! $this->is_date_allowed_for_menu( $menu_settings, $date, $timezone ) ) {
			return array();
		}

		// 予約可能期間を超える日付はスロットを返さない。
		// Return no slots for dates beyond the max advance booking period.
		if ( $menu_settings['max_advance_days'] > 0 ) {
			$now = current_datetime();
			if ( $now instanceof DateTimeImmutable ) {
				$max_date = $now->setTimezone( $timezone )->modify( sprintf( '+%d days', $menu_settings['max_advance_days'] ) );
				$target   = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $timezone );
				if ( $target instanceof DateTimeImmutable && $target->format( 'Y-m-d' ) > $max_date->format( 'Y-m-d' ) ) {
					return array();
				}
			}
		}

		$slot_step_minutes = $this->get_slot_step_minutes();
		$total_block_min   = max( $slot_step_minutes, $menu_settings['total_duration'] );
		$service_minutes   = $menu_settings['duration'];
		$fixed_start_times = $menu_settings['fixed_start_times'] ?? array();
		$deadline_cutoff   = null;

		if ( $menu_settings['deadline_hours'] > 0 ) {
			// Use the site clock to avoid user-provided timezone drift, but compare in requested timezone.
			$now = current_datetime();
			if ( $now instanceof DateTimeImmutable ) {
				$deadline_cutoff = $now->modify( sprintf( '+%d hours', $menu_settings['deadline_hours'] ) )->setTimezone( $timezone );
			}
		}

		$year  = (int) substr( $date, 0, 4 );
		$month = (int) substr( $date, 5, 2 );
		$day   = (int) substr( $date, 8, 2 );

		// 最大同時予約人数を取得（Pro版でのみ2以上が設定可能、未設定時はデフォルト1）。
		// Retrieve max capacity (configurable to 2+ in Pro, defaults to 1).
		$max_capacity = $this->get_menu_max_capacity( $menu_post );

		// 最小催行人数（グループ開催型）を取得（0=制約なし）。フロント・管理画面の催行状態表示に用いる。
		$min_capacity = $this->get_menu_min_capacity( $menu_post );

		$staff_info = $this->get_staff_snapshot( $staff_ids );
		$all_slots  = array();

		foreach ( $staff_ids as $staff_id ) {
			if ( empty( $staff_info[ $staff_id ] ) ) {
				continue;
			}

			$day_entry = $this->get_shift_entry( $staff_id, $year, $month, $day );

			if ( empty( $day_entry['slots'] ) || $this->is_closed_status( (string) ( $day_entry['status'] ?? '' ) ) ) {
				continue;
			}

			$bookings = $this->get_bookings_for_staff_date( $staff_id, $date, $timezone );
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
				// スタッフ指名ありの場合は従来どおり capacity=1（1対1）。
				// Staff-preferred slots always have capacity 1 (one-on-one).
				$guest_count = isset( $slot['guest_count'] ) ? (int) $slot['guest_count'] : 0;
				// 貸し切り予約で閉じた枠は、残席があっても受付停止（remaining=0）にする。
				$exclusive_closed = ! empty( $slot['exclusive_closed'] );
				$remaining        = $exclusive_closed ? 0 : max( 0, 1 - $guest_count );
				$all_slots[]      = array(
					'slot_id'          => sprintf( '%d-%s', $staff_id, gmdate( 'YmdHis', $slot['start']->getTimestamp() ) ),
					'start_at'         => $slot['start']->format( DATE_ATOM ),
					'end_at'           => $slot['end']->format( DATE_ATOM ),
					'service_end_at'   => $slot['service_end']->format( DATE_ATOM ),
					'duration_minutes' => $service_minutes,
					'staff'            => $staff_info[ $staff_id ],
					'capacity'         => 1,
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
			}
		}

		if ( ! $is_staff_preferred ) {
			return $this->collapse_slots_for_auto_assignment( $all_slots, (int) $menu_post->ID, $max_capacity, $min_capacity );
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
	 * Collapse staff-specific slots into auto-assignable buckets.
	 *
	 * スタッフ個別スロットを自動割り当てバケットに集約します。
	 * capacity はメニューの最大同時予約人数を反映します。
	 *
	 * @param array<int, array<string, mixed>> $slots        Slots.
	 * @param int                              $menu_id      Menu ID.
	 * @param int                              $max_capacity Maximum simultaneous bookings per slot.
	 * @param int                              $min_capacity 最小催行人数（0=制約なし）。
	 * @return array<int, array<string, mixed>>
	 */
	private function collapse_slots_for_auto_assignment( array $slots, int $menu_id, int $max_capacity = 1, int $min_capacity = 0 ): array {
		if ( empty( $slots ) ) {
			return array();
		}

		$max_capacity = max( 1, $max_capacity );
		$min_capacity = max( 0, $min_capacity );
		$grouped      = array();

		foreach ( $slots as $slot ) {
			$key = $slot['start_at'] . '|' . $slot['end_at'];

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'slot_id'              => sprintf( 'auto-%d-%s', $menu_id, md5( $key ) ),
					'start_at'             => $slot['start_at'],
					'end_at'               => $slot['end_at'],
					'service_end_at'       => $slot['service_end_at'] ?? $slot['end_at'],
					'duration_minutes'     => $slot['duration_minutes'],
					'staff'                => null,
					'staff_label'          => vkbm_get_no_nomination_label(),
					'assignable_staff_ids' => array(),
					'capacity'             => 1,
					'remaining'            => 1,
					// 貸し切り予約で閉じた枠かどうか。同一時間帯のどのスタッフ枠に貸し切り予約があっても閉じる。
					'exclusive_closed'     => ! empty( $slot['exclusive_closed'] ),
					'flags'                => array(
						'is_last_slot_of_day'   => ! empty( $slot['flags']['is_last_slot_of_day'] ),
						'requires_confirmation' => ! empty( $slot['flags']['requires_confirmation'] ),
					),
					'auto_assign'          => true,
				);
			} else {
				$grouped[ $key ]['flags']['is_last_slot_of_day']   = $grouped[ $key ]['flags']['is_last_slot_of_day'] || ! empty( $slot['flags']['is_last_slot_of_day'] );
				$grouped[ $key ]['flags']['requires_confirmation'] = $grouped[ $key ]['flags']['requires_confirmation'] || ! empty( $slot['flags']['requires_confirmation'] );
				// 同一時間帯のいずれかのスタッフ枠が貸し切りで閉じていれば、その枠は受付停止にする。
				$grouped[ $key ]['exclusive_closed'] = $grouped[ $key ]['exclusive_closed'] || ! empty( $slot['exclusive_closed'] );
			}

			$staff_id    = isset( $slot['staff']['id'] ) ? (int) $slot['staff']['id'] : 0;
			$guest_count = isset( $slot['guest_count'] ) ? (int) $slot['guest_count'] : 0;

			// シフトに入っているスタッフはすべて割り当て候補に追加する。
			// 1予約は分割せず単一スタッフへ割り当てるため、各スタッフの負荷（予約人数）を個別に保持する。
			if ( $staff_id > 0 ) {
				$grouped[ $key ]['assignable_staff_ids'][] = $staff_id;
				if ( ! isset( $grouped[ $key ]['staff_loads'] ) ) {
					$grouped[ $key ]['staff_loads'] = array();
				}
				// 同一スタッフが重複して現れた場合は最大の負荷を採用する（防御的）。
				$grouped[ $key ]['staff_loads'][ $staff_id ] = max(
					isset( $grouped[ $key ]['staff_loads'][ $staff_id ] ) ? (int) $grouped[ $key ]['staff_loads'][ $staff_id ] : 0,
					$guest_count
				);
			}
		}

		foreach ( $grouped as $key => $slot ) {
			if ( ! empty( $slot['assignable_staff_ids'] ) ) {
				$unique = array_values(
					array_unique(
						array_filter(
							array_map(
								static function ( $value ): int {
									return (int) $value;
								},
								$slot['assignable_staff_ids']
							)
						)
					)
				);

				$grouped[ $key ]['assignable_staff_ids'] = $unique;
			} else {
				$grouped[ $key ]['assignable_staff_ids'] = array();
			}

			// 1予約は分割せず単一スタッフへ割り当てるため、capacity は max_capacity（1スタッフの上限）。
			$grouped[ $key ]['capacity'] = $max_capacity;

			// remaining = 対応可能スタッフの中で「最も空きの大きい単一スタッフの残り」。
			// = 1予約で入れられる最大人数。対応可能スタッフが0人なら 0（予約不可）。
			$staff_loads    = ( isset( $slot['staff_loads'] ) && is_array( $slot['staff_loads'] ) ) ? $slot['staff_loads'] : array();
			$best_remaining = 0;

			// 催行判定用の合計予約人数（グループ開催型）。
			// 自動割り当てでは同一時間帯がスタッフ横断で1枠に集約されるため、
			// 各スタッフ枠の予約人数（staff_loads）を合計してその時間枠全体の相乗り人数とする。
			$grouped[ $key ]['min_capacity']  = $min_capacity;
			$grouped[ $key ]['booked_guests'] = (int) array_sum( $staff_loads );
			foreach ( $grouped[ $key ]['assignable_staff_ids'] as $sid ) {
				$load      = isset( $staff_loads[ $sid ] ) ? (int) $staff_loads[ $sid ] : 0;
				$remaining = max( 0, $max_capacity - $load );
				if ( $remaining > $best_remaining ) {
					$best_remaining = $remaining;
				}
			}
			$grouped[ $key ]['remaining'] = empty( $grouped[ $key ]['assignable_staff_ids'] ) ? 0 : $best_remaining;

			// 貸し切り予約で閉じた枠は、残席があっても受付停止（remaining=0）に上書きする。
			// スロット自体は消さず、フロントが「満席」と区別して「予約受付終了」を表示できるよう exclusive_closed を残す。
			if ( ! empty( $grouped[ $key ]['exclusive_closed'] ) ) {
				$grouped[ $key ]['remaining'] = 0;
			}

			// 内部集計用キーは出力に含めない。
			unset( $grouped[ $key ]['staff_loads'] );
		}

		// 満枠スロットもフロントエンド側で「満枠」表示するため除外しない。
		// Keep fully booked slots so the front-end can show them as greyed out.
		$result = array_values( $grouped );

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start_at'], $b['start_at'] );
			}
		);

		return $result;
	}

	/**
	 * Build staff info snapshot.
	 *
	 * @param array<int> $staff_ids Staff IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_staff_snapshot( array $staff_ids ): array {
		$info = array();

		foreach ( $staff_ids as $staff_id ) {
			$post = get_post( $staff_id );
			if ( ! $post ) {
				continue;
			}

			$avatar = get_the_post_thumbnail_url( $staff_id, 'thumbnail' );

			$resource_tags = \VKBookingManager\Resources\Resource_Tag_Taxonomy::get_tag_labels( $staff_id );

			$info[ $staff_id ] = array(
				'id'            => $staff_id,
				'name'          => vkbm_get_resource_display_name( $staff_id ),
				'resource_tags' => $resource_tags,
				'avatar'        => $avatar ? esc_url_raw( $avatar ) : '',
			);
		}

		return $info;
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
	private function build_slots_from_entry(
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

			$range_start = $this->create_datetime_from_time( $date, (string) $slot['start'], $timezone, false );
			$range_end   = $this->create_datetime_from_time( $date, (string) $slot['end'], $timezone, true );

			if ( ! $range_start || ! $range_end ) {
				continue;
			}

			if ( ! empty( $fixed_start_times ) ) {
				foreach ( $fixed_start_times as $time ) {
					$cursor = $this->create_datetime_from_time( $date, $time, $timezone, false );
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
					$guest_count = $this->count_guests_for_slot( $cursor, $end, $bookings );
					// 貸し切り予約がこの時間帯に重複していれば、残席があっても受付停止する。
					$exclusive_closed = $this->slot_has_exclusive_booking( $cursor, $end, $bookings, $menu_id );
					// スタッフ指名時は、予約済み（人数>0）または貸し切りで閉じた枠をスキップする。
					if ( $skip_booked_slots && ( $guest_count > 0 || $exclusive_closed ) ) {
						continue;
					}

					$service_end = $cursor->modify( sprintf( '+%d minutes', $service_minutes ) );
					$result[]    = array(
						'start'            => $cursor,
						'end'              => $end,
						'service_end'      => $service_end,
						'service_duration' => $service_minutes,
						'guest_count'      => $guest_count,
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
					$guest_count = $this->count_guests_for_slot( $cursor, $end, $bookings );
					// 貸し切り予約がこの時間帯に重複していれば、残席があっても受付停止する。
					$exclusive_closed = $this->slot_has_exclusive_booking( $cursor, $end, $bookings, $menu_id );
					// スタッフ指名時は、予約済み（人数>0）または貸し切りで閉じた枠をスキップする。
					if ( $skip_booked_slots && ( $guest_count > 0 || $exclusive_closed ) ) {
						$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
						continue;
					}

					$service_end = $cursor->modify( sprintf( '+%d minutes', $service_minutes ) );

					$result[] = array(
						'start'            => $cursor,
						'end'              => $end,
						'service_end'      => $service_end,
						'service_duration' => $service_minutes,
						'guest_count'      => $guest_count,
						'exclusive_closed' => $exclusive_closed,
					);

					$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
				}
			}
		}

		return $result;
	}

	/**
	 * スロットと予約が重複しているか判定する。
	 *
	 * @param DateTimeImmutable $start   スロット開始。
	 * @param DateTimeImmutable $end     スロット終了。
	 * @param array             $booking 予約データ（start/end キーを持つ配列）。
	 * @return bool 重複している場合 true。
	 */
	private function is_slot_overlapping( DateTimeImmutable $start, DateTimeImmutable $end, array $booking ): bool {
		return $booking['start'] < $end && $booking['end'] > $start;
	}

	/**
	 * 指定した時間帯に重複する予約の合計予約人数を返す。
	 *
	 * 複数人予約では1予約が人数分の枠を消費するため、予約件数ではなく
	 * 重複する各予約の guests を合計した値（既定1名）を返す。
	 *
	 * @param DateTimeImmutable                                                              $start    候補スロット開始。
	 * @param DateTimeImmutable                                                              $end      候補スロット終了。
	 * @param array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests?:int}> $bookings 予約リスト。
	 * @return int 重複する予約の合計予約人数。
	 */
	public function count_guests_for_slot( DateTimeImmutable $start, DateTimeImmutable $end, array $bookings ): int {
		$count = 0;
		foreach ( $bookings as $booking ) {
			if ( $this->is_slot_overlapping( $start, $end, $booking ) ) {
				// 件数ではなく予約人数を加算する（複数人予約で枠を人数分消費するため）。
				$guests = isset( $booking['guests'] ) ? max( 1, (int) $booking['guests'] ) : 1;
				$count += $guests;
			}
		}
		return $count;
	}

	/**
	 * 指定した時間帯に貸し切り（枠を専有する）予約が1件でも重複しているか判定する。
	 *
	 * 貸し切り予約が入った時間帯は、残席があっても他のユーザーは予約できなくする。
	 * count_guests_for_slot() と同じ overlap 走査を使い回し、空き判定・受付停止を
	 * すべてこのヘルパー経由で統一する。判定対象（status フィルタ済みの予約配列）は
	 * get_bookings_for_staff_date() が返す配列をそのまま渡す想定で、各予約の
	 * exclusive キーを参照する。
	 *
	 * 貸し切りは「当該メニューに入った予約」だけがそのメニューの枠を閉じる仕様のため、
	 * $menu_id を指定した場合は予約の service_id が一致する貸し切り予約のみを対象にする
	 * （メニューAの貸し切り予約がメニューBの枠を閉じないようにする）。$menu_id が 0 の場合は
	 * メニューを問わず判定する（後方互換）。
	 *
	 * @param DateTimeImmutable                                                                                                $start    候補スロット開始。
	 * @param DateTimeImmutable                                                                                                $end      候補スロット終了。
	 * @param array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests?:int, exclusive?:bool, service_id?:int}> $bookings 予約リスト。
	 * @param int                                                                                                              $menu_id  当該メニューID（>0 でそのメニューの貸し切り予約のみ対象。0 で全メニュー対象）。
	 * @return bool 重複する貸し切り予約があれば true。
	 */
	public function slot_has_exclusive_booking( DateTimeImmutable $start, DateTimeImmutable $end, array $bookings, int $menu_id = 0 ): bool {
		foreach ( $bookings as $booking ) {
			if ( empty( $booking['exclusive'] ) ) {
				continue;
			}
			// 当該メニュー限定指定がある場合は、予約のメニュー（service_id）が一致するものだけを対象にする。
			if ( $menu_id > 0 && (int) ( $booking['service_id'] ?? 0 ) !== $menu_id ) {
				continue;
			}
			if ( $this->is_slot_overlapping( $start, $end, $booking ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create DateTimeImmutable from date/time, allowing 24:00 as end-of-day.
	 *
	 * @param string       $date     Date (Y-m-d).
	 * @param string       $time     Time (HH:MM).
	 * @param DateTimeZone $timezone Timezone.
	 * @param bool         $is_end   Whether the time is an end boundary.
	 * @return DateTimeImmutable|null
	 */
	private function create_datetime_from_time( string $date, string $time, DateTimeZone $timezone, bool $is_end ): ?DateTimeImmutable {
		if ( '24:00' === $time ) {
			if ( ! $is_end ) {
				return null;
			}

			$base = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', sprintf( '%s 00:00', $date ), $timezone );
			return $base ? $base->modify( '+1 day' ) : null;
		}

		return DateTimeImmutable::createFromFormat( 'Y-m-d H:i', sprintf( '%s %s', $date, $time ), $timezone );
	}

	/**
	 * 予約の重複を検出する。
	 *
	 * @param DateTimeImmutable                            $start    候補スロット開始。
	 * @param DateTimeImmutable                            $end      候補スロット終了。
	 * @param array<int, array<string, DateTimeImmutable>> $bookings 予約リスト。
	 * @return bool 重複がある場合 true。
	 */
	private function has_booking_conflict( DateTimeImmutable $start, DateTimeImmutable $end, array $bookings ): bool {
		foreach ( $bookings as $booking ) {
			if ( $this->is_slot_overlapping( $start, $end, $booking ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get bookings for a staff member on a date.
	 *
	 * @param int          $staff_id Staff ID.
	 * @param string       $date     Date (Y-m-d).
	 * @param DateTimeZone $timezone Timezone.
	 * @return array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests:int}>
	 */
	private function get_bookings_for_staff_date( int $staff_id, string $date, DateTimeZone $timezone ): array {
		$cache_key = sprintf( '%d-%s', $staff_id, $date );

		if ( isset( $this->booking_cache[ $cache_key ] ) ) {
			return $this->booking_cache[ $cache_key ];
		}

		$start_of_day = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $timezone );
		$end_of_day   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', $timezone );

		if ( ! $start_of_day || ! $end_of_day ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::BOOKING_META_RESOURCE,
						'value'   => $staff_id,
						'compare' => '=',
					),
					array(
						'key'     => self::BOOKING_META_START,
						'value'   => $end_of_day->format( 'Y-m-d H:i:s' ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::BOOKING_META_TOTAL_END,
							'value'   => $start_of_day->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::BOOKING_META_END,
							'value'   => $start_of_day->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		$bookings = array();

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::BOOKING_META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため残数集計から除外する
			// （確定時の負荷集計と同じ方針）。
			if ( 'no_show' === $status || 'cancelled' === $status ) {
				continue;
			}

			$start_raw     = (string) get_post_meta( (int) $post_id, self::BOOKING_META_START, true );
			$total_end_raw = (string) get_post_meta( (int) $post_id, self::BOOKING_META_TOTAL_END, true );
			$end_raw       = '' !== $total_end_raw ? $total_end_raw : (string) get_post_meta( (int) $post_id, self::BOOKING_META_END, true );

			if ( ! $start_raw || ! $end_raw ) {
				continue;
			}

			$start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_raw, $timezone );
			$end_dt   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end_raw, $timezone );

			if ( ! $start_dt || ! $end_dt ) {
				continue;
			}

			// 予約人数（複数人予約。未設定の既存予約は1名として扱う）。
			$guests_raw = get_post_meta( (int) $post_id, self::BOOKING_META_GUESTS, true );
			$guests     = '' === $guests_raw ? 1 : max( 1, (int) $guests_raw );

			// 貸し切り予約フラグ（この予約が枠を専有するか）。
			// 設定ONのメニューで予約確定時に true が付与される。判定経路を統一するため
			// ここで booking 配列に exclusive キーとして持たせ、slot_has_exclusive_booking() で参照する。
			$exclusive = (bool) get_post_meta( (int) $post_id, self::BOOKING_META_EXCLUSIVE, true );

			// 予約のメニューID（サービスID）。貸し切り判定は「当該メニューの貸し切り予約のみ」を
			// 対象にするため、判定時にこの service_id で絞り込む（メニューAの貸し切りが
			// メニューBの枠を閉じないようにする）。
			$service_id = (int) get_post_meta( (int) $post_id, self::BOOKING_META_SERVICE, true );

			$bookings[] = array(
				'start'      => $start_dt,
				'end'        => $end_dt,
				'guests'     => $guests,
				'exclusive'  => $exclusive,
				'service_id' => $service_id,
			);
		}

		$this->booking_cache[ $cache_key ] = $bookings;

		return $bookings;
	}

	/**
	 * Get bookings for a user on a date.
	 *
	 * @param int          $user_id User ID.
	 * @param string       $date    Date (Y-m-d).
	 * @param DateTimeZone $timezone Timezone.
	 * @return array<int, array<string, DateTimeImmutable>>
	 */
	private function get_bookings_for_user_date( int $user_id, string $date, DateTimeZone $timezone ): array {
		$cache_key = sprintf( 'user-%d-%s', $user_id, $date );

		if ( isset( $this->booking_cache[ $cache_key ] ) ) {
			return $this->booking_cache[ $cache_key ];
		}

		$start_of_day = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $timezone );
		$end_of_day   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', $timezone );

		if ( ! $start_of_day || ! $end_of_day ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::BOOKING_META_START,
						'value'   => $end_of_day->format( 'Y-m-d H:i:s' ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::BOOKING_META_TOTAL_END,
							'value'   => $start_of_day->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::BOOKING_META_END,
							'value'   => $start_of_day->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		$bookings = array();

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::BOOKING_META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する
			// （get_bookings_for_staff_date() と同じ集計方針）。
			if ( 'no_show' === $status || 'cancelled' === $status ) {
				continue;
			}

			$start_raw     = (string) get_post_meta( (int) $post_id, self::BOOKING_META_START, true );
			$total_end_raw = (string) get_post_meta( (int) $post_id, self::BOOKING_META_TOTAL_END, true );
			$end_raw       = '' !== $total_end_raw ? $total_end_raw : (string) get_post_meta( (int) $post_id, self::BOOKING_META_END, true );

			if ( ! $start_raw || ! $end_raw ) {
				continue;
			}

			$start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_raw, $timezone );
			$end_dt   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end_raw, $timezone );

			if ( ! $start_dt || ! $end_dt ) {
				continue;
			}

			$bookings[] = array(
				'start' => $start_dt,
				'end'   => $end_dt,
			);
		}

		$this->booking_cache[ $cache_key ] = $bookings;

		return $bookings;
	}

	/**
	 * Retrieve shift entry for a staff/day.
	 *
	 * @param int $staff_id Staff ID.
	 * @param int $year     Year.
	 * @param int $month    Month.
	 * @param int $day      Day.
	 * @return array<string, mixed>
	 */
	private function get_shift_entry( int $staff_id, int $year, int $month, int $day ): array {
		$days = $this->get_shift_days_for_month( $staff_id, $year, $month );

		if ( isset( $days[ $day ] ) && is_array( $days[ $day ] ) ) {
			return $days[ $day ];
		}

		return array();
	}

	/**
	 * Load shift days for staff/month.
	 *
	 * @param int $staff_id Staff ID.
	 * @param int $year     Year.
	 * @param int $month    Month.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_shift_days_for_month( int $staff_id, int $year, int $month ): array {
		$cache_key = sprintf( '%d-%04d-%02d', $staff_id, $year, $month );

		if ( isset( $this->shift_cache[ $cache_key ] ) ) {
			return $this->shift_cache[ $cache_key ];
		}

		$query = new WP_Query(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::SHIFT_META_RESOURCE,
						'value'   => $staff_id,
						'compare' => '=',
					),
					array(
						'key'     => self::SHIFT_META_YEAR,
						'value'   => $year,
						'compare' => '=',
					),
					array(
						'key'     => self::SHIFT_META_MONTH,
						'value'   => $month,
						'compare' => '=',
					),
				),
			)
		);

		$days = array();

		if ( $query->have_posts() ) {
			$post_id = (int) $query->posts[0];
			$raw     = get_post_meta( $post_id, self::SHIFT_META_DAYS, true );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $index => $entry ) {
					$day_number = (int) $index;
					if ( $day_number <= 0 ) {
						continue;
					}
					$days[ $day_number ] = array(
						'status' => (string) ( $entry['status'] ?? self::DAY_STATUS_OPEN ),
						'slots'  => $this->sanitize_slots( $entry['slots'] ?? array() ),
					);
				}
			}
		}

		$this->shift_cache[ $cache_key ] = $days;

		return $days;
	}

	/**
	 * Sanitize slot collection.
	 *
	 * @param array<int, array<string, string>> $slots Slots.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_slots( array $slots ): array {
		$normalized = array();

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
			$end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';

			if ( ! $this->is_valid_time_string( $start ) || ! $this->is_valid_time_string( $end ) ) {
				continue;
			}

			if ( $end <= $start ) {
				continue;
			}

			$normalized[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $normalized;
	}

	/**
	 * Determine if day status is closed.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_closed_status( string $status ): bool {
		return in_array( $status, self::CLOSED_DAY_STATUSES, true );
	}

	/**
	 * Validate HH:MM string.
	 *
	 * @param string $time Time string.
	 * @return bool
	 */
	private function is_valid_time_string( string $time ): bool {
		if ( ! preg_match( '/^(2[0-4]|[01][0-9]):([0-5][0-9])$/', $time ) ) {
			return false;
		}

		return '24:00' === $time || ! str_starts_with( $time, '24:' );
	}

	/**
	 * Get the max capacity for a service menu.
	 *
	 * サービスメニューの最大同時予約人数を取得します。
	 * Pro版で設定されていない場合やFree版ではデフォルト1を返します。
	 *
	 * @param WP_Post $menu_post Menu post object.
	 * @return int
	 */
	public function get_menu_max_capacity( WP_Post $menu_post ): int {
		// 複数人予約機能が無効、または指名機能が有効な場合は1対1予約のため上限を1に固定する。
		// これによりフロントの空き枠生成・確定時の capacity チェックの双方で複数人受付を抑止する。
		if ( ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return 1;
		}

		$meta     = get_post_meta( $menu_post->ID, self::MENU_META_MAX_CAPACITY, true );
		$capacity = '' === $meta ? 1 : (int) $meta;
		return max( 1, $capacity );
	}

	/**
	 * サービスメニューの最小催行人数（グループ開催型）を取得する。
	 *
	 * 同じ時間枠の合計予約人数がこの値に達したら開催確定とみなす表示用の値です。
	 * 0 は「制約なし（催行判定なし）」で、未設定・Free版・指名ON・複数人予約OFF時は 0 を返します。
	 * 最大同時予約人数（get_menu_max_capacity）を上限としてクランプします。
	 *
	 * @param WP_Post $menu_post メニューの投稿オブジェクト。
	 * @return int 最小催行人数（0=制約なし）。
	 */
	public function get_menu_min_capacity( WP_Post $menu_post ): int {
		// 最小催行人数は複数人相乗りの催行判定が前提のため、最大受付数が1（複数人予約OFF・指名ON・Free版）の場合は無効。
		// max_capacity と同じゲートに追従し、1対1予約のメニューでは常に0（制約なし）を返す。
		$max_capacity = $this->get_menu_max_capacity( $menu_post );
		if ( $max_capacity <= 1 ) {
			return 0;
		}

		$meta = get_post_meta( $menu_post->ID, self::MENU_META_MIN_CAPACITY, true );
		$min  = '' === $meta ? 0 : (int) $meta;

		// 0未満は0へ、最大受付数を超える値は最大受付数へクランプする。
		return max( 0, min( $max_capacity, $min ) );
	}

	/**
	 * メニュー単位の予約設定（締切・上限・指名要否など）を解決して返す。
	 *
	 * @param WP_Post $menu_post サービスメニューの投稿オブジェクト。
	 * @return array メニューに適用される設定値の配列。
	 */
	private function get_menu_settings( WP_Post $menu_post ): array {
		$settings              = $this->settings_repository->get_settings();
		$provider_deadline     = isset( $settings['provider_reservation_deadline_hours'] ) ? (int) $settings['provider_reservation_deadline_hours'] : 0;
		$provider_buffer_after = isset( $settings['provider_service_menu_buffer_after_minutes'] ) ? (int) $settings['provider_service_menu_buffer_after_minutes'] : 0;
		$duration              = (int) get_post_meta( $menu_post->ID, self::MENU_META_DURATION, true );
		$buffer_meta           = get_post_meta( $menu_post->ID, self::MENU_META_BUFFER_AFTER, true );
		$buffer_after          = '' === $buffer_meta ? $provider_buffer_after : (int) $buffer_meta;
		$deadline_meta         = get_post_meta( $menu_post->ID, self::MENU_META_DEADLINE_HOURS, true );
		$deadline              = '' === $deadline_meta ? $provider_deadline : (int) $deadline_meta;

		// 予約可能期間（日数）の設定を取得。サービス個別設定があればそちらを優先。
		// Retrieve max advance booking days. Per-service override takes priority.
		$provider_max_advance = isset( $settings['provider_max_advance_booking_days'] ) ? (int) $settings['provider_max_advance_booking_days'] : 0;
		$max_advance_meta     = get_post_meta( $menu_post->ID, self::MENU_META_MAX_ADVANCE_DAYS, true );
		$max_advance_days     = '' === $max_advance_meta ? $provider_max_advance : (int) $max_advance_meta;

		$duration          = $duration > 0 ? $duration : 60;
		$slot_step_minutes = $this->get_slot_step_minutes();
		$total_block       = max( $duration + $buffer_after, $slot_step_minutes );

		// 予約可能日種別は共有ヘルパーで許容値（指定なし・土日限定・平日限定・曜日指定・日付指定）に正規化する。
		$reservation_day_type = Reservation_Day::sanitize_type( (string) get_post_meta( $menu_post->ID, self::MENU_META_RESERVATION_DAY_TYPE, true ) );

		// 曜日指定（頻度 × 曜日）・日付指定（単日・期間）の詳細設定を取得する。
		// 種別が custom_weekday / custom_date のときだけ is_date_allowed_for_menu() で参照される。
		$reservation_custom_weekdays = get_post_meta( $menu_post->ID, self::MENU_META_RESERVATION_CUSTOM_WEEKDAYS, true );
		$reservation_custom_weekdays = is_array( $reservation_custom_weekdays ) ? $reservation_custom_weekdays : array();
		$reservation_custom_dates    = get_post_meta( $menu_post->ID, self::MENU_META_RESERVATION_CUSTOM_DATES, true );
		$reservation_custom_dates    = is_array( $reservation_custom_dates ) ? $reservation_custom_dates : array();

		$fixed_start_times_raw = get_post_meta( $menu_post->ID, self::MENU_META_FIXED_START_TIMES, true );
		$fixed_start_times     = is_array( $fixed_start_times_raw ) ? $fixed_start_times_raw : array();
		// Normalize: filter to valid HH:MM with allowed minutes (10-minute intervals), deduplicate, and sort.
		// / 有効な HH:MM（分は10分刻みのみ）に絞り込み、重複排除・ソートを行う.
		$fixed_start_times = array_values(
			array_unique(
				array_filter(
					$fixed_start_times,
					static function ( $time ) {
						return is_string( $time )
							&& 1 === preg_match( '/^(?:[01]\d|2[0-3]):(?:00|10|20|30|40|50)$/', $time );
					}
				)
			)
		);
		sort( $fixed_start_times, SORT_STRING );

		return array(
			'duration'                    => $duration,
			'total_duration'              => $total_block,
			'deadline_hours'              => max( 0, $deadline ),
			'max_advance_days'            => max( 0, $max_advance_days ),
			'reservation_day_type'        => $reservation_day_type,
			'reservation_custom_weekdays' => $reservation_custom_weekdays,
			'reservation_custom_dates'    => $reservation_custom_dates,
			'fixed_start_times'           => $fixed_start_times,
		);
	}

		/**
		 * Determine if the given date is reservable for the menu day restriction.
		 *
		 * @param array<string, mixed> $menu_settings 予約枠設定（get_menu_settings() の戻り値）。
		 * @param string               $date          Date string (Y-m-d).
		 * @param DateTimeZone         $timezone      Timezone for weekday calculation.
		 * @return bool
		 */
	private function is_date_allowed_for_menu( array $menu_settings, string $date, DateTimeZone $timezone ): bool {
		$reservation_day_type = (string) ( $menu_settings['reservation_day_type'] ?? '' );

		if ( '' === $reservation_day_type ) {
			return true;
		}

		// 月次空き状況は Y-m-d 文字列と DateTimeZone から日付を組み立てる。
		// パース経路は従来どおりこの箇所で保持し、失敗時は許可（true）へフォールバックする。
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $timezone );
		if ( ! $datetime instanceof DateTimeImmutable ) {
			return true;
		}

		// 曜日指定・日付指定は実際の日付が必要なため、共有ヘルパーの日付ベース判定へ委譲する。
		// '' / weekend / weekday は is_date_allowed() 内で従来どおり曜日番号ベースの判定になる。
		return Reservation_Day::is_date_allowed(
			$reservation_day_type,
			$datetime,
			array(
				'weekdays' => is_array( $menu_settings['reservation_custom_weekdays'] ?? null ) ? $menu_settings['reservation_custom_weekdays'] : array(),
				'dates'    => is_array( $menu_settings['reservation_custom_dates'] ?? null ) ? $menu_settings['reservation_custom_dates'] : array(),
			)
		);
	}

	/**
	 * Resolve aggregated day status across staff members.
	 *
	 * @param array<int> $staff_ids Staff IDs.
	 * @param int        $year      Year.
	 * @param int        $month     Month.
	 * @param int        $day       Day.
	 * @return string
	 */
	private function resolve_day_status( array $staff_ids, int $year, int $month, int $day ): string {
		$statuses = array();
		foreach ( $staff_ids as $staff_id ) {
			$entry      = $this->get_shift_entry( $staff_id, $year, $month, $day );
			$statuses[] = (string) ( $entry['status'] ?? self::DAY_STATUS_OPEN );
		}

		$statuses = array_filter( array_unique( $statuses ) );

		if ( empty( $statuses ) ) {
			return self::DAY_STATUS_UNAVAILABLE;
		}

		if ( count( $statuses ) === 1 ) {
			return $statuses[0];
		}

		// Mixed statuses default to open if any open slots exist.
		return in_array( self::DAY_STATUS_OPEN, $statuses, true )
			? self::DAY_STATUS_OPEN
			: $statuses[0];
	}

	/**
	 * Map shift status to calendar label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function map_status_to_calendar_label( string $status ): string {
		switch ( $status ) {
			case self::DAY_STATUS_REGULAR_HOLIDAY:
				return 'holiday';
			case self::DAY_STATUS_TEMP_OPEN:
				return 'special_open';
			case self::DAY_STATUS_TEMP_CLOSED:
				return 'special_close';
			case self::DAY_STATUS_UNAVAILABLE:
				return 'off';
			default:
				return 'normal';
		}
	}

	/**
	 * Build notes array for calendar cell.
	 *
	 * @param string $status Status key.
	 * @param array  $slots  Slots.
	 * @return array<int, string>
	 */
	private function build_day_notes( string $status, array $slots ): array {
		if ( ! empty( $slots ) ) {
			return array();
		}

		if ( self::DAY_STATUS_UNAVAILABLE === $status ) {
			return array( __( 'Shift not registered', 'vk-booking-manager' ) );
		}

		return array();
	}

	/**
	 * Current timestamp in ISO8601 for timezone.
	 *
	 * @param DateTimeZone $timezone Timezone.
	 * @return string
	 */
	private function current_timestamp_iso( DateTimeZone $timezone ): string {
		return ( new DateTimeImmutable( 'now', $timezone ) )->format( DATE_ATOM );
	}
}
