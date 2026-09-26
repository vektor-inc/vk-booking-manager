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
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Mail_Error_Capture;
use VKBookingManager\Common\Rate_Limit_Trait;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Error;
use WP_Post;
use WP_User;
use function apply_filters;

/**
 * Front-end login & registration shortcodes.
 */
class Auth_Shortcodes {
	use Rate_Limit_Trait;

	private const EMAIL_TOKEN_TTL            = DAY_IN_SECONDS;
	private const RATE_LIMIT_LOGIN_MAX       = 10;
	private const RATE_LIMIT_LOGIN_WINDOW    = 600;
	private const RATE_LIMIT_REGISTER_MAX    = 5;
	private const RATE_LIMIT_REGISTER_WINDOW = 1800;
	// #510: メールログの通知種類。一覧画面で「会員登録の確認」ラベルとして表示する。
	private const EMAIL_TYPE_REGISTRATION_CONFIRMATION = 'registration_confirmation';

	// issue #507: 認証メール再送許可（使い捨て）の有効期間と、同一利用者への再送の間隔制限。
	private const RESEND_GRANT_TTL        = 10 * MINUTE_IN_SECONDS;
	private const RESEND_COOLDOWN_SECONDS = 60;

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
	 * Resend grant token issued during the current request (unhashed), if any.
	 *
	 * 同一リクエスト内（ショートコードページの POST → 直後の描画）で、クッキーの
	 * 反映を待たずに再送ボタンを出すために使う（$_COOKIE は同一リクエスト内では
	 * 更新されないため）。
	 *
	 * @var string
	 */
	private $resend_grant_token = '';

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
	public function register(): void {
		add_action( 'init', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_email_verification' ) );
		add_action( 'login_form_register', array( $this, 'redirect_wp_register_to_vkbm' ) );
		add_action( 'login_form_login', array( $this, 'redirect_wp_login_to_vkbm' ) );
		// issue #512: フォールバック案内リンク（wp-login.php + vkbm_native_login=1）で
		// 開いた場合、GET→POST を通じて回避クエリを維持するための隠しフィールド。
		add_action( 'login_form', array( $this, 'render_native_login_bypass_field' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_branding' ) );
		add_action( 'admin_init', array( $this, 'redirect_free_user_from_admin' ) );
		// issue #507: パスワード再設定の完了を、メール認証の代わりとして扱う。
		add_action( 'after_password_reset', array( $this, 'handle_after_password_reset' ), 10, 2 );
		add_shortcode( 'vkbm_login_form', array( $this, 'render_login_form' ) );
		add_shortcode( 'vkbm_register_form', array( $this, 'render_registration_form' ) );
	}

	/**
	 * WordPress 本体のパスワード再設定完了時に、メール認証未確認のユーザーを認証済み扱いにする。
	 *
	 * パスワード再設定のリンクは登録メールアドレスに届くため、再設定を完了できた時点で
	 * 「そのアドレスにメールが届く」ことは確認できている（issue #507 やること3）。
	 *
	 * @param WP_User $user     再設定を完了したユーザー。
	 * @param string  $new_pass 新しいパスワード（未使用）。
	 */
	public function handle_after_password_reset( WP_User $user, string $new_pass ): void {
		unset( $new_pass );

		$status = Email_Verification::get_status( (int) $user->ID );

		if ( Email_Verification::STATUS_UNVERIFIED === $status || Email_Verification::STATUS_MANUAL === $status ) {
			Email_Verification::mark_verified( (int) $user->ID );
		}
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

		$reservation_url = $this->get_reservation_page_url();

		if ( '' === $reservation_url ) {
			return;
		}

		// Only redirect if the reservation page contains the reservation block.
		if ( ! self::reservation_page_has_block( $reservation_url ) ) {
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

		// issue #512: 予約ブロックの JS 未実行時フォールバック案内（wp-login.php + この
		// クエリ）から開いた場合は、堂々巡りを避けるため予約ページへ転送しない。GET
		// (リンク経由) と POST (フォーム送信、render_native_login_bypass_field() が
		// 出す隠しフィールド経由) の両方を拾うため $_REQUEST を見る。
		if ( ! empty( $_REQUEST['vkbm_native_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bypass flag only, no state change.
			return;
		}

		$settings = $this->settings_service->get_settings();
		if ( empty( $settings['membership_redirect_wp_login'] ) ) {
			return;
		}

		$reservation_url = $this->get_reservation_page_url();

		if ( '' === $reservation_url ) {
			return;
		}

		// Only redirect if the reservation page contains the reservation block.
		if ( ! self::reservation_page_has_block( $reservation_url ) ) {
			return;
		}

		$redirect_url = add_query_arg( 'vkbm_auth', 'login', $reservation_url );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Outputs a hidden field on the native WordPress login form so the
	 * `vkbm_native_login` bypass flag survives the GET (link click) → POST
	 * (credentials submit) transition.
	 *
	 * issue #512: コアの wp-login.php ログインフォームは action 属性にクエリ文字列を
	 * 含まないため、フォールバック案内リンクの `?vkbm_native_login=1` はフォーム送信時に
	 * 失われる。`login_form` フック（コアが `</form>` 直前に発火）で隠しフィールドとして
	 * 埋め込み、`redirect_wp_login_to_vkbm()` が POST 側でも同じフラグを拾えるようにする。
	 *
	 * @return void
	 */
	public function render_native_login_bypass_field(): void {
		if ( empty( $_REQUEST['vkbm_native_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bypass flag only, no state change.
			return;
		}

		echo '<input type="hidden" name="vkbm_native_login" value="1">';
	}

	/**
	 * 予約顧客が /wp-admin にアクセスした際、予約ページURLにリダイレクトします。
	 *
	 * 予約ページURLが設定されている場合、管理権限を持たない予約顧客（subscriberロール相当）を
	 * 予約ページへ転送します。
	 * AJAXリクエストや未ログインユーザーの場合は何もしません。
	 *
	 * @return void
	 */
	public function redirect_free_user_from_admin(): void {
		// AJAXリクエストはリダイレクト対象外.
		if ( wp_doing_ajax() ) {
			return;
		}

		// ログインしていない場合は対象外.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$current_user = wp_get_current_user();

		// 予約顧客（管理権限・VKBM権限を持たないユーザー）でない場合は対象外.
		if ( ! self::is_booking_customer( $current_user ) ) {
			return;
		}

		// 予約ページURLが設定されていない場合は対象外.
		$reservation_url = $this->get_reservation_page_url();

		if ( '' === $reservation_url ) {
			return;
		}

		// 予約ページURLへリダイレクト.
		wp_safe_redirect( $reservation_url );
		exit;
	}

	/**
	 * 指定したユーザーが予約顧客（管理権限・VKBM権限を持たないユーザー）かどうかを判定します。
	 *
	 * 以下のすべての条件を満たす場合に予約顧客と判断します。
	 * - edit_posts 権限がない（管理者・エディター・著者・投稿者ではない）
	 * - vkbm_view_reservations 権限がない（VKBM オーナー・スタッフではない）
	 * - vkbm_manage_own_reservations 権限がない（VKBM スタッフではない）
	 *
	 * @param WP_User $user 判定対象のユーザー.
	 * @return bool 予約顧客の場合 true.
	 */
	public static function is_booking_customer( WP_User $user ): bool {
		// 投稿編集権限があれば管理者・エディター相当のため予約顧客ではない.
		if ( $user->has_cap( 'edit_posts' ) ) {
			return false;
		}

		// VKBM の予約閲覧権限があればオーナー・スタッフのため予約顧客ではない.
		if ( $user->has_cap( Capabilities::VIEW_RESERVATIONS ) ) {
			return false;
		}

		// VKBM の自分の予約管理権限があればスタッフのため予約顧客ではない.
		if ( $user->has_cap( Capabilities::MANAGE_OWN_RESERVATIONS ) ) {
			return false;
		}

		return true;
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

		if ( isset( $_POST['vkbm_resend_verification_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
			$this->process_resend_verification_request();
		}
	}

	/**
	 * Handles verification callback from email link.
	 */
	public function handle_email_verification(): void {
		if ( empty( $_GET['vkbm_verify_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email verification link uses token-based authentication.
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['vkbm_verify_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email verification link uses token-based authentication.
		if ( '' === $token ) {
			return;
		}

		// Compare against the SHA-256 hash stored in user meta to avoid keeping the raw token in the database.
		// 生トークンを DB に残さないため、ユーザーメタに保存された SHA-256 ハッシュと突き合わせる。
		$token_hash = hash( 'sha256', $token );

		$user = get_users(
			array(
				'meta_key'   => 'vkbm_email_verify_token_hash',
				'meta_value' => $token_hash,
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

		Email_Verification::mark_verified( $user->ID );
		$this->set_verification_notice( __( 'Email verification has been completed. Please log in.', 'vk-booking-manager' ) );

		$redirect_url = remove_query_arg( 'vkbm_verify_email', $this->get_current_url() );
		$redirect_url = add_query_arg( 'vkbm_auth', 'login', $redirect_url );
		$this->redirect_and_exit( $redirect_url );
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
			// issue #512: `vkbm_login_error` Cookie の代わりに、呼び出し側（REST
			// コントローラー等）が同一リクエスト内で判明したログイン失敗コードを
			// 直接渡すための att。ホワイトリスト外のコードは get_login_error_message()
			// が空文字を返すため、何も表示されない。
			'error_code'              => '',
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

		// issue #512 レビュー対応（安藤さん指摘）: $this->login_errors（共有プロパティ）
		// を直接書き換えると、同一インスタンスで render_login_form() を複数回呼ぶ場面
		// （PHPUnit のケース網羅・REST 経由の複数リクエストを模した呼び出し等）で、
		// 一度 error_code から追加したエラーが後続の呼び出しにも残り続けてしまう
		// （error_code が空/無効でも前回分がエラー欄に出続ける状態漏れ）。
		// $this->login_errors は他経路（process_login_request() 等）が設定した
		// 「本当にこのインスタンスが処理した結果」を保持する必要があるため、
		// error_code att はローカル変数へコピーしてから追加し、共有プロパティ自体は
		// 変更しない。
		$login_errors_to_render = $this->login_errors;
		$error_code             = sanitize_key( (string) ( $atts['error_code'] ?? '' ) );
		if ( '' !== $error_code ) {
			$error_message = $this->get_login_error_message( $error_code );

			if ( '' !== $error_message ) {
				$login_errors_to_render = $login_errors_to_render instanceof WP_Error
					? clone $login_errors_to_render
					: new WP_Error();
				$login_errors_to_render->add( $error_code, $error_message );
			}
		}

		// issue #507: 未認証で弾いたときだけ発行される使い捨ての再送許可。
		// クッキー経由（予約ブロックの別リクエスト）と同一リクエスト内（ショートコード
		// ページの POST 直後）の両方をカバーする。
		$resend_notice    = $this->consume_notice_cookie( 'vkbm_resend_notice' );
		$show_resend_form = $this->has_resend_grant();

		ob_start();
		?>
		<div class="vkbm-auth-card vkbm-auth-card--login">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2 class="vkbm-auth-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( ! empty( $atts['description'] ) ) : ?>
				<p class="vkbm-auth-card__description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<?php $this->render_error_list( $login_errors_to_render ); ?>
			<?php $verification_message = $this->consume_verification_notice(); ?>
			<?php if ( $verification_message ) : ?>
				<div class="vkbm-alert vkbm-alert__success">
					<?php echo esc_html( $verification_message ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $resend_notice || $show_resend_form ) : ?>
				<div class="vkbm-alert vkbm-alert__warning" role="status">
					<?php if ( 'success' === $resend_notice ) : ?>
						<p><?php esc_html_e( 'The verification email has been resent. Click the link in the email within 24 hours to complete verification.', 'vk-booking-manager' ); ?></p>
					<?php elseif ( 'rate_limited' === $resend_notice ) : ?>
						<p><?php esc_html_e( 'You have reached the resend limit for the verification email. Please wait a while and try again. Please also check whether the email already sent has arrived, including your spam folder.', 'vk-booking-manager' ); ?></p>
					<?php elseif ( 'send_failed' === $resend_notice ) : ?>
						<p><?php esc_html_e( 'Failed to resend the verification email. Please try again later.', 'vk-booking-manager' ); ?></p>
					<?php elseif ( 'expired' === $resend_notice ) : ?>
						<p><?php esc_html_e( 'The resend request has expired. Please log in again.', 'vk-booking-manager' ); ?></p>
					<?php else : ?>
						<h3><?php esc_html_e( 'Email verification has not been completed yet', 'vk-booking-manager' ); ?></h3>
						<p><?php esc_html_e( 'Click the link in the verification email sent to your registered email address to be able to log in.', 'vk-booking-manager' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $this->get_verification_contact_lines() as $verification_contact_line ) : ?>
						<p><?php echo esc_html( $verification_contact_line ); ?></p>
					<?php endforeach; ?>
					<?php if ( $show_resend_form ) : ?>
						<form class="vkbm-auth-form__resend" method="post" action="<?php echo esc_url( $form_action ); ?>">
							<?php echo wp_nonce_field( 'vkbm_resend_verification_form', 'vkbm_resend_verification_nonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() outputs escaped HTML. ?>
							<input type="hidden" name="vkbm_resend_verification_form" value="1">
							<button type="submit" class="vkbm-button vkbm-button__md vkbm-button__secondary"><?php esc_html_e( 'Resend verification email', 'vk-booking-manager' ); ?></button>
						</form>
					<?php endif; ?>
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
										echo wp_kses(
											sprintf(
												/* translators: %s: privacy policy link */
												__( 'I agree with %s', 'vk-booking-manager' ),
												$privacy_link
											), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped by wp_kses.
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
			<?php
			wp_add_inline_script( 'vkbm-auth-forms', $this->get_register_username_validation_script(), 'after' );
			?>
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

		// Restore errors from cookie if available (after redirect).
		// リダイレクト後にCookieからエラーを復元する。
		$cookie_errors = $this->consume_profile_errors_cookie();
		if ( null !== $cookie_errors ) {
			$this->profile_errors = $cookie_errors;
		}

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
			// Save errors to cookie and redirect so the SPA can display them.
			// エラーを Cookie に保存してリダイレクトし、SPA 側でエラーを表示できるようにする。
			$this->set_profile_errors_cookie( $errors );
			$this->profile_errors = $errors;

			$redirect_to = isset( $raw['redirect_to'] ) ? $this->normalize_redirect( $raw['redirect_to'] ) : $this->get_current_url();
			$redirect_to = add_query_arg( 'vkbm_auth', 'profile', $redirect_to );

			$this->redirect_and_exit( $redirect_to );
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
			// Save wp_update_user errors to cookie and redirect.
			// wp_update_user のエラーを Cookie に保存してリダイレクトする。
			$this->set_profile_errors_cookie( $update );
			$this->profile_errors = $update;

			$redirect_to = isset( $raw['redirect_to'] ) ? $this->normalize_redirect( $raw['redirect_to'] ) : $this->get_current_url();
			$redirect_to = add_query_arg( 'vkbm_auth', 'profile', $redirect_to );

			$this->redirect_and_exit( $redirect_to );
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

		$this->redirect_and_exit( $redirect_to );
	}

	/**
	 * Handles login submission.
	 */
	private function process_login_request(): void {
		$this->login_errors = new WP_Error();

		// issue #512: 表示文言は get_login_error_messages() の対応表を唯一の情報源とする
		// （REST 経由でコードから文言へ変換する get_login_error_message() とも共有）。
		$messages = $this->get_login_error_messages();

		$nonce = isset( $_POST['vkbm_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_login_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vkbm_login_form' ) ) {
			$this->login_errors->add( 'invalid_nonce', $messages['invalid_nonce'] );
			return;
		}

		$login_limit = $this->get_rate_limit_login_max();
		if ( $this->is_rate_limit_enabled() && ! $this->consume_rate_limit_token( 'login', $login_limit, self::RATE_LIMIT_LOGIN_WINDOW ) ) {
			$this->login_errors->add( 'rate_limited', $messages['rate_limited'] );
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
			$this->login_errors->add( 'empty_username', $messages['empty_username'] );
		}

		if ( '' === $password ) {
			$this->login_errors->add( 'empty_password', $messages['empty_password'] );
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
			// 確認2（issue #512 承認済み仕様）: wp_signon() が返すエラーの種類を問わず
			// 統一文言（auth_failed）に丸める。他プラグインが wp_signon() に独自の
			// エラーを追加していても、その文言をそのまま出すとユーザー列挙対策
			// （#194）が崩れるため。
			$this->login_errors->add( 'auth_failed', $messages['auth_failed'] );
			return;
		}

		// issue #507: 判定・状態変更ロジックを Email_Verification に一本化する。
		// 論点1: BM設定でメール認証が不要なら、'0'（未認証）のままの利用者もログインを許可する。
		$verification_required = $this->requires_email_verification();

		if ( ! Email_Verification::is_login_allowed( (int) $user->ID, $verification_required ) ) {
			wp_clear_auth_cookie();

			$status = Email_Verification::get_status( (int) $user->ID );

			if ( Email_Verification::STATUS_UNVERIFIED === $status ) {
				// パスワードが正しく、未認証で弾いたときにだけ使い捨ての再送許可を発行する
				// （#194＝ログイン失敗メッセージから登録済みユーザーを推測できた問題、と同じ観点）。
				// 案内・再送ボタンはログインフォーム描画時（render_login_form）に出す。
				$this->issue_resend_grant( (int) $user->ID );
				return;
			}

			// 想定外の保存値に対する保険（通常はここへ来ない）。
			$this->login_errors->add( 'unverified_email', $messages['unverified_email'] );
			return;
		}

		$redirect_to_raw = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$redirect_to     = '' !== $redirect_to_raw ? $this->normalize_redirect( $redirect_to_raw ) : $this->get_current_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.

		$this->redirect_and_exit( $redirect_to );
	}

	/**
	 * Issues a single-use resend grant for the given user.
	 *
	 * パスワードが正しく、未認証（'0'）で弾いたときにだけ呼ばれる。10分間有効な
	 * transient と、ブラウザ側からは読めない HttpOnly クッキーで運ぶ。パスワードを
	 * 間違えた人には発行しないため、「そのユーザーが存在して未認証である」ことは
	 * 分からない（#194 と同じ観点）。
	 *
	 * @param int $user_id 未認証のまま弾かれたユーザー ID。
	 */
	private function issue_resend_grant( int $user_id ): void {
		// 安藤さんレビュー指摘（issue #507 PR）: 直前の許可が残っていれば、再発行前に
		// 無効化する。再送のたびに新しい許可を出し直す（下記 finish_resend_request()
		// 参照）ようになったため、古い許可を放置すると有効な許可が積み上がってしまう。
		$this->invalidate_current_resend_grant();

		$token = $this->generate_email_token();

		set_transient( 'vkbm_resend_grant_' . hash( 'sha256', $token ), $user_id, self::RESEND_GRANT_TTL );

		// 同一リクエスト内での render_login_form() 呼び出しにも即座に反映させる
		// （$_COOKIE は同一リクエスト内では更新されないため）。
		$this->resend_grant_token = $token;
		$this->set_resend_grant_cookie( $token );
	}

	/**
	 * Deletes the transient behind whatever resend grant is currently tracked
	 * (in-memory token for this request, or the cookie from a previous request), if any.
	 */
	private function invalidate_current_resend_grant(): void {
		if ( '' !== $this->resend_grant_token ) {
			delete_transient( 'vkbm_resend_grant_' . hash( 'sha256', $this->resend_grant_token ) );
		}

		$cookie_token = $this->peek_resend_grant_cookie();
		if ( '' !== $cookie_token ) {
			delete_transient( 'vkbm_resend_grant_' . hash( 'sha256', $cookie_token ) );
		}
	}

	/**
	 * Sets the HttpOnly cookie carrying the raw resend grant token.
	 *
	 * @param string $token Raw (unhashed) grant token.
	 */
	private function set_resend_grant_cookie( string $token ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			'vkbm_resend_grant',
			$token,
			time() + self::RESEND_GRANT_TTL,
			'/',
			defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			$this->is_request_secure(),
			true
		);
	}

	/**
	 * Clears the resend grant cookie.
	 */
	private function clear_resend_grant_cookie(): void {
		// 同一リクエスト内で peek_resend_grant_cookie() を再度呼んでも古い値を
		// 拾わないよう、$_COOKIE 自体も更新する（setcookie() は次リクエストにしか効かない）。
		unset( $_COOKIE['vkbm_resend_grant'] );

		if ( headers_sent() ) {
			return;
		}

		setcookie( 'vkbm_resend_grant', '', time() - 3600, '/', defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '' );
	}

	/**
	 * Reads the raw resend grant token from the cookie without clearing it.
	 *
	 * @return string
	 */
	private function peek_resend_grant_cookie(): string {
		if ( empty( $_COOKIE['vkbm_resend_grant'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		return sanitize_text_field( wp_unslash( $_COOKIE['vkbm_resend_grant'] ) );
	}

	/**
	 * Determines whether a resend grant is currently available (without consuming it).
	 *
	 * ログインフォーム描画時に再送ボタンを出すかどうかの判定に使う。
	 *
	 * @return bool
	 */
	public function has_resend_grant(): bool {
		if ( '' !== $this->resend_grant_token ) {
			return true;
		}

		$token = $this->peek_resend_grant_cookie();
		if ( '' === $token ) {
			return false;
		}

		return false !== get_transient( 'vkbm_resend_grant_' . hash( 'sha256', $token ) );
	}

	/**
	 * Builds the "contact the shop if the email does not arrive" notice lines, appending
	 * the shop's phone number / email address from BM settings when configured (issue #507
	 * 論点3). Each line is rendered as its own paragraph by the caller (植草さんレビュー
	 * 指摘: 電話・メールを1行に詰め込まず別行にする).
	 *
	 * @return array<int, string>
	 */
	private function get_verification_contact_lines(): array {
		$lines = array(
			__( 'If the email does not arrive, please check your spam folder and then contact the shop.', 'vk-booking-manager' ),
		);

		$settings = $this->settings_service->get_settings();
		$phone    = isset( $settings['provider_phone'] ) ? trim( (string) $settings['provider_phone'] ) : '';
		$email    = isset( $settings['provider_email'] ) ? trim( (string) $settings['provider_email'] ) : '';

		if ( '' !== $phone ) {
			/* translators: %s: shop phone number. */
			$lines[] = sprintf( __( 'Phone: %s', 'vk-booking-manager' ), $phone );
		}
		if ( '' !== $email && is_email( $email ) ) {
			/* translators: %s: shop email address. */
			$lines[] = sprintf( __( 'Email: %s', 'vk-booking-manager' ), $email );
		}

		return $lines;
	}

	/**
	 * Handles a "resend verification email" submission.
	 *
	 * 使い捨ての再送許可を消費して、新しい認証トークンを発行・送信する。成否に
	 * 関わらず、この1回の POST で許可を無効化する（#507）。
	 */
	private function process_resend_verification_request(): void {
		$nonce = isset( $_POST['vkbm_resend_verification_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_resend_verification_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vkbm_resend_verification_form' ) ) {
			$this->login_errors = new WP_Error();
			$this->login_errors->add( 'invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'vk-booking-manager' ) );
			return;
		}

		$token = $this->peek_resend_grant_cookie();
		$this->clear_resend_grant_cookie();
		$this->resend_grant_token = '';

		if ( '' === $token ) {
			// 安藤さんレビュー指摘（issue #507 PR）: サイレントに何も表示しないと
			// 利用者が詰まる。存在確認につながらないよう、原因を問わず共通の文言で
			// 案内する（許可を再発行しないため $user_id は渡さない）。
			$this->finish_resend_request( 'expired' );
			return;
		}

		$transient_key = 'vkbm_resend_grant_' . hash( 'sha256', $token );
		$user_id       = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( false === $user_id || (int) $user_id <= 0 ) {
			$this->finish_resend_request( 'expired' );
			return;
		}

		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || Email_Verification::STATUS_UNVERIFIED !== Email_Verification::get_status( $user_id ) ) {
			// 既に別の操作（手動承認・認証リンクの利用等）で状態が変わっている場合も、
			// 状態を推測されないよう同じ 'expired' 文言にする。
			$this->finish_resend_request( 'expired' );
			return;
		}

		// レート制限1（安藤さんレビュー指摘）: 再送専用の IP 単位レート制限を使う。
		// 会員登録用の 'register' 枠と共有すると、再送の連打が新規登録のレート制限を
		// 巻き添えにしてしまうため、action を分ける（上限値・期間は登録用の設定値を流用）。
		$resend_ip_limit = $this->get_rate_limit_register_max();
		if ( $this->is_rate_limit_enabled() && ! $this->consume_rate_limit_token( 'resend_verification', $resend_ip_limit, self::RATE_LIMIT_REGISTER_WINDOW ) ) {
			$this->finish_resend_request( 'rate_limited', $user_id );
			return;
		}

		// レート制限2（安藤さんレビュー指摘）: 利用者単位の1日上限（24時間で5回まで）。
		// 他人のメールアドレスで登録して再送を連打し、大量にメールを送りつける対策。
		if ( Email_Verification::has_reached_daily_resend_limit( $user_id ) ) {
			$this->finish_resend_request( 'rate_limited', $user_id );
			return;
		}

		// レート制限3（論点4）: 同一利用者への再送は60秒に1回まで。
		$last_resend = (int) get_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, true );
		if ( $last_resend > 0 && ( time() - $last_resend ) < self::RESEND_COOLDOWN_SECONDS ) {
			$this->finish_resend_request( 'rate_limited', $user_id );
			return;
		}

		$new_token = $this->generate_email_token();
		// 新しいトークンで上書きすることで、以前発行した認証リンクを無効化する。
		update_user_meta( $user_id, Email_Verification::META_TOKEN_HASH, hash( 'sha256', $new_token ) );
		update_user_meta( $user_id, Email_Verification::META_TOKEN_EXPIRES, time() + self::EMAIL_TOKEN_TTL );

		if ( ! $this->send_verification_email( $user->user_email, $this->get_current_url(), $new_token ) ) {
			$this->finish_resend_request( 'send_failed', $user_id );
			return;
		}

		update_user_meta( $user_id, Email_Verification::META_RESEND_LAST_SENT, time() );
		Email_Verification::record_resend( $user_id );
		$this->finish_resend_request( 'success', $user_id );
	}

	/**
	 * Stores the resend outcome in a one-shot cookie and redirects back to the login page.
	 *
	 * 植草さんレビュー指摘（issue #507 PR）: 成功・上限到達・送信失敗のいずれの結果でも
	 * 文言が「もう一度お試しください」と再挑戦を促すため、手段（再送ボタン）を残す
	 * 必要がある。$user_id を渡した場合（＝有効な未認証ユーザーに対する処理だった
	 * 場合）は新しい使い捨て許可を発行し直す。連打は呼び出し元の各レート制限で防ぐ。
	 * 許可・状態が無効だった（'expired'）場合は $user_id を渡さず、発行し直さない。
	 *
	 * @param string $result  'success' | 'rate_limited' | 'send_failed' | 'expired'.
	 * @param int    $user_id 許可を再発行する対象ユーザー ID。'expired' では 0 のまま。
	 */
	private function finish_resend_request( string $result, int $user_id = 0 ): void {
		if ( $user_id > 0 ) {
			$this->issue_resend_grant( $user_id );
		}

		$this->set_notice_cookie( 'vkbm_resend_notice', $result, '/' );
		$redirect_to = add_query_arg( 'vkbm_auth', 'login', $this->get_current_url() );
		$this->redirect_and_exit( $redirect_to );
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

		$original_username = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
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

		$redirect_to_raw       = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$redirect_to           = '' !== $redirect_to_raw ? $this->normalize_redirect( $redirect_to_raw ) : $this->get_current_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$requires_verification = $this->requires_email_verification();

		if ( $requires_verification ) {
			Email_Verification::mark_unverified( $user_id );

			$token   = $this->generate_email_token();
			$expires = time() + self::EMAIL_TOKEN_TTL;
			// Store only the SHA-256 hash of the token so the database leak does not expose usable verification links.
			// DB から流出しても認証リンクとして使われないよう、SHA-256 ハッシュのみを保存する。
			update_user_meta( $user_id, 'vkbm_email_verify_token_hash', hash( 'sha256', $token ) );
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
			Email_Verification::mark_verified( $user_id );

			$this->set_verification_notice(
				__( 'Thank you for registering. Please log in to continue booking.', 'vk-booking-manager' )
			);

			$redirect_to = add_query_arg( 'vkbm_auth', 'login', $redirect_to );
		}

		$this->redirect_and_exit( $redirect_to );
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

		// Sanitize the decoded array data. / デコードされた配列データをサニタイズ。
		$data = map_deep( $data, 'sanitize_text_field' );

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
		<?php // issue #512: tabindex="-1" は自然なタブ順には加えず、JS（app.js / booking-confirm-app.js）が REST 応答後にこの要素へ一度だけプログラム的にフォーカスできるようにするため。 ?>
		<div class="vkbm-alert vkbm-alert__danger" role="alert" tabindex="-1">
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

		$auth_js      = VKBM_PLUGIN_DIR_PATH . 'assets/js/auth-forms.js';
		$auth_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : '1.0.0';
		if ( file_exists( $auth_js ) ) {
			$auth_version = (string) filemtime( $auth_js );
		}

		wp_enqueue_script(
			'vkbm-auth-forms',
			VKBM_PLUGIN_DIR_URL . 'assets/js/auth-forms.js',
			array(),
			$auth_version,
			true
		);
	}

	/**
	 * Returns inline script for register form username validation (enqueued via wp_add_inline_script).
	 *
	 * @return string
	 */
	private function get_register_username_validation_script(): string {
		$message = __( 'Please enter the username using only half-width alphanumeric characters, _, @, ., and -.', 'vk-booking-manager' );

		return sprintf(
			'(function () {
				var form = document.getElementById("vkbm-provider-register-form");
				var usernameInput = document.getElementById("vkbm-register-username");
				var feedback = document.getElementById("vkbm-register-username-feedback");
				if (!form || !usernameInput || !feedback) {
					return;
				}
				var submitButton = form.querySelector("button[type=\\"submit\\"]");
				var pattern = /^[A-Za-z0-9_@.\\-]+$/;
				var message = %s;
				var evaluate = function () {
					var value = usernameInput.value.trim();
					if (value === "") {
						feedback.textContent = "";
						feedback.hidden = true;
						if (submitButton) {
							submitButton.disabled = false;
						}
						return true;
					}
					if (!pattern.test(value)) {
						feedback.textContent = message;
						feedback.hidden = false;
						usernameInput.setCustomValidity(message);
						if (submitButton) {
							submitButton.disabled = true;
						}
						return false;
					}
					feedback.textContent = "";
					feedback.hidden = true;
					usernameInput.setCustomValidity("");
					if (submitButton) {
						submitButton.disabled = false;
					}
					return true;
				};
				usernameInput.addEventListener("input", evaluate);
				form.addEventListener("submit", function (event) {
					if (!evaluate()) {
						event.preventDefault();
						usernameInput.focus();
					}
				});
			})();',
			wp_json_encode( $message )
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

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building current URL from query string.
			if ( ! empty( $_GET ) ) {
				$query_args = array();
				foreach ( wp_unslash( $_GET ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Non-destructive public search request. Values sanitized via map_deep in loop.
					$sanitized_key                = sanitize_key( (string) $key );
					$query_args[ $sanitized_key ] = map_deep( $value, 'sanitize_text_field' );
				}
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
	 * Returns the normalized reservation page URL from provider settings, or '' if unset.
	 *
	 * 予約ページURL設定値を正規化して返す（`vkbm_normalize_reservation_page_url()` が
	 * 定義されていれば適用）。redirect_wp_register_to_vkbm() / redirect_wp_login_to_vkbm() /
	 * redirect_free_user_from_admin() で重複していた同じ取得処理を1か所にまとめたもの。
	 * issue #512 のフォールバック案内リンク（Reservation_Block::get_native_login_fallback_url()）
	 * からも参照する。
	 *
	 * @return string
	 */
	public function get_reservation_page_url(): string {
		$settings        = $this->settings_service->get_settings();
		$reservation_url = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';

		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			$reservation_url = vkbm_normalize_reservation_page_url( $reservation_url );
		}

		return $reservation_url;
	}

	/**
	 * Check if the reservation page URL contains the reservation block.
	 *
	 * 予約ページURLに予約ブロックが含まれているかチェックします。
	 *
	 * @param string $url Reservation page URL.
	 * @return bool True if the page contains the reservation block, false otherwise.
	 */
	public static function reservation_page_has_block( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! function_exists( 'has_block' ) ) {
			return false;
		}

		return has_block( 'vk-booking-manager/reservation', $post );
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
		$subject = sprintf( __( '[ %s ] Confirm email address', 'vk-booking-manager' ), $site_name );

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

		// #510: 予約通知メールと同じ仕組みで、wp_mail() 直前・直後だけ wp_mail_failed を
		// 購読してエラー文を取得する（WP_Error のメッセージ → PHPMailer の ErrorInfo →
		// 「不明なエラー」の優先順位）。
		$result = Mail_Error_Capture::send(
			static function () use ( $email, $subject, $message, $headers ) {
				return wp_mail( $email, $subject, $message, $headers );
			}
		);

		// Save log entry.
		if ( $email_log_enabled ) {
			$log_repository = new Email_Log_Repository();
			$log_repository->add_log(
				$email,
				$subject,
				$result['sent'] ? Email_Log_Repository::STATUS_SENT : Email_Log_Repository::STATUS_FAILED,
				$result['sent'] ? '' : $result['error'],
				self::EMAIL_TYPE_REGISTRATION_CONFIRMATION
			);
		}

		return $result['sent'];
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
	 * Perform a safe redirect and exit.
	 * This method is protected so it can be overridden in tests.
	 *
	 * 安全なリダイレクトを実行して終了する。
	 * テストでオーバーライドできるように protected にする。
	 *
	 * @param string $url Redirect URL.
	 * @return void
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store profile validation errors in a cookie for display after redirect.
	 * プロフィールバリデーションエラーをリダイレクト後に表示するため Cookie に保存する。
	 *
	 * @param WP_Error $errors Validation errors.
	 */
	private function set_profile_errors_cookie( WP_Error $errors ): void {
		$messages = $errors->get_error_messages();
		if ( empty( $messages ) ) {
			return;
		}

		// Store as JSON array of error messages.
		// エラーメッセージを JSON 配列として保存する。
		$json = wp_json_encode( $messages );
		if ( false === $json ) {
			return;
		}

		$this->set_notice_cookie( 'vkbm_profile_errors', $json );
	}

	/**
	 * Consume profile errors stored in a cookie and return as WP_Error.
	 * Cookie に保存されたプロフィールエラーを取得し WP_Error として返す。
	 *
	 * @return WP_Error|null WP_Error if errors were stored, null otherwise.
	 */
	private function consume_profile_errors_cookie(): ?WP_Error {
		if ( empty( $_COOKIE['vkbm_profile_errors'] ) ) {
			return null;
		}

		// Read and decode the cookie value.
		// Cookie 値を読み取りデコードする。
		$raw = isset( $_COOKIE['vkbm_profile_errors'] )
			? rawurldecode( (string) wp_unslash( $_COOKIE['vkbm_profile_errors'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-message below after JSON decode.
			: '';

		// Clear the cookie immediately.
		// Cookie を即座にクリアする。
		if ( ! headers_sent() ) {
			$primary_path  = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
			$cookie_domain = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
			setcookie( 'vkbm_profile_errors', '', time() - 3600, $primary_path, $cookie_domain );
			if ( '/' !== $primary_path ) {
				setcookie( 'vkbm_profile_errors', '', time() - 3600, '/', $cookie_domain );
			}
		}

		if ( '' === $raw ) {
			return null;
		}

		$messages = json_decode( $raw, true );
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return null;
		}

		$errors = new WP_Error();
		foreach ( $messages as $index => $message ) {
			// Sanitize each message before adding to WP_Error.
			// 各メッセージをサニタイズしてから WP_Error に追加する。
			$sanitized = sanitize_text_field( (string) $message );
			if ( '' !== $sanitized ) {
				$errors->add( 'profile_error_' . $index, $sanitized );
			}
		}

		return $errors->has_errors() ? $errors : null;
	}


	/**
	 * Generic helper to store a notice cookie.
	 *
	 * @param string      $name    Cookie key.
	 * @param string      $message Notice.
	 * @param string|null $path    Cookie path. Defaults to the reservation page path.
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

		// Decode first, then sanitize. This prevents destruction of encoded chars (e.g. %) while ensuring sanitization happens on the line.
		// 先にデコードしてからサニタイズする。これにより、エンコードされた文字（%など）の破壊を防ぎつつ、行内でのサニタイズを確実にする。
		$value = isset( $_COOKIE[ $name ] )
			? sanitize_text_field( rawurldecode( (string) wp_unslash( $_COOKIE[ $name ] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Correctly sanitized AFTER decode to prevent XSS. WPCS scanner does not track through rawurldecode.
			: '';

		if ( headers_sent() ) {
			return $value;
		}

		$primary_path  = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$cookie_domain = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
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
	 * @param array{id:string, name:string, label:string, value:string, type?:string, autocomplete?:string, required?:bool, args?:array<string, mixed>} $args Field settings.
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
			if ( $is_register ) {
				$message = __( 'Please enter the same password twice.', 'vk-booking-manager' );
			} else {
				$message = __( 'New passwords do not match.', 'vk-booking-manager' );
			}
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
	 * Returns the fixed whitelist of login error codes and their display messages.
	 *
	 * issue #512: `vkbm_login_error` Cookie を廃止し、ログイン失敗コードを同一リクエスト
	 * 内で HTML（render_block フィルタ）や REST パラメータとして受け渡す方式に替えた際の、
	 * コード→文言の唯一の対応表。process_login_request() のエラー登録、
	 * get_login_error_message()（REST 経由の文言解決）の両方がここを参照する。
	 * 一覧に無いコードは表示させないため、あえて連想配列以外の形は持たない。
	 *
	 * @return array<string,string> Error code => display message.
	 */
	private function get_login_error_messages(): array {
		return array(
			// #194: ユーザー列挙対策の統一文言（wp_signon() のエラー種別を問わず使う）。
			'auth_failed'      => __( 'Username or password is incorrect.', 'vk-booking-manager' ),
			'invalid_nonce'    => __( 'Security check failed. Please reload the page and try again.', 'vk-booking-manager' ),
			'rate_limited'     => __( 'Too many attempts in a short period of time. Please try again later.', 'vk-booking-manager' ),
			'unverified_email' => __( 'Email verification has not been completed. Please click the link in the registered email to confirm.', 'vk-booking-manager' ),
			'empty_username'   => __( 'Please enter your username (or email address).', 'vk-booking-manager' ),
			'empty_password'   => __( 'Please enter your password.', 'vk-booking-manager' ),
		);
	}

	/**
	 * Returns the display message for a known login error code, or '' if the code
	 * is not in the fixed whitelist.
	 *
	 * ホワイトリストに無いコードは空文字を返す。呼び出し側（REST コントローラー・
	 * render_block フィルタ）はこれを「何も表示しない」の合図として扱う（任意の
	 * 文字列を画面に出させないため）。
	 *
	 * @param string $code Sanitized error code.
	 * @return string
	 */
	public function get_login_error_message( string $code ): string {
		$messages = $this->get_login_error_messages();

		return $messages[ $code ] ?? '';
	}

	/**
	 * Returns the fixed whitelist of login error codes (without messages).
	 *
	 * issue #512 レビュー対応（安藤さん指摘）: REST `/vkbm/v1/auth-form` の `error`
	 * 引数を `register_rest_route()` の `args` で self-describing に宣言する際、
	 * その説明文（description）に一覧を載せるために使う（`enum` は使わない。理由は
	 * `Auth_Form_Controller::register_routes()` のコメント参照）。ホワイトリストの
	 * 実体は get_login_error_messages() の1か所のみで、ここはそのキー一覧を返すだけの
	 * 薄いラッパー。
	 *
	 * @return array<int,string>
	 */
	public function get_login_error_codes(): array {
		return array_keys( $this->get_login_error_messages() );
	}

	/**
	 * Returns the login error code determined during this request's
	 * process_login_request() call, or '' if login has not failed (or has not
	 * been attempted) on this request/instance.
	 *
	 * issue #512: 予約ブロックの render_block フィルタ（Reservation_Block）が、Cookie を
	 * 使わず同一リクエスト内で判明したログイン失敗を HTML に埋め込むために使う。
	 *
	 * @return string
	 */
	public function get_current_login_error_code(): string {
		if ( ! $this->login_errors instanceof WP_Error || ! $this->login_errors->has_errors() ) {
			return '';
		}

		$codes = $this->login_errors->get_error_codes();

		return (string) ( $codes[0] ?? '' );
	}
}
