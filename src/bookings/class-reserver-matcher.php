<?php
/**
 * 予約者の照合条件を共有する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use VKBookingManager\Capabilities\Capabilities;

/**
 * サイト所属ユーザーの姓名・連絡先を照合する。
 */
class Reserver_Matcher {

	/** 検索キーワードの最大文字数。 */
	public const MAX_KEYWORD_LENGTH = 100;

	/** ふりがなの保存キー。 */
	public const META_KANA_NAME = 'vkbm_kana_name';

	/**
	 * キーワードの空白を除去して長さを制限する。
	 *
	 * @param string $keyword 検索キーワード。
	 * @return string 比較用キーワード。
	 */
	public static function normalize_keyword( string $keyword ): string {
		$keyword = (string) preg_replace( '/[\s\x{3000}]+/u', '', $keyword );
		return mb_substr( $keyword, 0, self::MAX_KEYWORD_LENGTH );
	}

	/**
	 * 現在のサイトでロールを持つ会員かを判定する。
	 *
	 * @param \WP_User $user 予約者。
	 * @return bool
	 */
	public static function is_site_member( \WP_User $user ): bool {
		return $user->exists() && ! empty( $user->roles );
	}

	/**
	 * 予約者が表示・検索時点で予約管理権限を持つスタッフか判定する。
	 *
	 * @param \WP_User $user 予約者。
	 * @return bool
	 */
	public static function is_staff( \WP_User $user ): bool {
		return user_can( $user, Capabilities::MANAGE_RESERVATIONS );
	}

	/**
	 * 一致したユーザーIDを表示名順に取得する。
	 *
	 * @param string $needle 空白を除いたキーワード。
	 * @param int    $limit  取得する最大件数。
	 * @param bool   $booking_accounts_only 一覧用にロールのある会員だけを対象とし、予約管理者を除外するか。
	 * @return array<int, int>
	 */
	public static function find_user_ids( string $needle, int $limit, bool $booking_accounts_only = false ): array {
		$needle = self::normalize_keyword( $needle );
		if ( '' === $needle || $limit < 1 ) {
			return array();
		}
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
			LEFT JOIN {$wpdb->usermeta} AS kana_name ON kana_name.user_id = users.ID AND kana_name.meta_key = %s
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
			LIMIT %d OFFSET %d";

		// 一覧用の会員条件を適用してから上限を判定するため、候補を一定件数ずつ読む。
		$matched = array();
		$offset  = 0;
		do {
			$params = array_merge( array( self::META_KANA_NAME ), $site_params, array_fill( 0, 8, $like ), array( $limit, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 固定のSQL式とプレースホルダーで照合する。
			$ids   = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
			$users = empty( $ids ) ? array() : get_users(
				array(
					'include'     => $ids,
					'orderby'     => 'include',
					'count_total' => false,
				)
			);
			foreach ( $users as $user ) {
				if ( ! $booking_accounts_only || ( self::is_site_member( $user ) && ! self::is_staff( $user ) ) ) {
					$matched[] = absint( $user->ID );
					if ( count( $matched ) >= $limit ) {
						return $matched;
					}
				}
			}
			$offset     += $limit;
			$batch_count = count( $ids );
		} while ( $batch_count === $limit );

		return $matched;
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
