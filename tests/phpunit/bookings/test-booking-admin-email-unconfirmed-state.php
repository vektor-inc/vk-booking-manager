<?php
/**
 * issue #507: 予約タイトル横の「メール未確認」表示（display_post_states）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Auth\Email_Verification;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * @group bookings
 */
class Booking_Admin_Email_Unconfirmed_State_Test extends WP_UnitTestCase {

	/**
	 * add_email_unconfirmed_post_state() が、予約者の状態に応じて post state を
	 * 追加するかどうかを検証する。
	 */
	public function test_add_email_unconfirmed_post_state(): void {
		$booking_admin = new Booking_Admin();

		$test_cases = array(
			array(
				'test_condition_name' => '予約者が manual（手動承認・メール未確認）の場合 => 「メール未確認」を追加する',
				'author_status'       => Email_Verification::STATUS_MANUAL,
				'expect_state'        => true,
			),
			array(
				'test_condition_name' => '予約者が "1"（メールで認証済み）の場合 => 追加しない',
				'author_status'       => Email_Verification::STATUS_VERIFIED,
				'expect_state'        => false,
			),
			array(
				'test_condition_name' => '予約者が "0"（未認証）の場合 => 追加しない（対象は manual のみ）',
				'author_status'       => Email_Verification::STATUS_UNVERIFIED,
				'expect_state'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$author_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
			update_user_meta( $author_id, Email_Verification::META_STATUS, $case['author_status'] );

			$post = $this->factory()->post->create_and_get(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_author' => $author_id,
				)
			);

			$result = $booking_admin->add_email_unconfirmed_post_state( array(), $post );

			if ( $case['expect_state'] ) {
				$this->assertArrayHasKey( 'vkbm_email_unconfirmed', $result, $case['test_condition_name'] );
			} else {
				$this->assertArrayNotHasKey( 'vkbm_email_unconfirmed', $result, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 予約以外の投稿タイプには一切干渉しないことを確認する。
	 */
	public function test_add_email_unconfirmed_post_state_ignores_other_post_types(): void {
		$booking_admin = new Booking_Admin();

		$author_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $author_id, Email_Verification::META_STATUS, Email_Verification::STATUS_MANUAL );

		$post = $this->factory()->post->create_and_get(
			array(
				'post_type'   => 'post',
				'post_author' => $author_id,
			)
		);

		$existing = array( 'draft' => 'Draft' );
		$result   = $booking_admin->add_email_unconfirmed_post_state( $existing, $post );

		$this->assertSame( $existing, $result, '予約以外の投稿タイプは変更しない' );
	}
}
