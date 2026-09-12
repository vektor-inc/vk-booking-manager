<?php
/**
 * Tracks per-date / per-month generation numbers used to invalidate availability caches
 * for booking changes (create / update / cancel / delete).
 *
 * 背景（#417）: Availability_Cache_Generation はシフト・サービスメニュー・スタッフ・
 * システム設定の保存/削除でサイト全体の世代番号を1つ進める設計だが、そこへ予約
 * （Booking_Post_Type）をそのまま足すと、予約が1件入るたびに「全メニュー・全月ぶん」の
 * キャッシュが無効化されてしまう。予約は他の投稿タイプと比べて保存頻度が桁違いに高いため、
 * 予約の多いサイトでは一時保存がほとんど効かなくなり、issue の完了条件
 * 「予約が多いサイトでも、空き状況の計算が過剰に走らないことを確認できている」を満たせない。
 *
 * そのため予約だけは別扱いにし（司の decision record・issue #417 コメント参照）、
 * 「その予約が実際に関係する日付・月」だけの世代番号を進める。
 *
 * 世代番号の保存先（安藤レビュー MEDIUM-1 の是正・司の decision record 参照）:
 * 当初は1つの option（連想配列 array('daily' => ..., 'monthly' => ...)）へまとめて
 * 「読む → 足す → 丸ごと書き戻す」形で保存していたが、この方式はリクエスト A・B が
 * ほぼ同時に別々の日付の予約を受けると、双方が同じ内容を読み、後から書き戻した側の
 * 内容だけが残ってしまう（先に書き戻された側の変更が消える）競合を持つ。日付ごとに
 * 独立した option（`daily_option_name()` / `monthly_option_name()` が組み立てる名前）へ
 * 分割することで、異なる日付の更新は別々の option 行を触るため原理的に競合せず、
 * 同じ日付で競合しても両方が「進める」方向へ書くだけなので、無効化という目的
 * （古いキャッシュ名が二度と参照されなくなること）は達成される。
 * `update_option()` の第3引数（autoload）は、日付・月の数だけ option 行が増えるため
 * 全リクエストでの読み込みを避ける目的で false にする（安藤レビュー MEDIUM-2 の是正）。
 *
 * 古い option 行が増え続けないようにする仕組み（安藤レビュー MEDIUM-3 の是正）:
 * 個別 option へ分割したことで「保存件数の上限で切り捨てる」方式は使えなくなった
 * （どの行が「上限に収まらない」かを判定する母集団が option 単位ではなく DB 全体になるため）。
 * 代わりに、$wpdb で option_name の前方一致検索を行い、埋め込まれた日付・月が
 * 今日・今月より前の行を削除する（maybe_prune_stale_options()）。この削除は
 * 1日1回だけ実行すれば十分なため（過去日の空き状況が再度参照されることはない）、
 * 最後に実行した日付を別の option（LAST_PRUNED_OPTION_NAME）へ記録し、日付が変わって
 * いなければ何もしない。新たな WP-Cron は登録せず、既存の flush_bump()（shutdown）に
 * 相乗りする形で実行する（有効化・無効化やマルチサイトの考慮を増やさないため）。
 *
 * 日付・月の形式検証（安藤レビュー LOW-1 の格上げ対応）:
 * 分割後は日付・月の値がそのまま option 名の一部になるため、`get_daily_generation()` /
 * `get_monthly_generation()`（読み取り側）と `bump_daily_generation()` /
 * `bump_monthly_generation()`（書き込み側）の両方の入口で `YYYY-MM-DD` / `YYYY-MM` の
 * 形式（実在する日付・月であること込み）を検証し、一致しない値は option 名の組み立てに
 * 使わず、読み書きの対象から外す。
 *
 * 日付が変更された場合の扱い:
 * 変更前・変更後の両方の日付を無効化する必要がある（変更前の日付だけ古い表示のまま
 * 残るのを防ぐため）。Availability_Cache_Generation と同様に世代番号の書き込み（実際の
 * update_option()）は shutdown まで遅延させるが、それとは別に「変更前の値」は
 * 変更が起きた瞬間（update_post_meta フックが実際の上書きの直前に発火する時点）で
 * 読んでおく必要がある。shutdown まで待ってから読むと、その時点ではもう新しい値に
 * 上書きされてしまっているため。
 *
 * 拾う経路（既存クラスと同じ save_post_<type> / delete_post / trashed_post / untrashed_post
 * に加え、予約特有の事情でメタ変更フックも購読している理由。司が実物を確認のうえ承認済み）:
 * - 管理画面での新規作成・編集は save_post_vkbm_booking 経由（Booking_Admin::save_post()）で
 *   メタが書かれる。ここは既存クラスと同じ考え方で拾える。
 * - フロント側の予約確定（Booking_Confirmation_Controller）は wp_insert_post() で投稿を
 *   作った後、update_post_meta() を個別に呼んでメタを書く。save_post_vkbm_booking は
 *   wp_insert_post() の内部で（メタが書かれる前に）発火してしまうため、この経路の
 *   日付を save_post フックだけで拾うことはできない。
 * - フロント側のキャンセル（My_Bookings_Controller::cancel_booking()）は
 *   update_post_meta( $booking_id, '_vkbm_booking_status', 'cancelled' ) を直接呼ぶだけで
 *   wp_update_post() を経由しないため、save_post_vkbm_booking も delete_post 系のフックも
 *   一切発火しない。この経路は投稿ライフサイクルのフックでは原理的に拾えない。
 * 上記2つのフロント経路を確実に拾うため、このクラスは投稿ライフサイクルのフックに加えて
 * 「予約の日付・後片付け終了・ステータスに関わるメタが書き込まれた」ことを検知する
 * add_post_meta 系のフック（add_post_meta/added_post_meta・update_post_meta/updated_post_meta）も
 * 購読する。実際の日付範囲の計算は「そのメタ書き込みが完了した後」に行う必要があるため、
 * 対象の投稿IDを覚えておき、shutdown 時点で最終的なメタ値を読み直す
 * （1リクエスト中に複数回メタが書き換わっても、shutdown 時点の最終値だけを見れば十分）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use Exception;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Post;
use function add_action;
use function array_keys;
use function checkdate;
use function current_datetime;
use function defined;
use function delete_option;
use function get_option;
use function get_post;
use function get_post_meta;
use function in_array;
use function is_numeric;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function update_option;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_timezone;

/**
 * Bumps per-date / per-month generation numbers whenever a booking's dates or status change.
 */
