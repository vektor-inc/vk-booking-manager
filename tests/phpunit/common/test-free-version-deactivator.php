<?php
/**
 * Pro 版が有効なときの無料版の自動無効化（#694）の Free_Version_Deactivator のテスト。
 *
 * Pro 版専用のクラスのため、無料版ビルド（クラスファイルが無い構成）ではスキップする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use WP_UnitTestCase;

/**
 * Free_Version_Deactivator のテスト。
 *
 * @group common
 * @group free-version-deactivator
 */
class Free_Version_Deactivator_Test extends WP_UnitTestCase {
	private const PRO_PLUGIN  = 'vk-booking-manager-pro/vk-booking-manager.php';
	private const FREE_ORG    = 'vk-booking-manager/vk-booking-manager.php';
	private const FREE_FOLDER = 'vk-booking-manager-free/vk-booking-manager.php';
	private const OTHER       = 'hello-dolly/hello.php';

	/**
	 * 無料版ビルドではクラス自体が無いのでスキップし、テスト用に管理画面扱いにする。
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'Free_Version_Deactivator' ) ) {
			$this->markTestSkipped( 'Free_Version_Deactivator は Pro 版にのみ含まれます。' );
		}

		set_current_screen( 'dashboard' );
		delete_option( \Free_Version_Deactivator::NOTICE_OPTION );

		// 実ファイルには触れず、get_plugins() が読むキャッシュへプラグイン一覧を差し込む。
		$this->set_plugin_list(
			array(
				self::PRO_PLUGIN  => 'VK Booking Manager Pro',
				self::FREE_ORG    => 'VK Booking Manager',
				self::FREE_FOLDER => 'VK Booking Manager',
			)
		);
	}

	/**
	 * get_plugins() が返すプラグイン一覧を、キャッシュへ差し込んで差し替える（実ファイルは作らない）。
	 *
	 * @param array<string,string> $plugins basename => ヘッダーの Plugin Name。
	 */
	private function set_plugin_list( array $plugins ): void {
		$list = array();
		foreach ( $plugins as $basename => $plugin_name ) {
			$list[ $basename ] = array(
				'Name'       => $plugin_name,
				'TextDomain' => 'vk-booking-manager',
			);
		}
		wp_cache_set( 'plugins', array( '' => $list ), 'plugins' );
	}

	/**
	 * 後始末として画面とオプションを元に戻す。
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		delete_option( \Free_Version_Deactivator::NOTICE_OPTION );
		update_option( 'active_plugins', array() );
		remove_all_actions( 'admin_init' );

		wp_cache_delete( 'plugins', 'plugins' );

		parent::tear_down();
	}

	/**
	 * 無料版を有効化した直後（activated_plugin）に、Pro 版が有効なら無料版を無効化する。
	 */
	public function test_on_activated_plugin(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'Pro 有効中に wp.org 版フォルダの無料版を有効化 => 無料版だけ無効化され通知が残る',
				'active_plugins'      => array( self::PRO_PLUGIN, self::FREE_ORG ),
				'activated_plugin'    => self::FREE_ORG,
				'expected_active'     => array( self::PRO_PLUGIN ),
				'expected_notice'     => '1',
			),
			array(
				'test_condition_name' => 'Pro 有効中に -free フォルダの無料版を有効化 => 無料版だけ無効化され通知が残る',
				'active_plugins'      => array( self::FREE_FOLDER, self::PRO_PLUGIN ),
				'activated_plugin'    => self::FREE_FOLDER,
				'expected_active'     => array( self::PRO_PLUGIN ),
				'expected_notice'     => '1',
			),
			array(
				'test_condition_name' => 'Pro 版を有効化したとき無料版も有効 => 無料版だけ無効化され通知が残る',
				'active_plugins'      => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'activated_plugin'    => self::PRO_PLUGIN,
				'expected_active'     => array( self::PRO_PLUGIN ),
				'expected_notice'     => '1',
			),
			array(
				'test_condition_name' => '無関係なプラグインの有効化 => 何も無効化せず通知も出さない',
				'active_plugins'      => array( self::FREE_ORG, self::PRO_PLUGIN, self::OTHER ),
				'activated_plugin'    => self::OTHER,
				'expected_active'     => array( self::FREE_ORG, self::PRO_PLUGIN, self::OTHER ),
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'active_plugins', $case['active_plugins'] );
			delete_option( \Free_Version_Deactivator::NOTICE_OPTION );
			\Free_Version_Deactivator::schedule_deactivate_free_on_activated( WP_PLUGIN_DIR . '/' . self::PRO_PLUGIN );

