<?php
/**
 * 予約可能日（予約可能な曜日種別）の判定・ラベル生成を担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeInterface;
use DateTimeImmutable;

use function __;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 予約可能日種別（_vkbm_reservation_day_type）に関する判定を集約したユーティリティクラス。
 *
 * 種別は次のいずれか。
 * - ''               指定なし（すべての曜日を許可）
 * - 'weekend'        土日限定
 * - 'weekday'        平日限定
 * - 'custom_weekday' 曜日指定（頻度 × 曜日の行追加式。毎週◯曜・第N◯曜）
 * - 'custom_date'    日付指定（単日・期間の行追加式）
 *
 * フロント予約カレンダー・予約確定時のサーバー側検証・カード表示ラベルの3か所で
 * 同一の判定・写像が重複していたため、状態を持たない純粋な静的メソッドとして集約する。
 *
 * '' / weekend / weekday は「曜日番号（N）」だけで判定できるため is_weekday_allowed() の
 * 経路を維持する（挙動不変）。一方 custom_weekday の第N曜日や custom_date は実際の日付が
 * 必要になるため、日付ベースの判定は is_date_allowed() に集約する。日付のパース経路
 * （Y-m-d + DateTimeZone / ISO8601 + wp_date）は呼び出し箇所ごとに異なるため、各呼び出し箇所で
 * DateTimeInterface を組み立てたうえで本クラスへ渡す。
 */
class Reservation_Day {
	/**
	 * 予約可能日種別として許容される値の一覧。
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_TYPES = array( '', 'weekend', 'weekday', 'custom_weekday', 'custom_date' );

	/**
	 * 曜日指定（頻度 × 曜日）の詳細設定を保存する post meta キー。
	 *
	 * サービスメニュー編集・クイック編集・空き状況・予約確定検証の各所で参照する。
	 * キー名の唯一の定義元（single source of truth）とし、各クラスは本定数を参照する。
	 *
	 * @var string
	 */
	public const META_CUSTOM_WEEKDAYS = '_vkbm_reservation_custom_weekdays';

	/**
	 * 日付指定（単日・期間）の詳細設定を保存する post meta キー。
	 *
	 * @var string
	 */
	public const META_CUSTOM_DATES = '_vkbm_reservation_custom_dates';

	/**
	 * 指定なし（すべての曜日を許可）を表す種別値。
	 *
	 * @var string
	 */
	public const TYPE_NONE = '';

	/**
	 * 土日限定を表す種別値。
	 *
	 * @var string
	 */
	public const TYPE_WEEKEND = 'weekend';

	/**
	 * 平日限定を表す種別値。
	 *
	 * @var string
	 */
	public const TYPE_WEEKDAY = 'weekday';

	/**
	 * 曜日指定（頻度 × 曜日）を表す種別値。
	 *
	 * @var string
	 */
	public const TYPE_CUSTOM_WEEKDAY = 'custom_weekday';

	/**
	 * 日付指定（単日・期間）を表す種別値。
	 *
	 * @var string
	 */
	public const TYPE_CUSTOM_DATE = 'custom_date';

	/**
	 * 予約可能日種別を許容値に正規化する。
	 *
	 * 許容外の値（未知の種別）は指定なし（''）へ丸める。読み込んだ post meta の
	 * 値をそのまま信用せずに正規化する用途を想定する。
	 *
	 * @param string $reservation_day_type 予約可能日種別。
	 * @return string 許容値のいずれか（許容外は ''）。
	 */
	public static function sanitize_type( string $reservation_day_type ): string {
		return in_array( $reservation_day_type, self::ALLOWED_TYPES, true ) ? $reservation_day_type : self::TYPE_NONE;
	}

