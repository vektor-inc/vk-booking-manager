<?php
/**
 * VKBM_GitHub_Updater::set_plugin_info() のテスト。
 *
 * @package VKBookingManager
 */

/**
 * plugins_api フィルターで、他のプラグインの情報取得時に GitHub へ問い合わせないこと、
 * 自プラグインのときは transient を使って問い合わせ回数を抑えることを検証する。
 */
class Test_VKBM_GitHub_Updater_Set_Plugin_Info extends WP_UnitTestCase {

	/**
	 * GitHub API へのリクエスト回数。
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * スタブが返すダウンロード URL。
	 *
	 * @var string
	 */
	private $stub_url = 'https://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip';

	/**
	 * スタブが成功レスポンスを返すか（false なら WP_Error）。
	 *
	 * @var bool
	 */
	private $stub_success = true;

	/**
	 * セットアップ処理。
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/class-vkbm-github-updater.php';

		$this->request_count = 0;
		$this->stub_url      = 'https://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip';
		$this->stub_success  = true;

		delete_site_transient( 'vkbm_github_updater_release' );
		add_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10, 3 );
	}

	/**
	 * 後処理。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10 );
		delete_site_transient( 'vkbm_github_updater_release' );

		parent::tear_down();
	}

	/**
	 * GitHub API へのリクエストを数えてスタブに差し替える。
	 *
	 * @param false|array|WP_Error $preempt 既定のプリエンプト値。
	 * @param array                $args    リクエスト引数。
	 * @param string               $url     リクエスト URL。
	 * @return false|array|WP_Error スタブレスポンス、または既定動作（false）。
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.github.com/repos/vektor-inc/vk-booking-manager/releases' ) ) {
			return $preempt;
		}

		++$this->request_count;

		if ( ! $this->stub_success ) {
			return new WP_Error( 'http_request_failed', 'stubbed failure' );
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					array(
						'tag_name' => '99.0.0',
						'assets'   => array(
							array(
								'name'                 => 'vk-booking-manager.zip',
								'browser_download_url' => $this->stub_url,
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 本プラグインのファイルパスを返す。
	 *
	 * @return string プラグインのメインファイルの絶対パス。
	 */
	private function get_plugin_file(): string {
		return dirname( __DIR__, 3 ) . '/vk-booking-manager.php';
	}

	/**
	 * 他のプラグインの slug、または plugin_information 以外の action では GitHub へ問い合わせない。
	 */
	public function test_no_request_when_not_target() {
		$plugin_file = $this->get_plugin_file();
		$updater     = new VKBM_GitHub_Updater( $plugin_file );

		// 他のプラグインの slug。
		$other        = new stdClass();
		$other->slug  = 'other-plugin/other-plugin.php';
		$result_other = $updater->set_plugin_info( false, 'plugin_information', $other );
		$this->assertFalse( $result_other, '他プラグインの slug では $false をそのまま返す' );
		$this->assertSame( 0, $this->request_count, '他プラグインの slug では GitHub に問い合わせない' );

		// response がオブジェクトでない場合。
		$result_not_object = $updater->set_plugin_info( false, 'plugin_information', false );
		$this->assertFalse( $result_not_object, 'response がオブジェクトでなければ $false を返す' );
		$this->assertSame( 0, $this->request_count, 'response がオブジェクトでなければ問い合わせない' );

		// action が plugin_information 以外（自プラグインの slug でも問い合わせない）。
		$own       = new stdClass();
		$own->slug = plugin_basename( $plugin_file );
		$result    = $updater->set_plugin_info( false, 'query_plugins', $own );
		$this->assertFalse( $result, 'action が plugin_information 以外では $false を返す' );
		$this->assertSame( 0, $this->request_count, 'action が plugin_information 以外では問い合わせない' );
		$this->assertFalse( get_site_transient( 'vkbm_github_updater_release' ), '問い合わせていないので transient も保存されない' );
	}

	/**
	 * 自プラグインの slug では、1 回目は問い合わせて transient に保存し、2 回目は問い合わせない。
	 */
	public function test_request_once_and_cache_for_own_plugin() {
		$plugin_file = $this->get_plugin_file();
		$slug        = plugin_basename( $plugin_file );

		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = $slug;
		$result         = $updater->set_plugin_info( false, 'plugin_information', $response );

		$this->assertSame( 1, $this->request_count, '1 回目は GitHub に問い合わせる' );
		$this->assertSame( '99.0.0', $result->version, '取得したタグがバージョンに入る' );
		$this->assertSame( 'https://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip', $result->download_link );

		$cached = get_site_transient( 'vkbm_github_updater_release' );
		$this->assertIsObject( $cached, '取得成功時は transient に保存される' );
		$this->assertSame( '99.0.0', $cached->tag_name );

		// 別インスタンス（別リクエスト相当）では transient から読み、問い合わせない。
		$updater2        = new VKBM_GitHub_Updater( $plugin_file );
		$response2       = new stdClass();
		$response2->slug = $slug;
		$result2         = $updater2->set_plugin_info( false, 'plugin_information', $response2 );

		$this->assertSame( 1, $this->request_count, '2 回目は transient を使い問い合わせない' );
		$this->assertSame( '99.0.0', $result2->version );
	}

	/**
	 * 壊れた transient は無いものとして扱い、GitHub へ問い合わせ直す。
	 */
	public function test_invalid_cache_is_ignored() {
		set_site_transient( 'vkbm_github_updater_release', 'broken', HOUR_IN_SECONDS );

		$plugin_file    = $this->get_plugin_file();
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = plugin_basename( $plugin_file );
		$updater->set_plugin_info( false, 'plugin_information', $response );

		$this->assertSame( 1, $this->request_count, '不正な transient のときは問い合わせ直す' );
		$this->assertIsObject( get_site_transient( 'vkbm_github_updater_release' ), '取得成功で正しい値に上書きされる' );
	}

