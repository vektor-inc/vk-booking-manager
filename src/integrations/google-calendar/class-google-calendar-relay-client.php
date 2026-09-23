<?php
/**
 * Vektor が運用する中継サーバーとの通信を担当するクラス。
 *
 * issue #475（親 issue #94: Google カレンダー連携）。
 *
 * ## 中継サーバーとは
 * Google に「アプリ」として登録する接続情報（OAuth クライアント ID とシークレット）は
 * Vektor が1つだけ持ち、Google とのやり取りはこの中継サーバーが代行する（親 issue #94 の A 案）。
 * これにより、オーナーは Google Cloud での作業を一切せず、管理画面のボタンを押すだけで連携できる。
 *
 * 中継サーバーはアクセス許可（トークン）を保存しない。受け取った結果をその場で各サイトへ返すだけで、
 * 保存先は各サイトの DB（`Google_Calendar_Connection`）になる。全サイト分のトークンをまとめて
 * 持つ場所を作らないための設計（親 issue #94 で決定）。
 *
 * **中継サーバー本体の実装はこのリポジトリの範囲外。** Cloudflare Workers 上に、専用の
 * リポジトリで実装する（2026-09-20 決定）。このクラスは、その中継サーバーが備えるべき
 * 入り口（下記）を呼ぶ側にあたる。
 *
 * ## 中継サーバーに求める入り口
 * | パス | 使い方 |
 * |---|---|
 * | `POST /auth/session` | `license_key` / `state` / `code_challenge` / `site_callback` を受け取り、ライセンスキーを検証したうえで短命・使い捨ての `ticket` を発行して JSON で返す |
 * | `GET /auth/start` | ブラウザの送り先。`ticket` だけを受け取り、対応する `state` / `code_challenge` / `site_callback` を自分の記録から解決して Google の許可画面へ転送する |
 * | `GET /auth/callback` | Google からの戻り先（中継サーバー自身の URL）。受け取った `code` を、そのまま `site_callback` へ転送する |
 * | `POST /auth/token` | `code` と `code_verifier` を受け取り、Google と引き換えて `access_token` / `refresh_token` / `expires_in` / `email` を JSON で返す |
 * | `POST /auth/refresh` | `refresh_token` を受け取り、新しい `access_token` / `expires_in` を JSON で返す |
 * | `POST /auth/revoke` | `refresh_token` を受け取り、Google 側の許可を取り消す |
 *
 * `GET /auth/start` を「ブラウザの送り先」としつつ `ticket` だけしか渡さないのは、
 * ライセンスキーをブラウザが遷移する URL に載せないため（下記「Pro 版のライセンスキーを
 * 添えて送る」を参照）。`POST /auth/session` はサーバー間通信（`Google_Calendar_Relay_Client::request()`
 * 経由）で呼ぶため、ライセンスキーはブラウザを一切通らない。
 *
 * ## 横取りを防ぐ仕組み（PKCE）
 * Google からの戻りは「中継サーバー → 各サイト」の順に転送されるため、戻り先の URL を
 * 偽装されると、許可コードを第三者のサイトへ配送させられる恐れがある。これを防ぐため、
 * 接続を始めるサイトが毎回ランダムな文字列（`code_verifier`）を作り、そのハッシュ
 * （`code_challenge`）だけを Google へ渡しておく。許可コードを実際に引き換える
 * （`POST /auth/token`）ときに元の文字列を示せるのは、接続を始めたサイトだけになる。
 * これは OAuth の標準仕様（PKCE, RFC 7636）で、Google も対応している。
 *
 * ## Pro 版のライセンスキーを添えて送る
 * 中継サーバーは、Pro 版のライセンスキーを持つサイトからの依頼だけを受け付ける
 * （2026-09-21 決定）。検証キーは、Pro 版の更新チェック（`VKBM_Pro_Updater`）と
 * 同じ option（`vk-booking-manager-pro-license-key`）・同じ値を使う想定。
 *
 * **ライセンスキーはブラウザが遷移する URL には載せない**（2026-09-21 決定・安藤レビュー指摘）。
 * ライセンスキーは Pro 版の更新を受け取る権限に加え、この決定で「中継サーバーを使う権限」も
 * 兼ねる認証情報のため、URL に載せるとオーナーのブラウザのアドレスバー・履歴・中継サーバーの
 * アクセスログ・経路上のプロキシのログに残ってしまう。そのため、全ての依頼のうち
 * サーバー間通信（`request()` 経由。`POST /auth/session` `POST /auth/token`
 * `POST /auth/refresh` `POST /auth/revoke`）には JSON の本文でライセンスキーを添え、
 * オーナーのブラウザが実際に遷移する `GET /auth/start` にはライセンスキーを一切含めない
 * （`start_session()` で先にサーバー間通信を行い、ブラウザには使い捨ての `ticket` だけを渡す。
 * `build_authorization_url()` を参照）。
 *
 * ライセンスキーが未設定のサイトでは、依頼を送っても中継サーバーに拒否されるだけなので、
 * `Google_Calendar_Connect_Controller::handle_connect()` が Google の画面へ送り出す前に
 * `has_license_key()` で止め、ライセンスキーの入力を促す（Google の同意画面まで進めたのに
 * 引き換えだけ失敗する分かりにくい失敗を避けるため）。
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
use function apply_filters;
use function get_option;
use function is_wp_error;
use function sanitize_text_field;
use function untrailingslashit;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * 中継サーバーとの通信を行うクラス。
 */
