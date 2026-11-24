<?php
/**
 * Setup_Notices::render_siteguard_rest_api_notice()（SiteGuard が予約画面の通信を止めているときの通知、#1154）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Rest_Api_Check;
use WP_UnitTestCase;

/**
 * 通知を出す条件（SiteGuard の状態・権限・開いている画面）と、通知の中身を検証する。
 *
 * @group admin
 */
class Setup_Notices_SiteGuard_Rest_Api_Test extends WP_UnitTestCase {

	/**
	 * テスト後に画面の引数・設定値・ログイン状態を戻す。
	 */
	public function tear_down(): void {
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 指定の権限を持つユーザーでログインする。
	 *
	 * @param bool $can_manage_settings BM 設定を管理できるか。
	 */
	private function log_in( bool $can_manage_settings ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		if ( $can_manage_settings ) {
			get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		} else {
			get_user_by( 'id', $user_id )->remove_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		}
		wp_set_current_user( $user_id );
	}

	/**
	 * 通知を描画して HTML を返す。
	 *
	 * @param bool $siteguard_active SiteGuard が有効化されているか。
	 * @return string 出力された HTML。
	 */
	private function render( bool $siteguard_active ): string {
		$notices = new Setup_Notices( new SiteGuard_Rest_Api_Check( $siteguard_active ) );

		ob_start();
		$notices->render_siteguard_rest_api_notice();

		return (string) ob_get_clean();
	}

	/**
	 * render_siteguard_rest_api_notice(): 止められていて、BM 設定を管理できる人が、よくある質問タブ以外を開いているときだけ出し、
	 * 出した通知には除外に書く行・SiteGuard の設定へのボタン・よくある質問へのリンクがある。
	 */
	public function test_render_siteguard_rest_api_notice(): void {
		$blocking = array(
			'block_author_query_enable' => '1',
			'disable_restapi_enable'    => '1',
			'disable_restapi_exclude'   => 'oembed,contact-form-7,akismet',
		);

		$test_cases = array(
			array(
				'test_condition_name' => '止められている・管理できる人・ダッシュボード => 出す',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '止められている・管理できる人・BM 設定のほかのタブ => 出す',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'email',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '止められている・管理できる人・よくある質問タブ => 同じ手順が画面にあるので出さない',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'faq',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '止められている・SiteGuard の設定画面を GET で開いた => 出す（その場で行をコピーできる）',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array( 'page' => 'siteguard_author_query' ),
				'method'              => 'GET',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '止められている（保存前の値）・SiteGuard の設定画面へ POST で送信した表示 => 保存前の値で判定してしまうので出さない',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array( 'page' => 'siteguard_author_query' ),
				'method'              => 'POST',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '止められている・BM 設定へ POST で送信した表示 => 出す（SiteGuard の画面以外は送信でも隠さない）',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array( 'page' => 'vkbm-provider-settings' ),
				'method'              => 'POST',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '止められている・BM 設定を管理できない人 => 出さない',
				'active'              => true,
				'option'              => $blocking,
				'can_manage'          => false,
				'get'                 => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '除外に vkbm/v1 がある => 出さない（直すと消える）',
				'active'              => true,
				'option'              => array_merge( $blocking, array( 'disable_restapi_exclude' => 'oembed,contact-form-7,akismet,vkbm/v1' ) ),
				'can_manage'          => true,
				'get'                 => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「ユーザー名漏えい防御」が無効 => 出さない',
				'active'              => true,
				'option'              => array_merge( $blocking, array( 'block_author_query_enable' => '0' ) ),
				'can_manage'          => true,
				'get'                 => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'SiteGuard が無い（無効化して設定値だけ残っている） => 出さない',
				'active'              => false,
				'option'              => $blocking,
				'can_manage'          => true,
				'get'                 => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( SiteGuard_Rest_Api_Check::OPTION_NAME, $case['option'] );
			$this->log_in( $case['can_manage'] );
			$_GET                      = $case['get'];
			$_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'GET';

			$html = $this->render( $case['active'] );

			$this->assertSame( $case['expected'], str_contains( $html, 'id="vkbm-siteguard-rest-api-notice"' ), $case['test_condition_name'] );
		}

		// 出した通知の中身: 除外に書く行（読み取り専用欄とコピーボタン）・SiteGuard の設定へのボタン・よくある質問へのリンクがあり、閉じるボタンが無い。
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		update_option(
			SiteGuard_Rest_Api_Check::OPTION_NAME,
			array(
				'block_author_query_enable' => '1',
				'disable_restapi_enable'    => '1',
				'disable_restapi_exclude'   => 'oembed',
			)
		);
		$this->log_in( true );

		$html = $this->render( true );

		$markup_cases = array(
			array(
				'test_condition_name' => '除外に書く行を読み取り専用欄に入れる',
				'needle'              => 'value="' . SiteGuard_Rest_Api_Check::get_required_exclude_line() . '" readonly',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'コピーボタンが欄を指す',
				'needle'              => 'data-vkbm-copy-target="vkbm-siteguard-exclude-line"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'コピー結果を読み上げる欄がある',
				'needle'              => 'aria-live="polite"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'SiteGuard の設定画面へのボタン',
				'needle'              => 'href="' . esc_url( admin_url( 'admin.php?page=siteguard_author_query' ) ) . '"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'よくある質問の項目へのリンク',
				'needle'              => 'href="' . esc_url( SiteGuard_Rest_Api_Check::get_faq_url() ) . '"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '閉じるボタンは無い',
				'needle'              => 'vkbm-notice__dismiss',
				'expected'            => false,
			),
		);

		foreach ( $markup_cases as $case ) {
			$this->assertSame( $case['expected'], str_contains( $html, $case['needle'] ), $case['test_condition_name'] );
		}

		// コピーボタンのスクリプトを、通知を出したときに読み込む。
		$this->assertTrue( wp_script_is( 'vkbm-admin-copy-field', 'enqueued' ) );
	}
}
