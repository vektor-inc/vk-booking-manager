<?php
/**
 * 料金の単位が「1ツアーあたり」のメニューの合計（下書き・確定・通知メール・管理画面の予約編集・マイページ）のテスト（#1002）。
 *
 * 空き状況・下書き・確定・メールで同じ合計（人数にかかわらず基本料金そのまま）になることを確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

require_once __DIR__ . '/support/class-per-booking-notification-test-double.php';
require_once __DIR__ . '/support/class-per-booking-availability-test-double.php';

/**
 * 1ツアーあたりのメニューの合計・予約時点の料金の単位の保存・表示のテスト。
 *
 * @group bookings
 * @group price-unit
 */
class Booking_Price_Per_Booking_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/**
	 * setUp で上書きするタイムゾーンの元値。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * setUp で上書きするプロバイダ設定の元値。
	 *
	 * @var mixed
	 */
	private $original_settings = null;

	/**
	 * テストで作った下書きのトークン。
	 *
	 * @var array<int, string>
	 */
	private array $tokens = array();

	/**
	 * テスト前に設定を退避し、アクティビティのサイトにする。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、退避はスキップ判定より前に行う。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, null );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金の単位（1ツアーあたり）は Pro 版の業種プリセット（アクティビティ）限定のため、無料版ではスキップする。' );
		}

		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->set_site( 'activity' );
	}

	/**
	 * テスト後に設定・下書き・ログイン状態を元に戻す。
	 */
	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		$_POST = array();
		update_option( 'timezone_string', $this->original_timezone_string );
		if ( null === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する（指名なし・予約枠の定員機能 ON）。
	 *
	 * @param string $preset 業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => $preset,
					'staff_enabled'         => false,
					'slot_capacity_enabled' => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * スタッフとメニュー（基本料金30000円・定員8）を作る。
	 *
	 * @param array<string, mixed> $meta 追加のメニューメタ。
	 * @return array{menu_id: int, staff_id: int}
	 */
	private function create_menu_with_staff( array $meta = array() ): array {
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 8 );
		update_post_meta( $menu_id, '_vkbm_base_price', 30000 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return array(
			'menu_id'  => $menu_id,
			'staff_id' => $staff_id,
		);
	}

	/**
	 * 下書きを保存して読み出し、応答のデータを返す。
	 *
	 * @param int $menu_id メニュー ID。
	 * @param int $guests  要求する人数。
	 * @return array<string, mixed>
	 */
	private function save_and_read_draft( int $menu_id, int $guests ): array {
		wp_set_current_user( $this->factory()->user->create() );
		$controller = new Booking_Draft_Controller();

		$save = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$save->set_header( 'content-type', 'application/json' );
		$save->set_body(
			wp_json_encode(
				array(
					'menu_id' => $menu_id,
					'date'    => '2026-11-10',
					'guests'  => $guests,
					'slot'    => array(
						'slot_id'  => 'slot-1',
						'start_at' => '2026-11-10T10:00:00+09:00',
						'end_at'   => '2026-11-10T11:00:00+09:00',
					),
					'meta'    => array( 'timezone' => 'Asia/Tokyo' ),
				)
			)
		);
		$saved = $controller->save_draft( $save );
		$this->assertInstanceOf( WP_REST_Response::class, $saved, '下書きを保存できる' );
		$token          = (string) $saved->get_data()['token'];
		$this->tokens[] = $token;

		$get = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
		$get->set_url_params( array( 'token' => $token ) );
		$read = $controller->get_draft( $get );
		$this->assertInstanceOf( WP_REST_Response::class, $read, '下書きを読み出せる' );

		return $read->get_data();
	}

	/**
	 * get_draft: 1ツアーあたりのメニューは、人数にかかわらず合計が基本料金そのままになる。
	 */
	public function test_draft_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・5名 => 合計は基本料金のまま（30000）で、料金の単位 per_booking を返す',
				'site'                => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'guests'              => 5,
				'expected_guests'     => 5,
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
			),
			array(
				'test_condition_name' => '1ツアーあたり・1名 => 合計は30000',
				'site'                => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'guests'              => 1,
				'expected_guests'     => 1,
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
			),
			array(
				'test_condition_name' => '1ツアーあたり・料金区分が残っていても => 区分を使わず基本料金のまま（30000）',
				'site'                => 'activity',
				'meta'                => array(
					'_vkbm_price_unit'  => 'per_booking',
					'_vkbm_price_tiers' => array(
						array(
							'label' => '大人',
							'price' => 5000,
						),
					),
				),
				'guests'              => 4,
				'expected_guests'     => 4,
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
			),
			array(
				'test_condition_name' => '1名あたり（保存なし）・複数人一括予約あり・5名 => 今までどおり 30000 × 5',
				'site'                => 'activity',
				'meta'                => array( '_vkbm_allow_multiple_guests' => true ),
				'guests'              => 5,
				'expected_guests'     => 5,
				'expected_total'      => 150000,
				'expected_unit'       => 'per_guest',
			),
			array(
				'test_condition_name' => 'カスタムへ切り替えた後 => 1ツアーあたりの保存値は効かず、今までどおり 30000 × 5',
				'site'                => 'custom',
				'meta'                => array(
					'_vkbm_price_unit'            => 'per_booking',
					'_vkbm_allow_multiple_guests' => true,
				),
				'guests'              => 5,
				'expected_guests'     => 5,
				'expected_total'      => 150000,
				'expected_unit'       => 'per_guest',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['site'] );
			$menu = $this->create_menu_with_staff( $case['meta'] );

			$draft = $this->save_and_read_draft( $menu['menu_id'], $case['guests'] );

			$this->assertSame( $case['expected_guests'], (int) $draft['guests'], $case['test_condition_name'] . ' / 人数' );
			$this->assertSame( $case['expected_total'], (int) $draft['total_price'], $case['test_condition_name'] . ' / 合計' );
			$this->assertSame( $case['expected_unit'], $draft['price_unit'], $case['test_condition_name'] . ' / 料金の単位' );
		}
	}

	/**
	 * 確定コントローラを組み立てる。
	 *
	 * @param int    $staff_id スタッフ ID。
	 * @param string $start_at スロット開始。
	 * @param string $end_at   スロット終了。
	 * @return Booking_Confirmation_Controller
	 */
	private function build_confirmation_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		return new Booking_Confirmation_Controller(
			new Per_Booking_Notification_Test_Double(),
			new Settings_Repository(),
			new Per_Booking_Availability_Test_Double(
				array(
					'slot_id'              => 'slot-1',
					'start_at'             => $start_at,
					'end_at'               => $end_at,
					'service_end_at'       => $end_at,
					'staff'                => array( 'id' => $staff_id ),
					'assignable_staff_ids' => array( $staff_id ),
					'auto_assign'          => true,
				)
			)
		);
	}

	/**
	 * 予約を確定して予約 ID を返す。
	 *
	 * @param array{menu_id: int, staff_id: int} $menu   メニューとスタッフ。
	 * @param int                                $guests 人数。
	 * @param string                             $date   予約日（Y-m-d）。
	 * @return int 予約 ID。
	 */
	private function confirm_booking( array $menu, int $guests, string $date ): int {
		$start = $date . 'T10:00:00+09:00';
		$end   = $date . 'T11:00:00+09:00';

		wp_set_current_user( $this->factory()->user->create() );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token          = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$this->tokens[] = $token;
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'menu_id'       => $menu['menu_id'],
				'resource_id'   => $menu['staff_id'],
				'guests'        => $guests,
				'slot'          => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'meta'          => array( 'timezone' => 'Asia/Tokyo' ),
				'owner_user_id' => (int) get_current_user_id(),
			)
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $this->build_confirmation_controller( $menu['staff_id'], $start, $end )->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '予約を確定できる' );

		return (int) ( $response->get_data()['booking_id'] ?? 0 );
	}

	/**
	 * create_booking: 1ツアーあたりは人数にかかわらず合計が基本料金そのままで、予約時点の料金の単位・貸し切りの印を保存する。
	 */
	public function test_create_booking_total_and_snapshot(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・5名 => 合計30000・料金の単位を保存・貸し切り予約の印を付ける・人数5',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'guests'              => 5,
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
				'expected_exclusive'  => true,
				'expected_guests'     => 5,
			),
			array(
				'test_condition_name' => '1ツアーあたり・料金区分が残っていても => 区分は使わず30000・区分の内訳は保存しない',
				'meta'                => array(
					'_vkbm_price_unit'  => 'per_booking',
					'_vkbm_price_tiers' => array(
						array(
							'label' => '大人',
							'price' => 5000,
						),
					),
				),
				'guests'              => 3,
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
				'expected_exclusive'  => true,
				'expected_guests'     => 3,
			),
			array(
				'test_condition_name' => '1名あたり・複数人一括予約あり・5名 => 今までどおり 30000 × 5・料金の単位は保存しない・貸し切りにならない',
				'meta'                => array( '_vkbm_allow_multiple_guests' => true ),
				'guests'              => 5,
				'expected_total'      => 150000,
				'expected_unit'       => null,
				'expected_exclusive'  => false,
				'expected_guests'     => 5,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff( $case['meta'] );
			$booking_id = $this->confirm_booking( $menu, $case['guests'], sprintf( '2026-12-%02d', $index + 1 ) );

			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
			$this->assertSame( $case['expected_total'], (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $case['test_condition_name'] . ' / 合計' );
			$this->assertSame( $case['expected_guests'], (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), $case['test_condition_name'] . ' / 人数' );
			$this->assertFalse( metadata_exists( 'post', $booking_id, '_vkbm_booking_guest_tiers' ), $case['test_condition_name'] . ' / 区分の内訳は保存しない' );

			if ( null === $case['expected_unit'] ) {
				$this->assertFalse( metadata_exists( 'post', $booking_id, '_vkbm_booking_price_unit' ), $case['test_condition_name'] . ' / 料金の単位は保存しない' );
			} else {
				$this->assertSame( $case['expected_unit'], get_post_meta( $booking_id, '_vkbm_booking_price_unit', true ), $case['test_condition_name'] . ' / 料金の単位' );
			}
			$this->assertSame( $case['expected_exclusive'], metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive' ), $case['test_condition_name'] . ' / 貸し切り予約の印' );
		}
	}

	/**
	 * 確定後メールの料金: 1ツアーあたりの予約は人数倍にならず、保存済みの合計を使う（人数掛けの計算し直しをしない）。
	 */
	public function test_notification_price_label(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$payload->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・5名・保存済みの合計あり => 30,000 を表示し、150,000 は表示しない',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'drop_base_total'     => false,
				'expected_amount'     => '30,000',
				'unexpected_amount'   => '150,000',
			),
			array(
				'test_condition_name' => '1ツアーあたり・5名・保存済みの合計が無い旧データ => 予約時点の単位で計算し直しても 30,000（人数を掛けない）',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'drop_base_total'     => true,
				'expected_amount'     => '30,000',
				'unexpected_amount'   => '150,000',
			),
			array(
				'test_condition_name' => '1名あたり・5名 => 今までどおり 150,000',
				'meta'                => array( '_vkbm_allow_multiple_guests' => true ),
				'drop_base_total'     => false,
				'expected_amount'     => '150,000',
				'unexpected_amount'   => '30,000',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff( $case['meta'] );
			$booking_id = $this->confirm_booking( $menu, 5, sprintf( '2027-01-%02d', $index + 1 ) );
			if ( $case['drop_base_total'] ) {
				delete_post_meta( $booking_id, '_vkbm_booking_base_total_price' );
			}

			$data = $payload->invoke( $service, $booking_id );
			$this->assertStringContainsString( $case['expected_amount'], (string) $data['price_label'], $case['test_condition_name'] );
			$this->assertStringNotContainsString( $case['unexpected_amount'], (string) $data['price_label'], $case['test_condition_name'] . ' / 人数倍にならない' );
		}
	}

	/**
	 * 管理画面の予約編集: 人数を変更しても、1ツアーあたりの予約の合計は変わらない。
	 */
	public function test_admin_save_post_keeps_total_when_guests_change(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・人数を5から2へ編集 => 合計は30000のまま',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected_total'      => 30000,
			),
			array(
				'test_condition_name' => '1名あたり・人数を5から2へ編集 => 今までどおり 30000 × 2',
				'meta'                => array( '_vkbm_allow_multiple_guests' => true ),
				'expected_total'      => 60000,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff( $case['meta'] );
			$booking_id = $this->confirm_booking( $menu, 5, sprintf( '2027-02-%02d', $index + 1 ) );

			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
			get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

			$_POST = array(
				'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
				'vkbm_booking'             => array(
					'date'        => substr( (string) get_post_meta( $booking_id, '_vkbm_booking_service_start', true ), 0, 10 ),
					'start_time'  => '10:00',
					'end_time'    => '11:00',
					'service_id'  => $menu['menu_id'],
					'resource_id' => $menu['staff_id'],
					'status'      => 'confirmed',
					'guests'      => 2,
				),
			);
			( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );

			$this->assertSame( 2, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), $case['test_condition_name'] . ' / 人数は編集できる' );
			$this->assertSame( $case['expected_total'], (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $case['test_condition_name'] . ' / 合計' );
			$_POST = array();
		}
	}

	/**
	 * 管理画面で新規に作った予約: 1ツアーあたりのメニューは、人数3でも合計が基本料金のままで、予約時点の料金の単位を保存し、メールの料金表示も人数倍にならない。
	 */
	public function test_admin_created_booking_keeps_per_booking_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・人数3 => 合計30000・料金の単位を保存・メールは 30,000',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected_total'      => 30000,
				'expected_unit'       => 'per_booking',
				'expected_amount'     => '30,000',
				'unexpected_amount'   => '90,000',
			),
			array(
				'test_condition_name' => '1名あたり・人数3 => 今までどおり 30000 × 3・料金の単位は保存しない',
				'meta'                => array( '_vkbm_allow_multiple_guests' => true ),
				'expected_total'      => 90000,
				'expected_unit'       => null,
				'expected_amount'     => '90,000',
				'unexpected_amount'   => '30,000',
			),
		);

		$service = new Booking_Notification_Service( new Settings_Repository() );
		$payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$payload->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$menu    = $this->create_menu_with_staff( $case['meta'] );
			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
			get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

			// 管理画面の「予約を追加」を模す: 基本料金のスナップショットも料金の単位も無い新規の予約投稿。
			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$_POST      = array(
				'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
				'vkbm_booking'             => array(
					'date'        => '2027-03-05',
					'start_time'  => '10:00',
					'end_time'    => '11:00',
					'service_id'  => $menu['menu_id'],
					'resource_id' => $menu['staff_id'],
					'status'      => 'confirmed',
					'guests'      => 3,
				),
			);
			( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );
			$_POST = array();

			$this->assertSame( 3, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), $case['test_condition_name'] . ' / 人数' );
			$this->assertSame( $case['expected_total'], (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $case['test_condition_name'] . ' / 合計' );
			if ( null === $case['expected_unit'] ) {
				$this->assertFalse( metadata_exists( 'post', $booking_id, '_vkbm_booking_price_unit' ), $case['test_condition_name'] . ' / 料金の単位は保存しない' );
			} else {
				$this->assertSame( $case['expected_unit'], get_post_meta( $booking_id, '_vkbm_booking_price_unit', true ), $case['test_condition_name'] . ' / 料金の単位' );
			}

			$data = $payload->invoke( $service, $booking_id );
			$this->assertStringContainsString( $case['expected_amount'], (string) $data['price_label'], $case['test_condition_name'] . ' / メールの料金' );
			$this->assertStringNotContainsString( $case['unexpected_amount'], (string) $data['price_label'], $case['test_condition_name'] . ' / メールの料金が人数倍にならない' );
		}
	}

	/**
	 * マイページの予約一覧: 合計未保存の旧データでも、予約時点の料金の単位で人数を掛けずに合計を出す。
	 */
	public function test_my_bookings_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・5名・合計未保存 => 30000',
				'booking_unit'        => 'per_booking',
				'expected_total'      => 30000,
			),
			array(
				'test_condition_name' => '料金の単位の保存なし・5名・合計未保存 => 今までどおり 30000 × 5',
				'booking_unit'        => null,
				'expected_total'      => 150000,
			),
		);

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$menu       = $this->create_menu_with_staff();
			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => $user_id,
				)
			);
			$start_ts   = strtotime( '+3 days' );
			update_post_meta( $booking_id, '_vkbm_booking_service_start', wp_date( 'Y-m-d H:i:s', $start_ts ) );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', wp_date( 'Y-m-d H:i:s', $start_ts + 3600 ) );
			update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
			update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu['menu_id'] );
			update_post_meta( $booking_id, '_vkbm_booking_service_base_price', 30000 );
			update_post_meta( $booking_id, '_vkbm_booking_guests', 5 );
			if ( null !== $case['booking_unit'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_price_unit', $case['booking_unit'] );
			}

			$items = ( new My_Bookings_Controller() )->get_bookings( new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' ) )->get_data();
			$by_id = array_column( $items, null, 'id' );

			$this->assertSame( $case['expected_total'], (int) $by_id[ $booking_id ]['total_price'], $case['test_condition_name'] . ' / 合計' );
		}
	}
}