class Google_Calendar_Relay_Client {

	/**
	 * 中継サーバーの既定の URL。
	 *
	 * **空文字なのは意図的**（2026-09-20 時点で中継サーバーが未構築のため）。空のあいだは
	 * `is_configured()` が false を返し、BM 設定に「連携」タブが出ず、連携の受け口
	 * （`admin-post.php` の各 action）も登録されない。つまり、この状態でマージ・リリースしても
	 * オーナーの画面には何も現れない。
	 *
	 * 仮の URL を入れて「リリース前に差し替える」という注意書きで運用すると、差し替え忘れに
	 * 気づけないまま、つながらない連携ボタンがオーナーに見えてしまう。加えて Google の許可画面の
	 * 審査が終わるまでは、テストユーザー以外が連携しようとすると Google の警告画面で止まる。
	 * そのため注意書きではなく、URL が入るまで機能ごと現れない形にしている。
	 *
	 * @todo 中継サーバーを Cloudflare Workers 上に構築し、Google の許可画面の審査が通った時点で、
	 *       実際に払い出されたホスト名を入れる。この定数に URL が入った時点で、Pro 版の
	 *       BM 設定に「連携」タブが現れる。
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = '';

	/**
	 * 中継サーバーへの通信のタイムアウト（秒）。
	 *
	 * 中継サーバーが応答しないときに、呼び出し元の処理（管理画面の表示や、#476 での予約の保存）が
	 * 長時間止まらないようにするための上限。
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Pro 版のライセンスキーを保存している option 名。
	 *
	 * Pro 版の更新チェック（`VKBM_Pro_Updater::register()`）と同じ option・同じ値を使う。
	 *
	 * @var string
	 */
	private const LICENSE_KEY_OPTION_NAME = 'vk-booking-manager-pro-license-key';

	/**
	 * 中継サーバーの URL を返す。
	 *
	 * wp-config.php の定数 `VKBM_GOOGLE_CALENDAR_RELAY_URL`、フィルター
	 * `vkbm_google_calendar_relay_url` の順で上書きできる。開発中に手元の中継サーバーへ
	 * 向けたい場合に使う。
	 *
	 * @return string 末尾のスラッシュを除いた URL。
	 */
	public function get_base_url(): string {
		$base_url = self::DEFAULT_BASE_URL;

		if ( defined( 'VKBM_GOOGLE_CALENDAR_RELAY_URL' ) && is_string( VKBM_GOOGLE_CALENDAR_RELAY_URL ) && '' !== VKBM_GOOGLE_CALENDAR_RELAY_URL ) {
			$base_url = (string) VKBM_GOOGLE_CALENDAR_RELAY_URL;
		}

		/**
		 * 中継サーバーの URL を上書きするフィルター。
		 *
		 * @param string $base_url 中継サーバーの URL。
		 */
		$base_url = (string) apply_filters( 'vkbm_google_calendar_relay_url', $base_url );

		return untrailingslashit( $base_url );
	}

