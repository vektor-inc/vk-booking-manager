<?php
/**
 * Customer_Flag_Settings_Page::enqueue_assets() のテスト。
 *
 * 会員フラグの設定画面でだけ、管理画面用 CSS（vkbm-admin）が読み込まれることを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Customers\Customer_Flag_Settings_Page;
use WP_UnitTestCase;
use function get_plugin_page_hookname;
use function wp_dequeue_style;
use function wp_set_current_user;
use function wp_style_is;

/**
 * @group customers
 */
class Customer_Flag_Settings_Page_Assets_Test extends WP_UnitTestCase {
	/**
	 * 設定画面のフック名でだけ CSS が読み込まれることを確認する。
	 */
	public function test_enqueue_assets_loads_admin_style_only_on_own_page(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Common_Styles() )->register_styles();
		wp_dequeue_style( Common_Styles::ADMIN_HANDLE );

		$page = new Customer_Flag_Settings_Page();
		$page->register_menu();

		// 別の管理画面では読み込まない。
		$page->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ), '別の画面では読み込まないこと' );

		// この設定画面のフック名では読み込む。
		$page->enqueue_assets( get_plugin_page_hookname( Customer_Flag_Settings_Page::MENU_SLUG, 'vkbm-provider-settings' ) );
		$this->assertTrue( wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ), 'この画面では読み込むこと' );

		wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
	}
}
