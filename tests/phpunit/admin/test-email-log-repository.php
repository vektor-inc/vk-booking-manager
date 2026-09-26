<?php
/**
 * Email_Log_Repository のテスト（#510）。
 *
 * 通知の種類・何回目の送信か・予約ID・状態（送信済み／失敗／未送信）を
 * 追加で保存できること、互換のため success（真偽値）も引き続き保存されること、
 * 変更前に保存された古いログ（新しいキーが無いもの）が壊れずに読み込めることを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Email_Log_Repository;
use WP_UnitTestCase;
use function update_option;

/**
 * @group admin
 */
class Email_Log_Repository_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		( new Email_Log_Repository() )->clear_logs();
	}

	protected function tearDown(): void {
		( new Email_Log_Repository() )->clear_logs();
		parent::tearDown();
	}

	/**
	 * add_log() が status に応じて success・status・type・attempt 等を正しく保存することを確認する。
	 */
	public function test_add_log(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'status=sent => success は true、error は空文字（正常系）',
				'status'              => Email_Log_Repository::STATUS_SENT,
				'error'               => '',
				'expected_success'    => true,
			),
			array(
				'test_condition_name' => 'status=failed => success は false、error を保持する（正常系）',
				'status'              => Email_Log_Repository::STATUS_FAILED,
				'error'               => 'SMTP error',
				'expected_success'    => false,
			),
			array(
				'test_condition_name' => 'status=skipped（#510 で新設） => success は false（境界値）',
				'status'              => Email_Log_Repository::STATUS_SKIPPED,
				'error'               => '通知先のメールアドレスが設定されていません。',
				'expected_success'    => false,
			),
		);

		$repository = new Email_Log_Repository();

		foreach ( $test_cases as $case ) {
			$repository->clear_logs();

			$repository->add_log(
				'to@example.com',
				'件名サンプル',
				$case['status'],
				$case['error'],
				'pending_customer',
				2,
				3,
				123
			);

			$logs = $repository->get_logs();
			$this->assertCount( 1, $logs, $case['test_condition_name'] );

			$log = $logs[0];
			$this->assertSame( $case['status'], $log['status'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_success'], $log['success'], $case['test_condition_name'] );
			$this->assertSame( $case['error'], $log['error'], $case['test_condition_name'] );
			$this->assertSame( 'pending_customer', $log['type'], $case['test_condition_name'] );
			$this->assertSame( 2, $log['attempt'], $case['test_condition_name'] );
			$this->assertSame( 3, $log['max_attempts'], $case['test_condition_name'] );
			$this->assertSame( 123, $log['booking_id'], $case['test_condition_name'] );
			$this->assertSame( 'to@example.com', $log['email'], $case['test_condition_name'] );
		}
	}

	/**
	 * 変更前に保存された古いログ（type/status/attempt 等のキーが無いもの）が、
	 * 新しいキーを後付けされずにそのまま読み込めることを確認する。
	 */
	public function test_get_logs_keeps_legacy_entries_without_new_fields(): void {
		$legacy_entry = array(
			'timestamp' => time(),
			'email'     => 'legacy@example.com',
			'subject'   => '旧仕様の件名',
			'success'   => true,
			'error'     => '',
		);
		update_option( Email_Log_Repository::OPTION_KEY, array( $legacy_entry ) );

		$logs = ( new Email_Log_Repository() )->get_logs();

		$this->assertCount( 1, $logs );
		$this->assertArrayNotHasKey( 'status', $logs[0], '旧形式のログに status キーが後付けされないこと' );
		$this->assertArrayNotHasKey( 'type', $logs[0], '旧形式のログに type キーが後付けされないこと' );
		$this->assertTrue( $logs[0]['success'] );
		$this->assertSame( 'legacy@example.com', $logs[0]['email'] );
	}
}
