<?php
/**
 * 中継サーバーとの通信を担当するクラスのテスト。
 *
 * issue #475。実際の中継サーバーへは接続せず、WordPress の HTTP 通信を
 * `pre_http_request` フィルターで差し替えて確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use WP_Error;
use WP_UnitTestCase;
use function add_filter;
use function delete_option;
use function remove_filter;
use function update_option;

/**
 * Google_Calendar_Relay_Client のテスト。
 */
class Test_Google_Calendar_Relay_Client extends WP_UnitTestCase {

	/**
	 * 差し替えた HTTP 通信の応答を覚えておく変数。
	 *
	 * @var array<string, mixed>|WP_Error|null
	 */
	private $mock_response = null;

	/**
	 * 差し替えた HTTP 通信が受け取ったリクエストを覚えておく変数。
	 *
	 * @var array<string, mixed>
	 */
	private $captured_request = array();

	/**
	 * テスト対象。
	 *
	 * @var Google_Calendar_Relay_Client
	 */
	private $client;

	/**
	 * HTTP 通信を差し替えるフィルターの参照（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * 各テストの前に、HTTP 通信を差し替える。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->client = new Google_Calendar_Relay_Client();

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			return $this->mock_response;
		};

		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * 各テストの後に、差し替えを元へ戻す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}

		$this->mock_response    = null;
		$this->captured_request = array();
		delete_option( 'vk-booking-manager-pro-license-key' );

		parent::tear_down();
	}

	/**
	 * 許可コードの引き換えが、応答の内容ごとに期待どおりの結果になること。
	 *
	 * @return void
	 */
	public function test_exchange_code(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクセス許可が揃って返ってきた => 内容を取り出せる',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"access_token":"at","refresh_token":"rt","expires_in":3600,"email":"owner@example.com"}',
				),
				'expected'            => 'array',
			),
			array(
				'test_condition_name' => 'リフレッシュトークンが欠けている => エラー',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"access_token":"at","expires_in":3600}',
				),
				'expected'            => 'vkbm_google_calendar_invalid_token_response',
			),
			array(
				'test_condition_name' => '中継サーバーが 400 を返した => 中継サーバーのエラー種別をそのまま持つ',
				'response'            => array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_grant"}',
				),
				'expected'            => 'invalid_grant',
			),
			array(
				'test_condition_name' => '中継サーバーが 500 を返し内容が空 => 汎用のエラー',
				'response'            => array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				),
				'expected'            => 'vkbm_google_calendar_relay_error',
			),
			array(
				'test_condition_name' => '中継サーバーへ届かない => 届かなかったことが分かるエラー',
				'response'            => new WP_Error( 'http_request_failed', 'timeout' ),
				'expected'            => 'vkbm_google_calendar_relay_unreachable',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->mock_response = $case['response'];

			$result = $this->client->exchange_code( 'auth-code', 'verifier', 'https://example.com/wp-admin/admin-post.php' );

			if ( 'array' === $case['expected'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
				$this->assertSame( 'at', $result['access_token'], $case['test_condition_name'] );
				$this->assertSame( 'rt', $result['refresh_token'], $case['test_condition_name'] );
				$this->assertSame( 3600, $result['expires_in'], $case['test_condition_name'] );
				$this->assertSame( 'owner@example.com', $result['email'], $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
		}
	}

	/**
	 * アクセストークンの取り直しが、応答の内容ごとに期待どおりの結果になること。
	 *
	 * @return void
	 */
	public function test_refresh_access_token(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '新しいアクセストークンが返ってきた => 内容を取り出せる',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"access_token":"new-at","expires_in":3600}',
				),
				'expected'            => 'array',
			),
			array(
				'test_condition_name' => 'アクセストークンが欠けている => エラー',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"expires_in":3600}',
				),
				'expected'            => 'vkbm_google_calendar_invalid_refresh_response',
			),
			array(
				'test_condition_name' => '許可が取り消されている => 中継サーバーのエラー種別をそのまま持つ',
				'response'            => array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_grant"}',
				),
				'expected'            => 'invalid_grant',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->mock_response = $case['response'];

			$result = $this->client->refresh_access_token( 'refresh-token' );

			if ( 'array' === $case['expected'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
				$this->assertSame( 'new-at', $result['access_token'], $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
		}
	}

	/**
	 * 送り出し先の URL に、チケットだけが含まれること。
	 *
	 * ライセンスキーはもちろん、`state` / `code_challenge` / `site_callback` も
	 * オーナーのブラウザが遷移するこの URL には含めない（`start_session()` で
	 * サーバー間通信のときにだけ渡す。2026-09-21 決定）。
	 *
	 * @return void
	 */
	public function test_build_authorization_url(): void {
		$url = $this->client->build_authorization_url( 'ticket-value' );

		$this->assertStringStartsWith( $this->client->get_base_url() . '/auth/start', $url, '中継サーバーの入り口へ送り出すこと' );
		$this->assertStringContainsString( 'ticket=ticket-value', $url, 'チケットが含まれること' );
		$this->assertStringNotContainsString( 'license_key', $url, 'ライセンスキーがブラウザの遷移先 URL に含まれないこと' );
		$this->assertStringNotContainsString( 'state=', $url, 'state がブラウザの遷移先 URL に含まれないこと' );
		$this->assertStringNotContainsString( 'code_challenge', $url, 'code_challenge がブラウザの遷移先 URL に含まれないこと' );
		$this->assertStringNotContainsString( 'site_callback', $url, 'site_callback がブラウザの遷移先 URL に含まれないこと' );
	}

	/**
	 * チケットの発行（`POST /auth/session`）が、応答の内容ごとに期待どおりの結果になること。
	 *
	 * ライセンスキー・state・code_challenge・site_callback は、ここ（サーバー間通信）でだけ送る。
	 *
	 * @return void
	 */
	public function test_start_session(): void {
		update_option( 'vk-booking-manager-pro-license-key', 'license-key-value' );

		$test_cases = array(
			array(
				'test_condition_name' => 'チケットが返ってきた => 取り出せる',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"ticket":"ticket-value"}',
				),
				'expected'            => 'ticket-value',
			),
			array(
				'test_condition_name' => 'チケットが欠けている => エラー',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				),
				'expected'            => 'vkbm_google_calendar_invalid_session_response',
			),
			array(
				'test_condition_name' => 'ライセンスキーが無効 => 中継サーバーのエラー種別をそのまま持つ',
				'response'            => array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":"invalid_license"}',
				),
				'expected'            => 'invalid_license',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->mock_response = $case['response'];

			$result = $this->client->start_session( 'state-value', 'challenge-value', 'https://example.com/wp-admin/admin-post.php?action=cb' );

			if ( 'ticket-value' === $case['expected'] ) {
				$this->assertSame( 'ticket-value', $result, $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
		}

		// 送信内容の確認（最後に呼んだときの内容が残っている）。
		$sent_body = json_decode( (string) $this->captured_request['args']['body'], true );
		$this->assertSame( 'state-value', $sent_body['state'], 'state が本文で送られること' );
		$this->assertSame( 'challenge-value', $sent_body['code_challenge'], 'code_challenge が本文で送られること' );
		$this->assertSame( 'https://example.com/wp-admin/admin-post.php?action=cb', $sent_body['site_callback'], 'site_callback が本文で送られること' );
		$this->assertSame( 'license-key-value', $sent_body['license_key'], 'ライセンスキーが本文で送られること' );
		$this->assertStringContainsString( '/auth/session', $this->captured_request['url'], 'POST /auth/session を呼ぶこと' );
	}

	/**
	 * 中継サーバーへのサーバー間通信（`POST` 系）に、Pro 版のライセンスキーが添えられること。
	 *
	 * Pro 版のライセンスキーを持つサイトからの依頼だけを中継サーバーが受け付けるため
	 * （2026-09-21 決定）。未設定なら空文字を添える（`has_license_key()` で事前に止めるのは
	 * 呼び出し側 `Google_Calendar_Connect_Controller::handle_connect()` の責務）。
	 *
	 * ライセンスキーはオーナーのブラウザが遷移する URL（`GET /auth/start`）には一切含めない
	 * （安藤レビュー指摘。`test_build_authorization_url()` で確認済み）。
	 *
	 * @return void
	 */
	public function test_requests_include_the_license_key(): void {
		update_option( 'vk-booking-manager-pro-license-key', 'license-key-value' );

		$this->mock_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"at","refresh_token":"rt","expires_in":3600,"email":"owner@example.com"}',
		);
		$this->client->exchange_code( 'auth-code', 'verifier', 'https://example.com/wp-admin/admin-post.php' );
		$sent_body = json_decode( (string) $this->captured_request['args']['body'], true );
		$this->assertSame( 'license-key-value', $sent_body['license_key'], 'POST /auth/token にライセンスキーが添えられること' );

		$this->mock_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"new-at","expires_in":3600}',
		);
		$this->client->refresh_access_token( 'refresh-token' );
		$sent_body = json_decode( (string) $this->captured_request['args']['body'], true );
		$this->assertSame( 'license-key-value', $sent_body['license_key'], 'POST /auth/refresh にライセンスキーが添えられること' );

		$this->mock_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);
		$this->client->revoke( 'refresh-token' );
		$sent_body = json_decode( (string) $this->captured_request['args']['body'], true );
		$this->assertSame( 'license-key-value', $sent_body['license_key'], 'POST /auth/revoke にライセンスキーが添えられること' );
	}

	/**
	 * ライセンスキーが設定されているかどうかの判定。
	 *
	 * 空のまま連携を始めても、この後の依頼は全て中継サーバーに拒否されるため、
	 * `Google_Calendar_Connect_Controller::handle_connect()` がこれで事前に止める。
	 *
	 * @return void
	 */
	public function test_has_license_key(): void {
		delete_option( 'vk-booking-manager-pro-license-key' );
		$this->assertFalse( $this->client->has_license_key(), '未設定なら false' );

		update_option( 'vk-booking-manager-pro-license-key', 'license-key-value' );
		$this->assertTrue( $this->client->has_license_key(), '設定されていれば true' );
	}

	/**
	 * 横取り防止（PKCE）用のハッシュが、OAuth の仕様どおりに作られること。
	 *
	 * 期待値は RFC 7636 の付録 B に載っている例をそのまま使っている。
	 *
	 * @return void
	 */
	public function test_create_code_challenge(): void {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Google_Calendar_Relay_Client::create_code_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ),
			'OAuth の仕様（RFC 7636）の例と同じハッシュになること'
		);
	}

	/**
	 * 横取り防止（PKCE）用のランダムな文字列が、毎回異なり URL に含められる文字だけで作られること。
	 *
	 * @return void
	 */
	public function test_generate_code_verifier(): void {
		$first  = Google_Calendar_Relay_Client::generate_code_verifier();
		$second = Google_Calendar_Relay_Client::generate_code_verifier();

		$this->assertNotSame( $first, $second, '毎回異なる文字列になること' );
		$this->assertMatchesRegularExpression( '/\A[A-Za-z0-9\-_]{43,128}\z/', $first, 'OAuth の仕様が認める文字と長さの範囲に収まること' );
	}

	/**
	 * 中継サーバーの URL を、定数やフィルターで差し替えられること。
	 *
	 * 開発中に手元の中継サーバーへ向けるために必要。
	 *
	 * @return void
	 */
	public function test_get_base_url(): void {
		$this->assertSame( '', $this->client->get_base_url(), '既定では接続先が空であること' );

		$filter = static function (): string {
			return 'https://relay.test/';
		};
		add_filter( 'vkbm_google_calendar_relay_url', $filter );

		$this->assertSame( 'https://relay.test', $this->client->get_base_url(), 'フィルターで差し替えられ、末尾のスラッシュが除かれること' );

		remove_filter( 'vkbm_google_calendar_relay_url', $filter );
	}

	/**
	 * 中継サーバーの接続先が決まっているかどうかの判定。
	 *
	 * 接続先が空のあいだは、連携そのものをオーナーに見せない。仮の URL を入れて
	 * 「リリース前に差し替える」運用にすると、差し替え忘れに気づけないまま、つながらない
	 * 連携ボタンがオーナーに見えてしまうため（issue #475）。
	 *
	 * 暗号化されない接続（`http://`）も「決まっていない」として扱う。中継サーバーへの依頼には
	 * Pro 版のライセンスキーとアクセス許可を載せるため（安藤レビュー指摘）。手元で中継サーバーを
	 * 動かす場合のみ `localhost` と `127.0.0.1` を例外として認める。
	 *
	 * @return void
	 */
	public function test_is_configured(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '接続先が未設定（既定） => まだ使えない',
				'relay_url'           => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '接続先が空文字に上書きされている => まだ使えない',
				'relay_url'           => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '接続先が https で設定されている => 使える',
				'relay_url'           => 'https://relay.test',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '接続先が http（暗号化されない） => まだ使えない',
				'relay_url'           => 'http://relay.test',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '手元の中継サーバー（http://localhost） => 使える',
				'relay_url'           => 'http://localhost:8787',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '手元の中継サーバー（http://127.0.0.1） => 使える',
				'relay_url'           => 'http://127.0.0.1:8787',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$filter = null;

			if ( null !== $case['relay_url'] ) {
				$filter = static function () use ( $case ): string {
					return (string) $case['relay_url'];
				};
				add_filter( 'vkbm_google_calendar_relay_url', $filter );
			}

			$this->assertSame( $case['expected'], $this->client->is_configured(), $case['test_condition_name'] );

			if ( null !== $filter ) {
				remove_filter( 'vkbm_google_calendar_relay_url', $filter );
			}
		}
	}
}
