<?php
/**
 * 顧客フラグに基づく予約ブロックの連携処理。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use function __;
use function add_filter;
use function is_wp_error;

/**
 * 予約確定処理へ顧客フラグによるブロック判定を差し込む。
 *
 * 予約確定コントローラーが公開するフィルターフックを利用することで、
 * コントローラーへ直接依存させず疎結合に連携する。
 *
 * フラグの内容（種別名・メモ）はメールには載せない。メールは転送・引用などで顧客や第三者の目に触れうるため、
 * フラグは管理画面の表示だけに留める。
 */
class Customer_Flag_Booking_Guard {
	/**
	 * ゲスト予約を止めるときに返す、フラグを特定させない汎用のエラーコード。
	 */
	public const GUEST_BLOCKED_CODE = 'vkbm_booking_not_accepted';

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
	 * フックを登録する。
	 */
	public function register(): void {
		// フロント予約の確定直前にブロック判定を差し込む。
		add_filter( 'vkbm_booking_block_check', array( $this, 'block_flagged_customer' ), 10, 3 );
	}

	/**
	 * ブロック対象の顧客であれば WP_Error を返し、予約確定を中止させる。
	 *
	 * 会員の予約はユーザー ID で判定し、加えて会員の今のメールアドレス・電話番号で、ゲストに付けたフラグとも照合する。
	 * ゲスト予約（ユーザー ID が 0）は、第3引数の連絡先で会員のフラグと、ゲストに付けたフラグの両方と照合する
	 * （メールアドレスか電話番号のどちらかが、ブロック対象の相手と一致したら止める）。
	 *
	 * @param mixed                $result  既存の判定結果（他のフィルターが設定した WP_Error など）。
	 * @param int                  $user_id 予約者のユーザー ID。ゲスト予約は 0。
	 * @param array<string,string> $contact 予約者の連絡先（email・phone をそろえた値）。会員の予約では空配列でよい。
	 * @return mixed ブロック時は WP_Error（ゲスト予約は汎用の文言とコード、会員は種別のメッセージ）。それ以外は受け取った $result をそのまま返す。
	 */
	public function block_flagged_customer( $result, int $user_id, $contact = array() ) {
		// 既に他のフィルターがブロックを決定している場合はそれを尊重する。
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message  = null;
		$is_guest = $user_id <= 0;
		if ( $user_id > 0 ) {
			// 会員本人に付いたフラグ、次に会員の連絡先と一致するゲストのフラグの順に判定する。
			if ( $this->service->is_blocked( $user_id ) ) {
				$message = $this->service->get_block_message( $user_id );
			} else {
				$guest_flag = $this->service->find_blocking_guest_flag_for_user( $user_id );
				$message    = null !== $guest_flag ? $this->service->get_guest_block_message( $guest_flag ) : null;
			}
		} elseif ( is_array( $contact ) ) {
			$email = (string) ( $contact['email'] ?? '' );
			$phone = (string) ( $contact['phone'] ?? '' );

			// ブロック対象の会員との照合、次にゲストに付けたフラグとの照合の順に判定する。
			if ( $this->service->find_blocked_user_id_by_contact( $email, $phone ) > 0 || null !== $this->service->find_blocking_guest_flag( $email, $phone ) ) {
				$message = $this->service->get_default_block_message();
			}
		}

		if ( null === $message ) {
			return $result;
		}

		// ゲスト予約には、種別のメッセージやフラグ専用のエラーコードを返さない（フラグの有無を本人に悟らせないため。結果データにも残さない）。
		return new WP_Error(
			$is_guest ? self::GUEST_BLOCKED_CODE : 'vkbm_customer_flag_blocked',
			$is_guest ? $this->service->get_default_block_message() : $message,
			array( 'status' => 403 )
		);
	}
}
