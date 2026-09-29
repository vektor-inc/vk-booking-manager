<?php
/**
 * VKBM_GitHub_Updater::post_install() のテスト。
 *
 * @package VKBookingManager
 */

/**
 * $wp_filesystem->move() の呼び出しを記録するためのスパイ。
 *
 * post_install() が実際にファイルシステムへ触れずに
 * 「移動処理を呼んだかどうか」だけを検証できるようにする。
 */
class Spy_VKBM_WP_Filesystem {
	/**
	 * move() が呼ばれたかどうか。
	 *
	 * @var bool
	 */
	public $move_called = false;

	/**
	 * move() をスタブし、呼び出しを記録する。
	 *
	 * @param string $source 移動元パス。
	 * @param string $destination 移動先パス。
	 * @return bool 常に true を返す。
	 */
	public function move( $source, $destination ) {
		$this->move_called = true;

		return true;
	}
}

/**
 * upgrader_post_install は本プラグイン専用のフックではなく、サイト上の
 * すべてのプラグイン・テーマのインストール／更新で発火する WordPress コア共通フックのため、
 * post_install() が $hook_extra（今回の更新対象）を見て、本プラグイン自身の
 * 更新のときだけ移動・再有効化処理を行うことを検証する。
 */
class Test_VKBM_GitHub_Updater_Post_Install extends WP_UnitTestCase {

	/**
	 * テスト対象プラグインのファイルパス。
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * テスト対象プラグインのスラッグ（plugin_basename() の結果）。
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * テスト前の $wp_filesystem を退避する変数。
	 *
	 * @var mixed
	 */
	private $original_wp_filesystem;

	/**
	 * テスト前の active_plugins オプションを退避する変数。
	 *
	 * matching ケースで is_plugin_active() / activate_plugin() の
	 * 再有効化フローを検証するために active_plugins を書き換えるため、
	 * テスト後に元の値へ復元する。
	 *
	 * @var array
	 */
	private $original_active_plugins;

	/**
	 * セットアップ処理。
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/class-vkbm-github-updater.php';

		// is_plugin_active() / activate_plugin() / deactivate_plugins() は
		// いずれも wp-admin/includes/plugin.php 内の関数（wp-admin 専用）のため、
		// テスト実行コンテキストで未読み込みの場合に備えて明示的に読み込む。
		// is_plugin_active() の存在チェックだけで、同ファイル内にある
		// activate_plugin() / deactivate_plugins() もまとめて読み込まれる。
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$this->plugin_file = dirname( __DIR__, 3 ) . '/vk-booking-manager.php';
		$this->plugin_slug = plugin_basename( $this->plugin_file );

		global $wp_filesystem;
		$this->original_wp_filesystem = $wp_filesystem;

		$this->original_active_plugins = get_option( 'active_plugins', array() );
	}

	/**
	 * 後処理。$wp_filesystem と active_plugins オプションをテスト前の状態に戻す。
	 */
	public function tear_down() {
		global $wp_filesystem;
		$wp_filesystem = $this->original_wp_filesystem;

		update_option( 'active_plugins', $this->original_active_plugins );

		parent::tear_down();
	}

	/**
	 * post_install() を対象一致／不一致の条件別に検証する。
	 */
	public function test_post_install() {
		$test_cases = array(
			array(
				'test_condition_name' => '$hook_extra[\'plugin\'] が本プラグイン自身と一致する場合 => 従来どおり移動処理を行い destination がプラグインフォルダへ書き換わり、更新前に有効だったプラグインが再有効化される',
				'hook_extra'          => array( 'plugin' => $this->plugin_slug ),
				'expect_move_called'  => true,
			),
			array(
				'test_condition_name' => '$hook_extra[\'plugin\'] が他プラグインのスラッグ（本プラグイン以外の更新）の場合 => 移動処理を行わず $result をそのまま返す',
				'hook_extra'          => array( 'plugin' => 'other-plugin/other-plugin.php' ),
				'expect_move_called'  => false,
			),
			array(
				'test_condition_name' => '$hook_extra[\'theme\'] が指定されている（テーマの更新）場合 => 移動処理を行わず $result をそのまま返す',
				'hook_extra'          => array( 'theme' => 'twentytwentyfour' ),
				'expect_move_called'  => false,
			),
			array(
				'test_condition_name' => '$hook_extra にプラグイン・テーマどちらの情報も無い場合 => 移動処理を行わず $result をそのまま返す',
				'hook_extra'          => array(),
				'expect_move_called'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			global $wp_filesystem;
			$spy_filesystem = new Spy_VKBM_WP_Filesystem();
			$wp_filesystem  = $spy_filesystem;

			$reactivation_filter = null;

			if ( $case['expect_move_called'] ) {
				// matching ケース限定のフィクスチャ。
				//
				// post_install() は is_plugin_active( $this->plugin_slug ) が真の
				// 場合だけ activate_plugin( $this->plugin_slug ) を呼ぶ（更新前に
				// 有効だったプラグインの再有効化）。この呼び出しが削除されても
				// 検出できるテストにするには、activate_plugin() が実際に
				// 「active_plugins オプションへ対象を追加する」という観測可能な
				// 副作用を起こす状況を作る必要がある。
				//
				// まず deactivate_plugins() で active_plugins オプションから対象を
				// 確実に除外し、「更新前は非活性」という既知の状態にする
				// （WP_UnitTestCase はテストごとに DB を自動ロールバックするため、
				// 本番の有効化状態への副作用はない）。
				//
				// その上で pre_option_active_plugins フィルタを「一度だけ」有効を
				// 装うように仕込む。
				// - post_install() 内の is_plugin_active() の判定 → フィルタにより
				//   有効と判定され、activate_plugin() が呼ばれる分岐に入る。
				// - フィルタは読み取られた直後に自身を解除するため、続く
				//   activate_plugin() 内部の重複チェック（既に有効かどうか）は
				//   実際の active_plugins オプション（対象を含まない）を参照し、
				//   activate_plugin() の再有効化処理（オプションへの追加）が
				//   実際に実行される。
				deactivate_plugins( $this->plugin_slug );

				$reactivation_filter = function ( $pre ) use ( &$reactivation_filter ) {
					remove_filter( 'pre_option_active_plugins', $reactivation_filter );

					return array( $this->plugin_slug );
				};
				add_filter( 'pre_option_active_plugins', $reactivation_filter );
			}

			$updater = new VKBM_GitHub_Updater( $this->plugin_file );

			$result = array( 'destination' => '/tmp/vkbm-test-dummy-source-dir' );

			$actual = $updater->post_install( true, $case['hook_extra'], $result );

			if ( $case['expect_move_called'] ) {
				// post_install() が is_plugin_active() を一度も呼ばなかった場合に
				// 備えて、フィルタが残っていれば念のため外しておく。
				remove_filter( 'pre_option_active_plugins', $reactivation_filter );

				$this->assertTrue(
					is_plugin_active( $this->plugin_slug ),
					$case['test_condition_name'] . '（activate_plugin() が呼ばれ、プラグインが再有効化される）'
				);
			}

			$this->assertSame(
				$case['expect_move_called'],
				$spy_filesystem->move_called,
				$case['test_condition_name']
			);

			if ( $case['expect_move_called'] ) {
				$expected_destination = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );

				$this->assertSame(
					$expected_destination,
					$actual['destination'],
					$case['test_condition_name'] . '（destination が本プラグインのフォルダへ書き換わる）'
				);
			} else {
				$this->assertSame(
					$result,
					$actual,
					$case['test_condition_name'] . '（$result がそのまま返る）'
				);
			}
		}
	}
}
