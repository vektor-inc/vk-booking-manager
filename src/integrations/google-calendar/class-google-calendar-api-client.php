<?php
/**
 * Google カレンダーの API を呼ぶクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## 中継サーバーを通す通信・通さない通信
 * 中継サーバーを通すのは「Google の接続情報（クライアントシークレット）が必要な通信」だけ、
 * つまりアクセス許可の引き換えと取り直し・取り消しに限る。カレンダーの読み書きは
 * アクセストークンだけでできるため、各サイトから Google へ直接通信する。中継サーバーを
 * 通る通信量を減らし、中継サーバーが止まっているときの影響範囲を小さくするための切り分け。
 *
 * この issue（#475）で使うのは、反映先を選ぶためのカレンダー一覧の取得のみ。予定の作成・更新・
 * 削除は後続の #476 で実装する。
 *
 * ## 中継サーバーが応答しないときに、呼び出し元を止めない
 * アクセストークンは1時間で失効し、取り直しのたびに中継サーバーを通る。中継サーバーが
 * 応答しないとき（親 issue #94 で挙げた懸念）に呼び出し元の処理が止まらないよう、次の形にしている。
 *
 * - 通信のタイムアウトは `Google_Calendar_Relay_Client` 側で上限を設ける
 * - 失敗は例外ではなく `WP_Error` で返す（呼び出し元が続行を選べる）
 * - 取り直しに失敗した直後の再試行は `is_refresh_throttled()` で抑止し、
 *   応答しない中継サーバーへ連続して問い合わせないようにする
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use function add_query_arg;
use function delete_transient;
use function get_transient;
use function is_array;
use function is_wp_error;
use function rawurlencode;
use function set_transient;
use function wp_json_encode;
use function wp_remote_get;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Google カレンダーの API を呼ぶクラス。
 */
class Google_Calendar_Api_Client {

	/**
	 * カレンダー一覧を取得する API の URL。
	 *
	 * @var string
	 */
	private const CALENDAR_LIST_URL = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';

	/**
	 * カレンダーの予定（イベント）を作成・取得する API のベース URL（末尾にカレンダーIDを続ける）。
	 *
	 * issue #476。
	 *
	 * @var string
	 */
	private const EVENTS_BASE_URL = 'https://www.googleapis.com/calendar/v3/calendars/';

	/**
	 * Google への通信のタイムアウト（秒）。
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * アクセストークンの取り直しに失敗した直後、再試行を控える時間（秒）。
	 *
	 * @var int
	 */
	private const REFRESH_RETRY_INTERVAL = 300;

	/**
	 * 取り直しの抑止状態を保存する transient 名。
	 *
	 * @var string
	 */
	private const REFRESH_THROTTLE_TRANSIENT = 'vkbm_google_calendar_refresh_throttle';

	/**
	 * 接続状態（トークンの保存先）。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * 中継サーバーとの通信クライアント。
	 *
	 * @var Google_Calendar_Relay_Client
	 */
	private $relay_client;

	/**
	 * コンストラクタ。
	 *
	 * @param Google_Calendar_Connection   $connection   接続状態。
	 * @param Google_Calendar_Relay_Client $relay_client 中継サーバーとの通信クライアント。
	 */
	public function __construct( Google_Calendar_Connection $connection, Google_Calendar_Relay_Client $relay_client ) {
		$this->connection   = $connection;
		$this->relay_client = $relay_client;
	}

