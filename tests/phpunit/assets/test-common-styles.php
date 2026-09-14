<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Assets;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * @group assets
 */
class Common_Styles_Test extends WP_UnitTestCase {
	public function test_get_custom_css_returns_expected_string(): void {
		$test_cases = [
			[
				'test_condition_name' => 'primary_color_only',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '#112233',
						'design_radius_md'     => '',
					],
				],
				'expected'            => ':root{--vkbm--color--primary: #112233;}',
			],
			[
				'test_condition_name' => 'radius_only',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => 12,
					],
				],
				'expected'            => ':root{--vkbm--radius--md: 12px;}',
			],
			[
				'test_condition_name' => 'reservation_button_color_only',
				'conditions'          => [
					'options' => [
						'design_primary_color'           => '',
						'design_reservation_button_color' => '#445566',
						'design_radius_md'               => '',
					],
				],
				'expected'            => ':root{--vkbm--color--reservation-action: #445566;}',
			],
			[
				'test_condition_name' => 'empty_values',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => '',
					],
				],
				'expected'            => '',
			],
			[
				// 上限追加（issue #420）以前に保存された超過値（例: 50px）を持つサイトが
				// 再保存するまで超過値のまま描画され続けないよう、描画側（このクラス）でも
				// Settings_Sanitizer::DESIGN_RADIUS_MD_MAX を参照してクランプする。
				'test_condition_name' => 'radius_over_max_is_clamped',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => 999,
					],
				],
				'expected'            => ':root{--vkbm--radius--md: 32px;}',
			],
			[
				'test_condition_name' => 'radius_at_max_boundary_is_unchanged',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => 32,
					],
				],
				'expected'            => ':root{--vkbm--radius--md: 32px;}',
			],
		];

		foreach ( $test_cases as $case ) {
			$options = $case['conditions']['options'] ?? [];
			update_option( Settings_Repository::OPTION_KEY, $options, false );

			$styles = new Common_Styles();
			$method = new \ReflectionMethod( $styles, 'get_custom_css' );
			$method->setAccessible( true );

			$result = $method->invoke( $styles );

			$this->assertSame( $case['expected'], $result, $case['test_condition_name'] );
		}
	}

	/**
	 * テスト前後で $GLOBALS['wp_styles'] をリセットする。
	 *
	 * WP_UnitTestCase は wp_styles() のグローバル状態を自動でリセットしないため、
	 * register_styles() / enqueue_variables() のようにスタイルの登録・enqueue 状態を
	 * 検証するテストでは、他のテストへ影響を残さないよう自前でリセットする。
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wp_styles'] );
	}

	/**
	 * テスト後の後片付け。
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'] );
		parent::tearDown();
	}

	/**
	 * register() が enqueue_variables() を enqueue_block_assets フックへ登録することを検証する。
	 *
	 * enqueue_block_assets は、フロント（wp_enqueue_scripts 経由）・管理画面
	 * （admin_enqueue_scripts 経由）に加えて、ブロックエディターのキャンバス iframe
	 * （_wp_get_iframed_editor_assets() が直接 do_action する）でも発火する唯一のフック。
	 * ここに登録されていないと、キャンバス iframe 内で :root カスタムプロパティが
	 * 一切定義されない不具合（issue #420 の差し戻し分）が復活する。
	 */
	public function test_register_hooks_enqueue_variables_onto_enqueue_block_assets(): void {
		$styles = new Common_Styles();
		$styles->register();

		$this->assertNotFalse(
			has_action( 'enqueue_block_assets', array( $styles, 'enqueue_variables' ) ),
			'enqueue_variables() が enqueue_block_assets フックに登録されていません。'
		);
	}

	/**
	 * register_styles() が vkbm-variables ハンドルを登録し、他のバンドル
	 * （frontend / auth / editor / admin）がそれへ依存していることを検証する。
	 *
	 * $deps 経由で依存させることで、それぞれのハンドルが enqueue されるたびに
	 * vkbm-variables（:root カスタムプロパティの実体）も確実に一緒に読み込まれ、
	 * 読み込み順に関係なく設定値が最終的に勝つ。
	 */
	public function test_register_styles_makes_other_bundles_depend_on_variables_handle(): void {
		$styles = new Common_Styles();
		$styles->register_styles();

		$this->assertTrue(
			wp_style_is( Common_Styles::VARIABLES_HANDLE, 'registered' ),
			'vkbm-variables ハンドルが登録されていません。'
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm-frontend は vkbm-variables に依存する',
				'handle'              => Common_Styles::FRONTEND_HANDLE,
			),
			array(
				'test_condition_name' => 'vkbm-auth は vkbm-variables に依存する',
				'handle'              => Common_Styles::AUTH_HANDLE,
			),
			array(
				'test_condition_name' => 'vkbm-editor は vkbm-variables に依存する',
				'handle'              => Common_Styles::EDITOR_HANDLE,
			),
			array(
				'test_condition_name' => 'vkbm-admin は vkbm-variables に依存する',
				'handle'              => Common_Styles::ADMIN_HANDLE,
			),
		);

		$registered = wp_styles()->registered;

		foreach ( $test_cases as $case ) {
			$this->assertArrayHasKey( $case['handle'], $registered, $case['test_condition_name'] . '（ハンドル未登録）' );
			$this->assertContains(
				Common_Styles::VARIABLES_HANDLE,
				$registered[ $case['handle'] ]->deps,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * enqueue_variables() が vkbm-variables を enqueue し、設定値を反映したインライン CSS を
	 * 付与することを検証する。
	 *
	 * 条件と期待値（インライン CSS に含まれるべき文字列の有無）をセットで確認する。
	 */
	public function test_enqueue_variables_enqueues_handle_and_applies_configured_custom_properties(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '角丸の設定値がある場合、その値を反映したインライン CSS が付く',
				'options'             => array( 'design_radius_md' => 20 ),
				'expected_contains'   => '--vkbm--radius--md: 20px;',
			),
			array(
				'test_condition_name' => '設定値が空の場合、インライン CSS は付与されない',
				'options'             => array( 'design_radius_md' => '' ),
				'expected_contains'   => null,
			),
		);

		foreach ( $test_cases as $case ) {
			unset( $GLOBALS['wp_styles'] );
			update_option( Settings_Repository::OPTION_KEY, $case['options'], false );

			$styles = new Common_Styles();
			$styles->enqueue_variables();

			$this->assertTrue(
				wp_style_is( Common_Styles::VARIABLES_HANDLE, 'enqueued' ),
				$case['test_condition_name'] . '（vkbm-variables が enqueue されていません）'
			);

			$inline = wp_styles()->get_data( Common_Styles::VARIABLES_HANDLE, 'after' );
			$inline = is_array( $inline ) ? implode( '', $inline ) : '';

			if ( null === $case['expected_contains'] ) {
				$this->assertSame( '', $inline, $case['test_condition_name'] );
			} else {
				$this->assertStringContainsString( $case['expected_contains'], $inline, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * enqueue_admin() が、通常の管理画面（ブロックエディター以外）でも設定値を反映した
	 * インライン CSS を付与することを検証する（issue #420 再差し戻しの回帰防止）。
	 *
	 * コアの wp_common_block_scripts_and_styles() は、通常の管理画面（VKBM の基本設定・
	 * シフト管理・スタイルガイド等）では `is_admin() && !
	 * wp_should_load_block_editor_scripts_and_styles()` により enqueue_block_assets を
	 * 発火しない。enqueue_variables() のみに任せると、vkbm-variables.min.css 自体は
	 * $deps 経由で読み込まれても、設定値（プライマリカラー・角丸）のインライン CSS が
	 * 一度も付与されなくなる退行があった。
	 */
	public function test_enqueue_admin_applies_configured_custom_properties_on_regular_admin_screens(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'design_radius_md' => 24 ), false );

		$styles = new Common_Styles();
		// 通常の管理画面の hook_suffix を模擬する（ブロックエディター画面ではない）。
		$styles->enqueue_admin( 'toplevel_page_vkbm-shift-dashboard' );

		$this->assertTrue(
			wp_style_is( Common_Styles::VARIABLES_HANDLE, 'enqueued' ),
			'vkbm-variables が enqueue されていません。'
		);

		$inline = wp_styles()->get_data( Common_Styles::VARIABLES_HANDLE, 'after' );
		$inline = is_array( $inline ) ? implode( '', $inline ) : '';

		$this->assertStringContainsString(
			'--vkbm--radius--md: 24px;',
			$inline,
			'通常の管理画面で設定値のインライン CSS が付与されていません。'
		);
	}

	/**
	 * apply_custom_properties() が1リクエストにつき1回しか付与しないことを検証する。
	 *
	 * ブロックエディター画面では admin_enqueue_scripts（enqueue_admin 経由）と
	 * enqueue_block_assets（コアの wp_common_block_scripts_and_styles() 経由、および
	 * iframe 用アセット収集の _wp_get_iframed_editor_assets() 経由で最大2回）の両方から
	 * enqueue_variables() が呼ばれ得る。_wp_get_iframed_editor_assets() は
	 * $wp_styles->registered の _WP_Dependency オブジェクト自体を共有し続けるため、
	 * ガード無しに複数回呼ぶと同じ :root{…} が重複出力される。
	 */
	public function test_apply_custom_properties_is_applied_only_once_per_request(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'design_radius_md' => 16 ), false );

		$styles = new Common_Styles();
		// admin_enqueue_scripts（enqueue_admin）と enqueue_block_assets（enqueue_variables、
		// iframe 収集分含め複数回）の両方から呼ばれる状況を模擬する。
		$styles->enqueue_admin( 'toplevel_page_vkbm-shift-dashboard' );
		$styles->enqueue_variables();
		$styles->enqueue_variables();

		$inline = wp_styles()->get_data( Common_Styles::VARIABLES_HANDLE, 'after' );
		$inline = is_array( $inline ) ? $inline : array();

		$this->assertCount(
			1,
			$inline,
			'同一リクエスト内で複数回呼ばれても、インライン CSS は1回だけ付与されるはずです。'
		);
		$this->assertStringContainsString( '--vkbm--radius--md: 16px;', implode( '', $inline ) );
	}

	/**
	 * enqueue_frontend() が enqueue_variables() を直接も呼び、設定値を反映した
	 * インライン CSS を付与することを検証する。
	 *
	 * 安藤さんの再レビュー指摘。enqueue_admin() は通常の管理画面向けに
	 * enqueue_variables() を直接呼ぶよう修正済みだが、フロントは
	 * wp_enqueue_scripts → コアの wp_common_block_scripts_and_styles() 経由で
	 * enqueue_block_assets が発火する前提のままだった。今回の管理画面の退行が
	 * 「コアの特定フックが必ず発火する」という前提の崩れで起きたのと同じ構造のため、
	 * フロントも対称に直接呼ぶ。
	 */
	public function test_enqueue_frontend_applies_configured_custom_properties(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'design_radius_md' => 12 ), false );

		$styles = new Common_Styles();
		$styles->enqueue_frontend();

		$this->assertTrue(
			wp_style_is( Common_Styles::VARIABLES_HANDLE, 'enqueued' ),
			'vkbm-variables が enqueue されていません。'
		);

		$inline = wp_styles()->get_data( Common_Styles::VARIABLES_HANDLE, 'after' );
		$inline = is_array( $inline ) ? implode( '', $inline ) : '';

		$this->assertStringContainsString(
			'--vkbm--radius--md: 12px;',
			$inline,
			'enqueue_frontend() で設定値のインライン CSS が付与されていません。'
		);
	}

	/**
	 * apply_custom_properties() が wp_add_inline_style() の戻り値を見ずにガードの
	 * フラグを立てていた場合の回帰防止テスト。
	 *
	 * ハンドル未登録（第三者の wp_deregister_style() 等）で wp_add_inline_style() が
	 * false を返した場合にフラグを立ててしまうと、そのリクエスト内では以降二度と
	 * 付与が行われなくなる。失敗時はフラグを立てず、ハンドルが登録された後の
	 * 再試行では成功して付与されることを検証する。
	 */
	public function test_apply_custom_properties_does_not_set_guard_when_wp_add_inline_style_fails(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'design_radius_md' => 20 ), false );

		$styles = new Common_Styles();
		// register_styles() をあえて呼ばず、VARIABLES_HANDLE が未登録の状態で
		// wp_add_inline_style() を失敗（false 返却）させる。
		$method = new \ReflectionMethod( $styles, 'apply_custom_properties' );
		$method->setAccessible( true );
		$method->invoke( $styles, Common_Styles::VARIABLES_HANDLE );

		$flag = new \ReflectionProperty( $styles, 'custom_properties_applied' );
		$flag->setAccessible( true );
		$this->assertFalse(
			$flag->getValue( $styles ),
			'wp_add_inline_style() が失敗した場合、ガードのフラグを立ててはいけません。'
		);

		// ハンドルを登録してから再試行すると、今度は成功して付与されることを確認する。
		$styles->register_styles();
		$method->invoke( $styles, Common_Styles::VARIABLES_HANDLE );

		$inline = wp_styles()->get_data( Common_Styles::VARIABLES_HANDLE, 'after' );
		$inline = is_array( $inline ) ? implode( '', $inline ) : '';
		$this->assertStringContainsString(
			'--vkbm--radius--md: 20px;',
			$inline,
			'失敗後の再試行でインライン CSS が付与されていません。'
		);
	}
}
