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

use VKBookingManager\Common\Booking_Custom_Fields;
use VKBookingManager\Common\Page_Reference;
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
		// 別タブや部分更新で背景が未送信なら、現在の保存値を引き継ぐ。
		if ( ! array_key_exists( 'design_menu_card_background', $raw_settings ) && isset( $current['design_menu_card_background'] ) ) {
			$raw_settings['design_menu_card_background'] = $current['design_menu_card_background'];
		}
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
		// 予約ページの固定ページ（#1050）が送られなかった保存（REST 経由の部分更新など）では、現在の保存値を引き継ぎ、
		// 選んだページが黙って「その他」の URL に戻らないようにする。
		if ( ! array_key_exists( 'reservation_page_id', $raw_settings ) && isset( $current['reservation_page_id'] ) ) {
			$raw_settings['reservation_page_id'] = $current['reservation_page_id'];
		}
		// 利用者名のモードと見出しが送られなかった保存では、現在の保存値を引き継ぐ。
		// 無効時に disabled となる見出しや、REST 経由の部分更新で入力済みの設定を消さない。
		foreach ( array( 'booking_attendee_name_mode', 'booking_attendee_name_label' ) as $attendee_name_key ) {
			if ( ! array_key_exists( $attendee_name_key, $raw_settings ) && isset( $current[ $attendee_name_key ] ) ) {
				$raw_settings[ $attendee_name_key ] = $current[ $attendee_name_key ];
			}
		}
		// 予約時の入力項目（#1147）は「予約時の入力項目」画面でだけ欄が出る。欄が無い保存（別のタブ・REST の部分更新など）で
		// 送られなかったときは、保存済みの値を引き継ぐ。画面で全部削除したときは印（booking_custom_fields_submitted）だけが届くため、引き継がない。
		if ( ! array_key_exists( Booking_Custom_Fields::SETTINGS_KEY, $raw_settings ) && empty( $raw_settings[ Booking_Custom_Fields::SETTINGS_SUBMITTED_KEY ] ) && isset( $current[ Booking_Custom_Fields::SETTINGS_KEY ] ) ) {
			$raw_settings[ Booking_Custom_Fields::SETTINGS_KEY ] = $current[ Booking_Custom_Fields::SETTINGS_KEY ];
		}
		// 別タブの保存で名簿・番号設定が初期値へ戻らないよう、未送信項目は現在値を維持する。
		foreach ( array( 'attendee_roster_enabled', 'attendee_roster_label', 'attendee_roster_show_self', 'attendee_roster_kana_enabled', 'attendee_roster_kana_label', 'attendee_number_enabled', 'attendee_number_label' ) as $attendee_key ) {
			if ( ! array_key_exists( $attendee_key, $raw_settings ) && array_key_exists( $attendee_key, $current ) ) {
				$raw_settings[ $attendee_key ] = $current[ $attendee_key ];
			}
		}
		// 休前日の曜日・特定日・住所の必須設定は、宿泊の業種でだけ画面に欄が出る。欄が無い保存（別のタブ・宿泊以外の業種・REST など）で
		// 送られなかったときは、既定値ではなく保存済みの値を引き継ぎ、入力済みの値を消さない。
		// 最終チェックイン時刻の既定値（#1136）は、画面からは「時」「分」のプルダウンで送られる。そのときはサニタイズで「時」を優先するため、ここで引き継いでも上書きされない。
		foreach ( array( 'lodging_pre_holiday_weekdays', 'lodging_special_dates', 'lodging_address_required', Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME ) as $lodging_key ) {
			if ( ! array_key_exists( $lodging_key, $raw_settings ) && isset( $current[ $lodging_key ] ) ) {
				$raw_settings[ $lodging_key ] = $current[ $lodging_key ];
			}
		}
		// メニューの呼び方の複数形は、複数形のある言語の画面にだけ欄が出る。欄が無い保存（日本語の画面・別のタブ・REST の部分更新など）で
		// 送られなかったときは、既定値ではなく保存済みの値を引き継ぐ。空文字が送られたときは消去として扱う。
		foreach ( array( 'menu_label', 'menu_label_plural' ) as $menu_label_key ) {
			if ( ! array_key_exists( $menu_label_key, $raw_settings ) && array_key_exists( $menu_label_key, $current ) ) {
				$raw_settings[ $menu_label_key ] = $current[ $menu_label_key ];
			}
		}
		// 電話番号の必須設定は、欄が無い保存（REST など）で送られなかったとき、既定値ではなく保存済みの値を引き継ぐ。
		if ( ! array_key_exists( 'customer_phone_required', $raw_settings ) && isset( $current['customer_phone_required'] ) ) {
			$raw_settings['customer_phone_required'] = $current['customer_phone_required'];
		}
		$sanitized = $this->sanitizer->sanitize( $raw_settings, $defaults );
		$sanitized = Industry_Presets::apply_transition( $current, $sanitized, $raw_settings, $defaults );

		// 予約ページに新しく選べるのは公開中の固定ページだけ（#1050）。今保存されている ID はゴミ箱に入れた等でも保つ。
		$current_page_id                  = (int) ( $current['reservation_page_id'] ?? 0 );
		$sanitized['reservation_page_id'] = Page_Reference::restrict_to_selectable(
			(int) ( $sanitized['reservation_page_id'] ?? 0 ),
			$current_page_id
		);
		// 今保存されている ID が送られた（部分更新で引き継いだ）ときは、ページを完全に削除した後でも保つ。
		// サニタイズでは存在しない ID は 0 になるが、0 にすると「削除済み」の警告が消え、残っている URL の設定へ黙って戻るため。
		$submitted_page_id = $raw_settings['reservation_page_id'] ?? null;
		if ( $current_page_id > 0 && is_numeric( $submitted_page_id ) && (int) $submitted_page_id === $current_page_id ) {
			$sanitized['reservation_page_id'] = $current_page_id;
		}

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