	/**
	 * 今すぐ使えるアクセストークンを返す。期限切れなら中継サーバー経由で取り直す。
	 *
	 * 取り直しに失敗しても、接続状態をエラーにする（画面に「連携が切れています」と出る）のは
	 * 認可そのものが失われたと判断できる場合（`invalid_grant` 等、中継サーバーが HTTP 400/401 系で
	 * 返す応答）に限る。中継サーバーへ届かない・5xx を返すなど一時的な不調では、接続はまだ
	 * 生きている可能性が高いため状態を `connected` のまま維持し、次の機会に取り直しを再試行
	 * できるようにする（安藤レビュー指摘。中継サーバーが一瞬届かなかっただけで「連携が
	 * 切れています」に固定され、オーナーが手で連携し直すまで復帰しない問題への対応）。
	 * いずれの場合も例外は投げず WP_Error を返す。`before_delete_post` など、途中で止まると
	 * データの不整合を招く場所から呼ばれても、呼び出し元の処理を止めないため。
	 *
	 * @return string|WP_Error 有効なアクセストークン、または失敗の内容。
	 */
	public function get_access_token() {
		$access_token = $this->connection->get_access_token();

		if ( null !== $access_token ) {
			return $access_token;
		}

		$refresh_token = $this->connection->get_refresh_token();

		if ( null === $refresh_token ) {
			return new WP_Error(
				'vkbm_google_calendar_not_connected',
				__( 'The connection to Google has been lost.', 'vk-booking-manager' )
			);
		}

		if ( $this->is_refresh_throttled() ) {
			// 直前の取り直しが失敗している間は、中継サーバーへ連続して問い合わせない。
			return new WP_Error(
				'vkbm_google_calendar_refresh_throttled',
				__( 'Could not reach the relay server.', 'vk-booking-manager' )
			);
		}

		$refreshed = $this->relay_client->refresh_access_token( $refresh_token );

		if ( is_wp_error( $refreshed ) ) {
			$this->start_refresh_throttle();

			if ( $this->is_authorization_lost( $refreshed ) ) {
				$this->connection->mark_error(
					$refreshed->get_error_code(),
					__( 'The connection to Google has been lost.', 'vk-booking-manager' )
				);
			}

			return $refreshed;
		}

		$this->clear_refresh_throttle();

		if ( ! $this->connection->update_access_token( $refreshed['access_token'], $refreshed['expires_in'] ) ) {
			return new WP_Error(
				'vkbm_google_calendar_token_not_stored',
				__( 'Could not save the permission received from Google.', 'vk-booking-manager' )
			);
		}

		return $refreshed['access_token'];
	}

	/**
	 * 取り直しの失敗が、認可そのものが失われた（＝再接続しないと直らない）ものかどうかを返す。
	 *
	 * `Google_Calendar_Relay_Client::request()` は、中継サーバーが 2xx 以外を返した場合に
	 * `error_data['status']` へその HTTP ステータスを持たせている（到達不能の場合は
	 * 持たせない）。Google の OAuth トークンエンドポイントは `invalid_grant`（許可が取り消された・
	 * 失効した）等の認可エラーを HTTP 400 または 401 で返す仕様のため、ここでは中継サーバーが
	 * 転送してきたステータスが 400 か 401 のときだけ「認可が失われた」と判断する。到達不能・
	 * 5xx（中継サーバー側の一時的な不調）はここに含めない。
	 *
	 * ライセンスキー起因の失敗（未設定・無効・期限切れ）も、中継サーバーの実装次第では
	 * HTTP 400/401 で返ってくる可能性がある。これは Google 側の認可がまだ生きているのに
	 * 「Googleとの連携が切れています。」と誤って表示してしまう原因になる（直し方は
	 * ライセンスキーの更新であって Google の再接続ではないため）。仕様書「中継サーバーが
	 * 満たすべき要件」で、ライセンスキー起因のエラーは `license` を含む専用の種別で返すことを
	 * 求めているため、ここでもその種別だけは認可喪失として扱わない（安藤レビュー指摘）。
	 *
	 * @param WP_Error $error `refresh_access_token()` が返した WP_Error。
	 * @return bool 認可が失われたと判断できるなら true。
	 */
	private function is_authorization_lost( WP_Error $error ): bool {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;

		if ( ! ( 400 === $status || 401 === $status ) ) {
			return false;
		}

		return false === strpos( $error->get_error_code(), 'license' );
	}

