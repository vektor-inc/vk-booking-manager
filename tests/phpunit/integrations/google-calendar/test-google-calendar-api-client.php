<?php
/**
 * Google カレンダーの API を呼ぶクラスのテスト。
 *
 * issue #475。Google・中継サーバーへは接続せず、WordPress の HTTP 通信を
 * `pre_http_request` フィルターで差し替えて確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Api_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;
use WP_Error;
use WP_UnitTestCase;
use function add_filter;
use function delete_option;
use function delete_transient;
use function remove_filter;

/**
 * Google_Calendar_Api_Client のテスト。
 */
class Test_Google_Calendar_Api_Client extends WP_UnitTestCase {

	/**
	 * 送信先 URL ごとの応答を並べた配列。
	 *
	 * キーは URL に含まれる文字列。最初に一致したものを返す。
	 *
	 * @var array<string, mixed>
	 */
	private $mock_responses = array();

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * テスト対象。
	 *
	 * @var Google_Calendar_Api_Client
	 */
	private $client;

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
	 * 直近に送信されたリクエストの URL（issue #476 の sendUpdates=none 確認用）。
	 *
	 * @var string
	 */
	private $last_request_url = '';

	/**
	 * 直近に送信されたリクエストの引数（HTTP メソッド等。issue #476 の確認用）。
	 *
	 * @var array<string, mixed>
	 */
	private $last_request_args = array();

	/**
	 * 各テストの前に、接続済みの状態を作り、HTTP 通信を差し替える。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_transient( 'vkbm_google_calendar_refresh_throttle' );

		$this->connection = new Google_Calendar_Connection();
		$this->client     = new Google_Calendar_Api_Client( $this->connection, new Google_Calendar_Relay_Client() );

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->last_request_url  = (string) $url;
			$this->last_request_args = $args;

			foreach ( $this->mock_responses as $needle => $response ) {
				if ( false !== strpos( (string) $url, (string) $needle ) ) {
					return $response;
				}
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		// 中継サーバーの接続先は既定で空（未構築のため）。テストでは仮の接続先を入れて、
		// 連携が使える状態を作る（issue #475）。
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

		$this->mock_responses = array();
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_transient( 'vkbm_google_calendar_refresh_throttle' );

		parent::tear_down();
	}

	/**
	 * Google の応答から、画面で使う一覧を取り出せること。
	 *
	 * 通信を伴わない変換だけを確かめる。
	 *
	 * @return void
	 */
	public function test_normalize_calendar_list(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '書き込めるカレンダーだけが残り、本人の既定のものが先頭に来る',
				'items'               => array(
					array(
						'id'         => 'shared@example.com',
						'summary'    => '共有',
						'accessRole' => 'writer',
					),
					array(
						'id'         => 'owner@example.com',
						'summary'    => '本人',
						'accessRole' => 'owner',
						'primary'    => true,
					),
				),
				'expected'            => array( 'owner@example.com', 'shared@example.com' ),
			),
			array(
				'test_condition_name' => '閲覧しかできないカレンダーは除外される',
				'items'               => array(
					array(
						'id'         => 'readonly@example.com',
						'summary'    => '閲覧のみ',
						'accessRole' => 'reader',
					),
					array(
						'id'         => 'free-busy@example.com',
						'summary'    => '予定ありのみ',
						'accessRole' => 'freeBusyReader',
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'ID が無い項目は除外される',
				'items'               => array(
					array(
						'summary'    => 'ID が無い',
						'accessRole' => 'owner',
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '本人の既定が無い場合は名前順に並ぶ',
				'items'               => array(
					array(
						'id'         => 'b@example.com',
						'summary'    => 'B',
						'accessRole' => 'writer',
					),
					array(
						'id'         => 'a@example.com',
						'summary'    => 'A',
						'accessRole' => 'writer',
					),
				),
				'expected'            => array( 'a@example.com', 'b@example.com' ),
			),
			array(
				'test_condition_name' => '空の応答 => 空の一覧',
				'items'               => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = array_column( Google_Calendar_Api_Client::normalize_calendar_list( $case['items'] ), 'id' );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 期限切れのアクセストークンが、中継サーバー経由で取り直されること。
	 *
	 * @return void
	 */
	public function test_get_access_token(): void {
		// 期限切れのアクセストークンを持つ接続済みの状態を作る。
		$this->connection->save_tokens(
			array(
				'access_token'  => 'old-access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 1,
			)
		);

		$this->mock_responses = array(
			'/auth/refresh' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"access_token":"new-access-token","expires_in":3600}',
			),
		);

		$this->assertSame( 'new-access-token', $this->client->get_access_token(), '期限切れなら取り直して返すこと' );
		$this->assertSame( 'new-access-token', $this->connection->get_access_token(), '取り直した結果が保存されること' );
		$this->assertSame( 'connected', $this->connection->get_status(), '取り直しに成功したら接続済みのままであること' );

		// 2回目は保存済みの有効なトークンを使い、中継サーバーへは問い合わせないこと。
		$this->mock_responses = array(
			'/auth/refresh' => new WP_Error( 'should_not_be_called', '2回目は呼ばれないはず' ),
		);
		$this->assertSame( 'new-access-token', $this->client->get_access_token(), '有効期限内なら取り直さないこと' );
	}

	/**
	 * 取り直しに失敗したときに、連続で問い合わせないよう抑止されること。
	 *
	 * 中継サーバーが止まっているときに、呼び出し元が毎回待たされるのを避けるための抑止。
	 * これは失敗の理由（到達不可・認可の失敗のいずれ）でも共通の挙動。
	 *
	 * @return void
	 */
	public function test_get_access_token_when_refresh_fails(): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'old-access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 1,
			)
		);

		$this->mock_responses = array(
			'/auth/refresh' => new WP_Error( 'http_request_failed', 'timeout' ),
		);

		$first = $this->client->get_access_token();

		$this->assertInstanceOf( WP_Error::class, $first, '取り直しに失敗したら WP_Error を返すこと（例外は投げない）' );
		$this->assertSame( 'vkbm_google_calendar_relay_unreachable', $first->get_error_code(), '中継サーバーへ届かなかったことが分かること' );

		$second = $this->client->get_access_token();

		$this->assertInstanceOf( WP_Error::class, $second, '続けて呼んでも WP_Error を返すこと' );
		$this->assertSame( 'vkbm_google_calendar_refresh_throttled', $second->get_error_code(), '失敗した直後は中継サーバーへ問い合わせないこと' );
	}