			\Free_Version_Deactivator::on_activated_plugin( $case['activated_plugin'] );

			$this->assertSame(
				$case['expected_active'],
				array_values( (array) get_option( 'active_plugins' ) ),
				$case['test_condition_name'] . '（有効なプラグイン）'
			);
			$this->assertSame(
				$case['expected_notice'],
				get_option( \Free_Version_Deactivator::NOTICE_OPTION, false ),
				$case['test_condition_name'] . '（通知）'
			);
		}
	}

	/**
	 * 通常の管理画面の読み込みで Pro 版と無料版が両方有効なら、無料版を自動で無効化する。
	 */
	public function test_protect_activation(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理画面で Pro・無料版が両方有効（有効化操作ではない） => その場では触らず admin_init に予約し、Pro は読み込みを続ける',
				'is_admin'            => true,
				'is_activation'       => false,
				'active_plugins'      => array( self::FREE_FOLDER, self::PRO_PLUGIN ),
				'expected_return'     => false,
				'expected_active'     => array( self::FREE_FOLDER, self::PRO_PLUGIN ),
				'expected_notice'     => false,
				'expected_scheduled'  => true,
			),
			array(
				'test_condition_name' => '管理画面で Pro・wp.org 版が両方有効（有効化操作ではない） => admin_init に予約する',
				'is_admin'            => true,
				'is_activation'       => false,
				'active_plugins'      => array( self::PRO_PLUGIN, self::FREE_ORG ),
				'expected_return'     => false,
				'expected_active'     => array( self::PRO_PLUGIN, self::FREE_ORG ),
				'expected_notice'     => false,
				'expected_scheduled'  => true,
			),
			array(
				'test_condition_name' => 'Pro 版の有効化操作中に無料版が有効 => 読み込みを中断し、無効化は有効化後に回す（この時点では触らない）',
				'is_admin'            => true,
				'is_activation'       => true,
				'active_plugins'      => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_return'     => true,
				'expected_active'     => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_notice'     => false,
				'expected_scheduled'  => false,
			),
			array(
				'test_condition_name' => '管理画面以外のリクエスト => 何もしない',
				'is_admin'            => false,
				'is_activation'       => false,
				'active_plugins'      => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_return'     => false,
				'expected_active'     => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_notice'     => false,
				'expected_scheduled'  => false,
			),
			array(
				'test_condition_name' => '管理画面で無料版が有効でない => 何もせず通知も出さない',
				'is_admin'            => true,
				'is_activation'       => false,
				'active_plugins'      => array( self::PRO_PLUGIN ),
				'expected_return'     => false,
				'expected_active'     => array( self::PRO_PLUGIN ),
				'expected_notice'     => false,
				'expected_scheduled'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'active_plugins', $case['active_plugins'] );
			delete_option( \Free_Version_Deactivator::NOTICE_OPTION );
			remove_all_actions( 'admin_init' );
			set_current_screen( $case['is_admin'] ? 'dashboard' : 'front' );

			$actual = \Free_Version_Deactivator::protect_activation(
				WP_PLUGIN_DIR . '/' . self::PRO_PLUGIN,
				$case['is_activation']
			);

			$this->assertSame( $case['expected_return'], $actual, $case['test_condition_name'] . '（戻り値）' );
			$this->assertSame(
				$case['expected_scheduled'],
				false !== has_action( 'admin_init', array( \Free_Version_Deactivator::class, 'deactivate_free_on_admin_init' ) ),
				$case['test_condition_name'] . '（admin_init への予約）'
			);
			$this->assertSame(
				$case['expected_active'],
				array_values( (array) get_option( 'active_plugins' ) ),
				$case['test_condition_name'] . '（有効なプラグイン）'
			);
			$this->assertSame(
				$case['expected_notice'],
				get_option( \Free_Version_Deactivator::NOTICE_OPTION, false ),
				$case['test_condition_name'] . '（通知）'
			);
		}
	}

	/**
	 * 通常の管理画面での無効化は、権限のある利用者の通常リクエストでだけ行う。
	 */
	public function test_deactivate_free_on_admin_init(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者の通常の管理画面リクエスト => 無料版を無効化し通知を残す',
				'role'                => 'administrator',
				'is_ajax'             => false,
				'expected_active'     => array( self::PRO_PLUGIN ),
				'expected_notice'     => '1',
			),
			array(
				'test_condition_name' => 'プラグインを有効化できない購読者のリクエスト => 何も無効化しない',
				'role'                => 'subscriber',
				'is_ajax'             => false,
				'expected_active'     => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '管理者でも Ajax リクエスト => 何も無効化しない',
				'role'                => 'administrator',
				'is_ajax'             => true,
				'expected_active'     => array( self::FREE_ORG, self::PRO_PLUGIN ),
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'active_plugins', array( self::FREE_ORG, self::PRO_PLUGIN ) );
			delete_option( \Free_Version_Deactivator::NOTICE_OPTION );
			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['role'] ) ) );
			$ajax_filter = $case['is_ajax'] ? '__return_true' : '__return_false';
			add_filter( 'wp_doing_ajax', $ajax_filter );

			\Free_Version_Deactivator::deactivate_free_on_admin_init();

			remove_filter( 'wp_doing_ajax', $ajax_filter );
			$this->assertSame(
				$case['expected_active'],
				array_values( (array) get_option( 'active_plugins' ) ),
				$case['test_condition_name'] . '（有効なプラグイン）'
			);
			$this->assertSame(
				$case['expected_notice'],
				get_option( \Free_Version_Deactivator::NOTICE_OPTION, false ),
				$case['test_condition_name'] . '（通知）'
			);
		}
	}

	/**
	 * 無料版の一覧は、ヘッダーで無料版と判定できるものだけで、Pro 版自身は含めない。
	 */
	public function test_get_free_plugin_files(): void {
		// set_up() で差し込んだ一覧では、wp.org 版フォルダ（FREE_ORG）のヘッダーに Pro を含まないので、
		// Pro 版が wp.org 版と同じフォルダ名に置かれたサイトを再現できる。

		$test_cases = array(
			array(
				'test_condition_name' => 'Pro 版が vk-booking-manager-pro/ にある => 2つの無料版フォルダを返す',
				'pro_plugin'          => self::PRO_PLUGIN,
				'expected'            => array( self::FREE_FOLDER, self::FREE_ORG ),
			),
			array(
				'test_condition_name' => 'Pro 版が vk-booking-manager/ にある（ヘッダーに Pro を含まない） => 自分自身を除外する',
				'pro_plugin'          => self::FREE_ORG,
				'expected'            => array( self::FREE_FOLDER ),
			),
		);

		foreach ( $test_cases as $case ) {
			\Free_Version_Deactivator::schedule_deactivate_free_on_activated( WP_PLUGIN_DIR . '/' . $case['pro_plugin'] );

			$actual = \Free_Version_Deactivator::get_free_plugin_files();
			sort( $actual );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		\Free_Version_Deactivator::schedule_deactivate_free_on_activated( WP_PLUGIN_DIR . '/' . self::PRO_PLUGIN );
	}

	/**
	 * 有効化操作中かの既定の判定は、本体が有効化するファイルを読み込んでいるときだけ true になる。
	 */
	public function test_is_activation_request(): void {
		update_option( 'active_plugins', array( self::FREE_ORG, self::PRO_PLUGIN ) );

		$this->assertFalse( \Free_Version_Deactivator::is_activation_request(), '通常のリクエスト => false' );
		$this->assertFalse(
			\Free_Version_Deactivator::protect_activation( WP_PLUGIN_DIR . '/' . self::PRO_PLUGIN ),
			'引数を省略した通常のリクエスト => 中断せず、無効化は admin_init に回す'
		);
		$this->assertNotFalse(
			has_action( 'admin_init', array( \Free_Version_Deactivator::class, 'deactivate_free_on_admin_init' ) ),
			'引数を省略した通常のリクエスト => admin_init に予約される'
		);
	}

	/**
	 * 通知は権限のある利用者にだけ表示し、表示したら消える。
	 */
	public function test_render_deactivated_notice(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者が開く => 通知文が出て、通知の記録は消える',
				'role'                => 'administrator',
				'notice_option'       => '1',
				'expected_contains'   => array(
					esc_html__( 'The Free edition was deactivated because the Pro edition is active.', 'vk-booking-manager' ),
					esc_html__( 'You can keep using the Pro edition as it is.', 'vk-booking-manager' ),
				),
				'expected_remaining'  => false,
			),
			array(
				'test_condition_name' => 'プラグインを有効化できない購読者が開く => 何も出さず、記録は残す',
				'role'                => 'subscriber',
				'notice_option'       => '1',
				'expected_contains'   => array(),
				'expected_remaining'  => '1',
			),
			array(
				'test_condition_name' => '通知の記録が無い => 何も出さない',
				'role'                => 'administrator',
				'notice_option'       => false,
				'expected_contains'   => array(),
				'expected_remaining'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['role'] ) ) );
			delete_option( \Free_Version_Deactivator::NOTICE_OPTION );
			if ( false !== $case['notice_option'] ) {
				update_option( \Free_Version_Deactivator::NOTICE_OPTION, $case['notice_option'] );
			}

			ob_start();
			\Free_Version_Deactivator::render_deactivated_notice();
			$output = (string) ob_get_clean();

			if ( array() === $case['expected_contains'] ) {
				$this->assertSame( '', $output, $case['test_condition_name'] . '（出力）' );
			}
			foreach ( $case['expected_contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . '（出力）' );
			}
			$this->assertSame(
				$case['expected_remaining'],
				get_option( \Free_Version_Deactivator::NOTICE_OPTION, false ),
				$case['test_condition_name'] . '（通知の記録）'
			);
		}
	}

	/**
	 * 通知の2文は、1文目の末尾が ASCII なら半角スペース区切り、非 ASCII（「。」等）ならスペース無しで連結する。
	 */
	public function test_join_sentences(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1文目が英語のピリオド終わり => 半角スペースで連結',
				'sentence_a'          => 'The Free edition was deactivated.',
				'sentence_b'          => 'You can keep using the Pro edition.',
				'expected'            => 'The Free edition was deactivated. You can keep using the Pro edition.',
			),
			array(
				'test_condition_name' => '1文目が日本語の句点終わり => スペース無しで連結',
				'sentence_a'          => '無料版を無効化しました。',
				'sentence_b'          => 'Pro 版をそのままお使いいただけます。',
				'expected'            => '無料版を無効化しました。Pro 版をそのままお使いいただけます。',
			),
			array(
				'test_condition_name' => '1文目が全角の閉じ括弧終わり => スペース無しで連結',
				'sentence_a'          => '無料版を無効化しました（自動）',
				'sentence_b'          => '続きです。',
				'expected'            => '無料版を無効化しました（自動）続きです。',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				\Free_Version_Deactivator::join_sentences( $case['sentence_a'], $case['sentence_b'] ),
				$case['test_condition_name']
			);
		}
	}
}
