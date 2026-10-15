<?php
/**
 * サービスメニューの詳細ページへのリンク先を決める。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use function add_action;
use function add_query_arg;
use function current_user_can;
use function get_permalink;
use function get_post_meta;
use function post_password_required;
use function wp_validate_redirect;

/**
 * サービスメニューの詳細ページへのリンク先を決める（#635）。
 *
 * 基本設定「サービスメニュー詳細ページの表示方法」が「予約ブロック内で表示」（block）で、
 * かつ予約ページURLが自サイトのURLとして設定されているときは、詳細ページを
 * 予約ページURL + `menu_id=<ID>&vkbm_view=detail` で表す。それ以外は従来の個別ページ（パーマリンク）を使う。
 * 予約ページへ移れない状態で block を選んでいてもリンク切れを作らないための判定を、このクラスに集約している。
 */
class Service_Menu_Detail_Link {

	/**
	 * 予約ブロック内の表示内容を切り替えるクエリパラメーター名。
	 */
	public const QUERY_VIEW = 'vkbm_view';

	/**
	 * 詳細表示を表す `vkbm_view` の値。
	 */
	public const VIEW_DETAIL = 'detail';

	/**
	 * 表示するサービスメニューの投稿IDを渡すクエリパラメーター名（予約画面の既存パラメーターと共通）。
	 */
	public const QUERY_MENU_ID = 'menu_id';

	/**
	 * 「詳細ページを使用する」の投稿メタキー。
	 */
	private const META_USE_DETAIL_PAGE = '_vkbm_use_detail_page';

	/**
	 * アーカイブ済み（予約の一覧に出さない）を表す投稿メタキー。
	 */
	private const META_IS_ARCHIVED = '_vkbm_is_archived';

	/**
	 * 基本設定リポジトリ。
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * 解決済みの予約ページURL（1リクエスト内のキャッシュ）。未解決は null、使えない状態は空文字。
	 *
	 * @var string|null
	 */
	private $cached_page_url = null;

	/**
	 * コンストラクタ。
	 *
	 * @param Settings_Repository|null $settings_repository 基本設定リポジトリ。省略時は新規生成する。
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();

		// カード1枚ごとに設定を読み直さないようキャッシュし、設定が変わったら破棄する。
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook_prefix ) {
			add_action( $hook_prefix . Settings_Repository::OPTION_KEY, array( $this, 'clear_cache' ) );
		}
	}

	/**
	 * キャッシュした予約ページURLを破棄する。
	 */
	public function clear_cache(): void {
		$this->cached_page_url = null;
	}

	/**
	 * 入力された予約ページURLを、リンク先・リダイレクト先に使える形に直す。
	 *
	 * 設定画面の注意文の判定と、詳細表示の有効判定で共用する。
	 *
	 * @param string $raw_url 設定された予約ページURL。
	 * @return string 自サイトのURLとして使えるときは正規化済みURL、未設定・自サイト外のときは空文字。
	 */
	public static function resolve_usable_reservation_page_url( string $raw_url ): string {
		$url = function_exists( 'vkbm_normalize_reservation_page_url' ) ? vkbm_normalize_reservation_page_url( $raw_url ) : trim( $raw_url );

		if ( '' === $url ) {
			return '';
		}

		// 自サイトとして許可されないホストのURLは、リンク先にもリダイレクト先にも使わない。
		return wp_validate_redirect( $url, '' );
	}

	/**
	 * 詳細ページを予約ブロック内で表示する状態かどうかを返す。
	 *
	 * @return bool 表示方法が block で、予約ページURLが自サイトのURLとして使えるなら true。
	 */
	public function is_block_display_enabled(): bool {
		return '' !== $this->get_block_display_page_url();
	}

	/**
	 * 詳細ページを予約ブロック内で表示するときの、予約ページURL（クエリなし）を返す。
	 *
	 * 「一覧に戻る」の行き先にも使う。
	 *
	 * @return string 予約ブロック内表示が使えるときのURL。使えないときは空文字。
	 */
	public function get_block_display_page_url(): string {
		if ( null === $this->cached_page_url ) {
			$this->cached_page_url = $this->resolve_block_display_page_url();
		}

		return $this->cached_page_url;
	}

	/**
	 * サービスメニューの詳細ページのリンク先を返す。
	 *
	 * @param WP_Post              $post         サービスメニュー投稿。
	 * @param array<string,string> $extra_query  詳細表示用URLに足すクエリ（引き継ぐ date・check_out_date など）。空の値は付けない。予約ブロック内表示が使えないときは付けない。
	 * @return string 予約ブロック内表示が使えるときは詳細表示用URL、それ以外は個別ページのパーマリンク。
	 */
	public function get_detail_url( WP_Post $post, array $extra_query = array() ): string {
		$page_url = $this->get_block_display_page_url();

		if ( '' === $page_url ) {
			$permalink = get_permalink( $post );

			return is_string( $permalink ) ? $permalink : '';
		}

		// 値が空のクエリは付けない。固定のキー（menu_id・vkbm_view）は引き継ぎのクエリで上書きさせない。
		$extra_query = array_filter(
			$extra_query,
			static function ( $value, $key ): bool {
				return is_string( $value ) && '' !== $value && self::QUERY_MENU_ID !== $key && self::QUERY_VIEW !== $key;
			},
			ARRAY_FILTER_USE_BOTH
		);

		return add_query_arg(
			array_merge(
				array(
					self::QUERY_MENU_ID => (string) $post->ID,
					self::QUERY_VIEW    => self::VIEW_DETAIL,
				),
				$extra_query
			),
			$page_url
		);
	}

	/**
	 * 予約ブロック内の詳細表示に出してよいサービスメニューかどうかを返す。
	 *
	 * 公開範囲はサービスメニュー一覧と同じ（公開、または権限があれば非公開）。
	 * 「詳細ページを使用する」がOFF・アーカイブ済み・パスワード保護のメニューは出さない。
	 *
	 * @param WP_Post $post 判定対象の投稿。
	 * @return bool 詳細表示に出してよい場合は true。
	 */
	public function is_detail_viewable( WP_Post $post ): bool {
		if ( Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$is_visible_status = 'publish' === $post->post_status
			|| ( 'private' === $post->post_status && current_user_can( Capabilities::VIEW_SERVICE_MENUS ) );
		if ( ! $is_visible_status ) {
			return false;
		}

		if ( '1' !== (string) get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true ) ) {
			return false;
		}

		if ( '1' === (string) get_post_meta( $post->ID, self::META_IS_ARCHIVED, true ) ) {
			return false;
		}

		return ! post_password_required( $post );
	}

	/**
	 * 予約ブロック内表示が使える予約ページURLを解決する。
	 *
	 * @return string 使えるときはURL、使えないときは空文字。
	 */
	private function resolve_block_display_page_url(): string {
		$settings = $this->settings_repository->get_settings();

		if ( Settings_Repository::DETAIL_DISPLAY_MODE_BLOCK !== ( $settings[ Settings_Repository::KEY_SERVICE_MENU_DETAIL_DISPLAY_MODE ] ?? '' ) ) {
			return '';
		}

		return self::resolve_usable_reservation_page_url( isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '' );
	}
}
