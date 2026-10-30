<?php
/**
 * WordPress 本体の個人データ消去依頼から、ゲストに付けた顧客フラグを消す処理。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Booking_Post_Type;
use function __;
use function add_filter;
use function is_email;

/**
 * 消去依頼のメールアドレスと、そのメールアドレスの予約に入っている電話番号に付いた、ゲストの顧客フラグを消す。
 *
 * 予約ごとの匿名化（一括・自動）ではフラグを消さない。本人から消去を求められたときだけ消す。
 * 予約の匿名化で電話番号が消えると、電話番号に付いたフラグをたどれなくなるため、
 * 予約の消去処理（優先度 10）より先に動かす（優先度 9）。
 */
class Customer_Flag_Privacy_Eraser {
	/**
	 * 消去処理を登録するときの優先度。予約の消去処理より小さくして、先に動かす。
	 */
	public const FILTER_PRIORITY = 9;

	/**
	 * 予約のお客様メールアドレスを保存する post meta のキー。
	 */
	private const META_CUSTOMER_EMAIL = '_vkbm_booking_customer_email';

	/**
	 * 予約のお客様電話番号を保存する post meta のキー。
	 */
	private const META_CUSTOMER_TEL = '_vkbm_booking_customer_tel';

	/**
	 * 顧客フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * コンストラクタ。
	 *
	 * @param Customer_Flag_Service|null $service 顧客フラグサービス。未指定なら既定実装を生成する。
	 */
	public function __construct( ?Customer_Flag_Service $service = null ) {
		$this->service = $service ?? new Customer_Flag_Service();
	}

	/**
	 * WordPress 本体の個人データ消去処理へ登録する。
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ), self::FILTER_PRIORITY );
	}

	/**
	 * 顧客フラグの消去処理を一覧へ追加する。
	 *
	 * @param array<string, array<string, mixed>> $erasers 登録済みの消去処理。
	 * @return array<string, array<string, mixed>> 顧客フラグの消去処理を追加した一覧。
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['vk-booking-manager-customer-flags'] = array(
			'eraser_friendly_name' => __( 'VK Booking Manager customer flags for guests', 'vk-booking-manager' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * メールアドレスと、そのメールアドレスの予約の電話番号に付いたゲストのフラグを消す。
	 *
	 * 状態を持たず 1 回で終える。電話番号は、まだ匿名化されていない予約（メールアドレスが残っている予約）から集める。
	 *
	 * @param string $email_address 消去依頼のメールアドレス。
	 * @param int    $page          WordPress 本体から渡されるページ番号（1 回で終えるため使用しない）。
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} 消去結果。
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WordPress 本体の eraser コールバック仕様に合わせる。
		$email = VKBM_Helper::normalize_email( $email_address );
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->build_result( 0 );
		}

		$removed = $this->service->erase_guest_flags( $email, $this->find_booking_phones( $email ) );

		// 記録の書き換えの排他を取れず消せたか分からないときは、完了扱いにせず再実行を促す。
		if ( null === $removed ) {
			return $this->build_retry_result();
		}

		return $this->build_result( $removed );
	}

	/**
	 * 指定メールアドレスの、まだ匿名化されていない予約に入っている電話番号を返す。
	 *
	 * @param string $email そろえたメールアドレス。
	 * @return string[] 電話番号（予約に入っている値のまま、重複なし）。
	 */
	private function find_booking_phones( string $email ): array {
		global $wpdb;

		// 照合順序に依存しないよう SQL 側で前後空白と大小文字をそろえ、メールアドレスを完全一致させる。
		$phones = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 個人データ消去の都度、照合順序に依存しない完全一致が必要。
			$wpdb->prepare(
				"SELECT DISTINCT tel.meta_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} mail ON mail.post_id = p.ID
				INNER JOIN {$wpdb->postmeta} tel ON tel.post_id = p.ID
				WHERE p.post_type = %s
					AND mail.meta_key = %s
					AND LOWER(TRIM(mail.meta_value)) = %s
					AND tel.meta_key = %s
					AND tel.meta_value <> ''",
				Booking_Post_Type::POST_TYPE,
				self::META_CUSTOMER_EMAIL,
				$email,
				self::META_CUSTOMER_TEL
			)
		);

		return array_map( 'strval', $phones );
	}

	/**
	 * 消去できなかった（時間を置いて再実行が必要な）ときの結果を組み立てる。
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} 消去結果。
	 */
	private function build_retry_result(): array {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array(
				Nomination_Min_Guests_Message::join_sentences(
					__( 'VK Booking Manager: The customer flags set for this email address could not be removed.', 'vk-booking-manager' ),
					__( 'Please wait a moment and run the erasure again.', 'vk-booking-manager' )
				),
			),
			'done'           => true,
		);
	}

	/**
	 * 消去結果を組み立てる。
	 *
	 * @param int $removed 消したフラグの件数。
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} 消去結果。
	 */
	private function build_result( int $removed ): array {
		$messages = array();
		if ( $removed > 0 ) {
			$messages[] = __( 'VK Booking Manager: The customer flags set for this email address and its telephone numbers were removed.', 'vk-booking-manager' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
