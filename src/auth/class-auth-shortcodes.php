<?php

/**
 * Front-end login & registration shortcodes.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Email_Log_Repository;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Error;
use WP_Post;
use function apply_filters;

/**
 * Front-end login & registration shortcodes.
 */
class Auth_Shortcodes {
	private const EMAIL_TOKEN_TTL            = DAY_IN_SECONDS;
	private const RATE_LIMIT_LOGIN_MAX       = 10;
	private const RATE_LIMIT_LOGIN_WINDOW    = 600;
	private const RATE_LIMIT_REGISTER_MAX    = 5;
	private const RATE_LIMIT_REGISTER_WINDOW = 1800;

	/**
	 * Login errors.
	 *
	 * @var WP_Error|null
	 */
	private $login_errors;

	/**
	 * Registration errors.
	 *
	 * @var WP_Error|null
	 */
	private $registration_errors;

	/**
	 * Login posted data.
	 *
	 * @var array<string,mixed>
	 */
	private $login_posted_data = array();

	/**
	 * Registration posted data.
	 *
	 * @var array<string,mixed>
	 */
	private $registration_posted_data = array();

	/**
	 * Registration raw data.
	 *
	 * @var array<string,mixed>
	 */
	private $registration_raw_data = array();

	/**
	 * Profile errors.
	 *
	 * @var WP_Error|null
	 */
	private $profile_errors;

	/**
	 * Profile posted data.
	 *
	 * @var array<string,mixed>
	 */
	private $profile_posted_data = array();

	/**
	 * Provider settings helper.
	 *
	 * @var Settings_Service
	 */
	private $settings_service;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service $settings_service Provider settings helper.
	 */
	public function __construct( Settings_Service $settings_service ) {
		$this->settings_service = $settings_service;
	}

