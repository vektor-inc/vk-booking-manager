<?php

/**
 * Handles persistence of resource schedule templates via post meta.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Resources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persistence of resource schedule templates via post meta.
 */
class Resource_Schedule_Template_Repository {
	public const META_KEY = '_vkbm_resource_schedule_template';

	/**
	 * Retrieve a schedule template.
	 *
	 * @param int $resource_id Resource post ID.
	 * @return array<string, mixed>
	 */
	public function get_template( int $resource_id ): array {
		$stored = get_post_meta( $resource_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return $this->get_default_template();
		}

		return array_merge(
			$this->get_default_template(),
			array(
				'use_provider_hours' => ! empty( $stored['use_provider_hours'] ),
				'days'               => is_array( $stored['days'] ?? null ) ? $stored['days'] : array(),
			)
		);
	}

	/**
	 * Save a schedule template.
	 *
	 * @param int   $resource_id Resource post ID.
	 * @param array $template    Template data.
	 */
	public function save_template( int $resource_id, array $template ): void {
		update_post_meta( $resource_id, self::META_KEY, $template );
	}

	/**
	 * Delete a schedule template.
	 *
	 * @param int $resource_id Resource post ID.
	 */
	public function delete_template( int $resource_id ): void {
		delete_post_meta( $resource_id, self::META_KEY );
	}

	/**
	 * Default template structure.
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_template(): array {
		return array(
			'use_provider_hours' => true,
			'days'               => array(),
		);
	}
}
