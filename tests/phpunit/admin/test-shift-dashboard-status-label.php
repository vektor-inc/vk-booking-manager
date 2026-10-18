<?php
/**
 * シフト・予約表の予約カードに出す状態ラベル（仮予約・キャンセル・無断）の描画テスト。
 *
 * 日表示のカード・月表示のカード・「+N件」から開く一覧の 3 か所で、確定以外の状態に
 * 短縮ラベルが出て、確定にはラベルが出ないことを確かめる。
 *
 * @package VKBookingManager
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/issues/846
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Shift_Dashboard_Page;
use WP_UnitTestCase;

/**
 * 予約カードの状態ラベルの描画テスト。
 *
 * @group admin
 */
class Shift_Dashboard_Status_Label_Test extends WP_UnitTestCase {

	/**
	 * テストで使う予約日。
	 *
	 * @var string
	 */
	private const TEST_DATE = '2026-04-15';

	/**
	 * 各テスト前に管理者ユーザーを作成してログイン状態にする。
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user(
			$this->factory()->user->create(
				array(
					'role' => 'administrator',
				)
			)
		);
	}

	/**
	 * 各テスト後に $_GET とユーザー状態を戻す。
	 */
	protected function tearDown(): void {
		unset( $_GET['vkbm_date'], $_GET['vkbm_view'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 状態ごとに、日表示・月表示・「+N件」一覧のカードへ状態ラベルが出る（確定は出ない）ことをテストする。
	 */
	public function test_render_page_status_label(): void {
		// 短縮ラベルの翻訳は実装と同じ context で引き、ロケールに依らず期待値を作る。
		$context = 'short form of booking status on shift dashboard cards';

		$test_cases = array(
			array(
				'test_condition_name' => '仮予約の予約 => 日表示・月表示にラベルと正式名の title が出る',
				'status'              => 'pending',
				'booking_count'       => 1,
				'expected_label'      => _x( 'Pending', $context, 'vk-booking-manager' ),
				'expected_title'      => __( 'Pending', 'vk-booking-manager' ),
				'expected_modifier'   => 'pending',
				'expected_in_modal'   => false,
			),
			array(
				'test_condition_name' => 'キャンセルの予約 => 日表示・月表示にラベルと正式名の title が出る',
				'status'              => 'cancelled',
				'booking_count'       => 1,
				'expected_label'      => _x( 'Cancelled', $context, 'vk-booking-manager' ),
				'expected_title'      => __( 'Cancelled', 'vk-booking-manager' ),
				'expected_modifier'   => 'cancelled',
				'expected_in_modal'   => false,
			),
			array(
				'test_condition_name' => '無断キャンセルの予約 => 日表示・月表示にラベルと正式名の title が出る',
				'status'              => 'no_show',
				'booking_count'       => 1,
				'expected_label'      => _x( 'No-show', $context, 'vk-booking-manager' ),
				'expected_title'      => __( 'No-show', 'vk-booking-manager' ),
				'expected_modifier'   => 'no-show',
				'expected_in_modal'   => false,
			),
			array(
				'test_condition_name' => '確定の予約 => ラベルも title も出ない',
				'status'              => 'confirmed',
				'booking_count'       => 1,
				'expected_label'      => '',
				'expected_title'      => '',
				'expected_modifier'   => '',
				'expected_in_modal'   => false,
			),
			array(
				'test_condition_name' => '同じ時間帯に 4 件重なり全件が無断キャンセル => 「+N件」の一覧にもラベルが出る',
				'status'              => 'no_show',
				'booking_count'       => 4,
				'expected_label'      => _x( 'No-show', $context, 'vk-booking-manager' ),
				'expected_title'      => __( 'No-show', 'vk-booking-manager' ),
				'expected_modifier'   => 'no-show',
				'expected_in_modal'   => true,
			),
			array(
				'test_condition_name' => '同じ時間帯に 4 件重なり全件が確定 => 「+N件」の一覧にもラベルが出ない',
				'status'              => 'confirmed',
				'booking_count'       => 4,
				'expected_label'      => '',
				'expected_title'      => '',
				'expected_modifier'   => '',
				'expected_in_modal'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$post_ids = $this->create_dashboard_posts( $case['status'], $case['booking_count'] );
			$output   = $this->render_day_view();

			if ( '' === $case['expected_modifier'] ) {
				// 確定: どの表示にもラベル要素が出ない。
				foreach ( array( 'vkbm-booking-card__status', 'vkbm-month-booking__status', 'vkbm-booking-modal__status' ) as $label_class ) {
					$this->assertStringNotContainsString( $label_class, $output, $case['test_condition_name'] . ' (' . $label_class . ')' );
				}
				$this->assertStringNotContainsString( ' title="' . esc_attr( __( 'Confirmed', 'vk-booking-manager' ) ) . '"', $output, $case['test_condition_name'] );
			} else {
				// 日表示のカード: ラベル要素の中に短縮ラベルの文字が入る。
				$this->assertStringContainsString(
					'<span class="vkbm-booking-card__status vkbm-booking-card__status--' . $case['expected_modifier'] . '">' . esc_html( $case['expected_label'] ) . '</span>',
					$output,
					$case['test_condition_name'] . ' (日表示)'
				);
				// 月表示のカード: 時刻の行の先頭にラベルが入る。
				$this->assertStringContainsString(
					'<span class="vkbm-month-booking__time"><span class="vkbm-month-booking__status vkbm-month-booking__status--' . $case['expected_modifier'] . '">' . esc_html( $case['expected_label'] ) . '</span>',
					$output,
					$case['test_condition_name'] . ' (月表示)'
				);
				// カードの title 属性に正式名が入る。
				$this->assertStringContainsString(
					' title="' . esc_attr( $case['expected_title'] ) . '"',
					$output,
					$case['test_condition_name'] . ' (title)'
				);
			}

			if ( 1 === $case['booking_count'] ) {
				// 日表示のカードの title: 状態の正式名（確定は無し）・開始〜終了時刻・顧客名を「 / 」で連結する。
				$expected_card_title = implode(
					' / ',
					array_filter( array( $case['expected_title'], '10:00 - 11:00', 'Customer 1' ) )
				);
				$this->assertStringContainsString( ' title="' . esc_attr( $expected_card_title ) . '"', $output, $case['test_condition_name'] . ' (日表示の title)' );
				// 日表示の時刻は、開始だけの要素と終了側の要素に分けて出力する。
				$this->assertStringContainsString(
					'<span class="vkbm-booking-card__time"><span class="vkbm-booking-card__time-start">10:00</span><span class="vkbm-booking-card__time-end"> - 11:00</span></span>',
					$output,
					$case['test_condition_name'] . ' (日表示の時刻)'
				);
			}

			if ( $case['expected_in_modal'] ) {
				$this->assertStringContainsString(
					'<span class="vkbm-booking-modal__time"><span class="vkbm-booking-modal__status vkbm-booking-modal__status--' . $case['expected_modifier'] . '">' . esc_html( $case['expected_label'] ) . '</span>',
					$output,
					$case['test_condition_name'] . ' (一覧)'
				);
			}

			foreach ( $post_ids as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * リソース・シフト・予約を作り、作った投稿 ID を返す。
	 *
	 * @param string $status        予約ステータスのキー。
	 * @param int    $booking_count 同じ時間帯（10:00-11:00）に作る予約の件数。
	 * @return int[] 作成した投稿 ID（後始末用）。
	 */
	private function create_dashboard_posts( string $status, int $booking_count ): array {
		// リソースとシフト（2026-04-15 の 09:00-18:00）を作る。
		$resource_id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => 'Test Resource',
			)
		);
		$shift_id    = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'publish',
				'post_title'  => 'Test Shift',
			)
		);
		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $resource_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', 2026 );
		update_post_meta( $shift_id, '_vkbm_shift_month', 4 );
		update_post_meta(
			$shift_id,
			'_vkbm_shift_days',
			array(
				15 => array(
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

		// 同じ時間帯に指定件数の予約を作る。
		$post_ids = array( $resource_id, $shift_id );
		for ( $i = 0; $i < $booking_count; $i++ ) {
			$booking_id = $this->factory()->post->create(
				array(
					'post_type'   => 'vkbm_booking',
					'post_status' => 'publish',
					'post_title'  => 'Booking ' . ( $i + 1 ),
				)
			);
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', $resource_id );
			update_post_meta( $booking_id, '_vkbm_booking_service_start', self::TEST_DATE . ' 10:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', self::TEST_DATE . ' 11:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_total_end', self::TEST_DATE . ' 11:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'Customer ' . ( $i + 1 ) );
			update_post_meta( $booking_id, '_vkbm_booking_status', $status );
			$post_ids[] = $booking_id;
		}

		return $post_ids;
	}

	/**
	 * 日表示でシフト・予約表を描画し、出力した HTML を返す。
	 *
	 * @return string 描画した HTML。
	 */
	private function render_day_view(): string {
		$_GET['vkbm_date'] = self::TEST_DATE;
		$_GET['vkbm_view'] = 'day';

		$page = new Shift_Dashboard_Page();

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}
}