	/**
	 * 取得に失敗したときは transient に保存しない。
	 */
	public function test_failure_is_not_cached() {
		$this->stub_success = false;

		$plugin_file    = $this->get_plugin_file();
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = plugin_basename( $plugin_file );
		$result         = $updater->set_plugin_info( false, 'plugin_information', $response );

		$this->assertSame( 1, $this->request_count );
		$this->assertFalse( get_site_transient( 'vkbm_github_updater_release' ), '取得失敗時は transient に保存しない' );
		$this->assertSame( '', $result->version, '失敗時はバージョンを空のまま詳細情報を返す' );
		$this->assertFalse( isset( $result->download_link ), '失敗時はダウンロードリンクを付けない' );
	}

	/**
	 * set_transient()（定期の更新確認）は transient があっても毎回問い合わせ、transient を更新する。
	 */
	public function test_set_transient_always_requests_and_refreshes_cache() {
		$stale           = new stdClass();
		$stale->tag_name = '1.0.0';
		set_site_transient( 'vkbm_github_updater_release', $stale, HOUR_IN_SECONDS );

		$plugin_file = $this->get_plugin_file();
		$slug        = plugin_basename( $plugin_file );
		$updater     = new VKBM_GitHub_Updater( $plugin_file );

		$transient          = new stdClass();
		$transient->checked = array( $slug => '1.0.0' );
		$updater->set_transient( $transient );

		$this->assertSame( 1, $this->request_count, '定期確認は transient があっても問い合わせる' );
		$this->assertSame( '99.0.0', get_site_transient( 'vkbm_github_updater_release' )->tag_name, '取得成功で transient が更新される' );
	}

	/**
	 * transient 由来の値をメモした後に set_transient() が呼ばれたら、メモを捨てて問い合わせ直す。
	 */
	public function test_set_transient_after_set_plugin_info_requests_again() {
		$cached               = new stdClass();
		$cached->tag_name     = '98.0.0';
		$cached->published_at = '';
		$cached->assets       = array();
		set_site_transient( 'vkbm_github_updater_release', $cached, HOUR_IN_SECONDS );

		$plugin_file    = $this->get_plugin_file();
		$slug           = plugin_basename( $plugin_file );
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = $slug;
		$updater->set_plugin_info( false, 'plugin_information', $response );
		$this->assertSame( 0, $this->request_count, 'set_plugin_info() は transient を使い問い合わせない' );

		$transient          = new stdClass();
		$transient->checked = array( $slug => '1.0.0' );
		$updater->set_transient( $transient );
		$this->assertSame( 1, $this->request_count, '同じインスタンスでも set_transient() は問い合わせ直す' );
	}

	/**
	 * transient には必要な項目だけを保存し、不正な URL を含む値は無いものとして扱う。
	 */
	public function test_cache_is_compacted_and_invalid_url_is_ignored() {
		$plugin_file    = $this->get_plugin_file();
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = plugin_basename( $plugin_file );
		$updater->set_plugin_info( false, 'plugin_information', $response );

		$cached = get_site_transient( 'vkbm_github_updater_release' );
		$this->assertSame( array( 'tag_name', 'published_at', 'assets' ), array_keys( get_object_vars( $cached ) ), '必要な項目だけを保存する' );
		$this->assertCount( 1, $cached->assets );
		$this->assertSame( array( 'name', 'browser_download_url' ), array_keys( get_object_vars( $cached->assets[0] ) ) );

		// https 以外の URL を含む値は無効として問い合わせ直す。
		$cached->assets[0]->browser_download_url = 'http://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip';
		set_site_transient( 'vkbm_github_updater_release', $cached, HOUR_IN_SECONDS );
		$before   = $this->request_count;
		$updater2 = new VKBM_GitHub_Updater( $plugin_file );
		$updater2->set_plugin_info( false, 'plugin_information', clone $response );
		$this->assertSame( $before + 1, $this->request_count, 'https でない URL の transient は無視して問い合わせ直す' );
	}

	/**
	 * ホストが github.com でない URL を含む transient は無視して問い合わせ直す。
	 */
	public function test_non_github_host_is_ignored() {
		$plugin_file    = $this->get_plugin_file();
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = plugin_basename( $plugin_file );
		$updater->set_plugin_info( false, 'plugin_information', $response );

		$cached                                  = get_site_transient( 'vkbm_github_updater_release' );
		$cached->assets[0]->browser_download_url = 'https://example.com/vk-booking-manager.zip';
		set_site_transient( 'vkbm_github_updater_release', $cached, HOUR_IN_SECONDS );
		$before   = $this->request_count;
		$updater2 = new VKBM_GitHub_Updater( $plugin_file );
		$updater2->set_plugin_info( false, 'plugin_information', clone $response );
		$this->assertSame( $before + 1, $this->request_count, 'github.com 以外のホストの transient は無視して問い合わせ直す' );
	}

	/**
	 * 取得結果のダウンロード URL のホストが github.com でなければ、download_link を付けない。
	 */
	public function test_non_github_host_in_response_has_no_download_link() {
		$this->stub_url = 'https://example.com/vk-booking-manager.zip';
		$plugin_file    = $this->get_plugin_file();
		$updater        = new VKBM_GitHub_Updater( $plugin_file );
		$response       = new stdClass();
		$response->slug = plugin_basename( $plugin_file );
		$result         = $updater->set_plugin_info( false, 'plugin_information', $response );
		$this->assertTrue( empty( $result->download_link ), 'github.com 以外のホストでは package を空にする' );
	}
}
