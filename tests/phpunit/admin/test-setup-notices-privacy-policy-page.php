<?php
/**
 * Setup_Notices のプライバシーポリシーページ関連（issue #1168）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * プライバシーポリシーページに選んだ固定ページが使えないとき、管理画面の上部に警告が出ることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Privacy_Policy_Page_Test extends WP_UnitTestCase {

	/**
	 * 文言を英語（原文）で検証できるようにする。
	 */
	public function set_up(): void {
		parent::set_up();
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
	}

	/**
	 * 基本設定オプション・クエリ文字列・ログイン・言語を元に戻す。
	 */
	public function tear_down(): void {
		$_GET = array();
		delete_option( Settings_Repository::OPTION_KEY );
		wp_set_current_user( 0 );
		restore_previous_locale();
		parent::tear_down();
	}

	/**
	 * 固定ページを1件作る。
	 *
	 * @param string $status 公開状態。
	 * @param string $title  タイトル。
	 * @return int 固定ページ ID。
	 */
	private function create_page( string $status = 'publish', string $title = 'Privacy' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * register() が、プライバシーポリシーページの警告を管理画面の通知に登録することを検証する。
	 */
	public function test_register(): void {
		$notices = new Setup_Notices();
		$notices->register();

		$this->assertNotFalse( has_action( 'admin_notices', array( $notices, 'render_privacy_policy_page_problem_notice' ) ), 'プライバシーポリシーページの警告を admin_notices に登録する' );
	}

	/**
	 * render_privacy_policy_page_problem_notice() が、権限のある利用者に、ページ指定で選んだ固定ページが使えないときだけ
	 * （同意項目タブを除く）警告を出すことを検証する。
	 */
	public function test_render_privacy_policy_page_problem_notice(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）。
		( new Roles_Manager() )->sync_roles();
		$owner_id     = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$published_id = $this->create_page();
		$draft_id     = $this->create_page( 'draft', 'Draft Privacy Page' );
		$trash_id     = $this->create_page( 'trash', 'Trashed Privacy Page' );
		$deleted_id   = $this->create_page();
		wp_delete_post( $deleted_id, true );
		$field_href = 'admin.php?page=vkbm-provider-settings&#038;tab=consent#vkbm-provider-privacy-policy-page';

		$test_cases = array(
			array(
				'test_condition_name' => '下書きの固定ページを選択・管理者 => ページ名入りの警告。ボタンはそのページの編集画面へ',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Draft Privacy Page', 'Please publish the page.' ),
				'expect_href'         => 'post.php?post=' . $draft_id,
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページを選択（URL も残っている）・管理者 => ゴミ箱から戻して公開することを勧める。ボタンはゴミ箱一覧へ',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $trash_id,
					'provider_privacy_policy_url'     => 'https://external.example.com/privacy/',
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Trashed Privacy Page', 'Please restore the page from the trash and publish it.' ),
				'expect_href'         => 'edit.php?post_status=trash&#038;post_type=page',
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・業務オーナー（固定ページの権限なし） => 選び直しを勧める。ボタンは同意項目タブの欄へ',
				'user_id'             => $owner_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Draft Privacy Page', 'Please select the privacy policy page again.', 'Set the privacy policy page' ),
				'unexpect_text'       => 'publish the page',
				'expect_href'         => $field_href,
			),
			array(
				'test_condition_name' => '削除された固定ページを選択・管理者 => 選び直しを勧める。ボタンは同意項目タブの欄へ',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $deleted_id,
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'The page selected as the privacy policy page has been deleted.', 'Please select the privacy policy page again.' ),
				'expect_href'         => $field_href,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・同意項目タブ => 欄の中の警告と重なるので出さない',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'screen'              => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'consent',
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・BM 設定の他のタブ（system） => 出す',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'screen'              => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'system',
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Draft Privacy Page' ),
				'expect_href'         => 'post.php?post=' . $draft_id,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・他の画面で tab=consent => 出す',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'screen'              => array(
					'page' => 'vkbm-shift-dashboard',
					'tab'  => 'consent',
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Draft Privacy Page' ),
				'expect_href'         => 'post.php?post=' . $draft_id,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・権限なし => 出さない',
				'user_id'             => $subscriber_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択 => 出さない',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'url',
					'provider_privacy_policy_page_id' => $published_id,
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '本文を入力する方式で下書きの固定ページが残っている => 選んだページを使わないので出さない',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'content',
					'provider_privacy_policy_page_id' => $draft_id,
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '表示しない（none）で削除済みの固定ページが残っている => 出さない',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'none',
					'provider_privacy_policy_page_id' => $deleted_id,
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '既存の設定: ページ指定で旧ドメインの URL だけ => 出さない（「その他」の URL は判定しない）',
				'user_id'             => $admin_id,
				'settings'            => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => 'https://old.example.com/privacy/',
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '既存の設定: プライバシーポリシーの設定なし => 出さない',
				'user_id'             => $admin_id,
				'settings'            => array(),
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$_GET = $case['screen'] ?? array();

			ob_start();
			( new Setup_Notices() )->render_privacy_policy_page_problem_notice();
			$output = (string) ob_get_clean();

			if ( ! $case['expect_notice'] ) {
				$this->assertSame( '', $output, $case['test_condition_name'] );
				continue;
			}

			$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
			$this->assertStringContainsString( 'Please check the privacy policy page', $output, $case['test_condition_name'] . '（見出し）' );
			// 直すまでどうなるかは、欄の中の警告と同じ文。
			$this->assertStringContainsString( 'Until then, the user registration form does not ask for consent to the privacy policy, and the footer of the reservation page does not show the link either.', $output, $case['test_condition_name'] . '（直すまでどうなるか）' );
			foreach ( $case['expect_text'] as $expect_text ) {
				$this->assertStringContainsString( $expect_text, $output, $case['test_condition_name'] );
			}
			if ( isset( $case['unexpect_text'] ) ) {
				$this->assertStringNotContainsString( $case['unexpect_text'], $output, $case['test_condition_name'] . '（できない行動を勧めない）' );
			}
			// 閉じるボタンは付けない（直すまで出し続ける）。
			$this->assertStringNotContainsString( 'is-dismissible', $output, $case['test_condition_name'] . '（閉じるボタンなし）' );
			// ボタンの行き先は状態ごとに 1 つ。
			$this->assertSame( 1, substr_count( $output, 'button-primary' ), $case['test_condition_name'] . '（ボタンは 1 つ）' );
			$this->assertStringContainsString( $case['expect_href'], $output, $case['test_condition_name'] . '（ボタンの行き先）' );
		}
	}
}
