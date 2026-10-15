<?php
/**
 * 泊単位のプランの料金区分（平日・休前日・特定日）の判定（#737）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeImmutable;
use VKBookingManager\ProviderSettings\Settings_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 泊の日付が、特定日・休前日・平日のどれに当たるかを判定するユーティリティクラス。
 *
 * 設定の保存先は BM設定（vkbm_provider_settings）の次の 2 つ。
 * - lodging_pre_holiday_weekdays: 休前日とする曜日（mon〜sun のキーの配列）。
 * - lodging_special_dates: 特定日の期間 `[ [ 'start' => Y-m-d, 'end' => Y-m-d, 'label' => 名称 ], ... ]`。
 *
 * 判定は日付だけで行い、プランの料金が入力されているかは見ない（空欄時の扱いは Lodging_Price が決める）。
 */
class Lodging_Rate {
	/**
	 * 平日の料金区分。
	 */
	public const RATE_WEEKDAY = 'weekday';

	/**
	 * 休前日の料金区分。
	 */
	public const RATE_PRE_HOLIDAY = 'pre_holiday';

	/**
	 * 特定日の料金区分。
	 */
	public const RATE_SPECIAL = 'special';

	/**
	 * 設定に使う曜日キー（月曜始まり。DateTimeImmutable の 'D' 書式を小文字にした値と同じ）。
	 */
	public const WEEKDAY_KEYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * 特定日の名称の最大文字数。
	 */
	public const SPECIAL_LABEL_MAX_LENGTH = 50;

	/**
	 * 特定日の登録件数の上限（設定の肥大化を防ぐ）。
	 */
	public const SPECIAL_DATES_MAX = 100;

	/**
	 * 休前日とする曜日の生入力を、有効な曜日キーだけの配列に正規化する。
	 *
	 * @param mixed $raw 生入力。曜日キーの配列を想定する。
	 * @return array<int, string> 重複を除き月曜始まりの順に並べた曜日キー。
	 */
	public static function normalize_weekdays( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();
		foreach ( $raw as $value ) {
			if ( is_string( $value ) ) {
				$keys[] = strtolower( trim( $value ) );
			}
		}

		return array_values( array_intersect( self::WEEKDAY_KEYS, $keys ) );
	}

	/**
	 * 特定日の生入力から、有効な行（実在する日付で、終了日が開始日以降）だけを取り出す。
	 *
	 * 開始日・終了日のどちらかが空の行は、入力途中の空行として除く。
	 * 終了日が開始日より前の行は保存時のエラー表示で扱うため、ここでも除く。
	 *
	 * @param mixed $raw 生入力。`[ [ 'start' => ..., 'end' => ..., 'label' => ... ], ... ]` を想定する。
	 * @return array<int, array{start: string, end: string, label: string}> 開始日の昇順に並べた特定日。
	 */
	public static function normalize_special_periods( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$periods = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$start = self::normalize_date( $row['start'] ?? '' );
			$end   = self::normalize_date( $row['end'] ?? '' );
			if ( '' === $start || '' === $end || $end < $start ) {
				continue;
			}

			$periods[] = array(
				'start' => $start,
				'end'   => $end,
				'label' => self::normalize_label( $row['label'] ?? '' ),
			);

			if ( count( $periods ) >= self::SPECIAL_DATES_MAX ) {
				break;
			}
		}

		usort(
			$periods,
			static function ( array $a, array $b ): int {
				return array( $a['start'], $a['end'] ) <=> array( $b['start'], $b['end'] );
			}
		);

