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
				'test_condition_name' => 'menu_card_catch_color_only',
				'conditions'          => [
					'options' => [
						'design_primary_color'            => '',
						'design_menu_card_catch_color'    => '#778899',
						'design_reservation_button_color' => '',
						'design_radius_md'                => '',
					],
				],
				'expected'            => ':root{--vkbm--color--menu-card-catch: #778899;}',
			],
			[
				'test_condition_name' => 'menu_card_price_color_only',
				'conditions'          => [
					'options' => [
						'design_menu_card_price_color' => '#aa3300',
						'design_radius_md'             => '',
					],
				],
				'expected'            => ':root{--vkbm--color--menu-card-price: #aa3300;}',
			],
			[
				'test_condition_name' => 'menu_card_price_color_empty_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_menu_card_price_color' => '',
						'design_radius_md'             => '',
					],
				],
				'expected'            => '',
			],
			[
				'test_condition_name' => 'primary_color_with_injected_css_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '#fff;}body{display:none}',
						'design_radius_md' => '',
					],
				],
				'expected'            => '',
			],
			[
				'test_condition_name' => 'menu_card_catch_color_with_injected_css_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_menu_card_catch_color' => '#fff;}body{display:none}',
						'design_radius_md' => '',
					],
				],
				'expected'            => '',
			],
			[
				'test_condition_name' => 'menu_card_price_color_with_injected_css_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_menu_card_price_color' => '#fff;}body{display:none}',
						'design_radius_md' => '',
					],
				],
				'expected'            => '',
			],
			[
				'test_condition_name' => 'reservation_button_color_with_injected_css_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_reservation_button_color' => '#fff;}body{display:none}',
						'design_radius_md' => '',
					],
				],
				'expected'            => '',
			],
			[
				'test_condition_name' => 'invalid_price_color_does_not_affect_valid_primary_color',
				'conditions'          => [
					'options' => [
						'design_primary_color'         => '#112233',
						'design_menu_card_price_color' => 'red',
						'design_radius_md'             => '',
					],
				],
				'expected'            => ':root{--vkbm--color--primary: #112233;}',
			],
			[
				'test_condition_name' => 'menu_card_catch_color_empty_outputs_nothing',
				'conditions'          => [
					'options' => [
						'design_primary_color'            => '',
						'design_menu_card_catch_color'    => '',
						'design_reservation_button_color' => '',
						'design_radius_md'                => '',
					],
				],
				'expected'            => '',
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
				'test_condition_name' => '#825 フッターの背景色と文字色 => 背景・文字・区切り線（文字色の 20%）が出力される',
				'conditions'          => [
					'options' => [
						'design_footer_background_color' => '#1e3a8a',
						'design_footer_text_color'       => '#ffcc00',
						'design_radius_md'                    => '',
					],
				],
				'expected'            => ':root{--vkbm--color--footer-bg: #1e3a8a;--vkbm--color--footer-text: #ffcc00;--vkbm--color--footer-border: rgb(255 204 0 / 20%);}',
			],
			[
				'test_condition_name' => '#825 フッターの色がどちらも空 => 何も出力しない',
				'conditions'          => [
					'options' => [
						'design_footer_background_color' => '',
						'design_footer_text_color'       => '',
						'design_radius_md'                    => '',
					],
				],
				'expected'            => '',
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
	 * #825: get_footer_color_properties() が、背景色・文字色の設定から
	 * フッターの CSS 変数を組み立てることを検証する。
	 */
	public function test_get_footer_color_properties(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '背景色・文字色とも設定 => 文字色をそのまま使い、区切り線は文字色の 20%',
				'conditions'          => array(
					'design_footer_background_color' => '#1e3a8a',
					'design_footer_text_color'       => '#ffcc00',
				),
				'expected'            => array(
					'--vkbm--color--footer-bg'     => '#1e3a8a',
					'--vkbm--color--footer-text'   => '#ffcc00',
					'--vkbm--color--footer-border' => 'rgb(255 204 0 / 20%)',
				),
			),
			array(
				'test_condition_name' => '濃い背景色だけ設定 => 文字色は自動で白',
				'conditions'          => array(
					'design_footer_background_color' => '#1e3a8a',
					'design_footer_text_color'       => '',
				),
				'expected'            => array(
					'--vkbm--color--footer-bg'     => '#1e3a8a',
					'--vkbm--color--footer-text'   => '#ffffff',
					'--vkbm--color--footer-border' => 'rgb(255 255 255 / 20%)',
				),
			),
			array(
				'test_condition_name' => '薄い背景色だけ設定 => 文字色は自動で濃いグレー',
				'conditions'          => array(
					'design_footer_background_color' => '#f1f2f4',
				),
				'expected'            => array(
					'--vkbm--color--footer-bg'     => '#f1f2f4',
					'--vkbm--color--footer-text'   => '#333333',
					'--vkbm--color--footer-border' => 'rgb(51 51 51 / 20%)',
				),
			),
			array(
				'test_condition_name' => '文字色だけ設定（3 桁） => 背景色は出さず、文字色と区切り線を出す',
				'conditions'          => array(
					'design_footer_background_color' => '',
					'design_footer_text_color'       => '#abc',
				),
				'expected'            => array(
					'--vkbm--color--footer-text'   => '#abc',
					'--vkbm--color--footer-border' => 'rgb(170 187 204 / 20%)',
				),
			),
			array(
				'test_condition_name' => '3 桁の背景色だけ設定（#000） => 文字色は自動で白',
				'conditions'          => array(
					'design_footer_background_color' => '#000',
				),
				'expected'            => array(
					'--vkbm--color--footer-bg'     => '#000',
					'--vkbm--color--footer-text'   => '#ffffff',
					'--vkbm--color--footer-border' => 'rgb(255 255 255 / 20%)',
				),
			),
			array(
				'test_condition_name' => 'どちらも空 => 何も出力しない',
				'conditions'          => array(
					'design_footer_background_color' => '',
					'design_footer_text_color'       => '',
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '設定キーが無い（未保存のサイト） => 何も出力しない',
				'conditions'          => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '不正な文字色（# なし）と正しい背景色 => 文字色は背景色から自動で決める',
				'conditions'          => array(
					'design_footer_background_color' => '#ffffff',
					'design_footer_text_color'       => 'ffffff',
				),
				'expected'            => array(
					'--vkbm--color--footer-bg'     => '#ffffff',
					'--vkbm--color--footer-text'   => '#333333',
					'--vkbm--color--footer-border' => 'rgb(51 51 51 / 20%)',
				),
			),
			array(
				'test_condition_name' => 'CSS を埋め込む背景色・文字色 => 何も出力しない',
				'conditions'          => array(
					'design_footer_background_color' => '#fff;}body{display:none}',
					'design_footer_text_color'       => 'red;}body{display:none}',
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '文字列でない値 => 何も出力しない',
				'conditions'          => array(
					'design_footer_background_color' => array( '#ffffff' ),
					'design_footer_text_color'       => 123,
				),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Common_Styles::get_footer_color_properties( $case['conditions'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * #825: get_readable_text_color() が、濃いグレーで 4.5:1 に届けば濃いグレーを、
	 * 届かなければ黒・白のうちコントラスト比が高い方を返すことを検証する（境目を含む）。
	 */
	public function test_get_readable_text_color(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '白 => 濃いグレー',
				'background'          => '#ffffff',
				'expected'            => '#333333',
			),
			array(
				'test_condition_name' => '黒 => 白',
				'background'          => '#000000',
				'expected'            => '#ffffff',
			),
			array(
				'test_condition_name' => '濃いグレーで 4.5:1 に届く境目 #9b9b9b（約 4.55） => 濃いグレー',
				'background'          => '#9b9b9b',
				'expected'            => '#333333',
			),
			array(
				'test_condition_name' => '濃いグレーで 4.5:1 に届かない境目 #9a9a9a（約 4.49） => 黒（黒の方が比が高い）',
				'background'          => '#9a9a9a',
				'expected'            => '#000000',
			),
			array(
				'test_condition_name' => '黒の方が白より比が高い境目 #767676 => 黒',
				'background'          => '#767676',
				'expected'            => '#000000',
			),
			array(
				'test_condition_name' => '白の方が黒より比が高い境目 #757575 => 白',
				'background'          => '#757575',
				'expected'            => '#ffffff',
			),
			array(
				'test_condition_name' => '3 桁の白 #fff => 濃いグレー',
				'background'          => '#fff',
				'expected'            => '#333333',
			),
			array(
				'test_condition_name' => '3 桁の濃い青 #123 => 白',
				'background'          => '#123',
				'expected'            => '#ffffff',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Common_Styles::get_readable_text_color( $case['background'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * #825: get_contrast_ratio() が WCAG のコントラスト比を返すことを検証する。
	 */
	public function test_get_contrast_ratio(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '黒と白 => 21',
				'color_a'             => '#000000',
				'color_b'             => '#ffffff',
				'expected'            => 21.0,
			),
			array(
				'test_condition_name' => '順番を入れ替えても同じ値 => 21',
				'color_a'             => '#ffffff',
				'color_b'             => '#000000',
				'expected'            => 21.0,
			),
			array(
				'test_condition_name' => '同じ色 => 1',
				'color_a'             => '#808080',
				'color_b'             => '#808080',
				'expected'            => 1.0,
			),
			array(
				'test_condition_name' => '3 桁と 6 桁の同じ色 => 1',
				'color_a'             => '#abc',
				'color_b'             => '#aabbcc',
				'expected'            => 1.0,
			),
			array(
				'test_condition_name' => '濃いグレーと白 => 約 12.63',
				'color_a'             => '#333333',
				'color_b'             => '#ffffff',
				'expected'            => 12.63,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Common_Styles::get_contrast_ratio( $case['color_a'], $case['color_b'] );

			$this->assertEqualsWithDelta( $case['expected'], $actual, 0.01, $case['test_condition_name'] );
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
			array(
				'test_condition_name' => 'vkbm-admin-notice は vkbm-variables に依存する',
				'handle'              => Common_Styles::NOTICE_HANDLE,
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
	 * 共通部品バンドル（vkbm-components）の依存関係を検証する（issue #660）。
	 *
	 * 各ブロックの block.json の style から参照されるため、ブロック登録より前
	 * （init の優先度 5）にハンドルが登録される必要がある。また vkbm-auth は部品を
	 * 持たず vkbm-components に依存する。vkbm-editor は補正のみで、部品は vkbm-auth 経由で
	 * 先に読み込み、vkbm-admin には依存しない（issue #659）。
	 */
	public function test_register_styles_wires_components_auth_and_editor_dependencies(): void {
		$styles = new Common_Styles();
		$styles->register();
		$styles->register_styles();

		$this->assertSame(
			5,
			has_action( 'init', array( $styles, 'register_styles' ) ),
			'register_styles() は block.json の style 解決より前（init 優先度 5）に実行される必要があります。'
		);

		$registered = wp_styles()->registered;

		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm-components は vkbm-frontend（box-sizing 等の共通指定）に依存する',
				'handle'              => Common_Styles::COMPONENTS_HANDLE,
				'expected_dep'        => Common_Styles::FRONTEND_HANDLE,
			),
			array(
				'test_condition_name' => 'vkbm-auth は vkbm-components に依存する',
				'handle'              => Common_Styles::AUTH_HANDLE,
				'expected_dep'        => Common_Styles::COMPONENTS_HANDLE,
			),
			array(
				'test_condition_name' => 'vkbm-editor は vkbm-auth（経由で vkbm-components）に依存する',
				'handle'              => Common_Styles::EDITOR_HANDLE,
				'expected_dep'        => Common_Styles::AUTH_HANDLE,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertArrayHasKey( $case['handle'], $registered, $case['test_condition_name'] . '（ハンドル未登録）' );
			$this->assertContains( $case['expected_dep'], $registered[ $case['handle'] ]->deps, $case['test_condition_name'] );
			$this->assertContains( Common_Styles::VARIABLES_HANDLE, $registered[ $case['handle'] ]->deps, $case['test_condition_name'] . '（variables にも依存）' );
		}

		$this->assertNotContains( Common_Styles::ADMIN_HANDLE, $registered[ Common_Styles::EDITOR_HANDLE ]->deps, 'vkbm-editor は vkbm-admin に依存しない' );
	}

	/**
	 * vkbm-components は、ファイルがあるとき HTML への直接埋め込み（インライン化）用の path を持つ。
	 */
	public function test_register_styles_sets_inline_path_for_components_when_file_exists(): void {
		$path = VKBM_PLUGIN_DIR_PATH . 'build/assets/css/vkbm-components.min.css';

		wp_deregister_style( Common_Styles::COMPONENTS_HANDLE );
		( new Common_Styles() )->register_styles();

		$registered = wp_styles()->registered[ Common_Styles::COMPONENTS_HANDLE ];
		if ( file_exists( $path ) ) {
			$this->assertSame( $path, $registered->extra['path'] ?? null, 'ファイルがあるのに path が設定されていません。' );
		} else {
			$this->assertArrayNotHasKey( 'path', $registered->extra, 'ファイルが無いのに path が設定されています。' );
		}
	}

	/**
	 * 予約ブロックと menu-card が menu-loop の CSS を読み込むハンドル（vkbm-shared-menu-card）が、
	 * どちらの経路でも vkbm-components に依存していることを検証する（issue #660）。
	 * menu-loop の style-index.css は共通部品を含まないため、依存が外れるとボタン等の見た目が欠ける。
	 */
	public function test_shared_menu_card_style_depends_on_components_on_both_paths(): void {
		// 経路1: 予約ブロック。
		wp_deregister_style( 'vkbm-shared-menu-card' );
		$reservation = ( new \ReflectionClass( \VKBookingManager\Blocks\Reservation_Block::class ) )->newInstanceWithoutConstructor();
		$method      = new \ReflectionMethod( $reservation, 'register_menu_loop_style' );
		$method->setAccessible( true );
		$method->invoke( $reservation );

		$this->assertContains(
			Common_Styles::COMPONENTS_HANDLE,
			wp_styles()->registered['vkbm-shared-menu-card']->deps,
			'予約ブロック経路の vkbm-shared-menu-card が vkbm-components に依存していません。'
		);

		// 経路2: menu-card ブロック（wp_enqueue_block_style 経由）。
		// enqueue_block_assets を呼ぶと予約ブロックの処理が先に同ハンドルを登録し deps の検査が無効になるため、
		// ブロック描画（render_block）を起点に登録される設定へ切り替え、enqueue_block_assets は呼ばない。
		wp_deregister_style( 'vkbm-shared-menu-card' );
		add_filter( 'should_load_separate_core_block_assets', '__return_true' );
		$menu_card = ( new \ReflectionClass( \VKBookingManager\Blocks\Menu_Card_Block::class ) )->newInstanceWithoutConstructor();
		$method    = new \ReflectionMethod( $menu_card, 'enqueue_shared_menu_loop_style' );
		$method->setAccessible( true );
		$method->invoke( $menu_card );

		$parsed_block = array(
			'blockName'    => 'vk-booking-manager/menu-card',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		apply_filters( 'render_block', '', $parsed_block, new \WP_Block( $parsed_block ) );
		remove_filter( 'should_load_separate_core_block_assets', '__return_true' );

		$this->assertArrayHasKey( 'vkbm-shared-menu-card', wp_styles()->registered, 'menu-card 経路で vkbm-shared-menu-card が登録されていません。' );
		$this->assertContains(
			Common_Styles::COMPONENTS_HANDLE,
			wp_styles()->registered['vkbm-shared-menu-card']->deps,
			'menu-card 経路の vkbm-shared-menu-card が vkbm-components に依存していません。'
		);
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
	 * enqueue_admin() は、全管理画面で呼ばれても管理画面用バンドル（vkbm-admin）を読み込まないことを
	 * 検証する（issue #659）。
	 *
	 * vkbm-admin（約48KB）は VKBM の画面が自分で enqueue する。ここで読み込むと、投稿・固定ページ・
	 * ユーザーなど無関係な画面でも毎回評価される。全画面で必要な :root カスタムプロパティ
	 * （vkbm-variables）は引き続き読み込む。
	 */
	public function test_enqueue_admin_does_not_enqueue_admin_bundle(): void {
		$styles = new Common_Styles();
		$styles->register_styles();

		$test_cases = array(
			array(
				'test_condition_name' => 'VKBM の画面でも vkbm-admin は enqueue_admin() では読み込まない',
				'hook_suffix'         => 'toplevel_page_vkbm-shift-dashboard',
			),
			array(
				'test_condition_name' => 'コアの投稿一覧でも vkbm-admin は読み込まない',
				'hook_suffix'         => 'edit.php',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
			wp_dequeue_style( Common_Styles::VARIABLES_HANDLE );

			$styles->enqueue_admin( $case['hook_suffix'] );

			$this->assertFalse(
				wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ),
				$case['test_condition_name']
			);
			$this->assertTrue(
				wp_style_is( Common_Styles::VARIABLES_HANDLE, 'enqueued' ),
				$case['test_condition_name'] . '（vkbm-variables は読み込む）'
			);
		}
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
