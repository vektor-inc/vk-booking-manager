<?php
/**
 * issue #512: 予約ブロックの JS（view.js）が読み込めない・失敗した場合に画面が真っ白に
 * なる不具合のフォールバック（render_block フィルタ）のテスト。
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
use WP_UnitTestCase;

/**
 * Reservation_Block::inject_login_fallback_markup() のテスト。
 *
 * @group blocks
 */
class Reservation_Block_Login_Fallback_Test extends WP_UnitTestCase {
	/**
	 * issue #512 レビュー対応: $_GET / $_POST / $_SERVER['REQUEST_METHOD'] は
	 * 他のテスト（本ファイル内・他ファイル問わず）が書き換えたまま復元し損ねている
	 * 可能性があるグローバル状態のため、各テストの実行前に必ずここで
	 * 「未ログイン・GET・パラメータ無し」の既知の状態へ揃えてから、各テストが
	 * 必要な分だけ上書きする。setUp/tearDown はテストごとに毎回呼ばれるため、
	 * 前のテストの残骸に依存しないテストになる。
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
	 * 既知の状態へ戻す（良い意味での「お行儀」。setUp() 側の防御と対になる）。
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
	 * 差し戻し対応（司の指摘）: 本プラグインはブートストラップ時（vk-booking-manager.php）
	 * で自前の Reservation_Block インスタンスを1つ生成し、その render_block フィルタは
	 * PHPUnit プロセス全体を通して登録されたまま残る。render_block はフィルタチェーンの
	 * ため、ここで用意する「テスト専用インスタンス」のフィルタを単純に追加するだけでは
	 * 両方が同じ呼び出しで発火し、
	 * - ブートストラップ側のインスタンスが保持する Auth_Shortcodes は `init` フックで
	 *   一度だけ handle_form_submission() が実行済みで、このテストメソッドが後から
	 *   設定する $_POST とは無関係の（＝エラー無しの）状態のまま固定されている
	 * - かつ登録順（ブートストラップ側が先）により、その「エラー無し」の出力が先に
	 *   確定してしまい、テスト用インスタンス側の正しい判定結果が上書きされない
	 * という二重の問題が起きる（実際に出力へフォールバック markup が2組出ていた）。
	 * そのため、このヘルパーの実行中だけ 'render_block' に登録済みの全フィルタを
	 * remove_all_filters() で外し、テスト用インスタンスのフィルタだけが動く状態にする。
	 * $wp_filter を直接書き換えて退避・復元する実装（WordPress.WP.GlobalVariablesOverride
	 * 違反）にはしない。WP_UnitTestCase（vendor/wp-phpunit/wp-phpunit/includes/
	 * abstract-testcase.php の _backup_hooks()/_restore_hooks()）が各テストメソッドの
	 * 前後で $wp_filter 等のフック関連グローバルを自動的にバックアップ・復元するため、
	 * ここで remove_all_filters() しても本テストメソッド終了後には
	 * ブートストラップ側インスタンスの登録を含む元の状態へ自動的に戻る
	 * （tests/phpunit/resources/test-resource-delete-guard.php の get_instance_property()
	 * のコメントも同じ前提に触れている）。
	 *
	 * @param Auth_Shortcodes $auth_shortcodes フィルタが参照する Auth_Shortcodes。
	 * @return string レンダリング後の HTML。
	 */
	private function render_reservation_block( Auth_Shortcodes $auth_shortcodes ): string {
		$block = new Reservation_Block( $auth_shortcodes );
		$block->register_block();

		remove_all_filters( 'render_block' );

		// register() のうち render_block フィルタだけを対象に、他の副作用
		// （enqueue 系フック等）を避けて直接フィルタを追加する。
		add_filter( 'render_block', array( $block, 'inject_login_fallback_markup' ), 10, 2 );

		// 実ページで保存されるのと同じ形（useBlockProps.save() が出力する wrapper div を
		// 含むブロックコメント）でレンダリングする。自己終了形式（`/-->`）は保存済み HTML を
		// 含まず、wrapper div そのものが存在しない状態になってしまい実ページと異なる。
		$saved_html = '<div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div>';
		$output     = do_blocks( '<!-- wp:vk-booking-manager/reservation -->' . $saved_html . '<!-- /wp:vk-booking-manager/reservation -->' );

		remove_filter( 'render_block', array( $block, 'inject_login_fallback_markup' ), 10 );

		return $output;
	}

