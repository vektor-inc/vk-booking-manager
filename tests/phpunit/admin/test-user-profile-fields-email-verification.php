<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\User_Profile_Fields;
use VKBookingManager\Auth\Email_Verification;
use WP_UnitTestCase;

/**
 * issue #507: ユーザー編集画面での手動承認・取り消しの保存テスト。
 *
 * @group admin
 */
class User_Profile_Fields_Email_Verification_Test extends WP_UnitTestCase {

	/**
	 * 手動承認・取り消しチェックボックスの保存テスト（権限・自分自身への適用有無を含む）。
	 */
	public function test_save_fields_manual_approve_and_revoke(): void {
		$previous_post   = $_POST;
		$previous_user   = get_current_user_id();
		$fields          = new User_Profile_Fields();

		$test_cases = [
			[
				'test_condition_name' => '未認証の予約顧客に、edit_user権限を持つ別ユーザーが手動承認する場合 => manualに変わる',
				'target_role'         => 'subscriber',
				'initial_status'      => Email_Verification::STATUS_UNVERIFIED,
				'actor_role'          => 'administrator',
				'actor_is_self'       => false,
				'post_field'          => 'vkbm_email_verify_manual',
				'expected_status'     => Email_Verification::STATUS_MANUAL,
			],
			[
				'test_condition_name' => '手動承認済みの予約顧客を、edit_user権限を持つ別ユーザーが取り消す場合 => 0に戻る',
				'target_role'         => 'subscriber',
				'initial_status'      => Email_Verification::STATUS_MANUAL,
				'actor_role'          => 'administrator',
				'actor_is_self'       => false,
				'post_field'          => 'vkbm_email_verify_revoke_manual',
				'expected_status'     => Email_Verification::STATUS_UNVERIFIED,
			],
			[
				'test_condition_name' => '自分自身に対しては手動承認が適用されないこと（論点2）',
				'target_role'         => 'subscriber',
				'initial_status'      => Email_Verification::STATUS_UNVERIFIED,
				'actor_role'          => 'administrator',
				'actor_is_self'       => true,
				'post_field'          => 'vkbm_email_verify_manual',
				'expected_status'     => Email_Verification::STATUS_UNVERIFIED,
			],
			[
				'test_condition_name' => 'edit_user権限を持たないユーザーが操作しても適用されないこと',
				'target_role'         => 'subscriber',
				'initial_status'      => Email_Verification::STATUS_UNVERIFIED,
				'actor_role'          => 'subscriber',
				'actor_is_self'       => false,
				'post_field'          => 'vkbm_email_verify_manual',
				'expected_status'     => Email_Verification::STATUS_UNVERIFIED,
			],
		];

		try {
			foreach ( $test_cases as $case ) {
				$target_id = $this->factory()->user->create( [ 'role' => $case['target_role'] ] );
				update_user_meta( $target_id, Email_Verification::META_STATUS, $case['initial_status'] );

				if ( $case['actor_is_self'] ) {
					$actor_id = $target_id;
				} else {
					$actor_id = $this->factory()->user->create( [ 'role' => $case['actor_role'] ] );
				}
				wp_set_current_user( $actor_id );

				$_POST = [ $case['post_field'] => '1' ];

				$fields->save_fields( $target_id );

				$this->assertSame(
					$case['expected_status'],
					Email_Verification::get_status( $target_id ),
					$case['test_condition_name']
				);
			}
		} finally {
			$_POST = $previous_post;
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * 手動承認時に承認日時・承認者が保存されることのテスト。
	 */
	public function test_save_fields_manual_approve_records_approver_and_timestamp(): void {
		$previous_post = $_POST;
		$previous_user = get_current_user_id();

		try {
			$fields    = new User_Profile_Fields();
			$target_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
			$actor_id  = $this->factory()->user->create( [ 'role' => 'administrator' ] );
			update_user_meta( $target_id, Email_Verification::META_STATUS, Email_Verification::STATUS_UNVERIFIED );

			wp_set_current_user( $actor_id );
			$_POST = [ 'vkbm_email_verify_manual' => '1' ];

			$fields->save_fields( $target_id );

			$approval = Email_Verification::get_manual_approval_info( $target_id );
			$this->assertGreaterThan( 0, $approval['at'] );
			$this->assertSame( $actor_id, $approval['by'] );
		} finally {
			$_POST = $previous_post;
			wp_set_current_user( $previous_user );
		}
	}
}