class Availability_Booking_Cache_Generation {
	/**
	 * daily の世代番号を保存する option 名の接頭辞（実際の option 名は末尾に 'YYYY-MM-DD' が付く）。
	 * 前方一致検索（maybe_prune_stale_options()）で使うため、他の option と衝突しない値にする。
	 *
	 * @var string
	 */
	private const DAILY_OPTION_PREFIX = 'vkbm_avail_bgen_d_';

	/**
	 * monthly の世代番号を保存する option 名の接頭辞（実際の option 名は末尾に 'YYYY-MM' が付く）。
	 *
	 * @var string
	 */
	private const MONTHLY_OPTION_PREFIX = 'vkbm_avail_bgen_m_';

	/**
	 * 古い option 行を最後に間引いた日付（'YYYY-MM-DD'、サイトのタイムゾーン）を保存する option 名。
	 *
	 * @var string
	 */
	private const LAST_PRUNED_OPTION_NAME = 'vkbm_avail_bgen_last_pruned';

	/**
	 * queue_range() の while ループに対する安全弁（データ不整合等で極端に長い期間になっても
	 * 大量の option 書き込みへ発展しないようにするための反復回数の上限であり、保存件数の
	 * 上限ではない。安藤レビュー LOW-4: 以前は保存件数の上限 MAX_DAILY_ENTRIES をこの安全弁に
	 * 流用していたが、保存件数の上限そのものが個別 option 化により不要になったため、
	 * 反復回数専用の定数として独立させた）。
	 *
	 * 個別 option 化により「1反復 = 設定値1行の作成」を意味するようになったため、この回数の
	 * 安全弁だけでは、開始・終了が極端に離れたデータ不整合が1件あるだけで最大2000行の
	 * option が作られてしまう上、それが未来日であれば掃除（maybe_prune_stale_options()）の
	 * 対象外なので長期間残り続ける（安藤・再レビュー LOW-4 の追加是正）。そのため主たる
	 * 頭打ちは MAX_RANGE_DAYS（期間そのもののクランプ）で行い、この回数の安全弁は
	 * MAX_RANGE_DAYS のロジックに万一の不整合があった場合に備える二重の安全弁として残す
	 * （通常はこの上限に達する前にループが正常終了する）。
	 *
	 * @var int
	 */
	private const MAX_RANGE_ITERATIONS = 2000;

	/**
	 * queue_range() が無効化範囲として積む期間の上限日数（安藤・再レビュー LOW-4 の追加是正）。
	 *
	 * サービスメニュー・プロバイダ設定の「予約可能期間（日数）」
	 * （$vkbm_max_advance_booking_days 等）は 0（無制限）を指定できる設定のため、この設定
	 * そのものからは妥当な上限を導けない。一方で、1件の予約が跨ぐ実際の日数（開始〜
	 * 後片付け込み終了）は通常運用では数分〜数日程度で収まり、長期休暇プランのような
	 * 例外的なケースを含めても年単位に収まらないケースは通常想定しない。そのため
	 * 「1年（366日、うるう年を含めても収まる日数）」を、開始・終了が極端に離れたデータ
	 * 不整合時の頭打ちとして採用する。通常の予約データはこの上限に達することがなく、
	 * 実運用への影響なく安全弁として機能する。
	 *
	 * @var int
	 */
	private const MAX_RANGE_DAYS = 366;

