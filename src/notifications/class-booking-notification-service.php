<?php
/**
 * Booking notification service.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Notifications;

use DateTimeImmutable;
use VKBookingManager\Bookings\Customer_Name_Resolver;
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
use function error_log;
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
use function sprintf;
use function strtotime;
use function wp_date;
use function wp_next_scheduled;
use function wp_parse_url;
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

	/**
	 * Settings store.
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Customer name resolver.
	 *
	 * @var Customer_Name_Resolver
	 */
	private $customer_name_resolver;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository         $settings_repository Settings store.
	 * @param Customer_Name_Resolver|null $customer_name_resolver Customer name resolver.
	 */
	public function __construct( Settings_Repository $settings_repository, ?Customer_Name_Resolver $customer_name_resolver = null ) {
		$this->settings_repository    = $settings_repository;
		$this->customer_name_resolver = null !== $customer_name_resolver ? $customer_name_resolver : new Customer_Name_Resolver();
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
			error_log( sprintf( 'VK Booking Manager: notification for booking #%d (%s) abandoned after %d attempts.', $booking_id, $type, $attempt - 1 ) );
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

		$headers = $this->build_headers( $payload['site_name'] );
		$sent    = wp_mail( $message['to'], $message['subject'], $message['body'], $headers );

		if ( $sent ) {
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			error_log( sprintf( 'VK Booking Manager: notification for booking #%d (%s) failed after %d attempts.', $booking_id, $type, $attempt ) );
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

		$body    = $this->render_customer_body( $payload, $lead );
		$headers = $this->build_headers( $payload['site_name'] );

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Compose email headers.
	 *
	 * @param string $site_name Site name used as From name.
	 * @return array<int,string>
	 */
	private function build_headers( string $site_name ): array {
		$from_email = get_option( 'admin_email' );

		if ( ! is_email( $from_email ) ) {
			$host       = wp_parse_url( home_url(), PHP_URL_HOST );
			$from_email = $host ? 'wordpress@' . $host : 'wordpress@localhost';
		}

		return array(
			sprintf( 'From: %s <%s>', $site_name, $from_email ),
			'Content-Type: text/plain; charset=UTF-8',
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

		if ( '' === trim( $customer_name ) ) {
			$customer_name = $this->resolve_booking_author_name( $booking );
		}

		$edit_url = get_edit_post_link( $booking_id, '', true );
		if ( ! $edit_url ) {
			$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $booking_id ) );
		}

		return array(
			'booking_id'                   => $booking_id,
			'menu_title'                   => '' !== $menu_title ? $menu_title : __( 'Not set', 'vk-booking-manager' ),
			'staff_title'                  => '' !== $staff_title ? $staff_title : __( 'TBD', 'vk-booking-manager' ),
			'start_label'                  => $this->format_datetime( $start ),
			'end_label'                    => $this->format_time( $end ),
			'duration_label'               => $duration,
			'price_label'                  => $price_label,
			'customer_name'                => '' !== $customer_name ? $customer_name : __( 'Customer', 'vk-booking-manager' ),
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

		return $this->customer_name_resolver->resolve_for_user( $user );
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
					'subject' => sprintf( '[%s][%s] A new reservation has been made.', $payload['provider_name'], __( 'Pending', 'vk-booking-manager' ) ),
					'body'    => $this->render_provider_body( $payload, __( 'Pending', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CONFIRMED_PROVIDER:
				return array(
					'to'      => $payload['provider_email'],
					'subject' => sprintf( '[%s] Reservation confirmed', $payload['provider_name'] ),
					'body'    => $this->render_provider_body( $payload, __( 'Confirmed', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CONFIRMED_CUSTOMER:
				return array(
					'to'      => $payload['customer_email'],
					'subject' => sprintf( '[%s] Your reservation has been confirmed', $payload['provider_name'] ),
					'body'    => $this->render_customer_body( $payload, __( 'Your reservation has been confirmed.', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CANCELLED_CUSTOMER:
				return array(
					'to'      => $payload['customer_email'],
					'subject' => sprintf( '[%s] Your reservation has been canceled', $payload['provider_name'] ),
					'body'    => $this->render_customer_body( $payload, __( 'Your reservation has been cancelled.', 'vk-booking-manager' ) ),
				);

			case self::TYPE_CANCELLED_PROVIDER:
				return array(
					'to'      => $payload['provider_email'],
					'subject' => sprintf( '[%s] Reservation canceled', $payload['provider_name'] ),
					'body'    => $this->render_provider_body( $payload, __( 'Cancelled', 'vk-booking-manager' ) ),
				);

			case self::TYPE_PENDING_CUSTOMER:
			default:
				return array(
					'to'      => $payload['customer_email'],
					'subject' => sprintf( '[%s][%s] Your reservation has been accepted.', $payload['provider_name'], __( 'Pending', 'vk-booking-manager' ) ),
					'body'    => $this->render_customer_body( $payload, __( 'We have tentatively accepted your reservation.', 'vk-booking-manager' ) ),
				);
		}
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

		$lines[] = sprintf( 'Dear %s', $payload['customer_name'] );
		$lines[] = '';
		$lines[] = $lead;
		if ( 'pending' === (string) ( $payload['status'] ?? '' ) ) {
			$lines[] = __( 'This reservation is a provisional reservation. The administrator will confirm and confirm.', 'vk-booking-manager' );
		}
		$lines[] = 'Please check the following information.';
		$lines[] = '';
		$lines[] = '--- Reservation information ---';
		$lines[] = sprintf( 'Reservation number: #%d', $payload['booking_id'] );
		$lines[] = sprintf( 'Menu: %s', $payload['menu_title'] );
		$lines[] = sprintf( 'Staff in charge: %s', $payload['staff_title'] );
		$lines[] = sprintf( 'Reservation date and time: %s %s', $payload['start_label'], $payload['end_label'] ? '〜 ' . $payload['end_label'] : '' );
		if ( $payload['duration_label'] ) {
			$lines[] = sprintf( 'Time required: %s', $payload['duration_label'] );
		}
		if ( $payload['price_label'] ) {
			$lines[] = sprintf( 'Price guide: %s', $payload['price_label'] );
		}
		$customer_tel = isset( $payload['customer_tel'] ) && '' !== $payload['customer_tel'] ? $payload['customer_tel'] : __( 'Not provided', 'vk-booking-manager' );
		$lines[]      = sprintf( 'Contact: %s', $customer_tel );
		$lines[]      = 'Request contents/memo:';
		$lines[]      = $payload['memo'];
		$lines[]      = '';
		if ( '' !== $cancellation_policy ) {
			$lines[] = '';
			$lines[] = '--- Cancellation Policy ---';
			$lines[] = $cancellation_policy;
			$lines[] = '';
		}
		$lines[] = '===========';
		$lines[] = $payload['provider_name'];
		if ( $payload['provider_phone'] ) {
			$lines[] = sprintf( 'TEL: %s', $payload['provider_phone'] );
		}
		if ( $payload['provider_address'] ) {
			$lines[] = $payload['provider_address'];
		}
		if ( $payload['provider_site'] ) {
			$lines[] = sprintf( 'Web: %s', $payload['provider_site'] );
		}
		$lines[] = 'If you do not recognize this email, please discard it.';

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
			$lines[] = sprintf( 'A new %s has been registered.', $status_label );
		}
		$lines[] = '';
		$lines[] = '--- Reservation information ---';
		$lines[] = sprintf( 'Reservation number: #%d', $payload['booking_id'] );
		$lines[] = sprintf( 'Status: %s', $status_label );
		$lines[] = sprintf( 'Menu: %s', $payload['menu_title'] );
		$lines[] = sprintf( 'Staff in charge: %s', $payload['staff_title'] );
		$lines[] = sprintf( 'Reservation date and time: %s %s', $payload['start_label'], $payload['end_label'] ? '〜 ' . $payload['end_label'] : '' );
		if ( $payload['duration_label'] ) {
			$lines[] = sprintf( 'Time required: %s', $payload['duration_label'] );
		}
		if ( $payload['price_label'] ) {
			$lines[] = sprintf( 'Price guide: %s', $payload['price_label'] );
		}
		$lines[]        = '';
		$lines[]        = '--- Customer information ---';
		$lines[]        = sprintf( 'Name: %s', $payload['customer_name'] );
		$customer_email = isset( $payload['customer_email'] ) && '' !== $payload['customer_email'] ? $payload['customer_email'] : __( 'Not provided', 'vk-booking-manager' );
		$customer_tel   = isset( $payload['customer_tel'] ) && '' !== $payload['customer_tel'] ? $payload['customer_tel'] : __( 'Not provided', 'vk-booking-manager' );
		$lines[]        = sprintf( 'Email: %s', $customer_email );
		$lines[]        = sprintf( 'Phone number: %s', $customer_tel );
		$lines[]        = 'Note:';
		$lines[]        = $payload['memo'];
		if ( '' !== $cancellation_policy ) {
			$lines[] = '';
			$lines[] = '--- Cancellation Policy ---';
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
	 * Format datetime for display.
	 *
	 * @param string $value Database datetime.
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

		return wp_date( 'Y year n month j day H:i', $datetime->getTimestamp() );
	}

	/**
	 * Format only the time portion.
	 *
	 * @param string $value Datetime string.
	 * @return string
	 */
	private function format_time( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( ! $datetime ) {
			return '';
		}

		return $datetime->format( 'H:i' );
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
