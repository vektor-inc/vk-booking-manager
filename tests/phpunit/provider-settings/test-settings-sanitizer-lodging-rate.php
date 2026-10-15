<?php
/**
 * 宿泊プランの休前日の曜日・特定日（lodging_pre_holiday_weekdays / lodging_special_dates）の保存に関するテスト（#737）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Error;
use WP_UnitTestCase;

/**
 * 休前日の曜日と特定日のサニタイズ・検証・保存済みの値の引き継ぎを検証する。
 *
 * @group provider-settings
 * @group lodging
 */
class Settings_Sanitizer_Lodging_Rate_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
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
	 * テスト後に全体設定を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		parent::tearDown();
	}

	/**
	 * 既定値: 休前日の曜日は土曜のみ、特定日は未登録。
	 */
	public function test_default_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertSame( array( 'sat' ), $defaults['lodging_pre_holiday_weekdays'], '休前日とする曜日の初期値は土曜のみ' );
		$this->assertSame( array(), $defaults['lodging_special_dates'], '特定日の初期値は未登録' );
	}

	/**
	 * sanitize: 休前日の曜日と特定日の保存規則と、特定日のエラー（終了日が開始日より前など）を検証する。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '未送信 => 既定値（土曜のみ・特定日なし）',
				'input'               => array(),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(),
				'expected_error_rows' => array(),
			),
			array(
				'test_condition_name' => '金曜・土曜を選択 => fri, sat',
				'input'               => array( 'lodging_pre_holiday_weekdays' => array( '', 'sat', 'fri' ) ),
				'expected_weekdays'   => array( 'fri', 'sat' ),
				'expected_special'    => array(),
				'expected_error_rows' => array(),
			),
			array(
				'test_condition_name' => 'チェックが 1 つも無い（空の値だけ送信）=> 空配列（既定の土曜に戻さない）',
				'input'               => array( 'lodging_pre_holiday_weekdays' => array( '' ) ),
				'expected_weekdays'   => array(),
				'expected_special'    => array(),
				'expected_error_rows' => array(),
			),
			array(
				'test_condition_name' => '特定日: 名称は任意。開始日の昇順に保存し、空の行は捨てる',
				'input'               => array(
					'lodging_special_dates' => array(
						0 => array(
							'start' => '2026-12-28',
							'end'   => '2027-01-03',
							'label' => '年末年始',
						),
						1 => array(
							'start' => '',
							'end'   => '',
							'label' => '',
						),
						2 => array(
							'start' => '2026-08-13',
							'end'   => '2026-08-16',
							'label' => '',
						),
					),
				),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(
					array(
						'start' => '2026-08-13',
						'end'   => '2026-08-16',
						'label' => '',
					),
					array(
						'start' => '2026-12-28',
						'end'   => '2027-01-03',
						'label' => '年末年始',
					),
				),
				'expected_error_rows' => array(),
			),
			array(
				'test_condition_name' => '特定日: 開始日と終了日が同じ日は有効',
				'input'               => array(
					'lodging_special_dates' => array(
						array(
							'start' => '2026-05-03',
							'end'   => '2026-05-03',
							'label' => '',
						),
					),
				),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(
					array(
						'start' => '2026-05-03',
						'end'   => '2026-05-03',
						'label' => '',
					),
				),
				'expected_error_rows' => array(),
			),
			array(
				'test_condition_name' => '特定日: 終了日が開始日より前の行はエラー（行の番号 1 にエラー）。ほかの行は検証される',
				'input'               => array(
					'lodging_special_dates' => array(
						0 => array(
							'start' => '2026-08-13',
							'end'   => '2026-08-16',
							'label' => '',
						),
						1 => array(
							'start' => '2026-05-06',
							'end'   => '2026-05-03',
							'label' => 'GW',
						),
					),
				),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(
					array(
						'start' => '2026-08-13',
						'end'   => '2026-08-16',
						'label' => '',
					),
				),
				'expected_error_rows' => array( '1' ),
			),
			array(
				'test_condition_name' => '特定日: 開始日だけ・終了日だけの行はエラー',
				'input'               => array(
					'lodging_special_dates' => array(
						3 => array(
							'start' => '2026-05-03',
							'end'   => '',
							'label' => '',
						),
						4 => array(
							'start' => '',
							'end'   => '2026-05-03',
							'label' => '',
						),
					),
				),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(),
				'expected_error_rows' => array( '3', '4' ),
			),
			array(
				'test_condition_name' => '特定日: 実在しない日付の行はエラー',
				'input'               => array(
					'lodging_special_dates' => array(
						0 => array(
							'start' => '2026-02-30',
							'end'   => '2026-03-02',
							'label' => '',
						),
					),
				),
				'expected_weekdays'   => array( 'sat' ),
				'expected_special'    => array(),
				'expected_error_rows' => array( '0' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$sanitizer = new Settings_Sanitizer();
			$result    = $sanitizer->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected_weekdays'], $result['lodging_pre_holiday_weekdays'], $case['test_condition_name'] . ': 休前日の曜日' );
			$this->assertSame( $case['expected_special'], $result['lodging_special_dates'], $case['test_condition_name'] . ': 特定日' );

			$field_errors = $sanitizer->get_field_errors();
			$error_rows   = isset( $field_errors['lodging_special_dates'] ) ? array_map( 'strval', array_keys( $field_errors['lodging_special_dates'] ) ) : array();
			$this->assertSame( $case['expected_error_rows'], $error_rows, $case['test_condition_name'] . ': エラーの行' );
			$this->assertSame( array() !== $case['expected_error_rows'], array() !== $sanitizer->get_errors(), $case['test_condition_name'] . ': 保存を止めるエラーの有無' );
		}
	}

	/**
	 * save_settings: エラーのとき保存しない。欄が送られない保存では保存済みの値を引き継ぎ、曜日を空にした保存は空で保存する。
	 */
	public function test_save_settings(): void {
		$repository = new Settings_Repository();
		$service    = new Settings_Service( $repository, new Settings_Sanitizer() );

		// 休前日の曜日と特定日を保存する。
		$saved_special = array(
			array(
				'start' => '2026-12-28',
				'end'   => '2027-01-03',
				'label' => '年末年始',
			),
		);
		$result        = $service->save_settings(
			array(
				'lodging_pre_holiday_weekdays' => array( '', 'fri', 'sat' ),
				'lodging_special_dates'        => $saved_special,
			)
		);
		$this->assertTrue( $result, '休前日の曜日と特定日が保存できる' );
		$settings = $repository->get_settings();
		$this->assertSame( array( 'fri', 'sat' ), $settings['lodging_pre_holiday_weekdays'], '保存した曜日' );
		$this->assertSame( $saved_special, $settings['lodging_special_dates'], '保存した特定日' );

		// 欄が送られない保存（別のタブ・宿泊以外の業種など）では、保存済みの値を消さない。
		$result = $service->save_settings( array( 'provider_name' => '別の保存' ) );
		$this->assertTrue( $result, '欄が無い保存も成功する' );
		$settings = $repository->get_settings();
		$this->assertSame( array( 'fri', 'sat' ), $settings['lodging_pre_holiday_weekdays'], '欄が送られない保存で曜日を引き継ぐ' );
		$this->assertSame( $saved_special, $settings['lodging_special_dates'], '欄が送られない保存で特定日を引き継ぐ' );

		// 終了日が開始日より前の行があると保存しない（保存済みの値は変わらない）。
		$result = $service->save_settings(
			array(
				'lodging_pre_holiday_weekdays' => array( '', 'sun' ),
				'lodging_special_dates'        => array(
					array(
						'start' => '2026-05-06',
						'end'   => '2026-05-03',
						'label' => '',
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result, '終了日が開始日より前の行があると保存エラー' );
		$settings = $repository->get_settings();
		$this->assertSame( array( 'fri', 'sat' ), $settings['lodging_pre_holiday_weekdays'], 'エラーのとき曜日は保存されない' );
		$this->assertSame( $saved_special, $settings['lodging_special_dates'], 'エラーのとき特定日は保存されない' );

		// 曜日を 1 つも選ばず、特定日をすべて消した保存は、空で保存する。
		$result = $service->save_settings(
			array(
				'lodging_pre_holiday_weekdays' => array( '' ),
				'lodging_special_dates'        => array(
					array(
						'start' => '',
						'end'   => '',
						'label' => '',
					),
				),
			)
		);
		$this->assertTrue( $result, '空にした保存も成功する' );
		$settings = $repository->get_settings();
		$this->assertSame( array(), $settings['lodging_pre_holiday_weekdays'], '曜日を 1 つも選ばない保存は空で保存する' );
		$this->assertSame( array(), $settings['lodging_special_dates'], '特定日をすべて消した保存は空で保存する' );
	}
}
