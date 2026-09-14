<?php
/**
 * 指名を使うメニューの貸切（貸し切り予約・予約者による貸切指定）を担当スタッフ単位で
 * 排他制御することのテスト（#440）。
 *
 * 仕様: 指名を使うメニューの貸切は、担当スタッフ単位で押さえる。ガイドAへの貸切予約は
 * ガイドAのその時間帯だけを押さえ、ガイドB・Cは引き続き予約できる（#392 の「担当ごとに並行して
 * 予約できる」と整合させる）。指名を使わないメニューの動きは変えない（今までどおり「メニュー＋
 * 時間帯」で判定する）。
 *
 * 本ファイルは以下を検証する。
 * - Booking_Draft_Controller / Booking_Confirmation_Controller の
 *   slot_has_exclusive_booking_for_menu() / slot_has_any_active_booking_for_menu() が、
 *   staff_id を渡すとその担当スタッフの予約だけを対象にし、省略時はメニュー全体を対象にすること
 * - Booking_Confirmation_Controller::check_capacity_with_mutex() が、指名を使うメニューで
 *   指名した担当スタッフが既に貸切予約で埋まっていれば拒否し、別の担当スタッフへの指名・
 *   自動割当では拒否しないこと（1枠1組との整合）
 * - 指名を使わないメニューでは、貸切の判定が引き続きメニュー全体（担当スタッフ問わず）で
 *   効くこと（回帰）
 * - 予約確定時に、指名を使うメニューでも「貸し切り予約」フラグの付与・「予約者による貸切指定」＋
 *   貸切料金の加算が実際に効くこと（予約時にも実際に効くことの確認）
 * - Availability_Service::get_daily_slots()（指名なし・自動割当の空き枠）が、担当の一方だけが
 *   貸切予約で埋まっているときは受付可能のまま返し、担当が全員貸切予約で埋まっているときは
 *   枠全体を受付停止で返すこと（collapse_slots_for_auto_assignment() の担当スタッフ単位化。#440）
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
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
 * 指名を使うメニューの貸切スタッフ単位スコープのテスト。
 *
 * @group bookings
 * @group exclusive
 * @group nomination
 */
class Nomination_Staff_Scoped_Exclusive_Booking_Test extends WP_UnitTestCase {
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
	 * サイト全体の指名機能をON・予約枠の定員機能をONにする。
	 */
	private function enable_nomination(): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = true;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 指名OFF・予約枠の定員機能ONにする（回帰確認用）。
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
	 * slot_has_exclusive_booking_for_menu()（下書き・確定の両コントローラ）が、
	 * staff_id を渡すとその担当スタッフの予約だけを対象にすることを検証する。
	 *
	 * ガイドAの貸切予約がある時間帯で、staff_id=0（メニュー全体）は true、
	 * staff_id=A（本人）は true、staff_id=B（別の担当）は false になるべき。
	 */
	public function test_slot_has_exclusive_booking_for_menu_scopes_by_staff(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();

		$start = '2026-11-01 10:00:00';
		$end   = '2026-11-01 11:00:00';

		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		foreach ( $this->controllers_under_test() as $label => $controller ) {
			$method = new ReflectionMethod( get_class( $controller ), 'slot_has_exclusive_booking_for_menu' );
			$method->setAccessible( true );

			$this->assertTrue(
				$method->invoke( $controller, $menu_id, $start, $end ),
				"[{$label}] staff_id 省略（メニュー全体）は貸切予約を検出するべき"
			);
			$this->assertTrue(
				$method->invoke( $controller, $menu_id, $start, $end, $staff_a ),
				"[{$label}] staff_id=A（本人）は貸切予約を検出するべき"
			);
			$this->assertFalse(
				$method->invoke( $controller, $menu_id, $start, $end, $staff_b ),
				"[{$label}] staff_id=B（別の担当）は貸切予約を検出しないべき（#440）"
			);
		}
	}