	/**
	 * 予約の開始日時を保存するメタキー（Booking_Admin / Booking_Confirmation_Controller と同じ値）。
	 *
	 * @var string
	 */
	private const META_DATE_START = '_vkbm_booking_service_start';

	/**
	 * 予約の後片付け時間を含めた終了日時を保存するメタキー。
	 *
	 * @var string
	 */
	private const META_TOTAL_END = '_vkbm_booking_total_end';

	/**
	 * 予約ステータス（confirmed/pending/cancelled/no_show）を保存するメタキー。
	 *
	 * @var string
	 */
	private const META_STATUS = '_vkbm_booking_status';

	/**
	 * これらのメタキーが書き込まれたら、その投稿IDを「dirty」として shutdown 時に
	 * 最終的な日付を読み直す対象にする。日付が変わらないステータス変更（例: キャンセル）でも、
	 * その予約自身の現在の日付ぶんは無効化する必要があるため対象に含める。
	 *
	 * @var array<int, string>
	 */
	private const WATCHED_META_KEYS = array(
		self::META_DATE_START,
		self::META_TOTAL_END,
		self::META_STATUS,
	);

	/**
	 * 「変更前の値」を読んでおく必要があるメタキー（日付そのものに関わるものだけ）。
	 * ステータス変更は日付を伴わないため、変更前の値を別途読む必要はない
	 * （shutdown 時点の最終値＝現在の日付を無効化すれば足りる）。
	 *
	 * @var array<int, string>
	 */
	private const RANGE_META_KEYS = array(
		self::META_DATE_START,
		self::META_TOTAL_END,
	);

	/**
	 * このリクエスト内で shutdown フックへの結線を既に行ったか。
	 *
	 * Availability_Cache_Generation と同じ理由（FrankenPHP 等のワーカー常駐環境でも
	 * 「最初の1回しか進まない」状態にならないよう、flush 実行後に false へ戻す）で、
	 * 判定に使うだけで早期 return には使わない（bump 予約のたびに add_action() を呼び直す）。
	 *
	 * @var bool
	 */
	private static bool $needs_flush = false;

	/**
	 * このリクエスト内で無効化が確定している日付（'YYYY-MM-DD' => true）。
	 * 実際の option への書き込みは shutdown まで遅延させ、同じ日付への書き込みは
	 * 1リクエスト1回にまとめる。
	 *
	 * @var array<string, bool>
	 */
	private static array $pending_daily = array();

	/**
	 * このリクエスト内で無効化が確定している月（'YYYY-MM' => true）。
	 *
	 * @var array<string, bool>
	 */
	private static array $pending_monthly = array();

	/**
	 * shutdown 時点で最終的な日付を読み直す対象の予約投稿ID（post_id => true）。
	 *
	 * @var array<int, bool>
	 */
	private static array $dirty_post_ids = array();

	/**
	 * このリクエスト内で「変更前の日付」を確保済みの予約投稿ID（post_id => true）。
	 * 1つの予約に対して START・TOTAL_END の両方が書き換わっても、変更前スナップショットは
	 * 最初の1回だけ確保すればよい（2回目以降は既に新しい値で上書きされているため）。
	 *
	 * @var array<int, bool>
	 */
	private static array $captured_old_snapshot = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function register(): void {
		// 管理画面・REST 経由の作成/更新（trashed_post/untrashed_post 内部で走る wp_update_post()
		// による発火も含む）。auto-draft・リビジョン・オートセーブの除外は
		// handle_post_saved() 内で行う（Availability_Cache_Generation と同じ考え方）。
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, array( __CLASS__, 'handle_post_saved' ), 10, 2 );

		// 完全削除。delete_post はメタが実際に削除される前に発火するため、ここで
		// 予約の日付を読んでおかないと shutdown 時点ではもう読めなくなる（handle_post_deleted 参照）。
		add_action( 'delete_post', array( __CLASS__, 'handle_post_deleted' ), 10, 2 );

