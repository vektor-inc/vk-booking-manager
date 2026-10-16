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
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Booking_Datetime;
use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\Common\Lodging_Price_Display;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\Resource_Tag_Id_List;
use VKBookingManager\Common\Staff_Load_Calculator;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;
use WP_Query;
use WP_Error;
use function __;
use function array_filter;
use function array_intersect;
use function array_map;
use function array_unique;
use function array_values;
use function esc_url_raw;
use function current_datetime;
use function current_user_can;
use function get_current_user_id;
use function is_user_logged_in;
use function vkbm_get_resource_label_plural;

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
	private const MENU_META_ARCHIVED             = '_vkbm_is_archived';
	private const MENU_META_ONLINE_DISABLED      = '_vkbm_online_unavailable';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	// メタキーの定義元は Reservation_Day（single source of truth）。
	private const MENU_META_RESERVATION_CUSTOM_WEEKDAYS = Reservation_Day::META_CUSTOM_WEEKDAYS;
	private const MENU_META_RESERVATION_CUSTOM_DATES    = Reservation_Day::META_CUSTOM_DATES;
	private const MENU_META_FIXED_START_TIMES           = '_vkbm_fixed_start_times';
	private const MENU_META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const MENU_META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const MENU_META_ALLOW_MULTIPLE_GUESTS       = '_vkbm_allow_multiple_guests';
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
	 * 月次カレンダーの計算中だけ保持する、月全体の予約とスタッフ情報。
	 *
	 * 計算の終わりで必ず null に戻す（予約確定などで古い値を使い続けないため）。
	 * 計算中でなければ null。
	 *
	 * @var array{bookings: array<int, array<string, mixed>>, snapshots: array<string, array<int, array<string, mixed>>>}|null
	 */
	private ?array $month_scope = null;

	/**
	 * 時間枠戦略。未生成の場合は null（resolve_time_slot_strategy() が遅延生成する）。
	 *
	 * @var Time_Slot_Strategy|null
	 */
	private ?Time_Slot_Strategy $time_slot_strategy = null;

	/**
	 * 泊戦略。未生成の場合は null（resolve_night_strategy() が遅延生成する）。
	 *
	 * @var Night_Strategy|null
	 */
	private ?Night_Strategy $night_strategy = null;

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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @return int Slot step in minutes.
	 */
	public function get_slot_step_minutes(): int {
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

		$preferred_staff_id = $this->resolve_preferred_staff_id( $args );
		// #431: リソースタグ絞り込み（ターム ID・AND条件）。
		$tag_ids = $this->normalize_tag_ids( $args['resource_tag_ids'] ?? array() );

		$staff_ids = $this->resolve_staff_ids( $menu, $preferred_staff_id, $tag_ids );
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

		$timezone = $this->resolve_timezone();

		// 枠生成・キャッシュキーへ渡す「スタッフを指名している」状態は、resource_id の
		// 有無（$preferred_staff_id > 0）とは別に判定する（安藤さんレビュー・HIGH是正）。
		// 無料版は担当が常に基本スタッフ1名固定で、そのスタッフに対する予約は常に
		// 「指名あり」と同じ扱い（skip_booked_slots・枠の非集約）で予約済み枠を除外しないと、
		// origin/main と比べて予約済みの時間帯が「押せる枠」として表示されてしまう
		// （$preferred_staff_id 自体は resolve_preferred_staff_id() により無料版では常に0へ
		// 正規化されるため、resource_id の有無で結果が変わることは無い＝CodeRabbit指摘は解消済み）。
		$is_staff_preferred = ! Staff_Editor::is_enabled() || $preferred_staff_id > 0;

		$cache_key = $this->build_cache_key(
			'calendar',
			$menu->ID,
			$staff_ids,
			sprintf( '%04d-%02d', $year, $month ),
			$is_staff_preferred,
			$tag_ids
		);

		$cached = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$days_in_month = $this->days_in_month( $year, $month );
		// #655: 「今日」は日ごとに求め直さず、ループの前に1回だけ求める。
		$today = $this->get_today_date( $timezone );

		// #705: 日ごとに予約・スタッフ情報を問い合わせないよう、月全体の予約を担当者ごとに先にまとめて読む。
		// 保持するのはこの計算の間だけ（finally で必ず破棄する）。
		$this->month_scope = array(
			'bookings'  => array(),
			'snapshots' => array(),
		);

		try {
			// 月の最終日より前に「今日」が来る（全日が過去）なら、予約は使わないので読まない。
			$last_date_of_month = sprintf( '%04d-%02d-%02d', $year, $month, $days_in_month );
			if ( '' === $today || $last_date_of_month >= $today ) {
				foreach ( $staff_ids as $staff_id ) {
					$this->prime_bookings_for_staff_month( (int) $staff_id, $year, $month, $timezone );
				}
			}

			$results = $this->build_calendar_days( $menu, $staff_ids, $year, $month, $days_in_month, $today, $timezone, $is_staff_preferred );
		} finally {
			$this->month_scope = null;
		}

		$payload = array(
			'year'  => $year,
			'month' => $month,
			'days'  => $results,
			'meta'  => array(
				'menu_id'      => $menu->ID,
				// $preferred_staff_id は resolve_preferred_staff_id() で正規化済みの値（無料版は常に0）。
				// レスポンスへ raw な $args['resource_id'] をそのまま返すと、無料版で resource_id の
				// 有無だけでレスポンスが変わってしまう（CodeRabbit 指摘、PR #466）。
				'resource_id'  => $preferred_staff_id > 0 ? $preferred_staff_id : null,
				'timezone'     => $timezone->getName(),
				'generated_at' => $this->current_timestamp_iso( $timezone ),
			),
		);

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

		return $payload;
	}

	/**
	 * 日付文字列が、実在する Y-m-d（年は 2000〜2100）かを返す。
	 *
	 * get_calendar_meta() の年の範囲と同じ。日付の検証はここに集約する。
	 *
	 * @param string $date 日付文字列。
	 * @return bool 有効な日付なら true。
	 */
	public static function is_valid_date_string( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return false;
		}

		$year = (int) $parts[1];

		return $year >= 2000 && $year <= 2100 && checkdate( (int) $parts[2], (int) $parts[3], $year );
	}

	/**
	 * 指定した日に、メニューが予約画面のカレンダーで選べる日かを返す（絞り込み検索の日付指定用）。
	 *
	 * 判定は get_calendar_meta()（build_calendar_days()）の1日分と同じ基準にそろえる。
	 * つまり今日より前の日は不可、実際に予約可能な時間枠が1つ以上あれば可。
	 * メニューが予約できない状態（非公開・アーカイブ済み・オンライン予約不可）、
	 * 担当リソースが解決できない場合も不可として扱う。
	 *
	 * @param int                  $menu_id メニュー投稿 ID。
	 * @param string               $date    対象日（Y-m-d）。
	 * @param array<string, mixed> $args    resource_id（指名リソース ID）、resource_tag_ids（リソースタグのターム ID 配列）。
	 * @return bool 予約画面のカレンダーで選べる日なら true。
	 */
	public function is_date_bookable_for_menu( int $menu_id, string $date, array $args = array() ): bool {
		// 日付の形式・範囲が不正なら不可。
		if ( ! self::is_valid_date_string( $date ) ) {
			return false;
		}

		$menu = $this->validate_menu( $menu_id );
		if ( is_wp_error( $menu ) ) {
			return false;
		}

		// 担当リソースの解決（無料版の基本スタッフ固定・リソースタグの絞り込みを含む）は、カレンダーと同じ処理を通す。
		$preferred_staff_id = $this->resolve_preferred_staff_id( $args );
		$tag_ids            = $this->normalize_tag_ids( $args['resource_tag_ids'] ?? array() );
		$staff_ids          = $this->resolve_staff_ids( $menu, $preferred_staff_id, $tag_ids );
		if ( is_wp_error( $staff_ids ) ) {
			return false;
		}

		// 今日より前の日は受付不可（カレンダーの #655 と同じ扱い）。
		$timezone = $this->resolve_timezone();
		$today    = $this->get_today_date( $timezone );
		if ( '' !== $today && $date < $today ) {
			return false;
		}

		$is_staff_preferred = ! Staff_Editor::is_enabled() || $preferred_staff_id > 0;
		$slots              = $this->generate_slots_for_date( $menu, $staff_ids, $date, $timezone, $is_staff_preferred );

		// 貸し切り予約で閉じておらず、残席がある枠だけを予約可能として扱う（カレンダーと同じ）。
		foreach ( $slots as $slot ) {
			if ( empty( $slot['exclusive_closed'] ) && (int) ( $slot['remaining'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 月の日ごとの空き状況（カレンダーの1日分ずつ）を組み立てる。
	 *
	 * @param WP_Post      $menu               メニュー投稿。
	 * @param array<int>   $staff_ids          対象スタッフ ID。
	 * @param int          $year               年。
	 * @param int          $month              月。
	 * @param int          $days_in_month      その月の日数。
	 * @param string       $today              今日の日付（Y-m-d。求められなければ空文字）。
	 * @param DateTimeZone $timezone           タイムゾーン。
	 * @param bool         $is_staff_preferred スタッフを指名している状態か。
	 * @return array<int, array<string, mixed>> 日ごとの空き状況。
	 */
	private function build_calendar_days( WP_Post $menu, array $staff_ids, int $year, int $month, int $days_in_month, string $today, DateTimeZone $timezone, bool $is_staff_preferred ): array {
		$results = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

			// #655: 今日より前の日は枠生成をスキップし、受付不可として返す。
			// shift_status は既存の「受付なし」表示（ラベルなし）と同じ off。休業日ではないので is_holiday は false。
			if ( '' !== $today && $date < $today ) {
				$results[] = array(
					'date'            => $date,
					'available_slots' => 0,
					'first_start_at'  => null,
					'shift_status'    => 'off',
					'is_holiday'      => false,
					'is_disabled'     => true,
					'notes'           => array(),
				);
				continue;
			}

			$slots      = $this->generate_slots_for_date( $menu, $staff_ids, $date, $timezone, $is_staff_preferred );
			$status_key = $this->resolve_day_status( $staff_ids, $year, $month, $day );
			$status     = $this->map_status_to_calendar_label( $status_key );
			$is_holiday = in_array( $status, array( 'holiday', 'off', 'special_close' ), true );

			// 貸し切り予約で閉じておらず、残席がある枠だけをカレンダーの「空き枠」として数える。
			// 日別一覧では $slots をそのまま返すため、満枠のラベル表示は維持される。
			$bookable_slots = array_values(
				array_filter(
					$slots,
					static function ( array $slot ): bool {
						return empty( $slot['exclusive_closed'] ) && (int) ( $slot['remaining'] ?? 0 ) > 0;
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

		return $results;
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

		$timezone = $this->resolve_timezone();

		$preferred_staff_id = $this->resolve_preferred_staff_id( $args );
		// #431: リソースタグ絞り込み（ターム ID・AND条件）。
		$tag_ids = $this->normalize_tag_ids( $args['resource_tag_ids'] ?? array() );

		$staff_ids = $this->resolve_staff_ids( $menu, $preferred_staff_id, $tag_ids );
		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		// 枠生成・キャッシュキーへ渡す「スタッフを指名している」状態は、resource_id の
		// 有無（$preferred_staff_id > 0）とは別に判定する（安藤さんレビュー・HIGH是正）。
		// 無料版は担当が常に基本スタッフ1名固定で、そのスタッフに対する予約は常に
		// 「指名あり」と同じ扱い（skip_booked_slots・枠の非集約）で予約済み枠を除外しないと、
		// origin/main と比べて予約済みの時間帯が「押せる枠」として表示されてしまう
		// （$preferred_staff_id 自体は resolve_preferred_staff_id() により無料版では常に0へ
		// 正規化されるため、resource_id の有無で結果が変わることは無い＝CodeRabbit指摘は解消済み）。
		$is_staff_preferred = ! Staff_Editor::is_enabled() || $preferred_staff_id > 0;

		$current_user_id   = get_current_user_id();
		$apply_user_filter = $current_user_id > 0 && ! current_user_can( Capabilities::MANAGE_RESERVATIONS );
		if ( ! $apply_user_filter ) {
			$cache_key = $this->build_cache_key(
				'daily',
				$menu->ID,
				$staff_ids,
				$date->format( 'Y-m-d' ),
				$is_staff_preferred,
				$tag_ids
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
			$is_staff_preferred
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
				// $preferred_staff_id は resolve_preferred_staff_id() で正規化済みの値（無料版は常に0）。
				// レスポンスへ raw な $args['resource_id'] をそのまま返すと、無料版で resource_id の
				// 有無だけでレスポンスが変わってしまう（CodeRabbit 指摘、PR #466）。
				'resource_id'  => $preferred_staff_id > 0 ? $preferred_staff_id : null,
			),
		);

		if ( ! $apply_user_filter ) {
			set_transient( $cache_key, $payload, MINUTE_IN_SECONDS );
		}

		return $payload;
	}

	/**
	 * #411: 表示中の月に予約可能な日が1件も無い場合、管理者・サイトオーナー・事業者
	 * 向けに「なぜ予約できないのか」の診断理由を1件だけ返す。
	 *
	 * 権限判定はこのメソッドの呼び出し側（REST コントローラー）で行う。このメソッド自体は
	 * 権限に関わらず詳細な理由を返すため、呼び出し側は必ず
	 * current_user_can( Capabilities::MANAGE_SYSTEM_SETTINGS ) を通過したユーザーにのみ
	 * 結果をレスポンスへ含めること（非該当者にはレスポンスから理由フィールドごと省略する）。
	 *
	 * 優先順位（数値が小さいほど優先。複数該当しても1件だけ返す）は次のとおり。
	 * ただし実際の評価順は「P1/P4 → P3 → P5 → P6 → P7 → P2 → P8 → P9」（下記実装を参照）。
	 * P1（担当スタッフ0件）と P4（指名スタッフとメニューの担当設定が不一致）は
	 * どちらも resolve_staff_ids() の結果からしか判定できないため、コード上はP3より先に
	 * まとめて評価する。P4がP3より先に評価される実害は「非公開メニューかつ誤った指名
	 * スタッフ指定」という稀な組み合わせに限られるため、この順序のまま許容している
	 * （司・植草さん確認済み）。
	 * P1 担当スタッフ0件 → P4 指名スタッフとメニューの担当設定が不一致 → P3 メニューが非公開等 →
	 * P5 予約可能日設定に表示中の月が一致しない → P6 予約受付上限日数が短い →
	 * P7 定休日・臨時休業で全休 → P2 勤務はあるが所要時間に足りない → P8 予約受付締切が長すぎる →
	 * P9 貸切予約で全枠閉鎖。
	 *
	 * @param array<string, mixed> $args 引数（menu_id, resource_id, year, month, timezone）。
	 * @return array{code:string, message:string}|null 予約可能日が無い場合のみ理由を返す。
	 */
	public function get_unavailability_reason( array $args ): ?array {
		$menu_id = (int) ( $args['menu_id'] ?? 0 );
		$post    = get_post( $menu_id );

		if ( ! $post instanceof WP_Post || 'vkbm_service_menu' !== $post->post_type ) {
			return null;
		}

		$preferred_staff_id = $this->resolve_preferred_staff_id( $args );
		// #431: リソースタグ絞り込み（ターム ID・AND条件）。診断も同じ候補集合で判定する。
		$tag_ids = $this->normalize_tag_ids( $args['resource_tag_ids'] ?? array() );

		// P1 / P4: 担当スタッフの解決。メニューの公開状態より根本原因として優先判定するため、
		// validate_menu() より先に判定する（validate_menu() は最初に該当したエラーで早期returnし、
		// 他の問題を隠してしまうため、診断では判定順序を意図的に入れ替えている）。
		$staff_ids = $this->resolve_staff_ids( $post, $preferred_staff_id, $tag_ids );
		if ( is_wp_error( $staff_ids ) ) {
			$error_code = $staff_ids->get_error_code();

			if ( 'staff_not_configured' === $error_code ) {
				// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
				// join_sentences()（#411 植草さんレビュー指摘: ハードコードの半角スペースで
				// 連結すると、句点で終わる日本語文のあいだに不要な隙間ができるため、
				// 文末が非ASCIIかどうかで区切り方を切り替える）。
				$message = Nomination_Min_Guests_Message::join_sentences(
					__( 'This menu does not have any staff members assigned to it.', 'vk-booking-manager' ),
					__( 'Assign at least one staff member in the "In-charge staff" field on the service menu edit screen.', 'vk-booking-manager' )
				);

				return $this->build_diagnostic_reason( 'staff_not_configured', $message );
			}

			if ( 'staff_not_assigned' === $error_code ) {
				// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
				// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
				$message = Nomination_Min_Guests_Message::join_sentences(
					__( 'The specified staff member is not set as in charge of this menu.', 'vk-booking-manager' ),
					__( 'Check the "In-charge staff" setting on the service menu edit screen.', 'vk-booking-manager' )
				);

				return $this->build_diagnostic_reason( 'staff_not_assigned', $message );
			}

			if ( 'resource_tag_no_match' === $error_code ) {
				// #431: 選択されたリソースタグ（すべて）を持つ担当が1人もいない。
				// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
				// このファイルの他の分岐と同じ join_sentences() を使う（半角スペース等の
				// ハードコードを避け、結合方法を1箇所にまとめるため）。
				$message = Nomination_Min_Guests_Message::join_sentences(
					__( 'No one in charge matches all of the selected resource tags.', 'vk-booking-manager' ),
					__( 'Assign the resource tag to at least one staff member, or change the selected tags.', 'vk-booking-manager' )
				);

				return $this->build_diagnostic_reason( 'resource_tag_no_match', $message );
			}

			// 未知のエラーコードは診断対象外（バナーを出さない）。
			return null;
		}

		// P3: メニュー自体がオンライン予約対象外（非公開／アーカイブ／オンライン掲載オフ）。
		// validate_menu() を再利用せず個別に判定し、3つの条件をまとめて1つの理由として返す。
		$is_menu_not_bookable = ( 'publish' !== $post->post_status && ! $this->can_book_private_menu() )
			|| '1' === get_post_meta( $post->ID, self::MENU_META_ARCHIVED, true )
			|| '1' === get_post_meta( $post->ID, self::MENU_META_ONLINE_DISABLED, true );

		if ( $is_menu_not_bookable ) {
			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'This menu is currently not eligible for online reservations (not published / archived / hidden from online listing).', 'vk-booking-manager' ),
				__( 'Check the publication settings on the service menu edit screen.', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'menu_not_bookable', $message );
		}

		$year  = (int) ( $args['year'] ?? 0 );
		$month = (int) ( $args['month'] ?? 0 );
		if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
			return null;
		}

		$timezone      = $this->resolve_timezone();
		$menu_settings = $this->get_menu_settings( $post );
		$days_in_month = $this->days_in_month( $year, $month );

		// #655: 過去日は予約できないため診断の分析対象から外す。今日以降の日が表示月に1日も無ければ診断しない。
		$today = $this->get_today_date( $timezone );
		if ( '' !== $today && sprintf( '%04d-%02d-%02d', $year, $month, $days_in_month ) < $today ) {
			return null;
		}

		$any_date_allowed                 = false;
		$any_within_advance               = false;
		$any_day_open                     = false;
		$any_slots_ignoring_deadline      = false;
		$any_slots_after_deadline         = false;
		$any_bookable_excluding_exclusive = false;

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

			if ( '' !== $today && $date < $today ) {
				continue;
			}

			$date_allowed = $this->is_date_allowed_for_menu( $menu_settings, $date, $timezone );
			if ( $date_allowed ) {
				$any_date_allowed = true;
			}

			$within_advance = $this->is_within_max_advance_days( $menu_settings, $date, $timezone );
			if ( $within_advance ) {
				$any_within_advance = true;
			}

			if ( ! $date_allowed || ! $within_advance ) {
				// P5 / P6 のいずれかで既に予約不可な日は、シフト側の分析をしても無意味なためスキップする。
				continue;
			}

			$day_status = $this->resolve_day_status( $staff_ids, $year, $month, $day );
			if ( $this->is_closed_status( $day_status ) ) {
				// P7: 定休日・臨時休業。
				continue;
			}
			$any_day_open = true;

			list( $slots_ignoring_deadline, $slots_after_deadline ) = $this->build_diagnostic_slots_for_day(
				$post,
				$staff_ids,
				$date,
				$timezone,
				$menu_settings
			);

			if ( ! empty( $slots_ignoring_deadline ) ) {
				$any_slots_ignoring_deadline = true;
			}

			if ( ! empty( $slots_after_deadline ) ) {
				$any_slots_after_deadline = true;

				$bookable = array_filter(
					$slots_after_deadline,
					static function ( array $slot ): bool {
						return empty( $slot['exclusive_closed'] );
					}
				);

				if ( ! empty( $bookable ) ) {
					// 予約可能な枠が1件でも見つかった時点で、診断は不要（早期終了）。
					$any_bookable_excluding_exclusive = true;
					break;
				}
			}
		}

		if ( $any_bookable_excluding_exclusive ) {
			return null;
		}

		if ( ! $any_date_allowed ) {
			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'There are no days in the displayed month that match this menu\'s "reservable days" setting (day of week / specific dates).', 'vk-booking-manager' ),
				__( 'Check the setting on the service menu edit screen.', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'reservation_day_mismatch', $message );
		}

		if ( ! $any_within_advance ) {
			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'Reservations are only accepted up to a certain number of days from today, and the displayed month is outside that range.', 'vk-booking-manager' ),
				__( 'Check the "Maximum advance booking days" setting in the general settings (or this menu\'s individual setting).', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'max_advance_days_exceeded', $message );
		}

		if ( ! $any_day_open ) {
			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'There are no business days in the displayed month for the in-charge staff members, due to their closed-day settings (regular holidays / temporary closures).', 'vk-booking-manager' ),
				__( 'Check the shift settings in staff management.', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'all_days_closed', $message );
		}

		if ( ! $any_slots_ignoring_deadline ) {
			// #411 植草さんレビュー指摘: シフト未登録月（当メソッドの評価上はこのケースも
			// ここに含まれる）でも指す先が変わらないため、理由コードは分割せず文言のみで対応する。
			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'The required working hours for this menu\'s duration (including the buffer before/after the service) have not been secured for the in-charge staff members.', 'vk-booking-manager' ),
				__( 'Register or extend the working hours for the target month in the staff shift settings, or review the required time setting on the menu edit screen.', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'shift_too_short_for_duration', $message );
		}

		if ( ! $any_slots_after_deadline ) {
			// #655: 締切が0（締切なし）のときの「枠が無い」原因は締切ではない（今日の枠がすべて過ぎた等）。
			// 誤って「締切が長すぎる」と案内しないよう、診断は出さない。
			if ( (int) $menu_settings['deadline_hours'] <= 0 ) {
				return null;
			}

			// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
			// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
			$message = Nomination_Min_Guests_Message::join_sentences(
				__( 'Because of the reservation deadline setting, all time slots for nearby dates have already closed for booking.', 'vk-booking-manager' ),
				__( 'Check the "Reservation deadline" setting in the general settings (or this menu\'s individual setting).', 'vk-booking-manager' )
			);

			return $this->build_diagnostic_reason( 'deadline_hours_too_long', $message );
		}

		// ここまで到達した場合、生成されたスロットは全て貸し切り予約により閉鎖されている。
		// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
		// join_sentences()（#411 植草さんレビュー指摘。理由は上記コメント参照）。
		$message = Nomination_Min_Guests_Message::join_sentences(
			__( 'All time slots on the remaining days are closed because of another reservation (an exclusive booking).', 'vk-booking-manager' ),
			__( 'This is not a configuration issue -- check the reservation status for those days in the reservations screen.', 'vk-booking-manager' )
		);

		return $this->build_diagnostic_reason( 'exclusive_closed', $message );
	}

	/**
	 * 指定した年月の日数を取得する。
	 *
	 * `wp_date( 't', gmmktime( 0, 0, 0, $month, 1, $year ) )` は、サイトTZがUTCより
	 * マイナス側の場合に前月の日数を返しうる（#411 安藤さんレビュー指摘）。
	 * DateTimeImmutable はタイムゾーンを持たず年月日のみで構築するため、この問題が起きない。
	 *
	 * @param int $year  年。
	 * @param int $month 月（1-12）。
	 * @return int 指定月の日数。
	 */
	private function days_in_month( int $year, int $month ): int {
		return (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );
	}

	/**
	 * 診断理由の配列を組み立てる。
	 *
	 * @param string $code    理由コード。
	 * @param string $message 理由本文（管理者向けプレフィックスは呼び出し側／フロントで付与する）。
	 * @return array{code:string, message:string}
	 */
	private function build_diagnostic_reason( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * 指定日が予約受付上限日数（max_advance_days）の範囲内かどうかを判定する。
	 *
	 * generate_slots_for_date() 内のインライン判定と同じロジックを共有ヘルパー化したもの。
	 *
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param array<string, mixed> $menu_settings get_menu_settings() の戻り値。
	 * @param string               $date          日付（Y-m-d）。
	 * @param DateTimeZone         $timezone      タイムゾーン。
	 * @return bool 範囲内（または上限設定なし）の場合 true。
	 */
	public function is_within_max_advance_days( array $menu_settings, string $date, DateTimeZone $timezone ): bool {
		if ( $menu_settings['max_advance_days'] <= 0 ) {
			return true;
		}

		$now = current_datetime();
		if ( ! $now instanceof DateTimeImmutable ) {
			return true;
		}

		$max_date = $now->setTimezone( $timezone )->modify( sprintf( '+%d days', $menu_settings['max_advance_days'] ) );
		$target   = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $timezone );
		if ( ! $target instanceof DateTimeImmutable ) {
			return true;
		}

		return $target->format( 'Y-m-d' ) <= $max_date->format( 'Y-m-d' );
	}

	/**
	 * 予約を受け付ける最も早い日時（受付下限）を返す。
	 *
	 * 予約受付締切が 0（締切なし）のときは現在時刻、N 時間のときは現在時刻 + N 時間。
	 * 締切なしでも現在時刻より前の枠を予約可能として返さないために、常に下限を持つ（#655）。
	 * 現在時刻はサイトの時計（current_datetime()）で取り、比較用に要求タイムゾーンへ変換する。
	 *
	 * @param array<string, mixed> $menu_settings get_menu_settings() の戻り値。
	 * @param DateTimeZone         $timezone      比較に使うタイムゾーン。
	 * @return DateTimeImmutable|null 取得できない場合は null。
	 */
	public function resolve_booking_cutoff( array $menu_settings, DateTimeZone $timezone ): ?DateTimeImmutable {
		$now = current_datetime();
		if ( ! $now instanceof DateTimeImmutable ) {
			return null;
		}

		$deadline_hours = max( 0, (int) ( $menu_settings['deadline_hours'] ?? 0 ) );

		return $now->modify( sprintf( '+%d hours', $deadline_hours ) )->setTimezone( $timezone );
	}

	/**
	 * 今日の日付（Y-m-d）をサイトの時計・要求タイムゾーン基準で返す。
	 *
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return string 取得できない場合は空文字。
	 */
	private function get_today_date( DateTimeZone $timezone ): string {
		$now = current_datetime();
		if ( ! $now instanceof DateTimeImmutable ) {
			return '';
		}

		return $now->setTimezone( $timezone )->format( 'Y-m-d' );
	}

	/**
	 * 枠の開始時刻が、メニューの受付下限（現在時刻 + 締切時間）より前かを返す。
	 *
	 * 確定前の再検証で、キャッシュ済みの空き枠を通さずに下限と直接比べるために使う（#655）。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param string $start_at 枠の開始日時（ISO8601）。
	 * @return bool 下限より前なら true。判定できない場合は false。
	 */
	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		$menu = get_post( $menu_id );
		if ( ! $menu instanceof WP_Post ) {
			return false;
		}

		try {
			$start = new DateTimeImmutable( $start_at );
		} catch ( Exception $e ) {
			return false;
		}

		$cutoff = $this->resolve_booking_cutoff( $this->get_menu_settings( $menu ), $start->getTimezone() );

		return $cutoff instanceof DateTimeImmutable && $start < $cutoff;
	}

	/**
	 * 診断用: 指定日の候補スロットを「予約受付締切を無視した場合」「実際の締切を適用した場合」の
	 * 2系統で構築する。P2（所要時間に対しシフトが短すぎる）と P8（締切が長すぎて全滅する）を
	 * 切り分けるために使う。
	 *
	 * skip_booked_slots は常に false（自動割り当て相当）で呼び出し、予約済み・貸し切りの枠も
	 * 残したまま返す。「満席（remaining=0だが貸切ではない）」は診断対象外という仕様のため、
	 * 呼び出し側で exclusive_closed のみを見て判定する。
	 *
	 * @param WP_Post              $menu_post     メニュー投稿。
	 * @param array<int>           $staff_ids     対象スタッフID。
	 * @param string               $date          日付（Y-m-d）。
	 * @param DateTimeZone         $timezone      タイムゾーン。
	 * @param array<string, mixed> $menu_settings get_menu_settings() の戻り値。
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
	 *               [締切を無視した場合の全スロット, 実際の締切を適用した場合のスロット]
	 */
	private function build_diagnostic_slots_for_day( WP_Post $menu_post, array $staff_ids, string $date, DateTimeZone $timezone, array $menu_settings ): array {
		$slot_step_minutes = $this->get_slot_step_minutes();
		$total_block_min   = max( $slot_step_minutes, $menu_settings['total_duration'] );
		$service_minutes   = $menu_settings['duration'];
		$fixed_start_times = $menu_settings['fixed_start_times'] ?? array();

		// #655: 締切0でも現在時刻を下限にする（実際の枠生成と同じ式）。「締切を無視した場合」側は null のまま。
		$deadline_cutoff = $this->resolve_booking_cutoff( $menu_settings, $timezone );

		$year  = (int) substr( $date, 0, 4 );
		$month = (int) substr( $date, 5, 2 );
		$day   = (int) substr( $date, 8, 2 );

		$slots_ignoring_deadline = array();
		$slots_after_deadline    = array();

		foreach ( $staff_ids as $staff_id ) {
			$day_entry = $this->get_shift_entry( $staff_id, $year, $month, $day );

			if ( empty( $day_entry['slots'] ) || $this->is_closed_status( (string) ( $day_entry['status'] ?? '' ) ) ) {
				continue;
			}

			$bookings = $this->get_bookings_for_staff_date( $staff_id, $date, $timezone );

			$slots_ignoring_deadline = array_merge(
				$slots_ignoring_deadline,
				$this->build_slots_from_entry(
					$day_entry['slots'],
					$date,
					$timezone,
					$total_block_min,
					$service_minutes,
					null,
					$bookings,
					$slot_step_minutes,
					$fixed_start_times,
					false,
					(int) $menu_post->ID
				)
			);

			$slots_after_deadline = array_merge(
				$slots_after_deadline,
				$this->build_slots_from_entry(
					$day_entry['slots'],
					$date,
					$timezone,
					$total_block_min,
					$service_minutes,
					$deadline_cutoff,
					$bookings,
					$slot_step_minutes,
					$fixed_start_times,
					false,
					(int) $menu_post->ID
				)
			);
		}

		return array( $slots_ignoring_deadline, $slots_after_deadline );
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
	public function can_book_private_menu(): bool {
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
	 * #431: 候補リソースの絞り込みはこのメソッドに集約する。リソースタグ（$tag_ids）による
	 * 絞り込みも、指名の有無に関わらずここで一元的に適用することで、空き枠計算（calendar-meta /
	 * availabilities）・予約下書き・予約確定の再検証のすべてに同じ候補集合を通す
	 * （ロジックを二重に持たない）。
	 *
	 * @param WP_Post    $menu_post       Menu post.
	 * @param int        $preferred_staff Preferred staff ID.
	 * @param array<int> $tag_ids         必須のリソースタグターム ID（AND条件・#431）。空配列は絞り込みなし。
	 * @return array<int>|WP_Error
	 */
	private function resolve_staff_ids( WP_Post $menu_post, int $preferred_staff, array $tag_ids = array() ) {
		// 無料版では選択可能スタッフの制限を解除.
		$is_staff_enabled = Staff_Editor::is_enabled();

		if ( ! $is_staff_enabled ) {
			// 無料版はスタッフが常に基本スタッフ1名固定で、スタッフ指名機能自体が常に無効
			// （Staff_Editor::is_nomination_enabled() は無料版では常に false）。そのため
			// メニュー側の担当設定（_vkbm_staff_ids）はそもそも参照せず、$preferred_staff
			// （resource_id）の値も信用しない。$preferred_staff は URL クエリや予約データに
			// 保存された値をそのまま渡ってくることがあり（例: 過去の予約を再利用する導線）、
			// Pro版時代の古い／実在しないIDや下書きに落ちたIDが渡っても、そのIDをそのまま
			// 使ってしまうと (a) 全日程予約不可になる #465 と同じ症状が別経路で再発する、
			// (b) get_staff_snapshot() が post_type／公開状態を確認せず get_post() するだけの
			// ため非公開スタッフの情報を返しうる（#647 より前の状態。現在は get_staff_snapshot()
			// 側でも確認している）、という2つの問題があった（安藤さん・植草さんレビュー指摘）。
			// 常に現在の基本スタッフへ解決することで (a) を防ぐ。
			$default_staff_id = Resource_Post_Type::get_default_staff_id();
			$staff_ids        = $default_staff_id > 0 ? array( $default_staff_id ) : array();
		} else {
			// #485: 担当できるリソースの解決は Service_Menu_Post_Type::get_assignable_staff_ids() に集約
			// （「すべてのリソースが担当できる」フラグは公開中の全リソースへ展開済み。個別選択が
			// 空（未設定）のメニューは従来どおり空配列が返り、下の「指名あり→そのまま受け付ける／
			// 指名なし→staff_not_configured」の扱いは変えない）。
			$staff_ids = Service_Menu_Post_Type::get_assignable_staff_ids( $menu_post->ID );

			if ( $preferred_staff > 0 ) {
				if ( empty( $staff_ids ) ) {
					$staff_ids = array( $preferred_staff );
				} elseif ( ! in_array( $preferred_staff, $staff_ids, true ) ) {
					return new WP_Error( 'staff_not_assigned', __( 'The specified staff member cannot be in charge of this menu.', 'vk-booking-manager' ) );
				} else {
					$staff_ids = array( $preferred_staff );
				}
			}

			// #647: 候補を公開済みのリソースだけに絞る。個別選択に残った非公開（下書き・非公開・
			// ゴミ箱）のスタッフが自動割当や指名の候補になることと、「選ぶ」かつ個別選択が空の
			// メニューで任意の ID を指名して非公開スタッフの情報を引き出せることの両方を防ぐ。
			// （無料版は上で基本スタッフ1名へ解決済みのため、この絞り込みの対象外）
			$staff_ids = $this->filter_published_resource_ids( $staff_ids );
			if ( $preferred_staff > 0 && empty( $staff_ids ) ) {
				return new WP_Error( 'staff_not_assigned', __( 'The specified staff member cannot be in charge of this menu.', 'vk-booking-manager' ) );
			}
		}

		if ( empty( $staff_ids ) ) {
			return new WP_Error( 'staff_not_configured', __( 'No staff members have been set up to be in charge.', 'vk-booking-manager' ) );
		}

		// #431: リソースタグによる絞り込み（AND条件）。候補が0件になった場合は、
		// 「担当スタッフ自体が未設定」とは別のエラーとして返す（メッセージにはリソース名称の
		// 設定値を使う。issue #431 完了条件）。
		if ( ! empty( $tag_ids ) ) {
			$filtered = $this->filter_staff_ids_by_tags( $staff_ids, $tag_ids );
			if ( empty( $filtered ) ) {
				return new WP_Error(
					'resource_tag_no_match',
					sprintf(
						/* translators: %s: resource label (plural), e.g. "Staff". */
						__( 'There are no %s matching the selected conditions.', 'vk-booking-manager' ),
						vkbm_get_resource_label_plural()
					)
				);
			}
			$staff_ids = $filtered;
		}

		return $staff_ids;
	}

	/**
	 * 候補リソースIDを、指定したリソースタグをすべて持つものだけへ絞り込む（AND条件、#431）。
	 *
	 * @param array<int> $staff_ids 絞り込み対象の候補リソースID。
	 * @param array<int> $tag_ids   必須のリソースタグターム ID。
	 * @return array<int> 絞り込み後の候補リソースID（該当なしは空配列）。
	 */
	private function filter_staff_ids_by_tags( array $staff_ids, array $tag_ids ): array {
		if ( empty( $tag_ids ) || empty( $staff_ids ) ) {
			return $staff_ids;
		}

		// タグを「すべて」持つリソースの全体集合を求め、候補リソースIDと積を取る。
		// タグと担当リソースの逆引きロジックを Resource_Tag_Taxonomy 側の1メソッドに集約し、
		// フロント（メニュー逆引き）・サーバー（この絞り込み）で二重実装しない（issue #431 実装メモ）。
		$resource_ids_with_tags = Resource_Tag_Taxonomy::get_resource_ids_for_tags( $tag_ids );

		if ( empty( $resource_ids_with_tags ) ) {
			return array();
		}

		return array_values( array_intersect( $staff_ids, $resource_ids_with_tags ) );
	}

	/**
	 * REST引数のリソースタグターム ID を正規化する（#431）。
	 *
	 * 正規化ルールは Resource_Tag_Id_List::normalize() に一元化している（同じ処理を複数箇所へ重複させないため）。
	 *
	 * @param mixed $raw_tag_ids REST引数由来の生の値（配列以外は空配列扱い）。
	 * @return array<int>
	 */
	private function normalize_tag_ids( $raw_tag_ids ): array {
		return Resource_Tag_Id_List::normalize( $raw_tag_ids );
	}

	/**
	 * REST引数から指名スタッフID（resource_id）を読み取り、無料版では常に0へ正規化する。
	 *
	 * 無料版はスタッフ指名機能自体が存在しない（担当は常に基本スタッフ1名固定）ため、
	 * resource_id が0より大きい値で渡ってきても「指名あり」として扱わない。
	 * resolve_staff_ids() 内の正規化だけでは戻り値（候補スタッフの配列）しか正規化できず、
	 * 呼び出し元がキャッシュキーの組み立てや generate_slots_for_date() の自動割当無効化
	 * フラグへそのまま渡している $preferred_staff_id 自体は直せない。そのため、REST引数から
	 * 読み取る時点でこのメソッドを経由して正規化し、無料版では resource_id の有無に関わらず
	 * 同じ結果（自動割当・キャッシュキー・レスポンス）になるようにする
	 * （CodeRabbit 指摘、PR #466、issue #465）。
	 *
	 * 無料版の予約フロント（src/blocks/reservation/app.js）は「無料版では常にデフォルトスタッフID
	 * を送る」実装になっており、resource_id が0より大きい値でリクエストされることが通常フローで
	 * 実際に起こる（机上の経路ではない）。
	 *
	 * @param array<string, mixed> $args REST引数（resource_id を含む）。
	 * @return int 指名スタッフID（無料版・未指定時は0）。
	 */
	private function resolve_preferred_staff_id( array $args ): int {
		if ( ! Staff_Editor::is_enabled() ) {
			return 0;
		}

		return isset( $args['resource_id'] ) ? (int) $args['resource_id'] : 0;
	}

	/**
	 * 空き枠計算に使うサイトのタイムゾーンを返す。
	 *
	 * リクエストの timezone は後方互換のため REST API で受け付けるが、シフトや予約日時の
	 * 解釈には使わない。外部入力でサイトの営業時間の基準を変更できないようにする。
	 *
	 * @return DateTimeZone サイトのタイムゾーン。
	 */
	private function resolve_timezone(): DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Build cache key.
	 *
	 * キャッシュ名には Availability_Cache_Generation が管理するサイト全体の世代番号を混ぜる。
	 * シフト・サービスメニュー・スタッフ・システム設定のいずれかが保存・削除されると
	 * 世代番号が進み、古い世代番号で作られたキャッシュ名は二度と参照されなくなる
	 * （#410 / #412。delete_transient() による個別削除は再検討済みで不採用）。
	 *
	 * 加えて、その名前が対象にしている日付・月ぶんの世代番号（Availability_Booking_Cache_Generation
	 * が管理）も混ぜる（#417）。予約はシフト等より保存頻度が桁違いに高いため、サイト全体の
	 * 世代番号へ混ぜてしまうと予約が1件入るたびに全メニュー・全月ぶんのキャッシュが無効化され、
	 * 予約の多いサイトで一時保存がほとんど効かなくなる。そのため予約の変更は「その予約が
	 * 実際に関係する日付・月」だけの世代番号を進める方式にしている。calendar（月次カレンダー、
	 * date_key は YYYY-MM）はその月の世代番号を、daily（日別枠、date_key は YYYY-MM-DD）は
	 * その日の世代番号を見る。
	 *
	 * 世代番号を含めても、options テーブルの列名上限（191文字）に対して十分な余裕がある
	 * （既存部分が約100文字、世代番号2つを合わせても int の最大桁数でそれぞれ20文字程度）。
	 *
	 * $is_staff_preferred も必ずキーへ含める（安藤レビュー・MEDIUM）。担当スタッフが1人だけの
	 * メニューでは、指名あり（resource_id 指定）と指名なし（自動割り当て）の呼び出しが
	 * $staff_ids・$menu_id・$date_key のすべてで一致してしまい、この引数が
	 * 無いと同じキャッシュ名を共有してしまう。しかし generate_slots_for_date() は
	 * $is_staff_preferred の値で collapse_slots_for_auto_assignment() を通すか・
	 * auto_assign フラグ・予約済み枠の扱い（skip_booked_slots）を変えるため、出力は別物になる。
	 * 区別せずキャッシュすると、先にキャッシュされた側の結果（予約済み枠を含みうる自動割り当て
	 * 結果など）がもう一方のリクエストへ誤って返り、埋まっている枠を空きとして提示しうる。
	 *
	 * @param string     $prefix             Prefix.
	 * @param int        $menu_id            Menu ID.
	 * @param array<int> $staff_ids          Staff IDs.
	 * @param string     $date_key           Date key.
	 * @param bool       $is_staff_preferred 担当スタッフ指名の有無（resource_id 指定の有無）。
	 * @param array<int> $tag_ids            #431: リソースタグ絞り込みのターム ID。キーへ含めないと
	 *                                        タグ絞り込み前の結果が別条件のリクエストへ誤って返る。
	 * @return string
	 */
	private function build_cache_key( string $prefix, int $menu_id, array $staff_ids, string $date_key, bool $is_staff_preferred, array $tag_ids = array() ): string {
		$staff_hash = md5( implode( '-', $staff_ids ) );
		$generation = Availability_Cache_Generation::get_generation();

		// calendar（月次カレンダー）はその月ぶん、daily（日別枠）はその日ぶんの世代番号を見る（#417）。
		$date_generation = ( 'calendar' === $prefix )
			? Availability_Booking_Cache_Generation::get_monthly_generation( $date_key )
			: Availability_Booking_Cache_Generation::get_daily_generation( $date_key );

		// #431: リソースタグの組み合わせごとにキャッシュを分ける（ソートしてから結合し、
		// チェック順序違いを同一キーへ集約する）。
		$sorted_tag_ids = $tag_ids;
		sort( $sorted_tag_ids );
		$tag_hash = md5( implode( '-', $sorted_tag_ids ) );

		return sprintf( 'vkbm_%s_g%d_bg%d_%d_%s_%s_p%d_t%s', $prefix, $generation, $date_generation, $menu_id, $staff_hash, $date_key, $is_staff_preferred ? 1 : 0, $tag_hash );
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
		// 計算本体は戦略へ委ねる。予約方式の分岐はここではなく resolve_slot_strategy() に集約する。
		return $this->resolve_slot_strategy( $menu_post )->generate_slots_for_date( $menu_post, $staff_ids, $date, $timezone, $is_staff_preferred );
	}

	/**
	 * メニューに対して使う空き枠の計算戦略を返す。
	 *
	 * 現在は時間枠戦略のみ。予約方式ごとの戦略の選択はこのメソッドに足す。
	 *
	 * @param WP_Post $menu_post メニュー投稿。
	 * @return Slot_Strategy 空き枠の計算戦略。
	 */
	protected function resolve_slot_strategy( WP_Post $menu_post ): Slot_Strategy {
		return $this->resolve_time_slot_strategy();
	}

	/**
	 * 時間枠戦略を返す。未生成なら、この Availability_Service を取得元にして生成する。
	 *
	 * @return Time_Slot_Strategy 時間枠戦略。
	 */
	private function resolve_time_slot_strategy(): Time_Slot_Strategy {
		if ( null === $this->time_slot_strategy ) {
			$this->time_slot_strategy = new Time_Slot_Strategy( $this );
		}

		return $this->time_slot_strategy;
	}

	/**
	 * Collapse staff-specific slots into auto-assignable buckets.
	 *
	 * スタッフ個別スロットを自動割り当てバケットに集約します。
	 * capacity はメニューの最大同時予約人数を反映します。
	 *
	 * #392: 指名を使うメニュー（「指名なし」で申し込んだ予約も含む）は1枠1組（貸切）扱いのため、
	 * 既に1件でも予約が入ったスタッフの残りは、定員未達でも0（相乗り不可）にする。
	 * 指名を使わないメニューでは従来どおり max_capacity - 予約人数 の相乗りを許可する。
	 *
	 * 貸し切り予約（exclusive_closed）の集約は、指名を使うメニューかどうかで扱いを変える。
	 * 指名を使うメニューは担当ごとに独立した時間帯を持つため、ある担当の貸切予約は
	 * その担当の枠だけを閉じ、他の担当が空いていればこの時間帯は引き続き受付可能とする
	 * （候補の担当が全員 exclusive_closed のときだけ、この時間帯全体を受付停止にする）。
	 * 指名を使わないメニューは従来どおり、いずれかの担当の貸切予約でこの時間帯全体を閉じる
	 * （複数の担当が同じ物理的な枠を共有する前提のため）。
	 *
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param array<int, array<string, mixed>> $slots        Slots.
	 * @param int                              $menu_id      Menu ID.
	 * @param int                              $max_capacity Maximum simultaneous bookings per slot.
	 * @param int                              $min_capacity 最小催行人数（0=制約なし）。
	 * @return array<int, array<string, mixed>>
	 */
	public function collapse_slots_for_auto_assignment( array $slots, int $menu_id, int $max_capacity = 1, int $min_capacity = 0 ): array {
		if ( empty( $slots ) ) {
			return array();
		}

		$max_capacity    = max( 1, $max_capacity );
		$min_capacity    = max( 0, $min_capacity );
		$uses_nomination = Staff_Editor::is_nomination_enabled_for_menu( $menu_id );
		$grouped         = array();

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
					// 貸し切り予約で閉じた枠かどうかの暫定値（いずれかの担当が貸切なら true）。
					// 指名を使わないメニューはこの値をそのまま使う。指名を使うメニューでは、下の
					// 確定処理で「候補の担当が全員 exclusive_closed」のときだけ true へ計算し直す
					// （担当ごとの状態は staff_exclusive_closed に保持する）。
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
				$grouped[ $key ]['exclusive_closed']               = $grouped[ $key ]['exclusive_closed'] || ! empty( $slot['exclusive_closed'] );
			}

			$staff_id    = isset( $slot['staff']['id'] ) ? (int) $slot['staff']['id'] : 0;
			$guest_count = isset( $slot['guest_count'] ) ? (int) $slot['guest_count'] : 0;
			// 残席の計算には、同一メニューの人数と別メニューの予約の有無を使う（#656）。
			// same_menu_guests が無い呼び出しは従来どおり guest_count を同一メニューの人数として扱う。
			$same_menu_guests = isset( $slot['same_menu_guests'] ) ? (int) $slot['same_menu_guests'] : $guest_count;
			$has_other_menu   = ! empty( $slot['has_other_menu'] );
			$exclusive_closed = ! empty( $slot['exclusive_closed'] );

			// シフトに入っているスタッフはすべて割り当て候補に追加する。
			// 1予約は分割せず単一スタッフへ割り当てるため、各スタッフの負荷（予約人数）・
			// 貸し切り予約の有無を個別に保持する（指名を使うメニューの担当スタッフ単位の判定に使う）。
			if ( $staff_id > 0 ) {
				$grouped[ $key ]['assignable_staff_ids'][] = $staff_id;
				if ( ! isset( $grouped[ $key ]['staff_exclusive_closed'] ) ) {
					$grouped[ $key ]['staff_exclusive_closed'] = array();
				}
				// 同一スタッフが重複して現れた場合は最大の人数・OR判定を採用する（防御的）。
				$grouped[ $key ]['staff_exclusive_closed'][ $staff_id ] =
					! empty( $grouped[ $key ]['staff_exclusive_closed'][ $staff_id ] ) || $exclusive_closed;
				if ( ! isset( $grouped[ $key ]['staff_same_menu_guests'] ) ) {
					$grouped[ $key ]['staff_same_menu_guests'] = array();
				}
				$grouped[ $key ]['staff_same_menu_guests'][ $staff_id ] = max(
					isset( $grouped[ $key ]['staff_same_menu_guests'][ $staff_id ] ) ? (int) $grouped[ $key ]['staff_same_menu_guests'][ $staff_id ] : 0,
					$same_menu_guests
				);
				if ( ! isset( $grouped[ $key ]['staff_has_other_menu'] ) ) {
					$grouped[ $key ]['staff_has_other_menu'] = array();
				}
				$grouped[ $key ]['staff_has_other_menu'][ $staff_id ] =
					! empty( $grouped[ $key ]['staff_has_other_menu'][ $staff_id ] ) || $has_other_menu;
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
			$staff_same_menu_guests = ( isset( $slot['staff_same_menu_guests'] ) && is_array( $slot['staff_same_menu_guests'] ) ) ? $slot['staff_same_menu_guests'] : array();
			$staff_has_other_menu   = ( isset( $slot['staff_has_other_menu'] ) && is_array( $slot['staff_has_other_menu'] ) ) ? $slot['staff_has_other_menu'] : array();
			$best_remaining         = 0;

			// 催行判定用の合計予約人数（グループ開催型）。
			// 自動割り当てでは同一時間帯がスタッフ横断で1枠に集約されるため、
			// 各スタッフ枠の同一メニューの予約人数（staff_same_menu_guests）を合計してその時間枠全体の相乗り人数とする
			// （#656。別メニューの予約は人数に含めない）。
			$grouped[ $key ]['min_capacity']  = $min_capacity;
			$grouped[ $key ]['booked_guests'] = (int) array_sum( $staff_same_menu_guests );
			foreach ( $grouped[ $key ]['assignable_staff_ids'] as $sid ) {
				// 残席は予約確定・管理画面の競合判定と同じ基準で数える（Staff_Load_Calculator::calculate_remaining()）。
				// #392: 指名を使うメニューは1枠1組（貸切）で、予約が1件でもあれば残りは0。
				// #656: 別メニューの予約があるスタッフは、定員が2以上でも残りは0（担当者は同時に1組だけ受け持つ）。
				// 指名を使わないメニュー同士の相乗りは、従来どおり max_capacity - 同一メニューの人数 まで許可する。
				$remaining = Staff_Load_Calculator::calculate_remaining(
					$max_capacity,
					$uses_nomination,
					array(
						'same_menu_guests' => isset( $staff_same_menu_guests[ $sid ] ) ? (int) $staff_same_menu_guests[ $sid ] : 0,
						'has_other_menu'   => ! empty( $staff_has_other_menu[ $sid ] ),
					)
				);
				if ( $remaining > $best_remaining ) {
					$best_remaining = $remaining;
				}
			}
			$grouped[ $key ]['remaining'] = empty( $grouped[ $key ]['assignable_staff_ids'] ) ? 0 : $best_remaining;

			// 指名を使うメニューは担当ごとに独立した時間帯を持つため、候補の担当が全員
			// exclusive_closed のときだけ、この時間帯全体を受付停止にする（1人でも空いていれば
			// 引き続き受付可能）。指名を使わないメニューは、いずれかの担当の貸切予約で全体を
			// 閉じる従来どおりの exclusive_closed（グループ化時に OR で集約済み）をそのまま使う。
			if ( $uses_nomination ) {
				$staff_exclusive_closed = ( isset( $slot['staff_exclusive_closed'] ) && is_array( $slot['staff_exclusive_closed'] ) ) ? $slot['staff_exclusive_closed'] : array();
				$all_candidates_closed  = ! empty( $grouped[ $key ]['assignable_staff_ids'] );
				foreach ( $grouped[ $key ]['assignable_staff_ids'] as $sid ) {
					if ( empty( $staff_exclusive_closed[ $sid ] ) ) {
						$all_candidates_closed = false;
						break;
					}
				}
				$grouped[ $key ]['exclusive_closed'] = $all_candidates_closed;
			}

			// 貸し切り予約で閉じた枠は、残席があっても受付停止（remaining=0）に上書きする。
			// スロット自体は消さず、フロントが「満席」と区別して「予約受付終了」を表示できるよう exclusive_closed を残す。
			if ( ! empty( $grouped[ $key ]['exclusive_closed'] ) ) {
				$grouped[ $key ]['remaining'] = 0;
			}

			// 内部集計用キーは出力に含めない。
			unset( $grouped[ $key ]['staff_exclusive_closed'] );
			unset( $grouped[ $key ]['staff_same_menu_guests'] );
			unset( $grouped[ $key ]['staff_has_other_menu'] );
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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param array<int> $staff_ids Staff IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_staff_snapshot( array $staff_ids ): array {
		// #705: 月次カレンダーの計算中は、同じスタッフ構成の情報を日ごとに読み直さず1回だけ作る。
		$snapshot_key = null;
		if ( null !== $this->month_scope ) {
			$snapshot_key = implode( ',', $staff_ids );
			if ( isset( $this->month_scope['snapshots'][ $snapshot_key ] ) ) {
				return $this->month_scope['snapshots'][ $snapshot_key ];
			}
		}

		$info = array();

		foreach ( $staff_ids as $staff_id ) {
			$post = get_post( $staff_id );
			// #647: リソース投稿で、かつ公開済みのものだけを返す（非公開スタッフの名前・画像を出さない）。
			if ( ! $post || Resource_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
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

		if ( null !== $snapshot_key ) {
			$this->month_scope['snapshots'][ $snapshot_key ] = $info;
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
		// 計算本体は時間枠戦略へ委ねる。既存のテストが名前で呼ぶため、このメソッドは残す。
		return $this->resolve_time_slot_strategy()->build_slots_from_entry(
			$slots,
			$date,
			$timezone,
			$block_minutes,
			$service_minutes,
			$deadline_cutoff,
			$bookings,
			$slot_step_minutes,
			$fixed_start_times,
			$skip_booked_slots,
			$menu_id
		);
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
	 * 指定した時間帯に重複する予約を、同一メニューの相乗り人数と別メニューの予約の有無に要約する（#656）。
	 *
	 * 判定本体は Staff_Load_Calculator::summarize_staff_bookings()（予約確定・管理画面と共通）。
	 * $menu_id が 0 以下のときはメニューを区別せず、重複する予約すべてを同一メニューとして数える（後方互換）。
	 *
	 * @param DateTimeImmutable                                                                               $start   候補スロット開始。
	 * @param DateTimeImmutable                                                                               $end     候補スロット終了。
	 * @param array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests?:int, service_id?:int}> $bookings 予約リスト。
	 * @param int                                                                                             $menu_id 当該メニューID。
	 * @return array{same_menu_guests:int, has_other_menu:bool} 同一メニューの合計予約人数と、別メニューの予約の有無。
	 */
	public function summarize_menu_bookings_for_slot( DateTimeImmutable $start, DateTimeImmutable $end, array $bookings, int $menu_id ): array {
		$overlapping = array();
		foreach ( $bookings as $booking ) {
			if ( $this->is_slot_overlapping( $start, $end, $booking ) ) {
				$overlapping[] = $booking;
			}
		}

		if ( $menu_id <= 0 ) {
			return array(
				'same_menu_guests' => $this->count_guests_for_slot( $start, $end, $overlapping ),
				'has_other_menu'   => false,
			);
		}

		return Staff_Load_Calculator::summarize_staff_bookings( $menu_id, $overlapping );
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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param string       $date     Date (Y-m-d).
	 * @param string       $time     Time (HH:MM).
	 * @param DateTimeZone $timezone Timezone.
	 * @param bool         $is_end   Whether the time is an end boundary.
	 * @return DateTimeImmutable|null
	 */
	public function create_datetime_from_time( string $date, string $time, DateTimeZone $timezone, bool $is_end ): ?DateTimeImmutable {
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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param int          $staff_id Staff ID.
	 * @param string       $date     Date (Y-m-d).
	 * @param DateTimeZone $timezone Timezone.
	 * @return array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests:int}>
	 */
	public function get_bookings_for_staff_date( int $staff_id, string $date, DateTimeZone $timezone ): array {
		$cache_key = sprintf( '%d-%s', $staff_id, $date );

		if ( isset( $this->booking_cache[ $cache_key ] ) ) {
			return $this->booking_cache[ $cache_key ];
		}

		$start_of_day = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $timezone );
		$end_of_day   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', $timezone );

		if ( ! $start_of_day || ! $end_of_day ) {
			return array();
		}

		// #705: 月次カレンダーの計算中で、その月の予約を先に読んである担当者は、その保持分から日の予約を取り出す。
		// 保持分は計算の間だけの一時的なもので、$this->booking_cache（リクエスト内で残る保持）には入れない。
		$primed = $this->month_scope['bookings'][ $staff_id ] ?? null;
		if ( null !== $primed && substr( $date, 0, 7 ) === $primed['month'] ) {
			return $this->pick_primed_bookings_for_day( $primed['records'], $start_of_day, $end_of_day );
		}

		$bookings = array();

		foreach ( $this->query_booking_ids_for_staff( $staff_id, $start_of_day, $end_of_day ) as $post_id ) {
			$booking = $this->read_booking( $post_id, $timezone );
			if ( null !== $booking ) {
				$bookings[] = $booking;
			}
		}

		$this->booking_cache[ $cache_key ] = $bookings;

		return $bookings;
	}

	/**
	 * 指定した月の予約を担当者ぶんまとめて1回の問い合わせで読み、月次カレンダーの計算中だけ保持する。
	 *
	 * 保持した予約は get_bookings_for_staff_date() が日ごとに取り出す（日をまたぐ予約・月をまたぐ予約も含む）。
	 * 月次カレンダーの計算の外（month_scope が null）では何もしない。
	 *
	 * @param int          $staff_id 担当者（リソース）ID。
	 * @param int          $year     年。
	 * @param int          $month    月。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return void
	 */
	private function prime_bookings_for_staff_month( int $staff_id, int $year, int $month, DateTimeZone $timezone ): void {
		if ( null === $this->month_scope ) {
			return;
		}

		$month_key      = sprintf( '%04d-%02d', $year, $month );
		$start_of_month = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $month_key . '-01 00:00:00', $timezone );
		$end_of_month   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%s-%02d 23:59:59', $month_key, $this->days_in_month( $year, $month ) ), $timezone );

		// 日付を組み立てられないときは保持せず、日ごとの問い合わせに任せる。
		if ( ! $start_of_month || ! $end_of_month ) {
			return;
		}

		// 月の範囲に少しでも重なる予約を読む。日ごとの条件より広い範囲なので、日ごとの結果は必ずこの中に含まれる。
		$records = array();
		foreach ( $this->query_booking_ids_for_staff( $staff_id, $start_of_month, $end_of_month ) as $post_id ) {
			$booking = $this->read_booking( $post_id, $timezone );
			if ( null === $booking ) {
				continue;
			}

			// 日ごとの絞り込みは日の問い合わせと同じ条件（総終了と終了のどちらかが日の始まりより後）で判定するため、
			// 総終了と終了を別々に読み取った値を持たせる。
			$total_end = Booking_Datetime::parse( (string) get_post_meta( $post_id, self::BOOKING_META_TOTAL_END, true ), $timezone );
			$end_meta  = Booking_Datetime::parse( (string) get_post_meta( $post_id, self::BOOKING_META_END, true ), $timezone );

			$records[] = array(
				'booking'   => $booking,
				'total_end' => $total_end ? $total_end : null,
				'end_meta'  => $end_meta ? $end_meta : null,
			);
		}

		$this->month_scope['bookings'][ $staff_id ] = array(
			'month'   => $month_key,
			'records' => $records,
		);
	}

	/**
	 * 月ぶんの保持分から、指定した日に重なる予約を取り出す。
	 *
	 * 条件は get_bookings_for_staff_date() の問い合わせと同じ。
	 * 予約の開始が日の終わりより前で、かつ（総終了または終了のどちらかが）日の始まりより後。
	 * 日時はプラグインが保存する Y-m-d H:i:s 形式（秒なし・「T」区切りは Booking_Datetime の正規化で揃う）が前提で、
	 * 時差表記や小数秒付きの日時は想定していない。
	 *
	 * @param array<int, array<string, mixed>> $records      prime_bookings_for_staff_month() が保持した予約。
	 * @param DateTimeImmutable                $start_of_day 日の始まり（00:00:00）。
	 * @param DateTimeImmutable                $end_of_day   日の終わり（23:59:59）。
	 * @return array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, guests:int, exclusive:bool, service_id:int}>
	 */
	private function pick_primed_bookings_for_day( array $records, DateTimeImmutable $start_of_day, DateTimeImmutable $end_of_day ): array {
		$bookings = array();

		foreach ( $records as $record ) {
			$booking = $record['booking'];

			if ( $booking['start'] >= $end_of_day ) {
				continue;
			}

			$ends_after_day_start = ( $record['total_end'] && $record['total_end'] > $start_of_day )
				|| ( $record['end_meta'] && $record['end_meta'] > $start_of_day );
			if ( ! $ends_after_day_start ) {
				continue;
			}

			$bookings[] = $booking;
		}

		return $bookings;
	}

	/**
	 * 担当者の予約のうち、指定した期間に重なる予約の投稿 ID を取得する。
	 *
	 * 取得した予約の付帯データ（post meta）は、予約ごとに読みに行かないようまとめてキャッシュへ載せる。
	 *
	 * @param int               $staff_id    担当者（リソース）ID。
	 * @param DateTimeImmutable $range_start 期間の始まり。
	 * @param DateTimeImmutable $range_end   期間の終わり。
	 * @return array<int, int> 予約の投稿 ID。
	 */
	private function query_booking_ids_for_staff( int $staff_id, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
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
						'value'   => $range_end->format( 'Y-m-d H:i:s' ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::BOOKING_META_TOTAL_END,
							'value'   => $range_start->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::BOOKING_META_END,
							'value'   => $range_start->format( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		$post_ids = array_map( 'intval', $query->posts );

		// 予約ごとの post meta の読み込みを、1回の問い合わせにまとめる。
		if ( $post_ids ) {
			update_meta_cache( 'post', $post_ids );
		}

		return $post_ids;
	}

	/**
	 * 予約1件を空き計算用の配列に変換する。枠を消費しない・読み取れない予約は null。
	 *
	 * @param int          $post_id  予約の投稿 ID。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return array{start:DateTimeImmutable, end:DateTimeImmutable, guests:int, exclusive:bool, service_id:int}|null
	 */
	private function read_booking( int $post_id, DateTimeZone $timezone ): ?array {
		$status = (string) get_post_meta( $post_id, self::BOOKING_META_STATUS, true );
		// キャンセル・無断キャンセルの予約は枠を消費しないため残数集計から除外する
		// （確定時の負荷集計と同じ方針）。
		if ( 'no_show' === $status || 'cancelled' === $status ) {
			return null;
		}

		$start_raw     = (string) get_post_meta( $post_id, self::BOOKING_META_START, true );
		$total_end_raw = (string) get_post_meta( $post_id, self::BOOKING_META_TOTAL_END, true );
		$end_raw       = '' !== $total_end_raw ? $total_end_raw : (string) get_post_meta( $post_id, self::BOOKING_META_END, true );

		if ( ! $start_raw || ! $end_raw ) {
			return null;
		}

		// 秒無し・T 区切りの予約も読み取る（読み取れない予約は空き計算から外れ、二重予約の原因になるため）。
		$start_dt = Booking_Datetime::parse( $start_raw, $timezone );
		$end_dt   = Booking_Datetime::parse( $end_raw, $timezone );

		if ( ! $start_dt || ! $end_dt ) {
			return null;
		}

		// 予約人数（複数人予約。未設定の既存予約は1名として扱う）。
		$guests_raw = get_post_meta( $post_id, self::BOOKING_META_GUESTS, true );
		$guests     = '' === $guests_raw ? 1 : max( 1, (int) $guests_raw );

		// 貸し切り予約フラグ（この予約が枠を専有するか）。
		// 設定ONのメニューで予約確定時に true が付与される。判定経路を統一するため
		// ここで booking 配列に exclusive キーとして持たせ、slot_has_exclusive_booking() で参照する。
		$exclusive = (bool) get_post_meta( $post_id, self::BOOKING_META_EXCLUSIVE, true );

		// 予約のメニューID（サービスID）。貸し切り判定は「当該メニューの貸し切り予約のみ」を
		// 対象にするため、判定時にこの service_id で絞り込む（メニューAの貸し切りが
		// メニューBの枠を閉じないようにする）。
		$service_id = (int) get_post_meta( $post_id, self::BOOKING_META_SERVICE, true );

		return array(
			'start'      => $start_dt,
			'end'        => $end_dt,
			'guests'     => $guests,
			'exclusive'  => $exclusive,
			'service_id' => $service_id,
		);
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

			// 秒無し・T 区切りの予約も読み取る（読み取れない予約は空き計算から外れ、二重予約の原因になるため）。
			$start_dt = Booking_Datetime::parse( $start_raw, $timezone );
			$end_dt   = Booking_Datetime::parse( $end_raw, $timezone );

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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param int $staff_id Staff ID.
	 * @param int $year     Year.
	 * @param int $month    Month.
	 * @param int $day      Day.
	 * @return array<string, mixed>
	 */
	public function get_shift_entry( int $staff_id, int $year, int $month, int $day ): array {
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
					if ( $day_number <= 0 || ! is_array( $entry ) ) {
						continue;
					}
					$days[ $day_number ] = array(
						'status' => $this->normalize_day_status( $entry['status'] ?? null ),
						'slots'  => $this->sanitize_slots( $entry['slots'] ?? array() ),
					);
				}
			}
		}

		$this->shift_cache[ $cache_key ] = $days;

		return $days;
	}

	/**
	 * 営業日の状態を既知の値に整える。未知の値・空の値・文字列以外は営業（open）として扱う。
	 *
	 * @param mixed $status シフトに保存された状態。
	 * @return string 既知の営業日の状態。
	 */
	private function normalize_day_status( $status ): string {
		$known_statuses = array(
			self::DAY_STATUS_OPEN,
			self::DAY_STATUS_REGULAR_HOLIDAY,
			self::DAY_STATUS_TEMP_OPEN,
			self::DAY_STATUS_TEMP_CLOSED,
			self::DAY_STATUS_UNAVAILABLE,
		);

		if ( is_string( $status ) && in_array( $status, $known_statuses, true ) ) {
			return $status;
		}

		return self::DAY_STATUS_OPEN;
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
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public function is_closed_status( string $status ): bool {
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
	 * #392: 指名を使うメニューを「1枠1組（貸切）」として扱う仕様変更に伴い、
	 * 「指名ONなら1固定」の分岐を削除した。指名を使うメニューでも、この値は
	 * 「1件の予約で申し込める（1組の）最大人数」として使われる。1枠1組の排他制御は
	 * 予約処理側（Booking_Confirmation_Controller::select_best_fit_staff() 等の
	 * スタッフ競合判定）が別途担うため、ここでは人数の実値解決のみを行う。
	 *
	 * @param WP_Post $menu_post Menu post object.
	 * @return int
	 */
	public function get_menu_max_capacity( WP_Post $menu_post ): int {
		// 予約枠の定員機能が無効な場合は上限を1に固定する（複数人受付を抑止）。
		if ( ! Staff_Editor::is_slot_capacity_enabled() ) {
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
	 * #392: 指名を使うメニューは常に1枠1組（貸切）のため、複数の別々の予約が相乗りして
	 * 「催行確定」に達するという概念自体が成立しない。get_menu_max_capacity() が
	 * 指名ONでも1より大きい値を返すようになったため（#392）、この関数では
	 * 「max_capacity <= 1」だけに頼らず、指名ONを明示的にチェックして0（制約なし）へ倒す。
	 *
	 * @param WP_Post $menu_post メニューの投稿オブジェクト。
	 * @return int 最小催行人数（0=制約なし）。
	 */
	public function get_menu_min_capacity( WP_Post $menu_post ): int {
		// 指名を使うメニューは1枠1組（貸切）が確定しており、催行判定の概念が無いため常に0。
		if ( Staff_Editor::is_nomination_enabled_for_menu( $menu_post->ID ) ) {
			return 0;
		}

		// 最小催行人数は複数人相乗りの催行判定が前提のため、最大受付数が1（複数人予約OFF・Free版）の場合は無効。
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
	 * 指名を使うメニューの「最低申し込み人数」（受付制限）を取得する（#393）。
	 *
	 * get_menu_min_capacity()（催行状態の表示専用。指名を使うメニューでは常に0を返す）とは別の
	 * 取得経路。指名を使うメニューは1件の予約が1組の貸切になるため、この値は「1組の最低人数」を
	 * 意味する受付制限として使う。この値が1以上のとき、申込人数がこれを下回る予約は受け付けない
	 * （Booking_Draft_Controller / Booking_Confirmation_Controller が本メソッドの値でサーバ側の
	 * 申込を拒否する）。
	 *
	 * 有効になるのは「指名を使う」かつ「複数人一括予約ON」かつ「予約枠の定員2以上」のときだけで、
	 * それ以外は実効0（制限なし）を返す。複数人一括予約OFFのメニューはフロントの人数入力欄が出ず
	 * 申込人数が常に1名固定になるため、最低申し込み人数に2以上が保存されていると誰も予約できない
	 * メニューになってしまう。これを避けるため既存の最少催行人数（#320）と同じ型のクランプを行う。
	 *
	 * @param WP_Post $menu_post メニューの投稿オブジェクト。
	 * @return int 最低申し込み人数（0=制限なし）。
	 */
	public function get_menu_nomination_min_guests( WP_Post $menu_post ): int {
		// 1室1泊あたりのプランは人数で料金・受付条件を分けないため、保存済みの最低人数を適用しない。
		if ( Service_Menu_Post_Type::is_night_price_per_room( $menu_post->ID ) ) {
			return 0;
		}

		// 指名を使わないメニューではこの受付制限は無効（このメニューの最少催行人数は表示専用のまま）。
		if ( ! Staff_Editor::is_nomination_enabled_for_menu( $menu_post->ID ) ) {
			return 0;
		}

		// 複数人一括予約が許可されていないメニューは申込人数が常に1名固定のため、
		// 最低申し込み人数を課すと誰も予約できなくなる。実効0（制限なし）へクランプする。
		if ( ! (bool) get_post_meta( $menu_post->ID, self::MENU_META_ALLOW_MULTIPLE_GUESTS, true ) ) {
			return 0;
		}

		// 予約枠の定員が1以下（予約枠の定員機能OFF・Free版相当）の場合も同様に無効。
		$max_capacity = $this->get_menu_max_capacity( $menu_post );
		if ( $max_capacity < 2 ) {
			return 0;
		}

		$meta = get_post_meta( $menu_post->ID, self::MENU_META_MIN_CAPACITY, true );
		$min  = '' === $meta ? 0 : (int) $meta;

		// 0未満は0へ、予約枠の定員を超える値はその定員へクランプする。
		return max( 0, min( $max_capacity, $min ) );
	}

	/**
	 * メニュー単位の予約設定（締切・上限・指名要否など）を解決して返す。
	 *
	 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
	 *
	 * @param WP_Post $menu_post サービスメニューの投稿オブジェクト。
	 * @return array メニューに適用される設定値の配列。
	 */
	public function get_menu_settings( WP_Post $menu_post ): array {
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
		 * @internal 時間枠戦略（Time_Slot_Strategy）から呼ばれるため public。外部からは使わない。
		 *
		 * @param array<string, mixed> $menu_settings 予約枠設定（get_menu_settings() の戻り値）。
		 * @param string               $date          Date string (Y-m-d).
		 * @param DateTimeZone         $timezone      Timezone for weekday calculation.
		 * @return bool
		 */
	public function is_date_allowed_for_menu( array $menu_settings, string $date, DateTimeZone $timezone ): bool {
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

		// array_filter() はキー番号を詰めないため、先頭が空だと $statuses[0] が無くなる。array_values() で詰める。
		$statuses = array_values( array_filter( array_unique( $statuses ) ) );

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
	 * 泊単位のプランについて、チェックイン日〜チェックアウト日に空いている客室を料金付きで返す。
	 *
	 * 引数: menu_id, resource_id（任意。客室を指名する場合）, check_in_date, check_out_date（いずれも Y-m-d）,
	 * guests（任意。1名1泊あたりの料金計算に使う。既定 1）。日付はサイトのタイムゾーン（wp_timezone()）で解釈し、
	 * 送られてきたタイムゾーンは使わない。
	 *
	 * 宿泊が有効なサイトか・泊単位のメニューかを確認し、日付と客室を検証したうえで、空室判定を
	 * Night_Strategy に委ねる。結果は時間枠の calendar / daily とは別の区分でキャッシュする。
	 *
	 * @param array<string, mixed> $args 引数。
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_lodging_availability( array $args ) {
		// 宿泊が有効か・泊単位のメニューか・日付・客室を検証する（予約の下書き・確定と同じ検証）。
		$stay = $this->resolve_lodging_stay( $args );
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		$menu           = $stay['menu'];
		$timezone       = $stay['timezone'];
		$check_in_date  = $stay['check_in_date'];
		$check_out_date = $stay['check_out_date'];
		$nights         = $stay['nights'];
		$resource_id    = $stay['resource_id'];

		// 人数と料金区分の内訳は、予約の下書き・確定と同じ決め方（Lodging_Booking::resolve_guests()）で決める。
		// 上限を超える人数は丸めずに拒否する（表示した料金と、予約で保存される料金を一致させる）。
		$resolved_guests = Lodging_Booking::resolve_guests( $menu->ID, (int) ( $args['guests'] ?? 1 ), $args['guest_tiers'] ?? array() );
		if ( is_wp_error( $resolved_guests ) ) {
			return $resolved_guests;
		}
		$guests         = $resolved_guests['guests'];
		$guest_tiers    = $resolved_guests['guest_tiers'];
		$check_in_time  = Service_Menu_Post_Type::get_check_in_time( $menu->ID );
		$check_out_time = Service_Menu_Post_Type::get_check_out_time( $menu->ID );

		// 空室の客室 ID の一覧だけをキャッシュする。料金は人数で変わるため、読み出した後に毎回計算する。
		$available_ids = $this->find_available_room_ids( $stay );

		// 空室の客室ごとに、名前と泊数分の料金を付ける（客室のメタはまとめて読む）。
		update_meta_cache( 'post', $available_ids );
		$snapshot = $this->get_staff_snapshot( $available_ids );
		// メニューの料金設定は客室によらないため、ループの前に1回だけ読む。
		$plan_inputs = Lodging_Price::get_plan_inputs( $menu->ID );

		// 泊ごとの区分は客室によらないため、客室の追加料金なしで1回だけ求める（客室一覧の上に出す区分の表示用）。
		$stay_rates = Lodging_Price_Display::build_stay_display(
			$plan_inputs,
			$guest_tiers,
			Lodging_Booking::calculate_price( $menu->ID, 0, $check_in_date, $nights, $guests, $guest_tiers, $plan_inputs )['night_breakdown']
		);

		$rooms = array();
		foreach ( $available_ids as $room_id ) {
			// 予約の下書き・確定と同じ計算を通し、空室一覧の合計と確定する合計を一致させる。
			$price = Lodging_Booking::calculate_price( $menu->ID, $room_id, $check_in_date, $nights, $guests, $guest_tiers, $plan_inputs );

			// *_per_night はいずれも部屋全体の1泊分（人数の掛け算済み）。泊ごとに金額が違うときは null。
			// price_unit は料金単位の設定値で、表示の補足用（合計の再計算には使わない）。
			$rooms[] = array(
				'id'                       => $room_id,
				'name'                     => (string) ( $snapshot[ $room_id ]['name'] ?? '' ),
				'price_unit'               => $price['price_unit'],
				'plan_price_per_night'     => $price['plan_price_per_night'],
				'room_surcharge_per_night' => $price['room_surcharge_per_night'],
				'price_per_night'          => $price['price_per_night'],
				'night_breakdown'          => $price['night_breakdown'],
				'total_price'              => $price['total'],
			);
		}

		$payload = array(
			'check_in_date'      => $check_in_date,
			'check_out_date'     => $check_out_date,
			'nights'             => $nights,
			'timezone'           => $timezone->getName(),
			'check_in_time'      => $check_in_time,
			'check_out_time'     => $check_out_time,
			'rooms'              => $rooms,
			// 休前日・特定日の料金を設定したプランだけ true。false のときは画面に区分も内訳も出さない。
			'rate_tiers_enabled' => $stay_rates['enabled'],
			'night_rates'        => $stay_rates['night_rates'],
			'meta'               => array(
				'menu_id'      => $menu->ID,
				'resource_id'  => $resource_id > 0 ? $resource_id : null,
				'guests'       => $guests,
				'generated_at' => $this->current_timestamp_iso( $timezone ),
			),
		);

		return $payload;
	}

	/**
	 * 検証済みの滞在について、期間のすべての泊で空いている客室の ID を返す（短時間キャッシュ付き）。
	 *
	 * 泊専用のキャッシュ（時間枠の calendar / daily とは別の区分）を使う。空き状況の問い合わせ
	 * （get_lodging_availability()）と、絞り込み検索の日程指定（is_stay_bookable_for_menu()）が同じ結果を共有する。
	 *
	 * @param array<string, mixed> $stay               resolve_lodging_stay() が返した検証済みの滞在の情報。
	 * @param array<int>|null      $candidate_room_ids 空室を調べる客室。null は滞在の情報にある全客室。
	 * @return array<int, int> 空室の客室 ID。
	 */
	private function find_available_room_ids( array $stay, ?array $candidate_room_ids = null ): array {
		$menu           = $stay['menu'];
		$room_ids       = $candidate_room_ids ?? $stay['room_ids'];
		$check_in_date  = $stay['check_in_date'];
		$check_out_date = $stay['check_out_date'];
		$check_in_time  = Service_Menu_Post_Type::get_check_in_time( $menu->ID );
		$check_out_time = Service_Menu_Post_Type::get_check_out_time( $menu->ID );

		$cache_key = $this->build_lodging_cache_key( $menu->ID, $room_ids, $check_in_date, $check_out_date, $stay['timezone']->getName() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$available_ids = $stay['strategy']->get_available_room_ids( $room_ids, $check_in_date, $check_out_date, $check_in_time, $check_out_time );
		set_transient( $cache_key, $available_ids, MINUTE_IN_SECONDS );

		return $available_ids;
	}

	/**
	 * 絞り込み検索の日程指定で、泊単位のプランがその日程で予約できるかを返す。
	 *
	 * 予約の下書き・確定と同じ検証（宿泊が有効か・泊のプランか・日付・泊数の上限・受付上限日数・予約受付締切・客室）を通し、
	 * 通った日程だけ空室の有無を見る。検証に通らない日程（受付上限や締切を過ぎた日程を含む）は予約できない扱い。
	 * 空室の判定は get_lodging_availability() と同じ処理（Night_Strategy::get_available_room_ids()）とキャッシュを使う。
	 *
	 * @param int                  $menu_id        メニュー投稿 ID。
	 * @param string               $check_in_date  チェックイン日（Y-m-d）。
	 * @param string               $check_out_date チェックアウト日（Y-m-d）。
	 * @param array<string, mixed> $args           resource_id（指名した客室の ID）、resource_tag_ids（リソースタグのターム ID 配列。AND 条件）。
	 * @return bool 空室が1室以上あり、予約できる日程なら true。
	 */
	public function is_stay_bookable_for_menu( int $menu_id, string $check_in_date, string $check_out_date, array $args = array() ): bool {
		// 予約の下書き・確定と同じ検証。通らない日程は予約できない。
		$stay = $this->resolve_lodging_stay(
			array(
				'menu_id'        => $menu_id,
				'check_in_date'  => $check_in_date,
				'check_out_date' => $check_out_date,
				'resource_id'    => $this->resolve_preferred_staff_id( $args ),
			)
		);
		if ( is_wp_error( $stay ) ) {
			return false;
		}

		// リソースタグを選んでいるときは、タグをすべて持つ客室だけを空室の候補にする（時間枠のプランと同じ AND 条件）。
		$room_ids = $stay['room_ids'];
		$tag_ids  = $this->normalize_tag_ids( $args['resource_tag_ids'] ?? array() );
		if ( ! empty( $tag_ids ) ) {
			$room_ids = $this->filter_staff_ids_by_tags( $room_ids, $tag_ids );
		}
		if ( empty( $room_ids ) ) {
			return false;
		}

		return array() !== $this->find_available_room_ids( $stay, $room_ids );
	}

	/**
	 * 泊単位の問い合わせ・予約に共通の検証を行い、検証済みの滞在の情報を返す。
	 *
	 * 引数: menu_id, resource_id（任意。指名された客室）, check_in_date, check_out_date（いずれも Y-m-d）。
	 * 宿泊が有効なサイトか、泊単位のメニューか、日付（実在・順序・泊数の上限・今日以降かつ受付上限日数内。
	 * サイトのタイムゾーンで判定）、予約受付締切（チェックイン日時がメニューの締切の内側なら 400 の reservation_deadline_passed）、
	 * 客室（メニューの担当に含まれ、公開済み）を確認する。
	 * 空き状況の問い合わせと、予約の下書き・確定が同じ判定を通るようにするために切り出している。
	 *
	 * @param array<string, mixed> $args 引数。
	 * @return array{menu: WP_Post, timezone: DateTimeZone, strategy: Night_Strategy, check_in_date: string, check_out_date: string, nights: int, resource_id: int, room_ids: array<int, int>}|WP_Error
	 */
	public function resolve_lodging_stay( array $args ) {
		// 宿泊が有効なサイトか（無料版を含む）。無効なら存在しない窓口として扱う。
		if ( ! Industry_Presets::supports( 'lodging' ) ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$menu = $this->validate_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		// 泊単位のメニューだけを受け付ける（時間枠のプランは拒否する）。
		if ( ! Lodging_Price::is_applicable( $menu->ID ) ) {
			return new WP_Error( 'menu_not_night_unit', __( 'This menu is not a plan billed per night.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		$timezone = wp_timezone();
		$strategy = $this->resolve_night_strategy();

		// 日付の検証（実在する日付・チェックアウトがチェックインより後・泊数の上限・今日以降かつ受付上限日数の範囲内）。
		$check_in_date  = (string) ( $args['check_in_date'] ?? '' );
		$check_out_date = (string) ( $args['check_out_date'] ?? '' );
		$date_error     = $this->validate_lodging_dates( $menu, $check_in_date, $check_out_date, $timezone, $strategy );
		if ( null !== $date_error ) {
			return $date_error;
		}

		// 予約受付締切（空室の問い合わせでも、締切後のチェックイン日は料金つきで返さない）。
		$deadline_error = $this->validate_lodging_deadline( $menu, $check_in_date, $timezone );
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}

		$resource_id = max( 0, (int) ( $args['resource_id'] ?? 0 ) );
		$room_ids    = $this->resolve_lodging_room_ids( $menu, $resource_id );
		if ( is_wp_error( $room_ids ) ) {
			return $room_ids;
		}

		return array(
			'menu'           => $menu,
			'timezone'       => $timezone,
			'strategy'       => $strategy,
			'check_in_date'  => $check_in_date,
			'check_out_date' => $check_out_date,
			'nights'         => count( $strategy->get_night_dates( $check_in_date, $check_out_date ) ),
			'resource_id'    => $resource_id,
			'room_ids'       => $room_ids,
		);
	}

	/**
	 * 泊単位の問い合わせの日付を検証する。
	 *
	 * 次をすべて確認する: 実在する日付（checkdate）、チェックアウトがチェックインより後、泊数が上限以内、
	 * チェックインが今日以降（サイトのタイムゾーン）、チェックインがメニューの受付上限日数の範囲内。
	 *
	 * @param WP_Post        $menu           メニュー投稿。
	 * @param string         $check_in_date  チェックイン日（Y-m-d）。
	 * @param string         $check_out_date チェックアウト日（Y-m-d）。
	 * @param DateTimeZone   $timezone       サイトのタイムゾーン。
	 * @param Night_Strategy $strategy       泊戦略。
	 * @return WP_Error|null 問題があればエラー。なければ null。
	 */
	private function validate_lodging_dates( WP_Post $menu, string $check_in_date, string $check_out_date, DateTimeZone $timezone, Night_Strategy $strategy ): ?WP_Error {
		foreach ( array( $check_in_date, $check_out_date ) as $date ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) || (int) $matches[1] < 2000 || (int) $matches[1] > 2100 || ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return new WP_Error( 'invalid_date', __( 'The selected date is not valid. Please choose the check-in and check-out dates again.', 'vk-booking-manager' ), array( 'status' => 400 ) );
			}
		}

		// チェックアウト日の順序と泊数の上限（絞り込み検索と同じ判定）。
		$range_error = Night_Strategy::validate_stay_range( $check_in_date, $check_out_date );
		if ( null !== $range_error ) {
			return $range_error;
		}

		// チェックインは今日以降（サイトのタイムゾーンの今日）。
		$today = ( new DateTimeImmutable( 'now', $timezone ) )->format( 'Y-m-d' );
		if ( $check_in_date < $today ) {
			return new WP_Error( 'date_in_past', __( 'The check-in date has already passed. Please choose today or a later date.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		$menu_settings = $this->get_menu_settings( $menu );
		if ( ! $this->is_within_max_advance_days( $menu_settings, $check_in_date, $timezone ) ) {
			return new WP_Error(
				'date_beyond_max_advance',
				sprintf(
					/* translators: %d: number of days ahead that reservations are accepted. */
					__( 'Reservations are accepted up to %d days ahead. Please choose an earlier date.', 'vk-booking-manager' ),
					$menu_settings['max_advance_days']
				),
				array(
					'status'           => 400,
					'max_advance_days' => $menu_settings['max_advance_days'],
				)
			);
		}

		return null;
	}

	/**
	 * 泊単位の予約受付締切を検証する。
	 *
	 * チェックイン日時（チェックイン日 + メニューのチェックイン時刻。サイトのタイムゾーン）が
	 * 「今 + メニューの締切の時間」より前なら受け付けない。
	 *
	 * @param WP_Post      $menu          メニュー投稿。
	 * @param string       $check_in_date チェックイン日（Y-m-d。検証済み）。
	 * @param DateTimeZone $timezone      サイトのタイムゾーン。
	 * @return WP_Error|null 締切を過ぎていればエラー。なければ null。
	 */
	private function validate_lodging_deadline( WP_Post $menu, string $check_in_date, DateTimeZone $timezone ): ?WP_Error {
		$check_in_at = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $check_in_date . ' ' . Service_Menu_Post_Type::get_check_in_time( $menu->ID ), $timezone );
		if ( ! $check_in_at ) {
			// 日付は検証済みで時刻も HH:MM に正規化済みのため、実際には到達しない想定。
			return null;
		}

		$menu_settings = $this->get_menu_settings( $menu );
		$cutoff        = current_datetime()->setTimezone( $timezone )->modify( sprintf( '+%d hours', (int) $menu_settings['deadline_hours'] ) );
		if ( $check_in_at < $cutoff ) {
			return new WP_Error(
				'reservation_deadline_passed',
				__( 'The reservation deadline for this check-in date has passed. Please choose a later date.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * リソース ID を、公開済みの vkbm_resource だけに絞る（#647）。
	 *
	 * 1回の問い合わせで絞り、渡された並び（post__in）を保つ。泊（resolve_lodging_room_ids()）と
	 * 時間枠（resolve_staff_ids()）の両方で使い、公開済み判定を二重実装しない。
	 *
	 * @param array<int> $resource_ids 候補のリソース ID。
	 * @return array<int> 公開済みのリソース ID（該当なしは空配列）。
	 */
	private function filter_published_resource_ids( array $resource_ids ): array {
		$resource_ids = array_values( array_filter( array_map( 'intval', $resource_ids ) ) );
		if ( empty( $resource_ids ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => Resource_Post_Type::POST_TYPE,
				'post_status'            => 'publish',
				'post__in'               => $resource_ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * 泊単位の問い合わせの対象客室 ID を求める。
	 *
	 * メニューの担当リソースのうち、リソースの投稿の種類で公開済みのものだけを対象にする。
	 * 客室が指名された場合は、メニューの担当に含まれ、公開済みであることを必須にする
	 * （担当が空のとき指名された ID をそのまま受け付ける時間枠の経路とは異なる）。
	 *
	 * @param WP_Post $menu        メニュー投稿。
	 * @param int     $resource_id 指名された客室の ID（0 は指名なし）。
	 * @return array<int, int>|WP_Error 対象の客室 ID。
	 */
	private function resolve_lodging_room_ids( WP_Post $menu, int $resource_id ) {
		$assignable = Service_Menu_Post_Type::get_assignable_staff_ids( $menu->ID );

		if ( $resource_id > 0 ) {
			if ( ! in_array( $resource_id, $assignable, true ) ) {
				return new WP_Error(
					'staff_not_assigned',
					sprintf(
					/* translators: %s: resource label (singular), e.g. "Room". */
						__( 'The specified %s cannot be in charge of this menu.', 'vk-booking-manager' ),
						vkbm_get_resource_label_singular()
					),
					array( 'status' => 400 )
				);
			}
			$assignable = array( $resource_id );
		}

		// 公開済みのリソースだけに絞る（1回の問い合わせで、担当の並びを保つ）。
		$published = $this->filter_published_resource_ids( $assignable );

		if ( $resource_id > 0 && empty( $published ) ) {
			return new WP_Error(
				'resource_not_found',
				sprintf(
					/* translators: %s: resource label (singular), e.g. "Room". */
					__( 'The specified %s was not found.', 'vk-booking-manager' ),
					vkbm_get_resource_label_singular()
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $published ) ) {
			return new WP_Error(
				'staff_not_configured',
				sprintf(
					/* translators: %s: resource label (plural), e.g. "Rooms". */
					__( 'No %s have been set up to be in charge.', 'vk-booking-manager' ),
					vkbm_get_resource_label_plural()
				),
				array( 'status' => 400 )
			);
		}

		return $published;
	}

	/**
	 * 泊の空き状況のキャッシュ名を作る。
	 *
	 * 時間枠の calendar / daily（build_cache_key()）とは別の区分（vkbm_lodging_...）にする。
	 * サイト全体の世代番号に加えて、期間に含まれる全日（チェックイン日〜チェックアウト日）の予約の世代番号を
	 * 混ぜる。チェックイン日だけを見ると、期間の途中の日に入った予約でキャッシュが消えず、
	 * 埋まった客室が空きとして表示される。名前の長さは日数によらないよう、日ごとの世代番号は md5 にまとめる。
	 *
	 * @param int        $menu_id        メニュー ID。
	 * @param array<int> $room_ids       対象の客室 ID。
	 * @param string     $check_in_date  チェックイン日（Y-m-d）。
	 * @param string     $check_out_date チェックアウト日（Y-m-d）。
	 * @param string     $timezone       タイムゾーン名。
	 * @return string キャッシュ名。
	 */
	private function build_lodging_cache_key( int $menu_id, array $room_ids, string $check_in_date, string $check_out_date, string $timezone ): string {
		$generation = Availability_Cache_Generation::get_generation();

		return sprintf(
			'vkbm_lodging_g%d_bg%s_%d_%s_%s_%s_%s',
			$generation,
			Availability_Booking_Cache_Generation::get_range_generation_hash( $check_in_date, $check_out_date ),
			$menu_id,
			md5( implode( '-', $room_ids ) ),
			$check_in_date,
			$check_out_date,
			md5( $timezone )
		);
	}

	/**
	 * 泊戦略を返す。未生成なら生成する。
	 *
	 * @return Night_Strategy 泊戦略。
	 */
	private function resolve_night_strategy(): Night_Strategy {
		if ( null === $this->night_strategy ) {
			$this->night_strategy = new Night_Strategy();
		}

		return $this->night_strategy;
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
