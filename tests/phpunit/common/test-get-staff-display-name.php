<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use WP_UnitTestCase;
use function vkbm_get_resource_display_name;
use function wp_insert_term;
use function wp_set_object_terms;

/**
 * vkbm_get_resource_display_name() のテスト
 * Tests for vkbm_get_resource_display_name().
 *
 * @group common
 */
class Get_Staff_Display_Name_Test extends WP_UnitTestCase {

	/**
	 * テスト実行前にタクソノミーを登録し、設定で表示を有効化する。
	 * Register the taxonomy and enable tag display before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// リソースタグ機能は Pro 版のみ。Free 版ではスキップする。
		// Resource tag feature is Pro-only. Skip in the free edition.
		if ( ! method_exists( Resource_Tag_Taxonomy::class, 'register_taxonomy' ) ) {
			$this->markTestSkipped( 'Resource tag feature is not available in the free edition.' );
		}

		// テスト用にタクソノミーを登録
		// Register the taxonomy for testing.
		if ( ! taxonomy_exists( Resource_Tag_Taxonomy::TAXONOMY ) ) {
			$taxonomy_obj = new Resource_Tag_Taxonomy();
			$taxonomy_obj->register_taxonomy();
		}

		// プロバイダー設定でリソースタグ表示を有効にする
		// Enable resource tag display in provider settings.
		$repo     = new Settings_Repository();
		$settings = $repo->get_settings();
		$settings['resource_tag_display_enabled'] = true;
		$repo->update_settings( $settings );
	}

	/**
	 * テスト後にプロバイダー設定をクリーンアップする。
	 * Clean up provider settings after each test.
	 */
	public function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * タグ表示が有効な場合の表示名テスト（単一タグ・複数タグ）
	 * Test display name with tag display enabled (single tag, multiple tags).
	 */
	public function test_vkbm_get_resource_display_name_with_tags(): void {
		// テスト用スタッフ投稿を作成
		// Create a test staff post.
		$staff_id = $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_title'  => '田中',
				'post_status' => 'publish',
			)
		);

		// テスト用のリソースタグタームを作成
		// Create test resource tag terms.
		$male_term    = wp_insert_term( '男性', Resource_Tag_Taxonomy::TAXONOMY, array( 'slug' => 'male' ) );
		$female_term  = wp_insert_term( '女性', Resource_Tag_Taxonomy::TAXONOMY, array( 'slug' => 'female' ) );
		$veteran_term = wp_insert_term( 'ベテラン', Resource_Tag_Taxonomy::TAXONOMY, array( 'slug' => 'veteran' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'タグ未設定の場合 => スタッフ名のみ返す / No tags => name only',
				'tag_term_ids'       => array(),
				'expected'           => '田中',
			),
			array(
				'test_condition_name' => 'タグ1つの場合 => 「田中 ( 男性 )」を返す / Single tag',
				'tag_term_ids'       => array( $male_term['term_id'] ),
				'expected'           => '田中 ( 男性 )',
			),
			array(
				'test_condition_name' => '複数タグの場合 => 「田中 ( 女性, ベテラン )」を返す / Multiple tags',
				'tag_term_ids'       => array( $female_term['term_id'], $veteran_term['term_id'] ),
				'expected'           => '田中 ( 女性, ベテラン )',
			),
			array(
				'test_condition_name' => '存在しない投稿IDの場合 => 空文字を返す / Invalid post ID => empty string',
				'tag_term_ids'       => array(),
				'post_id'            => 99999,
				'expected'           => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$target_id = isset( $case['post_id'] ) ? $case['post_id'] : $staff_id;

			// リソースタグタームを設定
			// Set resource tag terms.
			$term_ids = array_map( 'intval', $case['tag_term_ids'] );
			wp_set_object_terms( $target_id, $term_ids, Resource_Tag_Taxonomy::TAXONOMY );

			// キャッシュをクリア（タームキャッシュの更新を反映するため）
			// Clear caches to reflect term changes.
			clean_post_cache( $target_id );
			clean_object_term_cache( $target_id, Resource_Tag_Taxonomy::TAXONOMY );

			$actual = vkbm_get_resource_display_name( $target_id );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * タグ表示が無効な場合、タグがあってもスタッフ名のみ返す。
	 * When tag display is disabled, return name only even if tags exist.
	 */
	public function test_vkbm_get_resource_display_name_disabled(): void {
		// 表示を無効にする / Disable tag display.
		$repo     = new Settings_Repository();
		$settings = $repo->get_settings();
		$settings['resource_tag_display_enabled'] = false;
		$repo->update_settings( $settings );

		$staff_id = $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_title'  => '山田',
				'post_status' => 'publish',
			)
		);

		$term = wp_insert_term( '女性', Resource_Tag_Taxonomy::TAXONOMY, array( 'slug' => 'female-disabled' ) );
		wp_set_object_terms( $staff_id, array( (int) $term['term_id'] ), Resource_Tag_Taxonomy::TAXONOMY );
		clean_post_cache( $staff_id );
		clean_object_term_cache( $staff_id, Resource_Tag_Taxonomy::TAXONOMY );

		$actual = vkbm_get_resource_display_name( $staff_id );
		$this->assertSame( '山田', $actual, 'タグ表示OFF => スタッフ名のみ / Tag display OFF => name only' );
	}

}
