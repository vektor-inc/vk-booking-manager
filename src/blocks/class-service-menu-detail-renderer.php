<?php
/**
 * 予約ブロック内に表示するサービスメニューの詳細表示を、サーバー側で用意する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Service_Menu_Detail_Link;
use WP_Post;
use function add_filter;
use function apply_filters;
use function esc_attr;
use function esc_url;
use function get_post;
use function get_the_title;
use function remove_filter;
use function sanitize_key;
use function setup_postdata;
use function str_replace;
use function wp_unslash;

/**
 * 予約ブロック内に表示するサービスメニューの詳細表示を、サーバー側で用意する（#635）。
 *
 * 詳細表示の本文は、REST で後から取得するとブロック用の CSS が読み込まれず崩れるため、
 * ページ表示時にサーバー側で `the_content` を通して作る。作った HTML は予約ブロックの
 * 中に非表示の要素として埋め込み、予約ブロックの JavaScript が読み取って表示する。
 */
class Service_Menu_Detail_Renderer {

	/**
	 * 詳細表示ができる状態を表す値。
	 */
	public const STATUS_OK = 'ok';

	/**
	 * 詳細表示ができない状態（無効・非公開・存在しない等）を表す値。
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * 本文の組み立て中かどうか。本文の中に予約ブロックがあっても入れ子で再帰しないための印。
	 *
	 * @var bool
	 */
	private static bool $is_rendering = false;

