<?php
/**
 * Email log repository.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles email log persistence.
 */
class Email_Log_Repository {
	public const OPTION_KEY            = 'vkbm_email_logs';
	public const MAX_LOGS              = 100;
	public const LAST_PRUNE_OPTION_KEY = 'vkbm_email_logs_last_pruned';

	// #510: ログの送信結果を表す状態。旧仕様の success(bool) は互換のため残しつつ、
	// 「未送信（宛先が無い・不正で送らなかった）」を区別できるようにする。
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Normalize a stored timestamp into a UTC epoch integer.
	 *
	 * Supports:
	 * - int epoch (preferred)
	 * - numeric string epoch
	 * - legacy local-time mysql string (Y-m-d H:i:s) interpreted in site timezone.
	 *
	 * @param mixed $value Timestamp field value.
	 * @return int|null UTC epoch seconds, or null if unknown.
	 */
	private function normalize_timestamp_to_epoch( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_string( $value ) ) {
			$raw = trim( $value );
			if ( '' === $raw ) {
				return null;
			}

			if ( ctype_digit( $raw ) ) {
				$epoch = (int) $raw;
				return $epoch > 0 ? $epoch : null;
			}

			// Legacy: local-time mysql string (site timezone).
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
			if ( $timezone instanceof \DateTimeZone ) {
				$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, $timezone );
				if ( $dt instanceof \DateTimeImmutable ) {
					return $dt->getTimestamp();
				}
			}

			// Fallback: best-effort parse (uses server timezone).
			$ts = strtotime( $raw );
			return false !== $ts && $ts > 0 ? (int) $ts : null;
		}

		if ( is_float( $value ) ) {
			$epoch = (int) $value;
			return $epoch > 0 ? $epoch : null;
		}

		return null;
	}

	/**
	 * ログエントリを1件追加する。
	 *
	 * #510: 通知の種類・何回目の送信か・予約IDを追加で保存し、成否は
	 * 「送信済み／失敗／未送信」の3状態（$status）で記録する。
	 * `success`（真偽値）は互換のため、$status から導出した値を残す。
	 *
	 * @param string $email        送信先メールアドレス（未送信で宛先が無い場合は空文字）。
	 * @param string $subject      件名（実際に送った、または送るはずだった文字列）。
	 * @param string $status       送信結果（self::STATUS_SENT / STATUS_FAILED / STATUS_SKIPPED）。
	 * @param string $error_info   失敗・未送信の理由（送信済みの場合は空文字）。
	 * @param string $type         通知の種類（例: pending_customer, registration_confirmation）。空文字は種類不明（旧形式のログ用）。
	 * @param int    $attempt      今回が何回目の送信か。再送の概念が無い通知（リマインダー等）は 0。
	 * @param int    $max_attempts 最大送信回数。再送の概念が無い通知は 0。
	 * @param int    $booking_id   紐づく予約の投稿ID。予約に紐づかない通知（会員登録の確認メール等）は 0。
	 * @param string $action_url   エラー欄に添える案内リンクの URL（無ければ空文字）。
	 * @param string $action_label 案内リンクの文言（無ければ空文字）。
	 * @return void
	 */
	public function add_log( string $email, string $subject, string $status, string $error_info = '', string $type = '', int $attempt = 0, int $max_attempts = 0, int $booking_id = 0, string $action_url = '', string $action_label = '' ): void {
		$logs = $this->get_logs();

		$log_entry = array(
			// Store UTC epoch seconds for consistent retention comparisons.
			'timestamp'    => time(),
			'email'        => $email,
			'subject'      => $subject,
			// 互換のため、送信済みかどうかの真偽値も引き続き保存する。
			'success'      => ( self::STATUS_SENT === $status ),
			'status'       => $status,
			'error'        => $error_info,
			'type'         => $type,
			'attempt'      => $attempt,
			'max_attempts' => $max_attempts,
			'booking_id'   => $booking_id,
			// #510: エラー欄の本文とは別に、案内リンク（例: 設定画面への導線）を持たせる。
			'action_url'   => $action_url,
			'action_label' => $action_label,
		);

		array_unshift( $logs, $log_entry );

		// Keep only the most recent logs.
		$logs = array_slice( $logs, 0, self::MAX_LOGS );

		update_option( self::OPTION_KEY, $logs );
	}

	/**
	 * Get all logs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs(): array {
		$logs = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $logs ) ) {
			return array();
		}

		$changed    = false;
		$normalized = array();

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$epoch = $this->normalize_timestamp_to_epoch( $log['timestamp'] ?? null );
			if ( null !== $epoch ) {
				if ( ( $log['timestamp'] ?? null ) !== $epoch ) {
					$log['timestamp'] = $epoch;
					$changed          = true;
				}
			}

			$normalized[] = $log;
		}

		$normalized = array_slice( $normalized, 0, self::MAX_LOGS );

		if ( $changed ) {
			update_option( self::OPTION_KEY, $normalized );
		}

		return $normalized;
	}

	/**
	 * Remove expired logs and persist the remaining entries.
	 *
	 * @param int $retention_days Retention days (minimum 1).
	 */
	public function prune_logs( int $retention_days ): void {
		$retention_days = max( 1, $retention_days );
		$logs           = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $logs ) || array() === $logs ) {
			return;
		}

		$cutoff   = time() - ( $retention_days * DAY_IN_SECONDS );
		$filtered = array();

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$timestamp = $this->normalize_timestamp_to_epoch( $log['timestamp'] ?? null );

			// If timestamp cannot be parsed, keep the entry (fail-safe).
			if ( null !== $timestamp && $timestamp < $cutoff ) {
				continue;
			}

			if ( null !== $timestamp ) {
				$log['timestamp'] = $timestamp;
			}

			$filtered[] = $log;
		}

		$filtered = array_slice( $filtered, 0, self::MAX_LOGS );

		if ( $filtered === $logs ) {
			return;
		}

		update_option( self::OPTION_KEY, $filtered );
	}

	/**
	 * Prune logs at most once per day.
	 *
	 * @param int $retention_days Retention days (minimum 1).
	 */
	public function maybe_prune_logs( int $retention_days ): void {
		$last_run = (int) get_option( self::LAST_PRUNE_OPTION_KEY, 0 );

		if ( $last_run > 0 && ( time() - $last_run ) < DAY_IN_SECONDS ) {
			return;
		}

		$this->prune_logs( $retention_days );
		update_option( self::LAST_PRUNE_OPTION_KEY, time() );
	}

	/**
	 * Clear all logs.
	 *
	 * @return void
	 */
	public function clear_logs(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::LAST_PRUNE_OPTION_KEY );
	}
}
