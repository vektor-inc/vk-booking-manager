<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Anonymizer;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Bookings\Guest_Booking_Controller;
use VKBookingManager\Customers\Customer_Flag_Booking_Guard;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Tests\Customers\Customer_Flag_Options_Cleanup_Trait;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * ゲスト予約（会員登録なしの予約）の申し込み受付と、確認リンクを開いたときの処理のテスト。
 *
 * @group bookings
 * @group guest-booking
 */
class Guest_Booking_Controller_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	private const DRAFT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE = 'vkbm_draft_owner';
	private const START        = '2026-12-01T10:00:00+09:00';
	private const END          = '2026-12-01T10:30:00+09:00';

	/**
	 * 作った下書きのトークン（後片付け用）。
	 *
	 * @var array<int, string>
	 */
	private array $draft_tokens = array();

	/**
	 * テスト開始時の $_COOKIE と $_SERVER（復元用）。
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	/**
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 顧客フラグの予約ブロックを有効にするガード。
	 *
	 * @var Customer_Flag_Booking_Guard
	 */
	private $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup            = $_COOKIE;
		$this->server_backup            = $_SERVER;
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_guest_test';
		$this->delete_all_flag_options();
		$this->guard = new Customer_Flag_Booking_Guard( new Customer_Flag_Service() );
		$this->guard->register();
		Guest_Booking_Controller::reset_current_result();
		wp_set_current_user( 0 );
		// 指名機能を切る（時間枠の自動割当で作る）。
		$this->save_settings( array( 'staff_enabled' => false ) );
	}

	protected function tearDown(): void {
		remove_filter( 'vkbm_booking_block_check', array( $this->guard, 'block_flagged_customer' ), 10 );
		foreach ( $this->draft_tokens as $token ) {
			delete_transient( self::DRAFT_PREFIX . $token );
		}
		$this->draft_tokens = array();
		$_COOKIE            = $this->cookie_backup;
		$_SERVER            = $this->server_backup;
		$_GET               = array();
		wp_set_current_user( 0 );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		Industry_Presets::clear_cache();
		Guest_Booking_Controller::reset_current_result();
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
		Staff_Editor::clear_nomination_enabled_cache();
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'カット',
			)
		);
	}

	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 予約の下書きを保存して、トークンを返す。
	 *
	 * @param int                  $menu_id  メニューの投稿 ID。
	 * @param int                  $staff_id スタッフの投稿 ID。
	 * @param string               $start    開始日時（ISO8601）。
	 * @param string               $end      終了日時（ISO8601）。
	 * @param array<string, mixed> $extra    下書きに足す値（memo など）。
	 * @return string トークン。
	 */
	private function store_draft( int $menu_id, int $staff_id, string $start = self::START, string $end = self::END, array $extra = array() ): string {
		$token   = 'token' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array_merge(
			array(
				'menu_id'              => $menu_id,
				'resource_id'          => $staff_id,
				'guests'               => 1,
				'is_staff_preferred'   => false,
				'slot'                 => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'assignable_staff_ids' => array( $staff_id ),
				'meta'                 => array( 'timezone' => 'Asia/Tokyo' ),
				'agree_terms'          => true,
				'owner_key'            => 'owner_guest_test',
			),
			$extra
		);
		set_transient( self::DRAFT_PREFIX . $token, $payload );
		$this->draft_tokens[] = $token;

		return $token;
	}

	/**
	 * 申し込みの REST リクエストを作る。
	 *
	 * @param string               $draft_token 下書きのトークン。
	 * @param array<string, mixed> $overrides   上書きする値。値が null の項目は送らない。
	 */
	private function build_request( string $draft_token, array $overrides = array() ): WP_REST_Request {
		$params  = array_merge(
			array(
				'token'          => $draft_token,
				'customer_name'  => 'ゲスト 太郎',
				'customer_email' => 'Guest@Example.com',
				'customer_phone' => '０９０-1234-5678',
			),
			$overrides
		);
		$request = new WP_REST_Request( 'POST', '/vkbm/v1/guest-bookings' );
		foreach ( $params as $key => $value ) {
			if ( null !== $value ) {
				$request->set_param( $key, $value );
			}
		}

		return $request;
	}

	/**
	 * ゲスト予約のコントローラーを組み立てる。
	 *
	 * @param int  $staff_id  スタッフの投稿 ID。
	 * @param bool $available 空き枠があるか。
	 * @param bool $mail_ok   確認メールの送信が成功するか。
	 * @return array{0: Guest_Booking_Controller, 1: Guest_Notification_Recording_Double}
	 */
	private function build_controller( int $staff_id, bool $available = true, bool $mail_ok = true ): array {
		$notification = new Guest_Notification_Recording_Double( $mail_ok );
		$settings     = new Settings_Repository();
		$availability = new Guest_Availability_Test_Double(
			$available
				? array(
					'slot_id'              => 'slot-1',
					'start_at'             => self::START,
					'end_at'               => self::END,
					'service_end_at'       => self::END,
					'staff'                => array( 'id' => $staff_id ),
					'assignable_staff_ids' => array( $staff_id ),
				)
				: null
		);
		$confirmation = new Booking_Confirmation_Controller( $notification, $settings, $availability );

		return array( new Guest_Booking_Controller( $confirmation, $notification, $settings ), $notification );
	}

	/**
	 * 申し込みを送り、確認メールに入った生のトークンを返す。
	 *
	 * @param Guest_Booking_Controller            $controller   コントローラー。
	 * @param Guest_Notification_Recording_Double $notification 通知のダブル。
	 * @param WP_REST_Request                     $request      リクエスト。
	 * @return string 生のトークン。
	 */
	private function request_and_get_token( Guest_Booking_Controller $controller, Guest_Notification_Recording_Double $notification, WP_REST_Request $request ): string {
		$response = $controller->create_guest_booking_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$last = end( $notification->verifications );
		$this->assertIsArray( $last );
		$this->assertSame( 1, preg_match( '/vkbm_guest_verify=([A-Za-z0-9]{32})$/', (string) $last['verify_url'], $matches ), '確認リンクに 32 文字のトークンが入る' );

		return $matches[1];
	}

	private function count_bookings(): int {
		return count(
			get_posts(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * 同じメールアドレスの回数制限を外す（60 秒の間隔を待たずに続けて送るため）。
	 */
	private function reset_email_limit( string $email ): void {
		delete_transient( Guest_Booking_Controller::EMAIL_LIMIT_PREFIX . hash( 'sha256', strtolower( trim( $email ) ) ) );
	}

	/**
	 * 受付方法・ログイン状態・入力・顧客フラグ・重複・隠し入力欄による受付拒否を確かめる。
	 */
	public function test_create_guest_booking_request_rejections(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$flag_service = new Customer_Flag_Service();
		$flag_service->save_types(
			array(
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => 'ご予約をお受けできません。',
				),
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
			)
		);
		$blocked_member = $this->factory()->user->create( array( 'user_email' => 'blocked@example.com' ) );
		update_user_meta( $blocked_member, 'phone_number', '08011112222' );
		$flag_service->set_flag( $blocked_member, 'blacklist', '無断キャンセル' );
		$noted_member = $this->factory()->user->create( array( 'user_email' => 'noted@example.com' ) );
		$flag_service->set_flag( $noted_member, 'caution', '遅刻が多い' );

		// 同じメールアドレスで、時間が重なる会員の予約がすでにある。
		$booked_member = $this->factory()->user->create( array( 'user_email' => 'booked@example.com' ) );
		$booked_id     = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $booked_member,
			)
		);
		update_post_meta( $booked_id, '_vkbm_booking_service_start', '2026-12-01 09:45:00' );
		update_post_meta( $booked_id, '_vkbm_booking_service_end', '2026-12-01 10:15:00' );
		update_post_meta( $booked_id, '_vkbm_booking_total_end', '2026-12-01 10:15:00' );
		update_post_meta( $booked_id, '_vkbm_booking_customer_email', 'booked@example.com' );
		update_post_meta( $booked_id, '_vkbm_booking_status', 'confirmed' );

		$test_cases = array(
			array(
				'test_condition_name' => '受付方法が「会員のみ」（初期値）の場合 => 403 で受け付けない',
				'mode'                => 'member',
				'logged_in'           => false,
				'params'              => array(),
				'expected_code'       => 'guest_booking_disabled',
				'expected_status'     => 403,
			),
			array(
				'test_condition_name' => 'ログイン中の会員の場合 => ゲストとして受け付けない',
				'mode'                => 'both',
				'logged_in'           => true,
				'params'              => array(),
				'expected_code'       => 'guest_booking_logged_in',
				'expected_status'     => 403,
			),
			array(
				'test_condition_name' => '隠し入力欄に値がある場合 => ロボットとして受け付けない',
				'mode'                => 'guest',
				'logged_in'           => false,
				'params'              => array( 'vkbm_hp_email' => 'bot@example.com' ),
				'expected_code'       => 'guest_booking_honeypot',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => '名前が空の場合 => 必須エラー',
				'mode'                => 'guest',
				'logged_in'           => false,
				'params'              => array( 'customer_name' => '  ' ),
				'expected_code'       => 'guest_contact_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => 'メールアドレスを送らない場合 => 必須エラー',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_email' => null ),
				'expected_code'       => 'guest_contact_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => '電話番号に数字が無い場合 => 必須エラー',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_phone' => 'なし' ),
				'expected_code'       => 'guest_contact_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => 'メールアドレスの形が正しくない場合 => 形式エラー',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_email' => 'not-an-email' ),
				'expected_code'       => 'guest_contact_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => '下書きのトークンを送らない場合 => 400',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'token' => null ),
				'expected_code'       => 'missing_token',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => '止める種類のフラグが付いた会員と同じメールアドレスの場合 => 申し込みの時点では断らない（他人のメールで照合結果を探れないように。リンクを開いた時点で止める）',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_email' => '  Blocked@Example.com ' ),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '止める種類のフラグが付いた会員と同じ電話番号の場合 => 申し込みの時点では断らない',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_phone' => '０８０-１１１１-２２２２' ),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '止めない種類のフラグが付いた会員と同じメールアドレスの場合 => 受け付ける',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_email' => 'noted@example.com' ),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '会員が同じメールアドレスで持つ予約と時間が重なる場合 => 申し込みの時点では断らない（予約の有無を探れないように。リンクを開いた時点で止める）',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array( 'customer_email' => 'Booked@example.com' ),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => 'キャンセルポリシーが設定されていて同意が無い場合 => 申し込みの時点で断る',
				'mode'                => 'both',
				'logged_in'           => false,
				'settings'            => array( 'provider_cancellation_policy' => '前日までにご連絡ください。' ),
				'params'              => array( 'agree_terms' => false ),
				'expected_code'       => 'cancellation_policy_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => '利用規約が設定されていて同意が無い場合 => 申し込みの時点で断る',
				'mode'                => 'both',
				'logged_in'           => false,
				'settings'            => array( 'provider_terms_of_service' => '利用規約です。' ),
				'params'              => array( 'agree_terms' => false ),
				'expected_code'       => 'terms_required',
				'expected_status'     => 400,
			),
			array(
				'test_condition_name' => 'キャンセルポリシーが設定されていて同意がある場合 => 受け付ける',
				'mode'                => 'both',
				'logged_in'           => false,
				'settings'            => array( 'provider_cancellation_policy' => '前日までにご連絡ください。' ),
				'params'              => array( 'agree_terms' => true ),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '受付方法が「ゲストのみ」で入力が正しい場合 => 受け付ける',
				'mode'                => 'guest',
				'logged_in'           => false,
				'params'              => array(),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '受付方法が「どちらでも」で入力が正しい場合 => 受け付ける',
				'mode'                => 'both',
				'logged_in'           => false,
				'params'              => array(),
				'expected_code'       => '',
				'expected_status'     => 200,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings(
				array_merge(
					array(
						'booking_customer_mode'        => $case['mode'],
						'provider_cancellation_policy' => '',
						'provider_terms_of_service'    => '',
					),
					$case['settings'] ?? array()
				)
			);
			wp_set_current_user( $case['logged_in'] ? $this->factory()->user->create() : 0 );
			$draft_token = $this->store_draft( $menu_id, $staff_id );
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$before = $this->count_bookings();

			$response = $controller->create_guest_booking_request( $this->build_request( $draft_token, $case['params'] ) );

			if ( 200 === $case['expected_status'] ) {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$this->assertCount( 1, $notification->verifications, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_code'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( $case['expected_status'], $response->get_error_data()['status'], $case['test_condition_name'] );
				$this->assertCount( 0, $notification->verifications, $case['test_condition_name'] . '（確認メールを送らない）' );
			}
			// どの場合も、予約データはまだ作らない。
			$this->assertSame( $before, $this->count_bookings(), $case['test_condition_name'] . '（予約データを作らない）' );
			wp_set_current_user( 0 );
			$this->reset_email_limit( 'guest@example.com' );
			$this->reset_email_limit( 'noted@example.com' );
		}
	}

	/**
	 * 受付方法が「会員のみ」（初期値）の間は、登録済みの REST ルートが申し込みを受け付けない。
	 */
	public function test_rest_route_rejects_when_member_only(): void {
		$menu_id     = $this->create_menu();
		$staff_id    = $this->create_staff();
		$draft_token = $this->store_draft( $menu_id, $staff_id );
		$this->save_settings( array( 'booking_customer_mode' => 'member' ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ルートの登録を確かめるため差し替える。
		do_action( 'rest_api_init' );
		$routes = $wp_rest_server->get_routes();
		$this->assertArrayHasKey( '/vkbm/v1/guest-bookings', $routes, 'ルートが登録されている' );

		$response = rest_do_request( $this->build_request( $draft_token ) );
		$this->assertSame( 403, $response->get_status(), '会員のみの間は受け付けない' );
		$this->assertSame( 'guest_booking_disabled', $response->get_data()['code'] );
		$this->assertSame( 0, $this->count_bookings() );
	}

	/**
	 * IP ごとの回数制限は、会員登録と同じ設定値と有効化設定に従う。
	 */
	public function test_create_guest_booking_request_ip_rate_limit(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		// テストの bootstrap は回数制限を無効にしているため、この検証の間だけ有効に戻す。
		remove_all_filters( 'vkbm_rate_limit_enabled' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_true' );

		$test_cases = array(
			array(
				'test_condition_name' => '回数制限が有効で上限が 2 の場合 => 3 回目から 429',
				'enabled'             => true,
				'max'                 => 2,
				'expected_statuses'   => array( 400, 400, 429 ),
			),
			array(
				'test_condition_name' => '回数制限が無効の場合 => 上限を超えても 429 にならない',
				'enabled'             => false,
				'max'                 => 2,
				'expected_statuses'   => array( 400, 400, 400 ),
			),
			array(
				'test_condition_name' => '回数制限が有効で上限が 3 の場合 => 3 回目までは受け付け、4 回目が 429',
				'enabled'             => true,
				'max'                 => 3,
				'expected_statuses'   => array( 400, 400, 400, 429 ),
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_settings(
				array(
					'booking_customer_mode'        => 'guest',
					'auth_rate_limit_enabled'      => $case['enabled'],
					'auth_rate_limit_register_max' => $case['max'],
				)
			);
			// 案件ごとに別の IP からの送信として数える。
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $index );
			list( $controller )     = $this->build_controller( $staff_id );
			$draft_token            = $this->store_draft( $menu_id, $staff_id );

			$statuses = array();
			foreach ( $case['expected_statuses'] as $unused ) {
				// 名前を空にして、回数制限より後の検証で止まる（400）ようにする。
				$response   = $controller->create_guest_booking_request( $this->build_request( $draft_token, array( 'customer_name' => '' ) ) );
				$statuses[] = $response->get_error_data()['status'];
			}
			$this->assertSame( $case['expected_statuses'], $statuses, $case['test_condition_name'] );
		}
		remove_all_filters( 'vkbm_rate_limit_enabled' );
		add_filter( 'vkbm_rate_limit_enabled', '__return_false' );
	}

	/**
	 * 同じメールアドレスへの送信は 60 秒に 1 回・24 時間に 5 回まで。
	 */
	public function test_create_guest_booking_request_email_limit(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );
		list( $controller ) = $this->build_controller( $staff_id );
		$email_key          = Guest_Booking_Controller::EMAIL_LIMIT_PREFIX . hash( 'sha256', 'guest@example.com' );

		$test_cases = array(
			array(
				'test_condition_name' => '初めての送信 => 受け付ける',
				'state'               => null,
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '30 秒前に送っている場合 => 60 秒の間隔に満たないので 429',
				'state'               => array(
					'window_start' => time() - 30,
					'count'        => 1,
					'last'         => time() - 30,
				),
				'expected_status'     => 429,
			),
			array(
				'test_condition_name' => '61 秒前に送っている場合 => 受け付ける',
				'state'               => array(
					'window_start' => time() - 61,
					'count'        => 1,
					'last'         => time() - 61,
				),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '24 時間以内にすでに 5 回送っている場合 => 429',
				'state'               => array(
					'window_start' => time() - 3600,
					'count'        => 5,
					'last'         => time() - 600,
				),
				'expected_status'     => 429,
			),
			array(
				'test_condition_name' => '24 時間以内に 4 回送っている場合 => 5 回目として受け付ける',
				'state'               => array(
					'window_start' => time() - 3600,
					'count'        => 4,
					'last'         => time() - 600,
				),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '24 時間より前に 5 回送っていた場合 => 数え直して受け付ける',
				'state'               => array(
					'window_start' => time() - DAY_IN_SECONDS - 10,
					'count'        => 5,
					'last'         => time() - DAY_IN_SECONDS - 10,
				),
				'expected_status'     => 200,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_transient( $email_key );
			if ( null !== $case['state'] ) {
				set_transient( $email_key, $case['state'], DAY_IN_SECONDS );
			}
			$draft_token = $this->store_draft( $menu_id, $staff_id );
			$response    = $controller->create_guest_booking_request( $this->build_request( $draft_token ) );

			$status = $response instanceof WP_Error ? $response->get_error_data()['status'] : 200;
			$this->assertSame( $case['expected_status'], $status, $case['test_condition_name'] );
		}
	}

	/**
	 * 差し込み口 vkbm_guest_booking_request_check で、別のプラグインが申し込みを止められる。
	 */
	public function test_create_guest_booking_request_check_filter(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'フィルターが WP_Error を返す場合 => そのエラーで止める',
				'result'              => new WP_Error( 'captcha_failed', 'NG', array( 'status' => 400 ) ),
				'expected_code'       => 'captcha_failed',
			),
			array(
				'test_condition_name' => 'フィルターが true を返す場合 => 受け付ける',
				'result'              => true,
				'expected_code'       => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$result_to_return = $case['result'];
			$filter           = static function () use ( $result_to_return ) {
				return $result_to_return;
			};
			add_filter( 'vkbm_guest_booking_request_check', $filter );
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$response                          = $controller->create_guest_booking_request( $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
			remove_filter( 'vkbm_guest_booking_request_check', $filter );

			if ( '' === $case['expected_code'] ) {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_code'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertCount( 0, $notification->verifications, $case['test_condition_name'] );
			}
			$this->reset_email_limit( 'guest@example.com' );
		}
	}

	/**
	 * 受け付けると確認待ちデータを一時保存に置く。保存するのはトークンのハッシュだけで、予約枠は押さえない。
	 */
	public function test_create_guest_booking_request_stores_pending_data_by_hash(): void {
		global $wpdb;

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'both' ) );
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$draft_token = $this->store_draft( $menu_id, $staff_id, self::START, self::END, array( 'memo' => '窓際の席を希望' ) );

		$response = $controller->create_guest_booking_request( $this->build_request( $draft_token ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'verification_sent', $data['status'] );
		$this->assertSame( 'guest@example.com', $data['email'], 'メールアドレスはそろえた値' );
		$this->assertSame( 3600, $data['expires_in'], '有効期限は 60 分' );

		$raw_token = $this->request_token_from_notification( $notification );
		$this->assertSame( 32, strlen( $raw_token ) );
		$hash = hash( 'sha256', $raw_token );

		$pending = get_transient( Guest_Booking_Controller::PENDING_PREFIX . $hash );
		$this->assertIsArray( $pending, 'ハッシュの名前で確認待ちデータがある' );
		$this->assertSame( 'guest@example.com', $pending['guest']['email'] );
		$this->assertSame( '09012345678', $pending['guest']['phone'], '電話番号は全角を半角にして数字だけ' );
		$this->assertSame( '窓際の席を希望', $pending['form']['memo'], '備考は予約を作るときのために保存する' );
		$this->assertSame( $menu_id, (int) $pending['draft']['menu_id'] );
		$this->assertFalse( get_transient( Guest_Booking_Controller::PENDING_PREFIX . $raw_token ), '生のトークンの名前では保存しない' );

		// 生のトークンはどのオプション（transient を含む）にも保存しない。
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", '%' . $wpdb->esc_like( $raw_token ) . '%', '%' . $wpdb->esc_like( $raw_token ) . '%' ) );
		$this->assertSame( 0, $count, '生のトークンは保存しない' );

		$this->assertSame( 0, $this->count_bookings(), '確認リンクを開くまで予約データを作らない' );
		$this->assertCount( 0, get_posts( array( 'post_type' => Booking_Post_Type::POST_TYPE, 'post_status' => 'any' ) ) );
	}

	/**
	 * 確認メールの本文は備考を載せず、リンク・有効期限を載せる。件名は仕様どおり。
	 */
	public function test_send_guest_verification(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_base_price', 3000 );

		$test_cases = array(
			array(
				'test_condition_name' => '仮予約モードの場合 => 仮予約になる旨を入れる',
				'status_mode'         => 'pending',
				'expect_pending_text' => true,
			),
			array(
				'test_condition_name' => '即時確定モードの場合 => 仮予約になる旨を入れない',
				'status_mode'         => 'confirmed',
				'expect_pending_text' => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings(
				array(
					'booking_customer_mode'        => 'guest',
					'provider_booking_status_mode' => $case['status_mode'],
					'provider_name'                => 'テスト店',
				)
			);
			$mails    = array();
			$capture  = static function ( $pre, $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return true;
			};
			add_filter( 'pre_wp_mail', $capture, 10, 2 );
			$notification = new Booking_Notification_Service( new Settings_Repository() );
			$sent         = $notification->send_guest_verification(
				array(
					'name'          => 'ゲスト 太郎',
					'email'         => 'guest@example.com',
					'verify_url'    => 'https://example.com/reserve/?vkbm_guest_verify=ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
					'expires_at'    => time() + 3600,
					'draft'         => array(
						'menu_id'     => $menu_id,
						'resource_id' => $staff_id,
						'guests'      => 1,
						'memo'        => 'SECRET_MEMO_TEXT',
						'slot'        => array(
							'start_at' => self::START,
							'end_at'   => self::END,
						),
					),
					'attendee_name' => '',
					'is_pending'    => 'pending' === $case['status_mode'],
				)
			);
			remove_filter( 'pre_wp_mail', $capture, 10 );

			$this->assertTrue( $sent, $case['test_condition_name'] );
			$this->assertCount( 1, $mails, $case['test_condition_name'] );
			$this->assertSame( 'guest@example.com', $mails[0]['to'], $case['test_condition_name'] );
			$this->assertStringContainsString( 'テスト店', $mails[0]['subject'], $case['test_condition_name'] );
			$body = (string) $mails[0]['message'];
			$this->assertStringContainsString( 'vkbm_guest_verify=ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', $body, $case['test_condition_name'] . '（リンク）' );
			$this->assertStringContainsString( 'ゲスト 太郎', $body, $case['test_condition_name'] . '（お名前）' );
			$this->assertStringContainsString( 'カット', $body, $case['test_condition_name'] . '（予約内容）' );
			$this->assertStringNotContainsString( 'SECRET_MEMO_TEXT', $body, $case['test_condition_name'] . '（備考は載せない）' );
			$this->assertStringNotContainsString( '#0', $body, $case['test_condition_name'] . '（予約番号は載せない）' );
			$this->assertStringContainsString( __( 'If the link has expired, please make the reservation again from the reservation page.', 'vk-booking-manager' ), $body, $case['test_condition_name'] . '（期限切れ後の案内）' );
			$this->assertStringContainsString( __( 'The price shown is a guide. The amount in the email sent after confirmation is the final amount.', 'vk-booking-manager' ), $body, $case['test_condition_name'] . '（料金は目安）' );
			$notice_pos    = strpos( $body, __( 'If you do not recognize this email, please discard it without opening the link. No reservation will be created.', 'vk-booking-manager' ) );
			$signature_pos = strpos( $body, '===========' );
			$info_pos      = strpos( $body, __( '--- Reservation information ---', 'vk-booking-manager' ) );
			$this->assertTrue( false !== $notice_pos && $info_pos < $notice_pos && $notice_pos < $signature_pos, $case['test_condition_name'] . '（心当たりの案内は予約内容の後・署名の前）' );
			$this->assertSame( $case['expect_pending_text'], false !== strpos( $body, __( 'After you open the link, your reservation will be a provisional reservation. The administrator will review and confirm it.', 'vk-booking-manager' ) ), $case['test_condition_name'] );
		}

		// メールアドレスが正しくなければ送らない。
		$notification = new Booking_Notification_Service( new Settings_Repository() );
		$this->assertFalse( $notification->send_guest_verification( array( 'email' => 'bad' ) ), '宛先が正しくない場合は送らない' );
	}

	/**
	 * 送信に失敗したときは確認待ちデータを残さず、500 を返す。
	 */
	public function test_create_guest_booking_request_mail_failure(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );
		list( $controller, $notification ) = $this->build_controller( $staff_id, true, false );

		$response = $controller->create_guest_booking_request( $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'guest_verification_mail_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$raw = $this->request_token_from_notification( $notification );
		$this->assertFalse( get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw ) ), '届かないリンクの確認待ちデータを残さない' );
	}

	/**
	 * 送信に失敗したときは、今回数えた宛先とメール送信回数を戻す（すでに記録済みの宛先は外さない）。
	 */
	public function test_release_send_slot(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );

		// steps の各要素: 宛先、メール送信の成否、期待するステータス、期待する送信回数、実行前に回数の記録を消すか。
		$test_cases = array(
			array(
				'test_condition_name' => '送信に失敗した直後に同じ宛先で送り直す場合 => 回数が戻っているので 60 秒の間隔に引っかからず送れる',
				'max'                 => null,
				'steps'               => array(
					array( 'rel1@example.com', false, 500, 0, false ),
					array( 'rel1@example.com', true, 200, 1, false ),
				),
			),
			array(
				'test_condition_name' => '上限 1 件で最初の宛先の送信に失敗した場合 => 宛先の枠が戻っているので別の宛先に送れる',
				'max'                 => 1,
				'steps'               => array(
					array( 'rel2a@example.com', false, 500, 0, false ),
					array( 'rel2b@example.com', true, 200, 1, false ),
				),
			),
			array(
				'test_condition_name' => '上限 1 件ですでに送った宛先への送り直しが失敗した場合 => 宛先は外さないので別の宛先は 429',
				'max'                 => 1,
				'steps'               => array(
					array( 'rel3a@example.com', true, 200, 1, false ),
					array( 'rel3a@example.com', false, 500, 0, true ),
					array( 'rel3b@example.com', true, 429, 0, false ),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$max    = $case['max'];
			$filter = static function ( $default ) use ( $max ) {
				return null === $max ? $default : $max;
			};
			add_filter( 'vkbm_guest_booking_max_recipients_per_draft', $filter );
			$draft_token = $this->store_draft( $menu_id, $staff_id );

			foreach ( $case['steps'] as $step ) {
				list( $email, $mail_ok, $expected_status, $expected_count, $reset_before ) = $step;
				if ( $reset_before ) {
					$this->reset_email_limit( $email );
				}
				list( $controller ) = $this->build_controller( $staff_id, true, $mail_ok );
				$response           = $controller->create_guest_booking_request( $this->build_request( $draft_token, array( 'customer_email' => $email ) ) );
				$actual_status      = $response instanceof WP_Error ? $response->get_error_data()['status'] : 200;
				$state              = get_transient( Guest_Booking_Controller::EMAIL_LIMIT_PREFIX . hash( 'sha256', $email ) );

				$this->assertSame( $expected_status, $actual_status, $case['test_condition_name'] . '（' . $email . '）' );
				$this->assertSame( $expected_count, is_array( $state ) ? (int) $state['count'] : 0, $case['test_condition_name'] . '（' . $email . ' の送信回数）' );
			}
			remove_filter( 'vkbm_guest_booking_max_recipients_per_draft', $filter );
		}
	}

	/**
	 * 送信に失敗しても、その間に同じメールアドレスの回数の記録が別の申し込みで書き換わっていたら、回数・時刻・宛先を戻さない。
	 */
	public function test_release_send_slot_keeps_concurrent_success(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '送信の間に別の申し込みが成功して、送信時刻が書き換わった場合 => 戻さない',
				'email'               => 'conc1@example.com',
				'window_shift'        => 0,
				'last_shift'          => 5,
			),
			array(
				'test_condition_name' => '送信の間に 24 時間の期間が切り替わって、期間の開始時刻が書き換わった場合 => 戻さない',
				'email'               => 'conc2@example.com',
				'window_shift'        => 7,
				'last_shift'          => 0,
			),
		);

		$max_filter = static function () {
			return 1;
		};
		add_filter( 'vkbm_guest_booking_max_recipients_per_draft', $max_filter );

		foreach ( $test_cases as $case ) {
			$email                             = $case['email'];
			$state_key                         = Guest_Booking_Controller::EMAIL_LIMIT_PREFIX . hash( 'sha256', $email );
			$expected                          = array();
			list( $controller, $notification ) = $this->build_controller( $staff_id, true, false );
			$notification->on_send             = function () use ( $state_key, &$expected, $case ) {
				$state    = get_transient( $state_key );
				$expected = array(
					'window_start' => (int) $state['window_start'] + $case['window_shift'],
					'count'        => (int) $state['count'] + 1,
					'last'         => (int) $state['last'] + $case['last_shift'],
				);
				set_transient( $state_key, $expected, DAY_IN_SECONDS );
			};
			$draft_token                       = $this->store_draft( $menu_id, $staff_id );

			$response = $controller->create_guest_booking_request( $this->build_request( $draft_token, array( 'customer_email' => $email ) ) );

			$this->assertSame( 500, $response->get_error_data()['status'], $case['test_condition_name'] );
			$this->assertSame( $expected, get_transient( $state_key ), $case['test_condition_name'] . '（回数・時刻がそのまま）' );

			// 宛先もそのまま残っている（上限 1 件なので、別の宛先は断られる）。
			list( $other_controller ) = $this->build_controller( $staff_id );
			$other                    = $other_controller->create_guest_booking_request( $this->build_request( $draft_token, array( 'customer_email' => 'other-' . $email ) ) );
			$this->assertSame( 'guest_booking_recipient_limit', $other->get_error_code(), $case['test_condition_name'] . '（宛先を外さない）' );
		}
		remove_filter( 'vkbm_guest_booking_max_recipients_per_draft', $max_filter );
	}

	/**
	 * 同じメールアドレス・同じ枠への送り直しは前の確認待ちデータを置き換え、別の枠は置き換えない。
	 */
	public function test_create_guest_booking_request_replaces_pending_for_same_slot(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );
		list( $controller, $notification ) = $this->build_controller( $staff_id );

		$first = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		$this->reset_email_limit( 'guest@example.com' );
		$second = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		$this->reset_email_limit( 'guest@example.com' );
		$other_slot = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id, '2026-12-02T10:00:00+09:00', '2026-12-02T10:30:00+09:00' ) ) );

		$pending = static function ( string $raw ) {
			return get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw ) );
		};
		$this->assertFalse( $pending( $first ), '同じ枠の前の確認待ちデータは置き換えられる' );
		$this->assertIsArray( $pending( $second ), '新しい確認待ちデータが残る' );
		$this->assertIsArray( $pending( $other_slot ), '別の枠の確認待ちデータは残る' );
		$this->assertSame( 'expired', $controller->process_verification( $first )['result'], '置き換えられた前のリンクは期限切れ扱い' );
	}

	/**
	 * 有効期限は開発者向けフィルターで差し替えられる（60 秒未満は 60 秒）。
	 */
	public function test_get_verification_ttl(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既定 => 3600 秒（60 分）',
				'filter'              => null,
				'expected'            => 3600,
			),
			array(
				'test_condition_name' => 'フィルターで 1800 を返す => 1800 秒',
				'filter'              => 1800,
				'expected'            => 1800,
			),
			array(
				'test_condition_name' => 'フィルターで 10 を返す => 下限の 60 秒',
				'filter'              => 10,
				'expected'            => 60,
			),
		);

		foreach ( $test_cases as $case ) {
			$value  = $case['filter'];
			$filter = static function ( $ttl ) use ( $value ) {
				return $value;
			};
			if ( null !== $value ) {
				add_filter( 'vkbm_guest_booking_verification_ttl', $filter );
			}
			$this->assertSame( $case['expected'], Guest_Booking_Controller::get_verification_ttl(), $case['test_condition_name'] );
			remove_filter( 'vkbm_guest_booking_verification_ttl', $filter );
		}
	}

	/**
	 * 確認リンクを開くと、予約を作る（確定／仮予約）。予約したユーザーは無く、ゲスト予約の印と確認日時を保存する。
	 */
	public function test_process_verification_creates_booking(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$test_cases = array(
			array(
				'test_condition_name' => '即時確定モード => 確定の予約が作られ、お客様と店舗へのお知らせ（確定）を送る',
				'status_mode'         => 'confirmed',
				'expected_result'     => 'confirmed',
				'expected_status'     => 'confirmed',
				'expected_mail'       => 'confirmed',
			),
			array(
				'test_condition_name' => '仮予約モード => 仮予約の予約が作られ、お知らせ（仮予約）を送る',
				'status_mode'         => 'pending',
				'expected_result'     => 'pending',
				'expected_status'     => 'pending',
				'expected_mail'       => 'pending',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_settings(
				array(
					'booking_customer_mode'        => 'both',
					'provider_booking_status_mode' => $case['status_mode'],
				)
			);
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$draft_token                       = $this->store_draft( $menu_id, $staff_id, self::START, self::END, array( 'memo' => '備考です' ) );
			$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $draft_token, array( 'customer_email' => 'guest' . $index . '@example.com' ) ) );
			$this->assertSame( 0, $this->count_bookings(), $case['test_condition_name'] . '（リンクを開くまで予約は無い）' );

			$payment_calls = array();
			$payment_check = static function ( $result, $request, $draft, $price, $context ) use ( &$payment_calls ) {
				$payment_calls[] = $context;
				return $result;
			};
			$created_calls = array();
			$post_created  = static function ( $booking_id, $draft, $request, $context ) use ( &$created_calls ) {
				$created_calls[] = array( $booking_id, $context );
			};
			add_filter( 'vkbm_booking_payment_check', $payment_check, 10, 5 );
			add_action( 'vkbm_booking_post_created', $post_created, 10, 4 );
			$result = $controller->process_verification( $raw_token );
			remove_filter( 'vkbm_booking_payment_check', $payment_check, 10 );
			remove_action( 'vkbm_booking_post_created', $post_created, 10 );

			$this->assertSame( $case['expected_result'], $result['result'], $case['test_condition_name'] . ' code=' . $result['code'] . ' ' . $result['message'] );
			$this->assertFalse( $result['repeat'], $case['test_condition_name'] );
			$booking_id = $result['booking_id'];
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$post = get_post( $booking_id );
			$this->assertSame( 0, (int) $post->post_author, $case['test_condition_name'] . '（予約したユーザーは無し）' );
			$this->assertSame( 'guest', get_post_meta( $booking_id, '_vkbm_booking_source', true ), $case['test_condition_name'] . '（ゲスト予約の印）' );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) get_post_meta( $booking_id, '_vkbm_booking_guest_verified_at', true ), $case['test_condition_name'] . '（確認日時）' );
			$this->assertSame( $case['expected_status'], get_post_meta( $booking_id, '_vkbm_booking_status', true ), $case['test_condition_name'] );
			$this->assertSame( 'ゲスト 太郎', get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ), $case['test_condition_name'] );
			$this->assertSame( 'guest' . $index . '@example.com', get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ), $case['test_condition_name'] . '（メールはそろえた値）' );
			$this->assertSame( '09012345678', get_post_meta( $booking_id, '_vkbm_booking_customer_tel', true ), $case['test_condition_name'] );
			$this->assertSame( '備考です', get_post_meta( $booking_id, '_vkbm_booking_note', true ), $case['test_condition_name'] );
			$this->assertSame( array( $case['expected_mail'] ), $notification->creation_calls, $case['test_condition_name'] );

			// キャンセル用リンクのトークン: 予約にはハッシュだけを保存し、生の値は作成直後のお知らせ（メールの URL）にだけ渡す。
			$cancel_hashes = get_post_meta( $booking_id, '_vkbm_booking_guest_cancel_token_hash', false );
			$this->assertCount( 1, $cancel_hashes, $case['test_condition_name'] . '（キャンセル用トークンのハッシュが 1 件）' );
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $cancel_hashes[0], $case['test_condition_name'] . '（SHA-256）' );
			$this->assertCount( 1, $notification->guest_cancel_tokens, $case['test_condition_name'] );
			$this->assertSame( 1, preg_match( '/^[A-Za-z0-9]{32}$/', $notification->guest_cancel_tokens[0] ), $case['test_condition_name'] . '（生のトークンは英数字 32 文字）' );
			$this->assertSame( hash( 'sha256', $notification->guest_cancel_tokens[0] ), $cancel_hashes[0], $case['test_condition_name'] . '（ハッシュは生のトークンの SHA-256）' );
			$this->assertNotSame( $notification->guest_cancel_tokens[0], $cancel_hashes[0], $case['test_condition_name'] . '（生の値は保存しない）' );

			// 決済の確認と予約作成後の通知に、ゲスト予約かどうかの情報を添える。
			$this->assertCount( 1, $payment_calls, $case['test_condition_name'] );
			$this->assertTrue( $payment_calls[0]['is_guest'], $case['test_condition_name'] );
			$this->assertSame( 'guest' . $index . '@example.com', $payment_calls[0]['guest']['email'], $case['test_condition_name'] );
			$this->assertCount( 1, $created_calls, $case['test_condition_name'] );
			$this->assertSame( $booking_id, $created_calls[0][0], $case['test_condition_name'] );
			$this->assertTrue( $created_calls[0][1]['is_guest'], $case['test_condition_name'] );

			// 使ったら確認待ちデータを消す。
			$this->assertFalse( get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw_token ) ), $case['test_condition_name'] . '（使ったら消す）' );
			// 元の下書きも消える。
			$this->assertFalse( get_transient( self::DRAFT_PREFIX . $draft_token ), $case['test_condition_name'] . '（下書きも消える）' );
			$this->reset_email_limit( 'guest' . $index . '@example.com' );
			// 次の案件が同じ枠で満席にならないよう、作った予約を片付ける。
			wp_delete_post( $booking_id, true );
		}
	}

	/**
	 * ログイン中の人が確認リンクを開いても、予約の投稿者は 0（予約したユーザー無し）のままになる。
	 */
	public function test_process_verification_keeps_guest_author_when_opened_while_logged_in(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $controller->process_verification( $raw_token );

		$this->assertSame( 'confirmed', $result['result'] );
		$this->assertSame( 0, (int) get_post( $result['booking_id'] )->post_author );
	}

	/**
	 * 同じリンクを 2 回開いても、エラーにせず、同じ予約の今の状態を返す。予約は 1 件しか作らない。
	 */
	public function test_process_verification_repeat(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'pending',
			)
		);
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );

		$first = $controller->process_verification( $raw_token );
		$this->assertSame( 'pending', $first['result'] );
		$this->assertFalse( $first['repeat'] );

		$second = $controller->process_verification( $raw_token );
		$this->assertSame( 'pending', $second['result'], '2 回目はその予約の今の状態（仮予約）' );
		$this->assertTrue( $second['repeat'] );
		$this->assertSame( $first['booking_id'], $second['booking_id'], '同じ予約' );
		$this->assertSame( 1, $this->count_bookings(), '予約は 1 件だけ' );
		$this->assertSame( array( 'pending' ), $notification->creation_calls, 'お知らせメールも 1 回だけ' );

		// 店側が確定にすると、開き直したときの表示も変わる。
		update_post_meta( $first['booking_id'], '_vkbm_booking_status', 'confirmed' );
		$this->assertSame( 'confirmed', $controller->process_verification( $raw_token )['result'], '確定に変わった状態を返す' );

		// キャンセルされた後は、確定でも仮予約でもない状態として返す。
		update_post_meta( $first['booking_id'], '_vkbm_booking_status', 'cancelled' );
		$closed = $controller->process_verification( $raw_token );
		$this->assertSame( 'booking_closed', $closed['result'] );
		$this->assertSame( 'cancelled', $closed['booking_status'] );
	}

	/**
	 * 期限切れ・存在しないトークン・形の正しくないトークンは、予約を作らず結果だけ返す。
	 */
	public function test_process_verification_invalid_tokens(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$expired_token                     = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		// 有効期限が切れた状態を再現する（transient が消えている）。
		delete_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $expired_token ) );

		$test_cases = array(
			array(
				'test_condition_name' => '有効期限が切れたトークン => expired',
				'token'               => $expired_token,
				'expected'            => 'expired',
			),
			array(
				'test_condition_name' => '存在しないトークン => expired',
				'token'               => 'abcdefghijklmnopqrstuvwxyz012345',
				'expected'            => 'expired',
			),
			array(
				'test_condition_name' => '短すぎるトークン => invalid',
				'token'               => 'short',
				'expected'            => 'invalid',
			),
			array(
				'test_condition_name' => '記号を含むトークン => invalid',
				'token'               => "abcdefghijklmnopqrstuvwxyz01234'",
				'expected'            => 'invalid',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $controller->process_verification( $case['token'] );
			$this->assertSame( $case['expected'], $result['result'], $case['test_condition_name'] );
			$this->assertSame( 0, $result['booking_id'], $case['test_condition_name'] );
		}
		$this->assertSame( 0, $this->count_bookings() );
	}

	/**
	 * リンクを開いた時点で枠が埋まっていた場合は予約を作らない。同じリンクを開き直しても同じ結果になる。
	 */
	public function test_process_verification_slot_unavailable(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );
		list( $request_controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                                 = $this->request_and_get_token( $request_controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );

		// リンクを開く時点では、空き枠が無い（別の人が先に取った）。
		list( $controller ) = $this->build_controller( $staff_id, false );
		$result             = $controller->process_verification( $raw_token );

		$this->assertSame( 'slot_unavailable', $result['result'] );
		$this->assertSame( 'slot_unavailable', $result['code'] );
		$this->assertSame( 0, $this->count_bookings() );
		$this->assertFalse( get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw_token ) ), '使ったら確認待ちデータを消す' );

		$again = $controller->process_verification( $raw_token );
		$this->assertSame( 'slot_unavailable', $again['result'], '開き直しても「枠が埋まっていた」を返す' );
		$this->assertTrue( $again['repeat'] );
	}

	/**
	 * 予約を作れなかった理由が一時的なら、確認待ちデータを残し結果も保存せず、開き直せばやり直す。やり直しても変わらない理由なら、結果を保存する。
	 */
	public function test_process_verification_temporary_failure(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '決済の確認が 500 を返した場合 => 一時的。processing を返し、開き直すと予約できる',
				'failure'             => array( 'payment_gateway_error', 500 ),
				'expected_first'      => 'processing',
				'expected_retry'      => true,
				'expected_after'      => 'confirmed',
			),
			array(
				'test_condition_name' => '決済の確認が 503 を返した場合 => 一時的',
				'failure'             => array( 'payment_gateway_unavailable', 503 ),
				'expected_first'      => 'processing',
				'expected_retry'      => true,
				'expected_after'      => 'confirmed',
			),
			array(
				'test_condition_name' => '決済の確認がステータスの無いエラーを返した場合（種類が分からない） => 一時側に倒す',
				'failure'             => array( 'unknown_error', null ),
				'expected_first'      => 'processing',
				'expected_retry'      => true,
				'expected_after'      => 'confirmed',
			),
			array(
				'test_condition_name' => '予約枠のロックが取れなかった場合（booking_busy、409） => 枠が埋まった扱いにせず一時的',
				'failure'             => array( 'booking_busy', 409 ),
				'expected_first'      => 'processing',
				'expected_retry'      => true,
				'expected_after'      => 'confirmed',
			),
			array(
				'test_condition_name' => '予約の投稿を作れなかった場合（booking_creation_failed、500） => 一時的',
				'failure'             => array( 'empty_post', null ),
				'expected_first'      => 'processing',
				'expected_retry'      => true,
				'expected_after'      => 'confirmed',
			),
			array(
				'test_condition_name' => '決済の確認が 402 で断った場合 => 恒久。rejected を保存し、確認待ちデータを消す',
				'failure'             => array( 'payment_declined', 402 ),
				'expected_first'      => 'rejected',
				'expected_retry'      => false,
				'expected_after'      => 'rejected',
			),
			array(
				'test_condition_name' => '枠が埋まっていた場合（capacity_exceeded、409） => 恒久。slot_unavailable を保存する',
				'failure'             => array( 'capacity_exceeded', 409 ),
				'expected_first'      => 'slot_unavailable',
				'expected_retry'      => false,
				'expected_after'      => 'slot_unavailable',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			list( $code, $status )             = $case['failure'];
			$email                             = 'temp' . $index . '@example.com';
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ), array( 'customer_email' => $email ) ) );
			$token_hash                        = hash( 'sha256', $raw_token );

			// 予約の投稿を作れない失敗は、投稿の保存を空の内容として止めて再現する。それ以外は決済の確認のフィルターで返す。
			$fail_payment = static function () use ( $code, $status ) {
				return new WP_Error( $code, 'テスト用の失敗', null === $status ? '' : array( 'status' => $status ) );
			};
			if ( 'empty_post' === $code ) {
				add_filter( 'wp_insert_post_empty_content', '__return_true' );
			} else {
				add_filter( 'vkbm_booking_payment_check', $fail_payment );
			}
			$first = $controller->process_verification( $raw_token );
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
			remove_filter( 'vkbm_booking_payment_check', $fail_payment );

			$this->assertSame( $case['expected_first'], $first['result'], $case['test_condition_name'] );
			$this->assertSame( 0, $this->count_bookings(), $case['test_condition_name'] . '（予約は作られない）' );
			$this->assertSame( $case['expected_retry'], false !== get_transient( Guest_Booking_Controller::PENDING_PREFIX . $token_hash ), $case['test_condition_name'] . '（確認待ちデータが残るか）' );
			$this->assertSame( ! $case['expected_retry'], false !== get_transient( Guest_Booking_Controller::RESULT_PREFIX . $token_hash ), $case['test_condition_name'] . '（結果が保存されるか）' );
			$this->assertNull( $this->read_lock( Guest_Booking_Controller::LOCK_PREFIX . $token_hash ), $case['test_condition_name'] . '（ロックは解放される）' );

			// 失敗の原因が無くなってから開き直す。
			$again = $controller->process_verification( $raw_token );
			$this->assertSame( $case['expected_after'], $again['result'], $case['test_condition_name'] . '（開き直したとき）' );
			$this->assertSame( ! $case['expected_retry'], $again['repeat'], $case['test_condition_name'] . '（保存済みの結果を返したか）' );
			if ( $again['booking_id'] > 0 ) {
				wp_delete_post( $again['booking_id'], true );
			}
			$this->reset_email_limit( $email );
		}
	}

	/**
	 * リンクを開く前に顧客フラグが付いた場合・受付方法が会員のみに戻った場合は、予約を作らない。
	 */
	public function test_process_verification_rechecks_conditions(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$flag_service = new Customer_Flag_Service();
		$flag_service->save_types(
			array(
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => 'ご予約をお受けできません。',
				),
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'リンクを開く前に、同じメールアドレスの会員へ止めるフラグが付いた場合 => 予約を作らず rejected',
				'prepare'             => 'flag',
				'expected_result'     => 'rejected',
				'expected_bookings'   => 0,
				'expected_pending'    => false,
			),
			array(
				'test_condition_name' => 'リンクを開く時点で受付方法が「会員のみ」に戻っていた場合 => 予約を作らず unavailable。確認待ちデータは残す',
				'prepare'             => 'member',
				'expected_result'     => 'unavailable',
				'expected_bookings'   => 0,
				'expected_pending'    => true,
			),
			array(
				'test_condition_name' => '何も変わっていない場合 => 予約が作られる',
				'prepare'             => 'none',
				'expected_result'     => 'pending',
				'expected_bookings'   => 1,
				'expected_pending'    => false,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->delete_all_flag_options();
			$flag_service->save_types(
				array(
					array(
						'id'             => 'blacklist',
						'label'          => 'ブラックリスト',
						'blocks_booking' => true,
						'block_message'  => 'ご予約をお受けできません。',
					),
				)
			);
			$this->save_settings(
				array(
					'booking_customer_mode'        => 'guest',
					'provider_booking_status_mode' => 'pending',
				)
			);
			$before                            = $this->count_bookings();
			$email                             = 'recheck' . $index . '@example.com';
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ), array( 'customer_email' => $email ) ) );

			if ( 'flag' === $case['prepare'] ) {
				$member = $this->factory()->user->create( array( 'user_email' => $email ) );
				$flag_service->set_flag( $member, 'blacklist', '無断キャンセル' );
			} elseif ( 'member' === $case['prepare'] ) {
				$this->save_settings( array( 'booking_customer_mode' => 'member' ) );
			}

			$result = $controller->process_verification( $raw_token );

			$this->assertSame( $case['expected_result'], $result['result'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_bookings'], $this->count_bookings() - $before, $case['test_condition_name'] );
			$this->assertSame( $case['expected_pending'], false !== get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw_token ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * 確認リンクを開いたときのフック処理: GET だけ処理し、結果を予約ブロックが読める形で保持する。
	 */
	public function test_handle_verification_request(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );

		$fired = array();
		$hook  = static function ( $result ) use ( &$fired ) {
			$fired[] = $result;
		};
		add_action( 'vkbm_guest_booking_verified', $hook );

		$test_cases = array(
			array(
				'test_condition_name' => 'クエリが無い場合 => 何もしない',
				'method'              => 'GET',
				'query'               => '',
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'HEAD リクエスト（メールの安全確認など）の場合 => 予約を作らない',
				'method'              => 'HEAD',
				'query'               => $raw_token,
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'GET でトークンがある場合 => 予約を作って結果を保持する',
				'method'              => 'GET',
				'query'               => $raw_token,
				'expected'            => 'confirmed',
			),
		);

		foreach ( $test_cases as $case ) {
			Guest_Booking_Controller::reset_current_result();
			$_SERVER['REQUEST_METHOD'] = $case['method'];
			$_GET                      = '' === $case['query'] ? array() : array( 'vkbm_guest_verify' => $case['query'] );
			$controller->handle_verification_request();

			$current = Guest_Booking_Controller::get_current_result();
			if ( null === $case['expected'] ) {
				$this->assertNull( $current, $case['test_condition_name'] );
			} else {
				$this->assertIsArray( $current, $case['test_condition_name'] );
				$this->assertSame( $case['expected'], $current['result'], $case['test_condition_name'] );
			}
		}
		remove_action( 'vkbm_guest_booking_verified', $hook );
		$this->assertCount( 1, $fired, '結果を知らせるアクションは、処理したときだけ発火する' );
		$this->assertSame( 1, $this->count_bookings() );
	}

	/**
	 * 予約ブロックの画面へ渡す結果: 処理していなければ null。確定・仮予約には予約内容を付け、理由（code・message）と連絡先は渡さない。
	 */
	public function test_get_public_result(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);

		// 処理していないリクエストでは null。
		Guest_Booking_Controller::reset_current_result();
		$this->assertNull( Guest_Booking_Controller::get_public_result(), '処理していない場合 => null' );

		// 確認リンクを開いて予約を作ると、予約内容つきの結果になる。
		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		$_SERVER['REQUEST_METHOD']         = 'GET';
		$_GET                              = array( 'vkbm_guest_verify' => $raw_token );
		$controller->handle_verification_request();

		$public = Guest_Booking_Controller::get_public_result();
		$this->assertSame( array( 'result', 'bookingStatus', 'repeat', 'booking' ), array_keys( $public ), '渡すキーは決まっている（code・message を含めない）' );
		$this->assertSame( 'confirmed', $public['result'] );
		$this->assertSame( 'confirmed', $public['bookingStatus'] );
		$this->assertFalse( $public['repeat'] );
		$this->assertSame( self::START, $public['booking']['startAt'] );
		$this->assertSame( self::END, $public['booking']['endAt'] );
		$this->assertSame( 'Asia/Tokyo', $public['booking']['timezone'] );
		$this->assertSame( get_the_title( $menu_id ), $public['booking']['menuName'] );
		$this->assertSame( 1, $public['booking']['guests'] );
		$this->assertStringNotContainsString( 'guest@example.com', (string) wp_json_encode( $public ), '連絡先は渡さない' );

		// 開き直したときは repeat が true になる。
		Guest_Booking_Controller::reset_current_result();
		$controller->handle_verification_request();
		$public = Guest_Booking_Controller::get_public_result();
		$this->assertSame( 'confirmed', $public['result'] );
		$this->assertTrue( $public['repeat'] );

		// 予約を作らなかった結果は、予約内容を付けず、理由も渡さない（顧客フラグ・重複予約の有無を探られないため）。
		$test_cases = array(
			array(
				'test_condition_name' => 'rejected => 理由を渡さず、予約内容も付けない',
				'current'             => array(
					'result'         => Guest_Booking_Controller::RESULT_REJECTED,
					'booking_id'     => 0,
					'booking_status' => '',
					'repeat'         => false,
					'code'           => 'customer_flag_blocked',
					'message'        => 'この予約は受け付けられません。（内部の理由）',
				),
				'expected_result'     => 'rejected',
			),
			array(
				'test_condition_name' => 'booking_closed => 予約があっても内容を付けない',
				'current'             => array(
					'result'         => Guest_Booking_Controller::RESULT_BOOKING_CLOSED,
					'booking_id'     => 99999,
					'booking_status' => 'cancelled',
					'repeat'         => true,
					'code'           => '',
					'message'        => '',
				),
				'expected_result'     => 'booking_closed',
			),
		);
		$property   = new \ReflectionProperty( Guest_Booking_Controller::class, 'current_result' );
		$property->setAccessible( true );
		foreach ( $test_cases as $case ) {
			$property->setValue( null, $case['current'] );
			$public = Guest_Booking_Controller::get_public_result();
			$this->assertSame( $case['expected_result'], $public['result'], $case['test_condition_name'] );
			$this->assertNull( $public['booking'], $case['test_condition_name'] );
			$this->assertStringNotContainsString( '内部の理由', (string) wp_json_encode( $public ), $case['test_condition_name'] );
			$this->assertStringNotContainsString( 'customer_flag_blocked', (string) wp_json_encode( $public ), $case['test_condition_name'] );
		}
	}

	/**
	 * ゲストは名簿を持たないため、ご利用になる方は手入力だけを受け付ける。名前が必須の設定では入力が無いと申し込みの時点で断る。
	 */
	public function test_guest_attendee_is_manual_only(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$test_cases = array(
			array(
				'test_condition_name' => '任意の設定で名前を入力した場合 => 手入力として保存する（名簿の ID を送っても無視する）',
				'attendee_mode'       => 'optional',
				'draft_extra'         => array( 'attendee_name' => '家族の花子' ),
				'params'              => array( 'attendee_id' => 'someone-from-roster' ),
				'expected_error'      => '',
				'expected_name'       => '家族の花子',
				'expected_id'         => 'manual',
			),
			array(
				'test_condition_name' => '必須の設定で名前が無い場合 => 申し込みの時点で断る',
				'attendee_mode'       => 'required',
				'draft_extra'         => array(),
				'params'              => array(),
				'expected_error'      => 'attendee_name_required',
				'expected_name'       => '',
				'expected_id'         => '',
			),
			array(
				'test_condition_name' => '利用しない設定の場合 => 名前を保存しない',
				'attendee_mode'       => 'disabled',
				'draft_extra'         => array( 'attendee_name' => '' ),
				'params'              => array(),
				'expected_error'      => '',
				'expected_name'       => '',
				'expected_id'         => '',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_settings(
				array(
					'booking_customer_mode'        => 'guest',
					'provider_booking_status_mode' => 'confirmed',
					'booking_attendee_name_mode'   => $case['attendee_mode'],
				)
			);
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$email                             = 'attendee' . $index . '@example.com';
			$draft_token                       = $this->store_draft( $menu_id, $staff_id, self::START, self::END, $case['draft_extra'] );
			$request                           = $this->build_request( $draft_token, array_merge( array( 'customer_email' => $email ), $case['params'] ) );

			if ( '' !== $case['expected_error'] ) {
				$response = $controller->create_guest_booking_request( $request );
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error'], $response->get_error_code(), $case['test_condition_name'] );
				continue;
			}

			$raw_token  = $this->request_and_get_token( $controller, $notification, $request );
			$result     = $controller->process_verification( $raw_token );
			$booking_id = $result['booking_id'];
			$this->assertSame( 'confirmed', $result['result'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_name'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_name', true ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_id'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_id', true ), $case['test_condition_name'] );
			// 重複しないよう、次の案件のために作った予約は片付ける。
			wp_delete_post( $booking_id, true );
		}
	}

	/**
	 * 確認リンクで予約を作る時点でも、同じメールアドレスの重複予約は止める。
	 */
	public function test_process_verification_blocks_duplicate_booking(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);
		$staff_id_2 = $this->create_staff();
		list( $controller, $notification )  = $this->build_controller( $staff_id );
		list( $controller_2, $notification_2 ) = $this->build_controller( $staff_id_2 );
		$first_token                        = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		$this->reset_email_limit( 'guest@example.com' );
		// 別のスタッフの枠（空きはある）で、同じメールアドレスの重なる時間帯を申し込む。
		$second_token = $this->request_and_get_token( $controller_2, $notification_2, $this->build_request( $this->store_draft( $menu_id, $staff_id_2, '2026-12-01T10:00:00+09:00', '2026-12-01T10:30:00+09:00' ) ) );

		$this->assertSame( 'confirmed', $controller->process_verification( $first_token )['result'] );
		$second = $controller_2->process_verification( $second_token );

		$this->assertSame( 'rejected', $second['result'], '同じメールアドレスで時間が重なる 2 件目は止める' );
		$this->assertSame( 'booking_time_conflict', $second['code'] );
		$this->assertSame( __( 'You already have a reservation at the same date and time with this email address. Please change the date and time.', 'vk-booking-manager' ), $second['message'], 'メールアドレス基準の文言' );
		$this->assertSame( 1, $this->count_bookings() );
	}

	/**
	 * vkbm_booking_block_check の第 3 引数: 会員の予約はユーザー ID で、ゲスト予約は連絡先で判定する。既存の 2 引数の呼び出し方も使える。
	 */
	public function test_block_flagged_customer_with_contact(): void {
		$service = new Customer_Flag_Service();
		$service->save_types(
			array(
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => 'ご予約をお受けできません。',
				),
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
			)
		);
		$blocked = $this->factory()->user->create( array( 'user_email' => 'bad@example.com' ) );
		update_user_meta( $blocked, 'phone_number', '09099998888' );
		$service->set_flag( $blocked, 'blacklist', '無断キャンセル' );
		$noted = $this->factory()->user->create( array( 'user_email' => 'noted@example.com' ) );
		update_user_meta( $noted, 'phone_number', '09077776666' );
		$service->set_flag( $noted, 'caution', '遅刻が多い' );
		$free = $this->factory()->user->create( array( 'user_email' => 'free@example.com' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '会員のユーザー ID（ブロック対象）のみ渡す既存の呼び出し方 => 止める',
				'args'                => array( null, $blocked ),
				'expected_blocked'    => true,
			),
			array(
				'test_condition_name' => '会員のユーザー ID（フラグなし）のみ渡す既存の呼び出し方 => 通す',
				'args'                => array( null, $free ),
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => 'ゲスト（ID 0）でメールアドレスが一致（大文字・空白は無視） => 止める',
				'args'                => array(
					null,
					0,
					array(
						'email' => ' BAD@Example.com ',
						'phone' => '',
					),
				),
				'expected_blocked'    => true,
			),
			array(
				'test_condition_name' => 'ゲスト（ID 0）で電話番号が一致（全角・ハイフン付きは、呼び出し側でそろえた値と同じに扱う） => 止める',
				'args'                => array(
					null,
					0,
					array(
						'email' => '',
						'phone' => '０９０-9999-8888',
					),
				),
				'expected_blocked'    => true,
			),
			array(
				'test_condition_name' => 'ゲストでメールは別人・電話番号が一致 => 止める（どちらか一致で止める）',
				'args'                => array(
					null,
					0,
					array(
						'email' => 'unknown@example.com',
						'phone' => '09099998888',
					),
				),
				'expected_blocked'    => true,
			),
			array(
				'test_condition_name' => 'ゲストで止めない種類のフラグの会員と一致 => 通す',
				'args'                => array(
					null,
					0,
					array(
						'email' => 'noted@example.com',
						'phone' => '09077776666',
					),
				),
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => 'ゲストでどの会員とも一致しない => 通す',
				'args'                => array(
					null,
					0,
					array(
						'email' => 'nobody@example.com',
						'phone' => '08000000000',
					),
				),
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => 'ゲストで連絡先が空 => 通す',
				'args'                => array( null, 0, array() ),
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => '他のフィルターがすでに WP_Error を返している場合 => そのエラーを尊重する',
				'args'                => array( new WP_Error( 'other_block', 'x' ), $free ),
				'expected_blocked'    => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $this->guard->block_flagged_customer( ...$case['args'] );
			$this->assertSame( $case['expected_blocked'], is_wp_error( $result ), $case['test_condition_name'] );
		}
	}

	/**
	 * 予約を作る部品は、フィルターに連絡先（第 3 引数）を渡す。会員の予約では空の配列。
	 */
	public function test_block_check_receives_contact(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);

		$received = array();
		$spy      = static function ( $result, $user_id, $contact = array() ) use ( &$received ) {
			$received[] = array( $user_id, $contact );
			return $result;
		};
		add_filter( 'vkbm_booking_block_check', $spy, 10, 3 );

		list( $controller, $notification ) = $this->build_controller( $staff_id );
		$raw_token                         = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
		$controller->process_verification( $raw_token );
		remove_filter( 'vkbm_booking_block_check', $spy, 10 );

		// 判定はリンクを開いた時点の 1 回だけ（申し込みの時点では行わない）。
		$expected_contact = array(
			'email' => 'guest@example.com',
			'phone' => '09012345678',
		);
		$this->assertSame( array( array( 0, $expected_contact ) ), $received );
	}

	/**
	 * 個人情報の匿名化の対象に、ゲスト予約の確認日時が含まれ、匿名化すると空になる。
	 */
	public function test_guest_verified_at_is_anonymized(): void {
		$fields = Booking_Anonymizer::get_meta_fields();
		$this->assertArrayHasKey( Booking_Source::META_GUEST_VERIFIED_AT, $fields );
		$this->assertSame( '', $fields[ Booking_Source::META_GUEST_VERIFIED_AT ]['value'] );

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '2020-01-01 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '2020-01-01 10:30:00' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', '2020-01-01 10:30:00' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', 'ゲスト 太郎' );
		update_post_meta( $booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
		update_post_meta( $booking_id, Booking_Source::META_GUEST_VERIFIED_AT, '2019-12-31 01:00:00' );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );

		$this->assertSame( '', get_post_meta( $booking_id, Booking_Source::META_GUEST_VERIFIED_AT, true ), '確認日時は消える' );
		$this->assertSame( Booking_Source::VALUE_GUEST, get_post_meta( $booking_id, Booking_Source::META_KEY, true ), 'ゲスト予約の印は残す（経緯を追えるように）' );
	}

	/**
	 * 個人情報の匿名化の対象に、キャンセル用トークンのハッシュが含まれ、匿名化するとキャンセル用リンクが使えなくなる。
	 */
	public function test_guest_cancel_token_hash_is_anonymized(): void {
		$fields = Booking_Anonymizer::get_meta_fields();
		$this->assertArrayHasKey( Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, $fields );
		$this->assertSame( '', $fields[ Booking_Source::META_GUEST_CANCEL_TOKEN_HASH ]['value'] );

		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '2020-01-01 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '2020-01-01 10:30:00' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', '2020-01-01 10:30:00' );
		update_post_meta( $booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
		$first  = \VKBookingManager\Bookings\Guest_Cancel_Controller::issue_token( $booking_id );
		$second = \VKBookingManager\Bookings\Guest_Cancel_Controller::issue_token( $booking_id );

		$this->assertSame( Booking_Anonymizer::RESULT_ANONYMIZED, ( new Booking_Anonymizer() )->anonymize( $booking_id ) );

		$this->assertSame( '', get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, true ), 'ハッシュは消える' );
		$this->assertSame( array(), get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), '空の行を残さず、すべての行を削除する' );
		$this->assertFalse( \VKBookingManager\Bookings\Guest_Cancel_Controller::verify_token( $booking_id, $first ), '匿名化後は古いリンクで使えない' );
		$this->assertFalse( \VKBookingManager\Bookings\Guest_Cancel_Controller::verify_token( $booking_id, $second ), '匿名化後は新しいリンクでも使えない' );
	}

	/**
	 * 通知の種類 guest_verification_customer がメールログの種類の一覧に載っている。
	 */
	public function test_email_log_type_label(): void {
		$page   = new \VKBookingManager\Admin\Email_Log_Page();
		$method = new \ReflectionMethod( $page, 'get_type_label' );
		$method->setAccessible( true );

		$this->assertNotSame( '', $method->invoke( $page, 'guest_verification_customer' ) );
		$this->assertSame( '', $method->invoke( $page, 'unknown_type' ) );
	}

	/**
	 * 1 つの予約下書きから確認メールを送れる宛先は 3 件まで（フィルターで変更可）。同じ宛先への送り直しは数を増やさない。
	 */
	public function test_create_guest_booking_request_recipient_limit(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings( array( 'booking_customer_mode' => 'guest' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '既定の上限 3 件 => 4 件目の宛先は 429、すでに送った宛先への送り直しは通る',
				'max'                 => null,
				'emails'              => array( 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'a@example.com' ),
				'expected'            => array( 200, 200, 200, 429, 200 ),
			),
			array(
				'test_condition_name' => 'フィルターで上限を 1 件にした場合 => 2 件目の宛先から 429',
				'max'                 => 1,
				'emails'              => array( 'e@example.com', 'f@example.com' ),
				'expected'            => array( 200, 429 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$max    = $case['max'];
			$filter = static function ( $default ) use ( $max ) {
				return null === $max ? $default : $max;
			};
			add_filter( 'vkbm_guest_booking_max_recipients_per_draft', $filter );
			list( $controller )  = $this->build_controller( $staff_id );
			$draft_token         = $this->store_draft( $menu_id, $staff_id );
			$statuses            = array();
			foreach ( $case['emails'] as $email ) {
				$this->reset_email_limit( $email );
				$response   = $controller->create_guest_booking_request( $this->build_request( $draft_token, array( 'customer_email' => $email ) ) );
				$statuses[] = $response instanceof WP_Error ? $response->get_error_data()['status'] : 200;
				if ( $response instanceof WP_Error ) {
					$this->assertSame( 'guest_booking_recipient_limit', $response->get_error_code(), $case['test_condition_name'] . '（エラーコード）' );
					$this->assertSame( __( 'Confirmation emails cannot be sent to any more email addresses for this reservation. Please reload the reservation page and start again.', 'vk-booking-manager' ), $response->get_error_message(), $case['test_condition_name'] . '（宛先の上限の専用文言）' );
					$this->assertNotSame( __( 'Too many requests. Please try again later.', 'vk-booking-manager' ), $response->get_error_message(), $case['test_condition_name'] . '（メールアドレス単位の文言とは別）' );
				}
			}
			remove_filter( 'vkbm_guest_booking_max_recipients_per_draft', $filter );
			$this->assertSame( $case['expected'], $statuses, $case['test_condition_name'] );
		}
	}

	/**
	 * 排他のオプションを DB へ直接置く（実装と同じく、キャッシュを通さない）。
	 *
	 * @param string $name  オプション名。
	 * @param string $value 値。
	 */
	private function put_lock( string $name, string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * 排他のオプションの値を DB から読む。無ければ null。
	 *
	 * @param string $name オプション名。
	 */
	private function read_lock( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * 排他のオプションを DB から消す。
	 *
	 * @param string $name オプション名。
	 */
	private function delete_lock( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * 排他の取得: 既に新しい排他があれば取れず、古ければ取り直せ、解放は自分の値のときだけ消す。
	 */
	public function test_acquire_lock_is_exclusive(): void {
		list( $controller ) = $this->build_controller( $this->create_staff() );
		$acquire            = new \ReflectionMethod( $controller, 'acquire_lock' );
		$acquire->setAccessible( true );
		$release = new \ReflectionMethod( $controller, 'release_lock' );
		$release->setAccessible( true );
		$name = Guest_Booking_Controller::LOCK_PREFIX . 'unit_test_lock';
		$this->delete_lock( $name );

		$first = $acquire->invoke( $controller, $name, 60 );
		$this->assertNotSame( '', $first, '排他が無ければ取れる' );
		$this->assertSame( '', $acquire->invoke( $controller, $name, 60 ), '新しい排他がある間は取れない' );

		// 「オプションが無い」という古いキャッシュが残っていても、DB を直接読んで判定する（get_option / add_option ベースだと取れてしまう）。
		wp_cache_set( 'notoptions', array( $name => true ), 'options' );
		wp_cache_delete( $name, 'options' );
		$this->assertSame( '', $acquire->invoke( $controller, $name, 60 ), '古いキャッシュが「無い」と言っていても、DB に新しい排他があれば取れない' );

		$release->invoke( $controller, $name, 'someone-else|1' );
		$this->assertSame( $first, $this->read_lock( $name ), '他人の値では解放できない' );

		$this->delete_lock( $name );
		$this->put_lock( $name, 'old|' . ( time() - 120 ) );
		$taken = $acquire->invoke( $controller, $name, 60 );
		$this->assertNotSame( '', $taken, '古い排他は取り直せる' );
		$this->assertSame( $taken, $this->read_lock( $name ) );

		$release->invoke( $controller, $name, $taken );
		$this->assertNull( $this->read_lock( $name ), '自分の値なら解放できる' );
	}

	/**
	 * 排他のキャッシュを消すとき、消すのはそのオプションの分だけで、notoptions の他のオプションの項目は残す。
	 */
	public function test_flush_lock_cache(): void {
		list( $controller ) = $this->build_controller( $this->create_staff() );
		$flush              = new \ReflectionMethod( $controller, 'flush_lock_cache' );
		$flush->setAccessible( true );
		$name = Guest_Booking_Controller::LOCK_PREFIX . 'unit_test_flush';

		$test_cases = array(
			array(
				'test_condition_name' => 'notoptions に自分と他のオプションが入っている場合 => 自分の項目だけ外す',
				'notoptions'          => array(
					$name                 => true,
					'other_unit_test_opt' => true,
				),
				'expected'            => array( 'other_unit_test_opt' => true ),
			),
			array(
				'test_condition_name' => 'notoptions に他のオプションだけ入っている場合 => そのまま',
				'notoptions'          => array( 'other_unit_test_opt' => true ),
				'expected'            => array( 'other_unit_test_opt' => true ),
			),
			array(
				'test_condition_name' => 'notoptions が無い場合 => 何も作らない',
				'notoptions'          => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_cache_delete( 'notoptions', 'options' );
			if ( null !== $case['notoptions'] ) {
				wp_cache_set( 'notoptions', $case['notoptions'], 'options' );
			}
			wp_cache_set( $name, 'stale', 'options' );

			$flush->invoke( $controller, $name );

			$this->assertFalse( wp_cache_get( $name, 'options' ), $case['test_condition_name'] . '（自分のキャッシュは消える）' );
			$this->assertSame( $case['expected'], wp_cache_get( 'notoptions', 'options' ), $case['test_condition_name'] );
		}
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * 確認リンクの処理中ロックは、古くなって残っていれば取り直し、新しければ処理中として扱う。
	 */
	public function test_process_verification_lock_expiry(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '10 秒前に取られたロックが残っている場合 => 処理中',
				'lock_age'            => 10,
				'expected'            => 'processing',
			),
			array(
				'test_condition_name' => '120 秒前に取られたロックが残っている場合 => 古いので取り直して予約を作る',
				'lock_age'            => 120,
				'expected'            => 'confirmed',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			list( $controller, $notification ) = $this->build_controller( $staff_id );
			$this->reset_email_limit( 'guest@example.com' );
			$raw_token = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ) ) );
			$lock_name = Guest_Booking_Controller::LOCK_PREFIX . hash( 'sha256', $raw_token );
			$this->put_lock( $lock_name, 'old|' . ( time() - $case['lock_age'] ) );

			$result = $controller->process_verification( $raw_token );

			$this->assertSame( $case['expected'], $result['result'], $case['test_condition_name'] );
			if ( 'confirmed' === $case['expected'] ) {
				$this->assertNull( $this->read_lock( $lock_name ), $case['test_condition_name'] . '（終わったらロックを消す）' );
				wp_delete_post( $result['booking_id'], true );
			}
			$this->delete_lock( $lock_name );
		}
	}

	/**
	 * 備考とご利用になる方の名前は、保存前に整形・上限を適用する。配列などが送られても文字列にしない。
	 */
	public function test_guest_form_values_are_sanitized(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$this->save_settings(
			array(
				'booking_customer_mode'        => 'guest',
				'provider_booking_status_mode' => 'confirmed',
				'booking_attendee_name_mode'   => 'optional',
			)
		);
		list( $controller, $notification ) = $this->build_controller( $staff_id );

		$test_cases = array(
			array(
				'test_condition_name' => '備考にタグが入っている場合 => タグを除いて保存する',
				'params'              => array( 'memo' => '<script>alert(1)</script>窓際を希望' ),
				'expected_memo'       => '窓際を希望',
			),
			array(
				'test_condition_name' => '備考に配列が送られた場合 => 空として扱う',
				'params'              => array( 'memo' => array( 'x' ) ),
				'expected_memo'       => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_email_limit( 'guest@example.com' );
			$raw_token = $this->request_and_get_token( $controller, $notification, $this->build_request( $this->store_draft( $menu_id, $staff_id ), $case['params'] ) );
			$pending   = get_transient( Guest_Booking_Controller::PENDING_PREFIX . hash( 'sha256', $raw_token ) );
			$this->assertSame( $case['expected_memo'], $pending['form']['memo'], $case['test_condition_name'] );
		}
	}

	/**
	 * 印のある投稿者 0 の予約を作る（ゲスト予約・iCal 取り込み予約）。
	 *
	 * @param string $source 予約の種類の印。
	 * @return int 予約の投稿 ID。
	 */
	private function create_authorless_booking( string $source ): int {
		wp_set_current_user( 0 );
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => 0,
			)
		);
		update_post_meta( $booking_id, Booking_Source::META_KEY, $source );

		return $booking_id;
	}

	/**
	 * 管理画面の編集画面保存（edit_post）では、保存した管理者の ID が post_author に入って渡される。
	 * それでもゲスト予約・iCal 取り込み予約の投稿者は 0 のまま。
	 */
	public function test_keep_authorless_booking_author_on_admin_edit_save(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'ゲスト予約を編集画面の保存（投稿者に保存した管理者の ID を明示）で保存する場合 => 投稿者は 0 のまま',
				'source'              => Booking_Source::VALUE_GUEST,
				'post_author'         => $admin,
			),
			array(
				'test_condition_name' => 'ゲスト予約を編集画面の保存（投稿者 0 を明示）で保存する場合 => 投稿者は 0 のまま',
				'source'              => Booking_Source::VALUE_GUEST,
				'post_author'         => 0,
			),
			array(
				'test_condition_name' => 'iCal 取り込み予約を編集画面の保存（投稿者に管理者の ID を明示）で保存する場合 => 投稿者は 0 のまま',
				'source'              => 'external_ical',
				'post_author'         => $admin,
			),
		);

		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_authorless_booking( $case['source'] );
			wp_set_current_user( $admin );
			$result = edit_post(
				array(
					'post_ID'     => $booking_id,
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_title'  => '編集画面で保存',
					'post_status' => 'publish',
					'post_author' => $case['post_author'],
				)
			);
			$this->assertSame( $booking_id, (int) $result, $case['test_condition_name'] . '（保存できていること）' );
			$this->assertSame( '編集画面で保存', get_post_field( 'post_title', $booking_id ), $case['test_condition_name'] . '（タイトルは更新されること）' );
			$this->assertSame( 0, (int) get_post_field( 'post_author', $booking_id ), $case['test_condition_name'] );
		}

		// クイック編集（wp_ajax_inline_save）も同じ edit_post() を通る。
		$booking_id = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
		wp_set_current_user( $admin );
		edit_post(
			array(
				'post_ID'     => $booking_id,
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_title'  => 'クイック編集',
			)
		);
		$this->assertSame( 0, (int) get_post_field( 'post_author', $booking_id ), 'クイック編集の保存でも投稿者は 0 のまま' );
	}

	/**
	 * 予約者の選び直し（allow_author_change() の中の保存）では付け替わる。印の無い予約には触れない。
	 */
	public function test_allow_author_change_reassigns_author(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$other = $this->factory()->user->create();

		$booking_id = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
		wp_set_current_user( $admin );
		Booking_Source::allow_author_change(
			$booking_id,
			static function () use ( $booking_id, $other ) {
				return wp_update_post(
					array(
						'ID'          => $booking_id,
						'post_author' => $other,
					)
				);
			}
		);
		$this->assertSame( $other, (int) get_post_field( 'post_author', $booking_id ), '許可の中の保存では付け替わる' );

		// 許可の外に出たら、また投稿者 0 を保つ（許可は下ろされている）。
		$booking_id = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
		wp_set_current_user( $admin );
		Booking_Source::allow_author_change( $booking_id, static function () {} );
		try {
			Booking_Source::allow_author_change(
				$booking_id,
				static function () {
					throw new \RuntimeException( 'x' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		wp_update_post(
			array(
				'ID'          => $booking_id,
				'post_author' => $other,
			)
		);
		$this->assertSame( 0, (int) get_post_field( 'post_author', $booking_id ), '許可の外（例外で抜けた後を含む）では付け替わらない' );

		// 許可は対象の予約だけ。別の予約の許可の中で保存しても、付け替わらない。
		$target_id  = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
		$another_id = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
		wp_set_current_user( $admin );
		Booking_Source::allow_author_change(
			$target_id,
			static function () use ( $another_id, $other ) {
				return wp_update_post(
					array(
						'ID'          => $another_id,
						'post_author' => $other,
					)
				);
			}
		);
		$this->assertSame( 0, (int) get_post_field( 'post_author', $another_id ), '別の予約の許可では付け替わらない' );

		// 印の無い通常の予約は、フィルターが触れない。
		$plain_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $admin,
			)
		);
		wp_update_post(
			array(
				'ID'          => $plain_id,
				'post_author' => $other,
			)
		);
		$this->assertSame( $other, (int) get_post_field( 'post_author', $plain_id ), '印の無い予約は通常どおり付け替わる' );
	}

	/**
	 * フィルターを外すと編集画面の保存で投稿者が管理者に置き換わる（フィルターが実際に効いていることの確認）。
	 */
	public function test_admin_edit_save_replaces_author_without_filter(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNotFalse( has_filter( 'wp_insert_post_data', array( Booking_Source::class, 'keep_authorless_booking_author' ) ), 'フィルターが登録されていること' );

		remove_filter( 'wp_insert_post_data', array( Booking_Source::class, 'keep_authorless_booking_author' ), 10 );
		try {
			$booking_id = $this->create_authorless_booking( Booking_Source::VALUE_GUEST );
			wp_set_current_user( $admin );
			edit_post(
				array(
					'post_ID'     => $booking_id,
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_title'  => '編集画面で保存',
					'post_status' => 'publish',
					'post_author' => $admin,
				)
			);
			$this->assertSame( $admin, (int) get_post_field( 'post_author', $booking_id ), 'フィルターが無いと投稿者が管理者に置き換わる' );
		} finally {
			add_filter( 'wp_insert_post_data', array( Booking_Source::class, 'keep_authorless_booking_author' ), 10, 2 );
		}
	}

	/**
	 * 通知のダブルが最後に記録した確認メールの生のトークンを返す。
	 */
	private function request_token_from_notification( Guest_Notification_Recording_Double $notification ): string {
		$last = end( $notification->verifications );
		$this->assertIsArray( $last );
		preg_match( '/vkbm_guest_verify=([A-Za-z0-9]{32})$/', (string) $last['verify_url'], $matches );

		return (string) ( $matches[1] ?? '' );
	}
}

/**
 * 確認メールと予約作成のお知らせの呼び出しを記録するテスト用ダブル。
 */
class Guest_Notification_Recording_Double extends Booking_Notification_Service {
	/**
	 * 送ろうとした確認メールの内容。
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $verifications = array();

	/**
	 * 予約作成のお知らせの種類（confirmed / pending）。
	 *
	 * @var array<int, string>
	 */
	public array $creation_calls = array();

	/**
	 * 予約作成のお知らせの時点で受け取れた、キャンセル用リンクの生のトークン。
	 *
	 * @var array<int, string>
	 */
	public array $guest_cancel_tokens = array();

	/**
	 * @var bool
	 */
	private bool $mail_ok;

	/**
	 * 送信の最中に実行する処理（送信に時間がかかる間に、別の申し込みが進む場面を再現するため）。
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $on_send = null;

	public function __construct( bool $mail_ok = true ) {
		parent::__construct( new Settings_Repository() );
		$this->mail_ok = $mail_ok;
	}

	public function send_guest_verification( array $data ): bool {
		$this->verifications[] = $data;
		if ( null !== $this->on_send ) {
			( $this->on_send )();
		}

		return $this->mail_ok;
	}

	public function handle_confirmed_creation( int $booking_id ): void {
		$this->creation_calls[]      = 'confirmed';
		$this->guest_cancel_tokens[] = \VKBookingManager\Bookings\Guest_Cancel_Controller::take_fresh_token( $booking_id );
	}

	public function handle_pending_creation( int $booking_id ): void {
		$this->creation_calls[]      = 'pending';
		$this->guest_cancel_tokens[] = \VKBookingManager\Bookings\Guest_Cancel_Controller::take_fresh_token( $booking_id );
	}
}

/**
 * 空き枠を 1 件返す（または空き枠なしを返す）テスト用ダブル。
 */
class Guest_Availability_Test_Double extends Availability_Service {
	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $slot;

	/**
	 * @param array<string, mixed>|null $slot 返す空き枠。null なら空き枠なし。
	 */
	public function __construct( ?array $slot ) {
		$this->slot = $slot;
	}

	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		return false;
	}

	public function get_daily_slots( array $args ) {
		return array(
			'slots' => null === $this->slot ? array() : array( $this->slot ),
		);
	}
}
