<?php
/**
 * 「すべてのリソースが担当できる」（_vkbm_staff_all、#485）が複数人一括予約のスタッフ数判定に効くことのテスト。
 *
 * 予約確定（Booking_Confirmation_Controller::count_menu_staff）と予約下書き
 * （Booking_Draft_Controller::get_max_guests）は、メニューを担当できるスタッフが1人も
 * いないと複数人一括予約を無効化する。「すべて」のメニューは個別チェックが無くても
 * 公開中の全リソースがスタッフ数として数えられることを、private メソッドのため
 * ReflectionMethod 経由で検証する。
 *
 * 無料版では「すべて」フラグを無視し（Staff_Editor::is_enabled() === false）、複数人一括予約自体も
 * 利用できないため、期待値は Pro / Free で分岐させる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_option;
use function get_option;
use function update_option;
use function update_post_meta;

/**
 * 「すべて」フラグとスタッフ数判定の連動を検証するテストクラス。
 *
 * @group bookings
 * @group staff
 */
class Menu_Staff_All_Multi_Guest_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 全体設定を退避し、キャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * 全体設定・静的キャッシュをテスト前の状態へ戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
		parent::tearDown();
	}

	/**
	 * テスト用のリソース（スタッフ）を作成する。
	 *
	 * @param string $status 投稿ステータス。
	 * @return int 投稿ID。
	 */
	private function create_resource( string $status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => $status,
			)
		);
	}

	/**
	 * テスト用のサービスメニューを作成する。
	 *
	 * @return int 投稿ID。
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
	 * このテストで作ったリソースだけを候補にするため、他の公開中リソースをフィルターで除外する。
	 *
	 * @param array<int> $own_ids 候補に残すリソースID。
	 * @return callable 登録したフィルター（remove_filter 用）。
	 */
	private function restrict_candidates_to( array $own_ids ): callable {
		$filter = static function ( array $staff_ids ) use ( $own_ids ): array {
			return array_values( array_intersect( $staff_ids, $own_ids ) );
		};
		add_filter( 'vkbm_menu_assignable_staff_ids', $filter );
		return $filter;
	}

	/**
	 * Booking_Confirmation_Controller::count_menu_staff() のテスト。
	 */
	public function test_count_menu_staff(): void {
		$is_pro  = Staff_Editor::is_enabled();
		$staff_a = $this->create_resource();
		$staff_b = $this->create_resource();
		$this->create_resource( 'draft' );
		$filter  = $this->restrict_candidates_to( array( $staff_a, $staff_b ) );
		$menu_id = $this->create_menu();

		$controller = new Booking_Confirmation_Controller(
			new Booking_Notification_Service( new Settings_Repository() ),
			new Settings_Repository()
		);
		$reflection = new ReflectionMethod( Booking_Confirmation_Controller::class, 'count_menu_staff' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が1人 => 1（正常系・従来どおり）',
				'staff_all'           => false,
				'staff_ids'           => array( $staff_a ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が空 => 0（境界値・従来どおり）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '「すべて」かつ個別選択が空 => Pro版は公開中の全リソース数（下書きは含まない）、Free版はフラグを無視して 0',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'expected'            => $is_pro ? 2 : 0,
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			$this->assertSame( $case['expected'], $reflection->invoke( $controller, $menu_id ), $case['test_condition_name'] );
		}

		remove_filter( 'vkbm_menu_assignable_staff_ids', $filter );
	}

	/**
	 * Booking_Draft_Controller::get_max_guests() のテスト。
	 *
	 * 複数人一括予約の前提（Pro 版・予約枠の定員機能ON・メニューの許可フラグON・定員2以上）が
	 * 揃っているとき、担当できるスタッフが0人なら 0、「すべて」で1人以上いれば定員を返すことを検証する。
	 */
	public function test_get_max_guests(): void {
		$is_pro  = Staff_Editor::is_enabled();
		$staff_a = $this->create_resource();
		$filter  = $this->restrict_candidates_to( array( $staff_a ) );

		// 予約枠の定員機能を ON にする（複数人一括予約の全体側の前提）。
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );

		$controller = new Booking_Draft_Controller();
		$reflection = new ReflectionMethod( Booking_Draft_Controller::class, 'get_max_guests' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択あり => Pro版は定員 3、Free版は複数人一括予約が使えないため 0（正常系・従来どおり）',
				'staff_all'           => false,
				'staff_ids'           => array( $staff_a ),
				'expected'            => $is_pro ? 3 : 0,
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が空 => 割り当て先が無いため 0（境界値・従来どおり）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '「すべて」かつ個別選択が空 => Pro版は公開中リソースが数えられ定員 3、Free版は 0',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'expected'            => $is_pro ? 3 : 0,
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			$this->assertSame( $case['expected'], $reflection->invoke( $controller, $menu_id ), $case['test_condition_name'] );
		}

		remove_filter( 'vkbm_menu_assignable_staff_ids', $filter );
	}
}
