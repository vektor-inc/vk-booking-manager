<?php
/**
 * 指名を使うメニューの最低申し込み人数（受付制限、#393）の不足エラーメッセージを担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function __;
use function preg_match;
use function sprintf;

/**
 * 最低申し込み人数（受付制限）の不足エラーメッセージ組み立てをまとめたユーティリティクラス。
 *
 * 状態を持たない純粋なメソッドのみで構成し、下書きコントローラ・確定コントローラの
 * いずれからも同じロジックを再利用できるようにする（Common\Price_Tiers /
 * Common\Exclusive_Fee と同じ方針）。
 *
 * #393 安藤レビュー指摘: 判定ロジックの複製を Availability_Service への委譲に統一した
 * 同じ PR で、このメッセージ組み立て（build_message() / join_sentences()）だけが
 * 2つのコントローラーへ逐語コピーされていたのを、ここへ集約して解消する。
 */
class Nomination_Min_Guests_Message {
	/**
	 * 最低申し込み人数の不足エラーメッセージを組み立てる。
	 *
	 * 「1文1翻訳関数」のルールを保つため2文に分けて翻訳する（同じ概念のエラー文言が
	 * 複数に分かれていたのを1種類へ統一。#393 安藤レビュー指摘）。翻訳済み文字列そのものに
	 * 前後の空白を含めると `@wordpress/i18n-no-flanking-whitespace`（JS側の対になる文言
	 * `src/blocks/reservation/app.js` の `buildNominationMinGuestsMessage()` と揃えるため、
	 * JS側の作法を踏襲）に反するため、結合はコード側で行う（join_sentences()）。
	 *
	 * @param int $min_guests 最低申し込み人数。
	 * @return string エラーメッセージ。
	 */
	public static function build_message( int $min_guests ): string {
		$sentence_a = sprintf(
			/* translators: %d: minimum number of guests required to book this menu. */
			__( 'This menu accepts bookings from %d guests.', 'vk-booking-manager' ),
			$min_guests
		);
		$sentence_b = sprintf(
			/* translators: %d: minimum number of guests required to book this menu. */
			__( 'Please set the number of guests to %d or more.', 'vk-booking-manager' ),
			$min_guests
		);

		return self::join_sentences( $sentence_a, $sentence_b );
	}

	/**
	 * 翻訳済みの2文を、文末が非 ASCII（日本語の句点「。」等）のときは半角スペース無しで、
	 * 文末が ASCII のとき（英語など）は半角スペース区切りで連結する。
	 *
	 * 個別の記号を列挙する方式（句点・感嘆符・閉じ括弧…）だと、想定していない記号終わりの
	 * 文で一貫しない挙動になる（例: 半角の閉じ括弧を列挙に含めると英語で
	 * `…(bar)Please set…` のようにスペース無しで連結されてしまう）ため、末尾1文字が
	 * ASCII かどうかだけで判定する（安藤レビュー指摘）。JS側
	 * （`src/blocks/reservation/app.js` の `joinSentences()`）と同型のロジック。
	 *
	 * @param string $sentence_a 1文目（すでに翻訳・sprintf済み）。
	 * @param string $sentence_b 2文目（すでに翻訳・sprintf済み）。
	 * @return string 連結後の文字列。
	 */
	public static function join_sentences( string $sentence_a, string $sentence_b ): string {
		$ends_with_ascii = (bool) preg_match( '/[\x00-\x7F]$/', $sentence_a );
		return $ends_with_ascii ? $sentence_a . ' ' . $sentence_b : $sentence_a . $sentence_b;
	}
}
