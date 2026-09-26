<?php
/**
 * 予約の状態変化を Google カレンダーの予定へ反映するクラスのテスト。
 *
 * issue #476。Google への通信は `pre_http_request` フィルターで差し替える
 * （#475 のテストと同じ方式）。安藤レビュー・植草レビューの差し戻しで、予定 ID を
 * 決定的に計算する方式（get_deterministic_event_id()）・GET リンクでの「今すぐ再試行」・
 * 受け付け直後のお知らせに合わせて全面的に書き直した。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Api_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_UnitTestCase;
use WPDieException;
use function add_filter;
use function delete_option;
use function delete_post_meta;
use function delete_transient;
use function do_action;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_user_by;
use function ob_get_clean;
use function ob_start;
use function remove_action;
use function remove_filter;
use function update_option;
use function update_post_meta;
use function wp_cache_delete;
use function wp_cache_set;
use function wp_clear_scheduled_hook;
use function wp_create_nonce;
use function wp_delete_post;
use function wp_next_scheduled;
use function wp_set_current_user;
use function wp_trash_post;

/**
 * Google_Calendar_Event_Sync のテスト。
 */
class Test_Google_Calendar_Event_Sync extends WP_UnitTestCase {

	private const CRON_ACTION = 'vkbm_google_calendar_sync_booking';

	private const META_EVENT_ID    = '_vkbm_google_calendar_event_id';
	private const META_SYNC_FAILED = '_vkbm_google_calendar_sync_failed';

	private const META_DATE_START = '_vkbm_booking_service_start';
	private const META_DATE_END   = '_vkbm_booking_service_end';
	private const META_TOTAL_END  = '_vkbm_booking_total_end';
	private const META_SERVICE_ID = '_vkbm_booking_service_id';
	private const META_STATUS     = '_vkbm_booking_status';

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * テスト対象。
	 *
	 * @var Google_Calendar_Event_Sync
	 */
	private $sync;

	/**
	 * 送信先 URL ごとの応答を並べた配列。
	 *
	 * @var array<string, mixed>
	 */
	private $mock_responses = array();

	/**
	 * `mock_responses` に一致して応答したリクエストの回数（実際に通信を試みたことの確認用）。
	 *
	 * @var int
	 */
	private $matched_request_count = 0;

	/**
	 * `mock_responses` に一致した直近のリクエストの `$args`（送信した本文の検証用。
	 * 安藤レビュー指摘: 409→update の経路で本文に `status` が入ることの確認に使う）。
	 *
	 * @var array<string, mixed>
	 */
	private $last_matched_request_args = array();

