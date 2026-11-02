<?php
/**
 * 「1ツアーあたり」で規定人数を超えた分の追加料金を使うメニューの合計（下書き・確定・通知メール・管理画面の予約詳細・マイページ）のテスト（#1003）。
 *
 * 下書き・確定・メールで同じ合計（基本料金 ＋ 超過人数分の区分単価の合計）になることを確かめる。
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
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

require_once __DIR__ . '/support/class-per-booking-notification-test-double.php';
require_once __DIR__ . '/support/class-per-booking-availability-test-double.php';

/**
 * 1ツアーあたりの追加料金の合計・予約時点の内訳の保存・表示のテスト。
 *
 * @group bookings
 * @group price-unit
 * @group extra-guest
 */
class Booking_Price_Extra_Guest_Test extends WP_UnitTestCase {
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
			$this->markTestSkipped( '追加料金（1ツアーあたり）は Pro 版の業種プリセット（アクティビティ）限定のため、無料版ではスキップする。' );
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
	 * 追加料金ありの「1ツアーあたり」メニュー（基本料金30000円・定員8・4名まで含む・大人3000円/子供1500円）とスタッフを作る。
	 *
	 * @param array<string, mixed> $meta 上書き・追加するメニューメタ。
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
		$defaults = array(
			'_vkbm_staff_ids'             => array( $staff_id ),
			'_vkbm_max_capacity'          => 8,
			'_vkbm_base_price'            => 30000,
			'_vkbm_price_unit'            => 'per_booking',
			'_vkbm_extra_guest_enabled'   => '1',
			'_vkbm_included_guests'       => 4,
			'_vkbm_allow_multiple_guests' => true,
			'_vkbm_price_tiers'           => array(
				array(
					'label' => '大人',
					'price' => 3000,
				),
				array(
					'label' => '子供',
					'price' => 1500,
				),
			),
		);
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $menu_id, $key );
				continue;
			}
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
	 * @param int                  $menu_id     メニュー ID。
	 * @param array<string, mixed> $guest_input 人数の送信値（guests または guest_tiers）。
	 * @return array<string, mixed>
	 */
	private function save_and_read_draft( int $menu_id, array $guest_input ): array {
		wp_set_current_user( $this->factory()->user->create() );
		$controller = new Booking_Draft_Controller();

		$save = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$save->set_header( 'content-type', 'application/json' );
		$save->set_body(
			wp_json_encode(
				array_merge(
					array(
						'menu_id' => $menu_id,
						'date'    => '2026-11-10',
						'slot'    => array(
							'slot_id'  => 'slot-1',
							'start_at' => '2026-11-10T10:00:00+09:00',
							'end_at'   => '2026-11-10T11:00:00+09:00',
						),
						'meta'    => array( 'timezone' => 'Asia/Tokyo' ),
					),
					$guest_input
				)
			)
		);
		$saved = $controller->save_draft( $save );
		$this->assertInstanceOf( WP_REST_Response::class, $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '下書きを保存できる' );
		$token          = (string) $saved->get_data()['token'];
		$this->tokens[] = $token;

		$get = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $token );
		$get->set_url_params( array( 'token' => $token ) );
		$read = $controller->get_draft( $get );
		$this->assertInstanceOf( WP_REST_Response::class, $read, is_wp_error( $read ) ? $read->get_error_message() : '下書きを読み出せる' );

		return $read->get_data();
	}

	/**
	 * get_draft: 基本料金 ＋ 超過人数分の区分単価の合計になり、超過の内訳を返す。
	 */
	public function test_draft_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '大人4名＋子供1名（4名まで含む） => 子供1名が超過で 30000 + 1500 = 31500',
				'site'                => 'activity',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 4, 1 ) ),
				'expected_guests'     => 5,
				'expected_total'      => 31500,
				'expected_extra'      => 1500,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(
					array(
						'label' => '子供',
						'count' => 1,
					),
				),
			),
			array(
				'test_condition_name' => '大人2名＋子供2名（4名まで含む） => 超過なしで 30000',
				'site'                => 'activity',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 2, 2 ) ),
				'expected_guests'     => 4,
				'expected_total'      => 30000,
				'expected_extra'      => 0,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(),
			),
			array(
				'test_condition_name' => '大人6名（4名まで含む） => 大人2名が超過で 30000 + 3000 × 2 = 36000',
				'site'                => 'activity',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 6, 0 ) ),
				'expected_guests'     => 6,
				'expected_total'      => 36000,
				'expected_extra'      => 6000,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(
					array(
						'label' => '大人',
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => '大人3名＋子供3名（4名まで含む） => 単価の高い大人から含めるので子供2名が超過で 30000 + 3000 = 33000',
				'site'                => 'activity',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 3, 3 ) ),
				'expected_guests'     => 6,
				'expected_total'      => 33000,
				'expected_extra'      => 3000,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(
					array(
						'label' => '子供',
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => '追加料金をオフ（保存なし）・人数5 => 区分は使わず 30000 のまま',
				'site'                => 'activity',
				'meta'                => array( '_vkbm_extra_guest_enabled' => null ),
				'guest_input'         => array( 'guests' => 5 ),
				'expected_guests'     => 5,
				'expected_total'      => 30000,
				'expected_extra'      => 0,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(),
			),
			array(
				'test_condition_name' => '追加料金オンでも料金区分が0件・人数5 => 追加料金は加算されず 30000',
				'site'                => 'activity',
				'meta'                => array( '_vkbm_price_tiers' => null ),
				'guest_input'         => array( 'guests' => 5 ),
				'expected_guests'     => 5,
				'expected_total'      => 30000,
				'expected_extra'      => 0,
				'expected_unit'       => 'per_booking',
				'expected_excess'     => array(),
			),
			array(
				'test_condition_name' => 'カスタムへ切り替えた後 => 追加料金の保存値は効かず、1名あたりの区分料金（大人1名 = 3000）',
				'site'                => 'custom',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 1, 0 ) ),
				'expected_guests'     => 1,
				'expected_total'      => 3000,
				'expected_extra'      => 0,
				'expected_unit'       => 'per_guest',
				'expected_excess'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['site'] );
			$menu = $this->create_menu_with_staff( $case['meta'] );

			$draft = $this->save_and_read_draft( $menu['menu_id'], $case['guest_input'] );

			$this->assertSame( $case['expected_guests'], (int) $draft['guests'], $case['test_condition_name'] . ' / 人数' );
			$this->assertSame( $case['expected_total'], (int) $draft['total_price'], $case['test_condition_name'] . ' / 合計' );
			$this->assertSame( $case['expected_unit'], $draft['price_unit'], $case['test_condition_name'] . ' / 料金の単位' );
			$this->assertSame( $case['expected_extra'], (int) $draft['extra_guest']['total'], $case['test_condition_name'] . ' / 追加料金' );

			$excess = array_map(
				static function ( array $tier ): array {
					return array(
						'label' => $tier['label'],
						'count' => $tier['count'],
					);
				},
				$draft['extra_guest']['tiers']
			);
			$this->assertSame( $case['expected_excess'], $excess, $case['test_condition_name'] . ' / 超過の内訳' );
		}
	}

	/**
	 * get_draft: 下書き保存後にメニューの含まれる人数を変えても、読み出し時の合計は現在の設定で再計算される。
	 */
	public function test_draft_total_follows_current_included_guests(): void {
		$menu = $this->create_menu_with_staff();

		$draft = $this->save_and_read_draft( $menu['menu_id'], array( 'guest_tiers' => array( 4, 1 ) ) );
		$this->assertSame( 31500, (int) $draft['total_price'], '含まれる人数4 => 31500' );
		$this->assertSame( 4, (int) $draft['extra_guest']['included_guests'], '含まれる人数を返す' );

		update_post_meta( $menu['menu_id'], '_vkbm_included_guests', 5 );
		$get = new WP_REST_Request( 'GET', '/vkbm/v1/drafts/' . $this->tokens[0] );
		$get->set_url_params( array( 'token' => $this->tokens[0] ) );
		$read = ( new Booking_Draft_Controller() )->get_draft( $get );

		$this->assertSame( 30000, (int) $read->get_data()['total_price'], '含まれる人数を5へ増やした後 => 超過なしで 30000' );
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
	 * @param array{menu_id: int, staff_id: int} $menu        メニューとスタッフ。
	 * @param array<string, mixed>               $guest_input 人数の送信値（guests または guest_tiers）。
	 * @param string                             $date        予約日（Y-m-d）。
	 * @return int 予約 ID。
	 */
	private function confirm_booking( array $menu, array $guest_input, string $date ): int {
		$start = $date . 'T10:00:00+09:00';
		$end   = $date . 'T11:00:00+09:00';

		wp_set_current_user( $this->factory()->user->create() );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token          = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$this->tokens[] = $token;
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array_merge(
				array(
					'menu_id'       => $menu['menu_id'],
					'resource_id'   => $menu['staff_id'],
					'slot'          => array(
						'slot_id'  => 'slot-1',
						'start_at' => $start,
						'end_at'   => $end,
					),
					'meta'          => array( 'timezone' => 'Asia/Tokyo' ),
					'owner_user_id' => (int) get_current_user_id(),
				),
				$guest_input
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
	 * create_booking: 合計が基本料金 ＋ 追加料金になり、予約時点の追加料金の内訳を保存する。
	 */
	public function test_create_booking_total_and_extra_snapshot(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '大人4名＋子供1名 => 合計31500・超過の内訳（子供1名）と含まれる人数4を保存・区分の人数内訳も保存',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 4, 1 ) ),
				'expected_total'      => 31500,
				'expected_guests'     => 5,
				'expected_extra'      => array(
					'included_guests' => 4,
					'tiers'           => array(
						array(
							'label' => '子供',
							'price' => 1500,
							'count' => 1,
						),
					),
				),
				'expect_guest_tiers'  => true,
			),
			array(
				'test_condition_name' => '大人2名＋子供2名（超過なし） => 合計30000・超過の内訳は保存しない',
				'meta'                => array(),
				'guest_input'         => array( 'guest_tiers' => array( 2, 2 ) ),
				'expected_total'      => 30000,
				'expected_guests'     => 4,
				'expected_extra'      => null,
				'expect_guest_tiers'  => true,
			),
			array(
				'test_condition_name' => '追加料金オフ・人数5 => 合計30000・超過の内訳も区分の人数内訳も保存しない',
				'meta'                => array( '_vkbm_extra_guest_enabled' => null ),
				'guest_input'         => array( 'guests' => 5 ),
				'expected_total'      => 30000,
				'expected_guests'     => 5,
				'expected_extra'      => null,
				'expect_guest_tiers'  => false,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff( $case['meta'] );
			$booking_id = $this->confirm_booking( $menu, $case['guest_input'], sprintf( '2026-12-%02d', $index + 1 ) );

			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
			$this->assertSame( $case['expected_total'], (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), $case['test_condition_name'] . ' / 合計' );
			$this->assertSame( $case['expected_guests'], (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), $case['test_condition_name'] . ' / 人数' );
			$this->assertSame( 'per_booking', get_post_meta( $booking_id, '_vkbm_booking_price_unit', true ), $case['test_condition_name'] . ' / 料金の単位' );
			$this->assertSame( 30000, (int) get_post_meta( $booking_id, '_vkbm_booking_service_base_price', true ), $case['test_condition_name'] . ' / ツアー料金は基本料金のスナップショット' );
			$this->assertSame( $case['expect_guest_tiers'], metadata_exists( 'post', $booking_id, '_vkbm_booking_guest_tiers' ), $case['test_condition_name'] . ' / 区分の人数内訳' );

			if ( null === $case['expected_extra'] ) {
				$this->assertFalse( metadata_exists( 'post', $booking_id, '_vkbm_booking_extra_guests' ), $case['test_condition_name'] . ' / 超過の内訳は保存しない' );
			} else {
				$this->assertSame( $case['expected_extra'], get_post_meta( $booking_id, '_vkbm_booking_extra_guests', true ), $case['test_condition_name'] . ' / 超過の内訳' );
			}
		}
	}

	/**
	 * create_booking: 人数が1ツアーの最大人数を超える区分内訳は確定できない。
	 */
	public function test_create_booking_rejects_guests_over_capacity(): void {
		$menu = $this->create_menu_with_staff( array( '_vkbm_max_capacity' => 5 ) );

		wp_set_current_user( $this->factory()->user->create() );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';
		$token                         = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$this->tokens[]                = $token;
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'menu_id'       => $menu['menu_id'],
				'resource_id'   => $menu['staff_id'],
				'guest_tiers'   => array( 4, 2 ),
				'slot'          => array(
					'slot_id'  => 'slot-1',
					'start_at' => '2027-04-01T10:00:00+09:00',
					'end_at'   => '2027-04-01T11:00:00+09:00',
				),
				'meta'          => array( 'timezone' => 'Asia/Tokyo' ),
				'owner_user_id' => (int) get_current_user_id(),
			)
		);
		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $this->build_confirmation_controller( $menu['staff_id'], '2027-04-01T10:00:00+09:00', '2027-04-01T11:00:00+09:00' )->create_booking( $request );

		$this->assertWPError( $response, '最大人数5名に対して6名は確定できない' );
	}

	/**
	 * 確定後メール: 合計に追加料金を含め、ツアー料金と追加料金を分けた内訳の行を添える。
	 */
	public function test_notification_price_label_and_breakdown(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$payload->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '大人4名＋子供1名 => 料金は 31,500・内訳は ツアー料金 30,000 と 追加料金 1,500',
				'guest_input'         => array( 'guest_tiers' => array( 4, 1 ) ),
				'expected_amount'     => '31,500',
				'expected_lines'      => array( '30,000', '1,500' ),
			),
			array(
				'test_condition_name' => '超過なし => 料金は 30,000・内訳の行は出さない',
				'guest_input'         => array( 'guest_tiers' => array( 2, 2 ) ),
				'expected_amount'     => '30,000',
				'expected_lines'      => array(),
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff();
			$booking_id = $this->confirm_booking( $menu, $case['guest_input'], sprintf( '2027-01-%02d', $index + 1 ) );

			$data = $payload->invoke( $service, $booking_id );
			$this->assertStringContainsString( $case['expected_amount'], (string) $data['price_label'], $case['test_condition_name'] . ' / 料金' );
			$this->assertCount( count( $case['expected_lines'] ) > 0 ? 2 : 0, $data['price_breakdown_lines'], $case['test_condition_name'] . ' / 内訳の行数' );
			foreach ( $case['expected_lines'] as $line_index => $expected_amount ) {
				$this->assertStringContainsString( $expected_amount, $data['price_breakdown_lines'][ $line_index ], $case['test_condition_name'] . ' / 内訳の金額' );
			}
		}
	}

	/**
	 * 確認メール（予約の確定前）: 下書きの人数から、追加料金を含めた目安の料金と内訳を出す。
	 */
	public function test_draft_notification_price_label_and_breakdown(): void {
		$menu    = $this->create_menu_with_staff();
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'build_draft_payload' );
		$method->setAccessible( true );

		$data = $method->invoke(
			$service,
			array(
				'menu_id'     => $menu['menu_id'],
				'resource_id' => $menu['staff_id'],
				'guests'      => 5,
				'guest_tiers' => array(
					array(
						'label' => '大人',
						'price' => 3000,
						'count' => 4,
					),
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 1,
					),
				),
			),
			''
		);

		$this->assertStringContainsString( '31,500', (string) $data['price_label'], '追加料金を含めた目安の料金' );
		$this->assertCount( 2, $data['price_breakdown_lines'], 'ツアー料金と追加料金の2行' );
	}

	/**
	 * 確認メールの内訳の行: 指名料があるときだけ指名料の行を足し、内訳の合計が料金と合うようにする。
	 */
	public function test_breakdown_lines_include_nomination_fee_only_when_positive(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'build_extra_guest_breakdown_lines' );
		$method->setAccessible( true );
		$extra = array(
			array(
				'label' => '子供',
				'price' => 1500,
				'count' => 1,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '指名料なし => ツアー料金と追加料金の2行',
				'nomination_fee'      => 0,
				'expected_count'      => 2,
			),
			array(
				'test_condition_name' => '指名料あり => 指名料の行を足した3行',
				'nomination_fee'      => 3000,
				'expected_count'      => 3,
			),
		);

		foreach ( $test_cases as $case ) {
			$lines = $method->invoke( $service, 30000, $extra, $case['nomination_fee'] );
			$this->assertCount( $case['expected_count'], $lines, $case['test_condition_name'] );
			if ( $case['nomination_fee'] > 0 ) {
				$this->assertStringContainsString( '3,000', $lines[2], $case['test_condition_name'] . ' / 指名料の金額' );
				$this->assertStringContainsString( vkbm_get_nomination_fee_label(), $lines[2], $case['test_condition_name'] . ' / 指名料の見出し' );
			}
		}
	}

	/**
	 * 確認メール（予約の確定前）: 指名料あり＋追加料金ありでは、料金に指名料を含め、内訳にも指名料の行を出す。
	 */
	public function test_draft_notification_includes_nomination_fee(): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => 'activity',
					'staff_enabled'         => true,
					'slot_capacity_enabled' => true,
				)
			)
		);
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();

		$menu    = $this->create_menu_with_staff();
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'build_draft_payload' );
		$method->setAccessible( true );

		$data = $method->invoke(
			$service,
			array(
				'menu_id'        => $menu['menu_id'],
				'resource_id'    => $menu['staff_id'],
				'nomination_fee' => 3000,
				'guests'         => 5,
				'guest_tiers'    => array(
					array(
						'label' => '大人',
						'price' => 3000,
						'count' => 4,
					),
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 1,
					),
				),
			),
			''
		);

		$this->assertStringContainsString( '34,500', (string) $data['price_label'], '30000 + 1500 + 指名料3000' );
		$this->assertCount( 3, $data['price_breakdown_lines'], 'ツアー料金・追加料金・指名料の3行' );
	}

	/**
	 * 確認メール（予約の確定前）: 超過した人がいない1ツアーあたりの予約は、従来の料金表記（基本料金のまま）に戻り、内訳の行は出さない。
	 */
	public function test_draft_notification_without_excess_uses_conventional_label(): void {
		$menu    = $this->create_menu_with_staff();
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'build_draft_payload' );
		$method->setAccessible( true );

		$data = $method->invoke(
			$service,
			array(
				'menu_id'     => $menu['menu_id'],
				'resource_id' => $menu['staff_id'],
				'guests'      => 4,
				'guest_tiers' => array(
					array(
						'label' => '大人',
						'price' => 3000,
						'count' => 2,
					),
					array(
						'label' => '子供',
						'price' => 1500,
						'count' => 2,
					),
				),
			),
			''
		);

		$this->assertStringContainsString( '30,000', (string) $data['price_label'], '基本料金のまま' );
		$this->assertSame( array(), $data['price_breakdown_lines'], '内訳の行は出さない' );
		$this->assertStringContainsString( '2', $data['guest_composition'], '人数の構成は添える' );
	}

	/**
	 * メール本文の人数の行: 1ツアーあたりは「5名（大人4名・子供1名）」の形で構成を添え、区分ごとの行は重ねて出さない。1名あたりは従来どおり。
	 */
	public function test_email_guest_line_shows_composition(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$payload = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$payload->setAccessible( true );
		$lines_method = new ReflectionMethod( Booking_Notification_Service::class, 'get_reservation_information_lines' );
		$lines_method->setAccessible( true );

		$menu       = $this->create_menu_with_staff();
		$booking_id = $this->confirm_booking( $menu, array( 'guest_tiers' => array( 4, 1 ) ), '2027-06-01' );

		$data  = $payload->invoke( $service, $booking_id );
		$lines = implode( "\n", $lines_method->invoke( $service, $data ) );

		$this->assertStringContainsString( '大人', $data['guest_composition'], '構成に大人が入る' );
		$this->assertStringContainsString( '子供', $data['guest_composition'], '構成に子供が入る' );
		$this->assertStringContainsString( $data['guest_composition'], $lines, '人数の行に構成が入る' );
		$this->assertStringNotContainsString( '大人: ', $lines, '区分ごとの行（大人: 4名）は重ねて出さない' );
		$this->assertStringNotContainsString( '子供: ', $lines, '区分ごとの行（子供: 1名）は重ねて出さない' );
	}

	/**
	 * 管理画面の予約編集: 保存し直しても、予約時点の追加料金を含む合計を保つ。
	 */
	public function test_admin_save_post_keeps_total_with_extra_guest_fee(): void {
		$menu       = $this->create_menu_with_staff();
		$booking_id = $this->confirm_booking( $menu, array( 'guest_tiers' => array( 4, 1 ) ), '2027-02-01' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

		// 保存後にメニューの設定を変えても、予約時点の内訳で合計を保つ。
		update_post_meta( $menu['menu_id'], '_vkbm_included_guests', 5 );
		update_post_meta(
			$menu['menu_id'],
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '子供',
					'price' => 9999,
				),
			)
		);

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'        => '2027-02-01',
				'start_time'  => '10:00',
				'end_time'    => '11:00',
				'service_id'  => $menu['menu_id'],
				'resource_id' => $menu['staff_id'],
				'status'      => 'confirmed',
				'guests'      => 2,
			),
		);
		( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );

		$this->assertSame( 5, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ), '区分の内訳がある予約は人数を編集できない' );
		$this->assertSame( 31500, (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ), '合計は予約時点の追加料金を含む 31500 のまま' );
	}

	/**
	 * 管理画面の予約詳細: ツアー料金と追加料金を分けて表示する。
	 */
	public function test_admin_meta_box_shows_tour_fee_and_extra_guest_fee(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '超過あり => ツアー料金（サービス基本料金の行）と追加料金の行を出す',
				'guest_input'         => array( 'guest_tiers' => array( 4, 1 ) ),
				'expect_extra_row'    => true,
				'expected_texts'      => array( '30,000', '1,500' ),
			),
			array(
				'test_condition_name' => '超過なし => ツアー料金の行は出し、追加料金の行は出さない',
				'guest_input'         => array( 'guest_tiers' => array( 2, 2 ) ),
				'expect_extra_row'    => false,
				'expected_texts'      => array( '30,000' ),
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$menu       = $this->create_menu_with_staff();
			$booking_id = $this->confirm_booking( $menu, $case['guest_input'], sprintf( '2027-05-%02d', $index + 1 ) );

			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
			get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );

			ob_start();
			( new Booking_Admin() )->render_meta_box( get_post( $booking_id ) );
			$output = (string) ob_get_clean();

			$this->assertSame( $case['expect_extra_row'], str_contains( $output, 'vkbm-booking-extra-guests' ), $case['test_condition_name'] . ' / 追加料金の行' );
			foreach ( $case['expected_texts'] as $text ) {
				$this->assertStringContainsString( $text, $output, $case['test_condition_name'] . ' / ' . $text );
			}
			$this->assertStringContainsString( 'vkbm-booking-meta__value', $output, $case['test_condition_name'] );
		}
	}

	/**
	 * マイページの予約一覧: 追加料金の内訳とツアー料金を返し、合計は保存済みの値を使う。
	 */
	public function test_my_bookings_returns_extra_guests(): void {
		$menu       = $this->create_menu_with_staff();
		$booking_id = $this->confirm_booking( $menu, array( 'guest_tiers' => array( 4, 1 ) ), gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );
		$author_id  = (int) get_post_field( 'post_author', $booking_id );
		wp_set_current_user( $author_id );

		$items = ( new My_Bookings_Controller() )->get_bookings( new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' ) )->get_data();
		$by_id = array_column( $items, null, 'id' );

		$this->assertArrayHasKey( $booking_id, $by_id, '自分の予約が返る' );
		$this->assertSame( 31500, (int) $by_id[ $booking_id ]['total_price'], '合計' );
		$this->assertSame( 30000, (int) $by_id[ $booking_id ]['base_price'], 'ツアー料金' );
		$this->assertSame( 4, (int) $by_id[ $booking_id ]['extra_guests']['included_guests'], '含まれる人数' );
		$this->assertSame( '子供', $by_id[ $booking_id ]['extra_guests']['tiers'][0]['label'], '超過した区分' );
		$this->assertSame( 1, (int) $by_id[ $booking_id ]['extra_guests']['tiers'][0]['count'], '超過人数' );
	}
}
