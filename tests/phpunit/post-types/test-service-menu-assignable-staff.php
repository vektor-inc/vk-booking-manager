<?php
/**
 * Service_Menu_Post_Type の「すべてのリソースが担当できる」（_vkbm_staff_all、#485）まわりのテスト。
 *
 * サービスメニューを担当できるリソースの解決（get_assignable_staff_ids）を1か所に集約したので、
 * その入口となる各静的メソッドと、REST の計算フィールド vkbm_assignable_staff_ids を検証する。
 *
 * 無料版（Staff_Editor::is_enabled() === false）ではメニュー側の担当設定を使わないため、
 * フラグが保存されていても常に無視される（is_all_staff_assigned() が false）。期待値は
 * Pro / Free で分岐させ、同じテストを両方のビルドで実行する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_UnitTestCase;
use function add_filter;
use function delete_post_meta;
use function do_action;
use function remove_filter;
use function update_post_meta;
use function wp_set_current_user;

/**
 * 「すべてのリソースが担当できる」の判定・展開・REST 公開を検証するテストクラス。
 *
 * @group post-types
 * @group staff
 */
class Service_Menu_Assignable_Staff_Test extends WP_UnitTestCase {

	/**
	 * 各テスト前に投稿タイプ・メタを登録し、キャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		// 投稿タイプ・メタ（register_post_meta）を登録する。
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフック。
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * 各テスト後にキャッシュとログイン状態を戻す。
	 */
	protected function tearDown(): void {
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
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
				'post_title'  => 'テストメニュー',
			)
		);
	}

	/**
	 * テスト用のリソース（スタッフ）を作成する。
	 *
	 * @param string $title      タイトル（並び順の確認に使う）。
	 * @param int    $menu_order 表示順（管理画面の並び順）。
	 * @param string $status     投稿ステータス。
	 * @return int 投稿ID。
	 */
	private function create_resource( string $title, int $menu_order, string $status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
				'menu_order'  => $menu_order,
			)
		);
	}

	/**
	 * is_all_staff_assigned() のテスト。
	 *
	 * メタの有無・値と、Pro / Free の違いで判定が変わることを検証する。
	 */
	public function test_is_all_staff_assigned(): void {
		$menu_id = $this->create_menu();
		$is_pro  = Staff_Editor::is_enabled();

		$test_cases = array(
			array(
				'test_condition_name' => 'メタ未設定（既定）の場合 => false（「選ぶ」）',
				'meta'                => null,
				'menu_id'             => $menu_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'メタが true の場合 => Pro版は true、Free版はメニュー側の担当設定を使わないため false',
				'meta'                => true,
				'menu_id'             => $menu_id,
				'expected'            => $is_pro,
			),
			array(
				'test_condition_name' => 'メタが \'1\'（管理画面フォーム保存値）の場合 => Pro版は true、Free版は false',
				'meta'                => '1',
				'menu_id'             => $menu_id,
				'expected'            => $is_pro,
			),
			array(
				'test_condition_name' => 'メタが空文字（明示的に「選ぶ」）の場合 => false（境界値）',
				'meta'                => '',
				'menu_id'             => $menu_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'メニューIDが 0 の場合 => false（異常系）',
				'meta'                => true,
				'menu_id'             => 0,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( null === $case['meta'] ) {
				delete_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL );
			} else {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['meta'] );
			}

			$this->assertSame(
				$case['expected'],
				Service_Menu_Post_Type::is_all_staff_assigned( $case['menu_id'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * get_selected_staff_ids() のテスト。
	 *
	 * 保存されている個別選択（_vkbm_staff_ids）を、「すべて」フラグの状態に関わらず正規化して返すことを検証する。
	 */
	public function test_get_selected_staff_ids(): void {
		$menu_id = $this->create_menu();

		$test_cases = array(
			array(
				'test_condition_name' => 'メタ未設定の場合 => 空配列',
				'staff_ids'           => null,
				'staff_all'           => false,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '重複・0・文字列を含む配列の場合 => int 化し 0 以下と重複を除いて順序を保つ',
				'staff_ids'           => array( 12, '7', 12, 0, -1, 'abc' ),
				'staff_all'           => false,
				'expected'            => array( 12, 7 ),
			),
			array(
				'test_condition_name' => '「すべて」フラグが立っていても保存済みの個別選択をそのまま返す（フラグの状態は見ない）',
				'staff_ids'           => array( 5 ),
				'staff_all'           => true,
				'expected'            => array( 5 ),
			),
			array(
				'test_condition_name' => '配列以外（文字列）が保存されている場合 => 空配列（異常系）',
				'staff_ids'           => 'broken',
				'staff_all'           => false,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			if ( null === $case['staff_ids'] ) {
				delete_post_meta( $menu_id, '_vkbm_staff_ids' );
			} else {
				update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );
			}
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );

			$this->assertSame(
				$case['expected'],
				Service_Menu_Post_Type::get_selected_staff_ids( $menu_id ),
				$case['test_condition_name']
			);
		}

		$this->assertSame( array(), Service_Menu_Post_Type::get_selected_staff_ids( 0 ), 'メニューIDが 0 の場合 => 空配列（異常系）' );
	}

	/**
	 * get_assignable_staff_ids() のテスト。
	 *
	 * 「すべて」のときは公開中の全リソース（管理画面の並び順、下書きは含まない）、
	 * それ以外は個別選択を返すこと、無料版ではフラグを無視すること、
	 * フィルター vkbm_menu_assignable_staff_ids で候補を絞り込めることを検証する。
	 */
	public function test_get_assignable_staff_ids(): void {
		$menu_id = $this->create_menu();
		$is_pro  = Staff_Editor::is_enabled();

		// 既存の公開リソースが混ざらないよう、このテストで作るリソースだけを公開状態にする。
		$this->draft_all_published_resources();

		// 並び順（menu_order → タイトル）を確認できるよう、作成順と表示順を逆にしておく。
		$staff_b = $this->create_resource( 'スタッフB', 2 );
		$staff_a = $this->create_resource( 'スタッフA', 1 );
		$draft_c = $this->create_resource( 'スタッフC（下書き）', 3, 'draft' );

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択あり => 個別選択をそのまま返す',
				'staff_all'           => false,
				'staff_ids'           => array( $staff_b ),
				'expected'            => array( $staff_b ),
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が空（未設定） => 空配列（「未設定」の意味は変えない）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '「すべて」かつ個別選択あり => Pro版は公開中の全リソース（menu_order 順・下書きは含まない）、Free版はフラグを無視して個別選択',
				'staff_all'           => true,
				'staff_ids'           => array( $staff_b ),
				'expected'            => $is_pro ? array( $staff_a, $staff_b ) : array( $staff_b ),
			),
			array(
				'test_condition_name' => '「すべて」かつ個別選択が空 => Pro版は公開中の全リソース、Free版は空配列',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'expected'            => $is_pro ? array( $staff_a, $staff_b ) : array(),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			$actual = Service_Menu_Post_Type::get_assignable_staff_ids( $menu_id );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			$this->assertNotContains( $draft_c, $actual, $case['test_condition_name'] . '（下書きのリソースは含まれない）' );
		}

		// フィルターで候補を絞り込めること（将来の「指名不可」属性などの拡張点）。
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, true );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a, $staff_b ) );
		$received_args = array();
		$filter        = static function ( array $staff_ids, int $filtered_menu_id, bool $is_all ) use ( &$received_args, $staff_b ): array {
			$received_args = array( $filtered_menu_id, $is_all );
			// $staff_b を候補から外す。
			return array_values( array_diff( $staff_ids, array( $staff_b ) ) );
		};
		add_filter( 'vkbm_menu_assignable_staff_ids', $filter, 10, 3 );
		$filtered = Service_Menu_Post_Type::get_assignable_staff_ids( $menu_id );
		remove_filter( 'vkbm_menu_assignable_staff_ids', $filter, 10 );

		$this->assertSame( array( $staff_a ), $filtered, 'フィルター vkbm_menu_assignable_staff_ids で候補から外したリソースは返らない' );
		$this->assertSame( array( $menu_id, $is_pro ), $received_args, 'フィルターにはメニューIDと「すべて」かどうかが渡る（Free版では「すべて」は常に false）' );

		$this->assertSame( array(), Service_Menu_Post_Type::get_assignable_staff_ids( 0 ), 'メニューIDが 0 の場合 => 空配列（異常系）' );
	}

	/**
	 * get_published_resource_ids() のテスト。
	 *
	 * 公開中のリソースだけを返すこと、同一リクエスト内でリソースを追加・非公開にしても
	 * 古いキャッシュを返さないことを検証する。
	 */
	public function test_get_published_resource_ids(): void {
		$this->draft_all_published_resources();

		$staff_a = $this->create_resource( 'スタッフA', 1 );
		$this->create_resource( 'スタッフC（下書き）', 3, 'draft' );

		$this->assertSame( array( $staff_a ), Service_Menu_Post_Type::get_published_resource_ids(), '公開中のリソースだけを返す（下書きは含まない）' );

		// キャッシュが乗った状態で新しいリソースを公開しても、最新の一覧が返ること。
		$staff_b = $this->create_resource( 'スタッフB', 2 );
		$this->assertSame( array( $staff_a, $staff_b ), Service_Menu_Post_Type::get_published_resource_ids(), 'リソースを追加した直後も古いキャッシュを返さない' );

		// 非公開にしたリソースは外れること。
		wp_update_post(
			array(
				'ID'          => $staff_a,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( array( $staff_b ), Service_Menu_Post_Type::get_published_resource_ids(), '非公開にしたリソースは直後から含まれない' );
	}

	/**
	 * sanitize_staff_ids() のテスト。
	 *
	 * register_post_meta() の sanitize_callback として外部から呼べる（public）こと、
	 * 正規化規則が get_selected_staff_ids() と同じであることを検証する。
	 */
	public function test_sanitize_staff_ids(): void {
		$service_menu = new Service_Menu_Post_Type();

		$test_cases = array(
			array(
				'test_condition_name' => '数値文字列と重複を含む配列 => int 化し重複を除く',
				'conditions'          => array( '3', 3, '9' ),
				'expected'            => array( 3, 9 ),
			),
			array(
				'test_condition_name' => '0・負数・数値でない文字列 => 除外される',
				'conditions'          => array( 0, -4, 'x', 8 ),
				'expected'            => array( 8 ),
			),
			array(
				'test_condition_name' => '配列以外 => 空配列（異常系）',
				'conditions'          => 'not-array',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $service_menu->sanitize_staff_ids( $case['conditions'] ), $case['test_condition_name'] );
		}

		// register_meta() は is_callable() で sanitize_callback を判定するため、外部から callable でなければならない。
		$this->assertTrue( is_callable( array( $service_menu, 'sanitize_staff_ids' ) ), 'sanitize_staff_ids() は外部から callable（register_meta の sanitize_callback として有効）' );
	}

	/**
	 * get_assignable_staff_ids_rest_field() のテスト（REST 経由）。
	 *
	 * /wp/v2/vkbm_service_menu/{id} のレスポンスに、展開済みの vkbm_assignable_staff_ids と
	 * meta._vkbm_staff_all が含まれることを検証する。
	 */
	public function test_get_assignable_staff_ids_rest_field(): void {
		$is_pro = Staff_Editor::is_enabled();

		// REST サーバーを取得する（初回生成時に rest_api_init が走り、プラグインのフィールド登録も行われる）。
		$server = rest_get_server();

		$admin_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->draft_all_published_resources();
		$staff_a = $this->create_resource( 'スタッフA', 1 );
		$staff_b = $this->create_resource( 'スタッフB', 2 );
		$menu_id = $this->create_menu();

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択あり => vkbm_assignable_staff_ids は個別選択',
				'staff_all'           => false,
				'staff_ids'           => array( $staff_b ),
				'expected_ids'        => array( $staff_b ),
			),
			array(
				'test_condition_name' => '「すべて」 => Pro版は公開中の全リソースへ展開済み、Free版は個別選択のまま',
				'staff_all'           => true,
				'staff_ids'           => array( $staff_b ),
				'expected_ids'        => $is_pro ? array( $staff_a, $staff_b ) : array( $staff_b ),
			),
			array(
				'test_condition_name' => '「選ぶ」かつ個別選択が空 => 空配列（境界値）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected_ids'        => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			$request  = new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id );
			$response = $server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $case['test_condition_name'] );
			$this->assertArrayHasKey( 'vkbm_assignable_staff_ids', $data, $case['test_condition_name'] . '（計算フィールドが含まれる）' );
			$this->assertSame( $case['expected_ids'], $data['vkbm_assignable_staff_ids'], $case['test_condition_name'] );
			$this->assertArrayHasKey( Service_Menu_Post_Type::META_STAFF_ALL, $data['meta'], $case['test_condition_name'] . '（meta に _vkbm_staff_all が公開される）' );
			$this->assertSame( $case['staff_all'], (bool) $data['meta'][ Service_Menu_Post_Type::META_STAFF_ALL ], $case['test_condition_name'] . '（meta の値が保存値と一致する）' );
		}
	}

	/**
	 * register_meta() で登録した _vkbm_staff_all の auth_callback のテスト。
	 *
	 * メタの書き込み権限（edit_post_meta）は、編集権限があり かつ Pro 版のときだけ許可されることを、
	 * current_user_can( 'edit_post_meta', $menu_id, '_vkbm_staff_all' ) で検証する
	 * （REST 経由の書き込みもこの判定を通る）。
	 */
	public function test_register_meta_staff_all_auth_callback(): void {
		$menu_id = $this->create_menu();
		$is_pro  = Staff_Editor::is_enabled();

		$administrator_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id    = (int) $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '編集権限のある管理者 => Pro版は書き込み可、Free版はメニュー側の担当設定が無いため不可',
				'user_id'             => $administrator_id,
				'expected'            => $is_pro,
			),
			array(
				'test_condition_name' => '編集権限の無い購読者 => 書き込み不可（異常系）',
				'user_id'             => $subscriber_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログイン => 書き込み不可（境界値）',
				'user_id'             => 0,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );

			$this->assertSame(
				$case['expected'],
				current_user_can( 'edit_post_meta', $menu_id, Service_Menu_Post_Type::META_STAFF_ALL ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 既存の公開中リソースをすべて下書きへ落とし、テストで作るリソースだけが公開状態になるようにする。
	 */
	private function draft_all_published_resources(): void {
		$published = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $published as $resource_id ) {
			wp_update_post(
				array(
					'ID'          => (int) $resource_id,
					'post_status' => 'draft',
				)
			);
		}
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}
}
