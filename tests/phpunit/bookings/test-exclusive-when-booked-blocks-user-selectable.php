<?php
/**
 * 「貸し切り予約」ONのメニューで「予約者による貸切指定」を無視するサーバ側ガードのテスト（#388）。
 *
 * #304（貸し切り予約）と #305（ユーザー貸し切り指定）の両方のメタがONで保存されているメニューでは、
 * 予約確定時の貸切判定が「メニュー設定 OR ユーザー選択」のOR条件になっているため、
 * ユーザーが貸切を指定してもしなくても枠は貸切扱いになる。にもかかわらず、
 * 貸切を指定したユーザーだけ貸し切り料金が加算されるため「払った人が損をする」不整合が起きていた。
 *
 * このテストは、is_user_exclusive_selectable()（下書き・確定の両コントローラに同型実装）が
 * メニューの「貸し切り予約」ON時に false を返すことで、下書き保存・下書き再取得・予約確定の
 * いずれの段階でもユーザーの貸切指定が無視され、貸し切り料金が加算されないことを検証する。
 * 過去（本修正前）に両方ONで保存されてしまったメニューでも同じガードが効くことを示すため、
 * メタは管理画面フォームを経由せず直接 update_post_meta() で設定する。
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
use function metadata_exists;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_set_current_user;

/**
 * 「貸し切り予約」ONメニューで「予約者による貸切指定」が無視されることのテスト（#388）。
 *
 * @group bookings
 * @group exclusive
 */
