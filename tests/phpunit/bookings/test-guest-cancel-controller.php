<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_Customer_Cancellation;
use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Bookings\Guest_Cancel_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * ゲスト予約のキャンセル用リンク（トークンの保存・照合・画面の用意・キャンセルの実行）のテスト。
 *
 * @group bookings
 * @group guest-booking
 */
class Guest_Cancel_Controller_Test extends WP_UnitTestCase {
	/**
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_backup            = $_SERVER;
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		Guest_Cancel_Controller::reset_current_view();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_GET    = array();
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Guest_Cancel_Controller::reset_current_view();
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
	 * ゲスト予約を 1 件作り、キャンセル用トークンを発行する。
	 *
	 * @param array<string, mixed> $meta 上書きする予約メタ。キー `_source` は null で印なし（会員の予約）にする。
	 * @return array{0: int, 1: string} 予約 ID と生のトークン。
	 */
	private function create_guest_booking( array $meta = array() ): array {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => 0,
			)
		);
		$values     = array_merge(
			array(
				'_vkbm_booking_service_start'  => wp_date( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ),
				'_vkbm_booking_service_end'    => wp_date( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS ),
				'_vkbm_booking_status'         => 'confirmed',
				'_vkbm_booking_customer_name'  => 'ゲスト 太郎',
				'_vkbm_booking_customer_email' => 'guest@example.com',
				Booking_Source::META_KEY       => Booking_Source::VALUE_GUEST,
			),
			$meta
		);
		foreach ( $values as $key => $value ) {
			if ( null !== $value ) {
				update_post_meta( $booking_id, $key, $value );
			}
		}

		return array( $booking_id, Guest_Cancel_Controller::issue_token( $booking_id ) );
	}

	/**
	 * キャンセル通知の呼び出しを記録するコントローラーを作る。
	 *
	 * @return array{0: Guest_Cancel_Controller, 1: Guest_Cancel_Notification_Double}
	 */
	private function build_controller(): array {
		$settings     = new Settings_Repository();
		$notification = new Guest_Cancel_Notification_Double();

		return array(
			new Guest_Cancel_Controller( $settings, new Booking_Customer_Cancellation( $settings, $notification ) ),
			$notification,
		);
	}

	/**
	 * トークンの発行: 32 文字の英数字を返し、予約には SHA-256 のハッシュだけを保存する。古いものは上限まで残す。
	 */
	public function test_issue_token(): void {
		list( $booking_id, $first ) = $this->create_guest_booking();

		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9]{32}$/', $first ), '英数字 32 文字' );
		$this->assertSame( array( hash( 'sha256', $first ) ), get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), 'ハッシュだけを保存する' );

		$test_cases = array(
			array(
				'test_condition_name' => '上限以内に発行を重ねる => 古いトークンのハッシュも残る',
				'issue_count'         => 2,
				'expected_rows'       => 3,
				'expected_first_kept' => true,
			),
			array(
				'test_condition_name' => '上限を超えて発行する => 古いものから消え、上限の件数に収まる',
				'issue_count'         => 12,
				'expected_rows'       => Guest_Cancel_Controller::MAX_TOKEN_HASHES,
				'expected_first_kept' => false,
			),
		);

		$issued = 1;
		foreach ( $test_cases as $case ) {
			while ( $issued < $case['issue_count'] + 1 ) {
				$token = Guest_Cancel_Controller::issue_token( $booking_id );
				$this->assertNotSame( $first, $token, $case['test_condition_name'] . '（毎回違う値）' );
				++$issued;
			}
			$rows = get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false );
			$this->assertCount( $case['expected_rows'], $rows, $case['test_condition_name'] );
			$this->assertSame( $case['expected_first_kept'], in_array( hash( 'sha256', $first ), $rows, true ), $case['test_condition_name'] . '（最初のトークン）' );
			$this->assertSame( $case['expected_first_kept'], Guest_Cancel_Controller::verify_token( $booking_id, $first ), $case['test_condition_name'] . '（最初のトークンの照合）' );
		}
	}

	/**
	 * トークンの照合: 予約 ID とトークンの組が正しいゲスト予約だけ通す。
	 */
	public function test_verify_token(): void {
		list( $booking_id, $raw ) = $this->create_guest_booking();
		list( $other_id, $other_raw ) = $this->create_guest_booking();
		list( $member_id, $member_raw ) = $this->create_guest_booking( array( Booking_Source::META_KEY => null ) );
		list( $anonymized_id, $anonymized_raw ) = $this->create_guest_booking();
		update_post_meta( $anonymized_id, Booking_Anonymizer::META_ANONYMIZED_AT, '2026-01-01 00:00:00' );
		list( $emptied_id, $emptied_raw ) = $this->create_guest_booking();
		update_post_meta( $emptied_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, '' );
		list( $trashed_id, $trashed_raw ) = $this->create_guest_booking();
		wp_trash_post( $trashed_id );
		$non_booking_id = (int) $this->factory()->post->create();
		add_post_meta( $non_booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, hash( 'sha256', str_repeat( 'a', 32 ) ) );
		add_post_meta( $non_booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );

		$test_cases = array(
			array( 'test_condition_name' => '正しい組 => 通る', 'booking_id' => $booking_id, 'token' => $raw, 'expected' => true ),
			array( 'test_condition_name' => '別の予約のトークン => 通らない', 'booking_id' => $booking_id, 'token' => $other_raw, 'expected' => false ),
			array( 'test_condition_name' => '別の予約の ID => 通らない', 'booking_id' => $other_id, 'token' => $raw, 'expected' => false ),
			array( 'test_condition_name' => '別の予約の組は、その予約では通る => 通る', 'booking_id' => $other_id, 'token' => $other_raw, 'expected' => true ),
			array( 'test_condition_name' => '1 文字違いのトークン => 通らない', 'booking_id' => $booking_id, 'token' => substr( $raw, 0, 31 ) . ( 'a' === substr( $raw, -1 ) ? 'b' : 'a' ), 'expected' => false ),
			array( 'test_condition_name' => '長さが違うトークン => 通らない', 'booking_id' => $booking_id, 'token' => substr( $raw, 0, 31 ), 'expected' => false ),
			array( 'test_condition_name' => '空のトークン => 通らない', 'booking_id' => $booking_id, 'token' => '', 'expected' => false ),
			array( 'test_condition_name' => '記号入りのトークン => 通らない', 'booking_id' => $booking_id, 'token' => substr( $raw, 0, 31 ) . '!', 'expected' => false ),
			array( 'test_condition_name' => '予約 ID が 0 => 通らない', 'booking_id' => 0, 'token' => $raw, 'expected' => false ),
			array( 'test_condition_name' => '存在しない予約 ID => 通らない', 'booking_id' => 99999999, 'token' => $raw, 'expected' => false ),
			array( 'test_condition_name' => 'ゲスト予約の印が無い予約（会員の予約）=> 通らない', 'booking_id' => $member_id, 'token' => $member_raw, 'expected' => false ),
			array( 'test_condition_name' => '匿名化済みの予約 => 通らない', 'booking_id' => $anonymized_id, 'token' => $anonymized_raw, 'expected' => false ),
			array( 'test_condition_name' => 'ハッシュを空にした予約 => 通らない', 'booking_id' => $emptied_id, 'token' => $emptied_raw, 'expected' => false ),
			array( 'test_condition_name' => 'ゴミ箱に入れた予約 => 通らない', 'booking_id' => $trashed_id, 'token' => $trashed_raw, 'expected' => false ),
			array( 'test_condition_name' => '予約でない投稿 => 通らない', 'booking_id' => $non_booking_id, 'token' => str_repeat( 'a', 32 ), 'expected' => false ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Guest_Cancel_Controller::verify_token( $case['booking_id'], $case['token'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * トークンの削除: 予約のハッシュをすべて消す。
	 */
	public function test_clear_tokens(): void {
		list( $booking_id, $raw ) = $this->create_guest_booking();
		Guest_Cancel_Controller::issue_token( $booking_id );

		Guest_Cancel_Controller::clear_tokens( $booking_id );

		$this->assertSame( array(), get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), 'ハッシュが残らない' );
		$this->assertFalse( Guest_Cancel_Controller::verify_token( $booking_id, $raw ), '消した後は通らない' );
	}

	/**
	 * メールのリンクの組み立て: 予約ページの URL に、トークンと予約 ID を付ける。
	 */
	public function test_build_cancel_url(): void {
		$raw = str_repeat( 'a', 32 );

		$test_cases = array(
			array(
				'test_condition_name' => '予約ページ URL がある => その URL にクエリを付ける',
				'settings'            => array( 'reservation_page_url' => 'https://example.com/reserve/' ),
				'expected'            => 'https://example.com/reserve/?vkbm_guest_cancel=' . $raw . '&booking=12',
			),
			array(
				'test_condition_name' => '予約ページ URL が ?page_id= の形 => 既存のクエリを残して付ける',
				'settings'            => array( 'reservation_page_url' => 'https://example.com/?page_id=7' ),
				'expected'            => 'https://example.com/?page_id=7&vkbm_guest_cancel=' . $raw . '&booking=12',
			),
			array(
				'test_condition_name' => '予約ページ URL が無い => サイトのトップページに付ける',
				'settings'            => array( 'reservation_page_url' => '' ),
				'expected'            => home_url( '/' ) . '?vkbm_guest_cancel=' . $raw . '&booking=12',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Guest_Cancel_Controller::build_cancel_url( 12, $raw, $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * リンクを開いたとき（GET）: 状態を確かめるだけで、キャンセルしない。
	 */
	public function test_handle_cancel_view_request(): void {
		list( $controller ) = $this->build_controller();
		$this->save_settings(
			array(
				'provider_booking_cancel_mode'           => 'hours',
				'provider_booking_cancel_deadline_hours' => 24,
			)
		);
		list( $booking_id, $raw ) = $this->create_guest_booking();
		list( $soon_id, $soon_raw ) = $this->create_guest_booking(
			array(
				'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
				'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ),
			)
		);
		list( $past_id, $past_raw ) = $this->create_guest_booking(
			array(
				'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);
		list( $cancelled_id, $cancelled_raw ) = $this->create_guest_booking( array( '_vkbm_booking_status' => 'cancelled' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '正しいリンク（キャンセルできる） => can_cancel。予約はキャンセルしない',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $booking_id ),
				'expected_state'      => 'can_cancel',
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => '締め切り（24 時間前）を過ぎた予約 => cancel_deadline_passed',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $soon_raw, 'booking' => (string) $soon_id ),
				'expected_state'      => 'cancel_deadline_passed',
				'expected_status'     => array( $soon_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => '開始時刻を過ぎた予約 => cancel_deadline_passed',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $past_raw, 'booking' => (string) $past_id ),
				'expected_state'      => 'cancel_deadline_passed',
				'expected_status'     => array( $past_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => '店舗側でキャンセル済みの予約（トークンは残っている） => cancelled',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $cancelled_raw, 'booking' => (string) $cancelled_id ),
				'expected_state'      => 'cancelled',
				'expected_status'     => array( $cancelled_id, 'cancelled' ),
			),
			array(
				'test_condition_name' => '予約 ID が合わない => invalid',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $soon_id ),
				'expected_state'      => 'invalid',
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => '予約 ID が無い => invalid',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => $raw ),
				'expected_state'      => 'invalid',
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => 'トークンの形が不正 => invalid',
				'method'              => 'GET',
				'get'                 => array( 'vkbm_guest_cancel' => 'short', 'booking' => (string) $booking_id ),
				'expected_state'      => 'invalid',
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => 'クエリが無い通常のページ => 何もしない',
				'method'              => 'GET',
				'get'                 => array(),
				'expected_state'      => null,
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
			array(
				'test_condition_name' => 'HEAD（メールの安全確認の仕組みの先読み） => 何もしない',
				'method'              => 'HEAD',
				'get'                 => array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $booking_id ),
				'expected_state'      => null,
				'expected_status'     => array( $booking_id, 'confirmed' ),
			),
		);

		foreach ( $test_cases as $case ) {
			Guest_Cancel_Controller::reset_current_view();
			$_SERVER['REQUEST_METHOD'] = $case['method'];
			$_GET                      = $case['get'];

			$controller->handle_cancel_view_request();

			$view = Guest_Cancel_Controller::get_current_view();
			$this->assertSame( $case['expected_state'], null === $view ? null : $view['state'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_status'][1], get_post_meta( $case['expected_status'][0], '_vkbm_booking_status', true ), $case['test_condition_name'] . '（開いただけではキャンセルしない）' );
		}

		// 「キャンセル不可」の設定。
		$this->save_settings( array( 'provider_booking_cancel_mode' => 'none' ) );
		Guest_Cancel_Controller::reset_current_view();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $booking_id );
		$controller->handle_cancel_view_request();
		$this->assertSame( 'cancel_disabled', Guest_Cancel_Controller::get_current_view()['state'], '設定が「キャンセル不可」 => cancel_disabled' );
		$this->assertSame( __( 'This reservation cannot be canceled online.', 'vk-booking-manager' ), Guest_Cancel_Controller::get_current_view()['message'], 'マイページと同じ文言' );
	}

	/**
	 * 画面へ渡す値: トークンは含めず、予約内容はトークンが正しいときだけ付ける。
	 */
	public function test_get_public_view(): void {
		list( $controller ) = $this->build_controller();
		list( $booking_id, $raw ) = $this->create_guest_booking( array( '_vkbm_booking_customer_tel' => '09012345678' ) );
		$this->save_settings( array( 'provider_phone' => '03-1234-5678' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertNull( Guest_Cancel_Controller::get_public_view(), 'リンクを開いていなければ null' );

		$_GET = array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $booking_id );
		$controller->handle_cancel_view_request();
		$view = Guest_Cancel_Controller::get_public_view();
		$this->assertSame( 'can_cancel', $view['state'] );
		$this->assertSame( $booking_id, $view['bookingId'] );
		$this->assertIsArray( $view['booking'], '正しいリンクなら予約内容を渡す' );
		$this->assertSame( '03-1234-5678', $view['providerPhone'] );
		$encoded = (string) wp_json_encode( $view );
		$this->assertStringNotContainsString( $raw, $encoded, '生のトークンは画面へ渡さない' );
		$this->assertStringNotContainsString( 'guest@example.com', $encoded, '連絡先は渡さない' );
		$this->assertStringNotContainsString( '09012345678', $encoded, '電話番号は渡さない' );

		Guest_Cancel_Controller::reset_current_view();
		$_GET = array( 'vkbm_guest_cancel' => str_repeat( 'x', 32 ), 'booking' => (string) $booking_id );
		$controller->handle_cancel_view_request();
		$view = Guest_Cancel_Controller::get_public_view();
		$this->assertSame( 'invalid', $view['state'] );
		$this->assertSame( 0, $view['bookingId'], '正しくないリンクには予約 ID を渡さない' );
		$this->assertNull( $view['booking'], '正しくないリンクには予約内容を渡さない' );
		$this->assertSame( '03-1234-5678', $view['providerPhone'], '店舗の電話番号は予約の有無に関係ない公開情報なので、正しくないリンクにも渡す' );
	}

	/**
	 * キャンセルの実行（POST）: トークンと予約 ID で本人を確かめ、キャンセルする。
	 */
	public function test_cancel_booking(): void {
		$this->save_settings(
			array(
				'provider_booking_cancel_mode'           => 'hours',
				'provider_booking_cancel_deadline_hours' => 24,
			)
		);

		$soon = array(
			'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
			'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '正しいトークン => キャンセルされ、トークンのハッシュが消え、キャンセル通知を送る',
				'meta'                => array(),
				'token'               => 'valid',
				'expected_code'       => null,
				'expected_status'     => 'cancelled',
				'expected_notified'   => 1,
				'expected_hash_rows'  => 0,
			),
			array(
				'test_condition_name' => '間違ったトークン => 403 で、キャンセルしない',
				'meta'                => array(),
				'token'               => str_repeat( 'z', 32 ),
				'expected_code'       => 'guest_cancel_invalid',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => 'トークンが空 => 403 で、キャンセルしない',
				'meta'                => array(),
				'token'               => '',
				'expected_code'       => 'guest_cancel_invalid',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => '締め切りを過ぎた予約 => cancel_deadline_passed で、キャンセルしない（トークンは残す）',
				'meta'                => $soon,
				'token'               => 'valid',
				'expected_code'       => 'cancel_deadline_passed',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => '開始時刻を過ぎた予約 => cancel_deadline_passed で、キャンセルしない',
				'meta'                => array(
					'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				),
				'token'               => 'valid',
				'expected_code'       => 'cancel_deadline_passed',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => '匿名化済みの予約 => 403 で、キャンセルしない',
				'meta'                => array( Booking_Anonymizer::META_ANONYMIZED_AT => '2026-01-01 00:00:00' ),
				'token'               => 'valid',
				'expected_code'       => 'guest_cancel_invalid',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => '会員の予約（ゲスト予約の印が無い） => 403 で、キャンセルしない',
				'meta'                => array( Booking_Source::META_KEY => null ),
				'token'               => 'valid',
				'expected_code'       => 'guest_cancel_invalid',
				'expected_status'     => 'confirmed',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 1,
			),
			array(
				'test_condition_name' => 'すでにキャンセル済み（店舗側など）=> 成功を返し、通知は重ねて送らず、トークンは消す',
				'meta'                => array( '_vkbm_booking_status' => 'cancelled' ),
				'token'               => 'valid',
				'expected_code'       => null,
				'expected_status'     => 'cancelled',
				'expected_notified'   => 0,
				'expected_hash_rows'  => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			list( $controller, $notification ) = $this->build_controller();
			list( $booking_id, $raw )          = $this->create_guest_booking( $case['meta'] );
			$token                             = 'valid' === $case['token'] ? $raw : $case['token'];

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
			$request->set_param( 'id', $booking_id );
			$request->set_param( 'token', $token );
			$response = $controller->cancel_booking( $request );

			if ( null === $case['expected_code'] ) {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$this->assertSame( array( 'cancelled' => true ), $response->get_data(), $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_code'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( 403, $response->get_error_data()['status'], $case['test_condition_name'] );
			}
			$this->assertSame( $case['expected_status'], get_post_meta( $booking_id, '_vkbm_booking_status', true ), $case['test_condition_name'] . '（ステータス）' );
			$this->assertSame( $case['expected_notified'], count( $notification->cancelled ), $case['test_condition_name'] . '（キャンセル通知）' );
			$this->assertCount( $case['expected_hash_rows'], get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), $case['test_condition_name'] . '（トークンのハッシュ）' );
		}
	}

	/**
	 * キャンセルした後は、同じリンクでキャンセルも画面の表示もできない。
	 */
	public function test_cancel_booking_link_is_single_use(): void {
		list( $controller, $notification ) = $this->build_controller();
		list( $booking_id, $raw )          = $this->create_guest_booking();

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
		$request->set_param( 'id', $booking_id );
		$request->set_param( 'token', $raw );
		$this->assertInstanceOf( WP_REST_Response::class, $controller->cancel_booking( $request ), '1 回目は成功' );

		$again = $controller->cancel_booking( $request );
		$this->assertInstanceOf( WP_Error::class, $again, '2 回目は使えない' );
		$this->assertSame( 'guest_cancel_invalid', $again->get_error_code() );
		$this->assertCount( 1, $notification->cancelled, '通知は 1 回だけ' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'vkbm_guest_cancel' => $raw, 'booking' => (string) $booking_id );
		$controller->handle_cancel_view_request();
		$this->assertSame( 'invalid', Guest_Cancel_Controller::get_current_view()['state'], 'キャンセル後に開くと使えないリンクとして扱う' );
	}

	/**
	 * REST のルートが登録され、未ログインでもトークンでキャンセルできる。
	 */
	public function test_register_routes(): void {
		// 本体が起動時に登録した実物のコントローラーのルートに対して確かめる（ここで作ったものは、ルート登録の呼び出しに使うだけ）。
		list( $controller ) = $this->build_controller();
		$controller->register();
		list( $booking_id, $raw ) = $this->create_guest_booking();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ルートの登録を確かめるため差し替える。
		do_action( 'rest_api_init' );
		$this->assertArrayHasKey( '/vkbm/v1/guest-bookings/(?P<id>\\d+)/cancel', $wp_rest_server->get_routes(), 'ルートが登録されている' );

		wp_set_current_user( 0 );
		$missing = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
		$this->assertSame( 400, rest_do_request( $missing )->get_status(), 'token が無いリクエストは 400（必須の引数）' );
		$array = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
		$array->set_body_params( array( 'token' => array( 'x' ) ) );
		$this->assertSame( 400, rest_do_request( $array )->get_status(), '配列の token は 400（文字列だけ受け付ける）' );
		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );

		$wrong = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
		$wrong->set_body_params( array( 'token' => str_repeat( 'q', 32 ) ) );
		$this->assertSame( 403, rest_do_request( $wrong )->get_status(), '間違ったトークンは 403' );
		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );

		$ok = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings/' . $booking_id . '/cancel' );
		$ok->set_body_params( array( 'token' => $raw ) );
		$response = rest_do_request( $ok );
		$this->assertSame( 200, $response->get_status(), '未ログインでも正しいトークンならキャンセルできる' );
		$this->assertSame( 'cancelled', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
		$this->assertSame( array(), get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), 'キャンセルしたらトークンのハッシュは消える' );
	}

	/**
	 * 発行した 1 件だけを取り消せる（送れなかったメールの分）。
	 */
	public function test_revoke_token(): void {
		list( $booking_id, $first ) = $this->create_guest_booking();
		$second                     = Guest_Cancel_Controller::issue_token( $booking_id );

		Guest_Cancel_Controller::revoke_token( $booking_id, $second );
		$this->assertFalse( Guest_Cancel_Controller::verify_token( $booking_id, $second ), '取り消した分は通らない' );
		$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $first ), '前に発行した分は残る' );

		Guest_Cancel_Controller::revoke_token( $booking_id, '' );
		$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $first ), '空のトークンでは何も消さない' );
	}

	/**
	 * ページの先頭で出す、トークンを URL から外す固定のスクリプト: リンクのクエリがあるときだけ出し、値は埋め込まない。
	 */
	public function test_print_link_scrubber(): void {
		$test_cases = array(
			array( 'test_condition_name' => 'キャンセル用リンクのクエリがある => 出す', 'get' => array( 'vkbm_guest_cancel' => 'secretvalue123' ), 'expected_script' => true ),
			array( 'test_condition_name' => '確認リンクのクエリがある => 出す', 'get' => array( 'vkbm_guest_verify' => 'secretvalue123' ), 'expected_script' => true ),
			array( 'test_condition_name' => 'どちらも無い => 出さない', 'get' => array( 'page_id' => '5' ), 'expected_script' => false ),
		);

		foreach ( $test_cases as $case ) {
			$_GET = $case['get'];
			ob_start();
			Guest_Cancel_Controller::print_link_scrubber();
			$output = (string) ob_get_clean();

			$this->assertSame( $case['expected_script'], false !== strpos( $output, 'vkbmGuestLinkParams' ), $case['test_condition_name'] );
			$this->assertStringNotContainsString( 'secretvalue123', $output, $case['test_condition_name'] . '（リクエストの値は埋め込まない）' );
			if ( $case['expected_script'] ) {
				$this->assertStringContainsString( 'vkbm_guest_cancel', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'vkbm_guest_verify', $output, $case['test_condition_name'] );
				$this->assertStringContainsString( 'replaceState', $output, $case['test_condition_name'] );
			}
		}

	}
}

/**
 * キャンセル通知の呼び出しを記録するテスト用ダブル。
 */
class Guest_Cancel_Notification_Double extends Booking_Notification_Service {
	/**
	 * キャンセル通知を送った予約 ID。
	 *
	 * @var array<int, int>
	 */
	public array $cancelled = array();

	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	public function handle_customer_cancellation( int $booking_id ): void {
		$this->cancelled[] = $booking_id;
	}
}
