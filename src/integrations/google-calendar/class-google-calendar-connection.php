<?php
/**
 * Google カレンダー連携の接続状態（アクセス許可・反映先カレンダー）を保存・読み出すクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## 保存先
 * `wp_options` の `vkbm_google_calendar_connection` 1件に、連携に必要な情報をまとめて持つ。
 * 自動読み込み（autoload）は無効にしている。連携の情報は管理画面の「連携」タブと、
 * カレンダーへの反映処理（#476）でしか使わず、全ページの表示のたびに読み込む必要が無いため。
 *
 * トークン（アクセス許可の証明）は `Google_Calendar_Secret_Store` で暗号化してから保存する。
 * このクラスの外へトークンの平文を渡すのは `get_refresh_token()` / `get_access_token()` の
 * 2つのみで、いずれも中継サーバー・Google への通信のためだけに使う。
 *
 * ## 画面に出す状態（issue #475 の植草案）との対応
 * | 画面の状態 | このクラスでの判定 |
 * |---|---|
 * | 未接続 | `get_status()` が `disconnected` |
 * | 接続済み | `get_status()` が `connected` |
 * | エラー（アクセス許可の失効など） | `get_status()` が `error` |
 *
 * 「接続中」（Google の画面へ送り出してから戻ってくるまで）は、このクラスではなく
 * `Google_Calendar_Connect_Controller` が一時データ（transient）で持つ。接続が完了するまでは
 * 保存すべき情報がまだ無く、途中で離脱した場合に自動で消えてほしいため。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function delete_option;
use function get_option;
use function update_option;

/**
 * Google カレンダー連携の接続状態を扱うクラス。
 */
class Google_Calendar_Connection {

	/**
	 * 接続状態を保存する option 名。
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'vkbm_google_calendar_connection';

	/**
	 * 未接続（まだ一度も接続していない、または解除済み）。
	 *
	 * @var string
	 */
	public const STATUS_DISCONNECTED = 'disconnected';

	/**
	 * 接続済み（Google のアクセス許可を保持している）。
	 *
	 * @var string
	 */
	public const STATUS_CONNECTED = 'connected';

	/**
	 * エラー（アクセス許可が失効した、または中継サーバー経由の更新に失敗し続けている）。
	 *
	 * @var string
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * アクセストークンを「まだ使える」と判断するときの余裕（秒）。
	 *
	 * 有効期限ぎりぎりのトークンで Google を呼ぶと、通信している間に失効して失敗しうる。
	 * 期限の60秒前を過ぎたものは期限切れ扱いにし、先に更新させる。
	 *
	 * @var int
	 */
	private const EXPIRY_MARGIN_SECONDS = 60;

