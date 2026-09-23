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
use function is_wp_error;
use function set_transient;
use function wp_remote_get;
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
