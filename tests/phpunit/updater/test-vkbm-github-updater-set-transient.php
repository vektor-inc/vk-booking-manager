<?php
/**
 * VKBM_GitHub_Updater::set_transient() のテスト。
 *
 * @package VKBookingManager
 */

/**
 * 無料版の GitHub Updater が、更新の有無に応じて
 * $transient->response / $transient->no_update の正しい方へ登録することを検証する。
 */
class Test_VKBM_GitHub_Updater_Set_Transient extends WP_UnitTestCase {

	/**
	 * pre_http_request でスタブする GitHub API のレスポンスボディ。
	 *
	 * @var string
	 */
	private $stub_response_body = '';

	/**
	 * pre_http_request のスタブ結果を差し込む。
	 *
	 * @var bool
	 */
	private $stub_enabled = false;

	/**
	 * スタブが返すタグ時点の readme.txt の本文（null なら取得失敗の WP_Error を返す）。
	 *
	 * @var string|null
	 */
	private $stub_readme_body = null;

	/**
	 * タグ時点の readme.txt へのリクエストの記録（URL と引数）。
	 *
	 * @var array
	 */
	private $readme_requests = array();

	/**
	 * テスト用に作った仮のプラグインのディレクトリ（tear_down で削除する）。
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

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

		// WordPress 本体の更新の一覧（get_core_updates()）を使うケースのために読み込む。
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$this->stub_enabled        = false;
		$this->stub_response_body  = '';
		$this->stub_readme_body    = null;
		$this->readme_requests     = array();
		$this->fixture_dirs        = array();
		$this->original_wp_version = $GLOBALS['wp_version'];

		// 前のテストで保存されたリリース情報・本体の更新情報が残らないよう消しておく。
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
		delete_site_transient( 'update_core' );
		$GLOBALS['wp_version'] = $this->original_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで書き換えた値を戻す。

		// 仮のプラグインのファイルとディレクトリを消す。
		foreach ( $this->fixture_dirs as $dir ) {
			foreach ( array( 'vk-booking-manager.php', 'readme.txt' ) as $file ) {
				if ( file_exists( $dir . '/' . $file ) ) {
					wp_delete_file( $dir . '/' . $file );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- テスト用の一時ディレクトリを消すだけ。
			}
		}

		parent::tear_down();
	}

	/**
	 * GitHub API とタグ時点の readme.txt へのリクエストをスタブに差し替える。
	 *
	 * @param false|array|WP_Error $preempt 既定のプリエンプト値。
	 * @param array                $args    リクエスト引数。
	 * @param string               $url     リクエスト URL。
	 * @return false|array|WP_Error スタブレスポンス、または既定動作（false）。
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
		// タグ時点の readme.txt（無料版リポジトリの raw ファイル）。
		if ( 0 === strpos( $url, 'https://raw.githubusercontent.com/vektor-inc/vk-booking-manager/' ) ) {
			$this->readme_requests[] = array(
				'url'  => $url,
				'args' => $args,
			);

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

		if ( ! $this->stub_enabled ) {
			return new WP_Error( 'http_request_failed', 'stubbed failure' );
		}

		return array(
			'headers'  => array(),
			'body'     => $this->stub_response_body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 一時ディレクトリに、指定のバージョンと readme.txt を持つ仮のプラグインを作る。
	 *
	 * @param string      $version プラグインのヘッダーに書くバージョン。
	 * @param string|null $readme  readme.txt の本文（null なら readme.txt を作らない）。
	 * @return string 仮のプラグインのメインファイルの絶対パス。
	 */
	private function create_fixture_plugin( string $version, ?string $readme ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'vkbm-updater-fixture-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;

		$plugin_file = $dir . '/vk-booking-manager.php';
		file_put_contents( $plugin_file, "<?php\n/**\n * Plugin Name: VK Booking Manager\n * Version: {$version}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テスト用の一時ファイルを作るだけ。

		if ( null !== $readme ) {
			file_put_contents( $dir . '/readme.txt', $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テスト用の一時ファイルを作るだけ。
		}

		return $plugin_file;
	}

	/**
	 * set_transient() を条件別に検証する。
	 *
	 * 更新あり・更新なしの登録先に加えて、WordPress に渡す対応バージョン
	 * （tested・requires_php・requires）の値と、タグ時点の readme.txt を取りに行く回数を確かめる。
	 */
	public function test_set_transient() {
		// 現在のバージョンは仮のプラグインのヘッダーで固定する（実ファイルのバージョンに左右されないため）。
		$current_version = '1.0.0';
		$newer_tag_name  = '99.0.0';

		$dummy_assets = array(
			array(
				'name'                 => 'vk-booking-manager.zip',
				'browser_download_url' => 'https://github.com/vektor-inc/vk-booking-manager/releases/download/99.0.0/vk-booking-manager.zip',
			),
		);

		// インストール済み（現在のバージョン）の readme.txt。
		$installed_readme = "=== VK Booking Manager ===\nContributors: vektor-inc\nRequires at least: 6.5\nTested up to: 6.8\nRequires PHP: 8.1\nStable tag: 1.0.0\n";
		$installed_values = array(
			'tested'       => '6.8',
			'requires_php' => '8.1',
			'requires'     => '6.5',
		);

		// 新しいバージョン（リリースのタグ時点）の readme.txt。
		$release_readme = "=== VK Booking Manager ===\r\nContributors: vektor-inc\r\nRequires at least: 6.8\r\nTested up to: 7.1\r\nRequires PHP: 8.3\r\nStable tag: 99.0.0\r\n";
		$release_values = array(
			'tested'       => '7.1',
			'requires_php' => '8.3',
			'requires'     => '6.8',
		);

		$empty_values = array(
			'tested'       => '',
			'requires_php' => '',
			'requires'     => '',
		);

		// 更新前のバージョンが保存したリリース情報（対応バージョンの項目が無い形）。
		$legacy_cache               = new stdClass();
		$legacy_cache->tag_name     = $newer_tag_name;
		$legacy_cache->published_at = '';
		$legacy_cache->assets       = array();

		// 同じタグで、前回 readme.txt の取得に失敗した状態を保存したリリース情報。
		$failed_cache               = clone $legacy_cache;
		$failed_cache->requirements = null;

		// 同じタグで、取得済みの対応バージョンを保存したリリース情報。
		$same_tag_cache               = clone $legacy_cache;
		$same_tag_cache->requirements = array(
			'tested'       => '7.0',
			'requires_php' => '8.2',
			'requires'     => '6.7',
		);

		// 前のタグの対応バージョンを保存したリリース情報。
		$older_tag_cache           = clone $same_tag_cache;
		$older_tag_cache->tag_name = '98.0.0';

		$release_readme_url = 'https://raw.githubusercontent.com/vektor-inc/vk-booking-manager/99.0.0/readme.txt';

		// ケースで wp_version を指定しないときの WordPress のバージョン。
		// 上の readme.txt の Tested up to（6.8・7.1・7.0）とは系列が違い、パッチ番号は補われない。
		$default_wp_version = '7.2.1';

		$test_cases = array(
			array(
				'test_condition_name'       => 'GitHub の最新タグが現在のバージョンと同じ（更新なし）場合 => no_update に登録され、インストール済みの readme.txt の対応バージョンが入る',
				'stub_enabled'              => true,
				'tag_name'                  => $current_version,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => $installed_values,
				'expect_readme_urls'        => array( 'https://raw.githubusercontent.com/vektor-inc/vk-booking-manager/1.0.0/readme.txt' ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => 'GitHub に新しいタグはあるがダウンロード可能なアセットが無い場合 => response には登録されず no_update に登録され、インストール済みの対応バージョンが入る',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => array(),
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => $installed_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => 'GitHub に新しいタグがあり配布 zip も存在する（更新あり）場合 => response に登録され、タグ時点の readme.txt の対応バージョンが入る',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $release_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => 'GitHub API の取得に失敗する場合 => no_update に登録され、readme.txt は取りに行かず、インストール済みの対応バージョンが入る',
				'stub_enabled'              => false,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => array(),
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => $installed_values,
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => null,
			),
			array(
				'test_condition_name'       => '更新ありでタグ時点の readme.txt の取得に失敗する場合 => 更新は止めずに response に登録し、対応バージョンは空にして、失敗は保存しない',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => null,
				'cached_release'            => null,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $empty_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => null,
			),
			array(
				'test_condition_name'       => '更新ありでタグ時点の readme.txt にバージョン形式でない値が混ざる場合 => 形式に合う値だけを入れ、合わない値は空にする',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => "=== VK Booking Manager ===\nRequires at least: 6.8 beta\nTested up to: 7.1<script>\nRequires PHP: 8.3\n",
				'cached_release'            => null,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => array(
					'tested'       => '',
					'requires_php' => '8.3',
					'requires'     => '',
				),
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => array(
					'tested'       => '',
					'requires_php' => '8.3',
					'requires'     => '',
				),
			),
			array(
				'test_condition_name'       => 'タグ名がバージョン形式でない場合 => URL に埋め込まず readme.txt を取りに行かない（更新の案内は止めない）',
				'stub_enabled'              => true,
				'tag_name'                  => '99.0.0/../main',
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $empty_values,
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => null,
			),
			array(
				'test_condition_name'       => '同じタグの対応バージョンを保存済みの場合 => readme.txt を取り直さず、保存済みの値を使う',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => $same_tag_cache,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $same_tag_cache->requirements,
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => $same_tag_cache->requirements,
			),
			array(
				'test_condition_name'       => '保存済みの対応バージョンが前のタグのものの場合 => 新しいタグの readme.txt を取りに行く',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => $older_tag_cache,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $release_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '更新前のバージョンが保存したリリース情報（対応バージョンの項目なし）が残っている場合 => 警告を出さずに readme.txt を取りに行き、値を保存する',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => $legacy_cache,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $release_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '同じタグで前回 readme.txt の取得に失敗していた場合 => 失敗を使い回さず、次の定期確認で取り直す',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => $failed_cache,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $release_values,
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '更新なしでインストール済みの readme.txt が無い場合 => 警告を出さずに対応バージョンを空にする',
				'stub_enabled'              => true,
				'tag_name'                  => $current_version,
				'assets'                    => $dummy_assets,
				'installed_readme'          => null,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => $empty_values,
				'expect_readme_urls'        => array( 'https://raw.githubusercontent.com/vektor-inc/vk-booking-manager/1.0.0/readme.txt' ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '更新なしでインストール済みの readme.txt にバージョン形式でない値がある場合 => 形式に合う値だけを入れる',
				'stub_enabled'              => false,
				'tag_name'                  => $current_version,
				'assets'                    => array(),
				'installed_readme'          => "=== VK Booking Manager ===\nRequires at least: 6.5\nTested up to: trunk\nRequires PHP: 8\n",
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => array(
					'tested'       => '',
					'requires_php' => '8',
					'requires'     => '6.5',
				),
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => null,
			),
			array(
				'test_condition_name'       => 'タグ名の末尾に改行がある場合 => バージョン形式として扱わず、readme.txt を取りに行かない',
				'stub_enabled'              => true,
				'tag_name'                  => "99.0.0\n",
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => $empty_values,
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => null,
			),
			array(
				'test_condition_name'       => '更新ありで readme.txt の Tested up to が 7.1、WordPress が 7.1.4 の場合 => 渡す tested は 7.1.4（保存する値は 7.1 のまま）',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'wp_version'                => '7.1.4',
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => array(
					'tested'       => '7.1.4',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '更新ありで Tested up to が 7.1、WordPress が 7.1.3 で本体の更新 7.1.4 が出ている場合 => 同じ系列で最大の 7.1.4 を補う',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'wp_version'                => '7.1.3',
				'core_updates'              => array( '7.1.4' ),
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => array(
					'tested'       => '7.1.4',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => $release_values,
			),
			array(
				'test_condition_name'       => '更新ありで Tested up to が 6.9、WordPress が 7.1.4（系列が違う）場合 => 6.9 のまま渡す',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 6.9\nRequires PHP: 8.3\n",
				'cached_release'            => null,
				'wp_version'                => '7.1.4',
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => array(
					'tested'       => '6.9',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => array(
					'tested'       => '6.9',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
			),
			array(
				'test_condition_name'       => '更新ありで Tested up to がパッチ番号付きの 7.1.2、WordPress が 7.1.4 の場合 => 補わずに 7.1.2 のまま渡す',
				'stub_enabled'              => true,
				'tag_name'                  => $newer_tag_name,
				'assets'                    => $dummy_assets,
				'installed_readme'          => $installed_readme,
				'release_readme'            => "=== VK Booking Manager ===\nRequires at least: 6.8\nTested up to: 7.1.2\nRequires PHP: 8.3\n",
				'cached_release'            => null,
				'wp_version'                => '7.1.4',
				'expect_no_update'          => false,
				'expect_response'           => true,
				'expect_requirements'       => array(
					'tested'       => '7.1.2',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
				'expect_readme_urls'        => array( $release_readme_url ),
				'expect_saved_requirements' => array(
					'tested'       => '7.1.2',
					'requires_php' => '8.3',
					'requires'     => '6.8',
				),
			),
			array(
				'test_condition_name'       => '更新なしでインストール済みの Tested up to が 7.1、WordPress が 7.1.4 の場合 => no_update にも 7.1.4 を渡す',
				'stub_enabled'              => false,
				'tag_name'                  => $current_version,
				'assets'                    => array(),
				'installed_readme'          => "=== VK Booking Manager ===\nRequires at least: 6.5\nTested up to: 7.1\nRequires PHP: 8.1\n",
				'release_readme'            => $release_readme,
				'cached_release'            => null,
				'wp_version'                => '7.1.4',
				'expect_no_update'          => true,
				'expect_response'           => false,
				'expect_requirements'       => array(
					'tested'       => '7.1.4',
					'requires_php' => '8.1',
					'requires'     => '6.5',
				),
				'expect_readme_urls'        => array(),
				'expect_saved_requirements' => null,
			),
		);

		foreach ( $test_cases as $case ) {
			// ケースごとにスタブと保存済みのリリース情報を整える。
			$this->stub_enabled       = $case['stub_enabled'];
			$this->stub_readme_body   = $case['release_readme'];
			$this->readme_requests    = array();
			$this->stub_response_body = wp_json_encode(
				array(
					array(
						'tag_name' => $case['tag_name'],
						'assets'   => $case['assets'],
					),
				)
			);
			delete_site_transient( 'vkbm_github_updater_release' );
			if ( null !== $case['cached_release'] ) {
				set_site_transient( 'vkbm_github_updater_release', clone $case['cached_release'], HOUR_IN_SECONDS );
			}

			// 今の WordPress のバージョンと、用意されている本体の更新。
			$GLOBALS['wp_version'] = $case['wp_version'] ?? $default_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストの条件として書き換え、tear_down で戻す。
			delete_site_transient( 'update_core' );
			if ( ! empty( $case['core_updates'] ) ) {
				$core_updates = array();
				foreach ( $case['core_updates'] as $core_version ) {
					$core_updates[] = (object) array(
						'response' => 'upgrade',
						'current'  => $core_version,
						'locale'   => 'ja',
					);
				}
				set_site_transient( 'update_core', (object) array( 'updates' => $core_updates ), HOUR_IN_SECONDS );
			}

			$plugin_file = $this->create_fixture_plugin( $current_version, $case['installed_readme'] );
			$plugin_slug = plugin_basename( $plugin_file );
			$updater     = new VKBM_GitHub_Updater( $plugin_file );

			$transient           = new stdClass();
			$transient->checked  = array( $plugin_slug => $current_version );
			$transient->response = array();

			$result = $updater->set_transient( $transient );

			// 更新あり・更新なしの登録先。
			$this->assertSame(
				$case['expect_no_update'],
				isset( $result->no_update[ $plugin_slug ] ),
				$case['test_condition_name']
			);
			$this->assertSame(
				$case['expect_response'],
				isset( $result->response[ $plugin_slug ] ),
				$case['test_condition_name']
			);

			if ( $case['expect_no_update'] ) {
				$this->assertSame(
					'',
					$result->no_update[ $plugin_slug ]->package,
					$case['test_condition_name'] . '（no_update の package は空文字）'
				);
			}

			// 登録した項目に入った対応バージョン。
			$item          = $case['expect_response'] ? $result->response[ $plugin_slug ] : $result->no_update[ $plugin_slug ];
			$actual_values = array();
			foreach ( array_keys( $case['expect_requirements'] ) as $key ) {
				$actual_values[ $key ] = isset( $item->{$key} ) ? $item->{$key} : null;
			}
			$this->assertSame( $case['expect_requirements'], $actual_values, $case['test_condition_name'] . '（対応バージョン）' );

			// タグ時点の readme.txt を取りに行った URL と、そのときの引数。
			$this->assertSame(
				$case['expect_readme_urls'],
				wp_list_pluck( $this->readme_requests, 'url' ),
				$case['test_condition_name'] . '（readme.txt の取得先）'
			);
			foreach ( $this->readme_requests as $request ) {
				$this->assertGreaterThan( 0, $request['args']['timeout'] ?? 0, $case['test_condition_name'] . '（timeout を付ける）' );
				$this->assertGreaterThan( 0, $request['args']['limit_response_size'] ?? 0, $case['test_condition_name'] . '（読む大きさを制限する）' );
				$this->assertSame( 'identity', $request['args']['headers']['Accept-Encoding'] ?? null, $case['test_condition_name'] . '（圧縮なしで受け取る）' );
			}

			// リリース情報と一緒に保存された対応バージョン（失敗時は保存しない）。
			$saved = get_site_transient( 'vkbm_github_updater_release' );
			$this->assertSame(
				$case['expect_saved_requirements'],
				is_object( $saved ) && isset( $saved->requirements ) ? $saved->requirements : null,
				$case['test_condition_name'] . '（保存した対応バージョン）'
			);
		}
	}

	/**
	 * set_transient() が、ベータ版・下書きを除いた最大バージョンのリリースを採用することを検証する。
	 */
	public function test_select_latest_release() {
		$plugin_file = dirname( __DIR__, 3 ) . '/vk-booking-manager.php';
		$plugin_slug = plugin_basename( $plugin_file );

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$real_plugin_data     = get_plugin_data( $plugin_file );
		$real_current_version = (string) $real_plugin_data['Version'];

		// 現在のバージョンより確実に大きい 3 つのバージョン（小 < 中 < 大）。
		$major    = (int) $real_current_version;
		$ver_low  = ( $major + 100 ) . '.0.0';
		$ver_mid  = ( $major + 200 ) . '.0.0';
		$ver_high = ( $major + 300 ) . '.0.0';

		/**
		 * リリース 1 件分の配列を作る。
		 *
		 * @param string $tag_name    タグ名。
		 * @param bool   $prerelease  ベータ版かどうか。
		 * @param bool   $draft       下書きかどうか。
		 * @return array リリース 1 件分。
		 */
		$release = static function ( $tag_name, $prerelease = false, $draft = false ) {
			return array(
				'tag_name'   => $tag_name,
				'prerelease' => $prerelease,
				'draft'      => $draft,
				'assets'     => array(
					array(
						'name'                 => 'vk-booking-manager.zip',
						'browser_download_url' => 'https://github.com/vektor-inc/vk-booking-manager/releases/download/' . $tag_name . '/vk-booking-manager.zip',
					),
				),
			);
		};

		$test_cases = array(
			array(
				'test_condition_name' => '先頭がベータ版で 2 件目が正式版の場合 => ベータ版を採用せず正式版を採用する',
				'releases'            => array( $release( $ver_high, true ), $release( $ver_mid ) ),
				'expect_new_version'  => $ver_mid,
			),
			array(
				'test_condition_name' => '先頭が下書きで 2 件目が正式版の場合 => 下書きを除外して正式版を採用する',
				'releases'            => array( $release( $ver_high, false, true ), $release( $ver_low ) ),
				'expect_new_version'  => $ver_low,
			),
			array(
				'test_condition_name' => '並び順が新しい順でない場合 => 並び順に関係なく最大バージョンを採用する',
				'releases'            => array( $release( $ver_low ), $release( $ver_high ), $release( $ver_mid ) ),
				'expect_new_version'  => $ver_high,
			),
			array(
				'test_condition_name' => 'v 付きのタグ名が混在する場合 => v を除いた数値で比較して最大を採用する',
				'releases'            => array( $release( $ver_low ), $release( 'v' . $ver_mid ) ),
				'expect_new_version'  => 'v' . $ver_mid,
			),
			array(
				'test_condition_name' => '不正な形の要素（文字列・タグ名なし・数値のタグ名）が混ざる場合 => 無視して正式版を採用する',
				'releases'            => array( 'foo', array( 'prerelease' => false ), array( 'tag_name' => 123 ), $release( $ver_mid ) ),
				'expect_new_version'  => $ver_mid,
			),
			array(
				'test_condition_name' => 'すべてベータ版または下書きの場合 => 採用できるリリースが無く更新なし（no_update）になる',
				'releases'            => array( $release( $ver_high, true ), $release( $ver_mid, false, true ) ),
				'expect_new_version'  => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->stub_enabled       = true;
			$this->stub_response_body = wp_json_encode( $case['releases'] );

			$updater = new VKBM_GitHub_Updater( $plugin_file );

			$transient           = new stdClass();
			$transient->checked  = array( $plugin_slug => $real_current_version );
			$transient->response = array();

			$result = $updater->set_transient( $transient );

			if ( null === $case['expect_new_version'] ) {
				$this->assertFalse( isset( $result->response[ $plugin_slug ] ), $case['test_condition_name'] );
				$this->assertTrue( isset( $result->no_update[ $plugin_slug ] ), $case['test_condition_name'] );
			} else {
				$this->assertTrue( isset( $result->response[ $plugin_slug ] ), $case['test_condition_name'] );
				$this->assertSame( $case['expect_new_version'], $result->response[ $plugin_slug ]->new_version, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * set_transient() に非オブジェクトの値を渡した場合の挙動を検証する。
	 *
	 * issue #530: set_transient() 冒頭のガード（is_object() チェックと
	 * empty( $transient->checked ) チェック）の順序に関する指摘に対応するテスト。
	 * empty() でのプロパティアクセスは非オブジェクトでも PHP8 の
	 * 「Attempt to read property」警告を出さない言語仕様のため、ガードの順序を
	 * 入れ替えても実害のある不具合ではないが、is_object() チェックが本来の
	 * 役割（オブジェクトでない値を最初に弾く）を果たし、渡した値をそのまま
	 * 返すことを直接確認する。
	 */
	public function test_set_transient_with_non_object() {
		$plugin_file = dirname( __DIR__, 3 ) . '/vk-booking-manager.php';

		$test_cases = array(
			array(
				'test_condition_name' => '$transient が false（オブジェクトでない）場合 => 何もせず false のまま返される',
				'transient'           => false,
			),
			array(
				'test_condition_name' => '$transient が null（オブジェクトでない）場合 => 何もせず null のまま返される',
				'transient'           => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$updater = new VKBM_GitHub_Updater( $plugin_file );

			$result = $updater->set_transient( $case['transient'] );

			$this->assertSame(
				$case['transient'],
				$result,
				$case['test_condition_name']
			);
		}
	}
}
