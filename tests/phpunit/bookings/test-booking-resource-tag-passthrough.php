<?php
/**
 * 「下書きにタグを保存し、確定時の再検証へ渡す」経路のテスト（issue #431）。
 *
 * 検証する2経路:
 * (1) Booking_Draft_Controller::save_draft() が resource_tag_ids を正規化・上限つきで
 *     保存すること（class-booking-draft-controller.php:475 付近）。
 * (2) Booking_Confirmation_Controller::create_booking() が下書きの resource_tag_ids を
 *     予約確定時の空き枠再検証（Availability_Service::get_daily_slots()）へそのまま
 *     渡すこと（class-booking-confirmation-controller.php:1196 付近）。
 *
 * このファイルは tests/phpunit/bookings/test-booking-confirmation-controller.php が定義する
 * Booking_Notification_Service_Test_Double（同一名前空間 VKBookingManager\Tests\Bookings）を
 * 再利用する。PHPUnit は実行前にテストスイート内の全ファイルを読み込むため、クラス定義は
 * このファイルの実行時点で利用可能になっている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Common\Resource_Tag_Id_List;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function array_merge;
use function delete_transient;
use function get_transient;
use function range;
use function set_transient;
use function wp_generate_password;
use function wp_insert_term;
use function wp_json_encode;
use function wp_set_current_user;
use function wp_set_object_terms;

/**
 * 「下書きにタグを保存し、確定時の再検証へ渡す」経路を検証するテストクラス。
 *
 * @group bookings
 * @group resource-tag
 */
