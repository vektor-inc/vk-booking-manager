<?php
/**
 * 予約確定時に、下書きの枠の日時を再検証で取り直した空き枠と照合するテスト（issue #528）。
 *
 * Booking_Confirmation_Controller::create_booking() は確定直前にその日の空き枠を取り直す。
 * ここでは、取り直した空き枠と下書きの日時が時刻として一致しない場合は既存の slot_unavailable（409）で
 * 断ること、保存・判定に使う日時が取り直した空き枠の値になること、通常の予約（下書き＝空き枠 API の
 * 応答そのまま）では保存値・予約タイトル・応答が従来どおりであることを検証する。
 *
 * このファイルは同一名前空間 VKBookingManager\Tests\Bookings の次のテストダブルを再利用する。
 * - Booking_Notification_Service_Test_Double（test-booking-confirmation-controller.php）
 * - Availability_Service_Args_Recording_Test_Double（test-double-availability-service-args-recording.php）
 * PHPUnit は実行前にテストスイート内の全ファイルを読み込むため、実行時点でクラス定義は利用可能になっている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function delete_transient;
use function get_post;
use function get_post_meta;
use function get_posts;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * 予約確定時の枠の日時の照合と、保存に使う日時の出どころを検証するテストクラス。
 *
 * @group bookings
 */