	/**
	 * 保存されている接続情報をそのまま取得する（内部用）。
	 *
	 * @return array<string, mixed> 保存されている配列。未保存なら空配列。
	 */
	private function get_raw(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * 接続情報を保存する（内部用）。
	 *
	 * autoload を無効にするため、第3引数に false を渡している。
	 *
	 * @param array<string, mixed> $data 保存する配列。
	 * @return void
	 */
	private function save_raw( array $data ): void {
		update_option( self::OPTION_NAME, $data, false );
	}

	/**
	 * 画面表示に使う接続状態をまとめて取得する。
	 *
	 * トークンそのものは含めない（画面に出す必要が無く、出すべきでもないため）。
	 *
	 * @return array{status:string, account_email:string, calendar_id:string, calendar_summary:string, connected_at:int, error_code:string, error_message:string}
	 */
	public function get_state(): array {
		$raw = $this->get_raw();

		return array(
			'status'           => $this->get_status(),
			'account_email'    => isset( $raw['account_email'] ) ? (string) $raw['account_email'] : '',
			'calendar_id'      => isset( $raw['calendar_id'] ) ? (string) $raw['calendar_id'] : '',
			'calendar_summary' => isset( $raw['calendar_summary'] ) ? (string) $raw['calendar_summary'] : '',
			'connected_at'     => isset( $raw['connected_at'] ) ? (int) $raw['connected_at'] : 0,
			'error_code'       => isset( $raw['error_code'] ) ? (string) $raw['error_code'] : '',
			'error_message'    => isset( $raw['error_message'] ) ? (string) $raw['error_message'] : '',
		);
	}

	/**
	 * 現在の接続状態を返す。
	 *
	 * 保存された状態が `connected` でも、再接続に必要なリフレッシュトークンが復号できない場合
	 * （wp-config.php の salt を入れ替えた等）は `error` として扱う。画面上は
	 * 「連携が切れています」と表示され、再接続すれば直る。
	 *
	 * @return string self::STATUS_* のいずれか。
	 */
	public function get_status(): string {
		$raw = $this->get_raw();

		if ( array() === $raw ) {
			return self::STATUS_DISCONNECTED;
		}

		$status = isset( $raw['status'] ) ? (string) $raw['status'] : self::STATUS_DISCONNECTED;

		if ( ! in_array( $status, array( self::STATUS_DISCONNECTED, self::STATUS_CONNECTED, self::STATUS_ERROR ), true ) ) {
			return self::STATUS_DISCONNECTED;
		}

		if ( self::STATUS_CONNECTED === $status && null === $this->get_refresh_token() ) {
			return self::STATUS_ERROR;
		}

		return $status;
	}

	/**
	 * 接続済みかどうかを返す。
	 *
	 * @return bool 接続済みなら true。
	 */
	public function is_connected(): bool {
		return self::STATUS_CONNECTED === $this->get_status();
	}

	/**
	 * 反映先カレンダーが選ばれているかどうかを返す。
	 *
	 * 接続しただけではカレンダーが未選択の状態があり、その場合は #476 の反映処理を
	 * 動かしてはいけないため、接続済みかどうかとは別に判定できるようにしている。
	 *
	 * @return bool 接続済みで、かつ反映先カレンダーが選ばれていれば true。
	 */
	public function is_ready(): bool {
		return $this->is_connected() && '' !== $this->get_calendar_id();
	}

	/**
	 * 連携中の Google アカウントのメールアドレスを返す。
	 *
	 * @return string メールアドレス。不明な場合は空文字。
	 */
	public function get_account_email(): string {
		$raw = $this->get_raw();

		return isset( $raw['account_email'] ) ? (string) $raw['account_email'] : '';
	}

	/**
	 * 反映先カレンダーの ID を返す。
	 *
	 * @return string カレンダー ID。未選択なら空文字。
	 */
	public function get_calendar_id(): string {
		$raw = $this->get_raw();

		return isset( $raw['calendar_id'] ) ? (string) $raw['calendar_id'] : '';
	}

	/**
	 * 反映先カレンダーの表示名を返す。
	 *
	 * @return string カレンダー名。未選択なら空文字。
	 */
	public function get_calendar_summary(): string {
		$raw = $this->get_raw();

		return isset( $raw['calendar_summary'] ) ? (string) $raw['calendar_summary'] : '';
	}

	/**
	 * Google から受け取ったアクセス許可を保存する。
	 *
	 * 既に接続済みの状態で再接続した場合、Google がリフレッシュトークンを返さないことがある
	 * （同意画面を再度通さなかった場合）。その場合は保存済みのリフレッシュトークンを維持する。
	 *
	 * ## 別の Google アカウントで繋ぎ直した場合
	 * 保存済みの `account_email` と今回の `email` が異なるときは、別アカウントへの繋ぎ直しと
	 * 判断し、次の2点を行う（安藤レビュー指摘）。
	 *
	 * - 反映先カレンダー（`calendar_id` / `calendar_summary`）は前のアカウントのものなので空に戻す。
	 *   空にしておけば、呼び出し側（`Google_Calendar_Connect_Controller::handle_callback()`）の
	 *   「未選択なら本人の既定のカレンダーを入れる」処理が自然に働き、新しいアカウントの
	 *   カレンダーが選び直される
	 * - 保存済みのリフレッシュトークンは前のアカウントのものなので引き継がない。別アカウントの
	 *   接続では Google が毎回同意画面を通すため、今回の応答に新しいリフレッシュトークンが
	 *   含まれないことは通常無いが、万一含まれていなかった場合は前のアカウントのまま
	 *   接続済み扱いにせず、保存に失敗させて再接続へ倒す
	 * - `connected_at`（連携を開始した時刻）も、別アカウントとしての連携開始時刻に更新する。
	 *   「連携後に発生した予約だけが反映される」（親 issue #94）の起点はアカウントごとに
	 *   意味を持つため
	 *
	 * @param array{access_token?:string, refresh_token?:string, expires_in?:int, email?:string} $tokens 中継サーバーから受け取ったトークン情報。
	 * @return bool 保存できたら true。暗号化できない環境では false。
	 */
	public function save_tokens( array $tokens ): bool {
		$access_token  = isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '';
		$refresh_token = isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '';
		$expires_in    = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 0;
		$email         = isset( $tokens['email'] ) ? (string) $tokens['email'] : '';

		$raw = $this->get_raw();

		$previous_email  = isset( $raw['account_email'] ) ? (string) $raw['account_email'] : '';
		$account_changed = '' !== $previous_email && '' !== $email && $previous_email !== $email;

		// 別アカウントへの繋ぎ直しでは、前のアカウントのリフレッシュトークンを引き継がない。
		// 引き継ぐと、新しい応答にリフレッシュトークンが含まれなかった場合に前のアカウントの
		// ままエラーにも気づかず動き続けてしまうため。
		$encrypted_refresh = ( ! $account_changed && isset( $raw['refresh_token'] ) ) ? (string) $raw['refresh_token'] : '';
		if ( '' !== $refresh_token ) {
			$encrypted_refresh = (string) Google_Calendar_Secret_Store::encrypt( $refresh_token );
		}

		if ( '' === $encrypted_refresh ) {
			// リフレッシュトークンが無いと1時間後に連携が切れるため、接続を成立させない。
			return false;
		}

		$encrypted_access = '' !== $access_token ? Google_Calendar_Secret_Store::encrypt( $access_token ) : null;

		if ( '' !== $access_token && null === $encrypted_access ) {
			return false;
		}

		$raw['status']                  = self::STATUS_CONNECTED;
		$raw['refresh_token']           = $encrypted_refresh;
		$raw['access_token']            = (string) $encrypted_access;
		$raw['access_token_expires_at'] = $expires_in > 0 ? time() + $expires_in : 0;
		$raw['error_code']              = '';
		$raw['error_message']           = '';

		if ( $account_changed ) {
			// 反映先カレンダーは Google アカウントごとの ID のため、前のアカウントの選択を
			// 持ち越さない。連携し直した時刻も、このアカウントでの連携開始時刻に更新する。
			$raw['calendar_id']      = '';
			$raw['calendar_summary'] = '';
			$raw['connected_at']     = time();
		} else {
			$raw['connected_at'] = isset( $raw['connected_at'] ) && $raw['connected_at'] > 0 ? (int) $raw['connected_at'] : time();
		}

		if ( '' !== $email ) {
			$raw['account_email'] = $email;
		}

		$this->save_raw( $raw );

		return true;
	}

	/**
	 * 更新したアクセストークンだけを保存する。
	 *
	 * 有効期限が切れたときに、中継サーバー経由で取り直した結果を書き戻すために使う。
	 *
	 * @param string $access_token 新しいアクセストークン。
	 * @param int    $expires_in   有効期限までの秒数。
	 * @return bool 保存できたら true。
	 */
	public function update_access_token( string $access_token, int $expires_in ): bool {
		$encrypted = Google_Calendar_Secret_Store::encrypt( $access_token );

		if ( null === $encrypted ) {
			return false;
		}

		$raw = $this->get_raw();

		if ( array() === $raw ) {
			return false;
		}

		$raw['access_token']            = $encrypted;
		$raw['access_token_expires_at'] = $expires_in > 0 ? time() + $expires_in : 0;
		$raw['status']                  = self::STATUS_CONNECTED;
		$raw['error_code']              = '';
		$raw['error_message']           = '';

		$this->save_raw( $raw );

		return true;
	}

	/**
	 * 反映先カレンダーを保存する。
	 *
	 * @param string $calendar_id      カレンダー ID。
	 * @param string $calendar_summary カレンダーの表示名。
	 * @return void
	 */
	public function set_calendar( string $calendar_id, string $calendar_summary ): void {
		$raw = $this->get_raw();

		if ( array() === $raw ) {
			return;
		}

		$raw['calendar_id']      = $calendar_id;
		$raw['calendar_summary'] = $calendar_summary;

		$this->save_raw( $raw );
	}

	/**
	 * 連携がエラー状態になったことを記録する。
	 *
	 * アクセス許可が失効した場合や、中継サーバー経由の更新に失敗した場合に呼ぶ。
	 * 保存済みのトークンは消さない。オーナーが再接続したときに、カレンダーの選択を
	 * 選び直さずに済むようにするため。
	 *
	 * @param string $code    エラーの種別（`invalid_grant` 等。画面には出さず、切り分け用に持つ）。
	 * @param string $message 画面に出すエラーの内容。
	 * @return void
	 */
	public function mark_error( string $code, string $message ): void {
		$raw = $this->get_raw();

		if ( array() === $raw ) {
			return;
		}

		$raw['status']        = self::STATUS_ERROR;
		$raw['error_code']    = $code;
		$raw['error_message'] = $message;
		$raw['error_at']      = time();

		$this->save_raw( $raw );
	}

	/**
	 * 連携を解除し、保存している情報をすべて消す。
	 *
	 * Google 側に作成済みの予定は削除しない（親 issue #94 で決定）。誤って解除したときに
	 * 予定が消えないようにするため。
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * リフレッシュトークン（アクセストークンを取り直すための長期の許可）を復号して返す。
	 *
	 * 中継サーバーへの更新・解除の依頼にだけ使う。
	 *
	 * @return string|null 復号したリフレッシュトークン。未保存・復号できない場合は null。
	 */
	public function get_refresh_token(): ?string {
		$raw = $this->get_raw();

		if ( empty( $raw['refresh_token'] ) || ! is_string( $raw['refresh_token'] ) ) {
			return null;
		}

		return Google_Calendar_Secret_Store::decrypt( $raw['refresh_token'] );
	}

	/**
	 * まだ有効期限内のアクセストークンを復号して返す。
	 *
	 * 期限切れ（または期限が近い）場合は null を返す。取り直しはこのクラスでは行わない。
	 * 取り直しには中継サーバーへの通信が必要で、通信の失敗をどう扱うかは呼び出し側
	 * （#476 の反映処理・画面のカレンダー一覧取得）の都合で変わるため。
	 *
	 * @return string|null 有効なアクセストークン。無い場合は null。
	 */
	public function get_access_token(): ?string {
		$raw = $this->get_raw();

		if ( empty( $raw['access_token'] ) || ! is_string( $raw['access_token'] ) ) {
			return null;
		}

		$expires_at = isset( $raw['access_token_expires_at'] ) ? (int) $raw['access_token_expires_at'] : 0;

		if ( $expires_at <= 0 || $expires_at - self::EXPIRY_MARGIN_SECONDS <= time() ) {
			return null;
		}

		return Google_Calendar_Secret_Store::decrypt( $raw['access_token'] );
	}
}
