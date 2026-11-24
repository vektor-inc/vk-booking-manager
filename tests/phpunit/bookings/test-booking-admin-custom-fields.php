<?php
/**
 * 予約の編集画面から、予約時の入力項目（#1147）の回答を保存する処理を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Booking_Admin::update_custom_fields_meta() のテスト。
 *
 * @group bookings
 */
class Booking_Admin_Custom_Fields_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 予約の編集画面から送られた回答で記録を作り直すことを検証する。
	 */
	public function test_update_custom_fields_meta(): void {
		// 今の設定の項目。
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'booking_custom_fields' => array(
					array(
						'id'       => 'cf_course',
						'label'    => '夕食のコース',
						'type'     => 'text',
						'required' => true,
					),
				),
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '削除済みの選択肢でも過去の回答をテキストとして編集できる',
				'saved'               => array( array( 'id' => 'cf_course', 'label' => '旧コース', 'type' => 'select', 'value' => '竹' ) ),
				'raw'                 => array( 'cf_course' => '竹コース' ),
				'expected'            => array( array( 'id' => 'cf_course', 'label' => '旧コース', 'type' => 'select', 'value' => '竹コース' ) ),
			),
			array(
				'test_condition_name' => '記録のある予約 => 当時の項目名のまま値を差し替え、今の設定の項目を後ろに足す',
				'saved'               => array(
					array(
						'id'    => 'cf_old',
						'label' => '当時の項目',
						'type'  => 'textarea',
						'value' => '当時の回答',
					),
				),
				'raw'                 => array(
					'cf_old'    => "直した\n回答",
					'cf_course' => '和食',
				),
				'expected'            => array(
					array(
						'id'    => 'cf_old',
						'label' => '当時の項目',
						'type'  => 'textarea',
						'value' => "直した\n回答",
					),
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '和食',
					),
				),
			),
			array(
				'test_condition_name' => '記録の無い予約で回答を入力 => 今の設定の項目で記録を作る',
				'saved'               => null,
				'raw'                 => array( 'cf_course' => '洋食' ),
				'expected'            => array(
					array(
						'id'    => 'cf_course',
						'label' => '夕食のコース',
						'type'  => 'text',
						'value' => '洋食',
					),
				),
			),
			array(
				'test_condition_name' => '記録の無い予約で回答が空 => 空の記録を作らない',
				'saved'               => null,
				'raw'                 => array( 'cf_course' => '' ),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// 予約を作り、保存済みの記録を用意する。
			$booking_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			if ( null !== $case['saved'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_custom_fields', $case['saved'] );
			}

			Booking_Admin::update_custom_fields_meta( $booking_id, $case['raw'] );

			$this->assertSame( $case['expected'], get_post_meta( $booking_id, '_vkbm_booking_custom_fields', true ), $case['test_condition_name'] );
		}
	}
}
