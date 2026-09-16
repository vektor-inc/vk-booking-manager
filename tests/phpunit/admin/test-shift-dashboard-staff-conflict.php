<?php
/**
 * シフト・予約表の担当スタッフ競合通知テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Common\Staff_Conflict_Detector;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 競合通知の期間抽出と表示を検証する。
 */
class Shift_Dashboard_Staff_Conflict_Test extends WP_UnitTestCase {
	/**
	 * 期間境界と、期間外から期間内まで続く予約の取得を検証する。
	 */
	public function test_get_bookings_intersecting_range(): void {
		$start    = '2026-09-15 00:00:00';
		$end      = '2026-10-15 23:59:59';
		$staff_id = $this->create_staff( '境界スタッフ' );
		$ids      = array(
			$this->create_booking( $staff_id, '2026-09-15 00:00', '2026-09-15 01:00', '境界開始' ),
			$this->create_booking( $staff_id, '2026-10-15 23:00:00', $end, '境界終了' ),
			$this->create_booking( $staff_id, '2026-09-14 23:00:00', '2026-09-15 00:30:00', '外から交差' ),
		);
		$this->create_booking( $staff_id, '2026-10-16 00:00:00', '2026-10-16 01:00:00', '期間外' );

		$bookings = Staff_Conflict_Detector::get_bookings_intersecting_range( $start, $end );
		$actual   = array_column( $bookings, 'id' );
		foreach ( $ids as $id ) {
			$this->assertContains( $id, $actual );
		}
		$this->assertCount( 3, $actual );
		$this->assertSame( '2026-09-15 00:00:00', $bookings[ array_search( $ids[0], $actual, true ) ]['start'] );
	}