	/**
	 * 連携した Google アカウントが持つカレンダーの一覧を取得する。
	 *
	 * 予定を書き込めないカレンダー（閲覧のみ共有されているもの）は選んでも反映できないため、
	 * 書き込める権限（`owner` / `writer`）のものだけを返す。
	 *
	 * @return array<int, array{id:string, summary:string, primary:bool}>|WP_Error カレンダーの一覧、または失敗の内容。
	 */
	public function get_calendar_list() {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'minAccessRole' => 'writer',
					'maxResults'    => 250,
					'showHidden'    => 'false',
				),
				self::CALENDAR_LIST_URL
			),
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'vkbm_google_calendar_unreachable',
				__( 'Could not reach Google.', 'vk-booking-manager' ),
				array( 'detail' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			// アクセス許可が取り消された場合。画面に「連携が切れています」と出す。
			$this->connection->mark_error(
				'invalid_grant',
				__( 'The connection to Google has been lost.', 'vk-booking-manager' )
			);

			return new WP_Error(
				'vkbm_google_calendar_unauthorized',
				__( 'The connection to Google has been lost.', 'vk-booking-manager' )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'vkbm_google_calendar_request_failed',
				__( 'Could not get the calendar list from Google.', 'vk-booking-manager' ),
				array( 'status' => $status )
			);
		}

		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$items  = ( is_array( $parsed ) && isset( $parsed['items'] ) && is_array( $parsed['items'] ) ) ? $parsed['items'] : array();

		return self::normalize_calendar_list( $items );
	}

	/**
	 * カレンダーに予定を新規作成する。
	 *
	 * issue #476。招待メールは送らない固定（`sendUpdates=none`）にしている（親 issue #94 で決定）。
	 *
	 * `$event_id` は呼び出し側（{@see \VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync::get_deterministic_event_id()}）が
	 * 予約IDから決定的に組み立てた ID。Google 側で応答がタイムアウトした後の再試行や、cron が
	 * 並行して動いた場合でも、同じ予約に対しては常に同じ ID で作成を試みるため、二重に作成
	 * されることがない（安藤レビュー指摘）。既に同じ ID の予定が存在する場合、Google は
	 * `409 Conflict` を返す。このメソッドはそれを `vkbm_google_calendar_event_already_exists`
	 * として返すので、呼び出し側は `update_event()` に切り替えること。
	 *
	 * @param string               $calendar_id 反映先カレンダーの ID。
	 * @param string               $event_id    作成する予定の ID（base32hex、5〜1024文字）。
	 * @param array<string, mixed> $event       Google Calendar API のイベント表現
	 *                                           （{@see Google_Calendar_Event_Builder::build()}）。
	 * @return string|WP_Error 作成できたイベント ID、または失敗の内容。
	 */
	public function create_event( string $calendar_id, string $event_id, array $event ) {
		$event['id'] = $event_id;

		return $this->send_event_request( 'POST', $this->get_events_url( $calendar_id ), $event );
	}

	/**
	 * 既存の予定を書き換える。
	 *
	 * 仮予約が確定したときに、新しい予定を作らず同じ予定を書き換えるために使う
	 * （司の decision record 参照。重複した予定が残るのを防ぐため）。
	 *
	 * @param string               $calendar_id 反映先カレンダーの ID。
	 * @param string               $event_id    書き換える予定の Google 側 ID。
	 * @param array<string, mixed> $event       Google Calendar API のイベント表現。
	 * @return string|WP_Error 書き換えたイベント ID、または失敗の内容。
	 *                         予定が Google 側で既に無くなっている場合は
	 *                         `vkbm_google_calendar_event_not_found` を返す
	 *                         （呼び出し側は新規作成へ倒すこと）。
	 */
	public function update_event( string $calendar_id, string $event_id, array $event ) {
		return $this->send_event_request( 'PUT', $this->get_events_url( $calendar_id ) . '/' . rawurlencode( $event_id ), $event );
	}

	/**
	 * 予定を削除する。
	 *
	 * Google 側で既に削除・存在しない予定を指定した場合も、目的（「その予定が無い状態」）は
	 * 既に達成されているとみなして成功扱いにする（安藤レビュー指摘を先取り: 手動で消された
	 * 予定を再試行し続けて失敗が積み上がるのを防ぐため）。
	 *
	 * @param string $calendar_id 反映先カレンダーの ID。
	 * @param string $event_id    削除する予定の Google 側 ID。
	 * @return true|WP_Error 成功したら true、失敗の内容。
	 */
	public function delete_event( string $calendar_id, string $event_id ) {
		$result = $this->send_event_request( 'DELETE', $this->get_events_url( $calendar_id ) . '/' . rawurlencode( $event_id ), null );

		if ( is_wp_error( $result ) && 'vkbm_google_calendar_event_not_found' === $result->get_error_code() ) {
			return true;
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * カレンダーIDから、予定（イベント）を扱う API の URL を組み立てる。
	 *
	 * @param string $calendar_id カレンダー ID。
	 * @return string API の URL。
	 */
	private function get_events_url( string $calendar_id ): string {
		return self::EVENTS_BASE_URL . rawurlencode( $calendar_id ) . '/events';
	}

	/**
	 * 予定の作成・更新・削除に共通する通信処理。
	 *
	 * 招待メールを送らないよう `sendUpdates=none` を必ず付ける（親 issue #94 で決定）。
	 *
	 * @param string                    $method HTTP メソッド（POST/PUT/DELETE）。
	 * @param string                    $url    宛先 URL（`sendUpdates` は付与前）。
	 * @param array<string, mixed>|null $body   送信する JSON 本文。DELETE では null。
	 * @return string|WP_Error 成功時、本文があれば `id`、無ければ空文字。失敗時は WP_Error。
	 */
	private function send_event_request( string $method, string $url, ?array $body ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = add_query_arg( array( 'sendUpdates' => 'none' ), $url );

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'vkbm_google_calendar_unreachable',
				__( 'Could not reach Google.', 'vk-booking-manager' ),
				array( 'detail' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status ) {
			// アクセス許可が取り消された場合のみ。画面に「連携が切れています」と出す
			// （get_calendar_list() と同じ扱い）。403 は権限不足・レート制限等、連携そのものが
			// 切れたとは限らない理由でも返るため、ここには含めない（安藤レビュー指摘）。
			$this->connection->mark_error(
				'invalid_grant',
				__( 'The connection to Google has been lost.', 'vk-booking-manager' )
			);

			return new WP_Error(
				'vkbm_google_calendar_unauthorized',
				__( 'The connection to Google has been lost.', 'vk-booking-manager' )
			);
		}

		if ( 403 === $status ) {
			// 認可そのものは失われていない可能性があるため connection を error にしない。
			// 一時的な失敗として、呼び出し側の再試行に任せる（安藤レビュー指摘）。
			return new WP_Error(
				'vkbm_google_calendar_forbidden',
				__( 'Google did not allow this request.', 'vk-booking-manager' ),
				array( 'status' => $status )
			);
		}

		if ( 404 === $status || 410 === $status ) {
			// 予定が Google 側で既に無い（手動で削除された等）。認可は失われていないため
			// 「連携が切れています」にはしない。呼び出し側が新規作成・削除成功扱いへ倒す。
			return new WP_Error(
				'vkbm_google_calendar_event_not_found',
				__( 'The event was not found in Google Calendar.', 'vk-booking-manager' )
			);
		}

		if ( 409 === $status ) {
			// 決定的な予定IDでの新規作成時、既に同じIDの予定が存在する場合（タイムアウト後の
			// 再試行・cron の並行実行等）。呼び出し側が update_event() へ切り替える
			// （安藤レビュー指摘。Google_Calendar_Event_Sync::execute_upsert() 参照）。
			return new WP_Error(
				'vkbm_google_calendar_event_already_exists',
				__( 'An event with this ID already exists in Google Calendar.', 'vk-booking-manager' )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'vkbm_google_calendar_request_failed',
				__( 'Could not update Google Calendar.', 'vk-booking-manager' ),
				array( 'status' => $status )
			);
		}

		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return ( is_array( $parsed ) && isset( $parsed['id'] ) ) ? (string) $parsed['id'] : '';
	}

	/**
	 * Google の応答から、画面で使う形（ID・表示名・既定のカレンダーか）だけを取り出す。
	 *
	 * 応答の形を1箇所に閉じ込め、通信せずに単体テストできるように分けている。
	 *
	 * @param array<int, mixed> $items Google の応答に含まれる items。
	 * @return array<int, array{id:string, summary:string, primary:bool}> 画面で使う形の一覧。
	 */
	public static function normalize_calendar_list( array $items ): array {
		$calendars = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$access_role = isset( $item['accessRole'] ) ? (string) $item['accessRole'] : '';

			// 予定を書き込めないカレンダーは反映先にできないため除外する。
			if ( ! in_array( $access_role, array( 'owner', 'writer' ), true ) ) {
				continue;
			}

			$calendars[] = array(
				'id'      => (string) $item['id'],
				'summary' => isset( $item['summary'] ) ? (string) $item['summary'] : (string) $item['id'],
				'primary' => ! empty( $item['primary'] ),
			);
		}

		// 本人の既定のカレンダーを先頭に置く（最も選ばれる可能性が高いため）。
		usort(
			$calendars,
			static function ( array $a, array $b ): int {
				if ( $a['primary'] === $b['primary'] ) {
					return strcmp( $a['summary'], $b['summary'] );
				}

				return $a['primary'] ? -1 : 1;
			}
		);

		return $calendars;
	}

	/**
	 * アクセストークンの取り直しを、いま控えるべきかどうかを返す。
	 *
	 * @return bool 控えるべきなら true。
	 */
	private function is_refresh_throttled(): bool {
		return false !== get_transient( self::REFRESH_THROTTLE_TRANSIENT );
	}

	/**
	 * 取り直しの抑止を開始する。
	 *
	 * @return void
	 */
	private function start_refresh_throttle(): void {
		set_transient( self::REFRESH_THROTTLE_TRANSIENT, 1, self::REFRESH_RETRY_INTERVAL );
	}

	/**
	 * 取り直しの抑止を解除する。
	 *
	 * @return void
	 */
	private function clear_refresh_throttle(): void {
		delete_transient( self::REFRESH_THROTTLE_TRANSIENT );
	}
}
