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
	 * Add a log entry.
	 *
	 * @param string $email      Recipient email address.
	 * @param string $subject    Email subject.
	 * @param bool   $success    Whether the email was sent successfully.
	 * @param string $error_info Error information if failed.
	 * @return void
	 */
	public function add_log( string $email, string $subject, bool $success, string $error_info = '' ): void {
		$logs = $this->get_logs();

		$log_entry = array(
			// Store UTC epoch seconds for consistent retention comparisons.
			'timestamp' => time(),
			'email'     => $email,
			'subject'   => $subject,
			'success'   => $success,
			'error'     => $error_info,
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
