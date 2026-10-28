<?php
/**
 * 匿名化済みの予約の編集画面（表示のみ・保存で書き換えない）のテスト（issue #870）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use DateTimeImmutable;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Admin の匿名化済みの予約まわりを検証する。
 *
 * @group bookings
 */
class Booking_Admin_Anonymized_Test extends WP_UnitTestCase {

	/**
	 * テスト後に $_POST を空に戻す。
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * 予約を管理する権限を持つユーザーに切り替える。
	 */
	private function switch_to_manager(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		get_user_by( 'id', $user_id )->add_cap( 'list_users' );
		wp_set_current_user( $user_id );
	}

	/**
	 * 終了済みの予約を作る。
	 *
	 * @param int $author_id 投稿者（予約したお客様）のユーザーID。
	 * @return int 予約の投稿ID。
	 */
	private function create_ended_booking( int $author_id ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => '山田太郎 / 2020.1.1 10:00',
			)
		);
		$ended      = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
		update_post_meta( $booking_id, '_vkbm_booking_service_start', $ended );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $ended );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'cancelled' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', '山田太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_tel', '09012345678' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', 'taro@example.com' );
		update_post_meta( $booking_id, '_vkbm_booking_attendee_name', '利用者太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_note', 'アレルギーがあります' );
		update_post_meta( $booking_id, '_vkbm_booking_internal_note', '常連のお客様' );

		return $booking_id;
	}

	/**
	 * 編集画面から、個人情報と予約者を入れ直す内容を送信して保存する。
	 *
	 * @param int $booking_id    予約の投稿ID。
	 * @param int $new_author_id 送信する予約者のユーザーID。
	 */
	private function save_with_personal_data( int $booking_id, int $new_author_id ): void {
		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'           => '2026-10-10',
				'start_time'     => '15:00',
				'end_time'       => '16:00',
				// キャンセルは担当スタッフの割り当て・競合判定の対象外なので、保存の内容だけを検証できる。
				'status'         => 'cancelled',
				'customer'       => '佐藤花子',
				'customer_tel'   => '08011112222',
				'customer_email' => 'hanako@example.com',
				'attendee_name'  => '復活させようとした利用者名',
				'note'           => '入れ直したメモ',
				'internal_note'  => '入れ直した運営側メモ',
				'author_id'      => $new_author_id,
			),
		);

		( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );
	}

	/**
	 * 匿名化済みの予約は、保存しても個人情報の欄と投稿者が書き換わらない。匿名化前は書き換わる。
	 */
	public function test_save_post_anonymized_booking(): void {
		$this->switch_to_manager();
		$new_author_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '匿名化済みの予約 => 個人情報の欄も投稿者も書き換えない',
				'anonymize'           => true,
				'expected'            => array(
					'author' => 0,
					'name'   => Booking_Anonymizer::get_deleted_label(),
					'tel'    => '',
					'email'  => '',
					'note'   => '',
					'inote'  => '',
					'attendee' => '',
				),
			),
			array(
				'test_condition_name' => '匿名化していない予約 => 送信した内容で書き換わる',
				'anonymize'           => false,
				'expected'            => array(
					'author' => $new_author_id,
					'name'   => '佐藤花子',
					'tel'    => '08011112222',
					'email'  => 'hanako@example.com',
					'note'   => '入れ直したメモ',
					'inote'  => '入れ直した運営側メモ',
					'attendee' => '復活させようとした利用者名',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$customer_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
			$booking_id  = $this->create_ended_booking( $customer_id );
			if ( $case['anonymize'] ) {
				( new Booking_Anonymizer() )->anonymize( $booking_id );
			}

			$this->save_with_personal_data( $booking_id, $new_author_id );

			$this->assertSame(
				$case['expected'],
				array(
					'author' => (int) get_post( $booking_id )->post_author,
					'name'   => get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ),
					'tel'    => get_post_meta( $booking_id, '_vkbm_booking_customer_tel', true ),
					'email'  => get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ),
					'note'   => get_post_meta( $booking_id, '_vkbm_booking_note', true ),
					'inote'  => get_post_meta( $booking_id, '_vkbm_booking_internal_note', true ),
					'attendee' => get_post_meta( $booking_id, '_vkbm_booking_attendee_name', true ),
				),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 編集画面: 匿名化済みなら注記を出し、個人情報の欄は入力欄にせず、予約者を選び直すボタンも出さない。
	 */
	public function test_render_meta_box_anonymized_booking(): void {
		$this->switch_to_manager();

		$test_cases = array(
			array(
				'test_condition_name' => '匿名化済みの予約 => 注記あり・入力欄なし・予約者の選び直しなし',
				'anonymize'           => true,
				'has_notice'          => true,
				'has_inputs'          => false,
			),
			array(
				'test_condition_name' => '匿名化していない予約 => 注記なし・入力欄あり・予約者の選び直しあり',
				'anonymize'           => false,
				'has_notice'          => false,
				'has_inputs'          => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$customer_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
			$booking_id  = $this->create_ended_booking( $customer_id );
			if ( $case['anonymize'] ) {
				( new Booking_Anonymizer() )->anonymize( $booking_id );
			}

			ob_start();
			( new Booking_Admin() )->render_meta_box( get_post( $booking_id ) );
			$html = (string) ob_get_clean();

			$this->assertSame( $case['has_notice'], str_contains( $html, 'notice notice-info inline' ), $case['test_condition_name'] . '（注記）' );
			foreach ( array( 'name="vkbm_booking[customer]"', 'name="vkbm_booking[customer_tel]"', 'name="vkbm_booking[customer_email]"', 'name="vkbm_booking[note]"', 'name="vkbm_booking[internal_note]"', 'data-vkbm-reserver-change' ) as $needle ) {
				$this->assertSame( $case['has_inputs'], str_contains( $html, $needle ), $case['test_condition_name'] . '（' . $needle . '）' );
			}
			if ( $case['anonymize'] ) {
				// 注記に並ぶ欄名は、匿名化の対象の定義から作られる。
				$this->assertStringContainsString( Booking_Anonymizer::get_field_labels_text(), $html );
				$this->assertStringContainsString( __( 'Anonymized', 'vk-booking-manager' ), $html, '予約者欄の表示' );
				$this->assertStringNotContainsString( '山田太郎', $html );
			}
		}
	}
}