	/**
	 * 取り直しの失敗理由ごとに、接続状態を「切れた」扱いにするかどうかが変わること
	 * （安藤レビュー指摘）。
	 *
	 * 中継サーバーへ一時的に届かなかっただけ・中継サーバー側の一時的な不調（5xx）では、
	 * 画面が「連携が切れています」に固定されて再接続するまで復帰しない事態を避けるため、
	 * 接続状態は `connected` のまま維持する。認可そのものが失われた場合（`invalid_grant` 等、
	 * HTTP 400/401 系）だけを「切れた」として記録する。
	 *
	 * ライセンスキー起因の失敗（中継サーバーが `license` を含む種別で返すもの）も、HTTP 400/401 で
	 * 返ってくる可能性があるが、これは Google の認可が生きているのにライセンスの更新が必要な状態。
	 * 「Googleとの連携が切れています。」と誤表示しないよう、接続済みのまま維持することを確かめる
	 * （安藤レビュー指摘。仕様書の「中継サーバーが満たすべき要件」でこの種別を要件にしている）。
	 *
	 * @return void
	 */
	public function test_get_access_token_marks_error_only_when_authorization_is_lost(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '中継サーバーへ届かない（ネットワーク断） => 接続済みのまま維持する',
				'response'            => new WP_Error( 'http_request_failed', 'timeout' ),
				'expected_status'     => 'connected',
			),
			array(
				'test_condition_name' => '中継サーバー側の一時的な不調（500） => 接続済みのまま維持する',
				'response'            => array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				),
				'expected_status'     => 'connected',
			),
			array(
				'test_condition_name' => '許可が取り消されている（400・invalid_grant） => 連携が切れた扱いにする',
				'response'            => array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_grant"}',
				),
				'expected_status'     => 'error',
			),
			array(
				'test_condition_name' => '認可されていない（401） => 連携が切れた扱いにする',
				'response'            => array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":"invalid_client"}',
				),
				'expected_status'     => 'error',
			),
			array(
				'test_condition_name' => 'ライセンスキーが無効（400・invalid_license） => 接続済みのまま維持する',
				'response'            => array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_license"}',
				),
				'expected_status'     => 'connected',
			),
			array(
				'test_condition_name' => 'ライセンスキーの期限切れ（401・license_expired） => 接続済みのまま維持する',
				'response'            => array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":"license_expired"}',
				),
				'expected_status'     => 'connected',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );
			delete_transient( 'vkbm_google_calendar_refresh_throttle' );
			$this->connection->save_tokens(
				array(
					'access_token'  => 'old-access-token',
					'refresh_token' => 'refresh-token',
					'expires_in'    => 1,
				)
			);

			$this->mock_responses = array( '/auth/refresh' => $case['response'] );

			$result = $this->client->get_access_token();

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected_status'], $this->connection->get_status(), $case['test_condition_name'] );
		}
	}

	/**
	 * カレンダー一覧の取得が、Google の応答ごとに期待どおりの結果になること。
	 *
	 * @return void
	 */
	public function test_get_calendar_list(): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'カレンダーが返ってきた => 一覧を取り出せる',
				'response'            => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"items":[{"id":"owner@example.com","summary":"本人","accessRole":"owner","primary":true}]}',
				),
				'expected'            => 'array',
			),
			array(
				'test_condition_name' => '許可が取り消されている（401） => 連携が切れた扱い',
				'response'            => array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":{"code":401}}',
				),
				'expected'            => 'vkbm_google_calendar_unauthorized',
			),
			array(
				'test_condition_name' => 'Google 側の不調（500） => 取得できなかったエラー',
				'response'            => array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				),
				'expected'            => 'vkbm_google_calendar_request_failed',
			),
			array(
				'test_condition_name' => 'Google へ届かない => 届かなかったことが分かるエラー',
				'response'            => new WP_Error( 'http_request_failed', 'timeout' ),
				'expected'            => 'vkbm_google_calendar_unreachable',
			),
		);

		foreach ( $test_cases as $case ) {
			// 各条件の前に、接続済み・アクセストークンが有効な状態へ戻す。
			delete_option( Google_Calendar_Connection::OPTION_NAME );
			delete_transient( 'vkbm_google_calendar_refresh_throttle' );
			$this->connection->save_tokens(
				array(
					'access_token'  => 'access-token',
					'refresh_token' => 'refresh-token',
					'expires_in'    => 3600,
				)
			);

			$this->mock_responses = array( 'calendarList' => $case['response'] );

			$result = $this->client->get_calendar_list();

			if ( 'array' === $case['expected'] ) {
				$this->assertIsArray( $result, $case['test_condition_name'] );
				$this->assertSame( 'owner@example.com', $result[0]['id'], $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );

			// 許可が取り消されたときだけ、画面に「連携が切れています」と出せるよう状態を記録する。
			// Google 側の一時的な不調や通信の失敗で、連携そのものを切れた扱いにしないため。
			$expected_status = 'vkbm_google_calendar_unauthorized' === $case['expected'] ? 'error' : 'connected';
			$this->assertSame( $expected_status, $this->connection->get_status(), $case['test_condition_name'] . '（接続状態の記録）' );
		}
	}

	/**
	 * 接続済みの状態を作る共通ヘルパー（issue #476 のテスト用）。
	 *
	 * @return void
	 */
	private function connect(): void {
		$this->connection->save_tokens(
			array(
				'access_token'  => 'access-token',
				'refresh_token' => 'refresh-token',
				'expires_in'    => 3600,
			)
		);
	}

	/**
	 * create_event() が、成功時にイベント ID を返すこと、招待メールを送らない
	 * （sendUpdates=none）を必ず付けること、指定した予定IDを本文の `id` に含めて送ること
	 * （安藤レビュー指摘: 決定的な予定IDでの作成）を検証する（issue #476。親 issue #94 で決定）。
	 */
	public function test_create_event(): void {
		$this->connect();

		$this->mock_responses = array(
			'calendars/calendar-1/events' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"new-event-id"}',
			),
		);

		$result = $this->client->create_event( 'calendar-1', 'new-event-id', array( 'summary' => 'カット' ) );

		$this->assertSame( 'new-event-id', $result, '成功時はイベント ID を返すこと' );
		$this->assertStringContainsString( 'sendUpdates=none', $this->last_request_url, '招待メールを送らない固定にすること' );
		$this->assertSame( 'POST', $this->last_request_args['method'], '新規作成は POST で送ること' );

		$sent_body = json_decode( (string) $this->last_request_args['body'], true );
		$this->assertSame( 'new-event-id', $sent_body['id'], '指定した予定IDを本文の id に含めて送ること' );
	}

	/**
	 * create_event() が、既に同じ予定IDが存在する場合（409）に専用のエラー種別を返すこと
	 * を検証する（安藤レビュー指摘: 呼び出し側が update へ切り替えるための切り分け）。
	 */
	public function test_create_event_already_exists(): void {
		$this->connect();

		$this->mock_responses = array(
			'calendars/calendar-1/events' => array(
				'response' => array( 'code' => 409 ),
				'body'     => '{"error":{"code":409,"message":"The requested identifier already exists."}}',
			),
		);

		$result = $this->client->create_event( 'calendar-1', 'existing-event-id', array( 'summary' => 'カット' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'vkbm_google_calendar_event_already_exists', $result->get_error_code() );
		$this->assertSame( 'connected', $this->connection->get_status(), '既に存在するだけでは連携を切れた扱いにしないこと' );
	}

	/**
	 * 403 は認可が失われたとは限らない（権限不足・レート制限等）ため、401 と違って
	 * connection を error 状態にしないことを検証する（安藤レビュー指摘）。
	 */
	public function test_create_event_forbidden_does_not_mark_connection_error(): void {
		$this->connect();

		$this->mock_responses = array(
			'calendars/calendar-1/events' => array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"error":{"code":403,"message":"Rate Limit Exceeded"}}',
			),
		);

		$result = $this->client->create_event( 'calendar-1', 'new-event-id', array( 'summary' => 'カット' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'vkbm_google_calendar_forbidden', $result->get_error_code() );
		$this->assertSame( 'connected', $this->connection->get_status(), '403 だけでは連携が切れた扱いにしないこと（安藤レビュー指摘）' );
	}

	/**
	 * update_event() が、成功時にイベント ID を返すこと、PUT で送ることを検証する。
	 */
	public function test_update_event(): void {
		$this->connect();

		$this->mock_responses = array(
			'events/existing-event-id' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":"existing-event-id"}',
			),
		);

		$result = $this->client->update_event( 'calendar-1', 'existing-event-id', array( 'summary' => 'カット（変更後）' ) );

		$this->assertSame( 'existing-event-id', $result, '成功時はイベント ID を返すこと' );
		$this->assertSame( 'PUT', $this->last_request_args['method'], '更新は PUT で送ること' );
	}

	/**
	 * update_event() が、Google 側で既に削除された予定に対して専用のエラー種別を返すこと
	 * （呼び出し側が新規作成へ倒すための切り分け。issue #476）。
	 */
	public function test_update_event_not_found(): void {
		$this->connect();

		$this->mock_responses = array(
			'events/deleted-event-id' => array(
				'response' => array( 'code' => 404 ),
				'body'     => '{"error":{"code":404,"message":"Not Found"}}',
			),
		);

		$result = $this->client->update_event( 'calendar-1', 'deleted-event-id', array( 'summary' => 'カット' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'vkbm_google_calendar_event_not_found', $result->get_error_code() );
		$this->assertSame( 'connected', $this->connection->get_status(), '予定が無いだけでは連携を切れた扱いにしないこと' );
	}

	/**
	 * delete_event() の正常系・異常系をまとめて検証する。
	 */
	public function test_delete_event(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '削除に成功（204） => true（正常系）',
				'response'            => array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'Google 側で既に無い（404） => 目的は達成済みとして true 扱い（正常系。安藤レビュー指摘の先取り対応）',
				'response'            => array(
					'response' => array( 'code' => 404 ),
					'body'     => '{"error":{"code":404,"message":"Not Found"}}',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'アクセス許可が失効（401） => WP_Error（異常系。連携が切れています扱い）',
				'response'            => array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":{"code":401,"message":"Invalid Credentials"}}',
				),
				'expected'            => 'vkbm_google_calendar_unauthorized',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );
			delete_transient( 'vkbm_google_calendar_refresh_throttle' );
			$this->connect();

			$this->mock_responses = array( 'events/event-id' => $case['response'] );

			$result = $this->client->delete_event( 'calendar-1', 'event-id' );

			if ( true === $case['expected'] ) {
				$this->assertTrue( $result, $case['test_condition_name'] );
				$this->assertSame( 'DELETE', $this->last_request_args['method'], $case['test_condition_name'] );
				continue;
			}

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result->get_error_code(), $case['test_condition_name'] );
		}
	}
}
