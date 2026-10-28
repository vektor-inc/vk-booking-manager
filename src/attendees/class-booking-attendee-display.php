<?php
/**
 * 予約に保存された「ご利用になる方」を管理画面の表示用に取り出す。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Attendees;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Common\Attendee_Name;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * 予約時点の名前・番号の写しと、名簿の今の番号から表示する値を決める。
 */
class Booking_Attendee_Display {
	/**
	 * 予約の「ご利用になる方」の名前と番号を返す。
	 *
	 * 番号は予約時点の写しを優先し、写しが無い場合だけ名簿の今の番号を使う。
	 * 手入力の予約と、名前を手入力した名簿機能より前の予約は、名簿の番号を使わない。
	 *
	 * @param int                      $booking_id 予約 ID。
	 * @param array<string,mixed>|null $settings 設定値。省略時は保存値。
	 * @param Attendee_Roster|null     $roster 名簿サービス。
	 * @return array{id:string,name:string,number:string} 表示する値。
	 */
	public static function get( int $booking_id, ?array $settings = null, ?Attendee_Roster $roster = null ): array {
		$id     = trim( (string) get_post_meta( $booking_id, Booking_Attendee_Resolver::META_ID, true ) );
		$name   = trim( (string) get_post_meta( $booking_id, Attendee_Name::META_KEY, true ) );
		$number = trim( (string) get_post_meta( $booking_id, Booking_Attendee_Resolver::META_NUMBER, true ) );

		if ( '' === $number ) {
			$settings = $settings ?? ( new Settings_Repository() )->get_settings();
			// ID の値そのものが無い過去予約は本人の予約として扱う。ただし名前が入っている場合は手入力の予約。
			$is_roster_person = Booking_Attendee_Resolver::ID_MANUAL !== $id && ( '' !== $id || '' === $name );
			if ( $is_roster_person && ! empty( $settings['attendee_number_enabled'] ) ) {
				$number = self::find_current_number( (int) get_post_field( 'post_author', $booking_id ), '' !== $id ? $id : Booking_Attendee_Resolver::ID_SELF, $roster );
			}
		}

		return array(
			'id'     => $id,
			'name'   => $name,
			'number' => $number,
		);
	}

	/**
	 * 名前と番号を1つの表示文字列にまとめる。
	 *
	 * @param string $name   名前。
	 * @param string $number 番号。
	 * @return string 「名前（番号）」の形式。番号が無い場合は名前だけ。
	 */
	public static function format_summary( string $name, string $number ): string {
		if ( '' === $number ) {
			return $name;
		}
		return '' !== $name ? sprintf( '%1$s（%2$s）', $name, $number ) : $number;
	}

	/**
	 * 名簿にある人の今の番号を返す。
	 *
	 * @param int                  $owner_id 名簿の持ち主の ID。
	 * @param string               $id       名簿の人の ID。
	 * @param Attendee_Roster|null $roster   名簿サービス。
	 * @return string 番号。見つからない場合は空文字。
	 */
	private static function find_current_number( int $owner_id, string $id, ?Attendee_Roster $roster ): string {
		if ( $owner_id <= 0 ) {
			return '';
		}
		$roster = $roster ?? new Attendee_Roster();
		foreach ( $roster->get( $owner_id ) as $row ) {
			if ( $row['id'] === $id ) {
				return trim( (string) $row['number'] );
			}
		}
		return '';
	}
}
