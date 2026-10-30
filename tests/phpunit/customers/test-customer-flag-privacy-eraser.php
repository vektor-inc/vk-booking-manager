<?php
/**
 * WordPress 本体の個人データ消去依頼による、ゲストの顧客フラグ消去のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_Privacy_Eraser;
use VKBookingManager\Customers\Customer_Flag_Privacy_Eraser;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Customer_Flag_Privacy_Eraser を検証する。
 *
 * @group customer-flags
 */
class Customer_Flag_Privacy_Eraser_Test extends WP_UnitTestCase {
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
	 * @var Customer_Flag_Privacy_Eraser
	 */
	private $eraser;

	/**
	 * 各テストの前に、種別を登録しデータを初期化する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		$this->service = new Customer_Flag_Service();
		$this->eraser  = new Customer_Flag_Privacy_Eraser( $this->service );
		$this->service->save_types(
			array(
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
	 * 各テストの後に、データを片付ける。
	 */
	public function tear_down(): void {
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * 消去処理が、予約の消去処理より先に動く位置（優先度 9）で一覧に登録されることを検証する。
	 */
	public function test_register(): void {
		$this->eraser->register();
		( new Booking_Privacy_Eraser( new Booking_Anonymizer() ) )->register();

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフィルターを呼ぶ。
		$keys    = array_keys( $erasers );

		remove_filter( 'wp_privacy_personal_data_erasers', array( $this->eraser, 'register_eraser' ), Customer_Flag_Privacy_Eraser::FILTER_PRIORITY );

		$this->assertContains( 'vk-booking-manager-customer-flags', $keys, '顧客フラグの消去処理が登録される' );
		$this->assertContains( 'vk-booking-manager', $keys, '予約の消去処理も登録される' );
		$this->assertLessThan( array_search( 'vk-booking-manager', $keys, true ), array_search( 'vk-booking-manager-customer-flags', $keys, true ), '顧客フラグの消去処理が予約の消去処理より先に並ぶ' );
		$this->assertSame( 9, Customer_Flag_Privacy_Eraser::FILTER_PRIORITY );
	}

	/**
	 * メール指定の消去で、記録内の電話番号と、そのメールの未匿名化の予約の電話番号に付いた記録が消えることを検証する。
	 */
	public function test_erase_personal_data(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'メールが一致する記録は、記録内の電話番号ごと消える（正常系）',
				'conditions'          => array(
					'request_email' => 'Erase@Example.com',
					'records'       => array( array( 'erase@example.com', '09011112222' ) ),
					'bookings'      => array(),
				),
				'expected'            => array(
					'removed'   => true,
					'remaining' => array(),
				),
			),
			array(
				'test_condition_name' => 'そのメールの予約の電話番号に付いた記録も消える（電話だけの記録。正常系）',
				'conditions'          => array(
					'request_email' => 'erase@example.com',
					'records'       => array( array( '', '09033334444' ) ),
					'bookings'      => array( array( 'erase@example.com', '090-3333-4444', false ) ),
				),
				'expected'            => array(
					'removed'   => true,
					'remaining' => array(),
				),
			),
			array(
				'test_condition_name' => '予約がすべて匿名化された後でも、メールが一致する記録は消える（正常系）',
				'conditions'          => array(
					'request_email' => 'erase@example.com',
					'records'       => array( array( 'erase@example.com', '09055556666' ) ),
					'bookings'      => array( array( '', '', true ) ),
				),
				'expected'            => array(
					'removed'   => true,
					'remaining' => array(),
				),
			),
			array(
				'test_condition_name' => '無関係な記録と、別のメールの予約の電話番号に付いた記録は残る（異常系）',
				'conditions'          => array(
					'request_email' => 'erase@example.com',
					'records'       => array(
						array( 'keep@example.com', '09077778888' ),
						array( '', '09099990000' ),
					),
					'bookings'      => array(
						array( 'erase@example.com', '09012345678', false ),
						array( 'keep@example.com', '09099990000', false ),
					),
				),
				'expected'            => array(
					'removed'   => false,
					'remaining' => array( 'keep@example.com', '' ),
				),
			),
			array(
				'test_condition_name' => '不正なメールアドレスの依頼では何も消さない（異常系）',
				'conditions'          => array(
					'request_email' => 'not-an-email',
					'records'       => array( array( 'keep@example.com', '09077778888' ) ),
					'bookings'      => array(),
				),
				'expected'            => array(
					'removed'   => false,
					'remaining' => array( 'keep@example.com' ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->delete_all_flag_options();
			$this->service->save_types(
				array(
					array(
						'id'             => 'blacklist',
						'label'          => 'ブラックリスト',
						'blocks_booking' => true,
					),
				)
			);

			$c = $case['conditions'];
			foreach ( $c['records'] as $record ) {
				$this->service->set_guest_flag( $record[0], $record[1], 'blacklist' );
			}
			foreach ( $c['bookings'] as $booking ) {
				$this->create_booking( $booking[0], $booking[1], $booking[2] );
			}

			$result = $this->eraser->erase_personal_data( $c['request_email'] );

			$this->assertSame( $case['expected']['removed'], $result['items_removed'], $case['test_condition_name'] . ' / items_removed' );
			$this->assertTrue( $result['done'], $case['test_condition_name'] . ' / done' );
			$this->assertFalse( $result['items_retained'], $case['test_condition_name'] . ' / items_retained' );
			$this->assertSame( $case['expected']['removed'], array() !== $result['messages'], $case['test_condition_name'] . ' / メッセージ' );
			$this->assertSame( $case['expected']['remaining'], array_column( $this->service->get_guest_records(), 'email' ), $case['test_condition_name'] . ' / 残る記録' );
		}
	}

	/**
	 * 記録の書き換えの排他を取れないとき、完了扱いにせず、残っている旨と再実行の案内を返すことを検証する。
	 */
	public function test_erase_personal_data_reports_retained_when_lock_is_held(): void {
		$this->service->set_guest_flag( 'erase@example.com', '09011112222', 'blacklist' );
		$this->hold_guest_lock_for_test();

		$result = $this->eraser->erase_personal_data( 'erase@example.com' );

		$this->assertTrue( $result['items_retained'], '消せていないので「残している」と返す（異常系）' );
		$this->assertFalse( $result['items_removed'], '消したとは返さない' );
		$this->assertNotSame( array(), $result['messages'], '再実行を促すメッセージを返す' );
		$this->assertCount( 1, $this->service->get_guest_records(), '記録はそのまま残る' );

		$this->release_guest_lock_for_test();
		$result = $this->eraser->erase_personal_data( 'erase@example.com' );
		$this->assertTrue( $result['items_removed'], '排他が空けば消せる（正常系）' );
		$this->assertFalse( $result['items_retained'] );
	}

	/**
	 * 予約の匿名化だけでは、ゲストのフラグが消えないことを検証する。
	 */
	public function test_anonymize_booking_keeps_guest_flags(): void {
		$this->service->set_guest_flag( 'kept@example.com', '09011112222', 'blacklist' );
		$booking_id = $this->create_booking( 'kept@example.com', '09011112222', false );

		$result = ( new Booking_Anonymizer() )->anonymize( $booking_id, Booking_Anonymizer::ANONYMIZED_BY_BULK );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, $result, '予約は匿名化される' );
		$this->assertCount( 1, $this->service->get_guest_records(), '匿名化してもゲストのフラグは残る' );
		$this->assertNotNull( $this->service->find_blocking_guest_flag( 'kept@example.com', '' ), '匿名化後も止める判定に使われる' );
	}

	/**
	 * テスト用の予約を作る。
	 *
	 * @param string $email      お客様のメールアドレス。
	 * @param string $phone      お客様の電話番号。
	 * @param bool   $anonymized 匿名化済みの印を付けるか（印を付けた予約は連絡先を空にして作る）。
	 * @return int 予約 ID。
	 */
	private function create_booking( string $email, string $phone, bool $anonymized ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_customer_email', $email );
		update_post_meta( $booking_id, '_vkbm_booking_customer_tel', $phone );
		// 終了済みの予約にする（匿名化できる条件）。
		update_post_meta( $booking_id, '_vkbm_booking_service_start', wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', (string) $this->factory()->post->create( array( 'post_title' => 'カット' ) ) );
		if ( $anonymized ) {
			update_post_meta( $booking_id, Booking_Anonymizer::META_ANONYMIZED_AT, gmdate( 'Y-m-d H:i:s' ) );
		}

		return $booking_id;
	}
}