		return $periods;
	}

	/**
	 * 特定日の名称を整える（タグ除去・前後の空白除去・最大文字数で切り詰め）。
	 *
	 * @param mixed $value 生入力。
	 * @return string 名称。空なら空文字。
	 */
	public static function normalize_label( $value ): string {
		$label = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );

		return mb_substr( $label, 0, self::SPECIAL_LABEL_MAX_LENGTH );
	}

	/**
	 * Y-m-d の実在する日付だけを返す。
	 *
	 * @param mixed $value 生入力。
	 * @return string Y-m-d 形式の日付。不正・空なら空文字。
	 */
	public static function normalize_date( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $value ) ) ) {
			return '';
		}

		$value = trim( $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * BM設定に保存された休前日の曜日と特定日を返す。
	 *
	 * 休前日の曜日が一度も保存されていないサイトは、土曜のみ（Settings_Repository の既定値）になる。
	 *
	 * @return array{weekdays: array<int, string>, special_periods: array<int, array{start: string, end: string, label: string}>}
	 */
	public static function get_settings(): array {
		$settings = ( new Settings_Repository() )->get_settings();

		return array(
			'weekdays'        => self::normalize_weekdays( $settings['lodging_pre_holiday_weekdays'] ?? array() ),
			'special_periods' => self::normalize_special_periods( $settings['lodging_special_dates'] ?? array() ),
		);
	}

	/**
	 * 泊の日付が、特定日の期間に入るかを返す（開始日・終了日の泊を含む）。
	 *
	 * @param string                                                       $date            泊の日付（チェックインする日。Y-m-d）。
	 * @param array<int, array{start: string, end: string, label: string}> $special_periods 特定日の期間。
	 * @return bool 特定日なら true。
	 */
	public static function is_special_date( string $date, array $special_periods ): bool {
		foreach ( $special_periods as $period ) {
			if ( $date >= (string) ( $period['start'] ?? '' ) && $date <= (string) ( $period['end'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 泊の日付が入る特定日の期間の名称を返す（期間が重なるときは開始日が早いほう）。
	 *
	 * @param string                                                       $date            泊の日付（チェックインする日。Y-m-d）。
	 * @param array<int, array{start: string, end: string, label: string}> $special_periods 特定日の期間。
	 * @return string 名称。特定日でない・名称が空なら空文字。
	 */
	public static function find_special_label( string $date, array $special_periods ): string {
		foreach ( $special_periods as $period ) {
			if ( $date >= (string) ( $period['start'] ?? '' ) && $date <= (string) ( $period['end'] ?? '' ) ) {
				return (string) ( $period['label'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * 泊の日付が、休前日に当たるかを返す。
	 *
	 * 設定で選んだ曜日、または翌日が祝日の日が休前日。祝日データの範囲外の日付は曜日だけで決める
	 * （Holiday_Calendar は範囲外の日付を祝日としないため）。
	 *
	 * @param string            $date     泊の日付（チェックインする日。Y-m-d）。
	 * @param array<int,string> $weekdays 休前日とする曜日キー。
	 * @return bool 休前日なら true。日付が不正なら false。
	 */
	public static function is_pre_holiday_date( string $date, array $weekdays ): bool {
		$day = '' !== self::normalize_date( $date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $date ) : false;
		if ( ! $day ) {
			return false;
		}

		if ( in_array( strtolower( $day->format( 'D' ) ), $weekdays, true ) ) {
			return true;
		}

		return Holiday_Calendar::is_holiday_eve( $date );
	}

	/**
	 * チェックイン日と泊数から、各泊の日付（チェックインする日）を返す。チェックアウト日は含めない。
	 *
	 * @param string $check_in_date チェックイン日（Y-m-d）。
	 * @param int    $nights        泊数。
	 * @return array<int, string> 各泊の日付。日付が不正・泊数が 0 以下なら空配列。
	 */
	public static function build_night_dates( string $check_in_date, int $nights ): array {
		// 2026-13-01 のように繰り上がって別の日付になる値は、実在しない日付として弾く。
		$first = '' !== self::normalize_date( $check_in_date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $check_in_date ) : false;
		if ( ! $first || $nights < 1 ) {
			return array();
		}

		$dates = array();
		for ( $i = 0; $i < $nights; $i++ ) {
			$dates[] = $first->modify( sprintf( '+%d days', $i ) )->format( 'Y-m-d' );
		}

		return $dates;
	}
}
