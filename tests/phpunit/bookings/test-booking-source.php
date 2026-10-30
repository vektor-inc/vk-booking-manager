<?php
/**
 * 予約の出どころを示す印（Booking_Source）の定数（#958）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Source;
use WP_UnitTestCase;

/**
 * 印のメタキーが両版共通の Booking_Source にあり、既存のキー名から変わっていないことを検証する。
 *
 * @group bookings
 */
class Booking_Source_Test extends WP_UnitTestCase {

	/**
	 * Booking_Source::META_KEY が、保存済みの予約の印と同じキー名であることを検証する。
	 */
	public function test_meta_key(): void {
		$this->assertSame( '_vkbm_booking_source', Booking_Source::META_KEY, '既に保存されている予約の印と同じキー名' );
	}
}
