<?php
/**
 * Tracks a site-wide generation number used to invalidate availability caches.
 *
 * 空き状況（予約カレンダー）の計算結果は transient へキャッシュされるが、
 * シフト・サービスメニュー・スタッフ・システム設定のいずれかが変わっても
 * そのキャッシュを消す処理が無いと、最大でキャッシュの有効期限分（数分間）は
 * 古い空き状況が表示され続けてしまう（#410 / #412）。
 *
 * このクラスは、キャッシュを個別に探して消すのではなく、サイト全体で1つ持つ
 * 「世代番号」を用意し、シフト等が保存・削除されるたびにこの番号を1つ進める。
 * 世代番号は Availability_Service::build_cache_key() がキャッシュ名に混ぜるため、
 * 世代番号が変わった時点で古いキャッシュ名は二度と参照されなくなり、
 * transient の有効期限切れとともに自然に消えていく。
 *
 * 採用しなかった方式（#410 で検討済み・再検討不要）:
 * - delete_transient() による個別削除: キャッシュ名に担当スタッフIDの md5（元の値へ
 *   戻せない変換）が含まれており、保存済みの名前を後から復元できない。
 * - データベースを直接検索して削除する方式: オブジェクトキャッシュ（Redis 等）を
 *   使っている環境では、transient の実体が DB に無いため消し漏れる。
 *
 * 世代番号の加算は shutdown（リクエスト終端で走る WordPress の仕組み）まで遅延させる
 * （安藤レビュー・HIGH の是正）。理由は次の3経路で「メタ保存の途中で世代番号だけ先に
 * 進んでしまう」窓ができるため:
 * - ブロックエディター経由の保存（REST）は wp_update_post() が save_post_* を発火した
 *   時点ではまだメタが書かれておらず、WP_REST_Posts_Controller によるメタ書き込みは
 *   その後に起きる。
 * - シフトの一括作成（Shift_Editor::handle_bulk_create()）はループ内で
 *   wp_insert_post() → update_post_meta() を繰り返すため、1件目の wp_insert_post() の
 *   時点で世代番号が進むと、2件目以降のメタがまだ書かれていない状態のキャッシュ名が
 *   有効になってしまう。
 * - Service_Menu_Post_Type::save_quick_edit() も優先度20で登録されており、
 *   フック優先度だけでは「メタ保存が先・世代番号の加算が後」を保証できない。
 * 加算をリクエスト終端（shutdown）まで遅らせれば、上記いずれの経路でも「その
 * リクエスト中に起きた全てのメタ書き込みが終わってから」世代番号が進むため、
 * 半端なデータへキャッシュ名が焼き付く窓が無くなる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use function add_action;
use function defined;
use function get_option;
use function get_post;
use function in_array;
use function is_numeric;
use function update_option;
use function wp_is_post_autosave;
use function wp_is_post_revision;

/**
 * Bumps a site-wide generation number whenever availability-affecting data changes.
 */
class Availability_Cache_Generation {
	/**
	 * 世代番号を保存する option 名。
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'vkbm_availability_cache_generation';

	/**
	 * 保存・削除を監視する投稿タイプ（シフト・サービスメニュー・スタッフ）。
	 *
	 * @var array<int, string>
	 */
	private const TARGET_POST_TYPES = array(
		Shift_Post_Type::POST_TYPE,
		Service_Menu_Post_Type::POST_TYPE,
		Resource_Post_Type::POST_TYPE,
	);

