<?php
/**
 * 予約の状態変化を Google カレンダーの予定（作成・更新・削除）へ反映するクラス。
 *
 * issue #476（親 issue #94: Google カレンダー連携）。#474 の
 * `vkbm_booking_state_changed` フックを購読し、#475 の接続・API クライアントを使って
 * 実際にカレンダーへ書き込む。詳細は docs/specification-google-calendar-event-sync.md 参照。
 *
 * ## 予約の保存中は Google へ通信しない（issue の実装方針）
 * `handle_state_changed()`（フックの購読側）は Google への通信を一切行わず、
 * `wp_schedule_single_event()` で WP-Cron へ実行を委譲するだけで終える。リマインダー通知
 * （`Booking_Notification_Service`）と同じ方式。予約完了画面・管理画面の保存が、
 * Google 側の応答を待って遅くなることを防ぐため。
 *
 * `vkbm_booking_state_changed` は `before_delete_post` からも同期発火するため
 * （`Booking_Event_Dispatcher` の仕様書参照）、この購読側は必ず try/catch で囲み、
 * 例外が `wp_delete_post()` の完了を妨げないようにする。
 *
 * ## 何を送るかは実行時に判定し直す（$event 引数は使わない）
 * スケジュールした時点の種別（created/updated/cancelled 等）をそのまま信じると、
 * 実行が遅延している間に予約がさらに変化した場合に古い内容を送ってしまう。
 * そのため cron 実行時（`handle_sync()`）に `determine_action()` で「今の DB の状態」から
 * upsert（作成・更新）/ delete（削除）/ skip（何もしない）を判定し直す。
 *
 * ## 予定 ID は予約 ID から決定的に組み立てる（安藤レビュー指摘）
 * 完全削除された予約は cron 実行時には投稿・メタを読めない。また、Google への作成リクエストが
 * タイムアウトした直後の再試行や、cron が並行して動いた場合、通常は「作成に成功したのに
 * 応答が届かず失敗と誤認して再作成する」ことで予定が重複しうる。これらをまとめて解決するため、
 * Google 側の予定 ID を予約ごとに保存する代わりに、予約 ID とサイト固有の値から
 * {@see get_deterministic_event_id()} が毎回同じ値を計算する。作成時はこの ID を明示的に
 * 指定し、既に同じ ID の予定があれば Google が `409` を返すので、その場合は更新に切り替える。
 * 完全削除された予約の削除も、投稿が読めなくても ID を計算できるため、スナップショットを
 * 持ち回る必要が無い。
 *
 * サイト固有の値には `wp_salt( 'auth' )` を使わず、この機能専用に一度だけ生成して DB に
 * 保存した値（{@see get_event_id_site_key()}）を使う。salt は鍵の再生成やサーバー移転で
 * 変わりうる値のため、混ぜてしまうと以後 ID が変わり、既存の予定を更新・削除できなくなる
 * （安藤レビュー指摘）。
 *
 * ## 連携より前からある予約は登録しない
 * まだ一度も同期したことがない予約について、投稿の作成日時（`post_date_gmt`）と接続の
 * 開始日時（`Google_Calendar_Connection::get_state()['connected_at']`）を比較する
 * （issue 本文の例に沿った判定。{@see is_eligible_for_new_event()}）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Error;
use WP_Post;
use function __;
use function absint;
use function add_action;
use function add_query_arg;
use function admin_url;
use function bin2hex;
use function check_admin_referer;
use function current_user_can;
use function delete_option;
use function delete_post_meta;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_transient;
use function hash;
use function is_string;
use function is_wp_error;
use function random_bytes;
use function sanitize_key;
use function set_transient;
use function strtotime;
use function substr;
use function time;
use function update_option;
use function update_post_meta;
use function vkbm_get_resource_display_name;
use function vkbm_get_no_nomination_label;
use function vkbm_get_resource_label_singular;
use function wp_cache_delete;
use function wp_die;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_schedule_single_event;
use function wp_strip_all_tags;
use function wp_timezone;
use function wp_unslash;

/**
 * 予約の状態変化を Google カレンダーの予定へ反映するクラス。
 */
class Google_Calendar_Event_Sync {

	/**
	 * WP-Cron の action 名（同期処理の実行）。
	 *
	 * @var string
	 */
	private const CRON_ACTION = 'vkbm_google_calendar_sync_booking';

	/**
	 * 「今すぐ再試行」の admin-post.php action 名。
	 *
	 * @var string
	 */
	public const ACTION_RETRY_NOW = 'vkbm_google_calendar_retry_sync';

	/**
	 * 再試行までの待ち時間（秒）。`Booking_Notification_Service::RETRY_DELAY` と同じ値を使う
	 * （通知クラスの再試行の仕組みに倣う。issue 本文の実装方針）。
	 *
	 * @var int
	 */
	private const RETRY_DELAY = 300;

