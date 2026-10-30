<?php
/**
 * 予約の出どころを示す印（予約メタ）の定義。
 *
 * 無料版・Pro 版の両方で使うため、Pro 版だけのファイル（iCal 取り込み）ではなく、ここに置く。
 * 印の値は、使う機能の側で定義する（取り込み予約は ICal_Import_Sync::SOURCE_EXTERNAL_ICAL）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 予約の出どころを示す予約メタのキー。
 */
class Booking_Source {
	/**
	 * 予約の出どころを示す予約メタのキー。
	 *
	 * 値の例: 'external_ical'（iCal 取り込み予約）、'guest'（ゲスト予約。docs/specification-guest-booking.md）。
	 * 印の無い予約は、管理画面や会員の予約画面から作られた通常の予約。
	 */
	public const META_KEY = '_vkbm_booking_source';

	/**
	 * ゲスト予約（会員登録なしの予約）の印の値。
	 */
	public const VALUE_GUEST = 'guest';

	/**
	 * ゲスト予約で、メールの持ち主が確認リンクを開いた日時（UTC。Y-m-d H:i:s）を保存する予約メタのキー。
	 */
	public const META_GUEST_VERIFIED_AT = '_vkbm_booking_guest_verified_at';

	/**
	 * ゲスト予約のキャンセル用リンクのトークンの SHA-256 を保存する予約メタのキー。
	 *
	 * 生のトークンはメールの URL にだけ入れ、サーバーにはハッシュだけを保存する（docs/specification-guest-booking.md）。
	 * 古いメールのリンクも使えるよう、新しく発行するたびに行を足す（上限あり。{@see Guest_Cancel_Controller::MAX_TOKEN_HASHES}）。
	 * キャンセルしたら、すべて消す。
	 */
	public const META_GUEST_CANCEL_TOKEN_HASH = '_vkbm_booking_guest_cancel_token_hash';

	/**
	 * 予約したユーザーを持たない予約の印の値（ゲスト予約・iCal 取り込み予約）。
	 */
	private const AUTHORLESS_VALUES = array( 'guest', 'external_ical' );

	/**
	 * 予約者の選び直しとして投稿者の付け替えを許している予約の ID ごとの入れ子の深さ。
	 *
	 * @var array<int, int>
	 */
	private static $author_change_allowed = array();

	/**
	 * 予約者の選び直しとして、指定した予約の投稿者の付け替えを許した状態でコールバックを実行する。
	 *
	 * 終わったら（例外のときも）許可を下ろす。ほかの予約の保存には影響しない。
	 *
	 * @param int      $post_id  付け替えを許す予約の投稿 ID。
	 * @param callable $callback 実行する処理（wp_update_post など）。
	 * @return mixed コールバックの戻り値。
	 */
	public static function allow_author_change( int $post_id, callable $callback ) {
		self::$author_change_allowed[ $post_id ] = ( self::$author_change_allowed[ $post_id ] ?? 0 ) + 1;
		try {
			return $callback();
		} finally {
			--self::$author_change_allowed[ $post_id ];
			if ( self::$author_change_allowed[ $post_id ] <= 0 ) {
				unset( self::$author_change_allowed[ $post_id ] );
			}
		}
	}

	/**
	 * 投稿者 0 を保つフックを登録する。
	 */
	public static function register(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_authorless_booking_author' ), 10, 2 );
	}

	/**
	 * 予約したユーザーを持たない予約（ゲスト予約・iCal 取り込み予約）を保存し直すとき、投稿者 0 を保つ。
	 *
	 * WordPress 本体の保存（編集画面・クイック編集・wp_update_post）は投稿者 0 を空とみなして保存した人の ID に
	 * 置き換えるため、DB に書く直前で 0 に戻す。編集画面・クイック編集は保存した人の ID を明示して渡すので、
	 * 渡された値は見ない。予約者の選び直しだけは、呼び出し側が allow_author_change() の中で保存して通す。
	 *
	 * @param array<string, mixed> $data    DB に書き込む投稿データ。
	 * @param array<string, mixed> $postarr 保存を依頼された元のデータ。
	 * @return array<string, mixed>
	 */
	public static function keep_authorless_booking_author( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) || 'vkbm_booking' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$booking_id = (int) ( $postarr['ID'] ?? 0 );
		if ( $booking_id <= 0 || 0 !== (int) get_post_field( 'post_author', $booking_id ) ) {
			return $data;
		}
		if ( ! in_array( get_post_meta( $booking_id, self::META_KEY, true ), self::AUTHORLESS_VALUES, true ) ) {
			return $data;
		}
		// 予約者の選び直し（allow_author_change() の中の保存）だけ通す。
		// 編集画面の保存・クイック編集は、保存した管理者の ID を post_author に明示して渡すため、値では見分けられない。
		if ( ( self::$author_change_allowed[ $booking_id ] ?? 0 ) > 0 ) {
			return $data;
		}

		$data['post_author'] = 0;

		return $data;
	}
}
