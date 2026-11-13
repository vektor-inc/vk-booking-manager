<?php
/**
 * サービスメニューの提供期間（開始日〜終了日）の整形・判定・表示ラベル生成を担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeImmutable;

use function __;
use function _x;
use function checkdate;
use function get_option;
use function get_post_meta;
use function preg_match;
use function sprintf;
use function wp_date;
use function wp_timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * サービスメニューの提供期間（_vkbm_service_period_start / _vkbm_service_period_end）に関する処理を集約したクラス。
 *
 * 提供期間は1メニューにつき1つだけ。値は Y-m-d の文字列で、空文字はその側が無期限を表す。
 * 開始日・終了日とも当日を含む。日付の判定はサイトのタイムゾーン（WordPress の設定）で行う。
 * 既存の予約可能日（Reservation_Day）の外側にかかる枠で、両方を満たす日だけが予約できる。
 *
 * 状態を持たない静的メソッドだけで構成し、日付の比較はすべて Y-m-d 文字列の辞書順比較で行う。
 */
class Service_Period {
	/**
	 * 提供期間の開始日を保存する post meta キー。
	 *
	 * @var string
	 */
	public const META_START = '_vkbm_service_period_start';

	/**
	 * 提供期間の終了日を保存する post meta キー。
	 *
	 * @var string
	 */
	public const META_END = '_vkbm_service_period_end';

	/**
	 * 提供期間モードの投稿メタキー。
	 *
	 * @var string
	 */
	public const META_MODE = '_vkbm_service_period_mode';

	/**
	 * メニューの有効な提供期間モードを返す。
	 *
	 * @param int $menu_id メニューID。
	 * @return string range または single。
	 */
	public static function get_mode( int $menu_id ): string {
		if ( \VKBookingManager\PostTypes\Service_Menu_Post_Type::is_night_unit( $menu_id ) ) {
			return 'range';
		}
		return 'single' === get_post_meta( $menu_id, self::META_MODE, true ) ? 'single' : 'range';
	}

	/**
	 * 提供期間モードの保存値を正規化する。
	 *
	 * @param mixed $value 保存値。
	 * @return string range または single。
	 */
	public static function sanitize_mode( $value ): string {
		return 'single' === $value ? 'single' : 'range';
	}

