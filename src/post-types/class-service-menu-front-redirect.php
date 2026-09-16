<?php
/**
 * 詳細ページを使用しないサービスメニューの個別ページを予約ページへリダイレクトする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use function add_action;
use function get_permalink;
use function get_post_meta;
use function get_queried_object;
use function is_preview;
use function is_singular;
use function untrailingslashit;
use function wp_parse_str;
use function wp_parse_url;
use function wp_safe_redirect;
use function wp_validate_redirect;

/**
 * 詳細ページを使用しないサービスメニューの個別ページを、予約ページのトップへリダイレクトする。
 *
 * 「詳細ページを使用する」（`_vkbm_use_detail_page`）がOFFのサービスメニューは、
 * 詳細ページ用のコンテンツが用意されていない前提のため、個別ページ（公開ページ）を
 * そのまま表示すると本文がほぼ空のページになってしまう（#453）。
 * このクラスは `template_redirect` フックでこの状態を検知し、基本設定の予約ページURLへ
 * 302リダイレクトする。
 */
class Service_Menu_Front_Redirect {

	/**
	 * 「詳細ページを使用する」の投稿メタキー。
	 */
	public const META_USE_DETAIL_PAGE = '_vkbm_use_detail_page';

	/**
	 * 基本設定リポジトリ。
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * コンストラクタ。
	 *
	 * @param Settings_Repository|null $settings_repository 基本設定リポジトリ。省略時は新規生成する。
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * サービスメニュー個別ページの表示直前にリダイレクトの要否を判定し、必要ならリダイレクトする。
	 *
	 * `template_redirect` フックのコールバック。判定ロジック自体は
	 * {@see self::resolve_redirect_url()} に分離しており、このメソッドは
	 * WordPress のグローバルな状態（クエリ・プレビュー判定）の取得と
	 * 実際のリダイレクト実行のみを担う。
	 */
	public function maybe_redirect(): void {
		// サービスメニューの個別ページ以外は対象外.
		if ( ! is_singular( Service_Menu_Post_Type::POST_TYPE ) ) {
			return;
		}

		// プレビュー表示では、編集者が内容を確認できるようリダイレクトしない.
		if ( is_preview() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$redirect_url = $this->resolve_redirect_url( $post );
		if ( '' === $redirect_url ) {
			return;
		}

		// resolve_redirect_url() で自サイトとして許可されるホストかどうかは検証済みだが、
		// 念のための多層防御として wp_safe_redirect() を使う
		// （同じ予約ページURLを扱う Auth_Shortcodes::redirect_free_user_from_admin() 等と同じ方針）.
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * 指定の投稿に対するリダイレクト先URLを判定する。
	 *
	 * WordPress のグローバルな状態（クエリ・プレビュー判定）に依存しない純粋な判定ロジックとして
	 * 分離し、単体テストしやすくしている。
	 *
	 * - 「詳細ページを使用する」がONの投稿は詳細ページをそのまま表示するため、空文字を返す。
	 * - 予約ページURLが未設定（正規化後に空）の場合も、遷移先が無いため空文字を返す。
	 * - 予約ページURLが自サイトとして許可されないホスト（他サイトのURLが誤って入力されている場合等）
	 *   の場合も空文字を返す（#453 レビュー対応）。許可しないと wp_safe_redirect() が既定で
	 *   admin_url() へフォールバックし、未ログイン訪問者を意図せずログイン画面へ送ってしまうため。
	 * - 予約ページURLがこのサービスメニュー自身の個別ページURLと同じ場合も空文字を返す
	 *   （#453 レビュー対応）。リダイレクトすると無限リダイレクトになるため。
	 *
	 * @param WP_Post $post 判定対象のサービスメニュー投稿。
	 * @return string リダイレクト先URL。リダイレクト不要な場合は空文字。
	 */
	public function resolve_redirect_url( WP_Post $post ): string {
		// サービスメニュー投稿タイプ以外は対象外（呼び出し元の判定漏れに対する防御）.
		if ( Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return '';
		}

		// 「詳細ページを使用する」がONの場合は、詳細ページの内容をそのまま表示する.
		$use_detail_page = (string) get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );
		if ( '1' === $use_detail_page ) {
			return '';
		}

		$reservation_url = $this->get_reservation_page_url();
		if ( '' === $reservation_url ) {
			return '';
		}

		// 自サイトとして許可されるホストか検証する（www有無の違いを含め、home_url() のホストと
		// 一致しない、または 'allowed_redirect_hosts' フィルターで許可されていないホストは
		// 第2引数のフォールバック（空文字）が返る）。許可されない場合はリダイレクトせず、
		// 従来どおり個別ページを表示する.
		$validated_url = wp_validate_redirect( $reservation_url, '' );
		if ( '' === $validated_url ) {
			return '';
		}

		// 予約ページURLがこの投稿自身の個別ページURLと同じ場合、リダイレクトすると
		// 無限リダイレクトになるためリダイレクトしない.
		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink
			&& $this->normalize_url_for_compare( $permalink ) === $this->normalize_url_for_compare( $validated_url ) ) {
			return '';
		}

		return $validated_url;
	}

	/**
	 * 予約ページのトップURL（クエリなし）を取得する。
	 *
	 * `menu_id` などのクエリは付与しない（予約ページのトップへ移動させる仕様のため）。
	 *
	 * @return string 設定済みなら正規化済みURL、未設定なら空文字。
	 */
	private function get_reservation_page_url(): string {
		$settings = $this->settings_repository->get_settings();
		$url      = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';

		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			return vkbm_normalize_reservation_page_url( $url );
		}

		return $url;
	}

	/**
	 * URLを比較用に正規化する。
	 *
	 * サービスメニュー投稿タイプは `'rewrite' => false` のため、個別ページURLは常に
	 * `home_url( '/?vkbm_service_menu=slug' )` のようなクエリ付きの形式になる。
	 * そのため、クエリを除去して正規化すると個別ページURLは常にサイトのトップURLと
	 * 一致してしまい、予約ページURLがサイトのトップやページID指定（`?page_id=...`）
	 * だった場合に「自分自身と同じ」と誤判定してしまう（#453 安藤レビュー対応）。
	 *
	 * このため、クエリはキーで並べ替えたうえで比較に含める。スキーム（http/https）は
	 * 表記違いの自分自身を取りこぼさないよう無視し、フラグメント（`#`以降）は
	 * ブラウザ側のみで使われ遷移先の同一性に影響しないため無視する。
	 *
	 * @param string $url 比較対象のURL。
	 * @return string 正規化後のURL。
	 */
	private function normalize_url_for_compare( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$query_args = array();
			wp_parse_str( $parts['query'], $query_args );
			ksort( $query_args );
			$query = '?' . http_build_query( $query_args );
		}

		return $host . $port . $path . $query;
	}
}
