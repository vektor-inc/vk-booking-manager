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
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Staff\Staff_Editor;

/**
 * Handles the provider settings admin page.
 */
class Provider_Settings_Page {
	private const MENU_SLUG    = 'vkbm-provider-settings';
	private const NONCE_ACTION = 'vkbm_provider_settings_save';
	private const NONCE_NAME   = 'vkbm_provider_settings_nonce';

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
	 * Constructor.
	 *
	 * @param Settings_Service $settings_service Provider settings service.
	 * @param string           $capability       Capability required to access the page.
	 * @param string           $parent_slug      Parent admin menu slug.
	 */
	public function __construct( Settings_Service $settings_service, string $capability = 'manage_options', string $parent_slug = 'vkbm-shift-dashboard' ) {
		$this->settings_service = $settings_service;
		$this->capability       = $capability;
		$this->parent_slug      = $parent_slug;
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
			set_transient( 'vkbm_provider_settings_previous_input', $old_input, 30 );
		} else {
			add_settings_error(
				self::MENU_SLUG,
				'vkbm_provider_settings_save',
				__( 'Basic settings saved.', 'vk-booking-manager' ),
				'updated'
			);
			delete_transient( 'vkbm_provider_settings_field_errors' );
			delete_transient( 'vkbm_provider_settings_previous_input' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		if ( ! empty( $field_errors ) ) {
			set_transient( 'vkbm_provider_settings_field_errors', $field_errors, 30 );
		} else {
			delete_transient( 'vkbm_provider_settings_field_errors' );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve UI state.
		if ( ! in_array( $active_tab, array( 'store', 'system', 'registration', 'consent', 'design', 'advanced', 'faq' ), true ) ) {
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
	 * Enqueue scripts and styles for the settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		$plugin_root         = dirname( __DIR__, 2 );
		$settings_js         = $plugin_root . '/assets/js/provider-settings.js';
		$settings_js_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0';
		if ( is_string( $settings_js ) && file_exists( $settings_js ) ) {
			$settings_js_version = (string) filemtime( $settings_js );
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'vkbm-provider-settings',
			plugins_url( 'assets/js/provider-settings.js', dirname( __DIR__ ) ),
			array( 'jquery', 'media-editor', 'wp-color-picker' ),
			$settings_js_version,
			true
		);
		wp_localize_script(
			'vkbm-provider-settings',
			'vkbmProviderSettings',
			array(
				'logoFrameTitle'  => __( 'Select logo image', 'vk-booking-manager' ),
				'logoFrameButton' => __( 'Select', 'vk-booking-manager' ),
			)
		);

		$regular_holiday_toggle = "(function () {
			var checkbox = document.getElementById('vkbm-regular-holiday-disabled');
			var panel = document.getElementById('vkbm-regular-holiday-settings');
			if (!checkbox || !panel) {
				return;
			}
			var toggle = function () {
				panel.style.display = checkbox.checked ? 'none' : '';
			};
			checkbox.addEventListener('change', toggle);
			toggle();
		})();";
		wp_add_inline_script( 'vkbm-provider-settings', $regular_holiday_toggle, 'after' );

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		$settings  = $this->settings_service->get_settings();
		$old_input = get_transient( 'vkbm_provider_settings_previous_input' );
		if ( is_array( $old_input ) ) {
			foreach ( $old_input as $key => $value ) {
				$settings[ $key ] = $value;
			}
			delete_transient( 'vkbm_provider_settings_previous_input' );
		}
		$logo_id              = isset( $settings['provider_logo_id'] ) ? (int) $settings['provider_logo_id'] : 0;
		$booking_cancel_mode  = isset( $settings['provider_booking_cancel_mode'] ) ? (string) $settings['provider_booking_cancel_mode'] : 'hours';
		$booking_cancel_hours = isset( $settings['provider_booking_cancel_deadline_hours'] ) ? (int) $settings['provider_booking_cancel_deadline_hours'] : 24;

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

		$frequency_options   = $this->get_regular_holiday_frequency_options();
		$weekday_options     = $this->get_weekday_options();
		$business_day_labels = $this->get_business_hours_day_labels();
		$hour_options        = $this->get_hour_options();
		$end_hour_options    = $this->get_end_hour_options();
		$minute_options      = $this->get_minute_options();
		$field_errors        = get_transient( 'vkbm_provider_settings_field_errors' );

		if ( false === $field_errors || ! is_array( $field_errors ) ) {
			$field_errors = array();
		} else {
			delete_transient( 'vkbm_provider_settings_field_errors' );
		}

		$basic_field_errors           = is_array( $field_errors['basic'] ?? null ) ? $field_errors['basic'] : array();
		$weekly_field_errors          = is_array( $field_errors['weekly'] ?? null ) ? $field_errors['weekly'] : array();
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
		$resource_label_singular      = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : 'Staff';
		$resource_label_plural        = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : 'Staff';
		$resource_label_menu          = isset( $settings['resource_label_menu'] ) ? (string) $settings['resource_label_menu'] : 'Staff available';
		$locale                       = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		$no_plural_locales            = array( 'ja', 'zh', 'ko' );
		$has_plural_forms_in_locale   = true;
		foreach ( $no_plural_locales as $prefix ) {
			if ( '' !== $locale && 0 === strpos( $locale, $prefix ) ) {
				$has_plural_forms_in_locale = false;
				break;
			}
		}
		$reservation_deadline_hours        = isset( $settings['provider_reservation_deadline_hours'] ) ? (int) $settings['provider_reservation_deadline_hours'] : 0;
		$slot_step_minutes                 = isset( $settings['provider_slot_step_minutes'] ) ? (int) $settings['provider_slot_step_minutes'] : 15;
		$service_menu_buffer_after_minutes = isset( $settings['provider_service_menu_buffer_after_minutes'] ) ? (int) $settings['provider_service_menu_buffer_after_minutes'] : 0;
		$booking_status_mode               = isset( $settings['provider_booking_status_mode'] ) ? (string) $settings['provider_booking_status_mode'] : 'confirmed';
		$cancellation_policy               = isset( $settings['provider_cancellation_policy'] ) ? (string) $settings['provider_cancellation_policy'] : '';
		$terms_of_service                  = isset( $settings['provider_terms_of_service'] ) ? (string) $settings['provider_terms_of_service'] : '';
		$privacy_policy_mode               = isset( $settings['provider_privacy_policy_mode'] ) ? sanitize_key( (string) $settings['provider_privacy_policy_mode'] ) : 'none';
		$privacy_policy_url                = isset( $settings['provider_privacy_policy_url'] ) ? (string) $settings['provider_privacy_policy_url'] : '';
		$privacy_policy_content            = isset( $settings['provider_privacy_policy_content'] ) ? (string) $settings['provider_privacy_policy_content'] : '';
		$payment_method                    = isset( $settings['provider_payment_method'] ) ? (string) $settings['provider_payment_method'] : '';
		if ( ! in_array( $privacy_policy_mode, array( 'none', 'url', 'content' ), true ) ) {
			$privacy_policy_mode = 'none';
		}
		$reservation_menu_list_display_mode = isset( $settings['reservation_menu_list_display_mode'] ) ? sanitize_key( (string) $settings['reservation_menu_list_display_mode'] ) : 'card';
		$shift_alert_months                 = isset( $settings['shift_alert_months'] ) ? (int) $settings['shift_alert_months'] : 1;
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
		$design_primary_color            = isset( $settings['design_primary_color'] ) ? (string) $settings['design_primary_color'] : '';
		$design_reservation_button_color = isset( $settings['design_reservation_button_color'] ) ? (string) $settings['design_reservation_button_color'] : '';
		$design_radius_md                = isset( $settings['design_radius_md'] ) ? (int) $settings['design_radius_md'] : 8;
		$currency_symbol                 = isset( $settings['currency_symbol'] ) ? (string) $settings['currency_symbol'] : '';
		$tax_label_text                  = isset( $settings['tax_label_text'] ) ? (string) $settings['tax_label_text'] : '';
		$currency_placeholder            = ( '' !== $locale && 0 === strpos( $locale, 'ja' ) ) ? '¥' : '$';
		if ( ! in_array( $reservation_menu_list_display_mode, array( 'card', 'text' ), true ) ) {
			$reservation_menu_list_display_mode = 'card';
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI state.
		if ( ! in_array( $active_tab, array( 'store', 'system', 'registration', 'consent', 'design', 'advanced', 'faq' ), true ) ) {
			$active_tab = 'store';
		}

		$base_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap vkbm-provider-settings" data-active-tab="<?php echo esc_attr( $active_tab ); ?>">
			<h1><?php esc_html_e( 'Basic settings', 'vk-booking-manager' ); ?></h1>
			<h2 class="nav-tab-wrapper vkbm-provider-settings__tabs" aria-label="<?php esc_attr_e( 'Settings tab', 'vk-booking-manager' ); ?>">
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'store', $base_url ) ); ?>"
					class="nav-tab<?php echo 'store' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Store basic information', 'vk-booking-manager' ); ?>
				</a>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', 'system', $base_url ) ); ?>"
					class="nav-tab<?php echo 'system' === $active_tab ? ' nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'System settings', 'vk-booking-manager' ); ?>
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
			</h2>
			<?php settings_errors( self::MENU_SLUG ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tbody>
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
								<p class="description"><?php esc_html_e( 'This is the name used in notification emails and screen displays.', 'vk-booking-manager' ); ?></p>
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
									rows="3"
									id="vkbm-provider-business-hours"
									name="vkbm_provider_settings[provider_business_hours]"
								><?php echo esc_textarea( $settings['provider_business_hours'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'This will be displayed in the signature of notification emails, etc.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr class="vkbm-provider-settings__tab-store">
							<th scope="row">
								<label for="vkbm-provider-payment-method"><?php esc_html_e( 'Payment method', 'vk-booking-manager' ); ?></label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="4"
									id="vkbm-provider-payment-method"
									name="vkbm_provider_settings[provider_payment_method]"
								><?php echo esc_textarea( $payment_method ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Enter payment instructions and supported payment methods (cash, credit card, IC, etc.) when visiting the store.', 'vk-booking-manager' ); ?></p>
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
								<div id="vkbm-regular-holiday-settings" <?php echo $regular_holidays_disabled ? 'style="display:none;"' : ''; ?>>
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
												$frequency_options,
												$weekday_options,
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
										$frequency_options,
										$weekday_options,
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
									<p class="description"><?php esc_html_e( 'Register the business hours that are common to the entire facility.', 'vk-booking-manager' ); ?></p>
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
			$day_settings                                   = isset( $business_hours_weekly[ $day_key ] ) && is_array( $business_hours_weekly[ $day_key ] )
				? $business_hours_weekly[ $day_key ]
				: array(
					'use_custom' => false,
					'time_slots' => array(),
				);
			$use_custom                                     = ! empty( $day_settings['use_custom'] );
			$slots_for_day                                  = $this->prepare_weekly_business_hours_slots( $day_settings, $basic_slots, $use_custom );
			$next_slot_index                                = $this->get_next_slot_index( $slots_for_day );
			$is_regular_holiday                             = ! empty( $weekly_closed_days[ $day_key ] );
			$day_field_errors                               = is_array( $weekly_field_errors[ $day_key ] ?? null ) ? $weekly_field_errors[ $day_key ] : array();
			$row_classes                                    = array(
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
								<p class="description"><?php esc_html_e( 'On holidays and days before holidays, shifts are generated based on basic business hours or individual settings.', 'vk-booking-manager' ); ?></p>
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
								<th scope="row"><?php esc_html_e( 'Reservation page display elements', 'vk-booking-manager' ); ?></th>
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
												id="vkbm-reservation-show-menu-list"
												name="vkbm_provider_settings[reservation_show_menu_list]"
												value="1"
												<?php checked( ! empty( $settings['reservation_show_menu_list'] ) ); ?>
											/>
											<?php esc_html_e( 'Service menu list', 'vk-booking-manager' ); ?>
										</label>
									</div>
							</td>
						</tr>

								<?php if ( 'system' === $active_tab ) : ?>
									<tr class="vkbm-provider-settings__tab-system" id="vkbm-reservation-menu-list-display-mode-row" style="<?php echo ! empty( $settings['reservation_show_menu_list'] ) ? '' : 'display:none;'; ?>">
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
										<p class="description"><?php esc_html_e( 'If there is an input for each service menu, that will take priority.', 'vk-booking-manager' ); ?></p>
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
									<label for="vkbm-provider-booking-status-mode"><?php esc_html_e( 'Reservation status', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<select
									id="vkbm-provider-booking-status-mode"
									name="vkbm_provider_settings[provider_booking_status_mode]"
								>
									<option value="confirmed" <?php selected( $booking_status_mode, 'confirmed' ); ?>>
										<?php esc_html_e( 'Instant confirmation', 'vk-booking-manager' ); ?>
									</option>
									<option value="pending" <?php selected( $booking_status_mode, 'pending' ); ?>>
										<?php esc_html_e( 'Make a tentative reservation', 'vk-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'For tentative reservations, a reservation confirmation email will be sent to the user even when the reservation is changed to confirmed.', 'vk-booking-manager' ); ?><br />
									<?php esc_html_e( '*Reservations made by users with administrator privileges and salon privileges will be confirmed immediately.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>

							<tr class="vkbm-provider-settings__tab-system">
								<th scope="row">
									<label for="vkbm-provider-booking-cancel-mode"><?php esc_html_e( 'Reservation cancellation', 'vk-booking-manager' ); ?></label>
								</th>
							<td>
								<p class="description" style="margin-top:0;margin-bottom:0.5rem;">
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
									style="margin-left:0.75rem;<?php echo 'none' === $booking_cancel_mode ? 'display:none;' : ''; ?>"
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



							<tr class="vkbm-provider-settings__tab-store">
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
								<p class="description"><?php esc_html_e( 'This is used as the reply address when sending notification emails.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>

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
							<th scope="row"><?php esc_html_e( 'Store logo image', 'vk-booking-manager' ); ?></th>
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
									<?php echo $logo_id > 0 ? '' : 'style="display:none;"'; ?>
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
									style="margin-top:0.75rem;<?php echo 'url' === $privacy_policy_mode ? '' : 'display:none;'; ?>"
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
									style="margin-top:0.75rem;<?php echo 'content' === $privacy_policy_mode ? '' : 'display:none;'; ?>"
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
								<label for="vkbm-shift-alert-months"><?php esc_html_e( 'Alert when shift is not registered', 'vk-booking-manager' ); ?></label>
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
								<?php esc_html_e( 'Display an alert if a shift for the next month is not registered.', 'vk-booking-manager' ); ?>
								<p class="description"><?php esc_html_e( 'Displayed if there is an unregistered month within the specified number of months including the current month.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-system">
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
												<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger">
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
										<button type="button" class="button-link vkbm-reminder-hours-remove">
											<?php esc_html_e( 'Remove', 'vk-booking-manager' ); ?>
										</button>
									</div>
								</template>
							</td>
						</tr>
						<?php if ( Staff_Editor::is_enabled() ) : ?>
							<tr class="vkbm-provider-settings__tab-system">
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
										<?php esc_html_e( 'Replaces the "staff" notation on the screen (does not change the post type name vkbm_resource).', 'vk-booking-manager' ); ?>
									</p>
								</td>
							</tr>

							<tr class="vkbm-provider-settings__tab-system">
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
										<?php esc_html_e( 'Replaces the "available staff" notation in the menu card.', 'vk-booking-manager' ); ?>
									</p>
								</td>
							</tr>

							<?php if ( $has_plural_forms_in_locale ) : ?>
								<tr class="vkbm-provider-settings__tab-system">
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
						<?php endif; ?>
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
									step="1"
									value="<?php echo esc_attr( (string) $design_radius_md ); ?>"
								/> px
								<p class="description"><?php esc_html_e( 'Applies to rounded corners (--vkbm--radius--md) in the main component.', 'vk-booking-manager' ); ?></p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-advanced">
							<th scope="row"><?php esc_html_e( 'Email debug', 'vk-booking-manager' ); ?></th>
							<td>
								<label class="vkbm-inline-checkbox">
									<input
										type="checkbox"
										name="vkbm_provider_settings[email_log_enabled]"
										value="1"
										<?php checked( $email_log_enabled ); ?>
									/>
									<?php esc_html_e( 'Enable email log', 'vk-booking-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, the Email Log page becomes available and email send attempts are recorded.', 'vk-booking-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr class="vkbm-provider-settings__tab-advanced">
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
									<dt><?php esc_html_e( 'Even after registering as a new user, the user does not receive a confirmation email.', 'vk-booking-manager' ); ?></dt>
									<dd>
										<p>
											<?php esc_html_e( 'To authenticate the email sender and prevent tampering, emails that do not have DKIM settings on the server may not even reach the spam folder.', 'vk-booking-manager' ); ?><br />
											<?php esc_html_e( "Especially if the user's email address is gmail, it will not arrive.", 'vk-booking-manager' ); ?>
										</p>
										<p><?php esc_html_e( 'Please check the following with the site administrator.', 'vk-booking-manager' ); ?></p>
										<ul class="ul-disc">
											<li><?php esc_html_e( "Is the domain where this system is running and the domain of the site administrator's email address the same?", 'vk-booking-manager' ); ?></li>
											<li><?php esc_html_e( 'Is DKIM configured on the server side for the domain where this system is running?', 'vk-booking-manager' ); ?></li>
										</ul>
									</dd>
								</dl>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save changes', 'vk-booking-manager' ) ); ?>
			</form>
		</div>
		<?php
	}
	/**
	 * Returns options for regular holiday frequency select.
	 *
	 * @return array<string, string>
	 */
	private function get_regular_holiday_frequency_options(): array {
		return array(
			'weekly' => __( 'Weekly', 'vk-booking-manager' ),
			'nth-1'  => __( '1st', 'vk-booking-manager' ),
			'nth-2'  => __( '2nd', 'vk-booking-manager' ),
			'nth-3'  => __( '3rd', 'vk-booking-manager' ),
			'nth-4'  => __( '4th', 'vk-booking-manager' ),
			'nth-5'  => __( 'Fifth', 'vk-booking-manager' ),
		);
	}

	/**
	 * Returns weekday options used in regular holiday selection.
	 *
	 * @return array<string, string>
	 */
	private function get_weekday_options(): array {
		return array(
			'mon' => __( 'Monday', 'vk-booking-manager' ),
			'tue' => __( 'Tuesday', 'vk-booking-manager' ),
			'wed' => __( 'Wednesday', 'vk-booking-manager' ),
			'thu' => __( 'Thursday', 'vk-booking-manager' ),
			'fri' => __( 'Friday', 'vk-booking-manager' ),
			'sat' => __( 'Saturday', 'vk-booking-manager' ),
			'sun' => __( 'Sunday', 'vk-booking-manager' ),
		);
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
	 * Sanitize previously submitted input for redisplay after validation errors.
	 *
	 * @param array<string, mixed> $input Raw input array.
	 * @return array<string, mixed>
	 */
	private function sanitize_old_input( array $input ): array {
		$output = array();

		$output['provider_name']                                  = sanitize_text_field( $input['provider_name'] ?? '' );
		$output['provider_address']                               = sanitize_textarea_field( $input['provider_address'] ?? '' );
		$output['provider_phone']                                 = sanitize_text_field( $input['provider_phone'] ?? '' );
		$output['provider_payment_method']                        = sanitize_textarea_field( $input['provider_payment_method'] ?? '' );
		$output['resource_label_singular']                        = sanitize_text_field( $input['resource_label_singular'] ?? 'Staff' );
		$output['resource_label_plural']                          = sanitize_text_field( $input['resource_label_plural'] ?? 'Staff' );
		$output['resource_label_menu']                            = sanitize_text_field( $input['resource_label_menu'] ?? 'Staff available' );
			$output['provider_business_hours']                    = sanitize_textarea_field( $input['provider_business_hours'] ?? '' );
			$output['provider_reservation_deadline_hours']        = absint( $input['provider_reservation_deadline_hours'] ?? 0 );
			$output['provider_service_menu_buffer_after_minutes'] = absint( $input['provider_service_menu_buffer_after_minutes'] ?? 0 );
			$output['provider_booking_status_mode']               = sanitize_key( (string) ( $input['provider_booking_status_mode'] ?? 'confirmed' ) );
		$output['provider_booking_cancel_mode']                   = sanitize_key( (string) ( $input['provider_booking_cancel_mode'] ?? 'hours' ) );
		$output['provider_booking_cancel_deadline_hours']         = absint( $input['provider_booking_cancel_deadline_hours'] ?? 24 );
		$output['provider_website_url']                           = sanitize_text_field( $input['provider_website_url'] ?? '' );
		$output['reservation_page_url']                           = sanitize_text_field( $input['reservation_page_url'] ?? '' );
		$output['reservation_show_menu_list']                     = ! empty( $input['reservation_show_menu_list'] );
		$output['reservation_menu_list_display_mode']             = sanitize_key( (string) ( $input['reservation_menu_list_display_mode'] ?? 'card' ) );
		$output['reservation_show_provider_logo']                 = ! empty( $input['reservation_show_provider_logo'] );
		$output['reservation_show_provider_name']                 = ! empty( $input['reservation_show_provider_name'] );
		$output['currency_symbol']                                = sanitize_text_field( $input['currency_symbol'] ?? '' );
		$output['provider_email']                                 = sanitize_text_field( $input['provider_email'] ?? '' );
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
	 * Render a regular holiday table row.
	 *
	 * @param string                $index             Row index.
	 * @param array<string, string> $frequency_options Frequency options.
	 * @param array<string, string> $weekday_options   Weekday options.
	 * @param array<string, string> $value             Current values.
	 * @return string
	 */
	private function render_regular_holiday_row( string $index, array $frequency_options, array $weekday_options, array $value ): string {
		$frequency_value = $value['frequency'] ?? 'weekly';
		$weekday_value   = $value['weekday'] ?? 'mon';

		ob_start();
		?>
		<tr class="vkbm-regular-holiday-row" data-index="<?php echo esc_attr( $index ); ?>">
			<td>
				<select name="vkbm_provider_settings[provider_regular_holidays][<?php echo esc_attr( $index ); ?>][frequency]">
					<?php foreach ( $frequency_options as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $frequency_value, $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="vkbm_provider_settings[provider_regular_holidays][<?php echo esc_attr( $index ); ?>][weekday]">
					<?php foreach ( $weekday_options as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $weekday_value, $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="column-actions">
				<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-regular-holiday-remove">
					<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
				</button>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
	}
}