	/**
	 * 中継サーバーの接続先が決まっているかどうかを返す。
	 *
	 * 接続先が空のあいだは、連携そのものをオーナーに見せない（DEFAULT_BASE_URL の説明を参照）。
	 *
	 * 暗号化されない接続（`http://`）も「決まっていない」として扱う。中継サーバーへの依頼には
	 * Pro 版のライセンスキーとアクセス許可（トークン）を載せるため、盗み見られる経路で
	 * やり取りさせないため（安藤レビュー指摘）。ライセンスキーは Pro 版の更新チェックとも
	 * 共用の、長く使い回す資格情報である点も考慮している。
	 *
	 * ただし手元で中継サーバーを動かして確認する場合（`wrangler dev` 等）は `http://` に
	 * なるため、`localhost` と `127.0.0.1` だけは例外として認める。
	 *
	 * @return bool 接続先が設定されていれば true。
	 */
	public function is_configured(): bool {
		$base_url = $this->get_base_url();

		if ( '' === $base_url ) {
			return false;
		}

		if ( 0 === strpos( $base_url, 'https://' ) ) {
			return true;
		}

		return 0 === strpos( $base_url, 'http://localhost' ) || 0 === strpos( $base_url, 'http://127.0.0.1' );
	}

	/**
	 * Pro 版のライセンスキーが設定されているかどうかを返す。
	 *
	 * 空のまま連携を始めても、この後の依頼は全て中継サーバーに拒否されるため、
	 * `Google_Calendar_Connect_Controller::handle_connect()` がこれで事前に連携の開始を止める。
	 *
	 * @return bool 設定されていれば true。
	 */
	public function has_license_key(): bool {
		return '' !== $this->get_license_key();
	}

	/**
	 * 保存されている Pro 版のライセンスキーを返す。
	 *
	 * @return string ライセンスキー。未設定なら空文字。
	 */
	private function get_license_key(): string {
		$raw = get_option( self::LICENSE_KEY_OPTION_NAME, '' );

		return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
	}

