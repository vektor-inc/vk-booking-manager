<?php
/**
 * Coordinates repository interactions and data sanitization.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Uninstall\Uninstaller;
use WP_Error;

/**
 * Coordinates repository interactions and data sanitization.
 */
class Settings_Service {
	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private $repository;

	/**
	 * Settings sanitizer.
	 *
	 * @var Settings_Sanitizer
	 */
	private $sanitizer;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $repository Settings repository instance.
	 * @param Settings_Sanitizer  $sanitizer  Sanitizer instance.
	 */
	public function __construct( Settings_Repository $repository, Settings_Sanitizer $sanitizer ) {
		$this->repository = $repository;
		$this->sanitizer  = $sanitizer;
	}

	/**
	 * Retrieve current settings with defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->repository->get_settings();
	}

	/**
	 * Retrieve default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return $this->repository->get_default_settings();
	}

	/**
	 * Sanitize and persist settings data.
	 *
	 * @param array<string, mixed> $raw_settings Raw settings payload.
	 * @return bool|WP_Error
	 */
	public function save_settings( array $raw_settings ) {
		$defaults = $this->repository->get_default_settings();
		$current  = $this->repository->get_settings();
		// #635: 詳細ページの表示方法が送られなかった場合（REST 経由の一部項目の更新など）は、
		// 既定値ではなく現在の実効値を引き継ぎ、既存サイトの設定が意図せず変わらないようにする。
		$detail_mode_key = Settings_Repository::KEY_SERVICE_MENU_DETAIL_DISPLAY_MODE;
		if ( ! array_key_exists( $detail_mode_key, $raw_settings ) && isset( $current[ $detail_mode_key ] ) ) {
			$raw_settings[ $detail_mode_key ] = $current[ $detail_mode_key ];
		}
		// 削除時の片付けが送られなかった場合は、既定値（残す）ではなく現在の保存値を引き継ぎ、
		// 「すべて削除する」が黙って「残す」に戻らないようにする。
		if ( ! array_key_exists( 'uninstall_cleanup', $raw_settings ) && isset( $current['uninstall_cleanup'] ) ) {
			$raw_settings['uninstall_cleanup'] = $current['uninstall_cleanup'];
		}
		// 休前日の曜日・特定日（#737）は、宿泊の業種でだけ画面に欄が出る。欄が無い保存（別のタブ・宿泊以外の業種・REST など）で
		// 送られなかったときは、既定値ではなく保存済みの値を引き継ぎ、入力済みの値を消さない。
		foreach ( array( 'lodging_pre_holiday_weekdays', 'lodging_special_dates' ) as $lodging_key ) {
			if ( ! array_key_exists( $lodging_key, $raw_settings ) && isset( $current[ $lodging_key ] ) ) {
				$raw_settings[ $lodging_key ] = $current[ $lodging_key ];
			}
		}
		$sanitized = $this->sanitizer->sanitize( $raw_settings, $defaults );
		$sanitized = Industry_Presets::apply_transition( $current, $sanitized, $raw_settings, $defaults );

		// 「プラグイン削除時の片付け」は、データを消す設定のため manage_options を持つ利用者だけが変更できる。
		// 権限が無い利用者の保存では、項目が送られないため、既存の値を保持する。
		if ( ! current_user_can( 'manage_options' ) ) {
			$sanitized['uninstall_cleanup'] = Uninstaller::normalize_mode( $current['uninstall_cleanup'] ?? Uninstaller::MODE_KEEP );
		}

		$errors       = $this->sanitizer->get_errors();
		$field_errors = $this->sanitizer->get_field_errors();

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'vkbm_settings_invalid',
				__( 'There is a problem with the input content.', 'vk-booking-manager' ),
				array(
					'messages' => $errors,
					'fields'   => $field_errors,
				)
			);
		}

		$result = $this->repository->update_settings( $sanitized );

		if ( $result ) {
			// 設定保存後にキャッシュをクリアし、同一リクエスト内で最新値を返すようにする。
			// Clear the nomination-enabled cache so the updated value is used
			// within the same request.
			Staff_Editor::clear_nomination_enabled_cache();
		}

		return $result;
	}
}
