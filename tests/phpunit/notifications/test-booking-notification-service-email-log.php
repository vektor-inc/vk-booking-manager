<?php
/**
 * Booking_Notification_Service のメールログ記録（#510）のテスト。
 *
 * send_mail() 内の1か所でメールログへ記録することを前提に、以下を確認する。
 * - メールログ有効時のみ記録され、無効時は何も記録されない。
 * - 送信失敗時はエラー文と、再送予定（自動で再送します。／これ以上再送しません。）を記録する。
 * - 宛先（利用者メール・事業者メール）が空/不正で送れない場合は「未送信」として理由付きで記録する。
 * - 事業者向け通知は provider_email が空/不正なら管理者メールアドレスへフォールバックし、
 *   管理者メールアドレスも空/不正なときだけ「未送信」になる（#510 追加決定）。
 * - リマインダーは attempt を持たず（0固定）、未送信は記録しない。
 *
 * 送信失敗は pre_wp_mail フィルターで再現し、あわせて wp_mail_failed を発火させて
 * Mail_Error_Capture が「今回の送信の失敗」として認識できるようにする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Notifications;

use ReflectionMethod;
use VKBookingManager\Admin\Email_Log_Repository;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use WP_UnitTestCase;
use function add_filter;
use function remove_filter;
use function update_option;
use function update_post_meta;

/**
 * @group notifications
 */
class Booking_Notification_Service_Email_Log_Test extends WP_UnitTestCase {
	/** @var string */
	private $original_admin_email = '';

	/** @var Settings_Repository */
	private $settings_repository;

	/** @var array<string, mixed> */
	private $original_settings;

	/** @var Email_Log_Repository */
	private $email_log_repository;

	protected function setUp(): void {
		parent::setUp();

		$this->original_admin_email = (string) get_option( 'admin_email' );
		$this->settings_repository  = new Settings_Repository();
		$this->original_settings    = $this->settings_repository->get_settings();
		$this->email_log_repository = new Email_Log_Repository();
		$this->email_log_repository->clear_logs();
	}

	protected function tearDown(): void {
		update_option( 'admin_email', $this->original_admin_email );
		$this->settings_repository->update_settings( $this->original_settings );
		$this->email_log_repository->clear_logs();

		parent::tearDown();
	}

	/**
	 * 検証用の予約投稿を作成し、利用者メールアドレスを設定する。
	 *
	 * @param string $customer_email 利用者のメールアドレス（空文字は未設定を表す）。
	 * @return int 作成した予約投稿ID。
	 */
	private function create_booking( string $customer_email ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', $customer_email );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'テスト太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'pending' );

