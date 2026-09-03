<?php
/**
 * 1枠あたりの最大予約受付数が1以下のときに、複数人予約相乗りが前提の設定（貸し切り・料金区分・最少催行人数）が
 * サーバ側で無効化されることを検証するテスト（#320）。
 *
 * 表示制御（管理画面の hidden）は迂回可能なため、保存済みメタが残っていても・改ざん経路で値を仕込んでも、
 * 確定コントローラ create_booking の出力（保存メタ・合計金額）として無効化されていることを確認する。
 *
 * 検証する3点（max_capacity=1 のメニューに各設定を盛った状態で）:
 *  (a) 貸し切り料金が0（exclusive フラグも付かない）。
 *  (b) 料金区分が無効で、合計は基本料金×人数（区分料金ではない）。
 *  (c) 最少催行人数の判定が効かない（人数不足でも貸切拒否されず通常予約が成立する）。
 *
 * ミューテーション検証: 追加したゲート条件（max_capacity <= 1）を外すと、これらのケースが
 * 期待値からずれて FAIL することを確認できるよう、max_capacity=2 の対照ケースも併記している。
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
use function get_post_meta;
use function metadata_exists;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * 最大受付数1以下での複数人予約系設定の無効化テスト（#320）。
 *
 * @group bookings
 * @group exclusive
 */
