<?php
/**
 * Email log admin page.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\ProviderSettings\Settings_Repository;
use function __;
use function current_user_can;
use function check_admin_referer;
use function wp_unslash;
use function sanitize_text_field;
use function admin_url;
use function add_query_arg;
use function wp_date;
use function get_option;

/**
 * Handles the email log admin page.
 */
class Email_Log_Page {
	private const MENU_SLUG    = 'vkbm-email-log';
	private const NONCE_ACTION = 'vkbm_email_log_clear';
	private const NONCE_NAME   = 'vkbm_email_log_nonce';

	/**
	 * Parent admin menu slug.
	 *
	 * @var string
	 */
	private $parent_slug;

	/**
	 * Capability required to access the page.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Email log repository.
	 *
	 * @var Email_Log_Repository
	 */
	private $log_repository;

	/**
	 * Constructor.
	 *
	 * @param string               $parent_slug    Parent admin menu slug.
	 * @param string               $capability     Capability required to access the page.
	 * @param Email_Log_Repository $log_repository Email log repository.
	 */
	public function __construct( string $parent_slug = 'vkbm-provider-settings', string $capability = Capabilities::MANAGE_PROVIDER_SETTINGS, ?Email_Log_Repository $log_repository = null ) {
		$this->parent_slug    = $parent_slug;
		$this->capability     = $capability;
		$this->log_repository = $log_repository ?? new Email_Log_Repository();
	}

	/**
	 * Register WordPress hooks for the log page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'block_if_disabled' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_prune_logs' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_clear_logs' ) );
	}

	/**
	 * Register the email log menu and page.
	 */
	public function register_menu(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_submenu_page(
			$this->parent_slug,
			__( 'Email Log', 'vk-booking-manager' ),
			__( 'Email Log', 'vk-booking-manager' ),
			$this->capability,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Block direct access to the page when disabled.
	 */
	public function block_if_disabled(): void {
		if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
			return;
		}

		if ( $this->is_enabled() ) {
			return;
		}

		wp_die( esc_html__( 'Email log is currently disabled.', 'vk-booking-manager' ) );
	}

	/**
	 * Handle clear logs request.
	 */
	public function handle_clear_logs(): void {
		if ( ! isset( $_GET['action'] ) || 'clear' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification just below.
			return;
		}

		if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification just below.
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$this->log_repository->clear_logs();

		$redirect_url = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'cleared' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render the email log page.
	 */
	public function render_page(): void {
		if ( ! $this->is_enabled() ) {
			wp_die( esc_html__( 'Email log is currently disabled.', 'vk-booking-manager' ) );
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		$logs    = $this->log_repository->get_logs();
		$cleared = isset( $_GET['cleared'] ) && '1' === $_GET['cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Email Log', 'vk-booking-manager' ); ?></h1>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Logs cleared successfully.', 'vk-booking-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $logs ) ) : ?>
				<p><?php echo esc_html__( 'No email logs found.', 'vk-booking-manager' ); ?></p>
			<?php else : ?>
				<div class="vkbm-email-log-actions" style="margin-bottom: 20px;">
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'clear' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ), self::NONCE_ACTION, self::NONCE_NAME ) ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'vk-booking-manager' ) ); ?>');">
						<?php echo esc_html__( 'Clear All Logs', 'vk-booking-manager' ); ?>
					</a>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 180px;"><?php echo esc_html__( 'Date/Time', 'vk-booking-manager' ); ?></th>
							<th style="width: 250px;"><?php echo esc_html__( 'Recipient', 'vk-booking-manager' ); ?></th>
							<th><?php echo esc_html__( 'Subject', 'vk-booking-manager' ); ?></th>
							<th style="width: 100px;"><?php echo esc_html__( 'Status', 'vk-booking-manager' ); ?></th>
							<th><?php echo esc_html__( 'Error', 'vk-booking-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td>
									<?php
									$timestamp = isset( $log['timestamp'] ) ? (int) $log['timestamp'] : 0;
									if ( $timestamp > 0 ) {
										$timezone = wp_timezone();
										echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, $timezone ) );
									} else {
										echo esc_html( $log['timestamp'] ?? '' );
									}
									?>
								</td>
								<td><?php echo esc_html( $log['email'] ?? '' ); ?></td>
								<td><?php echo esc_html( $log['subject'] ?? '' ); ?></td>
								<td>
									<?php if ( ! empty( $log['success'] ) ) : ?>
										<span style="color: green;"><?php echo esc_html__( 'Success', 'vk-booking-manager' ); ?></span>
									<?php else : ?>
										<span style="color: red;"><?php echo esc_html__( 'Failed', 'vk-booking-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $log['error'] ) ) : ?>
										<code style="font-size: 11px; color: #d63638;"><?php echo esc_html( $log['error'] ); ?></code>
									<?php else : ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether email logging is enabled in provider settings.
	 */
	private function is_enabled(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();

		return ! empty( $settings['email_log_enabled'] );
	}

	/**
	 * Prune email logs based on configured retention period.
	 */
	public function maybe_prune_logs(): void {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		$this->log_repository->maybe_prune_logs( $this->get_retention_days() );
	}

	/**
	 * Get configured retention days for email logs.
	 */
	private function get_retention_days(): int {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();

		$days = isset( $settings['email_log_retention_days'] ) ? (int) $settings['email_log_retention_days'] : 1;
		return max( 1, $days );
	}
}
