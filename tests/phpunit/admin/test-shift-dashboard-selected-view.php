<?php
/**
 * シフト・予約表で選択する日表示・月表示のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * シフト・予約表の表示指定と業種別の既定値を検証する。
 *
 * @group admin
 * @group lodging
 */
class Shift_Dashboard_Selected_View_Test extends WP_UnitTestCase {

	/**
	 * 元の設定。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * 元の GET パラメータ。
	 *
	 * @var array
	 */
	private $original_get;

	/**
	 * テスト前の設定と GET パラメータを保存する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト後に元の状態へ戻すための退避。
	}

	/**
	 * 設定と GET パラメータをテスト前の状態へ戻す。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}

		Industry_Presets::clear_cache();
		$_GET = $this->original_get;
		parent::tearDown();
	}

	/**
	 * 有効な表示指定を優先し、指定が無いか不正な場合だけ業種別の既定値を返すことを検証する。
	 *
	 * @return void
	 */
	public function test_get_selected_view(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊の表示既定値は Pro 版で検証する。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊以外・表示指定なし => 日表示',
				'preset'              => 'custom',
				'view'                => null,
				'expected'            => 'day',
			),
			array(
				'test_condition_name' => '宿泊・表示指定なし => 月表示',
				'preset'              => 'lodging',
				'view'                => null,
				'expected'            => 'month',
			),
			array(
				'test_condition_name' => '宿泊以外・日表示を明示 => 日表示',
				'preset'              => 'custom',
				'view'                => 'day',
				'expected'            => 'day',
			),
			array(
				'test_condition_name' => '宿泊以外・月表示を明示 => 月表示',
				'preset'              => 'custom',
				'view'                => 'month',
				'expected'            => 'month',
			),
			array(
				'test_condition_name' => '宿泊・日表示を明示 => 日表示',
				'preset'              => 'lodging',
				'view'                => 'day',
				'expected'            => 'day',
			),
			array(
				'test_condition_name' => '宿泊・月表示を明示 => 月表示',
				'preset'              => 'lodging',
				'view'                => 'month',
				'expected'            => 'month',
			),
			array(
				'test_condition_name' => '宿泊以外・不正な表示指定 => 日表示',
				'preset'              => 'custom',
				'view'                => 'week',
				'expected'            => 'day',
			),
			array(
				'test_condition_name' => '宿泊・不正な表示指定 => 月表示',
				'preset'              => 'lodging',
				'view'                => 'week',
				'expected'            => 'month',
			),
		);

		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'get_selected_view' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			// 業種とクエリをケースごとに設定する。
			$this->set_preset( $case['preset'] );
			if ( null === $case['view'] ) {
				unset( $_GET['vkbm_view'] );
			} else {
				$_GET['vkbm_view'] = $case['view'];
			}

			// 選択された表示が仕様どおりか確認する。
			$actual = $method->invoke( new Shift_Dashboard_Page() );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 業種プリセットを保存する。
	 *
	 * @param string $preset プリセットキー。
	 * @return void
	 */
	private function set_preset( string $preset ): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $preset ) ) );
		Industry_Presets::clear_cache();
	}
}
