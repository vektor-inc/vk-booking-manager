<?php
/**
 * ⚠️ 自動生成ファイル — 直接編集しないでください。
 *
 * このファイルはビルド／dist 処理（bin/switch-resource-config.js /
 * bin/switch-resource-config-dev.js）が `*-free.php` / `*-pro.php` から
 * コピーして生成し、ビルドのたびに上書きします。直接編集しても次のビルドで失われます。
 * 変更が必要な場合は対応する `*-free.php` / `*-pro.php`（差し替え元）を編集してください。
 */

/**
 * リソースタグタクソノミーのスタブ（Free版）
 * Resource tag taxonomy stub (Free edition).
 *
 * Free版ではリソースタグ機能を無効にする。
 * In the free edition the resource tag feature is disabled.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Resources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free版のスタブクラス。register()を呼んでも何もしない。
 * Free edition stub class. Calling register() is a no-op.
 */
class Resource_Tag_Taxonomy {

	/** タクソノミースラッグ（Pro版と同じ定数を持つ） / Taxonomy slug (same constant as Pro) */
	public const TAXONOMY = 'vkbm_resource_tag';

	/**
	 * フックを登録する（Free版では何もしない）。
	 * Register hooks (no-op in the free edition).
	 */
	public function register(): void {
		// Free版ではリソースタグタクソノミーを登録しない。
		// The free edition does not register the resource tag taxonomy.
	}

	/**
	 * 指定リソースのタグラベル配列を取得する（Free版では常に空配列）。
	 * Get tag labels for the given resource (always empty in the free edition).
	 *
	 * @param int $post_id リソース投稿ID / Resource post ID.
	 * @return string[] 常に空配列 / Always an empty array.
	 */
	public static function get_tag_labels( int $post_id ): array {
		return array();
	}

	/**
	 * 指定リソースのリソースタグターム ID 配列を取得する（Free版では常に空配列、#431）。
	 *
	 * @param int $post_id リソース投稿ID。
	 * @return int[] 常に空配列。
	 */
	public static function get_tag_ids( int $post_id ): array {
		return array();
	}

	/**
	 * 指定タグ ID をすべて持つリソースを返す（Free版ではタグ機能自体が無いため常に空配列、#431）。
	 *
	 * @param array<int> $tag_ids リソースタグのターム ID 配列。
	 * @return int[] 常に空配列。
	 */
	public static function get_resource_ids_for_tags( array $tag_ids ): array {
		return array();
	}

	/**
	 * リソースが指定タグをすべて持つかを判定する（Free版、#431）。
	 *
	 * Free版にはタグ機能自体が無いため、$tag_ids が空（＝制約なし）のときのみ true を返す。
	 *
	 * @param int        $resource_id リソース投稿ID。
	 * @param array<int> $tag_ids     必須のリソースタグターム ID 配列。
	 * @return bool
	 */
	public static function resource_has_all_tags( int $resource_id, array $tag_ids ): bool {
		return empty( $tag_ids );
	}

	/**
	 * 指定したターム ID 配列に対応するリソースタグ名を返す（Free版では常に空配列、#431）。
	 *
	 * @param array<int> $tag_ids リソースタグのターム ID 配列。
	 * @return string[] 常に空配列。
	 */
	public static function get_labels_for_tag_ids( array $tag_ids ): array {
		return array();
	}
}