	/**
	 * Hook into WordPress.
	 */
	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_email_verification' ) );
		add_action( 'login_form_register', array( $this, 'redirect_wp_register_to_vkbm' ) );
		add_action( 'login_form_login', array( $this, 'redirect_wp_login_to_vkbm' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_branding' ) );
		add_shortcode( 'vkbm_login_form', array( $this, 'render_login_form' ) );
		add_shortcode( 'vkbm_register_form', array( $this, 'render_registration_form' ) );
	}

	/**
	 * Redirect WordPress default registration screen to the VKBM registration page.
	 */
	public function redirect_wp_register_to_vkbm(): void {
		if ( ! get_option( 'users_can_register' ) ) {
			return;
		}

		$settings = $this->settings_service->get_settings();
		if ( empty( $settings['membership_redirect_wp_register'] ) ) {
			return;
		}

		$reservation_url = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			$reservation_url = vkbm_normalize_reservation_page_url( $reservation_url );
		}

		if ( '' === $reservation_url ) {
			return;
		}

		$redirect_url = add_query_arg( 'vkbm_auth', 'register', $reservation_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect WordPress default login screen to the VKBM login page.
	 */
	public function redirect_wp_login_to_vkbm(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Check login action only.
		if ( 'login' !== $action ) {
			return;
		}

		$settings = $this->settings_service->get_settings();
		if ( empty( $settings['membership_redirect_wp_login'] ) ) {
			return;
		}

		$reservation_url = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			$reservation_url = vkbm_normalize_reservation_page_url( $reservation_url );
		}

		if ( '' === $reservation_url ) {
			return;
		}

		$redirect_url = add_query_arg( 'vkbm_auth', 'login', $reservation_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Apply provider logo to the WordPress login screen.
	 *
	 * WordPress のログイン画面に店舗ロゴを反映します。
	 *
	 * @return void
	 */
	public function enqueue_login_branding(): void {
		$settings = $this->settings_service->get_settings();
		$logo_id  = isset( $settings['provider_logo_id'] ) ? (int) $settings['provider_logo_id'] : 0;

		if ( $logo_id <= 0 ) {
			return;
		}

		$logo_url = wp_get_attachment_image_url( $logo_id, 'medium' );
		if ( ! is_string( $logo_url ) || '' === $logo_url ) {
			return;
		}

		wp_enqueue_style( 'login' );
		wp_add_inline_style(
			'login',
			sprintf(
				'#login h1 a{background-image:url("%s");background-size:contain;background-position:center;background-repeat:no-repeat;width:100%%;max-width:280px;height:84px;}',
				esc_url( $logo_url )
			)
		);
	}

	/**
	 * Processes login + registration requests before rendering.
	 */
	public function handle_form_submission(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		if ( isset( $_POST['vkbm_login_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
			$this->process_login_request();
		}

		if ( isset( $_POST['vkbm_registration_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
			$this->process_registration_request();
		}

		if ( isset( $_POST['vkbm_profile_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
			$this->process_profile_request();
		}
	}

	/**
	 * Handles verification callback from email link.
	 */
	public function handle_email_verification(): void {
		if ( empty( $_GET['vkbm_verify_email'] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['vkbm_verify_email'] ) );
		if ( '' === $token ) {
			return;
		}

		$user = get_users(
			array(
				'meta_key'   => 'vkbm_email_verify_token',
				'meta_value' => $token,
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		if ( empty( $user[0] ) ) {
			$this->set_verification_notice( __( 'Invalid verification link.', 'vk-booking-manager' ) );
			return;
		}

		$user    = $user[0];
		$expires = (int) get_user_meta( $user->ID, 'vkbm_email_verify_expires', true );

		if ( $expires && $expires < time() ) {
			$this->set_verification_notice( __( 'The confirmation link has expired.', 'vk-booking-manager' ) );
			return;
		}

		update_user_meta( $user->ID, 'vkbm_email_verified', '1' );
		delete_user_meta( $user->ID, 'vkbm_email_verify_token' );
		delete_user_meta( $user->ID, 'vkbm_email_verify_expires' );
		$this->set_verification_notice( __( 'Email verification has been completed. Please log in.', 'vk-booking-manager' ) );

		$redirect_url = remove_query_arg( 'vkbm_verify_email', $this->get_current_url() );
		$redirect_url = add_query_arg( 'vkbm_auth', 'login', $redirect_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Outputs the login form markup.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_login_form( array $atts = array() ): string {
		if ( is_user_logged_in() ) {
			return $this->render_logged_in_message();
		}

		$this->enqueue_assets();

		$defaults = array(
			'redirect'                => $this->get_current_url(),
			'title'                   => __( 'Log in', 'vk-booking-manager' ),
			'description'             => '',
			'button_label'            => __( 'Log in', 'vk-booking-manager' ),
			'show_register_link'      => 'true',
			'register_url'            => '',
			'show_lost_password_link' => 'true',
			'lost_password_url'       => wp_lostpassword_url(),
			'action_url'              => '',
		);

		$atts        = shortcode_atts( $defaults, $atts, 'vkbm_login_form' );
		$redirect_to = $this->normalize_redirect( $atts['redirect'] ?? '' );

		$register_url = ! empty( $atts['register_url'] )
			? esc_url_raw( (string) $atts['register_url'] )
			: ( get_option( 'users_can_register' ) ? wp_registration_url() : '' );

		$lost_password_url = ! empty( $atts['lost_password_url'] )
			? esc_url_raw( (string) $atts['lost_password_url'] )
			: wp_lostpassword_url();
		$action_base       = $this->normalize_redirect( $atts['action_url'] ?? '' );
		$form_action       = $this->get_auth_action_url( 'login', $action_base );

		$username_value  = isset( $this->login_posted_data['user_login'] ) ? (string) $this->login_posted_data['user_login'] : '';
		$remember_active = isset( $this->login_posted_data['remember'] ) ? (bool) $this->login_posted_data['remember'] : true;

		$cookie_error = $this->consume_login_error_cookie();
		if ( $cookie_error ) {
			if ( ! $this->login_errors instanceof WP_Error ) {
				$this->login_errors = new WP_Error();
			}

			$this->login_errors->add( 'auth_failed', $cookie_error );
		}

		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--login">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="vkbm-auth-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( ! empty( $atts['description'] ) ) : ?>
				<p class="vkbm-auth-card__description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<?php $this->render_error_list( $this->login_errors ); ?>
			<?php $verification_message = $this->consume_verification_notice(); ?>
			<?php if ( $verification_message ) : ?>
				<div class="vkbm-alert vkbm-alert__success">
					<?php echo esc_html( $verification_message ); ?>
				</div>
			<?php endif; ?>
			<form class="vkbm-auth-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
				<div class="vkbm-auth-form__field">
					<label class="vkbm-auth-form__label" for="vkbm-login-username"><?php esc_html_e( 'Username or email address', 'vk-booking-manager' ); ?></label>
					<input type="text" class="vkbm-auth-form__input" id="vkbm-login-username" name="log" value="<?php echo esc_attr( $username_value ); ?>" autocomplete="username" required>
				</div>
				<div class="vkbm-auth-form__field vkbm-auth-form__password">
					<label class="vkbm-auth-form__label" for="vkbm-login-password"><?php esc_html_e( 'password', 'vk-booking-manager' ); ?></label>
					<div class="vkbm-auth-form__password-field">
						<input type="password" class="vkbm-auth-form__input" id="vkbm-login-password" name="pwd" autocomplete="current-password" required>
						<button
							type="button"
							class="vkbm-auth-form__password-toggle"
							aria-controls="vkbm-login-password"
							aria-pressed="false"
							data-show-label="<?php esc_attr_e( 'Show password', 'vk-booking-manager' ); ?>"
							data-hide-label="<?php esc_attr_e( 'hide password', 'vk-booking-manager' ); ?>"
						>
							<span class="vkbm-auth-form__password-toggle-label"><?php esc_html_e( 'show password', 'vk-booking-manager' ); ?></span>
							<svg class="vkbm-auth-form__password-toggle-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M12 5c-5.5 0-9.5 4.5-10.5 6.5C2.5 13.5 6.5 18 12 18s9.5-4.5 10.5-6.5C21.5 9.5 17.5 5 12 5zm0 11c-2.5 0-4.5-2-4.5-4.5S9.5 7 12 7s4.5 2 4.5 4.5S14.5 16 12 16zm0-7c-1.4 0-2.5 1.1-2.5 2.5S10.6 14 12 14s2.5-1.1 2.5-2.5S13.4 9 12 9z" />
							</svg>
						</button>
					</div>
				</div>
				<div class="vkbm-auth-form__meta">
					<label class="vkbm-auth-form__remember">
						<input type="checkbox" name="rememberme" value="forever" <?php checked( $remember_active ); ?>>
						<span><?php esc_html_e( 'Stay logged in', 'vk-booking-manager' ); ?></span>
					</label>
					<?php if ( $this->is_truthy( $atts['show_lost_password_link'] ) && ! empty( $lost_password_url ) ) : ?>
						<a class="vkbm-auth-form__link" href="<?php echo esc_url( $lost_password_url ); ?>"><?php esc_html_e( 'Forgot your password?', 'vk-booking-manager' ); ?></a>
					<?php endif; ?>
					</div>
				<div class="vkbm-auth-form__actions">
					<button type="submit" class="vkbm-auth-button vkbm-button vkbm-button__md vkbm-button__primary"><?php echo esc_html( $atts['button_label'] ); ?></button>
				</div>
				<?php echo wp_nonce_field( 'vkbm_login_form', 'vkbm_login_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() outputs escaped HTML. ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<input type="hidden" name="vkbm_login_form" value="1">
				<input type="hidden" name="vkbm_auth" value="login">
			</form>
			<?php if ( $this->is_truthy( $atts['show_register_link'] ) && ! empty( $register_url ) ) : ?>
				<p class="vkbm-auth-form__footer vkbm-auth-form__footer--register-link">
					<a class="vkbm-auth-form__link" href="<?php echo esc_url( $register_url ); ?>">
						<?php esc_html_e( 'Click here for new registration', 'vk-booking-manager' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Outputs the registration form markup.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_registration_form( array $atts = array() ): string {
		if ( ! get_option( 'users_can_register' ) ) {
			return $this->render_registration_disabled_notice();
		}

		$this->enqueue_assets();
		$this->restore_registration_errors();

		$defaults = array(
			'redirect'     => $this->get_current_url(),
			'title'        => __( 'Create an account', 'vk-booking-manager' ),
			'description'  => '',
			'button_label' => __( 'Register', 'vk-booking-manager' ),
			'auto_login'   => 'false',
			'login_url'    => '',
			'action_url'   => '',
		);

		$atts        = shortcode_atts( $defaults, $atts, 'vkbm_register_form' );
		$redirect_to = $this->normalize_redirect( $atts['redirect'] ?? '' );
		$auto_login  = $this->is_truthy( $atts['auto_login'] ?? true );
		$login_url   = ! empty( $atts['login_url'] )
			? esc_url_raw( (string) $atts['login_url'] )
			: wp_login_url();
		$action_base = $this->normalize_redirect( $atts['action_url'] ?? '' );
		$form_action = $this->get_auth_action_url( 'register', $action_base );

		$username_raw           = isset( $this->registration_raw_data['user_login'] ) ? (string) $this->registration_raw_data['user_login'] : '';
		$username_value         = '' !== $username_raw ? $username_raw : ( isset( $this->registration_posted_data['user_login'] ) ? (string) $this->registration_posted_data['user_login'] : '' );
		$email_value            = isset( $this->registration_posted_data['user_email'] ) ? (string) $this->registration_posted_data['user_email'] : '';
		$first_value            = isset( $this->registration_posted_data['first_name'] ) ? (string) $this->registration_posted_data['first_name'] : '';
		$last_value             = isset( $this->registration_posted_data['last_name'] ) ? (string) $this->registration_posted_data['last_name'] : '';
		$name_value             = isset( $this->registration_posted_data['full_name'] ) ? (string) $this->registration_posted_data['full_name'] : '';
		$kana_value             = isset( $this->registration_posted_data['kana_name'] ) ? (string) $this->registration_posted_data['kana_name'] : '';
		$phone_value            = isset( $this->registration_posted_data['phone_number'] ) ? (string) $this->registration_posted_data['phone_number'] : '';
		$birth_value            = isset( $this->registration_posted_data['birth_date'] ) ? (string) $this->registration_posted_data['birth_date'] : '';
		$birth_parts            = $this->resolve_birth_parts( $this->registration_posted_data, $birth_value );
		$birth_year_value       = $birth_parts['year'];
		$birth_month_value      = $birth_parts['month'];
		$birth_day_value        = $birth_parts['day'];
		$gender_value           = isset( $this->registration_posted_data['gender'] ) ? (string) $this->registration_posted_data['gender'] : '';
		$agree_terms_value      = ! empty( $this->registration_posted_data['agree_terms_of_service'] );
		$agree_privacy_value    = ! empty( $this->registration_posted_data['agree_privacy_policy'] );
		$settings               = $this->settings_service->get_settings();
		$terms_of_service       = isset( $settings['provider_terms_of_service'] ) ? trim( (string) $settings['provider_terms_of_service'] ) : '';
		$privacy_policy_mode    = isset( $settings['provider_privacy_policy_mode'] ) ? sanitize_key( (string) $settings['provider_privacy_policy_mode'] ) : 'none';
		$privacy_policy_url     = isset( $settings['provider_privacy_policy_url'] ) ? trim( (string) $settings['provider_privacy_policy_url'] ) : '';
		$privacy_policy_content = isset( $settings['provider_privacy_policy_content'] ) ? trim( (string) $settings['provider_privacy_policy_content'] ) : '';
		if ( ! in_array( $privacy_policy_mode, array( 'none', 'url', 'content' ), true ) ) {
			$privacy_policy_mode = 'none';
		}
		$show_terms           = '' !== $terms_of_service;
		$show_privacy_url     = 'url' === $privacy_policy_mode && '' !== $privacy_policy_url;
		$show_privacy_content = 'content' === $privacy_policy_mode && '' !== $privacy_policy_content;

		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--register">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="vkbm-auth-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( ! empty( $atts['description'] ) ) : ?>
				<p class="vkbm-auth-card__description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<?php $this->render_error_list( $this->registration_errors ); ?>
			<form id="vkbm-provider-register-form" class="vkbm-auth-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
				<div class="vkbm-auth-form__field">
					<label class="vkbm-auth-form__label" for="vkbm-register-username">
						<?php esc_html_e( 'username', 'vk-booking-manager' ); ?>
						<span class="vkbm-auth-form__required" aria-hidden="true">*</span>
					</label>
					<input type="text" class="vkbm-auth-form__input" id="vkbm-register-username" name="user_login" value="<?php echo esc_attr( $username_value ); ?>" autocomplete="username" pattern="^[A-Za-z0-9_@.\-]+$" required>
					<p class="vkbm-auth-form__note"><?php esc_html_e( 'Only half-width alphanumeric characters・_・@・.・- can be used.', 'vk-booking-manager' ); ?></p>
				</div>
				<?php
				$this->render_text_field(
					array(
						'id'           => 'vkbm-register-email',
						'name'         => 'user_email',
						'label'        => __( 'Email address', 'vk-booking-manager' ),
						'value'        => $email_value,
						'type'         => 'email',
						'autocomplete' => 'email',
						'required'     => true,
					)
				);
				?>
				<div class="vkbm-auth-form__field">
					<label class="vkbm-auth-form__label" for="vkbm-register-password">
						<?php esc_html_e( 'password', 'vk-booking-manager' ); ?>
						<span class="vkbm-auth-form__required" aria-hidden="true">*</span>
					</label>
					<input type="password" class="vkbm-auth-form__input" id="vkbm-register-password" name="user_pass" autocomplete="new-password" required>
				</div>
				<div class="vkbm-auth-form__field">
					<label class="vkbm-auth-form__label" for="vkbm-register-password-confirm">
						<?php esc_html_e( 'Password (confirm)', 'vk-booking-manager' ); ?>
						<span class="vkbm-auth-form__required" aria-hidden="true">*</span>
					</label>
					<input type="password" class="vkbm-auth-form__input" id="vkbm-register-password-confirm" name="user_pass_confirm" autocomplete="new-password" required>
				</div>
				<?php
				$this->render_name_fields( 'register', $last_value, $first_value, false );
				$this->render_text_field(
					array(
						'id'           => 'vkbm-register-kana',
						'name'         => 'kana_name',
						'label'        => __( 'Furigana', 'vk-booking-manager' ),
						'value'        => $kana_value,
						'autocomplete' => 'off',
						'required'     => true,
					)
				);
				$this->render_text_field(
					array(
						'id'           => 'vkbm-register-phone',
						'name'         => 'phone_number',
						'label'        => __( 'Telephone number', 'vk-booking-manager' ),
						'value'        => $phone_value,
						'type'         => 'tel',
						'autocomplete' => 'tel',
						'required'     => true,
					)
				);
				$this->render_gender_field( 'vkbm-register-gender', $gender_value );
				?>
				<div class="vkbm-auth-form__field">
					<?php
					$this->render_birth_fields(
						'register',
						$birth_year_value,
						$birth_month_value,
						$birth_day_value
					);
					?>
				</div>
				<?php if ( $show_terms || $show_privacy_url || $show_privacy_content ) : ?>
					<div class="vkbm-agreements">
						<?php if ( $show_terms ) : ?>
							<div class="vkbm-agreement">
								<h3 class="vkbm-agreement__title">
									<?php esc_html_e( 'System Terms of Use', 'vk-booking-manager' ); ?>
								</h3>
								<div class="vkbm-agreement__body vkbm-agreement__body--scroll"><?php echo esc_html( $terms_of_service ); ?>
								</div>
								<div class="vkbm-agreement__check">
									<input
										type="checkbox"
										id="vkbm-register-terms"
										name="vkbm_agree_terms_of_service"
										value="1"
										<?php checked( $agree_terms_value ); ?>
										required
									>
									<label for="vkbm-register-terms">
										<?php esc_html_e( 'I agree to the terms of use', 'vk-booking-manager' ); ?>
									</label>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $show_privacy_content ) : ?>
							<div class="vkbm-agreement">
								<h3 class="vkbm-agreement__title">
									<?php esc_html_e( 'Privacy policy', 'vk-booking-manager' ); ?>
								</h3>
								<div class="vkbm-agreement__body vkbm-agreement__body--scroll"><?php echo esc_html( $privacy_policy_content ); ?>
								</div>
								<div class="vkbm-agreement__check">
									<input
										type="checkbox"
										id="vkbm-register-privacy"
										name="vkbm_agree_privacy_policy"
										value="1"
										<?php checked( $agree_privacy_value ); ?>
										required
									>
									<label for="vkbm-register-privacy">
										<?php esc_html_e( 'I agree to the privacy policy', 'vk-booking-manager' ); ?>
									</label>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $show_privacy_url ) : ?>
							<div class="vkbm-agreement">
								<h3 class="vkbm-agreement__title">
									<?php esc_html_e( 'Privacy policy', 'vk-booking-manager' ); ?>
								</h3>
								<div class="vkbm-agreement__check">
									<input
										type="checkbox"
										id="vkbm-register-privacy"
										name="vkbm_agree_privacy_policy"
										value="1"
										<?php checked( $agree_privacy_value ); ?>
										required
									>
									<label for="vkbm-register-privacy">
										<?php
										$privacy_link = sprintf(
											'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
											esc_url( $privacy_policy_url ),
											esc_html__( 'Privacy policy', 'vk-booking-manager' )
										);
										/* translators: %s: privacy policy link */
										echo wp_kses(
											sprintf( __( 'I agree with %s', 'vk-booking-manager' ), $privacy_link ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped by wp_kses.
											array(
												'a' => array(
													'href' => true,
													'target' => true,
													'rel'  => true,
												),
											)
										);
										?>
									</label>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="vkbm-auth-form__honeypot" aria-hidden="true">
					<label for="vkbm-register-honeypot"><?php esc_html_e( 'Email address (for confirmation)', 'vk-booking-manager' ); ?></label>
					<input type="text" class="vkbm-auth-form__input" id="vkbm-register-honeypot" name="vkbm_hp_email" value="" autocomplete="off" tabindex="-1">
					</div>
					<?php if ( $this->requires_email_verification() ) : ?>
						<p class="vkbm-alert vkbm-alert__info vkbm-register-email-notice" role="note">
							<?php esc_html_e( 'When you click the Register button, a confirmation email will be sent, so please check your email. If you have not received the email, please also check your spam folder.', 'vk-booking-manager' ); ?>
						</p>
					<?php endif; ?>
					<div class="vkbm-auth-form__actions">
						<button type="submit" class="vkbm-auth-button vkbm-button vkbm-button__md vkbm-button__primary"><?php echo esc_html( $atts['button_label'] ); ?></button>
					</div>
				<?php echo wp_nonce_field( 'vkbm_registration_form', 'vkbm_registration_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() outputs escaped HTML. ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<input type="hidden" name="auto_login" value="<?php echo $auto_login ? '1' : '0'; ?>">
				<input type="hidden" name="vkbm_registration_form" value="1">
				<input type="hidden" name="vkbm_auth" value="register">
			</form>
			<p id="vkbm-register-username-feedback" class="vkbm-auth-form__feedback" aria-live="polite" hidden></p>
			<script>
				(function () {
					var form = document.getElementById('vkbm-provider-register-form');
					var usernameInput = document.getElementById('vkbm-register-username');
					var feedback = document.getElementById('vkbm-register-username-feedback');
					if (!form || !usernameInput || !feedback) {
						return;
					}

					var submitButton = form.querySelector('button[type="submit"]');
					var pattern = /^[A-Za-z0-9_@.\-]+$/;

					var evaluate = function () {
						var value = usernameInput.value.trim();
						if (value === '') {
							feedback.textContent = '';
							if (submitButton) {
								submitButton.disabled = false;
							}
							return true;
						}

						if (!pattern.test(value)) {
							var message = '<?php echo esc_js( __( 'Please enter the username using only half-width alphanumeric characters, _, @, ., and -.', 'vk-booking-manager' ) ); ?>';
							feedback.textContent = message;
							usernameInput.setCustomValidity(message);
							if (submitButton) {
								submitButton.disabled = true;
							}
							return false;
						}

						feedback.textContent = '';
						usernameInput.setCustomValidity('');
						if (submitButton) {
							submitButton.disabled = false;
						}
						return true;
					};

					usernameInput.addEventListener('input', evaluate);

					form.addEventListener('submit', function (event) {
						if (!evaluate()) {
							event.preventDefault();
							usernameInput.focus();
						}
					});
				})();
			</script>
			<?php if ( ! empty( $login_url ) ) : ?>
				<p class="vkbm-auth-form__footer vkbm-auth-form__footer--login-link">
					<a class="vkbm-auth-form__link" href="<?php echo esc_url( $login_url ); ?>">
						<?php esc_html_e( 'Log in here', 'vk-booking-manager' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Outputs the profile edit form markup.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_profile_form( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_form(
				array(
					'description' => __( 'Please log in to edit your profile.', 'vk-booking-manager' ),
					'action_url'  => $atts['action_url'] ?? '',
				)
			);
		}

		$this->enqueue_assets();

		$defaults = array(
			'redirect'     => $this->get_current_url(),
			'title'        => __( 'Edit user information', 'vk-booking-manager' ),
			'description'  => '',
			'button_label' => __( 'Save', 'vk-booking-manager' ),
			'action_url'   => '',
		);

		$atts        = shortcode_atts( $defaults, $atts, 'vkbm_profile_form' );
		$redirect_to = $this->normalize_redirect( $atts['redirect'] ?? '' );
		$action_base = $this->normalize_redirect( $atts['action_url'] ?? '' );
		$form_action = $this->get_auth_action_url( 'profile', $action_base );

		$current_user      = wp_get_current_user();
		$first_value       = $this->profile_posted_data['first_name'] ?? (string) $current_user->first_name;
		$last_value        = $this->profile_posted_data['last_name'] ?? (string) $current_user->last_name;
		$email_value       = $this->profile_posted_data['user_email'] ?? (string) $current_user->user_email;
		$kana_value        = $this->profile_posted_data['kana_name'] ?? (string) get_user_meta( $current_user->ID, 'vkbm_kana_name', true );
		$phone_value       = $this->profile_posted_data['phone_number'] ?? (string) get_user_meta( $current_user->ID, 'phone_number', true );
		$birth_value       = $this->profile_posted_data['birth_date'] ?? (string) get_user_meta( $current_user->ID, 'vkbm_birth_date', true );
		$birth_parts       = $this->resolve_birth_parts( $this->profile_posted_data, $birth_value );
		$birth_year_value  = $birth_parts['year'];
		$birth_month_value = $birth_parts['month'];
		$birth_day_value   = $birth_parts['day'];
		$gender_value      = $this->profile_posted_data['gender'] ?? (string) get_user_meta( $current_user->ID, 'gender', true );

		$message = $this->consume_profile_notice();

		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--profile">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="vkbm-auth-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( ! empty( $atts['description'] ) ) : ?>
				<p class="vkbm-auth-card__description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<?php $this->render_error_list( $this->profile_errors ); ?>
			<?php if ( $message ) : ?>
				<div class="vkbm-alert vkbm-alert__success">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>
			<form class="vkbm-auth-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
				<div class="vkbm-auth-form__field">
					<label class="vkbm-auth-form__label" for="vkbm-profile-username"><?php esc_html_e( 'username', 'vk-booking-manager' ); ?></label>
					<input type="text" class="vkbm-auth-form__input" id="vkbm-profile-username" value="<?php echo esc_attr( $current_user->user_login ); ?>" disabled>
				</div>
				<?php
				$this->render_text_field(
					array(
						'id'           => 'vkbm-profile-email',
						'name'         => 'user_email',
						'label'        => __( 'email address', 'vk-booking-manager' ),
						'value'        => $email_value,
						'type'         => 'email',
						'autocomplete' => 'email',
						'required'     => true,
					)
				);
				$this->render_name_fields( 'profile', $last_value, $first_value, false );
				$this->render_text_field(
					array(
						'id'           => 'vkbm-profile-kana',
						'name'         => 'kana_name',
						'label'        => __( 'Furigana', 'vk-booking-manager' ),
						'value'        => $kana_value,
						'autocomplete' => 'off',
						'required'     => true,
					)
				);
				$this->render_text_field(
					array(
						'id'       => 'vkbm-profile-phone',
						'name'     => 'phone_number',
						'label'    => __( 'telephone number', 'vk-booking-manager' ),
						'value'    => $phone_value,
						'type'     => 'tel',
						'required' => true,
					)
				);
				$this->render_gender_field( 'vkbm-profile-gender', $gender_value );
				?>
				<div class="vkbm-auth-form__field">
					<?php
					$this->render_birth_fields(
						'profile',
						$birth_year_value,
						$birth_month_value,
						$birth_day_value
					);
					?>
				</div>
				<div class="vkbm-auth-form__field vkbm-auth-form__password">
					<label class="vkbm-auth-form__label" for="vkbm-profile-password"><?php esc_html_e( 'New Password', 'vk-booking-manager' ); ?></label>
					<div class="vkbm-auth-form__password-field">
						<input type="password" class="vkbm-auth-form__input" id="vkbm-profile-password" name="new_password" autocomplete="new-password">
						<button
							type="button"
							class="vkbm-auth-form__password-toggle"
							aria-controls="vkbm-profile-password"
							aria-pressed="false"
							data-show-label="<?php esc_attr_e( 'Show password', 'vk-booking-manager' ); ?>"
							data-hide-label="<?php esc_attr_e( 'hide password', 'vk-booking-manager' ); ?>"
						>
							<span class="vkbm-auth-form__password-toggle-label"><?php esc_html_e( 'show password', 'vk-booking-manager' ); ?></span>
							<svg class="vkbm-auth-form__password-toggle-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M12 5c-5.5 0-9.5 4.5-10.5 6.5C2.5 13.5 6.5 18 12 18s9.5-4.5 10.5-6.5C21.5 9.5 17.5 5 12 5zm0 11c-2.5 0-4.5-2-4.5-4.5S9.5 7 12 7s4.5 2 4.5 4.5S14.5 16 12 16zm0-7c-1.4 0-2.5 1.1-2.5 2.5S10.6 14 12 14s2.5-1.1 2.5-2.5S13.4 9 12 9z" />
							</svg>
						</button>
					</div>
					<p class="vkbm-auth-form__note"><?php esc_html_e( 'Leave empty if you do not want to change it.', 'vk-booking-manager' ); ?></p>
				</div>
				<div class="vkbm-auth-form__field vkbm-auth-form__password">
					<label class="vkbm-auth-form__label" for="vkbm-profile-password-confirm"><?php esc_html_e( 'New password (confirm)', 'vk-booking-manager' ); ?></label>
					<div class="vkbm-auth-form__password-field">
						<input type="password" class="vkbm-auth-form__input" id="vkbm-profile-password-confirm" name="new_password_confirm" autocomplete="new-password">
						<button
							type="button"
							class="vkbm-auth-form__password-toggle"
							aria-controls="vkbm-profile-password-confirm"
							aria-pressed="false"
							data-show-label="<?php esc_attr_e( 'Show password', 'vk-booking-manager' ); ?>"
							data-hide-label="<?php esc_attr_e( 'hide password', 'vk-booking-manager' ); ?>"
						>
							<span class="vkbm-auth-form__password-toggle-label"><?php esc_html_e( 'show password', 'vk-booking-manager' ); ?></span>
							<svg class="vkbm-auth-form__password-toggle-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M12 5c-5.5 0-9.5 4.5-10.5 6.5C2.5 13.5 6.5 18 12 18s9.5-4.5 10.5-6.5C21.5 9.5 17.5 5 12 5zm0 11c-2.5 0-4.5-2-4.5-4.5S9.5 7 12 7s4.5 2 4.5 4.5S14.5 16 12 16zm0-7c-1.4 0-2.5 1.1-2.5 2.5S10.6 14 12 14s2.5-1.1 2.5-2.5S13.4 9 12 9z" />
							</svg>
						</button>
					</div>
				</div>
					<div class="vkbm-auth-form__actions">
						<button type="submit" class="vkbm-auth-button vkbm-button vkbm-button__md vkbm-button__primary"><?php echo esc_html( $atts['button_label'] ); ?></button>
					</div>
				<?php echo wp_nonce_field( 'vkbm_profile_form', 'vkbm_profile_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() outputs escaped HTML. ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<input type="hidden" name="vkbm_profile_form" value="1">
				<input type="hidden" name="vkbm_auth" value="profile">
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handle profile update submissions.
	 */
	private function process_profile_request(): void {
		if ( ! is_user_logged_in() ) {
			$this->profile_errors = new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ) );
			return;
		}

		if ( empty( $_POST['vkbm_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vkbm_profile_nonce'] ) ), 'vkbm_profile_form' ) ) {
			return;
		}

		$raw = wp_unslash( $_POST );

		$first_name   = sanitize_text_field( $raw['first_name'] ?? '' );
		$last_name    = sanitize_text_field( $raw['last_name'] ?? '' );
		$kana_name    = sanitize_text_field( $raw['kana_name'] ?? '' );
		$phone_raw    = sanitize_text_field( $raw['phone_number'] ?? '' );
		$phone        = VKBM_Helper::normalize_phone_number( $phone_raw );
		$gender       = sanitize_text_field( $raw['gender'] ?? '' );
		$birth_year   = sanitize_text_field( $raw['birth_year'] ?? '' );
		$birth_month  = sanitize_text_field( $raw['birth_month'] ?? '' );
		$birth_day    = sanitize_text_field( $raw['birth_day'] ?? '' );
		$birth        = $this->build_birth_date( $birth_year, $birth_month, $birth_day );
		$email        = sanitize_email( $raw['user_email'] ?? '' );
		$new_pass     = (string) ( $raw['new_password'] ?? '' );
		$pass_confirm = (string) ( $raw['new_password_confirm'] ?? '' );

		$this->profile_posted_data = array(
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'kana_name'    => $kana_name,
			'phone_number' => $phone,
			'gender'       => $gender,
			'birth_year'   => $birth_year,
			'birth_month'  => $birth_month,
			'birth_day'    => $birth_day,
			'birth_date'   => $birth,
			'user_email'   => $email,
		);

		$errors  = new WP_Error();
		$user_id = get_current_user_id();

		$this->add_required_field_errors(
			$errors,
			array(
				array(
					'value'   => $kana_name,
					'code'    => 'missing_kana_name',
					'message' => $this->get_required_field_message( 'kana_name' ),
				),
				array(
					'value'   => $phone,
					'code'    => 'missing_phone',
					'message' => $this->get_required_field_message( 'phone_number' ),
				),
			)
		);
		$this->validate_email_for_context( $errors, $email, 'profile', $user_id );

		if ( '' !== $new_pass || '' !== $pass_confirm ) {
			$this->validate_password_pair( $errors, $new_pass, $pass_confirm, false );
		}

		if ( $errors->has_errors() ) {
			$this->profile_errors = $errors;
			return;
		}

		$userdata = array(
			'ID'         => $user_id,
			'user_email' => $email,
		);

		if ( '' !== $new_pass ) {
			$userdata['user_pass'] = $new_pass;
		}

		$update = wp_update_user( $userdata );

		if ( is_wp_error( $update ) ) {
			$this->profile_errors = $update;
			return;
		}

		update_user_meta( $user_id, 'first_name', $first_name );
		update_user_meta( $user_id, 'last_name', $last_name );
		$display_name = trim( $first_name . ' ' . $last_name );
		if ( '' !== $display_name ) {
			update_user_meta( $user_id, 'display_name', $display_name );
			update_user_meta( $user_id, 'vkbm_full_name', $display_name );
		}

		update_user_meta( $user_id, 'vkbm_kana_name', $kana_name );
		update_user_meta( $user_id, 'phone_number', $phone );
		update_user_meta( $user_id, 'gender', $gender );
		update_user_meta( $user_id, 'vkbm_birth_date', $birth );

		$this->set_profile_notice( __( 'User information has been updated.', 'vk-booking-manager' ) );

		$redirect_to = isset( $raw['redirect_to'] ) ? $this->normalize_redirect( $raw['redirect_to'] ) : $this->get_current_url();
		$redirect_to = add_query_arg( 'vkbm_auth', 'profile', $redirect_to );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Handles login submission.
	 */
	private function process_login_request(): void {
		$this->login_errors = new WP_Error();

		$nonce = isset( $_POST['vkbm_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_login_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vkbm_login_form' ) ) {
			$this->login_errors->add( 'invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'vk-booking-manager' ) );
			return;
		}

		$login_limit = $this->get_rate_limit_login_max();
		if ( $this->is_rate_limit_enabled() && ! $this->consume_rate_limit_token( 'login', $login_limit, self::RATE_LIMIT_LOGIN_WINDOW ) ) {
			$this->login_errors->add(
				'rate_limited',
				__( 'Too many attempts in a short period of time. Please try again later.', 'vk-booking-manager' )
			);
			return;
		}

		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password should not be sanitized.
		$remember = ! empty( $_POST['rememberme'] );

		$this->login_posted_data = array(
			'user_login' => $username,
			'remember'   => $remember,
		);

		if ( '' === $username ) {
			$this->login_errors->add( 'empty_username', __( 'Please enter your username (or email address).', 'vk-booking-manager' ) );
		}

		if ( '' === $password ) {
			$this->login_errors->add( 'empty_password', __( 'Please enter your password.', 'vk-booking-manager' ) );
		}

		if ( $this->login_errors->has_errors() ) {
			return;
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$message = $this->format_login_error_message( $user, $username );
			$this->login_errors->add( 'auth_failed', $message );
			$this->queue_login_error_cookie( $message );
			return;
		}

		$verified = get_user_meta( $user->ID, 'vkbm_email_verified', true );
		if ( '' === $verified ) {
			$verified = '1';
		}

		if ( '1' !== $verified ) {
			wp_clear_auth_cookie();
			$this->login_errors->add(
				'unverified_email',
				__( 'Email verification has not been completed. Please click the link in the registered email to confirm.', 'vk-booking-manager' )
			);
			$this->queue_login_error_cookie(
				__( 'Email verification has not been completed.', 'vk-booking-manager' )
			);
			return;
		}

		$redirect_to_raw = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$redirect_to     = '' !== $redirect_to_raw ? $this->normalize_redirect( $redirect_to_raw ) : $this->get_current_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Handles registration submission.
	 */
	private function process_registration_request(): void {
		$this->registration_errors = new WP_Error();

		$nonce    = isset( $_POST['vkbm_registration_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_registration_nonce'] ) ) : '';
		$honeypot = isset( $_POST['vkbm_hp_email'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_hp_email'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vkbm_registration_form' ) ) {
			$this->registration_errors->add( 'invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'vk-booking-manager' ) );
			$this->persist_registration_errors();
			return;
		}

		$register_limit = $this->get_rate_limit_register_max();
		if ( $this->is_rate_limit_enabled() && ! $this->consume_rate_limit_token( 'register', $register_limit, self::RATE_LIMIT_REGISTER_WINDOW ) ) {
			$this->registration_errors->add(
				'rate_limited',
				__( 'Too many attempts in a short period of time. Please try again later.', 'vk-booking-manager' )
			);
			$this->persist_registration_errors();
			return;
		}

		if ( '' !== $honeypot ) {
			$this->registration_errors->add( 'honeypot', __( 'An invalid request was detected.', 'vk-booking-manager' ) );
			$this->persist_registration_errors();
			return;
		}

		if ( ! get_option( 'users_can_register' ) ) {
			$this->registration_errors->add( 'registration_disabled', __( 'We are currently not accepting user registration.', 'vk-booking-manager' ) );
			$this->persist_registration_errors();
			return;
		}

		$original_username = isset( $_POST['user_login'] ) ? wp_unslash( $_POST['user_login'] ) : '';
		$username          = sanitize_user( $original_username, true );
		$email             = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
		$last_name         = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$first_name        = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
			$kana_name     = isset( $_POST['kana_name'] ) ? sanitize_text_field( wp_unslash( $_POST['kana_name'] ) ) : '';
			$phone_raw     = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
			$phone         = VKBM_Helper::normalize_phone_number( $phone_raw );
		$birth_year        = isset( $_POST['birth_year'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_year'] ) ) : '';
		$birth_month       = isset( $_POST['birth_month'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_month'] ) ) : '';
		$birth_day         = isset( $_POST['birth_day'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_day'] ) ) : '';
		$birth             = $this->build_birth_date( $birth_year, $birth_month, $birth_day );
			$gender        = isset( $_POST['gender'] ) ? sanitize_text_field( wp_unslash( $_POST['gender'] ) ) : '';
		$agree_terms       = ! empty( $_POST['vkbm_agree_terms_of_service'] );
		$agree_privacy     = ! empty( $_POST['vkbm_agree_privacy_policy'] );
		$password          = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password should not be sanitized.
		$confirm           = isset( $_POST['user_pass_confirm'] ) ? (string) wp_unslash( $_POST['user_pass_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password should not be sanitized.

			$this->registration_posted_data = array(
				'user_login'             => $username,
				'user_email'             => $email,
				'first_name'             => $first_name,
				'last_name'              => $last_name,
				'kana_name'              => $kana_name,
				'phone_number'           => $phone,
				'birth_year'             => $birth_year,
				'birth_month'            => $birth_month,
				'birth_day'              => $birth_day,
				'birth_date'             => $birth,
				'gender'                 => $gender,
				'agree_terms_of_service' => $agree_terms,
				'agree_privacy_policy'   => $agree_privacy,
			);
			$this->registration_raw_data    = array(
				'user_login' => $original_username,
			);

			if ( '' === $username ) {
				if ( '' !== trim( $original_username ) ) {
					$this->registration_errors->add(
						'invalid_username',
						__( 'Please enter your user name in half-width alphanumeric characters (letters, numbers, underscores).', 'vk-booking-manager' )
					);
				} else {
					$this->registration_errors->add( 'empty_username', __( 'Please enter your username.', 'vk-booking-manager' ) );
				}
			}

			$this->validate_email_for_context( $this->registration_errors, $email, 'register' );

			if ( '' === $password ) {
				$this->registration_errors->add( 'empty_password', __( 'Please enter your password.', 'vk-booking-manager' ) );
			}

			$this->validate_password_pair( $this->registration_errors, $password, $confirm, true );
			$this->add_required_field_errors(
				$this->registration_errors,
				array(
					array(
						'value'   => $kana_name,
						'code'    => 'missing_kana_name',
						'message' => $this->get_required_field_message( 'kana_name' ),
					),
					array(
						'value'   => $phone,
						'code'    => 'empty_phone',
						'message' => $this->get_required_field_message( 'phone_number' ),
					),
				)
			);
		$settings         = $this->settings_service->get_settings();
		$terms_of_service = isset( $settings['provider_terms_of_service'] ) ? trim( (string) $settings['provider_terms_of_service'] ) : '';
		if ( '' !== $terms_of_service && ! $agree_terms ) {
			$this->registration_errors->add(
				'terms_required',
				__( 'You must agree to the terms of use.', 'vk-booking-manager' )
			);
		}
		$privacy_policy_mode = isset( $settings['provider_privacy_policy_mode'] ) ? sanitize_key( (string) $settings['provider_privacy_policy_mode'] ) : 'none';
		if ( ! in_array( $privacy_policy_mode, array( 'none', 'url', 'content' ), true ) ) {
			$privacy_policy_mode = 'none';
		}
		$privacy_policy_url     = isset( $settings['provider_privacy_policy_url'] ) ? trim( (string) $settings['provider_privacy_policy_url'] ) : '';
		$privacy_policy_content = isset( $settings['provider_privacy_policy_content'] ) ? trim( (string) $settings['provider_privacy_policy_content'] ) : '';
		$requires_privacy       = ( 'url' === $privacy_policy_mode && '' !== $privacy_policy_url )
			|| ( 'content' === $privacy_policy_mode && '' !== $privacy_policy_content );
		if ( $requires_privacy && ! $agree_privacy ) {
			$this->registration_errors->add(
				'privacy_required',
				__( 'You must agree to the privacy policy.', 'vk-booking-manager' )
			);
		}
		if ( username_exists( $username ) ) {
			$this->registration_errors->add( 'username_exists', __( 'This username is already in use.', 'vk-booking-manager' ) );
		}

		if ( $this->registration_errors->has_errors() ) {
			$this->persist_registration_errors();
			return;
		}

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			$this->registration_errors->add( 'registration_failed', $user_id->get_error_message() );
			$this->persist_registration_errors();
			return;
		}

		$this->store_registration_metadata( $user_id, $first_name, $last_name, $kana_name, $phone, $birth, $gender );

		$redirect_to           = isset( $_POST['redirect_to'] ) ? $this->normalize_redirect( wp_unslash( $_POST['redirect_to'] ) ) : $this->get_current_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$requires_verification = $this->requires_email_verification();

		if ( $requires_verification ) {
			update_user_meta( $user_id, 'vkbm_email_verified', '0' );

			$token   = $this->generate_email_token();
			$expires = time() + self::EMAIL_TOKEN_TTL;
			update_user_meta( $user_id, 'vkbm_email_verify_token', $token );
			update_user_meta( $user_id, 'vkbm_email_verify_expires', $expires );

			if ( ! $this->send_verification_email( $email, $redirect_to, $token ) ) {
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}

				wp_delete_user( $user_id );
				$this->registration_errors->add(
					'verification_email_failed',
					__( 'Failed to send confirmation email. Please try again later.', 'vk-booking-manager' )
				);
				$this->persist_registration_errors();
				return;
			}

			$this->set_verification_notice(
				__( 'A confirmation email has been sent. Click the link in the email to authenticate and log in.', 'vk-booking-manager' )
			);

			$redirect_to = add_query_arg( 'vkbm_auth', 'login', $redirect_to );
		} else {
			update_user_meta( $user_id, 'vkbm_email_verified', '1' );
			delete_user_meta( $user_id, 'vkbm_email_verify_token' );
			delete_user_meta( $user_id, 'vkbm_email_verify_expires' );

			$this->set_verification_notice(
				__( 'Thank you for registering. Please log in to continue booking.', 'vk-booking-manager' )
			);

			$redirect_to = add_query_arg( 'vkbm_auth', 'login', $redirect_to );
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Store registration errors and posted data for the next request.
	 */
	private function persist_registration_errors(): void {
		if ( ! $this->registration_errors instanceof WP_Error || ! $this->registration_errors->has_errors() ) {
			return;
		}

		$payload = array(
			'messages' => $this->registration_errors->get_error_messages(),
			'posted'   => $this->registration_posted_data,
			'raw'      => $this->registration_raw_data,
		);

		$this->set_notice_cookie( 'vkbm_registration_errors', wp_json_encode( $payload ), '/' );
	}

	/**
	 * Restore registration errors and posted data from cookies.
	 */
	private function restore_registration_errors(): void {
		$payload = $this->consume_notice_cookie( 'vkbm_registration_errors' );
		if ( null === $payload ) {
			return;
		}

		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$messages                       = isset( $data['messages'] ) && is_array( $data['messages'] ) ? $data['messages'] : array();
		$this->registration_posted_data = isset( $data['posted'] ) && is_array( $data['posted'] ) ? $data['posted'] : array();
		$this->registration_raw_data    = isset( $data['raw'] ) && is_array( $data['raw'] ) ? $data['raw'] : array();

		if ( empty( $messages ) ) {
			return;
		}

		$errors = new WP_Error();
		foreach ( $messages as $message ) {
			if ( is_string( $message ) && '' !== $message ) {
				$errors->add( 'registration_error', $message );
			}
		}

		if ( $errors->has_errors() ) {
			$this->registration_errors = $errors;
		}
	}

	/**
	 * Prints errors if present.
	 *
	 * @param WP_Error|null $errors Error bag.
	 */
	private function render_error_list( ?WP_Error $errors ): void {
		if ( ! $errors instanceof WP_Error || ! $errors->has_errors() ) {
			return;
		}
		?>
		<div class="vkbm-alert vkbm-alert__danger" role="alert">
			<ul>
				<?php foreach ( $errors->get_error_messages() as $message ) : ?>
					<li><?php echo wp_kses( $message, $this->get_allowed_error_tags() ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Returns allowed tags for login error output.
	 *
	 * @return array<string, array<string, bool|string|array>>
	 */
	private function get_allowed_error_tags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'rel'    => true,
				'target' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * Renders the notice displayed when the visitor is logged in.
	 *
	 * @return string
	 */
	private function render_logged_in_message(): string {
		$this->enqueue_assets();

		$user = wp_get_current_user();
		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--logged-in">
			<p class="vkbm-auth-card__description">
				<?php
				printf(
					/* translators: %s: user display name */
					esc_html__( 'You are logged in as %s.', 'vk-booking-manager' ),
					esc_html( $user->display_name )
				);
				?>
			</p>
			<p class="vkbm-auth-form__footer">
				<a class="vkbm-auth-form__link" href="<?php echo esc_url( wp_logout_url( $this->get_current_url() ) ); ?>"><?php esc_html_e( 'Log out', 'vk-booking-manager' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders registration disabled notice.
	 *
	 * @return string
	 */
	private function render_registration_disabled_notice(): string {
		$this->enqueue_assets();
		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--notice">
			<p class="vkbm-auth-card__description"><?php esc_html_e( 'We are currently not accepting user registration.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Ensures the stylesheet is loaded when the shortcode is used.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( Common_Styles::AUTH_HANDLE );

		$plugin_root  = dirname( __DIR__, 2 );
		$auth_js      = $plugin_root . '/assets/js/auth-forms.js';
		$auth_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0';
		if ( file_exists( $auth_js ) ) {
			$auth_version = (string) filemtime( $auth_js );
		}

		wp_enqueue_script(
			'vkbm-auth-forms',
			plugins_url( 'assets/js/auth-forms.js', dirname( __DIR__ ) ),
			array(),
			$auth_version,
			true
		);
	}

	/**
	 * Attempts to build the current URL.
	 *
	 * @return string
	 */
	private function get_current_url(): string {
		global $post, $wp;

		if ( $post instanceof WP_Post ) {
			$url = get_permalink( $post );
			if ( $url ) {
				return $url;
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		if ( isset( $wp->request ) && $wp->request ) {
			$base = home_url( '/' . ltrim( $wp->request, '/' ) );

			if ( ! empty( $_GET ) ) {
				$query_args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building current URL.
				return add_query_arg( $query_args, $base );
			}

			return $base;
		}

		return home_url( $request_uri );
	}

	/**
	 * Build action URL that retains auth mode query.
	 *
	 * @param string      $mode     Auth mode name.
	 * @param string|null $base_url Base URL. Defaults to current URL if null.
	 * @return string
	 */
	private function get_auth_action_url( string $mode, ?string $base_url = null ): string {
		$target = $this->normalize_redirect( $base_url ?? '', $this->get_current_url() );

		return esc_url_raw( add_query_arg( 'vkbm_auth', $mode, $target ) );
	}

	/**
	 * Returns a sanitized redirect target.
	 *
	 * @param string|mixed $value Raw URL.
	 * @param string|null  $fallback Fallback URL.
	 * @return string
	 */
	private function normalize_redirect( $value, ?string $fallback = null ): string {
		$value   = is_string( $value ) ? $value : '';
		$default = $fallback ?? $this->get_current_url();
		$url     = esc_url_raw( wp_unslash( $value ) );

		if ( empty( $url ) ) {
			return $default;
		}

		return wp_validate_redirect( $url, $default );
	}

	/**
	 * Evaluates various truthy/falsey values passed as shortcode atts.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( (string) $value );

		return ! in_array( $value, array( 'false', '0', 'off', '' ), true );
	}

	/**
	 * Save extra profile values after user creation.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @param string $kana_name Kana.
	 * @param string $phone     Phone number.
	 * @param string $birth     Birth date.
	 * @param string $gender    Gender value.
	 */
	private function store_registration_metadata( int $user_id, string $first_name, string $last_name, string $kana_name, string $phone, string $birth, string $gender ): void {
		if ( '' !== $first_name ) {
			update_user_meta( $user_id, 'first_name', $first_name );
		}

		if ( '' !== $last_name ) {
			update_user_meta( $user_id, 'last_name', $last_name );
		}

		$display_name = trim( $first_name . ' ' . $last_name );
		if ( '' !== $display_name ) {
			update_user_meta( $user_id, 'display_name', $display_name );
			update_user_meta( $user_id, 'vkbm_full_name', $display_name );
		}

		if ( '' !== $kana_name ) {
			update_user_meta( $user_id, 'vkbm_kana_name', $kana_name );
		}

		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'phone_number', $phone );
		}

		if ( '' !== $birth ) {
			update_user_meta( $user_id, 'vkbm_birth_date', $birth );
		}

		if ( '' !== $gender ) {
			update_user_meta( $user_id, 'gender', $gender );
		}
	}

	/**
	 * Generates a random token for email verification.
	 *
	 * @return string
	 */
	private function generate_email_token(): string {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Sends the verification email to the given address.
	 *
	 * @param string $email       User email.
	 * @param string $redirect_to Redirect URL to include in the verification link.
	 * @param string $token       Verification token.
	 * @return bool True if the email was queued, false otherwise.
	 */
	private function send_verification_email( string $email, string $redirect_to, string $token ): bool {
		$target_url       = '' !== $redirect_to ? $redirect_to : home_url();
		$verification_url = add_query_arg( 'vkbm_verify_email', $token, $target_url );
		$site_name        = get_bloginfo( 'name' );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Confirm email address', 'vk-booking-manager' ), $site_name );

		// Build email message by translating each sentence separately.
		$message  = __( 'Thank you for registering.', 'vk-booking-manager' ) . "\n\n";
		$message .= __( 'Click the link below to complete email address verification.', 'vk-booking-manager' ) . "\n\n";
		$message .= $verification_url . "\n\n";
		/* translators: %d: number of hours */
		$message .= sprintf( __( 'Link expires in %d hours.', 'vk-booking-manager' ), (int) ( self::EMAIL_TOKEN_TTL / HOUR_IN_SECONDS ) );

		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from_email = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$from_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$provider_settings = $this->settings_service->get_settings();
		$email_log_enabled = ! empty( $provider_settings['email_log_enabled'] );

		$result = wp_mail( $email, $subject, $message, $headers );

		// Get error information if available.
		$error_info = '';
		if ( ! $result ) {
			global $phpmailer;
			if ( isset( $phpmailer ) && is_object( $phpmailer ) ) {
				$phpmailer_error = isset( $phpmailer->ErrorInfo ) ? $phpmailer->ErrorInfo : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property name.
				if ( ! empty( $phpmailer_error ) ) {
					$error_info = $phpmailer_error;
				}
			}
			if ( empty( $error_info ) ) {
				$error_info = 'Unknown error';
			}
		}

		// Save log entry.
		if ( $email_log_enabled ) {
			$log_repository = new Email_Log_Repository();
			$log_repository->add_log( $email, $subject, (bool) $result, $error_info );
		}

		return (bool) $result;
	}

	/**
	 * Store a transient notice that can be shown after redirects.
	 *
	 * @param string $message Notice text.
	 */
	private function set_verification_notice( string $message ): void {
		$this->set_notice_cookie( 'vkbm_verification_notice', $message, '/' );
	}

	/**
	 * Store a temporary notice for profile updates.
	 *
	 * @param string $message Notice text.
	 */
	private function set_profile_notice( string $message ): void {
		$this->set_notice_cookie( 'vkbm_profile_notice', $message );
	}


	/**
	 * Generic helper to store a notice cookie.
	 *
	 * @param string $name    Cookie key.
	 * @param string $message Notice.
	 */
	private function set_notice_cookie( string $name, string $message, ?string $path = null ): void {
		if ( headers_sent() ) {
			return;
		}

		$cookie_path = null !== $path ? $path : ( defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/' );

		setcookie(
			$name,
			rawurlencode( $message ),
			time() + 30,
			$cookie_path,
			defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			$this->is_request_secure(),
			true
		);
	}

	/**
	 * Consume a notice stored in cookies.
	 *
	 * @return string|null
	 */
	private function consume_verification_notice(): ?string {
		return $this->consume_notice_cookie( 'vkbm_verification_notice' );
	}

	/**
	 * Consume stored profile notice.
	 *
	 * @return string|null
	 */
	private function consume_profile_notice(): ?string {
		return $this->consume_notice_cookie( 'vkbm_profile_notice' );
	}

	/**
	 * Generic helper to consume and clear notice cookies.
	 *
	 * @param string $name Cookie key.
	 * @return string|null
	 */
	private function consume_notice_cookie( string $name ): ?string {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$cookie_value = isset( $_COOKIE[ $name ] ) ? (string) wp_unslash( $_COOKIE[ $name ] ) : '';
		$value        = rawurldecode( $cookie_value );

		if ( headers_sent() ) {
			return $value;
		}

		$primary_path   = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$cookie_domain  = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
		setcookie( $name, '', time() - 3600, $primary_path, $cookie_domain );
		if ( '/' !== $primary_path ) {
			setcookie( $name, '', time() - 3600, '/', $cookie_domain );
		}

		return $value;
	}

	/**
	 * Returns whether the current request is over HTTPS.
	 *
	 * @return bool
	 */
	private function is_request_secure(): bool {
		if ( function_exists( 'wp_is_https' ) ) {
			return wp_is_https();
		}

		if ( function_exists( 'is_ssl' ) ) {
			return is_ssl();
		}

		return false;
	}

	/**
	 * Resolve birth date parts from posted data and stored date.
	 *
	 * @param array<string, mixed> $posted Post values.
	 * @param string               $birth_value Stored birth date (YYYY-MM-DD).
	 * @return array{year: string, month: string, day: string}
	 */
	private function resolve_birth_parts( array $posted, string $birth_value ): array {
		// Prefer explicit inputs; fallback to stored date. / 入力値を優先し、なければ保存済み日付を使用。
		$birth_year  = isset( $posted['birth_year'] ) ? (string) $posted['birth_year'] : '';
		$birth_month = isset( $posted['birth_month'] ) ? (string) $posted['birth_month'] : '';
		$birth_day   = isset( $posted['birth_day'] ) ? (string) $posted['birth_day'] : '';

		if ( '' !== $birth_value && '' === $birth_year && '' === $birth_month && '' === $birth_day ) {
			$parts = explode( '-', $birth_value );
			if ( 3 === count( $parts ) ) {
				$birth_year  = $parts[0];
				$birth_month = $parts[1];
				$birth_day   = $parts[2];
			}
		}

		return array(
			'year'  => $birth_year,
			'month' => $birth_month,
			'day'   => $birth_day,
		);
	}

	/**
	 * Build birth date string from input parts.
	 *
	 * @param string $birth_year  Birth year input.
	 * @param string $birth_month Birth month input.
	 * @param string $birth_day   Birth day input.
	 * @return string
	 */
	private function build_birth_date( string $birth_year, string $birth_month, string $birth_day ): string {
		// Normalize numeric parts before composing. / 数値に正規化してから日付を構成.
		if ( '' === $birth_year && '' === $birth_month && '' === $birth_day ) {
			return '';
		}

		$year  = preg_replace( '/\D/', '', $birth_year );
		$month = preg_replace( '/\D/', '', $birth_month );
		$day   = preg_replace( '/\D/', '', $birth_day );

		if ( '' === $year || '' === $month || '' === $day ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', (int) $year, (int) $month, (int) $day );
	}

	/**
	 * Render birth date inputs in a shared format.
	 *
	 * @param string $prefix Prefix for element IDs (register/profile).
	 * @param string $birth_year Birth year value.
	 * @param string $birth_month Birth month value.
	 * @param string $birth_day Birth day value.
	 * @return void
	 */
	private function render_birth_fields( string $prefix, string $birth_year, string $birth_month, string $birth_day ): void {
		$legend_id = sprintf( 'vkbm-%s-birth-legend', $prefix );
		?>
		<span class="vkbm-auth-form__label" id="<?php echo esc_attr( $legend_id ); ?>"><?php esc_html_e( 'date of birth', 'vk-booking-manager' ); ?></span>
		<div class="vkbm-auth-form__field-group vkbm-auth-form__field-group--inline" aria-labelledby="<?php echo esc_attr( $legend_id ); ?>">
			<select
				class="vkbm-auth-form__input"
				id="<?php echo esc_attr( "vkbm-{$prefix}-birth-year" ); ?>"
				name="birth_year"
				autocomplete="bday-year"
				aria-label="<?php esc_attr_e( 'Date of birth (year)', 'vk-booking-manager' ); ?>"
			>
				<?php $this->render_birth_year_options( $birth_year ); ?>
			</select>
			<span class="vkbm-auth-form__unit"><?php esc_html_e( 'year', 'vk-booking-manager' ); ?></span>
			<select
				class="vkbm-auth-form__input"
				id="<?php echo esc_attr( "vkbm-{$prefix}-birth-month" ); ?>"
				name="birth_month"
				autocomplete="bday-month"
				aria-label="<?php esc_attr_e( 'date of birth (month)', 'vk-booking-manager' ); ?>"
			>
				<?php $this->render_birth_month_options( $birth_month ); ?>
			</select>
			<span class="vkbm-auth-form__unit"><?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?></span>
			<select
				class="vkbm-auth-form__input"
				id="<?php echo esc_attr( "vkbm-{$prefix}-birth-day" ); ?>"
				name="birth_day"
				autocomplete="bday-day"
				aria-label="<?php esc_attr_e( 'date of birth (day)', 'vk-booking-manager' ); ?>"
			>
				<?php $this->render_birth_day_options( $birth_day ); ?>
			</select>
			<span class="vkbm-auth-form__unit"><?php esc_html_e( 'Sun', 'vk-booking-manager' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render birth year select options.
	 *
	 * @param string $selected Selected year.
	 * @return void
	 */
	private function render_birth_year_options( string $selected ): void {
		// Build year list from current year to 1900 for easier selection on mobile.
		// スマホで選びやすいように現在年から1900年までの選択肢を作る。
		$current_year = (int) gmdate( 'Y' );
		echo '<option value="">' . esc_html__( 'Select', 'vk-booking-manager' ) . '</option>';
		for ( $year = $current_year; $year >= 1900; $year-- ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( (string) $year ),
				selected( (string) $year, $selected, false )
			);
		}
	}

	/**
	 * Render birth month select options.
	 *
	 * @param string $selected Selected month.
	 * @return void
	 */
	private function render_birth_month_options( string $selected ): void {
		// Use zero-padded month values to match stored format.
		// 保存形式に合わせてゼロ埋めの月を使用する.
		echo '<option value="">' . esc_html__( 'Select', 'vk-booking-manager' ) . '</option>';
		for ( $month = 1; $month <= 12; $month++ ) {
			$value = sprintf( '%02d', $month );
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $value, $selected, false ),
				esc_html( (string) $month )
			);
		}
	}

	/**
	 * Render birth day select options.
	 *
	 * @param string $selected Selected day.
	 * @return void
	 */
	private function render_birth_day_options( string $selected ): void {
		// Use zero-padded day values to match stored format.
		// 保存形式に合わせてゼロ埋めの日を使用する。
		echo '<option value="">' . esc_html__( 'Select', 'vk-booking-manager' ) . '</option>';
		for ( $day = 1; $day <= 31; $day++ ) {
			$value = sprintf( '%02d', $day );
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $value, $selected, false ),
				esc_html( (string) $day )
			);
		}
	}

	/**
	 * Render a text-based form field.
	 *
	 * @param array{
	 *   id: string,
	 *   name: string,
	 *   label: string,
	 *   value: string,
	 *   type?: string,
	 *   args?: array<string, mixed>
	 * } $args Field arguments.
	 *   autocomplete?: string,
	 *   required?: bool
	 * } $args Field settings.
	 * @return void
	 */
	private function render_text_field( array $args ): void {
		$type         = isset( $args['type'] ) ? (string) $args['type'] : 'text';
		$autocomplete = isset( $args['autocomplete'] ) ? (string) $args['autocomplete'] : '';
		$required     = ! empty( $args['required'] );
		?>
		<div class="vkbm-auth-form__field">
			<label class="vkbm-auth-form__label" for="<?php echo esc_attr( $args['id'] ); ?>">
				<?php echo esc_html( $args['label'] ); ?>
				<?php if ( $required ) : ?>
					<span class="vkbm-auth-form__required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				class="vkbm-auth-form__input"
				id="<?php echo esc_attr( $args['id'] ); ?>"
				name="<?php echo esc_attr( $args['name'] ); ?>"
				value="<?php echo esc_attr( $args['value'] ); ?>"
				<?php if ( '' !== $autocomplete ) : ?>
					autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
				<?php endif; ?>
				<?php if ( $required ) : ?>
					required
				<?php endif; ?>
			>
		</div>
		<?php
	}

	/**
	 * Render last/first name fields in a shared layout.
	 *
	 * @param string $prefix Prefix for element IDs (register/profile).
	 * @param string $last_value Last name value.
	 * @param string $first_value First name value.
	 * @param bool   $last_required Whether last name is required.
	 * @return void
	 */
	private function render_name_fields( string $prefix, string $last_value, string $first_value, bool $last_required ): void {
		?>
		<div class="vkbm-auth-form__field-group">
			<div class="vkbm-auth-form__field">
				<label class="vkbm-auth-form__label" for="<?php echo esc_attr( "vkbm-{$prefix}-last" ); ?>">
					<?php esc_html_e( 'Last name', 'vk-booking-manager' ); ?>
					<?php if ( $last_required ) : ?>
						<span class="vkbm-auth-form__required" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>
				<input
					type="text"
					class="vkbm-auth-form__input"
					id="<?php echo esc_attr( "vkbm-{$prefix}-last" ); ?>"
					name="last_name"
					value="<?php echo esc_attr( $last_value ); ?>"
					autocomplete="family-name"
					<?php if ( $last_required ) : ?>
						required
					<?php endif; ?>
				>
			</div>
			<div class="vkbm-auth-form__field">
				<label class="vkbm-auth-form__label" for="<?php echo esc_attr( "vkbm-{$prefix}-first" ); ?>">
					<?php esc_html_e( 'given name', 'vk-booking-manager' ); ?>
				</label>
				<input
					type="text"
					class="vkbm-auth-form__input"
					id="<?php echo esc_attr( "vkbm-{$prefix}-first" ); ?>"
					name="first_name"
					value="<?php echo esc_attr( $first_value ); ?>"
					autocomplete="given-name"
				>
			</div>
		</div>
		<?php
	}

	/**
	 * Render gender select field.
	 *
	 * @param string $id Field ID.
	 * @param string $value Current value.
	 * @return void
	 */
	private function render_gender_field( string $id, string $value ): void {
		?>
		<div class="vkbm-auth-form__field">
			<label class="vkbm-auth-form__label" for="<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'sex', 'vk-booking-manager' ); ?>
			</label>
			<select class="vkbm-auth-form__input" id="<?php echo esc_attr( $id ); ?>" name="gender">
				<option value=""><?php esc_html_e( 'please select', 'vk-booking-manager' ); ?></option>
				<option value="male" <?php selected( $value, 'male' ); ?>><?php esc_html_e( 'male', 'vk-booking-manager' ); ?></option>
				<option value="female" <?php selected( $value, 'female' ); ?>><?php esc_html_e( 'woman', 'vk-booking-manager' ); ?></option>
				<option value="other" <?php selected( $value, 'other' ); ?>><?php esc_html_e( 'others', 'vk-booking-manager' ); ?></option>
			</select>
		</div>
		<?php
	}

	/**
	 * Add required-field errors in a shared format.
	 *
	 * 共通の必須エラーをまとめて追加します。
	 *
	 * @param WP_Error                        $errors Error bag.
	 * @param array<int, array<string,mixed>> $requirements Required rules.
	 * @return void
	 */
	private function add_required_field_errors( WP_Error $errors, array $requirements ): void {
		// Apply shared "required" messages. / 共通の必須チェックを適用.
		foreach ( $requirements as $requirement ) {
			$value   = isset( $requirement['value'] ) ? (string) $requirement['value'] : '';
			$code    = isset( $requirement['code'] ) ? (string) $requirement['code'] : 'required';
			$message = isset( $requirement['message'] ) ? (string) $requirement['message'] : '';

			if ( '' === $value && '' !== $message ) {
				$errors->add( $code, $message );
			}
		}
	}

	/**
	 * Return shared required-field messages.
	 *
	 * 共通の必須メッセージを返します。
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private function get_required_field_message( string $field ): string {
		switch ( $field ) {
			case 'kana_name':
				return __( 'Please enter furigana.', 'vk-booking-manager' );
			case 'phone_number':
				return __( 'Please enter your phone number.', 'vk-booking-manager' );
			default:
				return __( 'Please fill in the required fields.', 'vk-booking-manager' );
		}
	}

	/**
	 * Validate email by context and add appropriate errors.
	 *
	 * 画面コンテキストごとにメールを検証します。
	 *
	 * @param WP_Error $errors Error bag.
	 * @param string   $email Email value.
	 * @param string   $context Validation context (register/profile).
	 * @param int      $current_user_id Current user ID for profile.
	 * @return void
	 */
	private function validate_email_for_context( WP_Error $errors, string $email, string $context, int $current_user_id = 0 ): void {
		// Normalize common email rules. / メールアドレスの共通ルールを適用。
		if ( '' === $email || ! is_email( $email ) ) {
			$errors->add( 'invalid_email', __( 'Please enter a valid email address.', 'vk-booking-manager' ) );
			return;
		}

		if ( 'profile' === $context ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing && (int) $existing->ID !== (int) $current_user_id ) {
				$errors->add( 'email_in_use', __( 'This email address is already in use.', 'vk-booking-manager' ) );
			}
			return;
		}

		if ( email_exists( $email ) ) {
			$errors->add( 'email_exists', __( 'This email address is already registered.', 'vk-booking-manager' ) );
		}
	}

	/**
	 * Validate password pair for registration/profile flows.
	 *
	 * パスワードの一致・長さを共通チェックします。
	 *
	 * @param WP_Error $errors Error bag.
	 * @param string   $password Password value.
	 * @param string   $confirm Confirmation value.
	 * @param bool     $is_register Whether the context is registration.
	 * @return void
	 */
	private function validate_password_pair( WP_Error $errors, string $password, string $confirm, bool $is_register ): void {
		// Skip when both are empty for profile updates. / プロフィール更新時は未入力ならスキップ.
		if ( ! $is_register && '' === $password && '' === $confirm ) {
			return;
		}

		if ( $password !== $confirm ) {
			$message = $is_register
				? __( 'Please enter the same password twice.', 'vk-booking-manager' )
				: __( 'New passwords do not match.', 'vk-booking-manager' );
			$errors->add( 'password_mismatch', $message );
			return;
		}

		if ( '' !== $password && strlen( $password ) < 8 ) {
			$errors->add( 'password_short', __( 'Please enter a password of 8 characters or more.', 'vk-booking-manager' ) );
		}
	}

	/**
	 * Determines if email verification is required per settings.
	 *
	 * @return bool
	 */
	private function requires_email_verification(): bool {
		$settings = $this->settings_service->get_settings();
		return ! empty( $settings['registration_email_verification_enabled'] );
	}

	/**
	 * Determines if rate limiting is enabled per settings.
	 *
	 * @return bool
	 */
	private function is_rate_limit_enabled(): bool {
		$settings = $this->settings_service->get_settings();
		return ! empty( $settings['auth_rate_limit_enabled'] );
	}

	/**
	 * Returns the max registration attempts within the rate limit window.
	 *
	 * @return int
	 */
	private function get_rate_limit_register_max(): int {
		$settings = $this->settings_service->get_settings();
		$limit    = isset( $settings['auth_rate_limit_register_max'] )
			? (int) $settings['auth_rate_limit_register_max']
			: self::RATE_LIMIT_REGISTER_MAX;

		return $limit > 0 ? $limit : self::RATE_LIMIT_REGISTER_MAX;
	}

	/**
	 * Returns the max login attempts within the rate limit window.
	 *
	 * @return int
	 */
	private function get_rate_limit_login_max(): int {
		$settings = $this->settings_service->get_settings();
		$limit    = isset( $settings['auth_rate_limit_login_max'] )
			? (int) $settings['auth_rate_limit_login_max']
			: self::RATE_LIMIT_LOGIN_MAX;

		return $limit > 0 ? $limit : self::RATE_LIMIT_LOGIN_MAX;
	}

	/**
	 * Consume a rate limit token for the given action.
	 *
	 * @param string $action Action key (login/register).
	 * @param int    $max    Max attempts per window.
	 * @param int    $window Window seconds.
	 * @return bool True if allowed.
	 */
	private function consume_rate_limit_token( string $action, int $max, int $window ): bool {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$hash = substr( sha1( $action . '|' . $ip ), 0, 20 );
		$key  = 'vkbm_rl_' . $hash;
		$now  = time();

		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}

		$reset = isset( $state['reset'] ) ? (int) $state['reset'] : 0;
		$count = isset( $state['count'] ) ? (int) $state['count'] : 0;

		if ( $reset <= $now ) {
			$reset = $now + $window;
			$count = 0;
		}

		if ( $count >= $max ) {
			$ttl = max( 1, $reset - $now );
			set_transient(
				$key,
				array(
					'count' => $count,
					'reset' => $reset,
				),
				$ttl
			);
			return false;
		}

		++$count;
		$ttl = max( 1, $reset - $now );
		set_transient(
			$key,
			array(
				'count' => $count,
				'reset' => $reset,
			),
			$ttl
		);

		return true;
	}

	/**
	 * Get client IP address for basic rate limiting.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$remote_addr = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_addr = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$remote_addr     = preg_replace( '/[^0-9a-fA-F:\\.]/', '', (string) $remote_addr );
		$trusted_proxies = apply_filters( 'vkbm_trusted_proxy_ips', array() );
		if ( ! is_array( $trusted_proxies ) ) {
			$trusted_proxies = array();
		}

		$forwarded_ip = '';
		if (
			'' !== $remote_addr
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			&& in_array( $remote_addr, $trusted_proxies, true )
		) {
			// Respect XFF only for trusted proxies. / 信頼できるプロキシ経由のみXFFを採用します。
			$candidates   = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$forwarded_ip = trim( (string) ( $candidates[0] ?? '' ) );
			$forwarded_ip = preg_replace( '/[^0-9a-fA-F:\\.]/', '', (string) $forwarded_ip );
		}

		$ip = '' !== $forwarded_ip ? $forwarded_ip : $remote_addr;

		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Stores the latest login error in a cookie so it survives reloads.
	 *
	 * @param string $message Error message.
	 */
	private function queue_login_error_cookie( string $message ): void {
		if ( headers_sent() ) {
			return;
		}

		$cookie_value = rawurlencode( $message );
		setcookie(
			'vkbm_login_error',
			$cookie_value,
			time() + 30,
			defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			$this->is_request_secure(),
			true
		);
	}

	/**
	 * Consume any pending login error stored in a cookie.
	 *
	 * @return string|null
	 */
	private function consume_login_error_cookie(): ?string {
		if ( empty( $_COOKIE['vkbm_login_error'] ) ) {
			return null;
		}

		$value = rawurldecode( (string) wp_unslash( $_COOKIE['vkbm_login_error'] ) );

		$cookie_path  = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$cookie_domain = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
		setcookie( 'vkbm_login_error', '', time() - 3600, $cookie_path, $cookie_domain );

		return $value;
	}

	/**
	 * Formats login failure message for display.
	 *
	 * @param WP_Error $error    Error returned by wp_signon().
	 * @param string   $username Attempted username.
	 * @return string
	 */
	private function format_login_error_message( WP_Error $error, string $username ): string {
		if ( in_array( 'invalid_username', $error->get_error_codes(), true ) ) {
			$display = '' !== $username ? $username : __( 'Entered username', 'vk-booking-manager' );

			return sprintf(
				/* translators: %s: user name */
				__( '%s is a non-existent user.', 'vk-booking-manager' ),
				$display
			);
		}

		return $error->get_error_message();
	}
}