	/**
	 * 最大試行回数。`Booking_Notification_Service::MAX_ATTEMPTS` と同じ値を使う。
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * 「今すぐ再試行」の連打を防ぐ間隔（秒）。
	 *
	 * @var int
	 */
	private const RETRY_THROTTLE_SECONDS = 30;

	/**
	 * 予約メタキー（Google 側の予定 ID）。
	 *
	 * 値は常に {@see get_deterministic_event_id()} が計算する決定的な ID と一致する
	 * （予約ごとに一意なランダム値ではない）。
	 *
	 * 「反映に成功した」ことの印ではなく、「Google 側に、この ID の予定が存在するかもしれない」
	 * ことの印である点に注意（安藤レビュー指摘 MEDIUM）。{@see execute_upsert()} は
	 * `create_event()` を呼ぶ**前**にこの値を保存する。タイムアウト・5xx 等で応答が届かず
	 * 失敗と判定した場合でも、Google 側では実際に作成が完了していることがあるため。
	 * 先に保存しておくことで、そのあと予約がキャンセル・削除された場合に
	 * {@see determine_action()} が「一度も同期していない」と誤判定して DELETE を送らず、
	 * Google 側に予定が残り続ける事態を防ぐ。
	 *
	 * 「この予約が一度でも作成を試みたか」の判定と、管理画面での確認用に使う。
	 *
	 * @var string
	 */
	public const META_EVENT_ID = '_vkbm_google_calendar_event_id';

	/**
	 * 予約メタキー（再試行しても反映できなかったことを示すフラグ）。
	 *
	 * `Setup_Notices::get_failed_google_calendar_bookings()` が、サイト全体のお知らせに
	 * 失敗中の予約を列挙するために `meta_query` で参照するため public にしている
	 * （植草レビュー指摘 中）。
	 *
	 * @var string
	 */
	public const META_SYNC_FAILED = '_vkbm_google_calendar_sync_failed';

	/**
	 * 再試行の連打防止に使う transient 名の接頭辞。
	 *
	 * @var string
	 */
	private const RETRY_THROTTLE_TRANSIENT_PREFIX = 'vkbm_gcal_retry_throttle_';

	/**
	 * サイト全体の「再試行しても反映できていない」ことを示す option 名
	 * （{@see \VKBookingManager\Admin\Setup_Notices} が読む）。値は自由文字列では無く
	 * {@see REASON_AUTH} / {@see REASON_OTHER} のいずれか。
	 *
	 * @var string
	 */
	public const OPTION_SYNC_BROKEN = 'vkbm_google_calendar_sync_broken';

	/**
	 * 失敗理由: Google への認可が失われた可能性がある（`connection` が `error` 状態）。
	 *
	 * @var string
	 */
	public const REASON_AUTH = 'auth';

	/**
	 * 失敗理由: 認可以外の理由（日時が無い・一時的な通信失敗等）。
	 *
	 * @var string
	 */
	public const REASON_OTHER = 'other';

	/**
	 * 「今すぐ再試行」の結果を伝える URL クエリ引数名。
	 *
	 * @var string
	 */
	private const QUERY_VAR_RETRY_RESULT = 'vkbm_gcal_retry';

	/**
	 * 予定 ID の計算に使う、サイト固有のランダム値を保存する option 名。
	 *
	 * {@see get_event_id_site_key()} が一度だけ生成して保存し、以後はこの値を読み直して使う。
	 * autoload はしない（毎リクエストで読む値ではなく、Google 連携が使われるときだけ必要な
	 * ため）。
	 *
	 * @var string
	 */
	private const OPTION_EVENT_ID_KEY = 'vkbm_google_calendar_event_id_key';

	/**
	 * 予約メタキー（開始日時）。
	 *
	 * @var string
	 */
	private const META_DATE_START = '_vkbm_booking_service_start';

	/**
	 * 予約メタキー（サービス終了日時）。
	 *
	 * @var string
	 */
	private const META_DATE_END = '_vkbm_booking_service_end';

	/**
	 * 予約メタキー（後片付け込みの終了日時）。
	 *
	 * @var string
	 */
	private const META_TOTAL_END = '_vkbm_booking_total_end';

	/**
	 * 予約メタキー（担当スタッフ／リソースID）。
	 *
	 * @var string
	 */
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';

	/**
	 * 予約メタキー（サービスメニューID）。
	 *
	 * @var string
	 */
	private const META_SERVICE_ID = '_vkbm_booking_service_id';

	/**
	 * 予約メタキー（人数）。
	 *
	 * @var string
	 */
	private const META_GUESTS = '_vkbm_booking_guests';

