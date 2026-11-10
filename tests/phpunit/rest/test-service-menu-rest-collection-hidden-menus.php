<?php
/**
 * GET /wp/v2/vkbm_service_menu が、公開の一覧から外すメニューを返さないことのテスト（#1047）。
 *
 * 予約フォームのメニュー選択は、この投稿一覧 API から候補を取る。
 * アーカイブ済み・提供期間終了は、未ログインの一覧から外す。
 * 投稿 ID を指定した 1 件取得と、編集画面用の取得は変えない。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * サービスメニューの投稿一覧 API が、公開の一覧と同じ条件になることを検証するテストクラス。
 *
 * @group rest
 */
class Service_Menu_Rest_Collection_Hidden_Menus_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に未ログイン状態へ戻す。
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * テスト用のサービスメニューを作る。
	 *
	 * @param array<string,mixed>  $post_args 投稿の追加引数。
	 * @param array<string,string> $meta      追加するメタ。
	 * @return int 投稿 ID。
	 */
	private function create_menu( array $post_args = array(), array $meta = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array_merge(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => '一覧検証メニュー',
				),
				$post_args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 投稿一覧 API を呼ぶ。
	 *
	 * @param array<string,mixed> $params クエリ引数。
	 * @return \WP_REST_Response レスポンス。
	 */
	private function dispatch_collection( array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE );
		$request->set_param( 'per_page', 100 );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * 一覧レスポンスから投稿 ID を取り出す。
	 *
	 * @param \WP_REST_Response $response レスポンス。
	 * @return array<int, int> 投稿 ID。
	 */
	private function collection_ids( $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return array_map(
			static function ( $item ): int {
				return (int) ( $item['id'] ?? 0 );
			},
			$data
		);
	}

	/**
	 * 未ログインの投稿一覧から、公開中だけが残り、アーカイブ済み・提供終了が外れることを確認する。
	 */
	public function test_anonymous_collection_hides_archived_and_ended_menus(): void {
		wp_set_current_user( 0 );

		$yesterday = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$tomorrow  = gmdate( 'Y-m-d', strtotime( '+2 days' ) );

		$public_id   = $this->create_menu( array( 'post_title' => '公開中一覧検証' ) );
		$archived_id = $this->create_menu(
			array( 'post_title' => 'アーカイブ一覧検証' ),
			array( '_vkbm_is_archived' => '1' )
		);
		$ended_id    = $this->create_menu(
			array( 'post_title' => '提供終了一覧検証' ),
			array( '_vkbm_service_period_end' => $yesterday )
		);
		$upcoming_id = $this->create_menu(
			array( 'post_title' => '提供開始前一覧検証' ),
			array( '_vkbm_service_period_start' => $tomorrow )
		);
		$flag_off_id = $this->create_menu(
			array( 'post_title' => 'アーカイブフラグ0一覧検証' ),
			array( '_vkbm_is_archived' => '0' )
		);

		$ids = $this->collection_ids( $this->dispatch_collection() );

		$this->assertContains( $public_id, $ids, '公開中 => 一覧に残る' );
		$this->assertContains( $upcoming_id, $ids, '提供開始前 => 先行予約のため残る' );
		$this->assertContains( $flag_off_id, $ids, 'アーカイブフラグが 0 => 残る' );
		$this->assertNotContains( $archived_id, $ids, 'アーカイブ済み => 外す' );
		$this->assertNotContains( $ended_id, $ids, '提供期間終了 => 外す' );
	}

	/**
	 * 未ログインが context=edit を付けても、アーカイブ済みは外れたままであることを確認する。
	 */
	public function test_anonymous_cannot_bypass_hide_with_edit_context(): void {
		wp_set_current_user( 0 );

		$archived_id = $this->create_menu(
			array( 'post_title' => '編集文脈バイパス検証' ),
			array( '_vkbm_is_archived' => '1' )
		);

		$ids = $this->collection_ids( $this->dispatch_collection( array( 'context' => 'edit' ) ) );

		$this->assertNotContains( $archived_id, $ids, '未ログインの context=edit => アーカイブ済みは外す' );
	}

	/**
	 * メニューを編集できる利用者が、編集画面用の一覧ではアーカイブ済みを見られることを確認する。
	 */
	public function test_editor_edit_context_includes_archived_menus(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_SERVICE_MENUS );
		wp_set_current_user( $user_id );

		$archived_id = $this->create_menu(
			array( 'post_title' => '編集者アーカイブ検証' ),
			array( '_vkbm_is_archived' => '1' )
		);

		$view_ids = $this->collection_ids( $this->dispatch_collection() );
		$edit_ids = $this->collection_ids( $this->dispatch_collection( array( 'context' => 'edit' ) ) );

		$this->assertNotContains( $archived_id, $view_ids, '編集できる利用者でも画面表示用の一覧からは外す' );
		$this->assertContains( $archived_id, $edit_ids, '編集画面用の一覧には残す' );
	}

	/**
	 * 投稿 ID を指定した 1 件取得は、アーカイブ済みでも今までどおり返すことを確認する。
	 */
	public function test_anonymous_single_get_still_returns_archived_menu(): void {
		wp_set_current_user( 0 );

		$archived_id = $this->create_menu(
			array( 'post_title' => '1件取得アーカイブ検証' ),
			array( '_vkbm_is_archived' => '1' )
		);

		$request  = new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $archived_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $archived_id, (int) ( $response->get_data()['id'] ?? 0 ) );
	}
}
