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
 * リソース管理メニューに使用するデフォルトの Dashicons クラス名。
 *
 * @return string デフォルトの Dashicons クラス名。
 */
function vkbm_get_default_resource_menu_icon(): string {
	return 'dashicons-groups';
}

/**
 * リソースメニューアイコンの値を検証してサニタイズする。
 *
 * 値が 'dashicons-' で始まる正しい形式（英小文字・数字・ハイフンのみ）の場合のみ
 * その値を返し、それ以外（空文字・不正な形式・文字列以外）の場合はデフォルト
 * アイコンを返す。これにより管理画面メニューに不正なアイコンクラスが出力される
 * のを防ぐ。
 *
 * @param mixed $value 検証対象の値。
 * @return string サニタイズ済みの Dashicons クラス名。
 */
function vkbm_sanitize_resource_menu_icon( $value ): string {
	$default = vkbm_get_default_resource_menu_icon();

	if ( ! is_string( $value ) ) {
		return $default;
	}

	$value = trim( $value );

	// 'dashicons-' で始まり、英小文字・数字・ハイフンのみで構成される値のみ許可する。
	if ( 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $value ) ) {
		return $value;
	}

	return $default;
}

/**
 * 設定済みのリソース管理メニューアイコン（Dashicons クラス名）を取得する。
 *
 * 未設定または不正な値の場合はデフォルトアイコンを返す。
 *
 * @return string Dashicons クラス名。
 */
function vkbm_get_resource_menu_icon(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$value      = $settings['resource_menu_icon'] ?? '';

	return vkbm_sanitize_resource_menu_icon( $value );
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
 * 数量の見出しラベルを取得する（デフォルト: Number of guests / 人数）。
 *
 * 既存のラベル群と同じく、設定が空文字の場合は翻訳既定へフォールバックする。
 *
 * @return string
 */
function vkbm_get_guests_count_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['guests_count_label'] ) ? trim( (string) $settings['guests_count_label'] ) : '';

	return '' !== $label ? $label : __( 'Number of guests', 'vk-booking-manager' );
}

/**
 * 数量の単位ラベルを取得する（実効値を返す）。
 *
 * 設定値の出し分け:
 * - null（未設定）       → ロケール既定を返す（従来挙動の維持）。日本語は「名」、その他は「guests」。
 * - ''（意図的な空保存） → 空文字を返す（単位なし）。
 * - それ以外            → 保存された単位値をそのまま返す。
 *
 * @return string ロケール既定または保存された単位。単位なしの場合は空文字。
 */
function vkbm_get_guests_unit_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();

	// array_merge で defaults とマージされるため、保存値にキーが無ければ既定の null が入る。
	// 保存値にキーがあれば（空文字でも）そちらが優先される。
	$unit = $settings['guests_unit_label'] ?? null;

	// null（未設定）はロケール既定へ解決する。
	if ( null === $unit ) {
		return vkbm_get_guests_unit_label_locale_default();
	}

	// 空文字を含め、保存された値はそのまま返す（trim はしない。前後スペースもユーザー意図とみなす）。
	return (string) $unit;
}

/**
 * 数量の単位ラベルのロケール既定値を返す。
 *
 * 従来の表示（日本語「5名」/英語「5 guests」）を再現するための既定。
 * 日本語ロケールは「名」、それ以外のロケールは「guests」を返す。
 *
 * @return string
 */
function vkbm_get_guests_unit_label_locale_default(): string {
	// 日本語ロケールは従来どおり「名」を既定の単位とする。
	// この分岐は locale が日本語のときのみ通るため、翻訳は不要（固定値）。
	if ( strpos( get_locale(), 'ja' ) === 0 ) {
		return '名';
	}

	// 非日本語ロケールは従来の「5 guests」相当を再現するため「guests」を返す。
	return __( 'guests', 'vk-booking-manager' );
}

/**
 * 数量（数値＋単位）を表示用文字列に整形する。
 *
 * 単位とのスペーシングをここに集約し、各表示箇所で同じ組み立てを使えるようにする。
 * - 単位が空文字（単位なし）の場合は数値のみを返す。
 * - 日本語の単位（「名」「台」等）は数値と単位を詰めて返す（例: 5名）。
 * - 半角英字で始まる単位（「guests」等）は数値との間に半角スペースを入れる（例: 5 guests）。
 *
 * @param int         $count 数量。
 * @param string|null $unit  単位。null の場合は実効値（ロケール既定含む）を解決して使用する。
 * @return string
 */
function vkbm_format_guests_count( int $count, ?string $unit = null ): string {
	// 単位が渡されなければ実効値（設定 or ロケール既定）を取得する。
	if ( null === $unit ) {
		$unit = vkbm_get_guests_unit_label();
	}

	// 単位なしの場合は数値のみを返す。
	if ( '' === $unit ) {
		return (string) $count;
	}

	// 単位が半角英字で始まる場合のみ、数値との間に半角スペースを入れる（例: 5 guests）。
	// 日本語など全角の単位は詰めて表示する（例: 5名 / 5台）。
	$separator = preg_match( '/^[A-Za-z]/', $unit ) === 1 ? ' ' : '';

	return $count . $separator . $unit;
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