	/**
	 * 予約メタキー（顧客名）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER = '_vkbm_booking_customer_name';

	/**
	 * 予約メタキー（顧客メールアドレス）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER_MAIL = '_vkbm_booking_customer_email';

	/**
	 * 予約メタキー（顧客電話番号）。
	 *
	 * @var string
	 */
	private const META_CUSTOMER_TEL = '_vkbm_booking_customer_tel';

	/**
	 * 予約メタキー（お客様からのメモ・ご要望）。
	 *
	 * @var string
	 */
	private const META_NOTE = '_vkbm_booking_note';

	/**
	 * 予約メタキー（予約ステータス：confirmed/pending/cancelled/no_show）。
	 *
	 * @var string
	 */
	private const META_STATUS = '_vkbm_booking_status';

	/**
	 * 予約ステータス（メタ値）。
	 *
	 * @var string
	 */
	private const STATUS_CANCELLED = 'cancelled';

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * Google の API を呼ぶクライアント。
	 *
	 * @var Google_Calendar_Api_Client
	 */
	private $api_client;

	/**
	 * 連携が使える状態か（Pro 版 ＋ 中継サーバーの接続先が決まっているか）を判定するクラス。
	 *
	 * @var Google_Calendar_Connect_Controller
	 */
	private $connect_controller;

	/**
	 * 予定に載せる情報の設定。
	 *
	 * @var Google_Calendar_Event_Sync_Settings
	 */
	private $sync_settings;

	/**
	 * 「今すぐ再試行」に必要な権限。予約編集と同じ権限を使う。
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * コンストラクタ。
	 *
	 * @param Google_Calendar_Connection          $connection         接続状態。
	 * @param Google_Calendar_Api_Client          $api_client         Google の API を呼ぶクライアント。
	 * @param Google_Calendar_Connect_Controller  $connect_controller 連携が使える状態かの判定。
	 * @param Google_Calendar_Event_Sync_Settings $sync_settings      予定に載せる情報の設定。
	 * @param string                              $capability         「今すぐ再試行」に必要な権限。
	 */
	public function __construct(
		Google_Calendar_Connection $connection,
		Google_Calendar_Api_Client $api_client,
		Google_Calendar_Connect_Controller $connect_controller,
		Google_Calendar_Event_Sync_Settings $sync_settings,
		string $capability = 'vkbm_manage_reservations'
	) {
		$this->connection         = $connection;
		$this->api_client         = $api_client;
		$this->connect_controller = $connect_controller;
		$this->sync_settings      = $sync_settings;
		$this->capability         = $capability;
	}

	/**
	 * この機能が画面に出ている状態か（Pro 版 ＋ 中継サーバーの接続先が決まっているか）を返す。
	 *
	 * @return bool 使える状態なら true。
	 */
	public function is_available(): bool {
		return $this->connect_controller->is_available();
	}

	/**
	 * WordPress のフックへ登録する。
	 *
	 * 中継サーバーの接続先が決まっていない間は、フックそのものを登録しない
	 * （`Google_Calendar_Connect_Controller::register()` と同じ考え方）。
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_action( 'vkbm_booking_state_changed', array( $this, 'handle_state_changed' ), 10, 3 );
		add_action( self::CRON_ACTION, array( $this, 'handle_sync' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_RETRY_NOW, array( $this, 'handle_retry_now' ) );
	}

	/**
	 * `vkbm_booking_state_changed` の購読側。Google へは通信せず、WP-Cron へジョブを積むだけ。
	 *
	 * `before_delete_post` からも同期発火するため、必ず try/catch で囲み、ここでの例外が
	 * `wp_delete_post()` の完了を妨げないようにする（`Booking_Event_Dispatcher` の仕様書参照）。
	 *
	 * @param string               $event      種別（このクラスでは使わない。実行時に再判定するため）。
	 * @param int                  $booking_id 予約投稿ID。
	 * @param array<string, mixed> $payload    ペイロード（このクラスでは使わない）。
	 * @return void
	 */
	public function handle_state_changed( string $event, int $booking_id, array $payload ): void {
		unset( $event, $payload );

		try {
			if ( ! $this->connection->is_ready() ) {
				// 未接続・反映先カレンダー未選択の間は何もしない。
				return;
			}

			$this->schedule_sync( $booking_id, 1, 0 );
		} catch ( Throwable $e ) {
			// 外部連携側のエラーで wp_delete_post() 等の完了を妨げない（#474 仕様書参照）。
			unset( $e );
		}
	}

	/**
	 * WP-Cron のジョブを積む。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @param int $attempt    試行回数（1始まり）。
	 * @param int $delay      現在時刻からの遅延秒数。
	 * @return void
	 */
	private function schedule_sync( int $booking_id, int $attempt, int $delay ): void {
		wp_schedule_single_event(
			time() + $delay,
			self::CRON_ACTION,
			array( $booking_id, $attempt )
		);
	}

