<?php
/**
 * シフト・予約表の予約カードに出す顧客フラグのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\Tests\Customers\Customer_Flag_Options_Cleanup_Trait;
use WP_UnitTestCase;

/**
 * 予約カードへ、予約者に割り当てられた顧客フラグ（種別名・ブロック有無）を渡す処理を検証する。
 *
 * @group admin
 * @group customer-flags
 */
class Shift_Dashboard_Customer_Flag_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * 顧客フラグの種別を登録する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		( new Customer_Flag_Service() )->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => '',
				),
			)
		);
	}

	/**
	 * 顧客フラグのデータを片付ける。
	 */
	public function tear_down(): void {
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * 予約カードのデータに、予約者の顧客フラグが入ることを検証する。
	 */
	public function test_map_booking_to_card(): void {
		$service = new Customer_Flag_Service();
		$method  = new ReflectionMethod( Shift_Dashboard_Page::class, 'map_booking_to_card' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page( 'manage_options', null, null, $service );

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロックしない種別の予約者 => 種別名とブロックなし（正常系）',
				'conditions'          => array( 'type_id' => 'caution' ),
				'expected'            => array(
					'label'          => '要注意',
					'blocks_booking' => false,
				),
			),
			array(
				'test_condition_name' => 'ブロック種別の予約者 => 種別名とブロックあり（正常系）',
				'conditions'          => array( 'type_id' => 'blacklist' ),
				'expected'            => array(
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
				),
			),
			array(
				'test_condition_name' => 'フラグの無い予約者 => null（境界値）',
				'conditions'          => array( 'type_id' => '' ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '予約 ID が無いカード => null（異常系）',
				'conditions'          => array(
					'type_id'    => 'blacklist',
					'no_post_id' => true,
				),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			if ( '' !== $case['conditions']['type_id'] ) {
				$service->set_flag( $user_id, $case['conditions']['type_id'], '' );
			}
			$booking_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $user_id,
				)
			);

			$card = $method->invoke(
				$page,
				array(
					'post_id' => empty( $case['conditions']['no_post_id'] ) ? $booking_id : 0,
				)
			);

			$this->assertSame( $case['expected'], $card['customer_flag'], $case['test_condition_name'] );
		}
	}

	/**
	 * 会員に紐付かないゲスト予約のカードに、予約の連絡先で照合したゲストのフラグが入ることを検証する。
	 */
	public function test_map_booking_to_card_for_guest_booking(): void {
		$service = new Customer_Flag_Service();
		$method  = new ReflectionMethod( Shift_Dashboard_Page::class, 'map_booking_to_card' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page( 'manage_options', null, null, $service );

		$service->set_guest_flag( 'blocked@example.com', '', 'blacklist' );
		$service->set_guest_flag( '', '09011112222', 'caution' );
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$service->set_flag( $member_id, 'caution', '' );

		$test_cases = array(
			array(
				'test_condition_name' => 'メールがブロック種別に一致するゲスト予約 => 種別名とブロックあり（正常系）',
				'conditions'          => array(
					'author_id' => 0,
					'email'     => 'Blocked@Example.com',
					'phone'     => '',
				),
				'expected'            => array(
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
				),
			),
			array(
				'test_condition_name' => '電話が一致するゲスト予約 => 種別名とブロックなし（正常系）',
				'conditions'          => array(
					'author_id' => 0,
					'email'     => 'none@example.com',
					'phone'     => '090-1111-2222',
				),
				'expected'            => array(
					'label'          => '要注意',
					'blocks_booking' => false,
				),
			),
			array(
				'test_condition_name' => 'メールがブロック種別、電話が非ブロック種別に一致 => ブロック種別を優先する（境界値）',
				'conditions'          => array(
					'author_id' => 0,
					'email'     => 'blocked@example.com',
					'phone'     => '09011112222',
				),
				'expected'            => array(
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
				),
			),
			array(
				'test_condition_name' => 'どちらも一致しないゲスト予約 => null（異常系）',
				'conditions'          => array(
					'author_id' => 0,
					'email'     => 'none@example.com',
					'phone'     => '09000000000',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '会員の予約は連絡先で照合せず、会員のフラグで決まる（境界値）',
				'conditions'          => array(
					'author_id' => $member_id,
					'email'     => 'blocked@example.com',
					'phone'     => '',
				),
				'expected'            => array(
					'label'          => '要注意',
					'blocks_booking' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $case['conditions']['author_id'],
				)
			);
			update_post_meta( $booking_id, '_vkbm_booking_customer_email', $case['conditions']['email'] );
			update_post_meta( $booking_id, '_vkbm_booking_customer_tel', $case['conditions']['phone'] );

			$card = $method->invoke( $page, array( 'post_id' => $booking_id ) );

			$this->assertSame( $case['expected'], $card['customer_flag'], $case['test_condition_name'] );
		}
	}

	/**
	 * 月表示の予約カードのデータに、予約者の顧客フラグが入ることを検証する。
	 */
	public function test_format_month_cell_bookings(): void {
		$service = new Customer_Flag_Service();
		$method  = new ReflectionMethod( Shift_Dashboard_Page::class, 'format_month_cell_bookings' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page( 'manage_options', null, null, $service );

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別の予約者の通常の予約 => 種別名とブロックあり（正常系）',
				'conditions'          => array(
					'type_id'   => 'blacklist',
					'stay_type' => '',
				),
				'expected'            => array(
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
				),
			),
			array(
				'test_condition_name' => 'ブロックしない種別の予約者の宿泊 IN 日 => 種別名とブロックなし（正常系）',
				'conditions'          => array(
					'type_id'   => 'caution',
					'stay_type' => 'in',
				),
				'expected'            => array(
					'label'          => '要注意',
					'blocks_booking' => false,
				),
			),
			array(
				'test_condition_name' => '宿泊の中日（顧客名を出さない小さな札） => null（境界値）',
				'conditions'          => array(
					'type_id'   => 'blacklist',
					'stay_type' => 'stay',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'フラグの無い予約者 => null（異常系）',
				'conditions'          => array(
					'type_id'   => '',
					'stay_type' => '',
				),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			if ( '' !== $case['conditions']['type_id'] ) {
				$service->set_flag( $user_id, $case['conditions']['type_id'], '' );
			}
			$booking_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $user_id,
				)
			);

			$entries = $method->invoke(
				$page,
				array(
					array(
						'post_id'     => $booking_id,
						'start_label' => '10:00',
						'end_label'   => '11:00',
						'status'      => 'confirmed',
						'customer'    => '予約 太郎',
						'stay_type'   => $case['conditions']['stay_type'],
					),
				),
				array()
			);

			$this->assertSame( $case['expected'], $entries[0]['customer_flag'], $case['test_condition_name'] );
		}
	}

	/**
	 * 顧客フラグの印が、種別名を画面に文字で出さず、title と読み上げ用テキストでだけ伝えることを検証する。
	 */
	public function test_render_customer_flag_mark(): void {
		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'render_customer_flag_mark' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page();

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別 => 赤の印（--blocks）で、種別名は title と読み上げ用テキストだけ（正常系）',
				'conditions'          => array(
					'flag' => array(
						'label'          => 'ブラックリスト',
						'blocks_booking' => true,
					),
				),
				'expected'            => array(
					'has_mark'   => true,
					'has_blocks' => true,
				),
			),
			array(
				'test_condition_name' => 'ブロックしない種別 => 灰色の印（--blocks なし）（正常系）',
				'conditions'          => array(
					'flag' => array(
						'label'          => '要注意',
						'blocks_booking' => false,
					),
				),
				'expected'            => array(
					'has_mark'   => true,
					'has_blocks' => false,
				),
			),
			array(
				'test_condition_name' => 'フラグなし => 何も出力しない（境界値）',
				'conditions'          => array( 'flag' => null ),
				'expected'            => array(
					'has_mark'   => false,
					'has_blocks' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			ob_start();
			$method->invoke( $page, $case['conditions']['flag'] );
			$output = (string) ob_get_clean();

			$this->assertSame( $case['expected']['has_mark'], str_contains( $output, 'vkbm-customer-flag-mark' ), $case['test_condition_name'] );
			$this->assertSame( $case['expected']['has_blocks'], str_contains( $output, 'vkbm-customer-flag-mark--blocks' ), $case['test_condition_name'] . ' / 色' );

			if ( null !== $case['conditions']['flag'] ) {
				/* translators: %s: 顧客フラグのラベル名。 */
				$text = sprintf( __( 'Customer flag: %s', 'vk-booking-manager' ), $case['conditions']['flag']['label'] );
				// 種別名が出てくるのは title 属性と読み上げ用テキストの 2 か所だけ（画面に見える文字としては出さない）。
				$this->assertSame( 2, substr_count( $output, $case['conditions']['flag']['label'] ), $case['test_condition_name'] . ' / 種別名の出現箇所' );
				$this->assertStringContainsString( 'title="' . esc_attr( $text ) . '"', $output, $case['test_condition_name'] . ' / title' );
				$this->assertStringContainsString( '<span class="screen-reader-text">' . esc_html( $text ) . '</span>', $output, $case['test_condition_name'] . ' / 読み上げ' );
			}
		}
	}
}
