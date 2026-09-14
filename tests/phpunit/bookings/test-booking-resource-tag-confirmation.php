<?php
/**
 * 予約確定時のリソースタグ最終チェック・メタ保存のテスト（issue #431）。
 *
 * 空き枠計算（Availability_Service）の時点でタグを持つリソースだけに候補を絞っているため
 * 通常はここで弾かれることは無いが、Booking_Confirmation_Controller::create_booking() は
 * 「割り当て結果のリソースが指定タグを『すべて』持っているか」を多層防御として最終チェックする。
 * ここではその最終チェック（409）と、予約メタ `_vkbm_booking_resource_tag_ids` の保存を検証する。
 *
 * このファイルは tests/phpunit/bookings/test-booking-confirmation-controller.php が定義する
 * テストダブル（Availability_Service_Test_Double / Booking_Notification_Service_Test_Double。
 * 同一名前空間 VKBookingManager\Tests\Bookings）を再利用する。PHPUnit は実行前にテストスイート内の
 * 全ファイルを読み込むため、クラス定義はこのファイルの実行時点で利用可能になっている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function delete_transient;
use function get_post_meta;
use function set_transient;
use function update_option;
use function update_post_meta;
use function wp_generate_password;
use function wp_insert_term;
use function wp_set_current_user;
use function wp_set_object_terms;

/**
 * 予約確定時のリソースタグ最終チェック・メタ保存を検証するテストクラス。
 *
 * @group bookings
 * @group resource-tag
 */
class Booking_Resource_Tag_Confirmation_Test extends WP_UnitTestCase {
	private const TRANSIENT_PREFIX = 'vkbm_draft_';
	private const OWNER_COOKIE     = 'vkbm_draft_owner';

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
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前の Cookie・全体設定（option）を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup     = $_COOKIE;
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * 作成した下書き transient・Cookie・全体設定・静的キャッシュをテスト前の状態へ戻す。
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
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * サイト全体の指名機能をONにする（この一連のテストは指名予約フローで検証する）。
	 */
	private function enable_nomination(): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * テスト用のサービスメニューを作成するヘルパー。
	 *
	 * @return int 作成したサービスメニューの投稿ID。
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
	 * テスト用のスタッフ（リソース）投稿を作成するヘルパー。
	 *
	 * @param array<int> $tag_ids 割り当てるリソースタグのターム ID 配列。
	 * @return int 作成したリソース投稿ID。
	 */
	private function create_staff( array $tag_ids = array() ): int {
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		if ( ! empty( $tag_ids ) ) {
			wp_set_object_terms( $staff_id, $tag_ids, Resource_Tag_Taxonomy::TAXONOMY );
		}

		return $staff_id;
	}

	/**
	 * テスト用のリソースタグタームを作成するヘルパー。
	 *
	 * @param string $name タグ名。
	 * @return int タームID。
	 */
	private function create_tag( string $name ): int {
		$result = wp_insert_term( $name, Resource_Tag_Taxonomy::TAXONOMY );
		$this->assertIsArray( $result, 'wp_insert_term() はタームを作成できるべき: ' . $name );
		return (int) $result['term_id'];
	}

