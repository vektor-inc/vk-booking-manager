<?php
/**
 * シフト・予約表の「確定」ボタン（Ajax）に対する、担当未割当・競合のガードのテスト（#652）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Common\Staff_Conflict_Detector;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;
use WPDieException;

/**
 * ajax_confirm_booking() が、編集画面と同じガードを通ることを検証する。
 */
class Shift_Dashboard_Confirm_Guard_Test extends WP_UnitTestCase {
	/**
	 * 通知サービスのテストダブル（ステータス変更通知の呼び出し回数を数える）。
	 *
	 * @var Confirm_Guard_Notification_Double
	 */
	private $notification;

	/**
	 * 各テストの前提を整える。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->notification = new Confirm_Guard_Notification_Double( new Settings_Repository() );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * 各テストの後始末をする。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		unset( $_REQUEST['nonce'], $_POST['booking_id'] );
		parent::tear_down();
	}

	/**
	 * 担当未割当の予約は 400 のエラー（code: staff_required）で拒否され、ステータスも通知も変わらない。
	 */
	public function test_confirm_rejects_booking_without_staff(): void {
		$booking_id = $this->create_booking( 0, 'pending' );

		$response = $this->call_confirm( $booking_id );

		$this->assertSame( 'staff_required', $response['data']['code'] );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'was not confirmed', (string) $response['data']['message'] );
		$this->assertStringNotContainsString( 'not saved', (string) $response['data']['message'] );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( 0, $this->notification->status_transition_calls );
	}

	/**
	 * 競合があり、管理画面での重複を許可していない場合は 409 のエラー（code: staff_conflict）で拒否される。
	 *
	 * HTTP ステータス自体は CLI の PHPUnit では出力済みのため観測できず、code で識別する。
	 */
	public function test_confirm_rejects_conflicting_booking_when_overlap_not_allowed(): void {
		$staff_id = $this->create_staff();
		$this->create_booking( $staff_id, 'confirmed' );
		$booking_id = $this->create_booking( $staff_id, 'pending' );
		$this->set_allow_overlap_admin( false );

		$response = $this->call_confirm( $booking_id );

		$this->assertSame( 'staff_conflict', $response['data']['code'] );
		$this->assertStringContainsString( 'cannot be confirmed', (string) $response['data']['message'] );
		$this->assertStringContainsString( 'reservation details', (string) $response['data']['message'] );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( 0, $this->notification->status_transition_calls );
	}

	/**
	 * バッファ込みの終了（total_end）が service_end より遅く、その後半だけが別予約と重なる場合も競合として拒否される。
	 */
	public function test_confirm_rejects_booking_overlapping_only_in_buffer_period(): void {
		$staff_id = $this->create_staff();
		$other_id = $this->create_booking( $staff_id, 'confirmed' );
		update_post_meta( $other_id, '_vkbm_booking_service_start', '2030-01-10 11:10:00' );
		update_post_meta( $other_id, '_vkbm_booking_service_end', '2030-01-10 12:00:00' );
		update_post_meta( $other_id, '_vkbm_booking_total_end', '2030-01-10 12:00:00' );
		$booking_id = $this->create_booking( $staff_id, 'pending' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', '2030-01-10 11:30:00' );
		$this->set_allow_overlap_admin( false );

		$response = $this->call_confirm( $booking_id );

		$this->assertSame( 'staff_conflict', $response['data']['code'] );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( 0, $this->notification->status_transition_calls );
	}

	/**
	 * 競合があっても、管理画面での重複を許可していれば確定できる。
	 */
	public function test_confirm_allows_conflicting_booking_when_overlap_allowed(): void {
		$staff_id = $this->create_staff();
		$this->create_booking( $staff_id, 'confirmed' );
		$booking_id = $this->create_booking( $staff_id, 'pending' );
		$this->set_allow_overlap_admin( true );

		$response = $this->call_confirm( $booking_id );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( 1, $this->notification->status_transition_calls );
	}

	/**
	 * 担当がいて競合も無い予約は、従来どおり確定できる。
	 */
	public function test_confirm_allows_booking_with_staff_and_no_conflict(): void {
		$booking_id = $this->create_booking( $this->create_staff(), 'pending' );

		$response = $this->call_confirm( $booking_id );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( 1, $this->notification->status_transition_calls );
	}

	/**
	 * 共通の判定関数が、チェック対象外のステータス（キャンセル等）と担当未割当を正しく区別する。
	 */
	public function test_staff_unassigned_helper(): void {
		$this->assertTrue( Staff_Conflict_Detector::is_staff_unassigned( 'confirmed', 0 ) );
		$this->assertTrue( Staff_Conflict_Detector::is_staff_unassigned( 'pending', 0 ) );
		$this->assertFalse( Staff_Conflict_Detector::is_staff_unassigned( 'confirmed', 5 ) );
		$this->assertFalse( Staff_Conflict_Detector::is_staff_unassigned( 'cancelled', 0 ) );
		$this->assertFalse( Staff_Conflict_Detector::is_staff_unassigned( 'no_show', 0 ) );
	}

	/**
	 * お知らせパネルは、担当未定の予約に「確定」ボタンを出さず、割り当てを促して編集画面へ誘導する。
	 */
	public function test_pending_panel_hides_confirm_button_when_staff_is_missing(): void {
		$page   = new Shift_Dashboard_Page();
		$method = new ReflectionMethod( $page, 'render_pending_notifications_panel' );
		$method->setAccessible( true );
		$base = array(
			'time_label' => '2026年9月15日 10:00 - 11:00',
			'customer'   => '顧客',
			'staff'      => 'x',
			'url'        => 'https://example.org/wp-admin/post.php?post=123&action=edit',
		);

		ob_start();
		$method->invoke(
			$page,
			array(
				array_merge(
					$base,
					array(
						'id'          => '123',
						'needs_staff' => true,
					)
				),
				array_merge(
					$base,
					array(
						'id'          => '124',
						'needs_staff' => false,
					)
				),
			),
			array(
				'items'         => array(),
				'total'         => 0,
				'allow_overlap' => false,
			)
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-booking-id="124"', $output );
		$this->assertStringNotContainsString( 'data-booking-id="123"', $output );
		$this->assertSame( 1, substr_count( $output, 'js-vkbm-notification-confirm' ) );
		$this->assertStringContainsString( 'Assign ', $output );
		$this->assertStringContainsString( 'post=123', $output );
		// 担当未定の行は行き先を割り当てリンク1つにまとめるため、「詳細」は担当ありの行の1つだけ。
		$this->assertSame( 1, substr_count( $output, 'Detail' ) );
	}

	/**
	 * 担当未定で編集 URL が空の行でも、編集画面へのフォールバックリンクが出る。
	 */
	public function test_pending_panel_falls_back_to_edit_url_when_url_is_empty(): void {
		$page   = new Shift_Dashboard_Page();
		$method = new ReflectionMethod( $page, 'render_pending_notifications_panel' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			$page,
			array(
				array(
					'id'          => '321',
					'time_label'  => '2026年9月15日 10:00 - 11:00',
					'customer'    => '顧客',
					'staff'       => 'x',
					'url'         => '',
					'needs_staff' => true,
				),
			),
			array(
				'items'         => array(),
				'total'         => 0,
				'allow_overlap' => false,
			)
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'post=321', $output );
		$this->assertStringNotContainsString( 'js-vkbm-notification-confirm', $output );
	}

	/**
	 * 担当スタッフ投稿を作成する。
	 *
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => 'ガードテスト担当',
			)
		);
	}

	/**
	 * 同じ時間帯の予約投稿を作成する。
	 *
	 * @param int    $staff_id 担当スタッフID（0 なら担当未定）。
	 * @param string $status   予約ステータス。
	 * @return int 予約投稿ID。
	 */
	private function create_booking( int $staff_id, string $status ): int {
		$id = (int) $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, '_vkbm_booking_service_start', '2030-01-10 10:00:00' );
		update_post_meta( $id, '_vkbm_booking_service_end', '2030-01-10 11:00:00' );
		update_post_meta( $id, '_vkbm_booking_total_end', '2030-01-10 11:00:00' );
		update_post_meta( $id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $id, '_vkbm_booking_service_id', 1 );
		update_post_meta( $id, '_vkbm_booking_status', $status );
		update_post_meta( $id, '_vkbm_booking_guests', 1 );
		return $id;
	}

	/**
	 * 管理画面でのスタッフ重複の許可設定を保存する。
	 *
	 * @param bool $allow 許可するなら true。
	 */
	private function set_allow_overlap_admin( bool $allow ): void {
		$settings                                       = ( new Settings_Repository() )->get_settings();
		$settings['provider_allow_staff_overlap_admin'] = $allow;
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * ajax_confirm_booking() を呼び、JSON レスポンスを取得する。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return array<string, mixed> デコード済みの JSON レスポンス。
	 */
	private function call_confirm( int $booking_id ): array {
		$page                = new Shift_Dashboard_Page( 'manage_options', $this->notification );
		$_REQUEST['nonce']   = wp_create_nonce( 'vkbm_confirm_booking' );
		$_POST['booking_id'] = $booking_id;

		// wp_send_json() の die; を捕捉可能な例外へ置き換える。
		$die_handler = static function () {
			return static function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? (string) $message : '0' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- テスト用の終了ハンドラ。
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		$level = ob_get_level();
		ob_start();
		try {
			$page->ajax_confirm_booking();
		} catch ( WPDieException $e ) {
			// wp_send_json() の終了を想定どおり握りつぶす。
			unset( $e );
		} finally {
			while ( ob_get_level() > $level + 1 ) {
				ob_end_clean();
			}
			$body = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- テストダブルは使用箇所と同じファイルに置く。
/**
 * ステータス変更通知の呼び出し回数だけを数える通知サービスのダブル（メールは送らない）。
 */
class Confirm_Guard_Notification_Double extends Booking_Notification_Service {
	/**
	 * handle_status_transition() の呼び出し回数。
	 *
	 * @var int
	 */
	public int $status_transition_calls = 0;

	/**
	 * 呼び出し回数を数える。
	 *
	 * @param int    $booking_id      予約ID。
	 * @param string $previous_status 変更前ステータス。
	 * @param string $status          変更後ステータス。
	 */
	public function handle_status_transition( int $booking_id, string $previous_status, string $status ): void {
		++$this->status_transition_calls;
	}
}
