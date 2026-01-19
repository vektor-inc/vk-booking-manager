<?php

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use VKBookingManager\Common\VKBM_Helper;
use WP_User;
use function add_action;
use function current_user_can;
use function delete_user_meta;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function get_user_meta;
use function sanitize_text_field;
use function update_user_meta;
use function wp_unslash;

/**
 * Adds VKBM user meta fields to the WordPress user profile screens.
 */
class User_Profile_Fields {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render_fields' ] );
		add_action( 'edit_user_profile', [ $this, 'render_fields' ] );
		add_action( 'personal_options_update', [ $this, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_fields' ] );
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

		$raw = wp_unslash( $_POST );

		$kana  = sanitize_text_field( $raw['vkbm_user_kana'] ?? '' );
		$phone_raw = sanitize_text_field( $raw['vkbm_user_phone'] ?? '' );
		$phone = VKBM_Helper::normalize_phone_number( $phone_raw );
		$year  = sanitize_text_field( $raw['vkbm_birth_year'] ?? '' );
		$month = sanitize_text_field( $raw['vkbm_birth_month'] ?? '' );
		$day   = sanitize_text_field( $raw['vkbm_birth_day'] ?? '' );
		$birth = $this->build_birth_date( $year, $month, $day );

		$this->update_user_meta_value( $user_id, 'vkbm_kana_name', $kana );
		$this->update_user_meta_value( $user_id, 'phone_number', $phone );
		$this->update_user_meta_value( $user_id, 'vkbm_birth_date', $birth );
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

		return [
			'year'  => $birth_year,
			'month' => $birth_month,
			'day'   => $birth_day,
		];
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
		// Normalize numeric parts before composing. / 数値に正規化してから日付を構成。
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
		// Save when non-empty, otherwise remove. / 空なら削除。
		if ( '' === $value ) {
			delete_user_meta( $user_id, $key );
			return;
		}

		update_user_meta( $user_id, $key, $value );
	}
}