	/**
	 * `setup_postdata()` が書き換えるグローバル変数の名前。本文の組み立て前後で退避・復元する。
	 */
	private const POST_GLOBAL_KEYS = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );

	/**
	 * 詳細ページのリンク先・公開範囲の判定。
	 *
	 * @var Service_Menu_Detail_Link
	 */
	private $detail_link;

	/**
	 * メニューカードの描画に使うメニューループブロック。
	 *
	 * @var Menu_Loop_Block
	 */
	private $menu_loop_block;

	/**
	 * コンストラクタ。
	 *
	 * @param Service_Menu_Detail_Link $detail_link     詳細ページのリンク先・公開範囲の判定。
	 * @param Menu_Loop_Block          $menu_loop_block メニューカードの描画に使うメニューループブロック。
	 */
	public function __construct( Service_Menu_Detail_Link $detail_link, Menu_Loop_Block $menu_loop_block ) {
		$this->detail_link     = $detail_link;
		$this->menu_loop_block = $menu_loop_block;
	}

	/**
	 * 詳細表示の本文を組み立て中かどうかを返す。
	 *
	 * @return bool 組み立て中なら true。
	 */
	public function is_rendering(): bool {
		return self::$is_rendering;
	}

	/**
	 * 現在のリクエストが、予約ブロック内の詳細表示の要求かどうかを返す。
	 *
	 * 詳細ページを予約ブロック内で表示する設定が有効なときだけ、詳細表示の要求として扱う。
	 *
	 * @return bool 詳細表示の要求なら true。
	 */
	public function is_detail_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示内容を切り替えるだけの読み取り専用パラメーター。
		$view = isset( $_GET[ Service_Menu_Detail_Link::QUERY_VIEW ] ) ? sanitize_key( wp_unslash( (string) $_GET[ Service_Menu_Detail_Link::QUERY_VIEW ] ) ) : '';

		return Service_Menu_Detail_Link::VIEW_DETAIL === $view && $this->detail_link->is_block_display_enabled();
	}

	/**
	 * 予約ブロックの中へ埋め込む、詳細表示の元になる HTML を組み立てる。
	 *
	 * @return string 埋め込む HTML。詳細表示の要求でないときは空文字。
	 */
	public function build_source_markup(): string {
		if ( ! $this->is_detail_request() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示するメニューを選ぶだけの読み取り専用パラメーター。
		$menu_id = isset( $_GET[ Service_Menu_Detail_Link::QUERY_MENU_ID ] ) ? absint( wp_unslash( (string) $_GET[ Service_Menu_Detail_Link::QUERY_MENU_ID ] ) ) : 0;
		$menu    = $menu_id > 0 ? get_post( $menu_id ) : null;

		// 表示できないメニュー（OFF・非公開・存在しない等）は、通常の予約画面で案内するための印だけを出す。
		if ( ! ( $menu instanceof WP_Post ) || ! $this->detail_link->is_detail_viewable( $menu ) ) {
			return sprintf(
				'<div class="vkbm-reservation-detail-source" hidden data-vkbm-detail-status="%s"></div>',
				esc_attr( self::STATUS_UNAVAILABLE )
			);
		}

		// 非公開メニューの見出し・タイトルに付く「非公開:」を外す（詳細表示の見出しとタブのタイトルに出さない）。
		$remove_private_prefix = static function (): string {
			return '%s';
		};
		add_filter( 'private_title_format', $remove_private_prefix );
		try {
			$title        = get_the_title( $menu );
			$content_html = $this->render_content( $menu );
			$card_html    = $this->render_card( $menu );
		} finally {
			remove_filter( 'private_title_format', $remove_private_prefix );
		}

		return sprintf(
			'<div class="vkbm-reservation-detail-source" hidden data-vkbm-detail-status="%1$s" data-vkbm-detail-menu-id="%2$d" data-vkbm-detail-title="%3$s" data-vkbm-detail-list-url="%4$s"><div class="vkbm-reservation-detail-source__content entry-content">%5$s</div><div class="vkbm-reservation-detail-source__card">%6$s</div><div class="vkbm-reservation-detail-source__edit-link">%7$s</div></div>',
			esc_attr( self::STATUS_OK ),
			$menu->ID,
			esc_attr( $title ),
			esc_url( $this->detail_link->get_block_display_page_url() ),
			$content_html,
			$card_html,
			$this->menu_loop_block->get_menu_edit_link_markup( $menu )
		);
	}

	/**
	 * サービスメニューの本文を `the_content` フィルターを通して HTML にする。
	 *
	 * 本文の中のブロック（サービスカード等）が、表示中の投稿（予約ページ）ではなく
	 * このメニューを基準に動くよう、表示中の投稿を一時的にメニューへ差し替え、終わったら戻す。
	 *
	 * @param WP_Post $menu 表示するサービスメニュー。
	 * @return string 本文の HTML。
	 */
	private function render_content( WP_Post $menu ): string {
		// 差し替える前の投稿と、setup_postdata() が書き換えるグローバル変数を控えておく。
		$previous_globals = array();
		foreach ( self::POST_GLOBAL_KEYS as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				$previous_globals[ $key ] = $GLOBALS[ $key ];
			}
		}

		self::$is_rendering = true;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 本文のブロックが対象メニューを参照できるよう一時的に差し替え、finally で必ず戻す。
		$GLOBALS['post'] = $menu;
		setup_postdata( $menu );

		try {
			$html = (string) apply_filters( 'the_content', $menu->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアの the_content フィルター。
		} finally {
			// 差し替えたグローバル変数を、控えておいた状態（無かったものは無い状態）へ戻す。
			foreach ( self::POST_GLOBAL_KEYS as $key ) {
				if ( array_key_exists( $key, $previous_globals ) ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- 差し替える前にあった WordPress コアのグローバル変数を、控えておいた値へ戻す。
					$GLOBALS[ $key ] = $previous_globals[ $key ];
				} else {
					unset( $GLOBALS[ $key ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- 差し替える前に無かった WordPress コアのグローバル変数を元の状態へ戻す。
				}
			}
			self::$is_rendering = false;
		}

		return str_replace( ']]>', ']]&gt;', $html );
	}

	/**
	 * 選択中のサービスメニューのカード（メニューループブロックと同じカード）を HTML にする。
	 *
	 * 開いているこの詳細表示自身へのリンクになるため、「詳細を見る」とタイトルのリンクは出さない。
	 *
	 * @param WP_Post $menu 表示するサービスメニュー。
	 * @return string カードの HTML。
	 */
	private function render_card( WP_Post $menu ): string {
		$card = $this->menu_loop_block->render_menu_card(
			$menu->ID,
			array(
				'displayMode'      => 'card',
				'showDetailButton' => false,
				'disableTitleLink' => true,
			)
		);

		if ( '' === $card ) {
			return '';
		}

		return sprintf(
			'<div class="vkbm-menu-loop vkbm-menu-loop--selection vkbm-menu-loop--mode-card"><div class="vkbm-menu-loop__list vkbm-menu-loop__list--card">%s</div></div>',
			$card
		);
	}
}
