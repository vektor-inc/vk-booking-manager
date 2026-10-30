<?php
/**
 * お客様自身による予約のキャンセル（可否の判定と実行）。
 *
 * マイページのキャンセルと、ゲスト予約のキャンセル用リンク（docs/specification-guest-booking.md）が、
 * 同じ判定・同じ後処理（ステータス変更・通知メール・外部連携への通知）を通るように切り出している。
 * 本人確認（ログイン中の投稿者か、キャンセル用トークンか）は呼び出し側で済ませてから使う。
 * 無料版・Pro 版の両方で使う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use function __;
use function current_time;
use function get_post_meta;
use function sanitize_text_field;
use function update_post_meta;

/**
 * お客様自身によるキャンセル。
 */
class Booking_Customer_Cancellation {
	/**
	 * キャンセルできる。
	 */
	public const STATE_CAN_CANCEL = 'can_cancel';

	/**
	 * すでにキャンセル済み。
	 */
	public const STATE_CANCELLED = 'cancelled';

	/**
	 * 設定が「キャンセル不可」。
	 */
	public const STATE_CANCEL_DISABLED = 'cancel_disabled';

	/**
	 * キャンセルの締め切りを過ぎている（開始時刻を過ぎた予約を含む）。
	 */
	public const STATE_DEADLINE_PASSED = 'cancel_deadline_passed';

	/**
	 * 予約の日時が読み取れない。
	 */
	public const STATE_INVALID_BOOKING = 'invalid_booking';

	/**
	 * キャンセル済みを表す予約ステータス。
	 */
	private const STATUS_CANCELLED = 'cancelled';

	/**
	 * 予約のステータスのメタキー。
	 */
	private const META_STATUS = '_vkbm_booking_status';

	/**
	 * 予約の開始日時のメタキー。
	 */
	private const META_SERVICE_START = '_vkbm_booking_service_start';

	/**
	 * 基本設定。
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * 通知サービス。
	 *
	 * @var Booking_Notification_Service|null
	 */
	private ?Booking_Notification_Service $notification_service;

	/**
	 * 予約の状態変化を外部連携へ橋渡しするディスパッチャー。
	 *
	 * @var Booking_Event_Dispatcher
	 */
	private Booking_Event_Dispatcher $event_dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null          $settings_repository  基本設定。
	 * @param Booking_Notification_Service|null $notification_service 通知サービス。
	 * @param Booking_Event_Dispatcher|null     $event_dispatcher     外部連携へのディスパッチャー。
	 */
	public function __construct(
		?Settings_Repository $settings_repository = null,
		?Booking_Notification_Service $notification_service = null,
		?Booking_Event_Dispatcher $event_dispatcher = null
	) {
		$this->settings_repository  = $settings_repository ?? new Settings_Repository();
		$this->notification_service = $notification_service;
		$this->event_dispatcher     = $event_dispatcher ?? new Booking_Event_Dispatcher();
	}

	/**
	 * 予約が今キャンセルできるかを、状態の名前で返す。
	 *
	 * キャンセル済みなら STATE_CANCELLED。そうでなければ、設定「予約キャンセル」と
	 * {@see Booking_Cancellation_Policy::can_cancel()} の判定で決める。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return string STATE_* のいずれか。
	 */
	public function get_state( int $booking_id ): string {
		$start_at = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_SERVICE_START, true ) );
		$start_ts = '' !== $start_at ? strtotime( $start_at ) : false;
		if ( false === $start_ts ) {
			return self::STATE_INVALID_BOOKING;
		}

		$status = sanitize_text_field( (string) get_post_meta( $booking_id, self::META_STATUS, true ) );
		if ( self::STATUS_CANCELLED === $status ) {
			return self::STATE_CANCELLED;
		}

		$settings     = $this->settings_repository->get_settings();
		$cancel_mode  = isset( $settings['provider_booking_cancel_mode'] ) ? (string) $settings['provider_booking_cancel_mode'] : 'hours';
		$cancel_hours = isset( $settings['provider_booking_cancel_deadline_hours'] ) ? (int) $settings['provider_booking_cancel_deadline_hours'] : 24;
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
		$now = (int) current_time( 'timestamp' );

		if ( Booking_Cancellation_Policy::can_cancel( (int) $start_ts, $now, $status, $cancel_mode, $cancel_hours ) ) {
			return self::STATE_CAN_CANCEL;
		}

		return 'none' === $cancel_mode ? self::STATE_CANCEL_DISABLED : self::STATE_DEADLINE_PASSED;
	}

	/**
	 * 状態に対応する、お客様向けの文言を返す。マイページとゲストのキャンセル用リンクで同じ文言を出す。
	 *
	 * @param string $state STATE_* のいずれか。
	 * @return string 文言。文言の無い状態（キャンセルできる・キャンセル済み）では空文字。
	 */
	public static function get_state_message( string $state ): string {
		switch ( $state ) {
			case self::STATE_CANCEL_DISABLED:
				return __( 'This reservation cannot be canceled online.', 'vk-booking-manager' );
			case self::STATE_DEADLINE_PASSED:
				return __( 'The cancellation deadline has passed.', 'vk-booking-manager' );
			case self::STATE_INVALID_BOOKING:
				return __( 'The reservation date and time is invalid.', 'vk-booking-manager' );
			default:
				return '';
		}
	}

	/**
	 * 予約をキャンセルする。
	 *
	 * すでにキャンセル済みなら、何もせず成功を返す（二重送信でエラーにしない）。
	 * キャンセルしたら、お客様・店舗へのキャンセル通知メールと外部連携への通知まで行う。
	 *
	 * @param int $booking_id 予約の投稿 ID。本人確認は呼び出し側で済ませておく。
	 * @return true|WP_Error 成功なら true。
	 */
	public function cancel( int $booking_id ) {
		$state = $this->get_state( $booking_id );

		if ( self::STATE_INVALID_BOOKING === $state ) {
			return new WP_Error( 'invalid_booking', self::get_state_message( $state ), array( 'status' => 400 ) );
		}

		if ( self::STATE_CANCELLED === $state ) {
			return true;
		}

		if ( self::STATE_CAN_CANCEL !== $state ) {
			$code = self::STATE_CANCEL_DISABLED === $state ? 'cancel_disabled' : 'cancel_deadline_passed';
			return new WP_Error( $code, self::get_state_message( $state ), array( 'status' => 403 ) );
		}

		// #474: このメソッド自身のメタ書き込みより前にスナップショットを確保する（外部連携用の
		// ディスパッチャーへ「変更前の内容」として渡す）。
		$event_before = $this->event_dispatcher->capture_snapshot( $booking_id );

		update_post_meta( $booking_id, self::META_STATUS, self::STATUS_CANCELLED );
		if ( $this->notification_service ) {
			$this->notification_service->handle_customer_cancellation( $booking_id );
		}

		$this->event_dispatcher->dispatch_change( $booking_id, $event_before );

		return true;
	}
}
