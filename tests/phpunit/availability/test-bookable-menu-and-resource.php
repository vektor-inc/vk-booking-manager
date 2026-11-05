<?php
/**
 * Availability_Service の find_bookable_menu_post() と is_resource_assignable_to_menu() のテスト。
 *
 * 予約の下書きなど、利用者から受け取った予約メニュー・リソースの ID を検証するときの判定基準。
 * リソースの判定は、空き状況の確認（resolve_staff_ids() の指名の判定）と同じ結果になることも確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use ReflectionMethod;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_Post;
use WP_UnitTestCase;
use function get_post;
use function get_user_by;
use function update_post_meta;
use function wp_set_current_user;

/**
 * 予約メニュー・リソースの ID の判定を検証するテストクラス。
 *
 * @group availability
 */
class Bookable_Menu_And_Resource_Test extends WP_UnitTestCase {

	/**
	 * 各テスト前にキャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * 各テスト後にログイン状態を戻す。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 指定した投稿タイプ・状態の投稿を作る。
	 *
	 * @param string $post_type   投稿タイプ。
	 * @param string $post_status 投稿の状態。
	 * @return int 投稿 ID。
	 */
	private function create_post( string $post_type, string $post_status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => $post_status,
			)
		);
	}

	/**
	 * find_bookable_menu_post(): 予約メニューの投稿タイプで、公開中（権限があれば非公開も）の投稿だけを返す。
	 */
	public function test_find_bookable_menu_post(): void {
		$ids = array(
			'menu'         => $this->create_post( Service_Menu_Post_Type::POST_TYPE ),
			'menu_draft'   => $this->create_post( Service_Menu_Post_Type::POST_TYPE, 'draft' ),
			'menu_private' => $this->create_post( Service_Menu_Post_Type::POST_TYPE, 'private' ),
			'booking'      => $this->create_post( Booking_Post_Type::POST_TYPE ),
			'resource'     => $this->create_post( Resource_Post_Type::POST_TYPE ),
			'page'         => $this->create_post( 'page' ),
		);

		$manager_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $manager_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		$member_id = $this->factory()->user->create();

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の予約メニュー（未ログイン） => その投稿を返す',
				'user_id'             => 0,
				'menu_id'             => $ids['menu'],
				'expected'            => $ids['menu'],
			),
			array(
				'test_condition_name' => '下書きの予約メニュー（未ログイン） => null',
				'user_id'             => 0,
				'menu_id'             => $ids['menu_draft'],
				'expected'            => null,
			),
			array(
				'test_condition_name' => '非公開の予約メニュー（権限のない会員） => null',
				'user_id'             => $member_id,
				'menu_id'             => $ids['menu_private'],
				'expected'            => null,
			),
			array(
				'test_condition_name' => '非公開の予約メニュー（予約の管理権限のある利用者） => その投稿を返す（空き状況の確認と同じ）',
				'user_id'             => $manager_id,
				'menu_id'             => $ids['menu_private'],
				'expected'            => $ids['menu_private'],
			),
			array(
				'test_condition_name' => '下書きの予約メニュー（予約の管理権限のある利用者） => その投稿を返す',
				'user_id'             => $manager_id,
				'menu_id'             => $ids['menu_draft'],
				'expected'            => $ids['menu_draft'],
			),
			array(
				'test_condition_name' => '予約の投稿の ID（予約の管理権限のある利用者でも） => null',
				'user_id'             => $manager_id,
				'menu_id'             => $ids['booking'],
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'リソースの ID => null',
				'user_id'             => 0,
				'menu_id'             => $ids['resource'],
				'expected'            => null,
			),
			array(
				'test_condition_name' => '固定ページの ID => null',
				'user_id'             => 0,
				'menu_id'             => $ids['page'],
				'expected'            => null,
			),
			array(
				'test_condition_name' => '存在しない ID => null',
				'user_id'             => 0,
				'menu_id'             => 99999999,
				'expected'            => null,
			),
			array(
				'test_condition_name' => '0 => null',
				'user_id'             => 0,
				'menu_id'             => 0,
				'expected'            => null,
			),
			array(
				'test_condition_name' => '負の値 => null',
				'user_id'             => 0,
				'menu_id'             => -1,
				'expected'            => null,
			),
		);

		$service = new Availability_Service();
		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );

			$actual = $service->find_bookable_menu_post( $case['menu_id'] );

			if ( null === $case['expected'] ) {
				$this->assertNull( $actual, $case['test_condition_name'] );
				continue;
			}
			$this->assertInstanceOf( WP_Post::class, $actual, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $actual->ID, $case['test_condition_name'] );
		}
	}

	/**
	 * is_resource_assignable_to_menu(): 担当の指定があればその中、未指定なら公開中のリソースだけを担当できるとする。
	 *
	 * 同じ条件で resolve_staff_ids() に指名を渡した結果（エラーかどうか）と一致することも確かめる。
	 */
	public function test_is_resource_assignable_to_menu(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版では空き状況の確認がメニュー側の担当設定を参照しない（基本スタッフ固定）ため、Pro版限定のテスト。' );
		}

		$staff_a  = $this->create_post( Resource_Post_Type::POST_TYPE );
		$staff_b  = $this->create_post( Resource_Post_Type::POST_TYPE );
		$draft_c  = $this->create_post( Resource_Post_Type::POST_TYPE, 'draft' );
		$booking  = $this->create_post( Booking_Post_Type::POST_TYPE );
		$page     = $this->create_post( 'page' );
		$missing  = 99999999;
		$menu_pub = $this->create_post( Service_Menu_Post_Type::POST_TYPE );

		// 担当を個別選択したメニュー・個別選択に下書きを含むメニュー・「すべて」のメニュー・担当が未設定のメニュー。
		$menu_selected = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
		update_post_meta( $menu_selected, '_vkbm_staff_ids', array( $staff_a ) );
		$menu_with_draft = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
		update_post_meta( $menu_with_draft, '_vkbm_staff_ids', array( $staff_a, $draft_c ) );
		$menu_all = $this->create_post( Service_Menu_Post_Type::POST_TYPE );
		update_post_meta( $menu_all, Service_Menu_Post_Type::META_STAFF_ALL, true );

		$test_cases = array(
			array(
				'test_condition_name' => '個別選択したメニューで、選んだ公開中のスタッフ => true',
				'menu_id'             => $menu_selected,
				'resource_id'         => $staff_a,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '個別選択したメニューで、選んでいない公開中のスタッフ => false',
				'menu_id'             => $menu_selected,
				'resource_id'         => $staff_b,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '個別選択に含まれていても、下書きのスタッフ => false',
				'menu_id'             => $menu_with_draft,
				'resource_id'         => $draft_c,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '個別選択したメニューで、予約の投稿の ID => false',
				'menu_id'             => $menu_selected,
				'resource_id'         => $booking,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '担当が未設定のメニューで、公開中のスタッフ => true',
				'menu_id'             => $menu_pub,
				'resource_id'         => $staff_b,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '担当が未設定のメニューで、下書きのスタッフ => false',
				'menu_id'             => $menu_pub,
				'resource_id'         => $draft_c,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '担当が未設定のメニューで、予約の投稿の ID => false',
				'menu_id'             => $menu_pub,
				'resource_id'         => $booking,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '担当が未設定のメニューで、固定ページの ID => false',
				'menu_id'             => $menu_pub,
				'resource_id'         => $page,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '担当が未設定のメニューで、存在しない ID => false',
				'menu_id'             => $menu_pub,
				'resource_id'         => $missing,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「すべて」のメニューで、公開中のスタッフ => true',
				'menu_id'             => $menu_all,
				'resource_id'         => $staff_b,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '「すべて」のメニューで、下書きのスタッフ => false',
				'menu_id'             => $menu_all,
				'resource_id'         => $draft_c,
				'expected'            => false,
			),
		);

		$service = new Availability_Service();
		$resolve = new ReflectionMethod( Availability_Service::class, 'resolve_staff_ids' );
		$resolve->setAccessible( true );
		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$service->is_resource_assignable_to_menu( $case['menu_id'], $case['resource_id'] ),
				$case['test_condition_name']
			);

			// 空き状況の確認が指名を受け付ける条件と同じであること。
			$resolved = $resolve->invoke( $service, get_post( $case['menu_id'] ), $case['resource_id'] );
			$this->assertSame( $case['expected'], ! ( $resolved instanceof WP_Error ), $case['test_condition_name'] . '（resolve_staff_ids() と同じ結果）' );
		}

		// 指名なし（0）・メニューが無い場合は担当できるリソースとしない。
		$this->assertFalse( $service->is_resource_assignable_to_menu( $menu_pub, 0 ), 'resource_id が 0 => false' );
		$this->assertFalse( $service->is_resource_assignable_to_menu( 0, $staff_a ), 'menu_id が 0 => false' );
	}
}