		// ゴミ箱移動・復元。wp_trash_post()/wp_untrash_post() は内部で wp_update_post() を
		// 呼ぶため save_post_vkbm_booking 経由でも拾えるはずだが、Availability_Cache_Generation
		// と同じく多重防御として個別にも購読しておく（実害はない。$dirty_post_ids への
		// 追加が重複するだけで、書き込み自体は shutdown 時に1回にまとまる）。
		add_action( 'trashed_post', array( __CLASS__, 'handle_post_trashed_or_untrashed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'handle_post_trashed_or_untrashed' ) );

		// フロント側のキャンセル（My_Bookings_Controller::cancel_booking()）は
		// update_post_meta() を直接呼ぶだけで投稿ライフサイクルのフックを一切発火させないため、
		// メタ変更そのものを検知する必要がある（クラス doc コメント参照）。
		//
		// 'update_post_meta'（末尾に d が無い方）は実際の DB 上書きの「直前」に発火するため、
		// このタイミングで get_post_meta() を読めば「変更前」の値が取れる（日付変更時のみ必要）。
		add_action( 'update_post_meta', array( __CLASS__, 'handle_meta_before_update' ), 10, 4 );

		// 開始日時・後片付け終了日時のメタが update_meta_value() の仕様で delete_post_meta() 経由
		// で削除されるケース（管理画面で日時欄を空にして保存した場合）にも同様に対応する。
		// 'delete_post_meta' も実際の削除の直前に発火するため、ここで読む値は「変更前」の値になる。
		add_action( 'delete_post_meta', array( __CLASS__, 'handle_meta_before_delete' ), 10, 4 );

		// 'added_post_meta'（新規作成時）・'updated_post_meta'（更新時、上書き後に発火）は
		// 対象の投稿IDを dirty としてマークするためだけに使う。実際の日付は shutdown 時点で
		// 最終値を読み直す（1リクエスト中に複数回書き換わっても最終値だけを見れば十分なため）。
		add_action( 'added_post_meta', array( __CLASS__, 'handle_meta_after_write' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'handle_meta_after_write' ), 10, 4 );
	}

	/**
	 * 現在の daily 世代番号を返す。
	 *
	 * @param string $date 'YYYY-MM-DD' 形式の日付。
	 * @return int 0以上の整数。未記録・形式不正・存在しない日付の場合は0。
	 */
	public static function get_daily_generation( string $date ): int {
		if ( ! self::is_valid_daily_key( $date ) ) {
			return 0;
		}

		return self::sanitize_generation_value( get_option( self::daily_option_name( $date ), 0 ) );
	}

	/**
	 * 現在の monthly 世代番号を返す。
	 *
	 * @param string $month 'YYYY-MM' 形式の月。
	 * @return int 0以上の整数。未記録・形式不正・存在しない月の場合は0。
	 */
	public static function get_monthly_generation( string $month ): int {
		if ( ! self::is_valid_monthly_key( $month ) ) {
			return 0;
		}

		return self::sanitize_generation_value( get_option( self::monthly_option_name( $month ), 0 ) );
	}

	/**
	 * 予約の保存（作成・更新）をハンドルする。
	 *
	 * @param int     $post_id 投稿ID。
	 * @param WP_Post $post    投稿オブジェクト。
	 * @return void
	 */
	public static function handle_post_saved( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 新規追加画面を開いただけで作られる auto-draft は空き状況に影響しない
		// （Availability_Cache_Generation と同じ理由）。
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		self::mark_dirty( $post_id );
	}

	/**
	 * 予約の完全削除をハンドルする。
	 *
	 * delete_post アクションは、投稿メタが実際に削除される前に発火する（WordPress コアの
	 * wp_delete_post() は 'delete_post' → メタ削除 → 'deleted_post' → 投稿行の削除、の順で
	 * 進む）。そのため shutdown まで待たず、ここで日付を読んで即座に無効化対象へ加える
	 * （post_id だけ覚えて shutdown 時に読み直す方式は、削除後は読めなくなるため使えない）。
	 *
	 * @param int          $post_id 投稿ID。
	 * @param WP_Post|null $post    投稿オブジェクト（delete_post のみ渡される）。
	 * @return void
	 */
	public static function handle_post_deleted( int $post_id, ?WP_Post $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		$start     = (string) get_post_meta( $post_id, self::META_DATE_START, true );
		$total_end = (string) get_post_meta( $post_id, self::META_TOTAL_END, true );

		self::queue_range( $start, $total_end );
		self::schedule_flush();
	}

	/**
	 * ゴミ箱移動・復元をハンドルする（register() 参照。多重防御用）。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public static function handle_post_trashed_or_untrashed( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		self::mark_dirty( $post_id );
	}

	/**
	 * メタが上書きされる直前に発火し、日付系メタの「変更前の値」を確保する。
	 *
	 * @param int    $meta_id    メタID。
	 * @param int    $object_id  投稿ID。
	 * @param string $meta_key   メタキー。
	 * @param mixed  $meta_value 書き込まれようとしている新しい値（このメソッドでは未使用）。
	 * @return void
	 */
	public static function handle_meta_before_update( int $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );

		self::maybe_capture_old_snapshot( $meta_key, $object_id );
	}

