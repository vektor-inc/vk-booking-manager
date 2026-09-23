<?php
/**
 * Setup_Notices::render_shift_auto_register_notice() のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * シフト一覧画面でのみ表示されること・権限に応じてリンクの有無が変わることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Shift_Auto_Register_Test extends WP_UnitTestCase {
	private const CRON_HOOK = 'vkbm_shift_auto_register_daily';

	/**
	 * 各テスト後に設定・WP-Cron の予約・現在の画面・現在のユーザーをリセットし、他のテストへ影響しないようにする。
	 * 設定の保存は Shift_Editor 側の update_option_/add_option_ フックで自動的にスケジュールを
	 * 登録するため、あわせて解除しておく。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * シフト一覧画面（edit-vkbm_shift）では表示され、個別編集画面（vkbm_shift）では表示されないことを検証する。
	 * 安藤レビュー指摘（項目1・項目13）: post.php / post-new.php 相当の画面に誤って表示されないことの確認。
	 */
	public function test_render_shift_auto_register_notice_screen(): void {
		$admin_id = $this->create_user_with_provider_settings_access();
		wp_set_current_user( $admin_id );
		$this->save_auto_register_setting( 1 );

		$test_cases = array(
			array(
				'test_condition_name' => 'シフト一覧画面（edit-vkbm_shift） => 注意喚起が表示される',
				'screen_hook'         => 'edit-vkbm_shift',
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => 'シフト個別編集画面（vkbm_shift。post.php/post-new.php相当） => 注意喚起は表示されない',
				'screen_hook'         => 'vkbm_shift',
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '無関係な画面（edit-post） => 注意喚起は表示されない',
				'screen_hook'         => 'edit-post',
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			set_current_screen( $case['screen_hook'] );

			$output = $this->capture(
				function () {
					( new Setup_Notices() )->render_shift_auto_register_notice();
				}
			);

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * BM設定への権限（MANAGE_PROVIDER_SETTINGS）が無い利用者には、リンクにせず文字だけで表示することを検証する
	 * （安藤レビュー指摘 項目11・植草レビュー指摘）。
	 */
	public function test_render_shift_auto_register_notice_capability(): void {
		$this->save_auto_register_setting( 1 );

		$test_cases = array(
			array(
				'test_condition_name' => 'BM設定を閲覧できる利用者 => 「シフトの自動登録」がリンクになる',
				'user_id_getter'      => array( $this, 'create_user_with_provider_settings_access' ),
				'expect_link'         => true,
			),
			array(
				'test_condition_name' => 'BM設定を閲覧できない利用者（購読者） => 「シフトの自動登録」は文字のみで表示される',
				'user_id_getter'      => array( $this, 'create_user_without_provider_settings_access' ),
				'expect_link'         => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = call_user_func( $case['user_id_getter'] );
			wp_set_current_user( $user_id );
			set_current_screen( 'edit-vkbm_shift' );

			$output = $this->capture(
				function () {
					( new Setup_Notices() )->render_shift_auto_register_notice();
				}
			);

			$this->assertStringContainsString( 'Shift auto registration', $output, $case['test_condition_name'] );

			if ( $case['expect_link'] ) {
				$this->assertStringContainsString( '<a href=', $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( '<a href=', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * コールバックの出力を取得する。
	 *
	 * @param callable $callback 出力する処理.
	 * @return string 出力内容.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * BM設定への権限を持つ管理者ユーザーを作成する。
	 *
	 * @return int ユーザーID.
	 */
	private function create_user_with_provider_settings_access(): int {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		}

		return $user_id;
	}

	/**
	 * BM設定への権限を持たない購読者ユーザーを作成する。
	 *
	 * @return int ユーザーID.
	 */
	private function create_user_without_provider_settings_access(): int {
		return $this->factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * shift_auto_register_months 設定を保存する。
	 *
	 * @param int $value 保存する月数.
	 */
	private function save_auto_register_setting( int $value ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'shift_auto_register_months' => $value )
		);
	}
}
