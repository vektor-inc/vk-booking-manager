<?php
/**
 * Registers the reservation block metadata.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Capabilities\Capabilities;
use WP_HTML_Tag_Processor;
use WP_Post;
use function add_filter;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_url_raw;
use function generate_block_asset_handle;
use function get_rest_url;
use function home_url;
use function is_user_logged_in;
use function sanitize_key;
use function sanitize_text_field;
use function trailingslashit;
use function wp_get_inline_script_tag;
use function wp_json_encode;
use function wp_kses;
use function wp_login_url;
use function wp_logout_url;
use function wp_set_script_translations;
use function wp_unique_id;
use function wp_unslash;
use function wp_validate_redirect;

/**
 * Registers the reservation block metadata.
 */
class Reservation_Block {
	private const METADATA_PATH                 = 'build/blocks/reservation';
	private const MENU_CARD_STYLE_HANDLE        = 'vkbm-shared-menu-card';
	private const CURRENT_USER_BOOTSTRAP_HANDLE = 'vkbm-current-user-bootstrap';
	private const RESERVATION_CONFIG_HANDLE     = 'vkbm-reservation-config';
	private const BLOCK_NAME                    = 'vk-booking-manager/reservation';
	// issue #512 差し戻し対応（安藤さん指摘）: render_block はフィルタチェーンのため、
	// 同じ処理を行うコールバックが複数回フックされていると、後段のコールバックは
	// 前段が既に書き換えた $block_content を受け取って再度フォールバック markup を
	// 追記してしまい、二重出力になる。ただし WordPress は同じコールバックを同じ優先度で
	// 重ねて登録しない（_wp_filter_build_unique_id() が同じキーに上書きするため、
	// 同一インスタンス・同一優先度の多重登録では発生しない）。実際に起こり得るのは、
	// 別インスタンスが登録された場合、または異なる優先度で重ねて登録された場合。
	// このマーカーを埋め込み済みなら以降は何もしない防御を入れる。
	private const FALLBACK_MARKUP_MARKER = '<!-- vkbm-reservation-login-fallback -->';

	/**
	 * Whether block is registered.
	 *
	 * @var bool
	 */
	private static bool $block_registered = false;

	/**
	 * Auth shortcodes handler, used to read the current request's login error
	 * (if any) and the configured reservation page URL for the login fallback.
	 *
	 * ログイン失敗フォールバック（issue #512）で、同一リクエスト内のログイン
	 * エラー・予約ページURLを参照するために使う。
	 *
	 * @var Auth_Shortcodes
	 */
	private $auth_shortcodes;

