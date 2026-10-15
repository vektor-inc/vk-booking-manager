<?php
/**
 * 設定画面の「サービスメニュー詳細ページの表示方法」の注意文の表示条件（#635）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 予約ページURLが使えないときの注意文が、「予約ブロック内で表示」を選んでいるときだけ見えることを検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_Detail_Display_Mode_Notice_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 保存済みの設定とクエリ文字列を掃除する。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * システムタブを描画し、注意文の有無・hidden の有無・導線を検証する。
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'block 選択・予約ページURL未設定 => 注意文を表示（hidden なし）',
				'settings'            => array( 'service_menu_detail_display_mode' => 'block' ),
				'expected_exists'     => true,
				'expected_hidden'     => false,
			),
			array(
				'test_condition_name' => 'template 選択・予約ページURL未設定 => 注意文は出すが隠す（切り替えで表示）',
				'settings'            => array( 'service_menu_detail_display_mode' => 'template' ),
				'expected_exists'     => true,
				'expected_hidden'     => true,
			),
			array(
				'test_condition_name' => 'block 選択・予約ページURLが自サイト外 => 注意文を表示',
				'settings'            => array(
					'service_menu_detail_display_mode' => 'block',
					'reservation_page_url'             => 'https://other.example.org/booking/',
				),
				'expected_exists'     => true,
				'expected_hidden'     => false,
			),
			array(
				'test_condition_name' => 'block 選択・予約ページURLが自サイト => 注意文なし',
				'settings'            => array(
					'service_menu_detail_display_mode' => 'block',
					'reservation_page_url'             => home_url( '/booking/' ),
				),
				'expected_exists'     => false,
				'expected_hidden'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );

			$_GET = array( 'tab' => 'system' );
			ob_start();
			$page->render_page();
			$html = (string) ob_get_clean();
			$_GET = array();

			$exists = 1 === preg_match( '/<p class="description vkbm-detail-display-mode-notice" id="vkbm-service-menu-detail-display-mode-notice"([^>]*)>/', $html, $matches );
			$this->assertSame( $case['expected_exists'], $exists, $case['test_condition_name'] );

			if ( $exists ) {
				$this->assertSame( $case['expected_hidden'], false !== strpos( $matches[1], 'hidden' ), $case['test_condition_name'] . '（hidden）' );
				$this->assertStringContainsString( 'href="#vkbm-reservation-page-url"', $html, $case['test_condition_name'] . '（予約ページURL欄への導線）' );
			}
		}
	}
}
