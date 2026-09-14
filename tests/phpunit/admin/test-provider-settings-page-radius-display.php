<?php
/**
 * デザイン設定の「角丸の基本サイズ」の表示値クランプ（issue #420 再差し戻し）の単体テスト。
 *
 * 上限（Settings_Sanitizer::DESIGN_RADIUS_MD_MAX = 32px）を追加する以前に保存された
 * 超過値（例: 50）を持つサイトが設定画面を開いた場合、入力欄の value がそのまま
 * 超過値で出力されると、max="32" のブラウザ HTML5 検証によりフォーム送信自体が
 * ブロックされ、他の設定項目も保存できなくなる。
 * 表示側でも同じ上限でクランプすることでこれを防ぐ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use WP_UnitTestCase;

/**
 * Provider_Settings_Page::clamp_design_radius_md_for_display() を検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_Radius_Display_Test extends WP_UnitTestCase {

	/**
	 * 条件（保存済みの design_radius_md）と期待値（表示にクランプされる値）をセットで検証する。
	 */
	public function test_clamp_design_radius_md_for_display(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '上限を超えない保存値（20）はそのまま表示される',
				'settings'            => array( 'design_radius_md' => 20 ),
				'expected'            => 20,
			),
			array(
				'test_condition_name' => '上限と同じ保存値（32）はそのまま表示される',
				'settings'            => array( 'design_radius_md' => Settings_Sanitizer::DESIGN_RADIUS_MD_MAX ),
				'expected'            => Settings_Sanitizer::DESIGN_RADIUS_MD_MAX,
			),
			array(
				// 上限追加（issue #420）以前に保存された超過値を持つサイトの再現ケース。
				'test_condition_name' => '上限追加前に保存された超過値（50）は上限にクランプされる',
				'settings'            => array( 'design_radius_md' => 50 ),
				'expected'            => Settings_Sanitizer::DESIGN_RADIUS_MD_MAX,
			),
			array(
				'test_condition_name' => '未設定の場合は既定値 8 が返る',
				'settings'            => array(),
				'expected'            => 8,
			),
			array(
				'test_condition_name' => '空文字の場合は既定値 8 が返る（sanitize 前の未入力を保持する仕様と合わせる）',
				'settings'            => array( 'design_radius_md' => '' ),
				'expected'            => 8,
			),
			array(
				'test_condition_name' => '負の保存値は 0 にクランプされる',
				'settings'            => array( 'design_radius_md' => -5 ),
				'expected'            => 0,
			),
		);

		$method = new \ReflectionMethod( Provider_Settings_Page::class, 'clamp_design_radius_md_for_display' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$actual = $method->invoke( null, $case['settings'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * テスト前後で $GLOBALS['wp_settings_errors'] をリセットする。
	 *
	 * add_settings_error() はこのグローバル配列へ追記し続けるため、他のテストへ
	 * 影響を残さないよう自前でリセットする。
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wp_settings_errors'] );
	}

	/**
	 * テスト後の後片付け。
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_settings_errors'] );
		parent::tearDown();
	}

	/**
	 * maybe_notify_design_radius_md_clamped() が、保存済みの design_radius_md が上限を
	 * 超えている場合にだけ settings_errors() 経由の警告バナーを登録することを検証する。
	 *
	 * 植草さんの再レビュー指摘（対応必須・中）。上限追加（issue #420）以前から
	 * design_radius_md はリリース済みの設定項目で、旧 UI には max 属性が無かったため、
	 * 既に32pxを超える値（例: 50）を保存済みのサイトが実在しうる。そのようなサイトでは
	 * 表示値・描画値が黙って32pxへ縮小されるため、開いた本人に伝える必要がある。
	 */
	public function test_maybe_notify_design_radius_md_clamped(): void {
		$menu_slug = ( new \ReflectionClassConstant( Provider_Settings_Page::class, 'MENU_SLUG' ) )->getValue();

		$test_cases = array(
			array(
				'test_condition_name' => '上限を超える保存値（50）の場合、警告バナーが登録される',
				'settings'            => array( 'design_radius_md' => 50 ),
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => '上限と同じ保存値（32）の場合、警告バナーは登録されない',
				'settings'            => array( 'design_radius_md' => Settings_Sanitizer::DESIGN_RADIUS_MD_MAX ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '上限を超えない保存値（20）の場合、警告バナーは登録されない',
				'settings'            => array( 'design_radius_md' => 20 ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '未設定の場合、警告バナーは登録されない',
				'settings'            => array(),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '空文字の場合、警告バナーは登録されない',
				'settings'            => array( 'design_radius_md' => '' ),
				'expect_notice'       => false,
			),
		);

		$method = new \ReflectionMethod( Provider_Settings_Page::class, 'maybe_notify_design_radius_md_clamped' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			unset( $GLOBALS['wp_settings_errors'] );

			$method->invoke( null, $case['settings'] );
			$errors = get_settings_errors( $menu_slug );

			if ( ! $case['expect_notice'] ) {
				$this->assertSame( array(), $errors, $case['test_condition_name'] );
				continue;
			}

			$this->assertCount( 1, $errors, $case['test_condition_name'] );
			$this->assertSame( 'warning', $errors[0]['type'], $case['test_condition_name'] . '（種別）' );
			$this->assertStringContainsString(
				(string) Settings_Sanitizer::DESIGN_RADIUS_MD_MAX,
				$errors[0]['message'],
				$case['test_condition_name'] . '（上限値がメッセージに含まれる）'
			);
		}
	}
}