	/**
	 * ライセンスキーを検証してもらい、`GET /auth/start` で使う使い捨てのチケットを発行してもらう。
	 *
	 * `state` / `code_challenge` / `site_callback` / ライセンスキーはサーバー間通信（このメソッド）
	 * だけに乗せ、オーナーのブラウザが遷移する `GET /auth/start`（`build_authorization_url()`）
	 * には返ってきた `ticket` だけを渡す。ライセンスキーをブラウザが遷移する URL に載せない
	 * ための構成（2026-09-21 決定。安藤レビュー指摘）。
	 *
	 * @param string $state          サイト側で発行した照合用のランダムな文字列。戻ってきたときに同じ値か確かめる。
	 * @param string $code_challenge `code_verifier` のハッシュ。横取りを防ぐために Google へ渡される。
	 * @param string $callback_url   Google の許可が終わったあとに戻ってくる、このサイトの URL。
	 * @return string|WP_Error 成功時は `GET /auth/start` へ渡すチケット、失敗時は WP_Error。
	 */
	public function start_session( string $state, string $code_challenge, string $callback_url ) {
		$response = $this->request(
			'/auth/session',
			array(
				'state'          => $state,
				'code_challenge' => $code_challenge,
				'site_callback'  => $callback_url,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$ticket = isset( $response['ticket'] ) ? (string) $response['ticket'] : '';

		if ( '' === $ticket ) {
			return new WP_Error(
				'vkbm_google_calendar_invalid_session_response',
				__( 'The response from the relay server did not include the required ticket.', 'vk-booking-manager' )
			);
		}

		return $ticket;
	}

	/**
	 * オーナーのブラウザを送り出す先（中継サーバーの入り口）の URL を組み立てる。
	 *
	 * `ticket` は `start_session()` があらかじめ中継サーバーへライセンスキーを添えて
	 * 検証してもらい、発行してもらった使い捨ての値。ライセンスキーそのものは、この URL
	 * （オーナーのブラウザが実際に遷移する）には含まれない。
	 *
	 * @param string $ticket `start_session()` が返したチケット。
	 * @return string 送り先の URL。
	 */
	public function build_authorization_url( string $ticket ): string {
		return add_query_arg(
			array(
				'ticket' => rawurlencode( $ticket ),
			),
			$this->get_base_url() . '/auth/start'
		);
	}

	/**
	 * Google から受け取った許可コードを、アクセス許可（トークン）へ引き換える。
	 *
	 * 引き換えは中継サーバーが Google との間で行う。このサイトは結果を受け取るだけで、
	 * Google の接続情報（クライアントシークレット）を持たない。
	 *
	 * @param string $code          Google から戻ってきた許可コード。
	 * @param string $code_verifier 接続を始めたときに作ったランダムな文字列。
	 * @param string $callback_url  接続を始めたときに伝えた戻り先の URL。
	 * @return array{access_token:string, refresh_token:string, expires_in:int, email:string}|WP_Error 成功時はトークン情報、失敗時は WP_Error。
	 */
	public function exchange_code( string $code, string $code_verifier, string $callback_url ) {
		$response = $this->request(
			'/auth/token',
			array(
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'site_callback' => $callback_url,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$access_token  = isset( $response['access_token'] ) ? (string) $response['access_token'] : '';
		$refresh_token = isset( $response['refresh_token'] ) ? (string) $response['refresh_token'] : '';

		if ( '' === $access_token || '' === $refresh_token ) {
			return new WP_Error(
				'vkbm_google_calendar_invalid_token_response',
				__( 'The response from Google did not include the required permission.', 'vk-booking-manager' )
			);
		}

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 0,
			'email'         => isset( $response['email'] ) ? (string) $response['email'] : '',
		);
	}

	/**
	 * 期限が切れたアクセストークンを取り直す。
	 *
	 * アクセス許可は1時間で失効するため、Google を呼ぶ前に必要に応じてこれを通す。
	 *
	 * @param string $refresh_token 保存済みのリフレッシュトークン。
	 * @return array{access_token:string, expires_in:int}|WP_Error 成功時は新しいトークン、失敗時は WP_Error。
	 */
	public function refresh_access_token( string $refresh_token ) {
		$response = $this->request(
			'/auth/refresh',
			array(
				'refresh_token' => $refresh_token,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$access_token = isset( $response['access_token'] ) ? (string) $response['access_token'] : '';

		if ( '' === $access_token ) {
			return new WP_Error(
				'vkbm_google_calendar_invalid_refresh_response',
				__( 'The response from Google did not include the required permission.', 'vk-booking-manager' )
			);
		}

		return array(
			'access_token' => $access_token,
			'expires_in'   => isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 0,
		);
	}

	/**
	 * Google 側のアクセス許可を取り消す。
	 *
	 * 連携の解除のときに呼ぶ。取り消しに失敗しても、このサイトからは保存済みの情報を消すため、
	 * 解除操作そのものは成功として扱う（戻り値は記録・テスト用）。
	 *
	 * @param string $refresh_token 保存済みのリフレッシュトークン。
	 * @return bool 取り消せたら true。
	 */
	public function revoke( string $refresh_token ): bool {
		$response = $this->request(
			'/auth/revoke',
			array(
				'refresh_token' => $refresh_token,
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * 中継サーバーへ POST し、JSON の応答を配列にして返す。
	 *
	 * Pro 版のライセンスキーを持つサイトからの依頼だけを中継サーバーが受け付けるため、
	 * `license_key` を本文へ添えて送る。
	 *
	 * @param string               $path 中継サーバー上のパス（先頭スラッシュ付き）。
	 * @param array<string, mixed> $body 送信する内容。
	 * @return array<string, mixed>|WP_Error 応答の配列、または失敗時の WP_Error。
	 */
	protected function request( string $path, array $body ) {
		$body['license_key'] = $this->get_license_key();

		$response = wp_remote_post(
			$this->get_base_url() . $path,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// 中継サーバーへ届かなかった場合（DNS・接続断・タイムアウト）。
			return new WP_Error(
				'vkbm_google_calendar_relay_unreachable',
				__( 'Could not reach the relay server.', 'vk-booking-manager' ),
				array( 'detail' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}

		if ( $status < 200 || $status >= 300 ) {
			// 中継サーバーが返すエラー種別をそのまま持ち回り、呼び出し側で切り分けられるようにする。
			$code = isset( $parsed['error'] ) ? (string) $parsed['error'] : 'vkbm_google_calendar_relay_error';

			return new WP_Error(
				$code,
				__( 'The relay server returned an error.', 'vk-booking-manager' ),
				array( 'status' => $status )
			);
		}

		return $parsed;
	}

	/**
	 * 横取り防止（PKCE）に使うランダムな文字列を作る。
	 *
	 * @return string URL に含められる文字だけで構成した文字列。
	 */
	public static function generate_code_verifier(): string {
		return self::base64url_encode( random_bytes( 32 ) );
	}

	/**
	 * `code_verifier` から、Google へ渡すハッシュ（`code_challenge`）を作る。
	 *
	 * @param string $code_verifier generate_code_verifier() が返した文字列。
	 * @return string ハッシュ。
	 */
	public static function create_code_challenge( string $code_verifier ): string {
		return self::base64url_encode( hash( 'sha256', $code_verifier, true ) );
	}

	/**
	 * URL に含められる形（base64url）へエンコードする。
	 *
	 * @param string $binary エンコードしたいバイナリ。
	 * @return string エンコード結果。
	 */
	private static function base64url_encode( string $binary ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth の仕様（RFC 7636）が定める形式への変換（難読化目的ではない）.
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}
}
