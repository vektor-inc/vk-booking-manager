<?php
/**
 * Provider settings admin page.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Settings_Panel;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\Lodging_Rate;
use VKBookingManager\Common\Mail_From;
use VKBookingManager\Common\Weekday_Rule;
use VKBookingManager\PostTypes\Service_Menu_Detail_Link;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Payment_Instructions;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\Uninstall\Uninstaller;
use function vkbm_get_default_resource_menu_icon;
use function vkbm_get_resource_label_singular;
use function vkbm_sanitize_resource_menu_icon;

/**
 * Handles the provider settings admin page.
 */
class Provider_Settings_Page {
	private const MENU_SLUG    = 'vkbm-provider-settings';
	private const NONCE_ACTION = 'vkbm_provider_settings_save';
	private const NONCE_NAME   = 'vkbm_provider_settings_nonce';

	/**
	 * 入力エラー時の送信値を一時保存する transient キーの接頭辞（末尾にユーザー ID を付ける）。
	 */
	private const OLD_INPUT_TRANSIENT_PREFIX = 'vkbm_provider_settings_previous_input_';

	/**
	 * 項目ごとのエラーを一時保存する transient キーの接頭辞（末尾にユーザー ID を付ける）。
	 */
	private const FIELD_ERRORS_TRANSIENT_PREFIX = 'vkbm_provider_settings_field_errors_';

	/**
	 * 入力エラー時の再表示で、保存時と同じサニタイズ（Settings_Sanitizer::sanitize()）の結果から
	 * 送信値を復元する設定項目。
	 *
	 * ここに無い項目は sanitize_old_input() で個別に復元している。フォームにあるのに
	 * どちらにも含まれない項目は、再表示画面で送信値ではなく保存済みの値が表示され、
	 * エラーを直して保存し直したときに、その項目の変更が知らされないまま元に戻る。
	 * フォームに設定項目を追加したときは、このどちらかに必ず含めること。
	 */
	private const OLD_INPUT_KEYS_FROM_SANITIZER = array(
		'tax_label_text',
		'service_menu_detail_display_mode',
		'resource_tag_search_enabled',
		'reservation_date_search_enabled',
		'resource_tag_display_enabled',
		'provider_slot_step_minutes',
		'provider_allow_staff_overlap_admin',
		'booking_customer_mode',
		'booking_attendee_name_mode',
		'booking_attendee_name_label',
		'attendee_roster_enabled',
		'attendee_roster_label',
		'attendee_roster_show_self',
		'attendee_roster_kana_enabled',
		'attendee_roster_kana_label',
		'attendee_number_enabled',
		'attendee_number_label',
		'registration_email_verification_enabled',
		'membership_redirect_wp_register',
		'auth_rate_limit_enabled',
		'auth_rate_limit_register_max',
		'auth_rate_limit_login_max',
		'provider_privacy_policy_mode',
		'provider_privacy_policy_url',
		'provider_privacy_policy_content',
		'shift_alert_months',
		'shift_auto_register_months',
		'booking_auto_anonymize_months',
		'uninstall_cleanup',
		'design_primary_color',
		'design_menu_card_catch_color',
		'design_reservation_button_color',
		'design_menu_card_price_color',
		'design_footer_background_color',
		'design_footer_text_color',
		'design_menu_heading_use_footer_color',
		'design_header_logo_width',
		'design_menu_search_style',
		'design_radius_md',
	);

	/**
	 * Parent admin menu slug.
	 *
	 * @var string
	 */
	private $parent_slug;

	/**
	 * Provider settings service.
	 *
	 * @var Settings_Service
	 */
	private $settings_service;

	/**
	 * Capability required to access the page.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Page hook for the admin page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * 設定の保存が成功した直後にシフトの自動登録を実行するための Shift_Editor
	 * （安藤レビュー指摘T1対応）。コンストラクタで渡されなかった場合は null で、
	 * その場合は自動登録を実行しない（既存テスト等、シフト機能と無関係な用途向け）。
	 *
	 * @var Shift_Editor|null
	 */
	private $shift_editor;

	/**
	 * 「連携」タブ（Google カレンダー連携）の中身を描画するパネル（issue #475）。
	 * コンストラクタで渡されなかった場合は null で、その場合は「連携」タブを表示しない。
	 *
	 * @var Google_Calendar_Settings_Panel|null
	 */
	private $google_calendar_panel;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service                    $settings_service Provider settings service.
	 * @param string                              $capability       Capability required to access the page.
	 * @param string                              $parent_slug      Parent admin menu slug.
	 * @param Shift_Editor|null                   $shift_editor          設定保存成功時にシフトの自動登録を実行する
	 *                                                                    Shift_Editor（安藤レビュー指摘T1対応）。未指定
	 *                                                                    （null）の場合は保存成功時の自動登録を行わない。
	 * @param Google_Calendar_Settings_Panel|null $google_calendar_panel 「連携」タブの中身を描画するパネル（issue #475）。
	 *                                                                   未指定（null）の場合は「連携」タブを表示しない。
	 */
	public function __construct( Settings_Service $settings_service, string $capability = 'manage_options', string $parent_slug = 'vkbm-shift-dashboard', ?Shift_Editor $shift_editor = null, ?Google_Calendar_Settings_Panel $google_calendar_panel = null ) {
		$this->settings_service      = $settings_service;
		$this->capability            = $capability;
		$this->parent_slug           = $parent_slug;
		$this->shift_editor          = $shift_editor;
		$this->google_calendar_panel = $google_calendar_panel;
	}

	/**
	 * 「連携」タブ（Google カレンダー連携）を表示するかどうかを返す。
	 *
	 * 描画するパネルが注入されていて、かつそのパネルが「見せてよい」と答えた場合のみ表示する。
	 * パネル側の条件は Pro 版であることと、中継サーバーの接続先が決まっていること
	 * （`Google_Calendar_Settings_Panel::is_available()`）。
	 *
	 * @return bool 表示するなら true。
	 */
	private function show_integration_tab(): bool {
		return null !== $this->google_calendar_panel && $this->google_calendar_panel->is_available();
	}

	/**
	 * Register WordPress hooks for the settings page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Booking Manager menu and page.
	 */
	public function register_menu(): void {
		if ( '' !== $this->parent_slug ) {
			$this->page_hook = add_submenu_page(
				$this->parent_slug,
				__( 'Basic settings', 'vk-booking-manager' ),
				__( 'Basic settings', 'vk-booking-manager' ),
				$this->capability,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
			return;
		}

		$this->page_hook = add_menu_page(
			__( 'Basic settings', 'vk-booking-manager' ),
			__( 'BM settings', 'vk-booking-manager' ),
			$this->capability,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-generic',
			57
		);

		// When this page is a top-level menu, explicitly register it as the first submenu item as well.
		// This ensures the top-level menu click routes to the settings page even when additional submenus exist.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Basic settings', 'vk-booking-manager' ),
			__( 'Basic settings', 'vk-booking-manager' ),
			$this->capability,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Process form submission triggered from the settings page.
	 */
	public function handle_form_submission(): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verification just below.
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$users_can_register = ! empty( $_POST['vkbm_users_can_register'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		update_option( 'users_can_register', $users_can_register );

		$payload = isset( $_POST['vkbm_provider_settings'] ) && is_array( $_POST['vkbm_provider_settings'] )
			? wp_unslash( $_POST['vkbm_provider_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized inside settings_service->save_settings().
			: array();

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		$license_key_raw    = null;
		$delete_license_key = false;
		if ( class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE ) && current_user_can( 'manage_options' ) ) {
			$license_key_raw    = ( array_key_exists( 'license_key', $payload ) && is_scalar( $payload['license_key'] ) )
					? sanitize_text_field( (string) $payload['license_key'] )
					: null;
			$delete_license_key = ! empty( $payload['delete_license_key'] );
		}
		unset( $payload['license_key'], $payload['delete_license_key'] );
		$result       = $this->settings_service->save_settings( $payload );
		$saved        = true;
		$field_errors = array();

		if ( is_wp_error( $result ) ) {
			$saved      = false;
			$main_error = $result->get_error_message();

			if ( ! empty( $main_error ) ) {
				add_settings_error(
					self::MENU_SLUG,
					'vkbm_provider_settings_save',
					$main_error,
					'error'
				);
			}

			$error_data = $result->get_error_data();

			if ( is_array( $error_data ) ) {
				$field_errors = is_array( $error_data['fields'] ?? null ) ? $error_data['fields'] : array();
			}

			if ( empty( $main_error ) ) {
				add_settings_error(
					self::MENU_SLUG,
					'vkbm_provider_settings_save',
					__( 'Failed to save settings.', 'vk-booking-manager' ),
					'error'
				);
			}

			$old_input = $this->sanitize_old_input( $payload );
			set_transient( $this->get_old_input_transient_key(), $old_input, 30 );
		} else {
			// 削除指定を新しいキーより優先し、空欄または未送信なら保存済みキーを維持する。
			if ( $delete_license_key ) {
				delete_option( 'vk-booking-manager-pro-license-key' );
				add_settings_error(
					self::MENU_SLUG,
					'vkbm_provider_settings_license_key_deleted',
					__( 'License key deleted.', 'vk-booking-manager' ),
					'updated'
				);
			} elseif ( null !== $license_key_raw && '' !== $license_key_raw ) {
				update_option( 'vk-booking-manager-pro-license-key', $license_key_raw );
			}
			add_settings_error(
				self::MENU_SLUG,
				'vkbm_provider_settings_save',
				__( 'Basic settings saved.', 'vk-booking-manager' ),
				'updated'
			);
			delete_transient( $this->get_field_errors_transient_key() );
			delete_transient( $this->get_old_input_transient_key() );

			// #958: 予約の受付方法が「会員のみ」以外で送信元メールアドレスが未設定なら、メール設定タブへのリンク付きで注意を出す。
			$this->maybe_notify_booking_customer_mode_mail_from();

			// 設定の保存が成功した場合にのみ、シフトの自動登録をその場で実行する（安藤レビュー指摘T1）。
			$this->maybe_run_shift_auto_register();
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		if ( ! empty( $field_errors ) ) {
			set_transient( $this->get_field_errors_transient_key(), $field_errors, 30 );
		} else {
			delete_transient( $this->get_field_errors_transient_key() );
		}

		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve UI state.
		$allowed_tabs = array( 'store', 'system', 'attendees', 'email', 'registration', 'consent', 'design', 'advanced', 'faq' );
		if ( $this->show_integration_tab() ) {
			$allowed_tabs[] = 'integration';
		}
		if ( class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE ) && current_user_can( 'manage_options' ) ) {
			$allowed_tabs[] = 'license';
		}
		/**
		 * 許可されるタブスラッグの一覧。拡張プラグインが独自タブを追加できる拡張ポイント。
		 * 拡張プラグインはここに独自タブのスラッグを追加し、保存後のリダイレクトで表示中のタブを維持する。
		 *
		 * @param string[] $allowed_tabs 保存後のリダイレクトで維持してよいタブスラッグの一覧。
		 */
		$allowed_tabs = apply_filters( 'vkbm_provider_settings_allowed_tabs', $allowed_tabs );
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = '';
		}

		$redirect_args = array(
			'page'             => self::MENU_SLUG,
			'settings-updated' => $saved ? 'true' : 'false',
		);
		if ( '' !== $active_tab ) {
			$redirect_args['tab'] = $active_tab;
		}

		$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * 設定の保存が成功した直後に、シフトの自動登録（有効な場合のみ）をその場で実行する。
	 *
	 * WordPress の update_option() は、保存前後で値が変わらない場合
	 * update_option_{OPTION_KEY} フックを発火させないため、そのフックだけに頼ると、設定値を
	 * 変えずに保存したときに Shift_Editor::handle_settings_updated() が呼ばれず、完全に削除した
	 * シフトを作り直せない不具合があった（安藤レビュー指摘T1）。この呼び出し口を保存成功時の
	 * 実行に一本化し、フックの発火有無に関わらず、保存が成功するたびに実行する。
	 *
	 * $shift_editor がコンストラクタで注入されなかった場合（テスト等、シフト機能と無関係な
	 * 用途）は何もしない。
	 */
	private function maybe_run_shift_auto_register(): void {
		if ( null === $this->shift_editor ) {
			return;
		}

		$this->shift_editor->run_auto_register_on_settings_saved();
	}

	/**
	 * Enqueue scripts and styles for the settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		// ファイルの更新日時を取得するためなので PATH で正しい
		$settings_js         = VKBM_PLUGIN_DIR_PATH . 'assets/js/provider-settings.js';
		$settings_js_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0';
		if ( is_string( $settings_js ) && file_exists( $settings_js ) ) {
			$settings_js_version = (string) filemtime( $settings_js );
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'wp-color-picker' );
		// 旧位置の URL を開いたときに正しいタブへ移すための判定処理（#629）。provider-settings.js より先に読み込む。
		wp_enqueue_script(
			'vkbm-provider-settings-tab-redirect',
			VKBM_PLUGIN_DIR_URL . 'assets/js/provider-settings-tab-redirect.js',
			array(),
			defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0',
			true
		);
		wp_enqueue_script(
			'vkbm-provider-settings',
			VKBM_PLUGIN_DIR_URL . 'assets/js/provider-settings.js',
			array( 'jquery', 'media-editor', 'wp-color-picker', 'vkbm-provider-settings-tab-redirect' ),
			$settings_js_version,
			true
		);
		wp_enqueue_script( 'vkbm-admin-guard-dialog', VKBM_PLUGIN_DIR_URL . 'assets/js/admin-guard-dialog.js', array(), defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0', true );
		wp_enqueue_script( 'vkbm-booking-auto-anonymize-settings', VKBM_PLUGIN_DIR_URL . 'assets/js/booking-auto-anonymize-settings.js', array( 'vkbm-admin-guard-dialog' ), defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0', true );
		wp_localize_script(
			'vkbm-booking-auto-anonymize-settings',
			'vkbmBookingAutoAnonymize',
			array(
				'titleEnable'  => __( 'Enable automatic anonymization of reservation personal data?', 'vk-booking-manager' ),
				'titleShorten' => __( 'Shorten the automatic anonymization period?', 'vk-booking-manager' ),
				/* translators: %s: Selected retention period. */
				'bodyPeriod'   => __( 'Reservations whose end was more than %s ago, including reservations already past that period, will be eligible for anonymization.', 'vk-booking-manager' ),
				/* translators: %s: Personal data field labels. */
				'bodyFields'   => sprintf( __( '%s will be removed and the reservation will be unlinked from the customer. This action cannot be undone.', 'vk-booking-manager' ), \VKBookingManager\Bookings\Booking_Anonymizer::get_field_labels_text() ),
				'cancel'       => __( 'Cancel', 'vk-booking-manager' ),
				'confirm'      => __( 'Save with this setting', 'vk-booking-manager' ),
			)
		);
		$vkbm_provider_settings_l10n = array(
			'logoFrameTitle'           => __( 'Select logo image', 'vk-booking-manager' ),
			'logoFrameButton'          => __( 'Select', 'vk-booking-manager' ),
			'unsavedChangesMessage'    => __( 'You have unsaved changes. If you leave this tab, your changes will not be saved. Do you want to leave?', 'vk-booking-manager' ),
			// 予約リマインダーの「削除」ボタンの読み上げ用ラベル（時間の入力に合わせて JS が更新する）。
			'reminderRemoveLabel'      => self::get_reminder_remove_label( '%s' ),
			'reminderRemoveLabelEmpty' => self::get_reminder_remove_label( '' ),
		);

		// 無料版では業種プリセットの選択欄自体を表示しない（Pro_Upsell::get_feature_notice_html()
		// に差し替える）ため、プリセット関連のローカライズ値も Pro 版でのみ組み立てる。
		// get_presets() はフィルター適用・翻訳ルックアップを伴うため、使われない無料版で
		// 無駄に呼ばない（#387 レビュー指摘・安藤 LOW-8）。
		if ( ! Pro_Upsell::is_free_edition() ) {
			$default_settings = $this->settings_service->get_default_settings();

			$vkbm_provider_settings_l10n = array_merge(
				$vkbm_provider_settings_l10n,
				array(
					'industryPresets'                   => Industry_Presets::get_presets( $default_settings ),
					'industryDefaults'                  => array_intersect_key( $default_settings, array_flip( Industry_Presets::FIELD_KEYS ) ),
					/* translators: %d: Number of automatically configured fields. */
					'industrySummaryTemplate'           => __( 'Items automatically configured in the "System settings" tab for this industry (%d)', 'vk-booking-manager' ),
					'industryAppliedMessage'            => __( 'The industry preset default value has been applied.', 'vk-booking-manager' ),
					'industryCustomMessage'             => __( 'Switched back to Custom. Values have not been changed.', 'vk-booking-manager' ),

					/*
					 * aria-live の共通リージョンへ流す1本のアナウンス用テンプレート（#387）。
					 * 「非表示件数の確定」と「値の差し替え件数」は同じプリセット切り替えイベントで
					 * 同時に決まるため、2回に分けて announce() を呼ばず1本のテキストにまとめる
					 * （スクリーンリーダーによっては後勝ちで前の内容が読まれないことがあるため）。
					 * 差し替え件数が0件のときは後半の句自体を出さない別テンプレートを使う。
					 */
					// translators: 1: Industry preset label. 2: Number of automatically configured fields.
					'industryChangeTemplate'            => __( '%1$s selected. Automatically configured items: %2$d.', 'vk-booking-manager' ),
					// translators: 1: Industry preset label. 2: Number of automatically configured fields. 3: Number of fields updated to the preset default value.
					'industryChangeWithAppliedTemplate' => __( '%1$s selected. Automatically configured items: %2$d, items updated to default value: %3$d.', 'vk-booking-manager' ),
					'enabledLabel'                      => __( 'Enabled', 'vk-booking-manager' ),
					'disabledLabel'                     => __( 'Disabled', 'vk-booking-manager' ),
					'defaultGuestsUnitLabel'            => vkbm_get_guests_unit_label(),
				)
			);
		}

		wp_localize_script( 'vkbm-provider-settings', 'vkbmProviderSettings', $vkbm_provider_settings_l10n );

		$regular_holiday_toggle = "(function () {
			var checkbox = document.getElementById('vkbm-regular-holiday-disabled');
			var panel = document.getElementById('vkbm-regular-holiday-settings');
			if (!checkbox || !panel) {
				return;
			}
			var toggle = function () {
				panel.hidden = checkbox.checked;
			};
			checkbox.addEventListener('change', toggle);
			toggle();
		})();";
		wp_add_inline_script( 'vkbm-provider-settings', $regular_holiday_toggle, 'after' );

