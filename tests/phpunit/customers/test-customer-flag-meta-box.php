<?php
/**
 * 予約編集画面の顧客フラグ欄（メタボックス）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Customers\Customer_Flag_Meta_Box;
use VKBookingManager\Customers\Customer_Flag_Repository;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * 予約編集画面の顧客フラグ欄（メタボックス）の保存処理のテスト。
 *
 * @group customer-flags
 */
class Customer_Flag_Meta_Box_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * テスト対象のメタボックス。
	 *
	 * @var Customer_Flag_Meta_Box
	 */
	private $meta_box;

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

		// 各テスト前にオプションをクリーンにし、テスト間で割り当て状態が漏れないようにする。
		$this->delete_all_flag_options();
		$this->post_backup = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に復元するための退避。

		$this->service  = new Customer_Flag_Service();
		$this->meta_box = new Customer_Flag_Meta_Box( $this->service );

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
	 * 保存処理が、条件を満たすときだけ予約者ユーザーへフラグを書き込むことを検証する。
	 */
	public function test_save(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい nonce・権限・描画時と同じ予約者ならフラグが保存される（正常系）',
				'conditions'          => array(
					'role'          => 'admin',
					'valid_nonce'   => true,
					'rendered_user' => 'author',
					'post_type'     => 'caution',
				),
				'expected'            => 'caution',
			),
			array(
				'test_condition_name' => '送信された予約者が描画時と同じなら保存される（正常系）',
				'conditions'          => array(
					'role'           => 'admin',
					'valid_nonce'    => true,
					'rendered_user'  => 'author',
					'post_type'      => 'caution',
					'submitted_user' => 'author',
				),
				'expected'            => 'caution',
			),
			array(
				'test_condition_name' => 'nonce が不正なら保存しない（異常系）',
				'conditions'          => array(
					'role'          => 'admin',
					'valid_nonce'   => false,
					'rendered_user' => 'author',
					'post_type'     => 'caution',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '予約管理の権限が無いユーザーでは保存しない（異常系）',
				'conditions'          => array(
					'role'          => 'subscriber',
					'valid_nonce'   => true,
					'rendered_user' => 'author',
					'post_type'     => 'caution',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '描画時と保存時で予約者が違えば保存しない（異常系）',
				'conditions'          => array(
					'role'          => 'admin',
					'valid_nonce'   => true,
					'rendered_user' => 'other',
					'post_type'     => 'caution',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '送信された予約者が描画時と違えば保存しない（予約者の変更。異常系）',
				'conditions'          => array(
					'role'           => 'admin',
					'valid_nonce'    => true,
					'rendered_user'  => 'author',
					'post_type'      => 'caution',
					'submitted_user' => 'other',
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$users      = array(
				'author' => $this->create_user( 'subscriber' ),
				'other'  => $this->create_user( 'subscriber' ),
			);
			$booking_id = $this->create_booking( $users['author'] );

			wp_set_current_user( $this->create_user( $case['conditions']['role'] ) );
			$_POST = $this->build_post( $case['conditions'], $users );

			$this->meta_box->save( $booking_id );

			$flag = $this->service->get_flag( $users['author'] );
			$this->assertSame( $case['expected'], null !== $flag ? $flag['type_id'] : '', $case['test_condition_name'] );
		}
	}

	/**
	 * 予約者が予約顧客でない予約（代理予約で管理者が予約者になっている等）では、フラグを保存しないことを検証する。
	 */
	public function test_save_skips_non_customer_reserver(): void {
		$admin_id   = $this->create_user( 'admin' );
		$booking_id = $this->create_booking( $admin_id );

		wp_set_current_user( $admin_id );
		$_POST = $this->build_post(
			array(
				'valid_nonce'   => true,
				'rendered_user' => 'author',
				'post_type'     => 'caution',
			),
			array( 'author' => $admin_id )
		);

		$this->meta_box->save( $booking_id );

		$this->assertSame( '', get_user_meta( $admin_id, Customer_Flag_Repository::USER_META_ASSIGNMENT, true ), '管理者自身にフラグが付かない' );
	}

	/**
	 * フラグ選択欄が送信されない保存では、既存の割り当てを消さないことを検証する。
	 */
	public function test_save_keeps_assignment_when_select_is_not_submitted(): void {
		$user_id = $this->create_user( 'subscriber' );
		$this->service->set_flag( $user_id, 'caution', '遅刻常習' );
		$booking_id = $this->create_booking( $user_id );

		wp_set_current_user( $this->create_user( 'admin' ) );
		$_POST = $this->build_post(
			array(
				'valid_nonce'   => true,
				'rendered_user' => 'author',
				'post_type'     => null,
			),
			array( 'author' => $user_id )
		);

		$this->meta_box->save( $booking_id );

		$flag = $this->service->get_flag( $user_id );
		$this->assertNotNull( $flag, '選択欄が無い送信でも割り当ては残る' );
		$this->assertSame( '遅刻常習', $flag['memo'], 'メモも変わらない' );
	}

	/**
	 * 予約者が予約顧客かどうかで、編集欄と案内文を出し分けることを検証する。
	 */
	public function test_render(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約者が予約顧客ならフラグの選択欄を表示する（正常系）',
				'conditions'          => array( 'author_role' => 'subscriber' ),
				'expected'            => array(
					'has_select' => true,
					'has_notice' => false,
				),
			),
			array(
				'test_condition_name' => '予約者が管理者（会員と紐付かない代理予約）なら選択欄を出さず案内する（異常系）',
				'conditions'          => array( 'author_role' => 'admin' ),
				'expected'            => array(
					'has_select' => false,
					'has_notice' => true,
				),
			),
		);

		$notice = __( 'Customer flags cannot be set because this reservation is not linked to a member.', 'vk-booking-manager' );

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $this->create_user( 'admin' ) );
			$booking_id = $this->create_booking( $this->create_user( $case['conditions']['author_role'] ) );

			ob_start();
			$this->meta_box->render( get_post( $booking_id ) );
			$output = (string) ob_get_clean();

			$this->assertSame( $case['expected']['has_select'], str_contains( $output, 'name="vkbm_customer_flag_type"' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected']['has_notice'], str_contains( $output, esc_html( $notice ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * 指定ユーザーを予約者とする予約を作成する。
	 *
	 * @param int $author_id 予約者のユーザー ID。
	 * @return int 予約 ID。
	 */
	private function create_booking( int $author_id ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
	}

	/**
	 * テスト用のユーザーを作成する。
	 *
	 * @param string $role 'admin'（予約管理の権限あり）または 'subscriber'。
	 * @return int ユーザー ID。
	 */
	private function create_user( string $role ): int {
		if ( 'admin' !== $role ) {
			return (int) $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		}

		$user_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

		return $user_id;
	}

	/**
	 * 予約編集画面から送信される $_POST を組み立てる。
	 *
	 * @param array<string, mixed> $conditions テスト条件。
	 * @param array<string, int>   $users      条件で指すユーザー（'author' など）の ID。
	 * @return array<string, mixed> $_POST の内容。
	 */
	private function build_post( array $conditions, array $users ): array {
		$post = array(
			'_vkbm_customer_flag_nonce'  => ! empty( $conditions['valid_nonce'] ) ? wp_create_nonce( 'vkbm_customer_flag_meta' ) : 'invalid',
			'vkbm_customer_flag_user_id' => (string) $users[ $conditions['rendered_user'] ],
			'vkbm_customer_flag_memo'    => '',
		);

		// 選択欄が描画されていない画面を再現する場合は、種別を送らない。
		if ( null !== $conditions['post_type'] ) {
			$post['vkbm_customer_flag_type'] = $conditions['post_type'];
		}

		if ( isset( $conditions['submitted_user'] ) ) {
			$post['vkbm_booking'] = array( 'author_id' => (string) $users[ $conditions['submitted_user'] ] );
		}

		return $post;
	}
}
