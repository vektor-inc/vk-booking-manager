<?php
/**
 * 固定ページを ID で参照する設定のための共通処理（URL から固定ページ ID を求める・ページの状態を判定する）。
 *
 * 予約ページ（#1050）・プライバシーポリシー（#1168）のほか、固定ページを選ばせる設定を増やすときにそのまま使えるよう、特定の設定に寄せずに置く。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use WP_Post;
use function __;
use function admin_url;
use function current_user_can;
use function get_edit_post_link;
use function get_permalink;
use function get_post;
use function is_numeric;
use function is_string;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;
use function untrailingslashit;
use function url_to_postid;
use function wp_parse_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 */
class Page_Reference {

	/**
	 * ページの状態: 使える（公開中。パスワード保護は含む）。
	 */
	public const PROBLEM_NONE = '';

	/**
	 * ページの状態: 未公開（下書き・レビュー待ち・公開予定・非公開）。
	 */
	public const PROBLEM_UNPUBLISHED = 'unpublished';

	/**
	 * ページの状態: ゴミ箱。
	 */
	public const PROBLEM_TRASH = 'trash';

	/**
	 * ページの状態: 見つからない（ID が 0・完全に削除済み・固定ページ以外の投稿）。
	 */
	public const PROBLEM_NOT_FOUND = 'not_found';

	/**
	 * 絶対 URL が、このサイトの公開中の固定ページを指しているときだけ、その固定ページ ID を返す。
	 *
	 * 次をすべて満たすときだけ ID を返す（それ以外は 0）。
	 * - このサイトの URL である（Reservation_Page_Url::is_own_site_url()。ドメインを移す前の URL は対象外）
	 * - url_to_postid() で固定ページの ID が取れ、公開中である
	 * - その固定ページの get_permalink() が元の URL と同じページを指す（http / https・先頭の www.・末尾スラッシュ・
	 *   大文字小文字の違いは同じとみなす。`?` や `#` が付いた URL は、同じページとはみなさない）
	 *
	 * @param string $url 絶対 URL。
	 * @return int 固定ページ ID。該当しないときは 0。
	 */
	public static function find_page_id_by_url( string $url ): int {
		$url = trim( $url );
		if ( '' === $url || ! Reservation_Page_Url::is_own_site_url( $url ) ) {
			return 0;
		}

		// WordPress 本体の判定で ID を求める（表示設定で固定ページをトップにしていればトップページの URL も ID になる）。
		$page_id = url_to_postid( $url );
		if ( $page_id <= 0 ) {
			return 0;
		}

		// 公開中の固定ページ以外（投稿・下書きなど）は切り替え対象にしない。
		if ( self::PROBLEM_NONE !== self::get_page_problem( $page_id ) ) {
			return 0;
		}

		// url_to_postid() は `?` や `#` 以降を捨てて ID を返すため、パーマリンクが元の URL と一致するときだけ同じページとみなす。
		$permalink = get_permalink( $page_id );
		if ( ! is_string( $permalink ) || ! self::is_same_page_url( $url, $permalink ) ) {
			return 0;
		}

		return $page_id;
	}

	/**
	 * 固定ページ ID が指すページの状態を返す。
	 *
	 * @param int $page_id 固定ページ ID。
	 * @return string PROBLEM_NONE（公開中で使える）／PROBLEM_UNPUBLISHED／PROBLEM_TRASH／PROBLEM_NOT_FOUND のいずれか。
	 */
	public static function get_page_problem( int $page_id ): string {
		$page = self::get_page( $page_id );
		if ( null === $page ) {
			return self::PROBLEM_NOT_FOUND;
		}

		if ( 'trash' === $page->post_status ) {
			return self::PROBLEM_TRASH;
		}

		// パスワード保護は公開中（publish）のままなので、ここでは弾かない。
		return 'publish' === $page->post_status ? self::PROBLEM_NONE : self::PROBLEM_UNPUBLISHED;
	}

	/**
	 * 固定ページ ID として保存してよい値に整える。
	 *
	 * 存在する固定ページ（ゴミ箱・未公開を含む）の ID だけを通し、それ以外（固定ページ以外の投稿・削除済み・数値でない値）は 0 にする。
	 * ゴミ箱のページも通すのは、ゴミ箱から戻したときに設定が元に戻るよう、選択を保ったまま保存できるようにするため。
	 *
	 * @param mixed $value 送信された値。
	 * @return int 固定ページ ID。該当しないときは 0。
	 */
	public static function sanitize_page_id( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$page_id = (int) $value;
		if ( $page_id <= 0 ) {
			return 0;
		}

		return null !== self::get_page( $page_id ) ? $page_id : 0;
	}

