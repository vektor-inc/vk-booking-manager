<?php

/**
 * Resource label helper functions.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;

/**
 * Get the configured singular label for resources (default: Staff).
 *
 * @return string
 */
function vkbm_get_resource_label_singular(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : 'Staff';
	$label      = trim( $label );

	return '' !== $label ? $label : 'Staff';
}

/**
 * Get the configured plural label for resources (default: Staff).
 *
 * @return string
 */
function vkbm_get_resource_label_plural(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : 'Staff';
	$label      = trim( $label );

	if ( '' === $label ) {
		return vkbm_get_resource_label_singular();
	}

	$singular = vkbm_get_resource_label_singular();
	if ( 'Staff' === $label && 'Staff' !== $singular ) {
		return $singular;
	}

	return $label;
}

/**
 * リソースの表示名を取得する。
 * Get the display name for a resource.
 *
 * `resource_tag_display_enabled` 設定が ON の場合、リソース名の後ろに
 * タグを括弧表記で追加する。OFF の場合はリソース名のみ返す。
 * When the `resource_tag_display_enabled` setting is ON, tags are appended
 * in parentheses after the resource name. When OFF, only the name is returned.
 *
 * @param int $post_id リソース投稿ID / Resource post ID.
 * @return string 表示用リソース名 / Display name for the resource.
 */
function vkbm_get_resource_display_name( int $post_id ): string {
	// リクエスト内で設定値をキャッシュ（wp_cache 経由でテスト間のリセットにも対応）
	// Cache the setting value within the request (via wp_cache so it resets between tests).
	$enabled = wp_cache_get( 'resource_tag_display_enabled', 'vkbm' );
	if ( false === $enabled ) {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$enabled    = ! empty( $settings['resource_tag_display_enabled'] ) ? 'yes' : 'no';
		wp_cache_set( 'resource_tag_display_enabled', $enabled, 'vkbm' );
	}

	$name = get_the_title( $post_id );
	if ( '' === $name ) {
		return '';
	}

	if ( 'yes' !== $enabled ) {
		return $name;
	}

	$tag_labels = Resource_Tag_Taxonomy::get_tag_labels( $post_id );
	if ( empty( $tag_labels ) ) {
		return $name;
	}

	return $name . ' ( ' . implode( ', ', $tag_labels ) . ' )';
}
