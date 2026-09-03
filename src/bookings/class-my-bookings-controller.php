<?php
/**
 * REST controller for current user bookings.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function absint;
use function add_action;
use function current_time;
use function get_current_user_id;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function is_user_logged_in;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function update_post_meta;

/**
 * Provides current user's booking list.
 */
class My_Bookings_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';

	private const STATUS_CANCELLED = 'cancelled';

	private const META_SERVICE_ID         = '_vkbm_booking_service_id';
	private const META_RESOURCE_ID        = '_vkbm_booking_resource_id';
	private const META_SERVICE_START      = '_vkbm_booking_service_start';
	private const META_SERVICE_END        = '_vkbm_booking_service_end';
	private const META_IS_STAFF_PREFERRED = '_vkbm_booking_is_staff_preferred';
	private const META_OTHER_CONDITIONS   = '_vkbm_other_conditions';
	private const META_NOMINATION_FEE     = '_vkbm_booking_nomination_fee';
	private const META_BASE_PRICE         = '_vkbm_booking_service_base_price';
	private const META_BASE_TOTAL_PRICE   = '_vkbm_booking_base_total_price';
	private const META_GUESTS             = '_vkbm_booking_guests';
	private const META_GUEST_TIERS        = '_vkbm_booking_guest_tiers';
	private const META_STATUS             = '_vkbm_booking_status';

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * Notification service.
	 *
	 * @var Booking_Notification_Service|null
	 */
	private ?Booking_Notification_Service $notification_service;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null          $settings_repository Provider settings repository.
	 * @param Booking_Notification_Service|null $notification_service Notification handler.
	 */
	public function __construct(
		?Settings_Repository $settings_repository = null,
		?Booking_Notification_Service $notification_service = null
	) {
		$this->settings_repository  = $settings_repository ?? new Settings_Repository();
		$this->notification_service = $notification_service;
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
			'/my-bookings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bookings' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'future_only' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/my-bookings/(?P<id>\\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Return current user's bookings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_bookings( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return rest_ensure_response( array() );
		}

		$settings     = $this->settings_repository->get_settings();
		$cancel_mode  = isset( $settings['provider_booking_cancel_mode'] ) ? (string) $settings['provider_booking_cancel_mode'] : 'hours';
		$cancel_hours = isset( $settings['provider_booking_cancel_deadline_hours'] ) ? (int) $settings['provider_booking_cancel_deadline_hours'] : 24;

		$future_only = (bool) $request->get_param( 'future_only' );
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
		$now = (int) current_time( 'timestamp' );

		$query = new \WP_Query(
			array(
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => 'any',
				'author'                 => $user_id,
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $booking_post ) {
			$booking_id = (int) $booking_post->ID;
			if ( $booking_id <= 0 ) {
				continue;
			}

			$start_at = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_SERVICE_START, true ) );
			if ( '' === $start_at ) {
				continue;
			}

			$start_timestamp = strtotime( $start_at );
			if ( false === $start_timestamp ) {
				continue;
			}

			if ( $future_only && $start_timestamp < $now ) {
				continue;
			}

			$menu_id     = (int) get_post_meta( $booking_id, self::META_SERVICE_ID, true );
			$resource_id = (int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true );

			$menu_name     = $menu_id > 0 ? (string) get_the_title( $menu_id ) : '';
			$resource_name = $resource_id > 0 ? vkbm_get_resource_display_name( $resource_id ) : vkbm_get_no_nomination_label();

			$other_conditions = '';
			if ( $menu_id > 0 ) {
				$other_conditions = trim(
					sanitize_textarea_field(
						(string) get_post_meta( $menu_id, self::META_OTHER_CONDITIONS, true )
					)
				);
			}

			$end_at             = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_SERVICE_END, true ) );
			$is_staff_preferred = '1' === (string) get_post_meta( $booking_id, self::META_IS_STAFF_PREFERRED, true );
			$nomination_fee     = (int) get_post_meta( $booking_id, self::META_NOMINATION_FEE, true );
			// 基本料金スナップショット。未保存の旧予約はメニューの _vkbm_base_price をフォールバックに使う
			// （管理画面 render_meta_box と同じ挙動。¥0 表示・合計の過小計算を防ぐ）。
			$base_price = metadata_exists( 'post', $booking_id, self::META_BASE_PRICE )
				? (int) get_post_meta( $booking_id, self::META_BASE_PRICE, true )
				: ( $menu_id > 0 ? max( 0, (int) get_post_meta( $menu_id, '_vkbm_base_price', true ) ) : 0 );
			// 予約人数（複数人予約）。未設定の既存予約は1名として扱う。
			$guests = max( 1, (int) get_post_meta( $booking_id, self::META_GUESTS, true ) );
			// 料金区分の人数内訳スナップショット。未設定の既存予約は空配列（従来表示）。
			$guest_tiers    = Price_Tiers::normalize_guest_tiers( get_post_meta( $booking_id, self::META_GUEST_TIERS, true ) );
			$has_base_total = metadata_exists( 'post', $booking_id, self::META_BASE_TOTAL_PRICE );
			$base_total     = $has_base_total ? (int) get_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, true ) : max( 0, ( $base_price * $guests ) + $nomination_fee );

			if ( ! Staff_Editor::is_nomination_enabled() ) {
				$nomination_fee = 0;
				// 保存済みの基本料金合計があるときは、予約時に確定した（実際に請求した）金額を保持する。
				// 指名ON時に作成され後で指名OFFになった予約で、合計が書き換わるのを防ぐ。
				// 保存値が無い場合のみ「基本料金 × 人数」で再計算する。
				if ( ! $has_base_total ) {
					$base_total = max( 0, $base_price * $guests );
				}
			}
			$status = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_STATUS, true ) );

			$can_cancel = $this->can_cancel_booking( $start_timestamp, $now, $status, $cancel_mode, $cancel_hours );

			$items[] = array(
				'id'                 => $booking_id,
				'start_at'           => $start_at,
				'end_at'             => $end_at,
				// 過去の予約かどうか（開始日時が現在より前）。タイムゾーン依存の判定を
				// サーバ側に寄せ、フロントの誤判定を防ぐ。「同じ内容で予約する」での
				// 過去／今後セクションの振り分けに使う。
				'is_past'            => ( $start_timestamp < $now ),
				'can_cancel'         => $can_cancel,
				'is_staff_preferred' => $is_staff_preferred,
				'other_conditions'   => $other_conditions,
				'menu_id'            => $menu_id,
				'menu_name'          => $menu_name,
				'resource_id'        => $resource_id,
				'resource_name'      => $resource_name,
				'base_price'         => $base_price,
				'guests'             => $guests,
				'guest_tiers'        => $guest_tiers,
				'nomination_fee'     => $nomination_fee,
				'total_price'        => max( 0, $base_total ),
				'status'             => $status,
				'start_ts'           => $start_timestamp,
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return (int) $a['start_ts'] <=> (int) $b['start_ts'];
			}
		);

		foreach ( $items as &$item ) {
			unset( $item['start_ts'] );
		}
		unset( $item );

		return rest_ensure_response( $items );
	}

	/**
	 * Cancel booking for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_booking( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$booking_id = absint( $request['id'] ?? 0 );
		if ( $booking_id <= 0 ) {
			return new WP_Error( 'invalid_booking', __( 'The reservation ID is invalid.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		$post = get_post( $booking_id );
		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'booking_not_found', __( 'No reservations found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author !== $user_id ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to cancel this reservation.', 'vk-booking-manager' ), array( 'status' => 403 ) );
		}

		$start_at = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_SERVICE_START, true ) );
		$start_ts = $start_at ? strtotime( $start_at ) : false;
		if ( false === $start_ts ) {
			return new WP_Error( 'invalid_booking', __( 'The reservation date and time is invalid.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
		$now    = (int) current_time( 'timestamp' );
		$status = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_STATUS, true ) );

		if ( self::STATUS_CANCELLED === $status ) {
			return new WP_REST_Response( array( 'cancelled' => true ) );
		}

		$settings     = $this->settings_repository->get_settings();
		$cancel_mode  = isset( $settings['provider_booking_cancel_mode'] ) ? (string) $settings['provider_booking_cancel_mode'] : 'hours';
		$cancel_hours = isset( $settings['provider_booking_cancel_deadline_hours'] ) ? (int) $settings['provider_booking_cancel_deadline_hours'] : 24;

		if ( ! $this->can_cancel_booking( (int) $start_ts, $now, $status, $cancel_mode, $cancel_hours ) ) {
			if ( 'none' === $cancel_mode ) {
				return new WP_Error( 'cancel_disabled', __( 'This reservation cannot be canceled online.', 'vk-booking-manager' ), array( 'status' => 403 ) );
			}
			return new WP_Error( 'cancel_deadline_passed', __( 'The cancellation deadline has passed.', 'vk-booking-manager' ), array( 'status' => 403 ) );
		}

		update_post_meta( $booking_id, self::META_STATUS, self::STATUS_CANCELLED );
		if ( $this->notification_service ) {
			$this->notification_service->handle_customer_cancellation( $booking_id );
		}

		return new WP_REST_Response( array( 'cancelled' => true ) );
	}

	/**
	 * Determine if a booking can be cancelled.
	 *
	 * @param int    $start_timestamp Booking start timestamp.
	 * @param int    $now             Current timestamp.
	 * @param string $status          Current booking status.
	 * @param string $cancel_mode     Cancel mode ('hours'|'none').
	 * @param int    $cancel_hours    Cancel deadline hours.
	 * @return bool
	 */
	private function can_cancel_booking( int $start_timestamp, int $now, string $status, string $cancel_mode, int $cancel_hours ): bool {
		if ( self::STATUS_CANCELLED === $status ) {
			return false;
		}

		if ( $start_timestamp <= $now ) {
			return false;
		}

		if ( 'none' === $cancel_mode ) {
			return false;
		}

		$cancel_hours = max( 0, $cancel_hours );

		return ( $start_timestamp - $now ) >= ( $cancel_hours * 3600 );
	}
}