	/**
	 * 配色・文言・上限・超過件数・カード値・空状態をまとめて検証する。
	 */
	public function test_render_pending_notifications_panel(): void {
		$page   = new Shift_Dashboard_Page();
		$method = new ReflectionMethod( $page, 'render_pending_notifications_panel' );
		$method->setAccessible( true );
		$item  = array(
			'time_label' => '2026年9月15日 10:00 - 11:00',
			'customer'   => '<script>alert(1)</script>',
			'staff'      => '和田スタッフ',
			'url'        => 'https://example.org/wp-admin/post.php?post=123&action=edit',
			'status'     => 'pending',
			'reason'     => Staff_Conflict_Detector::REASON_OVERLAP,
		);
		$items = array_fill( 0, 10, $item );

		$output_warning = $this->capture(
			fn() => $method->invoke(
				$page,
				array(),
				array(
					'items'         => $items,
					'total'         => 12,
					'allow_overlap' => true,
				)
			)
		);
		$this->assertStringContainsString( 'vkbm-alert__warning', $output_warning );
		$this->assertStringContainsString( 'role="status"', $output_warning );
		$this->assertStringContainsString( 'overlapping bookings are allowed', $output_warning );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output_warning );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output_warning );
		$this->assertSame( 10, substr_count( $output_warning, 'class="vkbm-notification-panel__item"' ) );
		$this->assertStringContainsString( 'There are 2 more.', $output_warning );
		foreach ( array( '2026年9月15日 10:00 - 11:00', '和田スタッフ', 'same time period', 'Pending', 'post=123', 'Detail' ) as $value ) {
			$this->assertStringContainsString( $value, $output_warning );
		}

		$output_danger = $this->capture(
			fn() => $method->invoke(
				$page,
				array(),
				array(
					'items'         => array( $item ),
					'total'         => 1,
					'allow_overlap' => false,
				)
			)
		);
		$this->assertStringContainsString( 'vkbm-alert__danger', $output_danger );
		$this->assertStringContainsString( 'role="alert"', $output_danger );
		$this->assertStringContainsString( 'reassign the staff', $output_danger );

		$output_empty = $this->capture(
			fn() => $method->invoke(
				$page,
				array(),
				array(
					'items'         => array(),
					'total'         => 0,
					'allow_overlap' => false,
				)
			)
		);
		$this->assertStringNotContainsString( 'Bookings the assigned staff cannot accommodate', $output_empty );
	}

	/**
	 * 実際の収集処理が開始日時順で10件に制限し、総件数を保持することを検証する。
	 */
	public function test_get_staff_conflict_notifications(): void {
		// 担当スタッフ競合通知は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより常に空になる。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '担当スタッフ競合通知は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$staff_id  = $this->create_staff( '収集対象スタッフ' );
		$date      = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$yesterday = ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );
		for ( $i = 0; $i < 11; $i++ ) {
			$this->create_booking( $staff_id, $date . ' 10:' . sprintf( '%02d', $i ), $date . ' 11:00', '顧客' . $i );
		}
		// キャンセル済み・担当未定・昨日開始・非公開は、比較対象にあっても通知一覧には出さない。
		$this->create_booking( $staff_id, $date . ' 10:00', $date . ' 11:00', 'キャンセル顧客', 'cancelled' );
		$this->create_booking( 0, $date . ' 10:00', $date . ' 11:00', '担当未定顧客' );
		$this->create_booking( $staff_id, $yesterday . ' 23:00', $date . ' 10:30', '昨日開始顧客' );
		$draft_staff_id = $this->create_staff( '非公開用スタッフ' );
		$this->create_booking( $draft_staff_id, $date . ' 12:00', $date . ' 13:00', '非公開顧客1', 'confirmed', 'pending' );
		$this->create_booking( $draft_staff_id, $date . ' 12:30', $date . ' 13:30', '非公開顧客2', 'confirmed', 'pending' );

		$settings                                       = ( new Settings_Repository() )->get_settings();
		$settings['provider_allow_staff_overlap_admin'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );

		$page   = new Shift_Dashboard_Page();
		$method = new ReflectionMethod( $page, 'get_staff_conflict_notifications' );
		$method->setAccessible( true );
		$result = $method->invoke( $page );

		$this->assertSame( 11, $result['total'] );
		$this->assertCount( 10, $result['items'] );
		$this->assertSame( '顧客0', $result['items'][0]['customer'] );
		$this->assertSame( '顧客9', $result['items'][9]['customer'] );
		$this->assertTrue( $result['allow_overlap'] );
		$this->assertSame( Staff_Conflict_Detector::REASON_OVERLAP, $result['items'][0]['reason'] );
		$this->assertNotContains( 'キャンセル顧客', array_column( $result['items'], 'customer' ) );
		$this->assertNotContains( '担当未定顧客', array_column( $result['items'], 'customer' ) );
		$this->assertNotContains( '昨日開始顧客', array_column( $result['items'], 'customer' ) );
		$this->assertNotContains( '非公開顧客1', array_column( $result['items'], 'customer' ) );
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * 30日後の深夜に翌日開始の予約が重なる場合と、削除済みスタッフの代替表示を検証する。
	 */
	public function test_get_staff_conflict_notifications_at_range_end(): void {
		// 担当スタッフ競合通知は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより常に空になる。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '担当スタッフ競合通知は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$staff_id = $this->create_staff( '削除予定スタッフ' );
		$day_30   = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+30 days' )->format( 'Y-m-d' );
		$day_31   = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+31 days' )->format( 'Y-m-d' );
		$this->create_booking( $staff_id, $day_30 . ' 23:30', $day_31 . ' 00:30', '30日後の顧客' );
		$this->create_booking( $staff_id, $day_31 . ' 00:00', $day_31 . ' 01:00', '31日後の顧客' );
		wp_delete_post( $staff_id, true );

		$page   = new Shift_Dashboard_Page();
		$method = new ReflectionMethod( $page, 'get_staff_conflict_notifications' );
		$method->setAccessible( true );
		$result = $method->invoke( $page );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( '30日後の顧客', $result['items'][0]['customer'] );
		$this->assertSame( __( 'Person in charge undecided', 'vk-booking-manager' ), $result['items'][0]['staff'] );
	}

	/**
	 * 予約件数が増えても一括取得の問い合わせ数が線形増加しないことを検証する。
	 */
	public function test_get_bookings_intersecting_range_query_count(): void {
		global $wpdb;
		$staff_id = $this->create_staff( '問い合わせ数スタッフ' );
		$start    = '2026-09-15 00:00:00';
		$end      = '2026-10-15 23:59:59';

		$this->create_booking( $staff_id, '2026-09-20 10:00:00', '2026-09-20 11:00:00', '予約1' );
		wp_cache_flush();
		$before_one = $wpdb->num_queries;
		Staff_Conflict_Detector::get_bookings_intersecting_range( $start, $end );
		$one_query_count = $wpdb->num_queries - $before_one;

		for ( $i = 2; $i <= 20; $i++ ) {
			$this->create_booking( $staff_id, '2026-09-20 10:00:00', '2026-09-20 11:00:00', '予約' . $i );
		}
		wp_cache_flush();
		$before_many = $wpdb->num_queries;
		Staff_Conflict_Detector::get_bookings_intersecting_range( $start, $end );
		$many_query_count = $wpdb->num_queries - $before_many;
		$this->assertLessThanOrEqual( $one_query_count + 2, $many_query_count );
	}

	/**
	 * スタッフ投稿を作成する。
	 *
	 * @param string $title スタッフ名。
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff( string $title ): int {
		return $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * 予約投稿を作成する。
	 *
	 * @param int    $staff_id スタッフID。
	 * @param string $start    開始日時。
	 * @param string $end      終了日時。
	 * @param string $customer 顧客名。
	 * @param string $status 予約ステータス。
	 * @param string $post_status WordPress の投稿ステータス。
	 * @return int 予約投稿ID。
	 */
	private function create_booking( int $staff_id, string $start, string $end, string $customer, string $status = 'confirmed', string $post_status = 'publish' ): int {
		$id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => $post_status,
			)
		);
		update_post_meta( $id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $id, '_vkbm_booking_service_start', $start );
		update_post_meta( $id, '_vkbm_booking_service_end', $end );
		update_post_meta( $id, '_vkbm_booking_total_end', $end );
		update_post_meta( $id, '_vkbm_booking_service_id', 1 );
		update_post_meta( $id, '_vkbm_booking_status', $status );
		update_post_meta( $id, '_vkbm_booking_guests', 1 );
		update_post_meta( $id, '_vkbm_booking_customer_name', $customer );
		return $id;
	}

	/**
	 * コールバックの出力を取得する。
	 *
	 * @param callable $callback 出力する処理。
	 * @return string 出力HTML。
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
