<?php
/**
 * 固定スロットを返す空き状況のテストダブル（1ツアーあたりのテスト用）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Availability\Availability_Service;

/**
 * get_daily_slots を固定スロットで返すテストダブル（空き再検証を通すため）。
 */
class Per_Booking_Availability_Test_Double extends Availability_Service {

	/**
	 * 返却するスロット。
	 *
	 * @var array<string, mixed>
	 */
	private array $slot;

	/**
	 * 受付下限の判定は空き枠の差し替え対象外のため、常に「下限内」とする。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param string $start_at 枠の開始日時。
	 * @return bool
	 */
	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		return false;
	}

	/**
	 * 返却するスロットを受け取る。
	 *
	 * @param array<string, mixed> $slot 返却するスロット。
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	/**
	 * 固定スロットを返す。
	 *
	 * @param array<string, mixed> $args 検索条件。
	 * @return array<string, mixed>
	 */
	public function get_daily_slots( array $args ) {
		return array( 'slots' => array( $this->slot ) );
	}
}
