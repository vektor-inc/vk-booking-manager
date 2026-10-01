<?php
/**
 * Centralizes reservation-customer email verification status handling.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes reservation-customer email verification status handling.
 *
 * issue #507: 認証状態の判定・更新がログイン・ユーザー一覧・ユーザー編集・予約一覧・
 * 予約編集・パスワード再設定の複数箇所に散らばると、どこかで 'manual'（店舗による
 * 手動承認）の扱いが漏れる恐れがある。そのため、状態の定義・判定・状態変更の処理を
 * すべてこのクラスに一本化する。
 */
class Email_Verification {
	/**
	 * User meta key holding the verification status.
	 *
	 * 保存され得る値: '' (未保存＝機能導入前の登録ユーザー・管理者作成ユーザー), '0'
	 * (未認証), '1' (メールで認証済み), 'manual' (店舗が手動承認・メール到着は未確認)。
	 *
	 * @var string
	 */
	public const META_STATUS = 'vkbm_email_verified';

	/**
	 * User meta key holding the SHA-256 hash of the pending verification token.
	 *
	 * @var string
	 */
	public const META_TOKEN_HASH = 'vkbm_email_verify_token_hash';

	/**
	 * User meta key holding the expiry timestamp of the pending verification token.
	 *
	 * @var string
	 */
	public const META_TOKEN_EXPIRES = 'vkbm_email_verify_expires';

	/**
	 * Legacy plain-text token meta key, kept only for cleanup of pre-hash installs.
	 *
	 * @var string
	 */
	public const META_LEGACY_TOKEN = 'vkbm_email_verify_token';

	/**
	 * User meta key holding the manual approval timestamp (unix time).
	 *
	 * @var string
	 */
	public const META_MANUAL_AT = 'vkbm_email_verified_manual_at';

	/**
	 * User meta key holding the administrator user ID who granted manual approval.
	 *
	 * @var string
	 */
	public const META_MANUAL_BY = 'vkbm_email_verified_manual_by';

	/**
	 * User meta key holding the unix time of the last verification-email resend.
	 *
	 * 論点4（issue #507）: 同一利用者への再送の間隔（60秒）を判定するために使う。
	 *
	 * @var string
	 */
	public const META_RESEND_LAST_SENT = 'vkbm_email_verify_last_resend_at';

	/**
	 * User meta key holding the number of resends sent within the current daily window.
	 *
	 * 安藤さんレビュー指摘（issue #507 PR）: 利用者単位の1日上限を判定するために使う。
	 *
	 * @var string
	 */
	public const META_RESEND_COUNT = 'vkbm_email_verify_resend_count';

	/**
	 * User meta key holding the unix time when the current daily resend window started.
	 *
	 * @var string
	 */
	public const META_RESEND_WINDOW_START = 'vkbm_email_verify_resend_window_start';

	/**
	 * Max resends allowed per user within the daily window (安藤さんレビュー指摘）.
	 *
	 * @var int
	 */
	public const RESEND_DAILY_MAX = 5;

	/**
	 * Length of the daily resend window in seconds.
	 *
	 * @var int
	 */
	public const RESEND_DAILY_WINDOW = DAY_IN_SECONDS;

	/**
	 * Status: registered, but the verification email link has not been confirmed.
	 *
	 * @var string
	 */
	public const STATUS_UNVERIFIED = '0';

	/**
	 * Status: the verification email link has been clicked (or password reset completed).
	 *
	 * @var string
	 */
	public const STATUS_VERIFIED = '1';

	/**
	 * Status: an administrator granted login access without confirming email delivery.
	 *
	 * @var string
	 */
	public const STATUS_MANUAL = 'manual';

