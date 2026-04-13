<?php
/**
 * Integration test for the "+N" badge rendering when hidden_count >= 1.
 * hidden_count >= 1 の場合の「+N」バッジ描画の統合テスト。
 *
 * Verifies that Shift_Dashboard_Page::render_page() outputs the correct
 * "+N" badge, aria-label, and modal markup when 4 or more bookings overlap
 * in the same time slot (max_visible = 3).
 * 同一時間帯に4件以上の予約が重なった場合（max_visible = 3）、
 * render_page() が正しい「+N」バッジ・aria-label・モーダルを出力することを検証する。
 *
 * @package VKBookingManager
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/issues/188
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Shift_Dashboard_Page;
use WP_UnitTestCase;

/**
 * Integration tests for hidden_count badge rendering on the shift dashboard.
 * シフト・予約表画面の hidden_count バッジ描画に関する統合テスト。
 *
 * @group admin
 */
class Shift_Dashboard_Hidden_Count_Rendering_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 * 管理者ユーザーID。
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Set up an administrator user and prepare the environment before each test.
	 * 各テスト前に管理者ユーザーを作成し環境を整備する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create and set administrator user to satisfy capability check.
		// 管理者ユーザーを作成してセットし、権限チェックを通過させる。
		$this->admin_user_id = $this->factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Clean up user state after each test.
	 * 各テスト後にユーザー状態をクリーンアップする。
	 */
	protected function tearDown(): void {
		// Clear $_GET parameters set during tests.
		// テスト中に設定した $_GET パラメータをクリアする。
		unset( $_GET['vkbm_date'], $_GET['vkbm_view'] );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test render_page() outputs the correct "+N" badge and related markup
	 * depending on the number of overlapping bookings in a time slot.
	 * 同一時間帯の予約重複数に応じて render_page() が正しい「+N」バッジと
	 * 関連マークアップを出力することをテストする。
	 */
	public function test_render_page_with_hidden_count(): void {
		// Use a fixed date for reproducible results.
		// 再現性のある結果を得るために固定の日付を使用する。
		$test_date  = '2026-04-15';
		$test_year  = 2026;
		$test_month = 4;
		$test_day   = 15;

		$test_cases = array(
			array(
				'test_condition_name' => '4件の予約が同一時刻に重複する場合 => +1 バッジが出力に含まれる（境界値）',
				'booking_count'      => 4,
				'assertions'         => array(
					array(
						'type'   => 'contains',
						'needle' => 'vkbm-bookings__more-badge',
					),
					array(
						'type'   => 'contains',
						'needle' => '+1',
					),
				),
			),
			array(
				'test_condition_name' => '5件の予約が同一時刻に重複する場合 => +2 バッジが出力に含まれる',
				'booking_count'      => 5,
				'assertions'         => array(
					array(
						'type'   => 'contains',
						'needle' => 'vkbm-bookings__more-badge',
					),
					array(
						'type'   => 'contains',
						'needle' => '+2',
					),
				),
			),
			array(
				'test_condition_name' => '5件の予約が同一時刻に重複する場合 => more-badge の aria-label に件数2が含まれる',
				'booking_count'      => 5,
				'assertions'         => array(
					array(
						'type'   => 'regex',
						'needle' => '/vkbm-bookings__more-badge[^>]*aria-label="[^"]*2[^"]*"/',
					),
				),
			),
			array(
				'test_condition_name' => '5件の予約が同一時刻に重複する場合 => モーダル要素が出力に含まれる',
				'booking_count'      => 5,
				'assertions'         => array(
					array(
						'type'   => 'contains',
						'needle' => 'vkbm-booking-modal',
					),
				),
			),
			array(
				'test_condition_name' => '3件の予約が同一時刻に重複する場合 => +N バッジが出力に含まれない（hidden_count=0）',
				'booking_count'      => 3,
				'assertions'         => array(
					array(
						'type'   => 'not_contains',
						'needle' => 'vkbm-bookings__more-badge',
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_ids = array();

			// Create a resource post for this test case.
			// このテストケース用にリソース投稿を作成する。
			$resource_id = $this->factory()->post->create(
				array(
					'post_type'   => 'vkbm_resource',
					'post_status' => 'publish',
					'post_title'  => 'Test Resource',
				)
			);

			// Create a shift post with schedule data for the test date.
			// テスト日のスケジュールデータを含むシフト投稿を作成する。
			$shift_id = $this->factory()->post->create(
				array(
					'post_type'   => 'vkbm_shift',
					'post_status' => 'publish',
					'post_title'  => 'Test Shift',
				)
			);
			update_post_meta( $shift_id, '_vkbm_shift_resource_id', $resource_id );
			update_post_meta( $shift_id, '_vkbm_shift_year', $test_year );
			update_post_meta( $shift_id, '_vkbm_shift_month', $test_month );
			update_post_meta(
				$shift_id,
				'_vkbm_shift_days',
				array(
					$test_day => array(
						'status' => 'open',
						'slots'  => array(
							array(
								'start' => '09:00',
								'end'   => '18:00',
							),
						),
					),
				)
			);

			// Create overlapping booking posts for the same time slot (10:00-11:00).
			// 同一時間帯（10:00-11:00）に重複する予約投稿を作成する。
			for ( $i = 0; $i < $case['booking_count']; $i++ ) {
				$booking_id = $this->factory()->post->create(
					array(
						'post_type'   => 'vkbm_booking',
						'post_status' => 'publish',
						'post_title'  => 'Booking ' . ( $i + 1 ),
					)
				);
				update_post_meta( $booking_id, '_vkbm_booking_resource_id', $resource_id );
				update_post_meta( $booking_id, '_vkbm_booking_service_start', $test_date . ' 10:00:00' );
				update_post_meta( $booking_id, '_vkbm_booking_service_end', $test_date . ' 11:00:00' );
				update_post_meta( $booking_id, '_vkbm_booking_total_end', $test_date . ' 11:00:00' );
				update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'Customer ' . ( $i + 1 ) );
				update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
				$booking_ids[] = $booking_id;
			}

			// Set $_GET parameters to select the day view for the test date.
			// テスト日のデイビューを選択するために $_GET パラメータを設定する。
			$_GET['vkbm_date'] = $test_date;
			$_GET['vkbm_view'] = 'day';

			// Render the shift dashboard page and capture output.
			// シフト・予約表ページを描画し、出力をキャプチャする。
			$page   = new Shift_Dashboard_Page();
			$output = $this->capture_render_output(
				static function () use ( $page ): void {
					$page->render_page();
				}
			);

			// Run assertions for this test case.
			// このテストケースのアサーションを実行する。
			foreach ( $case['assertions'] as $assertion ) {
				if ( 'contains' === $assertion['type'] ) {
					$this->assertStringContainsString(
						$assertion['needle'],
						$output,
						$case['test_condition_name']
					);
				} elseif ( 'not_contains' === $assertion['type'] ) {
					$this->assertStringNotContainsString(
						$assertion['needle'],
						$output,
						$case['test_condition_name']
					);
				} elseif ( 'regex' === $assertion['type'] ) {
					$this->assertMatchesRegularExpression(
						$assertion['needle'],
						$output,
						$case['test_condition_name']
					);
				}
			}

			// Clean up posts created for this test case to avoid interference.
			// テストケース間の干渉を避けるため、作成した投稿をクリーンアップする。
			foreach ( $booking_ids as $bid ) {
				wp_delete_post( $bid, true );
			}
			wp_delete_post( $shift_id, true );
			wp_delete_post( $resource_id, true );
		}
	}

	/**
	 * Capture output from a rendering callback via output buffer.
	 * 出力バッファを使って描画コールバックの出力をキャプチャする。
	 *
	 * @param callable $callback Rendering callback. / 描画コールバック。
	 * @return string Captured HTML output. / キャプチャされたHTML出力。
	 */
	private function capture_render_output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
