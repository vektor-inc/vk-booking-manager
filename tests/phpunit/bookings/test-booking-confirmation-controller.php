<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Attendees\Attendee_Roster;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Customers\Customer_Flag_Booking_Guard;
use VKBookingManager\Customers\Customer_Flag_Repository;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Tests\Customers\Customer_Flag_Options_Cleanup_Trait;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Common\VKBM_Helper;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function delete_transient;
use function get_post;
use function get_user_by;
use function get_post_meta;
use function set_transient;
use function update_post_meta;
use function update_user_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * @group bookings
 */
class Booking_Confirmation_Controller_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var array<int, string> */
	private array $tokens = [];

	/** @var array<string, mixed> */
	private array $cookie_backup = [];

	/**
	 * テスト開始時の基本設定オプション値（復元用）。未設定時は false。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト開始時の timezone_string オプション値（復元用）。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup = $_COOKIE;
		// disable_nomination() が変更する基本設定オプションを保存しておく。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		// ISO8601(+09:00) と保存形式（サイトTZのY-m-d H:i:s）を一致させるため Asia/Tokyo に固定する（#406）。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = [];
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
		// テスト中に変更した基本設定オプションを元の状態へ復元する。
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		// 指名機能の静的キャッシュをクリアして他テストへの影響を防ぐ。
		Staff_Editor::clear_nomination_enabled_cache();
		Industry_Presets::clear_cache();
		parent::tearDown();
	}

	/**
	 * 指名機能を無効化する（複数人予約は指名OFF時のみ有効なため）。
	 */
	private function disable_nomination(): void {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$settings['staff_enabled'] = false;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 代理予約の名前未入力は、予約作成・ロック取得・決済確認より前に拒否する。
	 */
	public function test_create_booking_requires_proxy_customer_name(): void {
		global $wpdb;

		$this->disable_nomination();
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		wp_set_current_user( $this->create_admin_user() );
		$start = '2026-08-01T10:00:00+09:00';
		$end   = '2026-08-01T10:30:00+09:00';

		$test_cases = array(
			'名前を送らない' => null,
			'空文字'         => '',
			'半角空白のみ'   => '   ',
			'全角空白のみ'   => '　　',
			'混在する空白'   => " \t　 \n",
			'タグ除去後に空' => '<b>　 </b>',
		);
		$payment_calls = 0;
		$payment_check = static function ( $result ) use ( &$payment_calls ) {
			++$payment_calls;
			return $result;
		};
		$mutex_insert_queries = array();
		$record_mutex_insert = static function ( $query ) use ( &$mutex_insert_queries ) {
			if ( false !== strpos( $query, 'INSERT IGNORE' ) && false !== strpos( $query, '_transient_vkbm_booking_mutex_' ) ) {
				$mutex_insert_queries[] = $query;
			}
			return $query;
		};
		add_filter( 'vkbm_booking_payment_check', $payment_check );
		add_filter( 'query', $record_mutex_insert );
		try {
			foreach ( $test_cases as $label => $name ) {
				$token   = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
				$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
				$request->set_param( 'token', $token );
				if ( null !== $name ) {
					$request->set_param( 'customer_name', $name );
				}
				$response = $this->build_controller( $staff_id, $start, $end )->create_booking( $request );

				$this->assertInstanceOf( WP_Error::class, $response, $label );
				$this->assertSame( 'customer_name_required', $response->get_error_code(), $label );
				$this->assertSame( 400, $response->get_error_data()['status'], $label );
				$this->assertSame( 0, $payment_calls, $label );
				$this->assertSame( array(), get_posts( array( 'post_type' => Booking_Post_Type::POST_TYPE, 'post_status' => 'any' ) ), $label );
				// ミューテックスは直接 SQL で作るので、クエリ履歴と残存行の両方を確認する。
				$this->assertSame( array(), $mutex_insert_queries, $label );
				$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_vkbm_booking_mutex_' ) . '%' ) ), $label );
			}
		} finally {
			remove_filter( 'vkbm_booking_payment_check', $payment_check );
			remove_filter( 'query', $record_mutex_insert );
		}
	}

	/**
	 * 会員の本人予約はプロフィール名を使い、代理入力の名前を受け付けない。
	 */
	public function test_create_booking_customer_name_by_capability(): void {
		$this->disable_nomination();
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';
		$test_cases = array(
			array(
				'label'    => '本人予約はプロフィール名',
				'manager'  => false,
				'input'    => null,
				'expected' => '会員 姓名',
			),
			array(
				'label'    => '会員からの名前指定を無視',
				'manager'  => false,
				'input'    => '別のお客様',
				'expected' => '会員 姓名',
			),
			array(
				'label'    => '代理予約は電話なしでも入力名を保存',
				'manager'  => true,
				'input'    => '<b>お客様</b>',
				'expected' => 'お客様',
			),
		);
		foreach ( $test_cases as $case ) {
			// 管理者ロールではなく予約管理権限だけを付け、判定の範囲を確認する。
			$user_id = $this->factory()->user->create( array( 'first_name' => '姓名', 'last_name' => '会員' ) );
			if ( $case['manager'] ) {
				get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
			}
			wp_set_current_user( $user_id );
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();
			$start    = '2026-08-01T10:00:00+09:00';
			$end      = '2026-08-01T10:30:00+09:00';
			$token    = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$request  = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			if ( null !== $case['input'] ) {
				$request->set_param( 'customer_name', $case['input'] );
			}
			$response = $this->build_controller( $staff_id, $start, $end )->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['label'] );
			$booking_id = (int) $response->get_data()['booking_id'];
			$this->assertSame( $case['expected'], get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ), $case['label'] );
			$this->assertSame( $user_id, (int) get_post( $booking_id )->post_author, $case['label'] );
		}
	}

	/**
	 * 代理予約で電話番号が一致する会員の重複予約を拒否する。
	 */
	public function test_admin_booking_conflict_when_phone_matches_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$matched_user_id = $this->factory()->user->create();
		$phone           = '090-1234-5678';
		update_user_meta( $matched_user_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );

		$this->create_booking_post(
			$matched_user_id,
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-01T10:00:00+09:00',
			'2024-02-01T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_name', '代理予約のお客様' );
		$request->set_param( 'customer_phone', $phone );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'booking_time_conflict', $response->get_error_code() );
	}

	/**
	 * 入力した予約者名を保存し、電話番号が一致した会員を予約の持ち主にする。
	 */
	public function test_admin_booking_author_matches_user_when_phone_matches(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$matched_user_id = $this->factory()->user->create(
			[ 'user_email' => 'matched@example.com' ]
		);
		$phone = '090-1111-2222';
		update_user_meta( $matched_user_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-02T11:00:00+09:00',
			'2024-02-02T11:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-02T11:00:00+09:00',
			'2024-02-02T11:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_name', '代理予約のお客様' );
		$request->set_param( 'customer_phone', $phone );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data       = $response->get_data();
		$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
		$this->assertGreaterThan( 0, $booking_id );

		$booking = get_post( $booking_id );
		$this->assertSame( $matched_user_id, (int) $booking->post_author );
		$this->assertSame( '代理予約のお客様', get_post_meta( $booking_id, '_vkbm_booking_customer_name', true ) );
		$this->assertSame( 'matched@example.com', (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ) );
	}

	/**
	 * 管理者予約時に様々なフォーマットの電話番号を入力しても、
	 * 正規化済み番号で保存されたユーザーに正しく紐づくことを検証する。
	 */
	public function test_admin_booking_phone_format_variations_match_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		// ユーザーの電話番号は正規化済み（数字のみ）でDBに保存されている.
		$matched_user_id = $this->factory()->user->create(
			[ 'user_email' => 'phone-match@example.com' ]
		);
		update_user_meta( $matched_user_id, 'phone_number', '09012345678' );

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$test_cases = [
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が数字のみの場合 => ユーザーに紐づく',
				'input_phone'         => '09012345678',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が全角数字の場合 => ユーザーに紐づく',
				'input_phone'         => '０９０１２３４５６７８',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力がハイフン付き半角の場合 => ユーザーに紐づく',
				'input_phone'         => '090-1234-5678',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力がハイフン付き全角の場合 => ユーザーに紐づく',
				'input_phone'         => '０９０−１２３４−５６７８',
				'expected_author'     => $matched_user_id,
			],
			[
				'test_condition_name' => 'ユーザーの電話番号が数字のみで登録 + オーナー入力が括弧付き全角の場合 => ユーザーに紐づく',
				'input_phone'         => '（０９０）１２３４−５６７８',
				'expected_author'     => $matched_user_id,
			],
		];

		foreach ( $test_cases as $index => $case ) {
			// テストケース毎に異なる時間帯を使用して予約の重複を避ける.
			$hour     = 10 + $index;
			$start_at = sprintf( '2024-03-01T%02d:00:00+09:00', $hour );
			$end_at   = sprintf( '2024-03-01T%02d:30:00+09:00', $hour );

			$token = $this->store_temporary_reservation_data(
				$menu_id,
				$staff_id,
				$start_at,
				$end_at
			);

			$controller = $this->build_controller(
				$staff_id,
				$start_at,
				$end_at
			);

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'customer_name', '代理予約のお客様' );
			$request->set_param( 'customer_phone', $case['input_phone'] );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf(
				WP_REST_Response::class,
				$response,
				$case['test_condition_name'] . ' - レスポンスが WP_REST_Response であること'
			);

			$data       = $response->get_data();
			$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] . ' - booking_id が 0 より大きいこと' );

			$booking = get_post( $booking_id );
			$this->assertSame(
				$case['expected_author'],
				(int) $booking->post_author,
				$case['test_condition_name'] . ' - 予約の投稿者がユーザーに紐づいていること'
			);
			$this->assertSame(
				'phone-match@example.com',
				(string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ),
				$case['test_condition_name'] . ' - 顧客メールが紐づいたユーザーのメールであること'
			);
		}
	}

	/**
	 * 会員と一致しない電話番号での代理予約は、操作した管理者を持ち主にする。
	 */
	public function test_admin_booking_author_is_admin_when_phone_missing_or_unmatched(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$admin_id = $this->create_admin_user();
		wp_set_current_user( $admin_id );

		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-03T12:00:00+09:00',
			'2024-02-03T12:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-03T12:00:00+09:00',
			'2024-02-03T12:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'customer_name', '代理予約のお客様' );
		$request->set_param( 'customer_phone', '090-9999-9999' );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data       = $response->get_data();
		$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
		$this->assertGreaterThan( 0, $booking_id );

		$booking = get_post( $booking_id );
		$this->assertSame( $admin_id, (int) $booking->post_author );
		$this->assertSame( '', (string) get_post_meta( $booking_id, '_vkbm_booking_customer_email', true ) );
	}

	public function test_confirmation_allows_same_browser_temporary_data_without_owner_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$_COOKIE[ self::OWNER_COOKIE ] = 'owner123';
		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-04T10:00:00+09:00',
			'2024-02-04T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-04T10:00:00+09:00',
			'2024-02-04T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * #691: 予約確定の備考（memo）が下書きと同じ上限（1000 文字）で切り詰められて保存されることを検証する。
	 */
	public function test_create_booking_truncates_memo(): void {
		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name' => '1000 文字を超える備考は 1000 文字に切り詰められる',
				'memo'                => str_repeat( 'あ', 1500 ),
				'expected'            => str_repeat( 'あ', 1000 ),
			),
			array(
				'test_condition_name' => '1000 文字ちょうどの備考はそのまま保存される（境界値）',
				'memo'                => str_repeat( 'い', 1000 ),
				'expected'            => str_repeat( 'い', 1000 ),
			),
			array(
				'test_condition_name' => '「\」を含む備考は入力どおり保存される',
				'memo'                => 'A\\B',
				'expected'            => 'A\\B',
			),
			array(
				'test_condition_name' => '上限以内の備考はそのまま保存される',
				'memo'                => 'アレルギーあり',
				'expected'            => 'アレルギーあり',
			),
		);

		$start = '2024-07-01T10:00:00+09:00';
		$end   = '2024-07-01T10:30:00+09:00';

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();
			$user_id  = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_memo';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			$request->set_param( 'memo', $case['memo'] );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );

			$data       = $response->get_data();
			$booking_id = isset( $data['booking_id'] ) ? (int) $data['booking_id'] : 0;
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
			$this->assertSame(
				$case['expected'],
				(string) get_post_meta( $booking_id, '_vkbm_booking_note', true ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * #691: vkbm_draft_memo_max_length フィルターで上限を変えると、予約確定側の備考もその文字数で切れる。
	 */
	public function test_create_booking_truncates_memo_with_filtered_limit(): void {
		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name' => '上限を 10 にすると 10 文字に切り詰められる',
				'memo'                => str_repeat( 'あ', 30 ),
				'expected'            => str_repeat( 'あ', 10 ),
			),
			array(
				'test_condition_name' => '上限ちょうど（10 文字）はそのまま保存される',
				'memo'                => str_repeat( 'い', 10 ),
				'expected'            => str_repeat( 'い', 10 ),
			),
		);

		$start  = '2024-07-02T10:00:00+09:00';
		$end    = '2024-07-02T10:30:00+09:00';
		$filter = static function () {
			return 10;
		};
		add_filter( 'vkbm_draft_memo_max_length', $filter );

		try {
			foreach ( $test_cases as $case ) {
				$menu_id  = $this->create_menu();
				$staff_id = $this->create_staff();
				wp_set_current_user( $this->factory()->user->create() );
				$_COOKIE[ self::OWNER_COOKIE ] = 'owner_memo_filter';

				$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
				$controller = $this->build_controller( $staff_id, $start, $end );
				$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
				$request->set_param( 'token', $token );
				$request->set_param( 'agree_terms', true );
				$request->set_param( 'memo', $case['memo'] );

				$response = $controller->create_booking( $request );
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
				$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
				$this->assertSame(
					$case['expected'],
					(string) get_post_meta( $booking_id, '_vkbm_booking_note', true ),
					$case['test_condition_name']
				);
			}
		} finally {
			remove_filter( 'vkbm_draft_memo_max_length', $filter );
		}
	}

	public function test_confirmation_rejects_temporary_data_without_owner_cookie(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		$token = $this->store_temporary_reservation_data(
			$menu_id,
			$staff_id,
			'2024-02-05T10:00:00+09:00',
			'2024-02-05T10:30:00+09:00'
		);

		$controller = $this->build_controller(
			$staff_id,
			'2024-02-05T10:00:00+09:00',
			'2024-02-05T10:30:00+09:00'
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'forbidden_draft', $response->get_error_code() );
	}

	/**
	 * create_booking: 自動割当で人数が複数スタッフへ分割保存されることを検証する（要望Dの中核）。
	 */
	public function test_create_booking_auto_assignment_single_staff(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		$this->disable_nomination();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-08-01T10:00:00+09:00';
		$end   = '2026-08-01T11:00:00+09:00';

		// 自動割当（resource_id=0）・6人の下書きを作成する。
		// 1予約は分割せず単一スタッフに割り当てるため、人数は max_capacity(5) にクランプされる。
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => 0,
			'guests'               => 6,
			'slot'                 => array(
				'slot_id'  => 'auto-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'assignable_staff_ids' => array( $staff_a, $staff_b ),
			'meta'                 => array( 'timezone' => 'Asia/Tokyo' ),
		);
		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		// 2スタッフの自動割当スロット（capacity = max_capacity = 5、remaining = 最も空きの大きい単一スタッフの残り = 5）。
		$availability = new Availability_Service_Test_Double(
			array(
				'slot_id'              => 'auto-1',
				'start_at'             => $start,
				'end_at'               => $end,
				'service_end_at'       => $end,
				'staff'                => null,
				'assignable_staff_ids' => array( $staff_a, $staff_b ),
				'auto_assign'          => true,
				'capacity'             => 5,
				'remaining'            => 5,
			)
		);
		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			$availability
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
		$this->assertGreaterThan( 0, $booking_id );

		// 6人は max_capacity(5) にクランプされ、単一スタッフ A（空きが同じため候補順で先頭）へ割り当てられる。
		$this->assertSame( 5, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), '人数は max_capacity(5) にクランプされるべき' );
		$this->assertSame( $staff_a, (int) get_post_meta( $booking_id, '_vkbm_booking_resource_id', true ), '単一スタッフ A に割り当てられるべき' );
		$this->assertSame(
			'',
			(string) get_post_meta( $booking_id, '_vkbm_booking_staff_distribution', true ),
			'分配メタは保存されないべき（分割しない）'
		);
	}

	/**
	 * check_capacity_with_mutex: 指名を使うメニューでは、自動割当（指名なし）でも
	 * 既に予約があるスタッフを候補から除外し、1対1（1枠1組）を担保することを検証する（#392）。
	 *
	 * 指名を使うメニューは常に1枠1組（貸切）のため、max_capacity が2以上でも既に予約が
	 * あるスタッフとは相乗りさせない。ベストフィット（残りが最も少ないスタッフを優先）だけに
	 * 頼ると、負荷のあるスタッフAの方が残りが少なく「きつきつ」に見えて優先されてしまうため
	 * （max_capacity=3・Aの負荷=1 → A残り2 / Bの負荷=0 → B残り3。ベストフィットは残り最小のAを選ぶ）、
	 * `check_capacity_with_mutex()` は候補フィルタの時点でAを除外する必要がある。
	 * この判定が壊れると、指名を使うメニューでもAへ相乗りが成立してしまう
	 * （フロントの表示は迂回できるため、確定側のこの分岐だけが唯一の防御）。
	 */
	public function test_check_capacity_with_mutex_excludes_booked_staff_for_nomination_menu(): void {
		// 指名機能は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名機能は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();

		// サイト全体の指名機能をON。このメニューは指名を使う（既定＝使う。_vkbm_disable_nomination は保存しない）。
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$start = '2026-07-01 10:00:00';
		$end   = '2026-07-01 11:00:00';

		// スタッフAは既に同じ時間帯・同じメニューに1名の予約がある（負荷1）。
		$existing_booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		// 検索側（has_staff_conflict() 等）は format_datetime_for_storage() で
		// サイトのタイムゾーンへ変換してから比較するため、書き込むダミーデータも
		// ここを通して同じ基準に揃える（#406。揃えないと TZ=Asia/Tokyo 環境でのみ
		// 9時間ずれて重複が検出されず、このテストが失敗する）。
		update_post_meta( $existing_booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $existing_booking_id, '_vkbm_booking_service_start', $this->to_storage_datetime( $start ) );
		update_post_meta( $existing_booking_id, '_vkbm_booking_service_end', $this->to_storage_datetime( $end ) );
		update_post_meta( $existing_booking_id, '_vkbm_booking_total_end', $this->to_storage_datetime( $end ) );
		update_post_meta( $existing_booking_id, '_vkbm_booking_resource_id', $staff_a );
		update_post_meta( $existing_booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $existing_booking_id, '_vkbm_booking_guests', 1 );

		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository()
		);

		$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_capacity_with_mutex' );
		$method->setAccessible( true );

		// 自動割当（指名なし。is_staff_preferred=false）・候補 [A, B]・1名の申し込み。
		$result = $method->invoke( $controller, $menu_id, 0, $start, $end, 1, false, array( $staff_a, $staff_b ), false );

		if ( $result instanceof WP_Error ) {
			$this->fail( 'check_capacity_with_mutex が WP_Error を返した: ' . $result->get_error_message() );
		}

		$this->assertIsArray( $result, '指名を使うメニューでも空いているスタッフBへ割当できるべき' );
		$this->assertSame(
			$staff_b,
			$result['staff_id'],
			'#392: 指名を使うメニューは1枠1組のため、既に予約がある A ではなく空いている B へ割り当てられるべき'
		);

		// メソッドの契約どおり、呼び出し側でロックを解放する。
		$release = new ReflectionMethod( Booking_Confirmation_Controller::class, 'release_slot_mutex' );
		$release->setAccessible( true );
		$release->invoke( $controller, $result['name'], $result['token'] );
	}

	/**
	 * get_staff_summaries_for_slot: スロット内の既存予約からスタッフ別の担当人数を集計する処理を検証する。
	 *
	 * 1予約は単一スタッフに割り当てられるため、担当スタッフ（resource_id）へ全人数を計上する
	 * （人数メタが無い予約は1名扱い）。旧データの分配メタ（_vkbm_booking_staff_distribution）は
	 * 参照せず無視する。キャンセル・無断キャンセル予約は集計から除外する。
	 *
	 * Verify per-staff load aggregation: each booking counts its full guest count toward its single
	 * staff (resource_id); missing guest meta counts as 1. The legacy distribution meta is ignored.
	 * Cancelled / no-show bookings are excluded.
	 */
	public function test_get_staff_summaries_for_slot(): void {
		$staff_a    = $this->create_staff();
		$staff_b    = $this->create_staff();
		$slot_start = '2024-05-01 10:00:00';
		$slot_end   = '2024-05-01 11:00:00';

		$controller = $this->build_controller( $staff_a, $slot_start, $slot_end );
		$method     = new ReflectionMethod( Booking_Confirmation_Controller::class, 'get_staff_summaries_for_slot' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'レガシー予約（配分メタなし・代表 A・人数メタ3） => A に3名計上（正常系・後方互換）',
				'bookings'            => array(
					array(
						'status'      => 'confirmed',
						'resource_id' => $staff_a,
						'guests'      => 3,
					),
				),
				'expected'            => array( $staff_a => 3 ),
			),
			array(
				'test_condition_name' => 'レガシー予約（配分メタ・人数メタともになし・代表 A） => A に1名計上（境界値・後方互換）',
				'bookings'            => array(
					array(
						'status'      => 'confirmed',
						'resource_id' => $staff_a,
					),
				),
				'expected'            => array( $staff_a => 1 ),
			),
			array(
				'test_condition_name' => '旧分配メタ（A:5 / B:1）付き予約 => 分配メタは無視し代表 A に全人数6を計上（正常系・後方互換）',
				'bookings'            => array(
					array(
						'status'       => 'confirmed',
						'resource_id'  => $staff_a,
						'guests'       => 6,
						'distribution' => array(
							array(
								'staff_id' => $staff_a,
								'guests'   => 5,
							),
							array(
								'staff_id' => $staff_b,
								'guests'   => 1,
							),
						),
					),
				),
				'expected'            => array(
					$staff_a => 6,
				),
			),
			array(
				'test_condition_name' => 'キャンセル済みレガシー予約 => 残数集計から除外され空（異常系）',
				'bookings'            => array(
					array(
						'status'      => 'cancelled',
						'resource_id' => $staff_a,
						'guests'      => 3,
					),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '無断キャンセル（no_show）レガシー予約 => 残数集計から除外され空（異常系）',
				'bookings'            => array(
					array(
						'status'      => 'no_show',
						'resource_id' => $staff_a,
						'guests'      => 2,
					),
				),
				'expected'            => array(),
			),
		);

		$start_storage = $this->to_storage_datetime( $slot_start );
		$end_storage   = $this->to_storage_datetime( $slot_end );

		foreach ( $test_cases as $case ) {
			// ケース毎にメニューを分け、対象スロットの予約だけを集計対象にする。
			$menu_id = $this->create_menu();

			foreach ( $case['bookings'] as $booking ) {
				$booking_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Booking_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);

				update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
				update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_storage );
				update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_storage );
				update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_storage );
				update_post_meta( $booking_id, '_vkbm_booking_status', $booking['status'] );
				update_post_meta( $booking_id, '_vkbm_booking_resource_id', $booking['resource_id'] );

				if ( isset( $booking['guests'] ) ) {
					update_post_meta( $booking_id, '_vkbm_booking_guests', $booking['guests'] );
				}
				if ( isset( $booking['distribution'] ) ) {
					update_post_meta( $booking_id, '_vkbm_booking_staff_distribution', $booking['distribution'] );
				}
			}

			// 担当人数（同一メニューの合計）だけを取り出して比較する。ケース間で同じスタッフ・枠を使い回すため、
			// 前のケースの別メニュー予約だけを持つスタッフ（担当人数0）は比較から外す。
			$summaries = $method->invoke( $controller, $menu_id, $slot_start, $slot_end );
			$this->assertSame(
				$case['expected'],
				array_filter(
					array_map(
						static function ( array $summary ): int {
							return $summary['same_menu_guests'];
						},
						$summaries
					)
				),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 確定時点の設定と予約者の権限に従って利用者名を保存・検証する。
	 */
	public function test_create_booking_attendee_name(): void {
		$repository = new Settings_Repository();
		$menu_id    = $this->create_menu();
		$staff_id   = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$test_cases = array(
			array(
				'test_condition_name' => '任意・会員・値あり => 保存する',
				'mode'                => 'optional',
				'manager'             => false,
				'draft_value'         => '利用者花子',
				'request_value'       => null,
				'expected'            => '利用者花子',
				'error'               => '',
			),
			array(
				'test_condition_name' => '必須・会員・空 => エラー',
				'mode'                => 'required',
				'manager'             => false,
				'draft_value'         => '',
				'request_value'       => null,
				'expected'            => '',
				'error'               => 'attendee_name_required',
			),
			array(
				'test_condition_name' => '必須・代理予約・空 => 保存できる',
				'mode'                => 'required',
				'manager'             => true,
				'draft_value'         => '',
				'request_value'       => null,
				'expected'            => '',
				'error'               => '',
			),
			array(
				'test_condition_name' => '無効・送信値あり => 保存しない',
				'mode'                => 'disabled',
				'manager'             => false,
				'draft_value'         => '下書き値',
				'request_value'       => '改ざん値',
				'expected'            => '',
				'error'               => '',
			),
			array(
				'test_condition_name' => '下書き後に必須へ変更・空 => 確定時にエラー',
				'mode'                => 'required',
				'manager'             => false,
				'draft_value'         => '',
				'request_value'       => null,
				'expected'            => '',
				'error'               => 'attendee_name_required',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$settings                               = $repository->get_settings();
			$settings['booking_attendee_name_mode'] = $case['mode'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			$user_id = $case['manager'] ? $this->create_admin_user() : $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$day = 10 + $index;

			$start = sprintf( '2027-01-%02dT10:00:00+09:00', $day );

			$end = sprintf( '2027-01-%02dT10:30:00+09:00', $day );

			$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );

			$draft                  = get_transient( self::TRANSIENT_PREFIX . $token );
			$draft['attendee_name'] = $case['draft_value'];
			$draft['owner_user_id'] = $user_id;
			set_transient( self::TRANSIENT_PREFIX . $token, $draft );
			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			if ( $case['manager'] ) {
				$request->set_param( 'customer_name', '代理予約のお客様' );
			}
			if ( null !== $case['request_value'] ) {
				$request->set_param( 'attendee_name', $case['request_value'] );
			}
			$response = $this->build_controller( $staff_id, $start, $end )->create_booking( $request );
			if ( '' !== $case['error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['error'], $response->get_error_code(), $case['test_condition_name'] );
				continue;
			}
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ( is_wp_error( $response ) ? ': ' . $response->get_error_code() : '' ) );
			$booking_id = (int) $response->get_data()['booking_id'];
			$this->assertSame( $case['expected'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_name', true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 名簿機能を有効にした設定を保存する。
	 *
	 * @param array<string,mixed> $override 上書きする設定。
	 */
	private function enable_attendee_roster( array $override = [] ): void {
		$settings = array_merge(
			( new Settings_Repository() )->get_settings(),
			[
				'attendee_roster_enabled'   => true,
				'attendee_roster_show_self' => true,
				'attendee_number_enabled'   => true,
			],
			$override
		);
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * 予約確定のリクエストを組み立てて実行する。
	 *
	 * @param int                 $user_id  ログインさせるユーザー。
	 * @param int                 $menu_id  メニュー ID。
	 * @param int                 $staff_id スタッフ ID。
	 * @param string              $day      日付（Y-m-d）。
	 * @param array<string,mixed> $params   リクエストの追加パラメーター。
	 * @return WP_REST_Response|WP_Error 予約確定の結果。
	 */
	private function create_booking_with_params( int $user_id, int $menu_id, int $staff_id, string $day, array $params ) {
		wp_set_current_user( $user_id );
		$start = $day . 'T10:00:00+09:00';
		$end   = $day . 'T10:30:00+09:00';
		$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );

		$draft                  = get_transient( self::TRANSIENT_PREFIX . $token );
		$draft['owner_user_id'] = $user_id;
		set_transient( self::TRANSIENT_PREFIX . $token, $draft );

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->build_controller( $staff_id, $start, $end )->create_booking( $request );
	}

	/**
	 * 名簿の選択を、予約者の名簿と照合して予約へ保存する。
	 */
	public function test_create_booking_attendee_roster(): void {
		$this->enable_attendee_roster();
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$roster   = new Attendee_Roster();

		$family_id = '11111111-1111-4111-8111-111111111111';
		$others_id = '22222222-2222-4222-8222-222222222222';
		$new_id    = '33333333-3333-4333-8333-333333333333';
		$user_id   = $this->factory()->user->create(
			[
				'first_name' => '一郎',
				'last_name'  => '山田',
			]
		);
		$other_id  = $this->factory()->user->create();
		$roster->save(
			$user_id,
			[
				[ 'id' => 'self', 'name' => '山田 一郎', 'kana' => '', 'number' => 'S-1' ],
				[ 'id' => $family_id, 'name' => 'ポチ', 'kana' => '', 'number' => 'P-2' ],
			]
		);
		$roster->save(
			$other_id,
			[
				[ 'id' => 'self', 'name' => '他人', 'kana' => '', 'number' => '' ],
				[ 'id' => $others_id, 'name' => '他人のペット', 'kana' => '', 'number' => 'O-9' ],
			]
		);

		$test_cases = [
			[
				'test_condition_name' => '名簿の人を選ぶ => 名簿の名前と番号が予約に保存される',
				'params'              => [ 'attendee_id' => $family_id, 'attendee_name' => '改ざん', 'attendee_number' => '999' ],
				'expected'            => [ 'id' => $family_id, 'name' => 'ポチ', 'number' => 'P-2' ],
			],
			[
				'test_condition_name' => '本人を選ぶ => self とプロフィールの名前・名簿の番号が保存される',
				'params'              => [ 'attendee_id' => 'self' ],
				'expected'            => [ 'id' => 'self', 'name' => '一郎 山田', 'number' => 'S-1' ],
			],
			[
				'test_condition_name' => '名簿に無い人を手入力 => manual と名前だけが保存される',
				'params'              => [ 'attendee_id' => 'manual', 'attendee_name' => '知人の犬', 'attendee_number' => 'X-1' ],
				'expected'            => [ 'id' => 'manual', 'name' => '知人の犬', 'number' => '' ],
			],
			[
				'test_condition_name' => '手入力して名簿に保存 => 指定した UUID が ID になり名簿にも入る',
				'params'              => [ 'attendee_id' => 'manual', 'attendee_name' => '新しい子', 'attendee_new_id' => $new_id, 'attendee_save_to_roster' => true ],
				'expected'            => [ 'id' => $new_id, 'name' => '新しい子', 'number' => '' ],
			],
			[
				'test_condition_name' => '同じ UUID で再度保存 => 名簿は増えず同じ ID で予約される',
				'params'              => [ 'attendee_id' => 'manual', 'attendee_name' => '新しい子', 'attendee_new_id' => $new_id, 'attendee_save_to_roster' => true ],
				'expected'            => [ 'id' => $new_id, 'name' => '新しい子', 'number' => '' ],
			],
			[
				'test_condition_name' => '他人の名簿の UUID => 確定しない',
				'params'              => [ 'attendee_id' => $others_id ],
				'error'               => 'attendee_not_found',
			],
			[
				'test_condition_name' => '存在しない UUID => 確定しない',
				'params'              => [ 'attendee_id' => '99999999-9999-4999-8999-999999999999' ],
				'error'               => 'attendee_not_found',
			],
			[
				'test_condition_name' => '手入力の名前が空 => 確定しない',
				'params'              => [ 'attendee_id' => 'manual', 'attendee_name' => '' ],
				'error'               => 'attendee_name_required',
			],
		];

		foreach ( $test_cases as $index => $case ) {
			$before   = $this->count_booking_posts();
			$response = $this->create_booking_with_params( $user_id, $menu_id, $staff_id, sprintf( '2027-03-%02d', 1 + $index ), $case['params'] );
			if ( isset( $case['error'] ) ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['error'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( $before, $this->count_booking_posts(), $case['test_condition_name'] . '（予約は作られない）' );
				continue;
			}
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ( is_wp_error( $response ) ? ': ' . $response->get_error_code() : '' ) );
			$booking_id = (int) $response->get_data()['booking_id'];
			$this->assertSame( $case['expected']['id'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_id', true ), $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected']['name'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_name', true ), $case['test_condition_name'] . '（名前）' );
			$this->assertSame( $case['expected']['number'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_number', true ), $case['test_condition_name'] . '（番号）' );
		}

		// 保存した新しい子は名簿に1件だけ入り、他人の名簿は変わらない。
		$this->assertSame( [ 'self', $family_id, $new_id ], array_column( $roster->get( $user_id ), 'id' ), '名簿には新しい子が1件だけ増える' );
		$this->assertSame( [ 'self', $others_id ], array_column( $roster->get( $other_id ), 'id' ), '他人の名簿は変わらない' );
	}

	/**
	 * 予約が失敗しても、先に保存した名簿の登録は残る。
	 */
	public function test_create_booking_keeps_roster_when_booking_fails(): void {
		$this->enable_attendee_roster();
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();
		$new_id   = '55555555-5555-4555-8555-555555555555';

		// 同じ会員が同じ時間に予約済みで、確定は時間の重複で失敗する。
		$this->create_booking_post( $user_id, $staff_id, '2027-04-01T10:00:00+09:00', '2027-04-01T10:30:00+09:00' );
		$response = $this->create_booking_with_params(
			$user_id,
			$menu_id,
			$staff_id,
			'2027-04-01',
			[
				'attendee_id'             => 'manual',
				'attendee_name'           => '残る子',
				'attendee_new_id'         => $new_id,
				'attendee_save_to_roster' => true,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $response, '時間が重複して確定は失敗する' );
		$this->assertSame( 'booking_time_conflict', $response->get_error_code() );
		$this->assertContains( $new_id, array_column( ( new Attendee_Roster() )->get( $user_id ), 'id' ), '名簿の登録は残る' );
	}

	/**
	 * 代理予約では、電話番号が一致した会員の名簿と照合し、会員の名簿へ保存できる。
	 */
	public function test_create_booking_attendee_roster_proxy(): void {
		$this->enable_attendee_roster();
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$roster   = new Attendee_Roster();

		$family_id = '11111111-1111-4111-8111-111111111111';
		$others_id = '77777777-7777-4777-8777-777777777777';
		$new_id    = '66666666-6666-4666-8666-666666666666';
		$member_id = $this->factory()->user->create( [ 'user_email' => 'member-proxy@example.com' ] );
		$other_id  = $this->factory()->user->create();
		$phone     = '090-3333-4444';
		update_user_meta( $member_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );
		$roster->save(
			$member_id,
			[
				[ 'id' => 'self', 'name' => '会員', 'kana' => '', 'number' => 'M-0' ],
				[ 'id' => $family_id, 'name' => '会員のペット', 'kana' => '', 'number' => 'M-5' ],
			]
		);
		$roster->save(
			$other_id,
			[
				[ 'id' => 'self', 'name' => '他人', 'kana' => '', 'number' => '' ],
				[ 'id' => $others_id, 'name' => '他人のペット', 'kana' => '', 'number' => '' ],
			]
		);
		$admin_id = $this->create_admin_user();

		$test_cases = [
			[
				'test_condition_name' => '一致した会員の名簿の人を選ぶ => 名簿の名前と番号が保存される',
				'params'              => [ 'customer_name' => '代理のお客様', 'customer_phone' => $phone, 'attendee_id' => $family_id ],
				'expected'            => [ 'id' => $family_id, 'name' => '会員のペット', 'number' => 'M-5' ],
			],
			[
				'test_condition_name' => '一致しない会員の UUID を選ぶ => 確定しない',
				'params'              => [ 'customer_name' => '代理のお客様', 'customer_phone' => $phone, 'attendee_id' => $others_id ],
				'error'               => 'attendee_not_found',
			],
			[
				'test_condition_name' => '手入力して会員の名簿に保存 => 会員の名簿に入り、番号も保存される',
				'params'              => [ 'customer_name' => '代理のお客様', 'customer_phone' => $phone, 'attendee_id' => 'manual', 'attendee_name' => '代理で追加', 'attendee_number' => 'M-6', 'attendee_new_id' => $new_id, 'attendee_save_to_roster' => true ],
				'expected'            => [ 'id' => $new_id, 'name' => '代理で追加', 'number' => 'M-6' ],
			],
			[
				'test_condition_name' => '一致した会員の代理予約で空のまま確定 => manual（名前なし）が入り、本人の番号は出ない',
				'params'              => [ 'customer_name' => '代理のお客様', 'customer_phone' => $phone ],
				'expected'            => [ 'id' => 'manual', 'name' => '', 'number' => '' ],
				'display_number'      => '',
			],
			[
				'test_condition_name' => '電話番号が一致しない代理予約で手入力 => manual で保存される',
				'params'              => [ 'customer_name' => '代理のお客様', 'customer_phone' => '', 'attendee_id' => 'manual', 'attendee_name' => '飛び込みの子' ],
				'expected'            => [ 'id' => 'manual', 'name' => '飛び込みの子', 'number' => '' ],
			],
		];

		foreach ( $test_cases as $index => $case ) {
			$response = $this->create_booking_with_params( $admin_id, $menu_id, $staff_id, sprintf( '2027-05-%02d', 1 + $index ), $case['params'] );
			if ( isset( $case['error'] ) ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['error'], $response->get_error_code(), $case['test_condition_name'] );
				continue;
			}
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ( is_wp_error( $response ) ? ': ' . $response->get_error_code() : '' ) );
			$booking_id = (int) $response->get_data()['booking_id'];
			$this->assertSame( $case['expected']['id'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_id', true ), $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected']['name'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_name', true ), $case['test_condition_name'] . '（名前）' );
			$this->assertSame( $case['expected']['number'], (string) get_post_meta( $booking_id, '_vkbm_booking_attendee_number', true ), $case['test_condition_name'] . '（番号）' );
			if ( isset( $case['display_number'] ) ) {
				$this->assertSame( $case['display_number'], \VKBookingManager\Attendees\Booking_Attendee_Display::get( $booking_id )['number'], $case['test_condition_name'] . '（一覧の番号）' );
			}
		}

		$this->assertSame( [ 'self', $family_id, $new_id ], array_column( $roster->get( $member_id ), 'id' ), '会員の名簿に1件だけ増える' );
	}

	/**
	 * 選択肢の取得は、会員自身の名簿・代理予約で一致した会員の名簿を返す。
	 */
	public function test_get_booking_attendee_options(): void {
		$this->enable_attendee_roster();
		$staff_id  = $this->create_staff();
		$family_id = '11111111-1111-4111-8111-111111111111';
		$member_id = $this->factory()->user->create(
			[
				'first_name' => '一郎',
				'last_name'  => '山田',
			]
		);
		$phone     = '090-5555-6666';
		update_user_meta( $member_id, 'phone_number', VKBM_Helper::normalize_phone_number( $phone ) );
		( new Attendee_Roster() )->save(
			$member_id,
			[
				[ 'id' => 'self', 'name' => '山田 一郎', 'kana' => '', 'number' => 'S-1' ],
				[ 'id' => $family_id, 'name' => 'ポチ', 'kana' => '', 'number' => 'P-2' ],
			]
		);
		$admin_id = $this->create_admin_user();

		$test_cases = [
			[
				'test_condition_name' => '会員自身 => 自分の名簿を返す',
				'user_id'             => $member_id,
				'phone'               => null,
				'expected_ids'        => [ 'self', $family_id ],
			],
			[
				'test_condition_name' => '代理予約で電話番号が一致 => その会員の名簿を返す',
				'user_id'             => $admin_id,
				'phone'               => $phone,
				'expected_ids'        => [ 'self', $family_id ],
			],
			[
				'test_condition_name' => '代理予約で電話番号が未入力 => 選択肢は空',
				'user_id'             => $admin_id,
				'phone'               => null,
				'expected_ids'        => [],
			],
			[
				'test_condition_name' => '代理予約で電話番号が一致しない => 選択肢は空',
				'user_id'             => $admin_id,
				'phone'               => '090-0000-0000',
				'expected_ids'        => [],
			],
		];

		$controller = $this->build_controller( $staff_id, '2027-06-01T10:00:00+09:00', '2027-06-01T10:30:00+09:00' );
		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$request = new WP_REST_Request( 'GET', '/vkbm/v1/booking-attendees' );
			if ( null !== $case['phone'] ) {
				$request->set_param( 'customer_phone', $case['phone'] );
			}
			$data = $controller->get_booking_attendee_options( $request )->get_data();
			$this->assertTrue( $data['enabled'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_ids'], array_column( $data['items'], 'id' ), $case['test_condition_name'] );
		}

		// 名簿機能が無効なら enabled だけを返す。
		$this->enable_attendee_roster( [ 'attendee_roster_enabled' => false ] );
		wp_set_current_user( $member_id );
		$data = $controller->get_booking_attendee_options( new WP_REST_Request( 'GET', '/vkbm/v1/booking-attendees' ) )->get_data();
		$this->assertSame( [ 'enabled' => false ], $data, '名簿機能が無効 => enabled が false' );
	}

	/**
	 * 一般の利用者の予約が、基本設定の予約ステータスに応じた初期ステータスと通知で保存されることを検証する（#924）。
	 */
	public function test_create_booking_initial_status_by_settings(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		$user_id  = $this->factory()->user->create();

		$test_cases = [
			[
				'test_condition_name' => '基本設定が未保存の新規サイト => pending で保存され、仮予約の通知が送られる',
				'saved_option'        => null,
				'expected_status'     => 'pending',
				'expected_calls'      => [ 'pending' ],
			],
			[
				'test_condition_name' => '保存済みで予約ステータスのキーが無い既存サイト => confirmed で保存され、確定の通知が送られる',
				'saved_option'        => [ 'provider_name' => 'テスト店舗' ],
				'expected_status'     => 'confirmed',
				'expected_calls'      => [ 'confirmed' ],
			],
			[
				'test_condition_name' => '予約ステータスが pending => pending で保存され、仮予約の通知が送られる',
				'saved_option'        => [ 'provider_booking_status_mode' => 'pending' ],
				'expected_status'     => 'pending',
				'expected_calls'      => [ 'pending' ],
			],
			[
				'test_condition_name' => '予約ステータスが confirmed => confirmed で保存され、確定の通知が送られる',
				'saved_option'        => [ 'provider_booking_status_mode' => 'confirmed' ],
				'expected_status'     => 'confirmed',
				'expected_calls'      => [ 'confirmed' ],
			],
		];

		foreach ( $test_cases as $index => $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['saved_option'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['saved_option'] );
			}

			// 一般の利用者として、通知の呼び出しを記録するコントローラーで予約する。
			wp_set_current_user( $user_id );
			$day   = sprintf( '2027-05-%02d', 1 + $index );
			$start = $day . 'T10:00:00+09:00';
			$end   = $day . 'T10:30:00+09:00';
			$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$draft = get_transient( self::TRANSIENT_PREFIX . $token );
			$draft['owner_user_id'] = $user_id;
			set_transient( self::TRANSIENT_PREFIX . $token, $draft );

			$notification = new Booking_Notification_Service_Recording_Double();
			$controller   = new Booking_Confirmation_Controller(
				$notification,
				new Settings_Repository(),
				new Availability_Service_Test_Double(
					[
						'slot_id'              => 'slot-1',
						'start_at'             => $start,
						'end_at'               => $end,
						'service_end_at'       => $end,
						'staff'                => [ 'id' => $staff_id ],
						'assignable_staff_ids' => [ $staff_id ],
					]
				)
			);

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			$response = $controller->create_booking( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ( is_wp_error( $response ) ? ': ' . $response->get_error_code() : '' ) );
			$booking_id = (int) $response->get_data()['booking_id'];
			$this->assertSame( $case['expected_status'], (string) get_post_meta( $booking_id, '_vkbm_booking_status', true ), $case['test_condition_name'] . '（保存されたステータス）' );
			$this->assertSame( $case['expected_calls'], $notification->calls, $case['test_condition_name'] . '（通知）' );
		}
	}

	private function build_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		$notification_service = new Booking_Notification_Service_Test_Double();
		$settings_repository  = new Settings_Repository();
		$availability_service = new Availability_Service_Test_Double(
			[
				'slot_id'              => 'slot-1',
				'start_at'             => $start_at,
				'end_at'               => $end_at,
				'service_end_at'       => $end_at,
				'staff'                => [
					'id' => $staff_id,
				],
				'assignable_staff_ids' => [ $staff_id ],
			]
		);

		return new Booking_Confirmation_Controller(
			$notification_service,
			$settings_repository,
			$availability_service
		);
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	private function create_admin_user(): int {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
		}
		return (int) $user_id;
	}

	private function create_booking_post( int $author_id, int $staff_id, string $start, string $end ): int {
		$booking_id = (int) $this->factory()->post->create(
			[
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			]
		);

		$start_storage = $this->to_storage_datetime( $start );
		$end_storage   = $this->to_storage_datetime( $end );

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_storage );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}

	/**
	 * テスト用のダミー日時文字列を、製品コードの format_datetime_for_storage()（内部で
	 * strtotime() → wp_date() を使う）と同じ基準（サイトのタイムゾーン）へ変換して返す（#406）。
	 *
	 * has_staff_conflict() など予約の重複を検索する側は、渡された日時をこの基準で
	 * 変換してから比較する。書き込むダミーデータの基準がこれとズレていると、
	 * サイトの現在のタイムゾーンが UTC 以外（Asia/Tokyo 等）の環境でのみ重複判定が
	 * 一致せずテストが失敗する（テスト実行環境の TZ に依存する不具合になる）。
	 * このヘルパーを通すことで、このファイル内のテストが同じ落とし穴を踏まないようにする。
	 *
	 * 製品コードの format_datetime_for_storage() は解釈に失敗すると空文字を返して呼び出し元へ
	 * 処理を委ねるが、このヘルパーはテスト用ダミーデータの組み立てにしか使わないため、
	 * 解釈に失敗した場合は空文字を返さず $this->fail() でテストを失敗させる。空文字のまま
	 * 後続の比較へ進ませると、型エラーやアサーション不一致として現れて原因が追いにくくなるため、
	 * 日時文字列の打ち間違いだと分かる形でここで止める。
	 *
	 * @param string $value 変換対象の日時文字列（例: '2026-07-01 10:00:00'）。
	 * @return string サイトのタイムゾーンへ変換した Y-m-d H:i:s 形式の文字列。
	 */
	private function to_storage_datetime( string $value ): string {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			$this->fail( sprintf( 'to_storage_datetime(): 日時文字列を解釈できませんでした: "%s"', $value ) );
		}
		return wp_date( 'Y-m-d H:i:s', (int) $timestamp );
	}

	private function store_temporary_reservation_data(
		int $menu_id,
		int $staff_id,
		string $start_at,
		string $end_at,
		int $guests = 1,
		bool $is_staff_preferred = false
	): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = [
			'menu_id'      => $menu_id,
			'resource_id'  => $staff_id,
			'guests'       => $guests,
			// #391: 指名の有無を下書きペイロードで再現できるようにする（メニュー単位の指名機能ゲートのテスト用）。
			'is_staff_preferred' => $is_staff_preferred,
			'slot'         => [
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			],
			'assignable_staff_ids' => [ $staff_id ],
			'meta'         => [
				'timezone' => 'Asia/Tokyo',
			],
		];

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * create_booking: 単一スタッフ自動割当での複数人予約（人数の解決・料金の人数倍・クランプ・満枠エラー）を検証する。
	 *
	 * 指名OFF・スタッフ1名のメニューに対し、要求人数に応じて人数・基本料金合計が保存されること、
	 * 最大受付数を超える人数はクランプされること、複数人予約無効時は1名へフォールバックすること、
	 * 残数を超える人数は capacity_exceeded エラーになることを確認する。
	 */
	public function test_create_booking_resolves_guests(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name'         => '複数人許可・最大4・要求3 => 人数3 / 単価1000 / 基本料金 1000×3=3000（正常系）',
				'date'                        => '2024-05-01',
				'allow_multiple'              => true,
				'max_capacity'                => 4,
				'base_price'                  => 1000,
				'requested_guests'            => 3,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 3,
				'expected_service_base_price' => 1000,
				'expected_base_total'         => 3000,
			),
			array(
				'test_condition_name'         => '複数人許可・最大2・要求5 => 最大2にクランプ / 基本料金 1000×2=2000（境界値）',
				'date'                        => '2024-05-02',
				'allow_multiple'              => true,
				'max_capacity'                => 2,
				'base_price'                  => 1000,
				'requested_guests'            => 5,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 2,
				'expected_service_base_price' => null,
				'expected_base_total'         => 2000,
			),
			array(
				'test_condition_name'         => '複数人無効・要求4 => 1名へフォールバック（後方互換・境界値）',
				'date'                        => '2024-05-03',
				'allow_multiple'              => false,
				'max_capacity'                => null,
				'base_price'                  => 1000,
				'requested_guests'            => 4,
				'existing_guests'             => 0,
				'expect_error'                => null,
				'expected_guests'             => 1,
				'expected_service_base_price' => null,
				'expected_base_total'         => null,
			),
			array(
				'test_condition_name'         => '複数人許可・最大3・既存2名 + 今回2名=4>3 => capacity_exceeded（異常系）',
				'date'                        => '2024-05-04',
				'allow_multiple'              => true,
				'max_capacity'                => 3,
				'base_price'                  => 1000,
				'requested_guests'            => 2,
				'existing_guests'             => 2,
				'expect_error'                => 'capacity_exceeded',
				'expected_guests'             => null,
				'expected_service_base_price' => null,
				'expected_base_total'         => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();

			if ( null !== $case['base_price'] ) {
				update_post_meta( $menu_id, '_vkbm_base_price', $case['base_price'] );
			}
			if ( $case['allow_multiple'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
				if ( null !== $case['max_capacity'] ) {
					update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
				}
				// 複数人予約はスタッフ割当が前提のため、メニューにスタッフを割り当てる。
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			}

			$start = $case['date'] . 'T10:00:00+09:00';
			$end   = $case['date'] . 'T10:30:00+09:00';

			// 既存予約で枠を消費する（満枠検証用）。
			if ( $case['existing_guests'] > 0 ) {
				$other_user = $this->factory()->user->create();
				$existing   = $this->create_booking_post( $other_user, $staff_id, $start, $end );
				update_post_meta( $existing, '_vkbm_booking_service_id', $menu_id );
				update_post_meta( $existing, '_vkbm_booking_guests', $case['existing_guests'] );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, $case['requested_guests'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
				wp_set_current_user( 0 );
				continue;
			}

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ),
				$case['test_condition_name']
			);
			if ( null !== $case['expected_service_base_price'] ) {
				$this->assertSame(
					$case['expected_service_base_price'],
					(int) get_post_meta( $booking_id, '_vkbm_booking_service_base_price', true ),
					$case['test_condition_name']
				);
			}
			if ( null !== $case['expected_base_total'] ) {
				$this->assertSame(
					$case['expected_base_total'],
					(int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ),
					$case['test_condition_name']
				);
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 指名を使うメニューの最低申し込み人数（受付制限。#393）をサーバ側で
	 * 権威的に検証することを確認する。フロントの入力欄下限・警告表示は迂回可能なため、
	 * 予約確定時にもサーバ側で申込人数不足を拒否する必要がある。
	 *
	 * - 指名を使う・複数人一括予約ON・予約枠の定員2以上・最低申し込み人数2で1名 => 拒否（異常系）
	 * - 同条件で2名・3名 => 予約が確定できる（正常系：ちょうど／上回る）
	 * - 指名を使わないメニューでは最低申し込み人数の設定に関わらず1名でも確定できる
	 *   （正常系：従来どおり最少催行人数は表示専用のまま変わらない）
	 * - 複数人一括予約OFFのメニューでは最低申し込み人数に2以上が保存されていても1名で確定できる
	 *   （境界値：実効0へのクランプ）
	 */
	public function test_create_booking_rejects_nomination_min_guests(): void {
		// 指名機能・複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名機能・複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name'   => '指名ON・複数人予約ON・定員3・最低2・申込1名 => nomination_min_guests（異常系）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'min_capacity'          => 2,
				'requested_guests'      => 1,
				'expect_error'          => 'nomination_min_guests',
			),
			array(
				'test_condition_name'   => '指名ON・複数人予約ON・定員3・最低2・申込2名 => 確定できる（正常系：ちょうど）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'min_capacity'          => 2,
				'requested_guests'      => 2,
				'expect_error'          => null,
			),
			array(
				'test_condition_name'   => '指名ON・複数人予約ON・定員3・最低2・申込3名 => 確定できる（正常系：上回る）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'min_capacity'          => 2,
				'requested_guests'      => 3,
				'expect_error'          => null,
			),
			array(
				'test_condition_name'   => '指名OFF・複数人予約ON・定員3・最低2・申込1名 => 確定できる（正常系：指名を使わないメニューは従来どおり表示専用）',
				'nomination_enabled'    => false,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'min_capacity'          => 2,
				'requested_guests'      => 1,
				'expect_error'          => null,
			),
			array(
				'test_condition_name'   => '指名ON・複数人予約OFF・定員3・最低2・申込1名 => 確定できる（境界値：複数人予約OFFで実効0へクランプ）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => false,
				'max_capacity'          => 3,
				'min_capacity'          => 2,
				'requested_guests'      => 1,
				'expect_error'          => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                = new Settings_Repository();
			$settings                  = $repository->get_settings();
			$settings['staff_enabled'] = $case['nomination_enabled'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();

			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			update_post_meta( $menu_id, '_vkbm_min_capacity', $case['min_capacity'] );
			if ( $case['allow_multiple_guests'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			}
			// 複数人一括予約はスタッフ割当が前提のため、メニューにスタッフを割り当てる。
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			$start = '2026-08-01 10:00:00';
			$end   = '2026-08-01 10:30:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, $case['requested_guests'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
				$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
				$this->assertSame(
					$case['requested_guests'],
					(int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ),
					$case['test_condition_name']
				);
			}

			wp_set_current_user( 0 );
			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * create_booking: 指名を使うメニューの最低申し込み人数（受付制限。#393）が、
	 * 料金区分（guest_tiers）経路の合計人数に対しても効くことを検証する（安藤レビュー指摘）。
	 *
	 * #392 で指名を使うメニューでも料金区分が使えるようになったため、実際に確定時点へ届く
	 * 人数は下書きの `guests` ではなく guest_tiers の合計人数になる経路がある。この判定は
	 * guests/guest_tiers の合流後（区分内訳の合計人数を再計算した後）に置かれているため、
	 * 単一の guests 経路だけをテストしていると、将来この判定位置が動いても気づけない。
	 * 区分内訳の合計が最低申し込み人数を下回るケースを明示的に検証する。
	 */
	public function test_create_booking_rejects_nomination_min_guests_via_price_tiers(): void {
		// 指名機能・複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名機能・複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => 'Adult',
					'price' => 1000,
				),
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '区分合計1名（最低2未満） => nomination_min_guests（異常系）',
				'date'                => '2026-08-03',
				'stored_tiers'        => array(
					array(
						'label' => 'Adult',
						'price' => 1000,
						'count' => 1,
					),
				),
				'expect_error'        => 'nomination_min_guests',
			),
			array(
				'test_condition_name' => '区分合計2名（最低2ちょうど） => 確定できる（正常系）',
				'date'                => '2026-08-04',
				'stored_tiers'        => array(
					array(
						'label' => 'Adult',
						'price' => 1000,
						'count' => 2,
					),
				),
				'expect_error'        => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$start = $case['date'] . 'T10:00:00+09:00';
			$end   = $case['date'] . 'T10:30:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data_with_tiers( $menu_id, $staff_id, $start, $end, $case['stored_tiers'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
				$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
			}

			wp_set_current_user( 0 );
		}

		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * create_booking: メニュー単位の指名機能設定（_vkbm_disable_nomination、#391）により、
	 * 指名料計算が menu 単位でゲートされることを検証する。
	 *
	 * 下書き（transient）に is_staff_preferred=true・スタッフの指名料が設定されていても、
	 * このメニューで指名機能が無効化されていれば、予約確定時に指名料は必ず0として保存される
	 * （Booking_Confirmation_Controller 側の多層防御。通常フローでは Booking_Draft_Controller が
	 * 下書き保存時点で is_staff_preferred を強制的に false にするが、本テストは下書きの内容を
	 * 直接注入し、確定コントローラ単体でも同じゲートが独立して効くことを確認する）。
	 */
	public function test_create_booking_nomination_fee_gated_by_menu(): void {
		// メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより
		// 検証対象の挙動が無効になる。無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// サイト全体の指名機能はON（既定値）のまま検証する。
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$test_cases = array(
			array(
				'test_condition_name'     => 'メニュー単位の指名設定なし（既定＝使う） => スタッフの指名料1000がそのまま保存される（#391導入前と同じ挙動）',
				'menu_disable_nomination' => false,
				'industry_preset'         => 'custom',
				'expected_nomination_fee' => 1000,
			),
			array(
				'test_condition_name'     => '宿泊のサイトは時間枠のプランでも指名料は0（スタッフに指名料が保存されていても加算しない・#736）',
				'menu_disable_nomination' => false,
				'industry_preset'         => 'lodging',
				'expected_nomination_fee' => 0,
			),
			array(
				'test_condition_name'     => 'サイト全体は指名ONだがこのメニューは指名を無効化 => 指名料は0（#391・確定コントローラ側の多層防御）',
				'menu_disable_nomination' => true,
				'industry_preset'         => 'custom',
				'expected_nomination_fee' => 0,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$settings['industry_preset'] = $case['industry_preset'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Industry_Presets::clear_cache();
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();
			update_post_meta( $staff_id, Staff_Editor::META_NOMINATION_FEE, 1000 );

			if ( $case['menu_disable_nomination'] ) {
				update_post_meta( $menu_id, '_vkbm_disable_nomination', true );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$start = sprintf( '2024-08-%02dT10:00:00+09:00', 10 + $index );
			$end   = sprintf( '2024-08-%02dT10:30:00+09:00', 10 + $index );

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, 1, true );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected_nomination_fee'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_nomination_fee', true ),
				$case['test_condition_name']
			);

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 料金区分（大人料金・子供料金）の合計計算・人数合計・スナップショット保存と、
	 * クライアント改竄に対するサーバ側再計算（セキュリティ回帰）を検証する。
	 *
	 * - 区分定義メニューでは Σ(区分料金 × 区分人数) で基本料金合計を保存する。
	 * - 区分人数の合計が予約人数（_vkbm_booking_guests）として保存される。
	 * - 区分内訳（_vkbm_booking_guest_tiers）がラベル・料金・人数で保存される。
	 * - 特定区分が0名でも合計1名以上なら申込できる。
	 * - 下書きに改竄した料金・ラベルが入っていても、サーバ保存メタの料金で再計算される。
	 */
	public function test_create_booking_resolves_guest_tiers(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$menu_tiers = array(
			array(
				'label' => '一般',
				'price' => 4000,
			),
			array(
				'label' => '子供',
				'price' => 3000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '一般3名・子供2名 => 合計5名 / 基本料金合計 4000×3+3000×2=18000（正常系）',
				'date'                => '2024-07-01',
				'stored_tiers'        => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
				'expected_guests'     => 5,
				'expected_base_total' => 18000,
				'expected_counts'     => array( 3, 2 ),
			),
			array(
				'test_condition_name' => '一般3名・子供0名 => 合計3名 / 基本料金合計 12000（正常系・特定区分0名）',
				'date'                => '2024-07-02',
				'stored_tiers'        => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 0,
					),
				),
				'expected_guests'     => 3,
				'expected_base_total' => 12000,
				'expected_counts'     => array( 3, 0 ),
			),
			array(
				'test_condition_name' => 'クライアントが料金0・ラベル改竄を送っても保存メタ料金で再計算（セキュリティ回帰）',
				'date'                => '2024-07-03',
				'stored_tiers'        => array(
					array(
						'label' => 'ハッカー',
						'price' => 0,
						'count' => 2,
					),
					array(
						'label' => 'ハッカー',
						'price' => 0,
						'count' => 1,
					),
				),
				'expected_guests'     => 3,
				// 改竄料金0は無視され、サーバ保存メタ 4000/3000 で 4000×2+3000×1=11000 となる。
				'expected_base_total' => 11000,
				'expected_counts'     => array( 2, 1 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();

			// 区分料金で計算するため基本料金は別の値にしておき、区分料金が優先されることを確認する。
			update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_price_tiers', $menu_tiers );

			$start = $case['date'] . 'T10:00:00+09:00';
			$end   = $case['date'] . 'T10:30:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data_with_tiers( $menu_id, $staff_id, $start, $end, $case['stored_tiers'] );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ),
				$case['test_condition_name']
			);
			$this->assertSame(
				$case['expected_base_total'],
				(int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ),
				$case['test_condition_name']
			);

			// 区分内訳スナップショットが保存され、ラベル・料金がサーバ保存メタと一致することを確認する。
			$saved_tiers = get_post_meta( $booking_id, '_vkbm_booking_guest_tiers', true );
			$this->assertIsArray( $saved_tiers, $case['test_condition_name'] );
			$this->assertSame( '一般', $saved_tiers[0]['label'] ?? '', $case['test_condition_name'] );
			$this->assertSame( 4000, (int) ( $saved_tiers[0]['price'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( '子供', $saved_tiers[1]['label'] ?? '', $case['test_condition_name'] );
			$this->assertSame( 3000, (int) ( $saved_tiers[1]['price'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_counts'][0], (int) ( $saved_tiers[0]['count'] ?? -1 ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_counts'][1], (int) ( $saved_tiers[1]['count'] ?? -1 ), $case['test_condition_name'] );

			wp_set_current_user( 0 );
		}
	}

	/**
	 * 料金区分の人数内訳を含む下書きを transient に保存する（テスト用ヘルパー）。
	 *
	 * @param int                                  $menu_id  サービスメニューID。
	 * @param int                                  $staff_id スタッフID。
	 * @param string                               $start_at スロット開始（ISO8601）。
	 * @param string                               $end_at   スロット終了（ISO8601）。
	 * @param array<int, array<string, mixed>>     $tiers    区分人数内訳。
	 * @return string 下書きトークン。
	 */
	private function store_temporary_reservation_data_with_tiers(
		int $menu_id,
		int $staff_id,
		string $start_at,
		string $end_at,
		array $tiers
	): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );

		// 合計人数を guests として保存する（下書き保存時の挙動を模す）。
		$guests = 0;
		foreach ( $tiers as $tier ) {
			$guests += max( 0, (int) ( $tier['count'] ?? 0 ) );
		}

		$payload = [
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => max( 1, $guests ),
			'guest_tiers'          => $tiers,
			'slot'                 => [
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			],
			'assignable_staff_ids' => [ $staff_id ],
			'meta'                 => [
				'timezone' => 'Asia/Tokyo',
			],
		];

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * create_booking: 料金区分メニューで全区分0名（合計0）の予約確定は guests_required(400) で拒否する。
	 *
	 * max(1, total) で1名へ丸めると0名予約が成立してしまうため、合計1名未満を明示的にエラーにする。
	 */
	public function test_create_booking_rejects_zero_guest_tiers(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
				array(
					'label' => '子供',
					'price' => 3000,
				),
			)
		);

		$start = '2024-08-01T10:00:00+09:00';
		$end   = '2024-08-01T10:30:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		// 全区分0名の内訳を保存する。
		$token = $this->store_temporary_reservation_data_with_tiers(
			$menu_id,
			$staff_id,
			$start,
			$end,
			array(
				array(
					'label' => '一般',
					'price' => 4000,
					'count' => 0,
				),
				array(
					'label' => '子供',
					'price' => 3000,
					'count' => 0,
				),
			)
		);

		$controller = $this->build_controller( $staff_id, $start, $end );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );

		$this->assertInstanceOf( WP_Error::class, $response, '全区分0名は予約確定できない' );
		$this->assertSame( 'guests_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? 0 );

		wp_set_current_user( 0 );
	}

	/**
	 * create_booking: 料金区分経路（#392）で、下書き作成後に管理者が予約枠の定員を
	 * 引き下げた場合、確定時点の定員で区分内訳の合計人数を再検証し、超過なら guests_exceeded(400)
	 * で拒否することを検証する。
	 *
	 * 区分内訳の合計（Price_Tiers::total_count()）は resolve_guests() のクランプ（1〜max_capacity）を
	 * 通らない経路のため、#392 でこの再検証を追加するまでは定員超過の人数のまま確定できてしまっていた
	 * （#392より前は指名予約＝必ず1名だったため存在しなかった穴）。指名を使うメニューで発生する
	 * ケースとして検証する。
	 */
	public function test_create_booking_rejects_guest_tiers_exceeding_capacity_lowered_after_draft(): void {
		// 複数人予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		// サイト全体の指名機能をON。このメニューは指名を使う（既定＝使う）。
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		// 下書き作成時点では定員5。
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
				array(
					'label' => '子供',
					'price' => 3000,
				),
			)
		);

		$start = '2024-08-05T10:00:00+09:00';
		$end   = '2024-08-05T10:30:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		// 一般3名・子供1名 = 合計4名の下書きを、定員5のうちに作成する（この時点では超過していない）。
		$token = $this->store_temporary_reservation_data_with_tiers(
			$menu_id,
			$staff_id,
			$start,
			$end,
			array(
				array(
					'label' => '一般',
					'price' => 4000,
					'count' => 3,
				),
				array(
					'label' => '子供',
					'price' => 3000,
					'count' => 1,
				),
			)
		);

		// 下書き作成後、管理者が予約枠の定員を 5 → 2 に引き下げる（合計4名は超過になる）。
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );

		$controller = $this->build_controller( $staff_id, $start, $end );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );

		$this->assertInstanceOf(
			WP_Error::class,
			$response,
			'#392: 下書き作成後に定員が引き下げられた場合、区分内訳の合計人数が定員を超えていれば確定できないべき'
		);
		$this->assertSame( 'guests_exceeded', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? 0 );

		wp_set_current_user( 0 );
	}

	/**
	 * create_booking: wp_insert_post() が 0 を返した場合に booking_creation_failed エラーになることを検証する。
	 *
	 * wp_insert_post() は失敗時に WP_Error だけでなく 0 を返す場合がある。
	 * その際に無効な投稿IDでメタ保存に進んだり、成功レスポンスを返したりせず、
	 * 500 の booking_creation_failed エラーを返すことを確認する。
	 */
	public function test_create_booking_returns_error_when_insert_returns_zero(): void {
		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$start = '2024-06-01T10:00:00+09:00';
		$end   = '2024-06-01T10:30:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
		$controller = $this->build_controller( $staff_id, $start, $end );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		// wp_insert_post() を強制的に 0（失敗）で返させる。
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		try {
			$response = $controller->create_booking( $request );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'booking_creation_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] ?? 0 );

		wp_set_current_user( 0 );
	}

	/**
	 * 顧客フラグで予約ブロックされた顧客は、予約確定で 403 と店舗が設定したメッセージを受け取ることを検証する。
	 *
	 * 予約管理の権限を持つユーザー（管理者・オーナーの代理予約）はブロックしない。
	 */
	public function test_create_booking_blocks_flagged_customer(): void {
		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$start = '2026-09-01T10:00:00+09:00';
		$end   = '2026-09-01T10:30:00+09:00';

		// ブロック種別を作り、予約者に割り当てる。
		$flag_service = new Customer_Flag_Service();
		$flag_service->save_types(
			array(
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => '現在ご予約をお受けできません。',
				),
			)
		);
		$guard = new Customer_Flag_Booking_Guard( $flag_service );
		$guard->register();

		$test_cases = array(
			array(
				'test_condition_name' => 'ブロック種別が付いた一般の予約者は 403 で確定できない（異常系）',
				'conditions'          => array( 'is_admin' => false ),
				'expected'            => array(
					'blocked' => true,
					'status'  => 403,
					'message' => '現在ご予約をお受けできません。',
				),
			),
			array(
				'test_condition_name' => '予約管理の権限を持つユーザーはブロック種別が付いていてもブロックされない（正常系）',
				'conditions'          => array( 'is_admin' => true ),
				'expected'            => array( 'blocked' => false ),
			),
		);

		try {
			foreach ( $test_cases as $case ) {
				$user_id = $case['conditions']['is_admin'] ? $this->create_admin_user() : $this->factory()->user->create();
				if ( $case['conditions']['is_admin'] ) {
					// 管理者にはサービス経由でフラグを付けられないため、代理予約の除外を確かめる目的で割り当てを直接書き込む。
					update_user_meta(
						$user_id,
						Customer_Flag_Repository::USER_META_ASSIGNMENT,
						array(
							'type_id' => 'blacklist',
							'memo'    => '',
						)
					);
				} else {
					$flag_service->set_flag( $user_id, 'blacklist', '' );
				}

				wp_set_current_user( $user_id );
				$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

				$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
				$controller = $this->build_controller( $staff_id, $start, $end );
				$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
				$request->set_param( 'token', $token );
				$request->set_param( 'agree_terms', true );
				if ( $case['conditions']['is_admin'] ) {
					$request->set_param( 'customer_name', '代理予約のお客様' );
				}

				$response = $controller->create_booking( $request );

				if ( $case['expected']['blocked'] ) {
					$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
					$this->assertSame( 'vkbm_customer_flag_blocked', $response->get_error_code(), $case['test_condition_name'] );
					$this->assertSame( $case['expected']['status'], $response->get_error_data()['status'] ?? 0, $case['test_condition_name'] );
					$this->assertSame( $case['expected']['message'], $response->get_error_message(), $case['test_condition_name'] );
				} else {
					// ブロック判定より手前の別のエラーで通ってしまわないよう、予約が作成されたことまで確かめる。
					$this->assertNotInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
					$this->assertGreaterThan( 0, (int) ( $response->get_data()['booking_id'] ?? 0 ), $case['test_condition_name'] );
				}

				wp_set_current_user( 0 );
			}
		} finally {
			// 後続テストへ影響しないよう、登録したフィルターと顧客フラグのデータを片付ける。
			remove_filter( 'vkbm_booking_block_check', array( $guard, 'block_flagged_customer' ), 10 );
			$this->delete_all_flag_options();
		}
	}

	/**
	 * #411 安藤さんレビュー指摘の回帰テスト。
	 *
	 * revalidate_draft_slot() が get_daily_slots() から受け取った WP_Error（担当スタッフ未設定等の
	 * 内部設定を示す）を、権限が無い一般の予約者にはそのまま返さず slot_unavailable 相当の
	 * 汎用エラーへ差し替えることを検証する。vkbm_manage_system_settings を持つユーザーには
	 * 従来どおり詳細なエラーを返す。
	 */
	public function test_create_booking_masks_availability_error_for_non_privileged_user(): void {
		$this->disable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		$start = '2026-08-01T10:00:00+09:00';
		$end   = '2026-08-01T11:00:00+09:00';

		$test_cases = array(
			array(
				'test_condition_name' => '権限なしの一般予約者 => slot_unavailable の汎用エラーに差し替えられる',
				'is_admin'            => false,
				'expected_code'       => 'slot_unavailable',
			),
			array(
				'test_condition_name' => 'vkbm_manage_system_settings を持つ管理者 => 元のエラーがそのまま返る',
				'is_admin'            => true,
				'expected_code'       => 'staff_not_configured',
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['is_admin'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			} else {
				$user_id = $this->factory()->user->create();
			}
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$controller = new Booking_Confirmation_Controller(
				new Booking_Notification_Service_Test_Double(),
				new Settings_Repository(),
				new Availability_Service_Error_Test_Double()
			);

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
			$this->assertSame( $case['expected_code'], $response->get_error_code(), $case['test_condition_name'] );

			if ( ! $case['is_admin'] ) {
				$this->assertStringNotContainsString(
					'staff',
					strtolower( $response->get_error_message() ),
					$case['test_condition_name'] . ' / 内部設定を示す単語を含んではならない'
				);
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * issue #465 レビュー指摘の回帰テスト。
	 *
	 * 無料版はスタッフ指名機能自体が常に無効なため、create_booking()（confirm）は下書きに
	 * 保存された resource_id の値を信用せず、常に 0（自動割当）へ正規化する
	 * （src/bookings/class-booking-confirmation-controller.php の $staff_id 算出）。
	 * これにより、下書きに古い resource_id（例: 過去の予約を再利用する導線・ブックマーク経由で
	 * 渡ってくる Pro版時代の ID）が残っていても、revalidate_draft_slot() の担当一致チェックへは
	 * 常に 0 が渡るため、issue #15 と同じ「予約時にエラーになる」症状が別経路で再発することはない。
	 * 一方 Pro版では下書きの resource_id をそのまま使うため、この一致チェックは従来どおり
	 * 機能し、再検証で確保できなかった担当への予約は拒否される。
	 *
	 * 本番の入口である create_booking() を通して検証する（revalidate_draft_slot() を
	 * ReflectionMethod で直接叩く合成状態ではなく、下書き生成〜確定までの実経路と対応させるため）。
	 */
	public function test_confirm_normalizes_stale_draft_resource_id_by_edition(): void {
		$menu_id = $this->create_menu();
		$this->disable_nomination();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );

		// 実際に確保できる担当（空き枠再検証が返す基本スタッフ）。
		$resolved_staff_id = $this->create_staff();
		// 下書きに残っている古い／改竄された resource_id を想定した別スタッフ。
		$stale_staff_id = $this->create_staff();

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-08-01T10:00:00+09:00';
		$end   = '2026-08-01T11:00:00+09:00';

		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'            => $menu_id,
			'resource_id'        => $stale_staff_id,
			'is_staff_preferred' => true,
			'guests'             => 1,
			'slot'               => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
		);
		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		// 空き枠再検証は、実際に確保できる担当（$resolved_staff_id）を返す。
		$availability = new Availability_Service_Test_Double(
			array(
				'slot_id'        => 'slot-1',
				'start_at'       => $start,
				'end_at'         => $end,
				'service_end_at' => $end,
				'staff'          => array( 'id' => $resolved_staff_id ),
			)
		);
		$controller   = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			$availability
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );

		if ( Staff_Editor::is_enabled() ) {
			// Pro版: 下書きの resource_id（$stale_staff_id）をそのまま信用するため、
			// 実際に確保できる担当（$resolved_staff_id）と不一致であれば従来どおり拒否される。
			$this->assertInstanceOf( WP_Error::class, $response, 'Pro版は従来どおり担当スタッフの不一致を拒否すべき' );
			$this->assertSame( 'staff_unavailable', $response->get_error_code() );
		} else {
			// 無料版: 下書きの resource_id を信用せず 0 に正規化するため、一致チェックに
			// 阻まれず、実際に確保できた担当（$resolved_staff_id）へ割り当てられて予約が成立する
			// （issue #465 再発防止）。
			$this->assertInstanceOf( WP_REST_Response::class, $response, '無料版は古い resource_id との不一致で拒否してはならない' );
			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id );
			$this->assertSame(
				$resolved_staff_id,
				(int) get_post_meta( $booking_id, '_vkbm_booking_resource_id', true ),
				'実際に確保できた担当（$resolved_staff_id）へ割り当てられるべき'
			);
		}
	}

	/**
	 * 予約枠のロック行（options テーブルの _transient_vkbm_booking_mutex_*）の件数を数える。
	 *
	 * ロックは check_capacity_with_mutex() が INSERT IGNORE で options テーブルへ直接書き込むため、
	 * オプションのキャッシュを通さず、直接 SQL で数える。
	 *
	 * @return int ロック行の件数。
	 */
	private function count_slot_lock_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストでロック行の有無を DB から直接確かめるため。
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_vkbm_booking_mutex_' ) . '%'
			)
		);
	}

	/**
	 * 予約投稿（公開状態を問わない）の件数を数える。
	 *
	 * @return int 予約投稿の件数。
	 */
	private function count_booking_posts(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストで予約投稿が増えたかを DB から直接確かめるため。
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				Booking_Post_Type::POST_TYPE
			)
		);
	}

	/**
	 * create_booking: vkbm_booking_post_created が「予約枠のロック解放後・仮予約データ削除前」に発火することを検証する。
	 *
	 * 決済プラグインはこのフックで決済サービスと通信することがあるため、ロックを保持したまま発火すると、
	 * その間に同じ枠を予約しようとした人が「処理中（booking_busy）」で失敗してしまう。
	 * そのため、wp_insert_post() の時点ではロックを保持していること（ロックで守る範囲は変わらないこと）、
	 * フックの時点ではロックが解放済みで、予約メタは保存済み・仮予約データはまだ残っていることを確かめる。
	 * 予約投稿の作成に失敗して早期 return した場合は発火しないことも確かめる。
	 *
	 * フックのコールバックが例外（RuntimeException・\Error）を投げても、予約はその時点で成立しているため、
	 * 成功のレスポンスを返し、仮予約データの削除まで続けることを確かめる。
	 * あわせて、エラーログに予約 ID と例外クラス名が残り、予約者のメールアドレス（個人情報）は残らないことも確かめる。
	 */
	public function test_create_booking_fires_post_created_after_lock_release(): void {
		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name' => '決済の確認フィルターなしで予約できた場合 => ロック解放後・仮予約データ削除前に 1 回だけ発火する',
				'conditions'          => array(
					'start'                => '2024-07-01T10:00:00+09:00',
					'end'                  => '2024-07-01T10:30:00+09:00',
					'add_payment_check'    => false,
					'force_insert_failure' => false,
					'hook_throws'          => null,
				),
				'expected'            => array(
					'fired_count'               => 1,
					'lock_rows_at_insert'       => 1,
					'lock_rows_at_hook'         => 0,
					'base_total_price_at_hook'  => 1000,
					'draft_exists_at_hook'      => true,
					'draft_exists_after_return' => false,
					'returns_response'          => true,
					'booking_increase'          => 1,
					'logged_exception_class'    => null,
				),
			),
			array(
				'test_condition_name' => '決済の確認フィルターが true を返して予約できた場合 => ロック解放後・仮予約データ削除前に 1 回だけ発火する',
				'conditions'          => array(
					'start'                => '2024-07-02T10:00:00+09:00',
					'end'                  => '2024-07-02T10:30:00+09:00',
					'add_payment_check'    => true,
					'force_insert_failure' => false,
					'hook_throws'          => null,
				),
				'expected'            => array(
					'fired_count'               => 1,
					'lock_rows_at_insert'       => 1,
					'lock_rows_at_hook'         => 0,
					'base_total_price_at_hook'  => 1000,
					'draft_exists_at_hook'      => true,
					'draft_exists_after_return' => false,
					'returns_response'          => true,
					'booking_increase'          => 1,
					'logged_exception_class'    => null,
				),
			),
			array(
				'test_condition_name' => 'フックのコールバックが RuntimeException を投げた場合 => 予約は成功として返り、仮予約データも削除され、ログに予約 ID と例外クラス名が残る',
				'conditions'          => array(
					'start'                => '2024-07-04T10:00:00+09:00',
					'end'                  => '2024-07-04T10:30:00+09:00',
					'add_payment_check'    => false,
					'force_insert_failure' => false,
					'hook_throws'          => \RuntimeException::class,
				),
				'expected'            => array(
					'fired_count'               => 1,
					'lock_rows_at_insert'       => 1,
					'lock_rows_at_hook'         => 0,
					'base_total_price_at_hook'  => 1000,
					'draft_exists_at_hook'      => true,
					'draft_exists_after_return' => false,
					'returns_response'          => true,
					'booking_increase'          => 1,
					'logged_exception_class'    => \RuntimeException::class,
				),
			),
			array(
				'test_condition_name' => 'フックのコールバックが \Error を投げた場合 => 予約は成功として返り、仮予約データも削除され、ログに予約 ID と例外クラス名が残る',
				'conditions'          => array(
					'start'                => '2024-07-05T10:00:00+09:00',
					'end'                  => '2024-07-05T10:30:00+09:00',
					'add_payment_check'    => false,
					'force_insert_failure' => false,
					'hook_throws'          => \Error::class,
				),
				'expected'            => array(
					'fired_count'               => 1,
					'lock_rows_at_insert'       => 1,
					'lock_rows_at_hook'         => 0,
					'base_total_price_at_hook'  => 1000,
					'draft_exists_at_hook'      => true,
					'draft_exists_after_return' => false,
					'returns_response'          => true,
					'booking_increase'          => 1,
					'logged_exception_class'    => \Error::class,
				),
			),
			array(
				'test_condition_name' => 'wp_insert_post() が失敗して早期 return した場合 => 発火せず、仮予約データも残る',
				'conditions'          => array(
					'start'                => '2024-07-03T10:00:00+09:00',
					'end'                  => '2024-07-03T10:30:00+09:00',
					'add_payment_check'    => false,
					'force_insert_failure' => true,
					'hook_throws'          => null,
				),
				'expected'            => array(
					'fired_count'               => 0,
					'lock_rows_at_insert'       => null,
					'lock_rows_at_hook'         => null,
					'base_total_price_at_hook'  => null,
					'draft_exists_at_hook'      => null,
					'draft_exists_after_return' => true,
					'returns_response'          => false,
					'booking_increase'          => 0,
					'logged_exception_class'    => null,
				),
			),
		);

		// エラーログの出力先を一時ファイルへ切り替え、ログの中身を確かめられるようにする（PHPUnit の出力も汚さない）。
		$original_error_log = ini_get( 'error_log' );
		$log_file           = tempnam( get_temp_dir(), 'vkbm-post-created-log' );

		try {
			ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- テスト中だけログの出力先を一時ファイルへ向け、終了時に元へ戻す。

			foreach ( $test_cases as $index => $case ) {
				// ケースごとにメニュー・担当・予約者・仮予約データを用意する。
				$menu_id  = $this->create_menu();
				$staff_id = $this->create_staff();
				update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
				// ログにメールアドレスが残らないことを確かめるため、予約者のメールアドレスを固定する。
				$customer_email = 'vkbm-post-created-' . $index . '@example.com';
				wp_set_current_user( $this->factory()->user->create( array( 'user_email' => $customer_email ) ) );
				$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

				$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $case['conditions']['start'], $case['conditions']['end'] );
				$controller = $this->build_controller( $staff_id, $case['conditions']['start'], $case['conditions']['end'] );
				$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
				$request->set_param( 'token', $token );
				$request->set_param( 'agree_terms', true );

				// 各時点で観測した値を記録する（発火しなかった時点は null のまま）。
				$observed = array(
					'fired_count'              => 0,
					'lock_rows_at_insert'      => null,
					'lock_rows_at_hook'        => null,
					'base_total_price_at_hook' => null,
					'draft_exists_at_hook'     => null,
				);

				// wp_insert_post() で予約投稿が作られた時点のロック行の件数を記録する。
				$on_insert = function ( $post_id, $post ) use ( &$observed ): void {
					if ( Booking_Post_Type::POST_TYPE === $post->post_type ) {
						$observed['lock_rows_at_insert'] = $this->count_slot_lock_rows();
					}
				};
				// vkbm_booking_post_created の時点のロック行・予約メタ・仮予約データを記録する。
				$on_post_created = function ( $booking_id ) use ( &$observed, $token ): void {
					++$observed['fired_count'];
					$observed['lock_rows_at_hook']        = $this->count_slot_lock_rows();
					$observed['base_total_price_at_hook'] = (int) get_post_meta( (int) $booking_id, '_vkbm_booking_base_total_price', true );
					$observed['draft_exists_at_hook']     = false !== get_transient( self::TRANSIENT_PREFIX . $token );
				};
				// 観測用のコールバックより後（優先度 20）で、指定された種類の例外を投げる。
				$hook_throws   = $case['conditions']['hook_throws'];
				$throw_on_hook = static function () use ( $hook_throws ): void {
					throw new $hook_throws( 'テスト用の例外（拡張プラグインの不具合を想定）' );
				};
				$payment_check = static function () {
					return true;
				};

				add_action( 'wp_insert_post', $on_insert, 10, 2 );
				add_action( 'vkbm_booking_post_created', $on_post_created, 10, 1 );
				if ( null !== $hook_throws ) {
					add_action( 'vkbm_booking_post_created', $throw_on_hook, 20, 0 );
				}
				if ( $case['conditions']['add_payment_check'] ) {
					add_filter( 'vkbm_booking_payment_check', $payment_check );
				}
				if ( $case['conditions']['force_insert_failure'] ) {
					// wp_insert_post() を強制的に 0（失敗）で返させる。
					add_filter( 'wp_insert_post_empty_content', '__return_true' );
				}

				// ケースごとにログを空にしてから実行する。
				file_put_contents( $log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テスト用の一時ファイルを空にするだけ。
				$bookings_before = $this->count_booking_posts();

				try {
					$response = $controller->create_booking( $request );
				} finally {
					// 後続のケースへ影響しないよう、登録したフックを外す。
					remove_action( 'wp_insert_post', $on_insert, 10 );
					remove_action( 'vkbm_booking_post_created', $on_post_created, 10 );
					remove_action( 'vkbm_booking_post_created', $throw_on_hook, 20 );
					remove_filter( 'vkbm_booking_payment_check', $payment_check );
					remove_filter( 'wp_insert_post_empty_content', '__return_true' );
				}

				$this->assertSame( $case['expected']['fired_count'], $observed['fired_count'], $case['test_condition_name'] . '（発火回数）' );
				$this->assertSame( $case['expected']['lock_rows_at_insert'], $observed['lock_rows_at_insert'], $case['test_condition_name'] . '（wp_insert_post 時点ではロックを保持していること）' );
				$this->assertSame( $case['expected']['lock_rows_at_hook'], $observed['lock_rows_at_hook'], $case['test_condition_name'] . '（フック時点ではロックが解放済みであること）' );
				$this->assertSame( $case['expected']['base_total_price_at_hook'], $observed['base_total_price_at_hook'], $case['test_condition_name'] . '（フック時点で予約メタが保存済みであること）' );
				$this->assertSame( $case['expected']['draft_exists_at_hook'], $observed['draft_exists_at_hook'], $case['test_condition_name'] . '（フック時点で仮予約データが残っていること）' );
				$this->assertSame(
					$case['expected']['draft_exists_after_return'],
					false !== get_transient( self::TRANSIENT_PREFIX . $token ),
					$case['test_condition_name'] . '（処理後の仮予約データの有無）'
				);
				$this->assertSame( 0, $this->count_slot_lock_rows(), $case['test_condition_name'] . '（処理後にロックが残っていないこと）' );
				$this->assertSame( $case['expected']['returns_response'], $response instanceof WP_REST_Response, $case['test_condition_name'] . '（成功のレスポンスを返すか）' );
				$this->assertSame( $case['expected']['booking_increase'], $this->count_booking_posts() - $bookings_before, $case['test_condition_name'] . '（予約投稿の増加数）' );

				// エラーログの中身を確かめる。
				$log = (string) file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- テスト用の一時ファイルを読むだけ。
				if ( null === $case['expected']['logged_exception_class'] ) {
					$this->assertStringNotContainsString( 'vkbm_booking_post_created', $log, $case['test_condition_name'] . '（例外が無ければログを残さない）' );
				} else {
					$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
					$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] . '（レスポンスに予約 ID を含む）' );
					$this->assertStringContainsString( '[vkbm] vkbm_booking_post_created', $log, $case['test_condition_name'] . '（ログの接頭辞とフック名）' );
					$this->assertStringContainsString( 'booking_id=' . $booking_id . ' ', $log, $case['test_condition_name'] . '（ログに予約 ID が残る）' );
					$this->assertStringContainsString( 'exception=' . $case['expected']['logged_exception_class'] . ' ', $log, $case['test_condition_name'] . '（ログに例外クラス名が残る）' );
					$this->assertStringNotContainsString( $customer_email, $log, $case['test_condition_name'] . '（ログに予約者のメールアドレスを残さない）' );
				}
			}
		} finally {
			// ログの出力先を元に戻し、一時ファイルを消す。
			ini_set( 'error_log', false === $original_error_log ? '' : $original_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- テスト開始前の値へ戻す。
			wp_delete_file( $log_file );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: vkbm_booking_payment_check のコールバックが失敗しても予約枠のロックが残らないことを検証する。
	 *
	 * コールバックが例外（RuntimeException・\Error などの Throwable）を投げた場合もロックを解放しないと、
	 * ロックの有効期限が切れるまで同じ枠を誰も予約できなくなる。例外・WP_Error のどちらでも、
	 * ロックが残らず予約投稿も作られないこと、フィルターを外せば同じ仮予約データで予約し直せることを確かめる。
	 * WP_Error を返したときに、そのエラーをそのまま返す既存の動作が変わらないことも確かめる。
	 */
	public function test_create_booking_releases_lock_when_payment_check_fails(): void {
		$this->disable_nomination();

		$test_cases = array(
			array(
				'test_condition_name' => '決済の確認フィルターが未登録の場合 => 予約でき、ロックは残らない',
				'conditions'          => array(
					'start'         => '2024-08-01T10:00:00+09:00',
					'end'           => '2024-08-01T10:30:00+09:00',
					'payment_check' => 'none',
				),
				'expected'            => array(
					'exception'        => null,
					'error_code'       => null,
					'booking_increase' => 1,
					'retry_succeeds'   => null,
				),
			),
			array(
				'test_condition_name' => '決済の確認フィルターが true を返す場合 => 予約でき、ロックは残らない',
				'conditions'          => array(
					'start'         => '2024-08-02T10:00:00+09:00',
					'end'           => '2024-08-02T10:30:00+09:00',
					'payment_check' => 'true',
				),
				'expected'            => array(
					'exception'        => null,
					'error_code'       => null,
					'booking_increase' => 1,
					'retry_succeeds'   => null,
				),
			),
			array(
				'test_condition_name' => '決済の確認フィルターが WP_Error を返す場合 => そのエラーを返し、ロックは残らず予約も作られず、再予約できる',
				'conditions'          => array(
					'start'         => '2024-08-03T10:00:00+09:00',
					'end'           => '2024-08-03T10:30:00+09:00',
					'payment_check' => 'wp_error',
				),
				'expected'            => array(
					'exception'        => null,
					'error_code'       => 'vkbm_test_payment_failed',
					'booking_increase' => 0,
					'retry_succeeds'   => true,
				),
			),
			array(
				'test_condition_name' => '決済の確認フィルターが RuntimeException を投げる場合 => ロックは残らず予約も作られず、再予約できる',
				'conditions'          => array(
					'start'         => '2024-08-04T10:00:00+09:00',
					'end'           => '2024-08-04T10:30:00+09:00',
					'payment_check' => 'runtime_exception',
				),
				'expected'            => array(
					'exception'        => \RuntimeException::class,
					'error_code'       => null,
					'booking_increase' => 0,
					'retry_succeeds'   => true,
				),
			),
			array(
				'test_condition_name' => '決済の確認フィルターが \Error を投げる場合 => ロックは残らず予約も作られず、再予約できる',
				'conditions'          => array(
					'start'         => '2024-08-05T10:00:00+09:00',
					'end'           => '2024-08-05T10:30:00+09:00',
					'payment_check' => 'error',
				),
				'expected'            => array(
					'exception'        => \Error::class,
					'error_code'       => null,
					'booking_increase' => 0,
					'retry_succeeds'   => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// ケースごとにメニュー・担当・予約者・仮予約データを用意する。
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();
			wp_set_current_user( $this->factory()->user->create() );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $case['conditions']['start'], $case['conditions']['end'] );
			$controller = $this->build_controller( $staff_id, $case['conditions']['start'], $case['conditions']['end'] );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			// 条件に応じた決済の確認フィルターのコールバックを用意する（none は登録しない）。
			$payment_check = null;
			switch ( $case['conditions']['payment_check'] ) {
				case 'true':
					$payment_check = static function () {
						return true;
					};
					break;
				case 'wp_error':
					$payment_check = static function () {
						return new WP_Error( 'vkbm_test_payment_failed', 'テスト用の決済エラー', array( 'status' => 402 ) );
					};
					break;
				case 'runtime_exception':
					$payment_check = static function () {
						throw new \RuntimeException( 'テスト用の例外（決済サービスとの通信失敗を想定）' );
					};
					break;
				case 'error':
					$payment_check = static function () {
						throw new \Error( 'テスト用の \Error（決済プラグインの不具合を想定）' );
					};
					break;
			}

			$bookings_before = $this->count_booking_posts();
			$response        = null;
			$thrown          = null;
			if ( null !== $payment_check ) {
				add_filter( 'vkbm_booking_payment_check', $payment_check );
			}
			try {
				$response = $controller->create_booking( $request );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			} finally {
				// 再予約はフィルターを外した状態で行うため、ここで外しておく。
				if ( null !== $payment_check ) {
					remove_filter( 'vkbm_booking_payment_check', $payment_check );
				}
			}

			$this->assertSame( $case['expected']['exception'], null === $thrown ? null : get_class( $thrown ), $case['test_condition_name'] . '（投げられた例外）' );
			$this->assertSame( $case['expected']['error_code'], $response instanceof WP_Error ? $response->get_error_code() : null, $case['test_condition_name'] . '（返されたエラーコード）' );
			$this->assertSame( $case['expected']['booking_increase'], $this->count_booking_posts() - $bookings_before, $case['test_condition_name'] . '（増えた予約投稿の件数）' );
			$this->assertSame( 0, $this->count_slot_lock_rows(), $case['test_condition_name'] . '（ロック行が残っていないこと）' );

			// 失敗したケースは、フィルターを外して同じ仮予約データで予約し直せることを確かめる。
			if ( null !== $case['expected']['retry_succeeds'] ) {
				$retry_response = $controller->create_booking( $request );
				$this->assertSame(
					$case['expected']['retry_succeeds'],
					$retry_response instanceof WP_REST_Response,
					$case['test_condition_name'] . '（再予約の結果: ' . ( $retry_response instanceof WP_Error ? $retry_response->get_error_code() : 'OK' ) . '）'
				);
				$this->assertSame( 0, $this->count_slot_lock_rows(), $case['test_condition_name'] . '（再予約後にロック行が残っていないこと）' );
			}
		}

		wp_set_current_user( 0 );
	}
}

class Booking_Notification_Service_Test_Double extends Booking_Notification_Service {
	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	public function handle_confirmed_creation( int $booking_id ): void {
		// Do nothing in tests.
	}

	public function handle_pending_creation( int $booking_id ): void {
		// Do nothing in tests.
	}
}

/**
 * 通知の呼び出し（仮予約か確定か）を記録するテスト用ダブル。
 */
class Booking_Notification_Service_Recording_Double extends Booking_Notification_Service {
	/**
	 * 呼ばれた通知の種類（'pending' / 'confirmed'）を呼ばれた順に記録する。
	 *
	 * @var array<int,string>
	 */
	public array $calls = [];

	public function __construct() {
		parent::__construct( new Settings_Repository() );
	}

	public function handle_confirmed_creation( int $booking_id ): void {
		$this->calls[] = 'confirmed';
	}

	public function handle_pending_creation( int $booking_id ): void {
		$this->calls[] = 'pending';
	}
}

class Availability_Service_Test_Double extends Availability_Service {

	/**
	 * 受付下限の判定は空き枠の差し替え対象外（#655）。親コンストラクタを呼ばないダブルでも落ちないよう、常に「下限内」とする。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param string $start_at 枠の開始日時。
	 * @return bool
	 */
	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		return false;
	}
	/** @var array<string, mixed> */
	private array $slot;

	/**
	 * @param array<string, mixed> $slot
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	public function get_daily_slots( array $args ) {
		return [
			'slots' => [ $this->slot ],
		];
	}
}

/**
 * #411: get_daily_slots() が担当スタッフ未設定相当の WP_Error を返す状況を再現するダブル。
 * revalidate_draft_slot() のエラーマスキングを検証するために使う。
 */
class Availability_Service_Error_Test_Double extends Availability_Service {

	/**
	 * 受付下限の判定は空き枠の差し替え対象外（#655）。親コンストラクタを呼ばないダブルでも落ちないよう、常に「下限内」とする。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param string $start_at 枠の開始日時。
	 * @return bool
	 */
	public function is_before_booking_cutoff( int $menu_id, string $start_at ): bool {
		return false;
	}
	public function get_daily_slots( array $args ) {
		return new WP_Error(
			'staff_not_configured',
			'No staff members have been set up to be in charge.'
		);
	}
}
