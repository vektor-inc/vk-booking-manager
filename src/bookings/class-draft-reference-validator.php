<?php
/**
 * 予約の下書きに含まれる、予約メニューと担当の ID の検証。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Availability\Availability_Service;
use WP_Error;
use function __;

/**
 * 予約メニュー・担当の ID が、予約できる投稿を指していることを確かめるクラス。
 *
 * 判定の基準は Availability_Service（空き状況の確認）に集約している。
 * ここでは、拒否するときのエラーの形（コード・文言）だけを1か所にまとめる。
 */
class Draft_Reference_Validator {

	/**
	 * 予約メニューの ID が、予約できる予約メニューを指しているか確かめる。
	 *
	 * @param Availability_Service $availability_service 空き状況の確認サービス。
	 * @param int                  $menu_id              予約メニューの投稿 ID。
	 * @return WP_Error|null 該当しなければ WP_Error。問題なければ null。
	 */
	public static function validate_menu( Availability_Service $availability_service, int $menu_id ): ?WP_Error {
		if ( null !== $availability_service->find_bookable_menu_post( $menu_id ) ) {
			return null;
		}

		return new WP_Error( 'invalid_menu_id', __( 'Menu ID is invalid.', 'vk-booking-manager' ), array( 'status' => 400 ) );
	}

	/**
	 * 担当（スタッフ・客室）の ID が、そのメニューで担当できる公開中のリソースを指しているか確かめる。
	 *
	 * おまかせ（0）は確かめない。
	 *
	 * @param Availability_Service $availability_service 空き状況の確認サービス。
	 * @param int                  $menu_id              予約メニューの投稿 ID。
	 * @param int                  $resource_id          リソースの投稿 ID（0 はおまかせ）。
	 * @return WP_Error|null 該当しなければ WP_Error。問題なければ null。
	 */
	public static function validate_resource( Availability_Service $availability_service, int $menu_id, int $resource_id ): ?WP_Error {
		if ( $resource_id <= 0 || $availability_service->is_resource_assignable_to_menu( $menu_id, $resource_id ) ) {
			return null;
		}

		return new WP_Error( 'invalid_resource', __( 'The selected staff member cannot be assigned to this service menu.', 'vk-booking-manager' ), array( 'status' => 400 ) );
	}

	/**
	 * 保存済みの下書きの予約メニューと担当を、まとめて確かめる。
	 *
	 * @param Availability_Service $availability_service 空き状況の確認サービス。
	 * @param array<string, mixed> $draft                予約の下書き。
	 * @return WP_Error|null 問題があれば WP_Error。問題なければ null。
	 */
	public static function validate_draft( Availability_Service $availability_service, array $draft ): ?WP_Error {
		$menu_id     = isset( $draft['menu_id'] ) ? (int) $draft['menu_id'] : 0;
		$resource_id = isset( $draft['resource_id'] ) ? (int) $draft['resource_id'] : 0;

		$menu_error = self::validate_menu( $availability_service, $menu_id );
		if ( null !== $menu_error ) {
			return $menu_error;
		}

		return self::validate_resource( $availability_service, $menu_id, $resource_id );
	}
}
