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
	 * セットアップ処理。
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/class-vkbm-github-updater.php';

		$this->stub_enabled       = false;
		$this->stub_response_body = '';

		add_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10, 3 );
	}

	/**
	 * 後処理。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * GitHub API へのリクエストをスタブに差し替える。
	 *
	 * @param false|array|WP_Error $preempt 既定のプリエンプト値。
	 * @param array                $args    リクエスト引数。
	 * @param string               $url     リクエスト URL。
	 * @return false|array スタブレスポンス、または既定動作（false）。
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
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
	 * set_transient() を条件別に検証する。
	 */
	public function test_set_transient() {
		$plugin_file = dirname( __DIR__, 3 ) . '/vk-booking-manager.php';
		$plugin_slug = plugin_basename( $plugin_file );

		// set_transient() 内部の init_plugin_data() が get_plugin_data() で
		// 実ファイルの値を無条件に読み直すため、比較に使われるのは
		// 常に実際のプラグインバージョンになる。リフレクションでの
		// plugin_data['Version'] 上書きは比較に反映されず「同一バージョン」
		// ケースを偽陽性にするため、実バージョンを取得してケースの
		// 基準値として使う。
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$real_plugin_data     = get_plugin_data( $plugin_file );
		$real_current_version = (string) $real_plugin_data['Version'];

		// 「更新あり」ケースで確実に現在バージョンより大きくなるよう、
		// 実バージョンにメジャー番号を足したタグ名を使う。
		$newer_tag_name = ( (int) $real_current_version + 999 ) . '.0.0';

		$dummy_assets = array(
			array(
				'name'                 => 'vk-booking-manager.zip',
				'browser_download_url' => 'https://example.com/vk-booking-manager.zip',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'GitHub の最新タグが現在のバージョンと同じ（更新なし）場合 => no_update に登録され response には登録されない',
				'stub_enabled'        => true,
				'tag_name'            => $real_current_version,
				'checked_version'     => $real_current_version,
				'assets'              => $dummy_assets,
				'expect_no_update'    => true,
				'expect_response'     => false,
			),
			array(
				'test_condition_name' => 'GitHub に新しいタグはあるがダウンロード可能なアセットが無い場合 => response には登録されず、自動更新欄が消えないよう no_update に登録される',
				'stub_enabled'        => true,
				'tag_name'            => $newer_tag_name,
				'checked_version'     => $real_current_version,
				'assets'              => array(),
				'expect_no_update'    => true,
				'expect_response'     => false,
			),
			array(
				'test_condition_name' => 'GitHub に新しいタグがあり配布 zip も存在する（更新あり）場合 => response に登録され no_update には登録されない',
				'stub_enabled'        => true,
				'tag_name'            => $newer_tag_name,
				'checked_version'     => $real_current_version,
				'assets'              => $dummy_assets,
				'expect_no_update'    => false,
				'expect_response'     => true,
			),
			array(
				'test_condition_name' => 'GitHub API の取得に失敗する場合 => 自動更新欄が消えないよう no_update に登録される',
				'stub_enabled'        => false,
				'tag_name'            => $newer_tag_name,
				'checked_version'     => $real_current_version,
				'assets'              => array(),
				'expect_no_update'    => true,
				'expect_response'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->stub_enabled       = $case['stub_enabled'];
			$this->stub_response_body = wp_json_encode(
				array(
					array(
						'tag_name' => $case['tag_name'],
						'assets'   => $case['assets'],
					),
				)
			);

			$updater = new VKBM_GitHub_Updater( $plugin_file );

			$transient           = new stdClass();
			$transient->checked  = array( $plugin_slug => $case['checked_version'] );
			$transient->response = array();

			$result = $updater->set_transient( $transient );

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
