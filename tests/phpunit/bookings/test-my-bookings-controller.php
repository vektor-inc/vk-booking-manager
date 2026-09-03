<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_REST_Request;
use WP_UnitTestCase;
use function update_post_meta;
use function wp_date;
use function wp_set_current_user;

/**
 * マイ予約 REST コントローラのテスト（過去予約・is_past フラグ）。
 *
 * @group bookings
 */
class My_Bookings_Controller_Test extends WP_UnitTestCase {

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * future_only=false で過去・今後の予約が両方返り、is_past が正しく付与されることを検証する。
	 */
	public function test_returns_past_and_future_with_is_past_flag(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// 過去（2日前）と未来（2日後）の予約を作成する。
		$past_id   = $this->create_booking_post( $user_id, '-2 days' );
		$future_id = $this->create_booking_post( $user_id, '+2 days' );

		$controller = new My_Bookings_Controller();

		// future_only=false で全件取得する。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' );
		$request->set_param( 'future_only', false );

		$items = $controller->get_bookings( $request )->get_data();
		$this->assertCount( 2, $items );

		// IDごとに is_past を検証する。
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$this->assertArrayHasKey( $past_id, $by_id );
		$this->assertArrayHasKey( $future_id, $by_id );
		$this->assertTrue( $by_id[ $past_id ]['is_past'] );
		$this->assertFalse( $by_id[ $future_id ]['is_past'] );
	}

	/**
	 * future_only=true（既定）では過去の予約が除外されることを検証する。
	 */
	public function test_future_only_excludes_past_bookings(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$this->create_booking_post( $user_id, '-2 days' );
		$future_id = $this->create_booking_post( $user_id, '+2 days' );

		$controller = new My_Bookings_Controller();

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' );
		$request->set_param( 'future_only', true );

		$items = $controller->get_bookings( $request )->get_data();
		$this->assertCount( 1, $items );
		$this->assertSame( $future_id, (int) $items[0]['id'] );
		$this->assertFalse( $items[0]['is_past'] );
	}

	/**
	 * テスト用の予約投稿を作成する。
	 *
	 * @param int    $author_id     予約者のユーザーID。
	 * @param string $relative_time 現在からの相対時刻（例: '-2 days'）。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $author_id, string $relative_time ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		$start_ts = strtotime( $relative_time );
		$end_ts   = $start_ts + ( 30 * 60 );

		update_post_meta( $booking_id, '_vkbm_booking_service_start', wp_date( 'Y-m-d H:i:s', $start_ts ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', $end_ts ) );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}
}