		$license_key_delete_toggle = "(function () {
			var checkbox = document.getElementById('vkbm-delete-license-key');
			var input = document.getElementById('vkbm-license-key');
			if (!checkbox || !input) {
				return;
			}
			var toggle = function () {
				input.disabled = checkbox.checked;
			};
			checkbox.addEventListener('change', toggle);
			toggle();
		})();";
		wp_add_inline_script( 'vkbm-provider-settings', $license_key_delete_toggle, 'after' );

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );
	}

	/**
	 * 店舗タブの「お支払いの案内」入力欄の説明文を組み立てる。
	 *
	 * 常に表示する2文に、カード決済などのオンライン決済が有効なときだけ、
	 * どちらの決済方法を選んだお客様にも表示される旨の2文を続ける（#540）。
	 * 翻訳関数は1文ずつに分け、結合は Nomination_Min_Guests_Message::join_sentences()
	 * （日本語は区切りなし・英語は半角スペース区切り）で行う。
	 *
	 * @param bool $online_payment_enabled オンライン決済が有効な場合は true。
	 * @return string エスケープ前の説明文。
	 */
	private static function get_payment_instructions_description( bool $online_payment_enabled ): string {
		// 常に表示する説明。
		$sentences = array(
			__( 'Enter payment information for customers (for example, payment methods accepted at the store and receipts).', 'vk-booking-manager' ),
			__( 'This is shown in the "Payment method" section of the booking confirmation screen.', 'vk-booking-manager' ),
		);

		// オンライン決済が有効なときだけ続けて表示する説明。
		if ( $online_payment_enabled ) {
			$sentences[] = __( 'Because card payment is enabled, this text is shown to customers regardless of which payment method they choose.', 'vk-booking-manager' );
			$sentences[] = __( 'If some information applies only to payment at the store, make that clear, for example by starting with "For payment at the store, ...".', 'vk-booking-manager' );
		}

		// 1文ずつ、言語に合った区切りで連結する。
		return array_reduce(
			array_slice( $sentences, 1 ),
			array( Nomination_Min_Guests_Message::class, 'join_sentences' ),
			$sentences[0]
		);
	}

	/**
	 * お支払いの案内が旧初期値のままのときに、BM 設定画面の上部へ出す通知の HTML を返す。
	 *
	 * 店舗タブの入力欄へ移るリンクを付ける。閉じるボタンは付けない（案内文を書き換えて保存すれば消える）。
	 * リンクのクリック時・移動後に入力欄へフォーカスを移す処理は assets/js/provider-settings.js が行う。
	 *
	 * @param string $store_tab_url 店舗タブの URL（アンカーなし）。
	 * @return string エスケープ済みの HTML。
	 */
	private static function get_payment_instructions_page_notice_html( string $store_tab_url ): string {
		// 2文を言語に合った区切りで連結する。
		$message = Nomination_Min_Guests_Message::join_sentences(
			__( 'Card payment is enabled, but "Payment instructions" still contains the default text written for paying at the store.', 'vk-booking-manager' ),
			__( 'This text is also shown to customers who choose card payment, so please revise it to fit both payment methods.', 'vk-booking-manager' )
		);

		return sprintf(
			'<div class="notice notice-warning vkbm-payment-instructions-notice"><p>%1$s <a href="%2$s" class="vkbm-payment-instructions-notice__link">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( $store_tab_url . '#vkbm-provider-payment-method' ),
			esc_html__( 'Edit payment instructions', 'vk-booking-manager' )
		);
	}

	/**
	 * お支払いの案内が旧初期値のままのときに、入力欄のすぐ下へ出す注意の HTML を返す。
	 *
	 * WordPress の管理画面スクリプトが通知を画面上部へ移動させないよう、inline クラスを付けている。
	 *
	 * @return string エスケープ済みの HTML。
	 */
	private static function get_payment_instructions_field_notice_html(): string {
		// 2文を言語に合った区切りで連結する。
		$message = Nomination_Min_Guests_Message::join_sentences(
			__( 'This is still the default text written for paying at the store.', 'vk-booking-manager' ),
			__( 'It is also shown to customers who choose card payment, so please revise it.', 'vk-booking-manager' )
		);

		return sprintf(
			'<div class="notice notice-warning inline vkbm-payment-instructions-field-notice" id="vkbm-provider-payment-method-notice"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * 予約の受付方法が「会員のみ」以外で、送信元メールアドレスが未設定なら、保存後の画面に注意を出す（#958）。
	 *
	 * 保存済みの設定で判定する。vkbm_mail_from_email フィルターに処理が登録されているときは、フィルターで送信元を設定済みとみなして注意を出さない。注意は add_settings_error() で積み、設定保存の結果と一緒に表示される。
	 */
	private function maybe_notify_booking_customer_mode_mail_from(): void {
		$saved_settings  = $this->settings_service->get_settings();
		$customer_mode   = Settings_Repository::normalize_booking_customer_mode( $saved_settings['booking_customer_mode'] ?? null );
		$saved_mail_from = isset( $saved_settings['mail_from_email'] ) && is_string( $saved_settings['mail_from_email'] ) ? trim( $saved_settings['mail_from_email'] ) : '';

		if ( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER === $customer_mode || '' !== $saved_mail_from || has_filter( 'vkbm_mail_from_email' ) ) {
			return;
		}

		add_settings_error(
			self::MENU_SLUG,
			'vkbm_provider_settings_booking_customer_mode_mail_from',
			self::get_booking_customer_mode_mail_from_notice_html( add_query_arg( 'tab', 'email', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) ),
			'warning'
		);
	}

	/**
	 * 予約の受付方法が「会員のみ」以外のときに、「ユーザー登録設定」タブの先頭へ出す注意文を返す（#958）。
	 *
	 * @param string $mode 予約の受付方法（'guest' または 'both'）。
	 * @return string 注意文（エスケープ前のプレーンテキスト）。
	 */
	private static function get_booking_customer_mode_registration_notice( string $mode ): string {
		if ( Settings_Repository::BOOKING_CUSTOMER_MODE_GUEST === $mode ) {
			return sprintf(
				'%1$s %2$s',
				__( 'Currently, the reservation acceptance method is "Guests only".', 'vk-booking-manager' ),
				__( 'The login and user registration fields are not shown on the reservation screen.', 'vk-booking-manager' )
			);
		}

		return sprintf(
			'%1$s %2$s',
			__( 'Currently, the reservation acceptance method is "Members or guests".', 'vk-booking-manager' ),
			__( 'The settings on this tab apply only to customers who make reservations as members.', 'vk-booking-manager' )
		);
	}

	/**
	 * 予約の受付方法が「会員のみ」以外で、送信元メールアドレスが未設定のときに、保存後に出す注意の HTML を返す（#958）。
	 *
	 * ゲスト予約は確認メールが届くことが前提のため、メール設定タブへのリンクを付けて設定を促す。
	 *
	 * @param string $email_tab_url メール設定タブの URL（アンカーなし）。
	 * @return string エスケープ済みの HTML。
	 */
	private static function get_booking_customer_mode_mail_from_notice_html( string $email_tab_url ): string {
		return sprintf(
			'%1$s %2$s %3$s <a href="%4$s">%5$s</a>',
			esc_html__( 'The sender email address is not set.', 'vk-booking-manager' ),
			esc_html__( 'Confirmation emails for guest reservations may be treated as spam and not reach customers.', 'vk-booking-manager' ),
			esc_html__( 'Please set a sender email address on your own domain.', 'vk-booking-manager' ),
			esc_url( $email_tab_url . '#vkbm-mail-from-email' ),
			esc_html__( 'Open the email settings', 'vk-booking-manager' )
		);
	}

	/**
	 * 「角丸の基本サイズ」（design_radius_md）の表示値を上限でクランプする。
	 *
	 * 上限追加（issue #420）以前に保存された超過値（例: 50）を持つサイトでも、
	 * 表示値を上限でクランプすることで入力欄の value が max 属性を超えないようにする。
	 * これを怠ると、超過値のまま value に出た状態で保存しようとした際に
	 * ブラウザの HTML5 検証（max="32"）がフォーム送信自体をブロックし、
	 * 他の項目も含めて保存できなくなる。
	 *
	 * @param array<string, mixed> $settings 保存済みの設定値配列。
	 * @return int クランプ済みの表示値（未設定・空文字の場合は既定値 8）。
	 */
	private static function clamp_design_radius_md_for_display( array $settings ): int {
		if ( ! isset( $settings['design_radius_md'] ) || '' === $settings['design_radius_md'] ) {
			return 8;
		}

		return min( Settings_Sanitizer::DESIGN_RADIUS_MD_MAX, max( 0, (int) $settings['design_radius_md'] ) );
	}

	/**
	 * 保存済みの design_radius_md が上限を超えている場合、settings_errors() の仕組みで
	 * 警告バナーを表示する。
	 *
	 * 上限（Settings_Sanitizer::DESIGN_RADIUS_MD_MAX = 32px）追加以前から、この設定項目
	 * 自体はリリース済みだった。旧 UI には max 属性が無かったため、既に 32px を超える値
	 * （例: 50）を保存済みのサイトが実在しうる。そのようなサイトでは、
	 * clamp_design_radius_md_for_display() が入力欄の表示値を、
	 * Common_Styles::get_custom_properties() がフロントの描画値をそれぞれ黙って 32px へ
	 * 縮小するため、利用者が気づけないまま見た目が変わって見える。add_settings_error() は
	 * 保存を伴わない、この pageload 限りのメモリ内リストへの追加のため、保存済みの値
	 * そのものは変更しない。次回保存時に Settings_Sanitizer 側のクランプで自動的に
	 * 32px 以下へ収まり、以降はこの通知も出なくなる。
	 *
	 * @param array<string, mixed> $settings 保存済みの設定値配列（get_settings() の生の戻り値）。
	 */
	private static function maybe_notify_design_radius_md_clamped( array $settings ): void {
		if ( ! isset( $settings['design_radius_md'] ) || '' === $settings['design_radius_md'] ) {
			return;
		}

		if ( (int) $settings['design_radius_md'] <= Settings_Sanitizer::DESIGN_RADIUS_MD_MAX ) {
			return;
		}

		add_settings_error(
			self::MENU_SLUG,
			'vkbm_design_radius_md_clamped',
			sprintf(
				/* translators: %d: Maximum allowed value in pixels. */
				__( 'The "Base corner radius" was set to a value larger than the current maximum and has been adjusted to %dpx. Save the settings to persist this value.', 'vk-booking-manager' ),
				Settings_Sanitizer::DESIGN_RADIUS_MD_MAX
			),
			'warning'
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		$settings = $this->settings_service->get_settings();
		self::maybe_notify_design_radius_md_clamped( $settings );

		// オンライン決済が有効かどうか。説明文の出し分けと見直し通知の判定の両方に使うため、1回だけ求める。
		$online_payment_enabled = Payment_Instructions::is_online_payment_enabled();

		// お支払いの案内の見直し通知は「保存されている」案内文で判定する。
		// 後続の処理で入力エラー時の直前入力（transient）により $settings を上書きするため、その前に判定しておく。
		$show_payment_instructions_notice = Payment_Instructions::should_show_legacy_default_notice(
			isset( $settings['provider_payment_method'] ) ? (string) $settings['provider_payment_method'] : '',
			$online_payment_enabled
		);

		$defaults = $this->settings_service->get_default_settings();
		// 無料版では選択欄自体を表示しない（Pro_Upsell::get_feature_notice_html() に差し替える）
		// ため、フィルター適用・翻訳ルックアップを伴う get_presets() を無駄に呼ばない
		// （#387 レビュー指摘・安藤 LOW-8）。
		$presets            = Pro_Upsell::is_free_edition() ? array() : Industry_Presets::get_presets( $defaults );
		$old_input          = get_transient( $this->get_old_input_transient_key() );
		$has_restored_input = is_array( $old_input );
		if ( is_array( $old_input ) ) {
			foreach ( $old_input as $key => $value ) {
				$settings[ $key ] = $value;
			}
			delete_transient( $this->get_old_input_transient_key() );
		}
		$logo_id                          = isset( $settings['provider_logo_id'] ) ? (int) $settings['provider_logo_id'] : 0;
		$booking_cancel_mode              = isset( $settings['provider_booking_cancel_mode'] ) ? (string) $settings['provider_booking_cancel_mode'] : 'hours';
		$booking_complete_message_pending = isset( $settings['provider_booking_complete_message_pending'] ) ? (string) $settings['provider_booking_complete_message_pending'] : '';
		$booking_status_mode_current      = isset( $settings['provider_booking_status_mode'] ) ? (string) $settings['provider_booking_status_mode'] : Settings_Repository::BOOKING_STATUS_MODE_PENDING;
		$booking_cancel_hours             = isset( $settings['provider_booking_cancel_deadline_hours'] ) ? (int) $settings['provider_booking_cancel_deadline_hours'] : 24;

		$regular_holidays = array();
		if ( isset( $settings['provider_regular_holidays'] ) && is_array( $settings['provider_regular_holidays'] ) ) {
			$regular_holidays = array_values( $settings['provider_regular_holidays'] );
		}
		$regular_holidays_disabled = ! empty( $settings['provider_regular_holidays_disabled'] );
		if ( empty( $regular_holidays ) && ! $regular_holidays_disabled ) {
			$regular_holidays[] = array(
				'frequency' => 'weekly',
				'weekday'   => 'mon',
			);
		}

		$weekly_closed_days = $regular_holidays_disabled ? array() : $this->get_weekly_closed_days( $regular_holidays );

		$business_hours_basic = array();
		if ( isset( $settings['provider_business_hours_basic'] ) && is_array( $settings['provider_business_hours_basic'] ) ) {
			$business_hours_basic = $settings['provider_business_hours_basic'];
		}
		$basic_slots      = $this->prepare_basic_business_hours_slots( $business_hours_basic );
		$basic_next_index = $this->get_next_slot_index( $basic_slots );

		$business_hours_weekly = array();
		if ( isset( $settings['provider_business_hours_weekly'] ) && is_array( $settings['provider_business_hours_weekly'] ) ) {
			$business_hours_weekly = $settings['provider_business_hours_weekly'];
		}
		$business_hours_weekly = array_merge(
			$this->get_default_business_hours_weekly(),
			$business_hours_weekly
		);

		$business_day_labels = $this->get_business_hours_day_labels();
		$hour_options        = $this->get_hour_options();
		$end_hour_options    = $this->get_end_hour_options();
		$minute_options      = $this->get_minute_options();
		$field_errors        = get_transient( $this->get_field_errors_transient_key() );

		if ( false === $field_errors || ! is_array( $field_errors ) ) {
			$field_errors = array();
		} else {
			delete_transient( $this->get_field_errors_transient_key() );
		}

		$basic_field_errors           = is_array( $field_errors['basic'] ?? null ) ? $field_errors['basic'] : array();
		$weekly_field_errors          = is_array( $field_errors['weekly'] ?? null ) ? $field_errors['weekly'] : array();
		$mail_from_error              = is_string( $field_errors['mail_from_email'] ?? null ) ? $field_errors['mail_from_email'] : '';
		$mail_from_email              = isset( $settings['mail_from_email'] ) && is_string( $settings['mail_from_email'] ) ? $settings['mail_from_email'] : '';
		$default_mail_from            = Mail_From::resolve_default();
		$default_mail_from_no_reply   = '' !== Mail_From::resolve_default_from_url( network_home_url() );
		$next_holiday_index           = count( $regular_holidays );
		$email_verification_enabled   = ! empty( $settings['registration_email_verification_enabled'] );
		$membership_redirect_enabled  = ! empty( $settings['membership_redirect_wp_register'] );
		$login_redirect_enabled       = ! empty( $settings['membership_redirect_wp_login'] );
		$auth_rate_limit_enabled      = ! empty( $settings['auth_rate_limit_enabled'] );
		$email_log_enabled            = ! empty( $settings['email_log_enabled'] );
		$email_log_retention_days     = isset( $settings['email_log_retention_days'] ) ? (int) $settings['email_log_retention_days'] : 1;
		$email_log_retention_days     = max( 1, $email_log_retention_days );
		$auth_rate_limit_register_max = isset( $settings['auth_rate_limit_register_max'] ) ? (int) $settings['auth_rate_limit_register_max'] : 5;
		$auth_rate_limit_login_max    = isset( $settings['auth_rate_limit_login_max'] ) ? (int) $settings['auth_rate_limit_login_max'] : 10;
		$wp_users_can_register        = (bool) get_option( 'users_can_register' );
		$staff_enabled                = ! empty( $settings['staff_enabled'] );
		$industry_preset              = Industry_Presets::get_current_preset( $settings, $defaults );
		// 業種プリセットで自動設定される項目は、最初の描画から行ごと隠す。
		// JS（provider-settings.js）だけで隠すと、スクリプトはフッター読み込みのため
		// ページ表示から JS 実行までの間、対象行が一瞬そのまま見えてしまう。
		$industry_hidden_fields = self::get_industry_hidden_fields( $presets, $industry_preset );
		// 予約枠の定員（同一枠で複数人を受け入れる）機能の有効/無効（初期表示用）。
		// defaults マージ済みの $settings では新キー slot_capacity_enabled の既定 true が常に存在し、
		// 旧キー multiple_guests_enabled=false（開発DBの既存無効設定）に到達できないため、
		// 新→旧→既定true の判定に加え業種プリセットの強制値も反映する is_slot_capacity_enabled()
		// （内部で Settings_Repository::get_settings() 経由の実効値を参照する）に判定を委譲する。
		// これにより旧キーの無効設定・プリセットによる強制の両方が表示にも正しく反映される。
		$slot_capacity_enabled   = Staff_Editor::is_slot_capacity_enabled();
		$resource_label_singular = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : __( 'Staff', 'vk-booking-manager' );
		$resource_label_plural   = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : __( 'Staff', 'vk-booking-manager' );
		// 無料版の案内の内容。判定は1回だけ行う。
		$free_resource_label_notice = self::get_free_edition_resource_label_notice();
		$resource_label_menu        = isset( $settings['resource_label_menu'] ) ? (string) $settings['resource_label_menu'] : __( 'Staff available', 'vk-booking-manager' );
		// リソース管理メニューのアイコン（Dashicons クラス名）。未設定・不正値はデフォルトへ正規化する。
		$resource_menu_icon         = vkbm_sanitize_resource_menu_icon( $settings['resource_menu_icon'] ?? '' );
		$resource_menu_icon_presets = $this->get_resource_menu_icon_presets();
		$no_nomination_label        = isset( $settings['no_nomination_label'] ) && '' !== trim( (string) $settings['no_nomination_label'] ) ? (string) $settings['no_nomination_label'] : (string) ( $defaults['no_nomination_label'] ?? __( 'No preference', 'vk-booking-manager' ) );
		$nomination_fee_label       = isset( $settings['nomination_fee_label'] ) ? (string) $settings['nomination_fee_label'] : __( 'Nomination fee', 'vk-booking-manager' );
		$duration_label             = isset( $settings['duration_label'] ) ? (string) $settings['duration_label'] : __( 'Time', 'vk-booking-manager' );
		$other_conditions_label     = isset( $settings['other_conditions_label'] ) ? (string) $settings['other_conditions_label'] : __( 'Other conditions', 'vk-booking-manager' );
		// 数量の見出しは保存値（空ならプレースホルダー表示用に空のまま）を表示する。
		$guests_count_label = isset( $settings['guests_count_label'] ) ? (string) $settings['guests_count_label'] : '';
		// 数量の単位のプリフィル値を決める。
		// 検証エラー再表示時は old_input が $settings に上書き済みのため、送信値（空文字含む）を優先して復元する。
		// 通常表示で未保存（null）の場合は実効値（ロケール既定。日本語「名」）をプリフィルし、
		// アップグレード直後にそのまま保存しても従来どおり「名」が維持されるようにする。
		if ( array_key_exists( 'guests_unit_label', $settings ) && null !== $settings['guests_unit_label'] ) {
			$guests_unit_label = (string) $settings['guests_unit_label'];
		} else {
			$guests_unit_label = vkbm_get_guests_unit_label();
		}

		$locale                     = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		$no_plural_locales          = array( 'ja', 'zh', 'ko' );
		$has_plural_forms_in_locale = true;
		foreach ( $no_plural_locales as $prefix ) {
			if ( '' !== $locale && 0 === strpos( $locale, $prefix ) ) {
				$has_plural_forms_in_locale = false;
				break;
			}
		}
		$reservation_deadline_hours        = isset( $settings['provider_reservation_deadline_hours'] ) ? (int) $settings['provider_reservation_deadline_hours'] : 0;
		$max_advance_booking_days          = isset( $settings['provider_max_advance_booking_days'] ) ? (int) $settings['provider_max_advance_booking_days'] : 0;
		$slot_step_minutes                 = isset( $settings['provider_slot_step_minutes'] ) ? (int) $settings['provider_slot_step_minutes'] : 15;
		$service_menu_buffer_after_minutes = isset( $settings['provider_service_menu_buffer_after_minutes'] ) ? (int) $settings['provider_service_menu_buffer_after_minutes'] : 0;
		$booking_status_mode               = isset( $settings['provider_booking_status_mode'] ) ? (string) $settings['provider_booking_status_mode'] : Settings_Repository::BOOKING_STATUS_MODE_PENDING;
		$booking_customer_mode             = Settings_Repository::normalize_booking_customer_mode( $settings['booking_customer_mode'] ?? null );
		$booking_attendee_name_mode        = isset( $settings['booking_attendee_name_mode'] ) ? (string) $settings['booking_attendee_name_mode'] : 'disabled';
		$booking_attendee_name_label       = isset( $settings['booking_attendee_name_label'] ) ? (string) $settings['booking_attendee_name_label'] : '';
		$attendee_roster_label             = isset( $settings['attendee_roster_label'] ) ? (string) $settings['attendee_roster_label'] : '';
		$attendee_roster_kana_label        = isset( $settings['attendee_roster_kana_label'] ) ? (string) $settings['attendee_roster_kana_label'] : '';
		$attendee_number_label             = isset( $settings['attendee_number_label'] ) ? (string) $settings['attendee_number_label'] : '';
		$cancellation_policy               = isset( $settings['provider_cancellation_policy'] ) ? (string) $settings['provider_cancellation_policy'] : '';
		$terms_of_service                  = isset( $settings['provider_terms_of_service'] ) ? (string) $settings['provider_terms_of_service'] : '';
		$privacy_policy_mode               = isset( $settings['provider_privacy_policy_mode'] ) ? sanitize_key( (string) $settings['provider_privacy_policy_mode'] ) : 'none';
		$privacy_policy_url                = isset( $settings['provider_privacy_policy_url'] ) ? (string) $settings['provider_privacy_policy_url'] : '';
		$privacy_policy_content            = isset( $settings['provider_privacy_policy_content'] ) ? (string) $settings['provider_privacy_policy_content'] : '';
		$payment_method                    = isset( $settings['provider_payment_method'] ) ? (string) $settings['provider_payment_method'] : '';
		if ( ! in_array( $privacy_policy_mode, array( 'none', 'url', 'content' ), true ) ) {
			$privacy_policy_mode = 'none';
		}
		$service_menu_detail_display_mode = isset( $settings[ Settings_Repository::KEY_SERVICE_MENU_DETAIL_DISPLAY_MODE ] ) ? sanitize_key( (string) $settings[ Settings_Repository::KEY_SERVICE_MENU_DETAIL_DISPLAY_MODE ] ) : Settings_Repository::DETAIL_DISPLAY_MODE_TEMPLATE;
		if ( ! in_array( $service_menu_detail_display_mode, Settings_Repository::DETAIL_DISPLAY_MODES, true ) ) {
			$service_menu_detail_display_mode = Settings_Repository::DETAIL_DISPLAY_MODE_TEMPLATE;
		}

		// 「予約ブロック内で表示」は、予約ページURLが自サイトのURLとして設定されているときだけ働く。
		$is_reservation_page_url_usable = '' !== Service_Menu_Detail_Link::resolve_usable_reservation_page_url( (string) ( $settings['reservation_page_url'] ?? '' ) );

		$reservation_menu_list_display_mode = isset( $settings['reservation_menu_list_display_mode'] ) ? sanitize_key( (string) $settings['reservation_menu_list_display_mode'] ) : 'card';
		$shift_alert_months                 = isset( $settings['shift_alert_months'] ) ? (int) $settings['shift_alert_months'] : 1;
		$shift_auto_register_months         = isset( $settings['shift_auto_register_months'] ) ? (int) $settings['shift_auto_register_months'] : 0;
		$booking_auto_anonymize_months      = isset( $settings['booking_auto_anonymize_months'] ) ? (int) $settings['booking_auto_anonymize_months'] : 0;
		$uninstall_cleanup                  = Uninstaller::normalize_mode( $settings['uninstall_cleanup'] ?? Uninstaller::MODE_KEEP );
		$show_uninstall_cleanup             = current_user_can( 'manage_options' );
		$booking_reminder_hours             = $settings['booking_reminder_hours'] ?? array();
		if ( ! is_array( $booking_reminder_hours ) ) {
			$booking_reminder_hours = array();
		}
		$booking_reminder_hours      = array_values(
			array_filter(
				array_map(
					static function ( $value ): int {
						return absint( $value );
					},
					$booking_reminder_hours
				),
				static function ( int $value ): bool {
					return $value > 0;
				}
			)
		);
		$booking_reminder_rows       = $booking_reminder_hours;
		$booking_reminder_next_index = count( $booking_reminder_rows );
		if ( array() === $booking_reminder_rows ) {
			$booking_reminder_rows       = array( '' );
			$booking_reminder_next_index = 1;
		}
		$design_primary_color             = isset( $settings['design_primary_color'] ) ? (string) $settings['design_primary_color'] : '';
		$design_menu_card_catch_color     = isset( $settings['design_menu_card_catch_color'] ) ? (string) $settings['design_menu_card_catch_color'] : '';
		$design_reservation_button_color  = isset( $settings['design_reservation_button_color'] ) ? (string) $settings['design_reservation_button_color'] : '';
		$design_menu_card_price_color     = isset( $settings['design_menu_card_price_color'] ) ? (string) $settings['design_menu_card_price_color'] : '';
		$design_footer_background_color   = isset( $settings['design_footer_background_color'] ) ? (string) $settings['design_footer_background_color'] : '';
		$design_footer_text_color         = isset( $settings['design_footer_text_color'] ) ? (string) $settings['design_footer_text_color'] : '';
		$design_header_logo_width         = isset( $settings['design_header_logo_width'] ) ? (string) $settings['design_header_logo_width'] : '';
		$design_menu_search_style         = isset( $settings['design_menu_search_style'] ) ? (string) $settings['design_menu_search_style'] : Settings_Sanitizer::DESIGN_MENU_SEARCH_STYLE_DEFAULT;
		$design_menu_search_style_choices = Settings_Sanitizer::get_design_menu_search_style_choices();
		$design_radius_md                 = self::clamp_design_radius_md_for_display( $settings );
		$currency_symbol                  = isset( $settings['currency_symbol'] ) ? (string) $settings['currency_symbol'] : '';
		$tax_label_text                   = isset( $settings['tax_label_text'] ) ? (string) $settings['tax_label_text'] : '';
		$currency_placeholder             = ( '' !== $locale && 0 === strpos( $locale, 'ja' ) ) ? '¥' : '$';
		$has_saved_license_key            = '' !== (string) get_option( 'vk-booking-manager-pro-license-key', '' );
		$is_pro_edition                   = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		$show_license_tab                 = $is_pro_edition && current_user_can( 'manage_options' );
		// 「連携」タブ（Google カレンダー連携）は Pro 版限定（issue #475）。
		$show_integration_tab = $this->show_integration_tab();
		if ( ! in_array( $reservation_menu_list_display_mode, array( 'card', 'text' ), true ) ) {
			$reservation_menu_list_display_mode = 'card';
		}
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI state.
		$allowed_tabs = array( 'store', 'system', 'attendees', 'email', 'registration', 'consent', 'design', 'advanced', 'faq' );
		if ( $show_integration_tab ) {
			$allowed_tabs[] = 'integration';
		}
		if ( $show_license_tab ) {
			$allowed_tabs[] = 'license';
		}
		// 本体のタブ一覧（拡張プラグインが追加したタブを含まない）を、フィルターを通す前に控えておく。
		// 本体の保存フォーム（「変更を保存」ボタン）は本体のタブでだけ出力するために使う。
		$core_tabs = $allowed_tabs;
		// このフィルターの説明は handle_form_submission() 内の vkbm_provider_settings_allowed_tabs を参照。
		$allowed_tabs = apply_filters( 'vkbm_provider_settings_allowed_tabs', $allowed_tabs );
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'store';
		}
		// 拡張プラグインのタブでは、本体の保存フォームを出さない（拡張プラグインは独自のフォームを
		// vkbm_provider_settings_extra_content で出力するため。「連携」タブ（issue #475）と同じ考え方）。
		$is_core_tab = in_array( $active_tab, $core_tabs, true );

		$base_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap vkbm-provider-settings" data-active-tab="<?php echo esc_attr( $active_tab ); ?>" data-has-restored-input="<?php echo esc_attr( $has_restored_input ? '1' : '0' ); ?>">
			<h1><?php esc_html_e( 'Basic settings', 'vk-booking-manager' ); ?></h1>
			<?php
			// 無料版の場合はプロ版への誘導バナーを表示する（Pro 版では空文字）。
			echo Pro_Upsell::get_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
			?>
			<h2 class="nav-tab-wrapper vkbm-provider-settings__tabs" aria-label="<?php esc_attr_e( 'Settings tab', 'vk-booking-manager' ); ?>">
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'store', $base_url ) ); ?>"
					class="nav-tab<?php echo 'store' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Business information', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'system', $base_url ) ); ?>"
					class="nav-tab<?php echo 'system' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'System settings', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'attendees', $base_url ) ); ?>"
					class="nav-tab<?php echo 'attendees' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'People receiving the service', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'email', $base_url ) ); ?>"
					class="nav-tab<?php echo 'email' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Email settings', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'registration', $base_url ) ); ?>"
					class="nav-tab<?php echo 'registration' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'User registration settings', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'consent', $base_url ) ); ?>"
					class="nav-tab<?php echo 'consent' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'User consent items', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'design', $base_url ) ); ?>"
					class="nav-tab<?php echo 'design' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'design settings', 'vk-booking-manager' ); ?>
				</a>
				<?php
				/**
				 * 設定ページのタブナビゲーションに追加タブリンクを挿入する拡張ポイント。
				 * デザインタブの直後・FAQ タブの直前に発火する。
				 * 拡張プラグインはここに <a class="nav-tab"> 要素を出力する。
				 *
				 * @param string $active_tab 表示中のタブスラッグ。
				 * @param string $base_url   タブのリンク URL を組み立てるための基準 URL。
				 */
				do_action( 'vkbm_provider_settings_extra_tabs', $active_tab, $base_url );
				?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'faq', $base_url ) ); ?>"
					class="nav-tab<?php echo 'faq' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'FAQ', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'advanced', $base_url ) ); ?>"
					class="nav-tab<?php echo 'advanced' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Advanced settings', 'vk-booking-manager' ); ?>
				</a>
				<?php if ( $show_integration_tab ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'integration', $base_url ) ); ?>"
					class="nav-tab<?php echo 'integration' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Integration', 'vk-booking-manager' ); ?>
				</a>
				<?php endif; ?>
				<?php if ( $show_license_tab ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'license', $base_url ) ); ?>"
					class="nav-tab<?php echo 'license' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'License', 'vk-booking-manager' ); ?>
				</a>
				<?php endif; ?>
			</h2>
			<?php settings_errors( self::MENU_SLUG ); ?>
			<?php
			// カード決済が有効なのに、お支払いの案内が旧初期値のままのときは、どのタブでも上部に見直しを促す通知を出す（#540）。
			if ( $show_payment_instructions_notice ) {
				echo self::get_payment_instructions_page_notice_html( add_query_arg( 'tab', 'store', $base_url ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
			}
			?>

			<?php if ( 'integration' === $active_tab && $show_integration_tab ) : ?>
				<?php
				// 「連携」タブは、他タブと共有している保存フォームの外に置く。共有フォームの中に置くと、
				// 連携のボタンを押した時点で別タブの入力内容まで保存されてしまうため（issue #475）。
				$this->google_calendar_panel->render();
				?>
			<?php elseif ( $is_core_tab ) : ?>
			<form class="vkbm-provider-settings__form" method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="vkbm-provider-settings__tab-store vkbm-industry-preset-row">
							<th scope="row">
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php
									// 無料版では対応する <select> 自体を描画しないため、存在しない id を
									// 指す for 属性を持たせない（#387 UXレビュー指摘）。
									esc_html_e( 'Industry preset', 'vk-booking-manager' );
									?>
								<?php else : ?>
									<label for="vkbm-industry-preset"><?php esc_html_e( 'Industry preset', 'vk-booking-manager' ); ?></label>
								<?php endif; ?>
							</th>
							<td>
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php
									// 無料版では custom 固定とし、選択欄の代わりに Pro 版への案内を表示する。
									echo Pro_Upsell::get_feature_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
										__( 'Industry presets are available in the Pro edition.', 'vk-booking-manager' )
									);
									?>
								<?php else : ?>
									<select id="vkbm-industry-preset" name="vkbm_provider_settings[industry_preset]">
										<?php foreach ( $presets as $preset_key => $preset ) : ?>
											<option value="<?php echo esc_attr( (string) $preset_key ); ?>" <?php selected( $industry_preset, $preset_key ); ?>>
												<?php echo esc_html( (string) ( $preset['label'] ?? $preset_key ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'When you select an industry, only the items commonly used in that industry are displayed, with representative values entered in advance.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Other items are configured automatically in the "System settings" tab.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Save the industry preset to apply the changes to that tab.', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'Select "Custom" to configure all items individually (initial state).', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'If you select "Lodging", you can create plans by the night in service menus, and customers choose a check-in date, check-out date, and room on the reservation screen.', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'If you switch from "Lodging" to another industry, plans set by the night are handled as time slot plans.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Days that were set as sale days in "Lodging" have no time slots, so no vacancies appear for time slot plans until you set the time slots in the shift of each month.', 'vk-booking-manager' ); ?></p>
									<div class="vkbm-industry-preset-summary" hidden>
										<button type="button" class="button-link vkbm-industry-preset-summary__toggle" aria-expanded="false" aria-controls="vkbm-industry-preset-summary-content"></button>
										<div id="vkbm-industry-preset-summary-content" class="vkbm-industry-preset-summary__content" hidden>
											<dl class="vkbm-industry-preset-summary__list"></dl>
											<p class="description"><?php esc_html_e( 'These items are automatically saved with these values and cannot be changed individually in the "System settings" tab.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Select "Custom" to configure them individually.', 'vk-booking-manager' ); ?></p>
										</div>
									</div>
									<div class="screen-reader-text" id="vkbm-industry-preset-live" aria-live="polite" aria-atomic="true"></div>
								<?php endif; ?>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<label for="vkbm-provider-name"><?php esc_html_e( 'Business name', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-provider-name"
									name="vkbm_provider_settings[provider_name]"
									value="<?php echo esc_attr( $settings['provider_name'] ?? '' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'This is the name shown to customers.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'Enter a store name, facility name, trade name, or similar.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'If you operate as an individual, your personal name is also acceptable.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'It is used as the sender name of notification emails and in the header.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<label for="vkbm-provider-address"><?php esc_html_e( 'Address', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="3"
									id="vkbm-provider-address"
									name="vkbm_provider_settings[provider_address]"
								><?php echo esc_textarea( $settings['provider_address'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Please enter the prefecture, city, town, street address, building name, etc. all at once.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<label for="vkbm-provider-phone"><?php esc_html_e( 'Main phone number', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="tel"
									class="regular-text"
									id="vkbm-provider-phone"
									name="vkbm_provider_settings[provider_phone]"
									value="<?php echo esc_attr( $settings['provider_phone'] ?? '' ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'It will be displayed in the signature of the notification email and the contact information.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<label for="vkbm-provider-business-hours"><?php esc_html_e( 'Business hours text', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="6"
									id="vkbm-provider-business-hours"
									name="vkbm_provider_settings[provider_business_hours]"
								><?php echo esc_textarea( $settings['provider_business_hours'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'This will be displayed in the signature of notification emails, etc.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<?php
								// 予約確認画面の見出し「決済方法」（Payment method）とは別の文字列にしている（#540）。
								?>
								<label for="vkbm-provider-payment-method"><?php esc_html_e( 'Payment instructions', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="4"
									id="vkbm-provider-payment-method"
									name="vkbm_provider_settings[provider_payment_method]"
									aria-describedby="<?php echo esc_attr( $show_payment_instructions_notice ? 'vkbm-provider-payment-method-notice vkbm-provider-payment-method-description' : 'vkbm-provider-payment-method-description' ); ?>"
								><?php echo esc_textarea( $payment_method ); ?></textarea>
								<?php
								// 旧初期値のままのときは、入力欄のすぐ下にも見直しを促す注意を出す（#540）。
								if ( $show_payment_instructions_notice ) {
									echo self::get_payment_instructions_field_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
								}
								?>
								<p class="description" id="vkbm-provider-payment-method-description"><?php echo esc_html( self::get_payment_instructions_description( $online_payment_enabled ) ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<?php esc_html_e( 'Regular holiday', 'vk-booking-manager' ); ?>
							</th>
							<td>
								<label>
									<input
										type="checkbox"
										id="vkbm-regular-holiday-disabled"
										name="vkbm_provider_settings[provider_regular_holidays_disabled]"
										value="1"
										<?php checked( $regular_holidays_disabled ); ?>
									/>
									<?php esc_html_e( 'No regular holidays', 'vk-booking-manager' ); ?>
								</label>
								<div id="vkbm-regular-holiday-settings" <?php echo $regular_holidays_disabled ? 'hidden' : ''; ?>>
									<table class="vkbm-setting-table vkbm-regular-holidays-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'period', 'vk-booking-manager' ); ?></th>
											<th><?php esc_html_e( 'day of week', 'vk-booking-manager' ); ?></th>
											<th class="column-actions"><?php esc_html_e( 'operation', 'vk-booking-manager' ); ?></th>
										</tr>
									</thead>
									<tbody id="vkbm-regular-holiday-rows">
										<?php foreach ( $regular_holidays as $index => $holiday ) : ?>
											<?php
											$frequency_value = isset( $holiday['frequency'] ) ? (string) $holiday['frequency'] : 'weekly';
											$weekday_value   = isset( $holiday['weekday'] ) ? (string) $holiday['weekday'] : 'mon';
											// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within method.
											echo $this->render_regular_holiday_row(
												(string) $index,
												array(
													'frequency' => $frequency_value,
													'weekday'   => $weekday_value,
												)
											);
											// phpcs:enable
											?>
										<?php endforeach; ?>
									</tbody>
								</table>
								<input type="hidden" id="vkbm-regular-holiday-next-index" value="<?php echo esc_attr( (string) $next_holiday_index ); ?>" />
								<button type="button" class="button button-secondary" id="vkbm-regular-holiday-add">
									<?php esc_html_e( 'Add regular holidays', 'vk-booking-manager' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Register regular holidays by combining "every week", "1st to 5th", and days of the week.', 'vk-booking-manager' ); ?></p>
								<template id="vkbm-regular-holiday-row-template">
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Output used as template.
									echo $this->render_regular_holiday_row(
										'__INDEX__',
										array(
											'frequency' => 'weekly',
											'weekday'   => 'mon',
										)
									);
									// phpcs:enable
									?>
								</template>
								</div>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-store" id="vkbm-basic-business-hours">
								<th scope="row">
									<?php esc_html_e( 'Basic business hours', 'vk-booking-manager' ); ?>
								</th>
							<td>
								<div class="vkbm-basic-business-hours">
										<div
										id="vkbm-business-hours-basic-slots"
										class="vkbm-business-hours-slot-list vkbm-schedule-slot-list"
										data-scope="basic"
									>
				<?php foreach ( $basic_slots as $slot_values ) : ?>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within method.
					echo $this->render_basic_business_hours_slot(
						(string) $slot_values['index'],
						$slot_values,
						$hour_options,
						$end_hour_options,
						$minute_options,
						$basic_field_errors
					);
					// phpcs:enable
					?>
				<?php endforeach; ?>
									</div>
									<input type="hidden" id="vkbm-business-hours-basic-next-index" value="<?php echo esc_attr( (string) $basic_next_index ); ?>" />
									<button type="button" class="button button-secondary vkbm-business-hours-basic-add-slot vkbm-schedule-add-slot">
										<?php esc_html_e( 'Add time zone', 'vk-booking-manager' ); ?>
									</button>
				<template id="vkbm-business-hours-basic-slot-template">
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Output used as template.
					echo $this->render_basic_business_hours_slot(
						'__INDEX__',
						array(
							'index'        => '__INDEX__',
							'start_hour'   => '',
							'start_minute' => '',
							'end_hour'     => '',
							'end_minute'   => '',
						),
						$hour_options,
						$end_hour_options,
						$minute_options,
						array()
					);
					// phpcs:enable
					?>
				</template>
									<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
										<p class="description"><?php esc_html_e( 'This setting is not used for the availability of lodging plans.', 'vk-booking-manager' ); ?><?php esc_html_e( ' If you also use time slot plans, such as day-use baths or private baths, register the time ranges here.', 'vk-booking-manager' ); ?><?php esc_html_e( ' The registered time ranges become the reservation slots of the time slot plans.', 'vk-booking-manager' ); ?></p>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'Register the business hours that are common to the entire facility.', 'vk-booking-manager' ); ?></p>
									<?php endif; ?>
								</div>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-store">
								<th scope="row">
									<?php esc_html_e( 'Business hours by day of the week', 'vk-booking-manager' ); ?>
								</th>
							<td>
								<table class="vkbm-setting-table vkbm-business-hours-table vkbm-schedule-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'subject', 'vk-booking-manager' ); ?></th>
											<th class="column-use-basic"><?php esc_html_e( 'Basic business hours', 'vk-booking-manager' ); ?></th>
											<th><?php esc_html_e( 'Individual time period', 'vk-booking-manager' ); ?></th>
										</tr>
									</thead>
									<tbody>
				<?php foreach ( $business_day_labels as $day_key => $day_label ) : ?>
					<?php
					$day_settings                           = isset( $business_hours_weekly[ $day_key ] ) && is_array( $business_hours_weekly[ $day_key ] )
					? $business_hours_weekly[ $day_key ]
					: array(
						'use_custom' => false,
						'time_slots' => array(),
					);
					$use_custom                             = ! empty( $day_settings['use_custom'] );
					$slots_for_day                          = $this->prepare_weekly_business_hours_slots( $day_settings, $basic_slots, $use_custom );
					$next_slot_index                        = $this->get_next_slot_index( $slots_for_day );
					$is_regular_holiday                     = ! empty( $weekly_closed_days[ $day_key ] );
					$day_field_errors                       = is_array( $weekly_field_errors[ $day_key ] ?? null ) ? $weekly_field_errors[ $day_key ] : array();
					$row_classes                            = array(
						'vkbm-business-hours-row',
						$is_regular_holiday ? 'is-regular-holiday' : '',
						! $use_custom ? 'is-using-basic' : '',
					);
											$row_class_attr = implode( ' ', array_filter( $row_classes ) );
											$checkbox_id    = 'vkbm-business-hours-use-basic-' . $day_key;
					?>
											<tr class="<?php echo esc_attr( $row_class_attr ); ?>" data-day="<?php echo esc_attr( $day_key ); ?>">
												<th scope="row">
													<?php echo esc_html( $day_label ); ?>
													<?php if ( $is_regular_holiday ) : ?>
														<span class="vkbm-business-hours-note"><?php esc_html_e( 'Regular holidays (every week)', 'vk-booking-manager' ); ?></span>
													<?php endif; ?>
												</th>
												<td class="column-use-basic">
				<input type="hidden" name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][use_custom]" value="0" />
													<label for="<?php echo esc_attr( $checkbox_id ); ?>">
					<input
						type="checkbox"
						class="vkbm-business-hours-use-custom"
						id="<?php echo esc_attr( $checkbox_id ); ?>"
						name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][use_custom]"
						value="1"
						<?php checked( $use_custom ); ?>
						<?php disabled( $is_regular_holiday ); ?>
					/>
					<?php esc_html_e( 'Specify individual business hours', 'vk-booking-manager' ); ?>
													</label>
													<?php if ( $is_regular_holiday ) : ?>
														<p class="description"><?php esc_html_e( 'Since it is registered as a weekly regular holiday, it will be treated as closed.', 'vk-booking-manager' ); ?></p>
													<?php endif; ?>
												</td>
												<td class="column-slots">
													<div
														class="vkbm-business-hours-slot-list vkbm-schedule-slot-list"
														data-day="<?php echo esc_attr( $day_key ); ?>"
														data-basic-slots="<?php echo esc_attr( wp_json_encode( array_values( $basic_slots ) ) ); ?>"
													>
							<?php foreach ( $slots_for_day as $slot_values ) : ?>
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within method.
								echo $this->render_weekly_business_hours_slot(
									$day_key,
									(string) $slot_values['index'],
									$day_label,
									$slot_values,
									$hour_options,
									$end_hour_options,
									$minute_options,
									$is_regular_holiday || ! $use_custom,
									$day_field_errors
								);
								// phpcs:enable
								?>
							<?php endforeach; ?>
						</div>
													<input type="hidden" class="vkbm-business-hours-next-slot-index" value="<?php echo esc_attr( (string) $next_slot_index ); ?>" />
					<button
						type="button"
						class="button button-secondary vkbm-business-hours-add-slot vkbm-schedule-add-slot"
						<?php disabled( ! $use_custom || $is_regular_holiday ); ?>
					>
														<?php esc_html_e( 'Add time zone', 'vk-booking-manager' ); ?>
													</button>
													<template class="vkbm-business-hours-slot-template">
							<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Output used as template.
								echo $this->render_weekly_business_hours_slot(
									$day_key,
									'__INDEX__',
									$day_label,
									array(
										'index'        => '__INDEX__',
										'start_hour'   => '',
										'start_minute' => '',
										'end_hour'     => '',
										'end_minute'   => '',
									),
									$hour_options,
									$end_hour_options,
									$minute_options,
									$is_regular_holiday || ! $use_custom,
									array()
								);
								// phpcs:enable // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output used as template.
							?>
							</template>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<?php
								// 1つの __() に複数の文を入れないよう、文ごとに分けてから連結する。
								// 2文目は英語表示時のみ先頭に半角スペースが要るため、msgid 側に含めている。
								$is_lodging        = Industry_Presets::supports( 'lodging' );
								$shift_screen_link = sprintf(
									'<a href="%1$s">%2$s</a>',
									esc_url( admin_url( 'edit.php?post_type=' . \VKBookingManager\PostTypes\Shift_Post_Type::POST_TYPE ) ),
									esc_html( $is_lodging ? __( 'days on sale screen', 'vk-booking-manager' ) : __( 'shift screen', 'vk-booking-manager' ) )
								);

								$holiday_rows_description = $is_lodging
									? __( 'The settings in the holiday and day-before-holiday rows are not currently used when creating days on sale.', 'vk-booking-manager' )
									: __( 'The settings in the holiday and day-before-holiday rows are not currently used when creating shifts.', 'vk-booking-manager' );

								$holiday_rows_description .= $is_lodging
									? __( ' Days on sale for holidays and days before holidays are created using the regular weekday hours.', 'vk-booking-manager' )
									: __( ' Holidays and days before holidays follow the regular weekday hours.', 'vk-booking-manager' );

								$holiday_rows_description .= sprintf(
									/* translators: %s: link to the shift or days on sale screen. */
									__( ' To change the hours for a specific day, set them on the %s.', 'vk-booking-manager' ),
									$shift_screen_link
								);
								?>
								<p class="description"><?php echo wp_kses( $holiday_rows_description, array( 'a' => array( 'href' => array() ) ) ); ?></p>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-store">
								<th scope="row">
									<label for="vkbm-provider-website-url"><?php esc_html_e( 'Website URL', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<input
									type="url"
									class="regular-text"
									id="vkbm-provider-website-url"
									name="vkbm_provider_settings[provider_website_url]"
									value="<?php echo esc_attr( $settings['provider_website_url'] ?? '' ); ?>"
									placeholder="https://"
								/>
								<p class="description"><?php esc_html_e( 'Use it as a link in notification emails and on the reservation completion screen.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system">
								<th scope="row">
									<label for="vkbm-reservation-page-url"><?php esc_html_e( 'Reservation page URL', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<input
									type="url"
									class="regular-text"
									id="vkbm-reservation-page-url"
									name="vkbm_provider_settings[reservation_page_url]"
									value="<?php echo esc_attr( $settings['reservation_page_url'] ?? '' ); ?>"
									placeholder="https://"
								/>
								<p class="description">
									<?php esc_html_e( 'Place a "reservation block" on the fixed page you want to use as a reservation page, and then enter the public URL of that fixed page.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row" id="vkbm-reservation-display-elements" class="vkbm-provider-settings__link-target"><?php esc_html_e( 'Reservation page display elements', 'vk-booking-manager' ); ?></th>
								<td>
									<div class="vkbm-provider-settings__checkbox-stack">
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-reservation-show-provider-logo"
												name="vkbm_provider_settings[reservation_show_provider_logo]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_provider_logo'] ) ); ?>
											/>
											<?php esc_html_e( 'logo image', 'vk-booking-manager' ); ?>
										</label>
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-reservation-show-provider-name"
												name="vkbm_provider_settings[reservation_show_provider_name]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_provider_name'] ) ); ?>
											/>
											<?php esc_html_e( 'Business name', 'vk-booking-manager' ); ?>
										</label>
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-reservation-show-menu-search"
												name="vkbm_provider_settings[reservation_show_menu_search]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_menu_search'] ) ); ?>
											/>
											<?php esc_html_e( 'Filter search', 'vk-booking-manager' ); ?>
										</label>
										<?php // 「日付で絞り込む」は「絞り込み検索」の子項目のため、インデントして表示する。無料版・Pro 版の両方で使える。 ?>
										<label class="vkbm-inline-checkbox vkbm-inline-checkbox--child">
											<input
												type="checkbox"
												id="vkbm-reservation-date-search-enabled"
												name="vkbm_provider_settings[reservation_date_search_enabled]"
												value="1"
												<?php checked( ! empty( $settings['reservation_date_search_enabled'] ) ); ?>
											/>
											<?php esc_html_e( 'Filter by date', 'vk-booking-manager' ); ?>
										</label>
										<?php if ( $is_pro_edition ) : ?>
											<?php // #431: 「リソースタグ検索」は「絞り込み検索」の子項目のため、インデントして表示する。 ?>
											<label class="vkbm-inline-checkbox vkbm-inline-checkbox--child">
												<input
													type="checkbox"
													id="vkbm-resource-tag-search-enabled"
													name="vkbm_provider_settings[resource_tag_search_enabled]"
													value="1"
													<?php checked( ! empty( $settings['resource_tag_search_enabled'] ) ); ?>
												/>
												<?php esc_html_e( 'Resource tag search', 'vk-booking-manager' ); ?>
											</label>
										<?php endif; ?>
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-reservation-show-menu-list"
												name="vkbm_provider_settings[reservation_show_menu_list]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_menu_list'] ) ); ?>
											/>
											<?php esc_html_e( 'Service menu list', 'vk-booking-manager' ); ?>
										</label>
										<?php // #633: 店舗情報と規約リンクを予約ブロックの末尾に表示する。 ?>
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-reservation-show-footer"
												name="vkbm_provider_settings[reservation_show_footer]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_footer'] ) ); ?>
											/>
											<?php esc_html_e( 'Footer', 'vk-booking-manager' ); ?>
										</label>
									</div>
									<ul class="description vkbm-provider-settings__description-list">
										<li>
											<?php esc_html_e( 'If neither "Filter search" nor "Service menu list" is checked, only the filter search (selecting the menu, etc. from dropdowns) will be displayed.', 'vk-booking-manager' ); ?>
										</li>
										<li>
											<?php
											/* translators: Prefix label shown before the description of the "Filter by date" checkbox. Includes the colon separator. */
											esc_html_e( 'Filter by date:', 'vk-booking-manager' );
											?>
											<?php esc_html_e( 'Adds a date field to the filter search. Only the service menus that can be booked on the selected date are shown in the list and the menu dropdown.', 'vk-booking-manager' ); ?>
											<?php esc_html_e( 'On lodging sites, the check-in date, check-out date, and number of nights fields are displayed instead of the date field.', 'vk-booking-manager' ); ?>
										</li>
										<li>
											<?php
											/* translators: Prefix label shown before the description of the "Footer" checkbox. Includes the colon separator. */
											esc_html_e( 'Footer:', 'vk-booking-manager' );
											?>
											<?php esc_html_e( 'Displays the business information (business name, address, phone number, and business hours text) and links to the cancellation policy, system terms of use, and privacy policy at the end of the reservation block.', 'vk-booking-manager' ); ?>
											<?php esc_html_e( 'When the website URL differs from the reservation page URL, a "Website" link is also displayed at the left end of the links.', 'vk-booking-manager' ); ?>
											<br />
											<?php
											printf(
												/* translators: 1: link to the "Business information" tab, 2: link to the "User consent items" tab. */
												esc_html__( 'The displayed content uses the values entered in %1$s and %2$s.', 'vk-booking-manager' ),
												'<a href="' . esc_url( add_query_arg( 'tab', 'store', $base_url ) ) . '">' . esc_html__( 'Business information', 'vk-booking-manager' ) . '</a>',
												'<a href="' . esc_url( add_query_arg( 'tab', 'consent', $base_url ) ) . '">' . esc_html__( 'User consent items', 'vk-booking-manager' ) . '</a>'
											);
											?>
											<?php
											printf(
												/* translators: %s: link to the footer color settings on the "design settings" tab. */
												esc_html__( 'The background color and text color can be changed on the %s tab.', 'vk-booking-manager' ),
												'<a href="' . esc_url( add_query_arg( 'tab', 'design', $base_url ) . '#vkbm-design-footer-colors' ) . '">' . esc_html__( 'design settings', 'vk-booking-manager' ) . '</a>'
											);
											?>
										</li>
									</ul>
							</td>
						</tr>

								<?php if ( 'system' === $active_tab ) : ?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-reservation-menu-list-display-mode-row" <?php echo ! empty( $settings['reservation_show_menu_list'] ) ? '' : 'hidden'; ?>>
										<th scope="row">
											<label for="vkbm-reservation-menu-list-display-mode"><?php esc_html_e( 'Service menu display mode', 'vk-booking-manager' ); ?></label>
										</th>
										<td>
											<select
												id="vkbm-reservation-menu-list-display-mode"
												name="vkbm_provider_settings[reservation_menu_list_display_mode]"
											>
												<option value="card" <?php selected( $reservation_menu_list_display_mode, 'card' ); ?>>
													<?php esc_html_e( 'card', 'vk-booking-manager' ); ?>
												</option>
												<option value="text" <?php selected( $reservation_menu_list_display_mode, 'text' ); ?>>
													<?php esc_html_e( 'text', 'vk-booking-manager' ); ?>
												</option>
											</select>
										</td>
									</tr>
								<?php endif; ?>

								<?php if ( 'system' === $active_tab ) : ?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-service-menu-detail-display-mode-row">
										<th scope="row">
											<label for="vkbm-service-menu-detail-display-mode"><?php esc_html_e( 'Service menu detail page display method', 'vk-booking-manager' ); ?></label>
										</th>
										<td>
											<select
												id="vkbm-service-menu-detail-display-mode"
												name="vkbm_provider_settings[service_menu_detail_display_mode]"
												aria-describedby="vkbm-service-menu-detail-display-mode-help"
											>
												<option value="block" <?php selected( $service_menu_detail_display_mode, 'block' ); ?>>
													<?php esc_html_e( 'Display within the reservation block', 'vk-booking-manager' ); ?>
												</option>
												<option value="template" <?php selected( $service_menu_detail_display_mode, 'template' ); ?>>
													<?php esc_html_e( 'Display as a WordPress individual post', 'vk-booking-manager' ); ?>
												</option>
											</select>
											<dl class="vkbm-detail-display-mode-help" id="vkbm-service-menu-detail-display-mode-help">
												<dt><?php esc_html_e( 'Display within the reservation block', 'vk-booking-manager' ); ?></dt>
												<dd>
													<?php esc_html_e( 'The detail page of a service menu is displayed inside the reservation block of the reservation page.', 'vk-booking-manager' ); ?><br>
													<?php esc_html_e( 'Customers can proceed to the reservation screen without leaving the reservation page.', 'vk-booking-manager' ); ?><br>
													<?php esc_html_e( 'The reservation page URL must be set.', 'vk-booking-manager' ); ?>
												</dd>
												<dt><?php esc_html_e( 'Display as a WordPress individual post', 'vk-booking-manager' ); ?></dt>
												<dd>
													<?php esc_html_e( 'The detail page of a service menu is displayed as an individual post of WordPress.', 'vk-booking-manager' ); ?><br>
													<?php esc_html_e( 'The appearance depends on the post template of your theme.', 'vk-booking-manager' ); ?>
												</dd>
											</dl>
											<p class="description"><?php esc_html_e( 'If "Use" is turned off for "Details page" of a service menu, the detail page of that service menu is not displayed in either method.', 'vk-booking-manager' ); ?></p>
											<?php if ( ! $is_reservation_page_url_usable ) : ?>
												<?php // 「予約ブロック内で表示」を選んでいるときだけ見せる（選択の切り替えには assets/js/provider-settings.js が追従する）。 ?>
												<p class="description vkbm-detail-display-mode-notice" id="vkbm-service-menu-detail-display-mode-notice" <?php echo 'block' === $service_menu_detail_display_mode ? '' : 'hidden'; ?>>
													<?php esc_html_e( 'Please set the reservation page URL to a URL of this site.', 'vk-booking-manager' ); ?>
													<?php esc_html_e( 'Until then, service menus are displayed as individual posts of WordPress even if "Display within the reservation block" is selected.', 'vk-booking-manager' ); ?>
													<a href="#vkbm-reservation-page-url"><?php esc_html_e( 'Set the reservation page URL', 'vk-booking-manager' ); ?></a>
												</p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endif; ?>

								<?php if ( 'system' === $active_tab ) : ?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-currency-symbol-row">
										<th scope="row">
											<label for="vkbm-currency-symbol"><?php esc_html_e( 'Currency symbol', 'vk-booking-manager' ); ?></label>
										</th>
										<td>
											<input
												type="text"
												class="regular-text"
												id="vkbm-currency-symbol"
												name="vkbm_provider_settings[currency_symbol]"
												value="<?php echo esc_attr( $currency_symbol ); ?>"
												placeholder="<?php echo esc_attr( $currency_placeholder ); ?>"
											/>
											<p class="description"><?php esc_html_e( 'Enter the currency symbol to display with prices (e.g., $, ¥, €). If left empty, the default symbol based on the site language will be used.', 'vk-booking-manager' ); ?></p>
										</td>
									</tr>
								<?php endif; ?>

								<?php if ( 'system' === $active_tab ) : ?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-tax-label-text-row">
										<th scope="row">
											<label for="vkbm-tax-label-text"><?php esc_html_e( 'Tax label text', 'vk-booking-manager' ); ?></label>
										</th>
										<td>
											<input
												type="text"
												class="regular-text"
												id="vkbm-tax-label-text"
												name="vkbm_provider_settings[tax_label_text]"
												value="<?php echo esc_attr( $tax_label_text ); ?>"
											/>
											<p class="description"><?php esc_html_e( 'Shown to the right of prices only when this field is filled (e.g., "(tax included)").', 'vk-booking-manager' ); ?></p>
										</td>
									</tr>
								<?php endif; ?>

								<?php if ( 'system' === $active_tab && Industry_Presets::supports( 'lodging' ) ) : ?>
									<?php
									// #737: 宿泊の業種でだけ、宿泊プランの休前日・特定日を設定する欄を出す。
									$lodging_pre_holiday_weekdays = Lodging_Rate::normalize_weekdays( $settings['lodging_pre_holiday_weekdays'] ?? array() );
									$lodging_special_rows         = $this->prepare_lodging_special_date_rows( $settings['lodging_special_dates'] ?? array() );
									$lodging_special_errors       = is_array( $field_errors['lodging_special_dates'] ?? null ) ? $field_errors['lodging_special_dates'] : array();
									?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-lodging-rate-row">
										<th scope="row"><?php esc_html_e( 'Pre-holidays and special dates (lodging plan prices)', 'vk-booking-manager' ); ?></th>
										<td>
											<fieldset class="vkbm-lodging-rate-group">
												<legend><?php esc_html_e( 'Days of the week treated as pre-holidays', 'vk-booking-manager' ); ?></legend>
												<?php // チェックが 1 つも無い保存でも、項目が送られたと判別できるように空の値を先に送る。 ?>
												<input type="hidden" name="vkbm_provider_settings[lodging_pre_holiday_weekdays][]" value="" />
												<div class="vkbm-lodging-rate-weekdays">
													<?php foreach ( Lodging_Rate::WEEKDAY_KEYS as $weekday_key ) : ?>
														<label class="vkbm-inline-checkbox">
															<input
																type="checkbox"
																name="vkbm_provider_settings[lodging_pre_holiday_weekdays][]"
																value="<?php echo esc_attr( $weekday_key ); ?>"
																<?php checked( in_array( $weekday_key, $lodging_pre_holiday_weekdays, true ) ); ?>
															/>
															<?php echo esc_html( $business_day_labels[ $weekday_key ] ); ?>
														</label>
													<?php endforeach; ?>
												</div>
												<p class="description"><?php esc_html_e( 'Nights that start on the selected days of the week are priced as pre-holidays. A night before a public holiday is always a pre-holiday, whatever the day of the week.', 'vk-booking-manager' ); ?></p>
											</fieldset>

											<fieldset class="vkbm-lodging-rate-group">
												<legend><?php esc_html_e( 'Special dates', 'vk-booking-manager' ); ?></legend>
												<div id="vkbm-lodging-special-dates-rows" class="vkbm-lodging-special-dates" data-next-index="<?php echo esc_attr( (string) ( max( array_map( 'intval', array_keys( $lodging_special_rows ) ) ) + 1 ) ); ?>">
													<?php foreach ( $lodging_special_rows as $special_index => $special_row ) : ?>
														<?php
														$special_row_error = is_string( $lodging_special_errors[ (string) $special_index ] ?? null ) ? $lodging_special_errors[ (string) $special_index ] : '';
														$special_row_html  = $this->render_lodging_special_date_row( (string) $special_index, $special_row, $special_row_error );
														echo $special_row_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within method.
														?>
													<?php endforeach; ?>
												</div>
												<button type="button" class="button button-secondary" id="vkbm-lodging-special-date-add">
													<?php esc_html_e( '+ Add period', 'vk-booking-manager' ); ?>
												</button>
												<template id="vkbm-lodging-special-date-row-template">
													<?php
													$special_row_template = $this->render_lodging_special_date_row(
														'__INDEX__',
														array(
															'start' => '',
															'end'   => '',
															'label' => '',
														),
														''
													);
													echo $special_row_template; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output used as template.
													?>
												</template>
												<p class="description"><?php esc_html_e( 'Nights that start on a day in the period, including the end date, are priced as special dates. If the special date price of a plan is empty, the night is priced as a pre-holiday or a weekday instead.', 'vk-booking-manager' ); ?></p>
											</fieldset>

											<p class="description">
												<?php
												printf(
													/* translators: %s: link to the service menu list. */
													esc_html__( 'Set the regular, pre-holiday, and special date prices of each plan on the edit screen of each %s.', 'vk-booking-manager' ),
													'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ) ) . '">' . esc_html__( 'service menu', 'vk-booking-manager' ) . '</a>'
												);
												?>
											</p>
										</td>
									</tr>
								<?php endif; ?>

								<tr class="vkbm-provider-settings__tab-system">
									<th scope="row">
										<label for="vkbm-slot-step-minutes"><?php esc_html_e( 'Reservation slot time', 'vk-booking-manager' ); ?></label>
									</th>
									<td>
										<select
											id="vkbm-slot-step-minutes"
											name="vkbm_provider_settings[provider_slot_step_minutes]"
										>
											<option value="10" <?php selected( $slot_step_minutes, 10 ); ?>><?php esc_html_e( '10 minutes', 'vk-booking-manager' ); ?></option>
											<option value="15" <?php selected( $slot_step_minutes, 15 ); ?>><?php esc_html_e( '15 minutes', 'vk-booking-manager' ); ?></option>
											<option value="20" <?php selected( $slot_step_minutes, 20 ); ?>><?php esc_html_e( '20 minutes', 'vk-booking-manager' ); ?></option>
											<option value="30" <?php selected( $slot_step_minutes, 30 ); ?>><?php esc_html_e( '30 minutes', 'vk-booking-manager' ); ?></option>
											<option value="60" <?php selected( $slot_step_minutes, 60 ); ?>><?php esc_html_e( '60 minutes', 'vk-booking-manager' ); ?></option>
										</select>
										<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
											<p class="description"><?php esc_html_e( 'This setting applies only to time slot plans.', 'vk-booking-manager' ); ?><?php esc_html_e( ' It is not used for lodging plans.', 'vk-booking-manager' ); ?></p>
										<?php endif; ?>
									</td>
								</tr>

								<tr class="vkbm-provider-settings__tab-system" id="vkbm-service-menu-buffer-after-default-row">
									<th scope="row">
										<label for="vkbm-service-menu-buffer-after-default"><?php esc_html_e( 'Post-service buffer', 'vk-booking-manager' ); ?></label>
									</th>
									<td>
										<input
											type="number"
											class="small-text"
											id="vkbm-service-menu-buffer-after-default"
											name="vkbm_provider_settings[provider_service_menu_buffer_after_minutes]"
											min="0"
											step="1"
											value="<?php echo esc_attr( (string) $service_menu_buffer_after_minutes ); ?>"
										/> <?php esc_html_e( 'minutes', 'vk-booking-manager' ); ?>
										<p class="description"><?php esc_html_e( 'During the service time plus the buffer time, new reservations will not be accepted.', 'vk-booking-manager' ); ?></p>
										<p class="description"><?php esc_html_e( 'If there is an input for each service menu, that will take priority.', 'vk-booking-manager' ); ?></p>
										<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
											<p class="description"><?php esc_html_e( 'This setting applies only to time slot plans.', 'vk-booking-manager' ); ?><?php esc_html_e( ' It is not used for lodging plans.', 'vk-booking-manager' ); ?></p>
										<?php endif; ?>
									</td>
								</tr>

								<tr class="vkbm-provider-settings__tab-system">
									<th scope="row">
										<label for="vkbm-provider-reservation-deadline"><?php esc_html_e( 'Reservation deadline', 'vk-booking-manager' ); ?></label>
									</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-provider-reservation-deadline"
									name="vkbm_provider_settings[provider_reservation_deadline_hours]"
									min="0"
									step="1"
									value="<?php echo esc_attr( (string) $reservation_deadline_hours ); ?>"
								/> <?php esc_html_e( 'hours ago', 'vk-booking-manager' ); ?>
								<p class="description">
									<?php esc_html_e( 'If there is an input for each service menu, that will take priority.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-provider-max-advance-booking-days"><?php esc_html_e( 'Max advance booking period', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-provider-max-advance-booking-days"
									name="vkbm_provider_settings[provider_max_advance_booking_days]"
									min="0"
									step="1"
									value="<?php echo esc_attr( (string) $max_advance_booking_days ); ?>"
								/> <?php esc_html_e( 'days', 'vk-booking-manager' ); ?>
								<p class="description">
									<?php esc_html_e( 'The maximum number of days in advance that reservations can be made. Set to 0 for no limit.', 'vk-booking-manager' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'If there is an input for each service menu, that will take priority.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row">
									<label for="vkbm-provider-booking-customer-mode"><?php esc_html_e( 'Reservation acceptance method', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<select
									id="vkbm-provider-booking-customer-mode"
									name="vkbm_provider_settings[booking_customer_mode]"
								>
									<option value="<?php echo esc_attr( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER ); ?>" <?php selected( $booking_customer_mode, Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER ); ?>>
										<?php esc_html_e( 'Members only (only logged-in customers can make reservations)', 'vk-booking-manager' ); ?>
									</option>
									<option value="<?php echo esc_attr( Settings_Repository::BOOKING_CUSTOMER_MODE_GUEST ); ?>" <?php selected( $booking_customer_mode, Settings_Repository::BOOKING_CUSTOMER_MODE_GUEST ); ?>>
										<?php esc_html_e( 'Guests only (customers can make reservations without registering as a user)', 'vk-booking-manager' ); ?>
									</option>
									<option value="<?php echo esc_attr( Settings_Repository::BOOKING_CUSTOMER_MODE_BOTH ); ?>" <?php selected( $booking_customer_mode, Settings_Repository::BOOKING_CUSTOMER_MODE_BOTH ); ?>>
										<?php esc_html_e( 'Members or guests (customers choose on the reservation screen)', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Choose whether customers can make reservations without registering as a user.', 'vk-booking-manager' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'A guest reservation is accepted when the customer opens the link in the confirmation email.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'If "Reservation status" is set to "Make a tentative reservation", it is received as a tentative reservation.', 'vk-booking-manager' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'The cancellation deadline follows the "Reservation cancellation" setting.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row">
									<label for="vkbm-provider-booking-status-mode"><?php esc_html_e( 'Reservation status', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<select
									id="vkbm-provider-booking-status-mode"
									name="vkbm_provider_settings[provider_booking_status_mode]"
								>
									<option value="<?php echo esc_attr( Settings_Repository::BOOKING_STATUS_MODE_CONFIRMED ); ?>" <?php selected( $booking_status_mode, Settings_Repository::BOOKING_STATUS_MODE_CONFIRMED ); ?>>
										<?php esc_html_e( 'Instant confirmation', 'vk-booking-manager' ); ?>
									</option>
									<option value="<?php echo esc_attr( Settings_Repository::BOOKING_STATUS_MODE_PENDING ); ?>" <?php selected( $booking_status_mode, Settings_Repository::BOOKING_STATUS_MODE_PENDING ); ?>>
										<?php esc_html_e( 'Make a tentative reservation', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'With tentative reservations, reservations are received as "Tentative". Change the status to "Confirmed" in the reservation list to confirm them.', 'vk-booking-manager' ); ?><br />
									<?php esc_html_e( 'For tentative reservations, a reservation confirmation email will be sent to the user even when the reservation is changed to confirmed.', 'vk-booking-manager' ); ?><br />
									<?php esc_html_e( '*Reservations made by users with administrator privileges and salon privileges will be confirmed immediately.', 'vk-booking-manager' ); ?>
								</p>
								<?php // 仮予約の完了画面の案内文。「仮予約にする」選択時のみ表示する（切り替えは assets/js/provider-settings.js）。値は即時確定に戻しても保持する。 ?>
								<div
									id="vkbm-provider-booking-complete-message-pending-field"
									class="vkbm-provider-settings__pending-message-field"
									<?php echo Settings_Repository::BOOKING_STATUS_MODE_PENDING === $booking_status_mode_current ? '' : 'hidden'; ?>
								>
									<label for="vkbm-provider-booking-complete-message-pending"><strong><?php esc_html_e( 'Guidance text for tentative reservation completion', 'vk-booking-manager' ); ?></strong></label>
									<textarea
										id="vkbm-provider-booking-complete-message-pending"
										name="vkbm_provider_settings[provider_booking_complete_message_pending]"
										class="large-text"
										rows="4"
										maxlength="<?php echo esc_attr( (string) Settings_Sanitizer::BOOKING_COMPLETE_MESSAGE_MAX_LENGTH ); ?>"
									><?php echo esc_textarea( $booking_complete_message_pending ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'This text is displayed below the heading "Your tentative reservation has been received." on the screen shown after a tentative reservation is completed.', 'vk-booking-manager' ); ?><br />
										<?php esc_html_e( 'You can rewrite it to match how your store operates, such as when you will confirm the reservation (for example, "We will contact you within 24 hours.") and how you will contact the customer.', 'vk-booking-manager' ); ?><br />
										<?php esc_html_e( 'If left blank, the standard text below is displayed.', 'vk-booking-manager' ); ?><br />
										<?php esc_html_e( 'HTML tags cannot be used, and line breaks are displayed as entered.', 'vk-booking-manager' ); ?><br />
										<?php esc_html_e( 'The text of emails does not change.', 'vk-booking-manager' ); ?>
									</p>
									<p class="description vkbm-provider-settings__standard-text">
										<?php esc_html_e( 'Standard text:', 'vk-booking-manager' ); ?>
										<?php echo esc_html( ( new Settings_Repository() )->get_default_booking_complete_message_pending() ); ?>
									</p>
								</div>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row">
									<label for="vkbm-provider-booking-cancel-mode"><?php esc_html_e( 'Reservation cancellation', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<p class="description vkbm-provider-settings__field-lead">
									<?php esc_html_e( 'Cancellation of reservations via the web by users', 'vk-booking-manager' ); ?>
								</p>
								<select
									id="vkbm-provider-booking-cancel-mode"
									name="vkbm_provider_settings[provider_booking_cancel_mode]"
								>
									<option value="hours" <?php selected( $booking_cancel_mode, 'hours' ); ?>>
										<?php esc_html_e( 'time specification', 'vk-booking-manager' ); ?>
									</option>
									<option value="none" <?php selected( $booking_cancel_mode, 'none' ); ?>>
										<?php esc_html_e( 'Cannot be canceled', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<span
									id="vkbm-provider-booking-cancel-hours-field"
									class="vkbm-provider-settings__cancel-hours-field"
									<?php echo 'none' === $booking_cancel_mode ? 'hidden' : ''; ?>
								>
									<input
										type="number"
										class="small-text"
										id="vkbm-provider-booking-cancel-hours"
										name="vkbm_provider_settings[provider_booking_cancel_deadline_hours]"
										min="0"
										step="1"
										value="<?php echo esc_attr( (string) $booking_cancel_hours ); ?>"
										<?php disabled( 'none' === $booking_cancel_mode ); ?>
									/>
									<?php esc_html_e( 'until the hour', 'vk-booking-manager' ); ?>
								</span>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row"><?php esc_html_e( 'Duplicate reservations for the same staff', 'vk-booking-manager' ); ?></th>
								<td>
									<label class="vkbm-inline-checkbox">
										<input
											type="checkbox"
											id="vkbm-provider-allow-staff-overlap-admin"
											name="vkbm_provider_settings[provider_allow_staff_overlap_admin]"
											value="1"
											<?php checked( ! empty( $settings['provider_allow_staff_overlap_admin'] ) ); ?>
										/>
										<?php esc_html_e( 'Permitted only if specified from the management screen', 'vk-booking-manager' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Duplicate reservations for the same staff member in the same time slot will only be permitted if manually specified by the staff member from the management screen.', 'vk-booking-manager' ); ?>
									</p>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-attendees">
								<th scope="row"><label for="vkbm-booking-attendee-name-mode"><?php esc_html_e( 'Name field for the person receiving the service', 'vk-booking-manager' ); ?></label></th>
								<td>
									<select id="vkbm-booking-attendee-name-mode" name="vkbm_provider_settings[booking_attendee_name_mode]">
										<option value="disabled" <?php selected( $booking_attendee_name_mode, 'disabled' ); ?>><?php esc_html_e( 'Do not use', 'vk-booking-manager' ); ?></option>
										<option value="optional" <?php selected( $booking_attendee_name_mode, 'optional' ); ?>><?php esc_html_e( 'Use (optional)', 'vk-booking-manager' ); ?></option>
										<option value="required" <?php selected( $booking_attendee_name_mode, 'required' ); ?>><?php esc_html_e( 'Use (required)', 'vk-booking-manager' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Use this when the person making the booking differs from the person or animal receiving the service, such as a parent booking for a child or an owner booking for a pet.', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'When enabled, a name field appears on the booking confirmation screen. The entered name also appears in notification emails, booking editing, and booking lists.', 'vk-booking-manager' ); ?></p>
									<p><label for="vkbm-booking-attendee-name-label"><?php esc_html_e( 'Field heading', 'vk-booking-manager' ); ?></label><br />
										<input type="text" class="regular-text" id="vkbm-booking-attendee-name-label" name="vkbm_provider_settings[booking_attendee_name_label]" value="<?php echo esc_attr( $booking_attendee_name_label ); ?>" maxlength="<?php echo esc_attr( (string) \VKBookingManager\ProviderSettings\Settings_Sanitizer::BOOKING_ATTENDEE_NAME_LABEL_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr__( 'Name of the person receiving the service', 'vk-booking-manager' ); ?>" <?php disabled( 'disabled' === $booking_attendee_name_mode ); ?> />
									</p>
									<p class="description"><?php esc_html_e( 'This cannot be changed while “Do not use” is selected.', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'You can change the heading to match your industry (for example, Name of the child receiving care or Pet name).', 'vk-booking-manager' ); ?></p>
									<p class="description"><?php esc_html_e( 'If left blank, “Name of the person receiving the service” is displayed.', 'vk-booking-manager' ); ?></p>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-attendees">
								<th scope="row"><?php esc_html_e( 'Saved list', 'vk-booking-manager' ); ?></th>
								<td>
									<input type="hidden" name="vkbm_provider_settings[attendee_roster_enabled]" value="0"><label><input type="checkbox" name="vkbm_provider_settings[attendee_roster_enabled]" value="1" <?php checked( ! empty( $settings['attendee_roster_enabled'] ) ); ?>> <?php esc_html_e( 'Allow members and staff to manage a saved list', 'vk-booking-manager' ); ?></label>
									<?php
									if ( 'disabled' === $booking_attendee_name_mode && ! empty( $settings['attendee_roster_enabled'] ) ) :
										?>
										<p class="notice notice-warning inline"><?php echo wp_kses_post( __( 'The name field is not shown on the booking screen, so the saved list cannot be selected. Change <a href="#vkbm-booking-attendee-name-mode">Name field</a> to “Use (optional)” or “Use (required)”.', 'vk-booking-manager' ) ); ?></p><?php endif; ?>
									<fieldset>
									<p><label for="vkbm-attendee-roster-label"><?php esc_html_e( 'Name of the list', 'vk-booking-manager' ); ?></label><br><input type="text" class="regular-text" id="vkbm-attendee-roster-label" name="vkbm_provider_settings[attendee_roster_label]" value="<?php echo esc_attr( $attendee_roster_label ); ?>" placeholder="<?php esc_attr_e( 'People receiving the service', 'vk-booking-manager' ); ?>"></p>
									<p class="description"><?php esc_html_e( 'Used for the My Page heading and booking screen choices.', 'vk-booking-manager' ); ?></p>
									<input type="hidden" name="vkbm_provider_settings[attendee_roster_show_self]" value="0"><label><input type="checkbox" name="vkbm_provider_settings[attendee_roster_show_self]" value="1" <?php checked( ! empty( $settings['attendee_roster_show_self'] ) ); ?>> <?php esc_html_e( 'Show “Yourself” as a choice on the booking screen', 'vk-booking-manager' ); ?></label>
									<p><input type="hidden" name="vkbm_provider_settings[attendee_roster_kana_enabled]" value="0"><label><input type="checkbox" name="vkbm_provider_settings[attendee_roster_kana_enabled]" value="1" <?php checked( ! empty( $settings['attendee_roster_kana_enabled'] ) ); ?>> <?php esc_html_e( 'Use a furigana field', 'vk-booking-manager' ); ?></label></p>
									<p><label for="vkbm-attendee-roster-kana-label"><?php esc_html_e( 'Furigana field heading', 'vk-booking-manager' ); ?></label><br><input type="text" class="regular-text" id="vkbm-attendee-roster-kana-label" name="vkbm_provider_settings[attendee_roster_kana_label]" value="<?php echo esc_attr( $attendee_roster_kana_label ); ?>" placeholder="<?php esc_attr_e( 'Furigana', 'vk-booking-manager' ); ?>"></p>
									</fieldset>
									<p class="description"><?php esc_html_e( 'Saved lists and the settings below are retained while this feature is off.', 'vk-booking-manager' ); ?></p>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-attendees">
								<th scope="row"><?php esc_html_e( 'Customer number', 'vk-booking-manager' ); ?></th>
								<td><fieldset><input type="hidden" name="vkbm_provider_settings[attendee_number_enabled]" value="0"><label><input type="checkbox" name="vkbm_provider_settings[attendee_number_enabled]" value="1" <?php checked( ! empty( $settings['attendee_number_enabled'] ) ); ?>> <?php esc_html_e( 'Use customer numbers', 'vk-booking-manager' ); ?></label><p><label for="vkbm-attendee-number-label"><?php esc_html_e( 'Number field heading', 'vk-booking-manager' ); ?></label><br><input type="text" class="regular-text" id="vkbm-attendee-number-label" name="vkbm_provider_settings[attendee_number_label]" value="<?php echo esc_attr( $attendee_number_label ); ?>" placeholder="<?php esc_attr_e( 'Customer number', 'vk-booking-manager' ); ?>"></p><p class="description"><?php esc_html_e( 'Members can view numbers, while staff manage them from the user profile screen.', 'vk-booking-manager' ); ?></p></fieldset></td>
							</tr>




						<?php if ( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER !== $booking_customer_mode ) : ?>
							<?php // #958: 予約の受付方法が「会員のみ」以外のときだけ、この設定の影響を受けることをタブの先頭で知らせる。WordPress の通知が画面上部へ移動しないよう inline を付ける。 ?>
						<tr class="vkbm-provider-settings__tab-registration">
							<td colspan="2" class="vkbm-provider-settings__booking-customer-mode-notice-cell">
								<div class="notice notice-info inline vkbm-booking-customer-mode-notice" data-booking-customer-mode="<?php echo esc_attr( $booking_customer_mode ); ?>">
									<p>
										<?php echo esc_html( self::get_booking_customer_mode_registration_notice( $booking_customer_mode ) ); ?>
										<a href="<?php echo esc_url( add_query_arg( 'tab', 'system', $base_url ) . '#vkbm-provider-booking-customer-mode' ); ?>"><?php esc_html_e( 'Change the reservation acceptance method', 'vk-booking-manager' ); ?></a>
									</p>
								</div>
							</td>
						</tr>
						<?php endif; ?>

						<tr class="vkbm-provider-settings__tab-registration">
							<th scope="row"><?php esc_html_e( 'membership', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										name="vkbm_users_can_register"
										value="1"
										<?php checked( $wp_users_can_register ); ?>
									/>
									<?php esc_html_e( 'Allow anyone to register as a user', 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '*This is the same as the standard WordPress “Membership” settings.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-registration">
							<th scope="row">
								<label for="vkbm-registration-email-verification"><?php esc_html_e( 'Email authentication during user registration', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										id="vkbm-registration-email-verification"
										name="vkbm_provider_settings[registration_email_verification_enabled]"
										value="1"
										<?php checked( $email_verification_enabled ); ?>
									/>
									<?php esc_html_e( 'enable', 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Uncheck to skip email authentication and enable the user upon registration (mainly for local or test environments).', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-registration">
							<th scope="row"><?php esc_html_e( 'Registration/Login Attempt Limit', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										name="vkbm_provider_settings[auth_rate_limit_enabled]"
										value="1"
										<?php checked( $auth_rate_limit_enabled ); ?>
									/>
									<?php esc_html_e( 'enable', 'vk-booking-manager' ); ?>
								</label>
								<div class="vkbm-provider-settings__inline-field">
									<label for="vkbm-auth-rate-limit-register-max">
										<?php esc_html_e( 'User registration', 'vk-booking-manager' ); ?>
									</label>
									<input
										type="number"
										class="small-text"
										id="vkbm-auth-rate-limit-register-max"
										name="vkbm_provider_settings[auth_rate_limit_register_max]"
										min="1"
										step="1"
										value="<?php echo esc_attr( (string) $auth_rate_limit_register_max ); ?>"
									/>
									<span><?php esc_html_e( 'times / 30 minutes', 'vk-booking-manager' ); ?></span>
								</div>
								<div class="vkbm-provider-settings__inline-field">
									<label for="vkbm-auth-rate-limit-login-max">
										<?php esc_html_e( 'Log in', 'vk-booking-manager' ); ?>
									</label>
									<input
										type="number"
										class="small-text"
										id="vkbm-auth-rate-limit-login-max"
										name="vkbm_provider_settings[auth_rate_limit_login_max]"
										min="1"
										step="1"
										value="<?php echo esc_attr( (string) $auth_rate_limit_login_max ); ?>"
									/>
									<span><?php esc_html_e( 'times / 10 minutes', 'vk-booking-manager' ); ?></span>
								</div>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: login rate limit, 2: registration rate limit */
											__( 'Login by IP is limited to %1$d times/10 minutes, and user registration is limited to %2$d times/30 minutes.', 'vk-booking-manager' ),
											$auth_rate_limit_login_max,
											$auth_rate_limit_register_max
										)
									);
									?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-registration">
							<th scope="row"><?php esc_html_e( 'Registration screen redirection', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										name="vkbm_provider_settings[membership_redirect_wp_register]"
										value="1"
										<?php checked( $membership_redirect_enabled ); ?>
									/>
									<?php esc_html_e( "Disable the WordPress new user registration screen and redirect to this system's user registration screen", 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( "Even if WordPress's \"Membership (which allows anyone to register as a user)\" is enabled, wp-login.php?action=register will direct you to the registration screen of this system.", 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row"><?php esc_html_e( 'Login screen redirection', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										name="vkbm_provider_settings[membership_redirect_wp_login]"
										value="1"
										<?php checked( $login_redirect_enabled ); ?>
									/>
									<?php esc_html_e( "Disable the WordPress login screen and redirect to this system's login screen", 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'If you access wp-login.php, you will be directed to the login screen of the reservation page.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row"><?php esc_html_e( 'Logo image', 'vk-booking-manager' ); ?></th>
							<td>
								<div id="vkbm-provider-logo-preview-container" class="vkbm-provider-logo-preview">
									<?php
									if ( $logo_id > 0 ) {
										echo wp_kses_post( wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'vkbm-provider-logo-image' ) ) );
									}
									?>
								</div>
								<input
									type="hidden"
									id="vkbm-provider-logo-id"
									name="vkbm_provider_settings[provider_logo_id]"
									value="<?php echo esc_attr( $logo_id ); ?>"
								/>
								<button type="button" class="button" id="vkbm-provider-logo-select">
									<?php esc_html_e( 'select image', 'vk-booking-manager' ); ?>
								</button>
								<button
									type="button"
									class="button button-secondary"
									id="vkbm-provider-logo-remove"
									<?php echo $logo_id > 0 ? '' : 'hidden'; ?>
								>
									<?php esc_html_e( 'delete image', 'vk-booking-manager' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Select a logo image from your Media Library (optional).', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-consent">
							<th scope="row">
								<label for="vkbm-provider-cancellation-policy"><?php esc_html_e( 'Cancellation policy', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="6"
									id="vkbm-provider-cancellation-policy"
									name="vkbm_provider_settings[provider_cancellation_policy]"
								><?php echo esc_textarea( $cancellation_policy ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Enter the cancellation policy to be displayed on the reservation page, notification email, etc.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-consent">
							<th scope="row">
								<label for="vkbm-provider-terms-of-service"><?php esc_html_e( 'System Terms of Use', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="8"
									id="vkbm-provider-terms-of-service"
									name="vkbm_provider_settings[provider_terms_of_service]"
								><?php echo esc_textarea( $terms_of_service ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Enter the terms of use for the entire service, including membership registration, prohibitions, and disclaimers.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-consent">
							<th scope="row">
								<label for="vkbm-provider-privacy-policy-mode"><?php esc_html_e( 'Privacy policy', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<select
									id="vkbm-provider-privacy-policy-mode"
									name="vkbm_provider_settings[provider_privacy_policy_mode]"
								>
									<option value="none" <?php selected( $privacy_policy_mode, 'none' ); ?>>
										<?php esc_html_e( 'please select', 'vk-booking-manager' ); ?>
									</option>
									<option value="url" <?php selected( $privacy_policy_mode, 'url' ); ?>>
										<?php esc_html_e( 'Specify the URL of the privacy policy page', 'vk-booking-manager' ); ?>
									</option>
									<option value="content" <?php selected( $privacy_policy_mode, 'content' ); ?>>
										<?php esc_html_e( 'Specify from this page', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Select how to display the privacy policy that requires consent during user registration.', 'vk-booking-manager' ); ?>
								</p>
								<div
									id="vkbm-provider-privacy-policy-url-field"
									class="vkbm-provider-settings__privacy-policy-field"
									<?php echo 'url' === $privacy_policy_mode ? '' : 'hidden'; ?>
								>
									<label for="vkbm-provider-privacy-policy-url" class="vkbm-form__label--block">
										<?php esc_html_e( 'Privacy policy page URL', 'vk-booking-manager' ); ?>
									</label>
									<input
										type="url"
										class="regular-text"
										id="vkbm-provider-privacy-policy-url"
										name="vkbm_provider_settings[provider_privacy_policy_url]"
										value="<?php echo esc_attr( $privacy_policy_url ); ?>"
									/>
								</div>
								<div
									id="vkbm-provider-privacy-policy-content-field"
									class="vkbm-provider-settings__privacy-policy-field"
									<?php echo 'content' === $privacy_policy_mode ? '' : 'hidden'; ?>
								>
									<label for="vkbm-provider-privacy-policy-content" class="vkbm-form__label--block">
										<?php esc_html_e( 'Privacy policy', 'vk-booking-manager' ); ?>
									</label>
									<textarea
										class="large-text"
										rows="8"
										id="vkbm-provider-privacy-policy-content"
										name="vkbm_provider_settings[provider_privacy_policy_content]"
									><?php echo esc_textarea( $privacy_policy_content ); ?></textarea>
								</div>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-shift-alert-months"><?php echo esc_html( Industry_Presets::supports( 'lodging' ) ? __( 'Alert when days on sale are not registered', 'vk-booking-manager' ) : __( 'Alert when shift is not registered', 'vk-booking-manager' ) ); ?></label>
							</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-shift-alert-months"
									name="vkbm_provider_settings[shift_alert_months]"
									min="1"
									max="4"
									step="1"
									value="<?php echo esc_attr( (string) $shift_alert_months ); ?>"
								/>
								<?php echo esc_html( Industry_Presets::supports( 'lodging' ) ? __( 'Display an alert if days on sale for the next month are not registered.', 'vk-booking-manager' ) : __( 'Display an alert if a shift for the next month is not registered.', 'vk-booking-manager' ) ); ?>
								<p class="description"><?php esc_html_e( 'Displayed if there is an unregistered month within the specified number of months including the current month.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system" data-industry-field="staff_enabled" data-industry-label="<?php echo esc_attr__( 'Nomination feature', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'staff_enabled' ) ); ?>>
							<th scope="row">
								<label for="vkbm-staff-enabled"><?php esc_html_e( 'Nomination feature', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php
									// 無料版では指名機能は常に無効のため、操作不可（無効固定）のセレクトを表示する。
									?>
									<select id="vkbm-staff-enabled" disabled>
										<option value="0" selected><?php esc_html_e( 'Disabled', 'vk-booking-manager' ); ?></option>
									</select>
									<?php
									// 無料版では指名機能は常に無効のため値は送信しない。
									// 保存値は Settings_Sanitizer 側で無効へ強制されるため、UI・保存値・実挙動が一致する。
									?>
								<?php else : ?>
									<select
										id="vkbm-staff-enabled"
										name="vkbm_provider_settings[staff_enabled]"
									>
										<option value="1" <?php selected( $staff_enabled, true ); ?>><?php esc_html_e( 'Enabled', 'vk-booking-manager' ); ?></option>
										<option value="0" <?php selected( $staff_enabled, false ); ?>><?php esc_html_e( 'Disabled', 'vk-booking-manager' ); ?></option>
									</select>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'When disabled, the staff nomination selection and nomination fee will be hidden from the booking form. Staff assignment and shift management features remain available.', 'vk-booking-manager' ); ?>
									<?php if ( ! Pro_Upsell::is_free_edition() ) : ?>
										<?php // ここから続く9項目（〜「指名料ラベル」）は業種プリセットの対象（#387・植草案）。 ?>
										<?php esc_html_e( 'This field is affected by the industry preset selected above.', 'vk-booking-manager' ); ?>
									<?php endif; ?>
								</p>
								<?php
								// 無料版では指名・複数スタッフ機能が利用できないため案内を表示する。
								echo Pro_Upsell::get_feature_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
									__( 'Staff nomination and multiple staff registration are available in the Pro edition.', 'vk-booking-manager' )
								);
								?>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system" data-industry-field="slot_capacity_enabled" data-industry-label="<?php echo esc_attr__( 'Time slot capacity feature', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'slot_capacity_enabled' ) ); ?>>
							<th scope="row">
								<label for="vkbm-slot-capacity-enabled"><?php esc_html_e( 'Time slot capacity feature', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php
									// 無料版では予約枠の定員機能は常に無効のため、操作不可（無効固定）のセレクトを表示する。
									?>
									<select id="vkbm-slot-capacity-enabled" disabled>
										<option value="0" selected><?php esc_html_e( 'Disabled', 'vk-booking-manager' ); ?></option>
									</select>
									<?php
									// 無料版では値を送信しない。保存値は Settings_Sanitizer 側で無効へ強制される。
									?>
								<?php else : ?>
									<?php
									// #392: 以前は指名機能ONのときこのセレクトを disabled にし、hidden input で
									// 現在値を維持していた（予約枠の定員機能は指名OFF時のみ使える仕様だったため）。
									// 指名を使うメニューでも予約枠の定員（＝1組の最大人数）を利用できるようにしたため、
									// 指名機能の有効/無効に関わらず常に操作可能にする。
									?>
									<select
										id="vkbm-slot-capacity-enabled"
										name="vkbm_provider_settings[slot_capacity_enabled]"
										aria-describedby="<?php echo esc_attr( 'vkbm-slot-capacity-desc' ); ?>"
									>
										<option value="1" <?php selected( $slot_capacity_enabled, true ); ?>><?php esc_html_e( 'Enabled', 'vk-booking-manager' ); ?></option>
										<option value="0" <?php selected( $slot_capacity_enabled, false ); ?>><?php esc_html_e( 'Disabled', 'vk-booking-manager' ); ?></option>
									</select>
								<?php endif; ?>
								<p class="description" id="<?php echo esc_attr( 'vkbm-slot-capacity-desc' ); ?>">
									<?php esc_html_e( 'This feature is intended for use with bookings such as schools, tours, and events.', 'vk-booking-manager' ); ?>
									<br>
									<?php esc_html_e( 'When enabled, you can set the time slot capacity for each service menu.', 'vk-booking-manager' ); ?>
									<br>
									<?php esc_html_e( 'You can also allow users to book for multiple guests at once in a single booking.', 'vk-booking-manager' ); ?>
									<br>
									<?php
									// #392: 指名機能との連動が生まれたため、以前の「指名OFF時のみ
									// 使える」という注記が消えた分の不親切さを補う。指名を使うメニューでも、この
									// スイッチが「1組の最大人数」の有効/無効を左右する親スイッチであることを明記する。
									esc_html_e( 'This setting also controls whether the time slot capacity (maximum group size) is available for service menus that use staff nomination.', 'vk-booking-manager' );
									?>
								</p>
								<?php
								// 無料版では予約枠の定員機能が利用できないため案内を表示する。
								echo Pro_Upsell::get_feature_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
									__( 'Time slot capacity feature is available in the Pro edition.', 'vk-booking-manager' )
								);
								?>
							</td>
						</tr>

						<?php if ( Staff_Editor::is_enabled() ) : ?>
							<tr class="vkbm-provider-settings__tab-system" data-industry-field="resource_label_singular" data-industry-label="<?php echo esc_attr__( 'Resource name (singular)', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'resource_label_singular' ) ); ?>>
								<th scope="row">
									<label for="vkbm-resource-label-singular"><?php esc_html_e( 'Resource name (singular)', 'vk-booking-manager' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										class="regular-text"
										id="vkbm-resource-label-singular"
										name="vkbm_provider_settings[resource_label_singular]"
										value="<?php echo esc_attr( $resource_label_singular ); ?>"
									/>
									<p class="description">
										<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
											<?php esc_html_e( 'Replaces the resource name shown on the screen (does not change the post type name vkbm_resource).', 'vk-booking-manager' ); ?>
										<?php else : ?>
											<?php esc_html_e( 'Replaces the "staff" notation on the screen (does not change the post type name vkbm_resource).', 'vk-booking-manager' ); ?>
										<?php endif; ?>
									</p>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-system" data-industry-field="resource_menu_icon" data-industry-label="<?php echo esc_attr__( 'Resource menu icon', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'resource_menu_icon' ) ); ?>>
								<th scope="row">
									<label for="vkbm-resource-menu-icon"><?php esc_html_e( 'Resource menu icon', 'vk-booking-manager' ); ?></label>
								</th>
								<td>
									<div class="vkbm-icon-picker">
										<div class="vkbm-icon-picker__presets">
											<?php foreach ( $resource_menu_icon_presets as $preset_icon ) : ?>
												<?php $is_selected = ( $preset_icon === $resource_menu_icon ); ?>
												<button
													type="button"
													class="button vkbm-icon-picker__preset<?php echo $is_selected ? ' is-selected' : ''; ?>"
													data-icon="<?php echo esc_attr( $preset_icon ); ?>"
													aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>"
													aria-label="<?php echo esc_attr( $preset_icon ); ?>"
												>
													<span class="dashicons <?php echo esc_attr( $preset_icon ); ?>" aria-hidden="true"></span>
												</button>
											<?php endforeach; ?>
										</div>
										<div class="vkbm-icon-picker__field">
											<span class="vkbm-icon-picker__preview dashicons <?php echo esc_attr( $resource_menu_icon ); ?>" aria-hidden="true"></span>
											<input
												type="text"
												class="regular-text vkbm-icon-picker__input"
												id="vkbm-resource-menu-icon"
												name="vkbm_provider_settings[resource_menu_icon]"
												value="<?php echo esc_attr( $resource_menu_icon ); ?>"
											/>
											<a
												href="https://developer.wordpress.org/resource/dashicons/"
												class="button"
												target="_blank"
												rel="noopener noreferrer"
											><?php esc_html_e( 'Dashicons Library', 'vk-booking-manager' ); ?></a>
										</div>
										<p class="description">
											<?php esc_html_e( 'Sets the icon shown next to the resource management menu in the admin sidebar.', 'vk-booking-manager' ); ?>
										</p>
										<p class="description">
											<?php
											printf(
												/* translators: %s: default Dashicons class name. */
												esc_html__( 'If left empty or an invalid value is entered, the default icon (%s) will be used.', 'vk-booking-manager' ),
												esc_html( vkbm_get_default_resource_menu_icon() )
											);
											?>
										</p>
									</div>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-system" data-industry-field="resource_label_menu" data-industry-label="<?php echo esc_attr__( 'resource label', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'resource_label_menu' ) ); ?>>
								<th scope="row">
									<label for="vkbm-resource-label-menu"><?php esc_html_e( 'resource label', 'vk-booking-manager' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										class="regular-text"
										id="vkbm-resource-label-menu"
										name="vkbm_provider_settings[resource_label_menu]"
										value="<?php echo esc_attr( $resource_label_menu ); ?>"
									/>
									<p class="description">
										<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
											<?php esc_html_e( 'Replaces the label for available rooms shown in menu cards.', 'vk-booking-manager' ); ?>
										<?php else : ?>
											<?php esc_html_e( 'Replaces the "available staff" notation in the menu card.', 'vk-booking-manager' ); ?>
										<?php endif; ?>
									</p>
								</td>
							</tr>

							<?php if ( $has_plural_forms_in_locale ) : ?>
								<tr class="vkbm-provider-settings__tab-system" data-industry-field="resource_label_plural" data-industry-label="<?php echo esc_attr__( 'Resource name (multiple)', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'resource_label_plural' ) ); ?>>
									<th scope="row">
										<label for="vkbm-resource-label-plural"><?php esc_html_e( 'Resource name (multiple)', 'vk-booking-manager' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											class="regular-text"
											id="vkbm-resource-label-plural"
											name="vkbm_provider_settings[resource_label_plural]"
											value="<?php echo esc_attr( $resource_label_plural ); ?>"
										/>
										<p class="description">
											<?php esc_html_e( 'Used for plural notation in lists, menu names, etc.', 'vk-booking-manager' ); ?>
										</p>
									</td>
								</tr>
							<?php endif; ?>
						<?php elseif ( null !== $free_resource_label_notice ) : ?>
							<?php // 無料版には呼び方の入力欄が無いため、Pro 版で設定済みの呼び方があるときだけ、入力欄の位置に理由を常時表示する（閉じられない）。 ?>
							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row"><?php esc_html_e( 'Resource name (singular)', 'vk-booking-manager' ); ?></th>
								<td>
									<p class="description">
										<?php
										if ( '' !== $free_resource_label_notice['label'] ) {
											echo esc_html(
												sprintf(
													/* translators: 1: resource label saved while using the Pro edition, 2: default resource label shown in the free edition. */
													__( 'The resource name is set to "%1$s" in the Pro edition. The free edition displays "%2$s". The setting is saved, and the Pro edition displays the resource name you set when it is activated.', 'vk-booking-manager' ),
													$free_resource_label_notice['label'],
													__( 'Staff', 'vk-booking-manager' )
												)
											);
										} else {
											echo esc_html(
												sprintf(
													/* translators: 1: default resource name, 2: default menu card label. */
													__( 'The resource name has been changed in the Pro edition. The free edition displays the defaults ("%1$s" and "%2$s"). The setting is saved, and the Pro edition displays the resource name you set when it is activated.', 'vk-booking-manager' ),
													__( 'Staff', 'vk-booking-manager' ),
													__( 'Staff available', 'vk-booking-manager' )
												)
											);
										}
										?>
									</p>
								</td>
							</tr>
						<?php endif; ?>

						<tr class="vkbm-provider-settings__tab-system" data-industry-field="guests_unit_label" data-industry-label="<?php echo esc_attr__( 'Quantity unit', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'guests_unit_label' ) ); ?>>
							<th scope="row">
								<label for="vkbm-guests-unit-label"><?php esc_html_e( 'Quantity unit', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-guests-unit-label"
									name="vkbm_provider_settings[guests_unit_label]"
									value="<?php echo esc_attr( $guests_unit_label ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'The unit shown after the quantity on the reservation form, confirmation screen, and notification emails. Examples: 名 / 台 / 室 / seats.', 'vk-booking-manager' ); ?><br>
									<?php esc_html_e( 'If left empty, no unit is shown. To restore the default "guests", enter "guests".', 'vk-booking-manager' ); ?><br>
									<?php esc_html_e( 'A space is inserted between the number and the unit only when the unit begins with a single-byte letter (e.g. "5 seats"). Full-width units are placed directly after the number (e.g. "5名").', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<?php
						// #391: この設定は基本設定（サイト全体）画面であり、メニューという文脈が存在しない。
						// そのためメニュー単位判定（is_nomination_enabled_for_menu）は適用せず、
						// 従来どおりサイト全体の指名機能スイッチのみで表示可否を判定する。
						?>
						<?php if ( Staff_Editor::is_nomination_enabled() ) : ?>
							<tr class="vkbm-provider-settings__tab-system" data-industry-field="no_nomination_label" data-industry-label="<?php echo esc_attr( Industry_Presets::supports( 'lodging' ) ? __( 'No selection label', 'vk-booking-manager' ) : __( 'No nomination label', 'vk-booking-manager' ) ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'no_nomination_label' ) ); ?>>
									<th scope="row">
										<label for="vkbm-no-nomination-label"><?php echo esc_html( Industry_Presets::supports( 'lodging' ) ? __( 'No selection label', 'vk-booking-manager' ) : __( 'No nomination label', 'vk-booking-manager' ) ); ?></label>
									</th>
									<td>
										<input
											type="text"
											class="regular-text"
											id="vkbm-no-nomination-label"
											name="vkbm_provider_settings[no_nomination_label]"
											value="<?php echo esc_attr( $no_nomination_label ); ?>"
										/>
										<p class="description">
											<?php if ( Industry_Presets::supports( 'lodging' ) ) : ?>
												<?php esc_html_e( 'Replaces the "No preference" notation in the pulldown when no specific resource is selected.', 'vk-booking-manager' ); ?>
											<?php else : ?>
												<?php esc_html_e( 'Replaces the "No preference" notation in the pulldown (when no specific resource is selected).', 'vk-booking-manager' ); ?>
											<?php endif; ?>
										</p>
									</td>
								</tr>

								<tr class="vkbm-provider-settings__tab-system" data-industry-field="nomination_fee_label" data-industry-label="<?php echo esc_attr__( 'Nomination fee label', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'nomination_fee_label' ) ); ?>>
									<th scope="row">
										<label for="vkbm-nomination-fee-label"><?php esc_html_e( 'Nomination fee label', 'vk-booking-manager' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											class="regular-text"
											id="vkbm-nomination-fee-label"
											name="vkbm_provider_settings[nomination_fee_label]"
											value="<?php echo esc_attr( $nomination_fee_label ); ?>"
										/>
										<p class="description">
											<?php esc_html_e( 'Replaces the "Nomination fee" notation in the pricing display and service menu settings.', 'vk-booking-manager' ); ?>
										</p>
									</td>
								</tr>

								<tr class="vkbm-provider-settings__tab-system">
									<th scope="row">
										<?php esc_html_e( 'Resource tag display', 'vk-booking-manager' ); ?>
									</th>
									<td>
										<label class="vkbm-inline-checkbox">
											<input
												type="checkbox"
												id="vkbm-resource-tag-display-enabled"
												name="vkbm_provider_settings[resource_tag_display_enabled]"
												value="1"
												<?php checked( ! empty( $settings['resource_tag_display_enabled'] ) ); ?>
											/>
											<?php esc_html_e( 'Show resource tags', 'vk-booking-manager' ); ?>
										</label>
										<p class="description">
											<?php esc_html_e( 'When enabled, resource tags are displayed in parentheses after the resource name in the reservation form pulldown.', 'vk-booking-manager' ); ?>
											<br />
											<?php
											printf(
												/* translators: %s: display example */
												esc_html__( 'Example: %s', 'vk-booking-manager' ),
												'<code>' . esc_html__( 'Hanako Yamada ( Female, Veteran )', 'vk-booking-manager' ) . '</code>'
											);
											?>
										</p>
									</td>
								</tr>
						<?php endif; ?>

						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-duration-label"><?php esc_html_e( 'Duration label', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-duration-label"
									name="vkbm_provider_settings[duration_label]"
									value="<?php echo esc_attr( $duration_label ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Replaces the "Time" notation for service duration in the menu card and notification emails.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-other-conditions-label"><?php esc_html_e( 'Other conditions label', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-other-conditions-label"
									name="vkbm_provider_settings[other_conditions_label]"
									value="<?php echo esc_attr( $other_conditions_label ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Replaces the "Other conditions" notation in the menu card and notification emails.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system" data-industry-field="guests_count_label" data-industry-label="<?php echo esc_attr__( 'Quantity label', 'vk-booking-manager' ); ?>" <?php echo esc_attr( self::industry_row_hidden_attr( $industry_hidden_fields, 'guests_count_label' ) ); ?>>
							<th scope="row">
								<label for="vkbm-guests-count-label"><?php esc_html_e( 'Quantity label', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-guests-count-label"
									name="vkbm_provider_settings[guests_count_label]"
									value="<?php echo esc_attr( $guests_count_label ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Replaces the "Number of guests" heading shown for multi-guest bookings on the reservation form, confirmation screen, and notification emails.', 'vk-booking-manager' ); ?><br>
									<?php esc_html_e( 'If left empty, the default heading "Number of guests" is shown.', 'vk-booking-manager' ); ?><br>
									<?php esc_html_e( 'The reservation target is not necessarily people (e.g. seats, rooms, items).', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-closed-day-label"><?php esc_html_e( 'Closed day label', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-closed-day-label"
									name="vkbm_provider_settings[closed_day_label]"
									value="<?php echo esc_attr( $settings['closed_day_label'] ?? '' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Label text displayed on closed days in the calendar.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'If left empty, the default label will be used.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-menu-loop-reserve-button-label"><?php esc_html_e( 'Reservation button text', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-menu-loop-reserve-button-label"
									name="vkbm_provider_settings[menu_loop_reserve_button_label]"
									value="<?php echo esc_attr( $settings['menu_loop_reserve_button_label'] ?? '' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Display text for the button that proceeds to the reservation page from the menu loop.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-system">
							<th scope="row">
								<label for="vkbm-menu-loop-detail-button-label"><?php esc_html_e( 'Detail button text', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="regular-text"
									id="vkbm-menu-loop-detail-button-label"
									name="vkbm_provider_settings[menu_loop_detail_button_label]"
									value="<?php echo esc_attr( $settings['menu_loop_detail_button_label'] ?? '' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Display text for the button that proceeds to the service detail page from the menu loop.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-primary-color"><?php esc_html_e( 'primary color', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-primary-color"
									name="vkbm_provider_settings[design_primary_color]"
									value="<?php echo esc_attr( $design_primary_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the UI primary color (--vkbm--color--primary).', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( "If not specified, the theme's primary color will be used if the theme's primary color (--wp--preset--color--primary) exists.", 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-menu-card-catch-color"><?php esc_html_e( 'Text color of the catchphrase on service menu cards', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-menu-card-catch-color"
									name="vkbm_provider_settings[design_menu_card_catch_color]"
									value="<?php echo esc_attr( $design_menu_card_catch_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the text color of the catchphrase shown on service menu cards (--vkbm--color--menu-card-catch).', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( "If not specified, the theme's contrast color (--wp--preset--color--contrast) will be used. If the theme has no contrast color, a dark gray (#222) is used.", 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-menu-card-price-color"><?php esc_html_e( 'Price text color on service menu cards', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-menu-card-price-color"
									name="vkbm_provider_settings[design_menu_card_price_color]"
									value="<?php echo esc_attr( $design_menu_card_price_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the text color of the price shown on service menu cards (--vkbm--color--menu-card-price).', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( 'If not specified, a dark red (#d11c1c) is used.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-reservation-button-color"><?php esc_html_e( 'Proceed to reservation button color', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-reservation-button-color"
									name="vkbm_provider_settings[design_reservation_button_color]"
									value="<?php echo esc_attr( $design_reservation_button_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the background color for the Proceed to Booking button.', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( 'If not specified, the primary color will be applied.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<?php // #825: 予約ブロック末尾のフッターの色。「フッター」のチェックが外れていても隠さず、入力も止めない。 ?>
						<tr class="vkbm-provider-settings__tab-design vkbm-provider-settings__link-target" id="vkbm-design-footer-colors">
							<th scope="row">
								<label for="vkbm-design-footer-background-color"><?php esc_html_e( 'Footer background color', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-footer-background-color"
									name="vkbm_provider_settings[design_footer_background_color]"
									value="<?php echo esc_attr( $design_footer_background_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the background color of the band at the end of the reservation block that shows the business information and the terms links.', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( 'If left blank, a light gray is used.', 'vk-booking-manager' ); ?>
									<br />
									<?php
									printf(
										/* translators: %s: link to "Reservation page display elements" on the "System settings" tab. */
										esc_html__( 'The footer is displayed when "Footer" is checked in %s on the "System settings" tab.', 'vk-booking-manager' ),
										'<a href="' . esc_url( add_query_arg( 'tab', 'system', $base_url ) . '#vkbm-reservation-display-elements' ) . '">' . esc_html__( 'Reservation page display elements', 'vk-booking-manager' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-footer-text-color"><?php esc_html_e( 'Footer text color', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									class="vkbm-color-picker"
									id="vkbm-design-footer-text-color"
									name="vkbm_provider_settings[design_footer_text_color]"
									value="<?php echo esc_attr( $design_footer_text_color ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Use as the text color of the business information and the terms links.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'The divider lines are also displayed in a lighter shade of this color.', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( 'If left blank, a readable color is selected automatically to match the background color.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'If you specify a text color while leaving the background color blank, choose a color that is readable on the light gray background.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<?php
						// #864: フッターの背景色・文字色のどちらも空欄なら、チェックしても見出しの色は変わらないため行を隠す
						// （画面上の切り替えは provider-settings.js の updateMenuHeadingUseFooterColorRow()）。
						// 隠しても hidden とチェックボックスは送信し、チェックの状態を保つ。
						// オフでも値を送り、オフに戻して保存できるよう、チェックボックスの前に値 0 の hidden を置く。
						$has_footer_color = '' !== $design_footer_background_color || '' !== $design_footer_text_color;
						?>
						<tr class="vkbm-provider-settings__tab-design" id="vkbm-design-menu-heading-use-footer-color-row" <?php echo $has_footer_color ? '' : 'hidden'; ?>>
							<td></td>
							<td>
								<input type="hidden" name="vkbm_provider_settings[design_menu_heading_use_footer_color]" value="0" />
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										id="vkbm-design-menu-heading-use-footer-color"
										name="vkbm_provider_settings[design_menu_heading_use_footer_color]"
										value="1"
										<?php checked( ! empty( $settings['design_menu_heading_use_footer_color'] ) ); ?>
									/>
									<?php esc_html_e( 'Use the footer colors for the group headings in the Menu Loop block and the menu detail headings in the Reservation block', 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When checked, the colors set in "Footer background color" and "Footer text color" above are also used for the background and text of the headings.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-header-logo-width"><?php esc_html_e( 'Width of the logo image in the header', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-design-header-logo-width"
									name="vkbm_provider_settings[design_header_logo_width]"
									min="1"
									max="<?php echo esc_attr( (string) Settings_Sanitizer::DESIGN_HEADER_LOGO_WIDTH_MAX ); ?>"
									step="1"
									value="<?php echo esc_attr( $design_header_logo_width ); ?>"
								/> px
								<p class="description">
									<?php esc_html_e( 'Displays the logo image in the header of the booking page at this width (px).', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'The height follows the aspect ratio.', 'vk-booking-manager' ); ?>
									<br />
									<?php esc_html_e( 'If left blank, the logo is displayed at the same size as before.', 'vk-booking-manager' ); ?>
									<?php esc_html_e( 'The logo image is shown when "logo image" is turned on in "Reservation page display elements".', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-radius-md"><?php esc_html_e( 'Basic size of rounded corners', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-design-radius-md"
									name="vkbm_provider_settings[design_radius_md]"
									min="0"
									max="<?php echo esc_attr( (string) Settings_Sanitizer::DESIGN_RADIUS_MD_MAX ); ?>"
									step="1"
									value="<?php echo esc_attr( (string) $design_radius_md ); ?>"
								/> px
								<p class="description"><?php esc_html_e( 'Applies to the rounded corners of buttons, cards, and time slots on the booking page. Set to 0 for square corners (no rounding).', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-design">
							<th scope="row">
								<label for="vkbm-design-menu-search-style"><?php esc_html_e( 'Design of the search', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<select
									id="vkbm-design-menu-search-style"
									name="vkbm_provider_settings[design_menu_search_style]"
								>
									<?php foreach ( $design_menu_search_style_choices as $style_value => $style_label ) : ?>
										<option value="<?php echo esc_attr( (string) $style_value ); ?>" <?php selected( $design_menu_search_style, $style_value ); ?>>
											<?php echo esc_html( $style_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'When you select "Surround with a frame", the search on the booking page is surrounded by the same border as service menu cards.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-advanced">
							<th scope="row">
								<label for="vkbm-shift-auto-register-months"><?php echo esc_html( self::get_shift_auto_register_label() ); ?></label>
							</th>
							<td>
								<select
									id="vkbm-shift-auto-register-months"
									name="vkbm_provider_settings[shift_auto_register_months]"
								>
									<option value="0" <?php selected( $shift_auto_register_months, 0 ); ?>>
										<?php esc_html_e( 'Disabled', 'vk-booking-manager' ); ?>
									</option>
									<option value="1" <?php selected( $shift_auto_register_months, 1 ); ?>>
										<?php esc_html_e( 'Up to next month', 'vk-booking-manager' ); ?>
									</option>
									<option value="2" <?php selected( $shift_auto_register_months, 2 ); ?>>
										<?php esc_html_e( 'Up to 2 months ahead', 'vk-booking-manager' ); ?>
									</option>
									<option value="3" <?php selected( $shift_auto_register_months, 3 ); ?>>
										<?php esc_html_e( 'Up to 3 months ahead', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php
									if ( Industry_Presets::supports( 'lodging' ) ) {
										/* translators: %s: resource label (singular), e.g. "staff" or "room". */
										$auto_register_description = __( 'At the time of saving, and then once a day after that, days on sale are automatically registered as published (not draft) for each %s, for any month without registered days on sale, from the current month through the selected month ahead, using the same logic as bulk registration of days on sale.', 'vk-booking-manager' );
									} else {
										/* translators: %s: resource label (singular), e.g. "staff" or "room". */
										$auto_register_description = __( 'At the time of saving, and then once a day after that, shifts are automatically registered as published (not draft) for each %s, for any month without a registered shift, from the current month through the selected month ahead, using the same logic as bulk shift registration.', 'vk-booking-manager' );
									}
									echo esc_html(
										sprintf(
											$auto_register_description,
											vkbm_get_resource_label_singular()
										)
									);
									?>
								</p>
								<p class="description">
									<?php esc_html_e( 'A shift in the trash still counts as registered and is not re-created, but permanently deleting it will cause that month to be registered again the next time this runs.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-advanced">
							<th scope="row">
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php esc_html_e( 'Automatic anonymization of reservation personal data', 'vk-booking-manager' ); ?>
								<?php else : ?>
									<label for="vkbm-booking-auto-anonymize-months"><?php esc_html_e( 'Automatic anonymization of reservation personal data', 'vk-booking-manager' ); ?></label>
								<?php endif; ?>
							</th>
							<td>
								<?php if ( Pro_Upsell::is_free_edition() ) : ?>
									<?php echo Pro_Upsell::get_feature_notice_html( __( 'Automatically anonymizing personal data in reservations after a set period from their end is available in the Pro version.', 'vk-booking-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php else : ?>
									<select id="vkbm-booking-auto-anonymize-months" name="vkbm_provider_settings[booking_auto_anonymize_months]" data-initial-value="<?php echo esc_attr( (string) $booking_auto_anonymize_months ); ?>">
										<?php foreach ( Settings_Sanitizer::get_booking_auto_anonymize_month_choices() as $months ) : ?>
											<?php
											if ( 0 === $months ) {
												$period_label = '';
												$option_label = __( 'Do not anonymize automatically', 'vk-booking-manager' );
											} elseif ( 0 === $months % 12 ) {
												$years = (int) ( $months / 12 );
												/* translators: %s: Number of years. */
												$period_label = sprintf( _n( '%s year', '%s years', $years, 'vk-booking-manager' ), number_format_i18n( $years ) );
												/* translators: %s: Human-readable retention period. */
												$option_label = sprintf( __( '%s after the end', 'vk-booking-manager' ), $period_label );
											} else {
												/* translators: %s: Number of months. */
												$period_label = sprintf( _n( '%s month', '%s months', $months, 'vk-booking-manager' ), number_format_i18n( $months ) );
												/* translators: %s: Human-readable retention period. */
												$option_label = sprintf( __( '%s after the end', 'vk-booking-manager' ), $period_label );
											}
											?>
											<option value="<?php echo esc_attr( (string) $months ); ?>" data-period="<?php echo esc_attr( $period_label ); ?>" <?php selected( $booking_auto_anonymize_months, $months ); ?>>
												<?php echo esc_html( $option_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php /* translators: %s: Personal data field labels. */ echo esc_html( sprintf( __( 'Once a day, %s in reservations past the selected period after their end are removed and the reservation is unlinked from the customer. Dates, services, prices, and reservation status remain.', 'vk-booking-manager' ), \VKBookingManager\Bookings\Booking_Anonymizer::get_field_labels_text() ) ); ?></p>
									<p class="description"><?php esc_html_e( 'After enabling and saving, reservations already past the period are also anonymized. Large numbers are processed over several days.', 'vk-booking-manager' ); ?></p>
									<p class="description">
										<?php
										printf(
											/* translators: %1$s: Link to email settings. */
											wp_kses_post( __( 'Names and notes in sent email and email logs are not removed. Set the email log retention period in %1$s.', 'vk-booking-manager' ) ),
											'<a href="' . esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=email#vkbm-email-log-retention-days' ) ) . '">' . esc_html__( 'Email settings', 'vk-booking-manager' ) . '</a>'
										);
										?>
									</p>
									<?php $auto_state = get_option( 'vkbm_booking_auto_anonymize_state', array() ); ?>
									<p class="description">
										<?php
										if ( $booking_auto_anonymize_months > 0 && is_array( $auto_state ) && ! empty( $auto_state['completed_at'] ) && strtotime( (string) $auto_state['completed_at'] . ' UTC' ) <= time() - ( 2 * DAY_IN_SECONDS ) ) {
											esc_html_e( 'More than two days have passed since the last run. It may not run when there is no access to the site.', 'vk-booking-manager' );
										} elseif ( is_array( $auto_state ) && ! empty( $auto_state['completed_at'] ) ) {
											/* translators: 1: Last run date, 2: anonymized count, 3: next run date. */
											echo esc_html( sprintf( __( 'Last run: %1$s (%2$s anonymized) / Next run: %3$s around', 'vk-booking-manager' ), get_date_from_gmt( (string) $auto_state['completed_at'], 'Y-m-d H:i' ), number_format_i18n( (int) ( $auto_state['anonymized'] ?? 0 ) ), wp_next_scheduled( 'vkbm_booking_auto_anonymize_daily' ) ? wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( 'vkbm_booking_auto_anonymize_daily' ) ) : __( 'not scheduled', 'vk-booking-manager' ) ) );
											if ( ! empty( $auto_state['failed'] ) ) {
												/* translators: %s: Number of failed reservations. */
												echo ' ' . esc_html( sprintf( __( '(%s failed to save and will be tried again next time)', 'vk-booking-manager' ), number_format_i18n( (int) $auto_state['failed'] ) ) );
											}
										} else {
											esc_html_e( 'Not run yet.', 'vk-booking-manager' );
										}
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<?php // データを消す設定のため、manage_options を持つ利用者にだけ表示する（保存側も同じ権限で制限している）。 ?>
						<?php if ( $show_uninstall_cleanup ) : ?>
						<tr class="vkbm-provider-settings__tab-advanced">
							<th scope="row">
								<label for="vkbm-uninstall-cleanup"><?php esc_html_e( 'Cleanup when the plugin is deleted', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<select
									id="vkbm-uninstall-cleanup"
									name="vkbm_provider_settings[uninstall_cleanup]"
								>
									<option value="<?php echo esc_attr( Uninstaller::MODE_KEEP ); ?>" <?php selected( $uninstall_cleanup, Uninstaller::MODE_KEEP ); ?>>
										<?php esc_html_e( 'Keep (Google integration data is deleted)', 'vk-booking-manager' ); ?>
									</option>
									<option value="<?php echo esc_attr( Uninstaller::MODE_DELETE_ALL ); ?>" <?php selected( $uninstall_cleanup, Uninstaller::MODE_DELETE_ALL ); ?>>
										<?php esc_html_e( 'Delete everything', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Specify how settings and booking data are handled when VK Booking Manager is uninstalled.', 'vk-booking-manager' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'If you choose "Delete everything", all data including booking data will be deleted. This cannot be undone after deletion.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<?php endif; ?>
						<tr class="vkbm-provider-settings__tab-email">
							<th scope="row">
								<label for="vkbm-provider-email"><?php esc_html_e( 'Representative email address', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="email"
									class="regular-text"
									id="vkbm-provider-email"
									name="vkbm_provider_settings[provider_email]"
									value="<?php echo esc_attr( $settings['provider_email'] ?? '' ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'This is mainly used as the reply-to email address for customer replies to booking notification emails and other emails sent by this booking system.', 'vk-booking-manager' ); ?></p>
								<?php // #510: 事業者向け通知の宛先が空/不正な場合に管理者メールへフォールバックすることを明記する。 ?>
								<p class="description"><?php esc_html_e( 'If left blank, notification emails to the provider are sent to the WordPress administration email address (Settings > General).', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-email">
							<th scope="row">
								<label for="vkbm-mail-from-email"><?php esc_html_e( 'Sender email address', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="email"
									class="regular-text"
									id="vkbm-mail-from-email"
									name="vkbm_provider_settings[mail_from_email]"
									value="<?php echo esc_attr( $mail_from_email ); ?>"
									aria-describedby="vkbm-mail-from-email-description<?php echo '' !== $mail_from_error ? ' vkbm-mail-from-email-error' : ''; ?><?php echo Mail_From::is_free_email( $mail_from_email ) ? ' vkbm-mail-from-email-warning' : ''; ?>"
								/>
								<?php if ( '' !== $mail_from_error ) : ?>
									<p id="vkbm-mail-from-email-error" class="vkbm-alert vkbm-alert__danger" role="alert"><?php echo esc_html( $mail_from_error ); ?></p>
								<?php endif; ?>
								<p id="vkbm-mail-from-email-description" class="description">
									<?php esc_html_e( 'This is the sender email address for emails sent by this booking system.', 'vk-booking-manager' ); ?>
									<?php if ( '' !== $default_mail_from ) : ?>
										<?php if ( $default_mail_from_no_reply ) : ?>
											<?php /* translators: %s: default sender email address. */ ?>
											<?php echo esc_html( sprintf( __( 'If left blank, email is sent from %s (replies to this address cannot be received).', 'vk-booking-manager' ), $default_mail_from ) ); ?>
										<?php else : ?>
											<?php /* translators: %s: default sender email address. */ ?>
											<?php echo esc_html( sprintf( __( 'If left blank, email is sent from %s.', 'vk-booking-manager' ), $default_mail_from ) ); ?>
										<?php endif; ?>
									<?php endif; ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'If the booking site uses a subdomain, enter an address on that domain whose email authentication settings, such as SPF and DKIM, have been configured.', 'vk-booking-manager' ); ?>
								</p>
								<?php if ( Mail_From::is_free_email( $mail_from_email ) ) : ?>
									<p id="vkbm-mail-from-email-warning" class="vkbm-alert vkbm-alert__warning" role="status">
										<?php esc_html_e( "Caution: Because the sender email address is a free email service such as Gmail or Yahoo! Mail, user registration and booking emails will generally not be delivered to users (they may not even reach the spam folder). Enter an email address on the same domain as the site, or use an SMTP plugin such as WP Mail SMTP to send through this address's account and turn on “Force From Email”.", 'vk-booking-manager' ); ?>
										<?php
										$email_delivery_help_link = sprintf(
											'<a href="%1$s">%2$s</a>',
											esc_url( '#vkbm-email-delivery-help' ),
											esc_html__( 'If emails are not being delivered', 'vk-booking-manager' )
										);
										/* translators: %s: link to the email delivery checklist. */
										echo wp_kses( sprintf( __( 'For details, see “%s”.', 'vk-booking-manager' ), $email_delivery_help_link ), array( 'a' => array( 'href' => array() ) ) );
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-email">
							<th scope="row">
								<label for="vkbm-booking-reminder-hours-0"><?php esc_html_e( 'Reservation reminder email', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<div class="vkbm-reminder-hours">
									<input
										type="hidden"
										id="vkbm-booking-reminder-next-index"
										value="<?php echo esc_attr( (string) $booking_reminder_next_index ); ?>"
									/>
									<div class="vkbm-reminder-hours__list" id="vkbm-booking-reminder-hours-list">
										<?php foreach ( $booking_reminder_rows as $index => $hours ) : ?>
											<div class="vkbm-reminder-hours__row">
												<input
													type="number"
													class="small-text"
													id="vkbm-booking-reminder-hours-<?php echo esc_attr( (string) $index ); ?>"
													name="vkbm_provider_settings[booking_reminder_hours][<?php echo esc_attr( (string) $index ); ?>]"
													min="1"
													step="1"
													value="<?php echo esc_attr( '' === $hours ? '' : (string) $hours ); ?>"
												/>
												<span class="vkbm-reminder-hours__suffix"><?php esc_html_e( 'hours before', 'vk-booking-manager' ); ?></span>
												<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-reminder-hours-remove" aria-label="<?php echo esc_attr( self::get_reminder_remove_label( '' === $hours ? '' : (string) $hours ) ); ?>">
													<?php esc_html_e( 'Remove', 'vk-booking-manager' ); ?>
												</button>
											</div>
										<?php endforeach; ?>
									</div>
									<button type="button" class="button vkbm-reminder-hours-add">
										<?php esc_html_e( 'Add time', 'vk-booking-manager' ); ?>
									</button>
									<p class="description">
										<?php esc_html_e( 'Sends reminder emails to customers before their reservation time.', 'vk-booking-manager' ); ?>
									</p>
								</div>
								<template id="vkbm-booking-reminder-template">
									<div class="vkbm-reminder-hours__row">
										<input
											type="number"
											class="small-text"
											id="vkbm-booking-reminder-hours-__INDEX__"
											name="vkbm_provider_settings[booking_reminder_hours][__INDEX__]"
											min="1"
											step="1"
											value=""
										/>
										<span class="vkbm-reminder-hours__suffix"><?php esc_html_e( 'hours before', 'vk-booking-manager' ); ?></span>
										<button type="button" class="button-link vkbm-reminder-hours-remove" aria-label="<?php echo esc_attr( self::get_reminder_remove_label( '' ) ); ?>">
											<?php esc_html_e( 'Remove', 'vk-booking-manager' ); ?>
										</button>
									</div>
								</template>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-email">
							<th scope="row"><?php esc_html_e( 'Email log', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										id="vkbm-email-log-enabled"
										name="vkbm_provider_settings[email_log_enabled]"
										value="1"
										<?php checked( $email_log_enabled ); ?>
									/>
									<?php esc_html_e( 'Enable email log', 'vk-booking-manager' ); ?>
								</label>
								<?php // #510: 記録対象（予約通知＋会員登録の確認メールのみ）が伝わる説明文に変更。 ?>
								<p class="description">
									<?php esc_html_e( 'When enabled, the Email Log page shows the send results of booking notification emails (including reminders) and the registration confirmation email.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-email">
							<th scope="row">
								<label for="vkbm-email-log-retention-days"><?php esc_html_e( 'Email log retention period', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									class="small-text"
									id="vkbm-email-log-retention-days"
									name="vkbm_provider_settings[email_log_retention_days]"
									min="1"
									step="1"
									value="<?php echo esc_attr( (string) $email_log_retention_days ); ?>"
								/>
								<?php esc_html_e( 'days', 'vk-booking-manager' ); ?>
								<p class="description">
									<?php esc_html_e( 'Logs older than this will be automatically deleted.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-faq">
							<th scope="row">
								<?php esc_html_e( 'FAQ', 'vk-booking-manager' ); ?>
							</th>
							<td>
								<dl class="vkbm-provider-settings__faq">
									<?php $this->render_email_delivery_help( 'vkbm-email-delivery-help-faq', add_query_arg( 'tab', 'email', $base_url ) . '#vkbm-mail-from-email', $email_log_enabled, add_query_arg( 'tab', 'email', $base_url ) . '#vkbm-email-log-enabled', true ); ?>
								</dl>
							</td>
						</tr>
					<?php if ( $show_license_tab ) : ?>
						<tr class="vkbm-provider-settings__tab-license">
							<th scope="row">
								<label for="vkbm-license-key"><?php esc_html_e( 'License key', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<?php if ( $has_saved_license_key ) : ?>
									<p id="vkbm-license-key-status" class="description">
										<?php esc_html_e( 'License key is saved.', 'vk-booking-manager' ); ?>
									</p>
								<?php endif; ?>
								<input
									type="password"
									id="vkbm-license-key"
									name="vkbm_provider_settings[license_key]"
									value=""
									class="regular-text"
									autocomplete="new-password"
									<?php if ( $has_saved_license_key ) : ?>
										aria-describedby="vkbm-license-key-status vkbm-license-key-description"
									<?php endif; ?>
								/>
								<?php if ( $has_saved_license_key ) : ?>
									<p id="vkbm-license-key-description" class="description">
										<?php esc_html_e( 'Leave empty if you do not want to change it.', 'vk-booking-manager' ); ?>
									</p>
									<p>
										<label for="vkbm-delete-license-key">
											<input
												type="checkbox"
												id="vkbm-delete-license-key"
												name="vkbm_provider_settings[delete_license_key]"
												value="1"
											/>
											<?php esc_html_e( 'Delete the saved license key', 'vk-booking-manager' ); ?>
										</label>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php
				// サービスメニューの表示方法・通貨記号・税表記の入力欄は「システム」タブのときだけ描画される。
				// 保存時、送信されなかった項目は初期値で埋められるため、ほかのタブ（ライセンスタブを含む）で
				// 保存したときも 3 項目が初期値に戻らないよう、現在値を hidden で一緒に送る（issue #556）。
				?>
				<?php
				// 送信元メールアドレスの入力欄は「メール設定」タブの行にあるため、ほかのタブで保存しても
				// 保存済みの値が消えないよう、現在値を hidden で送る。メール設定タブでは入力欄の値を使うため出さない。
				?>
				<?php if ( 'email' !== $active_tab ) : ?>
					<input type="hidden" name="vkbm_provider_settings[mail_from_email]" value="<?php echo esc_attr( $mail_from_email ); ?>" />
				<?php endif; ?>
				<?php if ( 'system' !== $active_tab ) : ?>
					<input type="hidden" name="vkbm_provider_settings[reservation_menu_list_display_mode]" value="<?php echo esc_attr( $reservation_menu_list_display_mode ); ?>" />
					<input type="hidden" name="vkbm_provider_settings[service_menu_detail_display_mode]" value="<?php echo esc_attr( $service_menu_detail_display_mode ); ?>" />
					<input type="hidden" name="vkbm_provider_settings[currency_symbol]" value="<?php echo esc_attr( $currency_symbol ); ?>" />
					<input type="hidden" name="vkbm_provider_settings[tax_label_text]" value="<?php echo esc_attr( $tax_label_text ); ?>" />
				<?php endif; ?>

				<?php if ( Pro_Upsell::is_free_edition() && ! Staff_Editor::is_nomination_enabled() ) : ?>
					<?php
					// 指名機能が無効でも、ラベルの保存値を維持するために hidden フィールドで送信する。
					// Preserve saved label values via hidden fields even when nomination is disabled.
					?>
					<input type="hidden" name="vkbm_provider_settings[no_nomination_label]" value="<?php echo esc_attr( $no_nomination_label ); ?>" />
					<input type="hidden" name="vkbm_provider_settings[nomination_fee_label]" value="<?php echo esc_attr( $nomination_fee_label ); ?>" />
				<?php endif; ?>

				<?php submit_button( __( 'Save changes', 'vk-booking-manager' ) ); ?>

				<?php if ( 'email' === $active_tab ) : ?>
					<?php // メールが届かない場合の案内は、再読み込みで入力中の値が消えないよう、送信元メールアドレスへのリンクをページ内アンカーにする。 ?>
					<div class="vkbm-provider-settings__email-help">
						<?php $this->render_email_delivery_help( 'vkbm-email-delivery-help', '#vkbm-mail-from-email', $email_log_enabled, '', false ); ?>
					</div>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<?php
			/**
			 * メインフォームの外（ページ wrapper div 内）に追加コンテンツを挿入する拡張ポイント。
			 * 拡張プラグインはここに独自の <form> をレンダリングする（メインフォームとは別 nonce）。
			 * 拡張プラグインのタブ（本体にないタブ）では、本体のメインフォームと「変更を保存」ボタンは出力されない。
			 *
			 * @param string $active_tab 表示中のタブスラッグ。
			 */
			do_action( 'vkbm_provider_settings_extra_content', $active_tab );
			?>
		</div>
		<?php
	}

	/**
	 * シフト自動登録設定のラベルを業種に応じて返す。
	 *
	 * @return string 設定ラベル。
	 */
	public static function get_shift_auto_register_label(): string {
		return Industry_Presets::supports( 'lodging' )
			? __( 'Automatic registration of days on sale', 'vk-booking-manager' )
			: __( 'Shift auto registration', 'vk-booking-manager' );
	}

	/**
	 * 予約リマインダーの「削除」ボタンの読み上げ用ラベルを返す。
	 *
	 * 全行が同じ「削除」と読み上げられないよう、時間が入力済みの行は「◯時間前を削除」にする。
	 *
	 * @param string $hours 行に入力されている時間（未入力は空文字）。
	 * @return string 読み上げ用ラベル。
	 */
	private static function get_reminder_remove_label( string $hours ): string {
		if ( '' === $hours ) {
			return __( 'Remove', 'vk-booking-manager' );
		}

		/* translators: %s: number of hours before the reservation. */
		return sprintf( __( 'Remove the reminder %s hours before', 'vk-booking-manager' ), $hours );
	}

	/**
	 * 「メールが届かない場合」の確認手順を出力する。
	 *
	 * 「FAQ」タブ（dl 内の dt / dd）と「メール設定」タブ（見出し h2 と本文）の両方から呼ぶ共通パーツ。
	 * 同じページ内で id が重複しないよう、見出しの id は呼び出し側から受け取る。
	 *
	 * @param string $anchor_id           見出しに付けるアンカー id。
	 * @param string $mail_from_url       「送信元メールアドレス」設定へのリンク先 URL（ページ内アンカーでもよい）。
	 * @param bool   $email_log_enabled   メールログが有効かどうか。無効のときは有効にする案内を出す。
	 * @param string $email_log_setting_url メールログの設定へのリンク先 URL。空文字のときは「このタブ」で有効にする案内にする。
	 * @param bool   $is_faq_item         true のとき dt / dd として出力する。false のとき h2 と div として出力する。
	 */
	private function render_email_delivery_help( string $anchor_id, string $mail_from_url, bool $email_log_enabled, string $email_log_setting_url, bool $is_faq_item ): void {
		$email_log_url = admin_url( 'admin.php?page=vkbm-email-log' );
		$heading       = __( 'If emails are not being delivered', 'vk-booking-manager' );

		// 見出しと本文の囲みを、呼び出し元（FAQ / メール設定タブ）に合わせて出し分ける。
		if ( $is_faq_item ) {
			echo '<dt id="' . esc_attr( $anchor_id ) . '">' . esc_html( $heading ) . '</dt><dd>';
		} else {
			echo '<h2 id="' . esc_attr( $anchor_id ) . '">' . esc_html( $heading ) . '</h2><div class="vkbm-provider-settings__email-help-body">';
		}
		?>
		<p><?php esc_html_e( 'If registration confirmation emails or booking notification emails are not being delivered, check the following items in order from the top.', 'vk-booking-manager' ); ?></p>
		<ol>
			<li>
				<?php if ( $email_log_enabled ) : ?>
					<strong><a href="<?php echo esc_url( $email_log_url ); ?>"><?php esc_html_e( 'Check the Email Log', 'vk-booking-manager' ); ?></a></strong> — <?php esc_html_e( 'Open "BM settings" > "Email Log" in the admin menu and check the "Status" column for the email that did not arrive.', 'vk-booking-manager' ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Turn on the Email Log', 'vk-booking-manager' ); ?></strong> —
					<?php
					if ( '' !== $email_log_setting_url ) {
						$email_log_setting_link = sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( $email_log_setting_url ),
							esc_html__( 'BM settings > Email settings > Email log', 'vk-booking-manager' )
						);
						/* translators: %s: link to the email log setting. */
						echo wp_kses( sprintf( __( 'Turn on "Enable email log" in %s and save the settings.', 'vk-booking-manager' ), $email_log_setting_link ), array( 'a' => array( 'href' => array() ) ) );
					} else {
						esc_html_e( 'Turn on "Enable email log" on this tab and save the settings.', 'vk-booking-manager' );
					}
					?>
					<?php esc_html_e( 'Open "BM settings" > "Email Log" in the admin menu and check the "Status" column for the email that did not arrive.', 'vk-booking-manager' ); ?>
				<?php endif; ?>
				<ul class="ul-disc">
					<li><?php esc_html_e( 'If it shows "Failed" or "Not sent": the email could not be sent.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Check the message shown in the "Error" column.', 'vk-booking-manager' ); ?></li>
					<li><?php esc_html_e( 'If it shows "Sent": the email was sent.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'It was either rejected on the receiving side or sorted into the spam folder.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Continue with the items below.', 'vk-booking-manager' ); ?></li>
				</ul>
			</li>
			<li><strong><?php esc_html_e( 'Check the spam folder', 'vk-booking-manager' ); ?></strong> — <?php esc_html_e( 'Ask the recipient to check whether the email is in their spam folder.', 'vk-booking-manager' ); ?></li>
			<li>
				<strong><?php esc_html_e( 'Use a sender email address on the same domain as the site', 'vk-booking-manager' ); ?></strong> — <?php esc_html_e( 'If the sender is a free email address such as Gmail or Yahoo! Mail, emails will generally not be delivered.', 'vk-booking-manager' ); ?>
				<?php
				$sender_email_setting_link = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $mail_from_url ),
					esc_html__( 'BM settings > Email settings > Sender email address', 'vk-booking-manager' )
				);
				/* translators: %s: link to the sender email address setting. */
				echo wp_kses( sprintf( __( 'In %s, set an email address on the same domain as the site.', 'vk-booking-manager' ), $sender_email_setting_link ), array( 'a' => array( 'href' => array() ) ) );
				?>
			</li>
			<li><strong><?php esc_html_e( 'Set up SPF and DKIM on the server', 'vk-booking-manager' ); ?></strong> — <?php esc_html_e( 'SPF and DKIM are mechanisms that prove an email was sent from a legitimate sender.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Without them, emails (especially those sent to Gmail) may not even reach the spam folder.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Configure them in your hosting control panel.', 'vk-booking-manager' ); ?></li>
			<li><strong><?php esc_html_e( 'If emails still do not arrive, send via SMTP', 'vk-booking-manager' ); ?></strong> — <?php esc_html_e( 'Use a plugin such as WP Mail SMTP to send emails through a mail server.', 'vk-booking-manager' ); ?></li>
		</ol>
		<?php
		echo $is_faq_item ? '</dd>' : '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定の閉じタグ.
	}

	/**
	 * Day labels used for business hours table.
	 *
	 * @return array<string, string>
	 */
	private function get_business_hours_day_labels(): array {
		return array(
			'mon'         => __( 'Monday', 'vk-booking-manager' ),
			'tue'         => __( 'Tuesday', 'vk-booking-manager' ),
			'wed'         => __( 'Wednesday', 'vk-booking-manager' ),
			'thu'         => __( 'Thursday', 'vk-booking-manager' ),
			'fri'         => __( 'Friday', 'vk-booking-manager' ),
			'sat'         => __( 'Saturday', 'vk-booking-manager' ),
			'sun'         => __( 'Sunday', 'vk-booking-manager' ),
			'holiday'     => __( 'Holiday', 'vk-booking-manager' ),
			'holiday_eve' => __( 'The day before a public holiday', 'vk-booking-manager' ),
		);
	}

	/**
	 * Default business hours weekly structure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_business_hours_weekly(): array {
		$keys = array(
			'mon',
			'tue',
			'wed',
			'thu',
			'fri',
			'sat',
			'sun',
			'holiday',
			'holiday_eve',
		);

		$defaults = array();

		foreach ( $keys as $key ) {
			$defaults[ $key ] = array(
				'use_custom' => false,
				'time_slots' => array(),
			);
		}

		return $defaults;
	}

	/**
	 * Build a lookup of weekdays that are set as weekly closed days.
	 *
	 * @param array<int, array<string, string>> $regular_holidays Regular holiday rules.
	 * @return array<string, bool>
	 */
	private function get_weekly_closed_days( array $regular_holidays ): array {
		$closed = array();

		foreach ( $regular_holidays as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			if ( ( $rule['frequency'] ?? '' ) !== 'weekly' ) {
				continue;
			}

			$weekday = $rule['weekday'] ?? '';

			if ( '' === $weekday ) {
				continue;
			}

			$closed[ $weekday ] = true;
		}

		return $closed;
	}

	/**
	 * Normalize basic business hours slots for display.
	 *
	 * @param array<int, array<string, string>> $slots Raw slots.
	 * @return array<int, array<string, string>>
	 */
	private function prepare_basic_business_hours_slots( array $slots ): array {
		$prepared = array();

		foreach ( $slots as $index => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start_parts = $this->split_time_components( $slot['start'] ?? '' );
			$end_parts   = $this->split_time_components( $slot['end'] ?? '' );

			$prepared[] = array(
				'index'        => (string) $index,
				'start_hour'   => $start_parts['hour'],
				'start_minute' => $start_parts['minute'],
				'end_hour'     => $end_parts['hour'],
				'end_minute'   => $end_parts['minute'],
			);
		}

		return $prepared;
	}

	/**
	 * Normalize weekly business hours slots for display.
	 *
	 * @param array<string, mixed> $day_settings         Day settings.
	 * @param array<string, mixed> $basic_template_slots Basic template slots.
	 * @param bool                 $use_custom           Whether to use custom settings.
	 * @return array<int, array<string, string>>
	 */
	private function prepare_weekly_business_hours_slots( array $day_settings, array $basic_template_slots, bool $use_custom ): array {
		$prepared = array();

		if ( isset( $day_settings['time_slots'] ) && is_array( $day_settings['time_slots'] ) ) {
			foreach ( $day_settings['time_slots'] as $index => $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}

				$start_parts = $this->split_time_components( $slot['start'] ?? '' );
				$end_parts   = $this->split_time_components( $slot['end'] ?? '' );

				$prepared[] = array(
					'index'        => (string) $index,
					'start_hour'   => $start_parts['hour'],
					'start_minute' => $start_parts['minute'],
					'end_hour'     => $end_parts['hour'],
					'end_minute'   => $end_parts['minute'],
				);
			}
		}

		if ( empty( $prepared ) && $use_custom ) {
			foreach ( $basic_template_slots as $index => $slot ) {
				$prepared[] = array(
					'index'        => (string) $index,
					'start_hour'   => $slot['start_hour'],
					'start_minute' => $slot['start_minute'],
					'end_hour'     => $slot['end_hour'],
					'end_minute'   => $slot['end_minute'],
				);
			}
		}

		return $prepared;
	}

	/**
	 * Determine the next slot index based on prepared slots.
	 *
	 * @param array<int, array<string, string>> $slots Slot definitions.
	 * @return int
	 */
	private function get_next_slot_index( array $slots ): int {
		$max = -1;

		foreach ( $slots as $slot ) {
			if ( ! isset( $slot['index'] ) ) {
				continue;
			}

			$index = (int) $slot['index'];

			if ( $index > $max ) {
				$max = $index;
			}
		}

		return $max + 1;
	}

	/**
	 * 業種プリセットで自動設定される（＝設定画面に表示しない）項目のキー一覧を返す。
	 *
	 * provider-settings.js の update() と同じ判定をサーバー側でも行い、対象行を最初の
	 * 描画から hidden 属性付きで出力するために使う。JS 実行前の一瞬だけ対象行が
	 * 見えてしまう状態（#387 の実装では JS でのみ隠していた）を防ぐ。
	 *
	 * @param array<string, array<string, mixed>> $presets    フィルター適用済みのプリセット一覧。
	 * @param string                              $preset_key 現在の業種プリセットキー。
	 * @return array<int, string> 表示しない項目のキー一覧。
	 */
	private static function get_industry_hidden_fields( array $presets, string $preset_key ): array {
		if ( Pro_Upsell::is_free_edition() || 'custom' === $preset_key ) {
			return array();
		}

		$hidden = array();
		foreach ( $presets[ $preset_key ]['fields'] ?? array() as $field_key => $field ) {
			if ( empty( $field['display'] ) && in_array( $field_key, Industry_Presets::FIELD_KEYS, true ) ) {
				$hidden[] = (string) $field_key;
			}
		}

		return $hidden;
	}

	/**
	 * 無料版の基本設定画面に出す、呼び方の案内の内容を返す。
	 *
	 * 無料版で、保存されている呼び方（単数・複数・メニュー名）のいずれかが初期値から変わっているときだけ返す。
	 * 単数・複数のどちらかが変わっていれば、その値（単数を優先）を 'label' に入れる。
	 * メニュー名だけが変わっている場合は 'label' が空文字になり、呼び方を差し込まない文言を使う。
	 * Pro 版、または初期値のままの場合は null を返す（案内を出さない）。
	 *
	 * @return array{label:string}|null 案内の内容。案内が不要なら null。
	 */
	private static function get_free_edition_resource_label_notice(): ?array {
		// Pro 版では入力欄が出るため案内は不要。
		if ( ! Pro_Upsell::is_free_edition() ) {
			return null;
		}

		$default_name = __( 'Staff', 'vk-booking-manager' );
		$singular     = vkbm_get_saved_resource_label_singular();
		$plural       = vkbm_get_saved_resource_label_plural();

		// 単数・複数のうち、初期値から変えられている呼び方を探す（単数を優先）。
		if ( $singular !== $default_name ) {
			return array( 'label' => $singular );
		}
		if ( $plural !== $default_name ) {
			return array( 'label' => $plural );
		}

		// メニュー名だけが変えられている場合。
		if ( vkbm_get_saved_resource_label_menu() !== __( 'Staff available', 'vk-booking-manager' ) ) {
			return array( 'label' => '' );
		}

		return null;
	}

	/**
	 * 業種プリセットの対象行に付与する hidden 属性を返す。
	 *
	 * @param array<int, string> $hidden_fields 表示しない項目のキー一覧。
	 * @param string             $field_key     対象行の設定キー。
	 * @return string 非表示なら 'hidden'、表示するなら空文字列。
	 */
	private static function industry_row_hidden_attr( array $hidden_fields, string $field_key ): string {
		return in_array( $field_key, $hidden_fields, true ) ? 'hidden' : '';
	}

	/**
	 * リソースメニューアイコンのプリセット Dashicons クラス名一覧を返す。
	 *
	 * 「スタッフ」「ルーム」「コート」など、リソース種別として想定される用途を
	 * カバーするアイコンを選定している。クリック選択用のグリッドに表示される。
	 *
	 * @return array<int, string> Dashicons クラス名の配列。
	 */
	private function get_resource_menu_icon_presets(): array {
		return array(
			'dashicons-groups',
			'dashicons-businessperson',
			'dashicons-businessman',
			'dashicons-businesswoman',
			'dashicons-admin-users',
			'dashicons-id',
			'dashicons-welcome-learn-more',
			'dashicons-building',
			'dashicons-admin-home',
			'dashicons-admin-multisite',
			'dashicons-store',
			'dashicons-location',
			'dashicons-calendar-alt',
			'dashicons-products',
			'dashicons-palmtree',
			'dashicons-car',
			'dashicons-pets',
			'dashicons-clock',
		);
	}

	/**
	 * 入力エラー時の送信値を一時保存する transient のキーを返す。
	 *
	 * 複数の管理者が同時に操作しても、別の管理者の入力内容が復元されないよう、ユーザー単位にする。
	 *
	 * @return string
	 */
	private function get_old_input_transient_key(): string {
		return self::OLD_INPUT_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * 項目ごとのエラーを一時保存する transient のキーを返す。
	 *
	 * 複数の管理者が同時に操作しても、別の管理者の項目エラーが表示されないよう、ユーザー単位にする。
	 *
	 * @return string
	 */
	private function get_field_errors_transient_key(): string {
		return self::FIELD_ERRORS_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * Sanitize previously submitted input for redisplay after validation errors.
	 *
	 * @param array<string, mixed> $input Raw input array.
	 * @return array<string, mixed>
	 */
	private function sanitize_old_input( array $input ): array {
		$output                    = array();
		$output['industry_preset'] = Industry_Presets::sanitize_preset_key( $input['industry_preset'] ?? 'custom' );

		$output['provider_name']           = sanitize_text_field( $input['provider_name'] ?? '' );
		$output['provider_address']        = sanitize_textarea_field( $input['provider_address'] ?? '' );
		$output['provider_phone']          = sanitize_text_field( $input['provider_phone'] ?? '' );
		$output['provider_payment_method'] = sanitize_textarea_field( $input['provider_payment_method'] ?? '' );
		$output['staff_enabled']           = ! empty( $input['staff_enabled'] );
		// 無料版では指名機能は常に無効のため、再表示時も無効へ強制し保存経路と一致させる。
		if ( Pro_Upsell::is_free_edition() ) {
			$output['staff_enabled'] = false;
		}
		// 予約枠の定員（同一枠で複数人を受け入れる）機能の有効/無効を再表示用に復元する。
		// セレクトが送信されていない（指名ON時の disabled など）場合は有効として扱い、後方互換を維持する。
		// 新キー slot_capacity_enabled を優先し、旧キー multiple_guests_enabled の送信にもフォールバックする。
		if ( array_key_exists( 'slot_capacity_enabled', $input ) ) {
			$output['slot_capacity_enabled'] = ! empty( $input['slot_capacity_enabled'] );
		} elseif ( array_key_exists( 'multiple_guests_enabled', $input ) ) {
			$output['slot_capacity_enabled'] = ! empty( $input['multiple_guests_enabled'] );
		} else {
			$output['slot_capacity_enabled'] = true;
		}
		// 無料版では予約枠の定員機能は常に無効のため、再表示時も無効へ強制する。
		if ( Pro_Upsell::is_free_edition() ) {
			$output['slot_capacity_enabled'] = false;
		}
		$output['resource_label_singular'] = sanitize_text_field( $input['resource_label_singular'] ?? __( 'Staff', 'vk-booking-manager' ) );
		$output['resource_label_plural']   = sanitize_text_field( $input['resource_label_plural'] ?? __( 'Staff', 'vk-booking-manager' ) );
		$output['resource_label_menu']     = sanitize_text_field( $input['resource_label_menu'] ?? __( 'Staff available', 'vk-booking-manager' ) );
		$output['resource_menu_icon']      = vkbm_sanitize_resource_menu_icon( $input['resource_menu_icon'] ?? '' );
		$output['no_nomination_label']     = sanitize_text_field( $input['no_nomination_label'] ?? '' );
		$output['nomination_fee_label']    = sanitize_text_field( $input['nomination_fee_label'] ?? '' );
		$output['duration_label']          = sanitize_text_field( $input['duration_label'] ?? __( 'Time', 'vk-booking-manager' ) );
		$output['other_conditions_label']  = sanitize_text_field( $input['other_conditions_label'] ?? __( 'Other conditions', 'vk-booking-manager' ) );
		// 数量の見出しは兄弟ラベル同様に送信値を復元する（空欄は空のまま保持し、表示側で既定へフォールバック）。
		$output['guests_count_label'] = sanitize_text_field( $input['guests_count_label'] ?? '' );
		// 数量の単位は送信文字列をそのまま復元する（空欄＝単位なしの意図も保持する）。
		// フォームは実効値をプリフィルするため、検証エラー再表示時もキーは送信される想定。
		$output['guests_unit_label']                              = array_key_exists( 'guests_unit_label', $input )
			? sanitize_text_field( (string) $input['guests_unit_label'] )
			: null;
			$output['provider_business_hours']                    = sanitize_textarea_field( $input['provider_business_hours'] ?? '' );
			$output['provider_reservation_deadline_hours']        = absint( $input['provider_reservation_deadline_hours'] ?? 0 );
			$output['provider_max_advance_booking_days']          = absint( $input['provider_max_advance_booking_days'] ?? 0 );
			$output['provider_service_menu_buffer_after_minutes'] = absint( $input['provider_service_menu_buffer_after_minutes'] ?? 0 );
			$output['provider_booking_status_mode']               = sanitize_key( (string) ( $input['provider_booking_status_mode'] ?? Settings_Repository::BOOKING_STATUS_MODE_PENDING ) );
		$output['provider_booking_complete_message_pending']      = Settings_Sanitizer::sanitize_booking_complete_message( $input['provider_booking_complete_message_pending'] ?? '' );
		$output['provider_booking_cancel_mode']                   = sanitize_key( (string) ( $input['provider_booking_cancel_mode'] ?? 'hours' ) );
		$output['provider_booking_cancel_deadline_hours']         = absint( $input['provider_booking_cancel_deadline_hours'] ?? 24 );
		$output['provider_website_url']                           = sanitize_text_field( $input['provider_website_url'] ?? '' );
		$output['reservation_page_url']                           = sanitize_text_field( $input['reservation_page_url'] ?? '' );
		$output['reservation_show_menu_search']                   = ! empty( $input['reservation_show_menu_search'] );
		$output['reservation_show_menu_list']                     = ! empty( $input['reservation_show_menu_list'] );
		$output['reservation_menu_list_display_mode']             = sanitize_key( (string) ( $input['reservation_menu_list_display_mode'] ?? 'card' ) );
		$output['reservation_show_provider_logo']                 = ! empty( $input['reservation_show_provider_logo'] );
		$output['reservation_show_provider_name']                 = ! empty( $input['reservation_show_provider_name'] );
		$output['reservation_show_footer']                        = ! empty( $input['reservation_show_footer'] );
		$output['currency_symbol']                                = sanitize_text_field( $input['currency_symbol'] ?? '' );
		$output['provider_email']                                 = sanitize_text_field( $input['provider_email'] ?? '' );
		$output['mail_from_email']                                = is_string( $input['mail_from_email'] ?? null ) ? sanitize_text_field( $input['mail_from_email'] ) : '';
		$output['provider_logo_id']                               = isset( $input['provider_logo_id'] ) ? absint( $input['provider_logo_id'] ) : 0;
		$output['provider_cancellation_policy']                   = sanitize_textarea_field( $input['provider_cancellation_policy'] ?? '' );
		$output['provider_terms_of_service']                      = sanitize_textarea_field( $input['provider_terms_of_service'] ?? '' );
		$output['membership_redirect_wp_login']                   = ! empty( $input['membership_redirect_wp_login'] );
		$output['email_log_enabled']                              = ! empty( $input['email_log_enabled'] );
		$output['email_log_retention_days']                       = max( 1, absint( $input['email_log_retention_days'] ?? 1 ) );
		$output['booking_reminder_hours']                         = $this->sanitize_reminder_hours_input( $input['booking_reminder_hours'] ?? array() );
		$output['provider_regular_holidays_disabled']             = ! empty( $input['provider_regular_holidays_disabled'] );
		$output['provider_regular_holidays']                      = $output['provider_regular_holidays_disabled']
			? array()
			: $this->sanitize_regular_holidays_input( $input['provider_regular_holidays'] ?? array() );
		$output['provider_business_hours_basic']                  = $this->sanitize_basic_posted_slots( $input['provider_business_hours_basic'] ?? array() );
		$output['provider_business_hours_weekly']                 = $this->sanitize_weekly_posted_slots( $input['provider_business_hours_weekly'] ?? array() );
		$output['menu_loop_reserve_button_label']                 = sanitize_text_field( $input['menu_loop_reserve_button_label'] ?? '' );
		$output['menu_loop_detail_button_label']                  = sanitize_text_field( $input['menu_loop_detail_button_label'] ?? '' );
		$output['closed_day_label']                               = sanitize_text_field( $input['closed_day_label'] ?? '' );
		// 宿泊プランの休前日の曜日・特定日（#737）。欄が送られた保存だけ、直前の入力を復元する（送られなければ保存済みの値の表示に任せる）。
		if ( array_key_exists( 'lodging_pre_holiday_weekdays', $input ) ) {
			$output['lodging_pre_holiday_weekdays'] = Lodging_Rate::normalize_weekdays( $input['lodging_pre_holiday_weekdays'] );
		}
		if ( array_key_exists( 'lodging_special_dates', $input ) ) {
			$output['lodging_special_dates'] = $this->sanitize_lodging_special_dates_input( $input['lodging_special_dates'] );
		}

		// 上限・既定値・親子項目の連動（例: 絞り込み検索が OFF ならリソースタグ検索も OFF）を
		// 保存時と食い違わせないよう、保存時と同じ Settings_Sanitizer を通した値で復元する。
		// ここで得たエラーは使わない（エラーの表示は保存処理の結果で行っている）。
		$sanitized_by_save_rules = ( new Settings_Sanitizer() )->sanitize(
			$input,
			$this->settings_service->get_default_settings()
		);
		foreach ( self::OLD_INPUT_KEYS_FROM_SANITIZER as $key ) {
			// 万一サニタイズ結果にキーが無い場合は復元せず、保存済みの値の表示に任せる。
			if ( array_key_exists( $key, $sanitized_by_save_rules ) ) {
				$output[ $key ] = $sanitized_by_save_rules[ $key ];
			}
		}
		// モード無効時は見出し欄が disabled で送信されないため、既定の空文字で保存値を上書きしない。
		if ( ! array_key_exists( 'booking_attendee_name_label', $input ) ) {
			unset( $output['booking_attendee_name_label'] );
		}

		return $output;
	}

	/**
	 * Prepare basic business hours slots for display.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param string               $base_url Base URL for the settings page.
	 * @return array<int, array<string, string>>
	 */

	/**
	 * Sanitize posted basic business hour slots for redisplay.
	 *
	 * @param mixed $raw Raw slots input.
	 * @return array<int|string, array<string, string>>
	 */
	private function sanitize_basic_posted_slots( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $index => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start_hour = sanitize_text_field( $slot['start_hour'] ?? '' );
			$start_min  = sanitize_text_field( $slot['start_minute'] ?? '' );
			$end_hour   = sanitize_text_field( $slot['end_hour'] ?? '' );
			$end_min    = sanitize_text_field( $slot['end_minute'] ?? '' );

			$sanitized[ (string) $index ] = array(
				'index' => (string) $index,
				'start' => $this->normalize_time_from_parts( $start_hour, $start_min, false ),
				'end'   => $this->normalize_time_from_parts( $end_hour, $end_min, true ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize posted weekly business hour slots for redisplay.
	 *
	 * @param mixed $raw Raw weekly input.
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_weekly_posted_slots( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $day_key => $day_input ) {
			if ( ! is_array( $day_input ) ) {
				continue;
			}

			$sanitized_day = array(
				'use_custom' => ! empty( $day_input['use_custom'] ),
				'time_slots' => array(),
			);

			if ( isset( $day_input['time_slots'] ) && is_array( $day_input['time_slots'] ) ) {
				foreach ( $day_input['time_slots'] as $index => $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}

					$start_hour = sanitize_text_field( $slot['start_hour'] ?? '' );
					$start_min  = sanitize_text_field( $slot['start_minute'] ?? '' );
					$end_hour   = sanitize_text_field( $slot['end_hour'] ?? '' );
					$end_min    = sanitize_text_field( $slot['end_minute'] ?? '' );

					$sanitized_day['time_slots'][ (string) $index ] = array(
						'index' => (string) $index,
						'start' => $this->normalize_time_from_parts( $start_hour, $start_min, false ),
						'end'   => $this->normalize_time_from_parts( $end_hour, $end_min, true ),
					);
				}
			}

			$sanitized[ sanitize_key( (string) $day_key ) ] = $sanitized_day;
		}

		return $sanitized;
	}

	/**
	 * Normalize hour/minute parts into HH:MM format.
	 *
	 * @param string $hour     Hour input.
	 * @param string $minute   Minute input.
	 * @param bool   $allow_24 Whether 24:00 is allowed (end time only).
	 * @return string
	 */
	private function normalize_time_from_parts( string $hour, string $minute, bool $allow_24 ): string {
		if ( '' === $hour || '' === $minute ) {
			return '';
		}

		if ( ! ctype_digit( $hour ) || ! ctype_digit( $minute ) ) {
			return '';
		}

		$hour_int   = (int) $hour;
		$minute_int = (int) $minute;

		if ( $hour_int < 0 || $hour_int > 23 ) {
			if ( $allow_24 && 24 === $hour_int && 0 === $minute_int ) {
				return '24:00';
			}

			return '';
		}

		if ( ! in_array( $minute_int, array( 0, 10, 20, 30, 40, 50 ), true ) ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour_int, $minute_int );
	}

	/**
	 * Sanitize posted regular holidays for redisplay.
	 *
	 * @param mixed $raw Raw regular holiday input.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_regular_holidays_input( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $holiday ) {
			if ( ! is_array( $holiday ) ) {
				continue;
			}

			$sanitized[] = array(
				'frequency' => sanitize_text_field( $holiday['frequency'] ?? '' ),
				'weekday'   => sanitize_text_field( $holiday['weekday'] ?? '' ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize reminder hours input for redisplay.
	 *
	 * @param mixed $raw Raw reminder hours input.
	 * @return array<int, int>
	 */
	private function sanitize_reminder_hours_input( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$hours = absint( $value );
			if ( $hours <= 0 ) {
				continue;
			}

			$sanitized[] = $hours;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized );

		return $sanitized;
	}

	/**
	 * Render a basic business hour slot control group.
	 *
	 * @param string                            $slot_key       Slot key.
	 * @param array<string, string>             $slot_values    Slot values.
	 * @param array<string, string>             $start_hour_options Start hour options.
	 * @param array<string, string>             $end_hour_options End hour options.
	 * @param array<string, string>             $minute_options Minute options.
	 * @param array<string, array<int, string>> $field_errors Slot error messages keyed by slot index.
	 * @return string
	 */
	private function render_basic_business_hours_slot(
		string $slot_key,
		array $slot_values,
		array $start_hour_options,
		array $end_hour_options,
		array $minute_options,
		array $field_errors = array()
	): string {
		$field_id_base    = 'vkbm-basic-business-hours-' . $slot_key;
		$start_hour_value = $slot_values['start_hour'] ?? '';
		$start_min_value  = $slot_values['start_minute'] ?? '';
		$end_hour_value   = $slot_values['end_hour'] ?? '';
		$end_min_value    = $slot_values['end_minute'] ?? '';
		$slot_messages    = $field_errors[ (string) $slot_key ] ?? array();

		ob_start();
		?>
		<div class="vkbm-business-hours-slot vkbm-schedule-slot" data-slot-index="<?php echo esc_attr( $slot_key ); ?>">
			<div class="vkbm-time-range vkbm-schedule-time-range">
				<div class="vkbm-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-start-hour">
						<?php esc_html_e( 'Start time (hour) of basic business hours', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-start-hour"
						class="vkbm-business-hours-hour vkbm-schedule-hour"
						name="vkbm_provider_settings[provider_business_hours_basic][<?php echo esc_attr( $slot_key ); ?>][start_hour]"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $start_hour_options as $hour_value => $hour_label ) : ?>
							<?php
							if ( '00' === $hour_value ) {
								continue; }
							?>
							<option value="<?php echo esc_attr( $hour_value ); ?>" <?php selected( $start_hour_value, $hour_value ); ?>>
								<?php echo esc_html( $hour_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="vkbm-time-separator vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-start-minute">
						<?php esc_html_e( 'Start time of basic business hours (minutes)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-start-minute"
						class="vkbm-business-hours-minute vkbm-schedule-minute"
						name="vkbm_provider_settings[provider_business_hours_basic][<?php echo esc_attr( $slot_key ); ?>][start_minute]"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $minute_options as $minute_value => $minute_label ) : ?>
							<?php
							if ( '00' === $minute_value ) {
								continue; }
							?>
							<option value="<?php echo esc_attr( $minute_value ); ?>" <?php selected( $start_min_value, $minute_value ); ?>>
								<?php echo esc_html( $minute_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<span class="vkbm-time-range-delimiter vkbm-schedule-range-delimiter">〜</span>
				<div class="vkbm-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-end-hour">
						<?php esc_html_e( 'End time (hour) of basic business hours', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-end-hour"
						class="vkbm-business-hours-hour vkbm-schedule-hour"
						name="vkbm_provider_settings[provider_business_hours_basic][<?php echo esc_attr( $slot_key ); ?>][end_hour]"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $end_hour_options as $hour_value => $hour_label ) : ?>
							<?php
							if ( '00' === $hour_value ) {
								continue; }
							?>
							<option value="<?php echo esc_attr( $hour_value ); ?>" <?php selected( $end_hour_value, $hour_value ); ?>>
								<?php echo esc_html( $hour_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="vkbm-time-separator vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-end-minute">
						<?php esc_html_e( 'End time of basic business hours (minutes)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-end-minute"
						class="vkbm-business-hours-minute vkbm-schedule-minute"
						name="vkbm_provider_settings[provider_business_hours_basic][<?php echo esc_attr( $slot_key ); ?>][end_minute]"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $minute_options as $minute_value => $minute_label ) : ?>
							<?php
							if ( '00' === $minute_value ) {
								continue; }
							?>
							<option value="<?php echo esc_attr( $minute_value ); ?>" <?php selected( $end_min_value, $minute_value ); ?>>
								<?php echo esc_html( $minute_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-business-hours-basic-remove-slot vkbm-schedule-remove-slot">
					<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
				</button>
			</div>
			<?php if ( ! empty( $slot_messages ) ) : ?>
				<ul class="vkbm-field-errors">
					<?php foreach ( $slot_messages as $message ) : ?>
						<li><?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a weekly business hour slot control group.
	 *
	 * @param string                $day_key           Day key.
	 * @param string                $slot_key          Slot key.
	 * @param string                $day_label         Day label.
	 * @param array<string, string> $slot_values       Slot values.
	 * @param array<string, string> $start_hour_options Start hour options.
	 * @param array<string, string> $end_hour_options  End hour options.
	 * @param array<string, string> $minute_options    Minute options.
	 * @param bool                  $is_disabled       Whether the slot is disabled.
	 * @param array<string, string> $field_errors      Field errors.
	 * @return string
	 */
	private function render_weekly_business_hours_slot(
		string $day_key,
		string $slot_key,
		string $day_label,
		array $slot_values,
		array $start_hour_options,
		array $end_hour_options,
		array $minute_options,
		bool $is_disabled,
		array $field_errors = array()
	): string {
		$field_id_base    = 'vkbm-weekly-business-hours-' . $day_key . '-' . $slot_key;
		$start_hour_value = $slot_values['start_hour'] ?? '';
		$start_min_value  = $slot_values['start_minute'] ?? '';
		$end_hour_value   = $slot_values['end_hour'] ?? '';
		$end_min_value    = $slot_values['end_minute'] ?? '';
		$slot_messages    = $field_errors[ (string) $slot_key ] ?? array();

		ob_start();
		?>
		<div class="vkbm-business-hours-slot vkbm-schedule-slot" data-slot-index="<?php echo esc_attr( $slot_key ); ?>">
			<div class="vkbm-time-range vkbm-schedule-time-range">
				<div class="vkbm-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-start-hour">
						<?php
						printf(
							/* translators: %s: Day label. */
							esc_html__( 'Start time (hour) of %s', 'vk-booking-manager' ),
							$day_label
						);
						?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-start-hour"
						class="vkbm-business-hours-hour vkbm-schedule-hour"
						name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][time_slots][<?php echo esc_attr( $slot_key ); ?>][start_hour]"
						<?php disabled( $is_disabled ); ?>
					>
				<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
				<?php foreach ( $start_hour_options as $hour_value => $hour_label ) : ?>
					<?php
					if ( '00' === $hour_value ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $hour_value ); ?>" <?php selected( $start_hour_value, $hour_value ); ?>>
						<?php echo esc_html( $hour_label ); ?>
					</option>
				<?php endforeach; ?>
					</select>
					<span class="vkbm-time-separator vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-start-minute">
						<?php
						printf(
							/* translators: %s: Day label. */
							esc_html__( 'Start time of %s (minutes)', 'vk-booking-manager' ),
							$day_label
						);
						?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-start-minute"
						class="vkbm-business-hours-minute vkbm-schedule-minute"
						name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][time_slots][<?php echo esc_attr( $slot_key ); ?>][start_minute]"
						<?php disabled( $is_disabled ); ?>
					>
				<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
				<?php foreach ( $minute_options as $minute_value => $minute_label ) : ?>
					<?php
					if ( '00' === $minute_value ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $minute_value ); ?>" <?php selected( $start_min_value, $minute_value ); ?>>
						<?php echo esc_html( $minute_label ); ?>
					</option>
				<?php endforeach; ?>
					</select>
				</div>
				<span class="vkbm-time-range-delimiter vkbm-schedule-range-delimiter">〜</span>
				<div class="vkbm-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-end-hour">
						<?php
						printf(
							/* translators: %s: Day label. */
							esc_html__( 'End time (hour) of %s', 'vk-booking-manager' ),
							$day_label
						);
						?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-end-hour"
						class="vkbm-business-hours-hour vkbm-schedule-hour"
						name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][time_slots][<?php echo esc_attr( $slot_key ); ?>][end_hour]"
						<?php disabled( $is_disabled ); ?>
					>
				<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
				<?php foreach ( $end_hour_options as $hour_value => $hour_label ) : ?>
					<?php
					if ( '00' === $hour_value ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $hour_value ); ?>" <?php selected( $end_hour_value, $hour_value ); ?>>
						<?php echo esc_html( $hour_label ); ?>
					</option>
				<?php endforeach; ?>
					</select>
					<span class="vkbm-time-separator vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $field_id_base ); ?>-end-minute">
						<?php
						printf(
							/* translators: %s: Day label. */
							esc_html__( 'End time of %s (minutes)', 'vk-booking-manager' ),
							$day_label
						);
						?>
					</label>
					<select
						id="<?php echo esc_attr( $field_id_base ); ?>-end-minute"
						class="vkbm-business-hours-minute vkbm-schedule-minute"
						name="vkbm_provider_settings[provider_business_hours_weekly][<?php echo esc_attr( $day_key ); ?>][time_slots][<?php echo esc_attr( $slot_key ); ?>][end_minute]"
						<?php disabled( $is_disabled ); ?>
					>
				<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
				<?php foreach ( $minute_options as $minute_value => $minute_label ) : ?>
					<?php
					if ( '00' === $minute_value ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $minute_value ); ?>" <?php selected( $end_min_value, $minute_value ); ?>>
						<?php echo esc_html( $minute_label ); ?>
					</option>
				<?php endforeach; ?>
					</select>
				</div>
				<button
					type="button"
					class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-business-hours-remove-slot vkbm-schedule-remove-slot"
					<?php disabled( $is_disabled ); ?>
					aria-label="
					<?php
					echo esc_attr(
						sprintf(
							/* translators: %s: day label */
							__( 'Remove time slot for %s', 'vk-booking-manager' ),
							$day_label
						)
					);
					?>
					"
				>
					<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
				</button>
			</div>
		<?php if ( ! empty( $slot_messages ) ) : ?>
			<ul class="vkbm-field-errors">
				<?php foreach ( $slot_messages as $message ) : ?>
					<li><?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns hour options.
	 *
	 * @return array<string, string>
	 */
	private function get_hour_options(): array {
		$options = array();

		for ( $hour = 0; $hour <= 23; $hour++ ) {
			$formatted             = sprintf( '%02d', $hour );
			$options[ $formatted ] = $formatted;
		}

		return $options;
	}

	/**
	 * Returns end hour options (includes 24:00).
	 *
	 * @return array<string, string>
	 */
	private function get_end_hour_options(): array {
		$options       = $this->get_hour_options();
		$options['24'] = '24';

		return $options;
	}

	/**
	 * Returns minute options (10-minute increments).
	 *
	 * @return array<string, string>
	 */
	private function get_minute_options(): array {
		$values  = array( '00', '10', '20', '30', '40', '50' );
		$options = array();

		foreach ( $values as $value ) {
			$options[ $value ] = $value;
		}

		return $options;
	}

	/**
	 * Split HH:MM time string into components.
	 *
	 * @param string $time Time string.
	 * @return array{hour:string, minute:string}
	 */
	private function split_time_components( string $time ): array {
		if ( ! preg_match( '/^([0-1][0-9]|2[0-4]):([0-5][0-9])$/', $time, $matches ) ) {
			return array(
				'hour'   => '',
				'minute' => '',
			);
		}

		if ( '24' === $matches[1] && '00' !== $matches[2] ) {
			return array(
				'hour'   => '',
				'minute' => '',
			);
		}

		return array(
			'hour'   => $matches[1],
			'minute' => $matches[2],
		);
	}

	/**
	 * 特定日（宿泊プランの料金）の保存済みの値を、フォームの行の形に整える。
	 *
	 * 保存済みの特定日はすべて行にする。1 件も無いときは、入力欄を見せるために空の行を 1 つ返す。
	 * 検証エラーの再表示では、エラーの行の番号をそのまま使えるよう、送信時の番号（キー）を保つ。
	 *
	 * @param mixed $raw 保存済み、または再表示する入力の特定日。
	 * @return array<int|string, array{start: string, end: string, label: string}> 行の番号 => 行の値。
	 */
	private function prepare_lodging_special_date_rows( $raw ): array {
		$rows = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[ $index ] = array(
					'start' => isset( $row['start'] ) && is_scalar( $row['start'] ) ? (string) $row['start'] : '',
					'end'   => isset( $row['end'] ) && is_scalar( $row['end'] ) ? (string) $row['end'] : '',
					'label' => isset( $row['label'] ) && is_scalar( $row['label'] ) ? (string) $row['label'] : '',
				);
			}
		}

		if ( array() === $rows ) {
			$rows[0] = array(
				'start' => '',
				'end'   => '',
				'label' => '',
			);
		}

		return $rows;
	}

	/**
	 * 特定日（宿泊プランの料金）の入力を、検証エラーの再表示用に整える。
	 *
	 * 保存時の検証（Settings_Sanitizer）で弾かれた行も、利用者が直せるよう残す。日付の形式だけを確かめ、
	 * 終了日が開始日より前の行などもそのまま返す。
	 *
	 * @param mixed $raw 送信された特定日。
	 * @return array<int|string, array{start: string, end: string, label: string}> 行の番号 => 行の値。
	 */
	private function sanitize_lodging_special_dates_input( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[ $index ] = array(
				'start' => Lodging_Rate::normalize_date( $row['start'] ?? '' ),
				'end'   => Lodging_Rate::normalize_date( $row['end'] ?? '' ),
				'label' => Lodging_Rate::normalize_label( $row['label'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * 特定日（宿泊プランの料金）の 1 行を出力する。
	 *
	 * @param string                $index 行の番号。
	 * @param array<string, string> $value 行の値（start, end, label）。
	 * @param string                $error この行のエラーメッセージ。無ければ空文字。
	 * @return string
	 */
	private function render_lodging_special_date_row( string $index, array $value, string $error ): string {
		$name_base = 'vkbm_provider_settings[lodging_special_dates][' . $index . ']';
		$error_id  = 'vkbm-lodging-special-date-error-' . $index;

		ob_start();
		?>
		<div class="vkbm-lodging-special-date-row">
			<div class="vkbm-lodging-special-date-fields">
				<span class="vkbm-lodging-special-date-range">
					<label class="vkbm-lodging-special-date-field">
						<span><?php esc_html_e( 'Start date', 'vk-booking-manager' ); ?></span>
						<input
							type="date"
							name="<?php echo esc_attr( $name_base ); ?>[start]"
							value="<?php echo esc_attr( $value['start'] ?? '' ); ?>"
							<?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="' . esc_attr( $error_id ) . '"' : ''; ?>
						/>
					</label>
					<label class="vkbm-lodging-special-date-field">
						<span><?php esc_html_e( 'End date', 'vk-booking-manager' ); ?></span>
						<input
							type="date"
							name="<?php echo esc_attr( $name_base ); ?>[end]"
							value="<?php echo esc_attr( $value['end'] ?? '' ); ?>"
							<?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="' . esc_attr( $error_id ) . '"' : ''; ?>
						/>
					</label>
				</span>
				<label class="vkbm-lodging-special-date-field vkbm-lodging-special-date-label">
					<span><?php esc_html_e( 'Name (optional)', 'vk-booking-manager' ); ?></span>
					<input
						type="text"
						class="regular-text"
						name="<?php echo esc_attr( $name_base ); ?>[label]"
						value="<?php echo esc_attr( $value['label'] ?? '' ); ?>"
						maxlength="<?php echo esc_attr( (string) Lodging_Rate::SPECIAL_LABEL_MAX_LENGTH ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. New Year holidays', 'vk-booking-manager' ); ?>"
					/>
				</label>
				<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-lodging-special-date-remove"><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></button>
			</div>
			<?php if ( '' !== $error ) : ?>
				<p id="<?php echo esc_attr( $error_id ); ?>" class="vkbm-alert vkbm-alert__danger" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a regular holiday table row.
	 *
	 * @param string                $index Row index.
	 * @param array<string, string> $value Current values.
	 * @return string
	 */
	private function render_regular_holiday_row( string $index, array $value ): string {
		// 行マークアップ（頻度・曜日の選択肢を含む）は共有ユーティリティに集約している。
		// 定休日 UI 固有の name 接頭辞・JS がフックする行/削除ボタンのクラス名を引数で渡し、従来と同一の出力を維持する。
		return Weekday_Rule::render_row(
			'vkbm_provider_settings[provider_regular_holidays]',
			$index,
			$value,
			'vkbm-regular-holiday-row',
			'vkbm-regular-holiday-remove'
		);
	}
}
