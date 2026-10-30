<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Customers\Customer_Flag_Booking_Guard;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use ReflectionMethod;
use WP_Error;
use WP_UnitTestCase;

/**
 * 顧客フラグの予約ブロック・通知メール警告連携のテスト。
 *
 * @group customer-flags
 */
class Customer_Flag_Booking_Guard_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * テスト対象ガード。
	 *
	 * @var Customer_Flag_Booking_Guard
	 */
	private $guard;

	/**
	 * テスト用の顧客（'blocked' / 'noted' / 'free'）のユーザー ID。
	 *
	 * @var array<string, int>
	 */
	private $user_ids = array();

	public function set_up(): void {
		parent::set_up();

		// 各テスト前にオプションをクリーンにし、テスト間で割り当て状態が漏れないようにする。
		$this->delete_all_flag_options();

		$this->service = new Customer_Flag_Service();
		$this->guard   = new Customer_Flag_Booking_Guard( $this->service );

		// ブロック種別と非ブロック種別を登録する。
		$this->service->save_types(
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
					'block_message'  => 'ご予約をお受けできません。',
				),
			)
		);

		// 予約顧客（購読者）を作り、各顧客にフラグを割り当てる。
		foreach ( array( 'blocked', 'noted', 'free' ) as $key ) {
			$this->user_ids[ $key ] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}
		$this->service->set_flag( $this->user_ids['blocked'], 'blacklist', '無断キャンセル' );
		$this->service->set_flag( $this->user_ids['noted'], 'caution', '遅刻が多い' );
	}

	public function tear_down(): void {
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * ブロック判定フィルターの挙動を検証する。
	 */
	public function test_block_flagged_customer(): void {
		$existing_error = new WP_Error( 'existing', '既存エラー' );

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別の顧客はWP_Errorで予約を止める（正常系）',
				'conditions'          => array(
					'incoming' => null,
					'user'     => 'blocked',
				),
				'expected'            => 'wp_error',
			),
			array(
				'test_condition_name' => '非ブロック種別の顧客は受け取った値（null）をそのまま返す（正常系）',
				'conditions'          => array(
					'incoming' => null,
					'user'     => 'noted',
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => 'フラグの無い顧客も受け取った値（null）をそのまま返す（正常系）',
				'conditions'          => array(
					'incoming' => null,
					'user'     => 'free',
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => '既にWP_Errorが渡された場合はそのまま尊重して返す（境界値）',
				'conditions'          => array(
					'incoming' => $existing_error,
					'user'     => 'free',
				),
				'expected'            => 'same_error',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->guard->block_flagged_customer( $case['conditions']['incoming'], $this->user_ids[ $case['conditions']['user'] ] );

			if ( 'wp_error' === $case['expected'] ) {
				$this->assertInstanceOf( WP_Error::class, $actual, $case['test_condition_name'] );
				$this->assertSame( 'vkbm_customer_flag_blocked', $actual->get_error_code(), $case['test_condition_name'] );
			} elseif ( 'null' === $case['expected'] ) {
				$this->assertNull( $actual, $case['test_condition_name'] );
			} elseif ( 'same_error' === $case['expected'] ) {
				$this->assertSame( $existing_error, $actual, $case['test_condition_name'] );
			}
		}

		// register() で実際にフックへ登録され、フィルター経由でも同じ判定が働くことを検証する
		// （フィルター名や引数数の取り違えを検知するため）。
		$this->guard->register();
		$hooked = apply_filters( 'vkbm_booking_block_check', null, $this->user_ids['blocked'] );
		$this->assertInstanceOf( WP_Error::class, $hooked, 'register() 後はフィルター経由でもブロック対象は WP_Error を返す' );
		$this->assertSame( 'vkbm_customer_flag_blocked', $hooked->get_error_code(), 'フィルター経由でもエラーコードが一致する' );

		// 後続テストへ影響しないよう、登録したフィルターを解除する。
		remove_filter( 'vkbm_booking_block_check', array( $this->guard, 'block_flagged_customer' ), 10 );
	}

	/**
	 * ゲストに付けたフラグによる判定を検証する（ゲスト予約と、会員の予約の両方）。
	 */
	public function test_block_flagged_customer_by_guest_flag(): void {
		$this->service->set_guest_flag( 'guest-blocked@example.com', '09011112222', 'blacklist', '無断キャンセル' );
		$this->service->set_guest_flag( 'guest-noted@example.com', '09033334444', 'caution', '遅刻' );

		// 会員の連絡先がゲストのフラグと一致する会員を作る。
		$member_by_email = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'guest-blocked@example.com',
			)
		);
		$member_by_phone = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $member_by_phone, 'phone_number', '09011112222' );
		$member_noted = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'guest-noted@example.com',
			)
		);
		$existing     = new WP_Error( 'existing', '既存エラー' );

		$test_cases = array(
			array(
				'test_condition_name' => 'ゲスト予約: メールだけ一致で止める（正常系）',
				'conditions'          => array(
					'user_id' => 0,
					'contact' => array(
						'email' => 'guest-blocked@example.com',
						'phone' => '09000000000',
					),
				),
				'expected'            => 'wp_error',
			),
			array(
				'test_condition_name' => 'ゲスト予約: 電話だけ一致で止める（正常系）',
				'conditions'          => array(
					'user_id' => 0,
					'contact' => array(
						'email' => 'other@example.com',
						'phone' => '09011112222',
					),
				),
				'expected'            => 'wp_error',
			),
			array(
				'test_condition_name' => 'ゲスト予約: どちらも一致しなければ通す（正常系）',
				'conditions'          => array(
					'user_id' => 0,
					'contact' => array(
						'email' => 'other@example.com',
						'phone' => '09000000000',
					),
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => 'ゲスト予約: 止めない種別のフラグは通す（正常系）',
				'conditions'          => array(
					'user_id' => 0,
					'contact' => array(
						'email' => 'guest-noted@example.com',
						'phone' => '09033334444',
					),
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => '会員の予約: 会員のメールがゲストのフラグと一致すれば止める（正常系）',
				'conditions'          => array(
					'user_id' => $member_by_email,
					'contact' => array(),
				),
				'expected'            => 'wp_error',
			),
			array(
				'test_condition_name' => '会員の予約: 会員の電話がゲストのフラグと一致すれば止める（正常系）',
				'conditions'          => array(
					'user_id' => $member_by_phone,
					'contact' => array(),
				),
				'expected'            => 'wp_error',
			),
			array(
				'test_condition_name' => '会員の予約: 止めない種別のゲストのフラグなら通す（正常系）',
				'conditions'          => array(
					'user_id' => $member_noted,
					'contact' => array(),
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => '会員の予約: 一致するゲストのフラグが無い会員は通す（正常系）',
				'conditions'          => array(
					'user_id' => $this->user_ids['free'],
					'contact' => array(),
				),
				'expected'            => 'null',
			),
			array(
				'test_condition_name' => '先に返ったエラーを尊重する（境界値）',
				'conditions'          => array(
					'user_id'  => 0,
					'incoming' => $existing,
					'contact'  => array(
						'email' => 'guest-blocked@example.com',
						'phone' => '',
					),
				),
				'expected'            => 'same_error',
			),
		);

		foreach ( $test_cases as $case ) {
			$incoming = $case['conditions']['incoming'] ?? null;
			$actual   = $this->guard->block_flagged_customer( $incoming, $case['conditions']['user_id'], $case['conditions']['contact'] );

			if ( 'wp_error' === $case['expected'] ) {
				$this->assertInstanceOf( WP_Error::class, $actual, $case['test_condition_name'] );
				// ゲスト予約は汎用のコードと既定の文言、会員の予約は従来のコード。
				$expected_code = 0 === $case['conditions']['user_id'] ? 'vkbm_booking_not_accepted' : 'vkbm_customer_flag_blocked';
				$this->assertSame( $expected_code, $actual->get_error_code(), $case['test_condition_name'] );
				if ( 0 === $case['conditions']['user_id'] ) {
					$this->assertSame( $this->service->get_default_block_message(), $actual->get_error_message(), $case['test_condition_name'] . ' / 種別のメッセージを返さない' );
				}
			} elseif ( 'same_error' === $case['expected'] ) {
				$this->assertSame( $existing, $actual, $case['test_condition_name'] );
			} else {
				$this->assertNull( $actual, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * ゲストの記録が空のサイトでは、会員・ゲストとも従来の判定結果から変わらないことを検証する。
	 */
	public function test_block_flagged_customer_without_guest_records(): void {
		$this->assertInstanceOf( WP_Error::class, $this->guard->block_flagged_customer( null, $this->user_ids['blocked'] ), '会員のフラグでの停止は従来どおり' );
		$this->assertNull( $this->guard->block_flagged_customer( null, $this->user_ids['free'] ), 'フラグの無い会員は従来どおり通す' );
		$this->assertNull(
			$this->guard->block_flagged_customer(
				null,
				0,
				array(
					'email' => 'anyone@example.com',
					'phone' => '09012345678',
				)
			),
			'ゲストの記録が空ならゲスト予約も通す'
		);
	}

	/**
	 * register() で登録しても、顧客フラグの種別名・メモがどの通知メールにも入らないことを検証する。
	 *
	 * メールは転送・引用などで顧客や第三者の目に触れうるため、フラグは管理画面の表示だけに留める。
	 */
	public function test_register(): void {
		$this->guard->register();

		// ブロック種別のフラグが付いた顧客の予約を作る。
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $this->user_ids['blocked'],
			)
		);

		$notification  = new Booking_Notification_Service( new Settings_Repository() );
		$build_payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$build_message = new ReflectionMethod( Booking_Notification_Service::class, 'build_message' );
		$build_payload->setAccessible( true );
		$build_message->setAccessible( true );
		$payload = $build_payload->invoke( $notification, $booking_id );

		$test_cases = array(
			array(
				'test_condition_name' => '仮予約の事業者向け通知に種別名・メモが入らない',
				'conditions'          => array( 'type' => 'pending_provider' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約確定の事業者向け通知に種別名・メモが入らない',
				'conditions'          => array( 'type' => 'confirmed_provider' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約確定の顧客向け通知に種別名・メモが入らない',
				'conditions'          => array( 'type' => 'confirmed_customer' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'キャンセルの事業者向け通知に種別名・メモが入らない',
				'conditions'          => array( 'type' => 'cancelled_provider' ),
				'expected'            => false,
			),
		);

		try {
			foreach ( $test_cases as $case ) {
				$message = $build_message->invoke( $notification, $case['conditions']['type'], $payload );
				$text    = $message['subject'] . "\n" . $message['body'];

				$this->assertSame( $case['expected'], str_contains( $text, 'ブラックリスト' ), $case['test_condition_name'] . ' / 種別名' );
				$this->assertSame( $case['expected'], str_contains( $text, '無断キャンセル' ), $case['test_condition_name'] . ' / メモ' );
			}
		} finally {
			// 後続テストへ影響しないよう、登録したフィルターを解除する。
			remove_filter( 'vkbm_booking_block_check', array( $this->guard, 'block_flagged_customer' ), 10 );
		}
	}
}