	/**
	 * 値を、実在する Y-m-d の日付文字列へ整える。
	 *
	 * 日付として読めない値（空・不正な書式・存在しない日付）は空文字（無期限）にする。
	 *
	 * @param mixed $value 入力値。
	 * @return string 実在する Y-m-d。読めない場合は空文字。
	 */
	public static function sanitize_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
	}

	/**
	 * 開始日が終了日より後（逆転）かを返す。
	 *
	 * どちらかが空（無期限）のときは逆転しない。
	 *
	 * @param string $start 開始日（Y-m-d。空文字は無期限）。
	 * @param string $end   終了日（Y-m-d。空文字は無期限）。
	 * @return bool 逆転していれば true。
	 */
	public static function is_reversed( string $start, string $end ): bool {
		return '' !== $start && '' !== $end && $start > $end;
	}

	/**
	 * 日付が提供期間の中（両端を含む）かを返す。
	 *
	 * @param string $date  判定する日付（Y-m-d）。
	 * @param string $start 開始日（Y-m-d。空文字は無期限）。
	 * @param string $end   終了日（Y-m-d。空文字は無期限）。
	 * @return bool 期間内（または期間の指定なし）なら true。
	 */
	public static function is_date_within( string $date, string $start, string $end ): bool {
		if ( '' !== $start && $date < $start ) {
			return false;
		}

		return '' === $end || $date <= $end;
	}

	/**
	 * 提供期間が終了済み（終了日が今日より前）かを返す。
	 *
	 * @param string $end   終了日（Y-m-d。空文字は無期限）。
	 * @param string $today 今日の日付（Y-m-d。サイトのタイムゾーン）。
	 * @return bool 終了済みなら true。終了日が無い場合は false。
	 */
	public static function is_ended( string $end, string $today ): bool {
		return '' !== $end && '' !== $today && $end < $today;
	}

	/**
	 * サイトのタイムゾーンでの今日の日付を返す。
	 *
	 * @return string 今日の日付（Y-m-d）。
	 */
	public static function get_today(): string {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * メニューに保存されている提供期間を返す。
	 *
	 * 保存値が日付として読めない場合は、その側を無期限（空文字）として扱う。
	 *
	 * @param int $menu_id サービスメニューの投稿 ID。
	 * @return array{start: string, end: string} 開始日と終了日（Y-m-d。空文字は無期限）。
	 */
	public static function get_period( int $menu_id ): array {
		return array(
			'start' => self::sanitize_date( get_post_meta( $menu_id, self::META_START, true ) ),
			'end'   => self::sanitize_date( get_post_meta( $menu_id, self::META_END, true ) ),
		);
	}

	/**
	 * 提供期間が終了済みのメニューを除くための meta_query を返す。
	 *
	 * 終了日が未設定・空、または今日以降のメニューだけを残す。
	 *
	 * @param string $today 今日の日付（Y-m-d。サイトのタイムゾーン）。
	 * @return array<int|string, mixed> WP_Query の meta_query に追加する条件（1つの OR グループ）。
	 */
	public static function build_not_ended_meta_query( string $today ): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META_END,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_END,
				'value'   => '',
				'compare' => '=',
			),
			array(
				'key'     => self::META_END,
				'value'   => $today,
				'compare' => '>=',
				'type'    => 'CHAR',
			),
		);
	}

	/**
	 * 開催日を曜日付きで表示する。
	 *
	 * @param string $date 開催日。
	 * @param bool   $short 年を省くか。
	 * @return string 表示用の日付。
	 */
	public static function format_single_date( string $date, bool $short = false ): string {
		if ( '' === self::sanitize_date( $date ) ) {
			return '';
		}
		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $datetime ) {
			return '';
		}
		$format = $short ? _x( 'M j (D)', 'short event date format', 'vk-booking-manager' ) : _x( 'M j, Y (D)', 'event date format', 'vk-booking-manager' );
		return wp_date( $format, $datetime->getTimestamp() );
	}

	/**
	 * 提供期間を、画面に出す文言にする。
	 *
	 * 日付はサイトの日付書式（設定 > 一般）で表す。片側だけの指定は「◯◯から」「◯◯まで」にする。
	 * 両方とも空のときは空文字を返す。
	 *
	 * @param string $start 開始日（Y-m-d。空文字は無期限）。
	 * @param string $end   終了日（Y-m-d。空文字は無期限）。
	 * @return string 表示用の文言（プレーンテキスト）。期間の指定がなければ空文字。
	 */
	public static function format_label( string $start, string $end ): string {
		return self::build_label(
			$start,
			$end,
			static function ( string $date_text ): string {
				return $date_text;
			}
		);
	}

	/**
	 * 提供期間を、画面に出す文言にする（日付ごとに折り返さない塊にした HTML 版）。
	 *
	 * 文言は format_label() と同じ。各日付を .vkbm-service-period__date の要素で包み、幅が狭いときに
	 * 日付の途中（「2026」だけが次の行に落ちるなど）で改行されないようにする（改行は日付と日付の境目で起こる）。
	 * 日付はエスケープ済みで、返り値はそのまま出力できる。
	 *
	 * @param string $start 開始日（Y-m-d。空文字は無期限）。
	 * @param string $end   終了日（Y-m-d。空文字は無期限）。
	 * @return string 表示用の HTML（エスケープ済み）。期間の指定がなければ空文字。
	 */
	public static function format_label_html( string $start, string $end ): string {
		$html = self::build_label(
			$start,
			$end,
			static function ( string $date_text ): string {
				return '<span class="vkbm-service-period__date">' . esc_html( $date_text ) . '</span>';
			}
		);

		// 日付以外の部分（「から」「まで」「 - 」など翻訳文言）もエスケープする。日付の要素はそのまま残す。
		return self::escape_outside_date_spans( $html );
	}

	/**
	 * 提供期間の文言を組み立てる（日付の包み方だけを呼び出し側が決める）。
	 *
	 * @param string   $start   開始日（Y-m-d。空文字は無期限）。
	 * @param string   $end     終了日（Y-m-d。空文字は無期限）。
	 * @param callable $wrap    書式化した日付を受け取り、表示用に包んだ文字列を返す関数。
	 * @return string 組み立てた文言。期間の指定がなければ空文字。
	 */
	private static function build_label( string $start, string $end, callable $wrap ): string {
		$start_text = self::format_date( $start );
		$end_text   = self::format_date( $end );

		if ( '' !== $start_text && '' !== $end_text ) {
			return sprintf(
				/* translators: 1: service period start date, 2: service period end date. */
				_x( '%1$s - %2$s', 'service period range', 'vk-booking-manager' ),
				$wrap( $start_text ),
				$wrap( $end_text )
			);
		}

		if ( '' !== $start_text ) {
			return sprintf(
				/* translators: %s: service period start date. */
				__( 'From %s', 'vk-booking-manager' ),
				$wrap( $start_text )
			);
		}

		if ( '' !== $end_text ) {
			return sprintf(
				/* translators: %s: service period end date. */
				__( 'Until %s', 'vk-booking-manager' ),
				$wrap( $end_text )
			);
		}

		return '';
	}

	/**
	 * 日付の要素（.vkbm-service-period__date）以外の部分をエスケープする。
	 *
	 * @param string $html 日付の要素を含む文字列。
	 * @return string 日付の要素以外をエスケープした HTML。
	 */
	private static function escape_outside_date_spans( string $html ): string {
		$parts = preg_split( '#(<span class="vkbm-service-period__date">.*?</span>)#us', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return esc_html( wp_strip_all_tags( $html ) );
		}

		foreach ( $parts as $index => $part ) {
			// preg_split の PREG_SPLIT_DELIM_CAPTURE では、奇数番目が日付の要素（区切りに一致した部分）になる。
			if ( 0 === $index % 2 ) {
				$parts[ $index ] = self::wrap_text_part( esc_html( $part ) );
			}
		}

		return implode( '', $parts );
	}

	/**
	 * 日付以外の部分（「から」「まで」「From」など）の文字を、.vkbm-service-period__text の要素で包む。
	 *
	 * 幅が狭いときに「から」「まで」の語の途中（「か／ら」）で改行されないよう、CSS で折り返さない塊にするための目印。
	 * 前後の空白は包まず、文字を含まない部分（区切りの「 - 」など）は包まない。エスケープ済みの文字列を受け取る。
	 *
	 * @param string $escaped エスケープ済みの、日付以外の部分。
	 * @return string 文字の部分だけを包んだ HTML。
	 */
	private static function wrap_text_part( string $escaped ): string {
		if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/us', $escaped, $matches ) || ! preg_match( '/[\p{L}\p{N}]/u', $matches[2] ) ) {
			return $escaped;
		}

		return $matches[1] . '<span class="vkbm-service-period__text">' . $matches[2] . '</span>' . $matches[3];
	}

	/**
	 * Y-m-d の日付を、サイトの日付書式の文字列にする。
	 *
	 * @param string $date 日付（Y-m-d）。空文字や読めない値は空文字を返す。
	 * @return string 書式化した日付。
	 */
	private static function format_date( string $date ): string {
		if ( '' === self::sanitize_date( $date ) ) {
			return '';
		}

		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $datetime instanceof DateTimeImmutable ) {
			return '';
		}

		return wp_date( (string) get_option( 'date_format' ), $datetime->getTimestamp() );
	}
}