class Booking_Confirmation_Slot_Times_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

	/**
	 * 空き枠ダブルが返す、通常の枠（2026-10-05 は月曜日）。
	 *
	 * @var array<string, string>
	 */
	private const MONDAY_SLOT = array(
		'slot_id'        => 'slot-20261005-1000',
		'start_at'       => '2026-10-05T10:00:00+09:00',
		'end_at'         => '2026-10-05T11:30:00+09:00',
		'service_end_at' => '2026-10-05T11:00:00+09:00',
	);

	/**
	 * 空き枠ダブルが返す、土曜日の枠（2026-10-03 は土曜日）。
	 *
	 * @var array<string, string>
	 */
	private const SATURDAY_SLOT = array(
		'slot_id'        => 'slot-20261003-1000',
		'start_at'       => '2026-10-03T10:00:00+09:00',
		'end_at'         => '2026-10-03T11:30:00+09:00',
		'service_end_at' => '2026-10-03T11:00:00+09:00',
	);

	/**
	 * トークン一覧（tearDown で transient を削除するため保持）。
	 *
	 * @var array<int, string>
	 */
	private array $tokens = array();

	/**
	 * テスト前の $_COOKIE 退避用。
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	/**
	 * テスト前の全体設定（option）退避用。未設定時は false。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前の timezone_string 退避用。
	 *
	 * @var string
	 */
	private string $original_timezone_string = '';

	/**
	 * Cookie・全体設定・タイムゾーンを退避し、サイトのタイムゾーンを Asia/Tokyo に固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup     = $_COOKIE;
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		// ISO8601(+09:00) と保存形式（サイトのタイムゾーンの Y-m-d H:i:s）を一致させるため Asia/Tokyo に固定する。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 下書き transient・Cookie・全体設定・タイムゾーン・静的キャッシュをテスト前の状態へ戻す。
	 */
	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * テスト用のサービスメニューを作成する。
	 *
	 * @return int サービスメニューの投稿ID。
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
	 * テスト用のスタッフ（リソース）を作成する。
	 *
	 * @return int リソースの投稿ID。
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
	 * 予約一時データ（下書き）を transient へ保存する。
	 *
	 * @param int                  $menu_id    サービスメニューID。
	 * @param int                  $staff_id   スタッフID。
	 * @param array<string, mixed> $draft_slot 下書きに入れる枠。
	 * @param string               $timezone   下書きに入れるタイムゾーン。
	 * @return string 生成したトークン。
	 */
	private function store_draft( int $menu_id, int $staff_id, array $draft_slot, string $timezone = 'Asia/Tokyo' ): string {
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'guests'               => 1,
			'is_staff_preferred'   => false,
			'slot'                 => $draft_slot,
			'assignable_staff_ids' => array( $staff_id ),
			'meta'                 => array(
				'timezone' => $timezone,
			),
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * 予約投稿の件数を返す（予約が作られていないことの確認用）。
	 *
	 * @return int 予約投稿の件数。
	 */
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
	 * create_booking: 下書きの枠の日時を取り直した空き枠と照合し、保存・判定には空き枠の値を使うことを検証する。
	 */
	public function test_create_booking(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'サイトと異なるタイムゾーンでシフト外の時刻を指定した下書き => 409 slot_unavailable',
				'conditions'          => array(
					'timezone'    => 'America/New_York',
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge(
						self::MONDAY_SLOT,
						array(
							'start_at' => '2026-10-05T10:00:00-04:00',
							'end_at'   => '2026-10-05T11:30:00-04:00',
						)
					),
				),
				'expected'            => array(
					'error_code'        => 'slot_unavailable',
					'status'            => 409,
					'revalidates_slots' => true,
				),
			),
			array(
				'test_condition_name' => '通常の予約（下書き＝空き枠そのまま） => 確定し、保存値は従来どおり',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => self::MONDAY_SLOT,
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => '同じ時刻をタイムゾーンの書き方を変えて表した下書き => 確定し、保存値は空き枠と同じ',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array(
						'slot_id'        => 'slot-20261005-1000',
						'start_at'       => '2026-10-05T01:00:00+00:00',
						'end_at'         => '2026-10-05T02:30:00Z',
						'service_end_at' => '2026-10-05T02:00:00+00:00',
					),
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => 'サービス終了だけが空き枠と異なる下書き => 確定し、サービス終了は空き枠の値で保存',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge( self::MONDAY_SLOT, array( 'service_end_at' => '2026-10-05T20:00:00+09:00' ) ),
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => '枠の ID が空き枠に無く、開始・終了は一致してサービス終了だけが異なる下書き => 確定し、サービス終了は空き枠の値で保存',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge(
						self::MONDAY_SLOT,
						array(
							'slot_id'        => 'slot-unknown',
							'service_end_at' => '2026-10-05T20:00:00+09:00',
						)
					),
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => '下書きの終了が空 => 開始だけで照合して確定し、終了・サービス終了は空き枠の値で保存',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge(
						self::MONDAY_SLOT,
						array(
							'end_at'         => '',
							'service_end_at' => '',
						)
					),
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => '枠の ID は同じで終了が空き枠より遅い下書き => 409 slot_unavailable',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge( self::MONDAY_SLOT, array( 'end_at' => '2026-10-05T20:00:00+09:00' ) ),
				),
				'expected'            => array(
					'error_code'        => 'slot_unavailable',
					'status'            => 409,
					'revalidates_slots' => true,
				),
			),
			array(
				'test_condition_name' => '枠の ID は同じで終了が空き枠より早い下書き => 409 slot_unavailable',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge( self::MONDAY_SLOT, array( 'end_at' => '2026-10-05T10:15:00+09:00' ) ),
				),
				'expected'            => array(
					'error_code'        => 'slot_unavailable',
					'status'            => 409,
					'revalidates_slots' => true,
				),
			),
			array(
				'test_condition_name' => '枠の ID は同じで終了が日時として読めない下書き => 409 slot_unavailable',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge( self::MONDAY_SLOT, array( 'end_at' => 'not-a-date' ) ),
				),
				'expected'            => array(
					'error_code'        => 'slot_unavailable',
					'status'            => 409,
					'revalidates_slots' => true,
				),
			),
			array(
				'test_condition_name' => '枠の ID は同じで開始が空き枠と異なる下書き => 409 slot_unavailable',
				'conditions'          => array(
					'server_slot' => self::MONDAY_SLOT,
					'draft_slot'  => array_merge(
						self::MONDAY_SLOT,
						array(
							'start_at' => '2026-10-05T06:00:00+09:00',
							'end_at'   => '2026-10-05T07:30:00+09:00',
						)
					),
				),
				'expected'            => array(
					'error_code'        => 'slot_unavailable',
					'status'            => 409,
					'revalidates_slots' => true,
				),
			),
			array(
				'test_condition_name' => '平日のみのメニューで月曜の空き枠を通常どおり予約 => 確定する',
				'conditions'          => array(
					'reservation_day_type' => 'weekday',
					'server_slot'          => self::MONDAY_SLOT,
					'draft_slot'           => self::MONDAY_SLOT,
				),
				'expected'            => array(
					'revalidates_slots' => true,
					'error_code'        => '',
					'start'             => '2026-10-05 10:00:00',
					'service_end'       => '2026-10-05 11:00:00',
					'total_end'         => '2026-10-05 11:30:00',
					'title_suffix'      => ' / 2026.10.5 10:00',
					'response_keys'     => array( 'booking_id', 'status' ),
				),
			),
			array(
				'test_condition_name' => '平日のみのメニューで土曜の空き枠を予約 => 400 invalid_reservation_day（予約できる日の判定は引き続き行う）',
				'conditions'          => array(
					'reservation_day_type' => 'weekday',
					'server_slot'          => self::SATURDAY_SLOT,
					'draft_slot'           => self::SATURDAY_SLOT,
				),
				'expected'            => array(
					'error_code'        => 'invalid_reservation_day',
					'status'            => 400,
					'revalidates_slots' => false,
				),
			),
			array(
				'test_condition_name' => '平日のみのメニューで、月曜の空き枠の ID のまま開始だけ土曜にした下書き => 400 invalid_reservation_day（従来どおり予約できる日の判定で断る）',
				'conditions'          => array(
					'reservation_day_type' => 'weekday',
					'server_slot'          => self::MONDAY_SLOT,
					'draft_slot'           => array_merge(
						self::MONDAY_SLOT,
						array(
							'start_at' => '2026-10-03T10:00:00+09:00',
							'end_at'   => '2026-10-03T11:30:00+09:00',
						)
					),
				),
				'expected'            => array(
					'error_code'        => 'invalid_reservation_day',
					'status'            => 400,
					'revalidates_slots' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// ケースごとにメニュー・スタッフ・予約者を作り直し、他ケースの予約との重なりが判定に混ざらないようにする。
			$menu_id  = $this->create_menu();
			$staff_id = $this->create_staff();
			if ( isset( $case['conditions']['reservation_day_type'] ) ) {
				update_post_meta( $menu_id, '_vkbm_reservation_day_type', $case['conditions']['reservation_day_type'] );
			}

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			// 下書きを保存し、空き枠ダブルには取り直し時に返す枠を渡す。
			$token                = $this->store_draft( $menu_id, $staff_id, $case['conditions']['draft_slot'], $case['conditions']['timezone'] ?? 'Asia/Tokyo' );
			$server_slot          = array_merge(
				$case['conditions']['server_slot'],
				array(
					'staff'                => array( 'id' => $staff_id ),
					'assignable_staff_ids' => array( $staff_id ),
				)
			);
			$availability_service = new Availability_Service_Args_Recording_Test_Double( $server_slot );
			$controller           = new Booking_Confirmation_Controller(
				new Booking_Notification_Service_Test_Double(),
				new Settings_Repository(),
				$availability_service
			);
			$count_before         = $this->count_bookings();

			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			$response = $controller->create_booking( $request );
			if ( $case['expected']['revalidates_slots'] ) {
				$this->assertSame( 'Asia/Tokyo', $availability_service->last_get_daily_slots_args['timezone'] ?? null, $case['test_condition_name'] . '（空き枠再検証のタイムゾーン）' );
			} else {
				$this->assertNull( $availability_service->last_get_daily_slots_args, $case['test_condition_name'] . '（空き枠再検証は実行されない）' );
			}

			if ( '' !== $case['expected']['error_code'] ) {
				// 断られるケース: エラーコード・ステータスを確認し、予約が作られていないことを確かめる。
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected']['error_code'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( $case['expected']['status'], $response->get_error_data()['status'] ?? null, $case['test_condition_name'] );
				$this->assertSame( $count_before, $this->count_bookings(), $case['test_condition_name'] . '（予約は作られないべき）' );
				continue;
			}

			// 確定するケース: 応答の形と、保存された日時・予約タイトルを確認する。
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
			$data = $response->get_data();
			$this->assertSame( $case['expected']['response_keys'], array_keys( $data ), $case['test_condition_name'] . '（応答の形）' );
			$booking_id = (int) $data['booking_id'];
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );
			$this->assertSame( $case['expected']['start'], (string) get_post_meta( $booking_id, '_vkbm_booking_service_start', true ), $case['test_condition_name'] . '（開始）' );
			$this->assertSame( $case['expected']['service_end'], (string) get_post_meta( $booking_id, '_vkbm_booking_service_end', true ), $case['test_condition_name'] . '（サービス終了）' );
			$this->assertSame( $case['expected']['total_end'], (string) get_post_meta( $booking_id, '_vkbm_booking_total_end', true ), $case['test_condition_name'] . '（終了）' );
			$this->assertStringEndsWith( $case['expected']['title_suffix'], (string) get_post( $booking_id )->post_title, $case['test_condition_name'] . '（予約タイトル）' );
		}
	}

	/**
	 * find_matching_available_slot: 枠の ID と開始・終了を時刻として照合して一致する枠を返すことを検証する。
	 */
	public function test_find_matching_available_slot(): void {
		$slots = array(
			array_merge( self::MONDAY_SLOT, array( 'staff' => array( 'id' => 1 ) ) ),
			array(
				'slot_id'        => 'slot-20261005-1200',
				'start_at'       => '2026-10-05T12:00:00+09:00',
				'end_at'         => '2026-10-05T13:30:00+09:00',
				'service_end_at' => '2026-10-05T13:00:00+09:00',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '枠の ID・開始・終了がすべて一致 => その枠',
				'conditions'          => array(
					'slot_id'  => 'slot-20261005-1000',
					'start_at' => '2026-10-05T10:00:00+09:00',
					'end_at'   => '2026-10-05T11:30:00+09:00',
				),
				'expected'            => 'slot-20261005-1000',
			),
			array(
				'test_condition_name' => '枠の ID が一致し、開始・終了は同じ時刻を UTC で表記 => その枠',
				'conditions'          => array(
					'slot_id'  => 'slot-20261005-1000',
					'start_at' => '2026-10-05T01:00:00Z',
					'end_at'   => '2026-10-05T02:30:00+00:00',
				),
				'expected'            => 'slot-20261005-1000',
			),
			array(
				'test_condition_name' => '枠の ID が見つからず、開始・終了が別の枠と一致 => 開始・終了が一致した枠',
				'conditions'          => array(
					'slot_id'  => 'unknown-slot',
					'start_at' => '2026-10-05T12:00:00+09:00',
					'end_at'   => '2026-10-05T13:30:00+09:00',
				),
				'expected'            => 'slot-20261005-1200',
			),
			array(
				'test_condition_name' => '枠の ID は一致するが終了が異なる => 一致なし',
				'conditions'          => array(
					'slot_id'  => 'slot-20261005-1000',
					'start_at' => '2026-10-05T10:00:00+09:00',
					'end_at'   => '2026-10-06T10:00:00+09:00',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '枠の ID は一致するが、開始・終了は別の実在する枠のもの => 一致なし',
				'conditions'          => array(
					'slot_id'  => 'slot-20261005-1000',
					'start_at' => '2026-10-05T12:00:00+09:00',
					'end_at'   => '2026-10-05T13:30:00+09:00',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '枠の ID は一致するが開始が日時として読めない => 一致なし',
				'conditions'          => array(
					'slot_id'  => 'slot-20261005-1000',
					'start_at' => 'not-a-date',
					'end_at'   => '2026-10-05T11:30:00+09:00',
				),
				'expected'            => null,
			),
		);

		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			new Availability_Service_Args_Recording_Test_Double( self::MONDAY_SLOT )
		);
		// private メソッドのためリフレクションで呼び出す。
		$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'find_matching_available_slot' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$match  = $method->invoke(
				$controller,
				$slots,
				$case['conditions']['slot_id'],
				$case['conditions']['start_at'],
				$case['conditions']['end_at']
			);
			$actual = is_array( $match ) ? $match['slot_id'] : null;
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
