<?php
/**
 * Setup_Notices の予約ページURL関連（issue #1049）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 予約ページURLが旧ドメインのままのとき、セットアップ案内で「未設定」と同じ扱いになり、警告が出ることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Reservation_Page_Url_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		$_GET = array();
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * has_reservation_page_url() が、自サイトのURLのときだけ true を返すことを検証する。
	 */
	public function test_has_reservation_page_url(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '未設定 => false',
				'reservation_page_url' => '',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '空白のみ => false',
				'reservation_page_url' => '   ',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '自サイトの絶対URL => true',
				'reservation_page_url' => home_url( '/booking/' ),
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '相対パス => true',
				'reservation_page_url' => '/booking/',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '旧ドメインのURL => 未設定と同じ扱い（false）',
				'reservation_page_url' => 'https://old.example.com/booking/',
				'expected'             => false,
			),
		);

		$method = new \ReflectionMethod( Setup_Notices::class, 'has_reservation_page_url' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array( 'reservation_page_url' => $case['reservation_page_url'] ) );

			$this->assertSame( $case['expected'], $method->invoke( new Setup_Notices() ), $case['test_condition_name'] );
		}
	}

	/**
	 * 旧ドメインのときは初期設定通知の「予約ページURL」の行を重ねて出さず、未設定のときは出すことを検証する。
	 */
	public function test_get_missing_setup_items_for_user(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $admin_id );

		$test_cases = array(
			array(
				'test_condition_name'  => '未設定 => 初期設定通知に出す',
				'reservation_page_url' => '',
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '旧ドメイン => 専用の通知と重複するので出さない',
				'reservation_page_url' => 'https://old.example.com/booking/',
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '自サイトのURL => 出さない',
				'reservation_page_url' => home_url( '/booking/' ),
				'expected'             => false,
			),
		);

		$method = new \ReflectionMethod( Setup_Notices::class, 'get_missing_setup_items_for_user' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array( 'reservation_page_url' => $case['reservation_page_url'] ) );

			$ids = array_column( $method->invoke( new Setup_Notices() ), 'id' );

			$this->assertSame( $case['expected'], in_array( 'reservation_page_url', $ids, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_reservation_page_url_mismatch_notice() が、権限のある利用者に、旧ドメインのときだけ（基本設定のシステム設定タブを除く）警告を出すことを検証する。
	 */
	public function test_render_reservation_page_url_mismatch_notice(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$test_cases = array(
			array(
				'test_condition_name'  => '旧ドメイン・権限あり => 入力中のドメインとサイトのドメインを並べた警告',
				'user_id'              => $admin_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'expect_notice'        => true,
			),
			array(
				'test_condition_name'  => '旧ドメイン・基本設定のシステム設定タブ => 入力欄下の警告と重なるので出さない',
				'user_id'              => $admin_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'screen'               => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'system',
				),
				'expect_notice'        => false,
			),
			array(
				'test_condition_name'  => '旧ドメイン・基本設定の他タブ（email） => 出す',
				'user_id'              => $admin_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'screen'               => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'email',
				),
				'expect_notice'        => true,
			),
			array(
				'test_condition_name'  => '旧ドメイン・基本設定でタブ省略（店舗情報タブが開く） => 出す',
				'user_id'              => $admin_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'screen'               => array( 'page' => 'vkbm-provider-settings' ),
				'expect_notice'        => true,
			),
			array(
				'test_condition_name'  => '旧ドメイン・他の画面で tab=system => 出す',
				'user_id'              => $admin_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'screen'               => array(
					'page' => 'vkbm-shift-dashboard',
					'tab'  => 'system',
				),
				'expect_notice'        => true,
			),
			array(
				'test_condition_name'  => '自サイトのURL・システム設定タブ => 出さない',
				'user_id'              => $admin_id,
				'reservation_page_url' => home_url( '/booking/' ),
				'screen'               => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'system',
				),
				'expect_notice'        => false,
			),
			array(
				'test_condition_name'  => '旧ドメイン・権限なし => 出さない',
				'user_id'              => $subscriber_id,
				'reservation_page_url' => 'https://old.example.com/booking/',
				'expect_notice'        => false,
			),
			array(
				'test_condition_name'  => '自サイトのURL・権限あり => 出さない',
				'user_id'              => $admin_id,
				'reservation_page_url' => home_url( '/booking/' ),
				'expect_notice'        => false,
			),
			array(
				'test_condition_name'  => '未設定・権限あり => 出さない（未設定の案内は別の通知が担当）',
				'user_id'              => $admin_id,
				'reservation_page_url' => '',
				'expect_notice'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			update_option( Settings_Repository::OPTION_KEY, array( 'reservation_page_url' => $case['reservation_page_url'] ) );
			$_GET = $case['screen'] ?? array();

			ob_start();
			( new Setup_Notices() )->render_reservation_page_url_mismatch_notice();
			$output = (string) ob_get_clean();

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'old.example.com', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $output, $case['test_condition_name'] );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}
}
