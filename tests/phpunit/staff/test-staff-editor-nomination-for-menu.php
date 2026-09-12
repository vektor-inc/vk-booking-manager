<?php
/**
 * Staff_Editor::is_nomination_enabled_for_menu() のテスト（#391）。
 *
 * サービスメニュー単位の指名機能設定を追加したことに伴う判定ヘルパーの挙動を検証する。
 * サイト全体の指名機能設定（vkbm_provider_settings['staff_enabled']）と、
 * メニュー投稿メタ（_vkbm_disable_nomination）の組み合わせで、
 * 既存メニュー（メタ未設定）が従来どおりサイト全体の設定と等価に判定されることを担保する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Staff;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * Staff_Editor_Nomination_For_Menu_Test のテストクラス。
 *
 * @group staff
 * @group nomination
 */
class Staff_Editor_Nomination_For_Menu_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * テスト後に全体設定・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * サイト全体の指名機能スイッチを更新する。
	 *
	 * @param bool $enabled 指名機能を有効にする場合は true。
	 */
	private function set_site_wide_nomination( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * is_nomination_enabled_for_menu() が、サイト全体の設定とメニュー単位の設定を
	 * 正しく組み合わせて判定することを検証する。
	 *
	 * Free 版では指名機能自体が常に無効なため、常に false を返す（メニュー単位メタは無視される）。
	 * Pro 版では、サイト全体OFF時は常に false。サイト全体ON時のみメニュー単位メタを見る。
	 */
	public function test_is_nomination_enabled_for_menu(): void {
		$is_free = Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => 'サイト全体OFF・メニュー単位メタ未設定 => false（正常系：サイト全体が優先）',
				'site_wide'           => false,
				'menu_disabled'       => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイト全体OFF・メニュー単位で明示的に「使う」に設定 => false（サイト全体OFFが最優先）',
				'site_wide'           => false,
				'menu_disabled'       => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイト全体ON・メニュー単位メタ未設定 => true（正常系：既定は「使う」で#391導入前と等価）',
				'site_wide'           => true,
				'menu_disabled'       => null,
				'expected'            => ! $is_free,
			),
			array(
				'test_condition_name' => 'サイト全体ON・メニュー単位で明示的に無効化 => false（境界値：メニュー単位の上書き）',
				'site_wide'           => true,
				'menu_disabled'       => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイト全体ON・メニュー単位で明示的に「使う」(false) => true（既定と同じ挙動）',
				'site_wide'           => true,
				'menu_disabled'       => false,
				'expected'            => ! $is_free,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site_wide_nomination( $case['site_wide'] );

			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);

			if ( null !== $case['menu_disabled'] ) {
				update_post_meta( $menu_id, '_vkbm_disable_nomination', $case['menu_disabled'] );
			}

			$actual = Staff_Editor::is_nomination_enabled_for_menu( $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * メニューIDを特定できない（0以下）場合はサイト全体の設定にそのまま従うことを検証する。
	 *
	 * 基本設定画面など、メニューという文脈が存在しない呼び出し元からの利用を想定している。
	 */
	public function test_is_nomination_enabled_for_menu_without_menu_id(): void {
		$is_free = Pro_Upsell::is_free_edition();

		$test_cases = array(
			array(
				'test_condition_name' => 'サイト全体ON・menu_id=0 => is_nomination_enabled() と同じ結果（正常系）',
				'site_wide'           => true,
				'menu_id'             => 0,
				'expected'            => ! $is_free,
			),
			array(
				'test_condition_name' => 'サイト全体OFF・menu_id=0 => false（正常系）',
				'site_wide'           => false,
				'menu_id'             => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サイト全体ON・menu_id=負値 => is_nomination_enabled() と同じ結果（境界値）',
				'site_wide'           => true,
				'menu_id'             => -1,
				'expected'            => ! $is_free,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site_wide_nomination( $case['site_wide'] );

			$actual = Staff_Editor::is_nomination_enabled_for_menu( $case['menu_id'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
