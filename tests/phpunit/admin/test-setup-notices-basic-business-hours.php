<?php
/**
 * Setup_Notices の「基本営業時間」未登録通知（宿泊では必須にしない。issue #607）のテスト.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 業種プリセットが宿泊のときだけ、基本営業時間の未登録通知が出ないことを検証する。
 *
 * @group admin
 */
class Setup_Notices_Basic_Business_Hours_Test extends WP_UnitTestCase {
	/**
	 * 基本営業時間の通知が持つ、BM設定の該当欄へのリンク。
	 */
	private const BASIC_HOURS_ANCHOR = '#vkbm-basic-business-hours';

	/**
	 * 事業者名の通知が持つ、BM設定の該当欄へのリンク。
	 */
	private const PROVIDER_NAME_ANCHOR = '#vkbm-provider-name';

	/**
	 * 管理者でログインし、保存済みの設定を空にする。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
	}

	/**
	 * 保存済みの設定・画面・ユーザーを元に戻し、他のテストへ影響しないようにする。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 業種ごとに、基本営業時間の通知が出る／出ないを検証する。
	 * 無料版には業種プリセットが無く、保存値が宿泊でも custom 扱いになるため、宿泊でも通知が出る。
	 */
	public function test_render_notices_basic_business_hours(): void {
		// @pro-gate-ignore render_notices: markTestSkipped ではなく、無料版では通知が出る・Pro 版では宿泊だけ出ない、を分岐して両方で検証しているため対象外とする。
		$test_cases = array(
			array(
				'test_condition_name'  => '宿泊で基本営業時間が未登録 => Pro 版は通知が出ない（無料版は出る）',
				'industry_preset'      => 'lodging',
				'expected_notice_pro'  => false,
				'expected_notice_free' => true,
			),
			array(
				'test_condition_name'  => 'カスタムで基本営業時間が未登録 => 通知が出る',
				'industry_preset'      => 'custom',
				'expected_notice_pro'  => true,
				'expected_notice_free' => true,
			),
			array(
				'test_condition_name'  => 'サロンで基本営業時間が未登録 => 通知が出る',
				'industry_preset'      => 'salon',
				'expected_notice_pro'  => true,
				'expected_notice_free' => true,
			),
			array(
				'test_condition_name'  => 'アクティビティで基本営業時間が未登録 => 通知が出る',
				'industry_preset'      => 'activity',
				'expected_notice_pro'  => true,
				'expected_notice_free' => true,
			),
			array(
				'test_condition_name'  => 'セミナーで基本営業時間が未登録 => 通知が出る',
				'industry_preset'      => 'seminar',
				'expected_notice_pro'  => true,
				'expected_notice_free' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['industry_preset'], 'テスト店舗', array() );

			$expected = Pro_Upsell::is_free_edition() ? $case['expected_notice_free'] : $case['expected_notice_pro'];

			$this->assertSame( $expected, str_contains( $this->render_notices(), self::BASIC_HOURS_ANCHOR ), $case['test_condition_name'] );
		}
	}

	/**
	 * 基本営業時間が登録済みなら、宿泊でもそれ以外でも通知は出ないことを検証する。
	 */
	public function test_render_notices_basic_business_hours_registered(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊で基本営業時間が登録済み => 通知が出ない',
				'industry_preset'     => 'lodging',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'カスタムで基本営業時間が登録済み => 通知が出ない',
				'industry_preset'     => 'custom',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings(
				$case['industry_preset'],
				'テスト店舗',
				array(
					array(
						'start' => '09:00',
						'end'   => '18:00',
					),
				)
			);

			$this->assertSame( $case['expected'], str_contains( $this->render_notices(), self::BASIC_HOURS_ANCHOR ), $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊で基本営業時間の通知が外れても、ほかの必須項目（事業者名）が未設定ならそちらの通知は出ることを検証する。
	 */
	public function test_render_notices_other_required_items_remain(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊で事業者名が未設定 => 事業者名の通知が出る',
				'industry_preset'     => 'lodging',
				'provider_name'       => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'カスタムで事業者名が未設定 => 事業者名の通知が出る',
				'industry_preset'     => 'custom',
				'provider_name'       => '',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊で事業者名が設定済み => 事業者名の通知は出ない',
				'industry_preset'     => 'lodging',
				'provider_name'       => 'テスト旅館',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['industry_preset'], $case['provider_name'], array() );

			$this->assertSame( $case['expected'], str_contains( $this->render_notices(), self::PROVIDER_NAME_ANCHOR ), $case['test_condition_name'] );
		}
	}

	/**
	 * 設定を保存し、業種プリセットのキャッシュを捨てる。
	 *
	 * @param string                           $industry_preset 業種プリセットのキー.
	 * @param string                           $provider_name   事業者名.
	 * @param array<int, array<string,string>> $basic_hours     基本営業時間.
	 */
	private function save_settings( string $industry_preset, string $provider_name, array $basic_hours ): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				$defaults,
				array(
					'industry_preset'               => $industry_preset,
					'provider_name'                 => $provider_name,
					'provider_business_hours_basic' => $basic_hours,
				)
			)
		);
		Industry_Presets::clear_cache();
	}

	/**
	 * 初期設定チェック通知を描画して出力を返す。
	 *
	 * @return string
	 */
	private function render_notices(): string {
		ob_start();
		( new Setup_Notices() )->render_notices();
		return (string) ob_get_clean();
	}
}