	/**
	 * メタが削除される直前に発火し、日付系メタの「変更前の値」を確保する。
	 *
	 * update_meta_value() は値が空（''）のとき update_post_meta() ではなく delete_post_meta() を
	 * 呼ぶ実装になっている（例: 管理画面で日時欄を空にして保存した場合）ため、日付が
	 * 「変更」ではなく「削除」される経路もここで拾っておく必要がある。
	 *
	 * @param array<int, int> $meta_ids   削除されるメタID一覧（このメソッドでは未使用）。
	 * @param int             $object_id  投稿ID。
	 * @param string          $meta_key   メタキー。
	 * @param mixed           $meta_value 削除条件に指定された値（このメソッドでは未使用）。
	 * @return void
	 */
	public static function handle_meta_before_delete( $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		unset( $meta_ids, $meta_value );

		self::maybe_capture_old_snapshot( $meta_key, $object_id );
	}

	/**
	 * 日付系メタ（START・TOTAL_END）が変更・削除される直前に、変更前の値を確保して
	 * 無効化対象へ積む。1つの予約に対して両方が書き換わる場合でも、
	 * 「変更前」のスナップショットは最初の1回だけ確保すれば十分なため、
	 * 2回目以降の呼び出しは何もしない。
	 *
	 * @param string $meta_key  今まさに変更・削除されようとしているメタキー。
	 * @param int    $object_id 投稿ID。
	 * @return void
	 */
	private static function maybe_capture_old_snapshot( string $meta_key, int $object_id ): void {
		if ( ! in_array( $meta_key, self::RANGE_META_KEYS, true ) ) {
			return;
		}

		if ( isset( self::$captured_old_snapshot[ $object_id ] ) ) {
			return;
		}

		if ( ! self::is_watchable_booking( $object_id ) ) {
			return;
		}

		self::$captured_old_snapshot[ $object_id ] = true;

		// まだ新しい値で上書き・削除される前なので、ここで読む値は「変更前」の値になる。
		$old_start     = (string) get_post_meta( $object_id, self::META_DATE_START, true );
		$old_total_end = (string) get_post_meta( $object_id, self::META_TOTAL_END, true );

		self::queue_range( $old_start, $old_total_end );
		self::schedule_flush();
	}

	/**
	 * メタが書き込まれた（新規追加・更新）後に発火し、対象の投稿を dirty としてマークする。
	 *
	 * @param int    $meta_id    メタID。
	 * @param int    $object_id  投稿ID。
	 * @param string $meta_key   メタキー。
	 * @param mixed  $meta_value 書き込まれた値（このメソッドでは未使用。shutdown 時に読み直す）。
	 * @return void
	 */
	public static function handle_meta_after_write( int $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( ! in_array( $meta_key, self::WATCHED_META_KEYS, true ) ) {
			return;
		}

		if ( ! self::is_watchable_booking( $object_id ) ) {
			return;
		}

		self::mark_dirty( $object_id );
	}

	/**
	 * bump 予約されたすべての無効化を実行する（shutdown フックから呼ばれる）。
	 *
	 * @return void
	 */
	public static function flush_bump(): void {
		if ( ! self::$needs_flush ) {
			return;
		}

		self::$needs_flush = false;

		// dirty マークされた予約について、リクエスト終端（= 全メタ書き込みが完了した後）の
		// 最終的な日付を読み、無効化対象へ加える。
		foreach ( array_keys( self::$dirty_post_ids ) as $post_id ) {
			$start     = (string) get_post_meta( $post_id, self::META_DATE_START, true );
			$total_end = (string) get_post_meta( $post_id, self::META_TOTAL_END, true );

			self::queue_range( $start, $total_end );
		}

		self::$dirty_post_ids        = array();
		self::$captured_old_snapshot = array();

		// 古い option 行の掃除は、必ず bump（世代番号を進める書き込み）より先に実行する
		// （安藤・再レビュー LOW-1 の是正）。
		// 以前は bump の後に掃除していたため、今回のリクエストで過去日ぶんの世代番号を
		// 進めても、直後の掃除がその過去日の option 行を「今日より前」の条件で削除して
		// しまい、次に読んだときには option 未設定＝0（初回更新前と同じ値）に戻っていた。
		// 例: 昨日の予約を「無断キャンセル」へ変更すると、昨日・一昨日の世代番号が
		// 1へ進んだ直後に削除され、その過去日の日別キャッシュが最大5分間古いまま
		// 残ってしまう。掃除を先に済ませてから bump すれば、このリクエストで新しく
		// 書き込む値は掃除の対象にならず（掃除は1日1回しか走らないため、翌日以降の
		// 初回 flush で改めて削除される）、無効化そのものは正しく反映される。
		// 古い option 行の掃除は1日1回だけでよいため、無効化対象の有無にかかわらず
		// 毎回呼び出し、実行するかどうかの判定は maybe_prune_stale_options() 側に任せる。
		self::maybe_prune_stale_options();

		// 日付ごとに独立した option を1つずつ進める（クラス doc コメントの MEDIUM-1 是正参照）。
		// 異なる日付は別々の option 行を触るため競合せず、同じ日付が競合しても双方が
		// 「進める」方向へ書くだけなので、無効化の目的は達成される。
		foreach ( array_keys( self::$pending_daily ) as $date_key ) {
			self::bump_daily_generation( $date_key );
		}

		foreach ( array_keys( self::$pending_monthly ) as $month_key ) {
			self::bump_monthly_generation( $month_key );
		}

		self::$pending_daily   = array();
		self::$pending_monthly = array();
	}

