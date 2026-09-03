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
}