	/**
	 * slot_has_any_active_booking_for_menu()（下書き・確定の両コントローラ）が、
	 * staff_id を渡すとその担当スタッフの予約だけを対象にすることを検証する
	 * （「予約者による貸切指定」の『最初の予約者のみ』判定に使う）。
	 */
	public function test_slot_has_any_active_booking_for_menu_scopes_by_staff(): void {
		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();

		$start = '2026-11-02 10:00:00';
		$end   = '2026-11-02 11:00:00';

		// 貸切フラグは立てない通常予約でも「有効な予約」として検出される。
		$this->create_booking_post( $staff_a, $menu_id, $start, $end );

		foreach ( $this->controllers_under_test() as $label => $controller ) {
			$method = new ReflectionMethod( get_class( $controller ), 'slot_has_any_active_booking_for_menu' );
			$method->setAccessible( true );

			$this->assertTrue(
				$method->invoke( $controller, $menu_id, $start, $end ),
				"[{$label}] staff_id 省略（メニュー全体）は既存予約を検出するべき"
			);
			$this->assertTrue(
				$method->invoke( $controller, $menu_id, $start, $end, $staff_a ),
				"[{$label}] staff_id=A（本人）は既存予約を検出するべき"
			);
			$this->assertFalse(
				$method->invoke( $controller, $menu_id, $start, $end, $staff_b ),
				"[{$label}] staff_id=B（別の担当）は既存予約を検出しないべき（#440）"
			);
		}
	}

	/**
	 * check_capacity_with_mutex(): 指名を使うメニューで、指名した担当スタッフが既に貸切予約で
	 * 埋まっている場合は拒否し、別の担当スタッフを指名した場合は拒否しないことを検証する。
	 */
	public function test_check_capacity_with_mutex_nomination_exclusive_when_booked_scopes_by_staff(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名機能は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$start = '2026-11-03 10:00:00';
		$end   = '2026-11-03 11:00:00';

		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$controller = new Booking_Confirmation_Controller(
			new Nomination_Exclusive_Notification_Test_Double(),
			new Settings_Repository()
		);
		$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_capacity_with_mutex' );
		$method->setAccessible( true );
		$release = new ReflectionMethod( Booking_Confirmation_Controller::class, 'release_slot_mutex' );
		$release->setAccessible( true );

		// ガイドAを指名 => 既にAの貸切予約があるため拒否（担当スタッフ単位のガードが機能）。
		$result_a = $method->invoke( $controller, $menu_id, $staff_a, $start, $end, 1, true, array(), false );
		$this->assertInstanceOf(
			WP_Error::class,
			$result_a,
			'指名したガイドAが既に貸切予約で埋まっている場合は拒否されるべき'
		);
		$this->assertSame( 'capacity_exceeded', $result_a->get_error_code() );

		// ガイドBを指名 => Aの貸切予約とは無関係なので成立する（メニュー全体ではなくスタッフ単位）。
		$result_b = $method->invoke( $controller, $menu_id, $staff_b, $start, $end, 1, true, array(), false );
		if ( $result_b instanceof WP_Error ) {
			$this->fail( '指名したガイドBは空いているため成立するべきだが WP_Error になった: ' . $result_b->get_error_message() );
		}
		$this->assertSame( $staff_b, $result_b['staff_id'], 'ガイドBへ指名どおり割り当てられるべき' );
		$release->invoke( $controller, $result_b['name'], $result_b['token'] );
	}