	/**
	 * WP-Cron から呼ばれる、実際に Google と通信する処理。
	 *
	 * 例外は失敗として扱い、再試行・失敗フラグの経路へ合流させる（安藤レビュー指摘。
	 * 未捕捉の例外が WP-Cron のジョブを黙って落とすと、以後この予約が二度と再試行されない）。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @param int $attempt    試行回数（1始まり）。
	 * @return void
	 */
	public function handle_sync( int $booking_id, int $attempt ): void {
		try {
			if ( ! $this->is_available() || ! $this->connection->is_ready() ) {
				// 実行が遅延している間に連携が切れた・解除された場合はジョブを捨てる。
				return;
			}

			$action = $this->determine_action( $booking_id );

			if ( 'skip' === $action ) {
				$this->clear_booking_failure( $booking_id );
				return;
			}

			$result = 'delete' === $action
				? $this->execute_delete( $booking_id )
				: $this->execute_upsert( $booking_id );

			if ( is_wp_error( $result ) ) {
				$this->handle_sync_failure( $booking_id, $attempt, $result );
				return;
			}

			$this->clear_booking_failure( $booking_id );
			$this->clear_global_failure();
		} catch ( Throwable $e ) {
			$this->handle_sync_failure(
				$booking_id,
				$attempt,
				new WP_Error( 'vkbm_google_calendar_sync_exception', $e->getMessage() )
			);
		}
	}

	/**
	 * 同期の失敗を扱う（再試行を積む、または上限到達で失敗フラグを立てる）。
	 *
	 * @param int      $booking_id 予約投稿ID。
	 * @param int      $attempt    今回の試行回数。
	 * @param WP_Error $error      失敗の内容。
	 * @return void
	 */
	private function handle_sync_failure( int $booking_id, int $attempt, WP_Error $error ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->mark_booking_failure( $booking_id );
			$this->mark_global_failure( $this->classify_failure_reason( $error ) );
			return;
		}

