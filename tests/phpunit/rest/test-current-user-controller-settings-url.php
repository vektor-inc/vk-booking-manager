<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Current_User_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * issue #822: ヘッダーの「BM 設定」リンク用 settings_url は、BM 設定画面を開ける権限を持つユーザーにだけ返す。
 *
 * @group rest
 */
class Current_User_Controller_Settings_Url_Test extends WP_UnitTestCase {
	/**
	 * 権限（MANAGE_PROVIDER_SETTINGS）を持つユーザーには BM 設定画面の URL を返す。
	 */
	public function test_settings_url_is_returned_for_user_who_can_manage_provider_settings(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $user_id );

		$data = $this->fetch_data();

		$this->assertSame( admin_url( 'admin.php?page=vkbm-provider-settings' ), $data['settings_url'] );
	}

	/**
	 * 権限が無いユーザーには URL を渡さない（空文字）。
	 */
	public function test_settings_url_is_empty_for_user_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$data = $this->fetch_data();

		$this->assertSame( '', $data['settings_url'] );
	}

	/**
	 * コントローラーの応答データを取得する。
	 *
	 * @return array<string, mixed>
	 */
	private function fetch_data(): array {
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$controller = new Current_User_Controller( $service );
		$request    = new WP_REST_Request( 'GET', '/vkbm/v1/current-user' );

		return $controller->get_current_user_flags( $request )->get_data();
	}
}