	/**
	 * check_capacity_with_mutex(): 指名を使うメニューの「指名なし」自動割当で、候補の一方（A）が
	 * 貸切予約で埋まっていても、もう一方（B）へ正しく割り当てられることを検証する
	 * （担当スタッフが決まるタイミングと判定順が合っているかの確認）。
	 */
	public function test_check_capacity_with_mutex_nomination_auto_assign_skips_exclusively_booked_staff(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名機能は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$start = '2026-11-04 10:00:00';
		$end   = '2026-11-04 11:00:00';

		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$controller = new Booking_Confirmation_Controller(
			new Nomination_Exclusive_Notification_Test_Double(),
			new Settings_Repository()
		);
		$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_capacity_with_mutex' );
		$method->setAccessible( true );
		$release = new ReflectionMethod( Booking_Confirmation_Controller::class, 'release_slot_mutex' );
		$release->setAccessible( true );

		// 指名なし（自動割当）・候補 [A, B]。担当スタッフはこの時点では未確定のため、
		// 貸切ガードの早期チェックは行われず、has_staff_conflict() による候補絞り込みへ委ねられる。
		$result = $method->invoke( $controller, $menu_id, 0, $start, $end, 1, false, array( $staff_a, $staff_b ), false );

		if ( $result instanceof WP_Error ) {
			$this->fail( '空いているガイドBへ自動割当できるべきだが WP_Error になった: ' . $result->get_error_message() );
		}
		$this->assertSame(
			$staff_b,
			$result['staff_id'],
			'貸切予約で埋まっているガイドAではなく、空いているガイドBへ自動割当されるべき'
		);
		$release->invoke( $controller, $result['name'], $result['token'] );
	}

