<?php
/**
 * 予約時の入力項目（#1147）の設定の保存経路を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 予約時の入力項目の保存・引き継ぎ・エラーを検証する。
 *
 * @group provider-settings
 */
class Settings_Service_Booking_Custom_Fields_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings_Service::save_settings() が予約時の入力項目を保存・引き継ぎ・消去することを検証する。
	 */
	public function test_save_settings(): void {
		$saved_fields = array(
			array(
				'id'       => 'cf_course',
				'label'    => '夕食のコース',
				'type'     => 'text',
				'required' => true,
			),
			array(
				'id'       => 'cf_allergy',
				'label'    => 'アレルギー',
				'type'     => 'textarea',
				'required' => false,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '項目を送らず別タブの項目だけを保存 => 保存済みの項目を引き継ぐ',
				'raw_settings'        => array( 'provider_name' => 'テスト旅館' ),
				'expected_ids'        => array( 'cf_course', 'cf_allergy' ),
				'expected_labels'     => array( '夕食のコース', 'アレルギー' ),
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '画面で並べ替え・項目名を変更して保存 => 送った順・ID は変えずに保存する',
				'raw_settings'        => array(
					'booking_custom_fields_submitted' => '1',
					'booking_custom_fields'           => array(
						'4' => array(
							'id'    => 'cf_allergy',
							'label' => 'アレルギー・苦手な食材',
							'type'  => 'textarea',
						),
						'0' => array(
							'id'       => 'cf_course',
							'label'    => '夕食のコース',
							'type'     => 'text',
							'required' => '1',
						),
					),
				),
				'expected_ids'        => array( 'cf_allergy', 'cf_course' ),
				'expected_labels'     => array( 'アレルギー・苦手な食材', '夕食のコース' ),
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '画面で全部削除して保存（印だけが届く） => 項目を空にする',
				'raw_settings'        => array( 'booking_custom_fields_submitted' => '1' ),
				'expected_ids'        => array(),
				'expected_labels'     => array(),
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '項目名が空の行を送る => エラーにして保存しない',
				'raw_settings'        => array(
					'booking_custom_fields_submitted' => '1',
					'booking_custom_fields'           => array(
						'0' => array(
							'id'    => '',
							'label' => '',
							'type'  => 'text',
						),
					),
				),
				'expected_ids'        => array( 'cf_course', 'cf_allergy' ),
				'expected_labels'     => array( '夕食のコース', 'アレルギー' ),
				'expect_error'        => true,
			),
		);

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		foreach ( $test_cases as $case ) {
			// 保存済みの項目を用意する。
			update_option( Settings_Repository::OPTION_KEY, array( 'booking_custom_fields' => $saved_fields ) );

			$result = $service->save_settings( $case['raw_settings'] );
			if ( $case['expect_error'] ) {
				$this->assertWPError( $result, $case['test_condition_name'] );
				// エラーは行の番号ごとに返し、画面の該当行に出せるようにする。
				$this->assertSame( array( '0' ), array_map( 'strval', array_keys( $result->get_error_data()['fields']['booking_custom_fields'] ) ), $case['test_condition_name'] );
			} else {
				$this->assertNotWPError( $result, $case['test_condition_name'] );
			}

			// 保存された項目の並び・ID・項目名と、印が保存されていないことを確かめる。
			$stored = get_option( Settings_Repository::OPTION_KEY );
			$this->assertSame( $case['expected_ids'], array_column( $stored['booking_custom_fields'], 'id' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_labels'], array_column( $stored['booking_custom_fields'], 'label' ), $case['test_condition_name'] );
			$this->assertArrayNotHasKey( 'booking_custom_fields_submitted', $stored, $case['test_condition_name'] . '（印は保存しない）' );
		}
	}
}
