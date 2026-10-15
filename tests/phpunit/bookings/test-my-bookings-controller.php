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
	 * 予約件数・メニュー件数を増やしても、予約一覧取得の DB クエリ数が比例して増えないことを検証する（#708）。
	 * あわせて、レスポンス内容（メニュー名・その他条件・基本料金のフォールバック）が変わらないことも確認する。
	 */
	public function test_get_bookings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約3件・メニュー3件でもクエリ数は予約1件・メニュー1件から一定数以内の増加に収まる',
				'conditions'          => array( 'booking_count' => 3 ),
			),
			array(
				'test_condition_name' => '予約10件・メニュー10件でもクエリ数は予約1件・メニュー1件から一定数以内の増加に収まる',
				'conditions'          => array( 'booking_count' => 10 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$base_queries = $this->measure_get_bookings_queries( 1 )['queries'];
			$result       = $this->measure_get_bookings_queries( $case['conditions']['booking_count'] );

			// 件数に比例して増えない（定数の許容幅内）こと。
			$this->assertLessThanOrEqual( $base_queries + 3, $result['queries'], $case['test_condition_name'] . '（1件: ' . $base_queries . ' / 実測: ' . $result['queries'] . '）' );

			// レスポンス内容が変わらないこと。
			$this->assertCount( $case['conditions']['booking_count'], $result['items'], $case['test_condition_name'] );
			foreach ( $result['items'] as $item ) {
				$this->assertSame( 'Menu ' . $item['menu_id'], $item['menu_name'], $case['test_condition_name'] );
				$this->assertSame( 'cond ' . $item['menu_id'], $item['other_conditions'], $case['test_condition_name'] );
				// 予約側に基本料金スナップショットが無いため、メニューの _vkbm_base_price にフォールバックする。
				$this->assertSame( 1000 + $item['menu_id'], $item['base_price'], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 予約・メニューを作成して get_bookings() を1回呼び、実行された DB クエリ数を返す。
	 *
	 * @param int $count 作成する予約件数（メニューも同数を作成する）。
	 * @return array{queries:int,items:array<int,array<string,mixed>>} クエリ数とレスポンスの items。
	 */
	private function measure_get_bookings_queries( int $count ): array {
		global $wpdb;

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		for ( $i = 0; $i < $count; $i++ ) {
			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => 'vkbm_service_menu',
					'post_status' => 'publish',
				)
			);
			wp_update_post(
				array(
					'ID'         => $menu_id,
					'post_title' => 'Menu ' . $menu_id,
				)
			);
			update_post_meta( $menu_id, '_vkbm_other_conditions', 'cond ' . $menu_id );
			update_post_meta( $menu_id, '_vkbm_base_price', 1000 + $menu_id );

			$booking_id = $this->create_booking_post( $user_id, '+' . ( $i + 1 ) . ' days' );
			update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		}

		// 作成時に温まったキャッシュを破棄し、リクエスト開始直後の状態にそろえる。
		wp_cache_flush();

		$controller = new My_Bookings_Controller();
		$request    = new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' );

		$before = $wpdb->num_queries;
		$items  = $controller->get_bookings( $request )->get_data();
		$after  = $wpdb->num_queries;

		$queries = $after - $before;
		return array(
			'queries' => $queries,
			'items'   => $items,
		);
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