	/**
	 * ログイン導線（GET ?vkbm_auth=login）で、未ログイン・エラー無しなら
	 * 「フォームを読み込み中…」と隠し案内・スクリプトは出るが、エラー欄・
	 * data-vkbm-login-error 属性は出ないこと。
	 */
	public function test_login_get_without_error_shows_loading_and_hidden_hint_only(): void {
		$_GET = array( 'vkbm_auth' => 'login' );

		$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes = new Auth_Shortcodes( $service );

		$output = $this->render_reservation_block( $auth_shortcodes );

		// PHPUnit 実行時はサイトの言語をja に切り替えるため（rules/testing/phpunit.md）、
		// 翻訳可能な文言はハードコードした英語ではなく __() 経由の期待値と突き合わせる。
		$this->assertStringContainsString( __( 'Loading form…', 'vk-booking-manager' ), $output );
		$this->assertStringContainsString( 'vkbm-alert__info', $output );
		$this->assertStringContainsString( 'hidden', $output );
		$this->assertStringContainsString( '<script', $output );
		$this->assertStringContainsString( 'vkbm_native_login=1', $output );
		$this->assertStringNotContainsString( 'vkbm-alert__danger', $output );
		$this->assertStringNotContainsString( 'data-vkbm-login-error', $output );
	}

	/**
	 * ログイン POST が失敗した同一リクエストでは、data-vkbm-login-error 属性に
	 * 確定したコード（auth_failed）が wrapper div（wp-block-vk-booking-manager-reservation
	 * クラスを持つ要素）に入り、エラー欄にも統一文言が出ること。Cookie を経由しない
	 * （同一リクエスト内の Auth_Shortcodes インスタンスの状態から直接読む）ことを確認する。
	 */
	public function test_login_post_failure_embeds_error_code_and_message(): void {
		$user_id = $this->factory()->user->create(
			array(
				'user_login' => 'reservation_block_login_user',
				'user_pass'  => 'CorrectPass123!',
			)
		);

		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'vkbm_login_form'  => '1',
				'vkbm_login_nonce' => wp_create_nonce( 'vkbm_login_form' ),
				'log'              => 'reservation_block_login_user',
				'pwd'              => 'WrongPassword!!',
			);

			$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$auth_shortcodes = new Auth_Shortcodes( $service );
			// process_login_request() を先に実行し、同一リクエスト内で
			// login_errors を確定させる（実運用では init フックで先に走る）。
			$auth_shortcodes->handle_form_submission();

			$output = $this->render_reservation_block( $auth_shortcodes );

			// wrapper div（wp-block-vk-booking-manager-reservation クラス）に付いている
			// ことまで確認する。属性値のクォート文字（'/"）は WP_HTML_Tag_Processor の
			// 実装詳細のため、引用符の種類は問わない。同様に、新規属性を追加する際の
			// 挿入位置（class より前か後か）も WP_HTML_Tag_Processor::set_attribute() の
			// 実装詳細のため、両方のlookaheadで class・data-vkbm-login-error の
			// 有無だけを個別に確認し、タグ内の出現順序は問わない。
			$this->assertSame(
				1,
				preg_match(
					'/<div(?=[^>]*\sclass="[^"]*wp-block-vk-booking-manager-reservation[^"]*")(?=[^>]*\sdata-vkbm-login-error=([\'"])auth_failed\1)[^>]*>/',
					$output
				),
				'wrapper div（wp-block-vk-booking-manager-reservation）に data-vkbm-login-error="auth_failed" が付与されていること: ' . $output
			);
			$this->assertStringContainsString(
				__( 'Username or password is incorrect.', 'vk-booking-manager' ),
				$output
			);
			$this->assertStringContainsString( 'vkbm-alert__danger', $output );
			// PHPUnit 実行時はサイトの言語をja に切り替えるため（rules/testing/phpunit.md）、
			// 翻訳可能な文言はハードコードした英語ではなく __() 経由の期待値と突き合わせる。
			$this->assertStringContainsString( __( 'Loading form…', 'vk-booking-manager' ), $output );
		} finally {
			wp_delete_user( $user_id );
		}
	}

	/**
	 * ログイン導線でも無くログインPOSTでも無い通常の閲覧時は、フォールバック
	 * markup を一切追加しない（保存済みブロック HTML のまま）こと。
	 */
	public function test_normal_browsing_context_adds_no_fallback_markup(): void {
		// setUp() で GET・パラメータ無しの状態は既に整っているため、追加設定は無し。
		$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes = new Auth_Shortcodes( $service );

		$output = $this->render_reservation_block( $auth_shortcodes );

		$this->assertStringNotContainsString( 'Loading form', $output );
		$this->assertStringNotContainsString( 'data-vkbm-login-error', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * ログイン中のユーザーには、ログイン導線であってもフォールバック markup を
	 * 追加しない（ログイン済みでは予約ブロックが別の内容を描画するため）こと。
	 */
	public function test_logged_in_user_gets_no_fallback_markup(): void {
		$_GET = array( 'vkbm_auth' => 'login' );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$service         = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$auth_shortcodes = new Auth_Shortcodes( $service );

		$output = $this->render_reservation_block( $auth_shortcodes );

		$this->assertStringNotContainsString( 'Loading form', $output );
		$this->assertStringNotContainsString( 'data-vkbm-login-error', $output );
	}
}
