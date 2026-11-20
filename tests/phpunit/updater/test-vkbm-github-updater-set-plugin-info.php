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
	 * タグ時点の readme.txt へのリクエスト回数。
	 *
	 * @var int
	 */
	private $readme_request_count = 0;

	/**
	 * スタブが返すタグ時点の readme.txt の本文（null なら取得失敗の WP_Error を返す）。
	 *
	 * @var string|null
	 */
	private $stub_readme_body = null;

	/**
	 * テスト前の WordPress のバージョン（グローバル変数 $wp_version）。tear_down で戻す。
	 *
	 * @var string
	 */
	private $original_wp_version = '';

	/**
	 * セットアップ処理。
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/class-vkbm-github-updater.php';

		$this->request_count        = 0;
		$this->stub_url             = 'https://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip';
		$this->stub_success         = true;
		$this->readme_request_count = 0;
		$this->stub_readme_body     = "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\nStable tag: 99.0.0\n";
		$this->original_wp_version  = $GLOBALS['wp_version'];

		delete_site_transient( 'vkbm_github_updater_release' );
		delete_site_transient( 'update_core' );
		add_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10, 3 );
	}

	/**
	 * 後処理。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10 );
		delete_site_transient( 'vkbm_github_updater_release' );
		$GLOBALS['wp_version'] = $this->original_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで書き換えた値を戻す。

		parent::tear_down();
	}

	/**
	 * GitHub API とタグ時点の readme.txt へのリクエストを数えてスタブに差し替える。
	 *
	 * @param false|array|WP_Error $preempt 既定のプリエンプト値。
	 * @param array                $args    リクエスト引数。
	 * @param string               $url     リクエスト URL。
	 * @return false|array|WP_Error スタブレスポンス、または既定動作（false）。
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
		// タグ時点の readme.txt（無料版リポジトリの raw ファイル）。
		if ( 0 === strpos( $url, 'https://raw.githubusercontent.com/vektor-inc/vk-booking-manager/' ) ) {
			++$this->readme_request_count;

			if ( null === $this->stub_readme_body ) {
				return new WP_Error( 'http_request_failed', 'stubbed failure' );
			}

			return array(
				'headers'  => array(),
				'body'     => $this->stub_readme_body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

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
	 * set_plugin_info() が、詳細ポップアップに新しいバージョンの対応バージョン
	 * （tested・requires_php・requires）を入れることを、保存済みのリリース情報の形ごとに検証する。
	 *
	 * 詳細ポップアップを開くたびに readme.txt を取りに行かないこと（保存済みの値を使うこと）と、
	 * 更新前のバージョンが保存した形の値を読んでも警告を出さないことも確かめる。
	 */
	public function test_set_plugin_info() {
		$release_values = array(
			'tested'       => '7.1',
			'requires_php' => '8.3',
			'requires'     => '6.8',
		);
		$empty_values   = array(
			'tested'       => '',
			'requires_php' => '',
			'requires'     => '',
		);

		// 更新前のバージョンが保存したリリース情報（対応バージョンの項目が無い形）。
		$legacy_cache               = new stdClass();
		$legacy_cache->tag_name     = '98.0.0';
		$legacy_cache->published_at = '';
		$legacy_cache->assets       = array();

		// 取得済みの対応バージョンを保存したリリース情報。
		$saved_cache               = clone $legacy_cache;
		$saved_cache->requirements = array(
			'tested'       => '7.0',
			'requires_php' => '8.2',
			'requires'     => '6.7',
		);

		// readme.txt の取得に失敗した状態を保存したリリース情報。
		$failed_cache               = clone $legacy_cache;
		$failed_cache->requirements = null;

		// 対応バージョンに形式の合わない値が入ったリリース情報。
		$invalid_values_cache               = clone $legacy_cache;
		$invalid_values_cache->requirements = array(
			'tested'       => '<script>',
			'requires_php' => array( '8.3' ),
			'requires'     => '6.8',
		);

		// 対応バージョンの項目が配列でない、壊れたリリース情報。
		$broken_cache               = clone $legacy_cache;
		$broken_cache->requirements = 'broken';

		// Tested up to が 7.1・6.9 の対応バージョンを保存したリリース情報。
		$tested_71_cache               = clone $legacy_cache;
		$tested_71_cache->requirements = array(
			'tested'       => '7.1',
			'requires_php' => '8.3',
			'requires'     => '6.8',
		);
		$tested_69_cache               = clone $legacy_cache;
		$tested_69_cache->requirements = array(
			'tested'       => '6.9',
			'requires_php' => '8.3',
			'requires'     => '6.8',
		);

		// ケースで wp_version を指定しないときの WordPress のバージョン。
		// 各ケースの Tested up to（7.1・7.0）とは系列が違い、パッチ番号は補われない。
		$default_wp_version = '7.2.1';

		$test_cases = array(
			array(
				'test_condition_name'         => 'リリース情報を保存していない場合 => GitHub API とタグ時点の readme.txt を 1 回ずつ取り、新しいバージョンの対応バージョンを入れる',
				'cached_release'              => null,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '99.0.0',
				'expect_requirements'         => $release_values,
				'expect_request_count'        => 1,
				'expect_readme_request_count' => 1,
			),
			array(
				'test_condition_name'         => '対応バージョンを保存済みの場合 => どこにも問い合わせず、保存済みの値を入れる',
				'cached_release'              => $saved_cache,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '98.0.0',
				'expect_requirements'         => $saved_cache->requirements,
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => '更新前のバージョンが保存したリリース情報（対応バージョンの項目なし）の場合 => 警告を出さず、問い合わせもせず、値なし（空）にする',
				'cached_release'              => $legacy_cache,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '98.0.0',
				'expect_requirements'         => $empty_values,
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => 'readme.txt の取得に失敗した状態を保存している場合 => 詳細ポップアップでは取り直さず、値なし（空）にする',
				'cached_release'              => $failed_cache,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '98.0.0',
				'expect_requirements'         => $empty_values,
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => '保存済みの対応バージョンに形式の合わない値が混ざる場合 => 形式に合う値だけを入れる',
				'cached_release'              => $invalid_values_cache,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '98.0.0',
				'expect_requirements'         => array(
					'tested'       => '',
					'requires_php' => '',
					'requires'     => '6.8',
				),
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => '保存済みの対応バージョンが配列でない（壊れた値の）場合 => 保存値を使わず問い合わせ直し、新しい値を入れる',
				'cached_release'              => $broken_cache,
				'stub_success'                => true,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '99.0.0',
				'expect_requirements'         => $release_values,
				'expect_request_count'        => 1,
				'expect_readme_request_count' => 1,
			),
			array(
				'test_condition_name'         => 'GitHub API の取得に失敗する場合 => readme.txt は取りに行かず、値なし（空）にする',
				'cached_release'              => null,
				'stub_success'                => false,
				'stub_readme_body'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1\nRequires PHP: 8.3\n",
				'expect_version'              => '',
				'expect_requirements'         => $empty_values,
				'expect_request_count'        => 1,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => 'タグ時点の readme.txt の取得に失敗する場合 => バージョンは表示し、対応バージョンは値なし（空）にする',
				'cached_release'              => null,
				'stub_success'                => true,
				'stub_readme_body'            => null,
				'expect_version'              => '99.0.0',
				'expect_requirements'         => $empty_values,
				'expect_request_count'        => 1,
				'expect_readme_request_count' => 1,
			),
			array(
				'test_condition_name'         => '保存済みの Tested up to が 7.1、WordPress が 7.1.4 の場合 => 詳細ポップアップにも 7.1.4 を入れる',
				'cached_release'              => $tested_71_cache,
				'stub_success'                => true,
				'stub_readme_body'            => null,
				'wp_version'                  => '7.1.4',
				'expect_version'              => '98.0.0',
				'expect_requirements'         => array(
					'tested'       => '7.1.4',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
			array(
				'test_condition_name'         => '保存済みの Tested up to が 6.9、WordPress が 7.1.4（系列が違う）場合 => 6.9 のまま入れる',
				'cached_release'              => $tested_69_cache,
				'stub_success'                => true,
				'stub_readme_body'            => null,
				'wp_version'                  => '7.1.4',
				'expect_version'              => '98.0.0',
				'expect_requirements'         => $tested_69_cache->requirements,
				'expect_request_count'        => 0,
				'expect_readme_request_count' => 0,
			),
		);

		$plugin_file = $this->get_plugin_file();

		foreach ( $test_cases as $case ) {
			// ケースごとにスタブと保存済みのリリース情報を整える。
			$this->request_count        = 0;
			$this->readme_request_count = 0;
			$this->stub_success         = $case['stub_success'];
			$this->stub_readme_body     = $case['stub_readme_body'];
			$GLOBALS['wp_version']      = $case['wp_version'] ?? $default_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストの条件として書き換え、tear_down で戻す。
			delete_site_transient( 'vkbm_github_updater_release' );
			if ( null !== $case['cached_release'] ) {
				set_site_transient( 'vkbm_github_updater_release', clone $case['cached_release'], HOUR_IN_SECONDS );
			}

			$updater        = new VKBM_GitHub_Updater( $plugin_file );
			$response       = new stdClass();
			$response->slug = plugin_basename( $plugin_file );
			$result         = $updater->set_plugin_info( false, 'plugin_information', $response );

			$this->assertSame( $case['expect_version'], $result->version, $case['test_condition_name'] . '（バージョン）' );

			$actual_values = array();
			foreach ( array_keys( $case['expect_requirements'] ) as $key ) {
				$actual_values[ $key ] = isset( $result->{$key} ) ? $result->{$key} : null;
			}
			$this->assertSame( $case['expect_requirements'], $actual_values, $case['test_condition_name'] . '（対応バージョン）' );
			$this->assertSame( $case['expect_request_count'], $this->request_count, $case['test_condition_name'] . '（GitHub API への問い合わせ回数）' );
			$this->assertSame( $case['expect_readme_request_count'], $this->readme_request_count, $case['test_condition_name'] . '（readme.txt を取りに行った回数）' );
		}
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
		$this->assertSame( array( 'tag_name', 'published_at', 'assets', 'requirements' ), array_keys( get_object_vars( $cached ) ), '必要な項目だけを保存する' );
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
