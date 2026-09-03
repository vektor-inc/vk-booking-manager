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
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Common\Weekday_Rule;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;

/**
 * Handles the Service Menu editing UI and meta persistence.
 */
class Service_Menu_Editor {
	private const NONCE_ACTION              = 'vkbm_service_menu_meta';
	private const NONCE_NAME                = '_vkbm_service_menu_nonce';
	private const META_USE_DETAIL_PAGE      = '_vkbm_use_detail_page';
	private const META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	// メタキーの定義元は Reservation_Day（single source of truth）。保存側とサニタイズ側の drift を防ぐ。
	private const META_RESERVATION_CUSTOM_WEEKDAYS = Reservation_Day::META_CUSTOM_WEEKDAYS;
	private const META_RESERVATION_CUSTOM_DATES    = Reservation_Day::META_CUSTOM_DATES;
	private const NOTICE_TRANSIENT_PREFIX          = 'vkbm_reservation_day_notice_';
	private const META_OTHER_CONDITIONS            = '_vkbm_other_conditions';
	private const META_DISABLE_NOMINATION_FEE      = '_vkbm_disable_nomination_fee';
	private const META_FIXED_START_TIMES           = '_vkbm_fixed_start_times';
	private const META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const META_ALLOW_MULTIPLE_GUESTS       = '_vkbm_allow_multiple_guests';
	private const META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';
	private const META_EXCLUSIVE_USER_SELECTABLE   = '_vkbm_exclusive_user_selectable';
	private const META_EXCLUSIVE_FEE_PER_PERSON    = '_vkbm_exclusive_fee_per_person';
	private const META_EXCLUSIVE_FEE_EXEMPT_GUESTS = '_vkbm_exclusive_fee_exempt_guests';
	private const META_MAX_GUESTS_PER_BOOKING      = '_vkbm_max_guests_per_booking';
	private const META_PRICE_TIERS                 = '_vkbm_price_tiers';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 1 );
		add_action( 'do_meta_boxes', array( $this, 'promote_vkbm_meta_box' ), 10, 3 );
		add_action( 'save_post_' . Service_Menu_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_reservation_day_notice' ) );
	}

	/**
	 * 予約可能日の空選択防止で種別を「指定なし」へ戻した際の管理画面通知を表示する。
	 *
	 * 保存時に曜日指定／日付指定を選んだのに有効な行が1件も無かった場合、
	 * 「常に予約不可」を避けるため種別を指定なしへ戻す。その旨をユーザーへ知らせる。
	 */
	public function render_reservation_day_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || Service_Menu_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$transient_key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$message       = get_transient( $transient_key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $transient_key );
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
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
				'i18n'      => array(
					// Translators: Button label to remove a fixed start time row. / 固定開始時刻の行を削除するボタンのラベル.
					'delete'                    => __( 'Delete', 'vk-booking-manager' ),
					// Translators: Placeholder for the price category name input. / 料金区分名の入力プレースホルダー.
					'priceTierLabelPlaceholder' => __( 'Category name (e.g. Adult)', 'vk-booking-manager' ),
					// Translators: aria-label for the price category name input. / 料金区分名入力の aria-label.
					'priceTierLabelAria'        => __( 'Price category name', 'vk-booking-manager' ),
					// Translators: aria-label for the price input. / 料金入力の aria-label.
					'priceTierPriceAria'        => __( 'Price', 'vk-booking-manager' ),
				),
				// 料金区分の料金欄に表示する単位（通貨記号＋税込ラベル）。税込ラベルが空でも通貨記号は常時表示する。
				'priceUnit' => '' !== VKBM_Helper::get_tax_included_label()
					? VKBM_Helper::get_currency_symbol() . VKBM_Helper::get_tax_included_label()
					: VKBM_Helper::get_currency_symbol(),
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

		// 自前のメタボックスを最上部に並べ替えるため、グローバル $wp_meta_boxes を意図的に操作する。
		if ( empty( $wp_meta_boxes[ $post_type ]['normal']['high'] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reordering our own meta box to the top.
			$wp_meta_boxes[ $post_type ]['normal']['high'] = array();
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reordering our own meta box to the top.
		$wp_meta_boxes[ $post_type ]['normal']['high'] = array( $meta_box_id => $meta_box ) + $wp_meta_boxes[ $post_type ]['normal']['high'];
	}

	/**
	 * Render VK Booking Manager meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_vkbm_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->render_basic_meta_box( $post );
				$this->render_conditions_meta_box( $post );
				$this->render_booking_window_field( $post );
				$this->render_internal_memo_field( $post );
				$this->render_detail_page_field( $post );
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * 予約締切・予約可能な最大日数フィールドを描画する。
	 *
	 * 内部メモフィールドの直前に配置し、内部管理用の「締切」「窓口期間」を
	 * まとめて確認・編集できるようにする。
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_booking_window_field( WP_Post $post ): void {
		$reservation_deadline = get_post_meta( $post->ID, '_vkbm_reservation_deadline_hours', true );
		$max_advance_days     = get_post_meta( $post->ID, '_vkbm_max_advance_booking_days', true );
		?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_reservation_deadline"><?php esc_html_e( 'Reservation deadline', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_reservation_deadline" name="vkbm_service_menu[reservation_deadline_hours]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $reservation_deadline ); ?>" /> <?php esc_html_e( 'hours ago', 'vk-booking-manager' ); ?>
				<p class="description"><?php esc_html_e( 'If not filled in, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_max_advance_booking_days"><?php esc_html_e( 'Max advance booking period', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_max_advance_booking_days" name="vkbm_service_menu[max_advance_booking_days]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $max_advance_days ); ?>" /> <?php esc_html_e( 'days', 'vk-booking-manager' ); ?>
				<p class="description">
					<?php esc_html_e( 'The maximum number of days in advance that reservations can be made. Set to 0 for no limit.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'If not filled in, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

		/**
		 * Render internal memo field.
		 *
		 * @param WP_Post $post Current post object.
		 */
	private function render_internal_memo_field( WP_Post $post ): void {
		$internal_memo = get_post_meta( $post->ID, '_vkbm_internal_memo', true );
		?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_internal_memo"><?php esc_html_e( 'Internal memo (customer hidden)', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<textarea id="vkbm_service_menu_internal_memo" name="vkbm_service_menu[internal_memo]" class="large-text" rows="4"><?php echo esc_textarea( $internal_memo ); ?></textarea>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the details page checkbox field.
	 * 詳細ページ使用チェックボックスを出力する。
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_detail_page_field( WP_Post $post ): void {
		$use_detail_page = get_post_meta( $post->ID, self::META_USE_DETAIL_PAGE, true );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Details page', 'vk-booking-manager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="vkbm_service_menu[use_detail_page]" value="1" <?php checked( '1', $use_detail_page ); ?> />
					<?php esc_html_e( 'Use', 'vk-booking-manager' ); ?>
				</label>
			</td>
		</tr>
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
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_catch_copy"><?php esc_html_e( 'Catchphrase', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="text" id="vkbm_service_menu_catch_copy" name="vkbm_service_menu[catch_copy]" class="regular-text" value="<?php echo esc_attr( $catch_copy ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_base_price">
					<?php
					esc_html_e( 'Basic price', 'vk-booking-manager' );
					if ( '' !== $tax_label ) {
						echo ' ' . esc_html( $tax_label );
					}
					?>
				</label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_base_price" name="vkbm_service_menu[base_price]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $base_price ); ?>" />
			</td>
		</tr>
		<?php if ( Staff_Editor::is_nomination_enabled() ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html( vkbm_get_nomination_fee_label() ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="vkbm_service_menu[disable_nomination_fee]" value="1" <?php checked( '1', $disable_nomination_fee ); ?> />
					<?php esc_html_e( 'Disable for this service menu', 'vk-booking-manager' ); ?>
				</label>
			</td>
		</tr>
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
		$other_conditions     = get_post_meta( $post->ID, self::META_OTHER_CONDITIONS, true );
		?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_duration_minutes"><?php esc_html_e( 'Time required (minutes)', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_duration_minutes" name="vkbm_service_menu[duration_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $duration_minutes ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_buffer_after"><?php esc_html_e( 'Post-service buffer (min)', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_buffer_after" name="vkbm_service_menu[buffer_after_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $buffer_after_minutes ); ?>" />
				<p class="description">
					<?php esc_html_e( 'During the service time plus the buffer time, new reservations will not be accepted.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'If it is left blank, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<?php $this->render_reservation_day_field( $post ); ?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_other_conditions"><?php echo esc_html( vkbm_get_other_conditions_label() ); ?></label>
			</th>
			<td>
				<textarea id="vkbm_service_menu_other_conditions" name="vkbm_service_menu[other_conditions]" class="large-text" rows="4"><?php echo esc_textarea( (string) $other_conditions ); ?></textarea>
			</td>
		</tr>
		<?php
		// 最大予約人数フィールド（Pro版のみ表示）
		// Display max capacity field only in Pro edition.
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $is_pro_edition ) :
			// 指名機能が有効かどうかを判定する。
			$is_nomination_enabled = Staff_Editor::is_nomination_enabled();
			// 予約枠の定員（同一枠で複数人を受け入れる）機能（親スイッチ）が有効かどうかを判定する。
			$is_slot_capacity_enabled = Staff_Editor::is_slot_capacity_enabled();

			if ( $is_nomination_enabled ) :
				// 指名機能が有効な場合は予約枠の定員フィールドを非表示にし、案内メッセージを表示する。
				$settings_url = admin_url( 'admin.php?page=vkbm-provider-settings&tab=system' ) . '#vkbm-staff-enabled';
				?>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Time slot capacity', 'vk-booking-manager' ); ?>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'The time slot capacity can be set to 2 or more when the nomination feature is disabled.', 'vk-booking-manager' ); ?><br>
							<?php
							printf(
								/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag (基本設定画面へのリンク). */
								esc_html__( 'The nomination feature can be enabled or disabled from the %1$sbasic settings page%2$s.', 'vk-booking-manager' ),
								'<a href="' . esc_url( $settings_url ) . '" target="_blank" rel="noopener noreferrer">',
								'</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<?php
			elseif ( ! $is_slot_capacity_enabled ) :
				// 予約枠の定員機能が親スイッチで無効な場合も定員フィールドを非表示にし、案内メッセージを表示する。
				$settings_url = admin_url( 'admin.php?page=vkbm-provider-settings&tab=system' ) . '#vkbm-slot-capacity-enabled';
				?>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Time slot capacity', 'vk-booking-manager' ); ?>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'To set the time slot capacity to 2 or more, enable the time slot capacity option in the basic settings.', 'vk-booking-manager' ); ?><br>
							<?php
							printf(
								/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag (基本設定画面へのリンク). */
								esc_html__( 'The time slot capacity can be enabled from the %1$sbasic settings page%2$s.', 'vk-booking-manager' ),
								'<a href="' . esc_url( $settings_url ) . '" target="_blank" rel="noopener noreferrer">',
								'</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<?php
			else :
				// 指名機能が無効の場合は従来通り予約枠の定員フィールドを表示する。
				$max_capacity       = get_post_meta( $post->ID, self::META_MAX_CAPACITY, true );
				$min_capacity       = get_post_meta( $post->ID, self::META_MIN_CAPACITY, true );
				$allow_multi_guests = (bool) get_post_meta( $post->ID, self::META_ALLOW_MULTIPLE_GUESTS, true );
				// 実効の予約枠の定員（未設定は既定1）。最少催行人数・貸切・料金区分は
				// 複数人一括予約の相乗りが前提のため、ここが2以上のときだけ意味を持つ（#320）。
				$max_capacity_value = '' === $max_capacity ? 1 : max( 1, (int) $max_capacity );
				// 複数人一括予約系の追加設定（最少催行人数・貸切・料金区分）を初期表示するか。
				// 「複数人一括予約ON かつ 予約枠の定員2以上」のときだけ表示する。
				// それ以外（複数人一括予約OFF または 定員1以下）は hidden で隠し、JS でも連動させる。
				$show_multi_guest_fields = $allow_multi_guests && $max_capacity_value >= 2;
				?>
				<tr>
					<th scope="row">
						<label for="vkbm_service_menu_max_capacity"><?php esc_html_e( 'Time slot capacity', 'vk-booking-manager' ); ?></label>
					</th>
					<td>
						<input type="number" id="vkbm_service_menu_max_capacity" name="vkbm_service_menu[max_capacity]" class="small-text" min="1" step="1" value="<?php echo esc_attr( $max_capacity ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Maximum number of people who can share this time slot when staff auto-assignment is used.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'When a specific staff member is selected, only one booking per slot is allowed.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'Default is 1.', 'vk-booking-manager' ); ?>
						</p>
					</td>
				</tr>
				<tr id="vkbm-min-capacity-field" <?php echo $show_multi_guest_fields ? '' : 'hidden'; ?>>
					<th scope="row">
						<label for="vkbm_service_menu_min_capacity"><?php esc_html_e( 'Minimum participants to confirm', 'vk-booking-manager' ); ?></label>
					</th>
					<td>
						<input type="number" id="vkbm_service_menu_min_capacity" name="vkbm_service_menu[min_capacity]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $min_capacity ); ?>" />
						<p class="description">
							<?php esc_html_e( 'When bookings sharing the same time slot reach this number, the session is treated as confirmed.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'Enter 0 to disable the minimum and accept any number of bookings.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'Values above the time slot capacity are reduced to that capacity.', 'vk-booking-manager' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Booking multiple people at once', 'vk-booking-manager' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" id="vkbm_service_menu_allow_multiple_guests" name="vkbm_service_menu[allow_multiple_guests]" value="1" <?php checked( true, $allow_multi_guests ); ?> />
							<?php esc_html_e( 'Allow the same user to book for multiple people at once', 'vk-booking-manager' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'A single booking can reserve for multiple people by entering the number of people.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'The maximum number per booking is the time slot capacity, because each booking is assigned to a single staff member.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'The price is multiplied by the number of people.', 'vk-booking-manager' ); ?><br>
							<?php
							printf(
								/* translators: %s: Provider settings page link to customize the quantity heading and unit. */
								esc_html__( 'You can customize the quantity heading and unit in %s.', 'vk-booking-manager' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=vkbm-provider-settings' ) ) . '">' . esc_html__( 'Basic settings', 'vk-booking-manager' ) . '</a>'
							);
							?>
							<br>
							<?php
							// 「消えた」誤解の予防：追加設定が現れる条件を一行で案内する（#320）。
							esc_html_e( 'When this is enabled and the time slot capacity is 2 or more, settings such as minimum participants, private booking, and price categories are shown.', 'vk-booking-manager' );
							?>
						</p>
					</td>
				</tr>
				<?php $this->render_exclusive_when_booked_field( $post, $show_multi_guest_fields ); ?>
				<?php $this->render_exclusive_user_selectable_field( $post, $show_multi_guest_fields ); ?>
				<?php $this->render_price_tiers_field( $post, $show_multi_guest_fields ); ?>
			<?php endif; ?>
		<?php else : ?>
			<?php
			// 無料版では予約枠の定員・スタッフ自動アサインなどの設定が利用できないため案内を表示する。
			?>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Time slot capacity', 'vk-booking-manager' ); ?>
				</th>
				<td>
					<?php
					echo Pro_Upsell::get_feature_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 出力はメソッド内でエスケープ済み.
						__( 'Setting the time slot capacity and staff auto-assignment are available in the Pro edition.', 'vk-booking-manager' )
					);
					?>
				</td>
			</tr>
		<?php endif; ?>
		<?php $this->render_fixed_start_times_field( $post ); ?>
		<?php
	}

	/**
	 * 予約可能日フィールド（種別プルダウン＋曜日指定／日付指定の詳細UI）を出力する。
	 *
	 * プルダウンで「指定なし／土日限定／平日限定／曜日指定／日付指定」を選び、
	 * 「曜日指定」「日付指定」を選んだときだけ対応する詳細UI（行追加式）を表示する。
	 * 曜日指定と日付指定は排他で、切替時に非表示側の入力値は DOM に残して保持する。
	 * 表示の出し分けは service-menu-editor.js が行う（aria-controls で関連付け）。
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_reservation_day_field( WP_Post $post ): void {
		$reservation_day_type = (string) get_post_meta( $post->ID, self::META_RESERVATION_DAY_TYPE, true );

		// 曜日指定（頻度 × 曜日）・日付指定（単日・期間）の保存済み設定を読み込む。
		$custom_weekdays = get_post_meta( $post->ID, self::META_RESERVATION_CUSTOM_WEEKDAYS, true );
		$custom_weekdays = is_array( $custom_weekdays ) ? $custom_weekdays : array();
		$custom_dates    = get_post_meta( $post->ID, self::META_RESERVATION_CUSTOM_DATES, true );
		$custom_dates    = is_array( $custom_dates ) ? $custom_dates : array();

		// 詳細UIパネルの表示・非表示（選択中の種別のみ表示）。
		$weekday_hidden = ( Reservation_Day::TYPE_CUSTOM_WEEKDAY !== $reservation_day_type );
		$date_hidden    = ( Reservation_Day::TYPE_CUSTOM_DATE !== $reservation_day_type );

		// 曜日指定テーブルの name 接頭辞・行/削除ボタンのクラス（共有 render_row へ渡す）。
		$weekday_name_base  = 'vkbm_service_menu[reservation_custom_weekdays]';
		$weekday_row_class  = 'vkbm-reservation-custom-weekday-row';
		$weekday_remove_cls = 'vkbm-reservation-custom-weekday-remove';
		$weekday_panel_id   = 'vkbm-reservation-custom-weekday-panel';
		$date_panel_id      = 'vkbm-reservation-custom-date-panel';
		?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_reservation_day_type"><?php esc_html_e( 'Reservation date', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<select id="vkbm_service_menu_reservation_day_type" name="vkbm_service_menu[reservation_day_type]" aria-controls="<?php echo esc_attr( $weekday_panel_id . ' ' . $date_panel_id ); ?>">
					<option value="" <?php selected( '', $reservation_day_type ); ?>><?php esc_html_e( 'Not specified', 'vk-booking-manager' ); ?></option>
					<option value="weekend" <?php selected( 'weekend', $reservation_day_type ); ?>><?php esc_html_e( 'Saturdays and Sundays only', 'vk-booking-manager' ); ?></option>
					<option value="weekday" <?php selected( 'weekday', $reservation_day_type ); ?>><?php esc_html_e( 'Weekdays only', 'vk-booking-manager' ); ?></option>
					<option value="custom_weekday" <?php selected( 'custom_weekday', $reservation_day_type ); ?>><?php esc_html_e( 'Specified days of the week', 'vk-booking-manager' ); ?></option>
					<option value="custom_date" <?php selected( 'custom_date', $reservation_day_type ); ?>><?php esc_html_e( 'Specified dates', 'vk-booking-manager' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'If not specified, reservations are accepted on all days of the week (within the range where shifts exist).', 'vk-booking-manager' ); ?></p>

				<div class="vkbm-reservation-day-custom" id="<?php echo esc_attr( $weekday_panel_id ); ?>" <?php echo $weekday_hidden ? 'hidden' : ''; ?>>
					<fieldset class="vkbm-reservation-day-fieldset">
						<legend><?php esc_html_e( 'Specify the days of the week when reservations are accepted.', 'vk-booking-manager' ); ?></legend>
						<table class="vkbm-setting-table vkbm-reservation-custom-weekdays-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'period', 'vk-booking-manager' ); ?></th>
									<th scope="col"><?php esc_html_e( 'day of week', 'vk-booking-manager' ); ?></th>
									<th scope="col" class="column-actions"><?php esc_html_e( 'operation', 'vk-booking-manager' ); ?></th>
								</tr>
							</thead>
							<tbody id="vkbm-reservation-custom-weekday-rows">
								<?php
								foreach ( $custom_weekdays as $index => $rule ) {
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within Weekday_Rule::render_row().
									echo Weekday_Rule::render_row( $weekday_name_base, (string) $index, is_array( $rule ) ? $rule : array(), $weekday_row_class, $weekday_remove_cls );
								}
								?>
							</tbody>
						</table>
						<button type="button" class="button button-secondary" id="vkbm-reservation-custom-weekday-add"><?php esc_html_e( '+ Add day of the week', 'vk-booking-manager' ); ?></button>
						<p class="description"><?php esc_html_e( 'Combine "every week" or "1st to 5th" with a day of the week. Reservations are accepted only on the specified days of the week (within the range where shifts exist).', 'vk-booking-manager' ); ?></p>
						<template id="vkbm-reservation-custom-weekday-row-template">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within Weekday_Rule::render_row().
							echo Weekday_Rule::render_row( $weekday_name_base, '__INDEX__', array(), $weekday_row_class, $weekday_remove_cls );
							?>
						</template>
					</fieldset>
				</div>

				<div class="vkbm-reservation-day-custom" id="<?php echo esc_attr( $date_panel_id ); ?>" <?php echo $date_hidden ? 'hidden' : ''; ?>>
					<fieldset class="vkbm-reservation-day-fieldset">
						<legend><?php esc_html_e( 'Specify the dates when reservations are accepted.', 'vk-booking-manager' ); ?></legend>
						<div id="vkbm-reservation-custom-date-rows" class="vkbm-reservation-custom-date-rows">
							<?php
							foreach ( $custom_dates as $index => $row ) {
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within render_custom_date_row().
								echo $this->render_custom_date_row( (string) $index, is_array( $row ) ? $row : array() );
							}
							?>
						</div>
						<button type="button" class="button button-secondary" id="vkbm-reservation-custom-date-add"><?php esc_html_e( '+ Add date', 'vk-booking-manager' ); ?></button>
						<p class="description"><?php esc_html_e( 'Choose "Single day" or a "Range" (start to end) per row. Reservations are accepted only on the specified dates (within the range where shifts exist).', 'vk-booking-manager' ); ?></p>
						<template id="vkbm-reservation-custom-date-row-template">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escaped within render_custom_date_row().
							echo $this->render_custom_date_row( '__INDEX__', array() );
							?>
						</template>
					</fieldset>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * 日付指定（単日・期間）の1行分のマークアップを生成して返す。
	 *
	 * 行ごとに「単日」か「期間（開始〜終了）」を選べる。表示の出し分け（単日入力／期間入力）は
	 * service-menu-editor.js が種別セレクトの値に応じて行う。動的に複製される行のため、
	 * 各入力の accessible name は aria-label で付与する（for/id は行間で一意にできないため）。
	 *
	 * @param string $index 行のインデックス（テンプレート用に '__INDEX__' を渡す場合もある）。
	 * @param array  $value 現在値（'type' / 'date' / 'start' / 'end' キー）。
	 * @return string 行の HTML 文字列。
	 */
	private function render_custom_date_row( string $index, array $value ): string {
		$type  = isset( $value['type'] ) ? (string) $value['type'] : 'single';
		$type  = in_array( $type, array( 'single', 'range' ), true ) ? $type : 'single';
		$date  = isset( $value['date'] ) ? (string) $value['date'] : '';
		$start = isset( $value['start'] ) ? (string) $value['start'] : '';
		$end   = isset( $value['end'] ) ? (string) $value['end'] : '';

		// 種別ごとの入力欄の表示・非表示。
		$single_hidden = ( 'single' !== $type );
		$range_hidden  = ( 'range' !== $type );

		$name_base = 'vkbm_service_menu[reservation_custom_dates][' . $index . ']';

		ob_start();
		?>
		<div class="vkbm-reservation-custom-date-row" data-index="<?php echo esc_attr( $index ); ?>">
			<select name="<?php echo esc_attr( $name_base ); ?>[type]" class="vkbm-reservation-custom-date-type" aria-label="<?php esc_attr_e( 'Date specification type', 'vk-booking-manager' ); ?>">
				<option value="single" <?php selected( 'single', $type ); ?>><?php esc_html_e( 'Single day', 'vk-booking-manager' ); ?></option>
				<option value="range" <?php selected( 'range', $type ); ?>><?php esc_html_e( 'Range', 'vk-booking-manager' ); ?></option>
			</select>
			<span class="vkbm-reservation-custom-date-single" <?php echo $single_hidden ? 'hidden' : ''; ?>>
				<input type="date" name="<?php echo esc_attr( $name_base ); ?>[date]" value="<?php echo esc_attr( $date ); ?>" class="vkbm-reservation-custom-date-single-input" aria-label="<?php esc_attr_e( 'Date', 'vk-booking-manager' ); ?>" />
			</span>
			<span class="vkbm-reservation-custom-date-range" <?php echo $range_hidden ? 'hidden' : ''; ?>>
				<input type="date" name="<?php echo esc_attr( $name_base ); ?>[start]" value="<?php echo esc_attr( $start ); ?>" class="vkbm-reservation-custom-date-start" aria-label="<?php esc_attr_e( 'Start date', 'vk-booking-manager' ); ?>" />
				<span class="vkbm-reservation-custom-date-delimiter" aria-hidden="true">〜</span>
				<input type="date" name="<?php echo esc_attr( $name_base ); ?>[end]" value="<?php echo esc_attr( $end ); ?>" class="vkbm-reservation-custom-date-end" aria-label="<?php esc_attr_e( 'End date', 'vk-booking-manager' ); ?>" />
			</span>
			<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-reservation-custom-date-remove"><?php esc_html_e( 'delete', 'vk-booking-manager' ); ?></button>
		</div>
		<?php

		return (string) ob_get_clean();
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
		<tr>
			<th scope="row"><?php esc_html_e( 'Fixed start times', 'vk-booking-manager' ); ?></th>
			<td>
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
				<p class="description">
					<?php
					// 未設定の場合の説明（基本設定画面へのリンク付き） / Description when not set (with link to provider settings).
					printf(
						/* translators: %s: link to the reservation slot time setting on the provider settings page. */
						esc_html__( 'If not set, reservation slots are based on the interval specified in %s.', 'vk-booking-manager' ),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-slot-step-minutes' ) ),
							esc_html__( 'Reservation slot time on the General Settings page', 'vk-booking-manager' )
						)
					);
					?>
					<br>
					<?php
					// 設定した場合の説明 / Description when set.
					esc_html_e( 'If set, only the specified times are available for booking.', 'vk-booking-manager' );
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * 「貸し切り予約」設定の行を出力する。
	 *
	 * ONにすると、1件でも予約が入った時間帯は残り枠があっても他のユーザーが予約できなくなる。
	 * 複数人一括予約の相乗り（複数人一括予約ON かつ 予約枠の定員2以上）のときだけ意味を持つ設定のため、
	 * 料金区分欄と同じく条件未達時は hidden で隠し、JS（service-menu-editor.js）で
	 * 複数人一括予約チェック・予約枠の定員の変更と表示を連動させる（#320）。
	 * これにより条件未達のまま入力 → 保存で削除され入力が消える事故を防ぐ。
	 *
	 * @param WP_Post $post       Current post object.
	 * @param bool    $show_field 初期表示するか（複数人一括予約ON かつ 予約枠の定員2以上）。
	 */
	private function render_exclusive_when_booked_field( WP_Post $post, bool $show_field ): void {
		$exclusive_when_booked = (bool) get_post_meta( $post->ID, self::META_EXCLUSIVE_WHEN_BOOKED, true );
		// 説明文と input を aria-describedby で紐付けるための ID。
		$description_id = 'vkbm-exclusive-when-booked-description';
		?>
		<tr id="vkbm-exclusive-when-booked-field" <?php echo $show_field ? '' : 'hidden'; ?>>
			<th scope="row"><?php esc_html_e( 'Private booking', 'vk-booking-manager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" id="vkbm_service_menu_exclusive_when_booked" name="vkbm_service_menu[exclusive_when_booked]" value="1" aria-describedby="<?php echo esc_attr( $description_id ); ?>" <?php checked( true, $exclusive_when_booked ); ?> />
					<?php esc_html_e( 'Make the slot private once a booking is placed', 'vk-booking-manager' ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
					<?php esc_html_e( 'When enabled, once even a single booking is placed in a time slot, other users can no longer book that slot even if seats remain.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'This is intended for tours where strangers should not share the same slot (e.g. SUP, diving).', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * 「ユーザーによる貸し切り指定」設定の行を出力する（#305）。
	 *
	 * ONにすると、予約者がフロントの予約画面で「この時間帯を貸切にする」を選択できるようになる。
	 * チェックON時のみ第二段（貸し切り料金・適用しない申込人数）を表示する。
	 * 複数人一括予約の相乗り（複数人一括予約ON かつ 予約枠の定員2以上）のときだけ意味を持つ設定のため、
	 * 貸し切り予約・料金区分欄と同じく条件未達時は hidden で隠し、
	 * JS（service-menu-editor.js）で複数人一括予約チェック・予約枠の定員の変更と表示を連動させる（#320）。
	 *
	 * @param WP_Post $post       Current post object.
	 * @param bool    $show_field 初期表示するか（複数人一括予約ON かつ 予約枠の定員2以上）。
	 */
	private function render_exclusive_user_selectable_field( WP_Post $post, bool $show_field ): void {
		$user_selectable = (bool) get_post_meta( $post->ID, self::META_EXCLUSIVE_USER_SELECTABLE, true );
		$fee_per_person  = get_post_meta( $post->ID, self::META_EXCLUSIVE_FEE_PER_PERSON, true );
		$fee_exempt      = get_post_meta( $post->ID, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS, true );
		// 貸し切り料金欄に表示する通貨記号（税込ラベルは付けない＝1人あたり単価のため）。
		$currency_symbol = VKBM_Helper::get_currency_symbol();
		// 説明文と input を aria-describedby で紐付けるための ID。
		$description_id = 'vkbm-exclusive-user-selectable-description';
		?>
		<tr id="vkbm-exclusive-user-selectable-field" <?php echo $show_field ? '' : 'hidden'; ?>>
			<th scope="row"><?php esc_html_e( 'Private booking by customer', 'vk-booking-manager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" id="vkbm_service_menu_exclusive_user_selectable" name="vkbm_service_menu[exclusive_user_selectable]" value="1" aria-describedby="<?php echo esc_attr( $description_id ); ?>" <?php checked( true, $user_selectable ); ?> />
					<?php esc_html_e( 'Accept private booking requests from customers', 'vk-booking-manager' ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
					<?php esc_html_e( 'Only the first person to book the same date and time can choose this.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'It can be chosen only when the number of guests meets the minimum participants to confirm.', 'vk-booking-manager' ); ?>
				</p>
				<div id="vkbm-exclusive-fee-fields" class="vkbm-exclusive-fee-fields" <?php echo $user_selectable ? '' : 'hidden'; ?>>
					<p>
						<label for="vkbm_service_menu_exclusive_fee_per_person" class="vkbm-exclusive-fee-label">
							<span class="text-nowrap"><?php esc_html_e( 'Private booking fee', 'vk-booking-manager' ); ?></span>
							<span class="text-nowrap"><?php echo esc_html( $currency_symbol ); ?><input type="number" id="vkbm_service_menu_exclusive_fee_per_person" name="vkbm_service_menu[exclusive_fee_per_person]" class="small-text" min="0" step="1" value="<?php echo esc_attr( (string) $fee_per_person ); ?>" /> 
							<?php
								/* translators: per one guest unit suffix shown after the private booking fee input. */
								esc_html_e( '/ per guest', 'vk-booking-manager' );
							?>
							</span>
						</label>
					</p>
					<p class="description"><?php esc_html_e( 'The private booking fee is added per guest.', 'vk-booking-manager' ); ?></p>
					<p>
						<label for="vkbm_service_menu_exclusive_fee_exempt_guests" class="vkbm-exclusive-fee-label">
							<span class="text-nowrap"><?php esc_html_e( 'Number of guests that exempts the private booking fee', 'vk-booking-manager' ); ?></span>
							<input type="number" id="vkbm_service_menu_exclusive_fee_exempt_guests" name="vkbm_service_menu[exclusive_fee_exempt_guests]" class="small-text" min="0" step="1" value="<?php echo esc_attr( (string) $fee_exempt ); ?>" />
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'When the number of guests reaches this value or more, the slot is effectively private, so the private booking fee is not added.', 'vk-booking-manager' ); ?><br>
						<?php esc_html_e( 'If left blank, the private booking fee is always added regardless of the number of guests.', 'vk-booking-manager' ); ?>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * 料金区分（大人料金・子供料金など）のリピータUIを出力する。
	 *
	 * 「複数人一括予約を許可」ON のブロック内に表示する。区分を1つ以上定義すると、
	 * 基本料金は無視されて区分料金で計算される旨を併記する。
	 * 複数人一括予約の相乗り（複数人一括予約ON かつ 予約枠の定員2以上）の条件未達時は非表示（hidden）にし、
	 * JS で複数人一括予約チェック・予約枠の定員の変更と表示を連動させる（#320）。
	 * これにより条件未達のまま入力 → 保存で削除され入力が消える事故を防ぐ。
	 *
	 * @param WP_Post $post       Current post object.
	 * @param bool    $show_field 初期表示するか（複数人一括予約ON かつ 予約枠の定員2以上）。
	 */
	private function render_price_tiers_field( WP_Post $post, bool $show_field ): void {
		$tiers     = Price_Tiers::normalize_tiers( get_post_meta( $post->ID, self::META_PRICE_TIERS, true ) );
		$tax_label = VKBM_Helper::get_tax_included_label();
		// 料金欄の単位：税込ラベルが空でも通貨記号を常時表示する（design-rules の単位表示要件）。
		$price_unit       = VKBM_Helper::get_currency_symbol();
		$label_aria       = __( 'Price category name', 'vk-booking-manager' );
		$price_aria       = __( 'Price', 'vk-booking-manager' );
		$label_head_label = __( 'Category name', 'vk-booking-manager' );
		$price_head_label = __( 'Price', 'vk-booking-manager' );
		?>
		<tr id="vkbm-price-tiers-field" <?php echo $show_field ? '' : 'hidden'; ?>>
			<th scope="row"><?php esc_html_e( 'Price categories', 'vk-booking-manager' ); ?></th>
			<td>
				<div id="vkbm-price-tiers-list" class="vkbm-price-tiers-list">
					<?php if ( ! empty( $tiers ) ) : ?>
						<div class="vkbm-price-tier-head" aria-hidden="true">
							<span class="vkbm-price-tier-head-label"><?php echo esc_html( $label_head_label ); ?></span>
							<span class="vkbm-price-tier-head-price"><?php echo esc_html( $price_head_label ); ?></span>
						</div>
					<?php endif; ?>
					<?php foreach ( $tiers as $tier ) : ?>
						<div class="vkbm-price-tier-row">
							<input
								type="text"
								name="vkbm_service_menu[price_tiers][label][]"
								class="regular-text vkbm-price-tier-label"
								value="<?php echo esc_attr( $tier['label'] ); ?>"
								placeholder="<?php esc_attr_e( 'Category name (e.g. Adult)', 'vk-booking-manager' ); ?>"
								aria-label="<?php echo esc_attr( $label_aria ); ?>"
							/>
							<input
								type="number"
								name="vkbm_service_menu[price_tiers][price][]"
								class="small-text vkbm-price-tier-price"
								min="0"
								step="1"
								value="<?php echo esc_attr( (string) $tier['price'] ); ?>"
								aria-label="<?php echo esc_attr( $price_aria ); ?>"
							/>
							<span class="vkbm-price-tier-unit"><?php echo esc_html( '' !== $tax_label ? $price_unit . $tax_label : $price_unit ); ?></span>
							<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-price-tier-remove"><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" id="vkbm-price-tier-add" class="button"><?php esc_html_e( '+ Add price category', 'vk-booking-manager' ); ?></button>
				<p class="description"><?php esc_html_e( 'Define categories such as "Adult" and "Child", each with its own price.', 'vk-booking-manager' ); ?></p>
				<p class="description"><?php esc_html_e( 'When one or more categories are defined, the basic price is ignored and the price is calculated from the categories.', 'vk-booking-manager' ); ?></p>
				<p class="description"><?php esc_html_e( 'If no category is defined, the basic price multiplied by the number of guests is used.', 'vk-booking-manager' ); ?></p>
			</td>
		</tr>
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

		$data                 = wp_unslash( $_POST['vkbm_service_menu'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key is sanitized via sanitize_*_value() below.
		$catch_copy           = $this->sanitize_text_value( $data, 'catch_copy' );
		$internal_memo        = $this->sanitize_textarea_value( $data, 'internal_memo' );
		$other_conditions     = $this->sanitize_textarea_value( $data, 'other_conditions' );
		$base_price           = $this->sanitize_numeric_value( $data, 'base_price' );
		$duration             = $this->sanitize_numeric_value( $data, 'duration_minutes' );
		$buffer_after         = $this->sanitize_numeric_value( $data, 'buffer_after_minutes' );
		$deadline             = $this->sanitize_numeric_value( $data, 'reservation_deadline_hours' );
		$max_advance_days     = $this->sanitize_integer_value( $data, 'max_advance_booking_days' );
		$reservation_day_type = $this->sanitize_reservation_day_type( $data['reservation_day_type'] ?? '' );
		// 曜日指定（頻度 × 曜日）・日付指定（単日・期間）の詳細設定をサニタイズする。
		// 排他だが、切替時に非表示側の入力を保持するため両方とも常に保存する（選択中の種別のみ有効化）。
		$raw_weekdays    = ( isset( $data['reservation_custom_weekdays'] ) && is_array( $data['reservation_custom_weekdays'] ) ) ? $data['reservation_custom_weekdays'] : array();
		$raw_dates       = ( isset( $data['reservation_custom_dates'] ) && is_array( $data['reservation_custom_dates'] ) ) ? $data['reservation_custom_dates'] : array();
		$custom_weekdays = Weekday_Rule::sanitize_rules( $raw_weekdays );
		// 過去日のみの日付指定は「常に予約不可」を招くため、当日基準（サイトのタイムゾーン）で除外する。
		$today        = wp_date( 'Y-m-d' );
		$custom_dates = Reservation_Day::sanitize_custom_dates( $raw_dates, $today );

		// 予約可能日の保存に関する管理画面通知（空選択防止・部分破棄）を組み立てる。
		$reservation_day_notices = array();

		// 空選択の防止（design-rules: 注意書きではなくシステム側で防ぐ）。
		// 曜日指定／日付指定を選んだのに有効な行が0件だと全曜日が不可になるため、種別を指定なしへ戻し理由を通知する。
		if ( Reservation_Day::TYPE_CUSTOM_WEEKDAY === $reservation_day_type && empty( $custom_weekdays ) ) {
			$reservation_day_type      = Reservation_Day::TYPE_NONE;
			$reservation_day_notices[] = __( 'No valid day-of-week rows were found, so the reservation date was reset to "Not specified".', 'vk-booking-manager' );
		} elseif ( Reservation_Day::TYPE_CUSTOM_DATE === $reservation_day_type && empty( $custom_dates ) ) {
			$reservation_day_type      = Reservation_Day::TYPE_NONE;
			$reservation_day_notices[] = __( 'No valid date rows were found, so the reservation date was reset to "Not specified".', 'vk-booking-manager' );
		} elseif ( Reservation_Day::TYPE_CUSTOM_WEEKDAY === $reservation_day_type
			&& count( $custom_weekdays ) < $this->count_meaningful_weekday_rows( $raw_weekdays ) ) {
			// 選択中の種別が曜日指定で、入力のあった行の一部だけが破棄された場合に通知する（サイレント破棄の可視化）。
			$reservation_day_notices[] = __( 'Some invalid day-of-week rows were not saved.', 'vk-booking-manager' );
		} elseif ( Reservation_Day::TYPE_CUSTOM_DATE === $reservation_day_type
			&& count( $custom_dates ) < $this->count_meaningful_date_rows( $raw_dates ) ) {
			// 選択中の種別が日付指定で、入力のあった行の一部だけ（過去日・開始と終了が逆など）が破棄された場合に通知する。
			$reservation_day_notices[] = __( 'Some invalid dates (past dates, or start after end) were not saved.', 'vk-booking-manager' );
		}

		if ( ! empty( $reservation_day_notices ) ) {
			set_transient(
				self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				implode( ' ', $reservation_day_notices ),
				MINUTE_IN_SECONDS
			);
		}
		$online_unavailable     = isset( $data['online_unavailable'] ) ? '1' : '';
		$archive                = isset( $data['is_archived'] ) ? '1' : '';
		$use_detail_page        = isset( $data['use_detail_page'] ) ? '1' : '';
		$disable_nomination_fee = isset( $data['disable_nomination_fee'] ) ? '1' : '';
		$max_capacity           = max( 1, $this->sanitize_integer_value( $data, 'max_capacity' ) );
		// 最小催行人数は 0 以上に丸め、最大受付数を上限としてクランプする（0=制約なし／後方互換）。
		$min_capacity          = min( $max_capacity, max( 0, $this->sanitize_integer_value( $data, 'min_capacity' ) ) );
		$allow_multiple_guests = isset( $data['allow_multiple_guests'] );
		$exclusive_when_booked = isset( $data['exclusive_when_booked'] );
		// ユーザーによる貸し切り指定（#305）。
		$exclusive_user_selectable = isset( $data['exclusive_user_selectable'] );
		// 貸し切り料金（1人あたり）と適用外人数。空欄は 0（=単価なし／上限なし）として保存する。
		// sanitize_integer_value() は '' または数値文字列を返すため (int) で 0 へ正規化する。
		$exclusive_fee_per_person = max( 0, (int) $this->sanitize_integer_value( $data, 'exclusive_fee_per_person' ) );
		$exclusive_fee_exempt     = max( 0, (int) $this->sanitize_integer_value( $data, 'exclusive_fee_exempt_guests' ) );
		$price_tiers              = $this->sanitize_price_tiers( $data['price_tiers'] ?? array() );
		$staff_ids                = $this->sanitize_staff_ids( $data['staff_ids'] ?? array() );
		$fixed_start_times        = $this->sanitize_fixed_start_times(
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
		// 曜日指定・日付指定の詳細は種別に関わらず常に保存する（切替時の非表示側の入力を保持するため）。
		// 空配列のときはメタを削除する（update_meta_value は $allow_array=true で空を delete する）。
		$this->update_meta_value( $post_id, self::META_RESERVATION_CUSTOM_WEEKDAYS, $custom_weekdays, true );
		$this->update_meta_value( $post_id, self::META_RESERVATION_CUSTOM_DATES, $custom_dates, true );
		$this->update_meta_value( $post_id, '_vkbm_online_unavailable', $online_unavailable );
		delete_post_meta( $post_id, '_vkbm_online_available' );
		$this->update_meta_value( $post_id, '_vkbm_is_archived', $archive );
		$this->update_meta_value( $post_id, self::META_USE_DETAIL_PAGE, $use_detail_page );
		$this->update_meta_value( $post_id, self::META_DISABLE_NOMINATION_FEE, $disable_nomination_fee );
		// 予約枠の定員はPro版かつ指名機能無効かつ予約枠の定員機能ON時のみ保存。
		// 指名ON時・予約枠の定員機能OFF時はフォームにフィールドが無いため、既存値を保持する。
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $is_pro_edition && ! Staff_Editor::is_nomination_enabled() && Staff_Editor::is_slot_capacity_enabled() ) {
			$this->update_meta_value( $post_id, self::META_MAX_CAPACITY, $max_capacity );
			// 最小催行人数を保存。0（制約なし）はメタを削除して従来挙動へ戻す。
			if ( $min_capacity > 0 ) {
				update_post_meta( $post_id, self::META_MIN_CAPACITY, $min_capacity );
			} else {
				delete_post_meta( $post_id, self::META_MIN_CAPACITY );
			}
			// 複数人一括予約の許可フラグを保存（Pro版かつ指名OFF時のみ）。
			// 1予約あたりの最大人数は「予約枠の定員」に統一したため、専用メタは保存せず削除する。
			if ( $allow_multiple_guests ) {
				// 登録時のメタ型（boolean）に合わせて真偽値で保存する。
				update_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS, true );
				// 料金区分は複数人一括予約ON時のみ保存する。空配列ならメタを削除する（基本料金×人数へ戻す）。
				if ( ! empty( $price_tiers ) ) {
					update_post_meta( $post_id, self::META_PRICE_TIERS, $price_tiers );
				} else {
					delete_post_meta( $post_id, self::META_PRICE_TIERS );
				}
				// 貸し切り予約は複数人一括予約ON時のみ保存する（B案：複数人一括予約OFF時はフォームに無い）。
				// ONなら true で保存、OFFならメタを削除する（_vkbm_allow_multiple_guests と同じ保存パターン）。
				if ( $exclusive_when_booked ) {
					update_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED, true );
				} else {
					delete_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED );
				}
				// ユーザーによる貸し切り指定（#305）も複数人一括予約ON時のみ保存する。
				// ONなら true で保存し、貸し切り料金・適用外人数も保存する（0 は削除＝デフォルトへ戻す）。
				// OFFなら関連メタをすべて削除する（stale な単価・適用外人数を残さない）。
				if ( $exclusive_user_selectable ) {
					update_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE, true );
					if ( $exclusive_fee_per_person > 0 ) {
						update_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON, $exclusive_fee_per_person );
					} else {
						delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
					}
					if ( $exclusive_fee_exempt > 0 ) {
						update_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS, $exclusive_fee_exempt );
					} else {
						delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
					}
				} else {
					delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
					delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
					delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
				}
			} else {
				// このメニュー単位で「複数人一括予約を許可」チェックが OFF にされたケース。
				// このとき料金区分・貸し切り予約・ユーザー貸し切り指定の各フィールドは
				// JS（syncMultipleGuestsDependentFields）で同一チェックに連動して隠され、
				// フォームには空（未チェック）として送られる。ユーザーが明示的に複数人一括予約を
				// 無効化した＝従属設定を破棄する意思表示なので、従属メタは一括で削除する。
				//
				// NOTE: ここは「予約枠の定員 親スイッチ OFF」分岐（下の elseif、フィールド自体が
				// 未描画＝保持すべき）とは異なる。あちらはフォームに項目が無いため保持するが、
				// こちらは項目が描画され明示的に OFF にされたケース。ユーザー貸し切り指定の3メタは、
				// 同じチェックに連動する兄弟メタ（料金区分・貸し切り予約）と必ず同じライフサイクルに
				// 揃える（兄弟が delete されるここでは3メタも delete のまま据え置く）。
				delete_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS );
				delete_post_meta( $post_id, self::META_PRICE_TIERS );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED );
				// ユーザー貸し切り指定（#305）の3メタも兄弟と揃えて削除する（保持しない）。
				delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
			}
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
		} elseif ( $is_pro_edition && ! Staff_Editor::is_nomination_enabled() && ! Staff_Editor::is_slot_capacity_enabled() ) {
			// Pro版・指名OFFだが予約枠の定員機能が親スイッチで無効な場合。
			// フォームに予約枠の定員・複数名許可・料金区分のフィールドが無いため、
			// 既存のメニュー単位設定（予約枠の定員・複数名許可・料金区分）は削除せずそのまま保持する。
			// これにより、親スイッチを無効化しても再有効化すれば元の設定で復帰でき、後方互換を維持する。
			// なお、実際の人数判定はゲート側（get_max_guests / resolve_guests / get_menu_max_capacity）で
			// 予約枠の定員機能OFFを評価し1名へ抑止するため、メタを残しても挙動上の影響はない。
			// 廃止済みの専用メタ（1予約あたり最大人数）は他分岐と同様に削除する。
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
			// 貸し切り予約メタ（_vkbm_exclusive_when_booked）は、予約枠の定員機能OFF時もフォームにフィールドが無いため
			// 既存値を保持する（料金区分・予約枠の定員・複数名許可・ユーザー貸し切り指定と同じ「非表示時は保持」方針）。
			// これにより全体設定を無効化→再有効化したとき元の貸切設定で復帰でき、後方互換を維持する。
			// 判定側 is_menu_exclusive_when_booked() がフルゲート（予約枠の定員ON・最大受付数>=2 等）を再適用するため、保持しても OFF 中は無害。
			// ユーザー貸し切り指定（#305）の3メタも同様に、非表示のため既存値を保持する（兄弟メタと揃える）。
			// 判定側 is_user_exclusive_selectable() がフルゲートを再適用するため、保持しても OFF 中は無害。
		} elseif ( $is_pro_edition && Staff_Editor::is_nomination_enabled() ) {
			// Pro版で指名機能が有効な場合は1対1予約のため予約枠の定員・複数人一括予約は無効。
			// 予約枠の定員フィールドは未表示なので既存値を保持し、複数人一括予約関連メタのみ削除する。
			delete_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS );
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
			// 指名ON時は料金区分・貸し切り予約も使わないため削除する。
			delete_post_meta( $post_id, self::META_PRICE_TIERS );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED );
			// ユーザー貸し切り指定（#305）も削除する。
			delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
		} elseif ( ! $is_pro_edition ) {
			delete_post_meta( $post_id, self::META_MAX_CAPACITY );
			// 最小催行人数は Pro 版限定機能のため無料版では削除する。
			delete_post_meta( $post_id, self::META_MIN_CAPACITY );
			delete_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS );
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
			// 無料版では料金区分・貸し切り予約は利用できないため削除する。
			delete_post_meta( $post_id, self::META_PRICE_TIERS );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED );
			// ユーザー貸し切り指定（#305）も無料版では削除する。
			delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
			delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
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
		// 許容値への正規化（指定なし・土日限定・平日限定・曜日指定・日付指定）は共有ヘルパーに集約している。
		$value = sanitize_text_field( wp_unslash( (string) $raw ) );
		return Reservation_Day::sanitize_type( $value );
	}

	/**
	 * 曜日指定の生入力のうち「入力のあった行」の件数を数える。
	 *
	 * サニタイズ後の件数と比較して、一部の行だけが破棄されたか（部分破棄）を検出するために使う。
	 * 曜日行はセレクトのため通常は頻度・曜日のいずれかに値があり、値の入った行を対象とする。
	 *
	 * @param array $raw 曜日指定の生入力配列。
	 * @return int 入力のあった行数。
	 */
	private function count_meaningful_weekday_rows( array $raw ): int {
		$count = 0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$frequency = trim( (string) ( $row['frequency'] ?? '' ) );
			$weekday   = trim( (string) ( $row['weekday'] ?? '' ) );
			if ( '' !== $frequency || '' !== $weekday ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * 日付指定の生入力のうち「入力のあった行」の件数を数える。
	 *
	 * 単日は日付が入っている行、期間は開始・終了のいずれかが入っている行を「入力あり」とみなす。
	 * サニタイズ後の件数と比較して、過去日・start>end などで一部の行だけが破棄されたか
	 * （部分破棄）を検出するために使う。完全に空の行は対象外（未入力のまま残した行は破棄しても通知しない）。
	 *
	 * @param array $raw 日付指定の生入力配列。
	 * @return int 入力のあった行数。
	 */
	private function count_meaningful_date_rows( array $raw ): int {
		$count = 0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = (string) ( $row['type'] ?? '' );
			if ( 'range' === $type ) {
				$start = trim( (string) ( $row['start'] ?? '' ) );
				$end   = trim( (string) ( $row['end'] ?? '' ) );
				if ( '' !== $start || '' !== $end ) {
					++$count;
				}
			} else {
				// 既定は単日として扱う（type 未指定でも date があれば入力ありとみなす）。
				$date = trim( (string) ( $row['date'] ?? '' ) );
				if ( '' !== $date ) {
					++$count;
				}
			}
		}
		return $count;
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
	 * 料金区分のフォーム入力（label/price の並行配列）を正規化する。
	 *
	 * フォームは `price_tiers[label][]` と `price_tiers[price][]` を並行配列で送るため、
	 * インデックスで突き合わせて `[ [ 'label' => ..., 'price' => ... ], ... ]` に変換してから
	 * Price_Tiers::sanitize_tiers() へ委譲する（空行除去・負数クランプ・件数上限）。
	 *
	 * @param mixed $raw 料金区分の生入力。
	 * @return array<int, array{label: string, price: int}>
	 */
	private function sanitize_price_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$labels = isset( $raw['label'] ) && is_array( $raw['label'] ) ? array_values( $raw['label'] ) : array();
		$prices = isset( $raw['price'] ) && is_array( $raw['price'] ) ? array_values( $raw['price'] ) : array();

		$rows = array();
		foreach ( $labels as $index => $label ) {
			$rows[] = array(
				'label' => sanitize_text_field( wp_unslash( (string) $label ) ),
				'price' => isset( $prices[ $index ] ) ? wp_unslash( (string) $prices[ $index ] ) : 0,
			);
		}

		return Price_Tiers::sanitize_tiers( $rows );
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