class Exclusive_When_Booked_Blocks_User_Selectable_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';

	/** @var string */
	private $original_timezone_string = '';

	/** @var mixed */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );

		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		update_option( 'timezone_string', 'Asia/Tokyo' );
		// 指名OFF・複数人予約ON（貸し切り関連設定が意味を持つ前提）。
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * save_draft → get_draft: 「貸し切り予約」ONのメニューでは、ユーザーが貸切を指定して
	 * 下書き保存しても、下書き取得時点で指定が無視され貸し切り料金が0になる。
	 * 対照として「貸し切り予約」OFF（ユーザー貸切指定のみON）では従来どおり指定が有効になることも確認する。
	 */
	public function test_save_draft(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '貸し切り予約ON＋ユーザー貸切指定ON => 下書きで貸切指定が無視され料金0になる（#388・本修正）',
				'exclusive_when_booked' => true,
				'user_selectable'       => true,
				'expect_user_exclusive' => false,
				'expect_exclusive_fee'  => 0,
			),
			array(
				'test_condition_name'   => '貸し切り予約OFF＋ユーザー貸切指定ON => 従来どおり貸切指定が有効で料金が加算される（対照・回帰防止）',
				'exclusive_when_booked' => false,
				'user_selectable'       => true,
				'expect_user_exclusive' => true,
				'expect_exclusive_fee'  => 2000,
			),
			array(
				'test_condition_name'   => '貸し切り予約ONのみ（ユーザー貸切指定は未設定）=> 貸切指定は元々無効・料金0（境界値）',
				'exclusive_when_booked' => true,
				'user_selectable'       => false,
				'expect_user_exclusive' => false,
				'expect_exclusive_fee'  => 0,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			if ( $case['exclusive_when_booked'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			}
			if ( $case['user_selectable'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
				update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', 1000 );
			}

			$date  = sprintf( '2027-01-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );

			$draft_controller = new Booking_Draft_Controller( new Settings_Repository() );
			$body             = array(
				'menu_id'        => $menu_id,
				'resource_id'    => $staff_id,
				'date'           => $date,
				'guests'         => 2,
				'user_exclusive' => true,
				'slot'           => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'meta'           => array( 'timezone' => 'Asia/Tokyo' ),
			);
			$save_request     = new WP_REST_Request( 'POST', '/vkbm/v1/booking-drafts' );
			$save_request->set_header( 'Content-Type', 'application/json' );
			$save_request->set_body( (string) wp_json_encode( $body ) );

			$save_response = $draft_controller->save_draft( $save_request );
			$this->assertInstanceOf( WP_REST_Response::class, $save_response, $case['test_condition_name'] . ' / 下書き保存' );

			$token = (string) ( $save_response->get_data()['token'] ?? '' );
			$this->assertNotSame( '', $token, $case['test_condition_name'] . ' / トークン発行' );

			$get_request = new WP_REST_Request( 'GET', '/vkbm/v1/booking-drafts' );
			$get_request->set_param( 'token', $token );
			$get_response = $draft_controller->get_draft( $get_request );
			$this->assertInstanceOf( WP_REST_Response::class, $get_response, $case['test_condition_name'] . ' / 下書き取得' );

			$data = $get_response->get_data();
			$this->assertSame( $case['expect_user_exclusive'], $data['user_exclusive'], $case['test_condition_name'] . ' / user_exclusive' );
			$this->assertSame( $case['expect_exclusive_fee'], $data['exclusive_fee'], $case['test_condition_name'] . ' / exclusive_fee' );

			delete_transient( self::TRANSIENT_PREFIX . $token );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 「貸し切り予約」ONのメニューでは、下書き段階で既に貸切指定が無視されているため、
	 * 予約確定でも貸し切り料金が付与されない（枠自体は「貸し切り予約」設定により引き続き貸切になる。
	 * これはメニュー側の意図した挙動であり、修正対象は「指定した人だけ割高になる」料金側の不整合）。
	 * 対照として「貸し切り予約」OFF（ユーザー貸切指定のみON）では従来どおり貸切フラグ・料金が付与される。
	 */
	public function test_create_booking(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '貸し切り予約ON＋ユーザー貸切指定ON => 枠は貸切になるが料金は付かない（#388・本修正）',
				'exclusive_when_booked' => true,
				// 貸切フラグは「貸し切り予約」設定自体で true になる（意図した挙動・本修正の対象外）。
				'expect_exclusive'      => true,
				// 修正対象はここ：ユーザー指定に基づく貸し切り料金は加算されない。
				'expect_fee'            => 0,
			),
			array(
				'test_condition_name'   => '貸し切り予約OFF（ユーザー貸切指定のみON）=> 従来どおり貸切フラグ・料金が付与される（対照・回帰防止）',
				'exclusive_when_booked' => false,
				'user_selectable'       => true,
				'expect_exclusive'      => true,
				'expect_fee'            => 2000,
			),
			array(
				'test_condition_name'   => '貸し切り予約OFF＋ユーザー貸切指定OFF（未選択）=> 貸切フラグ・料金とも付かない（境界値）',
				'exclusive_when_booked' => false,
				'user_selectable'       => false,
				'expect_exclusive'      => false,
				'expect_fee'            => 0,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			if ( ! array_key_exists( 'user_selectable', $case ) || $case['user_selectable'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
				update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', 1000 );
			}
			if ( $case['exclusive_when_booked'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			}

			$date  = sprintf( '2027-02-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );

			// 下書き保存を経由して、実運用と同じ経路（save_draft のサーバ側ガード込み）で
			// 予約者の貸切指定リクエストを一時データへ反映させる。
			$draft_controller = new Booking_Draft_Controller( new Settings_Repository() );
			$body             = array(
				'menu_id'        => $menu_id,
				'resource_id'    => $staff_id,
				'date'           => $date,
				'guests'         => 2,
				'user_exclusive' => true,
				'slot'           => array(
					'slot_id'  => 'slot-1',
					'start_at' => $start,
					'end_at'   => $end,
				),
				'meta'           => array( 'timezone' => 'Asia/Tokyo' ),
			);
			$save_request     = new WP_REST_Request( 'POST', '/vkbm/v1/booking-drafts' );
			$save_request->set_header( 'Content-Type', 'application/json' );
			$save_request->set_body( (string) wp_json_encode( $body ) );
			$save_response = $draft_controller->save_draft( $save_request );
			$this->assertInstanceOf( WP_REST_Response::class, $save_response, $case['test_condition_name'] . ' / 下書き保存' );
			$token = (string) ( $save_response->get_data()['token'] ?? '' );

			$confirmation_controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$confirm_request         = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$confirm_request->set_param( 'token', $token );
			$confirm_request->set_param( 'agree_terms', true );

			$response = $confirmation_controller->create_booking( $confirm_request );
			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ' / 予約確定' );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$exclusive_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive' );
			$this->assertSame( $case['expect_exclusive'], $exclusive_exists, $case['test_condition_name'] . ' / 貸切フラグのキー存在' );

			$fee_exists      = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive_fee' );
			$expect_fee_meta = $case['expect_fee'] > 0;
			$this->assertSame( $expect_fee_meta, $fee_exists, $case['test_condition_name'] . ' / 貸切料金スナップショットのキー存在' );
			if ( $expect_fee_meta ) {
				$fee = (int) get_post_meta( $booking_id, '_vkbm_booking_exclusive_fee', true );
				$this->assertSame( $case['expect_fee'], $fee, $case['test_condition_name'] . ' / 貸切料金スナップショットの値' );
			}

			delete_transient( self::TRANSIENT_PREFIX . $token );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * create_booking: 確定コントローラに追加した is_user_exclusive_selectable() ガード単体を検証する。
	 *
	 * test_create_booking() は save_draft() を経由するため、下書き側ガードの時点で
	 * user_exclusive が false に落ちてしまい、下書きの transient には既に false しか入らない。
	 * これでは確定コントローラ側に追加したガードを丸ごと削除しても緑のまま通ってしまい、
	 * 多層防御の1枚（確定側）が将来のリファクタで無言で剥がれても気づけない。
	 *
	 * このテストは save_draft() を経由せず、下書き transient に直接 user_exclusive => true を
	 * 仕込むことで下書き側ガードを迂回し、確定コントローラ側ガード単体が効いていることを検証する
	 * （過去に保存済みの下書き・transient への直接改ざんを模したケースでもある）。
	 *
	 * 下書き側ガードを経由しない「メニューが貸切不可なのに user_exclusive=true」という状態は、
	 * #305 の既存設計（黙って通常予約に変換せず 409 で拒否し、利用者の明示的な貸切指定を
	 * 失わせない）により exclusive_unavailable(409) で拒否される
	 * （test-user-exclusive-booking.php の selectable=false ケースと同型の挙動）。
	 * このテストが検証したいのは「409で拒否されること」自体ではなく、
	 * 確定コントローラの is_user_exclusive_selectable() が「貸し切り予約」ONを見て false を
	 * 返すからこそこの409に到達している、という点。この判定を消すと user_exclusive がそのまま
	 * true 扱いとなり、409にならず貸切料金が加算されたまま予約が成立してしまう
	 * （＝このテストが FAIL する）ことで、確定側ガードの単体効果を検証する。
	 *
	 * 正常系はさらに2パターン用意する。1つは「貸し切り予約」OFFの対照（ゲート自体を通る従来経路）、
	 * もう1つは「貸し切り予約」ONだが予約者が貸切を「要求していない」ケース（下書きに直接
	 * user_exclusive=false を仕込む）。後者は、確定側ガードが「貸切を要求された時だけ介入し、
	 * 通常の予約成立まで巻き込んで止めてしまわないこと」を確認する目的で、
	 * 異常系（1件目）とはメニュー設定は同じで「予約者が何を要求したか」だけが違う。
	 */
	public function test_create_booking_confirmation_guard(): void {
		$test_cases = array(
			array(
				'test_condition_name'      => '貸し切り予約ON＋下書きに直接 user_exclusive=true を仕込む => 確定側ガードにより exclusive_unavailable(409)（多層防御の回帰防止・#388）',
				'exclusive_when_booked'    => true,
				'user_exclusive_requested' => true,
				'expect_error'             => 'exclusive_unavailable',
			),
			array(
				'test_condition_name'      => '貸し切り予約OFF（ユーザー貸切指定のみON）＋下書きに直接 user_exclusive=true => 従来どおり予約成立・料金が加算される（正常系・対照）',
				'exclusive_when_booked'    => false,
				'user_exclusive_requested' => true,
				'expect_error'             => null,
				'expect_exclusive'         => true,
				'expect_fee'               => 2000,
			),
			array(
				'test_condition_name'      => '貸し切り予約ON＋下書きに直接 user_exclusive=false を仕込む（予約者は貸切を要求していない）=> 通常どおり予約成立、枠は貸切だが料金は付かない（正常系）',
				'exclusive_when_booked'    => true,
				'user_exclusive_requested' => false,
				'expect_error'             => null,
				'expect_exclusive'         => true,
				'expect_fee'               => 0,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();

			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
			update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
			update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', 1000 );
			if ( $case['exclusive_when_booked'] ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			}

			$date  = sprintf( '2027-03-%02d', $index + 1 );
			$start = $date . 'T10:00:00+09:00';
			$end   = $date . 'T11:00:00+09:00';

			$user_id = $this->factory()->user->create();
			wp_set_current_user( $user_id );

			// save_draft() を経由せず、下書き側ガードを迂回して transient に直接仕込む。
			$token = $this->store_draft_directly( $menu_id, $staff_id, $start, $end, 2, $case['user_exclusive_requested'] );

			$confirmation_controller = $this->build_confirmation_controller( $staff_id, $start, $end );
			$confirm_request         = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
			$confirm_request->set_param( 'token', $token );
			$confirm_request->set_param( 'agree_terms', true );

			$response = $confirmation_controller->create_booking( $confirm_request );

			if ( null !== $case['expect_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $response, $case['test_condition_name'] . ' / 拒否される' );
				$this->assertSame( $case['expect_error'], $response->get_error_code(), $case['test_condition_name'] . ' / エラーコード' );
				$error_data = $response->get_error_data();
				$this->assertSame( 409, (int) ( $error_data['status'] ?? 0 ), $case['test_condition_name'] . ' / 409 ステータス' );
				delete_transient( self::TRANSIENT_PREFIX . $token );
				wp_set_current_user( 0 );
				continue;
			}

			$this->assertInstanceOf( WP_REST_Response::class, $response, $case['test_condition_name'] . ' / 予約成立' );

			$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
			$this->assertGreaterThan( 0, $booking_id, $case['test_condition_name'] );

			$exclusive_exists = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive' );
			$this->assertSame( $case['expect_exclusive'], $exclusive_exists, $case['test_condition_name'] . ' / 貸切フラグのキー存在' );

			$fee_exists      = metadata_exists( 'post', $booking_id, '_vkbm_booking_exclusive_fee' );
			$expect_fee_meta = $case['expect_fee'] > 0;
			$this->assertSame( $expect_fee_meta, $fee_exists, $case['test_condition_name'] . ' / 貸切料金スナップショットのキー存在' );
			if ( $expect_fee_meta ) {
				$fee = (int) get_post_meta( $booking_id, '_vkbm_booking_exclusive_fee', true );
				$this->assertSame( $case['expect_fee'], $fee, $case['test_condition_name'] . ' / 貸切料金スナップショットの値' );
			}

			delete_transient( self::TRANSIENT_PREFIX . $token );
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
			new Exclusive_Conflict_Notification_Test_Double(),
			$settings,
			new Exclusive_Conflict_Availability_Test_Double(
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
	 * 下書き transient を、Booking_Draft_Controller::save_draft() を経由せず直接作成する。
	 *
	 * 下書き側ガード（Booking_Draft_Controller::is_user_exclusive_selectable）を迂回した状態を
	 * 再現し、確定コントローラ側ガード単体の効果を検証するために使う
	 * （test-max-capacity-disables-multi-guest-settings.php の store_draft() と同型）。
	 *
	 * @param int    $menu_id        メニューID。
	 * @param int    $staff_id       スタッフID。
	 * @param string $start_at       スロット開始（ISO8601）。
	 * @param string $end_at         スロット終了（ISO8601）。
	 * @param int    $guests         申込人数。
	 * @param bool   $user_exclusive ユーザー貸切選択フラグ（下書き側ガードを介さず直接仕込む値）。
	 * @return string トークン。
	 */
	private function store_draft_directly( int $menu_id, int $staff_id, string $start_at, string $end_at, int $guests, bool $user_exclusive ): string {
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

		return $token;
	}
}

/**
 * 通知を抑止するテストダブル。
 */
class Exclusive_Conflict_Notification_Test_Double extends Booking_Notification_Service {
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
class Exclusive_Conflict_Availability_Test_Double extends Availability_Service {
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