		return $booking_id;
	}

	/**
	 * pre_wp_mail をフィルターして送信結果を固定し、失敗時は wp_mail_failed も発火させる
	 * コールバックを登録する。呼び出し側で remove_filter() すること。
	 *
	 * @param bool $mail_result wp_mail() の戻り値として使う値。
	 * @return callable 登録したフィルターコールバック（remove_filter 用に返す）。
	 */
	private function add_mail_result_filter( bool $mail_result ): callable {
		$filter = static function () use ( $mail_result ) {
			if ( $mail_result ) {
				return true;
			}
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Simulated SMTP failure' ) );
			return false;
		};
		add_filter( 'pre_wp_mail', $filter );

		return $filter;
	}

	/**
	 * dispatch_notification()（予約の作成・確定・キャンセル経路）のメールログ記録を検証する。
	 */
	public function test_dispatch_notification_records_email_log(): void {
		$test_cases = array(
			array(
				'test_condition_name'     => 'メールログ有効・送信成功 => sent で1件記録される（正常系）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_customer',
				'customer_email'          => 'customer@example.com',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => true,
				'attempt'                 => 1,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_SENT,
				'expected_email'          => 'customer@example.com',
				'expected_error_contains' => null,
			),
			array(
				'test_condition_name'     => 'メールログ無効 => 送信結果に関わらず記録されない（正常系）',
				'email_log_enabled'       => false,
				'type'                    => 'pending_customer',
				'customer_email'          => 'customer@example.com',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => true,
				'attempt'                 => 1,
				'expected_log_count'      => 0,
				'expected_status'         => null,
				'expected_email'          => null,
				'expected_error_contains' => null,
			),
			array(
				'test_condition_name'     => '利用者のメールアドレスが無い => 未送信として記録される（異常系）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_customer',
				'customer_email'          => '',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => true,
				'attempt'                 => 1,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_SKIPPED,
				'expected_email'          => '',
				'expected_error_contains' => 'customer email address',
				'expected_attempt'        => 1,
				'expected_max_attempts'   => 3,
			),
			array(
				'test_condition_name'     => '1回目の送信失敗後、再送（2回目）で利用者のメールアドレスが無効になった =>'
					. ' 未送信として記録され、何回目の送信だったかが attempt に残る（異常系・CodeRabbit指摘対応 #511）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_customer',
				'customer_email'          => '',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => true,
				'attempt'                 => 2,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_SKIPPED,
				'expected_email'          => '',
				'expected_error_contains' => 'customer email address',
				'expected_attempt'        => 2,
				'expected_max_attempts'   => 3,
			),
			array(
				'test_condition_name'     => '事業者メールが空 => 管理者メールへフォールバックして送信済みになる（正常系・#510追加決定）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_provider',
				'customer_email'          => 'customer@example.com',
				'provider_email'          => '',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => true,
				'attempt'                 => 1,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_SENT,
				'expected_email'          => 'admin@example.com',
				'expected_error_contains' => null,
			),
			array(
				'test_condition_name'          => '事業者メール・管理者メールとも空 => 未送信として記録され、案内リンクが別要素で付く（境界値・#510追加決定・植草レビュー対応）',
				'email_log_enabled'            => true,
				'type'                         => 'pending_provider',
				'customer_email'               => 'customer@example.com',
				'provider_email'               => '',
				'admin_email'                  => '',
				'mail_result'                  => true,
				'attempt'                      => 1,
				'expected_log_count'           => 1,
				'expected_status'              => Email_Log_Repository::STATUS_SKIPPED,
				'expected_email'               => '',
				'expected_error_contains'      => 'representative email address',
				'expected_action_url_contains' => 'vkbm-provider-settings',
				'expected_action_label'        => 'Set the representative email address',
				'expected_attempt'             => 1,
				'expected_max_attempts'        => 3,
			),
			array(
				'test_condition_name'     => '送信失敗・1回目 => failed で記録され「自動で再送します。」相当の文言を含む（異常系）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_customer',
				'customer_email'          => 'customer@example.com',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => false,
				'attempt'                 => 1,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_FAILED,
				'expected_email'          => 'customer@example.com',
				'expected_error_contains' => 'retried automatically',
			),
			array(
				'test_condition_name'     => '送信失敗・最終回（3回目） => failed で記録され「これ以上再送しません。」相当の文言を含む（境界値）',
				'email_log_enabled'       => true,
				'type'                    => 'pending_customer',
				'customer_email'          => 'customer@example.com',
				'provider_email'          => 'provider@example.com',
				'admin_email'             => 'admin@example.com',
				'mail_result'             => false,
				'attempt'                 => 3,
				'expected_log_count'      => 1,
				'expected_status'         => Email_Log_Repository::STATUS_FAILED,
				'expected_email'          => 'customer@example.com',
				'expected_error_contains' => 'not be retried further',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->email_log_repository->clear_logs();

			// #510: update_option( 'admin_email', '' ) は WordPress の sanitize_option() が
			// 不正なメールアドレスを拒否して古い値のまま据え置くため、空/不正値を確実に
			// 再現できない。pre_option_admin_email フィルターで読み取り値そのものを
			// 短絡させることで、sanitize_option() を経由せず狙った値を再現する。
			$admin_email        = $case['admin_email'];
			$admin_email_filter = static function () use ( $admin_email ) {
				return $admin_email;
			};
			add_filter( 'pre_option_admin_email', $admin_email_filter );

			$settings                      = $this->settings_repository->get_settings();
			$settings['email_log_enabled'] = $case['email_log_enabled'];
			$settings['provider_email']    = $case['provider_email'];
			$this->settings_repository->update_settings( $settings );

			$booking_id = $this->create_booking( $case['customer_email'] );

			$filter = $this->add_mail_result_filter( $case['mail_result'] );

			$service = new Booking_Notification_Service( $this->settings_repository, $this->email_log_repository );
			$method  = new ReflectionMethod( Booking_Notification_Service::class, 'dispatch_notification' );
			$method->setAccessible( true );
			$method->invoke( $service, $case['type'], $booking_id, $case['attempt'] );

			remove_filter( 'pre_wp_mail', $filter );
			remove_filter( 'pre_option_admin_email', $admin_email_filter );

			$logs = $this->email_log_repository->get_logs();
			$this->assertCount( $case['expected_log_count'], $logs, $case['test_condition_name'] );

			if ( $case['expected_log_count'] > 0 ) {
				$log = $logs[0];
				$this->assertSame( $case['expected_status'], $log['status'], $case['test_condition_name'] );
				$this->assertSame( $case['expected_email'], $log['email'], $case['test_condition_name'] );
				$this->assertSame( $case['type'], $log['type'], $case['test_condition_name'] );
				$this->assertSame( $booking_id, $log['booking_id'], $case['test_condition_name'] );
				$this->assertSame( Email_Log_Repository::STATUS_SENT === $log['status'], $log['success'], $case['test_condition_name'] . '（互換の success 真偽値）' );

				if ( null !== $case['expected_error_contains'] ) {
					$this->assertStringContainsString( $case['expected_error_contains'], $log['error'], $case['test_condition_name'] );
				}

				// #510 植草（UX）レビュー対応: 案内リンクは URL を本文へ埋め込まず、別フィールドに持たせる。
				$expected_action_url_contains = $case['expected_action_url_contains'] ?? null;
				if ( null !== $expected_action_url_contains ) {
					$this->assertStringContainsString( $expected_action_url_contains, $log['action_url'], $case['test_condition_name'] );
				}
				$expected_action_label = $case['expected_action_label'] ?? null;
				if ( null !== $expected_action_label ) {
					$this->assertSame( $expected_action_label, $log['action_label'], $case['test_condition_name'] );
				}

				// CodeRabbit 指摘対応（#511）: skipped（未送信）を含め、booking 系通知は
				// attempt / max_attempts をそのまま記録することを確認する。
				$expected_attempt = $case['expected_attempt'] ?? null;
				if ( null !== $expected_attempt ) {
					$this->assertSame( $expected_attempt, $log['attempt'], $case['test_condition_name'] );
				}
				$expected_max_attempts = $case['expected_max_attempts'] ?? null;
				if ( null !== $expected_max_attempts ) {
					$this->assertSame( $expected_max_attempts, $log['max_attempts'], $case['test_condition_name'] );
				}
			}
		}
	}

	/**
	 * send_customer_reminder()（リマインダー経路）のメールログ記録を検証する。
	 * リマインダーは attempt の概念を持たず、未送信（宛先無効）は記録されない。
	 */
	public function test_send_customer_reminder_email_log(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '有効なメールで送信成功 => sent で記録され attempt/max_attempts は 0（正常系）',
				'customer_email'      => 'customer@example.com',
				'mail_result'         => true,
				'expected_log_count'  => 1,
				'expected_status'     => Email_Log_Repository::STATUS_SENT,
			),
			array(
				'test_condition_name' => '送信失敗 => failed で記録されるが再送の案内文は付かない（異常系）',
				'customer_email'      => 'customer@example.com',
				'mail_result'         => false,
				'expected_log_count'  => 1,
				'expected_status'     => Email_Log_Repository::STATUS_FAILED,
			),
			array(
				'test_condition_name' => '利用者のメールアドレスが無い => リマインダーの未送信は記録されない（境界値）',
				'customer_email'      => '',
				'mail_result'         => true,
				'expected_log_count'  => 0,
				'expected_status'     => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->email_log_repository->clear_logs();

			$settings                      = $this->settings_repository->get_settings();
			$settings['email_log_enabled'] = true;
			$this->settings_repository->update_settings( $settings );

			$booking_id = $this->create_booking( $case['customer_email'] );

			$filter = $this->add_mail_result_filter( $case['mail_result'] );

			$service = new Booking_Notification_Service( $this->settings_repository, $this->email_log_repository );
			$method  = new ReflectionMethod( Booking_Notification_Service::class, 'send_customer_reminder' );
			$method->setAccessible( true );
			$method->invoke( $service, $booking_id, 24 );

			remove_filter( 'pre_wp_mail', $filter );

			$logs = $this->email_log_repository->get_logs();
			$this->assertCount( $case['expected_log_count'], $logs, $case['test_condition_name'] );

			if ( $case['expected_log_count'] > 0 ) {
				$log = $logs[0];
				$this->assertSame( $case['expected_status'], $log['status'], $case['test_condition_name'] );
				$this->assertSame( 0, $log['attempt'], $case['test_condition_name'] . '（リマインダーは attempt を持たない）' );
				$this->assertSame( 0, $log['max_attempts'], $case['test_condition_name'] );
				$this->assertSame( 'reminder_customer', $log['type'], $case['test_condition_name'] );

				if ( Email_Log_Repository::STATUS_FAILED === $case['expected_status'] ) {
					$this->assertStringNotContainsString( 'retried', $log['error'], $case['test_condition_name'] . '（再送の概念が無いので案内文を付けない）' );
				}
			}
		}
	}
}
