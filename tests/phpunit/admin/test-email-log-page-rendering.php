<?php
/**
 * Email_Log_Page::render_page() の描画テスト（#510）。
 *
 * 一覧の列を増やさず、件名の下に「通知の種類」と「予約 #123（編集画面リンク）」、
 * ステータスの下に再送のときだけ「再送（N回目）」を出すこと。
 * 変更前に保存された古いログ（type/status キーが無いもの）は、これらを出さず
 * 従来どおりの表示になることを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Email_Log_Page;
use VKBookingManager\Admin\Email_Log_Repository;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;
use function __;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function update_option;
use function wp_set_current_user;

/**
 * @group admin
 */
class Email_Log_Page_Rendering_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings_repository           = new Settings_Repository();
		$settings                      = $settings_repository->get_settings();
		$settings['email_log_enabled'] = true;
		$settings_repository->update_settings( $settings );

		( new Email_Log_Repository() )->clear_logs();
	}

	protected function tearDown(): void {
		( new Email_Log_Repository() )->clear_logs();
		unset( $_GET['cleared'] );
		parent::tearDown();
	}

	/**
	 * ログ1件の内容に応じて、一覧に出るべき／出てはいけない文字列を確認する。
	 */
	public function test_render_page(): void {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '新形式・送信済み・1回目 => 種類ラベルと予約リンクが出て、再送行は出ない（正常系）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => 'customer@example.com',
					'subject'      => 'テスト件名1',
					'success'      => true,
					'status'       => Email_Log_Repository::STATUS_SENT,
					'error'        => '',
					'type'         => 'pending_customer',
					'attempt'      => 1,
					'max_attempts' => 3,
					'booking_id'   => $booking_id,
				),
				// 構造（クラス名・翻訳文言の実値）の両方を確認する。翻訳文言は __()/sprintf() で
				// 動的に取得し、po/mo の翻訳有無に関わらずロケールに依存せず検証できるようにする。
				'contains'            => array(
					'vkbm-email-log__type',
					'vkbm-email-log__meta',
					__( 'Pending reservation (to customer)', 'vk-booking-manager' ),
					sprintf( __( 'Reservation #%d', 'vk-booking-manager' ), $booking_id ),
					__( 'Sent', 'vk-booking-manager' ),
				),
				'not_contains'        => array( 'vkbm-email-log__retry' ),
			),
			array(
				'test_condition_name' => '新形式・失敗・2回目（最終回未満） => 再送（2回目）の行が出て、最終回向け文言は出ない（正常系）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => 'customer@example.com',
					'subject'      => 'テスト件名2',
					'success'      => false,
					'status'       => Email_Log_Repository::STATUS_FAILED,
					'error'        => 'SMTP error',
					'type'         => 'pending_customer',
					'attempt'      => 2,
					'max_attempts' => 3,
					'booking_id'   => $booking_id,
				),
				'contains'            => array(
					__( 'Failed', 'vk-booking-manager' ),
					'vkbm-email-log__retry',
					sprintf( __( 'Resend (attempt %d)', 'vk-booking-manager' ), 2 ),
				),
				'not_contains'        => array( sprintf( __( 'Resend (attempt %d, final)', 'vk-booking-manager' ), 2 ) ),
			),
			array(
				'test_condition_name' => '新形式・失敗・最終回（3回目） => 最終回向けの再送文言が出る（境界値）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => 'customer@example.com',
					'subject'      => 'テスト件名3',
					'success'      => false,
					'status'       => Email_Log_Repository::STATUS_FAILED,
					'error'        => 'SMTP error',
					'type'         => 'pending_customer',
					'attempt'      => 3,
					'max_attempts' => 3,
					'booking_id'   => $booking_id,
				),
				'contains'            => array( sprintf( __( 'Resend (attempt %d, final)', 'vk-booking-manager' ), 3 ) ),
				'not_contains'        => array(),
			),
			array(
				'test_condition_name' => '新形式・未送信（skipped） => 未送信ラベルが出て、再送行は出ない（境界値）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => '',
					'subject'      => 'テスト件名4',
					'success'      => false,
					'status'       => Email_Log_Repository::STATUS_SKIPPED,
					'error'        => '通知先のメールアドレスが設定されていません。',
					'type'         => 'pending_provider',
					'attempt'      => 0,
					'max_attempts' => 0,
					'booking_id'   => $booking_id,
				),
				'contains'            => array( __( 'Not sent', 'vk-booking-manager' ) ),
				'not_contains'        => array( 'vkbm-email-log__retry' ),
			),
			array(
				'test_condition_name' => '新形式・未送信（skipped）・1回目 => attempt が1でも再送行は出ない（境界値・CodeRabbit指摘対応 #511）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => '',
					'subject'      => 'テスト件名5',
					'success'      => false,
					'status'       => Email_Log_Repository::STATUS_SKIPPED,
					'error'        => 'この予約に利用者のメールアドレスが無いため、送信しませんでした。',
					'type'         => 'pending_customer',
					'attempt'      => 1,
					'max_attempts' => 3,
					'booking_id'   => $booking_id,
				),
				'contains'            => array( __( 'Not sent', 'vk-booking-manager' ) ),
				'not_contains'        => array( 'vkbm-email-log__retry' ),
			),
			array(
				'test_condition_name' => '新形式・未送信（skipped）・再送2回目で宛先が無効になった => 再送（2回目）の行が出るが、'
					. '「自動で再送します。」等の付記は出ない（境界値・CodeRabbit指摘対応 #511）',
				'log'                 => array(
					'timestamp'    => time(),
					'email'        => '',
					'subject'      => 'テスト件名6',
					'success'      => false,
					'status'       => Email_Log_Repository::STATUS_SKIPPED,
					'error'        => 'この予約に利用者のメールアドレスが無いため、送信しませんでした。',
					'type'         => 'pending_customer',
					'attempt'      => 2,
					'max_attempts' => 3,
					'booking_id'   => $booking_id,
				),
				'contains'            => array(
					__( 'Not sent', 'vk-booking-manager' ),
					'vkbm-email-log__retry',
					/* translators: %d: Attempt number. */
					sprintf( __( 'Resend (attempt %d)', 'vk-booking-manager' ), 2 ),
				),
				'not_contains'        => array(
					/* translators: %d: Attempt number. */
					sprintf( __( 'Resend (attempt %d, final)', 'vk-booking-manager' ), 2 ),
					__( 'It will be retried automatically.', 'vk-booking-manager' ),
					__( 'It will not be retried further.', 'vk-booking-manager' ),
				),
			),
			array(
				'test_condition_name' => '旧形式（status キー無し） => 種類・予約リンク・再送行を出さず成否のみ表示（境界値）',
				'log'                 => array(
					'timestamp' => time(),
					'email'     => 'legacy@example.com',
					'subject'   => '旧仕様の件名',
					'success'   => true,
					'error'     => '',
				),
				'contains'            => array( __( 'Sent', 'vk-booking-manager' ) ),
				'not_contains'        => array( 'vkbm-email-log__type', 'vkbm-email-log__meta', 'vkbm-email-log__retry' ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Email_Log_Repository::OPTION_KEY, array( $case['log'] ) );

			$page = new Email_Log_Page();

			ob_start();
			$page->render_page();
			$output = (string) ob_get_clean();

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . " => '{$needle}' を含むべき" );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . " => '{$needle}' を含まないべき" );
			}
		}
	}

	/**
	 * 見出し直下の補足文（このログが何を示す/示さないか）が、ログの有無に関わらず常に出ることを確認する。
	 */
	public function test_render_page_always_shows_disclaimer(): void {
		update_option( Email_Log_Repository::OPTION_KEY, array() );

		$page = new Email_Log_Page();

		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'whether WordPress was able to process sending the email', $output );
	}

	/**
	 * 安藤さんレビュー対応（#510 LOW-3）: 件名・エラー・action_label に <script> を、
	 * action_url に javascript: を含むログを描画しても、生の <script> タグや
	 * javascript: スキームがそのまま出力に含まれないことを確認する。
	 */
	public function test_render_page_escapes_untrusted_log_fields(): void {
		$malicious_log = array(
			'timestamp'    => time(),
			'email'        => 'attacker@example.com',
			'subject'      => '<script>alert(1)</script>',
			'success'      => false,
			'status'       => Email_Log_Repository::STATUS_SKIPPED,
			'error'        => "<script>alert(2)</script>\nline2",
			'type'         => 'pending_provider',
			'attempt'      => 0,
			'max_attempts' => 0,
			'booking_id'   => 0,
			'action_url'   => 'javascript:alert(3)',
			'action_label' => '<script>alert(4)</script>',
		);

		update_option( Email_Log_Repository::OPTION_KEY, array( $malicious_log ) );

		$page = new Email_Log_Page();

		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script', $output, '件名・エラー・action_label の <script> が生のまま出力されないこと' );
		$this->assertStringNotContainsString( 'javascript:', $output, 'action_url の javascript: スキームが出力に含まれないこと' );
	}
}
