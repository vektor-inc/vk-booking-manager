<?php
/**
 * BM 設定画面の業種プリセットの選択肢（Provider_Settings_Page::render_page()）のテスト。
 *
 * Pro 版では選択肢に「宿泊」が出て、無料版では選択欄そのものが出ない（Pro 版への案内に差し替わる）ことを確かめる（#577）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 業種プリセットの選択肢を検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_Industry_Preset_Options_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインし、設定画面を描画できる状態にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
	}

	/**
	 * 保存済みの設定を掃除し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 設定画面の業種プリセットの選択肢が、Pro 版では5つ（宿泊を含む）、無料版では選択欄なしになることを検証する。
	 */
	public function test_industry_preset_options_on_provider_settings_page(): void {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$page    = new Provider_Settings_Page( $service );

		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();

		// 選択欄（select）の範囲だけを切り出す。
		preg_match( '/<select id="vkbm-industry-preset".*?<\/select>/s', $html, $select_match );
		$select_html = $select_match[0] ?? '';
		preg_match_all( '/<option value="([^"]*)"/', $select_html, $option_matches );

		// 無料版の案内を含め、業種プリセット行は事業者情報タブの先頭へ表示する。
		$industry_row_position  = strpos( $html, '<tr class="vkbm-provider-settings__tab-store vkbm-industry-preset-row">' );
		$provider_name_position = strpos( $html, 'id="vkbm-provider-name"' );
		$this->assertNotFalse( $industry_row_position, '業種プリセット行は事業者情報タブに属する' );
		$this->assertNotFalse( $provider_name_position, '事業者名の入力欄が出る' );
		$this->assertLessThan( $provider_name_position, $industry_row_position, '業種プリセット行は事業者名より前に出る' );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->assertSame( '', $select_html, '無料版では業種プリセットの選択欄が出ない' );
			$this->assertStringNotContainsString( 'value="lodging"', $html, '無料版では宿泊の選択肢が出ない' );
			return;
		}

		$this->assertSame(
			array( 'custom', 'salon', 'activity', 'seminar', 'lodging' ),
			$option_matches[1],
			'Pro 版の選択肢に宿泊が末尾に加わる（既存の選択肢の順序は変わらない）'
		);

		// 移動後も自動設定の対象はシステム設定タブであり、保存後に反映されることを案内する。
		$this->assertStringContainsString( esc_html__( 'Other items are configured automatically in the "System settings" tab.', 'vk-booking-manager' ), $html );
		$this->assertStringContainsString( esc_html__( 'Save the industry preset to apply the changes to that tab.', 'vk-booking-manager' ), $html );
		$this->assertStringContainsString( esc_html__( 'These items are automatically saved with these values and cannot be changed individually in the "System settings" tab.', 'vk-booking-manager' ), $html );
	}

	/**
	 * 基本営業時間の欄の説明文が、宿泊のときだけ宿泊用の文に差し替わり、時間枠専用の項目に注記が付くことを検証する（issue #607・#743）。
	 * 宿泊では既存の文は出ず、宿泊以外では宿泊用の文は出ない。欄自体はどの業種でも表示される。
	 */
	public function test_basic_business_hours_description(): void {
		// @pro-gate-ignore supports: markTestSkipped ではなく、無料版では既存の文・Pro 版では宿泊だけ宿泊用の文、を分岐して両方で検証しているため対象外とする。
		// 実行時のロケール（翻訳の有無）に依らず比較できるよう、画面と同じ翻訳関数で期待値を作る。
		$lodging_text = esc_html__( 'This setting is not used for the availability of lodging plans.', 'vk-booking-manager' ) . esc_html__( ' If you also use time slot plans, such as day-use baths or private baths, register the time ranges here.', 'vk-booking-manager' ) . esc_html__( ' The registered time ranges become the reservation slots of the time slot plans.', 'vk-booking-manager' );
		// 時間枠のプラン専用の項目（予約スロットの時間・サービス後バッファ）に付く注記（#743）。
		$slot_only_note = esc_html__( 'This setting applies only to time slot plans.', 'vk-booking-manager' ) . esc_html__( ' It is not used for lodging plans.', 'vk-booking-manager' );
		$standard_text  = esc_html__( 'Register the business hours that are common to the entire facility.', 'vk-booking-manager' );
		$defaults       = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊 => Pro 版は宿泊用の文だけが出る（無料版は既存の文だけ）',
				'industry_preset'     => 'lodging',
				'expected_lodging'    => true,
				'expected_standard'   => false,
				'expected_note_count' => 2,
			),
			array(
				'test_condition_name' => 'カスタム => 既存の文だけが出る',
				'industry_preset'     => 'custom',
				'expected_lodging'    => false,
				'expected_standard'   => true,
				'expected_note_count' => 0,
			),
			array(
				'test_condition_name' => 'サロン => 既存の文だけが出る',
				'industry_preset'     => 'salon',
				'expected_lodging'    => false,
				'expected_standard'   => true,
				'expected_note_count' => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $case['industry_preset'] ) ) );
			Industry_Presets::clear_cache();

			$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$page    = new Provider_Settings_Page( $service );

			ob_start();
			$page->render_page();
			$html = (string) ob_get_clean();

			// 無料版は業種に依らず既存の文だけが出る。
			$is_free           = Pro_Upsell::is_free_edition();
			$expected_lodging  = $is_free ? false : $case['expected_lodging'];
			$expected_standard = $is_free ? true : $case['expected_standard'];

			$this->assertSame( $expected_lodging, str_contains( $html, $lodging_text ), $case['test_condition_name'] . '（宿泊用の文）' );
			$this->assertSame( $expected_standard, str_contains( $html, $standard_text ), $case['test_condition_name'] . '（既存の文）' );

			// 時間枠専用の注記は、Pro 版の宿泊のときだけ、予約スロットの時間とサービス後バッファの2か所に出る。
			$expected_note_count = $is_free ? 0 : $case['expected_note_count'];
			$this->assertSame( $expected_note_count, substr_count( $html, $slot_only_note ), $case['test_condition_name'] . '（時間枠専用の注記の数）' );
		}
	}

	/**
	 * 無料版では、宿泊を保存済みでも宿泊の機能が無効（custom として扱われる）ことを検証する。
	 */
	public function test_lodging_is_not_available_on_free_edition_even_if_saved(): void {
		// @pro-gate-ignore supports: markTestSkipped ではなく、無料版では false・Pro 版では true を分岐して両方で検証しているため対象外とする。
		// @pro-gate-ignore get_current_preset: markTestSkipped ではなく、無料版では custom・Pro 版では lodging を分岐して両方で検証しているため対象外とする。
		$defaults = ( new Settings_Repository() )->get_default_settings();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => 'lodging' ) ) );
		Industry_Presets::clear_cache();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->assertSame( 'custom', Industry_Presets::get_current_preset( array( 'industry_preset' => 'lodging' ), $defaults ), '無料版は常に custom' );
			$this->assertFalse( Industry_Presets::supports( 'lodging' ), '無料版は宿泊の機能が無効' );
			return;
		}

		$this->assertSame( 'lodging', Industry_Presets::get_current_preset( array( 'industry_preset' => 'lodging' ), $defaults ) );
		$this->assertTrue( Industry_Presets::supports( 'lodging' ) );
	}
}
