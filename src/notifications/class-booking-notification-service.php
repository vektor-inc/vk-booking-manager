<?php

/**
 * Booking notification service.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;
use WP_User;
use function absint;
use function add_action;
use function add_filter;
use function __;
use function current_time;
use function get_user_by;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_bloginfo;
use function home_url;
use function is_email;
use function number_format_i18n;
use function sanitize_email;
use function sanitize_text_field;
use function sprintf;
use function strtotime;
use function remove_filter;
use function wp_date;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_schedule_single_event;
use function wp_timezone;
use function wp_clear_scheduled_hook;
use function wp_specialchars_decode;
use function wp_strip_all_tags;
use function wp_mail;
use function update_post_meta;

/**
 * Handles booking-related email notifications.
 */
class Booking_Notification_Service {
	private const RETRY_ACTION            = 'vkbm_retry_booking_email';
	private const RETRY_DELAY             = 300;
	private const MAX_ATTEMPTS            = 3;
	private const REMINDER_ACTION         = 'vkbm_send_booking_reminders';
	private const REMINDER_SCHEDULE       = 'vkbm_quarter_hour';
	private const REMINDER_WINDOW         = 3600;
	private const META_REMINDER_SENT      = '_vkbm_booking_reminder_sent';
	private const META_DATE_START         = '_vkbm_booking_service_start';
	private const META_STATUS             = '_vkbm_booking_status';
	private const TYPE_PENDING_CUSTOMER   = 'pending_customer';
	private const TYPE_PENDING_PROVIDER   = 'pending_provider';
	private const TYPE_CONFIRMED_CUSTOMER = 'confirmed_customer';
	private const TYPE_CONFIRMED_PROVIDER = 'confirmed_provider';
	private const TYPE_CANCELLED_CUSTOMER = 'cancelled_customer';
	private const TYPE_CANCELLED_PROVIDER = 'cancelled_provider';
	private const TYPE_REMINDER_CUSTOMER  = 'reminder_customer';

	/**
	 * Settings store.
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings_repository Settings store.
	 */
	public function __construct( Settings_Repository $settings_repository ) {
		$this->settings_repository = $settings_repository;
	}