	/**
	 * Constructor.
	 *
	 * @param Auth_Shortcodes $auth_shortcodes Auth shortcodes handler.
	 */
	public function __construct( Auth_Shortcodes $auth_shortcodes ) {
		$this->auth_shortcodes = $auth_shortcodes;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'maybe_enqueue_menu_loop_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_reservation_editor_script' ) );
		// issue #512: JS（view.js）が読み込めない・失敗した場合に画面が真っ白になる不具合の
		// フォールバック。ログイン導線に限り、保存済みブロック HTML（空の div）へ
		// ローディング表示・ログイン失敗文・代替ログイン導線を直接埋め込む。
		add_filter( 'render_block', array( $this, 'inject_login_fallback_markup' ), 10, 2 );
		// issue #516: 会員登録エラー時、入力値・エラー文を含む Cookie
		// （`vkbm_registration_errors`）を発行する代わりに、同一リクエスト内で発行された
		// トークンだけを wrapper の data 属性へ埋め込む。
		add_filter( 'render_block', array( $this, 'inject_registration_error_attribute' ), 10, 2 );
	}

	/**
	 * Register the block metadata with WordPress.
	 */
	public function register_block(): void {
		// Prevent duplicate registration in test environments.
		if ( self::$block_registered ) {
			return;
		}

		$metadata_path = VKBM_PLUGIN_DIR_PATH . self::METADATA_PATH;
		register_block_type_from_metadata( $metadata_path );
		$this->register_menu_loop_style();
		$this->register_script_translations( 'vk-booking-manager/reservation', array( 'script', 'viewScript', 'editorScript' ) );

		self::$block_registered = true;
	}

	/**
	 * Pass provider settings URL to the reservation block editor for the "configure from basic settings" message link.
	 *
	 * @return void
	 */
	public function localize_reservation_editor_script(): void {
		$handle = generate_block_asset_handle( 'vk-booking-manager/reservation', 'editorScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_localize_script(
			$handle,
			'vkbmReservationBlock',
			array(
				'providerSettingsUrl' => admin_url( 'admin.php?page=vkbm-provider-settings' ),
			)
		);
	}

	/**
	 * Load menu loop styles so that shared markup stays consistent.
	 */
	public function maybe_enqueue_menu_loop_styles(): void {
		if ( ! wp_style_is( self::MENU_CARD_STYLE_HANDLE, 'registered' ) ) {
			$this->register_menu_loop_style();
		}

		if ( is_admin() ) {
			wp_enqueue_style( self::MENU_CARD_STYLE_HANDLE );
			return;
		}

		global $post;

		if ( $post instanceof WP_Post && function_exists( 'has_block' ) && has_block( 'vk-booking-manager/reservation', $post ) ) {
			wp_enqueue_style( self::MENU_CARD_STYLE_HANDLE );
			$this->maybe_enqueue_current_user_bootstrap( $post );
			$this->maybe_enqueue_reservation_config();
			return;
		}
	}

	/**
	 * Enqueue a small inline script that exposes current user flags for initial render.
	 *
	 * This avoids a flicker where the frontend first renders the "customer" navigation
	 * and then switches to the "admin/provider" navigation after calling /vkbm/v1/current-user.
	 *
	 * @param WP_Post $post Current post instance.
	 */
	private function maybe_enqueue_current_user_bootstrap( WP_Post $post ): void {
		// Register an empty script handle we can attach inline JS to.
		if ( ! wp_script_is( self::CURRENT_USER_BOOTSTRAP_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::CURRENT_USER_BOOTSTRAP_HANDLE,
				'',
				array(),
				defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null,
				false
			);
		}

		$is_logged_in = is_user_logged_in();

		$current_url = $this->get_current_url( $post );
		$redirect    = wp_validate_redirect( $current_url, home_url() );

		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		$bootstrap = array(
			'canManageReservations' => $is_logged_in && current_user_can( Capabilities::MANAGE_RESERVATIONS ),
			'canViewPrivateMenus'   => $is_logged_in && current_user_can( Capabilities::VIEW_SERVICE_MENUS ),
			'shiftDashboardUrl'     => $is_logged_in ? admin_url( 'admin.php?page=vkbm-shift-dashboard' ) : '',
			'logoutUrl'             => $is_logged_in ? wp_logout_url( $redirect ) : '',
			'locale'                => $locale,
		);

		$inline = 'window.vkbmCurrentUserBootstrap = ' . wp_json_encode( $bootstrap ) . ';';
		wp_add_inline_script( self::CURRENT_USER_BOOTSTRAP_HANDLE, $inline, 'before' );
		wp_enqueue_script( self::CURRENT_USER_BOOTSTRAP_HANDLE );
	}

	/**
	 * Expose draft-related limits (e.g. memo maxLength) to the reservation front-end.
	 *
	 * 予約ブロックのフロント（textarea の maxlength など）に、コントローラー側と
	 * 同じ解決ロジックで算出した上限値を渡す。これにより
	 * `vkbm_draft_memo_max_length` フィルタを当てているサイトでも、フロントと
	 * バックの値が乖離しない。
	 *
	 * @return void
	 */
	private function maybe_enqueue_reservation_config(): void {
		// 空のスクリプトハンドルを登録してインラインスクリプトの土台として使う。
		// Register an empty script handle so we can attach the inline config to it.
		if ( ! wp_script_is( self::RESERVATION_CONFIG_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::RESERVATION_CONFIG_HANDLE,
				'',
				array(),
				defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null,
				false
			);
		}

		$config = array(
			// memo textarea の maxlength。`vkbm_draft_memo_max_length` フィルタを反映する。
			// memo textarea maxlength, reflecting the vkbm_draft_memo_max_length filter result.
			'memoMaxLength'        => Booking_Draft_Controller::resolve_memo_max_length(),
			// 通常の REST ルート URL（get_rest_url()）。パーマリンク構造が空なら、
			// この時点で既に ?rest_route= 形式になっている。
			// The regular REST root URL (get_rest_url()). Already ?rest_route= style
			// when the permalink structure is empty.
			'restRoot'             => get_rest_url(),
			// パーマリンク設定（DB）はあるのに、サーバー側（.htaccess 等）へ書き換えルールが
			// 反映されていない環境で REST 通信が 404 になる問題（issue #489）を避けるための
			// フォールバック用ルート URL。コアの get_rest_url() の非パーマリンク分岐と
			// 同じ形式（home_url + index.php?rest_route=/）で組み立てる。
			// restRoot と同じ値のとき（既に基本パーマリンク）は、フロント側でフォールバックを
			// 行わない前提のため、そのまま両方渡す。
			// Fallback REST root URL, built in the same shape as core's non-pretty-permalink
			// branch of get_rest_url() (home_url + index.php?rest_route=/), used to work around
			// REST requests returning 404 when the server's rewrite rules (.htaccess etc.) are
			// missing despite a permalink structure being configured in the DB (issue #489).
			// When it equals restRoot (plain permalinks already), the front-end is expected to
			// skip the fallback, but both values are always passed as-is.
			'restFallbackRoot'     => $this->get_rest_fallback_root(),
			// invalid_json エラー時、管理者向けメッセージに添えるパーマリンク設定画面へのリンク先。
			// フロント側は dangerouslySetInnerHTML を使わず、React 要素として <a href> を描画する
			// （植草レビュー指摘）。
			// Permalink settings screen URL, linked from the admin-facing invalid_json error
			// message. The front-end renders it as a real <a> element (not dangerouslySetInnerHTML).
			'permalinkSettingsUrl' => esc_url_raw( admin_url( 'options-permalink.php' ) ),
		);

		$inline = 'window.vkbmReservationConfig = ' . wp_json_encode( $config ) . ';';
		wp_add_inline_script( self::RESERVATION_CONFIG_HANDLE, $inline, 'before' );
		wp_enqueue_script( self::RESERVATION_CONFIG_HANDLE );
	}

	/**
	 * Build a REST root URL in the same shape as core's non-pretty-permalink
	 * branch of get_rest_url() (home_url + index.php?rest_route=/).
	 *
	 * コアの get_rest_url() の非パーマリンク分岐と同じ形式で ?rest_route= 形式の
	 * REST ルート URL を組み立てる。フロント側の apiFetch フォールバックで、
	 * サーバー側の書き換えルール未反映時の再試行先として使う。
	 *
	 * 既知の制約（安藤レビュー指摘 LOW-2）: コアの get_rest_url() と異なり、
	 * `rest_url` フィルタは通さない。このフィルタで REST のパスを独自に
	 * 書き換えているサイト（一部のセキュリティ・キャッシュ系プラグイン等）では、
	 * フォールバック用ルートが実際の REST パスとずれる可能性がある。
	 *
	 * @return string
	 */
	private function get_rest_fallback_root(): string {
		$url = trailingslashit( home_url( '/' ) ) . 'index.php';

		return add_query_arg( 'rest_route', '/', $url );
	}

	/**
	 * Build current URL including query string.
	 *
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	private function get_current_url( WP_Post $post ): string {
		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
			if ( '' !== $request_uri ) {
				return esc_url_raw( home_url( $request_uri ) );
			}
			return esc_url_raw( $permalink );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return esc_url_raw( home_url( $request_uri ) );
	}

	/**
	 * Registers the shared menu card stylesheet.
	 */
	private function register_menu_loop_style(): void {
		if ( wp_style_is( self::MENU_CARD_STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::MENU_CARD_STYLE_HANDLE,
			VKBM_PLUGIN_DIR_URL . 'build/blocks/menu-loop/style-index.css',
			array(),
			defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null
		);
	}

	/**
	 * Register block script translations from the plugin languages directory.
	 *
	 * プラグインの languages ディレクトリからブロックスクリプトの翻訳を登録します。
	 *
	 * @param string            $block_name Block name.
	 * @param array<int,string> $fields Script fields.
	 * @return void
	 */
	private function register_script_translations( string $block_name, array $fields ): void {
		if ( ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}

		$translation_path = VKBM_PLUGIN_DIR_PATH . 'languages';

		foreach ( $fields as $field ) {
			$handle = $this->resolve_script_handle( $block_name, $field );
			if ( $handle ) {
				wp_set_script_translations( $handle, 'vk-booking-manager', $translation_path );
			}
		}
	}

	/**
	 * Resolve a block script handle without relying on core helpers.
	 *
	 * コアのヘルパーに依存せずブロックスクリプトのハンドルを解決します。
	 *
	 * @param string $block_name Block name.
	 * @param string $field Script field.
	 * @return string
	 */
	private function resolve_script_handle( string $block_name, string $field ): string {
		if ( function_exists( 'generate_block_asset_handle' ) ) {
			// For 'script' field, WordPress generates handle with '-script' suffix.
			// even though it's treated as viewScript internally.
			return (string) generate_block_asset_handle( $block_name, $field );
		}

		$base = str_replace( '/', '-', $block_name );

		switch ( $field ) {
			case 'editorScript':
				return $base . '-editor-script';
			case 'viewScript':
				return $base . '-view-script';
			case 'script': // block.json 'script' field generates '-script' handle.
				return $base . '-script';
			default:
				return $base . '-' . sanitize_key( $field );
		}
	}

	/**
	 * Injects login-fallback markup into the reservation block's rendered HTML.
	 *
	 * issue #512: 予約ブロックは JS（view.js）が REST 経由でフォームを取得して描画するため、
	 * 保存済みブロック HTML は空の div のみで、JS が読み込めない・失敗した場合に画面が真っ白に
	 * なっていた（save.js・保存済みブロック HTML 自体は変更しないので deprecation は不要）。
	 * 未ログイン時のログイン導線（`vkbm_auth=login` の GET、またはこのショートコードの
	 * `vkbm_login_form` POST）に限り、この render_block フィルタで同一リクエスト内に
	 * - ログイン失敗コードを wrapper の data-vkbm-login-error 属性へ埋め込む
	 *   （JS は初回の auth-form 取得時だけこれを REST の error パラメータとして送る）
	 * - 「フォームを読み込み中…」（常時）／ログイン失敗時のみエラー文／
	 *   約3秒後に表示する「別のログイン画面」への案内（JS 未実行時の保険）
	 * を直接埋め込む。
	 *
	 * @param string              $block_content Rendered block HTML.
	 * @param array<string,mixed> $block         Parsed block data (blockName, attrs, ...).
	 * @return string
	 */
	public function inject_login_fallback_markup( string $block_content, array $block ): string {
		if ( self::BLOCK_NAME !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		// 二重登録（レビュー対応・PHPUnit 差し戻し）: 既にこのフィルタでフォールバック markup を
		// 埋め込み済みなら、他のコールバックが同じ内容を重ねて追記しないよう何もしない。
		if ( false !== strpos( $block_content, self::FALLBACK_MARKUP_MARKER ) ) {
			return $block_content;
		}

		if ( is_user_logged_in() ) {
			return $block_content;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のモード判定（状態変更なし）。
		$is_login_get = isset( $_GET['vkbm_auth'] ) && 'login' === sanitize_key( wp_unslash( $_GET['vkbm_auth'] ) );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 読み取り専用のコンテキスト判定。nonce検証は Auth_Shortcodes::process_login_request() 側で行済み。
		$is_login_post = 'POST' === $request_method && isset( $_POST['vkbm_login_form'] );

		// ログイン導線（GETのモード切替 or ログインPOST）以外では何も付与しない。
		if ( ! $is_login_get && ! $is_login_post ) {
			return $block_content;
		}

		// ログインPOST失敗時のみ、同一リクエスト内で確定したエラーコードを取得する。
		// （ログイン成功時は Auth_Shortcodes::process_login_request() が既に
		// redirect_and_exit() で終了しているため、ここに到達する時点で失敗確定）。
		$error_code    = $is_login_post ? $this->auth_shortcodes->get_current_login_error_code() : '';
		$error_message = '' !== $error_code ? $this->auth_shortcodes->get_login_error_message( $error_code ) : '';

		if ( '' !== $error_code ) {
			$block_content = $this->set_login_error_attribute( $block_content, $error_code );
		}

		return $this->append_before_closing_tag(
			$block_content,
			$this->build_login_fallback_markup( $error_message )
		);
	}

	/**
	 * Sets the `data-vkbm-login-error` attribute on the block's wrapper element.
	 *
	 * @param string $html Block wrapper HTML.
	 * @param string $code Whitelisted login error code.
	 * @return string
	 */
	private function set_login_error_attribute( string $html, string $code ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		// issue #512 レビュー対応（安藤さん指摘）: 「最初に見つかったタグ」ではなく、
		// useBlockProps.save() が必ず付与するブロックのベースクラス
		// （wp-block-vk-booking-manager-reservation）を明示的にマッチ対象にする。
		// $html の構造がどうであれ、この wrapper 要素だけを確実に狙うことで、
		// 別のタグ（読み込み中の <p> 等）へ誤って属性が付く事故を防ぐ。
		if ( ! $processor->next_tag( array( 'class_name' => 'wp-block-vk-booking-manager-reservation' ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-vkbm-login-error', $code );

		return $processor->get_updated_html();
	}

	/**
	 * Embeds the registration error token into the reservation block's wrapper
	 * element, without ever writing it to a Cookie.
	 *
	 * issue #516: 会員登録エラー時、入力値・エラー文を含む約1.3KBの
	 * `vkbm_registration_errors` Cookie を発行していたため、Cookie の多いブラウザで
	 * ヘッダーサイズの上限を超え、予約ページ・管理画面が 400 Bad Request になる
	 * おそれがあった。エラー文・入力値はサーバー側の transient
	 * （Auth_Shortcodes::persist_registration_errors() が発行）に短時間だけ保存し、
	 * この render_block フィルタでは推測不能なランダムトークンだけを wrapper の
	 * data-vkbm-registration-error-key 属性へ埋め込む。JS（app.js /
	 * booking-confirm-app.js）は初回の auth-form 取得時だけこれを REST の
	 * registration_error_key パラメータとして送る。
	 *
	 * @param string              $block_content Rendered block HTML.
	 * @param array<string,mixed> $block         Parsed block data (blockName, attrs, ...).
	 * @return string
	 */
	public function inject_registration_error_attribute( string $block_content, array $block ): string {
		if ( self::BLOCK_NAME !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		if ( is_user_logged_in() ) {
			return $block_content;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 読み取り専用のコンテキスト判定。nonce検証は Auth_Shortcodes::process_registration_request() 側で行済み。
		$is_registration_post = 'POST' === $request_method && isset( $_POST['vkbm_registration_form'] );

		if ( ! $is_registration_post ) {
			return $block_content;
		}

		// 会員登録POST失敗時のみ、同一リクエスト内で発行されたトークンを取得する
		// （成功時は Auth_Shortcodes::process_registration_request() が既に
		// redirect_and_exit() で終了しているため、ここに到達する時点で失敗確定）。
		// issue #516 安藤さんレビュー指摘（MEDIUM）: ここで実際に HTML へ埋め込む
		// ときにだけ `issue_registration_error_token()` を呼び、transient への書き込み
		// （個人情報の DB 永続化）をこの瞬間まで遅らせる。この render_block フィルタが
		// 呼ばれない（＝予約ブロックの無いページ等）状況では、この行自体に到達しないため
		// DB へは一切書き込まれない。
		$token = $this->auth_shortcodes->issue_registration_error_token();

		if ( '' === $token ) {
			return $block_content;
		}

		return $this->set_registration_error_attribute( $block_content, $token );
	}

	/**
	 * Sets the `data-vkbm-registration-error-key` attribute on the block's wrapper element.
	 *
	 * @param string $html  Block wrapper HTML.
	 * @param string $token Raw (unhashed) registration error token.
	 * @return string
	 */
	private function set_registration_error_attribute( string $html, string $token ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'class_name' => 'wp-block-vk-booking-manager-reservation' ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-vkbm-registration-error-key', $token );

		return $processor->get_updated_html();
	}

	/**
	 * Inserts markup just before the wrapper element's closing `</div>`.
	 *
	 * ブロックの save.js は属性なしの単一 div のみを出力するため、その最後の
	 * 閉じタグの直前へ挿入すれば wrapper の子要素として追加できる。
	 *
	 * @param string $html   Block wrapper HTML.
	 * @param string $markup Markup to insert.
	 * @return string
	 */
	private function append_before_closing_tag( string $html, string $markup ): string {
		if ( '' === $markup ) {
			return $html;
		}

		$pos = strrpos( $html, '</div>' );
		if ( false === $pos ) {
			return $html . $markup;
		}

		return substr_replace( $html, $markup . '</div>', $pos, strlen( '</div>' ) );
	}

	/**
	 * Builds the login-fallback markup (loading text, optional error text, and the
	 * hidden "alternate login page" hint revealed ~3s later if JS never mounted).
	 *
	 * @param string $error_message Display message for the current login failure, or '' if none.
	 * @return string
	 */
	private function build_login_fallback_markup( string $error_message ): string {
		$loading = sprintf(
			'<p class="vkbm-alert vkbm-alert__info" role="status">%s</p>',
			esc_html__( 'Loading form…', 'vk-booking-manager' )
		);

		$error_html = '';
		if ( '' !== $error_message ) {
			$error_html = sprintf(
				'<p class="vkbm-alert vkbm-alert__danger" role="alert" tabindex="-1">%s</p>',
				esc_html( $error_message )
			);
		}

		$hint_id   = wp_unique_id( 'vkbm-reservation-fallback-hint-' );
		$login_url = $this->get_native_login_fallback_url();

		/* translators: %1$s: opening <a> tag to the native WordPress login page, %2$s: closing </a> tag. */
		$hint_template = __( 'If the login form does not appear, please log in from the %1$salternate login page%2$s.', 'vk-booking-manager' );
		$hint_text     = sprintf(
			$hint_template,
			'<a href="' . esc_url( $login_url ) . '">',
			'</a>'
		);
		$hint_text     = wp_kses( $hint_text, array( 'a' => array( 'href' => true ) ) );

		$hint = sprintf(
			'<p id="%1$s" class="vkbm-alert vkbm-alert__info" role="status" hidden>%2$s</p>',
			esc_attr( $hint_id ),
			$hint_text
		);

		return self::FALLBACK_MARKUP_MARKER . $loading . $error_html . $hint . $this->get_fallback_reveal_script( $hint_id );
	}

	/**
	 * Returns the WordPress default login screen URL for the fallback link, with the
	 * `vkbm_native_login` bypass query so `Auth_Shortcodes::redirect_wp_login_to_vkbm()`
	 * does not redirect it straight back to this same reservation page (issue #512).
	 *
	 * @return string
	 */
	private function get_native_login_fallback_url(): string {
		$reservation_url = $this->auth_shortcodes->get_reservation_page_url();
		$login_url       = wp_login_url( $reservation_url );

		return add_query_arg( 'vkbm_native_login', '1', $login_url );
	}

	/**
	 * Builds the tiny inline script that reveals the hidden fallback hint ~3s after
	 * render if the reservation block's React app never replaced this markup.
	 *
	 * 外部 JS ファイルに依存しないよう、ブロック HTML 内に直接埋め込むごく短いスクリプト。
	 * React マウント成功時は wrapper の中身が丸ごと置き換わるため、この要素・スクリプトごと
	 * 消え、setTimeout のコールバックは既に DOM から外れた要素を操作するだけの無害な処理になる。
	 *
	 * @param string $hint_id Element id of the hidden hint paragraph.
	 * @return string
	 */
	private function get_fallback_reveal_script( string $hint_id ): string {
		$js = sprintf(
			'(function(){var h=document.getElementById(%s);if(!h){return;}window.setTimeout(function(){h.hidden=false;},3000);})();',
			wp_json_encode( $hint_id )
		);

		if ( function_exists( 'wp_get_inline_script_tag' ) ) {
			return wp_get_inline_script_tag( $js );
		}

		return sprintf( '<script>%s</script>', $js ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- 同一リクエスト内完結の極小フォールバック処理（外部JS不可時の保険）。
	}
}
