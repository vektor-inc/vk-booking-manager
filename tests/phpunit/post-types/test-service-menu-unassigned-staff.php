<?php
/**
 * 担当できるリソースがいないサービスメニューの扱い（#1144）のテスト。
 *
 * - 「担当できるリソース」の入力が保存できる組み合わせか（is_valid_staff_selection()）
 * - 担当できる公開中のリソースがいないメニューの判定（has_no_assignable_staff()）と一覧（get_menu_ids_without_assignable_staff()）
 * - 予約フォームが使う投稿一覧 API から外すこと（filter_rest_collection_query()）
 * - クイック編集で「選ぶ」かつチェック0件の保存を止めること（validate_quick_edit_staff_selection() / save_quick_edit()）
 * を検証する。
 *
 * 無料版（Staff_Editor::is_enabled() === false）は担当が常に基本スタッフ1名のため、
 * 判定は常に「該当なし」、保存も止めない。期待値は Pro / Free で分岐させる。
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
use WPDieException;
use function do_action;
use function rest_get_server;
use function get_post;
use function get_post_meta;
use function update_post_meta;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 担当できるリソースがいないメニューの判定・除外・保存の確認を検証するテストクラス。
 *
 * @group post-types
 * @group staff
 */
class Service_Menu_Unassigned_Staff_Test extends WP_UnitTestCase {

	/**
	 * テスト前の $_POST。
	 *
	 * @var array<string, mixed>
	 */
	private $original_post = array();

