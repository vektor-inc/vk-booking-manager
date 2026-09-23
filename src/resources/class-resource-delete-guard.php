<?php
/**
 * リソース（スタッフ）削除時に、紐づく予約の有無で警告・ブロックを行う（issue #262）。
 *
 * 実質 Pro 版限定機能（無料版はリソースの管理画面・REST 自体が露出しないため）。
 *
 * 仕様（司の決定 / issue #262 コメント参照）:
 * - ゴミ箱へ移動（個別・一括）: 対応中（pending・confirmed）の予約があれば警告を出すが、実行は止めない。
 * - 完全に削除（個別・「ゴミ箱を空にする」の一括）: ステータスを問わず紐づく予約が1件でも残っていればブロックする。
 *
 * 削除経路（一覧・編集画面・一括操作・REST API）は最終的にすべて WordPress 標準の
 * `wp_trash_post()` / `wp_delete_post()` を通るため、`pre_trash_post` / `pre_delete_post`
 * フィルターの2箇所で一元的に捕まえる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Resources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use WP_Error;
use WP_Post;
use WP_Query;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function delete_transient;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_current_screen;
use function get_current_user_id;
use function get_post;
use function get_transient;
use function is_admin;
use function sanitize_text_field;
use function set_transient;
use function vkbm_get_resource_display_name;
use function vkbm_get_resource_label_plural;
use function vkbm_get_resource_label_singular;
use function wp_create_nonce;
use function wp_doing_ajax;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * リソース削除ガード。
 */
class Resource_Delete_Guard {

	// 予約側 post meta（Booking_Admin と同じキー。循環依存を避けるため文字列リテラルを複製する）。
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';
	private const META_STATUS      = '_vkbm_booking_status';

	/**
	 * 「対応中」とみなす予約ステータス（警告の判定対象）。
	 *
	 * Booking_Admin::is_staff_check_target_status() と同じ基準（未対応・確定済みのみ。
	 * 完了・キャンセル・無断キャンセルは枠を消費しないため対象外）。
	 */
	private const WARNING_STATUSES = array( 'pending', 'confirmed' );

	/**
	 * 予約投稿として存在しうる WordPress の投稿ステータス一覧（安藤レビュー指摘・issue #262）。
	 *
	 * has_linked_bookings()（完全削除のブロック判定）が使う。`publish`・`pending` のみに
	 * 絞っていたため、ゴミ箱に入れた予約が判定対象から漏れ、「予約をゴミ箱へ入れる →
	 * スタッフを完全削除 → 予約を復元」で完全削除のブロックを回避できてしまっていた
	 * （`auto-draft` は「新規追加」画面を開いただけで作られる未保存の一時投稿のため対象外とする）。
	 */
	private const ALL_BOOKING_POST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/**
	 * 予約一覧の既定表示（ゴミ箱を含まない）と揃えた投稿ステータス一覧（安藤レビュー指摘・issue #262）。
	 *
	 * count_active_bookings()（ゴミ箱移動の警告件数）だけが使う。`ALL_BOOKING_POST_STATUSES` を
	 * そのまま流用すると、ゴミ箱に入った予約まで「対応中」として数えてしまい、警告ダイアログが
	 * 「対応中の予約が3件」と言うのに、リンク先の予約一覧（既定でゴミ箱を表示しない）には
	 * 2件しか出ないという食い違いが起きる。`ALL_BOOKING_POST_STATUSES` から `trash` を除いた
	 * 一覧をこちらへ分ける。
	 */
	private const ACTIVE_BOOKING_POST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	private const NOTICE_TRANSIENT_PREFIX = 'vkbm_resource_guard_notice_';
	private const NOTICE_TRANSIENT_TTL    = 300; // 5分。次の管理画面表示までに読まれなければ破棄する。

	// 予約一覧をリソースで絞り込むための query var（filter_booking_query_by_resource() が処理する）。
	public const QUERY_VAR_RESOURCE_ID = 'vkbm_resource_id';
	public const QUERY_VAR_ACTIVE_ONLY = 'vkbm_booking_active_only';

	private const AJAX_ACTION  = 'vkbm_resource_delete_check';
	private const NONCE_ACTION = 'vkbm_resource_delete_guard';

