<?php
/**
 * Adds VKBM user meta fields, plus the email verification status, to the WordPress
 * user profile and user list screens.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Auth\Email_Verification;
use VKBookingManager\Common\VKBM_Helper;
use WP_User;
use function __;
use function add_action;
use function add_filter;
use function current_user_can;
use function delete_user_meta;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function get_current_user_id;
use function get_option;
use function get_the_author_meta;
use function get_user_meta;
use function get_userdata;
use function sanitize_text_field;
use function sprintf;
use function update_user_meta;
use function wp_date;
use function wp_unslash;

/**
 * Adds VKBM user meta fields, plus the email verification status, to the WordPress
 * user profile and user list screens.
 */
class User_Profile_Fields {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'register_verification_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_verification_column' ), 10, 3 );
	}

	/**
	 * Render custom fields on user profile screens.
	 *
	 * @param WP_User $user User object.
	 */
	public function render_fields( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$kana  = (string) get_user_meta( $user->ID, 'vkbm_kana_name', true );
		$phone = (string) get_user_meta( $user->ID, 'phone_number', true );
		$birth = (string) get_user_meta( $user->ID, 'vkbm_birth_date', true );

		$birth_parts = $this->resolve_birth_parts( $birth );
		?>
		<h2><?php esc_html_e( 'Reservation system information', 'vk-booking-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="vkbm-user-kana"><?php esc_html_e( 'Furigana', 'vk-booking-manager' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" name="vkbm_user_kana" id="vkbm-user-kana" value="<?php echo esc_attr( $kana ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="vkbm-user-phone"><?php esc_html_e( 'telephone number', 'vk-booking-manager' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" name="vkbm_user_phone" id="vkbm-user-phone" value="<?php echo esc_attr( $phone ); ?>">
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'date of birth', 'vk-booking-manager' ); ?></th>
				<td>
					<!-- Use the same year/month/day pattern for consistency. / 年月日入力で統一 -->
					<input
						type="number"
						class="small-text"
						name="vkbm_birth_year"
						value="<?php echo esc_attr( $birth_parts['year'] ); ?>"
						inputmode="numeric"
						min="1900"
						max="2100"
						aria-label="<?php esc_attr_e( 'Date of birth (year)', 'vk-booking-manager' ); ?>"
					>
					<?php esc_html_e( 'year', 'vk-booking-manager' ); ?>
					<input
						type="number"
						class="small-text"
						name="vkbm_birth_month"
						value="<?php echo esc_attr( $birth_parts['month'] ); ?>"
						inputmode="numeric"
						min="1"
						max="12"
						aria-label="<?php esc_attr_e( 'date of birth (month)', 'vk-booking-manager' ); ?>"
					>
					<?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?>
					<input
						type="number"
						class="small-text"
						name="vkbm_birth_day"
						value="<?php echo esc_attr( $birth_parts['day'] ); ?>"
						inputmode="numeric"
						min="1"
						max="31"
						aria-label="<?php esc_attr_e( 'date of birth (day)', 'vk-booking-manager' ); ?>"
					>
					<?php esc_html_e( 'Sun', 'vk-booking-manager' ); ?>
				</td>
			</tr>
		</table>
		<?php
		$this->render_email_verification_section( $user );
	}

	/**
	 * Renders the "email verification" row (status, manual approve / revoke checkboxes).
	 *
	 * 予約顧客以外（オーナー・スタッフ・管理者）には表示しない。自分自身にも表示しない
	 * （論点2・issue #507: 自己承認・自己取り消しを防ぐ）。
	 *
	 * @param WP_User $user User object.
	 */
	private function render_email_verification_section( WP_User $user ): void {
		if ( ! Auth_Shortcodes::is_booking_customer( $user ) ) {
			return;
		}

		$is_self = get_current_user_id() === $user->ID;
		$status  = Email_Verification::get_status( $user->ID );
		?>
		<h2><?php esc_html_e( 'Email verification', 'vk-booking-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Status', 'vk-booking-manager' ); ?></th>
				<td>
					<p><?php echo esc_html( $this->get_status_label( $status ) ); ?></p>
					<?php if ( Email_Verification::STATUS_MANUAL === $status ) : ?>
						<?php $approval = Email_Verification::get_manual_approval_info( $user->ID ); ?>
						<?php if ( $approval['at'] > 0 ) : ?>
							<p class="description"><?php echo esc_html( $this->format_manual_approval_description( $approval ) ); ?></p>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! $is_self && Email_Verification::STATUS_UNVERIFIED === $status ) : ?>
						<p>
							<label>
								<input type="checkbox" name="vkbm_email_verify_manual" value="1">
								<?php esc_html_e( 'Mark email as verified (manual approval)', 'vk-booking-manager' ); ?>
							</label>
						</p>
						<p class="description">
							<?php esc_html_e( 'Checking this and clicking "Update User" lets this customer log in without clicking the link in the verification email. Because delivery to their email address has not been confirmed, booking confirmation emails may not reach them. If a booking comes in, contact them another way, such as by phone.', 'vk-booking-manager' ); ?>
						</p>
					<?php elseif ( ! $is_self && Email_Verification::STATUS_MANUAL === $status ) : ?>
						<p>
							<label>
								<input type="checkbox" name="vkbm_email_verify_revoke_manual" value="1">
								<?php esc_html_e( 'Revoke manual approval (return to unverified)', 'vk-booking-manager' ); ?>
							</label>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Returns a human-readable label for the given verification status.
	 *
	 * @param string $status Raw status value ('', '0', '1', or 'manual').
	 * @return string
	 */
	private function get_status_label( string $status ): string {
		if ( Email_Verification::STATUS_MANUAL === $status ) {
			return __( 'Manually approved (email unconfirmed)', 'vk-booking-manager' );
		}

		if ( Email_Verification::STATUS_UNVERIFIED === $status ) {
			return __( 'Unverified', 'vk-booking-manager' );
		}

		// '1' またはメタ未保存（機能導入前の登録ユーザー・管理者作成ユーザー）は認証済み扱い。
		return __( 'Verified', 'vk-booking-manager' );
	}

	/**
	 * Formats the "approved at ... by ..." description under the manual approval status.
	 *
	 * @param array{at: int, by: int} $approval Manual approval metadata.
	 * @return string
	 */
	private function format_manual_approval_description( array $approval ): string {
		$datetime = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $approval['at'] );
		$approver = $approval['by'] > 0 ? (string) get_the_author_meta( 'display_name', $approval['by'] ) : '';

		if ( '' !== $approver ) {
			/* translators: 1: approval datetime, 2: approver display name. */
			return sprintf( __( 'Manually approved at %1$s by %2$s.', 'vk-booking-manager' ), $datetime, $approver );
		}

		/* translators: %s: approval datetime. */
		return sprintf( __( 'Manually approved at %s.', 'vk-booking-manager' ), $datetime );
	}

	/**
	 * Adds the "Email verification" column to the users list table.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function register_verification_column( array $columns ): array {
		$columns['vkbm_email_verification'] = __( 'Email verification', 'vk-booking-manager' );
		return $columns;
	}

	/**
	 * Renders the "Email verification" column content on the users list table.
	 *
	 * @param string $value       Current column value (empty by default).
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render_verification_column( string $value, string $column_name, int $user_id ): string {
		if ( 'vkbm_email_verification' !== $column_name ) {
			return $value;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! Auth_Shortcodes::is_booking_customer( $user ) ) {
			return '';
		}

		$badge = Email_Verification::get_badge( $user_id );
		if ( null === $badge ) {
			return '';
		}

		return sprintf(
			'<span class="vkbm-email-verification-badge %1$s">%2$s</span>',
			esc_attr( $badge['css_class'] ),
			esc_html( $badge['label'] )
		);
	}

	/**
	 * Save user profile fields.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WordPress core hooks (edit_user_profile_update/personal_options_update).
		$raw = wp_unslash( $_POST );

		$kana      = sanitize_text_field( $raw['vkbm_user_kana'] ?? '' );
		$phone_raw = sanitize_text_field( $raw['vkbm_user_phone'] ?? '' );
		$phone     = VKBM_Helper::normalize_phone_number( $phone_raw );
		$year      = sanitize_text_field( $raw['vkbm_birth_year'] ?? '' );
		$month     = sanitize_text_field( $raw['vkbm_birth_month'] ?? '' );
		$day       = sanitize_text_field( $raw['vkbm_birth_day'] ?? '' );
		$birth     = $this->build_birth_date( $year, $month, $day );

		$this->update_user_meta_value( $user_id, 'vkbm_kana_name', $kana );
		$this->update_user_meta_value( $user_id, 'phone_number', $phone );
		$this->update_user_meta_value( $user_id, 'vkbm_birth_date', $birth );

		$this->save_email_verification_fields( $user_id, $raw );
	}

	/**
	 * Saves the manual approve / revoke checkboxes for email verification.
	 *
	 * 自分自身には適用しない（論点2・issue #507）。予約顧客以外も対象外にする。
	 *
	 * @param int                  $user_id Target user ID.
	 * @param array<string, mixed> $raw     Unslashed $_POST data.
	 */
	private function save_email_verification_fields( int $user_id, array $raw ): void {
		if ( get_current_user_id() === $user_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$target_user = get_userdata( $user_id );
		if ( ! $target_user instanceof WP_User || ! Auth_Shortcodes::is_booking_customer( $target_user ) ) {
			return;
		}

		$status = Email_Verification::get_status( $user_id );

		if ( Email_Verification::STATUS_UNVERIFIED === $status && ! empty( $raw['vkbm_email_verify_manual'] ) ) {
			Email_Verification::mark_manual( $user_id, get_current_user_id() );
			return;
		}

		if ( Email_Verification::STATUS_MANUAL === $status && ! empty( $raw['vkbm_email_verify_revoke_manual'] ) ) {
			Email_Verification::revoke_manual( $user_id );
		}
	}

	/**
	 * Resolve birth date parts from a stored date.
	 *
	 * @param string $birth_value Stored birth date (YYYY-MM-DD).
	 * @return array{year: string, month: string, day: string}
	 */
	private function resolve_birth_parts( string $birth_value ): array {
		$birth_year  = '';
		$birth_month = '';
		$birth_day   = '';

		if ( '' !== $birth_value ) {
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
	 * Update or delete user meta based on the value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key Meta key.
	 * @param string $value Meta value.
	 * @return void
	 */
	private function update_user_meta_value( int $user_id, string $key, string $value ): void {
		// Save when non-empty, otherwise remove. / 空なら削除.
		if ( '' === $value ) {
			delete_user_meta( $user_id, $key );
			return;
		}

		update_user_meta( $user_id, $key, $value );
	}
}
