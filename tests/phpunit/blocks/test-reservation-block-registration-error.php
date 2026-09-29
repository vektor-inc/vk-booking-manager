<?php
/**
 * issue #516: 会員登録エラー時、入力値・エラー文を含む約1.3KBの
 * `vkbm_registration_errors` Cookie を廃止し、同一リクエスト内で発行したランダム
 * トークンだけを wrapper の data-vkbm-registration-error-key 属性へ埋め込む
 * render_block フィルタ（Reservation_Block::inject_registration_error_attribute()）の
 * テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Tests\Auth\Testable_Auth_Shortcodes;
use WP_UnitTestCase;

/**
 * Reservation_Block::inject_registration_error_attribute() のテスト。
 *
 * @group blocks
 */
class Reservation_Block_Registration_Error_Test extends WP_UnitTestCase {
	/**
	 * $_GET / $_POST / $_SERVER['REQUEST_METHOD'] は他のテスト（本ファイル内・他ファイル
	 * 問わず）が書き換えたまま復元し損ねている可能性があるグローバル状態のため、各テストの
	 * 実行前に必ず「未ログイン・GET・パラメータ無し」の既知の状態へ揃えてから、各テストが
	 * 必要な分だけ上書きする（tests/phpunit/blocks/test-reservation-block-login-fallback.php
	 * と同じ方針）。
	 */
	protected function setUp(): void {
		parent::setUp();

		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );
	}

	/**
	 * このテストが書き換えたグローバル状態を、後続の他のテストへ持ち越さないように
	 * 既知の状態へ戻す。
	 */
	protected function tearDown(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * 予約ブロックの保存済み HTML（実際にエディタが保存する wrapper div を含む）を
	 * レンダリングし、render_block フィルタが通った後の HTML を返す。
	 *
	 * tests/phpunit/blocks/test-reservation-block-login-fallback.php の
	 * render_reservation_block() と同じ理由（ブートストラップ側インスタンスとの二重発火
	 * 回避）で、実行中だけ 'render_block' に登録済みの全フィルタを外し、テスト用
	 * インスタンスの inject_registration_error_attribute() だけが動く状態にする。
	 *
	 * @param Auth_Shortcodes $auth_shortcodes フィルタが参照する Auth_Shortcodes。
	 * @return string レンダリング後の HTML。
	 */
	private function render_reservation_block( Auth_Shortcodes $auth_shortcodes ): string {
		$block = new Reservation_Block( $auth_shortcodes );
		$block->register_block();

		remove_all_filters( 'render_block' );

		add_filter( 'render_block', array( $block, 'inject_registration_error_attribute' ), 10, 2 );

		// 実ページで保存されるのと同じ形（useBlockProps.save() が出力する wrapper div を
		// 含むブロックコメント）でレンダリングする。
		$saved_html = '<div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div>';
		$output     = do_blocks( '<!-- wp:vk-booking-manager/reservation -->' . $saved_html . '<!-- /wp:vk-booking-manager/reservation -->' );

		remove_filter( 'render_block', array( $block, 'inject_registration_error_attribute' ), 10 );

		return $output;
	}

	/**
	 * 会員登録 POST が失敗した同一リクエストでは、wrapper div
	 * （wp-block-vk-booking-manager-reservation クラスを持つ要素）に
	 * data-vkbm-registration-error-key 属性が付き、その値が
	 * Auth_Shortcodes::get_current_registration_error_token() が返すトークンと
	 * 一致すること。Cookie を経由しない（同一リクエスト内のインスタンスの状態から
	 * 直接読む）ことを確認する。
	 */
	public function test_registration_post_failure_embeds_error_key(): void {
		// Ensure registration is enabled for this test. / テスト用にユーザー登録を有効化。
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		$this->factory()->user->create(
			array(
				'user_login' => 'reservation_block_register_user',
				'user_email' => 'reservation_block_register_user@example.com',
			)
		);

		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'vkbm_registration_form'      => '1',
				'vkbm_registration_nonce'     => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'                  => 'another_new_user',
				// 既存メールと重複させ、確実にエラーを発生させる。
				'user_email'                  => 'reservation_block_register_user@example.com',
				'user_pass'                   => 'password123',
				'user_pass_confirm'           => 'password123',
				'kana_name'                   => 'たろう',
				'phone_number'                => '090-0000-0000',
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			);

			$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$auth_shortcodes = new Auth_Shortcodes( $service );
			// process_registration_request() を先に実行し、同一リクエスト内で
			// registration_error_token を確定させる（実運用では init フックで先に走る）。
			$auth_shortcodes->handle_form_submission();

			$token = $auth_shortcodes->get_current_registration_error_token();
			$this->assertNotSame( '', $token, '前提: このリクエストでトークンが発行されていること。' );

			$output = $this->render_reservation_block( $auth_shortcodes );

			// 属性値のクォート文字（'/"）は WP_HTML_Tag_Processor の実装詳細のため、
			// 引用符の種類は問わない。
			$this->assertSame(
				1,
				preg_match(
					'/<div(?=[^>]*\sclass="[^"]*wp-block-vk-booking-manager-reservation[^"]*")(?=[^>]*\sdata-vkbm-registration-error-key=([\'"])' . preg_quote( $token, '/' ) . '\1)[^>]*>/',
					$output
				),
				'wrapper div（wp-block-vk-booking-manager-reservation）に data-vkbm-registration-error-key="' . $token . '" が付与されていること: ' . $output
			);

			// issue #516 完了条件1: 入力値・エラー文そのものは Cookie は元より、
			// 属性・HTML のどこにも直接出てこない（トークンだけが渡る）。
			$this->assertStringNotContainsString( 'another_new_user', $output );
			$this->assertArrayNotHasKey( 'vkbm_registration_errors', $_COOKIE );
		} finally {
			update_option( 'users_can_register', $original_registration );
		}
	}

	/**
	 * 会員登録が成功した同一リクエストでは、エラートークンは発行されないため、
	 * data-vkbm-registration-error-key 属性も付かないこと。
	 */
	public function test_registration_post_success_adds_no_error_key(): void {
		$original_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );

		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'vkbm_registration_form'      => '1',
				'vkbm_registration_nonce'     => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'                  => 'successful_new_user',
				'user_email'                  => 'successful_new_user@example.com',
				'user_pass'                   => 'password123',
				'user_pass_confirm'           => 'password123',
				'kana_name'                   => 'たろう',
				'phone_number'                => '090-0000-0000',
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			);

			// 登録成功時は末尾で redirect_and_exit() が呼ばれるため、テストプロセスを
			// 終了させないよう Testable_Auth_Shortcodes（exit() を呼ばないよう
			// オーバーライド済み。tests/phpunit/auth/test-auth-shortcodes.php 参照）を使う。
			$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$auth_shortcodes = new Testable_Auth_Shortcodes( $service );
			$auth_shortcodes->handle_form_submission();

			$this->assertSame(
				'',
				$auth_shortcodes->get_current_registration_error_token(),
				'前提: 登録成功時はトークンが発行されないこと。'
			);

			$output = $this->render_reservation_block( $auth_shortcodes );

			$this->assertStringNotContainsString( 'data-vkbm-registration-error-key', $output );
		} finally {
			$user = get_user_by( 'login', 'successful_new_user' );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
			update_option( 'users_can_register', $original_registration );
		}
	}

	/**
	 * 会員登録導線でも無い通常の閲覧時は、属性を一切追加しない（保存済みブロック HTML の
	 * まま）こと。
	 */
	public function test_normal_browsing_context_adds_no_registration_error_key(): void {
		// setUp() で GET・パラメータ無しの状態は既に整っているため、追加設定は無し。
		$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes = new Auth_Shortcodes( $service );

		$output = $this->render_reservation_block( $auth_shortcodes );

		$this->assertStringNotContainsString( 'data-vkbm-registration-error-key', $output );
	}

	/**
	 * ログイン中のユーザーには、会員登録 POST であっても属性を追加しない（ログイン済みでは
	 * 予約ブロックが別の内容を描画するため）こと。
	 */
	public function test_logged_in_user_gets_no_registration_error_key(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'vkbm_registration_form' => '1' );

		$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes = new Auth_Shortcodes( $service );

		$output = $this->render_reservation_block( $auth_shortcodes );

		$this->assertStringNotContainsString( 'data-vkbm-registration-error-key', $output );
	}
}
