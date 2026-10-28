<?php
/**
 * 予約編集画面の「予約者」検索（ユーザー検索）を担当するクラス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use VKBookingManager\Common\VKBM_Helper;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 姓・名・ふりがな・表示名・ユーザー名・メールアドレスでユーザーを検索する。
 *
 * 全ユーザーを読み込まず、一致した先頭の数件だけを返す（顧客が数千人いても重くならないようにする）。
 */
class Reserver_Search {

	/**
	 * 1回の検索で返す最大件数。
	 */
	public const MAX_RESULTS = 10;

	/**
	 * キーワードに一致するユーザーを検索する。
	 *
	 * 姓名・ふりがな・表示名は空白（全角含む）の有無を問わず一致させる。
	 * 上限より1件多く取得し、上限を超える一致があるかどうかを has_more で返す。
	 *
	 * @param string $keyword 検索キーワード。
	 * @return array{users: array<int, array{id: int, label: string, email: string}>, has_more: bool}
	 */
	public static function search( string $keyword ): array {
		$empty = array(
			'users'    => array(),
			'has_more' => false,
		);

		// 空白（全角含む）を除いたキーワードで比較する。
		$needle = self::normalize_keyword( $keyword );
		if ( '' === $needle ) {
			return $empty;
		}

		// 上限+1件分のIDだけを取得する。
		$ids = Reserver_Matcher::find_user_ids( $needle, self::MAX_RESULTS + 1 );
		if ( array() === $ids ) {
			return $empty;
		}

		$has_more = count( $ids ) > self::MAX_RESULTS;
		$ids      = array_slice( $ids, 0, self::MAX_RESULTS );

		// 表示用のユーザー情報を、キャッシュ込みでまとめて取得する（1人ずつ問い合わせない）。
		$users = get_users(
			array(
				'include'     => $ids,
				'orderby'     => 'include',
				'count_total' => false,
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$results[] = array(
				'id'    => (int) $user->ID,
				'label' => self::resolve_label( $user ),
				'email' => (string) $user->user_email,
			);
		}

		return array(
			'users'    => $results,
			'has_more' => $has_more,
		);
	}

	/**
	 * ユーザーの表示ラベルを返す（姓名 > ふりがな > ユーザー名 > ID の優先順位）。
	 *
	 * @param WP_User $user ユーザー。
	 * @return string
	 */
	public static function resolve_label( WP_User $user ): string {
		$label = trim( VKBM_Helper::get_user_display_name( $user ) );
		if ( '' !== $label ) {
			return $label;
		}

		return (string) $user->ID;
	}

	/**
	 * 検索キーワードから空白（全角含む）を取り除き、長さを制限する。
	 *
	 * @param string $keyword 検索キーワード。
	 * @return string 比較に使うキーワード。空になった場合は空文字。
	 */
	public static function normalize_keyword( string $keyword ): string {
		return Reserver_Matcher::normalize_keyword( $keyword );
	}
}
