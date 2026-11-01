<?php
/**
 * お客様1人あたりの予約数の上限のテスト（#971）。
 *
 * 設定の既定値・サニタイズ、数える範囲、会員・ゲストそれぞれが上限ちょうどで止まること、
 * 代理予約（予約管理権限）は止めないこと、下書きの保存時点で会員を早めに止めることを確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Bookings\Customer_Booking_Limit;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Customer_Booking_Limit と、予約確定・下書き保存での上限判定のテスト。
 *
 * @group bookings
 */
class Customer_Booking_Limit_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';
	private const MUTEX_OPTION     = '_transient_vkbm_booking_mutex_';

	/** @var string */
	private $original_timezone_string = '';

	/** @var mixed */
	private $original_settings = false;

	/** @var array<int, string> */
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		delete_option( Settings_Repository::OPTION_KEY );
		// 指名機能を切る（時間枠の自動割当と同じ条件にそろえる）。
		Staff_Editor::clear_nomination_enabled_cache();
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		remove_all_filters( 'vkbm_customer_booking_limit' );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 保存済みの設定が無いサイトでも、上限の既定値は 3 になる（既存サイトにもアップデート直後から効く）。
	 */
	public function test_default_limit_applies_to_sites_without_saved_value(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '設定を一度も保存していないサイト => 3',
				'saved'               => false,
				'expected'            => 3,
			),
			array(
				'test_condition_name' => 'この項目が無い古い設定だけが保存されているサイト => 3',
				'saved'               => array( 'provider_name' => '旧設定のお店' ),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '0（上限なし）を保存したサイト => 0',
				'saved'               => array( Customer_Booking_Limit::SETTINGS_KEY => 0 ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '5 を保存したサイト => 5',
				'saved'               => array( Customer_Booking_Limit::SETTINGS_KEY => 5 ),
				'expected'            => 5,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( false !== $case['saved'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved'] );
			}

			$settings = ( new Settings_Repository() )->get_settings();

			$this->assertSame( $case['expected'], Customer_Booking_Limit::resolve_limit( $settings ), $case['test_condition_name'] );
		}
	}

	/**
	 * 設定の保存時は、非負の整数にそろえる。空欄は 0（上限なし）、送られなかった場合は保存済みの値（なければ既定値）を残す。
	 */
	public function test_sanitize_limit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '5 => 5',
				'input'               => array( Customer_Booking_Limit::SETTINGS_KEY => '5' ),
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '空欄 => 0（上限なし）',
				'input'               => array( Customer_Booking_Limit::SETTINGS_KEY => '' ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '負の数 => 0',
				'input'               => array( Customer_Booking_Limit::SETTINGS_KEY => '-2' ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '数字以外 => 0',
				'input'               => array( Customer_Booking_Limit::SETTINGS_KEY => 'abc' ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '送られなかった場合 => 既定値 3 のまま',
				'input'               => array(),
				'expected'            => 3,
			),
		);

		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();
		foreach ( $test_cases as $case ) {
			$sanitized = $sanitizer->sanitize( $case['input'], $defaults );

			$this->assertSame( $case['expected'], $sanitized[ Customer_Booking_Limit::SETTINGS_KEY ], $case['test_condition_name'] );
		}
	}

	/**
	 * 上限の値はフィルターで差し替えられ、負の値は 0（上限なし）にそろえる。
	 */
	public function test_resolve_limit_applies_filter(): void {
		$settings = array( Customer_Booking_Limit::SETTINGS_KEY => 3 );

		$this->assertSame( 3, Customer_Booking_Limit::resolve_limit( $settings ), 'フィルターが無い場合 => 設定の値' );

		$received = array();
		add_filter(
			'vkbm_customer_booking_limit',
			static function ( $limit, $user_id, $guest_email ) use ( &$received ) {
				$received = array( $limit, $user_id, $guest_email );
				return 7;
			},
			10,
			3
		);
		$this->assertSame( 7, Customer_Booking_Limit::resolve_limit( $settings, 12, 'a@example.com' ), 'フィルターが値を返した場合 => その値' );
		$this->assertSame( array( 3, 12, 'a@example.com' ), $received, 'フィルターへ設定の値・会員 ID・ゲストのメールアドレスを渡す' );

		remove_all_filters( 'vkbm_customer_booking_limit' );
		add_filter( 'vkbm_customer_booking_limit', static fn() => -5 );
		$this->assertSame( 0, Customer_Booking_Limit::resolve_limit( $settings ), '負の値 => 0（上限なし）' );
	}

	/**
	 * 上限なし（0）は常に止めず、上限があるときは「件数 >= 上限」で止める。
	 */
	public function test_is_reached(): void {
		$test_cases = array(
			array( 'limit' => 0, 'count' => 100, 'expected' => false, 'test_condition_name' => '上限 0（上限なし） => 件数が多くても止めない' ),
			array( 'limit' => 3, 'count' => 2, 'expected' => false, 'test_condition_name' => '上限の1つ手前 => 止めない' ),
			array( 'limit' => 3, 'count' => 3, 'expected' => true, 'test_condition_name' => '上限ちょうど => 止める' ),
			array( 'limit' => 3, 'count' => 4, 'expected' => true, 'test_condition_name' => '上限を超えている => 止める' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Customer_Booking_Limit::is_reached( $case['limit'], $case['count'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 会員の件数は、キャンセル・無断キャンセル・過去・他の会員の予約を除いて数える。仮予約と状態の記録が無い古い予約は数える。
	 */
	public function test_count_upcoming_for_member(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '確定の予約 => 数える',
				'bookings'            => array( array( 'status' => 'confirmed' ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '仮予約 => 数える',
				'bookings'            => array( array( 'status' => 'pending' ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '状態の記録が無い古い予約 => 数える',
				'bookings'            => array( array( 'status' => null ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => 'キャンセル => 数えない',
				'bookings'            => array( array( 'status' => 'cancelled' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '無断キャンセル => 数えない',
				'bookings'            => array( array( 'status' => 'no_show' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '開始日時が過去の確定の予約 => 数えない',
				'bookings'            => array( array( 'status' => 'confirmed', 'days' => -2 ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '公開済みでない予約（下書き） => 数えない',
				'bookings'            => array( array( 'status' => 'confirmed', 'post_status' => 'draft' ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '他の会員の予約 => 数えない',
				'bookings'            => array( array( 'status' => 'confirmed', 'other_author' => true ) ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '店が作った予約（電話番号で一致した会員が作成者） => 数える',
				'bookings'            => array( array( 'status' => 'confirmed', 'created_by_store' => true ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '人数が多く複数日にまたがる1件の予約 => 1件と数える',
				'bookings'            => array( array( 'status' => 'confirmed', 'multi_night_guests' => 4 ) ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '確定・仮予約・古い予約・キャンセル・無断キャンセル・過去が混在 => 数える3件だけ',
				'bookings'            => array(
					array( 'status' => 'confirmed' ),
					array( 'status' => 'pending' ),
					array( 'status' => null ),
					array( 'status' => 'cancelled' ),
					array( 'status' => 'no_show' ),
					array( 'status' => 'confirmed', 'days' => -1 ),
				),
				'expected'            => 3,
			),
		);

		foreach ( $test_cases as $case ) {
			$member_id = $this->factory()->user->create();
			$other_id  = $this->factory()->user->create();
			foreach ( $case['bookings'] as $index => $booking ) {
				$author = ! empty( $booking['other_author'] ) ? $other_id : $member_id;
				$this->create_booking_post( $author, array_merge( array( 'days' => 2 + $index ), $booking ) );
			}

			$this->assertSame( $case['expected'], Customer_Booking_Limit::count_upcoming_for_member( $member_id ), $case['test_condition_name'] );
		}

		$this->assertSame( 0, Customer_Booking_Limit::count_upcoming_for_member( 0 ), 'ユーザー ID が 0 => 0' );
	}

	/**
	 * ゲストの件数は、そろえたメールアドレスで数え、同じメールアドレスを持つ会員の予約も合算する。電話番号は使わない。
	 */
	public function test_count_upcoming_for_guest(): void {
		$email  = 'shared-guest@example.com';
		$member = $this->factory()->user->create( array( 'user_email' => $email ) );

		// ゲスト予約（作成者なし）が2件、同じメールアドレスの会員の予約が1件。会員の予約は予約者メールも同じ値（両方に当たっても1件）。
		$this->create_booking_post( 0, array( 'days' => 2, 'email' => $email ) );
		$this->create_booking_post( 0, array( 'days' => 3, 'email' => $email, 'status' => 'pending' ) );
		$this->create_booking_post( $member, array( 'days' => 4, 'email' => $email ) );
		// 会員が予約したあとにメールアドレスを変えた場合: 予約のメールは古い値、作成者は会員。
		$this->create_booking_post( $member, array( 'days' => 5, 'email' => 'old-address@example.com' ) );
		// 数えない予約。
		$this->create_booking_post( 0, array( 'days' => 6, 'email' => $email, 'status' => 'cancelled' ) );
		$this->create_booking_post( 0, array( 'days' => 7, 'email' => $email, 'status' => 'no_show' ) );
		$this->create_booking_post( 0, array( 'days' => -3, 'email' => $email ) );
		$this->create_booking_post( 0, array( 'days' => 8, 'email' => 'other@example.com' ) );
		// 電話番号だけが同じ予約は数えない。
		$this->create_booking_post( 0, array( 'days' => 9, 'email' => 'phone-only@example.com', 'phone' => '09012345678' ) );

		$this->assertSame( 4, Customer_Booking_Limit::count_upcoming_for_guest( $email ), '同じメールアドレスのゲスト予約2件 + 会員の予約2件（重複なし）' );
		$this->assertSame( 1, Customer_Booking_Limit::count_upcoming_for_guest( 'other@example.com' ), '別のメールアドレス => その予約だけ' );
		$this->assertSame( 0, Customer_Booking_Limit::count_upcoming_for_guest( '' ), 'メールアドレスが空 => 0' );
	}

	/**
	 * 会員の予約確定: 上限ちょうどで止まり、上限の手前・上限なし・予約管理権限の代理予約は止めない。
	 */
	public function test_create_booking_by_member(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '上限 3 で確定の予約が3件 => 409 で止まる',
				'limit'               => 3,
				'existing'            => array( 'confirmed', 'confirmed', 'confirmed' ),
				'as_manager'          => false,
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => '上限 3 で仮予約が3件 => 止まる（仮予約も数える）',
				'limit'               => 3,
				'existing'            => array( 'pending', 'pending', 'pending' ),
				'as_manager'          => false,
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => '上限 3 で予約が2件 => 3件目は取れる',
				'limit'               => 3,
				'existing'            => array( 'confirmed', 'pending' ),
				'as_manager'          => false,
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '上限 3 で、キャンセル・無断キャンセル・過去の予約を除くと2件 => 取れる',
				'limit'               => 3,
				'existing'            => array( 'confirmed', 'pending', 'cancelled', 'no_show', 'past' ),
				'as_manager'          => false,
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '上限 0（上限なし）で予約が5件 => 取れる',
				'limit'               => 0,
				'existing'            => array( 'confirmed', 'confirmed', 'confirmed', 'confirmed', 'confirmed' ),
				'as_manager'          => false,
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '上限 1 で予約が1件 => 止まる',
				'limit'               => 1,
				'existing'            => array( 'confirmed' ),
				'as_manager'          => false,
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => '代理予約（予約管理権限）: 電話番号で一致した会員が上限に達していても => 止めない',
				'limit'               => 3,
				'existing'            => array( 'confirmed', 'confirmed', 'confirmed' ),
				'as_manager'          => true,
				'expected_error'      => '',
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array( Customer_Booking_Limit::SETTINGS_KEY => $case['limit'] ) );

			$customer_id = $this->factory()->user->create();
			$phone       = '0901234' . str_pad( (string) $customer_id, 4, '0', STR_PAD_LEFT );
			update_user_meta( $customer_id, 'phone_number', $phone );
			foreach ( $case['existing'] as $index => $status ) {
				$this->create_booking_post(
					$customer_id,
					'past' === $status ? array( 'days' => -3 ) : array( 'days' => 2 + $index, 'status' => $status )
				);
			}
			$before = $this->count_bookings_of_author( $customer_id );

			$response = $this->run_member_booking( $customer_id, $case['as_manager'], $phone );

			if ( '' !== $case['expected_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( 409, $response->get_error_data()['status'] ?? 0, $case['test_condition_name'] );
				$this->assertSame( $case['limit'], $response->get_error_data()['limit'] ?? 0, $case['test_condition_name'] . '（エラーに上限の件数を持つ）' );
				$this->assertStringContainsString( (string) $case['limit'], $response->get_error_message(), $case['test_condition_name'] . '（文言に上限の件数が入る）' );
				$this->assertSame( $before, $this->count_bookings_of_author( $customer_id ), $case['test_condition_name'] . '（予約が増えていない）' );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ( $response instanceof WP_Error ? ' ' . $response->get_error_code() : '' ) );
				$this->assertSame( $before + 1, $this->count_bookings_of_author( $customer_id ), $case['test_condition_name'] . '（予約が1件増えた）' );
			}
			$this->assertSame( 0, $this->count_mutex_options(), $case['test_condition_name'] . '（利用者のロックも枠のロックも解放されている）' );
		}
	}

	/**
	 * フィルターで上限を差し替えると、会員の予約確定にも反映される。
	 */
	public function test_create_booking_uses_filtered_limit(): void {
		$customer_id = $this->factory()->user->create();
		$this->create_booking_post( $customer_id, array( 'days' => 2 ) );

		add_filter( 'vkbm_customer_booking_limit', static fn() => 1 );
		$blocked = $this->run_member_booking( $customer_id, false, '' );
		$this->assertInstanceOf( WP_Error::class, $blocked, 'フィルターで上限 1 => 1件持っていると止まる' );
		$this->assertSame( Customer_Booking_Limit::ERROR_CODE, $blocked->get_error_code() );

		remove_all_filters( 'vkbm_customer_booking_limit' );
		add_filter( 'vkbm_customer_booking_limit', static fn() => 2 );
		$this->assertInstanceOf( WP_REST_Response::class, $this->run_member_booking( $customer_id, false, '' ), 'フィルターで上限 2 => 1件持っていても取れる' );
	}

	/**
	 * ゲストの予約確定: 上限ちょうどで止まり、同じメールアドレスの会員の予約も数える。別のメールアドレスと電話番号だけの一致は数えない。
	 */
	public function test_create_booking_by_guest(): void {
		$phone = '09055556666';

		$test_cases = array(
			array(
				'test_condition_name' => '同じメールアドレスのゲスト予約が3件 => 止まる',
				'prepare'             => 'guest_3',
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => '同じメールアドレスの会員の予約が3件 => 止まる（会員の予約も合算する）',
				'prepare'             => 'member_3',
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => 'ゲスト予約が2件 + 同じメールアドレスの会員の予約が1件 => 止まる（合算して3件）',
				'prepare'             => 'mixed_3',
				'expected_error'      => Customer_Booking_Limit::ERROR_CODE,
			),
			array(
				'test_condition_name' => '同じメールアドレスのゲスト予約が2件 => 取れる',
				'prepare'             => 'guest_2',
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '別のメールアドレスの予約が3件 => 取れる',
				'prepare'             => 'other_email_3',
				'expected_error'      => '',
			),
			array(
				'test_condition_name' => '電話番号だけが同じ予約が3件 => 取れる（電話番号では数えない）',
				'prepare'             => 'same_phone_3',
				'expected_error'      => '',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			// ケースごとに別のメールアドレスにして、前のケースの会員と一致しないようにする。
			$email  = 'limit-guest' . $index . '@example.com';
			$member = $this->factory()->user->create( array( 'user_email' => $email ) );
			switch ( $case['prepare'] ) {
				case 'guest_3':
					$this->create_bookings( 3, 0, $email );
					break;
				case 'member_3':
					$this->create_bookings( 3, $member, $email );
					break;
				case 'mixed_3':
					$this->create_bookings( 2, 0, $email );
					$this->create_bookings( 1, $member, $email, 2 );
					break;
				case 'guest_2':
					$this->create_bookings( 2, 0, $email );
					break;
				case 'other_email_3':
					$this->create_bookings( 3, 0, 'someone-else' . $index . '@example.com' );
					break;
				case 'same_phone_3':
					$this->create_bookings( 3, 0, 'phone-twin' . $index . '@example.com', 0, $phone );
					break;
			}
			$before = $this->count_all_bookings();

			$response = $this->run_guest_booking( $email, $phone );

			if ( '' !== $case['expected_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( 3, $response->get_error_data()['limit'] ?? 0, $case['test_condition_name'] );
				$this->assertSame( $before, $this->count_all_bookings(), $case['test_condition_name'] . '（予約が増えていない）' );
			} else {
				$this->assertIsArray( $response, $case['test_condition_name'] . ( $response instanceof WP_Error ? ' ' . $response->get_error_code() : '' ) );
				$this->assertSame( $before + 1, $this->count_all_bookings(), $case['test_condition_name'] . '（予約が1件増えた）' );
			}
			$this->assertSame( 0, $this->count_mutex_options(), $case['test_condition_name'] . '（ロックが解放されている）' );

			// 次のケースへ持ち越さないよう、予約を片付ける。
			foreach ( $this->get_all_booking_ids() as $booking_id ) {
				wp_delete_post( $booking_id, true );
			}
		}
	}

	/**
	 * 同じ利用者が予約を保存している最中（ロック保持中）の別リクエストは、待たされて 409（booking_busy）になり、予約を作らない。
	 */
	public function test_create_booking_is_blocked_while_the_same_customer_lock_is_held(): void {
		$customer_id = $this->factory()->user->create();
		global $wpdb;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => self::MUTEX_OPTION . 'user_' . $customer_id,
				'option_value' => 'othertoken|' . time(),
				'autoload'     => 'no',
			)
		);

		$response = $this->run_member_booking( $customer_id, false, '' );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'booking_busy', $response->get_error_code(), '別リクエストが利用者のロックを持っている => 待たされて予約を作らない' );
		$this->assertSame( 0, $this->count_bookings_of_author( $customer_id ) );

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::MUTEX_OPTION . 'user_' . $customer_id ) );
	}

	/**
	 * ゲストのメールアドレスを今持つ会員がいれば、会員と同じ利用者ロックを使う
	 * （同じメールアドレスの会員の確定とゲストの確認リンクが同時に来ても上限を超えないため）。
	 * 会員がいないメールアドレスのゲストは、会員のロックに影響されない。
	 */
	public function test_guest_booking_shares_the_member_lock_for_the_same_email(): void {
		global $wpdb;
		$email  = 'shared-lock@example.com';
		$member = $this->factory()->user->create( array( 'user_email' => $email ) );
		$option = self::MUTEX_OPTION . 'user_' . $member;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => 'othertoken|' . time(),
				'autoload'     => 'no',
			)
		);

		$blocked = $this->run_guest_booking( $email, '09011112222' );
		$this->assertInstanceOf( WP_Error::class, $blocked, '同じメールアドレスの会員のロックを別リクエストが持っている => 待たされる' );
		$this->assertSame( 'booking_busy', $blocked->get_error_code() );

		$free = $this->run_guest_booking( 'no-member-lock@example.com', '09033334444' );
		$this->assertIsArray( $free, '会員がいないメールアドレス => 会員のロックに影響されない' );

		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
	}

	/**
	 * 上限に達したときの文言は、上限の件数・やり直しの案内・キャンセルの案内を含む。
	 */
	public function test_build_message(): void {
		foreach ( array( 1, 3 ) as $limit ) {
			$message = Customer_Booking_Limit::build_message( $limit );

			$this->assertStringContainsString( (string) $limit, $message, "上限 {$limit} => 件数が入る" );
			$this->assertStringContainsString( __( 'Please apply again after your current reservations have ended or been cancelled.', 'vk-booking-manager' ), $message, "上限 {$limit} => やり直しの案内" );
			$this->assertStringEndsWith( __( 'You can cancel your reservations from Confirm reservation.', 'vk-booking-manager' ), $message, "上限 {$limit} => 末尾にキャンセルの案内" );
		}
	}

	/**
	 * 下書きの保存: ログイン中の会員は上限に達していれば早めに止まる。代理予約・上限なし・上限の手前・未ログインは止めない。
	 */
	public function test_save_draft_checks_limit_for_logged_in_member(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '会員が上限ちょうど => 下書きの段階で 409',
				'role'                => 'subscriber',
				'limit'               => 3,
				'existing'            => 3,
				'expect_limit_error'  => true,
			),
			array(
				'test_condition_name' => '会員が上限の1つ手前 => 止めない',
				'role'                => 'subscriber',
				'limit'               => 3,
				'existing'            => 2,
				'expect_limit_error'  => false,
			),
			array(
				'test_condition_name' => '会員が上限なし（0） => 止めない',
				'role'                => 'subscriber',
				'limit'               => 0,
				'existing'            => 5,
				'expect_limit_error'  => false,
			),
			array(
				'test_condition_name' => '予約管理権限を持つ人（代理予約）が自分の予約で上限ちょうど => 止めない',
				'role'                => 'administrator',
				'limit'               => 3,
				'existing'            => 3,
				'expect_limit_error'  => false,
			),
			array(
				'test_condition_name' => '未ログイン（ゲスト） => 申し込み時点では判定しない',
				'role'                => '',
				'limit'               => 3,
				'existing'            => 3,
				'expect_limit_error'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, array( Customer_Booking_Limit::SETTINGS_KEY => $case['limit'] ) );

			$user_id = '' === $case['role'] ? 0 : $this->factory()->user->create( array( 'role' => $case['role'] ) );
			for ( $i = 0; $i < $case['existing']; $i++ ) {
				$this->create_booking_post( $user_id, array( 'days' => 2 + $i ) );
			}
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_limit_test';

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'menu_id' => 999999 ) ) );
			$result = ( new Booking_Draft_Controller() )->save_draft( $request );

			$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] . '（メニューが無いので、上限に達していなくても別のエラーにはなる）' );
			if ( $case['expect_limit_error'] ) {
				$this->assertSame( Customer_Booking_Limit::ERROR_CODE, $result->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( 409, $result->get_error_data()['status'] ?? 0, $case['test_condition_name'] );
			} else {
				$this->assertNotSame( Customer_Booking_Limit::ERROR_CODE, $result->get_error_code(), $case['test_condition_name'] );
			}

			unset( $_COOKIE[ self::OWNER_COOKIE ] );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * 会員の予約確定を実行する（予約管理権限の有無で本人予約・代理予約を切り替える）。
	 *
	 * @param int    $customer_id 予約する会員のユーザー ID（代理予約では電話番号で一致する会員）。
	 * @param bool   $as_manager  予約管理権限を持つ人が代理で入れるか。
	 * @param string $phone       代理予約で入れるお客様の電話番号。
	 * @return WP_REST_Response|WP_Error 確定の結果。
	 */
	private function run_member_booking( int $customer_id, bool $as_manager, string $phone ) {
		$menu_id  = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
		$staff_id = $this->create_post( Resource_Post_Type::POST_TYPE );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		if ( $as_manager ) {
			wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		} else {
			wp_set_current_user( $customer_id );
		}
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_limit_test';

		list( $start, $end ) = $this->new_slot();
		$token               = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'menu_id'            => $menu_id,
				'resource_id'        => $staff_id,
				'is_staff_preferred' => true,
				'guests'             => 1,
				'slot'               => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
				'owner_user_id'      => get_current_user_id(),
			)
		);
		$this->tokens[] = $token;

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );
		if ( $as_manager ) {
			$request->set_param( 'customer_name', '代理予約のお客様' );
			$request->set_param( 'customer_phone', $phone );
		}

		$response = $this->build_controller( $staff_id, $start, $end )->create_booking( $request );

		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		wp_set_current_user( 0 );

		return $response;
	}

	/**
	 * ゲストの予約確定（確認リンクを開いた後の処理）を実行する。
	 *
	 * @param string $email そろえたメールアドレス。
	 * @param string $phone 電話番号。
	 * @return array|WP_Error 確定の結果。
	 */
	private function run_guest_booking( string $email, string $phone ) {
		$menu_id  = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
		$staff_id = $this->create_post( Resource_Post_Type::POST_TYPE );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		list( $start, $end ) = $this->new_slot();
		$draft               = array(
			'menu_id'            => $menu_id,
			'resource_id'        => $staff_id,
			'is_staff_preferred' => true,
			'guests'             => 1,
			'slot'               => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
			'agree_terms'        => true,
		);

		// 初期設定のキャンセルポリシー・利用規約への同意は、ゲストの確認リンクでも必要。
		$request = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings' );
		$request->set_param( 'agree_terms', true );
		$request->set_param( 'agree_cancellation_policy', true );
		$request->set_param( 'agree_terms_of_service', true );

		return $this->build_controller( $staff_id, $start, $end )->create_booking_from_draft(
			$draft,
			'',
			0,
			false,
			$request,
			array(
				'name'  => 'ゲスト 太郎',
				'email' => $email,
				'phone' => $phone,
			)
		);
	}

	/**
	 * 確定コントローラーを組み立てる（通知は抑止し、空き枠は渡した枠で固定する）。
	 *
	 * @param int    $staff_id スタッフ投稿 ID。
	 * @param string $start    枠の開始日時（ISO8601）。
	 * @param string $end      枠の終了日時（ISO8601）。
	 * @return Booking_Confirmation_Controller コントローラー。
	 */
	private function build_controller( int $staff_id, string $start, string $end ): Booking_Confirmation_Controller {
		return new Booking_Confirmation_Controller(
			new Customer_Limit_Notification_Test_Double(),
			new Settings_Repository(),
			new Customer_Limit_Availability_Test_Double(
				array(
					'slot_id'              => 'slot-1',
					'start_at'             => $start,
					'end_at'               => $end,
					'service_end_at'       => $end,
					'staff'                => array( 'id' => $staff_id ),
					'assignable_staff_ids' => array( $staff_id ),
					'auto_assign'          => false,
				)
			)
		);
	}

	/**
	 * これから予約する枠（既存の予約と時間が重ならない、30日先の10時から1時間）を返す。
	 *
	 * @return array{0:string,1:string} 開始日時と終了日時（ISO8601）。
	 */
	private function new_slot(): array {
		$start = strtotime( '+30 days 10:00:00 Asia/Tokyo' );

		return array(
			wp_date( 'Y-m-d\TH:i:sP', $start ),
			wp_date( 'Y-m-d\TH:i:sP', $start + HOUR_IN_SECONDS ),
		);
	}

	/**
	 * 予約投稿を作る。
	 *
	 * @param int                  $author_id 作成者のユーザー ID（0 はゲスト予約）。
	 * @param array<string, mixed> $args      days（今日から何日後に始まるか。負の値は過去）、status（予約の状態メタ。null は記録なし）、
	 *                                        post_status、email、phone、other_author、created_by_store、multi_night_guests。
	 * @return int 予約投稿 ID。
	 */
	private function create_booking_post( int $author_id, array $args = array() ): int {
		$args = array_merge(
			array(
				'days'        => 2,
				'status'      => 'confirmed',
				'post_status' => 'publish',
			),
			$args
		);

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => $args['post_status'],
				'post_author' => $author_id,
			)
		);

		$start = strtotime( sprintf( '%+d days 12:00:00 Asia/Tokyo', (int) $args['days'] ) );
		$end   = $start + HOUR_IN_SECONDS;
		if ( isset( $args['multi_night_guests'] ) ) {
			// 人数が多く3泊にまたがる予約も、予約投稿は1件。
			$end = $start + 3 * DAY_IN_SECONDS;
			update_post_meta( $booking_id, '_vkbm_booking_guests', (int) $args['multi_night_guests'] );
		}
		update_post_meta( $booking_id, '_vkbm_booking_service_start', wp_date( 'Y-m-d H:i:s', $start ) );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', $end ) );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', wp_date( 'Y-m-d H:i:s', $end ) );
		if ( null !== $args['status'] ) {
			update_post_meta( $booking_id, '_vkbm_booking_status', $args['status'] );
		}
		if ( isset( $args['email'] ) ) {
			update_post_meta( $booking_id, '_vkbm_booking_customer_email', $args['email'] );
		}
		if ( isset( $args['phone'] ) ) {
			update_post_meta( $booking_id, '_vkbm_booking_customer_tel', $args['phone'] );
		}

		return $booking_id;
	}

	/**
	 * 同じ予約者の予約を複数件作る（開始日はそれぞれずらす）。
	 *
	 * @param int         $count     件数。
	 * @param int         $author_id 作成者のユーザー ID（0 はゲスト予約）。
	 * @param string      $email     予約者のメールアドレス。
	 * @param int         $offset    開始日をずらす日数（同じ予約者に続けて作るとき、日付が重ならないようにする）。
	 * @param string|null $phone     予約者の電話番号。
	 */
	private function create_bookings( int $count, int $author_id, string $email, int $offset = 0, ?string $phone = null ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$args = array(
				'days'  => 2 + $offset + $i,
				'email' => $email,
			);
			if ( null !== $phone ) {
				$args['phone'] = $phone;
			}
			$this->create_booking_post( $author_id, $args );
		}
	}

	/**
	 * 指定した投稿タイプの投稿を作成する。
	 *
	 * @param string $post_type 投稿タイプ。
	 * @return int 投稿 ID。
	 */
	private function create_post( string $post_type ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 指定ユーザーが投稿者の予約数を数える。
	 *
	 * @param int $author_id ユーザー ID。
	 * @return int 予約数。
	 */
	private function count_bookings_of_author( int $author_id ): int {
		return count(
			get_posts(
				array(
					'post_type'      => Booking_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'author'         => $author_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * すべての予約投稿の ID を返す。
	 *
	 * @return array<int, int> 予約投稿 ID。
	 */
	private function get_all_booking_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Booking_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * すべての予約投稿の数を数える。
	 *
	 * @return int 予約数。
	 */
	private function count_all_bookings(): int {
		return count( $this->get_all_booking_ids() );
	}

	/**
	 * 残っているロック（options テーブルの行）の数を数える。
	 *
	 * @return int ロックの数。
	 */
	private function count_mutex_options(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::MUTEX_OPTION ) . '%'
			)
		);
	}
}

/**
 * 通知を抑止するテストダブル。
 */
class Customer_Limit_Notification_Test_Double extends Booking_Notification_Service {
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	public function handle_confirmed_creation( int $booking_id ): void {
		// テストでは何もしない。
	}

	public function handle_pending_creation( int $booking_id ): void {
		// テストでは何もしない。
	}
}

/**
 * get_daily_slots を固定スロットで返すテストダブル（空き再検証を通すため）。
 */
class Customer_Limit_Availability_Test_Double extends Availability_Service {

	/** @var array<string, mixed> */
	private array $slot;

	/**
	 * @param array<string, mixed> $slot 返却するスロット。
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	/**
	 * 受付下限の判定は空き枠の差し替え対象外のため、常に「下限内」とする。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param string $start_at 枠の開始日時。
	 * @return bool
	 */
	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $args 検索条件。
	 * @return array<string, mixed>
	 */
	public function get_daily_slots( array $args ) {
		return array( 'slots' => array( $this->slot ) );
	}
}
