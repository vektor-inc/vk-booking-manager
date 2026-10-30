<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Customer_Cancellation;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * お客様自身によるキャンセルの可否の判定と実行（マイページとゲスト予約のキャンセル用リンクで共通）のテスト。
 *
 * @group bookings
 */
class Booking_Customer_Cancellation_Test extends WP_UnitTestCase {
	/**
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * @var string
	 */
	private $original_timezone_string = '';

	protected function setUp(): void {
		parent::setUp();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tearDown();
	}

	/**
	 * 基本設定を上書き保存する。
	 *
	 * @param array<string, mixed> $overrides 上書きする値。
	 */
	private function save_settings( array $overrides ): void {
		$repository = new Settings_Repository();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_settings(), $overrides ) );
	}

	/**
	 * 予約を 1 件作る。
	 *
	 * @param int    $author_id     投稿者。
	 * @param int    $start_offset  現在から開始までの秒数（負なら開始済み）。
	 * @param string $status        予約ステータス。
	 * @param string $start_override 開始日時の保存値を直接指定する（空なら $start_offset から作る）。
	 * @return int 予約 ID。
	 */
	private function create_booking( int $author_id, int $start_offset, string $status = 'confirmed', string $start_override = '' ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '' !== $start_override ? $start_override : wp_date( 'Y-m-d H:i:s', time() + $start_offset ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', time() + $start_offset + HOUR_IN_SECONDS ) );
		update_post_meta( $booking_id, '_vkbm_booking_status', $status );

		return $booking_id;
	}

	/**
	 * 状態の判定: 設定「予約キャンセル」とステータス・開始時刻で決まる。
	 */
	public function test_get_state(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '締め切り（24 時間前）より前 => can_cancel',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'confirmed',
				'expected'            => 'can_cancel',
			),
			array(
				'test_condition_name' => '仮予約でも締め切りより前 => can_cancel',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'pending',
				'expected'            => 'can_cancel',
			),
			array(
				'test_condition_name' => '締め切りを過ぎた => cancel_deadline_passed',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'offset'              => 2 * HOUR_IN_SECONDS,
				'status'              => 'confirmed',
				'expected'            => 'cancel_deadline_passed',
			),
			array(
				'test_condition_name' => '開始時刻を過ぎた => cancel_deadline_passed',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 0 ),
				'offset'              => -HOUR_IN_SECONDS,
				'status'              => 'confirmed',
				'expected'            => 'cancel_deadline_passed',
			),
			array(
				'test_condition_name' => '設定が「キャンセル不可」 => cancel_disabled',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'confirmed',
				'expected'            => 'cancel_disabled',
			),
			array(
				'test_condition_name' => 'キャンセル済み => cancelled（設定が不可でもキャンセル済みを優先）',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'cancelled',
				'expected'            => 'cancelled',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['settings'] );
			$booking_id = $this->create_booking( 0, $case['offset'], $case['status'] );

			$this->assertSame( $case['expected'], ( new Booking_Customer_Cancellation( new Settings_Repository() ) )->get_state( $booking_id ), $case['test_condition_name'] );
		}

		$booking_id = $this->create_booking( 0, DAY_IN_SECONDS, 'confirmed', 'not-a-date' );
		$this->assertSame( 'invalid_booking', ( new Booking_Customer_Cancellation( new Settings_Repository() ) )->get_state( $booking_id ), '開始日時が読めない => invalid_booking' );
	}

	/**
	 * 状態の文言: マイページと同じ文言を返す。
	 */
	public function test_get_state_message(): void {
		$test_cases = array(
			array( 'test_condition_name' => '不可の設定', 'state' => 'cancel_disabled', 'expected' => __( 'This reservation cannot be canceled online.', 'vk-booking-manager' ) ),
			array( 'test_condition_name' => '締め切り超過', 'state' => 'cancel_deadline_passed', 'expected' => __( 'The cancellation deadline has passed.', 'vk-booking-manager' ) ),
			array( 'test_condition_name' => '日時が読めない', 'state' => 'invalid_booking', 'expected' => __( 'The reservation date and time is invalid.', 'vk-booking-manager' ) ),
			array( 'test_condition_name' => 'キャンセルできる => 文言なし', 'state' => 'can_cancel', 'expected' => '' ),
			array( 'test_condition_name' => 'キャンセル済み => 文言なし', 'state' => 'cancelled', 'expected' => '' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Booking_Customer_Cancellation::get_state_message( $case['state'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * マイページのキャンセル: 共通の部品に切り出した後も、判定とエラーコード・後処理が変わらない。
	 */
	public function test_cancel(): void {
		$user_id  = $this->factory()->user->create();
		$other_id = $this->factory()->user->create();

		$test_cases = array(
			array(
				'test_condition_name' => '自分の予約で締め切り前 => キャンセルされる',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'author'              => $user_id,
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'confirmed',
				'expected_code'       => null,
				'expected_status'     => 'cancelled',
			),
			array(
				'test_condition_name' => 'すでにキャンセル済み => 成功を返し、そのまま',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'author'              => $user_id,
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'cancelled',
				'expected_code'       => null,
				'expected_status'     => 'cancelled',
			),
			array(
				'test_condition_name' => '締め切りを過ぎた => cancel_deadline_passed',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'author'              => $user_id,
				'offset'              => 2 * HOUR_IN_SECONDS,
				'status'              => 'confirmed',
				'expected_code'       => 'cancel_deadline_passed',
				'expected_status'     => 'confirmed',
			),
			array(
				'test_condition_name' => '設定が「キャンセル不可」 => cancel_disabled',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'author'              => $user_id,
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'confirmed',
				'expected_code'       => 'cancel_disabled',
				'expected_status'     => 'confirmed',
			),
			array(
				'test_condition_name' => '他人の予約 => forbidden',
				'settings'            => array( 'provider_booking_cancel_mode' => 'hours', 'provider_booking_cancel_deadline_hours' => 24 ),
				'author'              => $other_id,
				'offset'              => 3 * DAY_IN_SECONDS,
				'status'              => 'confirmed',
				'expected_code'       => 'forbidden',
				'expected_status'     => 'confirmed',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['settings'] );
			$booking_id = $this->create_booking( $case['author'], $case['offset'], $case['status'] );
			wp_set_current_user( $user_id );

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/my-bookings/' . $booking_id . '/cancel' );
			$request->set_param( 'id', $booking_id );
			$result = ( new My_Bookings_Controller( new Settings_Repository() ) )->cancel_booking( $request );

			if ( null === $case['expected_code'] ) {
				$this->assertInstanceOf( WP_REST_Response::class, $result, $case['test_condition_name'] );
				$this->assertSame( array( 'cancelled' => true ), $result->get_data(), $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( $case['expected_code'], $result->get_error_code(), $case['test_condition_name'] );
			}
			$this->assertSame( $case['expected_status'], get_post_meta( $booking_id, '_vkbm_booking_status', true ), $case['test_condition_name'] . '（ステータス）' );
		}
	}
}
