<?php
/**
 * 設定画面の「予約の受付方法」の行・ユーザー登録設定タブの注意・メール未設定の注意（#958）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 受付方法の設定行の位置と選択状態、ユーザー登録設定タブの注意、保存後の注意を検証する。
 *
 * @group admin
 */
class Provider_Settings_Page_Booking_Customer_Mode_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインする。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 保存済みの設定・クエリ文字列・設定エラーを掃除する。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET                          = array();
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト間で設定エラーの蓄積を掃除する。
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 指定のタブを描画して HTML を返す。
	 *
	 * @param string $tab タブのスラッグ。
	 * @return string 描画結果の HTML。
	 */
	private function render_tab( string $tab ): string {
		$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );

		$_GET = array( 'tab' => $tab );
		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * システム設定タブで、受付方法の行が「予約受付期間」と「予約ステータス」の間にあり、保存値が選択されていることを検証する。
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '保存されていない => member が選択されている',
				'settings'            => array(),
				'expected_selected'   => 'member',
			),
			array(
				'test_condition_name' => '保存値が guest => guest が選択されている',
				'settings'            => array( 'booking_customer_mode' => 'guest' ),
				'expected_selected'   => 'guest',
			),
			array(
				'test_condition_name' => '保存値が both => both が選択されている',
				'settings'            => array( 'booking_customer_mode' => 'both' ),
				'expected_selected'   => 'both',
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$html = $this->render_tab( 'system' );

			$this->assertSame( 1, preg_match( '/<option value="' . $case['expected_selected'] . '"[^>]*selected=\'selected\'/', $html ), $case['test_condition_name'] );
			$this->assertSame( 3, preg_match_all( '/<option value="(?:member|guest|both)"/', $html ), $case['test_condition_name'] . '（選択肢は3つ）' );

			$position_deadline = strpos( $html, 'id="vkbm-provider-max-advance-booking-days"' );
			$position_mode     = strpos( $html, 'id="vkbm-provider-booking-customer-mode"' );
			$position_status   = strpos( $html, 'id="vkbm-provider-booking-status-mode"' );
			$this->assertTrue( false !== $position_deadline && false !== $position_mode && false !== $position_status, $case['test_condition_name'] . '（3つの行が出力される）' );
			$this->assertTrue( $position_deadline < $position_mode && $position_mode < $position_status, $case['test_condition_name'] . '（受付期間と予約ステータスの間）' );

			// 予約画面がこの設定を読むようになったため、「まだ反映されません」の一時表示は出ない。
			$this->assertStringNotContainsString( 'vkbm-booking-customer-mode-pending-note', $html, $case['test_condition_name'] . '（一時表示は出ない）' );
		}
	}

	/**
	 * ユーザー登録設定タブの注意が、「会員のみ」以外のときだけ出て、受付方法の行へのリンクを持つことを検証する。
	 */
	public function test_render_page_registration_notice(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'member => 注意なし',
				'settings'            => array( 'booking_customer_mode' => 'member' ),
				'expected_notice'     => false,
				'expected_text'       => '',
			),
			array(
				'test_condition_name' => '保存されていない => 注意なし',
				'settings'            => array(),
				'expected_notice'     => false,
				'expected_text'       => '',
			),
			array(
				'test_condition_name' => 'guest => 注意あり（ゲストのみ）',
				'settings'            => array( 'booking_customer_mode' => 'guest' ),
				'expected_notice'     => true,
				'expected_text'       => 'data-booking-customer-mode="guest"',
			),
			array(
				'test_condition_name' => 'both => 注意あり（どちらでも）',
				'settings'            => array( 'booking_customer_mode' => 'both' ),
				'expected_notice'     => true,
				'expected_text'       => 'data-booking-customer-mode="both"',
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$html = $this->render_tab( 'registration' );

			$has_notice = false !== strpos( $html, 'vkbm-booking-customer-mode-notice' );
			$this->assertSame( $case['expected_notice'], $has_notice, $case['test_condition_name'] );

			if ( $case['expected_notice'] ) {
				$this->assertStringContainsString( 'tab=system#vkbm-provider-booking-customer-mode', $html, $case['test_condition_name'] . '（受付方法の行へのリンク）' );
				$this->assertStringContainsString( $case['expected_text'], $html, $case['test_condition_name'] . '（モードごとの注意）' );

				// 注意は、このタブの最初の行（会員登録の設定より前）に出る。
				$this->assertTrue(
					strpos( $html, 'vkbm-booking-customer-mode-notice' ) < strpos( $html, 'name="vkbm_users_can_register"' ),
					$case['test_condition_name'] . '（タブの先頭）'
				);
			}
		}
	}

	/**
	 * 「ゲストのみ」の注意が、2文目で「ログインとユーザー登録の欄は表示されません」と言い切ることを検証する。
	 */
	public function test_registration_notice_for_guest_only_is_definite(): void {
		$method = new ReflectionMethod( Provider_Settings_Page::class, 'get_booking_customer_mode_registration_notice' );
		$method->setAccessible( true );

		$notice = (string) $method->invoke( null, 'guest' );

		$this->assertStringContainsString( __( 'Currently, the reservation acceptance method is "Guests only".', 'vk-booking-manager' ), $notice, '1文目は現在の受付方法' );
		$this->assertStringContainsString( __( 'The login and user registration fields are not shown on the reservation screen.', 'vk-booking-manager' ), $notice, '2文目は言い切り' );
		$this->assertStringNotContainsString( __( 'Once this setting is reflected on the reservation screen, the login and user registration fields will not be shown there.', 'vk-booking-manager' ), $notice, '「反映されると」という仮の言い方は残さない' );
	}

	/**
	 * 保存後の注意が、「会員のみ」以外で送信元メールアドレスが未設定のときだけ積まれることを検証する。
	 */
	public function test_maybe_notify_booking_customer_mode_mail_from(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'member・送信元未設定 => 注意なし',
				'settings'            => array( 'booking_customer_mode' => 'member' ),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'guest・送信元未設定 => 注意あり',
				'settings'            => array( 'booking_customer_mode' => 'guest' ),
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => 'both・送信元が空白だけ => 注意あり',
				'settings'            => array(
					'booking_customer_mode' => 'both',
					'mail_from_email'       => '  ',
				),
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => 'guest・送信元設定済み => 注意なし',
				'settings'            => array(
					'booking_customer_mode' => 'guest',
					'mail_from_email'       => 'info@example.com',
				),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'guest・送信元未設定・vkbm_mail_from_email フィルター登録あり => 注意なし',
				'settings'            => array( 'booking_customer_mode' => 'guest' ),
				'with_filter'         => true,
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'both・送信元が空白だけ・vkbm_mail_from_email フィルター登録あり => 注意なし',
				'settings'            => array(
					'booking_customer_mode' => 'both',
					'mail_from_email'       => '  ',
				),
				'with_filter'         => true,
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト間で設定エラーの蓄積を掃除する。
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );

			// 送信元をフィルターで設定済みのケースだけ、フィルターを登録する（ケースの最後に外す）。
			$filter = static function () {
				return 'filtered@example.com';
			};
			if ( ! empty( $case['with_filter'] ) ) {
				add_filter( 'vkbm_mail_from_email', $filter );
			}
			$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );

			$method = new ReflectionMethod( Provider_Settings_Page::class, 'maybe_notify_booking_customer_mode_mail_from' );
			$method->setAccessible( true );
			$method->invoke( $page );

			remove_filter( 'vkbm_mail_from_email', $filter );

			$found = null;
			foreach ( get_settings_errors( 'vkbm-provider-settings' ) as $error ) {
				if ( 'vkbm_provider_settings_booking_customer_mode_mail_from' === $error['code'] ) {
					$found = $error;
				}
			}

			$this->assertSame( $case['expected_notice'], null !== $found, $case['test_condition_name'] );

			if ( null !== $found ) {
				$this->assertSame( 'warning', $found['type'], $case['test_condition_name'] . '（種別）' );
				$this->assertStringContainsString( 'tab=email#vkbm-mail-from-email', $found['message'], $case['test_condition_name'] . '（メール設定タブへのリンク）' );
			}
		}
	}
}
