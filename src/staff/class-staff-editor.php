<?php

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
	 * Register hooks.
	 */
	public function register(): void {
		// No-op for Free edition. / Free版では登録処理を行わない.
	}

	/**
	 * Clear the nomination-enabled cache (no-op for Free edition).
	 *
	 * 指名機能キャッシュをクリアする（Free版では no-op）。
	 *
	 * Defined for API compatibility with the Pro edition. The Free edition
	 * disables the nomination feature itself, so there is no cache to clear.
	 *
	 * Pro版とのAPI互換性のために定義。Free版では指名機能自体が無効のため、
	 * 実体としてクリアすべきキャッシュは存在しない。
	 *
	 * @return void
	 */
	public static function clear_nomination_enabled_cache(): void {
		// No-op for Free edition. / Free版では何もしない.
	}
}
