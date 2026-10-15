<?php
/**
 * 予約確定時の同一利用者の重複予約確認を、ロック取得後にも行うことのテスト（#692）。
 *
 * 同じ利用者が予約確定リクエストを同時に2回送ると、ロック前の重複確認を2件とも通り抜けて
 * 両方確定してしまう不具合があった。ロック取得の直前に別リクエストが予約を確定した状況を再現し、
 * ロック取得後の再確認で 409（booking_time_conflict）になることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

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

/**
 * Booking_Confirmation_Controller::create_booking() の重複予約再確認のテスト。
 *
 * @group bookings
 */
class Booking_User_Conflict_Recheck_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';
	private const MUTEX_OPTION     = '_transient_vkbm_booking_mutex_';

	/** @var string */
	private $original_timezone_string = '';

	/** @var array<int, string> */
	private array $tokens = array();

	/** @var callable|null */
	private $query_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		if ( null !== $this->query_filter ) {
			remove_filter( 'query', $this->query_filter );
			$this->query_filter = null;
		}
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
	 * create_booking() が、ロック取得直前に同じ利用者の予約が確定していた場合に 409 で拒否し、
	 * ロックも解放することを検証する。
	 *
	 * 「同時リクエスト」は、ロック取得の SQL が走る瞬間に別リクエストの予約が確定した状況として再現する。
	 */
	public function test_create_booking(): void {
		$start = '2026-11-08T10:00:00+09:00';
		$end   = '2026-11-08T11:00:00+09:00';

		$test_cases = array(
			array(
				'test_condition_name' => '通常の利用者: ロック取得直前に同じ利用者の予約が確定した場合 => 409 booking_time_conflict',
				'as_manager'         => false,
				'inject_booking'     => true,
				'expected_error'     => 'booking_time_conflict',
				'expected_status'    => 409,
			),
			array(
				'test_condition_name' => '代理入力（電話番号で会員が一致）: ロック取得直前にその会員の予約が確定した場合 => 409 booking_time_conflict',
				'as_manager'         => true,
				'inject_booking'     => true,
				'expected_error'     => 'booking_time_conflict',
				'expected_status'    => 409,
			),
			array(
				'test_condition_name' => '通常の利用者: 重複する予約が無い場合 => 予約が確定する',
				'as_manager'         => false,
				'inject_booking'     => false,
				'expected_error'     => '',
				'expected_status'    => 200,
			),
			array(
				'test_condition_name' => '代理入力（電話番号で会員が一致）: 重複する予約が無い場合 => 予約が確定する',
				'as_manager'         => true,
				'inject_booking'     => false,
				'expected_error'     => '',
				'expected_status'    => 200,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
			$staff_id = $this->create_post( Resource_Post_Type::POST_TYPE );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			// 予約者（会員）。代理入力では電話番号で一致させる。
			$customer_id = $this->factory()->user->create();
			// ケースごとに別の電話番号にして、前のケースの会員と一致しないようにする。
			$phone = '0901234' . str_pad( (string) $customer_id, 4, '0', STR_PAD_LEFT );
			update_user_meta( $customer_id, 'phone_number', $phone );

			if ( $case['as_manager'] ) {
				$manager_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
				wp_set_current_user( $manager_id );
			} else {
				wp_set_current_user( $customer_id );
			}
			$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

			$token = $this->create_draft( $menu_id, $staff_id, $start, $end );

			// ロック取得の SQL が走る瞬間に、別リクエストが同じ利用者の予約を確定した状況を作る。
			$injected = false;
			if ( $case['inject_booking'] ) {
				$this->query_filter = function ( $sql ) use ( &$injected, $customer_id, $start, $end ) {
					if ( ! $injected && false !== strpos( (string) $sql, 'INSERT IGNORE INTO' ) && false !== strpos( (string) $sql, self::MUTEX_OPTION ) ) {
						$injected = true;
						$this->create_booking_post( $customer_id, $start, $end );
					}
					return $sql;
				};
				add_filter( 'query', $this->query_filter );
			}

			$controller = new Booking_Confirmation_Controller(
				new User_Conflict_Notification_Test_Double(),
				new Settings_Repository(),
				new User_Conflict_Availability_Test_Double(
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
			$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$request->set_param( 'token', $token );
			$request->set_param( 'agree_terms', true );
			if ( $case['as_manager'] ) {
				$request->set_param( 'customer_phone', $phone );
			}

			$response = $controller->create_booking( $request );

			if ( null !== $this->query_filter ) {
				remove_filter( 'query', $this->query_filter );
				$this->query_filter = null;
			}

			if ( '' !== $case['expected_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_error'], $response->get_error_code(), $case['test_condition_name'] );
				$this->assertSame( $case['expected_status'], $response->get_error_data()['status'] ?? 0, $case['test_condition_name'] );
				$this->assertSame( 1, $this->count_bookings_of_author( $customer_id ), $case['test_condition_name'] . '（予約が増えていないこと）' );
				$this->assertSame( 0, $this->count_mutex_options(), $case['test_condition_name'] . '（ロックが解放されていること）' );
			} else {
				$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] );
				$this->assertSame( $case['expected_status'], $response->get_status(), $case['test_condition_name'] );
				$this->assertSame( 0, $this->count_mutex_options(), $case['test_condition_name'] . '（ロックが解放されていること）' );
			}

			// 次のケースに影響しないよう後始末する。
			unset( $_COOKIE[ self::OWNER_COOKIE ] );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * 下書き（仮予約）を作成し、トークンを返す。
	 *
	 * @param int    $menu_id  メニュー投稿 ID。
	 * @param int    $staff_id スタッフ投稿 ID。
	 * @param string $start_at 開始日時（ISO8601）。
	 * @param string $end_at   終了日時（ISO8601）。
	 * @return string 下書きトークン。
	 */
	private function create_draft( int $menu_id, int $staff_id, string $start_at, string $end_at ): string {
		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'menu_id'            => $menu_id,
				'resource_id'        => $staff_id,
				'is_staff_preferred' => true,
				'guests'             => 1,
				'slot'               => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start_at,
					'end_at'   => $end_at,
				),
				'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
				'owner_user_id'      => get_current_user_id(),
			)
		);
		$this->tokens[] = $token;

		return $token;
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
	 * 別リクエストが先に確定した予約を再現する（予約者を投稿者にして保存する）。
	 *
	 * 別プロセスの保存はこのプロセスのキャッシュを更新しないため、wp_insert_post() / update_post_meta() は使わず、
	 * $wpdb で posts と postmeta へ直接書き込む（フックやキャッシュの無効化を通さない）。
	 *
	 * @param int    $author_id 予約者のユーザー ID。
	 * @param string $start_at  開始日時（ISO8601）。
	 * @param string $end_at    終了日時（ISO8601）。
	 * @return int 予約投稿 ID。
	 */
	private function create_booking_post( int $author_id, string $start_at, string $end_at ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'       => $author_id,
				'post_date'         => $now,
				'post_date_gmt'     => get_gmt_from_date( $now ),
				'post_modified'     => $now,
				'post_modified_gmt' => get_gmt_from_date( $now ),
				'post_status'       => 'publish',
				'post_type'         => Booking_Post_Type::POST_TYPE,
				'post_name'         => 'concurrent-booking-' . wp_generate_password( 8, false, false ),
			)
		);
		$booking_id    = (int) $wpdb->insert_id;
		$start_storage = wp_date( 'Y-m-d H:i:s', strtotime( $start_at ) );
		$end_storage   = wp_date( 'Y-m-d H:i:s', strtotime( $end_at ) );

		$metas = array(
			'_vkbm_booking_service_start' => $start_storage,
			'_vkbm_booking_service_end'   => $end_storage,
			'_vkbm_booking_total_end'     => $end_storage,
		);
		foreach ( $metas as $meta_key => $meta_value ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $booking_id,
					'meta_key'   => $meta_key,
					'meta_value' => $meta_value,
				)
			);
		}

		return $booking_id;
	}

	/**
	 * 指定ユーザーが投稿者の予約数を数える。
	 *
	 * @param int $author_id ユーザー ID。
	 * @return int 予約数。
	 */
	private function count_bookings_of_author( int $author_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'author'         => $author_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return count( $ids );
	}

	/**
	 * 残っている予約枠のロック（options テーブルの行）の数を数える。
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
class User_Conflict_Notification_Test_Double extends Booking_Notification_Service {
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
class User_Conflict_Availability_Test_Double extends Availability_Service {

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
