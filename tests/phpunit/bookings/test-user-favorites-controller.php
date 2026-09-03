<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\User_Favorites_Controller;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function update_post_meta;
use function update_user_meta;
use function wp_delete_post;
use function wp_set_current_user;

/**
 * お気に入り（いつもの）REST コントローラのテスト。
 *
 * @group bookings
 */
class User_Favorites_Controller_Test extends WP_UnitTestCase {

	/**
	 * お気に入りを保持するユーザーメタのキー。
	 */
	private const META_KEY = '_vkbm_favorites';

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * お気に入りを追加すると、取得時に表示用情報付きで返ることを検証する。
	 */
	public function test_create_then_get_returns_favorite_with_display_info(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu( 'カット' );
		$staff_id = $this->create_staff();
		// メニューに対応スタッフを設定し、その指名を許可させる。
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();

		$create = $controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );
		$this->assertInstanceOf( WP_REST_Response::class, $create );
		$this->assertSame( 201, $create->get_status() );

		$created = $create->get_data();
		$this->assertSame( $menu_id, $created['menu_id'] );
		$this->assertSame( $staff_id, $created['resource_id'] );
		$this->assertTrue( $created['available'] );
		$this->assertNotSame( '', (string) $created['id'] );

		// 取得結果にも同じお気に入りが含まれること。
		$list = $controller->get_favorites( new WP_REST_Request( 'GET', '/vkbm/v1/favorites' ) );
		$items = $list->get_data();
		$this->assertCount( 1, $items );
		$this->assertSame( $menu_id, $items[0]['menu_id'] );
		$this->assertSame( 'カット', $items[0]['menu_name'] );
		$this->assertTrue( $items[0]['available'] );
	}

	/**
	 * 存在しないメニューを指定すると拒否されることを検証する。
	 */
	public function test_create_with_invalid_menu_is_rejected(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$controller = new User_Favorites_Controller();
		$response   = $controller->create_favorite( $this->build_create_request( 999999, 0 ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_menu', $response->get_error_code() );
	}

	/**
	 * メニューに紐づかないスタッフを指名すると拒否されることを検証する。
	 */
	public function test_create_with_unassignable_resource_is_rejected(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id        = $this->create_menu();
		$assignable     = $this->create_staff();
		$not_assignable = $this->create_staff();
		// メニューには $assignable のみを対応スタッフとして設定する。
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $assignable ) );

		$controller = new User_Favorites_Controller();
		$response   = $controller->create_favorite( $this->build_create_request( $menu_id, $not_assignable ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_resource', $response->get_error_code() );
	}

	/**
	 * 同じメニュー＋スタッフの組み合わせは重複登録できないことを検証する。
	 */
	public function test_duplicate_combination_is_rejected(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();
		$controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );

		$response = $controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'duplicate_favorite', $response->get_error_code() );
	}

	/**
	 * 上限件数を超える登録は拒否されることを検証する。
	 */
	public function test_create_beyond_limit_is_rejected(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id = $this->create_menu();

		// 上限（20件）まで事前にユーザーメタへ直接投入する。
		$seed = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$seed[] = array(
				'id'          => 'seed-' . $i,
				'menu_id'     => $menu_id,
				// 重複判定を避けるため指名スタッフIDをそれぞれ変える。
				'resource_id' => $i + 1,
				'label'       => 'seed',
			);
		}
		update_user_meta( $user_id, self::META_KEY, $seed );

		$controller = new User_Favorites_Controller();
		// 指名なし（resource_id=0）で新規追加 → 上限超過で拒否されるはず。
		$response = $controller->create_favorite( $this->build_create_request( $menu_id, 0 ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'favorites_limit_reached', $response->get_error_code() );
	}

	/**
	 * お気に入りを削除でき、存在しないIDの削除は 404 になることを検証する。
	 */
	public function test_delete_favorite(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();
		$created     = $controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) )->get_data();
		$favorite_id = (string) $created['id'];

		// 実在するIDの削除は成功する。
		$delete = $controller->delete_favorite( $this->build_delete_request( $favorite_id ) );
		$this->assertInstanceOf( WP_REST_Response::class, $delete );
		$this->assertTrue( $delete->get_data()['deleted'] );

		// 削除後は一覧が空になる。
		$list = $controller->get_favorites( new WP_REST_Request( 'GET', '/vkbm/v1/favorites' ) );
		$this->assertCount( 0, $list->get_data() );

		// 存在しないIDの削除は 404 を返す。
		$missing = $controller->delete_favorite( $this->build_delete_request( 'does-not-exist' ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'favorite_not_found', $missing->get_error_code() );
	}

	/**
	 * お気に入りはユーザーごとに分離され、他人の登録は見えないことを検証する。
	 */
	public function test_favorites_are_isolated_per_user(): void {
		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$user_a = $this->factory()->user->create();
		$user_b = $this->factory()->user->create();

		$controller = new User_Favorites_Controller();

		// ユーザーAが登録する。
		wp_set_current_user( $user_a );
		$controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );

		// ユーザーBには見えない。
		wp_set_current_user( $user_b );
		$list = $controller->get_favorites( new WP_REST_Request( 'GET', '/vkbm/v1/favorites' ) );
		$this->assertCount( 0, $list->get_data() );
	}

	/**
	 * 削除済みメニューのお気に入りは available=false になることを検証する。
	 */
	public function test_deleted_menu_marks_favorite_unavailable(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu( 'カット' );
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();
		$controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );

		// メニューを削除する。
		wp_delete_post( $menu_id, true );

		$list  = $controller->get_favorites( new WP_REST_Request( 'GET', '/vkbm/v1/favorites' ) );
		$items = $list->get_data();
		$this->assertCount( 1, $items );
		$this->assertFalse( $items[0]['available'] );
		$this->assertSame( '', $items[0]['menu_name'] );
	}

	/**
	 * 指名スタッフが削除済みのお気に入りは available=false になることを検証する。
	 */
	public function test_deleted_resource_marks_favorite_unavailable(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu();
		$staff_id = $this->create_staff();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();
		$controller->create_favorite( $this->build_create_request( $menu_id, $staff_id ) );

		// 指名スタッフを削除する（メニューは残す）。
		wp_delete_post( $staff_id, true );

		$items = $controller->get_favorites( new WP_REST_Request( 'GET', '/vkbm/v1/favorites' ) )->get_data();
		$this->assertCount( 1, $items );
		// メニューは生きているが、指名スタッフが消えているので利用不可になる。
		$this->assertFalse( $items[0]['available'] );
	}

	/**
	 * 指名なし（resource_id=0）で登録でき、ラベル既定がメニュー名になることを検証する。
	 * （予約確認画面のチェックボックスから無料版・指名なしで登録するケース）
	 */
	public function test_create_no_nomination_uses_menu_name_as_label(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id = $this->create_menu( 'カット' );

		$controller = new User_Favorites_Controller();
		$created     = $controller->create_favorite(
			$this->build_create_request( $menu_id, 0 )
		)->get_data();

		$this->assertSame( 0, $created['resource_id'] );
		$this->assertSame( 'カット', $created['label'] );
		$this->assertTrue( $created['available'] );
	}

	/**
	 * 指名ありの場合、ラベル既定が「メニュー名 / スタッフ名」になることを検証する。
	 */
	public function test_create_with_nomination_builds_combined_default_label(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id  = $this->create_menu( 'カット' );
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '田中',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$controller = new User_Favorites_Controller();
		$created     = $controller->create_favorite(
			$this->build_create_request( $menu_id, $staff_id )
		)->get_data();

		$this->assertSame( 'カット / 田中', $created['label'] );
	}

	/**
	 * label を明示指定した場合、タグ除去・前後空白除去などサニタイズされた値が
	 * 採用されることを検証する（sanitize_text_field 相当）。
	 */
	public function test_custom_label_is_sanitized(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id = $this->create_menu( 'カット' );

		$request = $this->build_create_request( $menu_id, 0 );
		// HTMLタグ・前後の空白を含む生の入力を渡す。
		$request->set_param( 'label', "  <b>いつもの</b>やつ\n  " );

		$controller = new User_Favorites_Controller();
		$created     = $controller->create_favorite( $request )->get_data();

		// タグが除去され、前後の空白・改行がトリムされた値になること。
		$this->assertSame( 'いつものやつ', $created['label'] );
	}

	/**
	 * ユーザーメタに壊れたデータが混在していても、正常なお気に入りだけ返ることを検証する。
	 */
	public function test_malformed_stored_entries_are_ignored(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$menu_id = $this->create_menu();

		// 正常1件＋壊れたデータ（id無し・menu_id無し・配列でない）を直接投入する。
		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				array(
					'id'          => 'valid-1',
					'menu_id'     => $menu_id,
					'resource_id' => 0,
					'label'       => '正常',
				),
				array(
					'menu_id' => $menu_id,
					'label'   => 'id無しなので無視',
				),
				array(
					'id'    => 'no-menu',
					'label' => 'menu_id無しなので無視',
				),
				'これは配列ですらない',
			)
		);

		$controller = new User_Favorites_Controller();
		$items       = $controller->get_favorites(
			new WP_REST_Request( 'GET', '/vkbm/v1/favorites' )
		)->get_data();

		$this->assertCount( 1, $items );
		$this->assertSame( 'valid-1', $items[0]['id'] );
	}

	/**
	 * 未ログイン時は認可コールバックが拒否することを検証する。
	 */
	public function test_permission_callback_requires_login(): void {
		wp_set_current_user( 0 );

		$controller = new User_Favorites_Controller();
		$result     = $controller->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	/**
	 * 追加リクエストを組み立てる。
	 *
	 * @param int $menu_id     メニューID。
	 * @param int $resource_id スタッフID（0は指名なし）。
	 * @return WP_REST_Request
	 */
	private function build_create_request( int $menu_id, int $resource_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/vkbm/v1/favorites' );
		$request->set_param( 'menu_id', $menu_id );
		$request->set_param( 'resource_id', $resource_id );
		return $request;
	}

	/**
	 * 削除リクエストを組み立てる。
	 *
	 * @param string $id お気に入りID。
	 * @return WP_REST_Request
	 */
	private function build_delete_request( string $id ): WP_REST_Request {
		$request = new WP_REST_Request( 'DELETE', '/vkbm/v1/favorites/' . $id );
		$request->set_param( 'id', $id );
		return $request;
	}

	/**
	 * テスト用のサービスメニューを作成する。
	 *
	 * @param string $title メニュー名。
	 * @return int
	 */
	private function create_menu( string $title = 'テストメニュー' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * テスト用のスタッフ（リソース）を作成する。
	 *
	 * @return int
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}
}
