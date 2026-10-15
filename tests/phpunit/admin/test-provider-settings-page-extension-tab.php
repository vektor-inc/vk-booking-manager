<?php
/**
 * BM 設定の拡張タブ（決済プラグインなどが vkbm_provider_settings_allowed_tabs で追加するタブ）の描画テスト。
 *
 * 拡張タブでは、本体の保存フォーム（nonce・設定の入力欄・「変更を保存」ボタン）を出力せず、
 * 拡張プラグインが vkbm_provider_settings_extra_content で出力する内容だけを表示することを確かめる。
 * 本体のフォームを出すと、中身の無いフォームと「変更を保存」ボタンが拡張タブに表示されてしまう
 * （「連携」タブ（issue #475）と同じ考え方）。
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
use function add_action;
use function add_filter;
use function add_settings_error;
use function ob_get_clean;
use function ob_start;
use function remove_action;
use function remove_filter;
use function wp_set_current_user;

/**
 * 拡張タブの描画テスト。
 *
 * @group admin
 */
class Provider_Settings_Page_Extension_Tab_Test extends WP_UnitTestCase {

	/**
	 * テスト用の拡張タブのスラッグ。
	 */
	private const EXTENSION_TAB = 'vkbm-test-extension';

	/**
	 * 拡張タブの中身として出力する目印。
	 */
	private const EXTENSION_MARKER = '<div id="vkbm-test-extension-content">テスト用の拡張タブの中身</div>';

	/**
	 * 本体の保存フォームの nonce フィールド名。
	 */
	private const NONCE_FIELD = 'name="vkbm_provider_settings_nonce"';

	/**
	 * 本体の設定の入力欄の name 属性の先頭部分。
	 */
	private const SETTINGS_FIELD = 'name="vkbm_provider_settings[';

	/**
	 * 本体の「変更を保存」ボタン（submit_button() の既定の id）。
	 */
	private const SAVE_BUTTON = 'id="submit"';

	/**
	 * 本体の設定画面の通知（settings_errors()）として出す目印。
	 */
	private const NOTICE_MARKER = 'テスト用の設定画面の通知';

	/**
	 * 拡張タブを許可するフィルターのコールバック（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $allowed_tabs_filter = null;

	/**
	 * 拡張タブの中身を出力するアクションのコールバック（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $extra_content_action = null;

	/**
	 * 管理者でログインし、テスト用の拡張タブを登録する。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();

		// 決済プラグインと同じ方法で、テスト用の拡張タブを許可する。
		$this->allowed_tabs_filter = static function ( array $allowed_tabs ): array {
			$allowed_tabs[] = self::EXTENSION_TAB;
			return $allowed_tabs;
		};
		add_filter( 'vkbm_provider_settings_allowed_tabs', $this->allowed_tabs_filter );

		// 拡張タブを表示しているときだけ、目印を出力する。
		$this->extra_content_action = static function ( string $active_tab ): void {
			if ( self::EXTENSION_TAB === $active_tab ) {
				echo self::EXTENSION_MARKER; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テスト用の固定文字列。
			}
		};
		add_action( 'vkbm_provider_settings_extra_content', $this->extra_content_action );
	}

	/**
	 * 登録したフックとクエリ文字列を元へ戻す。
	 */
	protected function tearDown(): void {
		remove_filter( 'vkbm_provider_settings_allowed_tabs', $this->allowed_tabs_filter );
		remove_action( 'vkbm_provider_settings_extra_content', $this->extra_content_action );
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 指定したタブで設定画面を描画し、出力を返す。
	 *
	 * @param string $tab 表示するタブ。
	 * @return string 描画結果。
	 */
	private function render_settings_page( string $tab ): string {
		$page = new Provider_Settings_Page(
			new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ),
			'manage_options',
			''
		);

		$_GET['tab'] = $tab;

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * タブごとに、本体の保存フォームと拡張タブの中身が出し分けられること。
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '拡張タブを表示 => 本体の nonce・入力欄・「変更を保存」ボタンは出ず、拡張タブの中身と通知は出る',
				'tab'                 => self::EXTENSION_TAB,
				'expected'            => array(
					'main_form'         => false,
					'extension_content' => true,
					'notice'            => true,
				),
			),
			array(
				'test_condition_name' => '「事業者情報」タブを表示 => 本体の保存フォームが出て、拡張タブの中身は出ない',
				'tab'                 => 'store',
				'expected'            => array(
					'main_form'         => true,
					'extension_content' => false,
					'notice'            => true,
				),
			),
			array(
				'test_condition_name' => '「詳細設定」タブを表示 => 本体の保存フォームが出て、拡張タブの中身は出ない',
				'tab'                 => 'advanced',
				'expected'            => array(
					'main_form'         => true,
					'extension_content' => false,
					'notice'            => true,
				),
			),
			array(
				'test_condition_name' => '許可されていないタブを指定 => 「事業者情報」タブとして本体の保存フォームが出る',
				'tab'                 => 'unknown-tab',
				'expected'            => array(
					'main_form'         => true,
					'extension_content' => false,
					'notice'            => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存後などに出る本体の通知を用意する（どのタブでも表示されること）。
			add_settings_error( 'vkbm-provider-settings', 'vkbm_test_notice', self::NOTICE_MARKER, 'updated' );

			$output = $this->render_settings_page( $case['tab'] );

			// 本体の保存フォームを構成する 3 要素（nonce・設定の入力欄・「変更を保存」ボタン）を確かめる。
			$this->assertSame( $case['expected']['main_form'], str_contains( $output, self::NONCE_FIELD ), $case['test_condition_name'] . '（本体の nonce）' );
			$this->assertSame( $case['expected']['main_form'], str_contains( $output, self::SETTINGS_FIELD ), $case['test_condition_name'] . '（本体の設定の入力欄）' );
			$this->assertSame( $case['expected']['main_form'], str_contains( $output, self::SAVE_BUTTON ), $case['test_condition_name'] . '（「変更を保存」ボタン）' );
			$this->assertSame( $case['expected']['extension_content'], str_contains( $output, self::EXTENSION_MARKER ), $case['test_condition_name'] . '（拡張タブの中身）' );
			$this->assertSame( $case['expected']['notice'], str_contains( $output, self::NOTICE_MARKER ), $case['test_condition_name'] . '（本体の通知）' );

			// 次のケースへ通知を持ち越さないよう消しておく。
			global $wp_settings_errors;
			$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストの後片付け。
		}
	}
}
