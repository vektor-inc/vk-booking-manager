<?php
/**
 * 通知を抑止するテストダブル（1ツアーあたりのテスト用）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * 通知を抑止するテストダブル。
 */
class Per_Booking_Notification_Test_Double extends Booking_Notification_Service {
	/**
	 * 設定リポジトリを渡して親を初期化する。
	 */
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	/**
	 * 確定通知を送らない。
	 *
	 * @param int $booking_id 予約 ID。
	 */
	public function handle_confirmed_creation( int $booking_id ): void {
		// テストでは何もしない。
	}

	/**
	 * 仮予約通知を送らない。
	 *
	 * @param int $booking_id 予約 ID。
	 */
	public function handle_pending_creation( int $booking_id ): void {
		// テストでは何もしない。
	}
}
