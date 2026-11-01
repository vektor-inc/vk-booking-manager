<?php
/**
 * お客様1人あたりの「これから先の予約」の数の上限（#971）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Error;
use WP_Query;
use WP_User;
use function __;
use function _n;
use function absint;
use function apply_filters;
use function get_user_by;
use function sprintf;
use function wp_date;

/**
 * 上限の値の決定・件数の数え方・上限到達のエラーを1か所にまとめたクラス。
 *
 * 数える予約: 公開済みで、キャンセル・無断キャンセルでなく、開始日時が今より後のもの（仮予約を含む）。
 * 予約投稿1件を1件と数える（人数・泊数・部屋数は関係しない）。
 * 会員はユーザー ID（予約投稿の作成者）、ゲストは小文字にそろえたメールアドレスで数え、
 * そのメールアドレスを今持つ会員の予約も合算する（重複予約の確認と同じ照合）。電話番号は使わない。
 */
class Customer_Booking_Limit {
	/**
	 * 設定（vkbm_provider_settings）のキー。
	 */
	public const SETTINGS_KEY = 'provider_customer_booking_limit';

	/**
	 * 設定が無いときの上限。0 は上限なし。
	 */
	public const DEFAULT_LIMIT = 3;

	/**
	 * 上限に達したときの REST エラーコード。予約ブロックが汎用エラーと区別するために使う。
	 */
	public const ERROR_CODE = 'vkbm_booking_limit_reached';

	/**
	 * 予約者のメールアドレスを保存する予約メタ。Booking_Confirmation_Controller と同じ値。
	 */
	private const META_CUSTOMER_MAIL = '_vkbm_booking_customer_email';

	/**
	 * 設定とフィルターから、実際に使う上限を決める。
	 *
	 * @param array<string, mixed> $settings    vkbm_provider_settings の設定値。
	 * @param int                  $user_id     会員のユーザー ID（ゲストは 0）。
	 * @param string               $guest_email ゲストのそろえたメールアドレス（会員は空文字）。
	 * @return int 上限（件）。0 は上限なし。
	 */
	public static function resolve_limit( array $settings, int $user_id = 0, string $guest_email = '' ): int {
		$limit = isset( $settings[ self::SETTINGS_KEY ] ) ? absint( $settings[ self::SETTINGS_KEY ] ) : self::DEFAULT_LIMIT;

		/**
		 * お客様1人あたりの予約数の上限を差し替える。
		 *
		 * @param int    $limit       設定画面の値（0 は上限なし）。
		 * @param int    $user_id     会員のユーザー ID（ゲストは 0）。
		 * @param string $guest_email ゲストのそろえたメールアドレス（会員は空文字）。
		 */
		$limit = (int) apply_filters( 'vkbm_customer_booking_limit', $limit, $user_id, $guest_email );

		return max( 0, $limit );
	}

	/**
	 * 会員のこれから先の予約の数を数える。
	 *
	 * @param int $user_id 会員のユーザー ID。
	 * @return int 件数。
	 */
	public static function count_upcoming_for_member( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		return count( self::query_upcoming_ids( array( 'author' => $user_id ) ) );
	}

	/**
	 * ゲストのメールアドレスで、これから先の予約の数を数える（同じメールアドレスの会員の予約も含める）。
	 *
	 * 予約に保存したメールアドレスで探す。あわせて、そのメールアドレスを今持つ会員がいれば、その会員の予約も数える
	 * （会員が予約したあとにメールアドレスを変えた場合に備える）。両方に当たる予約は1件と数える。
	 *
	 * @param string $email そろえたメールアドレス（前後の空白を除いた小文字）。
	 * @return int 件数。
	 */
	public static function count_upcoming_for_guest( string $email ): int {
		if ( '' === $email ) {
			return 0;
		}

		$ids = self::query_upcoming_ids(
			array(
				'meta_key'   => self::META_CUSTOMER_MAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 予約者のメールアドレスで絞るために必要。
				'meta_value' => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
			)
		);

		$member = get_user_by( 'email', $email );
		if ( $member instanceof WP_User ) {
			$ids = array_merge( $ids, self::query_upcoming_ids( array( 'author' => (int) $member->ID ) ) );
		}

		return count( array_unique( $ids ) );
	}

	/**
	 * 件数が上限に達しているかを返す。
	 *
	 * @param int $limit 上限（0 は上限なし）。
	 * @param int $count 今の件数。
	 * @return bool 上限があり、件数がそれ以上なら true。
	 */
	public static function is_reached( int $limit, int $count ): bool {
		return $limit > 0 && $count >= $limit;
	}

	/**
	 * 上限に達したときのエラーを作る。
	 *
	 * @param int $limit 上限（件）。
	 * @return WP_Error 409 の vkbm_booking_limit_reached エラー。data に limit を持つ。
	 */
	public static function create_error( int $limit ): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			self::build_message( $limit ),
			array(
				'status' => 409,
				'limit'  => $limit,
			)
		);
	}

	/**
	 * 上限に達したことを伝える文言を作る。
	 *
	 * @param int $limit 上限（件）。
	 * @return string 文言。
	 */
	public static function build_message( int $limit ): string {
		return sprintf(
			/* translators: %d: Maximum number of upcoming reservations per customer. */
			_n( 'You can have up to %d reservation at the same time.', 'You can have up to %d reservations at the same time.', $limit, 'vk-booking-manager' ),
			$limit
		) . ' ' . __( 'Please apply again after your current reservations have ended or been cancelled.', 'vk-booking-manager' )
			. ' ' . __( 'You can cancel your reservations from Confirm reservation.', 'vk-booking-manager' );
	}

	/**
	 * これから先の予約の ID を探す。
	 *
	 * @param array<string, mixed> $scope 予約者で絞る WP_Query の引数（author または meta_key と meta_value）。
	 * @return array<int, int> 予約投稿 ID。
	 */
	private static function query_upcoming_ids( array $scope ): array {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'              => Booking_Post_Type::POST_TYPE,
					'post_status'            => array( 'publish' ),
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'fields'                 => 'ids',
					// 同時に送られた別リクエストの予約を数え漏らさないよう、検索結果をキャッシュさせない（#692 と同じ）。
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						'relation' => 'AND',
						// キャンセル・無断キャンセルは数えない。状態メタの無い古い予約は数える。
						array(
							'relation' => 'OR',
							array(
								'key'     => Booking_Overlap::META_STATUS,
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => Booking_Overlap::META_STATUS,
								'value'   => array( Booking_Overlap::STATUS_CANCELLED, Booking_Overlap::STATUS_NO_SHOW ),
								'compare' => 'NOT IN',
							),
						),
						// 来店済みを表す状態が無いため、開始日時が今より後のものだけを「これから先」とする。
						array(
							'key'     => Booking_Overlap::META_START,
							'value'   => wp_date( 'Y-m-d H:i:s' ),
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
				$scope
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}
}
