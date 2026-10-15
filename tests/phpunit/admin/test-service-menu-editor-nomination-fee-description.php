<?php
/**
 * サービスメニュー編集画面の「指名料／客室ごとの追加料金」行の説明文のテスト（#736）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 指名料の無効化チェックボックスの説明文と aria-describedby のテスト。
 *
 * @group admin
 */
class Service_Menu_Editor_Nomination_Fee_Description_Test extends WP_UnitTestCase {

	/**
	 * Setup で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * テスト後に全体設定・キャッシュ・ログイン状態を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 基本設定メタボックスの描画結果を、業種プリセット別に検証する。
	 */
	public function test_nomination_fee_description(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトでは指名料・客室ごとの追加料金の行を出さない',
				'preset'              => 'lodging',
				'expects_row'         => false,
			),
			array(
				'test_condition_name' => '宿泊でないサイトでは指名料の説明文が出る',
				'preset'              => 'salon',
				'expects_row'         => true,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'lodging' === $case['preset'] && Pro_Upsell::is_free_edition() ) {
				continue;
			}

			Industry_Presets::clear_cache();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					( new Settings_Repository() )->get_default_settings(),
					array( 'industry_preset' => $case['preset'] )
				)
			);
			Staff_Editor::clear_nomination_enabled_cache();

			wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
			$post = get_post(
				$this->factory()->post->create(
					array(
						'post_type'   => Service_Menu_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				)
			);

			// 基本設定メタボックスを描画し、説明文と aria-describedby の有無を確かめる。
			ob_start();
			( new Service_Menu_Editor() )->render_basic_meta_box( $post );
			$html = (string) ob_get_clean();

			if ( ! $case['expects_row'] ) {
				$this->assertStringNotContainsString( 'vkbm-disable-nomination-fee-field', $html, $case['test_condition_name'] );
				$this->assertStringNotContainsString( 'disable_nomination_fee', $html, $case['test_condition_name'] );
				continue;
			}

			$this->assertStringContainsString( 'id="vkbm-disable-nomination-fee-description"', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( 'aria-describedby="vkbm-disable-nomination-fee-description"', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( 'nominates one.', $html, $case['test_condition_name'] );
		}
	}
}
