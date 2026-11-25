<?php
/**
 * Settings_Service のプライバシーポリシーのページ（固定ページ ID）まわりの保存（issue #1168）のテスト。
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
 * save_settings() が、プライバシーポリシーに選べる固定ページを公開中のものに限り、送られなかった保存では現在の選択を引き継ぐことを検証する。
 *
 * @group provider-settings
 */
class Settings_Service_Privacy_Policy_Page_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインする（uninstall_cleanup の保持分岐に入らないようにするため）。
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * 保存した設定を掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 固定ページを1件作る。
	 *
	 * @param string $status 公開状態。
	 * @return int 固定ページ ID。
	 */
	private function create_page( string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Privacy',
				'post_status' => $status,
			)
		);
	}

	/**
	 * save_settings() のプライバシーポリシーのページの保存結果を検証する。
	 */
	public function test_save_settings(): void {
		$published_id = $this->create_page();
		$draft_id     = $this->create_page( 'draft' );
		$trash_id     = $this->create_page( 'trash' );
		$deleted_id   = $this->create_page();
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページの ID => そのまま保存',
				'current'             => array(),
				'input'               => array( 'provider_privacy_policy_page_id' => (string) $published_id ),
				'expected_id'         => $published_id,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '公開中でない固定ページの ID（今の選択と違う） => 0（選べるのは公開中のページだけ）',
				'current'             => array( 'provider_privacy_policy_page_id' => $published_id ),
				'input'               => array( 'provider_privacy_policy_page_id' => (string) $draft_id ),
				'expected_id'         => 0,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '今の選択と同じゴミ箱の固定ページの ID => そのまま保つ（他の項目を保存しても選択が外れない）',
				'current'             => array( 'provider_privacy_policy_page_id' => $trash_id ),
				'input'               => array( 'provider_privacy_policy_page_id' => (string) $trash_id ),
				'expected_id'         => $trash_id,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => 'ID が送られなかった保存（REST 経由の部分更新など） => 現在の選択を引き継ぐ',
				'current'             => array( 'provider_privacy_policy_page_id' => $published_id ),
				'input'               => array( 'provider_name' => 'Shop' ),
				'expected_id'         => $published_id,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '完全に削除した固定ページを選んだまま ID が送られなかった保存 => 選択（ID）を保つ（削除済みの警告を消さない）',
				'current'             => array( 'provider_privacy_policy_page_id' => $deleted_id ),
				'input'               => array( 'provider_name' => 'Shop' ),
				'expected_id'         => $deleted_id,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '完全に削除した固定ページを選んだまま「選択してください」（空文字）で保存 => ID は 0、URL も消す',
				'current'             => array(
					'provider_privacy_policy_page_id' => $deleted_id,
					'provider_privacy_policy_url'     => 'https://example.com/privacy/',
				),
				'input'               => array( 'provider_privacy_policy_page_id' => '' ),
				'expected_id'         => 0,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '削除済みの別の固定ページの ID を送信 => 0（存在しないページは新しく選べない）',
				'current'             => array( 'provider_privacy_policy_page_id' => $published_id ),
				'input'               => array( 'provider_privacy_policy_page_id' => (string) $deleted_id ),
				'expected_id'         => 0,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => '「その他（URL を入力）」と外部サイトの URL => ID は 0、URL を保存',
				'current'             => array( 'provider_privacy_policy_page_id' => $published_id ),
				'input'               => array(
					'provider_privacy_policy_page_id' => 'other',
					'provider_privacy_policy_url'     => 'https://external.example.com/privacy/',
				),
				'expected_id'         => 0,
				'expected_url'        => 'https://external.example.com/privacy/',
			),
			array(
				'test_condition_name' => '既存の設定（URL だけ・ID のキー無し）で別の項目を保存 => ID は 0 のまま、URL も変えない',
				'current'             => array( 'provider_privacy_policy_url' => 'https://old.example.com/privacy/' ),
				'input'               => array(
					'provider_name'               => 'Shop',
					'provider_privacy_policy_url' => 'https://old.example.com/privacy/',
				),
				'expected_id'         => 0,
				'expected_url'        => 'https://old.example.com/privacy/',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( array() !== $case['current'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['current'] );
			}

			$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
			$result  = $service->save_settings( $case['input'] );
			$this->assertTrue( $result, $case['test_condition_name'] . '（保存の成否）' );

			$saved = $service->get_settings();
			$this->assertSame( $case['expected_id'], $saved['provider_privacy_policy_page_id'], $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected_url'], $saved['provider_privacy_policy_url'], $case['test_condition_name'] . '（URL）' );
		}
	}
}
