<?php
/**
 * ユーザー編集画面の名簿権限テスト。
 *
 * @package VKBookingManager
 */

use VKBookingManager\Attendees\Attendee_Roster;
use VKBookingManager\Attendees\Attendee_Roster_User_Profile;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;

/**
 * スタッフ向け編集権限を確認する。
 */
class Test_Attendee_Roster_User_Profile extends WP_UnitTestCase {
	/**
	 * 管理者を用意し、名簿機能を有効にする。
	 *
	 * @return int 管理者ユーザー ID。
	 */
	private function prepare_staff(): int {
		$staff_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $staff_id );
		$staff = get_userdata( $staff_id );
		$staff->add_cap( Capabilities::MANAGE_RESERVATIONS );
		if ( is_multisite() ) {
			grant_super_admin( $staff_id );
		}
		update_option( Settings_Repository::OPTION_KEY, array( 'attendee_roster_enabled' => true ) );
		return $staff_id;
	}

	/**
	 * 予約管理権限とユーザー編集権限の両方が必要なことを確認する。
	 *
	 * @return void
	 */
	public function test_can_edit(): void {
		$target_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$staff_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $staff_id );
		$staff = get_userdata( $staff_id );
		$staff->add_cap( Capabilities::MANAGE_RESERVATIONS );
		if ( is_multisite() ) {
			grant_super_admin( $staff_id );
		}
		$profile = new Attendee_Roster_User_Profile();
		$this->assertTrue( $profile->can_edit( $target_id ), '両方の権限があれば編集できる' );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$this->assertFalse( $profile->can_edit( $target_id ), '予約管理権限が無ければ編集できない' );
		$limited_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$limited    = get_userdata( $limited_id );
		$limited->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $limited_id );
		$this->assertFalse( $profile->can_edit( $target_id ), '対象ユーザーを編集できなければ編集できない' );
	}

	/**
	 * meta が無いユーザーの描画時基準は空配列になることを確認する。
	 *
	 * @return void
	 */
	public function test_render_uses_saved_roster_as_original(): void {
		$this->prepare_staff();
		$target  = get_userdata( self::factory()->user->create( array( 'display_name' => '対象者' ) ) );
		$profile = new Attendee_Roster_User_Profile();
		ob_start();
		$profile->render( $target );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vkbm_attendees_original" value="[]"', $html, 'meta が無い場合は空の保存済み名簿を基準にする' );
	}

	/**
	 * meta が無いユーザーを名簿未変更で更新してもエラーにならないことを確認する。
	 *
	 * @return void
	 */
	public function test_save_without_roster_meta_does_not_add_profile_error(): void {
		$this->prepare_staff();
		$target_id = self::factory()->user->create( array( 'display_name' => '対象者' ) );
		$previous  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト前のグローバルを退避する。
		$_POST     = array(
			'_vkbm_attendee_roster_nonce' => wp_create_nonce( 'vkbm_attendee_roster_user_profile' ),
			'vkbm_attendees_original'     => '[]',
			'vkbm_attendees'              => array(),
		);
		$errors    = new WP_Error();
		try {
			( new Attendee_Roster_User_Profile() )->save( $target_id );
			do_action( 'user_profile_update_errors', $errors, true, get_userdata( $target_id ) );
			$this->assertFalse( $errors->has_errors(), '名簿以外のユーザー更新を止めるエラーを追加しない' );
			$this->assertSame( 'self', ( new Attendee_Roster() )->get( $target_id )[0]['id'], '本人行を含む名簿を保存する' );
		} finally {
			$_POST = $previous; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後にグローバルを復元する。
		}
	}

	/**
	 * 描画時名簿が配列で送られても保存処理が停止しないことを確認する。
	 *
	 * @return void
	 */
	public function test_save_ignores_array_original_roster(): void {
		$this->prepare_staff();
		$target_id = self::factory()->user->create( array( 'display_name' => '対象者' ) );
		$previous  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト前のグローバルを退避する。
		$_POST     = array(
			'_vkbm_attendee_roster_nonce' => wp_create_nonce( 'vkbm_attendee_roster_user_profile' ),
			'vkbm_attendees_original'     => array( 'invalid' ),
			'vkbm_attendees'              => array(),
		);

		try {
			( new Attendee_Roster_User_Profile() )->save( $target_id );
			$this->assertSame( 'self', ( new Attendee_Roster() )->get( $target_id )[0]['id'], '不正な描画時名簿を空として扱い、本人行を保存する' );
		} finally {
			$_POST = $previous; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後にグローバルを復元する。
		}
	}
}