	/**
	 * このリクエスト中に検知した「警告」（ゴミ箱へ移動・対応中の予約あり）の一覧。
	 *
	 * @var array<int, array{id:int,name:string,count:int}>
	 */
	private array $pending_warnings = array();

	/**
	 * このリクエスト中に検知した「ブロック」（完全削除・紐づく予約あり）の一覧。
	 *
	 * @var array<int, array{id:int,name:string}>
	 */
	private array $pending_blocks = array();

	/**
	 * register() で登録された最新のインスタンス（安藤レビュー指摘・issue #262）。
	 *
	 * これが無いと、テストや他コードが「自分の登録したフックだけ」を外す手段を持てず、
	 * `remove_all_filters( 'pre_delete_post' )` のような全消しに頼らざるを得なくなる
	 * （全消しは、同じフックに他プラグインが登録している場合にそれも巻き込んで壊す）。
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		// static:: により、テストでサブクラスがこの判定だけを差し替えられるようにする
		// （Free_Version_Deactivator の実体は本番のプラグインヘッダ判定のため、テストから安全に
		// 「無料版扱い」を再現する手段として使う。is_rest_request() と同じ狙い）。
		if ( ! static::is_guard_enabled() ) {
			return;
		}

		self::$instance = $this;

		add_filter( 'pre_trash_post', array( $this, 'handle_pre_trash_post' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'handle_pre_delete_post' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_check' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_booking_query_by_resource' ) );
	}

	/**
	 * register() で登録された最新のインスタンスを返す（無ければ null）。
	 *
	 * 他コードやテストが、このクラス自身のフックだけを `remove_filter()` で外すために使う。
	 *
	 * @return self|null
	 */
	public static function get_instance(): ?self {
		return self::$instance;
	}

	/**
	 * この機能が有効かどうか（Pro版限定）。
	 *
	 * `register()` 以外の名前で保持するのは、`bin/check-pro-gate-test-skips.js` が
	 * `if ( Pro_Upsell::is_free_edition() ) { return; }` の形を持つメソッドを名前だけで
	 * 検出し、同名メソッドを呼ぶ全テストへスキップ指定を要求するため。`register()` という
	 * 極めて一般的な名前で直接この形を書くと、本機能と無関係な他クラスの `->register()` を
	 * 呼ぶ既存テスト全てが誤検出の対象になってしまう。専用の名前に切り出すことでそれを避ける。
	 *
	 * @return bool
	 */
	public static function is_guard_enabled(): bool {
		return ! Pro_Upsell::is_free_edition();
	}

	// =========================================================================
	// 判定ロジック（WordPress の副作用を持たない範囲は極力そのまま単体テストできるようにする）
	// =========================================================================

