<?php
/**
 * Regression test for the sprintf Fatal error in the "+N" badge
 * on the Shift Dashboard page.
 *
 * シフト・予約表画面の「+N件」バッジにおける sprintf Fatal error の回帰テスト。
 *
 * @package VKBookingManager
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/issues/181
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/pull/180
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use WP_UnitTestCase;

/**
 * Verify that sprintf format strings used in the Shift Dashboard "+N" badge
 * do not cause a Fatal error (ValueError: Unknown format specifier).
 *
 * シフトダッシュボードの「+N件」バッジで使用される sprintf フォーマット文字列が
 * Fatal error を発生させないことを検証する。
 *
 * @group admin
 * @group regression
 */
class Shift_Dashboard_Sprintf_Test extends WP_UnitTestCase {

	/**
	 * Force English locale so translatable strings return their original English text.
	 * テスト環境のロケールを英語に固定し、翻訳文字列が英語のまま返されるようにする。
	 */
	protected function setUp(): void {
		parent::setUp();
		switch_to_locale( 'en_US' );
	}

	/**
	 * Restore the previous locale after each test.
	 * テスト終了後にロケールを元に戻す。
	 */
	protected function tearDown(): void {
		restore_previous_locale();
		parent::tearDown();
	}

	/**
	 * Test that the "Show more bookings" format string works with sprintf.
	 * 「+N件」バッジの aria-label 用フォーマット文字列が sprintf で正常に動作することをテストする。
	 *
	 * The bug in PR #180 was caused by backslash-escaped dollar signs
	 * inside single-quoted strings ('%1\$d' instead of '%1$d'),
	 * which made sprintf throw a ValueError.
	 *
	 * PR #180 のバグは、シングルクォート内の不要なバックスラッシュエスケープ
	 * ('%1\$d' → '%1$d') が原因で sprintf が ValueError を発生させていた。
	 */
	public function test_sprintf_show_more_bookings_format(): void {
		// テストケースの配列 / Test case array.
		$test_cases = array(
			array(
				'test_condition_name' => '非表示件数が1件・時間帯が 10:00-11:00 の場合 => 正常にフォーマットされる',
				'hidden_count'        => 1,
				'time_range'          => '10:00-11:00',
				'expected'            => 'Show 1 more bookings for 10:00-11:00',
			),
			array(
				'test_condition_name' => '非表示件数が5件・時間帯が 14:00-15:30 の場合 => 正常にフォーマットされる',
				'hidden_count'        => 5,
				'time_range'          => '14:00-15:30',
				'expected'            => 'Show 5 more bookings for 14:00-15:30',
			),
			array(
				'test_condition_name' => '非表示件数が0件・時間帯が空文字の場合（境界値） => 正常にフォーマットされる',
				'hidden_count'        => 0,
				'time_range'          => '',
				'expected'            => 'Show 0 more bookings for ',
			),
		);

		foreach ( $test_cases as $case ) {
			// フォーマット文字列を翻訳関数経由で取得する / Get format string via translation function.
			// テスト環境では英語がそのまま返る / In test environment, English string is returned as-is.
			$format = __( 'Show %1$d more bookings for %2$s', 'vk-booking-manager' );

			// sprintf を実行して Fatal error が発生しないことを確認する / Execute sprintf and verify no Fatal error occurs.
			$result = sprintf(
				$format,
				(int) $case['hidden_count'],
				$case['time_range']
			);

			$this->assertSame(
				$case['expected'],
				$result,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Test that the "Bookings — %s" modal title format string works with sprintf.
	 * モーダルタイトル用の「Bookings — %s」フォーマット文字列が sprintf で正常に動作することをテストする。
	 */
	public function test_sprintf_bookings_modal_title_format(): void {
		// テストケースの配列 / Test case array.
		$test_cases = array(
			array(
				'test_condition_name' => '時間帯が 10:00-11:00 の場合 => 正常にフォーマットされる',
				'time_range'          => '10:00-11:00',
				'expected'            => 'Bookings — 10:00-11:00',
			),
			array(
				'test_condition_name' => '時間帯が 09:00-17:00 の場合 => 正常にフォーマットされる',
				'time_range'          => '09:00-17:00',
				'expected'            => 'Bookings — 09:00-17:00',
			),
			array(
				'test_condition_name' => '時間帯が空文字の場合（境界値） => 正常にフォーマットされる',
				'time_range'          => '',
				'expected'            => 'Bookings — ',
			),
		);

		foreach ( $test_cases as $case ) {
			// フォーマット文字列を翻訳関数経由で取得する / Get format string via translation function.
			$format = __( 'Bookings — %s', 'vk-booking-manager' );

			// sprintf を実行して Fatal error が発生しないことを確認する / Execute sprintf and verify no Fatal error occurs.
			$result = sprintf(
				$format,
				$case['time_range']
			);

			$this->assertSame(
				$case['expected'],
				$result,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Test that the "+%d" badge count format string works with sprintf.
	 * 「+%d」バッジカウント用フォーマット文字列が sprintf で正常に動作することをテストする。
	 */
	public function test_sprintf_badge_count_format(): void {
		// テストケースの配列 / Test case array.
		$test_cases = array(
			array(
				'test_condition_name' => '非表示件数が1件の場合 => +1 と表示される',
				'hidden_count'        => 1,
				'expected'            => '+1',
			),
			array(
				'test_condition_name' => '非表示件数が10件の場合 => +10 と表示される',
				'hidden_count'        => 10,
				'expected'            => '+10',
			),
			array(
				'test_condition_name' => '非表示件数が0件の場合（境界値） => +0 と表示される',
				'hidden_count'        => 0,
				'expected'            => '+0',
			),
		);

		foreach ( $test_cases as $case ) {
			// sprintf を実行して Fatal error が発生しないことを確認する / Execute sprintf and verify no Fatal error occurs.
			$result = sprintf(
				'+%d',
				(int) $case['hidden_count']
			);

			$this->assertSame(
				$case['expected'],
				$result,
				$case['test_condition_name']
			);
		}
	}
}