	/**
	 * 曜日番号に対して、種別が予約を許可するかどうかを判定する。
	 *
	 * 曜日番号は ISO-8601 形式の N（1=月曜 〜 7=日曜、date('N') と同じ）を想定する。
	 * 各呼び出し箇所は自身のパース経路（タイムゾーン・パース失敗時のフォールバック）で
	 * 曜日を算出したうえで本メソッドに渡すことで、既存挙動を維持したまま判定を共有できる。
	 *
	 * @param string $reservation_day_type 予約可能日種別（''|weekend|weekday）。
	 * @param int    $weekday              曜日番号（N: 1=月曜 〜 7=日曜）。
	 * @return bool 予約可能なら true、不可なら false。
	 */
	public static function is_weekday_allowed( string $reservation_day_type, int $weekday ): bool {
		// 指定なしはすべての曜日を許可する。
		if ( self::TYPE_NONE === $reservation_day_type ) {
			return true;
		}

		// 土日判定（6=土曜、7=日曜）。
		$is_weekend = ( 6 === $weekday || 7 === $weekday );

		if ( self::TYPE_WEEKEND === $reservation_day_type ) {
			return $is_weekend;
		}

		if ( self::TYPE_WEEKDAY === $reservation_day_type ) {
			return ! $is_weekend;
		}

		// 未知の種別は既存挙動どおり許可（フォールバック）。
		return true;
	}

	/**
	 * 実際の日付に対して、種別が予約を許可するかどうかを判定する。
	 *
	 * '' / weekend / weekday は曜日番号だけで判定できるため is_weekday_allowed() へ委譲する
	 * （挙動不変）。custom_weekday は「頻度 × 曜日」ルール、custom_date は「単日・期間」ルールを
	 * 実際の日付と突き合わせて判定する。
	 *
	 * custom_weekday / custom_date で設定（$config）が空の場合は「常に予約不可（false）」を返す。
	 * これは保存時の空選択防止（Service_Menu_Editor 側で種別を指定なしへ戻す）をすり抜けた
	 * 不整合データに対する防御的挙動で、誤って全曜日を許可してしまうより安全側に倒す。
	 *
	 * @param string            $reservation_day_type 予約可能日種別。
	 * @param DateTimeInterface $date                 判定対象の日付（呼び出し箇所でタイムゾーンを解決済み）。
	 * @param array             $config               custom 種別用の設定。
	 *                                                custom_weekday: array{weekdays: array<int, array{frequency: string, weekday: string}>}
	 *                                                custom_date:    array{dates: array<int, array<string, string>>}
	 * @return bool 予約可能なら true。
	 */
	public static function is_date_allowed( string $reservation_day_type, DateTimeInterface $date, array $config = array() ): bool {
		// 曜日指定（頻度 × 曜日）は共有ユーティリティで判定する。
		if ( self::TYPE_CUSTOM_WEEKDAY === $reservation_day_type ) {
			$rules = ( isset( $config['weekdays'] ) && is_array( $config['weekdays'] ) ) ? $config['weekdays'] : array();
			if ( empty( $rules ) ) {
				return false;
			}
			return Weekday_Rule::matches( $date, $rules );
		}

		// 日付指定（単日・期間）は日付の突き合わせで判定する。
		if ( self::TYPE_CUSTOM_DATE === $reservation_day_type ) {
			$dates = ( isset( $config['dates'] ) && is_array( $config['dates'] ) ) ? $config['dates'] : array();
			if ( empty( $dates ) ) {
				return false;
			}
			return self::date_matches_custom_dates( $date, $dates );
		}

		// '' / weekend / weekday は従来どおり曜日番号ベースで判定する（挙動不変）。
		return self::is_weekday_allowed( $reservation_day_type, (int) $date->format( 'N' ) );
	}

