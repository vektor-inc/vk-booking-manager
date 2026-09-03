<?php
/**
 * ユーザー貸し切り指定（#305）の貸し切り料金計算を担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 貸し切り料金（ユーザー貸し切り指定）の計算をまとめたユーティリティクラス。
 *
 * 状態を持たない純粋なメソッドのみで構成し、予約確定コントローラ・下書きコントローラ・
 * PHPUnit のいずれからも同じロジックを再利用できるようにする。
 * 料金は必ずサーバ保存メタ（1人あたり単価・適用外人数）を正とし、クライアント送信額は信用しない。
 */
class Exclusive_Fee {
	/**
	 * 貸し切り料金を計算する。
	 *
	 * 計算式（確定仕様 #305）:
	 *   貸し切り料金 = ( ユーザーが貸切選択 && per_person > 0
	 *                   && ( 適用外人数が空/0 ? true : 申込人数 < 適用外人数 ) )
	 *                 ? per_person × 申込人数 : 0
	 *
	 * 境界の扱い:
	 * - 適用外人数 `$exempt_guests` が 0（または負値→0へ丸め済み想定）は「上限なし＝常に加算」。
	 * - `$exempt_guests` が正の値のときは、申込人数が「以上（>=）」になったら加算しない（ちょうども含む）。
	 *   つまり加算するのは `$guests < $exempt_guests` のときのみ。
	 *
	 * @param bool $user_selected ユーザーが貸し切りを選択したか。
	 * @param int  $per_person    1人あたりの貸し切り単価（メニュー設定・サーバ保存メタ）。
	 * @param int  $exempt_guests 貸し切り料金を適用しない申込人数（0=上限なし）。
	 * @param int  $guests        申込人数。
	 * @return int 貸し切り料金（0 以上）。
	 */
	public static function calculate( bool $user_selected, int $per_person, int $exempt_guests, int $guests ): int {
		// ユーザーが選択していない／単価が0以下／人数が1未満なら課金しない。
		if ( ! $user_selected || $per_person <= 0 || $guests < 1 ) {
			return 0;
		}

		// 適用外人数が 0 以下は「上限なし」＝常に加算。
		// 正の値のときは、申込人数が適用外人数「以上」なら加算しない（< のときのみ加算）。
		if ( $exempt_guests > 0 && $guests >= $exempt_guests ) {
			return 0;
		}

		return max( 0, $per_person * $guests );
	}
}