	/**
	 * Register cron hook listeners.
	 */
	public function register(): void {
		add_action( self::RETRY_ACTION, array( $this, 'handle_retry' ), 10, 3 );
		add_action( self::REMINDER_ACTION, array( $this, 'handle_reminders' ) );
		add_action( 'init', array( $this, 'ensure_reminder_schedule' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
	}

	/**
	 * Triggered when a pending booking is created.
	 *
	 * @param int $booking_id Booking post ID.
	 */
	public function handle_pending_creation( int $booking_id ): void {
		$this->dispatch_notification( self::TYPE_PENDING_CUSTOMER, $booking_id, 1 );
		$this->dispatch_notification( self::TYPE_PENDING_PROVIDER, $booking_id, 1 );
	}

	/**
	 * Triggered when a confirmed booking is created.
	 *
	 * @param int $booking_id Booking post ID.
	 */
	public function handle_confirmed_creation( int $booking_id ): void {
		$this->dispatch_notification( self::TYPE_CONFIRMED_CUSTOMER, $booking_id, 1 );
		$this->dispatch_notification( self::TYPE_CONFIRMED_PROVIDER, $booking_id, 1 );
	}

	/**
	 * Triggered when a customer cancels a booking.
	 * 予約がキャンセルされた際に通知を送信します。
	 *
	 * @param int $booking_id Booking post ID.
	 */
	public function handle_customer_cancellation( int $booking_id ): void {
		$this->dispatch_notification( self::TYPE_CANCELLED_CUSTOMER, $booking_id, 1 );
		$this->dispatch_notification( self::TYPE_CANCELLED_PROVIDER, $booking_id, 1 );
	}

	/**
	 * Triggered when a booking changes status.
	 *
	 * @param int    $booking_id   Booking post ID.
	 * @param string $old_status   Previous status.
	 * @param string $new_status   Updated status.
	 */
	public function handle_status_transition( int $booking_id, string $old_status, string $new_status ): void {
		if ( 'confirmed' === $new_status && 'confirmed' !== $old_status ) {
			$this->dispatch_notification( self::TYPE_CONFIRMED_CUSTOMER, $booking_id, 1 );
		}
	}

	/**
	 * Cron callback for retries.
	 *
	 * @param string $type        Notification type.
	 * @param int    $booking_id  Booking ID.
	 * @param int    $attempt     Attempt counter.
	 */
	public function handle_retry( string $type, int $booking_id, int $attempt ): void {
		$this->dispatch_notification( $type, $booking_id, $attempt );
	}

	/**
	 * Cron callback for reservation reminders.
	 */
	public function handle_reminders(): void {
		$reminder_hours = $this->get_reminder_hours();
		if ( array() === $reminder_hours ) {
			return;
		}

		$max_hours  = max( $reminder_hours );
		$now        = current_time( 'timestamp' );
		$window_end = $now + ( $max_hours * HOUR_IN_SECONDS ) + self::REMINDER_WINDOW;
		$start_min  = wp_date( 'Y-m-d H:i:s', $now );
		$start_max  = wp_date( 'Y-m-d H:i:s', $window_end );

		$query = new \WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_STATUS,
						'value'   => 'confirmed',
						'compare' => '=',
					),
					array(
						'key'     => self::META_DATE_START,
						'value'   => array( $start_min, $start_max ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return;
		}

		foreach ( $query->posts as $booking_id ) {
			$start = (string) get_post_meta( (int) $booking_id, self::META_DATE_START, true );
			if ( '' === $start ) {
				continue;
			}

			$start_ts = $this->parse_datetime_to_timestamp( $start );
			if ( ! $start_ts ) {
				continue;
			}

			$sent = $this->get_sent_reminders( (int) $booking_id );

			foreach ( $reminder_hours as $hours_before ) {
				$target = $start_ts - ( $hours_before * HOUR_IN_SECONDS );
				if ( $now < $target ) {
					continue;
				}

				if ( ( $now - $target ) > self::REMINDER_WINDOW ) {
					continue;
				}

				if ( $this->has_sent_reminder( $sent, $hours_before, $start ) ) {
					continue;
				}

				if ( $this->send_customer_reminder( (int) $booking_id, $hours_before ) ) {
					$sent[] = array(
						'hours'   => $hours_before,
						'start'   => $start,
						'sent_at' => wp_date( 'c', $now ),
					);
					update_post_meta( (int) $booking_id, self::META_REMINDER_SENT, $sent );
				}
			}
		}
	}

	/**
	 * Ensure reminder cron schedule exists if enabled.
	 */
	public function ensure_reminder_schedule(): void {
		$reminder_hours = $this->get_reminder_hours();
		$next_scheduled = wp_next_scheduled( self::REMINDER_ACTION );

		if ( array() === $reminder_hours ) {
			if ( $next_scheduled ) {
				wp_clear_scheduled_hook( self::REMINDER_ACTION );
			}
			return;
		}

		if ( ! $next_scheduled ) {
			wp_schedule_event( time() + 300, self::REMINDER_SCHEDULE, self::REMINDER_ACTION );
		}
	}

	/**
	 * Register custom cron schedules.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ self::REMINDER_SCHEDULE ] ) ) {
			$schedules[ self::REMINDER_SCHEDULE ] = array(
				'interval' => 900,
				'display'  => __( 'Every 15 Minutes', 'vk-booking-manager' ),
			);
		}

		return $schedules;
	}

	/**
	 * Dispatch a notification and schedule retries on failure.
	 *
	 * @param string $type       Notification type.
	 * @param int    $booking_id Booking ID.
	 * @param int    $attempt    Current attempt count.
	 */
	private function dispatch_notification( string $type, int $booking_id, int $attempt ): void {
		if ( $attempt > self::MAX_ATTEMPTS ) {
			// Notification abandoned after max attempts.
			return;
		}

		$payload = $this->build_booking_payload( $booking_id );

		if ( empty( $payload ) ) {
			return;
		}

		$message = $this->build_message( $type, $payload );

		if ( empty( $message['to'] ) || '' === trim( $message['body'] ?? '' ) ) {
			return;
		}

		$sent = $this->send_mail(
			(string) $message['to'],
			(string) $message['subject'],
			(string) $message['body'],
			$type,
			$payload
		);

		if ( $sent ) {
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			// Notification failed after max attempts.
			return;
		}

		wp_schedule_single_event(
			time() + self::RETRY_DELAY,
			self::RETRY_ACTION,
			array(
				$type,
				$booking_id,
				$attempt + 1,
			)
		);
	}

	/**
	 * Return reminder hours configured in settings.
	 *
	 * @return array<int, int>
	 */
	private function get_reminder_hours(): array {
		$settings = $this->settings_repository->get_settings();
		$raw      = $settings['booking_reminder_hours'] ?? array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$hours = array();
		foreach ( $raw as $value ) {
			$normalized = absint( $value );
			if ( $normalized <= 0 ) {
				continue;
			}
			$hours[] = $normalized;
		}

		$hours = array_values( array_unique( $hours ) );
		sort( $hours );

		return $hours;
	}

	/**
	 * Parse booking datetime string into a timestamp.
	 *
	 * @param string $value Datetime string in Y-m-d H:i:s.
	 * @return int|null
	 */
	private function parse_datetime_to_timestamp( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}

		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( $datetime ) {
			return $datetime->getTimestamp();
		}

		$timestamp = strtotime( $value );
		return false !== $timestamp ? (int) $timestamp : null;
	}

	/**
	 * Get reminder send history for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_sent_reminders( int $booking_id ): array {
		$sent = get_post_meta( $booking_id, self::META_REMINDER_SENT, true );
		if ( ! is_array( $sent ) ) {
			return array();
		}

		return array_values( $sent );
	}

	/**
	 * Check if a reminder was already sent for the given offset.
	 *
	 * @param array<int, array<string, mixed>> $sent Sent reminder list.
	 * @param int                              $hours Hours before the booking.
	 * @param string                           $start Booking start datetime.
	 * @return bool
	 */
	private function has_sent_reminder( array $sent, int $hours, string $start ): bool {
		foreach ( $sent as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_hours = isset( $entry['hours'] ) ? (int) $entry['hours'] : 0;
			$entry_start = isset( $entry['start'] ) ? (string) $entry['start'] : '';

			if ( $entry_hours === $hours && $entry_start === $start ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send a reminder email to the booking customer.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $hours_before Hours before reservation.
	 * @return bool
	 */
	private function send_customer_reminder( int $booking_id, int $hours_before ): bool {
		$payload = $this->build_booking_payload( $booking_id );
		if ( array() === $payload ) {
			return false;
		}

		$to = $payload['customer_email'];
		if ( '' === trim( (string) $to ) || ! is_email( $to ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %1$s: Provider name, %2$d: Hours before reservation. */
			__( '[%1$s] Reservation reminder (%2$d hours before)', 'vk-booking-manager' ),
			$payload['provider_name'],
			$hours_before
		);
		$lead = sprintf(
			/* translators: %d: Hours before reservation. */
			__( 'This is a reminder for your reservation in %d hours.', 'vk-booking-manager' ),
			$hours_before
		);

		$body = $this->render_customer_body( $payload, $lead );

		return $this->send_mail(
			(string) $to,
			(string) $subject,
			(string) $body,
			self::TYPE_REMINDER_CUSTOMER,
			$payload
		);
	}

	/**
	 * 通知タイプに応じた送信者情報を適用してメールを送信します。
	 *
	 * @param string               $to      送信先メールアドレス。
	 * @param string               $subject 件名。
	 * @param string               $body    本文。
	 * @param string               $type    通知タイプ。
	 * @param array<string, mixed> $payload 通知本文・送信先の生成に使う予約データ。
	 * @return bool
	 */
	private function send_mail( string $to, string $subject, string $body, string $type, array $payload ): bool {
		$mail_header = $this->get_mail_header( $type, $payload );
		$from_name   = $this->sanitize_mail_header_name( (string) $mail_header['name'] );
		$headers     = $this->build_headers( (string) $mail_header['reply_to'] );

		// WordPress 標準の from / from_name フィルターで送信者情報を設定する。
		$from_mail_filter = static function () use ( $mail_header ) {
			return (string) $mail_header['mail'];
		};
		$from_name_filter = static function () use ( $from_name ) {
			return $from_name;
		};

		add_filter( 'wp_mail_from', $from_mail_filter );
		add_filter( 'wp_mail_from_name', $from_name_filter );

		try {
			return (bool) wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_filter( 'wp_mail_from', $from_mail_filter );
			remove_filter( 'wp_mail_from_name', $from_name_filter );
		}
	}

	/**
	 * メールヘッダー用の表示名を安全な文字列に整形します。
	 *
	 * @param string $name 表示名。
	 * @return string
	 */
	private function sanitize_mail_header_name( string $name ): string {
		// 一般的なテキスト入力として正規化し、危険な文字列を除去する。
		$sanitized_name = sanitize_text_field( $name );

		// ヘッダーインジェクション対策として CR/LF を除去する。
		$sanitized_name = str_replace( array( "\r", "\n" ), '', $sanitized_name );

		return trim( $sanitized_name );
	}

	/**
	 * 送信ヘッダー文字列を組み立てます。
	 *
	 * @param string $reply_to Reply-To に設定するメールアドレス。
	 * @return array<int,string>
	 */
	private function build_headers( string $reply_to ): array {
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
		);

		// 施設向け通知など Reply-To が必要なケースのみ付与する。
		if ( '' !== $reply_to ) {
			$headers[] = sprintf( 'Reply-To: %s', $reply_to );
		}

		return $headers;
	}

	/**
	 * 通知タイプに応じた送信者情報を返します。
	 *
	 * @param string               $type    通知タイプ。
	 * @param array<string, mixed> $payload 通知本文・送信先の生成に使う予約データ。
	 * @return array{name:string,mail:string,reply_to:string}
	 */
	private function get_mail_header( string $type, array $payload ): array {
		$from_mail = sanitize_email( (string) get_option( 'admin_email' ) );

		$provider_name = trim( (string) ( $payload['provider_name'] ?? '' ) );
		$site_name     = trim( (string) ( $payload['site_name'] ?? '' ) );
		$default_name  = '' !== $provider_name ? $provider_name : $site_name;

		$header = array(
			'name'     => $default_name,
			'mail'     => $from_mail,
			'reply_to' => '',
		);

		if ( ! $this->is_provider_notification_type( $type ) ) {
			// ユーザー向け通知は店舗メールアドレスを Reply-To に設定する。
			$provider_mail = sanitize_email( (string) ( $payload['provider_email'] ?? '' ) );
			if ( is_email( $provider_mail ) ) {
				$header['reply_to'] = $provider_mail;
			}
			return $header;
		}

		// 施設向け通知は予約投稿者名を優先して From 名に利用する。
		$author_name = trim( (string) ( $payload['booking_author_name'] ?? '' ) );
		if ( '' !== $author_name ) {
			$header['name'] = $author_name;
		}

		// 施設側が返信しやすいように Reply-To に予約者メールを設定する。
		$customer_mail = sanitize_email( (string) ( $payload['customer_email'] ?? '' ) );
		if ( is_email( $customer_mail ) ) {
			$header['reply_to'] = $customer_mail;
		}

		return $header;
	}

	/**
	 * 通知タイプが施設向け通知かどうかを判定します。
	 *
	 * @param string $type 通知タイプ。
	 * @return bool
	 */
	private function is_provider_notification_type( string $type ): bool {
		return in_array(
			$type,
			array(
				self::TYPE_PENDING_PROVIDER,
				self::TYPE_CONFIRMED_PROVIDER,
				self::TYPE_CANCELLED_PROVIDER,
			),
			true
		);
	}

	/**
	 * Build booking data used within notifications.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string,mixed>
	 */
	private function build_booking_payload( int $booking_id ): array {
		$booking = get_post( $booking_id );

		if ( ! $booking instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $booking->post_type ) {
			return array();
		}

		$settings           = $this->settings_repository->get_settings();
		$menu_id            = (int) get_post_meta( $booking_id, '_vkbm_booking_service_id', true );
		$staff_id           = (int) get_post_meta( $booking_id, '_vkbm_booking_resource_id', true );
		$is_staff_preferred = '1' === (string) get_post_meta( $booking_id, '_vkbm_booking_is_staff_preferred', true );
		$start              = (string) get_post_meta( $booking_id, '_vkbm_booking_service_start', true );
		$end                = (string) get_post_meta( $booking_id, '_vkbm_booking_service_end', true );
		$customer_name      = (string) get_post_meta( $booking_id, '_vkbm_booking_customer_name', true );
		$customer_email     = sanitize_email( (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ) );
		$customer_tel       = (string) get_post_meta( $booking_id, '_vkbm_booking_customer_tel', true );
		$memo               = wp_strip_all_tags( (string) get_post_meta( $booking_id, '_vkbm_booking_note', true ) );
		$status             = (string) get_post_meta( $booking_id, '_vkbm_booking_status', true );
		$nomination_fee     = (int) get_post_meta( $booking_id, '_vkbm_booking_nomination_fee', true );
		if ( ! Staff_Editor::is_enabled() ) {
			$nomination_fee = 0;
		}
		$has_price_snapshot = metadata_exists( 'post', $booking_id, '_vkbm_booking_service_base_price' );
		$base_price         = $has_price_snapshot
			? (int) get_post_meta( $booking_id, '_vkbm_booking_service_base_price', true )
			: (int) get_post_meta( $menu_id, '_vkbm_base_price', true );
		$base_price         = max( 0, $base_price );

		$menu_title  = $menu_id > 0 ? get_the_title( $menu_id ) : '';
		$staff_title = '';
		if ( $is_staff_preferred && $staff_id > 0 ) {
			$staff_title = get_the_title( $staff_id );
		}
		if ( '' === $staff_title ) {
			$staff_title = __( 'No preference', 'vk-booking-manager' );
		}
		$duration    = $this->get_menu_duration( $menu_id );
		$price_label = $this->get_menu_price_label( $base_price, $nomination_fee, $settings );
		$resource_label_singular = isset( $settings['resource_label_singular'] ) ? trim( (string) $settings['resource_label_singular'] ) : '';
		if ( '' === $resource_label_singular ) {
			$resource_label_singular = __( 'Staff', 'vk-booking-manager' );
		}

		$booking_author_name = $this->resolve_booking_author_name( $booking );

		if ( '' === trim( $customer_name ) ) {
			$customer_name = $booking_author_name;
		}

		$edit_url = get_edit_post_link( $booking_id, '', true );
		if ( ! $edit_url ) {
			$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $booking_id ) );
		}

		return array(
			'booking_id'                   => $booking_id,
			'menu_title'                   => '' !== $menu_title ? $menu_title : __( 'Not set', 'vk-booking-manager' ),
			'staff_title'                  => '' !== $staff_title ? $staff_title : __( 'TBD', 'vk-booking-manager' ),
			'reservation_datetime'         => $this->format_reservation_datetime_range( $start, $end ),
			'duration_label'               => $duration,
			'price_label'                  => $price_label,
			'customer_name'                => '' !== $customer_name ? $customer_name : __( 'Customer', 'vk-booking-manager' ),
			'booking_author_name'          => $booking_author_name,
			'customer_email'               => $customer_email,
			'customer_tel'                 => $customer_tel,
			'memo'                         => '' !== $memo ? $memo : __( '(none)', 'vk-booking-manager' ),
			'status'                       => $status,
			'provider_email'               => sanitize_email( (string) ( $settings['provider_email'] ?? '' ) ),
			'provider_name'                => isset( $settings['provider_name'] ) && '' !== $settings['provider_name'] ? $settings['provider_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'provider_phone'               => $settings['provider_phone'] ?? '',
			'provider_address'             => $settings['provider_address'] ?? '',
			'provider_site'                => isset( $settings['provider_website_url'] ) && '' !== $settings['provider_website_url'] ? $settings['provider_website_url'] : home_url(),
			'provider_cancellation_policy' => isset( $settings['provider_cancellation_policy'] ) ? (string) $settings['provider_cancellation_policy'] : '',
			'resource_label_singular'      => $resource_label_singular,
			'site_name'                    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'edit_url'                     => $edit_url,
		);
	}

	/**
	 * Resolve customer name from booking author if no explicit name is stored.
	 *
	 * @param WP_Post $booking Booking post.
	 * @return string
	 */
	private function resolve_booking_author_name( WP_Post $booking ): string {
		$author_id = (int) $booking->post_author;
		if ( $author_id <= 0 ) {
			return '';
		}

		$user = get_user_by( 'id', $author_id );
		if ( ! $user instanceof WP_User ) {
			return '';
		}

		return VKBM_Helper::get_user_display_name( $user );
	}

	/**
	 * Build the outgoing message details.
	 *
	 * @param string               $type    Notification type.
	 * @param array<string, mixed> $payload Booking payload.
	 * @return array{to:string,subject:string,body:string}
	 */
	private function build_message( string $type, array $payload ): array {
		switch ( $type ) {
			case self::TYPE_PENDING_PROVIDER:
				return array(
					'to'      => $payload['provider_email'],
					/* translators: %1$s: Provider name, %2$s: Booking status label. */
					'subject' => sprintf( __( '[ %1$s ][ %2$s ] A new reservation has been made.', 'vk-booking-manager' ), $payload['provider_name'], __( 'Pending', 'vk-booking-manager' ) ),
					'body'    => $this->render_provider_body( $payload, __( 'Pending', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CONFIRMED_PROVIDER:
				return array(
					'to'      => $payload['provider_email'],
					/* translators: %s: Provider name. */
					'subject' => sprintf( __( '[ %s ] Reservation confirmed', 'vk-booking-manager' ), $payload['provider_name'] ),
					'body'    => $this->render_provider_body( $payload, __( 'Confirmed', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CONFIRMED_CUSTOMER:
				return array(
					'to'      => $payload['customer_email'],
					/* translators: %s: Provider name. */
					'subject' => sprintf( __( '[ %s ] Your reservation has been confirmed', 'vk-booking-manager' ), $payload['provider_name'] ),
					'body'    => $this->render_customer_body( $payload, __( 'Your reservation has been confirmed.', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CANCELLED_CUSTOMER:
				return array(
					'to'      => $payload['customer_email'],
					/* translators: %s: Provider name. */
					'subject' => sprintf( __( '[ %s ] Your reservation has been canceled', 'vk-booking-manager' ), $payload['provider_name'] ),
					'body'    => $this->render_customer_body( $payload, __( 'Your reservation has been cancelled.', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CANCELLED_PROVIDER:
				return array(
					'to'      => $payload['provider_email'],
					/* translators: %s: Provider name. */
					'subject' => sprintf( __( '[ %s ] Reservation canceled', 'vk-booking-manager' ), $payload['provider_name'] ),
					'body'    => $this->render_provider_body( $payload, __( 'Cancelled', 'vk-booking-manager' ) ),
				);

			case self::TYPE_PENDING_CUSTOMER:
			default:
				return array(
					'to'      => $payload['customer_email'],
					/* translators: %1$s: Provider name, %2$s: Booking status label. */
					'subject' => sprintf( __( '[ %1$s ][ %2$s ] Your reservation has been accepted.', 'vk-booking-manager' ), $payload['provider_name'], __( 'Pending', 'vk-booking-manager' ) ),
					'body'    => $this->render_customer_body( $payload, __( 'We have tentatively accepted your reservation.', 'vk-booking-manager' ) ),
				);
		}
	}

	/**
	 * Build shared reservation information lines.
	 *
	 * @param array<string,mixed> $payload Booking payload.
	 * @param string              $status_label Status label.
	 * @return array<int,string>
	 */
	private function get_reservation_information_lines( array $payload, string $status_label = '' ): array {
		$lines   = array();
		$lines[] = __( '--- Reservation information ---', 'vk-booking-manager' );
		/* translators: %d: Booking ID. */
		$lines[] = sprintf( __( 'Reservation number: #%d', 'vk-booking-manager' ), $payload['booking_id'] );
		if ( '' !== $status_label ) {
			/* translators: %s: Booking status label. */
			$lines[] = sprintf( __( 'Status: %s', 'vk-booking-manager' ), $status_label );
		}
		/* translators: %s: Menu title. */
		$lines[] = sprintf( __( 'Menu: %s', 'vk-booking-manager' ), $payload['menu_title'] );
		/* translators: 1: Resource label, 2: Staff name. */
		$lines[] = sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), $payload['resource_label_singular'], $payload['staff_title'] );
		/* translators: %s: Reservation datetime range. */
		$lines[] = sprintf( __( 'Reservation date and time: %s', 'vk-booking-manager' ), $payload['reservation_datetime'] );
		if ( $payload['duration_label'] ) {
			/* translators: %s: Duration label. */
			$lines[] = sprintf( __( 'Time required: %s', 'vk-booking-manager' ), $payload['duration_label'] );
		}
		if ( $payload['price_label'] ) {
			/* translators: %s: Price label. */
			$lines[] = sprintf( __( 'Price guide: %s', 'vk-booking-manager' ), $payload['price_label'] );
		}

		return $lines;
	}

	/**
	 * Format message body for customers.
	 *
	 * @param array<string,mixed> $payload Booking payload.
	 * @param string              $lead    Leading sentence.
	 * @return string
	 */
	private function render_customer_body( array $payload, string $lead ): string {
		$lines               = array();
		$cancellation_policy = trim( (string) ( $payload['provider_cancellation_policy'] ?? '' ) );

		/* translators: %s: Customer name. */
		$lines[] = sprintf( __( 'Dear %s', 'vk-booking-manager' ), $payload['customer_name'] );
		$lines[] = '';
		$lines[] = $lead;
		if ( 'pending' === (string) ( $payload['status'] ?? '' ) ) {
			$lines[] = __( 'This reservation is a provisional reservation. The administrator will confirm and confirm.', 'vk-booking-manager' );
		}
		$lines[] = __( 'Please check the following information.', 'vk-booking-manager' );
		$lines[] = '';
		$lines   = array_merge( $lines, $this->get_reservation_information_lines( $payload ) );
		$customer_tel = isset( $payload['customer_tel'] ) && '' !== $payload['customer_tel'] ? $payload['customer_tel'] : __( 'Not provided', 'vk-booking-manager' );
		/* translators: %s: Customer contact value. */
		$lines[]      = sprintf( __( 'Contact: %s', 'vk-booking-manager' ), $customer_tel );
		$lines[]      = __( 'Request contents/memo:', 'vk-booking-manager' );
		$lines[]      = $payload['memo'];
		$lines[]      = '';
		if ( '' !== $cancellation_policy ) {
			$lines[] = '';
			$lines[] = __( '--- Cancellation Policy ---', 'vk-booking-manager' );
			$lines[] = $cancellation_policy;
			$lines[] = '';
		}
		$lines[] = '===========';
		$lines[] = $payload['provider_name'];
		if ( $payload['provider_phone'] ) {
			/* translators: %s: Provider phone number. */
			$lines[] = sprintf( __( 'TEL: %s', 'vk-booking-manager' ), $payload['provider_phone'] );
		}
		if ( $payload['provider_address'] ) {
			$lines[] = $payload['provider_address'];
		}
		if ( $payload['provider_site'] ) {
			$lines[] = $payload['provider_site'];
		}
		$lines[] = __( 'If you do not recognize this email, please discard it.', 'vk-booking-manager' );

		return implode( "\n", $lines );
	}

	/**
	 * Format message body for providers.
	 *
	 * @param array<string,mixed> $payload Booking payload.
	 * @param string              $status_label Status label.
	 * @return string
	 */
	private function render_provider_body( array $payload, string $status_label ): string {
		$lines               = array();
		$cancellation_policy = trim( (string) ( $payload['provider_cancellation_policy'] ?? '' ) );

		if ( __( 'Cancelled', 'vk-booking-manager' ) === $status_label ) {
			// Use a clearer lead for cancellation notifications.
			// キャンセル通知では自然な表現に置き換える.
			$lines[] = __( 'Your reservation has been cancelled.', 'vk-booking-manager' );
		} else {
			/* translators: %s: Booking status label. */
			$lines[] = sprintf( __( 'A new %s has been registered.', 'vk-booking-manager' ), $status_label );
		}
		$lines[] = '';
		$lines = array_merge( $lines, $this->get_reservation_information_lines( $payload, $status_label ) );
		$lines[]        = '';
		$lines[]        = __( '--- Customer information ---', 'vk-booking-manager' );
		/* translators: %s: Customer name. */
		$lines[]        = sprintf( __( 'Name: %s', 'vk-booking-manager' ), $payload['customer_name'] );
		$customer_email = isset( $payload['customer_email'] ) && '' !== $payload['customer_email'] ? $payload['customer_email'] : __( 'Not provided', 'vk-booking-manager' );
		$customer_tel   = isset( $payload['customer_tel'] ) && '' !== $payload['customer_tel'] ? $payload['customer_tel'] : __( 'Not provided', 'vk-booking-manager' );
		/* translators: %s: Customer email address. */
		$lines[]        = sprintf( __( 'Email: %s', 'vk-booking-manager' ), $customer_email );
		/* translators: %s: Customer phone number. */
		$lines[]        = sprintf( __( 'Phone number: %s', 'vk-booking-manager' ), $customer_tel );
		$lines[]        = __( 'Note:', 'vk-booking-manager' );
		$lines[]        = $payload['memo'];
		if ( '' !== $cancellation_policy ) {
			$lines[] = '';
			$lines[] = __( '--- Cancellation Policy ---', 'vk-booking-manager' );
			$lines[] = $cancellation_policy;
		}
		if ( ! empty( $payload['edit_url'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Manage this reservation', 'vk-booking-manager' );
			$lines[] = $payload['edit_url'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * WordPress の日付・時刻設定を使って日時を整形します。
	 *
	 * @param string $value DB 保存形式の日時文字列.
	 * @return string
	 */
	private function format_datetime( string $value ): string {
		if ( '' === $value ) {
			return __( 'Not set', 'vk-booking-manager' );
		}

		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( ! $datetime ) {
			return $value;
		}

		$date_format = (string) get_option( 'date_format' );
		$time_format = (string) get_option( 'time_format' );

		if ( '' === trim( $date_format ) ) {
			$date_format = 'Y-m-d';
		}
		if ( '' === trim( $time_format ) ) {
			$time_format = 'H:i';
		}

		return wp_date( $date_format . ' ' . $time_format, $datetime->getTimestamp(), $timezone );
	}

	/**
	 * WordPress の日付・時刻設定を使って、曜日付きの日時を整形します。
	 *
	 * @param string $value DB 保存形式の日時文字列.
	 * @return string
	 */
	private function format_datetime_with_weekday( string $value ): string {
		if ( '' === $value ) {
			return __( 'Not set', 'vk-booking-manager' );
		}

		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( ! $datetime ) {
			return $value;
		}

		$date_format = (string) get_option( 'date_format' );
		$time_format = (string) get_option( 'time_format' );

		if ( '' === trim( $date_format ) ) {
			$date_format = 'Y-m-d';
		}
		if ( '' === trim( $time_format ) ) {
			$time_format = 'H:i';
		}

		$timestamp = $datetime->getTimestamp();
		$date_text = wp_date( $date_format, $timestamp, $timezone );
		$day_text  = $this->get_localized_weekday_abbrev( $timestamp, $timezone );
		$time_text = wp_date( $time_format, $timestamp, $timezone );

		/* translators: 1: Date text, 2: Day of week, 3: Time text. */
		return sprintf( __( '%1$s (%2$s) %3$s', 'vk-booking-manager' ), $date_text, $day_text, $time_text );
	}

	/**
	 * 指定タイムスタンプの曜日略称を、WordPress ロケール情報から取得します。
	 *
	 * @param int          $timestamp Unix timestamp.
	 * @param \DateTimeZone $timezone タイムゾーン.
	 * @return string
	 */
	private function get_localized_weekday_abbrev( int $timestamp, \DateTimeZone $timezone ): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$weekday_number = (int) wp_date( 'w', $timestamp, $timezone );

		if ( 0 === strpos( (string) $locale, 'ja' ) ) {
			$ja_weekdays = array( '日', '月', '火', '水', '木', '金', '土' );
			if ( isset( $ja_weekdays[ $weekday_number ] ) ) {
				return $ja_weekdays[ $weekday_number ];
			}
		}

		global $wp_locale;

		if ( isset( $wp_locale ) && method_exists( $wp_locale, 'get_weekday' ) && method_exists( $wp_locale, 'get_weekday_abbrev' ) ) {
			$weekday_name   = (string) $wp_locale->get_weekday( $weekday_number );

			if ( '' !== $weekday_name ) {
				return (string) $wp_locale->get_weekday_abbrev( $weekday_name );
			}
		}

		return wp_date( 'D', $timestamp, $timezone );
	}

	/**
	 * 予約の日時範囲を整形します。
	 * 同日の予約は終了時刻のみを表示し、日付をまたぐ予約は終了日時をフル表示します。
	 *
	 * @param string $start DB 保存形式の開始日時文字列.
	 * @param string $end   DB 保存形式の終了日時文字列.
	 * @return string
	 */
	private function format_reservation_datetime_range( string $start, string $end ): string {
		if ( '' === $start || '' === $end ) {
			return __( 'Not set', 'vk-booking-manager' );
		}

		$timezone       = wp_timezone();
		$start_datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start, $timezone );
		$end_datetime   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end, $timezone );

		if ( ! $start_datetime || ! $end_datetime ) {
			return $this->format_datetime_with_weekday( $start ) . ' - ' . $this->format_datetime_with_weekday( $end );
		}

		$start_timestamp = $start_datetime->getTimestamp();
		$end_timestamp   = $end_datetime->getTimestamp();
		$start_full      = $this->format_datetime_with_weekday( $start );

		if ( wp_date( 'Y-m-d', $start_timestamp, $timezone ) === wp_date( 'Y-m-d', $end_timestamp, $timezone ) ) {
			$time_format = (string) get_option( 'time_format' );
			if ( '' === trim( $time_format ) ) {
				$time_format = 'H:i';
			}

			return $start_full . ' - ' . wp_date( $time_format, $end_timestamp, $timezone );
		}

		return $start_full . ' - ' . $this->format_datetime_with_weekday( $end );
	}

	/**
	 * Return the service duration as a label.
	 *
	 * @param int $menu_id Menu post ID.
	 * @return string
	 */
	private function get_menu_duration( int $menu_id ): string {
		if ( $menu_id <= 0 ) {
			return '';
		}

		$duration = (int) get_post_meta( $menu_id, '_vkbm_duration_minutes', true );

		if ( $duration <= 0 ) {
			return '';
		}

		/* translators: %d: duration in minutes */
		return sprintf( __( '%d minutes', 'vk-booking-manager' ), $duration );
	}

	/**
	 * Build the price label, including nomination fee if present.
	 *
	 * @param int                 $base_price    Base price.
	 * @param int                 $nomination_fee Nomination fee.
	 * @param array<string,mixed> $settings      Provider settings.
	 * @return string
	 */
	private function get_menu_price_label( int $base_price, int $nomination_fee, array $settings ): string {
		$base_price = max( 0, $base_price );

		$display = $base_price;

		$label = VKBM_Helper::format_currency( (int) $display );

		$tax_label = VKBM_Helper::get_tax_included_label();
		if ( '' !== $tax_label ) {
			$label .= $tax_label;
		}

		if ( $nomination_fee > 0 ) {
			$label .= sprintf(
				/* translators: %s: nomination fee amount */
				__( '+ Nomination fee %s', 'vk-booking-manager' ),
				VKBM_Helper::format_currency( absint( $nomination_fee ) )
			);
		}

		return $label;
	}
}
