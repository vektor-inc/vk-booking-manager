<?php
/**
 * BM 設定 > よくある質問の「ログアウトすると予約画面にメニューが表示されない場合」（#1154）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Rest_Api_Check;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * よくある質問の項目に、通知のリンク先のアンカー・除外に書く行・wp/v2 を除外しない注意が出ることを検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_SiteGuard_Faq_Test extends WP_UnitTestCase {

	/**
	 * render_siteguard_rest_api_help(): 項目の見出し・除外に書く行・SiteGuard の設定へのリンク・注意を出力する。
	 */
	public function test_render_siteguard_rest_api_help(): void {
		$page   = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ), Capabilities::MANAGE_PROVIDER_SETTINGS );
		$method = new \ReflectionMethod( Provider_Settings_Page::class, 'render_siteguard_rest_api_help' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $page );
		$html = (string) ob_get_clean();

		$test_cases = array(
			array(
				'test_condition_name' => '見出しに通知の「詳しい手順」リンクの行き先のアンカーを付ける',
				'needle'              => '<dt id="' . SiteGuard_Rest_Api_Check::FAQ_ANCHOR_ID . '">',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '除外に書く行（コードの値）を出す',
				'needle'              => '<code>' . SiteGuard_Rest_Api_Check::get_required_exclude_line() . '</code>',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'wp/v2 を除外しない注意を出す',
				'needle'              => '<code>wp/v2</code>',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'SiteGuard の設定画面へのリンクを出す',
				'needle'              => 'href="' . esc_url( SiteGuard_Rest_Api_Check::get_settings_url() ) . '"',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '除外に wp/v2 の行を書くよう案内しない（#1153 より前の 3 行）',
				'needle'              => 'wp/v2/vkbm_',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], str_contains( $html, $case['needle'] ), $case['test_condition_name'] );
		}
	}
}