	/**
	 * 日付指定（単日・期間）のルール配列に、対象日が該当するかどうかを判定する。
	 *
	 * @param DateTimeInterface $date  判定対象の日付。
	 * @param array             $dates 単日・期間ルールの配列。
	 * @return bool いずれかのルールに該当すれば true。
	 */
	private static function date_matches_custom_dates( DateTimeInterface $date, array $dates ): bool {
		// Y-m-d 文字列は辞書順比較が日付順と一致するため、文字列比較で範囲判定できる。
		$ymd = $date->format( 'Y-m-d' );

		foreach ( $dates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = (string) ( $row['type'] ?? '' );

			if ( 'single' === $type ) {
				$single = (string) ( $row['date'] ?? '' );
				if ( '' !== $single && $single === $ymd ) {
					return true;
				}
			} elseif ( 'range' === $type ) {
				$start = (string) ( $row['start'] ?? '' );
				$end   = (string) ( $row['end'] ?? '' );
				if ( '' !== $start && '' !== $end && $ymd >= $start && $ymd <= $end ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * 日付指定（単日・期間）の入力配列をサニタイズする。
	 *
	 * 各行は次のいずれかに正規化する。許容外・妥当でない行は破棄する。
	 * - 単日: array{type: 'single', date: 'Y-m-d'}
	 * - 期間: array{type: 'range', start: 'Y-m-d', end: 'Y-m-d'}（start <= end）
	 *
	 * $today（Y-m-d）を渡すと、過去日のみの行（単日は date < today、期間は end < today）を
	 * 破棄する。過去日だけの指定は将来の予約を1件も許可できず「常に予約不可」の誤設定に
	 * つながるため、保存前に取り除く。
	 *
	 * @param mixed       $raw   フォーム等から受け取った生の入力配列。
	 * @param string|null $today 過去日除外の基準日（Y-m-d）。null の場合は過去日除外を行わない。
	 * @return array<int, array<string, string>> 正規化済みの行配列。
	 */
	public static function sanitize_custom_dates( $raw, ?string $today = null ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$result = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = (string) ( $row['type'] ?? '' );

			if ( 'single' === $type ) {
				$single = self::normalize_ymd( (string) ( $row['date'] ?? '' ) );
				if ( '' === $single ) {
					continue;
				}
				// 過去日の単日指定は破棄する。
				if ( null !== $today && $single < $today ) {
					continue;
				}
				$result[] = array(
					'type' => 'single',
					'date' => $single,
				);
			} elseif ( 'range' === $type ) {
				$start = self::normalize_ymd( (string) ( $row['start'] ?? '' ) );
				$end   = self::normalize_ymd( (string) ( $row['end'] ?? '' ) );
				// 開始・終了のどちらかが不正、または start > end の期間は破棄する。
				if ( '' === $start || '' === $end || $start > $end ) {
					continue;
				}
				// 期間全体が過去（終了日が基準日より前）の行は破棄する。
				if ( null !== $today && $end < $today ) {
					continue;
				}
				$result[] = array(
					'type'  => 'range',
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		return array_values( $result );
	}

	/**
	 * 文字列が妥当な Y-m-d 形式かどうかを検証し、正規化した Y-m-d を返す。
	 *
	 * createFromFormat で解析したうえで、往復（再フォーマット）が入力と一致することを
	 * 確認して「2026-02-30」のような存在しない日付を弾く。妥当でない場合は空文字を返す。
	 *
	 * @param string $value 入力文字列。
	 * @return string 妥当なら Y-m-d、そうでなければ空文字。
	 */
	private static function normalize_ymd( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// 先頭の「!」で時刻要素を0に固定し、日付部分だけを厳密に解析する。
		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( ! $datetime instanceof DateTimeImmutable ) {
			return '';
		}

		// 再フォーマットが入力と一致しなければ、存在しない日付とみなして弾く。
		return $datetime->format( 'Y-m-d' ) === $value ? $value : '';
	}

	/**
	 * 予約可能日種別を表示用ラベルへ変換する。
	 *
	 * 既知の種別は翻訳済みラベルを返し、未知の値は入力値をそのまま返す
	 * （既存のカード表示挙動を維持するため）。
	 *
	 * @param string $reservation_day_type 予約可能日種別。
	 * @return string 表示用ラベル。
	 */
	public static function label( string $reservation_day_type ): string {
		if ( self::TYPE_WEEKEND === $reservation_day_type ) {
			return __( 'Saturdays and Sundays only', 'vk-booking-manager' );
		}

		if ( self::TYPE_WEEKDAY === $reservation_day_type ) {
			return __( 'Weekdays only', 'vk-booking-manager' );
		}

		if ( self::TYPE_CUSTOM_WEEKDAY === $reservation_day_type ) {
			return __( 'Specified days of the week', 'vk-booking-manager' );
		}

		if ( self::TYPE_CUSTOM_DATE === $reservation_day_type ) {
			return __( 'Specified dates', 'vk-booking-manager' );
		}

		// 既知の種別以外は値そのものをラベルとして返す。
		return $reservation_day_type;
	}
}