	/**
	 * 投稿タイプ・メタを登録し、キャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に戻すための退避。
		// 投稿タイプ・メタ（register_post_meta）を登録する。
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフック。
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * $_POST・キャッシュ・ログイン状態を戻す。
	 */
	protected function tearDown(): void {
		$_POST = $this->original_post;
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
		wp_set_current_user( 0 );
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
	 * テスト用のサービスメニューを、担当の設定付きで作成する。
	 *
	 * @param bool       $staff_all 「すべてのリソースが担当できる」なら true。
	 * @param array<int> $staff_ids 個別に選んだリソースID。
	 * @param string     $status    投稿ステータス。
	 * @return int 投稿ID。
	 */
	private function create_menu( bool $staff_all, array $staff_ids, string $status = 'publish' ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => $status,
			)
		);
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $staff_all ? '1' : '' );
		update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );

		return $menu_id;
	}

	/**
	 * Service_Menu_Post_Type::is_valid_staff_selection() のテスト。
	 */
	public function test_is_valid_staff_selection(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「すべて」かつチェック0件 => 保存できる（正常系）',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '「選ぶ」かつ1件以上チェック => 保存できる（正常系）',
				'staff_all'           => false,
				'staff_ids'           => array( 12, 34 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '「選ぶ」かつチェック0件 => 保存できない（異常系）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「選ぶ」かつ0以下の ID だけ => 正規化すると0件なので保存できない（境界値）',
				'staff_all'           => false,
				'staff_ids'           => array( 0, -1 ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Service_Menu_Post_Type::is_valid_staff_selection( $case['staff_all'], $case['staff_ids'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Service_Menu_Post_Type::has_no_assignable_staff() のテスト。
	 */
	public function test_has_no_assignable_staff(): void {
		$is_pro    = Staff_Editor::is_enabled();
		$published = $this->create_resource();
		$draft     = $this->create_resource( 'draft' );
		$trashed   = $this->create_resource( 'trash' );

		$test_cases = array(
			array(
				'test_condition_name' => '「すべて」で公開中のリソースがいる => 該当しない（正常系）',
				'staff_all'           => true,
				'staff_ids'           => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「選ぶ」で公開中のリソースを選んでいる => 該当しない（正常系）',
				'staff_all'           => false,
				'staff_ids'           => array( $published, $draft ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '「選ぶ」でチェック0件 => Pro は該当する（異常系）',
				'staff_all'           => false,
				'staff_ids'           => array(),
				'expected'            => $is_pro,
			),
			array(
				'test_condition_name' => '「選ぶ」で選んだリソースが全員下書き・ゴミ箱 => Pro は該当する（異常系）',
				'staff_all'           => false,
				'staff_ids'           => array( $draft, $trashed ),
				'expected'            => $is_pro,
			),
			array(
				'test_condition_name' => '「選ぶ」で選んだリソースが存在しない ID だけ => Pro は該当する（境界値）',
				'staff_all'           => false,
				'staff_ids'           => array( 999999999 ),
				'expected'            => $is_pro,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( $case['staff_all'], $case['staff_ids'] );
			$this->assertSame(
				$case['expected'],
				Service_Menu_Post_Type::has_no_assignable_staff( $menu_id ),
				$case['test_condition_name']
			);
		}

		$this->assertFalse( Service_Menu_Post_Type::has_no_assignable_staff( 0 ), 'メニューID が0 => 該当しない（境界値）' );
	}

	/**
	 * 「すべて」で公開中のリソースが1件もいないメニューが該当することのテスト。
	 */
	public function test_has_no_assignable_staff_when_no_published_resource(): void {
		// 他の公開中リソースの有無に左右されないよう、担当できるリソースを空にするフィルターで再現する。
		$menu_id = $this->create_menu( true, array() );
		$filter  = static function (): array {
			return array();
		};
		add_filter( 'vkbm_menu_assignable_staff_ids', $filter );

		$this->assertSame( Staff_Editor::is_enabled(), Service_Menu_Post_Type::has_no_assignable_staff( $menu_id ), '「すべて」で担当できるリソースが0件 => Pro は該当する' );

		remove_filter( 'vkbm_menu_assignable_staff_ids', $filter );
	}

	/**
	 * Service_Menu_Post_Type::get_menu_ids_without_assignable_staff() のテスト。
	 */
	public function test_get_menu_ids_without_assignable_staff(): void {
		$is_pro    = Staff_Editor::is_enabled();
		$published = $this->create_resource();
		$draft     = $this->create_resource( 'draft' );

		$menu_all          = $this->create_menu( true, array() );
		$menu_selected     = $this->create_menu( false, array( $published ) );
		$menu_empty        = $this->create_menu( false, array() );
		$menu_draft_only   = $this->create_menu( false, array( $draft ) );
		$menu_empty_draft  = $this->create_menu( false, array(), 'draft' );
		$menu_empty_trash  = $this->create_menu( false, array(), 'trash' );
		$own_menu_ids      = array( $menu_all, $menu_selected, $menu_empty, $menu_draft_only, $menu_empty_draft, $menu_empty_trash );
		$restrict_own_menu = static function ( array $menu_ids ) use ( $own_menu_ids ): array {
			return array_values( array_intersect( $menu_ids, $own_menu_ids ) );
		};

		$test_cases = array(
			array(
				'test_condition_name' => '既定（ゴミ箱以外のすべて） => 担当がいない公開・下書きのメニューだけ（正常系）',
				'post_status'         => 'any',
				'expected'            => $is_pro ? array( $menu_empty, $menu_draft_only, $menu_empty_draft ) : array(),
			),
			array(
				'test_condition_name' => '公開中だけ => 下書きのメニューは含めない（正常系）',
				'post_status'         => array( 'publish' ),
				'expected'            => $is_pro ? array( $menu_empty, $menu_draft_only ) : array(),
			),
			array(
				'test_condition_name' => '該当するメニューが無い状態（非公開だけ） => 空配列（境界値）',
				'post_status'         => array( 'private' ),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $restrict_own_menu( Service_Menu_Post_Type::get_menu_ids_without_assignable_staff( $case['post_status'] ) );
			sort( $actual );
			$expected = $case['expected'];
			sort( $expected );
			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * Service_Menu_Post_Type::filter_rest_collection_query() のテスト（担当がいないメニューを外す）。
	 */
	public function test_filter_rest_collection_query(): void {
		$is_pro     = Staff_Editor::is_enabled();
		$published  = $this->create_resource();
		$menu_ok    = $this->create_menu( false, array( $published ) );
		$menu_empty = $this->create_menu( false, array() );
		$post_type  = new Service_Menu_Post_Type();

		$test_cases = array(
			array(
				'test_condition_name' => '一般の閲覧（context=view） => Pro は担当がいないメニューを post__not_in に入れる（正常系）',
				'context'             => 'view',
				'user_role'           => '',
				'args'                => array(),
				'expected_excluded'   => $is_pro,
			),
			array(
				'test_condition_name' => '既存の post__not_in がある => 既存の ID を残したまま足す（正常系）',
				'context'             => 'view',
				'user_role'           => '',
				'args'                => array( 'post__not_in' => array( 123456 ) ),
				'expected_excluded'   => $is_pro,
			),
			array(
				'test_condition_name' => '編集画面用（context=edit）でメニューを編集できる管理者 => 外さない（境界値）',
				'context'             => 'edit',
				'user_role'           => 'administrator',
				'args'                => array(),
				'expected_excluded'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( '' === $case['user_role'] ? 0 : $this->factory()->user->create( array( 'role' => $case['user_role'] ) ) );
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE );
			$request->set_param( 'context', $case['context'] );

			$args       = $post_type->filter_rest_collection_query( $case['args'], $request );
			$not_in     = isset( $args['post__not_in'] ) ? $args['post__not_in'] : array();
			$message    = $case['test_condition_name'];
			$is_removed = in_array( $menu_empty, $not_in, true );

			$this->assertSame( $case['expected_excluded'], $is_removed, $message );
			$this->assertNotContains( $menu_ok, $not_in, $message . '（担当がいるメニューは外さない）' );
			foreach ( $case['args']['post__not_in'] ?? array() as $kept_id ) {
				$this->assertContains( $kept_id, $not_in, $message . '（既存の除外を残す）' );
			}
		}
	}

	/**
	 * Service_Menu_Post_Type::exclude_unassigned_menus_from_query_args() のテスト。
	 */
	public function test_exclude_unassigned_menus_from_query_args(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版は担当がいないメニューの判定自体が無いため、Pro版限定のテスト。' );
		}

		$published  = $this->create_resource();
		$menu_ok    = $this->create_menu( false, array( $published ) );
		$menu_empty = $this->create_menu( false, array() );

		$test_cases = array(
			array(
				'test_condition_name' => 'post__in の指定なし => post__not_in に足し、post__in は付けない（正常系）',
				'args'                => array( 'post__not_in' => array( 123456 ) ),
				'expected_in'         => null,
				'expected_not_in'     => array( 123456, $menu_empty ),
			),
			array(
				'test_condition_name' => 'post__in に担当ありと担当なしが混在 => 担当なしだけ差し引く（正常系）',
				'args'                => array( 'post__in' => array( $menu_ok, $menu_empty ) ),
				'expected_in'         => array( $menu_ok ),
				'expected_not_in'     => array( $menu_empty ),
			),
			array(
				'test_condition_name' => 'post__in が担当なしのメニューだけ => 何も返らないよう array( 0 ) にする（異常系）',
				'args'                => array( 'post__in' => array( (string) $menu_empty ) ),
				'expected_in'         => array( 0 ),
				'expected_not_in'     => array( $menu_empty ),
			),
		);

		foreach ( $test_cases as $case ) {
			$args = Service_Menu_Post_Type::exclude_unassigned_menus_from_query_args( $case['args'] );

			$this->assertSame( $case['expected_in'], $args['post__in'] ?? null, $case['test_condition_name'] . '（post__in）' );
			// 他のテストデータの担当なしメニューも入りうるため、このテストのメニューと既存の除外 ID だけで比べる。
			$own_not_in = array_values( array_intersect( $args['post__not_in'], array( 123456, $menu_ok, $menu_empty ) ) );
			$this->assertSame( $case['expected_not_in'], $own_not_in, $case['test_condition_name'] . '（post__not_in）' );
		}
	}

	/**
	 * 予約フォームが使う投稿一覧 API（/wp/v2/vkbm_service_menu）の応答から、担当がいないメニューが外れることのテスト。
	 *
	 * include（WP_Query の post__in）で担当がいないメニューを指定しても返らないことを含めて確かめる。
	 */
	public function test_filter_rest_collection_query_response(): void {
		$is_pro     = Staff_Editor::is_enabled();
		$published  = $this->create_resource();
		$menu_ok    = $this->create_menu( false, array( $published ) );
		$menu_empty = $this->create_menu( false, array() );
		wp_set_current_user( 0 );

		$test_cases = array(
			array(
				'test_condition_name' => 'include なしの通常取得 => 担当ありだけ返る（Free 版は両方）（正常系）',
				'include'             => null,
				'expected'            => $is_pro ? array( $menu_ok ) : array( $menu_ok, $menu_empty ),
			),
			array(
				'test_condition_name' => 'include に担当ありと担当なしを混在 => 担当ありだけ返る（Free 版は両方）（正常系）',
				'include'             => array( $menu_ok, $menu_empty ),
				'expected'            => $is_pro ? array( $menu_ok ) : array( $menu_ok, $menu_empty ),
			),
			array(
				'test_condition_name' => 'include に担当なしのメニューだけ => 何も返らない（Free 版は返る）（異常系）',
				'include'             => array( $menu_empty ),
				'expected'            => $is_pro ? array() : array( $menu_empty ),
			),
		);

		foreach ( $test_cases as $case ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE );
			$request->set_param( 'per_page', 100 );
			if ( null !== $case['include'] ) {
				$request->set_param( 'include', $case['include'] );
			}

			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 200, $response->get_status(), $case['test_condition_name'] . '（ステータス）' );

			// このテストで作ったメニューだけで比べる。
			$ids     = array_map(
				static function ( array $item ): int {
					return (int) $item['id'];
				},
				$response->get_data()
			);
			$own_ids = array_values( array_intersect( $ids, array( $menu_ok, $menu_empty ) ) );
			sort( $own_ids );
			$expected = $case['expected'];
			sort( $expected );

			$this->assertSame( $expected, $own_ids, $case['test_condition_name'] );
		}
	}

	/**
	 * クイック編集の送信内容（$_POST）を組み立てる。
	 *
	 * @param string|null $staff_all 「すべて」ラジオの値（null は送らない）。
	 * @param array<int>  $staff_ids チェックしたリソースID。
	 * @param bool        $valid_nonce 正しいノンスを送るなら true。
	 */
	private function set_quick_edit_post( ?string $staff_all, array $staff_ids, bool $valid_nonce = true ): void {
		$fields = array(
			'base_price'       => '1000',
			'duration_minutes' => '60',
			'staff_ids'        => array_map( 'strval', $staff_ids ),
		);
		if ( null !== $staff_all ) {
			$fields['staff_all'] = $staff_all;
		}

		$_POST = array(
			'post_type'                      => Service_Menu_Post_Type::POST_TYPE,
			'_vkbm_service_menu_quick_nonce' => $valid_nonce ? wp_create_nonce( 'vkbm_service_menu_quick_edit' ) : 'invalid',
			'vkbm_service_menu_quick'        => $fields,
		);
	}

	/**
	 * Service_Menu_Post_Type::validate_quick_edit_staff_selection() のテスト。
	 */
	public function test_validate_quick_edit_staff_selection(): void {
		$is_pro    = Staff_Editor::is_enabled();
		$published = $this->create_resource();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_type = new Service_Menu_Post_Type();

		$test_cases = array(
			array(
				'test_condition_name' => '「すべて」を選んだ => 止めない（正常系）',
				'staff_all'           => '1',
				'staff_ids'           => array(),
				'valid_nonce'         => true,
				'expected_die'        => false,
			),
			array(
				'test_condition_name' => '「選ぶ」で1件チェック => 止めない（正常系）',
				'staff_all'           => '0',
				'staff_ids'           => array( $published ),
				'valid_nonce'         => true,
				'expected_die'        => false,
			),
			array(
				'test_condition_name' => '「選ぶ」でチェック0件 => Pro は案内文で止める（異常系）',
				'staff_all'           => '0',
				'staff_ids'           => array(),
				'valid_nonce'         => true,
				'expected_die'        => $is_pro,
			),
			array(
				'test_condition_name' => 'ラジオを含まない古い行 DOM からの送信 => 止めない（境界値）',
				'staff_all'           => null,
				'staff_ids'           => array(),
				'valid_nonce'         => true,
				'expected_die'        => false,
			),
			array(
				'test_condition_name' => 'ノンスが合わない送信 => このメソッドでは止めない（境界値）',
				'staff_all'           => '0',
				'staff_ids'           => array(),
				'valid_nonce'         => false,
				'expected_die'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_quick_edit_post( $case['staff_all'], $case['staff_ids'], $case['valid_nonce'] );

			$died    = false;
			$message = '';
			try {
				$post_type->validate_quick_edit_staff_selection();
			} catch ( WPDieException $e ) {
				$died    = true;
				$message = $e->getMessage();
			}

			$this->assertSame( $case['expected_die'], $died, $case['test_condition_name'] );
			if ( $case['expected_die'] ) {
				$this->assertSame( esc_html( Service_Menu_Post_Type::get_staff_unselected_notice() ), $message, $case['test_condition_name'] . '（案内文）' );
			}
		}
	}

	/**
	 * Service_Menu_Post_Type::save_quick_edit() で、「選ぶ」かつチェック0件の担当の設定を保存しないことのテスト。
	 */
	public function test_save_quick_edit(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版はクイック編集に担当の欄が無いため、Pro版限定のテスト。' );
		}

		$staff_a = $this->create_resource();
		$staff_b = $this->create_resource();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_type = new Service_Menu_Post_Type();

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」でスタッフBに変更 => 保存される（正常系）',
				'staff_all'           => '0',
				'staff_ids'           => array( $staff_b ),
				'expected_all'        => '',
				'expected_ids'        => array( $staff_b ),
			),
			array(
				'test_condition_name' => '「すべて」に変更 => 保存される（正常系）',
				'staff_all'           => '1',
				'staff_ids'           => array( $staff_b ),
				'expected_all'        => '1',
				'expected_ids'        => array( $staff_b ),
			),
			array(
				'test_condition_name' => '「選ぶ」でチェック0件 => 担当の設定は保存前の値のまま（異常系）',
				'staff_all'           => '0',
				'staff_ids'           => array(),
				'expected_all'        => '',
				'expected_ids'        => array( $staff_a ),
			),
			array(
				'test_condition_name' => 'ラジオを含まない送信（「選ぶ」・スタッフA） => 担当の設定は保存前の値のまま（異常系）',
				'initial_all'         => false,
				'staff_all'           => null,
				'staff_ids'           => array(),
				'expected_all'        => '',
				'expected_ids'        => array( $staff_a ),
			),
			array(
				'test_condition_name' => 'ラジオを含まない送信（「すべて」・スタッフA） => 担当の設定は保存前の値のまま（異常系）',
				'initial_all'         => true,
				'staff_all'           => null,
				'staff_ids'           => array( $staff_b ),
				'expected_all'        => '1',
				'expected_ids'        => array( $staff_a ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存前の担当の設定は「選ぶ」・スタッフA（initial_all で「すべて」にも切り替える）。
			$menu_id = $this->create_menu( ! empty( $case['initial_all'] ), array( $staff_a ) );
			$this->set_quick_edit_post( $case['staff_all'], $case['staff_ids'] );

			$post_type->save_quick_edit( $menu_id, get_post( $menu_id ), true );

			$this->assertSame( $case['expected_all'], (string) get_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, true ), $case['test_condition_name'] . '（すべて）' );
			$this->assertSame( $case['expected_ids'], Service_Menu_Post_Type::get_selected_staff_ids( $menu_id ), $case['test_condition_name'] . '（個別選択）' );
		}
	}
}
