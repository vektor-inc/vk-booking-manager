<?php
/**
 * 貸し切り予約の受付停止が確定・下書きの両コントローラで効くことのテスト（#304）。
 *
 * 確定（check_capacity_with_mutex のロック保持下の再判定）と下書き保存の双方で、
 * 既存の貸し切り予約がある時間帯は残席があっても 409 で弾かれることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function get_option;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * 貸し切り予約の受付停止（確定・下書き）テスト。
 *
 * @group bookings
 * @group exclusive
 */
class Exclusive_Booking_Controllers_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var string */
	private $original_timezone_string = '';

	/** @var array<int, string> */
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->disable_nomination();
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 指名OFF・複数人予約ON（貸し切りが意味を持つ前提）を整える。
	 */
	private function disable_nomination(): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * create_booking: 既存の貸し切り予約がある時間帯は、残席があっても capacity_exceeded(409) で弾く。
	 *
	 * セキュリティ回帰の作法: 防御を入れた状態で 409 になることに加え、
	 * 「貸し切りでない通常予約（残席あり）」では成功することを対で確認し、
	 * exclusive 判定が効いていること（=判定を外すと素通りする経路）を示す。
	 */
	public function test_create_booking(): void {
		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '既存の貸し切り予約あり・残席ありでも => capacity_exceeded(409)（防御）',
				'existing_exclusive'  => true,
				'cancelled_decoy'     => false,
				'expect_error'        => 'capacity_exceeded',
			),
			array(
				'test_condition_name' => '既存予約が貸し切りでない・残席あり => 予約成立（回帰・ミューテーション対照）',
				'existing_exclusive'  => false,
				'cancelled_decoy'     => false,
				'expect_error'        => null,
			),
			array(
				// posts_per_page=1 の最適化で、キャンセル済み貸し切り予約が先頭に来ても
				// 有効な貸し切り予約を見落とさないことを確認する（クエリ段階の status 除外の回帰防止）。
				'test_condition_name' => 'キャンセル済み貸し切り＋有効な貸し切りが同枠 => capacity_exceeded(409)（取得1件最適化の回帰防止）',
				'existing_exclusive'  => true,
				'cancelled_decoy'     => true,
				'expect_error'        => 'capacity_exceeded',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			// 最大受付数3・スタッフ割当（残席を確保）。
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			$date  = sprintf( '2026-08-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			// キャンセル済みの貸し切り予約を先に1件投入する（取得順で先頭に来うる「おとり」）。
			// クエリ段階で status 除外していないと、posts_per_page=1 でこれが返り誤って素通りする。
			if ( $case['cancelled_decoy'] ) {
				$decoy = $this->create_booking_post( $staff_id, $menu_id, $start, $end );
				update_post_meta( $decoy, '_vkbm_booking_guests', 1 );
				update_post_meta( $decoy, '_vkbm_booking_exclusive', true );
				update_post_meta( $decoy, '_vkbm_booking_status', 'cancelled' );
			}

			// 既存予約を1件作成（残席2あり）。
			$existing = $this->create_booking_post( $staff_id, $menu_id, $start, $end );
			update_post_meta( $existing, '_vkbm_booking_guests', 1 );
			if ( $case['existing_exclusive'] ) {
				update_post_meta( $existing, '_vkbm_booking_exclusive', true );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
				// エラーコードだけでなく HTTP 409（競合）ステータスも返ることを確認する。
				$error_data = $response->get_error_data();
				$this->assertSame( 409, (int) ( $error_data['status'] ?? 0 ), $case['test_condition_name'] . ' / 409 ステータス' );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 妥当性ゲートを満たさないメニューでは、貸し切り設定メタが立っていても
	 * 予約レコードに _vkbm_booking_exclusive を付与しないことを検証する（#2/#6 の判定側ガード回帰防止）。
	 *
	 * メニューに _vkbm_exclusive_when_booked=true が残っていても、メニューの複数人予約許可
	 * （_vkbm_allow_multiple_guests）がOFFなら is_menu_exclusive_when_booked() が false を返し、
	 * 確定時に貸し切りフラグを付けない（=後続予約の受付停止が効かない）。
	 *
	 * 判定側のフルゲートを外す（メタ値だけ見る）と、このアサートが FAIL する。
	 */
	public function test_create_booking_exclusive_gate(): void {
		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '複数人予約許可ON＋貸し切りON => 予約に exclusive フラグが付く（正常系・ゲート通過）',
				'allow_multiple'      => true,
				'expect_exclusive'    => true,
			),
			array(
				'test_condition_name' => '複数人予約許可OFF＋貸し切りON（stale）=> exclusive フラグは付かない（防御）',
				'allow_multiple'      => false,
				'expect_exclusive'    => false,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			// 貸し切り設定メタは常に立てる。許可フラグの有無でゲート通過可否を切り替える。
			update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			if ( $case['allow_multiple'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			}

			$date  = sprintf( '2026-10-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end );
			$controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$exclusive = (bool) get_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
			$this->assertSame( $case['expect_exclusive'], $exclusive, $case['test_condition_name'] );

			wp_set_current_user( 0 );
		}
	}

	/**
	 * save_draft: 既存の貸し切り予約がある時間帯は、残席があっても capacity_exceeded(409) で弾く。
	 */
	public function test_save_draft(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既存の貸し切り予約あり => capacity_exceeded(409)（防御）',
				'existing_exclusive'  => true,
				'expect_error'        => 'capacity_exceeded',
			),
			array(
				'test_condition_name' => '既存予約が貸し切りでない => 下書き保存成功（回帰・ミューテーション対照）',
				'existing_exclusive'  => false,
				'expect_error'        => null,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			$date  = sprintf( '2026-09-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$existing = $this->create_booking_post( $staff_id, $menu_id, $start, $end );
			update_post_meta( $existing, '_vkbm_booking_guests', 1 );
			if ( $case['existing_exclusive'] ) {
				update_post_meta( $existing, '_vkbm_booking_exclusive', true );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );

			$controller = new Booking_Draft_Controller( new Settings_Repository() );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/booking-drafts' );
			$request->set_body_params(
				array(
					'menu_id'     => $menu_id,
					'resource_id' => $staff_id,
					'date'        => $date,
					'slot'        => array(
						'slot_id'  => 'slot-1',
						'start_at' => $start,
						'end_at'   => $end,
					),
					'meta'        => array( 'timezone' => 'Asia/Tokyo' ),
				)
			);
			// set_body_params だけでは get_json_params() が読まないため、JSON も明示的に与える。
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'menu_id'     => $menu_id,
						'resource_id' => $staff_id,
						'date'        => $date,
						'slot'        => array(
							'slot_id'  => 'slot-1',
							'start_at' => $start,
							'end_at'   => $end,
						),
						'meta'        => array( 'timezone' => 'Asia/Tokyo' ),
					)
				)
			);

			$response = $controller->save_draft( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
				// エラーコードだけでなく HTTP 409（競合）ステータスも返ることを確認する。
				$error_data = $response->get_error_data();
				$this->assertSame( 409, (int) ( $error_data['status'] ?? 0 ), $case['test_condition_name'] . ' / 409 ステータス' );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$token = (string) ( $response->get_data()['token'] ?? '' );
				if ( '' !== $token ) {
					$this->tokens[] = $token;
				}
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * 確定コントローラ（貸し切り判定が実DBクエリで効く）を組み立てる。
	 *
	 * @param int    $staff_id スタッフID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return Booking_Confirmation_Controller
	 */
	private function build_confirmation_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		$settings = new Settings_Repository();
		return new Booking_Confirmation_Controller(
			new Exclusive_Notification_Test_Double(),
			$settings,
			new Exclusive_Availability_Test_Double(
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
	 * 予約一時データ（下書き transient）を保存する。
	 *
	 * @param int    $menu_id  メニューID。
	 * @param int    $staff_id スタッフID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return string トークン。
	 */
	private function store_temporary_reservation_data( int $menu_id, int $staff_id, string $start_at, string $end_at ): string {
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => 1,
			'slot'                 => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			),
			'assignable_staff_ids' => array( $staff_id ),
			'meta'                 => array( 'timezone' => 'Asia/Tokyo' ),
			'owner_user_id'        => (int) get_current_user_id(),
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * スタッフを作成する。
	 *
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * サービスメニューを作成する。
	 *
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 予約投稿を作成する（confirmed）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $menu_id  メニューID。
	 * @param string $start_at 開始日時（ISO8601）。
	 * @param string $end_at   終了日時（ISO8601）。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $staff_id, int $menu_id, string $start_at, string $end_at ): int {
		$booking_id    = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$start_storage = wp_date( 'Y-m-d H:i:s', strtotime( $start_at ) );
		$end_storage   = wp_date( 'Y-m-d H:i:s', strtotime( $end_at ) );

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start_storage );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end_storage );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );

		return $booking_id;
	}
}

/**
 * 通知を抑止するテストダブル。
 */
class Exclusive_Notification_Test_Double extends Booking_Notification_Service {
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
class Exclusive_Availability_Test_Double extends Availability_Service {
	/** @var array<string, mixed> */
	private array $slot;

	/**
	 * @param array<string, mixed> $slot 返却するスロット。
	 */
	public function __construct( array $slot ) {
		$this->slot = $slot;
	}

	public function get_daily_slots( array $args ) {
		return array( 'slots' => array( $this->slot ) );
	}
}
