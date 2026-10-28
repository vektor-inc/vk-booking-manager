<?php
/**
 * 宿泊と宿泊以外で「シフト」の表示名を切り替えるテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Shifts\Shift_Lodging_Editor;
use WP_UnitTestCase;

/**
 * 販売日の表示文言を検証する。
 *
 * @group admin
 * @group lodging
 */
class Lodging_Days_On_Sale_Labels_Test extends WP_UnitTestCase {

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
	 * 元の設定を保存する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_get      = $_GET;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 設定と投稿タイプを復元する。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		$_GET = $this->original_get;
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 業種プリセットを保存する。
	 *
	 * @param string $preset プリセットキー。
	 */
	private function set_preset( string $preset ): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $defaults, array( 'industry_preset' => $preset ) ) );
		Industry_Presets::clear_cache();
	}

	/**
	 * コールバックの出力を取得する。
	 *
	 * @param callable $callback 出力する処理。
	 * @return string 出力内容。
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * 自動登録通知の確認文を業種別に切り替えることを検証する。
	 */
	public function test_render_shift_auto_register_notice(): void {
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の登録内容を確認する文言になる',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition() ? 'registered shift information and the actual shifts' : 'registered days on sale information and the actual days on sale',
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフト確認文を維持する',
				'preset'              => 'custom',
				'expected'            => 'registered shift information and the actual shifts',
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$settings                               = get_option( Settings_Repository::OPTION_KEY, array() );
			$settings['shift_auto_register_months'] = 1;
			update_option( Settings_Repository::OPTION_KEY, $settings );
			set_current_screen( 'edit-vkbm_shift' );

			$output = $this->capture(
				static function (): void {
					( new Setup_Notices() )->render_shift_auto_register_notice();
				}
			);

			$this->assertStringContainsString( $case['expected'], $output, $case['test_condition_name'] );
		}
	}

	/**
	 * 投稿タイプのメニュー名とラベルを業種別に切り替えることを検証する。
	 */
	public function test_register_post_type(): void {
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日のラベルになる',
				'preset'              => 'lodging',
				'expected_menu'       => Pro_Upsell::is_free_edition() ? 'BM Shift' : 'BM Days on Sale',
				'expected_name'       => Pro_Upsell::is_free_edition() ? 'Shift' : 'Days on Sale',
				'expected_add'        => Pro_Upsell::is_free_edition() ? 'Add Shift' : 'Add Day on Sale',
				'expected_search'     => Pro_Upsell::is_free_edition() ? 'Search Shift' : 'Search Days on Sale',
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフトのラベルを維持する',
				'preset'              => 'custom',
				'expected_menu'       => 'BM Shift',
				'expected_name'       => 'Shift',
				'expected_add'        => 'Add Shift',
				'expected_search'     => 'Search Shift',
			),
		);

		foreach ( $cases as $case ) {
			if ( post_type_exists( Shift_Post_Type::POST_TYPE ) ) {
				unregister_post_type( Shift_Post_Type::POST_TYPE );
			}
			$this->set_preset( $case['preset'] );
			( new Shift_Post_Type() )->register_post_type();
			$post_type = get_post_type_object( Shift_Post_Type::POST_TYPE );

			$this->assertNotNull( $post_type, $case['test_condition_name'] );
			$this->assertSame( $case['expected_menu'], $post_type->labels->menu_name, $case['test_condition_name'] );
			$this->assertSame( $case['expected_name'], $post_type->labels->name, $case['test_condition_name'] );
			$this->assertSame( $case['expected_add'], $post_type->labels->add_new_item, $case['test_condition_name'] );
			$this->assertSame( $case['expected_search'], $post_type->labels->search_items, $case['test_condition_name'] );
		}
	}

	/**
	 * 未登録通知の見出し・本文・ボタンを業種別に切り替えることを検証する。
	 */
	public function test_render_shift_notice_markup(): void {
		$method = new ReflectionMethod( Setup_Notices::class, 'render_shift_notice_markup' );
		$cases  = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の未登録通知を表示する',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition() ? array( 'Shift not registered', '10 Monthly shift is not registered.', 'Register shift' ) : array( 'Days on Sale not registered', 'Days on sale for month 10 are not registered.', 'Register days on sale' ),
			),
			array(
				'test_condition_name' => 'カスタム => 従来の未登録通知を表示する',
				'preset'              => 'custom',
				'expected'            => array( 'Shift not registered', '10 Monthly shift is not registered.', 'Register shift' ),
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$output = $this->capture(
				static function () use ( $method ): void {
					$method->invoke(
						new Setup_Notices(),
						array(
							array(
								'year'  => 2026,
								'month' => 10,
							),
						),
						true
					);
				}
			);
			foreach ( $case['expected'] as $expected ) {
				$this->assertStringContainsString( $expected, $output, $case['test_condition_name'] );
			}
			if ( 'lodging' === $case['preset'] && ! Pro_Upsell::is_free_edition() ) {
				$booking_guidance = sprintf(
					/* translators: %s: resource label (plural). */
					__( '%s cannot be booked for that month until they are registered.', 'vk-booking-manager' ),
					vkbm_get_resource_label_plural()
				);
				$this->assertStringContainsString( $booking_guidance, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 設定項目のラベルを業種別に切り替えることを検証する。
	 */
	public function test_render_provider_settings_labels(): void {
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の設定ラベルになる',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition() ? array( 'Alert when shift is not registered', 'Shift auto registration' ) : array( 'Alert when days on sale are not registered', 'Automatic registration of days on sale' ),
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフトの設定ラベルを維持する',
				'preset'              => 'custom',
				'expected'            => array( 'Alert when shift is not registered', 'Shift auto registration' ),
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$_GET['tab'] = 'system'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 描画対象タブの指定だけに使う。
			$page        = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
			$output      = $this->capture(
				static function () use ( $page ): void {
					$page->render_page();
				}
			);
			foreach ( $case['expected'] as $expected ) {
				$this->assertStringContainsString( $expected, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 営業時間設定の祝日行の説明を業種別に切り替えることを検証する。
	 */
	public function test_render_business_hours_guidance(): void {
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日画面と販売日作成の説明になる',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition()
					? array( 'shift screen', 'not currently used when creating shifts', 'Holidays and days before holidays follow the regular weekday hours.' )
					: array( 'days on sale screen', 'not currently used when creating days on sale', 'Days on sale for holidays and days before holidays are created using the regular weekday hours.' ),
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフト説明を維持する',
				'preset'              => 'custom',
				'expected'            => array( 'shift screen', 'not currently used when creating shifts', 'Holidays and days before holidays follow the regular weekday hours.' ),
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$_GET['tab'] = 'store'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 描画対象タブの指定だけに使う。
			$page        = new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
			$output      = $this->capture(
				static function () use ( $page ): void {
					$page->render_page();
				}
			);
			foreach ( $case['expected'] as $expected ) {
				$this->assertStringContainsString( $expected, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 一括追加の文言と宿泊編集画面の説明を業種別に切り替えることを検証する。
	 */
	public function test_render_bulk_create_labels(): void {
		set_current_screen( 'edit-' . Shift_Post_Type::POST_TYPE );
		self::factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の一括追加文言になる',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition()
					? array( 'Add shifts in bulk', 'Register draft shifts' )
					: array( 'Add days on sale in bulk', 'Register draft days on sale' ),
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフト文言を維持する',
				'preset'              => 'custom',
				'expected'            => array( 'Add shifts in bulk', 'Register draft shifts' ),
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$output = $this->capture(
				static function (): void {
					( new Shift_Editor() )->render_bulk_create_panel();
				}
			);
			foreach ( $case['expected'] as $expected ) {
				$this->assertStringContainsString( $expected, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 一括追加の結果メッセージを業種別に切り替えることを検証する。
	 */
	public function test_render_bulk_create_notice_labels(): void {
		set_current_screen( 'edit-' . Shift_Post_Type::POST_TYPE );
		$cases = array(
			array(
				'test_condition_name' => '宿泊 => 販売日の成功・失敗メッセージになる',
				'preset'              => 'lodging',
				'expected_success'    => Pro_Upsell::is_free_edition() ? 'Shifts for 10 month of 2026' : 'Days on sale for 10 month of 2026',
				'expected_error'      => Pro_Upsell::is_free_edition() ? 'Bulk registration of shifts failed.' : 'Bulk registration of days on sale failed.',
			),
			array(
				'test_condition_name' => 'カスタム => 従来のシフト成功・失敗メッセージを維持する',
				'preset'              => 'custom',
				'expected_success'    => 'Shifts for 10 month of 2026',
				'expected_error'      => 'Bulk registration of shifts failed.',
			),
		);

		foreach ( $cases as $case ) {
			$this->set_preset( $case['preset'] );
			$_GET           = array(
				'vkbm_shift_bulk_created' => '2',
				'vkbm_shift_bulk_skipped' => '1',
				'vkbm_shift_bulk_year'    => '2026',
				'vkbm_shift_bulk_month'   => '10',
			);
			$success_output = $this->capture(
				static function (): void {
					( new Shift_Editor() )->render_bulk_create_notice();
				}
			);
			$this->assertStringContainsString( $case['expected_success'], $success_output, $case['test_condition_name'] );

			$_GET         = array( 'vkbm_shift_bulk_error' => 'invalid_period' );
			$error_output = $this->capture(
				static function (): void {
					( new Shift_Editor() )->render_bulk_create_notice();
				}
			);
			$this->assertStringContainsString( $case['expected_error'], $error_output, $case['test_condition_name'] );
		}
	}

	/**
	 * 宿泊編集画面から旧「シフト」の注記を削除したことを検証する。
	 */
	public function test_render_lodging_days_editor_description(): void {
		$this->set_preset( 'lodging' );
		$output = $this->capture(
			static function (): void {
				( new Shift_Lodging_Editor() )->render_days_editor( '{}' );
			}
		);

		$this->assertStringNotContainsString( 'For lodging, shifts are used', $output );
		$this->assertStringContainsString( 'Check the days this', $output );
	}

	/**
	 * シフト・予約表のメニュー名を業種別に切り替えることを検証する。
	 */
	public function test_register_menu(): void {
		$original_menu = $GLOBALS['menu'] ?? null;
		$cases         = array(
			array(
				'test_condition_name' => '宿泊 => 販売日・予約表',
				'preset'              => 'lodging',
				'expected'            => Pro_Upsell::is_free_edition() ? 'BM shift/reservation' : 'BM days on sale/reservation',
			),
			array(
				'test_condition_name' => 'カスタム => シフト・予約表',
				'preset'              => 'custom',
				'expected'            => 'BM shift/reservation',
			),
		);

		try {
			foreach ( $cases as $case ) {
				$this->set_preset( $case['preset'] );
				$GLOBALS['menu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- register_menu() の出力だけを分離して検証する。
				( new Shift_Dashboard_Page() )->register_menu();
				$this->assertContains( $case['expected'], wp_list_pluck( $GLOBALS['menu'], 0 ), $case['test_condition_name'] );
			}
		} finally {
			if ( null === $original_menu ) {
				unset( $GLOBALS['menu'] );
			} else {
				$GLOBALS['menu'] = $original_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のグローバルを復元する。
			}
		}
	}
}
