<?php
/**
 * 予約の内容から Google カレンダーの予定（イベント）の中身を組み立てるクラス。
 *
 * issue #476（親 issue #94: Google カレンダー連携）。
 *
 * ## 通信を持たない理由
 * ここでは Google・WordPress の DB へは一切アクセスせず、渡された値だけから配列を組み立てる
 * 純粋な処理にしている。値の取得（投稿・メタの読み出し）は呼び出し側（
 * {@see Google_Calendar_Event_Sync}）が担い、このクラスは「入力値 → Google Calendar API の
 * イベント表現」の変換だけに責務を絞ることで、通信せずに単体テストできるようにしている
 * （`Google_Calendar_Api_Client::normalize_calendar_list()` と同じ考え方）。
 *
 * ## タイトルの付け方（司の decision record・植草案）
 * - 確定済み（`pending` 以外）: 「メニュー名」
 * - 仮予約（`pending`）: 「【仮】メニュー名」。確定したら同じ予定（同じ Google イベントID）を
 *   書き換える（新しく作らない。重複した予定が残るのを防ぐため）。書き換えは
 *   {@see Google_Calendar_Event_Sync} 側の責務で、このクラスはタイトル文字列を出し分けるだけ。
 *
 * ## 予定に載せる情報（チェックボックスで選べる項目）
 * `$enabled_fields` に含まれる項目だけを説明欄（description）へ出す。項目の定義・初期値は
 * {@see Google_Calendar_Event_Sync_Settings} を参照。管理用メモ（内部メモ）はそもそも
 * 選択肢に無いため、このクラスへ渡す引数にも含めていない。
 *
 * ## `status` を常に `confirmed` で明示する（安藤レビュー指摘）
 * キャンセル→確定や、ゴミ箱からの復元のときは、{@see Google_Calendar_Event_Sync} が
 * 「決定的な予定 ID」に対して update（PATCH 相当）を送る。Google 側でその予定が既に
 * `cancelled`（削除済み）になっている場合、送信する本文に `status` が無いと Google が
 * 既存の `status` を保ったままと解釈し、予定が復活しないおそれがある。そのため、
 * 送信するたびに `status: confirmed` を明示し、キャンセル済みだった予定も確実に戻す。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function __;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_replace;
use function trim;

/**
 * 予約の内容から Google カレンダーのイベント表現を組み立てるクラス。
 */
class Google_Calendar_Event_Builder {

	/**
	 * 予約ステータス（メタ値）。Booking_Admin 等と同じ文字列を使う。
	 *
	 * @var string
	 */
	private const STATUS_PENDING = 'pending';

	/**
	 * Google Calendar API のイベント表現を組み立てる。
	 *
	 * $booking に含めるキー: status（予約ステータス）・service_name（メニュー名）・
	 * start／end（開始・終了日時、`Y-m-d H:i:s`）・guests（人数）・resource_name（担当者名）・
	 * customer_name／customer_tel／customer_email／customer_note（顧客情報）・
	 * admin_edit_url（予約管理画面のURL）・resource_label（担当の呼び名）・
	 * timezone（IANA タイムゾーン名）・booking_id（予約投稿ID）。
	 *
	 * @param array<string, mixed> $booking        予約の内容（呼び出し側が投稿・メタから組み立てた値。上記キー一覧参照）。
	 * @param array<int, string>   $enabled_fields 説明欄に載せる項目キーの一覧（{@see Google_Calendar_Event_Sync_Settings::FIELD_*}）。
	 * @return array<string, mixed>|null イベント表現。開始・終了日時が無い等、予定を組み立てられない場合は null。
	 */
	public static function build( array $booking, array $enabled_fields ): ?array {
		$start = (string) ( $booking['start'] ?? '' );
		$end   = (string) ( $booking['end'] ?? '' );

		if ( '' === $start || '' === $end ) {
			return null;
		}

		$timezone = '' !== (string) ( $booking['timezone'] ?? '' ) ? (string) $booking['timezone'] : 'UTC';

		return array(
			// キャンセル→確定・ゴミ箱からの復元で、Google 側が既に `cancelled` にしている
			// 同じ ID の予定へ update を送るとき、`status` を省くと復活しないおそれがある
			// ため、常に明示する（安藤レビュー指摘。クラスのドキュメントコメント参照）。
			'status'             => 'confirmed',
			'summary'            => self::build_title( $booking ),
			'description'        => self::build_description( $booking, $enabled_fields ),
			'start'              => self::build_datetime_block( $start, $timezone ),
			'end'                => self::build_datetime_block( $end, $timezone ),
			// 予約の混入経路を追跡できるよう、Google 側の非公開プロパティへ予約投稿IDを持たせる。
			// 画面には出ない値（Google Calendar API のプライベート拡張プロパティ）。
			'extendedProperties' => array(
				'private' => array(
					'vkbm_booking_id' => (string) ( $booking['booking_id'] ?? 0 ),
				),
			),
		);
	}