	/**
	 * 新しく選べる固定ページ ID だけを通す（公開中のページ、または今保存されている ID と同じもの）。
	 *
	 * 一覧に出るのは公開中のページだけなので、それ以外の ID が送られてくるのは送信値を書き換えたときに限られる。
	 * 今保存されている ID はゴミ箱に入れた等で公開中でなくなっていても保ち、他の項目の保存で選択が外れないようにする。
	 *
	 * @param int $page_id         送信された固定ページ ID（sanitize_page_id() 済み）。
	 * @param int $current_page_id 今保存されている固定ページ ID（無ければ 0）。
	 * @return int 選べる ID。選べないときは 0。
	 */
	public static function restrict_to_selectable( int $page_id, int $current_page_id ): int {
		if ( $page_id <= 0 ) {
			return 0;
		}

		if ( $page_id === $current_page_id ) {
			return $page_id;
		}

		return self::PROBLEM_NONE === self::get_page_problem( $page_id ) ? $page_id : 0;
	}

	/**
	 * 公開中でない固定ページを、今の利用者が自分で直せるときの行き先（URL とラベル）を返す。
	 *
	 * 未公開・ゴミ箱のどちらも、最後に公開するところまでできないと直らないため、固定ページの公開権限（publish_pages）を前提にする。
	 * - 未公開: そのページを編集できるときは、そのページの編集画面
	 * - ゴミ箱: 固定ページの一覧を開けて（edit_pages）、そのページを戻せる（delete_post）ときは、固定ページのゴミ箱の一覧
	 * それ以外（削除済み・権限が無い）は null。呼び出し側は、その設定欄での選び直しを案内する。
	 *
	 * @param string $problem get_page_problem() の戻り値。
	 * @param int    $page_id 固定ページ ID。
	 * @return array{url:string,label:string}|null 行き先の URL とリンクのラベル。直せないときは null。
	 */
	public static function get_fix_action( string $problem, int $page_id ): ?array {
		$can_publish = $page_id > 0 && current_user_can( 'publish_pages' );

		if ( self::PROBLEM_UNPUBLISHED === $problem && $can_publish ) {
			// そのページを編集できないときは空が返り、選び直しの案内になる。
			$edit_url = get_edit_post_link( $page_id, 'raw' );
			if ( is_string( $edit_url ) && '' !== $edit_url ) {
				return array(
					'url'   => $edit_url,
					'label' => __( 'Edit the page', 'vk-booking-manager' ),
				);
			}
		}

		if ( self::PROBLEM_TRASH === $problem && $can_publish && current_user_can( 'edit_pages' ) && current_user_can( 'delete_post', $page_id ) ) {
			return array(
				'url'   => admin_url( 'edit.php?post_status=trash&post_type=page' ),
				'label' => __( 'Open the trash', 'vk-booking-manager' ),
			);
		}

		return null;
	}

	/**
	 * 固定ページの表示名を返す（タイトルが空のときは WordPress 本体と同じ「#ID (no title)」の形）。
	 *
	 * @param WP_Post $page 固定ページ。
	 * @return string 表示名。
	 */
	public static function get_page_label( WP_Post $page ): string {
		$title = (string) $page->post_title;
		if ( '' !== trim( $title ) ) {
			return $title;
		}

		/* translators: %d: page ID. */
		return sprintf( __( '#%d (no title)', 'vk-booking-manager' ), (int) $page->ID );
	}

	/**
	 * 固定ページ ID から投稿を取り出す（固定ページ以外は null）。
	 *
	 * @param int $page_id 固定ページ ID。
	 * @return WP_Post|null 固定ページの投稿。見つからない・固定ページ以外のときは null。
	 */
	public static function get_page( int $page_id ): ?WP_Post {
		if ( $page_id <= 0 ) {
			return null;
		}

		$page = get_post( $page_id );

		return $page instanceof WP_Post && 'page' === $page->post_type ? $page : null;
	}

	/**
	 * 2つの絶対 URL が同じページを指すかを判定する。
	 *
	 * http / https・先頭の www.・末尾スラッシュ・ホストの大文字小文字の違いは同じとみなし、
	 * パス・クエリ・フラグメントの違いは別のページとみなす。
	 *
	 * @param string $url_a 比較する URL。
	 * @param string $url_b 比較する URL。
	 * @return bool 同じページを指すなら true。
	 */
	public static function is_same_page_url( string $url_a, string $url_b ): bool {
		$normalized_a = self::normalize_url_for_comparison( $url_a );
		$normalized_b = self::normalize_url_for_comparison( $url_b );

		return '' !== $normalized_a && $normalized_a === $normalized_b;
	}

	/**
	 * URL を比較用に整える（スキームを除き、ホストは小文字化して先頭の www. を除き、末尾スラッシュを除く）。
	 *
	 * @param string $url 絶対 URL。
	 * @return string 比較用の文字列。ホストが取れないときは空文字。
	 */
	private static function normalize_url_for_comparison( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = (string) preg_replace( '/^www\./', '', strtolower( (string) $parts['host'] ) );
		$path = untrailingslashit( (string) ( $parts['path'] ?? '' ) );

		// クエリとフラグメントは別のページ（別の表示）として扱うため、そのまま残して比較する。
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $host . $path . $query . $fragment;
	}
}
