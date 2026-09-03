<?php
/**
 * 予約管理画面の顧客メールアドレスサニタイズ（sanitize_email + is_email）のテスト.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * Booking_Admin::sanitize_email() の検証テスト.
 *
 * @group bookings
 */
class Booking_Admin_Email_Sanitize_Test extends WP_UnitTestCase {

	/**
	 * private な sanitize_email() を Reflection 経由で呼び出すヘルパー.
	 *
	 * @param mixed $value 入力値.
	 * @return string サニタイズ結果.
	 */
	private function invoke_sanitize_email( $value ): string {
		$admin      = new Booking_Admin();
		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'sanitize_email' );
		$method->setAccessible( true );
		return $method->invoke( $admin, $value );
	}

	/**
	 * 妥当なメールアドレス・不正なメールアドレス・空値の扱いをまとめて検証する.
	 *
	 * @return void
	 */
	public function test_sanitize_email_cases(): void {
		$cases = array(
			// 妥当なメールアドレスはそのまま返る.
			array(
				'name'     => 'valid_email',
				'input'    => 'customer@example.com',
				'expected' => 'customer@example.com',
			),
			// プラス記号付きエイリアスも妥当として扱う.
			array(
				'name'     => 'valid_email_with_plus',
				'input'    => 'customer+alias@example.com',
				'expected' => 'customer+alias@example.com',
			),
			// `foo@` は sanitize_email() は通すが is_email() で不正となるため空文字.
			array(
				'name'     => 'missing_domain',
				'input'    => 'foo@',
				'expected' => '',
			),
			// `@` を含まない文字列は不正なため空文字.
			array(
				'name'     => 'no_at_sign',
				'input'    => 'not-an-email',
				'expected' => '',
			),
			// 空文字はそのまま空文字（メール未入力を許容）.
			array(
				'name'     => 'empty_string',
				'input'    => '',
				'expected' => '',
			),
			// null は empty() で空文字として扱う.
			array(
				'name'     => 'null_input',
				'input'    => null,
				'expected' => '',
			),
			// 配列など非スカラー値は (string) キャスト前に空文字として扱う.
			array(
				'name'     => 'array_input',
				'input'    => array( 'customer@example.com' ),
				'expected' => '',
			),
		);

		foreach ( $cases as $case ) {
			$actual = $this->invoke_sanitize_email( $case['input'] );
			$this->assertSame(
				$case['expected'],
				$actual,
				"ケース「{$case['name']}」で期待値と一致しませんでした。"
			);
		}
	}
}