	/**
	 * check_capacity_with_mutex(): 指名を使わないメニューでは、貸切の判定が引き続き
	 * 「メニュー全体」（担当スタッフ問わず）で効くことを確認する（回帰）。
	 */
	public function test_check_capacity_with_mutex_non_nomination_menu_still_scopes_by_menu(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->disable_nomination();

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$start = '2026-11-05 10:00:00';
		$end   = '2026-11-05 11:00:00';

		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$controller = new Booking_Confirmation_Controller(
			new Nomination_Exclusive_Notification_Test_Double(),
			new Settings_Repository()
		);
		$method = new ReflectionMethod( Booking_Confirmation_Controller::class, 'check_capacity_with_mutex' );
		$method->setAccessible( true );

		// 指名を使わないメニューでは、担当スタッフBを明示的に割り当てようとしても
		// メニュー全体の貸切ガードで拒否される（従来どおりの挙動）。
		$result = $method->invoke( $controller, $menu_id, 0, $start, $end, 1, false, array( $staff_b ), false );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'指名を使わないメニューでは、別スタッフBであってもメニュー全体の貸切ガードで拒否されるべき（回帰）'
		);
		$this->assertSame( 'capacity_exceeded', $result->get_error_code() );
	}

	/**
	 * save_draft(): 指名を使うメニューで、指名した担当スタッフ（resource_id）が既に貸切予約で
	 * 埋まっている場合は下書き保存の時点で拒否し、別の担当スタッフを指名した場合は拒否しないことを検証する。
	 */
	public function test_save_draft_nomination_exclusive_when_booked_scopes_by_staff(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		$staff_b = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );

		$start = '2026-11-06T10:00:00+09:00';
		$end   = '2026-11-06T11:00:00+09:00';

		// #406: format_datetime_for_storage() は strtotime() → wp_date() で解釈するため、
		// 保存する既存予約の日時文字列も、下書きリクエストへ渡す $start/$end と同じ表記
		// （ISO8601＋タイムゾーンオフセット）に揃える必要がある（サーバの既定タイムゾーンに
		// 依存する解釈ズレを避けるため）。
		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// ガイドAを指名 => 拒否。
		$response_a = $this->save_draft_request( $menu_id, $staff_a, $start, $end, true );
		$this->assertInstanceOf( WP_Error::class, $response_a, '指名したガイドAが既に貸切予約で埋まっている場合は下書き保存も拒否されるべき' );
		$this->assertSame( 'capacity_exceeded', $response_a->get_error_code() );

		// ガイドBを指名 => 成立（スタッフ単位のスコープ）。
		$response_b = $this->save_draft_request( $menu_id, $staff_b, $start, $end, true );
		$this->assertInstanceOf( WP_REST_Response::class, $response_b, '指名したガイドBは空いているため下書き保存が成立するべき' );
	}

	/**
	 * save_draft(): 指名を使うメニューで「指名なし」（resource_id=0・自動割当）の下書き保存は、
	 * 担当スタッフが未確定のため貸切ガードの早期チェックをスキップし、確定側の権威的な判定に
	 * 委ねることを検証する（下書き保存自体は拒否されない）。
	 */
	public function test_save_draft_nomination_auto_assign_defers_to_confirmation(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id = $this->create_menu();
		$staff_a = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a ) );

		$start = '2026-11-07T10:00:00+09:00';
		$end   = '2026-11-07T11:00:00+09:00';

		// #406: 保存する既存予約の日時文字列は、下書きリクエストへ渡す $start/$end と同じ表記に揃える。
		$booking_id = $this->create_booking_post( $staff_a, $menu_id, $start, $end );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$response = $this->save_draft_request( $menu_id, 0, $start, $end, false );
		$this->assertInstanceOf(
			WP_REST_Response::class,
			$response,
			'指名なし（自動割当・担当未確定）の下書き保存は、この時点では貸切ガードで拒否されず成立するべき'
		);
	}

	/**
	 * create_booking(): 指名を使うメニューでも「貸し切り予約」フラグが予約確定時に付与され、
	 * 「予約者による貸切指定」＋貸切料金が実際に効くことを検証する。
	 */
	public function test_create_booking_applies_exclusive_flag_and_fee_for_nomination_menu(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
		update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', 500 );

		$start = '2026-11-08T10:00:00+09:00';
		$end   = '2026-11-08T11:00:00+09:00';

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$token = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'menu_id'            => $menu_id,
				'resource_id'        => $staff_id,
				'is_staff_preferred' => true,
				'guests'             => 2,
				'user_exclusive'     => true,
				'slot'               => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
				'owner_user_id'      => $user_id,
			)
		);
		$this->tokens[] = $token;

		$controller = new Booking_Confirmation_Controller(
			new Nomination_Exclusive_Notification_Test_Double(),
			new Settings_Repository(),
			new Nomination_Exclusive_Availability_Test_Double(
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

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, '指名を使うメニューでも貸切指定つきの予約は成立するべき' );

		$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
		$this->assertGreaterThan( 0, $booking_id );

		$this->assertTrue(
			(bool) get_post_meta( $booking_id, '_vkbm_booking_exclusive', true ),
			'#440: 指名を使うメニューでも予約者による貸切指定で _vkbm_booking_exclusive が付与されるべき'
		);
		$this->assertSame(
			1000,
			(int) get_post_meta( $booking_id, '_vkbm_booking_exclusive_fee', true ),
			'#440: 指名を使うメニューでも貸切料金（単価500 × 2名）が加算されるべき'
		);
	}

	/**
	 * Availability_Service::get_daily_slots()（指名なし・自動割当の空き枠）の exclusive_closed が
	 * 担当スタッフ単位で正しくスコープされることを検証する。
	 *
	 * collapse_slots_for_auto_assignment() が「いずれかの担当の exclusive_closed」で
	 * 時間枠全体を閉じていた場合、担当2名のうち一方（A）だけの貸切でも
	 * exclusive_closed=true・remaining=0になってしまい、もう一方（B）が空いていても
	 * 受付できなくなる不具合があった（#440）。指名を使うメニューでは「候補の担当が全員
	 * exclusive_closed のときだけ」枠全体を閉じるべきで、片方のみ貸切なら開いたまま、
	 * 担当A・Bとも貸切なら閉じることの両方を確認する。指名を使わないメニューでは、
	 * 複数の担当が同じ物理的な枠を共有する前提のため、従来どおり1件の貸切予約だけで
	 * 閉じることを回帰確認する。
	 */
	public function test_get_daily_slots_auto_assign_exclusive_closed_scoped_by_staff(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$future_year = (int) wp_date( 'Y' ) + 1;
		$day         = 9;
		$slot_day    = sprintf( '%04d-12-%02d', $future_year, $day );

		$test_cases = array(
			array(
				'test_condition_name'    => '指名を使うメニュー：担当Aが貸切で埋まっていても担当Bが空いていれば受付可能（#440）',
				'nomination_enabled'     => true,
				'staff_b_also_exclusive' => false,
				'expect_closed'          => false,
			),
			array(
				'test_condition_name'    => '指名を使うメニュー：担当A・Bとも貸切で埋まっていれば「指名なし」の枠全体が受付停止になる（#440）',
				'nomination_enabled'     => true,
				'staff_b_also_exclusive' => true,
				'expect_closed'          => true,
			),
			array(
				'test_condition_name'    => '指名を使わないメニュー：担当Aの貸切予約だけで時間枠全体が受付停止になる（回帰）',
				'nomination_enabled'     => false,
				'staff_b_also_exclusive' => false,
				'expect_closed'          => true,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['nomination_enabled'] ) {
				$this->enable_nomination();
			} else {
				$this->disable_nomination();
			}

			$menu_id = $this->create_menu();
			$staff_a = $this->create_staff();
			$staff_b = $this->create_staff();
			update_post_meta( $menu_id, '_vkbm_max_capacity', 1 );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );
			if ( ! $case['nomination_enabled'] ) {
				// 指名を使わないメニューは複数人一括予約ONの文脈でのみ意味を持つ設定のため、
				// 貸切判定のフルゲート（Staff_Editor::is_exclusive_booking_available_for_menu()）を
				// 満たすよう有効化しておく。
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			}

			$this->create_shift( $staff_a, $future_year, 12, $day, '10:00', '12:00' );
			$this->create_shift( $staff_b, $future_year, 12, $day, '10:00', '12:00' );

			// 担当Aに10:00-11:00の貸切予約を1件入れる。ケースによっては担当Bにも同様に入れる
			// （担当Bは create_exclusive_booking_post_for_daily_slots_test() のコメントを参照）。
			$this->create_exclusive_booking_post_for_daily_slots_test( $staff_a, $menu_id, $slot_day );
			if ( ! empty( $case['staff_b_also_exclusive'] ) ) {
				$this->create_exclusive_booking_post_for_daily_slots_test( $staff_b, $menu_id, $slot_day );
			}

			// resource_id を渡さない＝「指名なし」（自動割当）の空き枠取得。
			$service = new Availability_Service();
			$result  = $service->get_daily_slots(
				array(
					'menu_id'  => $menu_id,
					'date'     => $slot_day,
					'timezone' => 'Asia/Tokyo',
				)
			);

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$slots = $result['slots'] ?? array();
			$this->assertNotEmpty( $slots, $case['test_condition_name'] . ' / スロットが生成されるべき' );

			$target = null;
			foreach ( $slots as $slot ) {
				if ( isset( $slot['start_at'] ) && str_contains( (string) $slot['start_at'], $slot_day . 'T10:00:00' ) ) {
					$target = $slot;
					break;
				}
			}
			$this->assertNotNull( $target, $case['test_condition_name'] . ' / 10:00 のスロットが存在するべき' );

			if ( $case['expect_closed'] ) {
				$this->assertTrue( ! empty( $target['exclusive_closed'] ), $case['test_condition_name'] );
				$this->assertSame( 0, (int) $target['remaining'], $case['test_condition_name'] . ' / remaining=0' );
			} else {
				$this->assertEmpty( $target['exclusive_closed'] ?? false, $case['test_condition_name'] );
				$this->assertGreaterThan( 0, (int) $target['remaining'], $case['test_condition_name'] . ' / remaining>0' );
			}
		}
	}

	/**
	 * save_draft() リクエストを組み立てて実行する。
	 *
	 * @param int    $menu_id            メニューID。
	 * @param int    $resource_id        担当スタッフID（0=指名なし）。
	 * @param string $start_at           スロット開始（ISO8601）。
	 * @param string $end_at             スロット終了（ISO8601）。
	 * @param bool   $is_staff_preferred 指名予約かどうか。
	 * @return WP_REST_Response|WP_Error
	 */
	private function save_draft_request( int $menu_id, int $resource_id, string $start_at, string $end_at, bool $is_staff_preferred ) {
		$controller = new Booking_Draft_Controller( new Settings_Repository() );
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/booking-drafts' );
		$payload    = array(
			'menu_id'            => $menu_id,
			'resource_id'        => $resource_id,
			'is_staff_preferred' => $is_staff_preferred,
			'date'               => '2026-11-06',
			'slot'               => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			),
			'meta'               => array( 'timezone' => 'Asia/Tokyo' ),
		);
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		$response = $controller->save_draft( $request );
		if ( $response instanceof WP_REST_Response ) {
			$token = (string) ( $response->get_data()['token'] ?? '' );
			if ( '' !== $token ) {
				$this->tokens[] = $token;
			}
		}

		return $response;
	}

	/**
	 * テスト対象の下書き・確定の両コントローラを、ラベル付きで返す
	 * （2コントローラの同型実装を同じ条件で検証するため）。
	 *
	 * @return array<string, object>
	 */
	private function controllers_under_test(): array {
		return array(
			'Booking_Draft_Controller'        => new Booking_Draft_Controller( new Settings_Repository() ),
			'Booking_Confirmation_Controller' => new Booking_Confirmation_Controller(
				new Nomination_Exclusive_Notification_Test_Double(),
				new Settings_Repository()
			),
		);
	}

	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
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
	 * 予約投稿を作成する（confirmed）。日時はサイトのタイムゾーン基準の保存形式へ変換して書き込む。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $menu_id  メニューID。
	 * @param string $start_at 開始日時。
	 * @param string $end_at   終了日時。
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

	/**
	 * get_daily_slots() 検証用に、指定した担当スタッフへ10:00-11:00の貸切予約を1件作成する。
	 *
	 * create_booking_post()（strtotime() → wp_date() でサイトTZへ変換）はこのクラスの他の
	 * テスト（$start/$end をそのまま slot_has_exclusive_booking_for_menu() 等へ渡し、保存側と
	 * 同じ変換を経由して突き合わせる形式）向けのため、ここでは使わない。get_daily_slots() の
	 * スロット生成はシフト時刻をサイトTZの壁時計としてそのまま使うため、予約側もサイトTZの
	 * 壁時計表記をそのまま保存する（test-exclusive-booking.php の create_booking_post() と同型）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $menu_id  メニューID。
	 * @param string $slot_day スロット日（Y-m-d、サイトTZの壁時計表記）。
	 * @return int 予約投稿ID。
	 */
	private function create_exclusive_booking_post_for_daily_slots_test( int $staff_id, int $menu_id, string $slot_day ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', $slot_day . ' 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $slot_day . ' 11:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $slot_day . ' 11:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		return $booking_id;
	}

	/**
	 * スタッフのシフト投稿を作成する（指定日に1スロット営業）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $year     年。
	 * @param int    $month    月。
	 * @param int    $day      日。
	 * @param string $start    営業開始（HH:MM）。
	 * @param string $end      営業終了（HH:MM）。
	 * @return int シフト投稿ID。
	 */
	private function create_shift( int $staff_id, int $year, int $month, int $day, string $start, string $end ): int {
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta(
			$shift_id,
			'_vkbm_shift_days',
			array(
				$day => array(
					'status' => 'open',
					'slots'  => array(
						array(
							'start' => $start,
							'end'   => $end,
						),
					),
				),
			)
		);

		return $shift_id;
	}
}

/**
 * 通知を抑止するテストダブル。
 */
class Nomination_Exclusive_Notification_Test_Double extends Booking_Notification_Service {
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
class Nomination_Exclusive_Availability_Test_Double extends Availability_Service {
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