		$this->schedule_sync( $booking_id, $attempt + 1, self::RETRY_DELAY );
	}

	/**
	 * 失敗の内容から、サイト全体のお知らせに出す理由を分類する。
	 *
	 * 認可が失われた（`vkbm_google_calendar_unauthorized`）場合だけ「アクセスが失われた
	 * 可能性」の文言にする。日時が無い等、認可と無関係な失敗にまでこの文言を出すと、
	 * オーナーが再接続しても直らず混乱する（植草レビュー指摘・安藤レビュー指摘）。
	 *
	 * @param WP_Error $error 失敗の内容。
	 * @return string self::REASON_* のいずれか。
	 */
	private function classify_failure_reason( WP_Error $error ): string {
		return 'vkbm_google_calendar_unauthorized' === $error->get_error_code() ? self::REASON_AUTH : self::REASON_OTHER;
	}

	/**
	 * 「今の DB の状態」から、この予約に対して何をすべきかを判定する。
	 *
	 * 完全削除された予約（投稿が既に読めない）を含め、予定 ID は常に
	 * {@see get_deterministic_event_id()} で予約IDから計算できるため、削除の可否判定には
	 * post meta の値を必要としない。
	 *
	 * ただし、投稿がまだ読める状態（ゴミ箱・キャンセル）で、かつ一度も作成を試みていない予約
	 * （{@see META_EVENT_ID} が空）は DELETE を送らず skip にする（安藤レビュー指摘）。
	 * 作成を試みたことが無ければ Google 側に対応する予定も無いはずで、DELETE を送っても
	 * 404 を成功扱いにするだけの無駄なリクエストになる上、決定的 ID の計算材料
	 * （{@see get_event_id_site_key()}）がサイト間で衝突した場合に、他サイトが作成した
	 * 同一 ID の予定を誤って消してしまうおそれがあるため。完全削除（投稿が読めない）の
	 * ときは作成を試みたかどうかを判定できないため、従来どおり DELETE を送る。
	 *
	 * `META_EVENT_ID` は「反映に成功した」ことの印ではなく「作成を試みた（Google 側に予定が
	 * あるかもしれない）」ことの印である点に注意（{@see META_EVENT_ID} の docblock 参照。
	 * 安藤レビュー指摘 MEDIUM）。作成が失敗していても、この値が入っていれば DELETE を送る。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return string upsert/delete/skip のいずれか。
	 */
	private function determine_action( int $booking_id ): string {
		$post = get_post( $booking_id );

		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			// 完全に削除済み（または対象外の投稿タイプ）。一度も作られていなくても、
			// delete は Google 側で「既に無い」＝ 404 を成功扱いにするため無害。
			return 'delete';
		}

		$has_attempted_before = '' !== (string) get_post_meta( $booking_id, self::META_EVENT_ID, true );

		if ( 'trash' === $post->post_status ) {
			return $has_attempted_before ? 'delete' : 'skip';
		}

		$status = (string) get_post_meta( $booking_id, self::META_STATUS, true );

		if ( self::STATUS_CANCELLED === $status ) {
			return $has_attempted_before ? 'delete' : 'skip';
		}

		if ( ! $has_attempted_before && ! $this->is_eligible_for_new_event( $post ) ) {
			// 連携より前からある予約は、まだ一度も作成を試みていなければ新規作成しない
			// （issue 本文「連携前からある予約は登録しない」）。
			return 'skip';
		}

		return 'upsert';
	}

	/**
	 * 連携開始後に作られた予約かどうかを、投稿の作成日時と接続の開始日時の比較で判定する。
	 *
	 * @param WP_Post $post 予約投稿。
	 * @return bool 連携後に作成されたなら true。
	 */
	private function is_eligible_for_new_event( WP_Post $post ): bool {
		$connected_at = (int) $this->connection->get_state()['connected_at'];

		if ( $connected_at <= 0 ) {
			return false;
		}

		$created_at = strtotime( $post->post_date_gmt . ' GMT' );

		if ( false === $created_at ) {
			return false;
		}

		return $created_at >= $connected_at;
	}

	/**
	 * 予約 ID とサイト固有の値から、Google Calendar API の予定 ID を決定的に組み立てる。
	 *
	 * Google Calendar API はイベント作成時に `id` を指定でき、`[0-9a-v]`（base32hex）
	 * 5〜1024文字という制約がある。SHA-256 の16進数表現（`[0-9a-f]`）はこの文字集合の
	 * 部分集合なので、そのまま使える。{@see get_event_id_site_key()} が返す値を混ぜているのは、
	 * 同じ Google アカウント・カレンダーを複数サイトが共有していても ID が衝突しないようにするため。
	 *
	 * 同じ予約 ID に対しては常に同じ値を返すため、作成リクエストがタイムアウトした直後の
	 * 再試行や、cron が並行して動いた場合でも、常に同じ ID で作成を試みることになり、
	 * 予定が重複して作られることがない（安藤レビュー指摘）。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return string 決定的な予定 ID。
	 */
	private function get_deterministic_event_id( int $booking_id ): string {
		return substr( hash( 'sha256', $this->get_event_id_site_key() . '|vkbm-booking-' . $booking_id ), 0, 40 );
	}

	/**
	 * 予定 ID の計算に使う、サイト固有のランダム値を返す。無ければ生成して保存する。
	 *
	 * `wp_salt( 'auth' )` を材料にしていた旧実装は、鍵の再生成やサーバー移転で値が変わり、
	 * 既存の予定を更新・削除できなくなる問題があった（安藤レビュー指摘）。この機能専用の
	 * ランダム値を一度だけ生成して DB に保存し、以後はその値を読み直して使うことで解決する。
	 *
	 * `add_option()` は使わない。`add_option()` は「無い」ことを `notoptions` キャッシュで
	 * 確認したあと `INSERT ... ON DUPLICATE KEY UPDATE` で書き込むため、この存在確認と書き込み
	 * の間に他のプロセスが割り込むと、後から書き込んだ側が先に生成された値を**無条件に
	 * 上書きしてしまう**（安藤レビュー指摘 LOW。読み直せば同じ値になるという前提が崩れる）。
	 *
	 * 代わりに `INSERT IGNORE` を使う。`option_name` の一意制約により、2つのプロセスが
	 * ほぼ同時に書き込もうとしても、先に挿入できた側の行だけが残り、後発の呼び出しは
	 * 何も書き込めずに終わる（DB の一意制約で解決するため、存在確認とのすり合わせが不要）。
	 * `INSERT IGNORE` は options/notoptions キャッシュを更新しないため、直後に明示的に
	 * キャッシュを消してから `get_option()` で読み直す（消さずに読むと、このリクエストが
	 * 直前に見た「無い」というキャッシュがそのまま返ってしまう）。
	 *
	 * @return string サイト固有のランダム値（64文字の16進数文字列）。
	 */
	private function get_event_id_site_key(): string {
		$existing = get_option( self::OPTION_EVENT_ID_KEY );

		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}

		global $wpdb;

		$generated = bin2hex( random_bytes( 32 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE ＋ option_name の一意制約によるアトミックな初回書き込み。add_option() は存在確認と書き込みの間に割り込まれると上書きしてしまうため使えない（上記コメント参照。class-booking-confirmation-controller.php の check_capacity_with_mutex() と同じ考え方）。
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION_EVENT_ID_KEY,
				$generated
			)
		);

		// INSERT IGNORE は options/notoptions キャッシュを更新しないため、明示的に消してから
		// 読み直す（消さずに get_option() を呼ぶと、直前に見た「無い」というキャッシュを
		// そのまま返してしまい、今まさに挿入した値を拾えない）。
		wp_cache_delete( self::OPTION_EVENT_ID_KEY, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_option( self::OPTION_EVENT_ID_KEY );

		return is_string( $stored ) && '' !== $stored ? $stored : $generated;
	}

	/**
	 * 予定を作成・更新する。
	 *
	 * 決定的な予定 ID で常に作成（create）を試み、既に同じ ID の予定が存在する場合
	 * （Google が `409` を返す）は更新（update）に切り替える。これにより、確定時の
	 * タイトル書き換え（司の decision record 参照）と、リトライ時の重複防止の両方を
	 * 同じ経路でまかなう。
	 *
	 * `META_EVENT_ID` は `create_event()` を呼ぶ**前**に保存する（安藤レビュー指摘 MEDIUM。
	 * 理由は保存箇所のインラインコメントと `META_EVENT_ID` の docblock を参照）。そのため
	 * 戻り値が `WP_Error`（失敗）であっても、`META_EVENT_ID` は既に保存された状態になる。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return true|WP_Error 成功したら true、失敗の内容。
	 */
	private function execute_upsert( int $booking_id ) {
		$post = get_post( $booking_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'vkbm_google_calendar_booking_missing', __( 'The booking no longer exists.', 'vk-booking-manager' ) );
		}

		$event_body = Google_Calendar_Event_Builder::build( $this->build_event_input( $booking_id, $post ), $this->sync_settings->get_enabled_fields() );

		if ( null === $event_body ) {
			return new WP_Error( 'vkbm_google_calendar_event_invalid', __( 'The booking does not have a date and time.', 'vk-booking-manager' ) );
		}

		$calendar_id = $this->connection->get_calendar_id();
		$event_id    = $this->get_deterministic_event_id( $booking_id );

		// create_event() を呼ぶ「前」に保存する（安藤レビュー指摘 MEDIUM）。タイムアウトや
		// 5xx で応答が届かず失敗と判定した場合でも、Google 側では実際に作成が完了している
		// ことがある。先に保存しておかないと、そのあと予約がキャンセル・削除された場合に
		// determine_action() が「一度も作成を試みていない」と誤判定して DELETE を送らず、
		// Google 側に予定が残り続けてしまう。この時点から META_EVENT_ID の意味は「反映に
		// 成功した」ではなく「Google 側に予定があるかもしれない」印になる（META_EVENT_ID の
		// docblock 参照）。
		update_post_meta( $booking_id, self::META_EVENT_ID, $event_id );

		$result = $this->api_client->create_event( $calendar_id, $event_id, $event_body );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			if ( 'vkbm_google_calendar_event_already_exists' === $code || 'vkbm_google_calendar_event_not_found' === $code ) {
				// 既に同じ ID の予定がある（リトライ・cron の並行実行等）、または稀に
				// created の応答だけ失われた場合。書き換えに倒す。
				$result = $this->api_client->update_event( $calendar_id, $event_id, $event_body );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * 予定を削除する。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return true|WP_Error 成功したら true、失敗の内容。
	 */
	private function execute_delete( int $booking_id ) {
		$calendar_id = $this->connection->get_calendar_id();
		$event_id    = $this->get_deterministic_event_id( $booking_id );
		$result      = $this->api_client->delete_event( $calendar_id, $event_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 投稿が既に完全削除されている場合、存在しない投稿IDに新しく postmeta の行を
		// 作らない（安藤レビュー指摘）。delete_post_meta() 自体は対象が無ければ何もしない
		// 安全な操作だが、意図を明確にするため投稿の存在を確認してから呼ぶ。
		if ( get_post( $booking_id ) instanceof WP_Post ) {
			delete_post_meta( $booking_id, self::META_EVENT_ID );
		}

		return true;
	}

	/**
	 * `Google_Calendar_Event_Builder::build()` へ渡す入力値を、投稿・メタから組み立てる。
	 *
	 * @param int     $booking_id 予約投稿ID。
	 * @param WP_Post $post       予約投稿。
	 * @return array<string, mixed>
	 */
	private function build_event_input( int $booking_id, WP_Post $post ): array {
		$service_id  = (int) get_post_meta( $booking_id, self::META_SERVICE_ID, true );
		$resource_id = (int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true );
		$end         = (string) get_post_meta( $booking_id, self::META_TOTAL_END, true );
		if ( '' === $end ) {
			// 後片付け込みの終了日時が無い（旧データ等）場合はサービス終了日時を使う。
			$end = (string) get_post_meta( $booking_id, self::META_DATE_END, true );
		}

		$resource_name = '';
		if ( $resource_id > 0 ) {
			$resource_name = vkbm_get_resource_display_name( $resource_id );
		}
		if ( '' === $resource_name ) {
			$resource_name = vkbm_get_no_nomination_label();
		}

		return array(
			'booking_id'     => $booking_id,
			'status'         => (string) get_post_meta( $booking_id, self::META_STATUS, true ),
			'service_name'   => $service_id > 0 ? (string) get_the_title( $service_id ) : '',
			'start'          => (string) get_post_meta( $booking_id, self::META_DATE_START, true ),
			'end'            => $end,
			'guests'         => (int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'resource_name'  => $resource_name,
			'resource_label' => vkbm_get_resource_label_singular(),
			'customer_name'  => (string) get_post_meta( $booking_id, self::META_CUSTOMER, true ),
			'customer_tel'   => (string) get_post_meta( $booking_id, self::META_CUSTOMER_TEL, true ),
			'customer_email' => (string) get_post_meta( $booking_id, self::META_CUSTOMER_MAIL, true ),
			'customer_note'  => wp_strip_all_tags( (string) get_post_meta( $booking_id, self::META_NOTE, true ) ),
			// get_edit_post_link() はログイン中の利用者がいない状態（WP-Cron 実行時）では
			// 空文字を返すため使えない（安藤レビュー指摘）。admin_url() で直接組み立てる。
			'admin_edit_url' => admin_url( 'post.php?post=' . $booking_id . '&action=edit' ),
			'timezone'       => wp_timezone()->getName(),
		);
	}

	/**
	 * 予約単位の「反映できていない」フラグを立てる。
	 *
	 * 投稿が既に完全削除されている場合は、存在しない投稿IDへ新しく postmeta の行を
	 * 作らない（安藤レビュー指摘）。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return void
	 */
	private function mark_booking_failure( int $booking_id ): void {
		if ( get_post( $booking_id ) instanceof WP_Post ) {
			update_post_meta( $booking_id, self::META_SYNC_FAILED, '1' );
		}
	}

	/**
	 * 予約単位の「反映できていない」フラグを消す。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return void
	 */
	private function clear_booking_failure( int $booking_id ): void {
		delete_post_meta( $booking_id, self::META_SYNC_FAILED );
	}

	/**
	 * サイト全体の「再試行しても反映できていない」フラグを立てる。
	 *
	 * `Setup_Notices` が、この option を「連携」タブを開ける権限の人にだけ、復旧するまで
	 * 消えないお知らせとして表示する。
	 *
	 * @param string $reason self::REASON_* のいずれか。
	 * @return void
	 */
	private function mark_global_failure( string $reason ): void {
		update_option( self::OPTION_SYNC_BROKEN, $reason, false );
	}

	/**
	 * サイト全体の「再試行しても反映できていない」フラグを消す。
	 *
	 * @return void
	 */
	private function clear_global_failure(): void {
		delete_option( self::OPTION_SYNC_BROKEN );
	}

	/**
	 * ある予約について、編集画面にお知らせを出すべきかどうかと、出す内容を判定する。
	 *
	 * - 連携そのものが切れている（`error` 状態）: 種別 `disconnected`
	 * - この予約だけ反映に失敗している: 種別 `failed`
	 * - それ以外（反映済み・未着手）: null（何も出さない）
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return string|null 'disconnected'|'failed'|null。
	 */
	public function get_booking_notice_type( int $booking_id ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		if ( Google_Calendar_Connection::STATUS_ERROR === $this->connection->get_status() ) {
			return 'disconnected';
		}

		if ( ! $this->connection->is_ready() ) {
			// 未接続・カレンダー未選択の間は「連携タブを開いて設定してください」という
			// お知らせを予約ごとに出すと煩雑になるため、編集画面では何も出さない
			// （「連携」タブ側で案内する）。
			return null;
		}

		if ( '1' === (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ) ) {
			return 'failed';
		}

		return null;
	}

	/**
	 * 予約編集画面（入力欄の上）に、未反映のときだけお知らせを出す。
	 *
	 * 「今すぐ再試行」を押した直後（`?vkbm_gcal_retry=accepted`）は、まだ同期が終わって
	 * いないため、失敗中の表示を消さず「受け付けました」の案内だけを重ねて出す
	 * （植草レビュー指摘）。連打防止に引っかかった場合（`?vkbm_gcal_retry=throttled`）も
	 * 同様に、行き止まりにならない案内を出す。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return void
	 */
	public function render_booking_notice( int $booking_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する内容の切り替えのみで、変更は伴わない（Google_Calendar_Settings_Panel::render() と同じ考え方）.
		$retry_result = isset( $_GET[ self::QUERY_VAR_RETRY_RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR_RETRY_RESULT ] ) ) : '';

		if ( 'accepted' === $retry_result ) {
			?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'The retry request has been accepted. It may take a little while to be reflected.', 'vk-booking-manager' ); ?></p>
			</div>
			<?php
		} elseif ( 'throttled' === $retry_result ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Please wait a little before retrying.', 'vk-booking-manager' ); ?></p>
			</div>
			<?php
		}

		$type = $this->get_booking_notice_type( $booking_id );

		if ( null === $type ) {
			return;
		}

		if ( 'disconnected' === $type ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This booking has not been reflected in Google Calendar because the connection to Google has been lost.', 'vk-booking-manager' ); ?></p>
				<?php if ( current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) : ?>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( Google_Calendar_Connect_Controller::get_settings_tab_url() ); ?>">
							<?php esc_html_e( 'Open the Integration tab', 'vk-booking-manager' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Please ask your site administrator to reconnect on the Integration tab.', 'vk-booking-manager' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		// 「今すぐ再試行」直後（受け付けました／少し待って）のお知らせを出した直後は、
		// 同じ内容を二重に出さないよう、通常の「未反映」お知らせは省く。
		if ( '' !== $retry_result ) {
			return;
		}

		?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'This booking has not been reflected in Google Calendar.', 'vk-booking-manager' ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $this->get_retry_now_url( $booking_id ) ); ?>">
					<?php esc_html_e( 'Retry now', 'vk-booking-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * 「今すぐ再試行」の URL（admin-post.php への GET リンク）を組み立てる。
	 *
	 * `<form>` ではなく `<a>` リンクにしているのは、予約編集画面が既にひとつの `<form>` で
	 * 覆われており、HTML の仕様上 `<form>` を入れ子にできないため（入れ子にすると、外側の
	 * フォームの submit ボタンが内側のフォームに奪われる等、ブラウザの実装によって不定の
	 * 挙動になる。安藤レビュー指摘 HIGH）。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return string URL。
	 */
	private function get_retry_now_url( int $booking_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::ACTION_RETRY_NOW,
					'booking_id' => $booking_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_RETRY_NOW . '_' . $booking_id,
			'_vkbm_gcal_retry_nonce'
		);
	}

	/**
	 * 「今すぐ再試行」を押されたときの処理。
	 *
	 * `<a>` リンク（GET リクエスト）で送られてくるため `$_GET` から受け取る。
	 * 連打防止のため、短い間隔での再実行は無視する（結果は予約編集画面へのクエリ引数で
	 * 伝える。{@see render_booking_notice()}）。
	 *
	 * @return void
	 */
	public function handle_retry_now(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 直後の check_admin_referer() で確認する.
		$booking_id = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;

		if ( $booking_id <= 0 ) {
			wp_die( esc_html__( 'Invalid request.', 'vk-booking-manager' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::ACTION_RETRY_NOW . '_' . $booking_id, '_vkbm_gcal_retry_nonce' );

		// 予約以外の投稿IDを渡されても処理しない（安藤レビュー指摘）。
		$post = get_post( $booking_id );
		if ( ! $post instanceof WP_Post || Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid request.', 'vk-booking-manager' ), '', array( 'response' => 400 ) );
		}

		if ( ! current_user_can( $this->capability, $booking_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'vk-booking-manager' ), '', array( 'response' => 403 ) );
		}

		$retry_result = 'accepted';

		if ( $this->is_throttled( $booking_id ) ) {
			$retry_result = 'throttled';
		} else {
			set_transient( self::RETRY_THROTTLE_TRANSIENT_PREFIX . $booking_id, 1, self::RETRY_THROTTLE_SECONDS );
			// 失敗フラグはここでは消さない。同期がまだ終わっていないため、結果が出るまでは
			// 「未反映」の扱いを保つ（植草レビュー指摘）。実際に消えるのは handle_sync() の
			// 成功時のみ。
			$this->schedule_sync( $booking_id, 1, 0 );
		}

		$redirect = add_query_arg(
			self::QUERY_VAR_RETRY_RESULT,
			$retry_result,
			admin_url( 'post.php?post=' . $booking_id . '&action=edit' )
		);

		$this->redirect_after_retry( $redirect );
	}

	/**
	 * 予約編集画面へ戻す。
	 *
	 * 送り出しと同時に処理を終える（`exit`）。テストから呼べるように、`private` ではなく
	 * `protected` にして差し替えられるようにしている
	 * （`Google_Calendar_Connect_Controller::redirect_to_settings()` と同じ考え方）。
	 *
	 * @param string $url 送り先の URL。
	 * @return void
	 */
	protected function redirect_after_retry( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 「今すぐ再試行」の連打を控えるべきかどうかを返す。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return bool 控えるべきなら true。
	 */
	private function is_throttled( int $booking_id ): bool {
		return false !== get_transient( self::RETRY_THROTTLE_TRANSIENT_PREFIX . $booking_id );
	}
}
