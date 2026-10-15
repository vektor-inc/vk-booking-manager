<?php
/**
 * ユーザー編集画面の顧客フラグ欄のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Customers\Customer_Flag_User_Profile;
use WP_UnitTestCase;

/**
 * ユーザー編集画面の顧客フラグ欄の表示・保存処理のテスト。
 *
 * @group customer-flags
 */
class Customer_Flag_User_Profile_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * テスト対象。
	 *
	 * @var Customer_Flag_User_Profile
	 */
	private $user_profile;

	/**
	 * テスト前の $_POST（復元用）。
	 *
	 * @var array<string, mixed>
	 */
	private $post_backup = array();

	/**
	 * 各テストの前に、顧客フラグのデータと $_POST を初期化する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		$this->post_backup = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に復元するための退避。

		$this->service      = new Customer_Flag_Service();
		$this->user_profile = new Customer_Flag_User_Profile( $this->service );

		$this->service->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
			)
		);
	}

	/**
	 * 各テストの後に、$_POST・ログインユーザー・顧客フラグのデータを元に戻す。
	 */
	public function tear_down(): void {
		$_POST = $this->post_backup;
		wp_set_current_user( 0 );
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * 編集できるかの判定を検証する。
	 */
	public function test_can_edit_flag(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約管理の権限を持つ管理者が予約顧客を編集する場合は編集できる（正常系）',
				'conditions'          => array(
					'editor' => 'admin',
					'target' => 'subscriber',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約顧客本人は自分のフラグを編集できない（異常系）',
				'conditions'          => array(
					'editor' => 'self',
					'target' => 'subscriber',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '対象が予約顧客でない（管理者）場合は編集できない（異常系）',
				'conditions'          => array(
					'editor' => 'admin',
					'target' => 'administrator',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$target_id = self::factory()->user->create( array( 'role' => $case['conditions']['target'] ) );
			$editor_id = 'self' === $case['conditions']['editor'] ? $target_id : $this->create_admin();
			wp_set_current_user( $editor_id );

			$this->assertSame( $case['expected'], $this->user_profile->can_edit_flag( $target_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * ユーザー編集画面からの保存処理を検証する。
	 */
	public function test_save(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい nonce と種別を送るとフラグとメモが保存される（正常系）',
				'conditions'          => array(
					'valid_nonce' => true,
					'type'        => 'caution',
					'memo'        => '遅刻常習',
					'assigned'    => false,
				),
				'expected'            => array(
					'type_id' => 'caution',
					'memo'    => '遅刻常習',
				),
			),
			array(
				'test_condition_name' => '「なし」を送ると既存の割り当てが解除される（正常系）',
				'conditions'          => array(
					'valid_nonce' => true,
					'type'        => '',
					'memo'        => '',
					'assigned'    => true,
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'nonce が不正なら保存しない（異常系）',
				'conditions'          => array(
					'valid_nonce' => false,
					'type'        => 'caution',
					'memo'        => '',
					'assigned'    => false,
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '種別が送信されない場合は既存の割り当てを消さない（境界値）',
				'conditions'          => array(
					'valid_nonce' => true,
					'type'        => null,
					'memo'        => '',
					'assigned'    => true,
				),
				'expected'            => array(
					'type_id' => 'caution',
					'memo'    => '既存のメモ',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$target_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			if ( $case['conditions']['assigned'] ) {
				$this->service->set_flag( $target_id, 'caution', '既存のメモ' );
			}

			wp_set_current_user( $this->create_admin() );
			$_POST = array(
				'_vkbm_customer_flag_user_profile_nonce' => $case['conditions']['valid_nonce'] ? wp_create_nonce( 'vkbm_customer_flag_user_profile' ) : 'invalid',
				'vkbm_customer_flag_memo'                => $case['conditions']['memo'],
			);
			if ( null !== $case['conditions']['type'] ) {
				$_POST['vkbm_customer_flag_type'] = $case['conditions']['type'];
			}

			$this->user_profile->save( $target_id );

			$flag   = $this->service->get_flag( $target_id );
			$actual = null !== $flag
				? array(
					'type_id' => $flag['type_id'],
					'memo'    => $flag['memo'],
				)
				: null;
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 編集欄の表示条件を検証する。
	 */
	public function test_render(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者が予約顧客を表示すると編集欄が出て、保存済みのメモが入っている（正常系）',
				'conditions'          => array(
					'editor' => 'admin',
					'target' => 'subscriber',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約顧客本人が自分のプロフィールを表示しても編集欄は出ない（異常系）',
				'conditions'          => array(
					'editor' => 'self',
					'target' => 'subscriber',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$target_id = self::factory()->user->create( array( 'role' => $case['conditions']['target'] ) );
			$this->service->set_flag( $target_id, 'caution', '表示確認用のメモ' );
			wp_set_current_user( 'self' === $case['conditions']['editor'] ? $target_id : $this->create_admin() );

			ob_start();
			$this->user_profile->render( get_userdata( $target_id ) );
			$output = (string) ob_get_clean();

			$this->assertSame( $case['expected'], str_contains( $output, 'name="vkbm_customer_flag_type"' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected'], str_contains( $output, '表示確認用のメモ' ), $case['test_condition_name'] . ' / メモ' );
		}
	}

	/**
	 * 予約管理の権限を持つ管理者を作成する。
	 *
	 * @return int ユーザー ID。
	 */
	private function create_admin(): int {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

		return $user_id;
	}
}