	/**
	 * Returns the raw stored status for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string '' (no meta saved), '0', '1', or 'manual'.
	 */
	public static function get_status( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_STATUS, true );
	}

	/**
	 * Determines whether a user may log in given their current verification status.
	 *
	 * @param int  $user_id               User ID.
	 * @param bool $verification_required Whether the site currently requires email
	 *                                    verification at registration (BM settings
	 *                                    `registration_email_verification_enabled`).
	 * @return bool
	 */
	public static function is_login_allowed( int $user_id, bool $verification_required ): bool {
		$status = self::get_status( $user_id );

		// 保存値が無い場合は、機能導入前の登録ユーザー・管理者作成ユーザーのため従来どおり許可する。
		if ( '' === $status ) {
			return true;
		}

		if ( self::STATUS_VERIFIED === $status || self::STATUS_MANUAL === $status ) {
			return true;
		}

		if ( self::STATUS_UNVERIFIED === $status ) {
			// 論点1（issue #507）: BM設定でメール認証が不要になっている店舗では、
			// 既に '0' のまま止まっている利用者もログインを許可する。
			return ! $verification_required;
		}

		// 想定外の値は安全側に倒して拒否する。
		return false;
	}

	/**
	 * Error code returned when login is stopped because email verification is incomplete.
	 *
	 * issue #519: 標準ログイン画面・独自ログインフォーム・アプリケーションパスワードが
	 * 同じコードで未認証を判別できるようにする。
	 *
	 * @var string
	 */
	public const ERROR_CODE_UNVERIFIED = 'vkbm_unverified_email';

	/**
	 * Key of the verification status inside the unverified-login WP_Error data.
	 *
	 * `status` は REST API が HTTP ステータスとして解釈するため、別名にしている。
	 *
	 * @var string
	 */
	public const ERROR_DATA_STATUS_KEY = 'verification_status';

	/**
	 * Builds the error that stops a login when the authenticated user has not verified their email.
	 *
	 * issue #519: `authenticate` フィルタ（優先度 100）と、アプリケーションパスワードの
	 * 照合時から呼ばれる判定。パスワード照合後（WP_User が渡されたとき）にだけ呼ぶため、
	 * パスワードを間違えた人には未認証かどうかが伝わらない（#194 と同じ観点）。
	 * 予約顧客ではない利用者（管理者・スタッフ）は、メール認証の対象外として止めない。
	 *
	 * @param mixed $user                 `authenticate` フィルタが受け取った値（WP_User / WP_Error / null 等）。
	 * @param bool  $verification_required BM 設定でメール認証が必要か（`registration_email_verification_enabled`）。
	 * @return \WP_Error|null 止める場合はエラー（データに user_id と status）。止めない場合は null。
	 */
	public static function get_unverified_login_error( $user, bool $verification_required ): ?\WP_Error {
		// パスワード照合が済んだ WP_User のときだけ判定する（null / WP_Error はそのまま通す）。
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		// 管理者・スタッフ（予約顧客ではない利用者）は対象外。
		if ( ! Auth_Shortcodes::is_booking_customer( $user ) ) {
			return null;
		}

		// 認証状態 meta 無し・認証済み・手動承認・設定オフの未認証は従来どおり通す。
		if ( self::is_login_allowed( (int) $user->ID, $verification_required ) ) {
			return null;
		}

		return new \WP_Error(
			self::ERROR_CODE_UNVERIFIED,
			__( 'Email verification has not been completed. Please click the link in the registered email to confirm.', 'vk-booking-manager' ),
			array(
				'user_id'                   => (int) $user->ID,
				self::ERROR_DATA_STATUS_KEY => self::get_status( (int) $user->ID ),
			)
		);
	}

	/**
	 * Marks a user as verified via the email confirmation link (or a completed password reset).
	 *
	 * 認証用トークン系メタ（ハッシュ・有効期限・旧仕様の平文トークン）も併せて削除する。
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_verified( int $user_id ): void {
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_VERIFIED );
		delete_user_meta( $user_id, self::META_TOKEN_HASH );
		delete_user_meta( $user_id, self::META_TOKEN_EXPIRES );
		// ハッシュ化前の旧仕様で平文保存された残骸を、ここで併せて削除する。
		delete_user_meta( $user_id, self::META_LEGACY_TOKEN );
		self::clear_resend_tracking( $user_id );
	}

	/**
	 * Clears all resend-related bookkeeping meta (daily cap counter/window, last-sent timestamp).
	 *
	 * 認証済みになった利用者にはもう不要なため mark_verified() から呼ぶ。
	 *
	 * @param int $user_id User ID.
	 */
	public static function clear_resend_tracking( int $user_id ): void {
		delete_user_meta( $user_id, self::META_RESEND_COUNT );
		delete_user_meta( $user_id, self::META_RESEND_WINDOW_START );
		delete_user_meta( $user_id, self::META_RESEND_LAST_SENT );
	}

	/**
	 * Determines whether the user has reached the daily resend limit.
	 *
	 * 安藤さんレビュー指摘（issue #507 PR）: 他人のメールアドレスで登録し、再送を
	 * 繰り返すことで1日あたり大量のメールを送りつけられる問題への対処。
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_reached_daily_resend_limit( int $user_id ): bool {
		$window_start = (int) get_user_meta( $user_id, self::META_RESEND_WINDOW_START, true );

		// ウィンドウ未開始、または24時間を過ぎていれば上限には達していない（次の記録で自然に再開する）。
		if ( $window_start <= 0 || ( time() - $window_start ) >= self::RESEND_DAILY_WINDOW ) {
			return false;
		}

		$count = (int) get_user_meta( $user_id, self::META_RESEND_COUNT, true );

		return $count >= self::RESEND_DAILY_MAX;
	}

	/**
	 * Records a successful resend for the daily limit counter.
	 *
	 * ウィンドウが未開始・期限切れの場合は、この呼び出しを起点に新しいウィンドウを開始する
	 * （固定の暦日ではなく、直近24時間のローリングウィンドウ）。
	 *
	 * @param int $user_id User ID.
	 */
	public static function record_resend( int $user_id ): void {
		$now          = time();
		$window_start = (int) get_user_meta( $user_id, self::META_RESEND_WINDOW_START, true );

		if ( $window_start <= 0 || ( $now - $window_start ) >= self::RESEND_DAILY_WINDOW ) {
			update_user_meta( $user_id, self::META_RESEND_WINDOW_START, $now );
			update_user_meta( $user_id, self::META_RESEND_COUNT, 1 );
			return;
		}

		$count = (int) get_user_meta( $user_id, self::META_RESEND_COUNT, true );
		update_user_meta( $user_id, self::META_RESEND_COUNT, $count + 1 );
	}

	/**
	 * Marks a user as unverified. Used immediately after registration.
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_unverified( int $user_id ): void {
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_UNVERIFIED );
	}

	/**
	 * Grants manual approval: the user can log in, but email delivery is unconfirmed.
	 *
	 * @param int $user_id     User being approved.
	 * @param int $approver_id Administrator performing the approval.
	 */
	public static function mark_manual( int $user_id, int $approver_id ): void {
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_MANUAL );
		update_user_meta( $user_id, self::META_MANUAL_AT, time() );
		update_user_meta( $user_id, self::META_MANUAL_BY, $approver_id );
	}

	/**
	 * Revokes a manual approval, returning the user to the unverified state.
	 *
	 * 論点2（issue #507）: 人違いの承認を戻す手段。
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_manual( int $user_id ): void {
		update_user_meta( $user_id, self::META_STATUS, self::STATUS_UNVERIFIED );
		delete_user_meta( $user_id, self::META_MANUAL_AT );
		delete_user_meta( $user_id, self::META_MANUAL_BY );
	}

	/**
	 * Returns manual approval metadata for display on the user edit screen.
	 *
	 * @param int $user_id User ID.
	 * @return array{at: int, by: int} 未承認の場合は両方 0。
	 */
	public static function get_manual_approval_info( int $user_id ): array {
		return array(
			'at' => (int) get_user_meta( $user_id, self::META_MANUAL_AT, true ),
			'by' => (int) get_user_meta( $user_id, self::META_MANUAL_BY, true ),
		);
	}

	/**
	 * Returns badge info for admin list screens (users list / booking list), or null
	 * when the user's status needs no badge (verified, or no meta saved).
	 *
	 * @param int $user_id User ID.
	 * @return array{label: string, css_class: string}|null
	 */
	public static function get_badge( int $user_id ): ?array {
		$status = self::get_status( $user_id );

		if ( self::STATUS_UNVERIFIED === $status ) {
			return array(
				'label'     => __( 'Unverified', 'vk-booking-manager' ),
				'css_class' => 'vkbm-email-verification-badge--unverified',
			);
		}

		if ( self::STATUS_MANUAL === $status ) {
			return array(
				'label'     => __( 'Email unconfirmed', 'vk-booking-manager' ),
				'css_class' => 'vkbm-email-verification-badge--manual',
			);
		}

		return null;
	}
}
