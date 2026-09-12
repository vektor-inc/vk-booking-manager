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
 * Free edition: staff editor is disabled.
 *
 * Free版ではスタッフ編集UIを無効化する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Staff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free edition: staff editor is disabled.
 * Free版ではスタッフ編集UIを無効化する。
 */
class Staff_Editor {
	public const META_NOMINATION_FEE = '_vkbm_nomination_fee';

	/**
	 * Whether the staff/resource system is available.
	 *
	 * Free版ではリソース機能は無効です。
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return false;
	}

	/**
	 * Whether the nomination feature is enabled.
	 *
	 * Free版では指名機能は無効です。
	 *
	 * @return bool
	 */
	public static function is_nomination_enabled(): bool {
		return false;
	}

	/**
	 * サービスメニュー単位の指名機能有効判定（#391）。
	 *
	 * Free版では指名機能自体が常に無効のため、メニューIDに関わらず常に false を返す。
	 * Pro版とのAPI互換性のために定義する。
	 *
	 * @param int $menu_id サービスメニューの投稿ID（Free版では未使用）。
	 * @return bool
	 */
	public static function is_nomination_enabled_for_menu( int $menu_id ): bool {
		return false;
	}

	/**
	 * このメニューで予約枠の定員2以上・複数人一括予約・料金区分を利用できるかどうか（#412 C-3 / #392）。
	 *
	 * Free版ではこれらの機能自体が常に無効のため、メニューIDに関わらず常に false を返す。
	 * Pro版とのAPI互換性のために定義する。
	 *
	 * @param int $menu_id サービスメニューの投稿ID（Free版では未使用）。
	 * @return bool
	 */
	public static function is_multi_guest_available_for_menu( int $menu_id ): bool {
		return false;
	}

	/**
	 * このメニューで貸し切り予約・予約者による貸切指定・貸切料金を利用できるかどうか（#392）。
	 *
	 * Free版ではこれらの機能自体が常に無効のため、メニューIDに関わらず常に false を返す。
	 * Pro版とのAPI互換性のために定義する。
	 *
	 * @param int $menu_id サービスメニューの投稿ID（Free版では未使用）。
	 * @return bool
	 */
	public static function is_exclusive_booking_available_for_menu( int $menu_id ): bool {
		return false;
	}

	/**
	 * 指名機能キャッシュをクリアする（Free版では no-op）。
	 *
	 * Pro版とのAPI互換性のために定義する。Free版では指名機能・予約枠の定員機能とも
	 * 無効のため、実体としてクリアすべきキャッシュは存在しない。
	 *
	 * @return void
	 */
	public static function clear_nomination_enabled_cache(): void {
		// Free版では何もしない.
	}

	/**
	 * 予約枠の定員（同一枠で複数人を受け入れる）機能が有効かどうかを返す。
	 *
	 * Free版ではこの機能は利用できないため常に無効を返す。
	 * Pro版とのAPI互換性のために定義する。
	 *
	 * @return bool
	 */
	public static function is_slot_capacity_enabled(): bool {
		return false;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// No-op for Free edition. / Free版では登録処理を行わない.
	}
}