class Booking_Resource_Tag_Passthrough_Test extends WP_UnitTestCase {
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
	 * テスト前の $_COOKIE を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cookie_backup = $_COOKIE;
	}

	/**
	 * 作成した下書き transient・Cookie をテスト前の状態へ戻す。
	 */
	protected function tearDown(): void {
		foreach ( $this->tokens as $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->tokens = array();
		$_COOKIE      = $this->cookie_backup;
		wp_set_current_user( 0 );
		parent::tearDown();
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
	 * @return int 作成したリソース投稿ID。
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
	 * (1) save_draft() が resource_tag_ids を Resource_Tag_Id_List::normalize() と
	 * 同じルールで正規化・上限つきで保存することを検証する。
	 *
	 * ミューテーション確認: この検証を担う実装行
	 * （class-booking-draft-controller.php:475 付近の `'resource_tag_ids' => $resource_tag_ids,`）を
	 * 一時的に削除すると、このテストが失敗することを確認済み（削除後は transient に
	 * resource_tag_ids キー自体が存在せず、本テストの assertSame が失敗した）。確認後、
	 * 該当行は元に戻してコミットしている。
	 */
	public function test_save_draft_normalizes_and_caps_resource_tag_ids(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu();
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		// 負値・重複・上限超過を含む入力（Resource_Tag_Id_List::normalize() の正規化仕様を
		// そのまま検証する）。上限件数 + 5 件の連番に、先頭へ負値・重複を混ぜる。
		$over_limit_tail = range( 1, Resource_Tag_Id_List::MAX_COUNT + 5 );
		$raw_tag_ids     = array_merge( array( -5, 1, 1 ), $over_limit_tail );

		// 期待値は Resource_Tag_Id_List::normalize() を呼ばず、正規化仕様（正の整数のみ・
		// 重複除去・先頭から上限件数で打ち切り）から実際の値を直接書き下す。
		// $raw_tag_ids は -5（負値なので除外）・1（重複）に続けて 1〜25 の連番なので、
		// 正規化後は 1〜20（MAX_COUNT）の連番になるはず。
		$expected = range( 1, Resource_Tag_Id_List::MAX_COUNT );

		$payload = array(
			'menu_id'          => $menu_id,
			'date'             => '2026-10-01',
			'resource_tag_ids' => $raw_tag_ids,
			'slot'             => array(
				'slot_id'  => 'slot-1',
				'start_at' => '2026-10-01T10:00:00+09:00',
				'end_at'   => '2026-10-01T10:30:00+09:00',
			),
			'meta'             => array(
				'timezone' => 'Asia/Tokyo',
			),
		);

		$controller = new Booking_Draft_Controller();
		$request    = new WP_REST_Request( 'POST', '/vkbm/v1/drafts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = $controller->save_draft( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data  = $response->get_data();
		$token = isset( $data['token'] ) ? (string) $data['token'] : '';
		$this->assertNotSame( '', $token );
		$this->tokens[] = $token;

		// get_draft() 経由ではなく、保存された transient の生ペイロードを直接確認する
		// （save_draft() が実際に何を保存したかを、料金計算等の後処理を挟まずに検証するため）。
		$stored = get_transient( self::TRANSIENT_PREFIX . $token );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'resource_tag_ids', $stored );
		$this->assertSame( $expected, $stored['resource_tag_ids'] );
	}

	/**
	 * (2) create_booking() が下書きの resource_tag_ids を、確定時の空き枠再検証
	 * （Availability_Service::get_daily_slots()）へそのまま渡すことを検証する。
	 *
	 * ミューテーション確認: この受け渡しを担う実装行
	 * （class-booking-confirmation-controller.php:1196 付近の
	 * revalidate_draft_slot() 内 `'resource_tag_ids' => $tag_ids,`）を一時的に削除すると、
	 * このテストが失敗することを確認済み（削除後は get_daily_slots() へ渡る引数に
	 * resource_tag_ids キー自体が存在せず、本テストの assertSame が失敗した）。確認後、
	 * 該当行は元に戻してコミットしている。
	 */
	public function test_create_booking_passes_resource_tag_ids_to_daily_slots_revalidation(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();

		// 予約確定時の最終タグチェック（Resource_Tag_Taxonomy::resource_has_all_tags()）に
		// 弾かれず正常に成立させるため、実在するタームを作成してスタッフへ割り当てる
		// （このテストの検証対象は「渡す経路」であり、タグ一致判定そのものではない）。
		$tag_a = wp_insert_term( 'passthrough_tag_a_' . wp_generate_password( 6, false ), 'vkbm_resource_tag' );
		$tag_b = wp_insert_term( 'passthrough_tag_b_' . wp_generate_password( 6, false ), 'vkbm_resource_tag' );
		$this->assertIsArray( $tag_a );
		$this->assertIsArray( $tag_b );
		$tag_ids = array( (int) $tag_a['term_id'], (int) $tag_b['term_id'] );
		wp_set_object_terms( $staff_id, $tag_ids, 'vkbm_resource_tag' );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE[ self::OWNER_COOKIE ] = 'owner_test';

		$start = '2026-10-02T10:00:00+09:00';
		$end   = '2026-10-02T11:00:00+09:00';

		$token   = 'token_' . strtolower( wp_generate_password( 8, false, false ) );
		$payload = array(
			'menu_id'              => $menu_id,
			'resource_id'          => $staff_id,
			'resource_tag_ids'     => $tag_ids,
			'guests'               => 1,
			'is_staff_preferred'   => true,
			'slot'                 => array(
				'slot_id'  => 'slot-1',
				'start_at' => $start,
				'end_at'   => $end,
			),
			'assignable_staff_ids' => array( $staff_id ),
			'meta'                 => array(
				'timezone' => 'Asia/Tokyo',
			),
		);
		set_transient( self::TRANSIENT_PREFIX . $token, $payload );
		$this->tokens[] = $token;

		$availability_double = new Availability_Service_Args_Recording_Test_Double(
			array(
				'slot_id'              => 'slot-1',
				'start_at'             => $start,
				'end_at'               => $end,
				'service_end_at'       => $end,
				'staff'                => array( 'id' => $staff_id ),
				'assignable_staff_ids' => array( $staff_id ),
			)
		);

		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service_Test_Double(),
			new Settings_Repository(),
			$availability_double
		);

		$request = new WP_REST_Request( 'POST', '/vkbm/v1/bookings' );
		$request->set_param( 'token', $token );
		$request->set_param( 'agree_terms', true );

		$response = $controller->create_booking( $request );
		// 失敗時に原因を追いやすいよう、WP_Error ならコード・メッセージをアサーション文言へ含める。
		$failure_message = '正常な下書きなので予約は成立するべき';
		if ( $response instanceof \WP_Error ) {
			$failure_message .= sprintf( ' (code=%s message=%s)', $response->get_error_code(), $response->get_error_message() );
		}
		$this->assertInstanceOf( WP_REST_Response::class, $response, $failure_message );

		$this->assertIsArray( $availability_double->last_get_daily_slots_args, 'get_daily_slots() が呼ばれているべき' );
		$this->assertArrayHasKey( 'resource_tag_ids', $availability_double->last_get_daily_slots_args );
		$this->assertSame(
			$tag_ids,
			$availability_double->last_get_daily_slots_args['resource_tag_ids'],
			'下書きに保存した resource_tag_ids が、確定時の再検証（get_daily_slots）へそのまま渡るべき'
		);
	}
}
