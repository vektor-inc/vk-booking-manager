<?php
/**
 * 国民の祝日の判定ユーティリティ。
 *
 * 内閣府「国民の祝日」CSV から生成した同梱データ（src/common/data/holidays-jp.php）を使い、
 * 祝日・祝日前日・祝日名・データの対応期間を返す。データは年 1 回、
 * bin/build-holidays.js で更新する（手順は docs/specification-operation-owner.md を参照）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeImmutable;

use function apply_filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 祝日判定を提供する、状態を持たない静的ユーティリティクラス。
 * 判定は Y-m-d 文字列の照合のみで行うため、タイムゾーンの影響を受けない。
 */
class Holiday_Calendar {
	/**
	 * リクエスト内の祝日データのキャッシュ（Y-m-d => 祝日名）。null は未読込。
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $data = null;

	/**
	 * 指定した日が祝日（振替休日を含む）かを返す。
	 *
	 * @param string $ymd Y-m-d 形式の日付。
	 * @return bool 祝日なら true。不正な日付・データ範囲外は false。
	 */
	public static function is_holiday( string $ymd ): bool {
		return null !== self::get_name( $ymd );
	}

	/**
	 * 指定した日の翌日が祝日かを返す（翌日が日曜というだけでは祝日前日にしない。日曜を休日とみなさない）。
	 *
	 * @param string $ymd Y-m-d 形式の日付。
	 * @return bool 翌日が祝日なら true。不正な日付・データ範囲外は false。
	 */
	public static function is_holiday_eve( string $ymd ): bool {
		$date = self::parse_ymd( $ymd );
		if ( null === $date ) {
			return false;
		}

		// 年またぎ（12/31 の翌日 1/1 など）も DateTimeImmutable で解決する。
		return self::is_holiday( $date->modify( '+1 day' )->format( 'Y-m-d' ) );
	}

	/**
	 * 指定した日の祝日名を返す。
	 *
	 * @param string $ymd Y-m-d 形式の日付。
	 * @return string|null 祝日名。祝日でない・不正な日付・データ範囲外は null。
	 */
	public static function get_name( string $ymd ): ?string {
		$date = self::parse_ymd( $ymd );
		if ( null === $date ) {
			return null;
		}

		$data = self::get_data();

		return $data[ $date->format( 'Y-m-d' ) ] ?? null;
	}

	/**
	 * 同梱データが対応している最終日（データ最終年の 12/31）を返す。
	 * 最後の祝日の日付ではなく年末にするのは、最後の祝日以降の日付（12 月など）を
	 * 「範囲外」と誤認しないため。
	 *
	 * @return string|null Y-m-d 形式の日付。データが空なら null。
	 */
	public static function get_covered_until(): ?string {
		$data = self::get_data();
		if ( array() === $data ) {
			return null;
		}

		$last_date = array_key_last( $data );

		return substr( (string) $last_date, 0, 4 ) . '-12-31';
	}

	/**
	 * リクエスト内キャッシュを破棄する（テスト用）。
	 */
	public static function reset_cache(): void {
		self::$data = null;
	}

	/**
	 * 祝日データを読み込む（リクエスト内で 1 回だけ）。
	 * フィルタ vkbm_holiday_calendar_data で差し替えられる。
	 *
	 * @return array<string, string> Y-m-d => 祝日名。
	 */
	private static function get_data(): array {
		if ( null !== self::$data ) {
			return self::$data;
		}

		$data = require __DIR__ . '/data/holidays-jp.php';

		/**
		 * 祝日データを差し替える。
		 *
		 * @param array<string, string> $data Y-m-d => 祝日名の配列（日付昇順）。
		 */
		$filtered = apply_filters( 'vkbm_holiday_calendar_data', $data );

		self::$data = self::normalize_data( $filtered );

		return self::$data;
	}

	/**
	 * フィルタ後の祝日データを、判定に使える形へ整える。
	 * キーが Y-m-d として有効で、値が空でないスカラーの項目だけを残し、値を文字列にして日付順に並べる。
	 *
	 * @param mixed $data フィルタの戻り値。
	 * @return array<string, string> Y-m-d => 祝日名（日付昇順）。
	 */
	private static function normalize_data( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $data as $ymd => $name ) {
			// 0 始まりの番号配列など、キーが日付でない項目は捨てる（整数キーは文字列にして検査する）。
			if ( null === self::parse_ymd( (string) $ymd ) ) {
				continue;
			}
			if ( ! is_scalar( $name ) || '' === trim( (string) $name ) ) {
				continue;
			}

			$normalized[ (string) $ymd ] = (string) $name;
		}

		// 末尾が最終年になるよう日付順にそろえる。
		ksort( $normalized );

		return $normalized;
	}

	/**
	 * Y-m-d 形式の文字列を厳密に日付へ変換する。
	 *
	 * @param string $ymd Y-m-d 形式の日付。
	 * @return DateTimeImmutable|null 変換結果。形式違い・存在しない日付は null。
	 */
	private static function parse_ymd( string $ymd ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}

		return $date;
	}
}
