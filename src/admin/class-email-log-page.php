<?php
/**
 * Email log admin page.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
use function absint;
use function esc_url;
use function get_edit_post_link;
use function sprintf;

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

			<?php // #510: このログが何を示す/示さないかを常に案内する（見出し直下）。 ?>
			<p class="description">
				<?php echo esc_html__( 'This log only shows whether WordPress was able to process sending the email.', 'vk-booking-manager' ); ?>
				<?php echo esc_html__( 'It does not record whether the email was actually delivered to the recipient.', 'vk-booking-manager' ); ?>
				<?php echo esc_html__( 'If the status shows "Sent" but the email was not received, check the recipient\'s spam folder and the server\'s email settings (such as DKIM).', 'vk-booking-manager' ); ?>
			</p>

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
							<?php
							// #510: type・attempt 等を持つのは今回の変更以降に記録されたログのみ。
							// 変更前に保存された古いログ（status キーが無い）は従来どおりの表示のままにする。
							$is_new_format_log = array_key_exists( 'status', $log );
							$status_meta       = $this->resolve_status_meta( $log );
							?>
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
								<td>
									<?php echo esc_html( $log['subject'] ?? '' ); ?>
									<?php if ( $is_new_format_log ) : ?>
										<?php echo $this->render_type_and_booking_line( $log ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the helper. ?>
									<?php endif; ?>
								</td>
								<td>
									<span style="color: <?php echo esc_attr( $status_meta['color'] ); ?>;"><?php echo esc_html( $status_meta['label'] ); ?></span>
									<?php if ( $is_new_format_log ) : ?>
										<?php echo $this->render_retry_line( $log ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the helper. ?>
									<?php endif; ?>
								</td>
								<?php
								// レビュー対応（#510 植草・安藤）: 再送案内文は "\n" で連結して保存しているため、
								// nl2br() で改行を <br> として表示する（<code> は空白を折りたたむため、そのままでは
								// 1行に繋がって読めなくなる）。未送信（skipped）は設定不足であり故障ではないため、
								// 失敗（failed）と同じ赤 (#d63638) ではなく中立色 (#50575e) で表示する
								// （白背景・縞模様行 #f6f7f7 のどちらでもコントラスト比 4.5:1 以上）。
								$error_text_color = ( Email_Log_Repository::STATUS_SKIPPED === $status_meta['status'] ) ? '#50575e' : '#d63638';
								?>
								<td>
									<?php if ( ! empty( $log['error'] ) ) : ?>
										<code style="font-size: 11px; color: <?php echo esc_attr( $error_text_color ); ?>;"><?php echo nl2br( esc_html( (string) $log['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nl2br() only adds <br> after esc_html() already escaped the content. ?></code>
									<?php else : ?>
										<span style="color: #999;">—</span>
									<?php endif; ?>
									<?php if ( ! empty( $log['action_url'] ) ) : ?>
										<?php // #510: 植草（UX）レビュー対応。案内リンクは <code> の外に通常の文字として置き、リンク単体で行き先が分かる文言にする。 ?>
										<br><a href="<?php echo esc_url( (string) $log['action_url'] ); ?>"><?php echo esc_html( (string) ( $log['action_label'] ?? '' ) ); ?></a>
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
	 * ログの状態（送信済み／失敗／未送信）に応じたラベルと文字色を返す。
	 *
	 * 新形式（status キーあり）は status の値をそのまま使う。旧形式（status キー無し）は
	 * success（真偽値）から sent/failed のいずれかに読み替える（skipped は旧形式には存在しない）。
	 *
	 * @param array<string, mixed> $log ログ1件分のデータ。
	 * @return array{status:string,label:string,color:string}
	 */
	private function resolve_status_meta( array $log ): array {
		if ( array_key_exists( 'status', $log ) ) {
			$status = (string) $log['status'];
		} else {
			$status = ! empty( $log['success'] ) ? Email_Log_Repository::STATUS_SENT : Email_Log_Repository::STATUS_FAILED;
		}

		switch ( $status ) {
			case Email_Log_Repository::STATUS_SKIPPED:
				return array(
					'status' => $status,
					// #510: アクセシビリティ基準（コントラスト比 4.5:1 以上）を満たす色。
					'label'  => __( 'Not sent', 'vk-booking-manager' ),
					'color'  => '#8a6d00',
				);

			case Email_Log_Repository::STATUS_FAILED:
				return array(
					'status' => $status,
					'label'  => __( 'Failed', 'vk-booking-manager' ),
					'color'  => '#b32d2e',
				);

			case Email_Log_Repository::STATUS_SENT:
			default:
				return array(
					'status' => Email_Log_Repository::STATUS_SENT,
					'label'  => __( 'Sent', 'vk-booking-manager' ),
					'color'  => '#008000',
				);
		}
	}

	/**
	 * 件名の下に出す「通知の種類」と「予約 #123（編集画面リンク）」の行を組み立てる。
	 *
	 * @param array<string, mixed> $log ログ1件分のデータ。
	 * @return string 出力用にエスケープ済みの HTML。
	 */
	private function render_type_and_booking_line( array $log ): string {
		$type       = (string) ( $log['type'] ?? '' );
		$booking_id = isset( $log['booking_id'] ) ? absint( $log['booking_id'] ) : 0;

		$type_label = $this->get_type_label( $type );

		$parts = array();
		if ( '' !== $type_label ) {
			$parts[] = '<span class="vkbm-email-log__type">' . esc_html( $type_label ) . '</span>';
		}

		if ( $booking_id > 0 ) {
			$edit_url = (string) get_edit_post_link( $booking_id, 'raw' );
			/* translators: %d: Booking post ID. */
			$booking_label = sprintf( __( 'Reservation #%d', 'vk-booking-manager' ), $booking_id );
			if ( '' !== $edit_url ) {
				$parts[] = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $booking_label ) . '</a>';
			} else {
				$parts[] = esc_html( $booking_label );
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		return '<br><small class="vkbm-email-log__meta">' . implode( ' / ', $parts ) . '</small>';
	}

	/**
	 * ステータスの下に出す「再送（N回目）」の行を組み立てる。1回目（attempt が 1 以下）や
	 * 再送の概念が無い通知（max_attempts が 0）は何も出力しない。
	 *
	 * @param array<string, mixed> $log ログ1件分のデータ。
	 * @return string 出力用にエスケープ済みの HTML。
	 */
	private function render_retry_line( array $log ): string {
		$attempt      = isset( $log['attempt'] ) ? absint( $log['attempt'] ) : 0;
		$max_attempts = isset( $log['max_attempts'] ) ? absint( $log['max_attempts'] ) : 0;

		if ( $attempt < 2 || $max_attempts <= 0 ) {
			return '';
		}

		if ( $attempt >= $max_attempts ) {
			/* translators: %d: Attempt number (e.g. 3rd attempt, and final). */
			$label = sprintf( __( 'Resend (attempt %d, final)', 'vk-booking-manager' ), $attempt );
		} else {
			/* translators: %d: Attempt number (e.g. 2nd attempt). */
			$label = sprintf( __( 'Resend (attempt %d)', 'vk-booking-manager' ), $attempt );
		}

		return '<br><small class="vkbm-email-log__retry">' . esc_html( $label ) . '</small>';
	}

	/**
	 * 通知タイプの内部キーを、一覧表示用のラベルへ変換する。
	 *
	 * 対応表は Booking_Notification_Service の通知タイプ定数、および
	 * Auth_Shortcodes::EMAIL_TYPE_REGISTRATION_CONFIRMATION と一致させること。
	 *
	 * @param string $type 通知タイプ。
	 * @return string ラベル（未知のタイプ・空文字は空文字を返す）。
	 */
	private function get_type_label( string $type ): string {
		$labels = array(
			'pending_customer'          => __( 'Pending reservation (to customer)', 'vk-booking-manager' ),
			'pending_provider'          => __( 'Pending reservation (to provider)', 'vk-booking-manager' ),
			'confirmed_customer'        => __( 'Reservation confirmed (to customer)', 'vk-booking-manager' ),
			'confirmed_provider'        => __( 'Reservation confirmed (to provider)', 'vk-booking-manager' ),
			'cancelled_customer'        => __( 'Reservation cancelled (to customer)', 'vk-booking-manager' ),
			'cancelled_provider'        => __( 'Reservation cancelled (to provider)', 'vk-booking-manager' ),
			'reminder_customer'         => __( 'Reservation reminder (to customer)', 'vk-booking-manager' ),
			'registration_confirmation' => __( 'Email address confirmation', 'vk-booking-manager' ),
		);

		return $labels[ $type ] ?? '';
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
