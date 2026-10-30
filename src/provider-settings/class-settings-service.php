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
		// #924: 予約ステータスが送られなかった場合も現在の実効値を引き継ぎ、既存サイトの「即時確定」が仮予約に変わらないようにする。
		$status_mode_key = Settings_Repository::KEY_BOOKING_STATUS_MODE;
		if ( ! array_key_exists( $status_mode_key, $raw_settings ) && isset( $current[ $status_mode_key ] ) ) {
			$raw_settings[ $status_mode_key ] = $current[ $status_mode_key ];
		}
		// #958: 予約の受付方法が送られなかった場合（REST 経由の一部項目の更新など）も、現在の保存値を引き継ぐ。
		$customer_mode_key = Settings_Repository::KEY_BOOKING_CUSTOMER_MODE;
		if ( ! array_key_exists( $customer_mode_key, $raw_settings ) && isset( $current[ $customer_mode_key ] ) ) {
			$raw_settings[ $customer_mode_key ] = $current[ $customer_mode_key ];
		}
		// 削除時の片付けが送られなかった場合は、既定値（残す）ではなく現在の保存値を引き継ぎ、
		// 「すべて削除する」が黙って「残す」に戻らないようにする。
		if ( ! array_key_exists( 'uninstall_cleanup', $raw_settings ) && isset( $current['uninstall_cleanup'] ) ) {
			$raw_settings['uninstall_cleanup'] = $current['uninstall_cleanup'];
		}
		// 画面に項目が無い保存経路では、既存の保持期間を維持する。
		if ( ! array_key_exists( 'booking_auto_anonymize_months', $raw_settings ) && isset( $current['booking_auto_anonymize_months'] ) ) {
			$raw_settings['booking_auto_anonymize_months'] = $current['booking_auto_anonymize_months'];
		}
		// 利用者名のモードと見出しが送られなかった保存では、現在の保存値を引き継ぐ。
		// 無効時に disabled となる見出しや、REST 経由の部分更新で入力済みの設定を消さない。
		foreach ( array( 'booking_attendee_name_mode', 'booking_attendee_name_label' ) as $attendee_name_key ) {
			if ( ! array_key_exists( $attendee_name_key, $raw_settings ) && isset( $current[ $attendee_name_key ] ) ) {
				$raw_settings[ $attendee_name_key ] = $current[ $attendee_name_key ];
			}
		}
		// 別タブの保存で名簿・番号設定が初期値へ戻らないよう、未送信項目は現在値を維持する。
		foreach ( array( 'attendee_roster_enabled', 'attendee_roster_label', 'attendee_roster_show_self', 'attendee_roster_kana_enabled', 'attendee_roster_kana_label', 'attendee_number_enabled', 'attendee_number_label' ) as $attendee_key ) {
			if ( ! array_key_exists( $attendee_key, $raw_settings ) && array_key_exists( $attendee_key, $current ) ) {
				$raw_settings[ $attendee_key ] = $current[ $attendee_key ];
			}
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
