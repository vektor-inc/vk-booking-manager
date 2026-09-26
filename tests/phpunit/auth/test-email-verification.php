<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use VKBookingManager\Auth\Email_Verification;
use WP_UnitTestCase;

/**
 * @group auth
 */
class Email_Verification_Test extends WP_UnitTestCase {

	/**
	 * is_login_allowed() のテスト。4つの保存状態 × メール認証必須設定のオン/オフ。
	 */
	public function test_is_login_allowed(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '状態が保存されていない（旧ユーザー）かつ設定オンの場合 => 許可',
				'status'                => null,
				'verification_required' => true,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '状態 "1"（メールで認証済み）かつ設定オンの場合 => 許可',
				'status'                => Email_Verification::STATUS_VERIFIED,
				'verification_required' => true,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '状態 "manual"（手動承認）かつ設定オンの場合 => 許可',
				'status'                => Email_Verification::STATUS_MANUAL,
				'verification_required' => true,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '状態 "0"（未認証）かつ設定オンの場合 => 拒否',
				'status'                => Email_Verification::STATUS_UNVERIFIED,
				'verification_required' => true,
				'expected'              => false,
			),
			array(
				'test_condition_name'   => '状態 "0"（未認証）かつ設定オフの場合（論点1） => 許可',
				'status'                => Email_Verification::STATUS_UNVERIFIED,
				'verification_required' => false,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '想定外の保存値の場合 => 安全側に倒して拒否',
				'status'                => 'garbage',
				'verification_required' => true,
				'expected'              => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create();

			if ( null !== $case['status'] ) {
				update_user_meta( $user_id, Email_Verification::META_STATUS, $case['status'] );
			}

			$actual = Email_Verification::is_login_allowed( $user_id, $case['verification_required'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * mark_verified() が状態を '1' にし、トークン系メタを削除することのテスト。
	 */
	public function test_mark_verified(): void {
		$user_id = $this->factory()->user->create();

		update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );
		update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, hash( 'sha256', 'token' ) );
		update_user_meta( $user_id, Email_Verification::META_TOKEN_EXPIRES, time() + DAY_IN_SECONDS );
		update_user_meta( $user_id, Email_Verification::META_LEGACY_TOKEN, 'raw-token' );
		// 安藤さんレビュー指摘（issue #507 PR）: 再送の追跡メタも認証済みになれば不要になる。
		update_user_meta( $user_id, Email_Verification::META_RESEND_COUNT, 3 );
		update_user_meta( $user_id, Email_Verification::META_RESEND_WINDOW_START, time() );
		update_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, time() );

		Email_Verification::mark_verified( $user_id );

		$this->assertSame( Email_Verification::STATUS_VERIFIED, Email_Verification::get_status( $user_id ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_TOKEN_EXPIRES, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_LEGACY_TOKEN, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_RESEND_COUNT, true ), '再送回数カウンタが削除されること' );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_RESEND_WINDOW_START, true ), '再送ウィンドウ開始時刻が削除されること' );
		$this->assertSame( '', (string) get_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, true ), '最終再送時刻が削除されること' );
	}

	/**
	 * has_reached_daily_resend_limit() / record_resend() のテスト（安藤さんレビュー指摘）。
	 */
	public function test_has_reached_daily_resend_limit_and_record_resend(): void {
		$user_id = $this->factory()->user->create();

		// ウィンドウ未開始 → 上限には達していない。
		$this->assertFalse( Email_Verification::has_reached_daily_resend_limit( $user_id ), 'ウィンドウ未開始では上限に達していないこと' );

		for ( $i = 0; $i < Email_Verification::RESEND_DAILY_MAX; $i++ ) {
			$this->assertFalse(
				Email_Verification::has_reached_daily_resend_limit( $user_id ),
				sprintf( '%d回目の送信前は上限に達していないこと', $i + 1 )
			);
			Email_Verification::record_resend( $user_id );
		}

		// 上限（既定5回）まで記録した直後は上限に達していること。
		$this->assertTrue( Email_Verification::has_reached_daily_resend_limit( $user_id ), '上限回数を記録した直後は上限に達していること' );

		// ウィンドウが24時間を過ぎていれば、カウントが残っていても上限扱いにしない。
		update_user_meta( $user_id, Email_Verification::META_RESEND_WINDOW_START, time() - Email_Verification::RESEND_DAILY_WINDOW - 1 );
		$this->assertFalse( Email_Verification::has_reached_daily_resend_limit( $user_id ), 'ウィンドウ経過後は上限扱いにしないこと' );

		// ウィンドウ経過後に record_resend() すると、カウンタが1から再開すること。
		Email_Verification::record_resend( $user_id );
		$this->assertSame( 1, (int) get_user_meta( $user_id, Email_Verification::META_RESEND_COUNT, true ), 'ウィンドウ経過後はカウンタが1から再開すること' );
	}

	/**
	 * mark_manual() / revoke_manual() の保存内容のテスト。
	 */
	public function test_mark_manual_and_revoke_manual(): void {
		$user_id     = $this->factory()->user->create();
		$approver_id = $this->factory()->user->create();

		update_user_meta( $user_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

		Email_Verification::mark_manual( $user_id, $approver_id );

		$this->assertSame( Email_Verification::STATUS_MANUAL, Email_Verification::get_status( $user_id ) );
		$approval = Email_Verification::get_manual_approval_info( $user_id );
		$this->assertGreaterThan( 0, $approval['at'], '承認日時が保存されていること' );
		$this->assertSame( $approver_id, $approval['by'], '承認者IDが保存されていること' );

		Email_Verification::revoke_manual( $user_id );

		$this->assertSame( Email_Verification::STATUS_UNVERIFIED, Email_Verification::get_status( $user_id ), '取り消し後は未認証に戻ること' );
		$approval_after_revoke = Email_Verification::get_manual_approval_info( $user_id );
		$this->assertSame( 0, $approval_after_revoke['at'], '取り消し後は承認日時が消えていること' );
		$this->assertSame( 0, $approval_after_revoke['by'], '取り消し後は承認者IDが消えていること' );
	}

	/**
	 * get_badge() のテスト。
	 */
	public function test_get_badge(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '状態 "0"（未認証）の場合 => unverified バッジ',
				'status'               => Email_Verification::STATUS_UNVERIFIED,
				'expected_class'       => 'vkbm-email-verification-badge--unverified',
			),
			array(
				'test_condition_name' => '状態 "manual" の場合 => manual バッジ',
				'status'               => Email_Verification::STATUS_MANUAL,
				'expected_class'       => 'vkbm-email-verification-badge--manual',
			),
			array(
				'test_condition_name' => '状態 "1"（認証済み）の場合 => バッジなし',
				'status'               => Email_Verification::STATUS_VERIFIED,
				'expected_class'       => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create();
			update_user_meta( $user_id, Email_Verification::META_STATUS, $case['status'] );

			$badge = Email_Verification::get_badge( $user_id );

			if ( null === $case['expected_class'] ) {
				$this->assertNull( $badge, $case['test_condition_name'] );
			} else {
				$this->assertIsArray( $badge, $case['test_condition_name'] );
				$this->assertSame( $case['expected_class'], $badge['css_class'], $case['test_condition_name'] );
			}
		}
	}
}
