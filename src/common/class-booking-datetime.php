<?php
/**
 * 予約メタに保存された日時の読み取りを共通化するユーティリティ。
 *
 * インポートや外部連携で入った予約は、秒が無い（Y-m-d H:i）・日付と時刻の区切りが T など、
 * 標準の Y-m-d H:i:s と少し違う形で保存されていることがある。読み取れない予約は空き計算から
 * 外れて二重予約の原因になるため、空き計算・重複判定・泊戦略で同じ規則を使う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 予約日時の正規化・読み取りを提供する、状態を持たない静的ユーティリティクラス。
 */
class Booking_Datetime {

	/**
	 * 予約メタの日時を Y-m-d H:i:s に整える。読み取れなければ空文字。
	 *
	 * 受け付ける形は「Y-m-d H:i」「Y-m-d H:i:s」「Y-m-dTH:i」「Y-m-dTH:i:s」。
	 * 後ろにタイムゾーン表記などが続いていても、続きは無視して日時部分だけを使う。
	 *
	 * @param mixed $value 予約メタの値。
	 * @return string Y-m-d H:i:s。読み取れなければ空文字。
	 */
	public static function normalize( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/', $value, $matches ) ) {
			return '';
		}

		return $matches[1] . ' ' . $matches[2] . ':' . ( $matches[3] ?? '00' );
	}

	/**
	 * 予約メタの日時を DateTimeImmutable に変換する。読み取れなければ false。
	 *
	 * 正規化できる形は正規化した結果で読み取る。できない形は元の文字列を従来どおり
	 * Y-m-d H:i:s で読み取る（桁が揃っていない旧来の形など、これまで読めていた値を落とさないため）。
	 *
	 * @param mixed        $value    予約メタの値。
	 * @param DateTimeZone $timezone 解釈するタイムゾーン。
	 * @return DateTimeImmutable|false
	 */
	public static function parse( $value, DateTimeZone $timezone ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		// 正規化できなければ元の文字列をそのまま読み取りに回す。
		$normalized = self::normalize( $value );
		$source     = '' !== $normalized ? $normalized : $value;

		return DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $source, $timezone );
	}
}
