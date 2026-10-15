<?php
/**
 * ログアウト時の予約ページへの転送を、画面遷移を伴わない処理の中では行わないことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

/**
 * issue #696: AJAX・REST API・WP-CLI・WP-Cron の処理中に wp_logout で転送して exit すると、
 * 他のプラグインがプログラムからログアウトさせる処理など、呼び出し元の処理が途中で止まる。
 *
 * @group logout
 */
class Logout_Redirect_Suppression_Test extends WP_UnitTestCase {

	/**
	 * 転送してよい種類のリクエストかの判定。
	 */
	public function test_vkbm_should_redirect_on_logout(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '通常の画面からのログアウト => 転送してよい',
				'conditions'          => array( false, false, false, false ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'AJAX の処理中 => 転送しない',
				'conditions'          => array( true, false, false, false ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'REST API の処理中 => 転送しない',
				'conditions'          => array( false, true, false, false ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'WP-CLI の処理中 => 転送しない',
				'conditions'          => array( false, false, true, false ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'WP-Cron の処理中 => 転送しない',
				'conditions'          => array( false, false, false, true ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '複数に当てはまる（REST API かつ WP-Cron） => 転送しない',
				'conditions'          => array( false, true, false, true ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				vkbm_should_redirect_on_logout( ...$case['conditions'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * wp_logout の実行中に、AJAX・WP-Cron の処理中であれば転送も exit もせず、呼び出し元に処理が戻ること。
	 *
	 * 転送は wp_redirect フィルターで例外にして検出する（本物の転送は exit でテストごと止まるため）。
	 * REST API（定数 REST_REQUEST）と WP-CLI（定数 WP_CLI）は定数のため、判定の関数側のテストで確かめる。
	 */
	public function test_wp_logout_redirect_in_background_requests(): void {
		$reservation_url = home_url( '/reservation/' );
		update_option( 'vkbm_provider_settings', array( 'reservation_page_url' => $reservation_url ) );
		$_REQUEST = array();

		$throw_on_redirect = static function ( $location ) {
			throw new RuntimeException( (string) $location );
		};
		add_filter( 'wp_redirect', $throw_on_redirect );

		$test_cases = array(
			array(
				'test_condition_name' => '通常の画面からのログアウト => 予約ページへ転送しようとする',
				'conditions'          => array( 'filter' => '' ),
				'expected'            => $reservation_url,
			),
			array(
				'test_condition_name' => 'AJAX の処理中 => 転送せず処理が戻る',
				'conditions'          => array( 'filter' => 'wp_doing_ajax' ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'WP-Cron の処理中 => 転送せず処理が戻る',
				'conditions'          => array( 'filter' => 'wp_doing_cron' ),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( '' !== $case['conditions']['filter'] ) {
				add_filter( $case['conditions']['filter'], '__return_true' );
			}

			$redirected_to = null;
			try {
				do_action( 'wp_logout', 0 );
			} catch ( RuntimeException $e ) {
				$redirected_to = $e->getMessage();
			}

			if ( '' !== $case['conditions']['filter'] ) {
				remove_filter( $case['conditions']['filter'], '__return_true' );
			}

			$this->assertSame( $case['expected'], $redirected_to, $case['test_condition_name'] );
		}

		remove_filter( 'wp_redirect', $throw_on_redirect );
		delete_option( 'vkbm_provider_settings' );
	}
}
