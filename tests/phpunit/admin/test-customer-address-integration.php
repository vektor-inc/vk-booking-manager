<?php
/**
 * 住所の設定・プロフィール・本人情報取得の検証。
 *
 * @package VKBookingManager
 */

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Admin\User_Profile_Fields;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Customer_Address;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Current_User_Controller;
use WP_UnitTestCase;

/**
 * 設定とプロフィールの住所連携を検証する。
 *
 * @group customer-address
 */
class Customer_Address_Integration_Test extends WP_UnitTestCase {
	/** 初期化し、他のテストに残らない管理者を用意する。 */
	protected function setUp(): void {
		parent::setUp();
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊はPro版の機能。' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings_Repository::OPTION_KEY, array( 'industry_preset' => 'lodging' ) );
		Industry_Presets::clear_cache();
	}

	/** POST・設定・認証状態を戻す。 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** 初期値オン・オフ・別の設定のみ更新する場合を検証する。 */
	public function test_save_settings(): void {
		$repository = new Settings_Repository();
		$service    = new Settings_Service( $repository, new Settings_Sanitizer() );
		$this->assertTrue( $repository->get_settings()['lodging_address_required'] );
		$test_cases = array(
			array(
				'test_condition_name' => 'オフを保存',
				'input'               => array( 'lodging_address_required' => '0' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別項目だけではオフを維持',
				'input'               => array( 'provider_name' => '宿泊施設' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'オンを保存',
				'input'               => array( 'lodging_address_required' => '1' ),
				'expected'            => true,
			),
		);
		foreach ( $test_cases as $case ) {
			$service->save_settings( $case['input'] );
			$this->assertSame( $case['expected'], $repository->get_settings()['lodging_address_required'], $case['test_condition_name'] );
		}
	}

	/** 管理プロフィールは任意で、長い住所を切り捨てて保存しない。 */
	public function test_save_fields(): void {
		$user_id    = get_current_user_id();
		$fields     = new User_Profile_Fields();
		$test_cases = array(
			array(
				'test_condition_name' => '国内住所',
				'input'               => '東京都1-2',
				'expected'            => '東京都1-2',
				'error'               => false,
			),
			array(
				'test_condition_name' => '海外住所',
				'input'               => '12 Main St, London',
				'expected'            => '12 Main St, London',
				'error'               => false,
			),
			array(
				'test_condition_name' => '256文字で既存住所を維持',
				'input'               => str_repeat( '住', 256 ),
				'expected'            => '12 Main St, London',
				'error'               => true,
			),
			array(
				'test_condition_name' => '必須設定オンでもプロフィールは空欄可',
				'input'               => '',
				'expected'            => '',
				'error'               => false,
			),
		);
		foreach ( $test_cases as $case ) {
			$_POST  = array( 'vkbm_user_address' => $case['input'] );
			$errors = new \WP_Error();
			$fields->validate_address( $errors );
			$fields->save_fields( $user_id );
			$this->assertSame( $case['error'], $errors->has_errors(), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], get_user_meta( $user_id, Customer_Address::USER_META, true ), $case['test_condition_name'] );
		}
		// 他の利用者への権限が無ければ住所も変更しない。
		$other = self::factory()->user->create();
		wp_set_current_user( $other );
		$_POST = array( 'vkbm_user_address' => '権限なし' );
		$fields->save_fields( $user_id );
		$this->assertSame( '', get_user_meta( $user_id, Customer_Address::USER_META, true ) );
	}

	/** 宿泊以外は表示せず、宿泊プロフィールは任意・説明付きにする。 */
	public function test_render_fields(): void {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		foreach ( array(
			'lodging' => true,
			'custom'  => false,
			'salon'   => false,
		) as $preset => $visible ) {
			update_option( Settings_Repository::OPTION_KEY, array( 'industry_preset' => $preset ) );
			Industry_Presets::clear_cache();
			ob_start();
			( new User_Profile_Fields() )->render_fields( wp_get_current_user() );
			$admin_html   = ob_get_clean();
			$profile_html = ( new Auth_Shortcodes( $service ) )->render_profile_form();
			$this->assertSame( $visible, str_contains( $admin_html, 'id="vkbm-user-address"' ), $preset );
			$this->assertSame( $visible, str_contains( $profile_html, 'id="vkbm-profile-address"' ), $preset );
			if ( $visible ) {
				$this->assertStringContainsString( __( 'Address (optional)', 'vk-booking-manager' ), $profile_html );
				$this->assertStringContainsString( 'aria-describedby="vkbm-profile-address-help"', $profile_html );
			}
		}
	}

	/** 高度な設定に宿泊時だけ必須設定を表示する。 */
	public function test_render_page(): void {
		$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
		$_GET = array( 'tab' => 'advanced' );
		foreach ( array(
			'lodging' => true,
			'custom'  => false,
			'salon'   => false,
		) as $preset => $visible ) {
			update_option( Settings_Repository::OPTION_KEY, array( 'industry_preset' => $preset ) );
			Industry_Presets::clear_cache();
			ob_start();
			$page->render_page();
			$html = ob_get_clean();
			$this->assertSame( $visible, str_contains( $html, __( 'Require an address for lodging bookings', 'vk-booking-manager' ) ), $preset );
			$this->assertSame( $visible, str_contains( $html, 'aria-describedby="vkbm-lodging-address-required-help"' ), $preset );
		}
	}

	/** 自分の宿泊住所だけを返し、ゲスト・管理者・宿泊以外には返さない。 */
	public function test_get_current_user_flags(): void {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$controller = new Current_User_Controller( $service );
		$test_cases = array(
			array(
				'test_condition_name' => '本人の宿泊予約',
				'preset'              => 'lodging',
				'proxy'               => false,
				'expected'            => '保存住所',
			),
			array(
				'test_condition_name' => '代理予約の管理者',
				'preset'              => 'lodging',
				'proxy'               => true,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '宿泊以外',
				'preset'              => 'salon',
				'proxy'               => false,
				'expected'            => '',
			),
		);
		foreach ( $test_cases as $case ) {
			$user_id = self::factory()->user->create();
			if ( $case['proxy'] ) {
				get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
			}
			wp_set_current_user( $user_id );
			update_user_meta( $user_id, Customer_Address::USER_META, '保存住所' );
			update_option( Settings_Repository::OPTION_KEY, array( 'industry_preset' => $case['preset'] ) );
			Industry_Presets::clear_cache();
			$response = $controller->get_current_user_flags( new \WP_REST_Request() );
			$this->assertSame( $case['expected'], $response->get_data()['address_full'], $case['test_condition_name'] );
		}
		wp_set_current_user( 0 );
		$this->assertWPError( $controller->get_current_user_flags( new \WP_REST_Request() ) );
	}
	/** フロントのプロフィールも任意で、エラー後に他の入力を保持する。 */
	public function test_handle_form_submission(): void {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$user_id = self::factory()->user->create( array( 'user_email' => 'address-profile@example.com' ) );
		wp_set_current_user( $user_id );
		$old_server                = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$test_cases                = array(
			array(
				'test_condition_name' => '国内住所を保存',
				'input'               => '東京都1-2',
				'expected'            => '東京都1-2',
			),
			array(
				'test_condition_name' => '海外住所を保存',
				'input'               => '12 Main St, London',
				'expected'            => '12 Main St, London',
			),
			array(
				'test_condition_name' => '長すぎる住所は保存せず他の入力を復元',
				'input'               => str_repeat( '住', 256 ),
				'expected'            => '12 Main St, London',
			),
			array(
				'test_condition_name' => '巨大な住所は復元用の1024文字だけ保持',
				'input'               => str_repeat( '住', 100000 ),
				'expected'            => '12 Main St, London',
			),
			array(
				'test_condition_name' => '必須設定オンでもプロフィール空欄可',
				'input'               => '',
				'expected'            => '',
			),
		);
		try {
			foreach ( $test_cases as $case ) {
				$_POST      = array(
					'vkbm_profile_form'  => '1',
					'vkbm_profile_nonce' => wp_create_nonce( 'vkbm_profile_form' ),
					'user_email'         => 'address-profile@example.com',
					'first_name'         => '保持する名前',
					'kana_name'          => 'カナ',
					'phone_number'       => '09012345678',
					'address_full'       => $case['input'],
				);
				// 過去の失敗入力が残っていても、保存成功後に再表示されない。
				set_transient( 'vkbm_profile_input_' . $user_id, array( 'address_full' => '古い訂正入力' ), 300 );
				$shortcodes = new Address_Test_Auth_Shortcodes( $service );
				$shortcodes->handle_form_submission();
				$this->assertSame( $case['expected'], get_user_meta( $user_id, Customer_Address::USER_META, true ), $case['test_condition_name'] );
				if ( mb_strlen( $case['input'] ) <= 255 ) {
					$this->assertFalse( get_transient( 'vkbm_profile_input_' . $user_id ), '保存成功時に復元入力を削除' );
				}
				if ( mb_strlen( $case['input'] ) > 255 ) {
					$_POST = array();
					$html  = ( new Address_Test_Auth_Shortcodes( $service ) )->render_profile_form();
					$this->assertStringContainsString( 'value="保持する名前"', $html );
					$restored = get_transient( 'vkbm_profile_input_' . $user_id );
					$this->assertFalse( $restored, '表示後に復元入力を削除' );
					$expected_input = mb_strlen( $case['input'] ) > 1024 ? str_repeat( '住', 1024 ) : $case['input'];
					$this->assertStringContainsString( 'value="' . $expected_input . '"', $html );
				}
			}
		} finally {
			$_SERVER = $old_server;
		}
	}
}
