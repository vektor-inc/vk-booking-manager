<?php
/**
 * Plugin::maybe_create_default_staff() のテスト（issue #465）。
 *
 * この処理は 'init' フックで毎リクエスト走り、無料版の公開状態の resource を常に1件に
 * 保つ（それ以外を下書きに落とす）。以前は「どの投稿を残すか」の判定が post_title 一致
 * （`__( 'Default Staff', ... )`）だけだったため、公開中の resource が1件だけでも
 * その名前を「Default Staff」以外へ変更していると、毎リクエストその投稿が下書きに落とされ、
 * 新規の「Default Staff」投稿が作り直されてしまっていた（植草さんレビュー指摘）。
 *
 * 判定ロジックは Resource_Post_Type::resolve_default_staff_from_published_ids() に揃えた
 * （「公開中の resource がちょうど1件ならそれを使う」を優先し、0件・複数件のときだけ
 * post_title 一致にフォールバックする）。ここでは、その揃え直しによって
 * - 公開スタッフが1件だけでリネームされている場合に維持されるようになったこと（新しい挙動）
 * - 公開スタッフが複数ある場合の挙動が変わっていないこと（既存挙動の回帰防止）
 * を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function get_post;
use function get_posts;
use function vkbm_plugin;
use function wp_delete_post;

/**
 * Plugin::maybe_create_default_staff() の基本スタッフ維持挙動を検証するテストクラス。
 *
 * @group post-types
 * @group default-staff-id
 */
class Plugin_Maybe_Create_Default_Staff_Test extends WP_UnitTestCase {

	/**
	 * 他テストが残した resource 投稿がテスト結果を左右しないよう、実行前に全て削除する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->delete_all_resources();
	}

	/**
	 * テストで作成した resource 投稿を後片付けする。
	 */
	protected function tearDown(): void {
		$this->delete_all_resources();
		parent::tearDown();
	}

	/**
	 * 公開スタッフが1件だけで、名前が「Default Staff」以外にリネームされている場合、
	 * その投稿が下書きにされず、新規の「Default Staff」投稿も作られないことを検証する。
	 */
	public function test_keeps_renamed_single_published_staff(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465）。' );
		}

		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'とんかつ太郎',
			)
		);

		vkbm_plugin()->maybe_create_default_staff();

		$post = get_post( $staff_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'publish', $post->post_status, 'リネームされた唯一の公開スタッフは下書きに落とされてはならない' );
		$this->assertSame( 'とんかつ太郎', $post->post_title, '管理者がつけたスタッフ名を無断で書き換えてはならない' );

		$published_ids = $this->get_published_resource_ids();
		$this->assertSame( array( $staff_id ), $published_ids, '新規の「Default Staff」投稿を作り直してはならない' );
	}

	/**
	 * 公開スタッフが複数ある場合（Pro版から無料版への切り替え直後を想定）、挙動が
	 * 従来から変わっていないことを検証する。件数判定が1件にならないため post_title 一致へ
	 * 落ち、一致が無ければ新規の「Default Staff」投稿を作成し、既存の複数件は下書きにする。
	 */
	public function test_multiple_published_staff_behavior_is_unchanged(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465）。' );
		}

		$old_staff_ids = array(
			(int) $this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Pro版スタッフA',
				)
			),
			(int) $this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Pro版スタッフB',
				)
			),
		);

		vkbm_plugin()->maybe_create_default_staff();

		// 既存の複数件は、どちらも「Default Staff」という名前ではないため、
		// 新規の「Default Staff」投稿が作られ、既存の2件は下書きに落とされる（従来どおり）。
		foreach ( $old_staff_ids as $old_staff_id ) {
			$post = get_post( $old_staff_id );
			$this->assertNotNull( $post );
			$this->assertSame( 'draft', $post->post_status, 'Pro版から引き継いだ複数のスタッフは、従来どおり下書きに落とされるべき' );
		}

		$published_ids = $this->get_published_resource_ids();
		$this->assertCount( 1, $published_ids, '公開状態は新規作成された基本スタッフ1件だけになるべき' );

		$new_default_staff = get_post( $published_ids[0] );
		$this->assertNotNull( $new_default_staff );
		$this->assertSame( 'Default Staff', $new_default_staff->post_title );
		$this->assertNotContains( $published_ids[0], $old_staff_ids, '新規に作成された投稿であるべき（既存の投稿の使い回しではない）' );
	}

	/**
	 * 公開中（post_status = publish）の resource 投稿ID一覧を取得する。
	 *
	 * @return array<int>
	 */
	private function get_published_resource_ids(): array {
		return get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * 既存の resource 投稿を全て完全削除する（テストの前提条件を確定させるため）。
	 */
	private function delete_all_resources(): void {
		$ids = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