	/**
	 * 対象の投稿IDを dirty としてマークし、flush を予約する。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	private static function mark_dirty( int $post_id ): void {
		self::$dirty_post_ids[ $post_id ] = true;
		self::schedule_flush();
	}

	/**
	 * 対象の投稿が「予約（trash 中も含む）」であるかを判定する。
	 *
	 * @param int $post_id 投稿ID。
	 * @return bool
	 */
	private static function is_watchable_booking( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return false;
		}

		// リビジョン・オートセーブは予約投稿タイプ自体が対応していないため通常は発生しないが、
		// 多層防御として除外しておく。
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * shutdown フックへ flush_bump() を結線する。
	 *
	 * Availability_Cache_Generation と同じ理由で、$needs_flush の値にかかわらず毎回
	 * add_action() を呼び直す（同一コールバック・同一優先度のため重複登録にはならない）。
	 *
	 * @return void
	 */
	private static function schedule_flush(): void {
		self::$needs_flush = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_bump' ), 0 );
	}

	/**
	 * 開始・終了（後片付け込み）の日時から、無効化すべき日付・月をこのリクエストの
	 * 保留リストへ積む（実際の option 書き込みは行わない）。
	 *
	 * @param string $start_value     開始日時（Y-m-d H:i:s 形式想定）。
	 * @param string $total_end_value 後片付け込みの終了日時（同上）。
	 * @return void
	 */
	private static function queue_range( string $start_value, string $total_end_value ): void {
		$start = self::parse_meta_datetime( $start_value );
		$end   = self::parse_meta_datetime( $total_end_value );

		if ( ! $start instanceof DateTimeImmutable && ! $end instanceof DateTimeImmutable ) {
			// 日付がまだ確定していない（メタ未保存・保存に失敗した等）予約は無効化対象がない。
			return;
		}

		// 片方だけ取得できた場合は、もう片方も同じ日として扱う
		// （少なくとも取得できた側の日付は確実に無効化する、安全側の扱い）。
		if ( ! $start instanceof DateTimeImmutable ) {
			$start = $end;
		}
		if ( ! $end instanceof DateTimeImmutable ) {
			$end = $start;
		}

		// 開始が終了より後（データ不整合）の場合は入れ替えて範囲を正しく計算する。
		if ( $start > $end ) {
			$swap  = $start;
			$start = $end;
			$end   = $swap;
		}

		// 時刻を切り捨てて日付だけで比較・反復する（安藤レビュー LOW-3 の是正）。
		// 時刻付きのまま比較すると、終了の時刻が開始の時刻より前になる複数日予約
		// （例: 開始が 1/10 23:00、終了が 1/11 01:00）で $until（終了+1日 01:00）より
		// $cursor（開始-1日 23:00）が先に追い越してしまい、意図していた「終了日の翌日」が
		// ループへ入らないまま終わってしまう。
		$start = $start->setTime( 0, 0, 0 );
		$end   = $end->setTime( 0, 0, 0 );

		// 開始・終了が極端に離れたデータ不整合の場合、期間そのものを頭打ちにする
		// （安藤・再レビュー LOW-4 の追加是正。回数ではなく期間でクランプする。
		// MAX_RANGE_DAYS の定数コメント参照）。
		$range_limit = $start->modify( sprintf( '+%d days', self::MAX_RANGE_DAYS ) );
		if ( $end > $range_limit ) {
			$end = $range_limit;
		}

		// 深夜をまたぐ予約・後片付け時間が隣の日にはみ出すケースを考慮し、前後1日を含める。
		$cursor = $start->modify( '-1 day' );
		$until  = $end->modify( '+1 day' );

		// 安全弁: データ異常等で極端に長い期間になっても大量書き込みにならないよう上限を設ける。
		$iterations = 0;

		while ( $cursor <= $until && $iterations < self::MAX_RANGE_ITERATIONS ) {
			$date_key  = $cursor->format( 'Y-m-d' );
			$month_key = $cursor->format( 'Y-m' );

			self::$pending_daily[ $date_key ]    = true;
			self::$pending_monthly[ $month_key ] = true;

			$cursor = $cursor->modify( '+1 day' );
			++$iterations;
		}
	}

