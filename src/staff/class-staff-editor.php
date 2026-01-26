<?php
/**
 * Free edition: staff editor is disabled.
 *
 * 日本語: Free版ではスタッフ編集UIを無効化する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Staff;

/**
 * Free edition: staff editor is disabled.
 * 日本語: Free版ではスタッフ編集UIを無効化する。
 */
class Staff_Editor {
	public const META_NOMINATION_FEE = '_vkbm_nomination_fee';

	/**
	 * Whether the staff editor is enabled.
	 *
	 * 日本語: スタッフ編集機能が有効かどうかを返します。
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return false;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// No-op for Free edition. / Free版では登録処理を行わない.
	}
}
