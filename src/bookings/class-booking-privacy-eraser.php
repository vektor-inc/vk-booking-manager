<?php
/**
 * WordPress 本体の個人データ消去依頼から予約を匿名化する処理（issue #872）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use DateTimeImmutable;
use Exception;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;

/**
 * メールアドレスに一致する予約を個人データ消去依頼から匿名化する。
 */
class Booking_Privacy_Eraser {

	private const PER_PAGE            = 20;
	private const LIST_LIMIT          = 10;
	private const META_CUSTOMER_EMAIL = '_vkbm_booking_customer_email';
	private const META_SERVICE_START  = '_vkbm_booking_service_start';
	private const META_SERVICE_ID     = '_vkbm_booking_service_id';

	/**
	 * 匿名化処理本体。
	 *
	 * @var Booking_Anonymizer
	 */
	private $anonymizer;

	/**
	 * コンストラクター。
	 *
	 * @param Booking_Anonymizer $anonymizer 匿名化処理本体。
	 */
	public function __construct( Booking_Anonymizer $anonymizer ) {
		$this->anonymizer = $anonymizer;
	}

	/**
	 * WordPress 本体の個人データ消去処理へ登録する。
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * 予約の個人データ消去処理を一覧へ追加する。
	 *
	 * @param array<string, array<string, mixed>> $erasers 登録済みの消去処理。
	 * @return array<string, array<string, mixed>> 予約の消去処理を追加した一覧。
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['vk-booking-manager'] = array(
			'eraser_friendly_name' => __( 'VK Booking Manager reservations', 'vk-booking-manager' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * 指定メールアドレスに完全一致する予約を最大20件匿名化する。
	 *
	 * 状態を保存せず毎回先頭から探す。匿名化できた予約はメールが空になり、次回の検索対象から外れる。
	 *
	 * @param string $email_address 消去依頼のメールアドレス。
	 * @param int    $page          WordPress 本体から渡されるページ番号（状態を持たないため使用しない）。
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} 消去結果。
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WordPress 本体の eraser コールバック仕様に合わせる。
		$email = strtolower( trim( $email_address ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->empty_result();
		}

		$booking_ids = $this->find_booking_ids( $email );
		_prime_post_caches( $booking_ids, false, true );
		$results          = array();
		$attempted        = 0;
		$anonymized_count = 0;
		$has_remaining    = false;

		foreach ( $booking_ids as $booking_id ) {
			$result = $this->anonymizer->check_anonymizable( $booking_id );
			if ( Booking_Anonymizer::RESULT_ANONYMIZED === $result ) {
				if ( $attempted >= self::PER_PAGE ) {
					$has_remaining = true;
					continue;
				}

				++$attempted;
				$original_email = get_post_meta( $booking_id, self::META_CUSTOMER_EMAIL, true );
				$result         = $this->anonymizer->anonymize( $booking_id, Booking_Anonymizer::ANONYMIZED_BY_PRIVACY_REQUEST );

				// 途中失敗で検索キーを失うと消去依頼から再実行できないため、匿名化済みの印が無い場合だけ元へ戻す。
				$current_email = get_post_meta( $booking_id, self::META_CUSTOMER_EMAIL, true );
				if ( Booking_Anonymizer::RESULT_FAILED === $result && '' === Booking_Anonymizer::get_anonymized_at( $booking_id ) && $original_email !== $current_email ) {
					update_post_meta( $booking_id, self::META_CUSTOMER_EMAIL, $original_email );
				}

				if ( Booking_Anonymizer::RESULT_ANONYMIZED === $result ) {
					++$anonymized_count;
				}
			}

			if ( in_array( $result, array( Booking_Anonymizer::RESULT_NOT_ENDED, Booking_Anonymizer::RESULT_NO_DATETIME, Booking_Anonymizer::RESULT_FAILED ), true ) ) {
				$results[ $result ][] = $booking_id;
			}
		}

		$has_remaining     = $has_remaining || $this->has_anonymizable_booking( $booking_ids, $email );
		$done              = ! $has_remaining || 0 === $anonymized_count;
		$completed_results = $done ? $results : array();
		$failed_results    = isset( $results[ Booking_Anonymizer::RESULT_FAILED ] ) ? array( Booking_Anonymizer::RESULT_FAILED => $results[ Booking_Anonymizer::RESULT_FAILED ] ) : array();
		$message_results   = array_merge( $completed_results, $failed_results );
		$messages          = $this->build_messages( $anonymized_count, $message_results, $done && $this->member_exists( $email ) );

		return array(
			'items_removed'  => $anonymized_count > 0,
			'items_retained' => ! empty( $failed_results ) || ( $done && ! empty( $results ) ),
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * 大文字小文字と前後空白を無視してメールが一致する予約IDを昇順で返す。
	 *
	 * @param string $email 正規化済みメールアドレス。
	 * @return int[] 予約ID。
	 */
	private function find_booking_ids( string $email ): array {
		global $wpdb;

		// 照合順序に依存しないよう SQL 側で前後空白と大小文字をそろえ、完全一致させる。
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 個人データ消去の都度、照合順序に依存しない完全一致が必要。
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = %s
					AND pm.meta_key = %s
					AND LOWER(TRIM(pm.meta_value)) = %s
				ORDER BY p.ID ASC",
				Booking_Post_Type::POST_TYPE,
				self::META_CUSTOMER_EMAIL,
				$email
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * 取得済みの予約に、現在も同じメールアドレスで匿名化できるものが残っているか判定する。
	 *
	 * @param int[]  $booking_ids この回の検索で取得した予約ID。
	 * @param string $email       正規化済みメールアドレス。
	 * @return bool 匿名化できる予約があれば true。
	 */
	private function has_anonymizable_booking( array $booking_ids, string $email ): bool {
		foreach ( $booking_ids as $booking_id ) {
			$current_email = strtolower( trim( (string) get_post_meta( $booking_id, self::META_CUSTOMER_EMAIL, true ) ) );
			if ( $email === $current_email && Booking_Anonymizer::RESULT_ANONYMIZED === $this->anonymizer->check_anonymizable( $booking_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 消去結果のメッセージを組み立てる。
	 *
	 * @param int                  $anonymized_count この回に匿名化した件数。
	 * @param array<string, int[]> $results          保持した予約IDを結果別に分けた配列。
	 * @param bool                 $member_exists     同じメールアドレスの会員がいるか。
	 * @return string[] WordPress 本体が HTML として表示できるようエスケープしたメッセージ。
	 */
	private function build_messages( int $anonymized_count, array $results, bool $member_exists ): array {
		$messages = array();
		if ( $anonymized_count > 0 ) {
			$messages[] = sprintf(
				/* translators: %s: Number of reservations anonymized in this request. */
				_n( 'VK Booking Manager: Personal data of %s reservation was anonymized.', 'VK Booking Manager: Personal data of %s reservations were anonymized.', $anonymized_count, 'vk-booking-manager' ),
				number_format_i18n( $anonymized_count )
			);
		}

		$not_ended = $results[ Booking_Anonymizer::RESULT_NOT_ENDED ] ?? array();
		if ( $not_ended ) {
			$count      = count( $not_ended );
			$messages[] = sprintf(
				/* translators: 1: Number of reservations, 2: Reservation date/time and service menu list. */
				_n( 'VK Booking Manager: %1$s reservation was not anonymized because its end date and time has not passed yet: %2$s', 'VK Booking Manager: %1$s reservations were not anonymized because their end date and time have not passed yet: %2$s', $count, 'vk-booking-manager' ),
				number_format_i18n( $count ),
				$this->format_booking_list( $not_ended, false )
			);
			$messages[] = __( 'VK Booking Manager: After the end date and time has passed, run "Erase personal data" for this request again, or use the bulk action "Anonymize personal data" on the reservation list.', 'vk-booking-manager' );
		}

		$no_datetime = $results[ Booking_Anonymizer::RESULT_NO_DATETIME ] ?? array();
		if ( $no_datetime ) {
			$count      = count( $no_datetime );
			$messages[] = sprintf(
				/* translators: 1: Number of reservations, 2: Reservation creation date/time and service menu list. */
				_n( 'VK Booking Manager: %1$s reservation was not anonymized because its date and time is not set: %2$s', 'VK Booking Manager: %1$s reservations were not anonymized because their date and time are not set: %2$s', $count, 'vk-booking-manager' ),
				number_format_i18n( $count ),
				$this->format_booking_list( $no_datetime, true )
			);
			$messages[] = __( 'VK Booking Manager: Enter the date and time on the reservation edit screen and wait until it has passed, or delete the reservation if it is no longer needed.', 'vk-booking-manager' );
		}

		$failed = $results[ Booking_Anonymizer::RESULT_FAILED ] ?? array();
		if ( $failed ) {
			$count      = count( $failed );
			$messages[] = sprintf(
				/* translators: %s: Number of reservations that could not be saved. */
				_n( 'VK Booking Manager: %s reservation could not be anonymized because saving failed. Run "Erase personal data" again.', 'VK Booking Manager: %s reservations could not be anonymized because saving failed. Run "Erase personal data" again.', $count, 'vk-booking-manager' ),
				number_format_i18n( $count )
			);
		}

		if ( $member_exists ) {
			$messages[] = __( 'VK Booking Manager: The member profile for this email address (name, kana, birth date, gender, phone number, customer flag, people receiving the service and their numbers) was not erased. To remove the account, delete the user from the Users screen after all remaining reservations have ended.', 'vk-booking-manager' );
		}

		return array_map( 'esc_html', $messages );
	}

	/**
	 * 予約を「日時（メニュー名）」の一覧にする。
	 *
	 * @param int[] $booking_ids    予約ID。
	 * @param bool  $use_created_at 日時未設定のため作成日時を使うか。
	 * @return string 最大10件の一覧。
	 */
	private function format_booking_list( array $booking_ids, bool $use_created_at ): string {
		$labels = array();
		foreach ( array_slice( $booking_ids, 0, self::LIST_LIMIT ) as $booking_id ) {
			$post = get_post( $booking_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$datetime   = $use_created_at ? new DateTimeImmutable( $post->post_date, wp_timezone() ) : $this->get_start_datetime( $booking_id );
			$date_label = $datetime ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $datetime->getTimestamp(), wp_timezone() ) : '';
			if ( $use_created_at ) {
				/* translators: %s: Reservation creation date and time. */
				$date_label = sprintf( __( '%s created', 'vk-booking-manager' ), $date_label );
			}
			$service_id    = (int) get_post_meta( $booking_id, self::META_SERVICE_ID, true );
			$service_title = $service_id > 0 ? get_the_title( $service_id ) : '';
			if ( '' === $service_title ) {
				$service_title = __( 'Menu not set', 'vk-booking-manager' );
			}
			$labels[] = sprintf(
				/* translators: 1: Reservation date and time, 2: Service menu name. */
				_x( '%1$s (%2$s)', 'Reservation list item: date and time (service menu name)', 'vk-booking-manager' ),
				$date_label,
				$service_title
			);
		}

		$remaining = count( $booking_ids ) - self::LIST_LIMIT;
		if ( $remaining > 0 ) {
			/* translators: %s: Number of additional reservations omitted from the list. */
			$labels[] = sprintf( __( '%s more', 'vk-booking-manager' ), number_format_i18n( $remaining ) );
		}

		return implode( wp_get_list_item_separator(), $labels );
	}

	/**
	 * 予約の開始日時を返す。
	 *
	 * @param int $booking_id 予約ID。
	 * @return DateTimeImmutable|null 開始日時。読めなければ null。
	 */
	private function get_start_datetime( int $booking_id ): ?DateTimeImmutable {
		try {
			$value = (string) get_post_meta( $booking_id, self::META_SERVICE_START, true );
			return '' === $value ? null : new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * 同じメールアドレスの会員がいるか判定する。
	 *
	 * @param string $email 正規化済みメールアドレス。
	 * @return bool 会員がいれば true。
	 */
	private function member_exists( string $email ): bool {
		return false !== get_user_by( 'email', $email );
	}

	/**
	 * 処理対象が無い場合の結果を返す。
	 *
	 * @return array{items_removed: false, items_retained: false, messages: array<never>, done: true} 空の結果。
	 */
	private function empty_result(): array {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
