<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\TermOrder\Term_Order_Manager;
use WP_UnitTestCase;
use function do_action;
use function get_post;
use function update_term_meta;
use function wp_set_object_terms;

/**
 * @group post-types
 */
class Service_Menu_Group_Sort_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		do_action( 'init' );
	}

	public function test_sort_menus_by_group_order_and_menu_order(): void {
		$set_group_id = self::factory()->term->create(
			[
				'taxonomy' => Service_Menu_Post_Type::TAXONOMY_GROUP,
				'name'     => 'Set Menu',
			]
		);
		$single_group_id = self::factory()->term->create(
			[
				'taxonomy' => Service_Menu_Post_Type::TAXONOMY_GROUP,
				'name'     => 'Single Menu',
			]
		);

		update_term_meta( $set_group_id, Term_Order_Manager::META_KEY, '1' );
		update_term_meta( $single_group_id, Term_Order_Manager::META_KEY, '2' );

		$cut_perm_id = self::factory()->post->create(
			[
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'post_title' => 'Cut & Perm',
				'menu_order' => 1,
			]
		);
		$cut_id = self::factory()->post->create(
			[
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'post_title' => 'Cut',
				'menu_order' => 1,
			]
		);
		$perm_id = self::factory()->post->create(
			[
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'post_title' => 'Perm',
				'menu_order' => 2,
			]
		);
		$blow_id = self::factory()->post->create(
			[
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'post_title' => 'Blow',
				'menu_order' => 3,
			]
		);
		$ungrouped_id = self::factory()->post->create(
			[
				'post_type'  => Service_Menu_Post_Type::POST_TYPE,
				'post_title' => 'Ungrouped',
				'menu_order' => 0,
			]
		);

		wp_set_object_terms( $cut_perm_id, [ $set_group_id ], Service_Menu_Post_Type::TAXONOMY_GROUP );
		wp_set_object_terms( $cut_id, [ $single_group_id ], Service_Menu_Post_Type::TAXONOMY_GROUP );
		wp_set_object_terms( $perm_id, [ $single_group_id ], Service_Menu_Post_Type::TAXONOMY_GROUP );
		wp_set_object_terms( $blow_id, [ $single_group_id ], Service_Menu_Post_Type::TAXONOMY_GROUP );

		$posts = array_filter(
			array_map(
				static fn( int $post_id ): ?\WP_Post => get_post( $post_id ),
				[ $cut_id, $blow_id, $cut_perm_id, $perm_id, $ungrouped_id ]
			)
		);

		$sorted = Service_Menu_Post_Type::sort_menus_by_group( $posts );
		$sorted_ids = array_map(
			static fn( \WP_Post $post ): int => (int) $post->ID,
			$sorted
		);

		$this->assertSame(
			[ $cut_perm_id, $cut_id, $perm_id, $blow_id, $ungrouped_id ],
			$sorted_ids
		);
	}
}
