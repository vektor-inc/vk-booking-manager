<?php
/**
 * Resource_Post_Type::get_default_staff_id() / resolve_default_staff_from_published_ids() のテスト（issue #465）。
 *
 * 無料版の基本スタッフ解決ロジックを、この2メソッドへ集約した（詳細は
 * Resource_Post_Type クラスの PHPDoc を参照）。ここでは判定アルゴリズム本体
 * （resolve_default_staff_from_published_ids()）を配列だけで検証し、
 * get_default_staff_id() は実際に post_status = publish で絞り込むことを
 * 別途確認する（安藤さんレビュー指摘: 新規 public static メソッドのテストが無かった）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Resource_Post_Type;
use WP_UnitTestCase;
use function get_posts;
use function wp_delete_post;

/**
 * Resource_Post_Type の基本スタッフID解決を検証するテストクラス。
 *
 * @group post-types
 * @group default-staff-id
 */
class Resource_Post_Type_Default_Staff_Id_Test extends WP_UnitTestCase {

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
	 * resolve_default_staff_from_published_ids() の判定アルゴリズムを検証する。
	 *
	 * この関数は「公開中のID一覧」を受け取るだけの純粋な判定ロジックのため、
	 * post_status を問わず（テスト用の実投稿ID・実在しないダミーID）で条件を組み立てられる。
	 */
	public function test_resolve_default_staff_from_published_ids(): void {
		$staff_with_default_title = $this->create_staff( 'Default Staff' );
		$staff_with_other_title   = $this->create_staff( 'とんかつ太郎' );

		$test_cases = array(
			array(
				'test_condition_name' => '公開0件 => 0を返す（異常系・境界値）',
				'published_ids'       => array(),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '公開1件（タイトルは問わない）=> その1件のIDを返す（正常系）',
				'published_ids'       => array( $staff_with_other_title ),
				'expected'            => $staff_with_other_title,
			),
			array(
				'test_condition_name' => '公開複数件 + タイトル一致あり => 一致した投稿のIDを返す（正常系）',
				'published_ids'       => array( 999999, $staff_with_default_title, $staff_with_other_title ),
				'expected'            => $staff_with_default_title,
			),
			array(
				'test_condition_name' => '公開複数件 + タイトル一致なし => 0を返す（異常系）',
				'published_ids'       => array( 999999, 999998, $staff_with_other_title ),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Resource_Post_Type::resolve_default_staff_from_published_ids( $case['published_ids'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_default_staff_id() が post_status = publish のみを対象にすることを検証する
	 * （下書きに落とされたスタッフを誤って基本スタッフとみなさないことの回帰防止）。
	 */
	public function test_get_default_staff_id_only_counts_published_resources(): void {
		$published_staff = $this->create_staff( 'とんかつ太郎', 'publish' );
		$this->create_staff( 'Default Staff', 'draft' );

		$this->assertSame(
			$published_staff,
			Resource_Post_Type::get_default_staff_id(),
			'下書き投稿は候補から除外し、公開中の1件のみを基本スタッフとみなすべき'
		);
	}

	/**
	 * スタッフ（リソース）投稿を作成する。
	 *
	 * @param string $title       投稿タイトル。
	 * @param string $post_status 投稿ステータス。
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff( string $title, string $post_status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => $post_status,
				'post_title'  => $title,
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