	/**
	 * HTTP 通信を差し替えるフィルターの参照（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * 中継サーバーの接続先を仮の値に差し替えるフィルターの参照（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $relay_url_filter = null;

	/**
	 * 各テストの前に、接続済みの状態を作る準備をし、HTTP 通信を差し替える。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Google カレンダー連携は Pro 版限定機能のため、無料版ビルドでは同期処理が動かない。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );
		delete_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN );
		delete_transient( 'vkbm_google_calendar_refresh_throttle' );
		wp_clear_scheduled_hook( self::CRON_ACTION );

		$this->connection   = new Google_Calendar_Connection();
		$relay_client       = new Google_Calendar_Relay_Client();
		$api_client         = new Google_Calendar_Api_Client( $this->connection, $relay_client );
		$connect_controller = new Google_Calendar_Connect_Controller( $this->connection, $relay_client, $api_client, 'manage_options' );
		$sync_settings      = new Google_Calendar_Event_Sync_Settings();

		$this->sync = new Spy_Event_Sync( $this->connection, $api_client, $connect_controller, $sync_settings, Capabilities::MANAGE_RESERVATIONS );

		$this->http_filter = function ( $preempt, $args, $url ) {
			foreach ( $this->mock_responses as $needle => $response ) {
				if ( false !== strpos( (string) $url, (string) $needle ) ) {
					++$this->matched_request_count;
					$this->last_matched_request_args = $args;
					return $response;
				}
			}

			return $preempt;
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$this->relay_url_filter = static function (): string {
			return 'https://relay.test';
		};
		add_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
	}

	/**
	 * 各テストの後に、差し替えと保存内容を元へ戻す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		if ( null !== $this->relay_url_filter ) {
			remove_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
		}

		remove_action( 'vkbm_booking_state_changed', array( $this->sync, 'handle_state_changed' ), 10 );
		remove_action( self::CRON_ACTION, array( $this->sync, 'handle_sync' ), 10 );

		wp_clear_scheduled_hook( self::CRON_ACTION );
		$this->mock_responses = array();
		wp_set_current_user( 0 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );
		delete_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN );
		delete_transient( 'vkbm_google_calendar_refresh_throttle' );

		parent::tear_down();
	}

	/**
	 * 接続済み・反映先カレンダー選択済みの状態を作る。
	 *
	 * @param int $connected_at_offset connected_at を「今」からどれだけずらすか（秒）。
	 *                                  正の値は未来（＝これから作る予約が「連携前」扱いになる）。
	 * @return void
	 */
	private function connect_and_select_calendar( int $connected_at_offset = 0 ): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);
		$this->connection->set_calendar( 'calendar-1', 'My Calendar' );

		if ( 0 !== $connected_at_offset ) {
			$raw                  = get_option( Google_Calendar_Connection::OPTION_NAME );
			$raw['connected_at'] += $connected_at_offset;
			update_option( Google_Calendar_Connection::OPTION_NAME, $raw, false );
		}
	}

	/**
	 * 予約投稿を作成する。
	 *
	 * @param string $status 予約ステータス。
	 * @return int 予約投稿ID。
	 */
	private function create_booking( string $status = 'confirmed' ): int {
		$service_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'カット',
			)
		);

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, self::META_DATE_START, '2026-10-01 10:00:00' );
		update_post_meta( $booking_id, self::META_DATE_END, '2026-10-01 10:30:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, '2026-10-01 10:30:00' );
		update_post_meta( $booking_id, self::META_SERVICE_ID, $service_id );
		update_post_meta( $booking_id, self::META_STATUS, $status );

		return $booking_id;
	}

	/**
	 * `Google_Calendar_Event_Sync::get_deterministic_event_id()`（private）をテストから呼ぶ。
	 *
	 * 安藤レビュー指摘対応で、予定 ID は予約IDから決定的に計算する方式にしたため、
	 * モックする URL を組み立てるにはテスト側でも同じ値を知る必要がある。
	 *
	 * @param int $booking_id 予約投稿ID。
	 * @return string 決定的な予定ID。
	 */
	private function deterministic_event_id( int $booking_id ): string {
		$method = new ReflectionMethod( Google_Calendar_Event_Sync::class, 'get_deterministic_event_id' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->sync, $booking_id );
	}

	/**
	 * `vkbm_booking_state_changed` の購読側が、Google へ通信せず WP-Cron へジョブを
	 * 積むだけで終えることを検証する（issue の実装方針: 予約の保存中は Google へ通信しない）。
	 */
	public function test_handle_state_changed_schedules_cron_without_calling_google(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();

		// もし誤って Google へ通信すれば、この WP_Error で必ず失敗させる（安全網）。
		$this->mock_responses = array(
			'googleapis.com' => new WP_Error( 'should_not_be_called', '予約保存中に Google へ通信してはいけない' ),
		);

		$this->sync->register();
		do_action( 'vkbm_booking_state_changed', 'created', $booking_id, array() );

		$scheduled = wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 1 ) );
		$this->assertNotFalse( $scheduled, '初回試行のジョブが積まれること' );
		$this->assertLessThanOrEqual( time() + 5, $scheduled, '初回試行はほぼ即時（遅延無し）でスケジュールされること' );

		// メタが未反映のままであること（Google へ通信していない証拠）。
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ) );
		$this->assertSame( 0, $this->matched_request_count, '購読側の処理中に Google への通信が発生していないこと' );
	}

	/**
	 * 未接続・反映先カレンダー未選択のときは、ジョブを積まないことを検証する（異常系）。
	 */
	public function test_handle_state_changed_does_nothing_when_not_ready(): void {
		$booking_id = $this->create_booking();

		$this->sync->handle_state_changed( 'created', $booking_id, array() );

		$this->assertFalse( wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 1 ) ), '未接続のときはジョブを積まないこと' );
	}

	/**
	 * handle_sync() の判定・実行をまとめて検証する（determine_action() の分岐を含む）。
	 * 予定 ID は予約IDから決定的に計算されるため、新規作成でも常に create を先に試み、
	 * 既に存在する場合（409）は update に切り替わることも合わせて確認する。
	 */
	public function test_handle_sync(): void {
		// --- 正常系1: 連携後に作られた予約 → 新規作成（create 成功） ---
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		$event_id   = $this->deterministic_event_id( $booking_id );

		$this->mock_responses = array(
			'events?sendUpdates' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"' . $event_id . '"}',
			),
		);
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertSame( $event_id, (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), '新規作成に成功したら決定的な予定IDを保存すること' );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ), '成功したら失敗フラグが立っていないこと' );

		// --- 正常系2: 2回目以降も create を先に試みるが、既に同じIDの予定があるため
		// 409 が返り、update へ切り替わる（確定時のタイトル書き換え・リトライ時の
		// 重複防止を同じ経路で扱う設計。安藤レビュー指摘） ---
		$this->mock_responses = array(
			'events?sendUpdates'  => array(
				'response' => array( 'code' => 409 ),
				'body'     => '{"error":{"code":409,"message":"The requested identifier already exists."}}',
			),
			'events/' . $event_id => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"' . $event_id . '"}',
			),
		);
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertSame( $event_id, (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), '409 のときは同じ予定IDのまま更新すること（重複作成しない）' );

		// --- 正常系3: キャンセル → 削除 ---
		update_post_meta( $booking_id, self::META_STATUS, 'cancelled' );
		$this->mock_responses = array(
			'events/' . $event_id => array(
				'response' => array( 'code' => 204 ),
				'body'     => '',
			),
		);
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), 'キャンセルで予定IDメタが消えること' );

		// --- 異常系（境界値）: 連携より前からある予約（まだ同期していない）は新規作成しない ---
		$pre_existing_booking = $this->create_booking();
		// 接続を「これから」に見せかけて、この予約を連携前扱いにする。
		$this->connect_and_select_calendar( HOUR_IN_SECONDS );
		$this->mock_responses = array(
			'googleapis.com' => new WP_Error( 'should_not_be_called', '連携前からある予約は新規作成してはいけない' ),
		);
		$this->sync->handle_sync( $pre_existing_booking, 1 );
		$this->assertSame( '', (string) get_post_meta( $pre_existing_booking, self::META_EVENT_ID, true ), '連携前からある予約には予定を作らないこと' );

		// --- 異常系: 未接続・カレンダー未選択に戻った場合はジョブを実行せず捨てる ---
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		$this->mock_responses = array(
			'googleapis.com' => new WP_Error( 'should_not_be_called', '未接続なら Google へ通信してはいけない' ),
		);
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), '未接続に戻った場合はメタを変更しないこと（直前のキャンセルで既に空になっている）' );
	}

	/**
	 * 予定 ID の計算材料が、`wp_salt( 'auth' )` ではなく option に一度だけ保存した値であることを
	 * 検証する（安藤レビュー指摘: salt を使うと鍵の再生成・サーバー移転で ID が変わり、
	 * 既存の予定を更新・削除できなくなる）。option の値を書き換えると予定IDが変わり、
	 * 同じ値であれば常に同じ予定IDを返す（決定的）ことの両方を確認する。
	 */
	public function test_get_deterministic_event_id_uses_persisted_site_key(): void {
		$option_name = 'vkbm_google_calendar_event_id_key';
		delete_option( $option_name );

		$booking_id = $this->create_booking();

		$first  = $this->deterministic_event_id( $booking_id );
		$stored = get_option( $option_name );

		$this->assertNotFalse( $stored, '初回呼び出しでサイト固有の値が option に保存されること' );
		$this->assertNotSame( '', (string) $stored, '保存される値が空でないこと' );

		$again = $this->deterministic_event_id( $booking_id );
		$this->assertSame( $first, $again, '同じ option 値であれば、常に同じ予定IDを返すこと（決定的）' );

		// option の値を書き換えると予定IDも変わることを確認し、`wp_salt()` ではなくこの
		// option の値を実際に使っていることの証拠とする。
		update_option( $option_name, 'forced-site-key-for-test', false );
		$after_key_change = $this->deterministic_event_id( $booking_id );
		$this->assertNotSame( $first, $after_key_change, 'option の値を変えれば予定IDも変わること' );

		delete_option( $option_name );
	}

	/**
	 * 既に DB にサイト固有の値がある場合、`get_event_id_site_key()` はそれを上書きしないことを
	 * 検証する（安藤レビュー指摘 LOW: 旧実装は `add_option()` を使っており、存在確認
	 * （`notoptions` キャッシュ）と書き込みの間に割り込まれると無条件に上書きしてしまう
	 * 問題があった。`INSERT IGNORE` ＋ `option_name` の一意制約に変更した）。
	 *
	 * 同時実行そのものは再現せず、`notoptions` キャッシュが「無い」と誤って記憶している
	 * （＝ `get_option()` の早期リターンを経由せず、`INSERT IGNORE` の分岐まで実際に
	 * 到達する）状態を作ったうえで、DB には既に別の値がある場合に、その値が保たれる
	 * ことだけを確認する（安藤指摘: 「値がすでにあれば上書きしない」ことの確認で足りる）。
	 */
	public function test_get_event_id_site_key_does_not_overwrite_existing_value(): void {
		global $wpdb;

		$option_name = 'vkbm_google_calendar_event_id_key';
		delete_option( $option_name );

		// DB には既に値がある状態を、$wpdb で直接作る（update_option() 等の WP API 経由だと
		// options/notoptions キャッシュも一緒に更新されてしまい、次の notoptions 偽装が効かない）。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- テストの前提状態（DB行はあるがキャッシュには無い）を意図的に作るための直接書き込み.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => 'pre-existing-site-key',
				'autoload'     => 'no',
			)
		);

		// notoptions キャッシュに「無い」という誤った記録を強制し、get_option() の早期
		// リターンを経由させず、get_event_id_site_key() が INSERT IGNORE を実際に試みる
		// 状態を再現する。
		wp_cache_delete( $option_name, 'options' );
		wp_cache_set( 'notoptions', array( $option_name => true ), 'options' );

		$method = new ReflectionMethod( Google_Calendar_Event_Sync::class, 'get_event_id_site_key' );
		$method->setAccessible( true );
		$result = (string) $method->invoke( $this->sync );

		$this->assertSame( 'pre-existing-site-key', $result, 'INSERT IGNORE により、既存の値が保たれること（戻り値）' );
		$this->assertSame( 'pre-existing-site-key', get_option( $option_name ), 'INSERT IGNORE により、既存の値が保たれること（option の中身）' );

		delete_option( $option_name );
	}

	/**
	 * 409→update の経路で送る本文に `status: confirmed` が明示されることを検証する
	 * （安藤レビュー指摘: キャンセル→確定やゴミ箱からの復元で、Google 側が既に
	 * `cancelled` にしている同じ ID の予定へ update するとき、`status` が無いと
	 * 予定が復活しないおそれがある）。
	 */
	public function test_handle_sync_sends_confirmed_status_on_409_update(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		$event_id   = $this->deterministic_event_id( $booking_id );

		$this->mock_responses = array(
			'events?sendUpdates'  => array(
				'response' => array( 'code' => 409 ),
				'body'     => '{"error":{"code":409,"message":"The requested identifier already exists."}}',
			),
			'events/' . $event_id => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"' . $event_id . '"}',
			),
		);

		$this->sync->handle_sync( $booking_id, 1 );

		$sent_body = json_decode( (string) ( $this->last_matched_request_args['body'] ?? '' ), true );
		$this->assertIsArray( $sent_body, '送信した本文が JSON として読めること' );
		$this->assertSame( 'confirmed', $sent_body['status'] ?? null, '409→update の本文に status: confirmed を明示すること' );
	}

	/**
	 * ゴミ箱へ移動した予約は、キャンセルと同様に予定を削除することを検証する
	 * （determine_action() の trash 分岐）。
	 */
	public function test_handle_sync_deletes_event_on_trash(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		$event_id   = $this->deterministic_event_id( $booking_id );
		update_post_meta( $booking_id, self::META_EVENT_ID, $event_id );

		wp_trash_post( $booking_id );

		$this->mock_responses = array(
			'events/' . $event_id => array(
				'response' => array( 'code' => 204 ),
				'body'     => '',
			),
		);

		$this->sync->handle_sync( $booking_id, 1 );

		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), 'ゴミ箱移動で予定IDメタが消えること' );
	}

	/**
	 * ゴミ箱・キャンセルの時点で、その予約が一度も同期していない（予定IDメタが空）場合は
	 * DELETE を送らず skip にすることを検証する（安藤レビュー指摘。同期したことが無ければ
	 * Google 側に対応する予定が無いはずで、無駄なリクエストになる上、決定的IDが他サイトと
	 * 衝突した場合に別サイトの予定を誤って消してしまうおそれがあるため）。
	 */
	public function test_handle_sync_skips_delete_for_never_synced_trash_and_cancelled(): void {
		$this->connect_and_select_calendar();

		// --- ゴミ箱: 一度も同期していない（META_EVENT_ID が空） ---
		$trashed_booking_id = $this->create_booking();
		wp_trash_post( $trashed_booking_id );

		// --- キャンセル: 一度も同期していない ---
		$cancelled_booking_id = $this->create_booking( 'cancelled' );

		$this->mock_responses = array(
			'googleapis.com' => new WP_Error( 'should_not_be_called', '一度も同期していない予約は DELETE を送ってはいけない' ),
		);

		$this->sync->handle_sync( $trashed_booking_id, 1 );
		$this->sync->handle_sync( $cancelled_booking_id, 1 );

		$this->assertSame( 0, $this->matched_request_count, 'いずれも Google へ通信していないこと（skip されたこと）' );
		$this->assertSame( '', (string) get_post_meta( $trashed_booking_id, self::META_SYNC_FAILED, true ), 'skip は失敗として扱わないこと（ゴミ箱）' );
		$this->assertSame( '', (string) get_post_meta( $cancelled_booking_id, self::META_SYNC_FAILED, true ), 'skip は失敗として扱わないこと（キャンセル）' );
	}

	/**
	 * 作成リクエストがタイムアウト・5xx 等で失敗しても、Google 側では実際に作成が完了して
	 * いることがある。そのあと予約がキャンセルされた場合に、DELETE が送られることを検証する
	 * （安藤レビュー指摘 MEDIUM: create_event() を呼ぶ前に META_EVENT_ID を保存していないと、
	 * determine_action() が「一度も作成を試みていない」と誤判定して skip し、Google 側に
	 * 予定が残り続けてしまう）。
	 */
	public function test_handle_sync_sends_delete_after_cancel_when_create_failed_with_server_error(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		$event_id   = $this->deterministic_event_id( $booking_id );

		// --- 作成リクエストが 500 で失敗する（Google 側では実際に作成が完了しているかも
		// しれない想定。応答が届かなかっただけのケース） ---
		$this->mock_responses = array(
			'events?sendUpdates' => array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"server error"}',
			),
		);
		$this->sync->handle_sync( $booking_id, 1 );

		$this->assertSame(
			$event_id,
			(string) get_post_meta( $booking_id, self::META_EVENT_ID, true ),
			'create_event() が失敗しても、呼び出す前に決定的な予定IDを保存しておくこと'
		);

		// --- そのあとキャンセルされると、META_EVENT_ID が入っているため DELETE が送られること ---
		update_post_meta( $booking_id, self::META_STATUS, 'cancelled' );
		$this->mock_responses        = array(
			'events/' . $event_id => array(
				'response' => array( 'code' => 204 ),
				'body'     => '',
			),
		);
		$this->matched_request_count = 0;
		$this->sync->handle_sync( $booking_id, 1 );

		$this->assertSame( 1, $this->matched_request_count, '作成が失敗していても、そのあとキャンセルされたら DELETE が送られること（skip されないこと）' );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_EVENT_ID, true ), 'DELETE成功で予定IDメタが消えること' );
	}

	/**
	 * 完全削除された予約を削除できることを検証する。予定IDは予約IDから決定的に計算する
	 * ため、投稿が既に読めなくても（before_delete_post のスナップショットを持ち回らなくても）
	 * 削除リクエストを組み立てられる（安藤レビュー指摘。issue 本文「完全削除時は meta が
	 * 消えるので…」に対する設計変更）。
	 */
	public function test_handle_sync_deletes_event_for_fully_deleted_booking(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		$event_id   = $this->deterministic_event_id( $booking_id );
		update_post_meta( $booking_id, self::META_EVENT_ID, $event_id );

		wp_delete_post( $booking_id, true );

		$this->mock_responses        = array(
			'events/' . $event_id => array(
				'response' => array( 'code' => 204 ),
				'body'     => '',
			),
		);
		$this->matched_request_count = 0;

		// 投稿が既に無いため handle_sync() 内部では get_post() が null を返す状態。
		$this->sync->handle_sync( $booking_id, 1 );

		$this->assertSame( 1, $this->matched_request_count, '完全削除された予約でも、予約IDから計算した予定IDで削除リクエストが送られること' );
	}

	/**
	 * 失敗が続いたときに再試行が積まれ、上限に達すると予約単位・サイト全体の失敗フラグが
	 * 立つこと、その後の成功でどちらも消えることを検証する
	 * （issue 本文「失敗時は時間を空けて再試行する」「再試行しても反映できない場合の通知」）。
	 * サイト全体のフラグの値（理由）が、認可喪失以外の失敗では REASON_OTHER になることも
	 * 確認する（植草レビュー・安藤レビュー指摘: 原因と文言を合わせる）。
	 */
	public function test_handle_sync_retries_then_marks_failure_and_recovers(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();

		$this->mock_responses = array(
			'events?sendUpdates' => array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"server error"}',
			),
		);

		// 1回目・2回目の失敗 => 次の試行がスケジュールされ、まだ失敗フラグは立たない。
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertNotFalse( wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 2 ) ), '1回目の失敗後、2回目の試行が積まれること' );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ), '上限に達するまでは予約単位の失敗フラグを立てないこと' );

		$this->sync->handle_sync( $booking_id, 2 );
		$this->assertNotFalse( wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 3 ) ), '2回目の失敗後、3回目の試行が積まれること' );

		// 3回目（上限）の失敗 => 予約単位・サイト全体の両方の失敗フラグが立つ。理由は
		// 認可喪失ではない（500エラー）ため REASON_OTHER。
		$this->sync->handle_sync( $booking_id, 3 );
		$this->assertSame( '1', (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ), '上限到達で予約単位の失敗フラグが立つこと' );
		$this->assertSame( Google_Calendar_Event_Sync::REASON_OTHER, (string) get_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, '' ), '認可喪失ではない失敗は REASON_OTHER で記録すること' );

		// 次に成功すると、両方のフラグが消える。
		$this->mock_responses = array(
			'events?sendUpdates' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"' . $this->deterministic_event_id( $booking_id ) . '"}',
			),
		);
		$this->sync->handle_sync( $booking_id, 1 );
		$this->assertSame( '', (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ), '成功したら予約単位の失敗フラグが消えること' );
		$this->assertSame( '', (string) get_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, '' ), '成功したらサイト全体の失敗フラグも消えること' );
	}

	/**
	 * 認可が失われた失敗（401）では、サイト全体のフラグの理由が REASON_AUTH になることを
	 * 検証する（植草レビュー・安藤レビュー指摘: 「アクセスが失われた可能性」の文言は
	 * 認可喪失のときだけ出す）。
	 */
	public function test_handle_sync_marks_auth_reason_on_unauthorized(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();

		$this->mock_responses = array(
			'events?sendUpdates' => array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"error":{"code":401,"message":"Invalid Credentials"}}',
			),
		);

		// MAX_ATTEMPTS（private const、現在の値は3）に達する試行回数で呼ぶ。
		$this->sync->handle_sync( $booking_id, 3 );

		$this->assertSame( Google_Calendar_Event_Sync::REASON_AUTH, (string) get_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, '' ), '認可喪失（401）は REASON_AUTH で記録すること' );
	}

	/**
	 * get_booking_notice_type() が、連携そのものが切れている場合と、この予約だけ
	 * 失敗している場合を区別して返すことを検証する（司の decision record 参照）。
	 */
	public function test_get_booking_notice_type(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();

		$this->assertNull( $this->sync->get_booking_notice_type( $booking_id ), '反映済み・未着手のときは何も出さないこと（正常系）' );

		update_post_meta( $booking_id, self::META_SYNC_FAILED, '1' );
		$this->assertSame( 'failed', $this->sync->get_booking_notice_type( $booking_id ), 'この予約だけ失敗しているときは failed（正常系）' );

		delete_post_meta( $booking_id, self::META_SYNC_FAILED );
		$this->connection->mark_error( 'invalid_grant', 'The connection to Google has been lost.' );
		$this->assertSame( 'disconnected', $this->sync->get_booking_notice_type( $booking_id ), '連携が切れているときは disconnected（他の予約にも共通する状態のため優先。異常系）' );
	}

	/**
	 * render_booking_notice() が、「今すぐ再試行」を押した直後のクエリ引数
	 * （`vkbm_gcal_retry=accepted`/`throttled`）に応じて、通常の「未反映」お知らせとは
	 * 別の案内を出すことを検証する（植草レビュー指摘: 同期が終わっていないのに
	 * 「未反映」表示が消えるのはおかしい／連打時も行き止まりにしない）。
	 */
	public function test_render_booking_notice_shows_retry_result_messages(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		update_post_meta( $booking_id, self::META_SYNC_FAILED, '1' );

		$_GET['vkbm_gcal_retry'] = 'accepted';
		ob_start();
		$this->sync->render_booking_notice( $booking_id );
		$accepted_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'accepted', $accepted_output, '受け付けたことを伝える文言を出すこと' );
		$this->assertStringNotContainsString( 'Retry now', $accepted_output, '受け付けました表示のときは、二重に「今すぐ再試行」ボタンを出さないこと' );

		$_GET['vkbm_gcal_retry'] = 'throttled';
		ob_start();
		$this->sync->render_booking_notice( $booking_id );
		$throttled_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'wait', $throttled_output, '連打時は少し待つよう伝える文言を出すこと' );

		unset( $_GET['vkbm_gcal_retry'] );
		ob_start();
		$this->sync->render_booking_notice( $booking_id );
		$normal_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Retry now', $normal_output, '再試行結果のクエリが無ければ通常どおり「今すぐ再試行」を出すこと' );
	}

	/**
	 * build_event_input() が、ログイン中の利用者がいない状態（WP-Cron 実行時を想定）でも
	 * 予約管理画面のURLを組み立てられることを検証する（安藤レビュー指摘 MEDIUM:
	 * get_edit_post_link() はこの状態で空文字を返すため使えない）。
	 */
	public function test_build_event_input_admin_edit_url_works_without_logged_in_user(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();

		wp_set_current_user( 0 );

		$method = new ReflectionMethod( Google_Calendar_Event_Sync::class, 'build_event_input' );
		$method->setAccessible( true );
		$input = $method->invoke( $this->sync, $booking_id, get_post( $booking_id ) );

		$this->assertStringContainsString( 'post.php?post=' . $booking_id . '&action=edit', (string) $input['admin_edit_url'], 'ログイン中の利用者がいなくても予約管理画面のURLが組み立てられること' );
	}

	/**
	 * 「今すぐ再試行」（handle_retry_now）の権限チェック・投稿タイプの確認・連打防止・
	 * 再スケジュールを検証する。HIGH（安藤レビュー指摘）対応で `<a>` リンク（GET）に
	 * 変わったため `$_GET` から受け取る。受け付けた直後は失敗フラグを維持し
	 * （植草レビュー指摘）、結果はリダイレクト先のクエリ引数で伝える。
	 */
	public function test_handle_retry_now(): void {
		$this->connect_and_select_calendar();
		$booking_id = $this->create_booking();
		update_post_meta( $booking_id, self::META_SYNC_FAILED, '1' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		$staff_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// --- 異常系: booking_id が無い ---
		wp_set_current_user( $admin_id );
		$_GET     = array();
		$_REQUEST = array();
		try {
			$this->sync->handle_retry_now();
			$this->fail( 'booking_id が無ければ wp_die するはず' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}

		// --- 異常系: nonce が無効 ---
		$_GET                               = array( 'booking_id' => (string) $booking_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト用にリクエストを組み立てているだけ（nonce の妥当性はテスト対象の check_admin_referer() 側で検証する）.
		$_REQUEST                           = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST['_vkbm_gcal_retry_nonce'] = 'invalid-nonce';
		try {
			$this->sync->handle_retry_now();
			$this->fail( 'nonce が不正なら wp_die するはず' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}

		// --- 異常系: 予約以外の投稿タイプを渡された ---
		$non_booking_id                     = (int) $this->factory()->post->create( array( 'post_type' => 'post' ) );
		$_GET                               = array( 'booking_id' => (string) $non_booking_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST                           = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST['_vkbm_gcal_retry_nonce'] = wp_create_nonce( Google_Calendar_Event_Sync::ACTION_RETRY_NOW . '_' . $non_booking_id );
		try {
			$this->sync->handle_retry_now();
			$this->fail( '予約以外の投稿タイプなら wp_die するはず（安藤レビュー指摘）' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}

		// --- 異常系: 権限が無い ---
		wp_set_current_user( $staff_id );
		$_GET                               = array( 'booking_id' => (string) $booking_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST                           = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST['_vkbm_gcal_retry_nonce'] = wp_create_nonce( Google_Calendar_Event_Sync::ACTION_RETRY_NOW . '_' . $booking_id );
		try {
			$this->sync->handle_retry_now();
			$this->fail( '予約編集権限が無ければ wp_die するはず' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}

		// --- 正常系: 管理者が再試行を押す => ジョブが積まれる。結果が出るまでは
		// 未反映（失敗フラグ）の扱いを保つ（植草レビュー指摘）。 ---
		wp_set_current_user( $admin_id );
		$_GET                               = array( 'booking_id' => (string) $booking_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST                           = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上.
		$_REQUEST['_vkbm_gcal_retry_nonce'] = wp_create_nonce( Google_Calendar_Event_Sync::ACTION_RETRY_NOW . '_' . $booking_id );
		try {
			$this->sync->handle_retry_now();
			$this->fail( '再試行後はリダイレクト（テスト用に例外化）されるはず' );
		} catch ( Redirect_Exception $e ) {
			$this->assertStringContainsString( 'vkbm_gcal_retry=accepted', $this->sync->redirected_to, '受け付けたことを伝えるクエリ引数付きで編集画面へ戻すこと' );
		}
		$this->assertSame( '1', (string) get_post_meta( $booking_id, self::META_SYNC_FAILED, true ), '結果が出るまでは失敗フラグを維持すること（植草レビュー指摘）' );
		$this->assertNotFalse( wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 1 ) ), '再試行のジョブが積まれること' );

		// --- 正常系（連打防止）: 連続で押しても2回目はジョブを増やさず、待つよう伝える ---
		// wp_clear_scheduled_hook() は引数なしでは呼び出せない。上の正常系で積んだジョブは
		// array( $booking_id, 1 ) 付きで積まれているため、同じ引数を渡して消す
		// （司の PHPUnit 実行で判明。引数無しだと消えず、次の assertFalse が誤って失敗する）。
		wp_clear_scheduled_hook( self::CRON_ACTION, array( $booking_id, 1 ) );
		try {
			$this->sync->handle_retry_now();
			$this->fail( '連打時もリダイレクトされるはず' );
		} catch ( Redirect_Exception $e ) {
			$this->assertStringContainsString( 'vkbm_gcal_retry=throttled', $this->sync->redirected_to, '連打時は少し待つよう伝えるクエリ引数を付けること' );
		}
		$this->assertFalse( wp_next_scheduled( self::CRON_ACTION, array( $booking_id, 1 ) ), '連打防止の間隔内は再スケジュールしないこと' );
	}
}
