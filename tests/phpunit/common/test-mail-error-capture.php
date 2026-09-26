<?php
/**
 * Mail_Error_Capture のテスト（#510）。
 *
 * wp_mail() の送信結果とエラー文を、「今回」の wp_mail_failed が渡す WP_Error の
 * メッセージ → PHPMailer の ErrorInfo → 「不明なエラー」の優先順位で取得できることを
 * 確認する（レビュー対応 #510: SMTP 系プラグインが wp_mail() を差し替える環境では、
 * グローバル $phpmailer に前回送信分の ErrorInfo が残ったままのことがあるため、
 * 今回確実に捕捉できたメッセージを優先する）。
 * 「今回の送信が失敗したことを確かめてから」エラー文を読む仕様（#510 仕様案2）のため、
 * wp_mail_failed が一度も発火しないまま失敗したケース（pre_wp_mail による短絡など）は
 * 直前の送信のグローバル状態を誤って使わず「不明なエラー」になることも確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Mail_Error_Capture;
use WP_Error;
use WP_UnitTestCase;

/**
 * @group notifications
 */
class Mail_Error_Capture_Test extends WP_UnitTestCase {
	/**
	 * テスト前後で汚さないよう、グローバル $phpmailer を退避・復元する。
	 *
	 * @var mixed
	 */
	private $original_phpmailer;

	protected function setUp(): void {
		parent::setUp();

		global $phpmailer;
		$this->original_phpmailer = $phpmailer;
	}

	protected function tearDown(): void {
		global $phpmailer;
		$phpmailer = $this->original_phpmailer; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.

		parent::tearDown();
	}

	/**
	 * send() の成否・エラー文の優先順位を確認する。
	 */
	public function test_send(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '送信成功 => sent は true、error は空文字（正常系）',
				'send_result'          => true,
				'fire_wp_mail_failed'  => false,
				'wp_error_message'     => '',
				'phpmailer_error_info' => '',
				'expected_sent'        => true,
				'expected_error'       => '',
			),
			array(
				'test_condition_name'  => '失敗・wp_mail_failed のメッセージと PHPMailer の ErrorInfo が両方ある => メッセージを優先する（正常系・レビュー対応#510）',
				'send_result'          => false,
				'fire_wp_mail_failed'  => true,
				'wp_error_message'     => 'wp_mail_failed のメッセージ',
				'phpmailer_error_info' => 'PHPMailer の ErrorInfo',
				'expected_sent'        => false,
				'expected_error'       => 'wp_mail_failed のメッセージ',
			),
			array(
				'test_condition_name'  => '失敗・wp_mail_failed のメッセージが空 => PHPMailer の ErrorInfo を採用する（正常系・レビュー対応#510）',
				'send_result'          => false,
				'fire_wp_mail_failed'  => true,
				'wp_error_message'     => '',
				'phpmailer_error_info' => 'PHPMailer の ErrorInfo',
				'expected_sent'        => false,
				'expected_error'       => 'PHPMailer の ErrorInfo',
			),
			array(
				'test_condition_name'  => '失敗・メッセージも ErrorInfo も無いが wp_mail_failed は発火 => 「不明なエラー」（境界値）',
				'send_result'          => false,
				'fire_wp_mail_failed'  => true,
				'wp_error_message'     => '',
				'phpmailer_error_info' => '',
				'expected_sent'        => false,
				'expected_error'       => __( 'Unknown error', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name'  => '失敗・wp_mail_failed が発火しない（pre_wp_mail 短絡等） => 直前の状態を使わず「不明なエラー」（異常系・境界値）',
				'send_result'          => false,
				'fire_wp_mail_failed'  => false,
				'wp_error_message'     => '',
				// 直前の送信で残った ErrorInfo が誤って使われないことを確かめるため、あえて非空にしておく。
				'phpmailer_error_info' => '直前の送信の残骸ErrorInfo',
				'expected_sent'        => false,
				'expected_error'       => __( 'Unknown error', 'vk-booking-manager' ),
			),
		);

		global $phpmailer;

		foreach ( $test_cases as $case ) {
			$phpmailer = (object) array( 'ErrorInfo' => $case['phpmailer_error_info'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.

			$send_result         = $case['send_result'];
			$fire_wp_mail_failed = $case['fire_wp_mail_failed'];
			$wp_error_message    = $case['wp_error_message'];

			$callback = static function () use ( $send_result, $fire_wp_mail_failed, $wp_error_message ) {
				if ( $fire_wp_mail_failed ) {
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $wp_error_message ) );
				}
				return $send_result;
			};

			$actual = Mail_Error_Capture::send( $callback );

			$this->assertSame( $case['expected_sent'], $actual['sent'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_error'], $actual['error'], $case['test_condition_name'] );
		}
	}

	/**
	 * 複数のメールを連続送信する場面で、直前の送信の wp_mail_failed リスナーが
	 * 今回の送信結果に混ざらないことを確認する（#510 仕様案2の取り違え防止）。
	 */
	public function test_send_does_not_mix_up_consecutive_calls(): void {
		global $phpmailer;

		// 1件目: 失敗（wp_mail_failed のメッセージが ErrorInfo より優先されるため「1件目の WP_Error」を記録）。
		$phpmailer = (object) array( 'ErrorInfo' => '1件目のエラー' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
		$first     = Mail_Error_Capture::send(
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', '1件目の WP_Error' ) );
				return false;
			}
		);
		$this->assertFalse( $first['sent'] );
		$this->assertSame( '1件目の WP_Error', $first['error'] );

		// 2件目: 成功。1件目のリスナーが残っていないことを確認する
		// （残っていれば $failed が意図せず true になり得るが、成功時は error を返さないので
		// リスナーの解除自体は sent=true の結果だけでは検証できない。3件目で検証する）。
		$second = Mail_Error_Capture::send(
			static function () {
				return true;
			}
		);
		$this->assertTrue( $second['sent'] );
		$this->assertSame( '', $second['error'] );

		// 3件目: wp_mail_failed を発火させず失敗。1件目のリスナーが解除されていれば
		// 「不明なエラー」になる。解除されていなければ 1件目のフラグ・メッセージが
		// 誤って残り、1件目のエラー文が漏れ出てしまう。
		$phpmailer = (object) array( 'ErrorInfo' => '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
		$third     = Mail_Error_Capture::send(
			static function () {
				return false;
			}
		);
		$this->assertFalse( $third['sent'] );
		$this->assertSame( __( 'Unknown error', 'vk-booking-manager' ), $third['error'], '前回のリスナーが残って誤ったエラー文を拾っていないこと' );
	}

	/**
	 * 安藤さんレビュー対応（#510 LOW-4）: $send_callback が例外を投げても、
	 * finally 節で wp_mail_failed のリスナーが解除される（has_action が呼び出し前の
	 * 状態に戻る）ことを確認する。解除されないと、以降に発生する無関係な
	 * wp_mail_failed までこのリスナーが拾い続けてしまう。
	 */
	public function test_send_removes_listener_when_callback_throws(): void {
		$before_has_action = has_action( 'wp_mail_failed' );

		$thrown = null;
		try {
			Mail_Error_Capture::send(
				static function () {
					throw new \RuntimeException( 'コールバック内で発生した例外' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, '呼び出し元のコールバックの例外はそのまま伝播すること' );
		$this->assertSame(
			$before_has_action,
			has_action( 'wp_mail_failed' ),
			'コールバックが例外を投げても finally で wp_mail_failed のリスナーが解除されること'
		);
	}
}
