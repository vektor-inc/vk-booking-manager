<?php
/**
 * BM 設定「プラグイン削除時の片付け」（uninstall_cleanup）の既定値・保存・画面表示のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * uninstall_cleanup の既定値・サニタイズ結果・「高度な設定」タブの表示を検証する。
 *
 * @group provider-settings
 */
class Settings_Uninstall_Cleanup_Test extends WP_UnitTestCase {

	/**
	 * 保存済みの設定とリクエスト値を掃除する。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 既定値（未保存状態）が「残す」であること。
	 *
	 * 何も設定を変えずに削除したサイトで、予約データが消える事故を防ぐための前提。
	 */
	public function test_get_default_settings(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$this->assertSame( 'keep', $defaults['uninstall_cleanup'] );
	}

	/**
	 * 送信された値が、許可した 2 つの値のどちらかに揃うこと。
	 */
	public function test_sanitize(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();

		$test_cases = array(
			array(
				'test_condition_name' => '未送信の場合 => 既定値の keep',
				'conditions'          => array( 'input' => array() ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => 'keep を送信 => keep',
				'conditions'          => array( 'input' => array( 'uninstall_cleanup' => 'keep' ) ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => 'delete_all を送信 => delete_all',
				'conditions'          => array( 'input' => array( 'uninstall_cleanup' => 'delete_all' ) ),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => '想定外の文字列を送信 => データを消さない側の keep',
				'conditions'          => array( 'input' => array( 'uninstall_cleanup' => 'drop_everything' ) ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '配列を送信 => keep',
				'conditions'          => array( 'input' => array( 'uninstall_cleanup' => array( 'delete_all' ) ) ),
				'expected'            => 'keep',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Settings_Sanitizer() )->sanitize( $case['conditions']['input'], $defaults );

			$this->assertSame( $case['expected'], $result['uninstall_cleanup'], $case['test_condition_name'] );
		}
	}

	/**
	 * 保存済みの値が、他の項目だけを保存し直しても変わらないこと（delete_all のまま維持される）。
	 */
	public function test_sanitize_keeps_saved_value_when_not_submitted(): void {
		$defaults = ( new Settings_Repository() )->get_default_settings();
		$saved    = array_merge( $defaults, array( 'uninstall_cleanup' => 'delete_all' ) );

		// 実際の保存処理は、保存済みの値と既定値を合わせた上で入力を重ねる。
		$result = ( new Settings_Sanitizer() )->sanitize( array_merge( $saved, array( 'shift_alert_months' => '2' ) ), $defaults );

		$this->assertSame( 'delete_all', $result['uninstall_cleanup'] );
	}

	/**
	 * 「高度な設定」タブに、説明文つきで選択肢が表示され、保存済みの値が選ばれていること。
	 */
	public function test_render_page(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$test_cases = array(
			array(
				'test_condition_name' => '未保存 => 「残す」が選ばれている',
				'conditions'          => array( 'saved' => null ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => 'delete_all が保存済み => 「すべて削除する」が選ばれている',
				'conditions'          => array( 'saved' => 'delete_all' ),
				'expected'            => 'delete_all',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['conditions']['saved'] ) {
				update_option( Settings_Repository::OPTION_KEY, array( 'uninstall_cleanup' => $case['conditions']['saved'] ) );
			}

			$_GET = array( 'tab' => 'advanced' );
			ob_start();
			( new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) ) )->render_page();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'for="vkbm-uninstall-cleanup"', $html, $case['test_condition_name'] . '（ラベル）' );
			$this->assertStringContainsString( esc_html__( 'Specify how settings and booking data are handled when VK Booking Manager is uninstalled.', 'vk-booking-manager' ), $html, $case['test_condition_name'] . '（説明文）' );
			$this->assertSame(
				1,
				preg_match( '/<option value="' . $case['expected'] . '"\s+selected=\'selected\'/', $html ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 設定の保存で、manage_options を持たない利用者は「プラグイン削除時の片付け」を変更できず、既存の値が保たれること。
	 */
	public function test_save_settings(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者が keep から delete_all に変更 => 変更される',
				'conditions'          => array(
					'role'    => 'administrator',
					'saved'   => 'keep',
					'payload' => 'delete_all',
				),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => '管理者が項目を送らずに保存 => 保存済みの delete_all のまま（keep に戻らない）',
				'conditions'          => array(
					'role'    => 'administrator',
					'saved'   => 'delete_all',
					'payload' => null,
				),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => '編集者（manage_options なし）が keep から delete_all に変更しようとする => keep のまま',
				'conditions'          => array(
					'role'    => 'editor',
					'saved'   => 'keep',
					'payload' => 'delete_all',
				),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '編集者が項目を送らずに保存（項目が表示されない画面） => 保存済みの delete_all のまま',
				'conditions'          => array(
					'role'    => 'editor',
					'saved'   => 'delete_all',
					'payload' => null,
				),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => '編集者が delete_all から keep に変更しようとする => delete_all のまま',
				'conditions'          => array(
					'role'    => 'editor',
					'saved'   => 'delete_all',
					'payload' => 'keep',
				),
				'expected'            => 'delete_all',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['conditions']['role'] ) ) );
			update_option( Settings_Repository::OPTION_KEY, array( 'uninstall_cleanup' => $case['conditions']['saved'] ) );

			$input = array();
			if ( null !== $case['conditions']['payload'] ) {
				$input['uninstall_cleanup'] = $case['conditions']['payload'];
			}
			( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) )->save_settings( $input );

			$this->assertSame( $case['expected'], ( new Settings_Repository() )->get_settings()['uninstall_cleanup'], $case['test_condition_name'] );
		}
	}

	/**
	 * 「プラグイン削除時の片付け」の項目が manage_options を持つ利用者にだけ表示され、補足の説明文が付いていること。
	 */
	public function test_render_page_visibility(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者 => 項目と補足の説明文が表示される',
				'conditions'          => array( 'role' => 'administrator' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集者（manage_options なし） => 項目が表示されない',
				'conditions'          => array( 'role' => 'editor' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $case['conditions']['role'] ) ) );

			$_GET = array( 'tab' => 'advanced' );
			ob_start();
			( new Provider_Settings_Page( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ), 'read' ) )->render_page();
			$html = (string) ob_get_clean();

			$warning = esc_html__( 'If you choose "Delete everything", all data including booking data will be deleted. This cannot be undone after deletion.', 'vk-booking-manager' );

			$has_select  = str_contains( $html, 'name="vkbm_provider_settings[uninstall_cleanup]"' );
			$has_warning = str_contains( $html, $warning );

			$this->assertSame( $case['expected'], $has_select, $case['test_condition_name'] . '（選択肢）' );
			$this->assertSame( $case['expected'], $has_warning, $case['test_condition_name'] . '（補足の説明文）' );
		}
	}
}