	/**
	 * 予約メタの日時文字列（Y-m-d H:i:s 想定）をサイトのタイムゾーンで DateTimeImmutable へ変換する。
	 *
	 * @param string $value メタの値。
	 * @return DateTimeImmutable|null 解釈できない場合は null。
	 */
	private static function parse_meta_datetime( string $value ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		$timezone = wp_timezone();
		$parsed   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( $parsed instanceof DateTimeImmutable && ! self::has_datetime_parse_errors() ) {
			return $parsed;
		}

		// 保存フォーマットが想定と異なる場合の保険（安藤レビュー LOW-2 の是正）。
		// createFromFormat() は末尾に余分な文字が付いた値や範囲外の値（例: '2026-13-45'）を
		// 警告付きで通してしまうため、instanceof のチェックだけでは弾けない
		// （has_datetime_parse_errors() で warning_count / error_count を確認する）。
		// また、フォールバックの DateTimeImmutable コンストラクタは「+5 years」のような
		// 相対表現も日時として受理してしまうため、値が 'YYYY-MM-DD'（時刻部分は任意）の
		// 絶対日付「だけ」であることを確認してから使う。
		//
		// 末尾を固定していないと（安藤・再レビュー LOW-3 の是正）、'2026-01-01 10:00:00 +1 year'
		// のような「絶対日付＋相対表現」を連結した値が preg_match の判定だけを通過してしまう
		// （DateTimeImmutable コンストラクタはこの形式を「2026-01-01 10:00:00 の1年後」として
		// 警告なしで解釈してしまうため、has_datetime_parse_errors() でも弾けない）。
		// 先頭に加えて末尾も固定し、絶対日付・時刻以外の文字列が続く値は弾く。
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
			return null;
		}

		try {
			$fallback = new DateTimeImmutable( $value, $timezone );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return null;
		}

