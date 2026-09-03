<?php
/**
 * REST controller that finalizes reservation drafts into confirmed bookings.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Exclusive_Fee;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Query;
use WP_User;
use function __;
use function delete_post_meta;
use function delete_transient;
use function get_post;
use function get_post_meta;
use function get_transient;
use function get_users;
use function is_user_logged_in;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function current_user_can;
use function get_current_user_id;
use function vkbm_get_resource_label_singular;
use function wp_get_current_user;
use function wp_insert_post;
use function update_post_meta;
use function wp_date;
use function strtotime;
use function wp_unslash;

/**
 * REST controller that finalizes reservation drafts into confirmed bookings.
 */
class Booking_Confirmation_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';
	private const DRAFT_PREFIX   = 'vkbm_draft_';

	private const META_DATE_START                = '_vkbm_booking_service_start';
	private const META_DATE_END                  = '_vkbm_booking_service_end';
	private const META_RESOURCE_ID               = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID                = '_vkbm_booking_service_id';
	private const META_CUSTOMER                  = '_vkbm_booking_customer_name';
	private const META_CUSTOMER_TEL              = '_vkbm_booking_customer_tel';
	private const META_CUSTOMER_MAIL             = '_vkbm_booking_customer_email';
	private const META_STATUS                    = '_vkbm_booking_status';
	private const META_NOTE                      = '_vkbm_booking_note';
	private const META_INTERNAL_NOTE             = '_vkbm_booking_internal_note';
	private const META_IS_PREFERRED              = '_vkbm_booking_is_staff_preferred';
	private const META_NOMINATION_FEE            = '_vkbm_booking_nomination_fee';
	private const META_DATE_TOTAL_END            = '_vkbm_booking_total_end';
	private const META_SERVICE_BASE_PRICE        = '_vkbm_booking_service_base_price';
	private const META_BASE_TOTAL_PRICE          = '_vkbm_booking_base_total_price';
	private const META_GUESTS                    = '_vkbm_booking_guests';
	private const META_GUEST_TIERS               = '_vkbm_booking_guest_tiers';
	private const META_EXCLUSIVE                 = '_vkbm_booking_exclusive';
	private const META_EXCLUSIVE_FEE             = '_vkbm_booking_exclusive_fee';
	private const MENU_META_PRICE_TIERS          = '_vkbm_price_tiers';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	// メタキーの定義元は Reservation_Day（single source of truth）。
	private const MENU_META_RESERVATION_CUSTOM_WEEKDAYS = Reservation_Day::META_CUSTOM_WEEKDAYS;
	private const MENU_META_RESERVATION_CUSTOM_DATES    = Reservation_Day::META_CUSTOM_DATES;
	private const MENU_META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const MENU_META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const MENU_META_ALLOW_GUESTS                = '_vkbm_allow_multiple_guests';
	private const MENU_META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';
	private const MENU_META_EXCLUSIVE_USER_SELECTABLE   = '_vkbm_exclusive_user_selectable';
	private const MENU_META_EXCLUSIVE_FEE_PER_PERSON    = '_vkbm_exclusive_fee_per_person';
	private const MENU_META_EXCLUSIVE_FEE_EXEMPT        = '_vkbm_exclusive_fee_exempt_guests';
	private const BOOKING_STATUS_CONFIRMED              = 'confirmed';
	private const BOOKING_STATUS_PENDING                = 'pending';
	private const BOOKING_STATUS_CANCELLED              = 'cancelled';
	private const BOOKING_STATUS_NO_SHOW                = 'no_show';
	private const OWNER_COOKIE                          = 'vkbm_draft_owner';

	/**
	 * Transient-based mutex lock timeout in seconds.
	 *
	 * ロック保持区間（wp_insert_post＋予約メタ保存）が万一遅延しても、その途中で
	 * 別リクエストに stale ロックとして奪取され二重に capacity チェックを通過する
	 * （＝オーバーブッキングする）ことのないよう、PHP の既定 max_execution_time（30秒）に
	 * 合わせて設定する。これにより元リクエストが生存し得る間はロックを奪われない。
	 *
	 * @var int
	 */
	private const MUTEX_TIMEOUT = 30;

	/**
	 * Availability service.
	 *
	 * @var Availability_Service
	 */
	private $availability_service;

	/**
	 * Notification handler.
	 *
	 * @var Booking_Notification_Service
	 */
	private $notification_service;

	/**
	 * Provider settings repository.
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Booking_Notification_Service $notification_service Notification handler.
	 * @param Settings_Repository          $settings_repository  Provider settings repository.
	 * @param Availability_Service|null    $availability_service Availability service.
	 */
	public function __construct(
		Booking_Notification_Service $notification_service,
		Settings_Repository $settings_repository,
		?Availability_Service $availability_service = null
	) {
		$this->notification_service = $notification_service;
		$this->settings_repository  = $settings_repository;
		$this->availability_service = null !== $availability_service ? $availability_service : new Availability_Service( $settings_repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				// Booking creation requires an authenticated user.
				// 予約作成はログインユーザーのみ許可する。
				'permission_callback' => array( $this, 'check_create_booking_permission' ),
			)
		);
	}

	/**
	 * Permission callback for POST /bookings.
	 *
	 * 予約作成エンドポイントの認可コールバック。ログイン済みのユーザーのみ許可する。
	 *
	 * @return bool|WP_Error
	 */
	public function check_create_booking_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'not_logged_in',
				__( 'Login required.', 'vk-booking-manager' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Finalize a reservation temporary data into a booking post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( WP_REST_Request $request ) {
		// Defense in depth: the permission_callback already enforces login,
		// but we re-check here so direct callers (e.g. tests) still get a 401.
		// 多重防御: permission_callback で既にチェック済みだが、
		// 直接呼び出された場合（テスト等）にも 401 を返すため再チェックする。
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$token = $this->sanitize_token( $request['token'] ?? '' );
		if ( '' === $token ) {
			return new WP_Error(
				'missing_token',
				__( 'Temporary reservation data token not found.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = get_transient( $this->build_transient_key( $token ) );
		if ( false === $draft ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		if ( ! is_array( $draft ) ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = $this->backfill_draft_owner( $token, $draft );
		if ( ! $this->can_access_draft( $draft ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		$menu_id  = isset( $draft['menu_id'] ) ? (int) $draft['menu_id'] : 0;
		$staff_id = isset( $draft['resource_id'] ) ? (int) $draft['resource_id'] : 0;
		$slot     = isset( $draft['slot'] ) && is_array( $draft['slot'] ) ? $draft['slot'] : array();
		$start_at = isset( $slot['start_at'] ) ? (string) $slot['start_at'] : '';
		$end_at   = isset( $slot['end_at'] ) ? (string) $slot['end_at'] : '';

		if ( $menu_id <= 0 || '' === $start_at ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// 料金区分が定義されているメニューは、人数を区分ごとの内訳から確定する。
		// ラベル・料金は必ずサーバ保存メタを正とし、下書きに保存済みの人数のみ突き合わせる（改竄防止）。
		$menu_price_tiers = $this->resolve_menu_price_tiers( $menu_id );
		$guest_tiers      = array();
		if ( ! empty( $menu_price_tiers ) ) {
			$stored_tiers = isset( $draft['guest_tiers'] ) && is_array( $draft['guest_tiers'] ) ? $draft['guest_tiers'] : array();
			$guest_tiers  = Price_Tiers::resolve_guest_tiers( $menu_price_tiers, $stored_tiers );
			// 区分人数の合計を予約人数とする（枠消費・後方互換集計に使う）。
			// 全区分0名（合計0）の予約は成立させない。max() で1名に丸めると0名予約が確定してしまうため、
			// 合計1名未満は明示的にエラーで拒否する（下書き保存時のバリデーションと対称）。
			$guests = Price_Tiers::total_count( $guest_tiers );
			if ( $guests < 1 ) {
				return new WP_Error(
					'guests_required',
					__( 'Please select at least one guest.', 'vk-booking-manager' ),
					array( 'status' => 400 )
				);
			}
		} else {
			// 予約人数（複数人一括予約）を確定する。メニュー設定に応じて 1〜最大人数にクランプする。
			$guests = $this->resolve_guests( $menu_id, isset( $draft['guests'] ) ? (int) $draft['guests'] : 1 );
		}

		// ユーザーによる貸し切り指定（#305）。下書きに保存されたユーザー選択フラグを取得する。
		// フロントの抑止は迂回可能なため、サーバ側でフルゲート（メニュー設定・最小催行人数・空き枠）を再判定する。
		$user_requested_exclusive = ! empty( $draft['user_exclusive'] );
		$user_exclusive           = false;
		$exclusive_fee            = 0;
		if ( $user_requested_exclusive ) {
			if ( ! $this->is_user_exclusive_selectable( $menu_id ) ) {
				// 利用者が下書きで明示的に貸し切りを指定したのに、確定時点でメニュー設定が
				// 貸し切り不可（受付OFF・予約枠の定員機能の無効化・指名ON等）に変わっている場合は、
				// 黙って通常予約に変換せず 409 で拒否し、再選択を促す（明示的な意思を失わせない）。
				return new WP_Error(
					'exclusive_unavailable',
					__( 'This slot can no longer be reserved exclusively. Please review your selection.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			} else {
				// 最小催行人数（>=）を満たさない場合は貸し切り指定を拒否する。
				// 正規ヘルパ経由で取得し、最大受付数1以下のメニューでは実効0（判定無効）になるようにする（#320）。
				$min_capacity = $this->get_menu_min_capacity( $menu_id );
				if ( $min_capacity > 0 && $guests < $min_capacity ) {
					return new WP_Error(
						'exclusive_min_capacity',
						__( 'The number of guests does not reach the minimum required for a private booking.', 'vk-booking-manager' ),
						array( 'status' => 400 )
					);
				}
				$user_exclusive = true;
				// 貸し切り料金をサーバ保存メタから権威的に再計算する（フロント送信額は信用しない）。
				$exclusive_fee = $this->calculate_exclusive_fee( $menu_id, $guests );
			}
		}

		$reservation_day_type = (string) get_post_meta( $menu_id, self::MENU_META_RESERVATION_DAY_TYPE, true );
		if ( '' !== $reservation_day_type && ! $this->is_reservation_day_allowed( $reservation_day_type, $start_at, $menu_id ) ) {
			return new WP_Error(
				'invalid_reservation_day',
				__( 'The selected date cannot be reserved. Please choose another date.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$timezone = '';
		if ( isset( $draft['meta'] ) && is_array( $draft['meta'] ) ) {
			$timezone = sanitize_text_field( (string) ( $draft['meta']['timezone'] ?? '' ) );
		}

		// Re-check availability for the selected slot before confirming. / 予約確定前に空きを再検証します.
		$available_slot = $this->revalidate_draft_slot( $menu_id, $staff_id, $slot, $timezone );
		if ( is_wp_error( $available_slot ) ) {
			return $available_slot;
		}

		// Use the latest slot snapshot for staff assignment checks. / 最新の空き情報に基づいて指名判定を行います.
		if ( isset( $available_slot['staff'] ) && is_array( $available_slot['staff'] ) ) {
			$slot['staff'] = $available_slot['staff'];
		}

		$assignable_staff = $this->normalize_assignable_staff_ids( $available_slot['assignable_staff_ids'] ?? array() );

		$is_staff_preferred = ! empty( $draft['is_staff_preferred'] );

		// 自動割当スロットでは、確定直前のロック保持下で単一スタッフへベストフィット割り当てする（分割しない）。
		$is_auto_assign = ! empty( $available_slot['auto_assign'] );

		if ( $is_staff_preferred && $staff_id > 0 ) {
			// 指名予約：選択された1名のスタッフへ割り当てる。
			$is_staff_preferred = true;
		} elseif ( ! empty( $assignable_staff ) ) {
			// 自動割当：スタッフの確定はロック保持下のベストフィット判定（check_capacity_with_mutex）で行う。
			$is_staff_preferred = false;
		} elseif ( $staff_id <= 0 && isset( $slot['staff']['id'] ) ) {
			$staff_id = (int) $slot['staff']['id'];
		}

		// 無料版では選択可能スタッフの制限を解除.
		// 指名予約で選択スタッフが候補外なら拒否する（自動割当はロック保持下で確定するため対象外）。
		if ( Staff_Editor::is_enabled() && $is_staff_preferred && $staff_id > 0 && ! empty( $assignable_staff ) && ! in_array( $staff_id, $assignable_staff, true ) ) {
			return new WP_Error(
				'staff_unavailable',
				__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// 指名予約はこの時点でスタッフが確定している必要がある。
		// 自動割当はロック保持下のベストフィット判定でスタッフを確定するため、ここではチェックしない。
		if ( $is_staff_preferred && $staff_id <= 0 ) {
			$singular = vkbm_get_resource_label_singular();
			return new WP_Error(
				'staff_assignment_failed',
				sprintf(
					/* translators: %s: Resource label (singular). */
					__( 'Could not assign person %s. Please try a different frame.', 'vk-booking-manager' ),
					$singular
				),
				array( 'status' => 409 )
			);
		}

			$user               = wp_get_current_user();
			$memo               = sanitize_textarea_field( (string) ( $request['memo'] ?? '' ) );
			$agree              = ! empty( $request['agree_terms'] );
			$agree_cancellation = $request->has_param( 'agree_cancellation_policy' )
				? ! empty( $request['agree_cancellation_policy'] )
				: $agree;
			$agree_tos          = $request->has_param( 'agree_terms_of_service' )
				? ! empty( $request['agree_terms_of_service'] )
				: $agree;
		$can_override_contact   = current_user_can( Capabilities::MANAGE_RESERVATIONS );
		$customer_name_override = $can_override_contact
			? sanitize_text_field( (string) ( $request['customer_name'] ?? '' ) )
			: '';
		// 電話番号を正規化する：全角数字を半角に変換し、数字以外の文字（ハイフン・スペース・括弧など）を除去する.
		$customer_phone      = $can_override_contact
			? VKBM_Helper::normalize_phone_number( sanitize_text_field( (string) ( $request['customer_phone'] ?? '' ) ) )
			: VKBM_Helper::normalize_phone_number( $this->get_user_phone_number( $user->ID ) );
		$internal_note       = $can_override_contact
			? sanitize_textarea_field( (string) ( $request['internal_note'] ?? '' ) )
			: '';
		$customer_name_value = '' !== $customer_name_override ? $customer_name_override : VKBM_Helper::get_user_display_name( $user );
		$booking_author_id   = (int) $user->ID;
		$customer_email      = (string) $user->user_email;
		$matched_user_id     = 0;

		if ( $can_override_contact ) {
			// $customer_phone は既に正規化済みのためそのままユーザー検索に使用する.
			if ( '' !== $customer_phone ) {
				// Assign booking author by matching phone number when possible.
				// 電話番号が一致するユーザーがいれば予約投稿者を割り当てる.
				$matched_user = $this->get_user_by_phone_number( $customer_phone );
				if ( $matched_user instanceof WP_User ) {
					$booking_author_id = (int) $matched_user->ID;
					$customer_email    = (string) $matched_user->user_email;
					$matched_user_id   = (int) $matched_user->ID;
				}
			}

			if ( 0 === $matched_user_id ) {
				$customer_email = '';
			}
		}

		$settings              = $this->settings_repository->get_settings();
		$requires_cancellation = '' !== trim( (string) ( $settings['provider_cancellation_policy'] ?? '' ) );
		$requires_tos          = '' !== trim( (string) ( $settings['provider_terms_of_service'] ?? '' ) );
		$status_mode           = sanitize_key( (string) ( $settings['provider_booking_status_mode'] ?? self::BOOKING_STATUS_CONFIRMED ) );
		$initial_status        = self::BOOKING_STATUS_PENDING === $status_mode ? self::BOOKING_STATUS_PENDING : self::BOOKING_STATUS_CONFIRMED;

		if ( $can_override_contact ) {
			$requires_cancellation = false;
			$requires_tos          = false;
			$initial_status        = self::BOOKING_STATUS_CONFIRMED;
		}

		if ( $requires_cancellation && ! $agree_cancellation ) {
			return new WP_Error(
				'cancellation_policy_required',
				__( 'You must agree to the cancellation policy.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( $requires_tos && ! $agree_tos ) {
			return new WP_Error(
				'terms_required',
				__( 'You must agree to the terms of use.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $can_override_contact && $this->has_user_conflict( (int) $user->ID, $start_at, $end_at ) ) {
			return new WP_Error(
				'booking_time_conflict',
				__( 'A reservation for the same date and time already exists. Please change the date and time.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}
		if ( $can_override_contact && $matched_user_id > 0 && $this->has_user_conflict( $matched_user_id, $start_at, $end_at ) ) {
			return new WP_Error(
				'booking_time_conflict',
				__( 'A reservation for the same date and time already exists. Please change the date and time.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// 予約確定直前に capacity 上限を再チェック（簡易排他制御付き）。
		// 自動割当時はロック保持下で、人数を収容できる単一スタッフをベストフィットで確定する（分割しない）。
		// Re-check capacity just before confirming, with transient-based mutex.
		// For auto-assignment, pick a single best-fit staff under the lock (no splitting).
		$capacity_check = $this->check_capacity_with_mutex( $menu_id, $staff_id, $start_at, $end_at, $guests, $is_staff_preferred, $is_auto_assign ? $assignable_staff : array(), $user_exclusive );
		if ( is_wp_error( $capacity_check ) ) {
			return $capacity_check;
		}

		// 取得したロック（ロック名＋所有者トークン）は予約投稿の作成・メタ保存まで保持し、後段で必ず解放する。
		$slot_lock_name  = (string) $capacity_check['name'];
		$slot_lock_token = (string) $capacity_check['token'];
		// 自動割当時はベストフィットで確定したスタッフIDを採用する。指名予約は渡した staff_id がそのまま返る。
		$staff_id = (int) $capacity_check['staff_id'];
		if ( $staff_id <= 0 ) {
			$this->release_slot_mutex( $slot_lock_name, $slot_lock_token );
			$singular = vkbm_get_resource_label_singular();
			return new WP_Error(
				'staff_assignment_failed',
				sprintf(
					/* translators: %s: Resource label (singular). */
					__( 'Could not assign person %s. Please try a different frame.', 'vk-booking-manager' ),
					$singular
				),
				array( 'status' => 409 )
			);
		}

		// 予約投稿の作成・メタ保存の途中で例外が発生してもロックが残らないよう、
		// ロック取得から全メタ保存までを try/finally で囲み、finally で必ず解放する。
		try {
			$booking_id = wp_insert_post(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $this->generate_booking_title( $customer_name_value, $start_at ),
					'post_author' => $booking_author_id,
				)
			);

			if ( is_wp_error( $booking_id ) ) {
				return $booking_id;
			}
			// wp_insert_post() は失敗時に WP_Error だけでなく 0 を返す場合がある。
			// 0 も失敗として扱い、無効な投稿IDでメタ保存に進んだり成功レスポンスを返したりしないようにする。
			if ( 0 === $booking_id ) {
				return new WP_Error(
					'booking_creation_failed',
					__( 'Failed to create the booking. Please try again.', 'vk-booking-manager' ),
					array( 'status' => 500 )
				);
			}

			$start_for_storage     = $this->format_datetime_for_storage( $start_at );
			$end_for_storage       = $this->format_datetime_for_storage( $end_at );
			$service_end_at        = isset( $slot['service_end_at'] ) ? (string) $slot['service_end_at'] : '';
			$service_end_for_store = $this->format_datetime_for_storage( $service_end_at );

			$start_meta_value = '' !== $start_for_storage ? $start_for_storage : $start_at;
			update_post_meta( $booking_id, self::META_DATE_START, $start_meta_value );

			$service_end_value = '' !== $service_end_for_store ? $service_end_for_store : ( '' !== $service_end_at ? $service_end_at : ( '' !== $end_for_storage ? $end_for_storage : $end_at ) );
			if ( $service_end_value ) {
				update_post_meta( $booking_id, self::META_DATE_END, $service_end_value );
			}
			if ( '' !== $end_for_storage || '' !== $end_at ) {
				$total_end_value = '' !== $end_for_storage ? $end_for_storage : $end_at;
				update_post_meta( $booking_id, self::META_DATE_TOTAL_END, $total_end_value );
			}
			if ( $staff_id > 0 ) {
				update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
			}
			update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
			// 予約人数を保存する（複数人一括予約）。単価×人数または区分料金合計の計算に用いる。
			update_post_meta( $booking_id, self::META_GUESTS, $guests );
			// 貸し切り予約フラグを保存する。
			// (1) 設定ON（_vkbm_exclusive_when_booked）のメニューでは予約確定時に自動で true を付与する（#304）。
			// (2) ユーザーによる貸し切り指定（#305）でユーザーが貸切を選択し、フルゲートを通過した場合も true を付与する。
			// いずれかが成立すればこの予約が枠を専有する（以降その枠は他のユーザーが予約できなくなる）。
			if ( $this->is_menu_exclusive_when_booked( $menu_id ) || $user_exclusive ) {
				update_post_meta( $booking_id, self::META_EXCLUSIVE, true );
			} else {
				delete_post_meta( $booking_id, self::META_EXCLUSIVE );
			}
			// 貸し切り料金（#305）のスナップショットを保存する。メニュー側の単価を後で変えても
			// 過去予約の金額が動かないよう、確定時の金額を予約レコードに保存する（base_price スナップショットと同思想）。
			// 0（ユーザー貸切なし／適用外人数到達等）の場合はメタを削除する。
			if ( $exclusive_fee > 0 ) {
				update_post_meta( $booking_id, self::META_EXCLUSIVE_FEE, $exclusive_fee );
			} else {
				delete_post_meta( $booking_id, self::META_EXCLUSIVE_FEE );
			}
			// 料金区分の人数内訳スナップショットを保存する（区分未定義なら削除する＝従来表示）。
			if ( ! empty( $guest_tiers ) ) {
				update_post_meta( $booking_id, self::META_GUEST_TIERS, $guest_tiers );
			} else {
				delete_post_meta( $booking_id, self::META_GUEST_TIERS );
			}
			$service_base_price = (int) get_post_meta( $menu_id, '_vkbm_base_price', true );
			$service_base_price = max( 0, $service_base_price );
			update_post_meta( $booking_id, self::META_SERVICE_BASE_PRICE, $service_base_price );
			update_post_meta( $booking_id, self::META_CUSTOMER, $customer_name_value );
			update_post_meta( $booking_id, self::META_CUSTOMER_MAIL, $customer_email );
			if ( '' !== $customer_phone ) {
				update_post_meta( $booking_id, self::META_CUSTOMER_TEL, $customer_phone );
			} else {
				delete_post_meta( $booking_id, self::META_CUSTOMER_TEL );
			}
			update_post_meta( $booking_id, self::META_STATUS, $initial_status );
			update_post_meta( $booking_id, self::META_IS_PREFERRED, $is_staff_preferred ? '1' : '' );
			update_post_meta( $booking_id, '_vkbm_booking_agreed_cancellation_policy', $agree_cancellation ? '1' : '' );
			update_post_meta( $booking_id, '_vkbm_booking_agreed_terms_of_service', $agree_tos ? '1' : '' );
			$nomination_fee = isset( $draft['nomination_fee'] ) ? (int) $draft['nomination_fee'] : 0;
			if ( ! Staff_Editor::is_nomination_enabled() ) {
				$nomination_fee = 0;
			}
			$disable_nomination_fee = (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true );
			if ( '1' === $disable_nomination_fee ) {
				$nomination_fee = 0;
			} elseif ( $nomination_fee <= 0 && $is_staff_preferred && $staff_id > 0 ) {
				$nomination_fee = $this->get_staff_nomination_fee( $staff_id );
			}

			if ( $nomination_fee > 0 ) {
				update_post_meta( $booking_id, self::META_NOMINATION_FEE, $nomination_fee );
			} else {
				delete_post_meta( $booking_id, self::META_NOMINATION_FEE );
			}

			// 基本料金合計を予約確定時に数値で確定保存する。
			// 料金区分が定義されている場合は Σ(区分料金 × 区分人数) + 指名料。
			// 区分未定義は従来どおり サービス基本料金 × 人数 + 指名料（完全後方互換）。
			// 料金はクライアントから送られた金額を信用せず、サーバ保存メタを正として再計算する。
			if ( ! empty( $guest_tiers ) ) {
				$base_total_price = max( 0, Price_Tiers::total_price( $guest_tiers ) + max( 0, $nomination_fee ) + max( 0, $exclusive_fee ) );
			} else {
				$base_total_price = max( 0, ( $service_base_price * $guests ) + max( 0, $nomination_fee ) + max( 0, $exclusive_fee ) );
			}
			update_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, $base_total_price );
			if ( '' !== $memo ) {
				update_post_meta( $booking_id, self::META_NOTE, $memo );
			}
			if ( '' !== $internal_note ) {
				update_post_meta( $booking_id, self::META_INTERNAL_NOTE, $internal_note );
			} else {
				delete_post_meta( $booking_id, self::META_INTERNAL_NOTE );
			}
		} finally {
			// すべての予約メタを保存し終えた（または途中で離脱した）のでロックを解放する。
			// 以降の通知処理はロック不要。
			$this->release_slot_mutex( $slot_lock_name, $slot_lock_token );
		}

		delete_transient( $this->build_transient_key( $token ) );

		if ( self::BOOKING_STATUS_CONFIRMED === $initial_status ) {
			$this->notification_service->handle_confirmed_creation( (int) $booking_id );
		} else {
			$this->notification_service->handle_pending_creation( (int) $booking_id );
		}

		return new WP_REST_Response(
			array(
				'booking_id' => $booking_id,
				'status'     => $initial_status,
			)
		);
	}

	/**
	 * 人数 N を収容できるスタッフの中から「残りが最も少ない（きつきつの）」スタッフを選ぶ（ベストフィット）。
	 *
	 * 1予約は分割せず単一スタッフへ割り当てる。完全に空いているスタッフを温存し、
	 * スロットの空き（= 最も空きの大きい単一スタッフの残り）を高く保つことで、
	 * 後から来る大人数予約の機会を守る。残りが同じ場合は候補配列の順（スタッフ登録順）で先頭を選ぶ。
	 *
	 * @param array<int>     $candidates   候補スタッフID。
	 * @param array<int,int> $loads        staff_id => 既存予約人数。
	 * @param int            $guests       割り当てる人数。
	 * @param int            $max_capacity 1スタッフあたりの最大受付数。
	 * @return int 選ばれたスタッフID。収容できる候補が無ければ 0。
	 */
	private function select_best_fit_staff( array $candidates, array $loads, int $guests, int $max_capacity ): int {
		$guests       = max( 1, $guests );
		$max_capacity = max( 1, $max_capacity );

		$best_id        = 0;
		$best_remaining = PHP_INT_MAX;
		foreach ( $candidates as $sid ) {
			$sid = (int) $sid;
			if ( $sid <= 0 ) {
				continue;
			}
			$load      = isset( $loads[ $sid ] ) ? (int) $loads[ $sid ] : 0;
			$remaining = $max_capacity - $load;
			// このスタッフだけでは全員を収容できない場合は対象外（分割はしない）。
			if ( $remaining < $guests ) {
				continue;
			}
			// 残りが最も少ない（きつきつの）スタッフを選ぶ。同点は先頭（登録順）を保持する。
			if ( $remaining < $best_remaining ) {
				$best_remaining = $remaining;
				$best_id        = $sid;
			}
		}

		return $best_id;
	}

	/**
	 * スロット内の各スタッフの担当人数を集計する（既存予約の配分から）。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return array<int, int> staff_id => 担当人数。
	 */
	private function get_staff_loads_for_slot( int $menu_id, string $start_at, string $end_at ): array {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return array();
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

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
						'key'     => self::META_SERVICE_ID,
						'value'   => $menu_id,
						'compare' => '=',
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		$loads = array();
		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			if ( self::BOOKING_STATUS_CANCELLED === $status || self::BOOKING_STATUS_NO_SHOW === $status ) {
				continue;
			}

			// 1予約 = 単一スタッフ。担当スタッフの予約人数を加算する（未設定は1名・後方互換）。
			$sid = (int) get_post_meta( (int) $post_id, self::META_RESOURCE_ID, true );
			if ( $sid > 0 ) {
				$guests_raw    = get_post_meta( (int) $post_id, self::META_GUESTS, true );
				$g             = '' === $guests_raw ? 1 : max( 1, (int) $guests_raw );
				$loads[ $sid ] = ( $loads[ $sid ] ?? 0 ) + $g;
			}
		}

		return $loads;
	}

	/**
	 * Determine if the staff already has a booking overlapping the slot.
	 *
	 * @param int    $staff_id Staff ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_staff_conflict( int $staff_id, string $start_at, string $end_at ): bool {
		if ( $staff_id <= 0 ) {
			return true;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );

		if ( '' === $start_for_storage ) {
			return true;
		}

		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					// キャンセル・無断キャンセルの予約はスタッフ競合に含めない（実枠が空いていれば割り当て可能）。
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STATUS,
							'value'   => array(
								self::BOOKING_STATUS_CANCELLED,
								self::BOOKING_STATUS_NO_SHOW,
							),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => self::META_RESOURCE_ID,
						'value'   => $staff_id,
						'compare' => '=',
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Determine if the user already has a booking overlapping the slot.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_user_conflict( int $user_id, string $start_at, string $end_at ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );

		if ( '' === $start_for_storage ) {
			return false;
		}

		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Retrieve nomination fee for staff.
	 *
	 * @param int $staff_id Staff ID.
	 * @return int
	 */
	private function get_staff_nomination_fee( int $staff_id ): int {
		if ( $staff_id <= 0 ) {
			return 0;
		}

		if ( ! Staff_Editor::is_nomination_enabled() ) {
			return 0;
		}

		$raw = get_post_meta( $staff_id, Staff_Editor::META_NOMINATION_FEE, true );

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

			$fee = (int) $raw;

		if ( $fee <= 0 ) {
			return 0;
		}
			return $fee;
	}

	/**
	 * Build transient key name.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function build_transient_key( string $token ): string {
		return self::DRAFT_PREFIX . $token;
	}

	/**
	 * Sanitize token input.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function sanitize_token( string $token ): string {
		$token = sanitize_key( $token );
		return $token ? $token : '';
	}

	/**
	 * Check whether the current requester can access the temporary reservation data payload.
	 *
	 * @param array<string, mixed> $payload Temporary reservation data payload.
	 * @return bool
	 */
	private function can_access_draft( array $payload ): bool {
		if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return true;
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $owner_user_id > 0 ) {
			return (int) get_current_user_id() === $owner_user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' === $owner_key ) {
			return false;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return false;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return false;
		}

		return hash_equals( $owner_key, $cookie_value );
	}

	/**
	 * Backfill ownership data for older temporary reservation data.
	 *
	 * @param string              $token Temporary reservation data token.
	 * @param array<string,mixed> $payload Temporary reservation data payload.
	 * @return array<string, mixed>
	 */
	private function backfill_draft_owner( string $token, array $payload ): array {
		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		$owner_key     = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';

		if ( $owner_user_id > 0 || '' !== $owner_key ) {
			return $payload;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return $payload;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return $payload;
		}

		$payload['owner_key'] = $cookie_value;
		return $payload;
	}

	/**
	 * Generate readable booking title.
	 *
	 * @param string $customer Customer name.
	 * @param string $start_at Start datetime.
	 * @return string
	 */
	private function generate_booking_title( string $customer, string $start_at ): string {
		$label           = $customer ? $customer : __( 'Reservation', 'vk-booking-manager' );
		$formatted_start = $this->format_datetime_for_title( $start_at );
		return sprintf( '%s / %s', $label, $formatted_start );
	}

	/**
	 * Convert ISO8601 datetime to a compact booking title format.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
	 */
	private function format_datetime_for_title( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( 'Y.n.j H:i', $timestamp, wp_timezone() );
	}

	/**
	 * Convert ISO8601 datetime to site-local Y-m-d H:i:s string.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
	 */
	private function format_datetime_for_storage( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Retrieve stored phone number for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_user_phone_number( int $user_id ): string {
		$phone = trim( (string) get_user_meta( $user_id, 'phone_number', true ) );
		return $phone;
	}

	/**
	 * Retrieve a user by normalized phone number.
	 *
	 * @param string $phone Normalized phone number.
	 * @return WP_User|null
	 */
	private function get_user_by_phone_number( string $phone ): ?WP_User {
		if ( '' === $phone ) {
			return null;
		}

		// Match on normalized phone number to avoid formatting differences.
		// 表記揺れを避けるため正規化済みの電話番号で検索する.
		$users = get_users(
			array(
				'meta_key'    => 'phone_number',
				'meta_value'  => $phone,
				'number'      => 1,
				'count_total' => false,
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		$user = $users[0];
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Determine if booking date matches menu restriction.
	 *
	 * @param string $reservation_day_type Restriction type (weekend|weekday|custom_weekday|custom_date).
	 * @param string $start_at             Slot start (ISO8601).
	 * @param int    $menu_id              Menu post ID（custom 種別の詳細設定を読み込むために使用）。
	 * @return bool
	 */
	private function is_reservation_day_allowed( string $reservation_day_type, string $start_at, int $menu_id ): bool {
		if ( '' === $reservation_day_type ) {
			return true;
		}

		// 予約確定時は ISO8601 文字列を strtotime で解釈し、wp_timezone() で日付を組み立てる。
		// パース経路は従来どおりこの箇所で保持し、失敗時は許可（true）へフォールバックする。
		$timestamp = strtotime( $start_at );
		if ( false === $timestamp ) {
			return true;
		}

		// タイムスタンプからサイトのタイムゾーンで日付を組み立て、日付ベース判定へ渡す。
		$datetime = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );

		// 曜日指定・日付指定の詳細設定を取得する（該当種別のときだけ参照される）。
		$custom_weekdays = get_post_meta( $menu_id, self::MENU_META_RESERVATION_CUSTOM_WEEKDAYS, true );
		$custom_dates    = get_post_meta( $menu_id, self::MENU_META_RESERVATION_CUSTOM_DATES, true );

		// 共有ヘルパーの日付ベース判定へ委譲する。
		// '' / weekend / weekday は is_date_allowed() 内で従来どおり曜日番号ベースの判定になる。
		return Reservation_Day::is_date_allowed(
			$reservation_day_type,
			$datetime,
			array(
				'weekdays' => is_array( $custom_weekdays ) ? $custom_weekdays : array(),
				'dates'    => is_array( $custom_dates ) ? $custom_dates : array(),
			)
		);
	}

	/**
	 * Revalidate temporary reservation data slot availability before booking confirmation.
	 *
	 * 予約確定前に予約一時データの枠がまだ空いているか再検証します。
	 *
	 * @param int                  $menu_id            Menu post ID.
	 * @param int                  $preferred_staff_id Preferred staff ID (0 for auto).
	 * @param array<string, mixed> $slot               Temporary reservation data slot payload.
	 * @param string               $timezone           Timezone string (optional).
	 * @return array<string, mixed>|WP_Error
	 */
	private function revalidate_draft_slot(
		int $menu_id,
		int $preferred_staff_id,
		array $slot,
		string $timezone
	) {
		$slot_id  = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$start_at = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$end_at   = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// Resolve timezone from draft or slot data. / 予約一時データまたは枠情報からタイムゾーンを補正します.
		$timezone = sanitize_text_field( $timezone );
		if ( '' === $timezone ) {
			$timezone = $this->extract_timezone_from_datetime( $start_at );
		}

		$date = $this->extract_slot_date( $start_at, $timezone );
		if ( '' === $date ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$availability = $this->availability_service->get_daily_slots(
			array(
				'menu_id'     => $menu_id,
				'resource_id' => $preferred_staff_id,
				'date'        => $date,
				'timezone'    => $timezone,
			)
		);

		if ( is_wp_error( $availability ) ) {
			return new WP_Error(
				$availability->get_error_code(),
				$availability->get_error_message(),
				array( 'status' => 409 )
			);
		}

		$slots = isset( $availability['slots'] ) && is_array( $availability['slots'] ) ? $availability['slots'] : array();
		$match = $this->find_matching_available_slot( $slots, $slot_id, $start_at, $end_at );
		if ( null === $match ) {
			return new WP_Error(
				'slot_unavailable',
				__( 'The selected slot is no longer available. Please choose another slot.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $preferred_staff_id > 0 ) {
			$matched_staff_id = isset( $match['staff']['id'] ) ? (int) $match['staff']['id'] : 0;
			if ( 0 === $matched_staff_id || $matched_staff_id !== $preferred_staff_id ) {
				return new WP_Error(
					'staff_unavailable',
					__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		return $match;
	}

	/**
	 * Extract slot date string from ISO datetime.
	 *
	 * ISO日時から営業日の文字列を取得します。
	 *
	 * @param string $start_at Slot start datetime.
	 * @param string $timezone Timezone string (optional).
	 * @return string
	 */
	private function extract_slot_date( string $start_at, string $timezone ): string {
		try {
			$datetime = new DateTimeImmutable( $start_at );
		} catch ( Exception $e ) {
			return '';
		}

		if ( '' !== $timezone ) {
			try {
				$datetime = $datetime->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				// Ignore invalid timezone and use original. / タイムゾーンが不正な場合は元の値を使います.
				unset( $e );
			}
		}

		return $datetime->format( 'Y-m-d' );
	}

	/**
	 * Extract timezone name from ISO datetime if possible.
	 *
	 * ISO日時からタイムゾーン名を抽出します。
	 *
	 * @param string $value ISO datetime.
	 * @return string
	 */
	private function extract_timezone_from_datetime( string $value ): string {
		try {
			$datetime = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return '';
		}

		return $datetime->getTimezone()->getName();
	}

	/**
	 * Normalize staff ID list to unique positive integers.
	 *
	 * 指名候補IDを正の整数に正規化します。
	 *
	 * @param mixed $raw_ids Raw staff IDs.
	 * @return array<int>
	 */
	private function normalize_assignable_staff_ids( $raw_ids ): array {
		if ( ! is_array( $raw_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw_ids as $candidate ) {
			$candidate = (int) $candidate;
			if ( $candidate > 0 && ! in_array( $candidate, $ids, true ) ) {
				$ids[] = $candidate;
			}
		}

		return $ids;
	}

	/**
	 * Find matching slot from the availability snapshot.
	 *
	 * 空き一覧から一致する枠を取得します。
	 *
	 * @param array<int, array<string, mixed>> $slots   Available slots.
	 * @param string                           $slot_id Temporary reservation data slot ID.
	 * @param string                           $start_at Temporary reservation data start datetime.
	 * @param string                           $end_at Temporary reservation data end datetime.
	 * @return array<string, mixed>|null
	 */
	private function find_matching_available_slot( array $slots, string $slot_id, string $start_at, string $end_at ): ?array {
		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_id = isset( $candidate['slot_id'] ) ? (string) $candidate['slot_id'] : '';
			if ( '' !== $slot_id && $candidate_id === $slot_id ) {
				return $candidate;
			}
		}

		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_start = isset( $candidate['start_at'] ) ? (string) $candidate['start_at'] : '';
			$candidate_end   = isset( $candidate['end_at'] ) ? (string) $candidate['end_at'] : '';

			if ( $candidate_start === $start_at && ( '' === $end_at || $candidate_end === $end_at ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Check capacity limit with transient-based mutex to prevent race conditions.
	 *
	 * 予約確定直前に capacity 上限をチェックし、自動割当時はロック保持下で
	 * 人数を収容できる単一スタッフをベストフィットで確定します（分割しない）。
	 * transient を使った簡易排他制御で同時リクエストによる超過予約を防止します。
	 *
	 * @param int        $menu_id            Service menu ID.
	 * @param int        $staff_id           Assigned staff ID (designated booking). Auto-assign では候補からベストフィットで確定する。
	 * @param string     $start_at           Slot start (ISO8601).
	 * @param string     $end_at             Slot end (ISO8601).
	 * @param int        $guests             Number of guests for the incoming booking.
	 * @param bool       $is_staff_preferred Whether this is a designated (1:1) booking that must never share a staff member.
	 * @param array<int> $assignable_staff   自動割当時の候補スタッフID（指名予約では空配列）。
	 * @param bool       $user_exclusive     ユーザーが貸し切りを指定したか（#305）。true の場合、ロック保持下で
	 *                                       「その枠に既に他の予約があれば貸し切り不可」を再判定する。
	 * @return array{name:string,token:string,staff_id:int}|WP_Error Lock handle + 確定スタッフID。caller は release_slot_mutex() で解放する。WP_Error on failure.
	 */
	private function check_capacity_with_mutex( int $menu_id, int $staff_id, string $start_at, string $end_at, int $guests = 1, bool $is_staff_preferred = false, array $assignable_staff = array(), bool $user_exclusive = false ) {
		$max_capacity = $this->get_menu_max_capacity( $menu_id );
		$guests       = max( 1, $guests );

		// ミューテックスキーを生成。メニューIDとスロット開始時刻で一意にする。
		// Generate mutex key unique to menu ID and slot start time.
		$mutex_key = sprintf( 'vkbm_booking_mutex_%d_%s', $menu_id, md5( $start_at . '|' . $end_at ) );

		// DBレベルの排他制御: INSERT IGNORE でアトミックにロックを取得する。
		// Atomic lock acquisition via INSERT IGNORE at the DB level.
		global $wpdb;
		$option_name = '_transient_' . $mutex_key;
		// ロック所有者を識別する一意トークン。値は "token|timestamp" 形式で保存し、解放時に所有者を検証する。
		// 期限切れロックを別リクエストが奪取した後、遅延した旧リクエストが新所有者のロックを誤って削除するのを防ぐ。
		$lock_token    = wp_generate_password( 20, false, false );
		$lock_acquired = false;
		for ( $i = 0; $i < 3; $i++ ) {
			// options テーブルを用いたミューテックスロック。アトミックな取得・確認・上書きが
			// 必要で、キャッシュするとロックが機能しないため直接クエリを使用する。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex lock acquisition; caching would defeat locking.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$option_name,
					$lock_token . '|' . time()
				)
			);
			if ( 1 === $result ) {
				$lock_acquired = true;
				break;
			}

			// タイムアウトしたロックを検出して上書きする。
			// 上書きは「読み取った値と一致する場合のみ更新」する compare-and-swap で行い、
			// 同時に複数リクエストが同じ期限切れロックを奪取するのを防ぐ。
			// Detect and overwrite stale locks that exceeded MUTEX_TIMEOUT.
			// Use a compare-and-swap (WHERE option_value = the value we just read) so only one
			// concurrent request can claim the same expired lock.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading current mutex lock state; caching would defeat locking.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
			if ( null !== $existing && ( time() - $this->parse_mutex_lock_timestamp( (string) $existing ) ) > self::MUTEX_TIMEOUT ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically overwriting a stale mutex lock; caching would defeat locking.
				$updated = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => $lock_token . '|' . time() ),
					array(
						'option_name'  => $option_name,
						'option_value' => $existing,
					)
				);
				if ( 1 === $updated ) {
					$lock_acquired = true;
					break;
				}
				// 別リクエストが先にロックを奪取したためリトライする。
			}

			// 短時間待機してリトライする。
			// Wait briefly and retry.
			usleep( 200000 ); // 200ms.
		}

		if ( ! $lock_acquired ) {
			return new WP_Error(
				'booking_busy',
				__( 'The system is currently processing another booking. Please try again in a few seconds.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// 貸し切り（枠を専有する）予約がこの時間帯に既に入っていれば、残席があっても受付停止する。
		// API直叩き対策として、ロック取得後に最新状態で再判定する（フロントのスロット一覧での抑止とは別経路の多重防御）。
		if ( $this->slot_has_exclusive_booking_for_menu( $menu_id, $start_at, $end_at ) ) {
			$this->release_slot_mutex( $option_name, $lock_token );
			return new WP_Error(
				'capacity_exceeded',
				__( 'Reservations are closed for this time slot because it has been reserved exclusively.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// ユーザーによる貸し切り指定（#305）: ユーザーが貸切を選んだ場合、その枠に既に他の予約があれば貸切にできない。
		// フロントでは booked_guests > 0 の枠の貸切チェックを無効化するが、API直叩きで迂回され得るため、
		// ロック保持下で最新状態を再判定する（必須の多層防御）。最初の予約者のみ貸切指定できる仕様の担保。
		if ( $user_exclusive && $this->slot_has_any_active_booking_for_menu( $menu_id, $start_at, $end_at ) ) {
			$this->release_slot_mutex( $option_name, $lock_token );
			return new WP_Error(
				'exclusive_unavailable',
				__( 'This slot cannot be reserved exclusively because it already has a booking.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $is_staff_preferred ) {
			// 指名予約（1対1）：ロック取得後にスタッフの競合を再チェックして二重割り当てを防止する。
			if ( $staff_id > 0 && $this->has_staff_conflict( $staff_id, $start_at, $end_at ) ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'staff_unavailable',
					__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
			$assigned_staff_id = $staff_id;
		} else {
			// 自動割当：ロック保持下の最新負荷で、人数を収容できる単一スタッフをベストフィットで確定する。
			$candidates = ! empty( $assignable_staff )
				? array_values( array_map( 'intval', $assignable_staff ) )
				: ( $staff_id > 0 ? array( $staff_id ) : array() );

			// 各スタッフの当該メニュー・時間帯の負荷（予約人数合計）。
			$loads = $this->get_staff_loads_for_slot( $menu_id, $start_at, $end_at );

			// max_capacity = 1 は1スタッフ1対1。メニューをまたいだ競合（同一スタッフが別メニューで予約済み）も除外する。
			if ( 1 === $max_capacity ) {
				$candidates = array_values(
					array_filter(
						$candidates,
						function ( $sid ) use ( $start_at, $end_at ): bool {
							return (int) $sid > 0 && ! $this->has_staff_conflict( (int) $sid, $start_at, $end_at );
						}
					)
				);
			}

			$assigned_staff_id = $this->select_best_fit_staff( $candidates, $loads, $guests, $max_capacity );
			if ( $assigned_staff_id <= 0 ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'capacity_exceeded',
					__( 'This time slot is fully booked. Please choose another time.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		// 成功時はロックを解放せず、ロック名・トークンと確定スタッフIDを呼び出し側へ返す。
		// 予約投稿の作成とメタ保存まで同じロックを保持し、確定直前の競合（同時挿入による超過予約）を防ぐ。
		return array(
			'name'     => $option_name,
			'token'    => $lock_token,
			'staff_id' => $assigned_staff_id,
		);
	}

	/**
	 * Release a slot mutex lock acquired by check_capacity_with_mutex().
	 *
	 * check_capacity_with_mutex() が取得したスロットロックを解放します。
	 * 保存されたトークンが自分のものと一致する場合のみ削除し、期限切れ奪取後に
	 * 他リクエストが取得した新しいロックを誤って削除しないようにします（ロック盗難防止）。
	 *
	 * @param string $option_name Lock option name returned by check_capacity_with_mutex().
	 * @param string $token       Owner token returned by check_capacity_with_mutex().
	 */
	private function release_slot_mutex( string $option_name, string $token ): void {
		if ( '' === $option_name || '' === $token ) {
			return;
		}

		global $wpdb;
		// 現在のロック値を読み、トークン部分が自分のものと一致する場合のみ削除する。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading mutex lock state for ownership check; caching would defeat locking.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
		if ( null === $existing ) {
			return;
		}

		$existing_token = $this->parse_mutex_lock_token( (string) $existing );
		if ( '' === $existing_token || ! hash_equals( $existing_token, $token ) ) {
			// 期限切れで別リクエストに奪取された後など、所有者が異なる場合は削除しない。
			return;
		}

		// option_value を条件に含めた1クエリの条件付き DELETE でアトミックに解放する。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex lock release; caching would defeat locking.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => $existing,
			)
		);

		// 直接クエリのため option キャッシュを無効化しておく。
		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Parse the timestamp portion from a mutex lock value ("token|timestamp").
	 *
	 * ミューテックスロック値（"token|timestamp"）からタイムスタンプ部分を取り出します。
	 * 後方互換のため、区切りが無い旧形式（純粋な数値）もそのまま数値として扱います。
	 *
	 * @param string $value Stored lock value.
	 * @return int
	 */
	private function parse_mutex_lock_timestamp( string $value ): int {
		$pos = strrpos( $value, '|' );
		return false === $pos ? (int) $value : (int) substr( $value, $pos + 1 );
	}

	/**
	 * Parse the owner token portion from a mutex lock value ("token|timestamp").
	 *
	 * ミューテックスロック値（"token|timestamp"）から所有者トークン部分を取り出します。
	 * 区切りが無い旧形式（トークン無し）の場合は空文字を返します。
	 *
	 * @param string $value Stored lock value.
	 * @return string
	 */
	private function parse_mutex_lock_token( string $value ): string {
		$pos = strrpos( $value, '|' );
		return false === $pos ? '' : substr( $value, 0, $pos );
	}

	/**
	 * Get the max capacity for a service menu.
	 *
	 * Availability_Service 側の公開メソッドに委譲します。
	 *
	 * @param int $menu_id Service menu ID.
	 * @return int
	 */
	private function get_menu_max_capacity( int $menu_id ): int {
		$menu_post = get_post( $menu_id );
		if ( ! $menu_post instanceof \WP_Post ) {
			return 1;
		}
		return $this->availability_service->get_menu_max_capacity( $menu_post );
	}

	/**
	 * Get the effective minimum participants to confirm for a service menu.
	 *
	 * Availability_Service 側の正規メソッドに委譲する。最大受付数が1以下のメニューでは
	 * 0（催行判定なし）を返すため、get_post_meta の直読みより default 揺れが無い（#320）。
	 *
	 * @param int $menu_id Service menu ID.
	 * @return int 実効の最小催行人数（0=制約なし）。
	 */
	private function get_menu_min_capacity( int $menu_id ): int {
		$menu_post = get_post( $menu_id );
		if ( ! $menu_post instanceof \WP_Post ) {
			return 0;
		}
		return $this->availability_service->get_menu_min_capacity( $menu_post );
	}

	/**
	 * メニューが「予約が入ったら貸し切りにする」設定として有効かどうかを返す。
	 *
	 * 貸し切り予約は複数人一括予約の文脈でのみ意味を持つため、メタ値だけでなくフルゲートを
	 * 再適用する（load-bearing な主防御）。Pro でなくなった／予約枠の定員機能が無効化された／
	 * 指名がONになった／メニューの複数人一括予約許可がOFFになった等で条件が崩れた場合は、
	 * 保存済みメタ（stale）が残っていても false を返し、無効な貸し切り設定が
	 * 実際の受付停止として効かないようにする。save 側の delete と多層で防ぐ。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return bool 貸し切り設定が有効なら true。
	 */
	private function is_menu_exclusive_when_booked( int $menu_id ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		// メタが立っていなければそもそも対象外。
		if ( ! get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_WHEN_BOOKED, true ) ) {
			return false;
		}

		// フルゲート（Pro版・予約枠の定員ON・指名OFF）を再適用する。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return false;
		}

		// メニュー単位の複数人一括予約許可（_vkbm_allow_multiple_guests）も必須。
		if ( ! get_post_meta( $menu_id, self::MENU_META_ALLOW_GUESTS, true ) ) {
			return false;
		}

		// 実効の最大予約受付数が1以下なら貸し切りは意味を持たないため無効化する（#320）。
		// 表示制御（管理画面の hidden）だけに頼らず、保存済みメタが残っていても実効0扱いにする。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * メニューが「ユーザーによる貸し切り指定を受け付ける」設定として有効かどうかを返す（#305）。
	 *
	 * 貸し切り指定は複数人一括予約の文脈でのみ意味を持つため、メタ値だけでなく
	 * is_menu_exclusive_when_booked() と同じフルゲート（Pro版・予約枠の定員ON・指名OFF・
	 * メニューの複数人一括予約許可ON）を再適用する。条件が崩れていれば stale メタが残っていても
	 * false を返し、無効なユーザー貸し切り指定が実際に効かないようにする（save 側の delete と多層防御）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return bool ユーザー貸し切り指定が有効なら true。
	 */
	private function is_user_exclusive_selectable( int $menu_id ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		// メタが立っていなければ対象外。
		if ( ! get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_USER_SELECTABLE, true ) ) {
			return false;
		}

		// フルゲート（Pro版・予約枠の定員ON・指名OFF）を再適用する。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return false;
		}

		// メニュー単位の複数人一括予約許可（_vkbm_allow_multiple_guests）も必須。
		if ( ! get_post_meta( $menu_id, self::MENU_META_ALLOW_GUESTS, true ) ) {
			return false;
		}

		// 実効の最大予約受付数が1以下なら貸し切り指定は意味を持たないため無効化する（#320）。
		// 表示制御（管理画面の hidden）だけに頼らず、保存済みメタが残っていても実効0扱いにする。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * メニュー設定とユーザー選択に基づき、貸し切り料金を権威的に再計算する（#305）。
	 *
	 * 単価・適用外人数は必ずサーバ保存メタを正とする（フロント送信額は信用しない）。
	 * ゲート（is_user_exclusive_selectable）を満たさないメニューでは 0 を返す。
	 * 実際の計算式は Exclusive_Fee::calculate() に委譲する（下書きコントローラ・テストと同一ロジック）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @param int $guests  申込人数。
	 * @return int 貸し切り料金（0 以上）。
	 */
	private function calculate_exclusive_fee( int $menu_id, int $guests ): int {
		if ( ! $this->is_user_exclusive_selectable( $menu_id ) ) {
			return 0;
		}

		$per_person    = max( 0, (int) get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_FEE_PER_PERSON, true ) );
		$exempt_guests = max( 0, (int) get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_FEE_EXEMPT, true ) );

		return Exclusive_Fee::calculate( true, $per_person, $exempt_guests, $guests );
	}

	/**
	 * 指定スロットに、ステータスが有効な予約（貸し切りか否かを問わない）が既に1件以上あるかを判定する（#305）。
	 *
	 * ユーザー貸し切り指定時に「最初の予約者のみ貸切にできる（既に予約があれば貸切不可）」を
	 * ロック保持下で担保するための判定。slot_has_exclusive_booking_for_menu() が
	 * 貸し切りフラグの有無で絞るのに対し、こちらは枠を消費する有効な予約があれば貸し切りフラグの
	 * 有無に関わらず true を返す。cancelled / no_show は枠を消費しないため除外する（既存の空き判定と同方針）。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return bool 有効な予約が重複していれば true。
	 */
	private function slot_has_any_active_booking_for_menu( int $menu_id, string $start_at, string $end_at ): bool {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				// 有効な予約が1件でもあれば判定は確定するため、cancelled/no_show を除いた上で
				// 取得件数を絞らず status を個別確認する（おとり対策）。最大10件まで見れば十分。
				'posts_per_page' => 10,
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
						'relation' => 'OR',
						array(
							'key'     => self::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STATUS,
							'value'   => array(
								self::BOOKING_STATUS_CANCELLED,
								self::BOOKING_STATUS_NO_SHOW,
							),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する。
			if ( self::BOOKING_STATUS_CANCELLED === $status || self::BOOKING_STATUS_NO_SHOW === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * 指定スロットに、枠を専有する貸し切り予約が既に1件以上あるかを判定する。
	 *
	 * 確定処理のロック保持下で最新状態を再判定するための多重防御。
	 * 既存の空き判定と同じく、publish かつ status が cancelled/no_show 以外の予約のみを対象とし、
	 * その中で _vkbm_booking_exclusive が立っている予約がスロットに重複していれば true を返す。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return bool 貸し切り予約が重複していれば true。
	 */
	private function slot_has_exclusive_booking_for_menu( int $menu_id, string $start_at, string $end_at ): bool {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				// 1件でもヒットすれば貸し切り判定は確定するため取得は1件に絞る（軽微な最適化）。
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_SERVICE_ID,
						'value'   => $menu_id,
						'compare' => '=',
					),
					// 貸し切りフラグが立っている予約のみを対象にする。
					array(
						'key'     => self::META_EXCLUSIVE,
						'value'   => '1',
						'compare' => '=',
					),
					// キャンセル・無断キャンセルの予約は枠を消費しないためクエリ段階で除外する。
					// posts_per_page=1 で取得を1件に絞っても、対象外ステータスの予約が
					// 先頭に来て有効な貸し切り予約を見落とすことがないようにする。
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STATUS,
							'value'   => array(
								self::BOOKING_STATUS_CANCELLED,
								self::BOOKING_STATUS_NO_SHOW,
							),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_DATE_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => self::META_DATE_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する（既存の空き判定と同じ方針）。
			if ( self::BOOKING_STATUS_CANCELLED === $status || self::BOOKING_STATUS_NO_SHOW === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * メニューに割り当てられたスタッフ数を数える。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return int スタッフ数。
	 */
	private function count_menu_staff( int $menu_id ): int {
		$staff_ids = get_post_meta( $menu_id, '_vkbm_staff_ids', true );
		if ( ! is_array( $staff_ids ) ) {
			return 0;
		}

		// 重複スタッフIDは1名として数える。
		$valid = array_unique(
			array_filter(
				array_map( 'intval', $staff_ids ),
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);

		return count( $valid );
	}

	/**
	 * メニューに有効な料金区分が定義されている場合に、その正規化済み区分を返す。
	 *
	 * 料金区分は複数人一括予約が適用される場面（Pro版・指名OFF・複数人一括予約許可・スタッフ割当あり）でのみ意味を持つ。
	 * resolve_guests と同じゲートを通し、適用外なら空配列を返す（区分未定義として従来計算に倒す）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return array<int, array{label: string, price: int}>
	 */
	private function resolve_menu_price_tiers( int $menu_id ): array {
		// 複数人一括予約のゲート（Pro版・予約枠の定員機能ON・指名OFF・許可フラグON・スタッフ割当あり）を満たさない場合は区分を使わない。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $menu_id <= 0 || ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return array();
		}

		$allow = (bool) get_post_meta( $menu_id, self::MENU_META_ALLOW_GUESTS, true );
		if ( ! $allow || $this->count_menu_staff( $menu_id ) < 1 ) {
			return array();
		}

		// 実効の最大予約受付数が1以下なら料金区分は意味を持たないため空配列扱いにする（#320）。
		// 区分未定義として基本料金×人数へフォールバックさせる（保存済みメタは破壊しない）。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return array();
		}

		return Price_Tiers::normalize_tiers( get_post_meta( $menu_id, self::MENU_META_PRICE_TIERS, true ) );
	}

	/**
	 * Resolve the number of guests for a booking against the menu's multi-guest settings.
	 *
	 * メニューの複数人一括予約設定に基づいて予約人数を確定する。
	 * 複数人一括予約が無効、または指名機能が有効な場合は常に1名を返す。
	 * 有効な場合は 1〜最大人数の範囲にクランプする。
	 *
	 * NOTE: 同等のロジックを Booking_Draft_Controller::resolve_guests にも意図的に複製している。
	 * 下書きと確定の2つの REST コントローラは独立しており、共有トレイト化すると両者を不要に結合させるため、
	 * あえて各クラスに閉じた実装としている。仕様変更時は必ず両方を同じ式（上限 = max_capacity）で更新すること。
	 *
	 * @param int $menu_id   Service menu ID.
	 * @param int $requested Requested number of guests.
	 * @return int
	 */
	private function resolve_guests( int $menu_id, int $requested ): int {
		// 複数人一括予約は Pro 版 かつ 予約枠の定員機能ON かつ 指名機能OFF（自動割り当て）のときのみ有効。
		// Pro から無料版へダウングレードしてもメタが残る可能性があるため、確定時に Pro 版を再確認する。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $menu_id <= 0 || ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return 1;
		}

		$allow = (bool) get_post_meta( $menu_id, self::MENU_META_ALLOW_GUESTS, true );
		if ( ! $allow ) {
			return 1;
		}

		// 1予約は分割せず単一スタッフに割り当てるため、上限は max_capacity（1スタッフの上限）。
		// スタッフ未割当のメニューは割り当て先が無いため複数人一括予約を許可しない（常に1名）。
		// 実際のスロット別の空きは確定時（check_capacity_with_mutex のベストフィット判定）で厳密に判定する。
		$max_raw      = get_post_meta( $menu_id, self::MENU_META_MAX_CAPACITY, true );
		$max_capacity = '' === $max_raw ? 1 : max( 1, (int) $max_raw );
		$staff_count  = $this->count_menu_staff( $menu_id );

		if ( $staff_count < 1 ) {
			return 1;
		}

		return max( 1, min( $max_capacity, $requested ) );
	}
}
