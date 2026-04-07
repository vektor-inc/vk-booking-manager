<?php

/**
 * Handles the Service Menu editing UI and meta persistence.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;

/**
 * Handles the Service Menu editing UI and meta persistence.
 */
class Service_Menu_Editor {
	private const NONCE_ACTION                = 'vkbm_service_menu_meta';
	private const NONCE_NAME                  = '_vkbm_service_menu_nonce';
	private const META_USE_DETAIL_PAGE        = '_vkbm_use_detail_page';
	private const META_RESERVATION_DAY_TYPE   = '_vkbm_reservation_day_type';
	private const META_OTHER_CONDITIONS       = '_vkbm_other_conditions';
	private const META_DISABLE_NOMINATION_FEE = '_vkbm_disable_nomination_fee';
	private const META_FIXED_START_TIMES      = '_vkbm_fixed_start_times';
	private const META_MAX_CAPACITY           = '_vkbm_max_capacity';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 1 );
		add_action( 'do_meta_boxes', array( $this, 'promote_vkbm_meta_box' ), 10, 3 );
		add_action( 'save_post_' . Service_Menu_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin assets for service menu editor.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();

		if ( ! $screen || Service_Menu_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$base_url = plugin_dir_url( VKBM_PLUGIN_FILE );
		wp_enqueue_script(
			'vkbm-service-menu-editor',
			$base_url . 'assets/js/service-menu-editor.js',
			array( 'jquery' ),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-service-menu-editor',
			'vkbmServiceMenuEditor',
			array(
				'i18n' => array(
					// Translators: Button label to remove a fixed start time row. / 固定開始時刻の行を削除するボタンのラベル.
					'delete' => __( 'Delete', 'vk-booking-manager' ),
				),
			)
		);
	}

	/**
	 * Add Service Menu meta boxes.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_meta_boxes( string $post_type ): void {
		if ( Service_Menu_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		add_meta_box(
			'vkbm_service_menu_vkbm',
			__( 'VK Booking Manager', 'vk-booking-manager' ),
			array( $this, 'render_vkbm_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		if ( Staff_Editor::is_enabled() ) {
			add_meta_box(
				'vkbm_service_menu_staff',
				__( 'Staff collaboration', 'vk-booking-manager' ),
				array( $this, 'render_staff_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}

		add_meta_box(
			'vkbm_service_menu_publish',
			__( 'Public settings', 'vk-booking-manager' ),
			array( $this, 'render_publish_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Promote VK Booking Manager meta box to the top of the editor screen.
	 *
	 * Note: Users can still reorder meta boxes via screen options. This tries to keep the default order.
	 *
	 * @param string $post_type Current post type.
	 * @param string $context   Meta box context.
	 * @param mixed  $object    Screen object (post, dashboard object, etc.).
	 */
	public function promote_vkbm_meta_box( string $post_type, string $context, $object ): void {
		if ( Service_Menu_Post_Type::POST_TYPE !== $post_type || 'normal' !== $context ) {
			return;
		}

		if ( ! ( $object instanceof WP_Post ) ) {
			return;
		}

		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes[ $post_type ]['normal'] ) ) {
			return;
		}

		$meta_box_id = 'vkbm_service_menu_vkbm';
		$meta_box    = null;

		foreach ( array( 'high', 'core', 'default', 'low' ) as $priority ) {
			if ( isset( $wp_meta_boxes[ $post_type ]['normal'][ $priority ][ $meta_box_id ] ) ) {
				$meta_box = $wp_meta_boxes[ $post_type ]['normal'][ $priority ][ $meta_box_id ];
				unset( $wp_meta_boxes[ $post_type ]['normal'][ $priority ][ $meta_box_id ] );
				break;
			}
		}

		if ( null === $meta_box ) {
			return;
		}

		if ( empty( $wp_meta_boxes[ $post_type ]['normal']['high'] ) ) {
			$wp_meta_boxes[ $post_type ]['normal']['high'] = array();
		}

		$wp_meta_boxes[ $post_type ]['normal']['high'] = array( $meta_box_id => $meta_box ) + $wp_meta_boxes[ $post_type ]['normal']['high'];
	}

	/**
	 * Render VK Booking Manager meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_vkbm_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$this->render_basic_meta_box( $post );
		$this->render_conditions_meta_box( $post );
		$this->render_internal_memo_field( $post );
	}

		/**
		 * Render internal memo field.
		 *
		 * @param WP_Post $post Current post object.
		 */
	private function render_internal_memo_field( WP_Post $post ): void {
		$internal_memo = get_post_meta( $post->ID, '_vkbm_internal_memo', true );
		?>
			<div class="vkbm-service-menu-field">
				<label for="vkbm_service_menu_internal_memo"><?php esc_html_e( 'Internal memo (customer hidden)', 'vk-booking-manager' ); ?></label><br />
				<textarea id="vkbm_service_menu_internal_memo" name="vkbm_service_menu[internal_memo]" class="widefat" rows="4"><?php echo esc_textarea( $internal_memo ); ?></textarea>
			</div>
			<?php
	}

		/**
		 * Render the basic information meta box.
		 *
		 * @param WP_Post $post Current post object.
		 */
	public function render_basic_meta_box( WP_Post $post ): void {
		$catch_copy             = get_post_meta( $post->ID, '_vkbm_catch_copy', true );
		$base_price             = get_post_meta( $post->ID, '_vkbm_base_price', true );
		$disable_nomination_fee = (string) get_post_meta( $post->ID, self::META_DISABLE_NOMINATION_FEE, true );
		$tax_label              = VKBM_Helper::get_tax_included_label();
		?>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_catch_copy"><?php esc_html_e( 'Catchphrase', 'vk-booking-manager' ); ?></label>
			<input type="text" id="vkbm_service_menu_catch_copy" name="vkbm_service_menu[catch_copy]" class="widefat" value="<?php echo esc_attr( $catch_copy ); ?>" />
		</div>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_base_price">
				<?php
				esc_html_e( 'Basic price', 'vk-booking-manager' );
				if ( '' !== $tax_label ) {
					echo ' ' . esc_html( $tax_label );
				}
				?>
			</label>
			<input type="number" id="vkbm_service_menu_base_price" name="vkbm_service_menu[base_price]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $base_price ); ?>" />
		</div>
		<?php if ( Staff_Editor::is_nomination_enabled() ) : ?>
		<div class="vkbm-service-menu-field">
			<strong><?php echo esc_html( vkbm_get_nomination_fee_label() ); ?></strong><br />
			<label>
				<input type="checkbox" name="vkbm_service_menu[disable_nomination_fee]" value="1" <?php checked( '1', $disable_nomination_fee ); ?> />
				<?php esc_html_e( 'Disable for this service menu', 'vk-booking-manager' ); ?>
			</label>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the providing conditions meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_conditions_meta_box( WP_Post $post ): void {
		$duration_minutes     = get_post_meta( $post->ID, '_vkbm_duration_minutes', true );
		$buffer_after_minutes = get_post_meta( $post->ID, '_vkbm_buffer_after_minutes', true );
		$reservation_deadline = get_post_meta( $post->ID, '_vkbm_reservation_deadline_hours', true );
		$reservation_day_type = (string) get_post_meta( $post->ID, self::META_RESERVATION_DAY_TYPE, true );
		$other_conditions     = get_post_meta( $post->ID, self::META_OTHER_CONDITIONS, true );
		$use_detail_page      = get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );
		?>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_duration_minutes"><?php esc_html_e( 'Time required (minutes)', 'vk-booking-manager' ); ?></label>
			<input type="number" id="vkbm_service_menu_duration_minutes" name="vkbm_service_menu[duration_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $duration_minutes ); ?>" />
		</div>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_buffer_after"><?php esc_html_e( 'Post-service buffer (min)', 'vk-booking-manager' ); ?></label>
			<input type="number" id="vkbm_service_menu_buffer_after" name="vkbm_service_menu[buffer_after_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $buffer_after_minutes ); ?>" />
			<p class="description"><?php esc_html_e( 'During the service time plus the buffer time, new reservations will not be accepted.', 'vk-booking-manager' ); ?><br />
			<?php esc_html_e( 'If it is left blank, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?></p>
		</div>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_reservation_day_type"><?php esc_html_e( 'Reservation date', 'vk-booking-manager' ); ?></label>
			<select id="vkbm_service_menu_reservation_day_type" name="vkbm_service_menu[reservation_day_type]">
				<option value="" <?php selected( '', $reservation_day_type ); ?>><?php esc_html_e( 'Not specified', 'vk-booking-manager' ); ?></option>
				<option value="weekend" <?php selected( 'weekend', $reservation_day_type ); ?>><?php esc_html_e( 'Saturdays and Sundays only', 'vk-booking-manager' ); ?></option>
				<option value="weekday" <?php selected( 'weekday', $reservation_day_type ); ?>><?php esc_html_e( 'Weekdays only', 'vk-booking-manager' ); ?></option>
			</select>
		</div>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_other_conditions"><?php esc_html_e( 'Other conditions', 'vk-booking-manager' ); ?></label>
			<textarea id="vkbm_service_menu_other_conditions" name="vkbm_service_menu[other_conditions]" class="widefat" rows="4"><?php echo esc_textarea( (string) $other_conditions ); ?></textarea>
		</div>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_reservation_deadline"><?php esc_html_e( 'Reservation deadline', 'vk-booking-manager' ); ?></label>
			<input type="number" id="vkbm_service_menu_reservation_deadline" name="vkbm_service_menu[reservation_deadline_hours]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $reservation_deadline ); ?>" /> <?php esc_html_e( 'hours ago', 'vk-booking-manager' ); ?>
			<p class="description"><?php esc_html_e( 'If not filled in, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php
		$max_advance_days = get_post_meta( $post->ID, '_vkbm_max_advance_booking_days', true );
		?>
		<div class="vkbm-service-menu-field">
			<label for="vkbm_service_menu_max_advance_booking_days"><?php esc_html_e( 'Max advance booking period', 'vk-booking-manager' ); ?></label>
			<input type="number" id="vkbm_service_menu_max_advance_booking_days" name="vkbm_service_menu[max_advance_booking_days]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $max_advance_days ); ?>" /> <?php esc_html_e( 'days', 'vk-booking-manager' ); ?>
			<p class="description"><?php esc_html_e( 'The maximum number of days in advance that reservations can be made. Set to 0 for no limit.', 'vk-booking-manager' ); ?></p>
			<p class="description"><?php esc_html_e( 'If not filled in, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php
		// 最大予約人数フィールド（Pro版のみ表示）
		// Display max capacity field only in Pro edition.
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $is_pro_edition ) :
			$max_capacity = get_post_meta( $post->ID, self::META_MAX_CAPACITY, true );
			?>
			<div class="vkbm-service-menu-field">
				<label for="vkbm_service_menu_max_capacity"><?php esc_html_e( 'Maximum bookings per time slot', 'vk-booking-manager' ); ?></label>
				<input type="number" id="vkbm_service_menu_max_capacity" name="vkbm_service_menu[max_capacity]" class="small-text" min="1" step="1" value="<?php echo esc_attr( $max_capacity ); ?>" />
				<p class="description"><?php esc_html_e( 'Maximum number of bookings that can be accepted per time slot when staff auto-assignment is used.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'When a specific staff member is selected, only one booking per slot is allowed.', 'vk-booking-manager' ); ?> <?php esc_html_e( 'Default is 1.', 'vk-booking-manager' ); ?></p>
			</div>
		<?php endif; ?>
		<div class="vkbm-service-menu-field">
			<label>
				<input type="checkbox" name="vkbm_service_menu[use_detail_page]" value="1" <?php checked( '1', $use_detail_page ); ?> />
				<?php esc_html_e( 'Use the details page', 'vk-booking-manager' ); ?>
			</label>
		</div>
		<?php $this->render_fixed_start_times_field( $post ); ?>
		<?php
	}

	/**
	 * Render the fixed start times field.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_fixed_start_times_field( WP_Post $post ): void {
		$fixed_start_times = get_post_meta( $post->ID, self::META_FIXED_START_TIMES, true );
		$fixed_start_times = is_array( $fixed_start_times ) ? $fixed_start_times : array();
		?>
		<div class="vkbm-service-menu-field">
			<strong><?php esc_html_e( 'Fixed start times', 'vk-booking-manager' ); ?></strong>
			<p class="description"><?php esc_html_e( 'If set, only the specified times are available for booking. Leave empty to use the default slot step.', 'vk-booking-manager' ); ?></p>
			<div id="vkbm-fixed-start-times-list">
				<?php foreach ( $fixed_start_times as $time ) : ?>
					<div class="vkbm-fixed-start-time-row">
						<select name="vkbm_service_menu[fixed_start_times][]" class="vkbm-fixed-start-hour">
							<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>" <?php selected( sprintf( '%02d', $h ), substr( (string) $time, 0, 2 ) ); ?>>
									<?php echo esc_html( sprintf( '%02d', $h ) ); ?>
								</option>
							<?php endfor; ?>
						</select>
						<span>:</span>
						<select name="vkbm_service_menu[fixed_start_minutes][]" class="vkbm-fixed-start-minute">
							<?php foreach ( array( '00', '10', '20', '30', '40', '50' ) as $min ) : ?>
								<option value="<?php echo esc_attr( $min ); ?>" <?php selected( $min, substr( (string) $time, 3, 2 ) ); ?>>
									<?php echo esc_html( $min ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-fixed-start-time-remove"><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" id="vkbm-fixed-start-time-add" class="button"><?php esc_html_e( '+ Add time', 'vk-booking-manager' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render the staff linkage meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_staff_meta_box( WP_Post $post ): void {
		$selected_staff = get_post_meta( $post->ID, '_vkbm_staff_ids', true );
		$selected_staff = is_array( $selected_staff ) ? array_map( 'intval', $selected_staff ) : array();

		$resources = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'posts_per_page' => -1,
			)
		);

		?>
		<p>
			<?php esc_html_e( 'Staff available', 'vk-booking-manager' ); ?>
		</p>
		<?php if ( empty( $resources ) ) : ?>
			<p class="description"><?php esc_html_e( 'No staff members are registered.', 'vk-booking-manager' ); ?></p>
		<?php else : ?>
			<ul style="margin: 0;">
				<?php foreach ( $resources as $resource ) : ?>
					<li style="margin: 0 0 4px;">
						<label>
							<input
								type="checkbox"
								name="vkbm_service_menu[staff_ids][]"
								value="<?php echo esc_attr( (string) $resource->ID ); ?>"
								<?php checked( in_array( (int) $resource->ID, $selected_staff, true ) ); ?>
							/>
							<?php echo esc_html( vkbm_get_resource_display_name( (int) $resource->ID ) ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the publishing settings meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_publish_meta_box( WP_Post $post ): void {
		$online_unavailable = get_post_meta( $post->ID, '_vkbm_online_unavailable', true );

		if ( '' === $online_unavailable && ! metadata_exists( 'post', $post->ID, '_vkbm_online_unavailable' ) ) {
			$legacy_online_available = get_post_meta( $post->ID, '_vkbm_online_available', true );

			if ( metadata_exists( 'post', $post->ID, '_vkbm_online_available' ) && '1' !== $legacy_online_available ) {
				$online_unavailable = '1';
			}
		}

		$is_archived = get_post_meta( $post->ID, '_vkbm_is_archived', true );
		?>
		<div class="vkbm-service-menu-field">
			<label>
				<input type="checkbox" name="vkbm_service_menu[online_unavailable]" value="1" <?php checked( '1', $online_unavailable ); ?> />
				<?php esc_html_e( 'Disable online reservations', 'vk-booking-manager' ); ?>
			</label>
		</div>
		<div class="vkbm-service-menu-field">
			<label>
				<input type="checkbox" name="vkbm_service_menu[is_archived]" value="1" <?php checked( '1', $is_archived ); ?> />
				<?php esc_html_e( 'Mark as archived', 'vk-booking-manager' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Persist Service Menu meta values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post instance.
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS, $post_id ) ) {
			return;
		}

		if ( Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['vkbm_service_menu'] ) || ! is_array( $_POST['vkbm_service_menu'] ) ) {
			return;
		}

		$data = wp_unslash( $_POST['vkbm_service_menu'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key is sanitized via sanitize_*_value() below.
		$catch_copy                     = $this->sanitize_text_value( $data, 'catch_copy' );
		$internal_memo                  = $this->sanitize_textarea_value( $data, 'internal_memo' );
		$other_conditions               = $this->sanitize_textarea_value( $data, 'other_conditions' );
		$base_price                     = $this->sanitize_numeric_value( $data, 'base_price' );
		$duration                       = $this->sanitize_numeric_value( $data, 'duration_minutes' );
		$buffer_after                   = $this->sanitize_numeric_value( $data, 'buffer_after_minutes' );
		$deadline                       = $this->sanitize_numeric_value( $data, 'reservation_deadline_hours' );
		$max_advance_days               = $this->sanitize_integer_value( $data, 'max_advance_booking_days' );
		$reservation_day_type           = $this->sanitize_reservation_day_type( $data['reservation_day_type'] ?? '' );
		$online_unavailable             = isset( $data['online_unavailable'] ) ? '1' : '';
		$archive                        = isset( $data['is_archived'] ) ? '1' : '';
		$use_detail_page                = isset( $data['use_detail_page'] ) ? '1' : '';
		$disable_nomination_fee         = isset( $data['disable_nomination_fee'] ) ? '1' : '';
		$max_capacity                   = max( 1, $this->sanitize_integer_value( $data, 'max_capacity' ) );
		$staff_ids                      = $this->sanitize_staff_ids( $data['staff_ids'] ?? array() );
		$fixed_start_times              = $this->sanitize_fixed_start_times(
			$data['fixed_start_times'] ?? array(),
			$data['fixed_start_minutes'] ?? array()
		);

			$this->update_meta_value( $post_id, '_vkbm_catch_copy', $catch_copy );
		$this->update_meta_value( $post_id, '_vkbm_internal_memo', $internal_memo );
		$this->update_meta_value( $post_id, self::META_OTHER_CONDITIONS, $other_conditions );
		$this->update_meta_value( $post_id, '_vkbm_base_price', $base_price );
		$this->update_meta_value( $post_id, '_vkbm_duration_minutes', $duration );
		$this->update_meta_value( $post_id, '_vkbm_buffer_after_minutes', $buffer_after );
		$this->update_meta_value( $post_id, '_vkbm_reservation_deadline_hours', $deadline );
		$this->update_meta_value( $post_id, '_vkbm_max_advance_booking_days', $max_advance_days );
		$this->update_meta_value( $post_id, self::META_RESERVATION_DAY_TYPE, $reservation_day_type );
		$this->update_meta_value( $post_id, '_vkbm_online_unavailable', $online_unavailable );
		delete_post_meta( $post_id, '_vkbm_online_available' );
		$this->update_meta_value( $post_id, '_vkbm_is_archived', $archive );
		$this->update_meta_value( $post_id, self::META_USE_DETAIL_PAGE, $use_detail_page );
		$this->update_meta_value( $post_id, self::META_DISABLE_NOMINATION_FEE, $disable_nomination_fee );
		// 最大予約人数はPro版のみ保存。Pro版でない場合はメタを削除してデフォルト1を維持する。
		// Save max capacity only in Pro edition. In free edition, delete the meta to maintain default of 1.
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $is_pro_edition ) {
			$this->update_meta_value( $post_id, self::META_MAX_CAPACITY, $max_capacity );
		} else {
			delete_post_meta( $post_id, self::META_MAX_CAPACITY );
		}
		if ( Staff_Editor::is_enabled() ) {
			$this->update_meta_value( $post_id, '_vkbm_staff_ids', $staff_ids, true );
		}
		if ( empty( $fixed_start_times ) ) {
			delete_post_meta( $post_id, self::META_FIXED_START_TIMES );
		} else {
			update_post_meta( $post_id, self::META_FIXED_START_TIMES, $fixed_start_times );
		}
	}

	/**
	 * Sanitize simple text values.
	 *
	 * @param array  $data  Submitted data.
	 * @param string $key   Array key.
	 * @return string
	 */
	private function sanitize_text_value( array $data, string $key ): string {
		if ( ! isset( $data[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $data[ $key ] ) );
	}

	/**
	 * Sanitize textarea values.
	 *
	 * @param array  $data  Submitted data.
	 * @param string $key   Array key.
	 * @return string
	 */
	private function sanitize_textarea_value( array $data, string $key ): string {
		if ( ! isset( $data[ $key ] ) ) {
			return '';
		}

		return sanitize_textarea_field( wp_unslash( (string) $data[ $key ] ) );
	}

	/**
	 * Sanitize numeric values. Returns empty string if non-numeric or blank.
	 *
	 * @param array  $data Submitted data.
	 * @param string $key  Array key.
	 * @return string
	 */
	private function sanitize_numeric_value( array $data, string $key ): string {
		if ( ! isset( $data[ $key ] ) ) {
			return '';
		}

		$raw = trim( (string) wp_unslash( $data[ $key ] ) );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		$value = max( 0, (int) $raw );

		return (string) $value;
	}

	/**
	 * Sanitize integer values. Returns empty string if non-integer or blank.
	 *
	 * @param array  $data Submitted data.
	 * @param string $key  Array key.
	 * @return string
	 */
	private function sanitize_integer_value( array $data, string $key ): string {
		if ( ! isset( $data[ $key ] ) ) {
			return '';
		}

		$raw = trim( (string) wp_unslash( $data[ $key ] ) );

		if ( '' === $raw || false === filter_var( $raw, FILTER_VALIDATE_INT ) ) {
			return '';
		}

		$value = max( 0, intval( $raw ) );

		return (string) $value;
	}

		/**
		 * Sanitize reservation day type.
		 *
		 * @param mixed $raw Raw value.
		 * @return string
		 */
	private function sanitize_reservation_day_type( $raw ): string {
		$value = sanitize_text_field( wp_unslash( (string) $raw ) );
		if ( '' === $value ) {
			return '';
		}

		$allowed = array( 'weekend', 'weekday' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Sanitize date value formatted as Y-m-d.
	 *
	 * @param array  $data Submitted data.
	 * @param string $key  Array key.
	 * @return string
	 */
	/**
	 * Sanitize fixed start times from parallel hour/minute arrays.
	 *
	 * @param mixed $hours   Array of hour values (HH).
	 * @param mixed $minutes Array of minute values (MM).
	 * @return array<string> Sorted unique HH:MM strings.
	 */
	private function sanitize_fixed_start_times( $hours, $minutes ): array {
		if ( ! is_array( $hours ) || ! is_array( $minutes ) ) {
			return array();
		}

		$result = array();

		foreach ( $hours as $index => $hour ) {
			$h = (int) sanitize_text_field( wp_unslash( (string) $hour ) );
			$m = (int) sanitize_text_field( wp_unslash( (string) ( $minutes[ $index ] ?? '0' ) ) );

			if ( $h < 0 || $h > 23 ) {
				continue;
			}

			$allowed_minutes = array( 0, 10, 20, 30, 40, 50 );
			if ( ! in_array( $m, $allowed_minutes, true ) ) {
				continue;
			}

			$result[] = sprintf( '%02d:%02d', $h, $m );
		}

		$result = array_unique( $result );
		sort( $result );

		return array_values( $result );
	}

	/**
	 * Sanitize staff ID array.
	 *
	 * @param mixed $ids Raw IDs.
	 * @return array<int>
	 */
	private function sanitize_staff_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_map(
			static function ( $id ) {
				return (int) $id;
			},
			$ids
		);

		$ids = array_filter(
			$ids,
			static function ( int $id ): bool {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Update or delete post meta.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $meta_key    Meta key.
	 * @param mixed  $value       Value to store.
	 * @param bool   $allow_array Whether the value can be an array.
	 */
	private function update_meta_value( int $post_id, string $meta_key, $value, bool $allow_array = false ): void {
		if ( ! $allow_array && '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		if ( $allow_array && empty( $value ) ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}
}