		return self::has_datetime_parse_errors() ? null : $fallback;
	}

	/**
	 * 直前の DateTimeImmutable::createFromFormat() / コンストラクタでの日時解釈に
	 * 警告・エラーが出ていないかを確認する（安藤レビュー LOW-2 の是正）。
	 *
	 * @return bool 警告またはエラーがあれば true。
	 */
	private static function has_datetime_parse_errors(): bool {
		$errors = DateTimeImmutable::getLastErrors();

		if ( false === $errors ) {
			return false;
		}

		return $errors['warning_count'] > 0 || $errors['error_count'] > 0;
	}

	/**
	 * 次の世代番号を返す（オーバーフロー対策込み）。
	 *
	 * @param int $current 現在の世代番号。
	 * @return int
	 */
	private static function next_generation( int $current ): int {
		return ( $current >= PHP_INT_MAX - 1 ) ? 0 : $current + 1;
	}

	/**
	 * daily の世代番号を1つ進める。形式が不正な日付は option 名を組み立てず、何もしない
	 * （安藤レビュー LOW-1 の格上げ対応・書き込み側の検証）。
	 *
	 * @param string $date_key 'YYYY-MM-DD' 形式の日付。
	 * @return void
	 */
	private static function bump_daily_generation( string $date_key ): void {
		if ( ! self::is_valid_daily_key( $date_key ) ) {
			return;
		}

		$option_name = self::daily_option_name( $date_key );
		$current     = self::sanitize_generation_value( get_option( $option_name, 0 ) );

		// autoload を false にする理由: 日付の数だけ option 行が増えるため、
		// 全リクエストでの読み込み対象にしない（安藤レビュー MEDIUM-2 の是正）。
		update_option( $option_name, self::next_generation( $current ), false );
	}

	/**
	 * monthly の世代番号を1つ進める。形式が不正な月は option 名を組み立てず、何もしない。
	 *
	 * @param string $month_key 'YYYY-MM' 形式の月。
	 * @return void
	 */
	private static function bump_monthly_generation( string $month_key ): void {
		if ( ! self::is_valid_monthly_key( $month_key ) ) {
			return;
		}

		$option_name = self::monthly_option_name( $month_key );
		$current     = self::sanitize_generation_value( get_option( $option_name, 0 ) );

		update_option( $option_name, self::next_generation( $current ), false );
	}

	/**
	 * daily の世代番号を保存する option 名を組み立てる。
	 *
	 * 呼び出し側（get_daily_generation() / bump_daily_generation()）で既に
	 * is_valid_daily_key() による形式検証を済ませている前提。
	 *
	 * @param string $date 'YYYY-MM-DD' 形式の日付。
	 * @return string
	 */
	private static function daily_option_name( string $date ): string {
		return self::DAILY_OPTION_PREFIX . $date;
	}

	/**
	 * monthly の世代番号を保存する option 名を組み立てる。
	 *
	 * @param string $month 'YYYY-MM' 形式の月。
	 * @return string
	 */
	private static function monthly_option_name( string $month ): string {
		return self::MONTHLY_OPTION_PREFIX . $month;
	}

	/**
	 * 'YYYY-MM-DD' 形式かつ実在する日付であるかを検証する（安藤レビュー LOW-1 の格上げ対応）。
	 *
	 * 分割後は日付の値がそのまま option 名の一部になるため、桁数だけでなく
	 * checkdate() で実在する日付（例: 2月30日ではない）かどうかまで確認する。
	 *
	 * @param string $date 検証する値。
	 * @return bool
	 */
	private static function is_valid_daily_key( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	/**
	 * 'YYYY-MM' 形式かつ実在する月（01〜12）であるかを検証する。
	 *
	 * @param string $month 検証する値。
	 * @return bool
	 */
	private static function is_valid_monthly_key( string $month ): bool {
		if ( ! preg_match( '/^\d{4}-(\d{2})$/', $month, $matches ) ) {
			return false;
		}

		$month_number = (int) $matches[1];

		return $month_number >= 1 && $month_number <= 12;
	}

	/**
	 * option から読み取った世代番号を防御的にサニタイズする（壊れた値は0扱いにする）。
	 *
	 * @param mixed $value option の生の値。
	 * @return int 0以上の整数。
	 */
	private static function sanitize_generation_value( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$generation = (int) $value;

		return $generation < 0 ? 0 : $generation;
	}

	/**
	 * 今日・今月より前の日付・月ぶんの option 行を間引く。1日1回だけ実行すれば十分なため
	 * （過去日の空き状況が再度参照されることはない）、最後に実行した日付を
	 * LAST_PRUNED_OPTION_NAME へ記録し、今日と同じであれば何もしない。
	 *
	 * 新たな WP-Cron は登録せず、既存の flush_bump()（shutdown フック）に相乗りする形で
	 * 実行する（司の指示・有効化/無効化やマルチサイトの考慮を増やさないため）。
	 *
	 * @return void
	 */
	private static function maybe_prune_stale_options(): void {
		$today = current_datetime()->format( 'Y-m-d' );

		$last_pruned = get_option( self::LAST_PRUNED_OPTION_NAME, '' );
		if ( $today === $last_pruned ) {
			return;
		}

		self::prune_stale_daily_options( $today );
		self::prune_stale_monthly_options( current_datetime()->format( 'Y-m' ) );

		update_option( self::LAST_PRUNED_OPTION_NAME, $today, false );
	}

	/**
	 * $today より前の日付が埋め込まれた daily option 行を、前方一致検索で洗い出して削除する。
	 *
	 * @param string $today 'YYYY-MM-DD' 形式の今日の日付（サイトのタイムゾーン）。
	 * @return void
	 */
	private static function prune_stale_daily_options( string $today ): void {
		global $wpdb;

		$like = $wpdb->esc_like( self::DAILY_OPTION_PREFIX ) . '%';

		// option 名は「接頭辞＋ISO形式の日付（YYYY-MM-DD）」で、接頭辞の長さが揃っているため
		// 辞書順＝日付順になる。そのため上限（今日の option 名）を SQL の WHERE 句へ足すことで、
		// 未来日ぶんの行を PHP 側へ取り出す前に絞り込める（安藤・再レビュー LOW-2 の是正。
		// 削除の実行そのものはオブジェクトキャッシュとの整合のため delete_option() のままにする）。
		$upper_bound = self::DAILY_OPTION_PREFIX . $today;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 1日1回だけ実行する掃除処理であり、options テーブルへの前方一致検索は core API に代替手段が無い。
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$like,
				$upper_bound
			)
		);

		foreach ( $option_names as $option_name ) {
			$date_key = substr( $option_name, strlen( self::DAILY_OPTION_PREFIX ) );

			// 形式が壊れている行（他機能の option が偶然前方一致した等）は消さずに残す（安全側）。
			// SQL 側で上限は絞り込み済みのため、ここでの日付比較は不要（形式検証のみ行う）。
			if ( ! self::is_valid_daily_key( $date_key ) ) {
				continue;
			}

			delete_option( $option_name );
		}
	}

	/**
	 * $this_month より前の月が埋め込まれた monthly option 行を、前方一致検索で洗い出して削除する。
	 *
	 * @param string $this_month 'YYYY-MM' 形式の今月（サイトのタイムゾーン）。
	 * @return void
	 */
	private static function prune_stale_monthly_options( string $this_month ): void {
		global $wpdb;

		$like = $wpdb->esc_like( self::MONTHLY_OPTION_PREFIX ) . '%';

		// prune_stale_daily_options() と同じ理由（辞書順＝月順になる上限を SQL 側へ足して
		// 未来月ぶんを取り出さないようにする。安藤・再レビュー LOW-2 の是正）。
		$upper_bound = self::MONTHLY_OPTION_PREFIX . $this_month;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prune_stale_daily_options() と同じ理由。
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$like,
				$upper_bound
			)
		);

		foreach ( $option_names as $option_name ) {
			$month_key = substr( $option_name, strlen( self::MONTHLY_OPTION_PREFIX ) );

			// SQL 側で上限は絞り込み済みのため、ここでの月比較は不要（形式検証のみ行う）。
			if ( ! self::is_valid_monthly_key( $month_key ) ) {
				continue;
			}

			delete_option( $option_name );
		}
	}
}