	/**
	 * 予約一時データ（下書き）を transient へ保存するヘルパー。
	 *
	 * @param int        $menu_id  サービスメニューID。
	 * @param int        $staff_id 指名するスタッフID。
	 * @param string     $start_at スロット開始（ISO8601）。
	 * @param string     $end_at   スロット終了（ISO8601）。
	 * @param array<int> $tag_ids  下書きに保存するリソースタグのターム ID 配列。
	 * @return string 生成したトークン。
	 */
	private function store_temporary_reservation_data(
		int $menu_id,
		int $staff_id,
		string $start_at,
		string $end_at,
		array $tag_ids = array()
	): string {
		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'resource_tag_ids'     => $tag_ids,
			'guests'               => 1,
			'is_staff_preferred'   => true,
			'slot'                 => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start_at,
				'end_at'   => $end_at,
			),
			'assignable_staff_ids' => array( $staff_id ),
			'meta'                 => array(
				'timezone' => 'Asia/Tokyo',
			),
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		return $token;
	}

	/**
	 * テストダブルを組み込んだ Booking_Confirmation_Controller を組み立てるヘルパー。
	 *
	 * @param int    $staff_id 空き枠計算ダブルが返すスロットの担当スタッフID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return Booking_Confirmation_Controller
	 */
	private function build_controller( int $staff_id, string $start_at, string $end_at ): Booking_Confirmation_Controller {
		$notification_service = new Booking_Notification_Service_Test_Double();
		$settings_repository  = new Settings_Repository();
		$availability_service = new Availability_Service_Test_Double(
			array(
				'slot_id'              => 'slot-1',
				'start_at'             => $start_at,
				'end_at'               => $end_at,
				'service_end_at'       => $end_at,
				'staff'                => array( 'id' => $staff_id ),
				'assignable_staff_ids' => array( $staff_id ),
			)
		);

		return new Booking_Confirmation_Controller(
			$notification_service,
			$settings_repository,
			$availability_service
		);
	}

	/**
	 * create_booking() が、指定したリソースタグをすべて持つ担当への予約を正しく確定し、
	 * 予約メタ `_vkbm_booking_resource_tag_ids` を保存することを検証する（正常系）。
	 */
	public function test_create_booking_saves_resource_tag_ids_when_staff_matches(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$tag_female = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$menu_id    = $this->create_menu();
		$staff_id   = $this->create_staff( array( $tag_female ) );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-09-01T10:00:00+09:00';
		$end   = '2026-09-01T11:00:00+09:00';

		$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, array( $tag_female ) );

		$controller = $this->build_controller( $staff_id, $start, $end );

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, '希望タグを持つ担当への予約は成立するべき' );

		$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
		$this->assertGreaterThan( 0, $booking_id );

		$this->assertSame(
			array( $tag_female ),
			get_post_meta( $booking_id, '_vkbm_booking_resource_tag_ids', true ),
			'指定したリソースタグのターム ID 配列が予約メタへ保存されるべき'
		);
	}

	/**
	 * create_booking() が、指定したリソースタグを持たない担当への割り当てを 409 で拒否することを
	 * 検証する（多層防御。空き枠計算の絞り込みが機能しなかった場合の最終チェック）。
	 */
	public function test_create_booking_rejects_when_assigned_staff_lacks_required_tag(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$tag_female = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$menu_id    = $this->create_menu();
		// #431 最終チェックを直接検証するため、スタッフには意図的にタグを割り当てない。
		$staff_id = $this->create_staff( array() );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-09-01T10:00:00+09:00';
		$end   = '2026-09-01T11:00:00+09:00';

		// 下書きにはタグ指定を保存するが、Availability_Service はテストダブルで
		// 常にこのスタッフを候補として返すため、空き枠計算側の絞り込みでは弾かれない
		// （＝最終チェック単体の効果を検証できる状態を作る）。
		$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, array( $tag_female ) );

		$controller = $this->build_controller( $staff_id, $start, $end );

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );

		$this->assertInstanceOf( \WP_Error::class, $response, 'タグを持たない担当への予約は拒否されるべき' );
		$this->assertSame( 'resource_tag_unavailable', $response->get_error_code() );
		$error_data = $response->get_error_data();
		$this->assertSame( 409, $error_data['status'] ?? null, '拒否時のHTTPステータスは409であるべき' );
	}

	/**
	 * リソースタグを指定しない予約では、`_vkbm_booking_resource_tag_ids` メタが保存されないことを
	 * 検証する（issue #431 完了条件「タグ未指定の予約はメタを保存しない」・境界値）。
	 */
	public function test_create_booking_does_not_save_meta_when_no_tag_specified(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->enable_nomination();

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff( array() );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-09-01T10:00:00+09:00';
		$end   = '2026-09-01T11:00:00+09:00';

		$token = $this->store_temporary_reservation_data( $menu_id, $staff_id, $start, $end, array() );

		$controller = $this->build_controller( $staff_id, $start, $end );

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$booking_id = (int) ( $response->get_data()['booking_id'] ?? 0 );
		$this->assertGreaterThan( 0, $booking_id );

		$this->assertSame(
			'',
			get_post_meta( $booking_id, '_vkbm_booking_resource_tag_ids', true ),
			'タグ未指定の予約はメタを保存しないべき（get_post_meta の未設定時の戻り値は空文字）'
		);
	}
}
