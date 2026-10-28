<?php
/**
 * 予約のご利用になる方の表示値のテスト。
 *
 * @package VKBookingManager
 */

use VKBookingManager\Attendees\Attendee_Roster;
use VKBookingManager\Attendees\Booking_Attendee_Display;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * Booking_Attendee_Display の表示値を確認する。
 *
 * @group attendees
 */
class Test_Booking_Attendee_Display extends WP_UnitTestCase {
	/**
	 * 家族の UUID。
	 */
	private const FAMILY_ID = '11111111-1111-4111-8111-111111111111';

	/**
	 * 番号の写しがない場合は名簿の今の番号を使い、手入力の予約では使わないことを確認する。
	 *
	 * @return void
	 */
	public function test_get(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		( new Attendee_Roster() )->save(
			$owner_id,
			array(
				array(
					'id'     => 'self',
					'name'   => '本人',
					'kana'   => '',
					'number' => 'S-1',
				),
				array(
					'id'     => self::FAMILY_ID,
					'name'   => '家族',
					'kana'   => '',
					'number' => 'F-2',
				),
			)
		);

		$cases = array(
			array(
				'test_condition_name' => '番号の写しがある => 写しを優先する',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'     => self::FAMILY_ID,
					'_vkbm_booking_attendee_name'   => '家族',
					'_vkbm_booking_attendee_number' => 'OLD-2',
				),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '家族',
					'number' => 'OLD-2',
				),
			),
			array(
				'test_condition_name' => '名簿の人で写しが無い => 名簿の今の番号を出す',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'   => self::FAMILY_ID,
					'_vkbm_booking_attendee_name' => '家族',
				),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '家族',
					'number' => 'F-2',
				),
			),
			array(
				'test_condition_name' => '本人の予約で写しが無い => 名簿の本人の番号を出す',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'   => 'self',
					'_vkbm_booking_attendee_name' => '本人',
				),
				'expected'            => array(
					'id'     => 'self',
					'name'   => '本人',
					'number' => 'S-1',
				),
			),
			array(
				'test_condition_name' => 'ID も名前も無い過去予約 => 本人扱いで名簿の本人の番号を出す',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(),
				'expected'            => array(
					'id'     => '',
					'name'   => '',
					'number' => 'S-1',
				),
			),
			array(
				'test_condition_name' => 'ID が無く名前だけがある過去予約 => 手入力として番号は出さない',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_name' => 'ポチ',
				),
				'expected'            => array(
					'id'     => '',
					'name'   => 'ポチ',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '手入力（manual）で写しが無い => 名簿の番号は出さない',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'   => 'manual',
					'_vkbm_booking_attendee_name' => '知人',
				),
				'expected'            => array(
					'id'     => 'manual',
					'name'   => '知人',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '番号機能が無効 => 名簿の今の番号は出さない',
				'settings'            => array( 'attendee_number_enabled' => false ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'   => self::FAMILY_ID,
					'_vkbm_booking_attendee_name' => '家族',
				),
				'expected'            => array(
					'id'     => self::FAMILY_ID,
					'name'   => '家族',
					'number' => '',
				),
			),
			array(
				'test_condition_name' => '名簿から削除された人 => 番号は空',
				'settings'            => array( 'attendee_number_enabled' => true ),
				'meta'                => array(
					'_vkbm_booking_attendee_id'   => '99999999-9999-4999-8999-999999999999',
					'_vkbm_booking_attendee_name' => '削除済みの人',
				),
				'expected'            => array(
					'id'     => '99999999-9999-4999-8999-999999999999',
					'name'   => '削除済みの人',
					'number' => '',
				),
			),
		);

		foreach ( $cases as $case ) {
			$booking_id = self::factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $owner_id,
				)
			);
			foreach ( $case['meta'] as $key => $value ) {
				update_post_meta( $booking_id, $key, $value );
			}
			$settings = array_merge( ( new Settings_Repository() )->get_settings(), $case['settings'] );

			$this->assertSame( $case['expected'], Booking_Attendee_Display::get( $booking_id, $settings ), $case['test_condition_name'] );
		}
	}

	/**
	 * 名前と番号を1つの表示文字列にまとめることを確認する。
	 *
	 * @return void
	 */
	public function test_format_summary(): void {
		$cases = array(
			array(
				'test_condition_name' => '名前と番号がある => 名前（番号）',
				'name'                => '山田 太郎',
				'number'              => '00123',
				'expected'            => '山田 太郎（00123）',
			),
			array(
				'test_condition_name' => '番号が無い => 名前だけ',
				'name'                => '山田 太郎',
				'number'              => '',
				'expected'            => '山田 太郎',
			),
			array(
				'test_condition_name' => '名前が無く番号だけがある => 番号だけ',
				'name'                => '',
				'number'              => '00123',
				'expected'            => '00123',
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Attendee_Display::format_summary( $case['name'], $case['number'] ), $case['test_condition_name'] );
		}
	}
}
