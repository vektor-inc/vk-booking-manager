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
		add_filter( 'vkbm_booking_block_check', array( $this, 'block_flagged_customer' ), 10, 2 );
	}

	/**
	 * ブロック対象の顧客であれば WP_Error を返し、予約確定を中止させる。
	 *
	 * @param mixed $result  既存の判定結果（他のフィルターが設定した WP_Error など）。
	 * @param int   $user_id 予約者のユーザー ID。
	 * @return mixed ブロック時は WP_Error。それ以外は受け取った $result をそのまま返す。
	 */
	public function block_flagged_customer( $result, int $user_id ) {
		// 既に他のフィルターがブロックを決定している場合はそれを尊重する。
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $this->service->is_blocked( $user_id ) ) {
			return $result;
		}

		return new WP_Error(
			'vkbm_customer_flag_blocked',
			$this->service->get_block_message( $user_id ),
			array( 'status' => 403 )
		);
	}
}
