<?php
/**
 * BM 設定で入力エラーが出たときの送信値の再表示（Provider_Settings_Page::sanitize_old_input()・render_page()）のテスト。
 *
 * 再表示用の値に含まれない項目は、再表示画面で送信値ではなく保存済みの値が表示され、
 * 利用者がエラーを直して保存し直すと、その項目の変更が知らされないまま元に戻る
 * （リソースタグ検索・シフト自動登録の月数などで発生していた）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 入力エラー時の送信値の再表示を検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_Old_Input_Test extends WP_UnitTestCase {

	/**
	 * 再表示用の値を受け渡す transient のキー（Provider_Settings_Page と同じ値）。
	 */
	private const OLD_INPUT_TRANSIENT = 'vkbm_provider_settings_previous_input';

	/**
	 * 管理者でログインし、設定画面を描画できる状態にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		// 描画するタブはクエリ文字列の tab で決まるため、テストごとにクエリ文字列を初期化する。
		$_GET = array();
	}

	/**
	 * 保存済みの設定と再表示用の値を掃除し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_transient( self::OLD_INPUT_TRANSIENT );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 送信値から再表示用の値を作るとき、保存時と同じルールで値が決まることを検証する。
	 *
	 * あわせて、フォームにある設定項目がすべて再表示用の値に含まれていることを検証する
	 * （項目を追加したときの入れ忘れを検出するため）。
	 */
	public function test_sanitize_old_input(): void {
		// リソースタグ検索は Pro 版限定のため、無料版では保存時と同じく常に OFF になる。
		$resource_tag_search_when_checked = ! Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => '絞り込み検索 ON でリソースタグ検索 ON・自動登録 2ヶ月先まで => 送信値が復元される（無料版ではリソースタグ検索は OFF）',
				'input'               => array(
					'reservation_show_menu_search' => '1',
					'resource_tag_search_enabled'  => '1',
					'shift_auto_register_months'   => '2',
				),
				'expected'            => array(
					'resource_tag_search_enabled' => $resource_tag_search_when_checked,
					'shift_auto_register_months'  => 2,
				),
			),
			array(
				'test_condition_name' => 'リソースタグ検索のチェックなし・自動登録 無効 => OFF・0 が復元される',
				'input'               => array(
					'reservation_show_menu_search' => '1',
					'shift_auto_register_months'   => '0',
				),
				'expected'            => array(
					'resource_tag_search_enabled' => false,
					'shift_auto_register_months'  => 0,
				),
			),
			array(
				'test_condition_name' => '自動登録の上限の 3ヶ月先まで => 3 が復元される',
				'input'               => array(
					'shift_auto_register_months' => '3',
				),
				'expected'            => array(
					'shift_auto_register_months' => 3,
				),
			),
			array(
				'test_condition_name' => '自動登録に選択肢に無い月数（5） => 保存時と同じく 0（無効）になる',
				'input'               => array(
					'shift_auto_register_months' => '5',
				),
				'expected'            => array(
					'shift_auto_register_months' => 0,
				),
			),
			array(
				'test_condition_name' => '自動登録に数値でない値 => 保存時と同じく 0（無効）になる',
				'input'               => array(
					'shift_auto_register_months' => 'abc',
				),
				'expected'            => array(
					'shift_auto_register_months' => 0,
				),
			),
			array(
				'test_condition_name' => '絞り込み検索 OFF でリソースタグ検索 ON を送信 => 保存時と同じくリソースタグ検索は OFF になる',
				'input'               => array(
					'resource_tag_search_enabled' => '1',
				),
				'expected'            => array(
					'resource_tag_search_enabled' => false,
				),
			),
			array(
				'test_condition_name' => '同じく漏れていた項目の上限・既定値 => 保存時と同じ値に丸められる',
				'input'               => array(
					'shift_alert_months'         => '9',
					'design_radius_md'           => '50',
					'provider_slot_step_minutes' => '7',
					'design_primary_color'       => 'red',
					'tax_label_text'             => ' (税込)',
				),
				'expected'            => array(
					'shift_alert_months'         => 4,
					'design_radius_md'           => Settings_Sanitizer::DESIGN_RADIUS_MD_MAX,
					'provider_slot_step_minutes' => 15,
					'design_primary_color'       => '',
					'tax_label_text'             => ' (税込)',
				),
			),
			array(
				'test_condition_name' => '同じく漏れていたチェックボックスがチェックなし => OFF が復元される',
				'input'               => array(),
				'expected'            => array(
					'resource_tag_display_enabled'       => false,
					'provider_allow_staff_overlap_admin' => false,
					'registration_email_verification_enabled' => false,
					'membership_redirect_wp_register'    => false,
					'auth_rate_limit_enabled'            => false,
				),
			),
		);

		$page   = $this->create_page();
		$method = new \ReflectionMethod( Provider_Settings_Page::class, 'sanitize_old_input' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$actual = $method->invoke( $page, $case['input'] );

			// 期待値に挙げた項目だけを取り出して比較する。
			$actual_subset = array();
			foreach ( array_keys( $case['expected'] ) as $key ) {
				$actual_subset[ $key ] = array_key_exists( $key, $actual ) ? $actual[ $key ] : '(再表示用の値に含まれない)';
			}
			$this->assertSame( $case['expected'], $actual_subset, $case['test_condition_name'] );
		}

		// フォームに出力される設定項目（vkbm_provider_settings[項目名]）を実際の描画から集める。
		// タブによって描画される項目が異なる（例: 通貨記号・税表記は「システム」タブ、ライセンスキーは
		// 「ライセンス」タブのときだけ描画される）ため、render_page() のタブ定義の全タブで描画して合算する。
		// 「連携」タブは保存フォームの外に描画され、設定項目を持たないため対象外。
		// 「ライセンス」タブは無料版では存在せず、既定の「店舗」タブとして描画される。
		$form_keys = array();
		foreach ( array( 'store', 'system', 'registration', 'consent', 'design', 'advanced', 'faq', 'license' ) as $tab ) {
			$_GET['tab'] = $tab;
			ob_start();
			$page->render_page();
			$html = (string) ob_get_clean();
			preg_match_all( '/name="vkbm_provider_settings\[([a-z0-9_]+)\]/', $html, $matches );
			$form_keys = array_merge( $form_keys, $matches[1] );
		}
		$_GET = array();
		// ライセンスキーは設定項目ではなく、保存処理の前に取り除かれるため対象外。
		$form_keys = array_diff( array_unique( $form_keys ), array( 'license_key', 'delete_license_key' ) );
		$this->assertNotEmpty( $form_keys, '前提: フォームから設定項目を取得できる' );

		// 何も送信されなかった場合でも、フォームの全項目が再表示用の値に含まれていること。
		$old_input_keys = array_keys( $method->invoke( $page, array() ) );
		$this->assertSame(
			array(),
			array_values( array_diff( $form_keys, $old_input_keys ) ),
			'フォームにあるのに再表示用の値に含まれない項目が無い'
		);
	}

	/**
	 * 入力エラー時は、保存済みの値ではなく送信値で設定画面が表示されることを検証する。
	 */
	public function test_render_page(): void {
		// 保存済み: リソースタグ検索 OFF・自動登録 無効。
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'reservation_show_menu_search' => true,
				'resource_tag_search_enabled'  => false,
				'shift_auto_register_months'   => 0,
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '入力エラーで自動登録 2ヶ月先まで・リソースタグ検索 ON を送信 => 送信値が選択された状態で表示される',
				'input'               => array(
					'reservation_show_menu_search' => '1',
					'resource_tag_search_enabled'  => '1',
					'shift_auto_register_months'   => '2',
				),
				'expected'            => array(
					'shift_auto_register_months'  => '2',
					'resource_tag_search_checked' => true,
				),
			),
			array(
				'test_condition_name' => '入力エラーで自動登録 3ヶ月先まで・リソースタグ検索 OFF を送信 => 送信値が選択された状態で表示される',
				'input'               => array(
					'reservation_show_menu_search' => '1',
					'shift_auto_register_months'   => '3',
				),
				'expected'            => array(
					'shift_auto_register_months'  => '3',
					'resource_tag_search_checked' => false,
				),
			),
			array(
				'test_condition_name' => '入力エラー時の送信値が無い（通常表示） => 保存済みの値で表示される',
				'input'               => null,
				'expected'            => array(
					'shift_auto_register_months'  => '0',
					'resource_tag_search_checked' => false,
				),
			),
		);

		$page   = $this->create_page();
		$method = new \ReflectionMethod( Provider_Settings_Page::class, 'sanitize_old_input' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			// 入力エラー時に handle_form_submission() が行うのと同じく、再表示用の値を transient に置く。
			delete_transient( self::OLD_INPUT_TRANSIENT );
			if ( null !== $case['input'] ) {
				set_transient( self::OLD_INPUT_TRANSIENT, $method->invoke( $page, $case['input'] ), 30 );
			}

			ob_start();
			$page->render_page();
			$html = (string) ob_get_clean();

			// 自動登録の月数: セレクトボックスの範囲だけを切り出し、選択状態の option の value を取り出す。
			preg_match( '/name="vkbm_provider_settings\[shift_auto_register_months\]".*?<\/select>/s', $html, $select_match );
			preg_match( '/<option value="([0-9]+)"\s+selected=\'selected\'/', $select_match[0] ?? '', $selected_match );
			$this->assertSame(
				$case['expected']['shift_auto_register_months'],
				$selected_match[1] ?? '(選択なし)',
				$case['test_condition_name'] . '（自動登録の月数）'
			);

			// リソースタグ検索は Pro 版限定のため、無料版ではチェックボックス自体が表示されない。
			if ( Pro_Upsell::is_free_edition() ) {
				continue;
			}
			// リソースタグ検索: チェックボックスに checked が付いているかを調べる。
			$is_resource_tag_search_checked = (bool) preg_match( '/name="vkbm_provider_settings\[resource_tag_search_enabled\]"\s+value="1"\s+checked=\'checked\'/', $html );
			$this->assertSame(
				$case['expected']['resource_tag_search_checked'],
				$is_resource_tag_search_checked,
				$case['test_condition_name'] . '（リソースタグ検索）'
			);
		}
	}

	/**
	 * 実際の依存関係で設定画面を生成する。
	 *
	 * @return Provider_Settings_Page 設定画面。
	 */
	private function create_page(): Provider_Settings_Page {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		return new Provider_Settings_Page( $service );
	}
}