	/**
	 * このリクエスト内で世代番号の加算予約（shutdown での書き込み）を既に行ったか。
	 *
	 * シフトの一括作成のように1リクエストで複数回 save_post が発火する場合でも、
	 * shutdown での実書き込みは1リクエストにつき最大1回に抑える（過剰な更新を避けるため）。
	 * flush_bump() が実行され次第 false に戻すため、FrankenPHP / RoadRunner / Swoole の
	 * ようにワーカープロセスが複数リクエストをまたいで生き続ける環境でも、
	 * 「最初の1回しか進まない」状態にはならない（安藤レビュー・HIGH 併発の MEDIUM 是正）。
	 *
	 * @var bool
	 */
	private static bool $needs_bump = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::TARGET_POST_TYPES as $post_type ) {
			// 各エディタークラス（Shift_Editor 等）の保存処理は nonce が無いと早期リターンして
			// しまい、シフト一括作成や REST 更新などの経路を取りこぼす。そのため、投稿タイプ単位の
			// save_post_{post_type} を汎用フックとして別途購読し、保存経路によらず拾う。
			// 実際の世代番号の加算は shutdown まで遅延させる（bump() 参照）ため、
			// このフック自体の優先度は問わない（デフォルトの10のままでよい）。
			add_action( 'save_post_' . $post_type, array( __CLASS__, 'handle_post_saved' ), 10, 2 );
		}

		// 削除・ゴミ箱移動・ゴミ箱からの復元でも空き状況は変わる（例: シフトを削除すると
		// その日の予約枠が無くなる）。post_type を問わない汎用フックで拾い、対象の投稿タイプだけを
		// handle_post_deleted() 内で絞り込む。delete_post は $post を第2引数で渡すため
		// 受け取って get_post() の呼び直しを避ける（trashed_post / untrashed_post は渡さない）。
		add_action( 'delete_post', array( __CLASS__, 'handle_post_deleted' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'handle_post_deleted' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'handle_post_deleted' ) );

		// システム設定（予約締切時間・枠の刻みなど）の保存。
		// Settings_Repository::update_settings() は常にこの option 名へ update_option() するため、
		// 保存経路（管理画面・REST 等）を問わずここで一括して拾える。値が実質変わらない保存では
		// update_option()/add_option() 自体が発火しないため、無駄な世代番号更新も自然に避けられる。
		add_action( 'add_option_' . Settings_Repository::OPTION_KEY, array( __CLASS__, 'handle_settings_saved' ) );
		add_action( 'update_option_' . Settings_Repository::OPTION_KEY, array( __CLASS__, 'handle_settings_saved' ) );

		// 重要: 世代番号自身の option（self::OPTION_NAME）に対して
		// updated_option / update_option のような「全 option 共通」のフックは購読しないこと。
		// flush_bump() の update_option() 呼び出しが再びそのフックを発火させ、無限ループになる。
		// ここでは self::OPTION_NAME 用の add_option_ / update_option_ フックを一切登録しない
		// ことで、この経路自体を存在させない設計にしている。
	}

	/**
	 * 投稿の保存をハンドルし、リビジョン・オートセーブ・auto-draft でなければ世代番号の加算を予約する。
	 *
	 * @param int     $post_id 投稿ID。
	 * @param WP_Post $post    投稿オブジェクト。
	 * @return void
	 */
	public static function handle_post_saved( int $post_id, WP_Post $post ): void {
		// リビジョン・オートセーブの保存は実データの変更ではないため、世代番号を進めない。
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 新規追加画面を開いただけで作られる auto-draft は、内容の無い一時的な下書きであり
		// 空き状況に影響しない。ここで除外しないと、管理者が新規追加画面を開くたびに
		// サイト全体のキャッシュが無効化されてしまう（安藤レビュー・MEDIUM 是正）。
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::TARGET_POST_TYPES, true ) ) {
			return;
		}

		self::bump();
	}

	/**
	 * 投稿の削除・ゴミ箱移動・ゴミ箱からの復元をハンドルする。
	 *
	 * delete_post は $post を第2引数で渡すため受け取れば get_post() を省略できるが、
	 * trashed_post / untrashed_post は投稿IDしか渡さないため、その場合は自前で取得する。
	 *
	 * @param int          $post_id 投稿ID。
	 * @param WP_Post|null $post    投稿オブジェクト（delete_post のみ渡される。他は null）。
	 * @return void
	 */
	public static function handle_post_deleted( int $post_id, ?WP_Post $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// 中身の無い auto-draft の削除（wp_scheduled_auto_draft_delete の cron 等）でも
		// 空き状況は変わらないため除外する（handle_post_saved() と同じ理由）。
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::TARGET_POST_TYPES, true ) ) {
			return;
		}

		self::bump();
	}

	/**
	 * システム設定（provider settings）の保存をハンドルする。
	 *
	 * @return void
	 */
	public static function handle_settings_saved(): void {
		self::bump();
	}

	/**
	 * 現在の世代番号を返す。
	 *
	 * option が未設定の場合と、壊れた値（非数値・負値）が保存されている場合の
	 * どちらも同じ0を返す（呼び出し側からは区別しない・防御的に安全側へ倒す）。
	 *
	 * @return int 0以上の整数。option が未設定・不正な値の場合は0を返す。
	 */
	public static function get_generation(): int {
		$value = get_option( self::OPTION_NAME, 0 );

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$generation = (int) $value;

		return $generation < 0 ? 0 : $generation;
	}

	/**
	 * 世代番号の加算を予約する。
	 *
	 * 実際の update_option() はここでは行わず、リクエスト終端（shutdown）まで遅延させる
	 * （安藤レビュー・HIGH の是正）。理由:
	 * - ブロックエディター（REST）保存・シフト一括作成・優先度20のクイック編集フックなど、
	 *   「メタの書き込みが save_post_* 発火後に起きる」経路があり、フックの優先度だけでは
	 *   「世代番号は必ずメタ保存の後に進む」ことを保証できない。shutdown まで遅らせれば、
	 *   そのリクエスト中に起きた全てのメタ書き込みが終わってから世代番号が進む。
	 * - 1リクエスト1回の書き込みに抑える判定（$needs_bump）を、書き込み完了後
	 *   （flush_bump() 内）に false へ戻すことで、FrankenPHP / RoadRunner / Swoole のように
	 *   ワーカープロセスが複数リクエストをまたいで生き続ける環境でも、「最初の1回しか
	 *   進まない」状態を避けられる（静的プロパティがプロセス内で持続しても、各リクエストの
	 *   shutdown で必ずリセットされる）。
	 *
	 * $needs_bump が true でも早期 return せず、毎回 add_action() を呼び直す
	 * （安藤レビュー・再指摘 LOW の是正）。理由:
	 * - $needs_bump は flush_bump() が実行されて初めて false に戻る。もし何らかの理由で
	 *   そのリクエストの shutdown で flush_bump() が走らなかった場合（フェイタルエラー等で
	 *   register_shutdown_function() 自体が積まれなかった、shutdown フック配列が
	 *   途中でクリアされた等）、PHP-FPM のようにリクエストごとにプロセスが使い捨てられる
	 *   環境なら次のリクエストで静的プロパティが初期化されるため問題にならない。
	 * - しかし FrankenPHP / RoadRunner / Swoole のようにワーカープロセスが複数リクエストを
	 *   またいで生き続ける環境では、$needs_bump が true のまま残る。ここで早期 return して
	 *   add_action() を呼び直さないと、次にこのワーカーで bump() が呼ばれても
	 *   shutdown への結線自体が行われず、そのワーカーが生きている間ずっと世代番号が
	 *   進まなくなる（初回レビューで MEDIUM として指摘された「静的フラグがワーカー
	 *   プロセスの寿命で持続する」問題と同じ原因が、条件を狭めて残っていた形）。
	 * - add_action( 'shutdown', array( __CLASS__, 'flush_bump' ), 0 ) は同一コールバック・
	 *   同一優先度のため、_wp_filter_build_unique_id() が毎回同じキーを返し、何度呼んでも
	 *   $wp_filter 内では1件のまま重複登録にはならない。よって早期 return を外しても
	 *   フックが増殖することはなく、「1リクエスト1回の書き込み」は flush_bump() 側の
	 *   $needs_bump 判定でこれまでどおり維持される。
	 *
	 * @return void
	 */
	public static function bump(): void {
		self::$needs_bump = true;

		// register_shutdown_function() 経由のため、admin-post.php の
		// wp_safe_redirect(); exit; のような終了のしかたでも発火する。
		add_action( 'shutdown', array( __CLASS__, 'flush_bump' ), 0 );
	}

	/**
	 * bump() で予約された世代番号の加算を実行する（shutdown フックから呼ばれる）。
	 *
	 * @return void
	 */
	public static function flush_bump(): void {
		if ( ! self::$needs_bump ) {
			return;
		}

		self::$needs_bump = false;

		$current = self::get_generation();

		// オーバーフロー対策: PHP_INT_MAX に到達する手前で 0 へ巻き戻す。
		// この値は「キャッシュ名の一部が変わればよい」用途であり、連番としての連続性は不要なため、
		// 0 へ巻き戻ってもキャッシュキーの一意性（直前の世代番号との違い）は保たれる。
		$next = ( $current >= PHP_INT_MAX - 1 ) ? 0 : $current + 1;

		// autoload を true にする理由: この値は空き状況（予約カレンダー）の計算のたびに
		// Availability_Service::build_cache_key() から必ず読まれる、フロント側の高頻度な経路。
		// autoload させておけば WordPress が起動時に一括読み込みする alloptions に含まれるため、
		// リクエストのたびに個別の追加クエリ（オブジェクトキャッシュ非導入環境では都度SQL）を
		// 発生させずに済む。
		update_option( self::OPTION_NAME, $next, true );
	}
}
