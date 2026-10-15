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
	 * 検索キーワードの最大文字数。
	 */
	private const MAX_KEYWORD_LENGTH = 100;

	/**
	 * ふりがなを保存しているユーザーメタキー（VKBM_Helper::get_user_display_name() と同じ）。
	 */
	private const META_KANA_NAME = 'vkbm_kana_name';

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
		$ids = self::find_user_ids( $needle, self::MAX_RESULTS + 1 );
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
		$keyword = (string) preg_replace( '/[\s\x{3000}]+/u', '', $keyword );

		return mb_substr( $keyword, 0, self::MAX_KEYWORD_LENGTH );
	}

	/**
	 * 一致したユーザーIDを表示名順に取得する。
	 *
	 * @param string $needle 空白を除いたキーワード。
	 * @param int    $limit  取得する最大件数。
	 * @return array<int, int>
	 */
	private static function find_user_ids( string $needle, int $limit ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';

		// 空白（半角・全角）を除いた状態で比較するための列の式。固定の列名だけを渡しており、入力値は含まない。
		$last_name  = self::strip_spaces_sql( 'last_name.meta_value' );
		$first_name = self::strip_spaces_sql( 'first_name.meta_value' );
		$kana_name  = self::strip_spaces_sql( 'kana_name.meta_value' );
		$display    = self::strip_spaces_sql( 'users.display_name' );

		// このサイトで権限（ロール）を持つユーザーだけを対象にする（旧プルダウンの「全ロール」と同じ範囲。マルチサイトではこのサイトの所属ユーザーに絞られる）。
		$site_join   = "INNER JOIN {$wpdb->usermeta} AS site_caps ON site_caps.user_id = users.ID AND site_caps.meta_key = %s AND site_caps.meta_value <> 'a:0:{}'";
		$site_params = array( $wpdb->get_blog_prefix() . 'capabilities' );

		// 姓名は「姓+名」「名+姓」のどちらの並びでも、空白の有無を問わず一致させる。
		$sql = "SELECT users.ID FROM {$wpdb->users} AS users
			LEFT JOIN {$wpdb->usermeta} AS last_name ON last_name.user_id = users.ID AND last_name.meta_key = 'last_name'
			LEFT JOIN {$wpdb->usermeta} AS first_name ON first_name.user_id = users.ID AND first_name.meta_key = 'first_name'
			LEFT JOIN {$wpdb->usermeta} AS kana_name ON kana_name.user_id = users.ID AND kana_name.meta_key = '" . self::META_KANA_NAME . "'
			{$site_join}
			WHERE (
				users.user_login LIKE %s
				OR users.user_email LIKE %s
				OR {$display} LIKE %s
				OR {$last_name} LIKE %s
				OR {$first_name} LIKE %s
				OR {$kana_name} LIKE %s
				OR CONCAT( {$last_name}, {$first_name} ) LIKE %s
				OR CONCAT( {$first_name}, {$last_name} ) LIKE %s
			)
			GROUP BY users.ID, users.display_name
			ORDER BY users.display_name ASC, users.ID ASC
			LIMIT %d";

		$params = array_merge( $site_params, array_fill( 0, 8, $like ), array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 列名・テーブル名は固定値のみで、入力値は prepare のプレースホルダーに渡している。
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * 列から半角・全角の空白を取り除く SQL の式を返す。
	 *
	 * @param string $column 固定の列名（入力値を渡さないこと）。
	 * @return string
	 */
	private static function strip_spaces_sql( string $column ): string {
		return "REPLACE( REPLACE( {$column}, ' ', '' ), '　', '' )";
	}
}
