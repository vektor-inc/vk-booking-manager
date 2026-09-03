<?php
/**
 * ユーザーによる貸し切り指定（#305）のサーバ側ガードと料金スナップショットのテスト。
 *
 * 確定コントローラ create_booking で、ユーザー貸切選択時に
 * - 既存予約のある枠では 409（exclusive_unavailable）で拒否される（最初の予約者のみ貸切）
 * - 最小催行人数未満では 400（exclusive_min_capacity）で拒否される
 * - 正常系では予約に _vkbm_booking_exclusive と _vkbm_booking_exclusive_fee が付与され合計に貸切料金が乗る
 * を検証する。防御の有無を対で確認できるよう、貸切非選択の対照ケースも置く。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
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
use function delete_option;
use function get_option;
use function metadata_exists;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * ユーザー貸し切り指定（#305）のテスト。
 *
 * @group bookings
 * @group exclusive
 */
class User_Exclusive_Booking_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var string */
	private $original_timezone_string = '';

	/**
	 * setUp で上書きするプロバイダ設定オプションの元値（tearDown で復元し後続テストの汚染を防ぐ）。
	 *
	 * @var mixed
	 */
	private $original_settings = null;

	/** @var array<int, string> */
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, null );

		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		update_option( 'timezone_string', 'Asia/Tokyo' );
		// 指名OFF・複数人予約ON（ユーザー貸し切り指定が意味を持つ前提）。
		// 上書き前の元値を保持し、tearDown で復元する（他テストへの汚染防止）。
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		unset( $_COOKIE[ self::OWNER_COOKIE ] );
		update_option( 'timezone_string', $this->original_timezone_string );
		// setUp で上書きしたプロバイダ設定を元へ戻す。元が無ければ削除して初期状態へ戻す。
		if ( null === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * create_booking: ユーザー貸し切り指定時のサーバ側ガードを検証する。
	 *
	 * - 既存予約あり＋ユーザー貸切選択 => exclusive_unavailable(409)（防御）。
	 * - 既存予約あり＋貸切非選択 => 予約成立（残席あり・回帰対照／防御を外すと両方素通り）。
	 * - 最小催行人数未満＋ユーザー貸切選択 => exclusive_min_capacity(400)（防御）。
	 * - メニューが貸切不可へ変化＋user_exclusive=true => exclusive_unavailable(409)（黙って通常予約化しない・#305）。
	 */
	public function test_create_booking(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既存予約あり＋ユーザー貸切選択 => exclusive_unavailable(409)（最初の予約者のみ貸切・防御）',
				'existing_booking'    => true,
				'min_capacity'        => 0,
				'guests'              => 1,
				'user_exclusive'      => true,
				'expect_error'        => 'exclusive_unavailable',
				'expect_status'       => 409,
			),
			array(
				'test_condition_name' => '既存予約あり＋貸切非選択 => 予約成立（残席あり・回帰対照）',
				'existing_booking'    => true,
				'min_capacity'        => 0,
				'guests'              => 1,
				'user_exclusive'      => false,
				'expect_error'        => null,
				'expect_status'       => 0,
			),
			array(
				'test_condition_name' => '最小催行人数3・申込2名＋ユーザー貸切選択 => exclusive_min_capacity(400)（人数不足・防御）',
				'existing_booking'    => false,
				'min_capacity'        => 3,
				'guests'              => 2,
				'user_exclusive'      => true,
				'expect_error'        => 'exclusive_min_capacity',
				'expect_status'       => 400,
			),
			array(
				'test_condition_name' => '最小催行人数3・申込3名＋ユーザー貸切選択 => 予約成立（最小催行人数を満たす・対照）',
				'existing_booking'    => false,
				'min_capacity'        => 3,
				'guests'              => 3,
				'user_exclusive'      => true,
				'selectable'          => true,
				'expect_error'        => null,
				'expect_status'       => 0,
			),
			array(
				// 下書き保存後にメニュー設定が貸切不可（受付OFF）へ変わったケース。
				// 黙って通常予約に変換せず 409 で拒否し、利用者の明示的な貸切指定を失わせない（#305 / 司の決定）。
				'test_condition_name' => 'メニューが貸切不可（selectable OFF）＋user_exclusive=true の下書き確定 => exclusive_unavailable(409)（黙って通常予約化しない）',
				'existing_booking'    => false,
				'min_capacity'        => 0,
				'guests'              => 1,
				'user_exclusive'      => true,
				'selectable'          => false,
				'expect_error'        => 'exclusive_unavailable',
				'expect_status'       => 409,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			// 既定はユーザー貸し切り指定を受け付ける設定。selectable=false のケースのみ設定しない（=貸切不可）。
			if ( ! array_key_exists( 'selectable', $case ) || $case['selectable'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
			}
			if ( $case['min_capacity'] > 0 ) {
				update_post_meta( $menu_id, '_vkbm_min_capacity', $case['min_capacity'] );
			}

			$date  = sprintf( '2026-11-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			if ( $case['existing_booking'] ) {
				// 貸し切りでない通常予約を1件入れる（残席は残る）。
				$existing = $this->create_booking_post( $staff_id, $menu_id, $start, $end );
				update_post_meta( $existing, '_vkbm_booking_guests', 1 );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, $case['guests'], $case['user_exclusive'] );
			$controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] );
				$error_data = $response->get_error_data();
				$this->assertSame( $case['expect_status'], (int) ( $error_data['status'] ?? 0 ), $case['test_condition_name'] . ' / ステータス' );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: ユーザー貸切選択の正常系で、予約に貸切フラグと貸切料金が保存され合計へ加算される。
	 *
	 * 貸し切り料金 = per_person × 申込人数（適用外人数未達）。base_total には貸切料金込みで保存される。
	 */
	public function test_create_booking_exclusive_fee(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '単価1000・適用外人数なし・申込2名・貸切選択 => exclusive=true, fee=2000, base_total=base*2+2000',
				'per_person'          => 1000,
				'exempt_guests'       => 0,
				'guests'              => 2,
				'user_exclusive'      => true,
				'base_price'          => 3000,
				'expect_exclusive'    => true,
				'expect_fee'          => 2000,
				'expect_base_total'   => 8000, // 3000*2 + 2000.
			),
			array(
				'test_condition_name' => '単価1000・適用外人数2・申込2名（= 適用外＝以上）・貸切選択 => exclusive=true, fee=0, base_total=base*2',
				'per_person'          => 1000,
				'exempt_guests'       => 2,
				'guests'              => 2,
				'user_exclusive'      => true,
				'base_price'          => 3000,
				'expect_exclusive'    => true,
				'expect_fee'          => 0,
				'expect_base_total'   => 6000, // 3000*2 + 0.
			),
			array(
				'test_condition_name' => '貸切非選択 => exclusive=false, fee=0, base_total=base*2（対照）',
				'per_person'          => 1000,
				'exempt_guests'       => 0,
				'guests'              => 2,
				'user_exclusive'      => false,
				'base_price'          => 3000,
				'expect_exclusive'    => false,
				'expect_fee'          => 0,
				'expect_base_total'   => 6000, // 3000*2 + 0.
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_base_price', $case['base_price'] );
			update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
			update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', $case['per_person'] );
			if ( $case['exempt_guests'] > 0 ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_fee_exempt_guests', $case['exempt_guests'] );
			}

			$date  = sprintf( '2026-12-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, $case['guests'], $case['user_exclusive'] );
			$controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			// 「予約レコードにスナップショットを保存する」契約を検証するため、まずメタキーの存在を確認する。
			// (bool)/(int) キャストだけだと、メタ未保存（キー不在）でも false/0 を返して通ってしまい、
			// 保存有無を取り違えても気付けないため、metadata_exists() でキー存在を先に assert する。
			//
			// 契約: 貸切のとき _vkbm_booking_exclusive=true を保存し、非貸切のときキーを削除する。
			//       貸切料金 > 0 のとき _vkbm_booking_exclusive_fee を保存し、0 のときキーを削除する。
			$exclusive_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive' );
			$this->assertSame( $case['expect_exclusive'], $exclusive_exists, $case['test_condition_name'] . ' / 貸切フラグのキー存在' );
			if ( $case['expect_exclusive'] ) {
				$exclusive = (bool) get_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
				$this->assertTrue( $exclusive, $case['test_condition_name'] . ' / 貸切フラグの値' );
			}

			$fee_exists      = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive_fee' );
			$expect_fee_meta = $case['expect_fee'] > 0;
			$this->assertSame( $expect_fee_meta, $fee_exists, $case['test_condition_name'] . ' / 貸切料金スナップショットのキー存在' );
			if ( $expect_fee_meta ) {
				$fee = (int) get_post_meta( $booking_id, '_vkbm_booking_exclusive_fee', true );
				$this->assertSame( $case['expect_fee'], $fee, $case['test_condition_name'] . ' / 貸切料金スナップショットの値' );
			}

			$base_total = (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true );
			$this->assertSame( $case['expect_base_total'], $base_total, $case['test_condition_name'] . ' / 合計（貸切料金込み）' );

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
			new User_Exclusive_Notification_Test_Double(),
			$settings,
			new User_Exclusive_Availability_Test_Double(
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
	 * @param int    $menu_id        メニューID。
	 * @param int    $staff_id       スタッフID。
	 * @param string $start_at       スロット開始（ISO8601）。
	 * @param string $end_at         スロット終了（ISO8601）。
	 * @param int    $guests         申込人数。
	 * @param bool   $user_exclusive ユーザー貸切選択フラグ。
	 * @return string トークン。
	 */
	private function store_temporary_reservation_data( int $menu_id, int $staff_id, string $start_at, string $end_at, int $guests, bool $user_exclusive ): string {
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => $guests,
			'user_exclusive'       => $user_exclusive,
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
class User_Exclusive_Notification_Test_Double extends Booking_Notification_Service {
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
class User_Exclusive_Availability_Test_Double extends Availability_Service {
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