	/**
	 * 指定リソースに紐づく「対応中」（pending・confirmed）の予約件数を数える。
	 *
	 * ゴミ箱へ移動時の警告の判定に使う。日付の未来／過去ではなく、予約のステータスで判定する。
	 * 予約一覧は既定でゴミ箱を表示しないため、こちらも `ACTIVE_BOOKING_POST_STATUSES`
	 * （`trash` を含まない）で数え、警告ダイアログの件数とリンク先一覧の件数を一致させる
	 * （安藤レビュー指摘・issue #262）。ステータスを問わず数える has_linked_bookings()
	 * （完全削除のブロック判定）とは判定基準が異なる点に注意。
	 *
	 * @param int $resource_id リソース（スタッフ）投稿ID。
	 * @return int 対応中の予約件数。
	 */
	public static function count_active_bookings( int $resource_id ): int {
		if ( $resource_id <= 0 ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => self::ACTIVE_BOOKING_POST_STATUSES,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 予約件数は多くても数百件規模のため許容する（Staff_Conflict_Detector と同じ方針）。
					'relation' => 'AND',
					array(
						'key'   => self::META_RESOURCE_ID,
						'value' => $resource_id,
					),
					array(
						'key'     => self::META_STATUS,
						'value'   => self::WARNING_STATUSES,
						'compare' => 'IN',
					),
				),
			)
		);

		return count( $query->posts );
	}

	/**
	 * 指定リソースに紐づく予約が1件でも存在するかどうか。
	 *
	 * 完全削除時のブロックの判定に使う。予約の post_status・meta 上のステータスを問わず
	 * 全件（過去分・キャンセル済み・ゴミ箱に入っている予約を含む）を対象にする。ゴミ箱の予約を
	 * 対象から外すと「予約をゴミ箱へ入れる → スタッフを完全削除 → 予約を復元」でブロックを
	 * 回避できてしまうため（安藤レビュー指摘・issue #262）、`self::ALL_BOOKING_POST_STATUSES`
	 * で `trash` を含めて判定する。
	 *
	 * @param int $resource_id リソース（スタッフ）投稿ID。
	 * @return bool 紐づく予約が1件でもあれば true。
	 */
	public static function has_linked_bookings( int $resource_id ): bool {
		if ( $resource_id <= 0 ) {
			return false;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => self::ALL_BOOKING_POST_STATUSES,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 存在確認のみ（posts_per_page=1）のため許容する。
					array(
						'key'   => self::META_RESOURCE_ID,
						'value' => $resource_id,
					),
				),
			)
		);

		return count( $query->posts ) > 0;
	}

	/**
	 * 指定した投稿IDが、実際にリソース（スタッフ）投稿かどうか。
	 *
	 * ajax_check() が、渡された投稿IDをそのまま信用せずリソース投稿かを検証するために使う。
	 * 検証しないと、下書き・レビュー待ち・非公開など任意の投稿のタイトルを
	 * vkbm_get_resource_display_name() 経由で読み出せてしまう（安藤レビュー指摘・issue #262）。
	 *
	 * @param int $post_id 検証対象の投稿ID。
	 * @return bool リソース投稿なら true。
	 */
	private static function is_resource_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post instanceof WP_Post && Resource_Post_Type::POST_TYPE === $post->post_type;
	}

	// =========================================================================
	// フック本体
	// =========================================================================

	/**
	 * `pre_trash_post`: ゴミ箱へ移動する際、対応中の予約があれば警告を記録するが、実行自体は止めない。
	 *
	 * @param mixed        $check 他のフィルターが既に値を返している場合はそれを優先する。
	 * @param WP_Post|null $post  ゴミ箱へ移動しようとしている投稿。
	 * @return mixed
	 */
	public function handle_pre_trash_post( $check, $post ) {
		if ( null !== $check ) {
			return $check;
		}

		if ( ! $post instanceof WP_Post || Resource_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$active_count = self::count_active_bookings( $post->ID );
		if ( $active_count > 0 ) {
			$this->pending_warnings[] = array(
				'id'    => (int) $post->ID,
				'name'  => vkbm_get_resource_display_name( (int) $post->ID ),
				'count' => $active_count,
			);
		}

		// 対応中の予約があっても実行自体は止めない（司の決定: issue #262）。
		return null;
	}

	/**
	 * `pre_delete_post`: 完全削除の際、紐づく予約が1件でも残っていればブロックする。
	 *
	 * @param mixed        $check        他のフィルターが既に値を返している場合はそれを優先する。
	 * @param WP_Post|null $post         削除しようとしている投稿。
	 * @param bool         $force_delete ゴミ箱を経由せず完全に削除するかどうか。
	 * @return mixed
	 */
	public function handle_pre_delete_post( $check, $post, $force_delete ) {
		if ( null !== $check ) {
			return $check;
		}

		if ( ! $post instanceof WP_Post || Resource_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		if ( ! self::has_linked_bookings( (int) $post->ID ) ) {
			return null;
		}

		unset( $force_delete ); // ステータス・完全削除の経路を問わず、紐づく予約があれば常にブロックする。

		$this->pending_blocks[] = array(
			'id'   => (int) $post->ID,
			'name' => vkbm_get_resource_display_name( (int) $post->ID ),
		);

		// WP_Error は真として扱われる（`! $result` が false になる）ため、wp-admin の画面遷移
		// 経由の削除だけに限定して返す。それ以外（WP-CLI・他プラグインの Ajax・フロントからの
		// 呼び出しなど）で WP_Error を返すと、呼び出し元が「失敗した」と判定できず、実際には
		// ブロックされているのに成功したと誤認してしまう（安藤レビュー指摘・issue #262）。
		// REST API（force=true）は wp_delete_post() の戻り値の真偽だけを見て成否を判定する
		// （WP_REST_Posts_Controller::delete_item()）ため、false を返して正しく失敗として扱わせる。
		if ( ! $this->is_admin_screen_request() ) {
			return false;
		}

		// wp-admin（個別削除・一括削除・「ゴミ箱を空にする」）は wp_delete_post() が false を
		// 返すと wp_die() で処理全体を打ち切ってしまい、同じ一括操作に含まれる他の投稿の
		// 削除まで巻き込んで中断させてしまう（wp-admin/edit.php・wp-admin/post.php を確認済み）。
		// オブジェクト（WP_Error）を返すと wp_delete_post() はそれをそのまま返し、呼び出し元の
		// `! wp_delete_post(...)` 判定は false（＝成功扱い）になるため wp_die() を回避できる。
		// 実際には削除されなかったことは render_admin_notices() の自前通知で正確に伝える。
		// エスケープは出力時に行う（安藤レビュー指摘・issue #262）: 翻訳関数側で先に
		// エスケープすると、後から sprintf() で差し込む値（管理画面で変更できるリソースラベル）が
		// エスケープされないまま残ってしまうため、ここでは __()（生の翻訳文字列）のまま組み立てる。
		return new WP_Error(
			'vkbm_resource_has_bookings',
			sprintf(
				/* translators: %s: Resource label (singular), e.g. "staff". */
				__( 'This %s could not be permanently deleted because a booking is still linked to it.', 'vk-booking-manager' ),
				vkbm_get_resource_label_singular()
			)
		);
	}

	/**
	 * 現在のリクエストが REST API 経由かどうか。
	 *
	 * `REST_REQUEST` 定数はプロセス全体に影響するグローバル状態のため、テストで安全に
	 * 分岐を検証できるよう protected メソッドへ切り出す（テストはサブクラスでこのメソッド
	 * だけを差し替え、定数そのものは書き換えない）。
	 *
	 * @return bool
	 */
	protected function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * 現在のリクエストが wp-admin の画面遷移（一覧・編集画面・一括操作・「ゴミ箱を空にする」）
	 * 経由かどうか。
	 *
	 * WP_Error を返してよいのはこの経路だけに限定する（安藤レビュー指摘・issue #262）。
	 * `is_admin()` だけでは admin-ajax（他プラグインの Ajax 経由の削除呼び出しを含む）・
	 * WP-CLI（管理画面と同じく is_admin() が true になりうる）も真になってしまうため、
	 * `wp_doing_ajax()` と `WP_CLI` 定数の両方を除外する。REST は `is_rest_request()` で
	 * 別途判定済みのためここでも除外する。
	 *
	 * `is_admin()` だけでは `admin-post.php`・`admin.php?page=...` の独自ハンドラも真になり、
	 * wp_die() を避けたい本来の対象（予約一覧・投稿編集の画面遷移）より広く WP_Error を
	 * 返してしまう（安藤レビュー指摘・issue #262）。`$GLOBALS['pagenow']` で
	 * `edit.php`（一覧・一括操作・「ゴミ箱を空にする」）・`post.php`（個別の編集画面からの
	 * 削除）の2画面に限定する。protected にしてテストから差し替え可能にする
	 * （`is_rest_request()` と同じ狙い）。
	 *
	 * @return bool
	 */
	protected function is_admin_screen_request(): bool {
		if ( $this->is_rest_request() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( ! is_admin() ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		return in_array( $pagenow, array( 'edit.php', 'post.php' ), true );
	}

	// =========================================================================
	// 通知（管理画面）
	// =========================================================================

	/**
	 * このリクエスト中に記録した警告・ブロックを、現在のユーザー向け transient へ蓄積する。
	 *
	 * 一括操作（`wp-admin/edit.php` の一括削除・「ゴミ箱を空にする」）は複数投稿の
	 * `pre_trash_post` / `pre_delete_post` を同一リクエスト内でまとめて呼び出した後、
	 * `wp_redirect(); exit;` する。`exit()` の後も PHP の shutdown 関数は実行されるため、
	 * `shutdown` フックでリダイレクト後も確実に書き込める。
	 */
	public function flush_notices(): void {
		if ( array() === $this->pending_warnings && array() === $this->pending_blocks ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$key      = self::NOTICE_TRANSIENT_PREFIX . $user_id;
		$existing = get_transient( $key );
		$payload  = is_array( $existing ) ? $existing : array();

		$payload['warnings'] = array_merge(
			isset( $payload['warnings'] ) && is_array( $payload['warnings'] ) ? $payload['warnings'] : array(),
			$this->pending_warnings
		);
		$payload['blocks']   = array_merge(
			isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ? $payload['blocks'] : array(),
			$this->pending_blocks
		);

		set_transient( $key, $payload, self::NOTICE_TRANSIENT_TTL );
	}

	/**
	 * リソースの一覧・編集画面に、蓄積された警告・ブロックの通知を表示する。
	 *
	 * 個別操作・一括操作のどちらでも同じ transient を経由するため、リダイレクト先の
	 * クエリ引数に依存せず一律で表示できる。
	 */
	public function render_admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Resource_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$key     = self::NOTICE_TRANSIENT_PREFIX . $user_id;
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) ) {
			return;
		}

		delete_transient( $key );

		$warnings = isset( $payload['warnings'] ) && is_array( $payload['warnings'] ) ? $payload['warnings'] : array();
		$blocks   = isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ? $payload['blocks'] : array();

		// ブロック（エラー・完全削除）を先に、警告（ゴミ箱移動）を後に出す（重大度が高い方を先に伝える）。
		if ( array() !== $blocks ) {
			$this->render_notice( $blocks, false );
		}
		if ( array() !== $warnings ) {
			$this->render_notice( $warnings, true );
		}
	}

	/**
	 * 通知1件分（警告 or ブロック）を出力する。
	 *
	 * 色だけで危険度を伝えないよう、アイコン（dashicons）とテキストを併記する。1件ごとに
	 * その担当スタッフの予約一覧へのリンクを付け、どの予約が原因か追えるようにする
	 * （複数件をまとめて1つのリンクにすると、どのスタッフの予約か区別できないため。
	 * 植草レビュー指摘・issue #262）。警告側は「対応中」だけに絞った一覧へ、ブロック側は
	 * 全ステータスの一覧へリンクする。
	 *
	 * @param array<int, array<string, mixed>> $items      対象一覧（id・name、警告時のみ count を含む）。
	 * @param bool                             $is_warning true ならゴミ箱移動の警告、false なら完全削除のブロック。
	 */
	private function render_notice( array $items, bool $is_warning ): void {
		$singular  = vkbm_get_resource_label_singular();
		$list_rows = array();
		foreach ( $items as $item ) {
			$resource_id = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$name        = isset( $item['name'] ) ? (string) $item['name'] : '';
			if ( '' === $name || $resource_id <= 0 ) {
				continue;
			}

			$query_args = array(
				'post_type'                 => Booking_Post_Type::POST_TYPE,
				self::QUERY_VAR_RESOURCE_ID => $resource_id,
			);
			if ( $is_warning ) {
				$query_args[ self::QUERY_VAR_ACTIVE_ONLY ] = 1;
			}
			$booking_url = add_query_arg( $query_args, admin_url( 'edit.php' ) );

			if ( $is_warning && isset( $item['count'] ) ) {
				$label = sprintf(
					/* translators: 1: Resource (staff) name, 2: Number of active bookings. */
					__( '%1$s (%2$d active booking(s))', 'vk-booking-manager' ),
					$name,
					(int) $item['count']
				);
			} else {
				$label = $name;
			}

			$list_rows[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( $booking_url ),
				esc_html( $label )
			);
		}

		if ( array() === $list_rows ) {
			return;
		}

		$count        = count( $list_rows );
		$notice_class = $is_warning ? 'notice-warning' : 'notice-error';
		$icon_class   = $is_warning ? 'dashicons-warning' : 'dashicons-dismiss';

		// エスケープは末尾の printf() でまとめて行うため（二重エスケープを避けるため）、
		// ここでは __()（esc_html__() ではない）で生の翻訳文字列のまま組み立てる。
		if ( $is_warning ) {
			$heading = sprintf(
				/* translators: 1: number of resources (staff), 2: Resource label (singular), e.g. "staff". */
				__( 'Moved %1$d %2$s with active bookings to the Trash.', 'vk-booking-manager' ),
				$count,
				$singular
			);
			// コーディングルール（1翻訳関数=1文）に従い、2文を別々の __() に分けてから結合する。
			$body = __( 'These bookings remain assigned as before, but this staff member can no longer accept new bookings.', 'vk-booking-manager' )
				. ' ' . __( 'If you only need to stop accepting new bookings temporarily (for example, due to leave or resignation), consider switching to draft instead of deleting.', 'vk-booking-manager' );
		} else {
			$heading = sprintf(
				/* translators: 1: number of resources (staff) skipped, 2: Resource label (singular), e.g. "staff". */
				__( 'Skipped permanently deleting %1$d %2$s because bookings are still linked to them.', 'vk-booking-manager' ),
				$count,
				$singular
			);
			// 予約一覧の「予約ステータス」列はゴミ箱へ移しても書き換わらないため、リンク先の
			// 一覧にはゴミ箱内の予約もそのままのステータス表示で混ざって並ぶ。原因の予約が
			// どれか見分けられるよう、その旨を明示する（植草レビュー指摘・issue #262）。
			// この直下に並ぶのは予約そのものの一覧ではなくスタッフへのリンク一覧のため、
			// 「below」を含めるとリンク先の一覧と誤読される（植草レビュー指摘・issue #262
			// 再々レビュー）。ダイアログ側の blockBodyTrashNote と表現を揃える。
			$body = __( 'Please check the linked bookings before deleting again.', 'vk-booking-manager' )
				. ' ' . __( 'The list also includes bookings that are already in the Trash.', 'vk-booking-manager' );
		}

		printf(
			'<div class="notice %1$s vkbm-resource-guard-notice"><p class="vkbm-resource-guard-notice__heading"><span class="dashicons %2$s" aria-hidden="true"></span> %3$s</p><p>%4$s</p><ul class="vkbm-resource-guard-notice__list">%5$s</ul></div>',
			esc_attr( $notice_class ),
			esc_attr( $icon_class ),
			esc_html( $heading ),
			esc_html( $body ),
			implode( '', $list_rows ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各 <li> は生成時に esc_url()/esc_html() 済み。
		);
	}

	// =========================================================================
	// 事前チェック（一覧・編集画面のダイアログ用 AJAX）
	// =========================================================================

	/**
	 * 管理画面アセットを読み込む（リソースの一覧・編集画面のみ）。
	 *
	 * @param string $hook_suffix 現在の管理画面フック。
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Resource_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'vkbm-resource-delete-guard',
			VKBM_PLUGIN_DIR_URL . 'assets/js/resource-delete-guard.js',
			array(),
			VKBM_VERSION,
			true
		);

		$singular = vkbm_get_resource_label_singular();
		$plural   = vkbm_get_resource_label_plural();

		wp_localize_script(
			'vkbm-resource-delete-guard',
			'vkbmResourceDeleteGuard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				// コーディングルール（1翻訳関数=1文）に従い、複数文になる説明はキーを分割し、
				// JS 側でそれぞれ別々の段落として描画する（1つの __() に複数文を詰め込まない）。
				'i18n'    => array(
					/* translators: %s: Resource label (singular), e.g. "staff". */
					'warningTitle'             => sprintf( __( 'This %s has active bookings', 'vk-booking-manager' ), $singular ),
					// %%d は JS 側で実際の件数に置き換える（PHP sprintf の時点では %s だけを解決する）。
					/* translators: %s: Resource label (singular), e.g. "staff". */
					'warningBodyCount'         => sprintf( __( 'This %s currently has %%d active booking(s).', 'vk-booking-manager' ), $singular ),
					/* translators: %s: Resource label (singular), e.g. "staff". */
					'warningBodyDetail'        => sprintf( __( 'Moving it to the Trash keeps those bookings assigned as they are, but this %s will no longer be able to accept new bookings.', 'vk-booking-manager' ), $singular ),
					'warningAlternative'       => __( 'If you only need to stop accepting new bookings temporarily (for example, due to leave or resignation), consider switching to draft instead of deleting.', 'vk-booking-manager' ),
					// warningReassign の直後に描画されるのは予約の一覧ではなく、その一覧への
					// リンク（checkBookingsLink）1件だけのため、「below」を「一覧が直下にある」
					// 意味で使うと実際の描画と食い違う。リンク自体は直下にある事実は変わらないため、
					// 「list linked below」（下にリンクされている一覧）へ言い換え、通知側の
					// 修正（below の誤読防止・司の指摘・issue #262）と表現方針を揃える。
					'warningReassign'          => __( 'You can also change the assigned staff for each of those bookings from the list linked below.', 'vk-booking-manager' ),
					'checkBookingsLink'        => __( 'Check the active bookings', 'vk-booking-manager' ),
					'proceedTrash'             => __( 'Move to Trash anyway', 'vk-booking-manager' ),
					'cancel'                   => __( 'Cancel', 'vk-booking-manager' ),
					/* translators: %s: Resource label (singular), e.g. "staff". */
					'blockTitle'               => sprintf( __( 'This %s cannot be permanently deleted', 'vk-booking-manager' ), $singular ),
					/* translators: %s: Resource label (singular), e.g. "staff". */
					'blockBody'                => sprintf( __( 'This %s cannot be permanently deleted because a booking is still linked to it.', 'vk-booking-manager' ), $singular ),
					// 予約一覧の「予約ステータス」列はゴミ箱へ移しても書き換わらないため、
					// リンク先にゴミ箱内の予約が元のステータス表示のまま混ざって並ぶことを
					// 別段落で明示する（植草レビュー指摘・issue #262）。1つの __() に
					// 複数文を詰め込まないコーディングルールに従い、blockBody とはキーを分ける。
					'blockBodyTrashNote'       => __( 'The list also includes bookings that are already in the Trash.', 'vk-booking-manager' ),
					'checkLinkedLink'          => __( 'Check the linked bookings', 'vk-booking-manager' ),
					'close'                    => __( 'Close', 'vk-booking-manager' ),
					// %%d は JS 側で実際の件数に置き換える。ラベルはサイトごとに変更できる
					// リソースの呼び方（複数形）を使う（植草レビュー指摘・issue #262）。
					// bulkBlockTitleTemplate は用意しない: 一括の完全削除は事前ダイアログを
					// 出さずサーバー側でスキップする仕様のため、対応するタイトルは使われない。
					/* translators: %s: Resource label (plural), e.g. "Staff". */
					'bulkWarningTitleTemplate' => sprintf( __( '%%d %s have active bookings', 'vk-booking-manager' ), $plural ),
				),
			)
		);
	}

	/**
	 * Ajax: 選択されたリソースについて、対応中の予約件数・紐づく予約の有無を返す
	 * （削除・ゴミ箱移動を実行する前に確認ダイアログを出すための事前チェック）。
	 */
	public function ajax_check(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
		}

		if ( ! current_user_can( Capabilities::MANAGE_STAFF ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		// absint() を array_map() で直接かけて各要素をサニタイズする（WPCS が追跡できる形に揃える）。
		$ids = isset( $_POST['ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) ) )
			: array();

		$results = array();
		foreach ( $ids as $resource_id ) {
			// 渡された投稿IDが実際にリソース投稿かを検証する（安藤レビュー指摘・issue #262）。
			// 検証しないと、任意の投稿ID（下書き・レビュー待ち・非公開の投稿）のタイトルを
			// vkbm_get_resource_display_name() 経由で読み出せてしまう（権限チェックをすり抜ける）。
			if ( ! self::is_resource_post( $resource_id ) ) {
				continue;
			}

			$results[] = array(
				'id'                => $resource_id,
				'name'              => vkbm_get_resource_display_name( $resource_id ),
				'activeCount'       => self::count_active_bookings( $resource_id ),
				'hasBookings'       => self::has_linked_bookings( $resource_id ),
				// 完全削除のブロック側は全ステータスを確認先とするため絞り込みを付けない。
				'bookingsUrl'       => add_query_arg(
					array(
						'post_type'                 => Booking_Post_Type::POST_TYPE,
						self::QUERY_VAR_RESOURCE_ID => $resource_id,
					),
					admin_url( 'edit.php' )
				),
				// ゴミ箱移動の警告側は「対応中」だけに絞った一覧へ誘導する（植草レビュー指摘・issue #262）。
				'activeBookingsUrl' => add_query_arg(
					array(
						'post_type'                 => Booking_Post_Type::POST_TYPE,
						self::QUERY_VAR_RESOURCE_ID => $resource_id,
						self::QUERY_VAR_ACTIVE_ONLY => 1,
					),
					admin_url( 'edit.php' )
				),
			);
		}

		wp_send_json_success( array( 'items' => $results ) );
	}

	// =========================================================================
	// 予約一覧のリソース絞り込み（通知・ダイアログのリンク先で使う）
	// =========================================================================

	/**
	 * 予約一覧（`edit.php?post_type=vkbm_booking`）を、クエリ引数 `vkbm_resource_id` の
	 * リソースIDで絞り込む。通知・ダイアログの「対応中の予約を確認する」「紐づく予約を確認する」
	 * リンクの遷移先として使う。
	 *
	 * @param WP_Query $query 現在のメインクエリ。
	 */
	public function filter_booking_query_by_resource( WP_Query $query ): void {
		if ( is_admin() === false || ! $query->is_main_query() ) {
			return;
		}

		if ( Booking_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$resource_id = isset( $_GET[ self::QUERY_VAR_RESOURCE_ID ] ) ? absint( wp_unslash( $_GET[ self::QUERY_VAR_RESOURCE_ID ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用の一覧絞り込みのため。
		if ( $resource_id <= 0 ) {
			return;
		}

		$active_only = ! empty( $_GET[ self::QUERY_VAR_ACTIVE_ONLY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用の一覧絞り込みのため。
		// get() の既定値を array() にする（省略すると未設定時の既定値 '' が返り、
		// (array) '' で先頭に空文字要素が1件混ざってしまうため。司の指摘・issue #262）。
		$meta_query   = (array) $query->get( 'meta_query', array() );
		$meta_query[] = array(
			'key'   => self::META_RESOURCE_ID,
			'value' => $resource_id,
		);

		if ( $active_only ) {
			$meta_query[] = array(
				'key'     => self::META_STATUS,
				'value'   => self::WARNING_STATUSES,
				'compare' => 'IN',
			);
		}

		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理画面の一覧絞り込みのみ（Booking_Admin::handle_sortable_query() と同じ方針）。

		// `post_status` が未指定（URL に post_status が付いていない、既定表示）のときは、
		// カウント側の判定基準と一致するよう明示的に設定する。既定のままだとゴミ箱の予約が
		// 一覧から漏れ、完全削除ブロックの原因がゴミ箱の予約だけだった場合に「紐づく予約を
		// 確認する」リンクの遷移先が空になってしまう（安藤レビュー指摘・issue #262）。
		// 既に post_status が指定されている場合（例: `post_status=trash` を手動指定）は、
		// ユーザーの指定を優先しここでは上書きしない。先行する処理が配列で値を入れている
		// 場合もあるため、`(string)` へキャストして比較しない（安藤レビュー指摘・issue #262:
		// 配列を文字列変換すると PHP 8 で警告が出る）。
		if ( empty( $query->get( 'post_status' ) ) ) {
			$query->set(
				'post_status',
				// 対応中のみ（警告リンク）は count_active_bookings() と同じくゴミ箱を含まない一覧、
				// 全ステータス（ブロック通知のリンク）は has_linked_bookings() と同じくゴミ箱を
				// 含む一覧を使う。
				$active_only ? self::ACTIVE_BOOKING_POST_STATUSES : self::ALL_BOOKING_POST_STATUSES
			);
			// 投稿の状態を明示すると、WordPress 本体の「post_status 未指定時に自動で付与する
			// 閲覧権限チェック」が働かなくなる。既定の役割では露出は増えないが、権限編集
			// プラグイン等で予約管理の権限だけを配った構成では、他の人の非公開予約まで
			// 見えてしまう（安藤レビュー指摘・issue #262）。本体が同じ状況で行っているのと
			// 同じ形で `perm` を明示し、閲覧権限の無い投稿を除外させる。
			$query->set( 'perm', 'readable' );
		}
	}
}
