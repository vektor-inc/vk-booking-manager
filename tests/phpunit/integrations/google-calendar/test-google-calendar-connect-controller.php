<?php
/**
 * Google カレンダー連携の「つなぐ・選ぶ・外す」操作を受け取るクラスのテスト。
 *
 * issue #475。実際の送り出し（リダイレクト）は処理を終えてしまうため、
 * テスト用に差し替えた子クラス（class-spy-connect-controller.php）で送り先だけを記録して確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Api_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;
use WP_Error;
use WP_UnitTestCase;
use WPDieException;
use function add_filter;
use function delete_option;
use function delete_transient;
use function has_action;
use function remove_filter;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * Google_Calendar_Connect_Controller のテスト。
 */
class Test_Google_Calendar_Connect_Controller extends WP_UnitTestCase {

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * テスト対象。
	 *
	 * @var Spy_Connect_Controller
	 */
	private $controller;

	/**
	 * 送信先 URL ごとの応答を並べた配列。
	 *
	 * @var array<string, mixed>
	 */
	private $mock_responses = array();

	/**
	 * 差し替えた HTTP 通信が最後に受け取ったリクエストを覚えておく変数。
	 *
	 * @var array<string, mixed>
	 */
	private $captured_request = array();

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
	 * 各テストの前に、管理者としてログインし、HTTP 通信を差し替える。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Google_Calendar_Secret_Store::is_available() ) {
			$this->markTestSkipped( 'この環境では openssl の AES-256-GCM が使えないためスキップする。' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( Google_Calendar_Connection::OPTION_NAME );

		// ライセンスキーが無いと handle_connect() が連携の開始を止めるため、既定では設定しておく
		// （未設定時の挙動は test_handle_connect_without_license_key で個別に確かめる）。
		update_option( 'vk-booking-manager-pro-license-key', 'license-key-value' );

		$this->connection = new Google_Calendar_Connection();
		$relay_client     = new Google_Calendar_Relay_Client();
		$this->controller = new Spy_Connect_Controller(
			$this->connection,
			$relay_client,
			new Google_Calendar_Api_Client( $this->connection, $relay_client ),
			'manage_options'
		);

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

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

		$this->mock_responses   = array();
		$this->captured_request = array();
		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_option( 'vk-booking-manager-pro-license-key' );
		delete_transient( 'vkbm_gcal_pending_' . get_current_user_id() );
		delete_transient( 'vkbm_gcal_notice_' . get_current_user_id() );
		$_POST = array();
		$_GET  = array();

		parent::tear_down();
	}

	/**
	 * この機能が Pro 版限定であること。
	 *
	 * @return void
	 */
	public function test_is_integration_enabled(): void {
		$this->assertSame(
			! Pro_Upsell::is_free_edition(),
			Google_Calendar_Connect_Controller::is_integration_enabled(),
			'Pro 版でのみ有効になること'
		);
	}

	/**
	 * 連携の操作を受け取るフックが登録されること（Pro 版のみ）。
	 *
	 * @return void
	 */
	public function test_register(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->controller->register();

		$actions = array(
			Google_Calendar_Connect_Controller::ACTION_CONNECT,
			Google_Calendar_Connect_Controller::ACTION_CALLBACK,
			Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR,
			Google_Calendar_Connect_Controller::ACTION_DISCONNECT,
		);

		foreach ( $actions as $action ) {
			$this->assertNotFalse( has_action( 'admin_post_' . $action ), $action . ' の受け口が登録されること' );
		}
	}

	/**
	 * 中継サーバーの接続先が決まるまでは、連携の受け口そのものを作らないこと。
	 *
	 * 受け口が無ければ、URL を直接叩かれても連携は始まらない（issue #475）。
	 *
	 * @return void
	 */
	public function test_register_skips_until_the_relay_server_is_ready(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// 仮の接続先を外し、中継サーバーが未構築の状態（既定）へ戻す。
		remove_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
		$this->relay_url_filter = null;

		$this->assertFalse( $this->controller->is_available(), '接続先が決まるまでは使えない状態であること' );

		$this->controller->register();

		$actions = array(
			Google_Calendar_Connect_Controller::ACTION_CONNECT,
			Google_Calendar_Connect_Controller::ACTION_CALLBACK,
			Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR,
			Google_Calendar_Connect_Controller::ACTION_DISCONNECT,
		);

		foreach ( $actions as $action ) {
			$this->assertFalse( has_action( 'admin_post_' . $action ), $action . ' の受け口が作られないこと' );
		}
	}

	/**
	 * 連携の開始で、中継サーバーへ送り出され、照合用の値が一時保存されること。
	 *
	 * @return void
	 */
	public function test_handle_connect(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// ブラウザを送り出す前に、サーバー間通信でチケットを発行してもらう
		// （ライセンスキーをブラウザ遷移先の URL に載せないための構成）。
		$this->mock_responses['/auth/session'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ticket":"ticket-value"}',
		);
		$_REQUEST['_wpnonce']                  = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

		try {
			$this->controller->handle_connect();
			$this->fail( '中継サーバーへ送り出されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertStringContainsString( '/auth/start', $this->controller->redirected_to, '中継サーバーの入り口へ送り出すこと' );
			$this->assertStringContainsString( 'ticket=ticket-value', $this->controller->redirected_to, 'サーバー間通信で発行されたチケットを渡すこと' );
			$this->assertStringNotContainsString( 'license_key', $this->controller->redirected_to, 'ライセンスキーがブラウザの遷移先 URL に含まれないこと' );
		}

		$this->assertTrue( $this->controller->is_pending(), '戻ってくるまでの間は「接続中」として扱えること' );
	}

	/**
	 * チケットの発行（`POST /auth/session`）に失敗したとき、Google の画面へは送り出さず、
	 * 設定画面へエラーとともに戻すこと。
	 *
	 * @return void
	 */
	public function test_handle_connect_when_session_creation_fails(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->mock_responses['/auth/session'] = new WP_Error( 'http_request_failed', 'timeout' );
		$_REQUEST['_wpnonce']                  = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

		try {
			$this->controller->handle_connect();
			$this->fail( '設定画面へ戻されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $this->controller->redirected_to, 'チケットが発行できない場合は中継サーバーへ送り出さず、設定画面へ戻すこと' );
		}

		$this->assertFalse( $this->controller->is_pending(), '送り出していないため「接続中」にはならないこと' );
	}

	/**
	 * 権限の無い利用者が連携を開始できないこと。
	 *
	 * @return void
	 */
	public function test_handle_connect_without_permission(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

		$this->expectException( WPDieException::class );

		$this->controller->handle_connect();
	}

	/**
	 * Google からの戻りが、条件ごとに期待どおりに扱われること。
	 *
	 * @return void
	 */
	public function test_handle_callback(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '照合用の値が一致し、引き換えにも成功する => 接続済みになる',
				'pending'             => array(
					'state'         => 'state-value',
					'code_verifier' => 'verifier-value',
				),
				'query'               => array(
					'state' => 'state-value',
					'code'  => 'auth-code',
				),
				'token_response'      => array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"access_token":"at","refresh_token":"rt","expires_in":3600,"email":"owner@example.com"}',
				),
				'expected'            => 'connected',
			),
			array(
				'test_condition_name' => 'オーナーが Google の画面で許可しなかった => 未接続のまま',
				'pending'             => array(
					'state'         => 'state-value',
					'code_verifier' => 'verifier-value',
				),
				'query'               => array(
					'error' => 'access_denied',
				),
				'token_response'      => null,
				'expected'            => 'disconnected',
			),
			array(
				'test_condition_name' => '照合用の値が一致しない => 未接続のまま',
				'pending'             => array(
					'state'         => 'state-value',
					'code_verifier' => 'verifier-value',
				),
				'query'               => array(
					'state' => 'another-state',
					'code'  => 'auth-code',
				),
				'token_response'      => null,
				'expected'            => 'disconnected',
			),
			array(
				'test_condition_name' => '接続の途中経過が時間切れで消えている => 未接続のまま',
				'pending'             => null,
				'query'               => array(
					'state' => 'state-value',
					'code'  => 'auth-code',
				),
				'token_response'      => null,
				'expected'            => 'disconnected',
			),
			array(
				'test_condition_name' => '引き換えに失敗した => 未接続のまま',
				'pending'             => array(
					'state'         => 'state-value',
					'code_verifier' => 'verifier-value',
				),
				'query'               => array(
					'state' => 'state-value',
					'code'  => 'auth-code',
				),
				'token_response'      => array(
					'response' => array( 'code' => 400 ),
					'body'     => '{"error":"invalid_grant"}',
				),
				'expected'            => 'disconnected',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );
			delete_transient( 'vkbm_gcal_pending_' . get_current_user_id() );
			$_GET = array();

			if ( null !== $case['pending'] ) {
				set_transient( 'vkbm_gcal_pending_' . get_current_user_id(), $case['pending'], 900 );
			}

			foreach ( $case['query'] as $key => $value ) {
				$_GET[ $key ] = $value;
			}

			$this->mock_responses = array();
			if ( null !== $case['token_response'] ) {
				$this->mock_responses['/auth/token'] = $case['token_response'];
			}
			// 接続に成功した直後は、既定のカレンダーを選ぶために一覧を取りに行く。
			$this->mock_responses['calendarList'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"items":[{"id":"owner@example.com","summary":"本人","accessRole":"owner","primary":true}]}',
			);

			try {
				$this->controller->handle_callback();
				$this->fail( $case['test_condition_name'] . '（設定画面へ戻されること）' );
			} catch ( Redirect_Exception $exception ) {
				$this->assertSame( 'settings', $this->controller->redirected_to, $case['test_condition_name'] . '（設定画面へ戻されること）' );
			}

			$this->assertSame( $case['expected'], $this->connection->get_status(), $case['test_condition_name'] );

			// 接続できた場合は、反映先として本人の既定のカレンダーが初期値に入ること
			// （接続直後に「どれも選ばれていない」状態で放置されないようにするため）。
			$expected_calendar_id = 'connected' === $case['expected'] ? 'owner@example.com' : '';
			$this->assertSame( $expected_calendar_id, $this->connection->get_calendar_id(), $case['test_condition_name'] . '（反映先の初期値）' );
		}
	}

	/**
	 * 別の Google アカウントで繋ぎ直したとき、前のアカウントの反映先カレンダーが引き継がれず、
	 * 新しいアカウントの既定のカレンダーが選び直されること（安藤レビュー指摘・issue #476 の土台）。
	 *
	 * @return void
	 */
	public function test_handle_callback_when_reconnecting_with_different_account(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// アカウントAに接続済みで、反映先カレンダーも選ばれている状態を作る。
		$this->connection->save_tokens(
			array(
				'access_token'  => 'at-a',
				'refresh_token' => 'rt-a',
				'expires_in'    => 3600,
				'email'         => 'account-a@example.com',
			)
		);
		$this->connection->set_calendar( 'calendar-a@example.com', 'アカウントAの予定' );

		set_transient(
			'vkbm_gcal_pending_' . get_current_user_id(),
			array(
				'state'         => 'state-value',
				'code_verifier' => 'verifier-value',
			),
			900
		);
		$_GET['state'] = 'state-value';
		$_GET['code']  = 'auth-code';

		$this->mock_responses['/auth/token'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"at-b","refresh_token":"rt-b","expires_in":3600,"email":"account-b@example.com"}',
		);
		// アカウントBのカレンダー一覧。アカウントAのカレンダー ID は含まれない。
		$this->mock_responses['calendarList'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"items":[{"id":"calendar-b@example.com","summary":"アカウントBの予定","accessRole":"owner","primary":true}]}',
		);

		try {
			$this->controller->handle_callback();
			$this->fail( '設定画面へ戻されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $this->controller->redirected_to, '設定画面へ戻されること' );
		}

		$this->assertSame( 'connected', $this->connection->get_status(), '接続済みになること' );
		$this->assertSame( 'account-b@example.com', $this->connection->get_account_email(), '新しいアカウントに更新されること' );
		$this->assertSame( 'calendar-b@example.com', $this->connection->get_calendar_id(), '前のアカウントのカレンダーIDが残らず、新しいアカウントの既定のカレンダーが選ばれること' );
	}

	/**
	 * ライセンスキーが未設定のときは、Google の画面へ送り出す前に連携の開始を止めること。
	 *
	 * ライセンスキーが無いまま送り出しても、引き換え（handle_callback()）の依頼は中継サーバーに
	 * 拒否される（Pro 版のライセンスキーを持つサイトからの依頼だけを受け付ける）ため、
	 * Google の同意画面まで進めたのに引き換えだけ失敗する分かりにくい失敗を避ける。
	 *
	 * @return void
	 */
	public function test_handle_connect_without_license_key(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		delete_option( 'vk-booking-manager-pro-license-key' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

		try {
			$this->controller->handle_connect();
			$this->fail( '設定画面へ戻されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $this->controller->redirected_to, 'ライセンスキーが無い場合は中継サーバーへ送り出さず、設定画面へ戻すこと' );
		}

		$this->assertFalse( $this->controller->is_pending(), '送り出していないため「接続中」にはならないこと' );
	}

	/**
	 * ライセンスキーが未設定のときの案内を、ライセンスタブを開ける利用者かどうかで分けること。
	 *
	 * ライセンスキーを入力する「ライセンス」タブは `manage_options` を持つ利用者にしか出ない。
	 * BM 設定に入れる「店舗管理者」は `manage_options` を剥がされているため、全員に
	 * 「ライセンスタブで入力してください」と案内すると、後者は案内のとおりに動けず行き止まりになる
	 * （安藤レビュー指摘）。
	 *
	 * @return void
	 */
	public function test_handle_connect_without_license_key_guides_by_capability(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'ライセンスタブを開ける利用者 => タブでの入力を案内する',
				'role'                => 'administrator',
				'capability'          => 'manage_options',
				'expected'            => __( 'Please enter your license key on the License tab before connecting to Google Calendar.', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => 'ライセンスタブを開けない利用者 => サイト管理者への確認を案内する',
				'role'                => 'editor',
				'capability'          => 'edit_posts',
				'expected'            => __( 'The license key has not been registered.', 'vk-booking-manager' ) . ' ' . __( 'Please ask your site administrator.', 'vk-booking-manager' ),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( 'vk-booking-manager-pro-license-key' );
			delete_transient( 'vkbm_gcal_notice_' . get_current_user_id() );

			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['role'] ) ) );

			$relay_client = new Google_Calendar_Relay_Client();
			$controller   = new Spy_Connect_Controller(
				$this->connection,
				$relay_client,
				new Google_Calendar_Api_Client( $this->connection, $relay_client ),
				$case['capability']
			);

			$_REQUEST['_wpnonce'] = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

			try {
				$controller->handle_connect();
				$this->fail( $case['test_condition_name'] . '（設定画面へ戻されること）' );
			} catch ( Redirect_Exception $exception ) {
				$this->assertSame( 'settings', $controller->redirected_to, $case['test_condition_name'] . '（設定画面へ戻されること）' );
			}

			$notice = Google_Calendar_Connect_Controller::pull_notice();

			$this->assertIsArray( $notice, $case['test_condition_name'] . '（お知らせが出ること）' );
			$this->assertSame( $case['expected'], $notice['message'], $case['test_condition_name'] );
		}
	}

	/**
	 * ライセンスキーが受け付けられなかったときに、原因の分かる案内を出すこと。
	 *
	 * 中継サーバーが `license` を含む種別でエラーを返した場合、「中継サーバーがエラーを
	 * 返しました。」では何を直せばよいか分からないため、ライセンス向けの案内に差し替える
	 * （安藤レビュー指摘）。ライセンスキーが未設定のときとは別の経路（`start_session()` の失敗）。
	 *
	 * @return void
	 */
	public function test_handle_connect_when_the_license_key_is_rejected(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'ライセンスキーが無効（中継サーバーが invalid_license を返す） => ライセンス向けの案内',
				'error_code'          => 'invalid_license',
				'expected'            => __( 'The license key was not accepted.', 'vk-booking-manager' ) . ' ' . __( 'Please check the license key on the License tab.', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => 'ライセンスキーの期限切れ => ライセンス向けの案内',
				'error_code'          => 'license_expired',
				'expected'            => __( 'The license key was not accepted.', 'vk-booking-manager' ) . ' ' . __( 'Please check the license key on the License tab.', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => 'ライセンス以外の理由で中継サーバーが失敗 => 従来どおりその失敗の内容',
				'error_code'          => 'internal_error',
				'expected'            => __( 'The relay server returned an error.', 'vk-booking-manager' ),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_transient( 'vkbm_gcal_notice_' . get_current_user_id() );

			$this->mock_responses['/auth/session'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode( array( 'error' => $case['error_code'] ) ),
			);
			$_REQUEST['_wpnonce']                  = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

			try {
				$this->controller->handle_connect();
				$this->fail( $case['test_condition_name'] . '（設定画面へ戻されること）' );
			} catch ( Redirect_Exception $exception ) {
				$this->assertSame( 'settings', $this->controller->redirected_to, $case['test_condition_name'] . '（設定画面へ戻されること）' );
			}

			$notice = Google_Calendar_Connect_Controller::pull_notice();

			$this->assertIsArray( $notice, $case['test_condition_name'] . '（お知らせが出ること）' );
			$this->assertSame( $case['expected'], $notice['message'], $case['test_condition_name'] );
			$this->assertFalse( $this->controller->is_pending(), $case['test_condition_name'] . '（送り出していないため「接続中」にはならないこと）' );
		}
	}

	/**
	 * ライセンスキーが設定されていれば、通常どおり中継サーバーへ送り出されること。
	 *
	 * ライセンスキーは `POST /auth/session`（サーバー間通信）の本文にだけ添えられ、
	 * ブラウザが遷移する `GET /auth/start` の URL には含まれないこと（安藤レビュー指摘）。
	 *
	 * @return void
	 */
	public function test_handle_connect_with_license_key(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// set_up() で既定のライセンスキーを設定済み。
		$this->mock_responses['/auth/session'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ticket":"ticket-value"}',
		);
		$_REQUEST['_wpnonce']                  = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_CONNECT );

		try {
			$this->controller->handle_connect();
			$this->fail( '中継サーバーへ送り出されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertStringNotContainsString( 'license_key', $this->controller->redirected_to, 'ブラウザの遷移先 URL にライセンスキーが含まれないこと' );
		}

		$sent_body = json_decode( (string) $this->captured_request['args']['body'], true );
		$this->assertSame( 'license-key-value', $sent_body['license_key'], 'POST /auth/session の本文にライセンスキーが添えられること' );
	}

	/**
	 * 連携の解除で、保存している情報が消えること。
	 *
	 * @return void
	 */
	public function test_handle_disconnect(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->connection->save_tokens(
			array(
				'access_token'  => 'at',
				'refresh_token' => 'rt',
				'expires_in'    => 3600,
			)
		);

		$this->mock_responses['/auth/revoke'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_DISCONNECT );

		try {
			$this->controller->handle_disconnect();
			$this->fail( '設定画面へ戻されること' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $this->controller->redirected_to, '設定画面へ戻されること' );
		}

		$this->assertSame( 'disconnected', $this->connection->get_status(), '解除すると未接続に戻ること' );
	}

	/**
	 * Google 側の取り消しに失敗しても、このサイトからは解除できること。
	 *
	 * 取り消せないことを理由に情報を残すと、解除したつもりの状態が残ってしまうため。
	 *
	 * @return void
	 */
	public function test_handle_disconnect_when_revoke_fails(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->connection->save_tokens(
			array(
				'access_token'  => 'at',
				'refresh_token' => 'rt',
				'expires_in'    => 3600,
			)
		);

		$this->mock_responses['/auth/revoke'] = new WP_Error( 'http_request_failed', 'timeout' );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_DISCONNECT );

		try {
			$this->controller->handle_disconnect();
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $this->controller->redirected_to, '設定画面へ戻されること' );
		}

		$this->assertSame( 'disconnected', $this->connection->get_status(), '取り消しに失敗しても解除できること' );
	}

	/**
	 * 反映先カレンダーの選択が、条件ごとに期待どおりに扱われること。
	 *
	 * @return void
	 */
	public function test_handle_select_calendar(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'Google 側にあるカレンダーを選んだ => 保存される',
				'calendar_id'         => 'owner@example.com',
				'expected'            => 'owner@example.com',
			),
			array(
				'test_condition_name' => 'Google 側に無いカレンダーが送られてきた => 保存しない',
				'calendar_id'         => 'not-exists@example.com',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '何も選ばれていない => 保存しない',
				'calendar_id'         => '',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );
			$this->connection->save_tokens(
				array(
					'access_token'  => 'at',
					'refresh_token' => 'rt',
					'expires_in'    => 3600,
				)
			);

			$this->mock_responses['calendarList'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"items":[{"id":"owner@example.com","summary":"本人","accessRole":"owner","primary":true}]}',
			);

			$_POST['vkbm_google_calendar_id'] = $case['calendar_id'];
			$_REQUEST['_wpnonce']             = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR );

			try {
				$this->controller->handle_select_calendar();
				$this->fail( $case['test_condition_name'] . '（設定画面へ戻されること）' );
			} catch ( Redirect_Exception $exception ) {
				$this->assertSame( 'settings', $this->controller->redirected_to, $case['test_condition_name'] . '（設定画面へ戻されること）' );
			}

			$this->assertSame( $case['expected'], $this->connection->get_calendar_id(), $case['test_condition_name'] );
		}
	}

	/**
	 * 「予定に載せる情報」の項目設定が、カレンダー一覧の取得・照合の成否に関わらず
	 * 保存されることを検証する（安藤レビュー指摘: 以前は照合失敗時に save_sync_fields() を
	 * 呼ばずに戻っていたため保存されなかった）。
	 *
	 * @return void
	 */
	public function test_handle_select_calendar_saves_sync_fields_even_when_calendar_verification_fails(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );

		$relay_client  = new Google_Calendar_Relay_Client();
		$sync_settings = new Google_Calendar_Event_Sync_Settings();
		$controller    = new Spy_Connect_Controller(
			$this->connection,
			$relay_client,
			new Google_Calendar_Api_Client( $this->connection, $relay_client ),
			'manage_options',
			$sync_settings
		);

		$this->connection->save_tokens(
			array(
				'access_token'  => 'at',
				'refresh_token' => 'rt',
				'expires_in'    => 3600,
			)
		);

		// カレンダー一覧の取得そのものを失敗させる（照合失敗の一種）。
		$this->mock_responses['calendarList'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":{"message":"server error"}}',
		);

		$_POST['vkbm_google_calendar_id']          = 'owner@example.com';
		$_POST['vkbm_google_calendar_sync_fields'] = array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_TEL );
		$_REQUEST['_wpnonce']                      = wp_create_nonce( Google_Calendar_Connect_Controller::ACTION_SELECT_CALENDAR );

		try {
			$controller->handle_select_calendar();
			$this->fail( 'カレンダー一覧の取得に失敗しても設定画面へ戻されるはず' );
		} catch ( Redirect_Exception $exception ) {
			$this->assertSame( 'settings', $controller->redirected_to, 'カレンダー一覧の取得に失敗しても設定画面へ戻されること' );
		}

		$this->assertSame(
			array( Google_Calendar_Event_Sync_Settings::FIELD_CUSTOMER_TEL ),
			$sync_settings->get_enabled_fields(),
			'カレンダー一覧が取得できなくても、項目設定は保存されること'
		);

		delete_option( Google_Calendar_Event_Sync_Settings::OPTION_NAME );
	}

	/**
	 * 操作結果のお知らせが、1度だけ取り出せること。
	 *
	 * @return void
	 */
	public function test_pull_notice(): void {
		$this->assertNull( Google_Calendar_Connect_Controller::pull_notice(), 'お知らせが無ければ null' );

		set_transient(
			'vkbm_gcal_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => 'Googleカレンダーと連携しました。',
			),
			60
		);

		$notice = Google_Calendar_Connect_Controller::pull_notice();

		$this->assertIsArray( $notice, '保存されたお知らせを取り出せること' );
		$this->assertSame( 'success', $notice['type'], 'お知らせの種類が取り出せること' );
		$this->assertNull( Google_Calendar_Connect_Controller::pull_notice(), '一度取り出したら消えること' );
	}

	/**
	 * Google からの戻り先が、このサイトの `admin-post.php` になること。
	 *
	 * @return void
	 */
	public function test_get_callback_url(): void {
		$url = $this->controller->get_callback_url();

		$this->assertStringContainsString( 'admin-post.php', $url, '管理画面の個別処理用の送信先であること' );
		$this->assertStringContainsString( Google_Calendar_Connect_Controller::ACTION_CALLBACK, $url, '戻り先の処理が指定されていること' );
	}
}
