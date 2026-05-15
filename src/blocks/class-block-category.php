<?php
/**
 * Registers the block inserter category for VK Booking Manager blocks.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a dedicated block inserter category so plugin blocks are easy to find.
 */
class Block_Category {
	public const SLUG = 'vk-booking-manager';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
	}

	/**
	 * Append the VK Booking Manager category to the block inserter.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing block categories.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && self::SLUG === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => self::SLUG,
			'title' => __( 'VK Booking Manager', 'vk-booking-manager' ),
			'icon'  => null,
		);

		return $categories;
	}
}