	/**
	 * 予定のタイトルを組み立てる。
	 *
	 * @param array<string, mixed> $booking 予約の内容。
	 * @return string タイトル。
	 */
	private static function build_title( array $booking ): string {
		$service_name = trim( (string) ( $booking['service_name'] ?? '' ) );
		if ( '' === $service_name ) {
			$service_name = __( 'Reservation', 'vk-booking-manager' );
		}

		if ( self::STATUS_PENDING === (string) ( $booking['status'] ?? '' ) ) {
			/* translators: %s: サービスメニュー名。 */
			return sprintf( __( '[Tentative] %s', 'vk-booking-manager' ), $service_name );
		}

		return $service_name;
	}

	/**
	 * 予定の説明欄を組み立てる。
	 *
	 * オンになっている項目だけを行として積み、改行でつなぐ。
	 *
	 * @param array<string, mixed> $booking        予約の内容。
	 * @param array<int, string>   $enabled_fields 説明欄に載せる項目キーの一覧。
	 * @return string 説明欄。
	 */
	private static function build_description( array $booking, array $enabled_fields ): string {
		$lines = array();

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_GUESTS, $enabled_fields, true ) ) {
			/* translators: %d: 予約人数。 */
			$lines[] = sprintf( __( 'Number of guests: %d', 'vk-booking-manager' ), (int) ( $booking['guests'] ?? 0 ) );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_STAFF, $enabled_fields, true ) ) {
			$resource_label = '' !== (string) ( $booking['resource_label'] ?? '' ) ? (string) $booking['resource_label'] : __( 'Staff', 'vk-booking-manager' );
			$resource_name  = (string) ( $booking['resource_name'] ?? '' );
			/* translators: 1: 担当の呼び名（設定で変更可）, 2: 担当者名。 */
			$lines[] = sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), $resource_label, $resource_name );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_ADMIN_LINK, $enabled_fields, true ) && '' !== (string) ( $booking['admin_edit_url'] ?? '' ) ) {
			/* translators: %s: 予約管理画面へのURL。 */
			$lines[] = sprintf( __( 'Reservation management: %s', 'vk-booking-manager' ), (string) $booking['admin_edit_url'] );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NAME, $enabled_fields, true ) && '' !== (string) ( $booking['customer_name'] ?? '' ) ) {
			/* translators: %s: お客様の氏名。 */
			$lines[] = sprintf( __( 'Customer name: %s', 'vk-booking-manager' ), (string) $booking['customer_name'] );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_TEL, $enabled_fields, true ) && '' !== (string) ( $booking['customer_tel'] ?? '' ) ) {
			/* translators: %s: お客様の電話番号。 */
			$lines[] = sprintf( __( 'Phone number: %s', 'vk-booking-manager' ), (string) $booking['customer_tel'] );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_EMAIL, $enabled_fields, true ) && '' !== (string) ( $booking['customer_email'] ?? '' ) ) {
			/* translators: %s: お客様のメールアドレス。 */
			$lines[] = sprintf( __( 'Email address: %s', 'vk-booking-manager' ), (string) $booking['customer_email'] );
		}

		if ( in_array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_NOTE, $enabled_fields, true ) && '' !== (string) ( $booking['customer_note'] ?? '' ) ) {
			/* translators: %s: お客様からのメモ・ご要望。 */
			$lines[] = sprintf( __( 'Notes from the customer: %s', 'vk-booking-manager' ), (string) $booking['customer_note'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * `start`/`end` に入れる `dateTime`（＋必要なら `timeZone`）の組を作る。
	 *
	 * Google Calendar API は `dateTime` にオフセット無しの日時と、別項目の `timeZone`
	 * （IANA タイムゾーン名、例: `Asia/Tokyo`）を組み合わせて受け付ける。ただし WordPress の
	 * 「サイトのタイムゾーン」設定が「都市を選ぶ」ではなく「UTC±オフセットを直接入力する」
	 * 形式（`wp_timezone()->getName()` が `+09:00` のような文字列を返す）の場合、Google は
	 * この文字列を `timeZone`（IANA 名）として受け付けず、同期がすべて失敗する
	 * （安藤レビュー指摘）。この場合は `timeZone` を送らず、`dateTime` 自体にオフセットを
	 * 直接埋め込む（`2026-10-01T10:00:00+09:00` のような RFC3339 形式）。
	 *
	 * @param string $value    `Y-m-d H:i:s` 形式の日時文字列。
	 * @param string $timezone `wp_timezone()->getName()` の値（IANA 名、または `+09:00` 形式のオフセット）。
	 * @return array{dateTime:string, timeZone?:string}
	 */
	private static function build_datetime_block( string $value, string $timezone ): array {
		$local = str_replace( ' ', 'T', trim( $value ) );

		if ( self::is_utc_offset( $timezone ) ) {
			return array( 'dateTime' => $local . $timezone );
		}

		return array(
			'dateTime' => $local,
			'timeZone' => $timezone,
		);
	}

	/**
	 * `wp_timezone()->getName()` の値が、IANA タイムゾーン名ではなく `+09:00` のような
	 * UTC オフセット直接指定かどうかを返す。
	 *
	 * @param string $timezone 判定対象の文字列。
	 * @return bool オフセット直接指定なら true。
	 */
	private static function is_utc_offset( string $timezone ): bool {
		return 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone );
	}
}