class Max_Capacity_Disables_Multi_Guest_Settings_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/** @var string */
	private $original_timezone_string = '';

	/** @var mixed setUp で上書きするプロバイダ設定の元値（tearDown で復元）。 */
	private $original_settings = null;

	/** @var array<int, string> */
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, null );

		// 複数人予約（料金区分・貸し切り）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（料金区分・貸し切り）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		update_option( 'timezone_string', 'Asia/Tokyo' );
		// 指名OFF・複数人予約ON（複数人予約系の設定が意味を持つ前提）。
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
	 * create_booking: 最大受付数1以下のメニューでは貸し切り（自動貸切・ユーザー貸切）・最少催行人数が無効化される。
	 *
	 * - (a)(c) max_capacity=1＋自動貸切ON＋最少催行3・申込2名（user_exclusive なし）
	 *   => 貸切無効・通常予約成立・_vkbm_booking_exclusive 無し・貸切料金0。
	 *      最少催行人数の判定も効かない（人数不足でも拒否されず成立する）。
	 * - 改ざん：max_capacity=1＋user_exclusive=true => 貸切は成立せず exclusive_unavailable(409) で拒否される（防御）。
	 * - 対照：max_capacity=2＋自動貸切ON＋user_exclusive=true・申込2名（最少催行2）
	 *   => 貸切が有効になり _vkbm_booking_exclusive=true・貸切料金が合計へ乗る（ゲートを外すと max_capacity=1 ケースと差が消え FAIL）。
	 */
	public function test_is_user_exclusive_selectable(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '最大受付数1＋自動貸切ON＋最少催行3・申込2名（貸切非選択） => 貸切無効・最少催行無効・通常予約成立・貸切料金0（防御）',
				'max_capacity'        => 1,
				'min_capacity'        => 3,
				'per_person'          => 1000,
				'base_price'          => 3000,
				'requested_guests'    => 2,
				'user_exclusive'      => false,
				'expect_error'        => null,
				'expect_exclusive'    => false,
				'expect_fee'          => 0,
				// max_capacity=1 のため人数は1へクランプされる。貸切料金は乗らない => 3000*1。
				'expect_base_total'   => 3000,
			),
			array(
				'test_condition_name' => '最大受付数1＋user_exclusive=true（改ざん） => 貸切成立せず exclusive_unavailable(409) で拒否（防御）',
				'max_capacity'        => 1,
				'min_capacity'        => 0,
				'per_person'          => 1000,
				'base_price'          => 3000,
				'requested_guests'    => 1,
				'user_exclusive'      => true,
				'expect_error'        => 'exclusive_unavailable',
				'expect_exclusive'    => false,
				'expect_fee'          => 0,
				'expect_base_total'   => 0,
			),
			array(
				'test_condition_name' => '最大受付数2＋自動貸切ON＋user_exclusive=true・申込2名（最少催行2） => 貸切有効・貸切料金が合計へ加算（対照）',
				'max_capacity'        => 2,
				'min_capacity'        => 2,
				'per_person'          => 1000,
				'base_price'          => 3000,
				'requested_guests'    => 2,
				'user_exclusive'      => true,
				'expect_error'        => null,
				'expect_exclusive'    => true,
				'expect_fee'          => 2000,
				// 人数2・基本3000・貸切1000/人 => 3000*2 + 1000*2 = 8000。
				'expect_base_total'   => 8000,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			// 複数人予約を許可し、貸切（自動・ユーザー）・最少催行人数・料金を改ざん経路として一通り保存する。
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_base_price', $case['base_price'] );
			update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
			update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', $case['per_person'] );
			if ( $case['min_capacity'] > 0 ) {
				update_post_meta( $menu_id, '_vkbm_min_capacity', $case['min_capacity'] );
			}

			$date  = sprintf( '2027-01-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token      = $this->store_draft( $menu_id, $staff_id, $start, $end, $case['requested_guests'], $case['user_exclusive'], array() );
			$controller = $this->build_controller( $staff_id, $start, $end );
			$request    = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );

			if ( null !== $case['expect_error'] ) {
				// 改ざん経路（user_exclusive 仕込み）は貸切として成立させず拒否されること。
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] . ' / 拒否される' );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] . ' / エラーコード' );
				wp_set_current_user( 0 );
				continue;
			}

			// (c) 最少催行人数の判定が効いていれば人数不足で拒否されるはずだが、max_capacity=1 では無効のため成立する。
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ' / 予約成立' );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] . ' / 予約ID' );

			// (a) 貸切フラグ・貸切料金スナップショットの有無。
			$exclusive_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive' );
			$this->assertSame( $case['expect_exclusive'], $exclusive_exists, $case['test_condition_name'] . ' / 貸切フラグのキー存在' );

			$fee_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive_fee' );
			$this->assertSame( $case['expect_fee'] > 0, $fee_exists, $case['test_condition_name'] . ' / 貸切料金スナップショットのキー存在' );
			if ( $case['expect_fee'] > 0 ) {
				$fee = (int) get_post_meta( $booking_id, '_vkbm_booking_exclusive_fee', true );
				$this->assertSame( $case['expect_fee'], $fee, $case['test_condition_name'] . ' / 貸切料金の値' );
			}

			$base_total = (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true );
			$this->assertSame( $case['expect_base_total'], $base_total, $case['test_condition_name'] . ' / 合計金額' );

			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 最大受付数1以下のメニューでは料金区分が無効化され、基本料金×人数で計算される。
	 *
	 * - max_capacity=1＋料金区分定義＋区分人数2名 => 区分は無効（空扱い）。
	 *   人数は1へクランプされ、合計は基本料金×1（区分料金 Σ ではない）。_vkbm_booking_guest_tiers は保存されない。
	 * - 対照：max_capacity=2 では区分が有効になり、区分人数2名の区分料金合計で計算される
	 *   （ゲートを外すと両ケースが同じ結果になり FAIL）。
	 */
	public function test_resolve_menu_price_tiers(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '最大受付数1＋料金区分定義＋区分人数2名 => 区分無効・基本料金×1（防御）',
				'max_capacity'        => 1,
				'base_price'          => 3000,
				// 大人5000×2名 = 10000 になってはいけない（防御版は基本3000×1）。
				'tier_price'          => 5000,
				'tier_count'          => 2,
				'expect_has_tiers'    => false,
				'expect_base_total'   => 3000,
			),
			array(
				'test_condition_name' => '最大受付数2＋料金区分定義＋区分人数2名 => 区分有効・区分料金合計（対照）',
				'max_capacity'        => 2,
				'base_price'          => 3000,
				'tier_price'          => 5000,
				'tier_count'          => 2,
				'expect_has_tiers'    => true,
				// 区分料金 5000×2名 = 10000（基本料金×人数ではない）。
				'expect_base_total'   => 10000,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_base_price', $case['base_price'] );
			// 料金区分（大人）を1件定義する。
			update_post_meta(
				$menu_id,
				'_vkbm_price_tiers',
				array(
					array(
						'label' => '大人',
						'price' => $case['tier_price'],
					),
				)
			);

			$date  = sprintf( '2027-02-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			// 下書きに区分人数の内訳（大人2名）を仕込む（改ざん経路）。
			$guest_tiers = array(
				array(
					'label' => '大人',
					'price' => $case['tier_price'],
					'count' => $case['tier_count'],
				),
			);
			$token       = $this->store_draft( $menu_id, $staff_id, $start, $end, $case['tier_count'], false, $guest_tiers );
			$controller  = $this->build_controller( $staff_id, $start, $end );
			$request     = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );

			$response = $controller->create_booking( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ' / 予約成立' );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] . ' / 予約ID' );

			// 料金区分が有効なら区分内訳スナップショットが保存される。無効なら保存されない。
			$tiers_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_guest_tiers' );
			$this->assertSame( $case['expect_has_tiers'], $tiers_exists, $case['test_condition_name'] . ' / 区分内訳の保存有無' );

			$base_total = (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true );
			$this->assertSame( $case['expect_base_total'], $base_total, $case['test_condition_name'] . ' / 合計金額' );

			wp_set_current_user( 0 );
		}
	}

	/**
	 * 確定コントローラ（貸切判定・空き再検証が実DBで効く）を組み立てる。
	 *
	 * @param int    $staff_id スタッフID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return Booking_Confirmation_Controller
	 */
	private function build_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		$settings = new Settings_Repository();
		return new Booking_Confirmation_Controller(
			new Max_Capacity_Notification_Test_Double(),
			$settings,
			new Max_Capacity_Availability_Test_Double(
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
	 * @param int                          $menu_id        メニューID。
	 * @param int                          $staff_id       スタッフID。
	 * @param string                       $start_at       スロット開始（ISO8601）。
	 * @param string                       $end_at         スロット終了（ISO8601）。
	 * @param int                          $guests         申込人数。
	 * @param bool                         $user_exclusive ユーザー貸切選択フラグ。
	 * @param array<int, array<string, mixed>> $guest_tiers 料金区分の人数内訳（区分未使用なら空配列）。
	 * @return string トークン。
	 */
	private function store_draft( int $menu_id, int $staff_id, string $start_at, string $end_at, int $guests, bool $user_exclusive, array $guest_tiers ): string {
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => $guests,
			'guest_tiers'          => $guest_tiers,
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
}

/**
 * 通知を抑止するテストダブル。
 */
class Max_Capacity_Notification_Test_Double extends Booking_Notification_Service {
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
class Max_Capacity_Availability_Test_Double extends Availability_Service {
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
