<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Current_User_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * issue #593: ログアウト後の戻り先は redirect → 予約ページ URL → home_url の順で決まる。
 *
 * @group rest
 */
class Current_User_Controller_Logout_Redirect_Test extends WP_UnitTestCase {
	/**
	 * テスト前の設定値（復元用）。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * ログイン済みユーザーを用意する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, null );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	/**
	 * 設定値を元に戻す。
	 */
	public function tear_down(): void {
		if ( null === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}

		parent::tear_down();
	}

	/**
	 * redirect が有効なら、予約ページ URL が設定されていてもその URL（パラメーター付き）を使う。
	 */
	public function test_valid_redirect_takes_priority_over_reservation_page_url(): void {
		$this->set_reservation_page_url( home_url( '/reservation/' ) );
		$current_page = home_url( '/booking/?vkbm_auth=bookings&foo=bar' );

		$logout_url = $this->fetch_logout_url( $current_page );

		$this->assertSame( $this->expected_logout_url( $current_page ), $logout_url );
		// wp_logout_url() が返す &amp; が残っていないこと。
		$this->assertStringNotContainsString( '&amp;', $logout_url );
	}

	/**
	 * redirect が無ければ予約ページ URL を使う。
	 */
	public function test_reservation_page_url_is_used_when_redirect_is_missing(): void {
		$reservation_page = home_url( '/reservation/' );
		$this->set_reservation_page_url( $reservation_page );

		$this->assertSame( $this->expected_logout_url( $reservation_page ), $this->fetch_logout_url( null ) );
	}

	/**
	 * redirect も予約ページ URL も無ければ home_url を使う。
	 */
	public function test_home_url_is_used_when_nothing_is_available(): void {
		$this->set_reservation_page_url( '' );

		$this->assertSame( $this->expected_logout_url( home_url() ), $this->fetch_logout_url( null ) );
	}

	/**
	 * 外部ドメイン・不正スキームの redirect。
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_invalid_redirects(): array {
		return array(
			'external https'    => array( 'https://evil.example.com/phishing' ),
			'protocol relative' => array( '//evil.example.com/x' ),
			'javascript scheme' => array( 'javascript:alert(1)' ),
		);
	}

	/**
	 * 外部ドメイン等の redirect は採用せず、予約ページ URL も無ければ home_url に置き換わる。
	 *
	 * @dataProvider provide_invalid_redirects
	 *
	 * @param string $redirect 不正な redirect。
	 */
	public function test_invalid_redirect_is_replaced_with_home_url( string $redirect ): void {
		$this->set_reservation_page_url( '' );

		$this->assertSame( $this->expected_logout_url( home_url() ), $this->fetch_logout_url( $redirect ) );
	}

	/**
	 * 外部ドメイン等の redirect は、予約ページ URL が設定されていればそちらに置き換わる。
	 *
	 * @dataProvider provide_invalid_redirects
	 *
	 * @param string $redirect 不正な redirect。
	 */
	public function test_invalid_redirect_falls_back_to_reservation_page_url( string $redirect ): void {
		$reservation_page = home_url( '/reservation/' );
		$this->set_reservation_page_url( $reservation_page );

		$this->assertSame( $this->expected_logout_url( $reservation_page ), $this->fetch_logout_url( $redirect ) );
	}

	/**
	 * 予約ページ URL を設定値へ保存する。
	 *
	 * @param string $url 予約ページ URL。
	 */
	private function set_reservation_page_url( string $url ): void {
		$settings                         = get_option( Settings_Repository::OPTION_KEY, array() );
		$settings                         = is_array( $settings ) ? $settings : array();
		$settings['reservation_page_url'] = $url;
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * 期待するログアウト URL（&amp; を & に戻したもの）を返す。
	 *
	 * @param string $redirect 戻り先。
	 * @return string
	 */
	private function expected_logout_url( string $redirect ): string {
		return str_replace( '&amp;', '&', wp_logout_url( $redirect ) );
	}

	/**
	 * コントローラーから logout_url を取得する。
	 *
	 * @param string|null $redirect redirect パラメーター。null なら指定しない。
	 * @return string
	 */
	private function fetch_logout_url( ?string $redirect ): string {
		// コントローラーを組み立てる。
		$service    = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$controller = new Current_User_Controller( $service );
		// redirect を付けてリクエストを作る。
		$request = new WP_REST_Request( 'GET', '/vkbm/v1/current-user' );
		if ( null !== $redirect ) {
			$request->set_param( 'redirect', $redirect );
		}

		// レスポンスから logout_url を取り出す。
		$data = $controller->get_current_user_flags( $request )->get_data();

		return (string) $data['logout_url'];
	}
}
