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
	$default    = __( 'Staff', 'vk-booking-manager' );
	$label      = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : $default;
	$label      = trim( $label );

	return '' !== $label ? $label : $default;
}

/**
 * Get the configured plural label for resources (default: Staff).
 *
 * @return string
 */
function vkbm_get_resource_label_plural(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$default    = __( 'Staff', 'vk-booking-manager' );
	$label      = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : $default;
	$label      = trim( $label );

	if ( '' === $label ) {
		return vkbm_get_resource_label_singular();
	}

	$singular = vkbm_get_resource_label_singular();
	// If the plural label is still the default and singular has been customized,
	// use the singular label as the plural label.
	// 複数形ラベルがデフォルトのままで単数形がカスタマイズされている場合、
	// 単数形ラベルを複数形として使用する。
	if ( $default === $label && $default !== $singular ) {
		return $singular;
	}

	return $label;
}

/**
 * 指名なしラベルを取得する（デフォルト: No preference）。
 *
 * @return string
 */
function vkbm_get_no_nomination_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['no_nomination_label'] ) ? trim( (string) $settings['no_nomination_label'] ) : '';

	return '' !== $label ? $label : __( 'No preference', 'vk-booking-manager' );
}

/**
 * 指名料ラベルを取得する（デフォルト: Nomination fee）。
 *
 * @return string
 */
function vkbm_get_nomination_fee_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['nomination_fee_label'] ) ? trim( (string) $settings['nomination_fee_label'] ) : '';

	return '' !== $label ? $label : __( 'Nomination fee', 'vk-booking-manager' );
}

/**
 * Get the configured duration label (default: Time).
 * 所要時間ラベルを取得する（デフォルト: Time）。
 *
 * @return string
 */
function vkbm_get_duration_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['duration_label'] ) ? trim( (string) $settings['duration_label'] ) : '';

	return '' !== $label ? $label : __( 'Time', 'vk-booking-manager' );
}

/**
 * Get the configured other conditions label (default: Other conditions).
 * その他条件ラベルを取得する（デフォルト: Other conditions）。
 *
 * @return string
 */
function vkbm_get_other_conditions_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['other_conditions_label'] ) ? trim( (string) $settings['other_conditions_label'] ) : '';

	return '' !== $label ? $label : __( 'Other conditions', 'vk-booking-manager' );
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
