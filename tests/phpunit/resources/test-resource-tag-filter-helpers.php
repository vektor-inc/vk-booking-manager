<?php
/**
 * Resource_Tag_Taxonomy のタグ絞り込みヘルパーのテスト（issue #431）。
 *
 * リソースタグでの絞り込み機能を支える3つの静的ヘルパーを検証する。
 * - get_resource_ids_for_tags(): 指定タグを「すべて」持つリソースID配列（AND条件）を返す
 * - resource_has_all_tags(): 1リソースが指定タグを「すべて」持つかを判定する（予約確定時の最終チェックに使用）
 * - get_labels_for_tag_ids(): ターム ID 配列からタグ名配列を返す（希望タグ表示用）
 *
 * タグの照合はターム ID で行う（タグ名は名称変更で壊れるため使わない。issue #431 実装メモ）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Resources;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use WP_UnitTestCase;

/**
 * Resource_Tag_Taxonomy のタグ絞り込みヘルパーを検証するテストクラス。
 *
 * @group resources
 * @group resource-tag
 */
class Resource_Tag_Filter_Helpers_Test extends WP_UnitTestCase {

	/**
	 * テスト用のリソース投稿を作成するヘルパー。
	 *
	 * @param string     $title   投稿タイトル。
	 * @param array<int> $tag_ids 割り当てるリソースタグのターム ID 配列。
	 * @return int 作成したリソース投稿ID。
	 */
	private function create_resource( string $title, array $tag_ids = array() ): int {
		$resource_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( ! empty( $tag_ids ) ) {
			wp_set_object_terms( $resource_id, $tag_ids, Resource_Tag_Taxonomy::TAXONOMY );
		}

		return $resource_id;
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
	 * get_resource_ids_for_tags() が AND 条件で正しく絞り込むことを検証する。
	 */
	public function test_get_resource_ids_for_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );

		// 両方のタグを持つリソース。
		$resource_both = $this->create_resource( 'リソースA', array( $tag_female, $tag_veteran ) );
		// 「女性」のみ持つリソース。
		$resource_female_only = $this->create_resource( 'リソースB', array( $tag_female ) );
		// 「ベテラン」のみ持つリソース。
		$resource_veteran_only = $this->create_resource( 'リソースC', array( $tag_veteran ) );
		// タグ無しリソース。
		$this->create_resource( 'リソースD' );

		$test_cases = array(
			array(
				'test_condition_name' => '単一タグ「女性」指定 => 「女性」を持つ2件（正常系）',
				'tag_ids'             => array( $tag_female ),
				'expected'            => array( $resource_both, $resource_female_only ),
			),
			array(
				'test_condition_name' => '複数タグ「女性」「ベテラン」指定（AND） => 両方持つリソースAのみ（正常系）',
				'tag_ids'             => array( $tag_female, $tag_veteran ),
				'expected'            => array( $resource_both ),
			),
			array(
				'test_condition_name' => '存在しないタグIDを含めて指定 => 該当リソース0件（異常系・境界値）',
				'tag_ids'             => array( $tag_female, 999999 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空配列指定 => 絞り込み対象なしのため空配列（境界値）',
				'tag_ids'             => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Resource_Tag_Taxonomy::get_resource_ids_for_tags( $case['tag_ids'] );
			sort( $actual );
			$expected = $case['expected'];
			sort( $expected );
			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}

		// 未使用の変数警告を避けるため、意図的に参照だけ行う（何件作成したかの確認）。
		$this->assertGreaterThan( 0, $resource_veteran_only );
	}

	/**
	 * resource_has_all_tags() が単一リソースに対する AND 判定を正しく行うことを検証する。
	 */
	public function test_resource_has_all_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );
		$resource_id = $this->create_resource( 'リソースE', array( $tag_female ) );

		$test_cases = array(
			array(
				'test_condition_name' => '持っているタグ1件を指定 => true（正常系）',
				'tag_ids'             => array( $tag_female ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '持っていないタグを含めて指定（AND） => false（正常系）',
				'tag_ids'             => array( $tag_female, $tag_veteran ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'タグ指定なし（空配列） => 制約なしのため true（境界値）',
				'tag_ids'             => array(),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Resource_Tag_Taxonomy::resource_has_all_tags( $resource_id, $case['tag_ids'] ),
				$case['test_condition_name']
			);
		}

		// リソースID自体が不正（0以下）な場合は、タグ指定があれば必ず false（異常系）。
		$this->assertFalse(
			Resource_Tag_Taxonomy::resource_has_all_tags( 0, array( $tag_female ) ),
			'リソースIDが0以下の場合はタグ指定があれば false を返すべき'
		);
	}

	/**
	 * get_labels_for_tag_ids() がターム ID 配列からタグ名配列を返すことを検証する。
	 */
	public function test_get_labels_for_tag_ids(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$tag_name   = '女性_' . wp_generate_password( 6, false );
		$tag_female = $this->create_tag( $tag_name );

		$test_cases = array(
			array(
				'test_condition_name' => '存在するタームIDを指定 => タグ名の配列（正常系）',
				'tag_ids'             => array( $tag_female ),
				'expected'            => array( $tag_name ),
			),
			array(
				'test_condition_name' => '存在しないタームIDを指定 => 空配列（異常系）',
				'tag_ids'             => array( 999999 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空配列を指定 => 空配列（境界値）',
				'tag_ids'             => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Resource_Tag_Taxonomy::get_labels_for_tag_ids( $case['tag_ids'] ),
				$case['test_condition_name']
			);
		}
	}
}
