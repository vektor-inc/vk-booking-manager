<?php
/**
 * メニューの呼び方（menu_label / menu_label_plural）の保存・読み出し・REST・業種プリセットのテスト（#1127）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * メニューの呼び方の設定を検証する。
 *
 * @group provider-settings
 * @group resource-labels
 */
class Menu_Label_Test extends WP_UnitTestCase {
	/**
	 * 空欄のときの既定の呼び方。
	 *
	 * 翻訳の読み込み状態は先に走るテストで変わるため、翻訳テーブルには触らず、
	 * その時点の翻訳結果（日本語なら「メニュー」、原文なら「Menu」）を期待値に使う。
	 *
	 * @var string
	 */
	private $default_label = '';

	/**
	 * 各テスト前に設定を初期化し、既定の呼び方を控える。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( 'vkbm_industry_presets' );
		Industry_Presets::clear_cache();
		$this->default_label = __( 'Menu', 'vk-booking-manager' );
	}

	/**
	 * 各テスト後に設定を破棄する。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( 'vkbm_industry_presets' );
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 呼び方は空欄なら「Menu」へフォールバックし、値があればそれを返す。
	 *
	 * @return void
	 */
	public function test_vkbm_get_menu_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'オプション未保存（キー無し）の場合 => 既定の呼び方',
				'options'             => null,
				'expected'            => $this->default_label,
			),
			array(
				'test_condition_name' => '空文字保存の場合 => 既定の呼び方',
				'options'             => array( 'menu_label' => '' ),
				'expected'            => $this->default_label,
			),
			array(
				'test_condition_name' => '前後スペースのみ保存の場合 => trim 後に空となり既定の呼び方',
				'options'             => array( 'menu_label' => '   ' ),
				'expected'            => $this->default_label,
			),
			array(
				'test_condition_name' => 'プラン を保存した場合 => プラン',
				'options'             => array( 'menu_label' => 'プラン' ),
				'expected'            => 'プラン',
			),
			array(
				'test_condition_name' => '前後にスペースのある Course を保存した場合 => trim した Course',
				'options'             => array( 'menu_label' => ' Course ' ),
				'expected'            => 'Course',
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$this->assertSame( $case['expected'], vkbm_get_menu_label(), $case['test_condition_name'] );
		}
	}

	/**
	 * 複数形は空欄なら単数形の呼び方（その実効値）へフォールバックする。
	 *
	 * @return void
	 */
	public function test_vkbm_get_menu_label_plural(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '単数形・複数形とも未保存の場合 => 既定の呼び方',
				'options'             => null,
				'expected'            => $this->default_label,
			),
			array(
				'test_condition_name' => '単数形 Plan・複数形 Plans を保存した場合 => Plans',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => 'Plans',
			),
			array(
				'test_condition_name' => '単数形 Plan だけ保存した場合 => 単数形の Plan',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => '',
				),
				'expected'            => 'Plan',
			),
			array(
				'test_condition_name' => '複数形がスペースのみの場合 => 単数形の Plan',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => '  ',
				),
				'expected'            => 'Plan',
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$this->assertSame( $case['expected'], vkbm_get_menu_label_plural(), $case['test_condition_name'] );
		}
	}

	/**
	 * 文に差し込む呼び方は、空欄・「メニュー」のとき空文字になり、それ以外は保存値になる（#1129）。
	 *
	 * @return void
	 */
	public function test_vkbm_get_custom_menu_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'オプション未保存の場合 => 空文字',
				'options'             => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '空文字保存の場合 => 空文字',
				'options'             => array( 'menu_label' => '' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'メニュー と入力した場合 => 空文字',
				'options'             => array( 'menu_label' => 'メニュー' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '前後にスペースのある Menu と入力した場合 => 空文字',
				'options'             => array( 'menu_label' => ' Menu ' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プラン を保存した場合 => プラン',
				'options'             => array( 'menu_label' => 'プラン' ),
				'expected'            => 'プラン',
			),
			array(
				'test_condition_name' => 'サービスメニュー を保存した場合 => 既定の呼び方とは別の語としてそのまま',
				'options'             => array( 'menu_label' => 'サービスメニュー' ),
				'expected'            => 'サービスメニュー',
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$this->assertSame( $case['expected'], vkbm_get_custom_menu_label(), $case['test_condition_name'] );
		}
	}

	/**
	 * 文に差し込む複数形は、単数形が既定のままなら空文字、変えていれば複数形（空なら単数形）になる（#1129）。
	 *
	 * @return void
	 */
	public function test_vkbm_get_custom_menu_label_plural(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '単数形・複数形とも未保存の場合 => 空文字',
				'options'             => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '単数形が Menu で複数形だけ Plans の場合 => 単数形で判定して空文字',
				'options'             => array(
					'menu_label'        => 'Menu',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '単数形 Plan・複数形 Plans の場合 => Plans',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => 'Plans',
			),
			array(
				'test_condition_name' => '単数形 プラン だけ保存した場合 => 単数形の プラン',
				'options'             => array( 'menu_label' => 'プラン' ),
				'expected'            => 'プラン',
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$this->assertSame( $case['expected'], vkbm_get_custom_menu_label_plural(), $case['test_condition_name'] );
		}
	}

	/**
	 * 呼び方を変えていれば差し込み用の文に入れ、変えていなければ今ある文言をそのまま返す（#1129）。
	 *
	 * @return void
	 */
	public function test_vkbm_get_menu_label_text(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '呼び方が未保存の場合 => 今ある文言のまま',
				'options'             => null,
				'args'                => array( '今ある文言', '%sの文' ),
				'expected'            => '今ある文言',
			),
			array(
				'test_condition_name' => '呼び方に メニュー と入力した場合 => 今ある文言のまま',
				'options'             => array( 'menu_label' => 'メニュー' ),
				'args'                => array( '今ある文言', '%sの文' ),
				'expected'            => '今ある文言',
			),
			array(
				'test_condition_name' => '呼び方が プラン の場合 => 差し込み用の文に プラン を入れる',
				'options'             => array( 'menu_label' => 'プラン' ),
				'args'                => array( '今ある文言', '%sの文' ),
				'expected'            => 'プランの文',
			),
			array(
				'test_condition_name' => '呼び方が プラン で追加の値がある場合 => 呼び方を1番目、追加の値を2番目に入れる',
				'options'             => array( 'menu_label' => 'プラン' ),
				'args'                => array( '今ある文言', 'この%1$sは %2$d 名から', array( 3 ) ),
				'expected'            => 'このプランは 3 名から',
			),
			array(
				'test_condition_name' => '複数形を指定し、単数形 Plan・複数形 Plans の場合 => Plans を入れる',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'args'                => array( 'No service menus', 'No %s', array(), true ),
				'expected'            => 'No Plans',
			),
			array(
				'test_condition_name' => '複数形を指定し、呼び方が未保存の場合 => 今ある文言のまま',
				'options'             => null,
				'args'                => array( 'No service menus', 'No %s', array(), true ),
				'expected'            => 'No service menus',
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$this->assertSame( $case['expected'], vkbm_get_menu_label_text( ...$case['args'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 保存時の検査（Settings_Sanitizer::sanitize()）で、呼び方がテキストとして整えられ、未送信は空のままになる。
	 *
	 * @return void
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '未送信の場合 => 既定の空文字',
				'input'               => array(),
				'expected'            => array(
					'menu_label'        => '',
					'menu_label_plural' => '',
				),
			),
			array(
				'test_condition_name' => 'プラン / Plans を送信した場合 => そのまま保持',
				'input'               => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'Plans',
				),
			),
			array(
				'test_condition_name' => 'タグを含む値を送信した場合 => タグを取り除く',
				'input'               => array(
					'menu_label'        => 'プラン<script>alert(1)</script>',
					'menu_label_plural' => '<b>Plans</b>',
				),
				'expected'            => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'Plans',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Settings_Sanitizer() )->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected']['menu_label'], $result['menu_label'], $case['test_condition_name'] . ' / menu_label' );
			$this->assertSame( $case['expected']['menu_label_plural'], $result['menu_label_plural'], $case['test_condition_name'] . ' / menu_label_plural' );
		}
	}

	/**
	 * Settings_Service::save_settings() で保存した呼び方が、無料版・Pro 版とも読み出せる。
	 *
	 * @return void
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'プラン を保存した場合 => 保存値・読み出し値ともプラン',
				'input'               => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => '',
				),
				'expected'            => array(
					'saved'    => 'プラン',
					'label'    => 'プラン',
					'plural'   => 'プラン',
					'is_saved' => true,
				),
			),
			array(
				'test_condition_name' => 'Plan / Plans を保存した場合 => 複数形は Plans',
				'input'               => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => array(
					'saved'    => 'Plan',
					'label'    => 'Plan',
					'plural'   => 'Plans',
					'is_saved' => true,
				),
			),
			array(
				'test_condition_name' => '空欄で保存した場合 => 保存値は空、読み出し値は既定の呼び方',
				'input'               => array(
					'menu_label'        => '',
					'menu_label_plural' => '',
				),
				'expected'            => array(
					'saved'    => '',
					'label'    => $this->default_label,
					'plural'   => $this->default_label,
					'is_saved' => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

			// 既定値に呼び方を重ねて保存する（画面からの送信を模す）。
			$result = $service->save_settings( array_merge( $service->get_default_settings(), $case['input'] ) );

			$this->assertSame( $case['expected']['is_saved'], $result, $case['test_condition_name'] . ' / 保存の成否' );
			$this->assertSame( $case['expected']['saved'], $service->get_settings()['menu_label'], $case['test_condition_name'] . ' / 保存値' );
			$this->assertSame( $case['expected']['label'], vkbm_get_menu_label(), $case['test_condition_name'] . ' / 読み出し値' );
			$this->assertSame( $case['expected']['plural'], vkbm_get_menu_label_plural(), $case['test_condition_name'] . ' / 複数形の読み出し値' );
		}
	}

	/**
	 * 呼び方の欄を送らない保存では保存済みの値を引き継ぎ、空文字を送ったときだけ消去する。
	 *
	 * 複数形の欄は複数形のある言語の画面にしか出ないため、英語の画面で保存した複数形が、
	 * 日本語の画面での保存や REST の部分更新で消えないことを確かめる。
	 *
	 * @return void
	 */
	public function test_save_settings_keeps_unsent_menu_label(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語の画面で Plan / Plans を保存後、日本語の画面（複数形の欄なし）で保存した場合 => 複数形 Plans が残る',
				'unset_keys'          => array( 'menu_label_plural' ),
				'input'               => array( 'menu_label' => 'Plan' ),
				'expected'            => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
			),
			array(
				'test_condition_name' => '英語の画面で Plan / Plans を保存後、呼び方の欄を送らない部分更新をした場合 => どちらも残る',
				'unset_keys'          => array( 'menu_label', 'menu_label_plural' ),
				'input'               => array(),
				'expected'            => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
			),
			array(
				'test_condition_name' => '英語の画面で Plan / Plans を保存後、複数形に空文字を送った場合 => 複数形だけ消去される',
				'unset_keys'          => array(),
				'input'               => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => '',
				),
				'expected'            => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => '',
				),
			),
			array(
				'test_condition_name' => '英語の画面で Plan / Plans を保存後、日本語の画面で呼び方に空文字を送った場合 => 呼び方だけ消去され、複数形は残る',
				'unset_keys'          => array( 'menu_label_plural' ),
				'input'               => array( 'menu_label' => '' ),
				'expected'            => array(
					'menu_label'        => '',
					'menu_label_plural' => 'Plans',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

			// 英語の画面（複数形の欄あり）からの保存を模す。
			$service->save_settings(
				array_merge(
					$service->get_default_settings(),
					array(
						'menu_label'        => 'Plan',
						'menu_label_plural' => 'Plans',
					)
				)
			);

			// 2回目の保存。欄が無い項目は送信値から外す。
			$input = array_merge( $service->get_default_settings(), $case['input'] );
			foreach ( $case['unset_keys'] as $unset_key ) {
				unset( $input[ $unset_key ] );
			}
			$service->save_settings( $input );

			$saved = $service->get_settings();
			$this->assertSame( $case['expected']['menu_label'], $saved['menu_label'], $case['test_condition_name'] . ' / menu_label' );
			$this->assertSame( $case['expected']['menu_label_plural'], $saved['menu_label_plural'], $case['test_condition_name'] . ' / menu_label_plural' );
		}
	}

	/**
	 * REST API（provider-settings）の返却値に、呼び方の実効値が入る。
	 *
	 * @return void
	 */
	public function test_get_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定が一度も保存されていない => 既定の呼び方',
				'saved_option'        => null,
				'expected'            => array(
					'menu_label'        => $this->default_label,
					'menu_label_plural' => $this->default_label,
				),
			),
			array(
				'test_condition_name' => 'プラン を保存済み => プラン（複数形も単数形へフォールバック）',
				'saved_option'        => array( 'menu_label' => 'プラン' ),
				'expected'            => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'プラン',
				),
			),
			array(
				'test_condition_name' => 'Plan / Plans を保存済み => Plan / Plans',
				'saved_option'        => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
			),
			array(
				'test_condition_name' => '空文字を保存済み => 既定の呼び方',
				'saved_option'        => array( 'menu_label' => '' ),
				'expected'            => array(
					'menu_label'        => $this->default_label,
					'menu_label_plural' => $this->default_label,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			$data = ( new Provider_Settings_Controller( new Settings_Repository() ) )->get_settings()->get_data();

			$this->assertSame( $case['expected']['menu_label'], $data['menu_label'] ?? null, $case['test_condition_name'] . ' / menu_label' );
			$this->assertSame( $case['expected']['menu_label_plural'], $data['menu_label_plural'] ?? null, $case['test_condition_name'] . ' / menu_label_plural' );
		}
	}

	/**
	 * 業種プリセットを切り替えても、呼び方は保存済みの値から変わらない。
	 *
	 * @return void
	 */
	public function test_apply_transition(): void {
		// プリセットが書き換える設定の一覧に入っていないこと（無料版でも定数は同じ）。
		$this->assertNotContains( 'menu_label', Industry_Presets::FIELD_KEYS, 'menu_label は業種プリセットの対象外' );
		$this->assertNotContains( 'menu_label_plural', Industry_Presets::FIELD_KEYS, 'menu_label_plural は業種プリセットの対象外' );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '業種プリセットの切り替えは Pro 版で検証する。' );
		}

		$defaults   = ( new Settings_Repository() )->get_default_settings();
		$test_cases = array();
		foreach ( array( 'salon', 'activity', 'seminar', 'lodging', 'custom' ) as $preset_key ) {
			$test_cases[] = array(
				'test_condition_name' => 'custom の未入力から ' . $preset_key . ' へ切り替えた場合 => 未入力のまま',
				'preset_key'          => $preset_key,
				'current'             => array(
					'menu_label'        => '',
					'menu_label_plural' => '',
				),
				'expected'            => array(
					'menu_label'        => '',
					'menu_label_plural' => '',
				),
			);
			$test_cases[] = array(
				'test_condition_name' => 'custom のプラン / Plans から ' . $preset_key . ' へ切り替えた場合 => プラン / Plans のまま',
				'preset_key'          => $preset_key,
				'current'             => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'Plans',
				),
				'expected'            => array(
					'menu_label'        => 'プラン',
					'menu_label_plural' => 'Plans',
				),
			);
		}

		foreach ( $test_cases as $case ) {
			// 切り替え前（custom）の保存値と、切り替えの送信値を用意する。
			$current   = array_merge( $defaults, array( 'industry_preset' => 'custom' ), $case['current'] );
			$input     = array_merge( array( 'industry_preset' => $case['preset_key'] ), $case['current'] );
			$sanitized = array_merge( $current, $input );

			$result = Industry_Presets::apply_transition( $current, $sanitized, $input, $defaults );

			$this->assertSame( $case['expected']['menu_label'], $result['menu_label'], $case['test_condition_name'] . ' / menu_label' );
			$this->assertSame( $case['expected']['menu_label_plural'], $result['menu_label_plural'], $case['test_condition_name'] . ' / menu_label_plural' );
		}
	}

	/**
	 * 「表記」タブに呼び方の欄が、数量の見出しの直前・業種プリセットの目印なしで描画される。
	 * 複数形の欄は複数形のある言語の画面にだけ出る。
	 *
	 * @return void
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '日本語の画面 => 呼び方の欄だけ出る',
				'locale'              => 'ja',
				'expected'            => array(
					'has_plural_row' => false,
				),
			),
			array(
				'test_condition_name' => '英語の画面 => 呼び方の欄と複数形の欄が出る',
				'locale'              => 'en_US',
				'expected'            => array(
					'has_plural_row' => true,
				),
			),
		);

		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $admin_user_id );
			update_option( Settings_Repository::OPTION_KEY, array( 'menu_label' => 'プラン' ) );

			// 画面のロケールを切り替えて描画する。
			$locale        = $case['locale'];
			$locale_filter = static function () use ( $locale ) {
				return $locale;
			};
			add_filter( 'locale', $locale_filter );
			try {
				$page = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
				ob_start();
				$page->render_page();
				$output = (string) ob_get_clean();
			} finally {
				remove_filter( 'locale', $locale_filter );
			}

			// 呼び方の欄: 保存値・プレースホルダーが入り、行に業種プリセットの目印が付かない。
			$row = $this->get_row_containing( $output, 'id="vkbm-menu-label"' );
			$this->assertStringContainsString( 'value="プラン"', $row, $case['test_condition_name'] . ' / 保存値が入る' );
			$this->assertStringContainsString( 'placeholder="' . esc_attr( $this->default_label ) . '"', $row, $case['test_condition_name'] . ' / プレースホルダー' );
			$this->assertStringNotContainsString( 'data-industry-field', $row, $case['test_condition_name'] . ' / 業種プリセットの目印なし' );

			// 並び順: 詳細ボタンテキストの後、業種プリセットで隠れることのある数量の見出し・単位より前。
			$detail_button_position = strpos( $output, 'id="vkbm-menu-loop-detail-button-label"' );
			$menu_position          = strpos( $output, 'id="vkbm-menu-label"' );
			$count_label_position   = strpos( $output, 'id="vkbm-guests-count-label"' );
			$this->assertGreaterThan( (int) $detail_button_position, (int) $menu_position, $case['test_condition_name'] . ' / 詳細ボタンテキストより後' );
			$this->assertLessThan( (int) $count_label_position, (int) $menu_position, $case['test_condition_name'] . ' / 数量の見出しより前' );

			// 複数形の欄はロケールで出し分ける。
			$has_plural_row = false !== strpos( $output, 'id="vkbm-menu-label-plural"' );
			$this->assertSame( $case['expected']['has_plural_row'], $has_plural_row, $case['test_condition_name'] . ' / 複数形の欄' );
			if ( $has_plural_row ) {
				$plural_row = $this->get_row_containing( $output, 'id="vkbm-menu-label-plural"' );
				$this->assertStringNotContainsString( 'data-industry-field', $plural_row, $case['test_condition_name'] . ' / 複数形の行にも目印なし' );
			}
		}
	}

	/**
	 * 出力から、指定した文字列を含む表の行（<tr>〜</tr>）を取り出す。
	 *
	 * @param string $output 描画結果。
	 * @param string $needle 行に含まれる文字列。
	 * @return string 行の HTML（見つからない場合はテストを失敗させる）。
	 */
	private function get_row_containing( string $output, string $needle ): string {
		$needle_position = strpos( $output, $needle );
		$this->assertNotFalse( $needle_position, $needle . ' が描画されていること' );

		// needle より前で最後に現れる <tr と、後ろで最初に現れる </tr> で行を切り出す。
		$row_start = strrpos( substr( $output, 0, (int) $needle_position ), '<tr' );
		$row_end   = strpos( $output, '</tr>', (int) $needle_position );
		$this->assertNotFalse( $row_start, $needle . ' を含む行の開始タグがあること' );
		$this->assertNotFalse( $row_end, $needle . ' を含む行の終了タグがあること' );

		return substr( $output, (int) $row_start, (int) $row_end - (int) $row_start );
	}
}
