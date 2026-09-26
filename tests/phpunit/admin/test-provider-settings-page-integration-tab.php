<?php
/**
 * BM 設定の「連携」タブ（Google カレンダー連携）の描画テスト。
 *
 * issue #475。とくに「連携のボタンが、他タブと共有している保存フォームの中に入っていないこと」を
 * 確かめる。共有フォームの中に入っていると、連携のボタンを押した時点で別タブの入力内容まで
 * 保存されてしまうため（issue #475 の「実装上の注意」）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Api_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Settings_Panel;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;
use function add_filter;
use function delete_option;
use function delete_transient;
use function remove_filter;
use function ob_get_clean;
use function ob_start;
use function wp_set_current_user;

/**
 * 「連携」タブの描画テスト。
 */
class Test_Provider_Settings_Page_Integration_Tab extends WP_UnitTestCase {

	/**
	 * 接続状態。
	 *
	 * @var Google_Calendar_Connection
	 */
	private $connection;

	/**
	 * Google への通信を差し替えるフィルターの参照（後片付け用）。
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
	 * 各テストの前に、管理者としてログインする。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_transient( 'vkbm_google_calendar_list' );

		$this->connection = new Google_Calendar_Connection();

		// 画面の描画中に Google のカレンダー一覧を取りに行くため、応答を差し替えて結果を固定する。
		$this->http_filter = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"items":[{"id":"owner@example.com","summary":"本人","accessRole":"owner","primary":true}]}',
			);
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
	 * 各テストの後に、保存内容と画面の指定を元へ戻す。
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

		delete_option( Google_Calendar_Connection::OPTION_NAME );
		delete_transient( 'vkbm_google_calendar_list' );
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * 「連携」タブを含む設定画面を描画し、出力を返す。
	 *
	 * @param string $tab 表示するタブ。
	 * @return string 描画結果。
	 */
	private function render_settings_page( string $tab ): string {
		$relay_client = new Google_Calendar_Relay_Client();
		$api_client   = new Google_Calendar_Api_Client( $this->connection, $relay_client );
		$controller   = new Google_Calendar_Connect_Controller( $this->connection, $relay_client, $api_client );
		$panel        = new Google_Calendar_Settings_Panel( $this->connection, $api_client, $controller, new Google_Calendar_Event_Sync_Settings() );

		$page = new Provider_Settings_Page(
			new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ),
			'manage_options',
			'',
			null,
			$panel
		);

		$_GET['tab'] = $tab;

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * 「連携」タブが、状態ごとに期待どおりの内容で描画されること。
	 *
	 * @return void
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '未接続 => 連携を始めるボタンが出る',
				'connected'           => false,
				'expected'            => Google_Calendar_Connect_Controller::ACTION_CONNECT,
			),
			array(
				'test_condition_name' => '接続済み => 連携を解除する導線が出る',
				'connected'           => true,
				'expected'            => 'vkbm_gcal_view=disconnect-confirm',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Connection::OPTION_NAME );

			if ( $case['connected'] ) {
				$this->connection->save_tokens(
					array(
						'access_token'  => 'at',
						'refresh_token' => 'rt',
						'expires_in'    => 3600,
						'email'         => 'owner@example.com',
					)
				);
			}

			$output = $this->render_settings_page( 'integration' );

			$this->assertStringContainsString( 'vkbm-google-calendar', $output, $case['test_condition_name'] . '（連携タブの中身が出ること）' );
			$this->assertStringContainsString( $case['expected'], $output, $case['test_condition_name'] );

			if ( $case['connected'] ) {
				$this->assertStringContainsString( 'owner@example.com', $output, $case['test_condition_name'] . '（連携中のアカウントと反映先の選択肢が出ること）' );
			}
			$this->assertStringNotContainsString( 'Fatal error', $output, $case['test_condition_name'] . '（致命的エラーが出ていないこと）' );
		}
	}

	/**
	 * 連携の操作が、他タブと共有している保存フォームの中に入っていないこと。
	 *
	 * 共有フォームの中に入っていると、連携のボタンを押した時点で別タブの入力内容まで
	 * 保存されてしまう（issue #475 の「実装上の注意」）。
	 *
	 * @return void
	 */
	public function test_render_page_keeps_connect_button_outside_the_shared_form(): void {
		$output = $this->render_settings_page( 'integration' );

		$this->assertStringNotContainsString(
			'<form method="post" action="">',
			$output,
			'「連携」タブでは、他タブと共有している保存フォームを描画しないこと'
		);
		$this->assertStringNotContainsString(
			'vkbm_provider_settings[',
			$output,
			'「連携」タブでは、他タブの設定項目を送信対象に含めないこと'
		);
		$this->assertStringContainsString(
			'admin-post.php',
			$output,
			'連携の操作は admin-post.php へ送ること'
		);
	}

	/**
	 * 中継サーバーの接続先が決まるまでは、「連携」タブがどこにも出ないこと。
	 *
	 * 仮の URL を入れて「リリース前に差し替える」運用にすると、差し替え忘れに気づけないまま、
	 * 押してもつながらない連携ボタンがオーナーに見えてしまう。加えて Google の許可画面の審査が
	 * 終わるまでは、テストユーザー以外が連携しようとすると Google の警告画面で止まる。
	 * そのため、接続先が決まるまでは機能ごと画面に出さない（issue #475）。
	 *
	 * @return void
	 */
	public function test_render_page_hides_integration_tab_until_the_relay_server_is_ready(): void {
		// 仮の接続先を外し、中継サーバーが未構築の状態（既定）へ戻す。
		remove_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
		$this->relay_url_filter = null;

		$output = $this->render_settings_page( 'integration' );

		$this->assertStringNotContainsString( 'vkbm-google-calendar', $output, '連携タブの中身が出ないこと' );
		$this->assertStringNotContainsString( 'tab=integration', $output, 'タブの並びにも「連携」が出ないこと' );
		$this->assertStringContainsString( '<form method="post" action="">', $output, '既定のタブが従来どおり表示されること' );
	}

	/**
	 * 「連携」以外のタブでは、連携の操作が出てこないこと。
	 *
	 * @return void
	 */
	public function test_render_page_hides_integration_panel_on_other_tabs(): void {
		$output = $this->render_settings_page( 'advanced' );

		$this->assertStringNotContainsString( 'vkbm-google-calendar', $output, '他タブでは連携タブの中身を描画しないこと' );
		$this->assertStringContainsString( '<form method="post" action="">', $output, '他タブでは従来どおり共有の保存フォームを描画すること' );
		$this->assertStringContainsString( 'tab=integration', $output, '他タブからも「連携」タブへ移動できること' );
	}
}
