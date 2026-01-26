<?php
/**
 * Provides calculated availability data for menus and staff resources.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Capabilities\Capabilities;
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

	private const MENU_META_DURATION             = '_vkbm_duration_minutes';
	private const MENU_META_BUFFER_AFTER         = '_vkbm_buffer_after_minutes';
	private const MENU_META_DEADLINE_HOURS       = '_vkbm_reservation_deadline_hours';
	private const MENU_META_STAFF_IDS            = '_vkbm_staff_ids';
	private const MENU_META_ARCHIVED             = '_vkbm_is_archived';
	private const MENU_META_ONLINE_DISABLED      = '_vkbm_online_unavailable';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';

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
	 * @var array<string, array<int, array<string, DateTimeImmutable>>>
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

			$results[] = array(
				'date'            => $date,
				'available_slots' => count( $slots ),
				'first_start_at'  => $slots ? $slots[0]['start_at'] : null,
				'shift_status'    => $status,
				'is_holiday'      => $is_holiday,
				'is_disabled'     => empty( $slots ),
				'notes'           => $this->build_day_notes( $status_key, $slots ),
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
		if ( ! $this->is_date_allowed_for_menu( (string) ( $menu_settings['reservation_day_type'] ?? '' ), $date, $timezone ) ) {
			return array();
		}
		$slot_step_minutes = $this->get_slot_step_minutes();
		$total_block_min   = max( $slot_step_minutes, $menu_settings['total_duration'] );
		$service_minutes   = $menu_settings['duration'];
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

			$bookings    = $this->get_bookings_for_staff_date( $staff_id, $date, $timezone );
			$staff_slots = $this->build_slots_from_entry(
				$day_entry['slots'],
				$date,
				$timezone,
				$total_block_min,
				$service_minutes,
				$deadline_cutoff,
				$bookings,
				$slot_step_minutes
			);

			if ( empty( $staff_slots ) ) {
				continue;
			}

			$last_index = count( $staff_slots ) - 1;
			foreach ( $staff_slots as $index => $slot ) {
				$all_slots[] = array(
					'slot_id'          => sprintf( '%d-%s', $staff_id, gmdate( 'YmdHis', $slot['start']->getTimestamp() ) ),
					'start_at'         => $slot['start']->format( DATE_ATOM ),
					'end_at'           => $slot['end']->format( DATE_ATOM ),
					'service_end_at'   => $slot['service_end']->format( DATE_ATOM ),
					'duration_minutes' => $service_minutes,
					'staff'            => $staff_info[ $staff_id ],
					'capacity'         => 1,
					'remaining'        => 1,
					'flags'            => array(
						'is_last_slot_of_day'   => ( $index === $last_index ),
						'requires_confirmation' => false,
					),
					'auto_assign'      => ! $is_staff_preferred,
				);
			}
		}

		if ( ! $is_staff_preferred ) {
			return $this->collapse_slots_for_auto_assignment( $all_slots, (int) $menu_post->ID );
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
	 * @param array<int, array<string, mixed>> $slots   Slots.
	 * @param int                              $menu_id Menu ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function collapse_slots_for_auto_assignment( array $slots, int $menu_id ): array {
		if ( empty( $slots ) ) {
			return array();
		}

		$grouped = array();

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
					'staff_label'          => __( 'No preference', 'vk-booking-manager' ),
					'assignable_staff_ids' => array(),
					'capacity'             => 1,
					'remaining'            => 1,
					'flags'                => array(
						'is_last_slot_of_day'   => ! empty( $slot['flags']['is_last_slot_of_day'] ),
						'requires_confirmation' => ! empty( $slot['flags']['requires_confirmation'] ),
					),
					'auto_assign'          => true,
				);
			} else {
				$grouped[ $key ]['flags']['is_last_slot_of_day']   = $grouped[ $key ]['flags']['is_last_slot_of_day'] || ! empty( $slot['flags']['is_last_slot_of_day'] );
				$grouped[ $key ]['flags']['requires_confirmation'] = $grouped[ $key ]['flags']['requires_confirmation'] || ! empty( $slot['flags']['requires_confirmation'] );
			}

			$staff_id = isset( $slot['staff']['id'] ) ? (int) $slot['staff']['id'] : 0;
			if ( $staff_id > 0 ) {
				$grouped[ $key ]['assignable_staff_ids'][] = $staff_id;
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
				$grouped[ $key ]['capacity']             = max( 1, count( $unique ) );
				$grouped[ $key ]['remaining']            = $grouped[ $key ]['capacity'];
			} else {
				$grouped[ $key ]['assignable_staff_ids'] = array();
			}
		}

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

			$info[ $staff_id ] = array(
				'id'     => $staff_id,
				'name'   => get_the_title( $staff_id ),
				'avatar' => $avatar ? esc_url_raw( $avatar ) : '',
			);
		}

		return $info;
	}

	/**
	 * Build slot collection from shift entry.
	 *
	 * @param array<int, array<string, string>>            $slots           Shift slots.
	 * @param string                                       $date            Date (Y-m-d).
	 * @param DateTimeZone                                 $timezone        Timezone.
	 * @param int                                          $block_minutes   Slot length including buffers.
	 * @param int                                          $service_minutes Pure service minutes.
	 * @param DateTimeImmutable|null                       $deadline_cutoff Deadline cutoff.
	 * @param array<int, array<string, DateTimeImmutable>> $bookings Existing bookings.
	 * @param int                                          $slot_step_minutes Slot step in minutes.
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
		int $slot_step_minutes
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

				if ( $this->has_booking_conflict( $cursor, $end, $bookings ) ) {
					$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
					continue;
				}

				$service_end = $cursor->modify( sprintf( '+%d minutes', $service_minutes ) );

				$result[] = array(
					'start'            => $cursor,
					'end'              => $end,
					'service_end'      => $service_end,
					'service_duration' => $service_minutes,
				);

				$cursor = $cursor->modify( sprintf( '+%d minutes', $slot_step_minutes ) );
			}
		}

		return $result;
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
	 * Detect booking overlap.
	 *
	 * @param DateTimeImmutable                            $start    Candidate start.
	 * @param DateTimeImmutable                            $end      Candidate end.
	 * @param array<int, array<string, DateTimeImmutable>> $bookings Booking list.
	 * @return bool
	 */
	private function has_booking_conflict( DateTimeImmutable $start, DateTimeImmutable $end, array $bookings ): bool {
		foreach ( $bookings as $booking ) {
			if ( $start < $booking['end'] && $end > $booking['start'] ) {
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
	 * @return array<int, array<string, DateTimeImmutable>>
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
			if ( 'no_show' === $status ) {
				continue;
			}

			$start_raw     = (string) get_post_meta( (int) $post_id, self::BOOKING_META_START, true );
			$total_end_raw = (string) get_post_meta( (int) $post_id, self::BOOKING_META_TOTAL_END, true );
			$end_raw = '' !== $total_end_raw ? $total_end_raw : (string) get_post_meta( (int) $post_id, self::BOOKING_META_END, true );

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
			if ( 'no_show' === $status ) {
				continue;
			}

			$start_raw     = (string) get_post_meta( (int) $post_id, self::BOOKING_META_START, true );
			$total_end_raw = (string) get_post_meta( (int) $post_id, self::BOOKING_META_TOTAL_END, true );
			$end_raw = '' !== $total_end_raw ? $total_end_raw : (string) get_post_meta( (int) $post_id, self::BOOKING_META_END, true );

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
	 * Get duration/buffer settings for a menu.
	 *
	 * @param WP_Post $menu_post Menu post.
	 * @return array<string, int>
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

		$duration          = $duration > 0 ? $duration : 60;
		$slot_step_minutes = $this->get_slot_step_minutes();
		$total_block       = max( $duration + $buffer_after, $slot_step_minutes );

		$reservation_day_type = (string) get_post_meta( $menu_post->ID, self::MENU_META_RESERVATION_DAY_TYPE, true );
		$allowed_day_types    = array( '', 'weekend', 'weekday' );
		if ( ! in_array( $reservation_day_type, $allowed_day_types, true ) ) {
			$reservation_day_type = '';
		}

		return array(
			'duration'             => $duration,
			'total_duration'       => $total_block,
			'deadline_hours'       => max( 0, $deadline ),
			'reservation_day_type' => $reservation_day_type,
		);
	}

		/**
		 * Determine if the given date is reservable for the menu day restriction.
		 *
		 * @param string       $reservation_day_type Restriction type (''|weekend|weekday).
		 * @param string       $date                 Date string (Y-m-d).
		 * @param DateTimeZone $timezone             Timezone for weekday calculation.
		 * @return bool
		 */
	private function is_date_allowed_for_menu( string $reservation_day_type, string $date, DateTimeZone $timezone ): bool {
		if ( '' === $reservation_day_type ) {
			return true;
		}

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $timezone );
		if ( ! $datetime instanceof DateTimeImmutable ) {
			return true;
		}

		$weekday    = (int) $datetime->format( 'N' ); // 1 (Mon) - 7 (Sun).
		$is_weekend = ( 6 === $weekday || 7 === $weekday );

		if ( 'weekend' === $reservation_day_type ) {
			return $is_weekend;
		}

		if ( 'weekday' === $reservation_day_type ) {
			return ! $is_weekend;
		}

		return true;
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
