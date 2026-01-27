<?php

/**
 * Handles Booking post type admin UI and meta persistence.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use VKBookingManager\Bookings\Customer_Name_Resolver;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;
use WP_Query;
use WP_User;
use function admin_url;
use function get_userdata;
use function get_users;
use function vkbm_get_resource_label_singular;
use function vkbm_normalize_reservation_page_url;
use function number_format_i18n;

/**
 * Handles Booking post type admin UI and meta persistence.
 */
class Booking_Admin {
	private const NONCE_ACTION = 'vkbm_booking_meta';
	private const NONCE_NAME   = '_vkbm_booking_meta_nonce';

	private const META_DATE_START             = '_vkbm_booking_service_start';
	private const META_DATE_END               = '_vkbm_booking_service_end';
	private const META_RESOURCE_ID            = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID             = '_vkbm_booking_service_id';
	private const META_CUSTOMER               = '_vkbm_booking_customer_name';
	private const META_CUSTOMER_TEL           = '_vkbm_booking_customer_tel';
	private const META_CUSTOMER_MAIL          = '_vkbm_booking_customer_email';
	private const META_ATTACHMENTS            = '_vkbm_booking_attachment_ids';
	private const META_ATTACHMENT_UPLOAD_FLAG = '_vkbm_booking_uploaded_attachment';
	private const META_STATUS                 = '_vkbm_booking_status';
	private const META_NOTE                   = '_vkbm_booking_note';
	private const META_INTERNAL_NOTE          = '_vkbm_booking_internal_note';
	private const META_NOMINATION_FEE         = '_vkbm_booking_nomination_fee';
	private const META_BASE_TOTAL_PRICE       = '_vkbm_booking_base_total_price';
	private const META_BILLED_TOTAL_PRICE     = '_vkbm_booking_billed_total_price';
	private const META_IS_PREFERRED           = '_vkbm_booking_is_staff_preferred';
	private const META_TOTAL_END              = '_vkbm_booking_total_end';
	private const META_SERVICE_BASE_PRICE     = '_vkbm_booking_service_base_price';

	private const STATUS_CONFIRMED              = 'confirmed';
	private const STATUS_PENDING                = 'pending';
	private const STATUS_CANCELLED              = 'cancelled';
	private const STATUS_NO_SHOW                = 'no_show';
	private const ADMIN_NOTICE_QUERY_VAR        = 'vkbm_booking_staff_conflict';
	private const ADMIN_NOTICE_TRANSIENT_PREFIX = 'vkbm_booking_staff_conflict_';
	private const ADMIN_NOTICE_TYPE_ERROR       = 'error';
	private const ADMIN_NOTICE_TYPE_WARNING     = 'warning';

	/**
	 * Notification service.
	 *
	 * @var Booking_Notification_Service|null
	 */
	private $notification_service;

	/**
	 * Constructor.
	 *
	 * @param Booking_Notification_Service|null $notification_service Notification handler.
	 */
	public function __construct( Booking_Notification_Service $notification_service = null ) {
		$this->notification_service = $notification_service;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_action( 'save_post_' . Booking_Post_Type::POST_TYPE, array( $this, 'save_quick_edit' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_reservation_page_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_staff_conflict_notice' ) );
		add_filter( 'manage_' . Booking_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'register_columns' ) );
		add_action( 'manage_' . Booking_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . Booking_Post_Type::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_sortable_query' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_fields' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Booking_Post_Type::POST_TYPE, array( $this, 'remove_author_meta_box' ), 100 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'filter_booking_attachment_metadata' ), 10, 2 );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_booking_upload_quality' ), 10, 2 );
	}

	/**
	 * Register a "Reservation Page" submenu under the booking post type.
	 */
	public function register_reservation_page_menu(): void {
		$settings = ( new Settings_Repository() )->get_settings();
		$url      = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		$url      = vkbm_normalize_reservation_page_url( $url );

		if ( '' === $url ) {
			$url = admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-reservation-page-url' );
		}

		$parent_slug = 'edit.php?post_type=' . Booking_Post_Type::POST_TYPE;
		$menu_slug   = 'vkbm-reservation-page';

		add_submenu_page(
			$parent_slug,
			__( 'Reservation Page', 'vk-booking-manager' ),
			__( 'Reservation Page', 'vk-booking-manager' ),
			Capabilities::MANAGE_PROVIDER_SETTINGS,
			$menu_slug,
			'__return_null'
		);

		$this->replace_submenu_url( $parent_slug, $menu_slug, $url );
	}

	/**
	 * Replace submenu slug with an absolute URL for direct navigation.
	 *
	 * @param string $parent_slug Parent menu slug.
	 * @param string $menu_slug   Submenu slug to replace.
	 * @param string $url         Destination URL.
	 */
	private function replace_submenu_url( string $parent_slug, string $menu_slug, string $url ): void {
		if ( '' === $url ) {
			return;
		}

		global $submenu;
		if ( ! isset( $submenu[ $parent_slug ] ) ) {
			return;
		}

		foreach ( $submenu[ $parent_slug ] as $index => $item ) {
			if ( isset( $item[2] ) && $menu_slug === $item[2] ) {
				$submenu[ $parent_slug ][ $index ][2] = $url;
				break;
			}
		}
	}

	/**
	 * Enqueue admin assets for booking edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Booking_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'vkbm-booking-admin',
			plugins_url( 'assets/js/booking-admin.js', VKBM_PLUGIN_FILE ),
			array(),
			VKBM_VERSION,
			true
		);
	}

	/**
	 * Enqueue Quick Edit JS for booking list table.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_quick_edit_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Booking_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'vkbm-booking-quick-edit',
			plugins_url( 'assets/js/booking-quick-edit.js', VKBM_PLUGIN_FILE ),
			array( 'jquery', 'inline-edit-post' ),
			VKBM_VERSION,
			true
		);
	}

	/**
	 * Add booking meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'vkbm-booking-details',
			__( 'Reservation details', 'vk-booking-manager' ),
			array( $this, 'render_meta_box' ),
			Booking_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render booking meta box form.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$start                   = get_post_meta( $post->ID, self::META_DATE_START, true );
		$end                     = get_post_meta( $post->ID, self::META_DATE_END, true );
		$resource_id             = (int) get_post_meta( $post->ID, self::META_RESOURCE_ID, true );
		$service_id              = (int) get_post_meta( $post->ID, self::META_SERVICE_ID, true );
		$has_base_price_snapshot = metadata_exists( 'post', $post->ID, self::META_SERVICE_BASE_PRICE );
		$service_base_price      = $has_base_price_snapshot
			? (int) get_post_meta( $post->ID, self::META_SERVICE_BASE_PRICE, true )
			: ( $service_id > 0 ? max( 0, (int) get_post_meta( $service_id, '_vkbm_base_price', true ) ) : 0 );
		$customer                = (string) get_post_meta( $post->ID, self::META_CUSTOMER, true );
		$customer_tel            = (string) get_post_meta( $post->ID, self::META_CUSTOMER_TEL, true );
		$customer_mail           = (string) get_post_meta( $post->ID, self::META_CUSTOMER_MAIL, true );
		$status                  = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		$note                    = (string) get_post_meta( $post->ID, self::META_NOTE, true );
		$internal_note           = (string) get_post_meta( $post->ID, self::META_INTERNAL_NOTE, true );
		$nomination_fee          = (int) get_post_meta( $post->ID, self::META_NOMINATION_FEE, true );
		if ( ! Staff_Editor::is_enabled() ) {
			$nomination_fee = 0;
		}
		$has_base_total_price = metadata_exists( 'post', $post->ID, self::META_BASE_TOTAL_PRICE );
		$base_total_price     = $has_base_total_price
			? (int) get_post_meta( $post->ID, self::META_BASE_TOTAL_PRICE, true )
			: max( 0, (int) $service_base_price ) + max( 0, (int) $nomination_fee );
		if ( ! Staff_Editor::is_enabled() ) {
			$base_total_price = max( 0, (int) $service_base_price );
		}
		$has_billed_total_price = metadata_exists( 'post', $post->ID, self::META_BILLED_TOTAL_PRICE );
		$billed_total_price     = $has_billed_total_price ? (int) get_post_meta( $post->ID, self::META_BILLED_TOTAL_PRICE, true ) : '';
		$is_preferred           = '1' === (string) get_post_meta( $post->ID, self::META_IS_PREFERRED, true );
		$author_options         = $this->get_booking_author_options( $post );
		$attachment_ids         = $this->normalize_attachment_ids( get_post_meta( $post->ID, self::META_ATTACHMENTS, true ) );
		$attachment_ids_csv     = implode( ',', $attachment_ids );

		$start_date = $this->format_datetime_for_input( $start, 'date' );
		$start_time = $this->format_datetime_for_input( $start, 'time' );
		$end_time   = $this->format_datetime_for_input( $end, 'time' );

		$resources        = $this->get_resources();
		$services         = $this->get_service_menus();
		$base_price_label = '—';
		if ( $has_base_price_snapshot || $service_id > 0 ) {
			$base_price_label = VKBM_Helper::format_currency( (int) $service_base_price );
		}
		$tax_label        = VKBM_Helper::get_tax_included_label();
		$base_total_label = '—';
		if ( $has_base_price_snapshot || $service_id > 0 ) {
			$base_total_label = VKBM_Helper::format_currency( (int) $base_total_price );
		}
		$effective_billed_total_price = $has_billed_total_price ? max( 0, (int) $billed_total_price ) : max( 0, (int) $base_total_price );
		$effective_billed_total_label = '—';
		if ( $has_base_price_snapshot || $service_id > 0 ) {
			$effective_billed_total_label = VKBM_Helper::format_currency( (int) $effective_billed_total_price );
		}
		?>
		<div class="vkbm-booking-meta">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="vkbm-booking-author"><?php esc_html_e( 'Reserver', 'vk-booking-manager' ); ?></label></th>
						<td>
							<select id="vkbm-booking-author" name="vkbm_booking[author_id]">
								<?php foreach ( $author_options as $author_id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $author_id ); ?>" <?php selected( $author_id, (int) $post->post_author ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( $post->post_author ) : ?>
								<?php
								$author_url = add_query_arg(
									array(
										'post_type' => Booking_Post_Type::POST_TYPE,
										'author'    => (int) $post->post_author,
									),
									admin_url( 'edit.php' )
								);
								?>
								<p class="description">
									<a class="button button-secondary" href="<?php echo esc_url( $author_url ); ?>">
										<?php esc_html_e( 'List of reservations made by this person', 'vk-booking-manager' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-status"><?php esc_html_e( 'Reservation status', 'vk-booking-manager' ); ?></label></th>
						<td>
							<select id="vkbm-booking-status" name="vkbm_booking[status]">
								<?php foreach ( $this->get_status_options() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-date"><?php esc_html_e( 'Reservation date', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input type="date" id="vkbm-booking-date" name="vkbm_booking[date]" value="<?php echo esc_attr( $start_date ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'time', 'vk-booking-manager' ); ?></th>
						<td>
							<label class="vkbm-booking-meta__time-label">
								<?php esc_html_e( 'start', 'vk-booking-manager' ); ?>
								<input type="time" name="vkbm_booking[start_time]" value="<?php echo esc_attr( $start_time ); ?>" />
							</label>
							&nbsp;
							<label class="vkbm-booking-meta__time-label">
								<?php esc_html_e( 'end', 'vk-booking-manager' ); ?>
								<input type="time" name="vkbm_booking[end_time]" value="<?php echo esc_attr( $end_time ); ?>" />
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="vkbm-booking-resource">
								<?php
								$singular = vkbm_get_resource_label_singular();
								printf(
									/* translators: %s: Resource label (singular). */
									esc_html__( 'Person in charge%s', 'vk-booking-manager' ),
									esc_html( $singular )
								);
								?>
							</label>
						</th>
						<td>
							<select id="vkbm-booking-resource" name="vkbm_booking[resource_id]">
								<option value="0">
									<?php
									$singular = vkbm_get_resource_label_singular();
									printf(
										/* translators: %s: Resource label (singular). */
										esc_html__( 'Select %s', 'vk-booking-manager' ),
										esc_html( $singular )
									);
									?>
								</option>
								<?php foreach ( $resources as $resource ) : ?>
									<option value="<?php echo esc_attr( (string) $resource->ID ); ?>" <?php selected( $resource_id, $resource->ID ); ?>>
										<?php echo esc_html( get_the_title( $resource ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Nomination category', 'vk-booking-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vkbm_booking[is_staff_preferred]" value="1" <?php checked( $is_preferred ); ?> />
								<?php
								$singular = vkbm_get_resource_label_singular();
								printf(
									/* translators: %s: Resource label (singular). */
									esc_html__( 'Reservation of %s nomination by customer', 'vk-booking-manager' ),
									esc_html( $singular )
								);
								?>
							</label>
							<p class="description">
								<?php
								$singular = vkbm_get_resource_label_singular();
								printf(
									/* translators: %s: Resource label (singular). */
									esc_html__( 'Checked when a customer makes a reservation by naming %s.', 'vk-booking-manager' ),
									esc_html( $singular )
								);
								?>
								<br />
								<?php esc_html_e( 'Prices are based on the "basic service fee", "Nomination fee", and "total basic fee" saved at the time of reservation. Please enter the "total billing amount" if necessary.', 'vk-booking-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Nomination fee', 'vk-booking-manager' ); ?></th>
						<td>
							<span class="vkbm-booking-meta__value">
								<?php
								echo esc_html( VKBM_Helper::format_currency( (int) $nomination_fee ) );
								?>
							</span>
							<?php
							if ( '' !== $tax_label ) {
								echo ' ' . esc_html( $tax_label );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-service"><?php esc_html_e( 'Menu', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input type="hidden" name="vkbm_booking[service_id]" value="<?php echo esc_attr( (string) $service_id ); ?>" />
							<label style="display:block; margin-bottom: 6px;">
								<input type="checkbox" id="vkbm-booking-allow-service-change" name="vkbm_booking[allow_service_change]" value="1" />
								<?php esc_html_e( 'Change menu (exception)', 'vk-booking-manager' ); ?>
							</label>
							<select id="vkbm-booking-service" name="vkbm_booking[service_id_select]" disabled>
								<option value="0"><?php esc_html_e( 'Select menu', 'vk-booking-manager' ); ?></option>
								<?php foreach ( $services as $service ) : ?>
									<option value="<?php echo esc_attr( (string) $service->ID ); ?>" <?php selected( $service_id, $service->ID ); ?>>
										<?php echo esc_html( get_the_title( $service ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'In principle, the menu selected at the time of reservation will not be changed. Please check and select only if you need to make changes (the price saved at the time of booking will not be changed).', 'vk-booking-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Service basic fee', 'vk-booking-manager' ); ?></th>
						<td>
							<span class="vkbm-booking-meta__value"><?php echo esc_html( $base_price_label ); ?></span>
							<?php
							if ( '' !== $tax_label ) {
								echo ' ' . esc_html( $tax_label );
							}
							?>
							<p class="description">
								<?php esc_html_e( 'This is the basic service charge at the time of reservation. (Cannot be edited)', 'vk-booking-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total basic fee', 'vk-booking-manager' ); ?></th>
						<td>
							<span class="vkbm-booking-meta__value"><?php echo esc_html( $base_total_label ); ?></span>
							<?php
							if ( '' !== $tax_label ) {
								echo ' ' . esc_html( $tax_label );
							}
							?>
							<p class="description">
								<?php esc_html_e( 'This is the total of the basic service fee + nomination fee at the time of reservation. (Cannot be edited)', 'vk-booking-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-billed-total-price"><?php esc_html_e( 'Total billing amount', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input
								type="number"
								id="vkbm-booking-billed-total-price"
								name="vkbm_booking[billed_total_price]"
								class="small-text"
								min="0"
								step="1"
								value="<?php echo esc_attr( '' === $billed_total_price ? '' : (string) max( 0, (int) $billed_total_price ) ); ?>"
								placeholder="<?php echo esc_attr( (string) max( 0, (int) $base_total_price ) ); ?>"
							/>
							<?php
							if ( '' !== $tax_label ) {
								echo ' ' . esc_html( $tax_label );
							}
							?>
							<p class="description">
								<?php esc_html_e( 'If there are any service changes or additional charges, please enter the final amount charged, including the nomination fee. If not entered, the total basic fee will be applied.', 'vk-booking-manager' ); ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: price amount */
									esc_html__( 'Current applicable amount (reference): %s', 'vk-booking-manager' ),
									esc_html( $effective_billed_total_label )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-customer"><?php esc_html_e( 'customer name', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input type="text" id="vkbm-booking-customer" name="vkbm_booking[customer]" value="<?php echo esc_attr( $customer ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-tel"><?php esc_html_e( 'telephone number', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input type="tel" id="vkbm-booking-tel" name="vkbm_booking[customer_tel]" value="<?php echo esc_attr( $customer_tel ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-email"><?php esc_html_e( 'email address', 'vk-booking-manager' ); ?></label></th>
						<td>
							<input type="email" id="vkbm-booking-email" name="vkbm_booking[customer_email]" value="<?php echo esc_attr( $customer_mail ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-attachments"><?php esc_html_e( 'Treatment image', 'vk-booking-manager' ); ?></label></th>
						<td>
							<div class="vkbm-booking-attachments">
								<ul class="vkbm-booking-attachments__list" data-remove-label="<?php esc_attr_e( 'delete', 'vk-booking-manager' ); ?>">
									<?php foreach ( $attachment_ids as $attachment_id ) : ?>
										<li class="vkbm-booking-attachments__item" data-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
											<?php
											echo wp_get_attachment_image(
												$attachment_id,
												'thumbnail',
												false,
												array(
													'class' => 'vkbm-booking-attachments__image',
													'data-full-url' => wp_get_attachment_image_url( $attachment_id, 'full' ) ? wp_get_attachment_image_url( $attachment_id, 'full' ) : '',
												)
											);
											?>
											<button type="button" class="vkbm-button vkbm-button__xs vkbm-button-outline vkbm-button-outline__danger vkbm-booking-attachments__remove">
												<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
								<div class="vkbm-booking-attachments__lightbox" hidden>
									<div class="vkbm-booking-attachments__lightbox-backdrop" data-lightbox-close="1"></div>
									<div class="vkbm-booking-attachments__lightbox-content" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'enlarge image', 'vk-booking-manager' ); ?>">
										<img class="vkbm-booking-attachments__lightbox-image" alt="" />
										<div class="vkbm-booking-attachments__lightbox-actions">
											<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__secondary vkbm-booking-attachments__lightbox-prev" data-lightbox-prev="1">
												<?php esc_html_e( 'Previous', 'vk-booking-manager' ); ?>
											</button>
											<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__secondary vkbm-booking-attachments__lightbox-next" data-lightbox-next="1">
												<?php esc_html_e( 'to the next', 'vk-booking-manager' ); ?>
											</button>
											<button type="button" class="vkbm-button vkbm-button__sm vkbm-button__secondary vkbm-booking-attachments__lightbox-close" data-lightbox-close="1">
												<?php esc_html_e( 'close', 'vk-booking-manager' ); ?>
											</button>
										</div>
									</div>
								</div>
								<input type="hidden" id="vkbm-booking-attachments" name="vkbm_booking[attachment_ids]" value="<?php echo esc_attr( $attachment_ids_csv ); ?>" />
								<button type="button" class="button vkbm-booking-attachments__add">
									<?php esc_html_e( 'addition', 'vk-booking-manager' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'You can register multiple images.', 'vk-booking-manager' ); ?><br /><?php esc_html_e( 'Images uploaded from here will be automatically converted and resized to jpg format.', 'vk-booking-manager' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-note"><?php esc_html_e( 'memo', 'vk-booking-manager' ); ?></label></th>
						<td>
							<textarea id="vkbm-booking-note" name="vkbm_booking[note]" rows="4" class="large-text"><?php echo esc_textarea( $note ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vkbm-booking-internal-note"><?php esc_html_e( 'Management memo', 'vk-booking-manager' ); ?></label></th>
						<td>
							<textarea id="vkbm-booking-internal-note" name="vkbm_booking[internal_note]" rows="3" class="large-text"><?php echo esc_textarea( $internal_note ); ?></textarea>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save booking meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS, $post_id ) ) {
			return;
		}

		$data = isset( $_POST['vkbm_booking'] ) && is_array( $_POST['vkbm_booking'] )
			? wp_unslash( $_POST['vkbm_booking'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Individual fields are sanitized below.
			: array();

		$previous_status = (string) get_post_meta( $post_id, self::META_STATUS, true );

		$date       = isset( $data['date'] ) ? $this->sanitize_date( $data['date'] ) : '';
		$start_time = isset( $data['start_time'] ) ? $this->sanitize_time( $data['start_time'] ) : '';
		$end_time   = isset( $data['end_time'] ) ? $this->sanitize_time( $data['end_time'] ) : '';

		$start = ( $date && $start_time ) ? $this->combine_datetime( $date, $start_time ) : '';
		$end   = ( $date && $end_time ) ? $this->combine_datetime( $date, $end_time ) : '';

		$resource_id          = isset( $data['resource_id'] ) ? $this->sanitize_resource_id( (int) $data['resource_id'] ) : 0;
		$service_id_locked    = isset( $data['service_id'] ) ? $this->sanitize_service_id( (int) $data['service_id'] ) : 0;
		$service_id_select    = isset( $data['service_id_select'] ) ? $this->sanitize_service_id( (int) $data['service_id_select'] ) : 0;
		$allow_service_change = isset( $data['allow_service_change'] );
		$service_id           = $allow_service_change ? $service_id_select : $service_id_locked;
		$customer             = isset( $data['customer'] ) ? sanitize_text_field( $data['customer'] ) : '';
		$customer_tel         = isset( $data['customer_tel'] ) ? sanitize_text_field( $data['customer_tel'] ) : '';
		$customer_mail        = isset( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : '';
		$billed_total_price   = array_key_exists( 'billed_total_price', $data )
			? $this->sanitize_base_price( $data['billed_total_price'] )
			: '';
		$status               = isset( $data['status'] ) ? $this->sanitize_status( (string) $data['status'] ) : self::STATUS_CONFIRMED;
		$note                 = isset( $data['note'] ) ? wp_kses_post( $data['note'] ) : '';
		$internal_note        = isset( $data['internal_note'] ) ? wp_kses_post( $data['internal_note'] ) : '';
		$is_preferred         = isset( $data['is_staff_preferred'] ) ? '1' : '';
		$author_id            = isset( $data['author_id'] ) ? absint( $data['author_id'] ) : 0;
		$author_id            = $this->sanitize_author_id( $author_id );
		$attachment_ids       = isset( $data['attachment_ids'] ) ? $this->normalize_attachment_ids( $data['attachment_ids'] ) : array();

		$has_conflict        = $this->has_staff_conflict( $post_id, $resource_id, $start, $end );
		$settings            = ( new Settings_Repository() )->get_settings();
		$allow_overlap_admin = ! empty( $settings['provider_allow_staff_overlap_admin'] );

		if ( $has_conflict && ! $allow_overlap_admin ) {
			$this->set_staff_conflict_notice( $post_id, $resource_id, self::ADMIN_NOTICE_TYPE_ERROR );
			return;
		}

		if ( $has_conflict && $allow_overlap_admin ) {
			$this->set_staff_conflict_notice( $post_id, $resource_id, self::ADMIN_NOTICE_TYPE_WARNING );
		}

		// 基本料金は管理画面から編集させない（POST値は信頼しない）.
		// 予約時点のスナップショットは後から更新しない（過去データで未保存の場合のみ補完する）.
		$service_base_price              = '';
		$should_fill_base_price_snapshot = ! metadata_exists( 'post', $post_id, self::META_SERVICE_BASE_PRICE );
		if ( $should_fill_base_price_snapshot && $service_id > 0 ) {
			$raw_price          = get_post_meta( $service_id, '_vkbm_base_price', true );
			$service_base_price = ( '' !== $raw_price && is_numeric( $raw_price ) ) ? max( 0, (int) $raw_price ) : '';
		}

		if ( $author_id > 0 && (int) $post->post_author !== $author_id ) {
			// Update booking author when a valid user is selected.
			// 有効なユーザーが選択された場合に予約投稿者を更新する.
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_author' => $author_id,
				)
			);
		}

		$this->update_meta_value( $post_id, self::META_DATE_START, $start );
		$this->update_meta_value( $post_id, self::META_DATE_END, $end );
		$this->update_meta_value( $post_id, self::META_TOTAL_END, $end );
		$this->update_meta_value( $post_id, self::META_RESOURCE_ID, $resource_id );
		$this->update_meta_value( $post_id, self::META_SERVICE_ID, $service_id );
		if ( $should_fill_base_price_snapshot ) {
			$this->update_meta_value_allow_zero( $post_id, self::META_SERVICE_BASE_PRICE, $service_base_price );
		}
		$should_fill_base_total_price = ! metadata_exists( 'post', $post_id, self::META_BASE_TOTAL_PRICE );
		if ( $should_fill_base_total_price ) {
			$base_price_for_total     = metadata_exists( 'post', $post_id, self::META_SERVICE_BASE_PRICE )
				? (int) get_post_meta( $post_id, self::META_SERVICE_BASE_PRICE, true )
				: ( '' === $service_base_price ? 0 : (int) $service_base_price );
			$nomination_fee_for_total = (int) get_post_meta( $post_id, self::META_NOMINATION_FEE, true );
			if ( ! Staff_Editor::is_enabled() ) {
				$nomination_fee_for_total = 0;
			}
			$base_total_price = max( 0, $base_price_for_total + max( 0, $nomination_fee_for_total ) );
			$this->update_meta_value_allow_zero( $post_id, self::META_BASE_TOTAL_PRICE, $base_total_price );
		}
		$this->update_meta_value_allow_zero( $post_id, self::META_BILLED_TOTAL_PRICE, $billed_total_price );
		$this->update_meta_value( $post_id, self::META_CUSTOMER, $customer );
		$this->update_meta_value( $post_id, self::META_CUSTOMER_TEL, $customer_tel );
		$this->update_meta_value( $post_id, self::META_CUSTOMER_MAIL, $customer_mail );
		$this->update_attachment_meta( $post_id, $attachment_ids );
		$this->maybe_optimize_booking_attachments( $attachment_ids );
		$this->update_meta_value( $post_id, self::META_STATUS, $status );
		$this->update_meta_value( $post_id, self::META_NOTE, $note );
		$this->update_meta_value( $post_id, self::META_INTERNAL_NOTE, $internal_note );
		$this->update_meta_value( $post_id, self::META_IS_PREFERRED, $is_preferred );

		$this->maybe_update_post_title( $post_id, $post, $customer, $start );

		if ( $this->notification_service ) {
			$this->notification_service->handle_status_transition( $post_id, $previous_status, $status );
		}
	}

	/**
	 * Customize admin list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function register_columns( array $columns ): array {
		$new                              = array();
		$new['cb']                        = $columns['cb'] ?? '';
		$new['title']                     = __( 'Reservation title', 'vk-booking-manager' );
		$new['vkbm_booking_datetime']     = __( 'Reservation date and time', 'vk-booking-manager' );
		$new['vkbm_booking_service']      = __( 'Menu', 'vk-booking-manager' );
		$new['vkbm_booking_resource']     = __( 'In charge', 'vk-booking-manager' );
		$new['vkbm_booking_status']       = __( 'Reservation status', 'vk-booking-manager' );
		$new['vkbm_booking_billed_total'] = __( 'Total billing amount', 'vk-booking-manager' );

		return $new;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'vkbm_booking_datetime':
				$start = get_post_meta( $post_id, self::META_DATE_START, true );
				$end   = get_post_meta( $post_id, self::META_DATE_END, true );

				if ( $start ) {
					echo esc_html( $this->format_datetime_admin( $start ) );
					if ( $end ) {
						echo esc_html( ' - ' . $this->format_datetime_admin( $end, 'time' ) );
					}
				} else {
					esc_html_e( 'Not set', 'vk-booking-manager' );
				}
				break;

			case 'vkbm_booking_resource':
				$resource_id = (int) get_post_meta( $post_id, self::META_RESOURCE_ID, true );
				if ( $resource_id ) {
					$resource = get_post( $resource_id );
					echo esc_html( $resource ? $resource->post_title : __( 'Not clear', 'vk-booking-manager' ) );
				} else {
					esc_html_e( 'Not set', 'vk-booking-manager' );
				}
				break;
			case 'vkbm_booking_service':
				$service_id = (int) get_post_meta( $post_id, self::META_SERVICE_ID, true );
				if ( $service_id ) {
					$service = get_post( $service_id );
					echo esc_html( $service ? $service->post_title : __( 'Not clear', 'vk-booking-manager' ) );
				} else {
					esc_html_e( 'Not set', 'vk-booking-manager' );
				}
				break;

			case 'vkbm_booking_status':
				$status  = (string) get_post_meta( $post_id, self::META_STATUS, true );
				$options = $this->get_status_options();
				echo esc_html( $options[ $status ] ?? __( 'Not clear', 'vk-booking-manager' ) );
				printf(
					'<span class="vkbm-booking-qe" data-status="%s"></span>',
					esc_attr( $status )
				);
				break;
			case 'vkbm_booking_billed_total':
				$has_base_total = metadata_exists( 'post', $post_id, self::META_BASE_TOTAL_PRICE );
				$base_total     = $has_base_total
					? (int) get_post_meta( $post_id, self::META_BASE_TOTAL_PRICE, true )
					: ( (int) get_post_meta( $post_id, self::META_SERVICE_BASE_PRICE, true ) + (int) get_post_meta( $post_id, self::META_NOMINATION_FEE, true ) );
				if ( ! Staff_Editor::is_enabled() ) {
					$base_total = (int) get_post_meta( $post_id, self::META_SERVICE_BASE_PRICE, true );
				}

				$has_billed_total = metadata_exists( 'post', $post_id, self::META_BILLED_TOTAL_PRICE );
				$amount           = $has_billed_total ? (int) get_post_meta( $post_id, self::META_BILLED_TOTAL_PRICE, true ) : $base_total;
				$amount           = max( 0, (int) $amount );

				echo esc_html( VKBM_Helper::format_currency( (int) $amount ) );
				break;
		}
	}

	/**
	 * Mark sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['vkbm_booking_datetime'] = 'vkbm_booking_datetime';
		$columns['vkbm_booking_status']   = 'vkbm_booking_status';
		return $columns;
	}

	/**
	 * Adjust query for sortable columns.
	 *
	 * @param WP_Query $query Query.
	 */
	public function handle_sortable_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( Booking_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'vkbm_booking_datetime' === $orderby ) {
			$query->set( 'meta_key', self::META_DATE_START );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'vkbm_booking_status' === $orderby ) {
			$query->set( 'meta_key', self::META_STATUS );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Render Quick Edit fields for booking status.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 */
	public function render_quick_edit_fields( string $column_name, string $post_type ): void {
		if ( Booking_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		if ( 'vkbm_booking_status' !== $column_name ) {
			return;
		}

		// Render once (WordPress calls this per visible custom column).
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		wp_nonce_field( 'vkbm_booking_quick_edit', '_vkbm_booking_quick_nonce' );
		?>
		<fieldset class="inline-edit-col-right vkbm-booking-quick-edit">
			<div class="inline-edit-col">
				<div class="inline-edit-group">
					<label>
						<span class="title"><?php esc_html_e( 'Reservation status', 'vk-booking-manager' ); ?></span>
						<span class="input-text-wrap">
							<select name="vkbm_booking[status]" class="vkbm-qe-booking-status">
								<?php foreach ( $this->get_status_options() as $status_key => $label ) : ?>
									<option value="<?php echo esc_attr( $status_key ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</span>
					</label>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Persist Quick Edit submissions for booking status.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post instance.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function save_quick_edit( int $post_id, WP_Post $post, bool $update ): void {
		if ( ! $update ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( Booking_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_vkbm_booking_quick_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_vkbm_booking_quick_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
		if ( ! wp_verify_nonce( $nonce, 'vkbm_booking_quick_edit' ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_RESERVATIONS, $post_id ) ) {
			return;
		}

		$data = isset( $_POST['vkbm_booking'] ) && is_array( $_POST['vkbm_booking'] ) ? wp_unslash( $_POST['vkbm_booking'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above. Individual fields are sanitized below.
		if ( ! is_array( $data ) ) {
			return;
		}

		$status = isset( $data['status'] ) ? $this->sanitize_status( (string) $data['status'] ) : '';
		if ( '' === $status ) {
			return;
		}

		$this->update_meta_value( $post_id, self::META_STATUS, $status );
	}

	/**
	 * Combine date and time into a site timezone datetime string.
	 *
	 * @param string $date Date (Y-m-d).
	 * @param string $time Time (H:i).
	 * @return string
	 */
	private function combine_datetime( string $date, string $time ): string {
		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', "{$date} {$time}", $timezone );

		if ( ! $datetime instanceof DateTimeImmutable ) {
			return '';
		}

		return $datetime->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Update or delete meta value.
	 *
	 * @param int        $post_id Post ID.
	 * @param string     $meta_key Meta key.
	 * @param string|int $value Value.
	 */
	private function update_meta_value( int $post_id, string $meta_key, $value ): void {
		if ( '' === $value || 0 === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Update or delete meta value (0 is a valid value).
	 *
	 * @param int           $post_id  Post ID.
	 * @param string        $meta_key Meta key.
	 * @param string|int|'' $value    Value.
	 */
	private function update_meta_value_allow_zero( int $post_id, string $meta_key, $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Update attachment IDs for booking.
	 * 予約の添付画像IDを更新する。
	 *
	 * @param int        $post_id Post ID.
	 * @param array<int> $ids     Attachment IDs.
	 */
	private function update_attachment_meta( int $post_id, array $ids ): void {
		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::META_ATTACHMENTS );
			return;
		}

		// Store attachment IDs as an array for safe retrieval.
		// 添付画像IDは配列で保存して安全に取得する.
		update_post_meta( $post_id, self::META_ATTACHMENTS, $ids );
	}

	/**
	 * Normalize attachment IDs from stored meta or input string.
	 * 保存値や入力値から添付画像IDを正規化する。
	 *
	 * @param mixed $raw Raw value.
	 * @return array<int>
	 */
	private function normalize_attachment_ids( $raw ): array {
		$ids = array();

		if ( is_array( $raw ) ) {
			$ids = $raw;
		} elseif ( is_string( $raw ) ) {
			$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}

		$ids = array_map( 'absint', $ids );
		$ids = array_filter(
			$ids,
			static function ( int $id ): bool {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Optimize booking attachments on save.
	 * 予約の添付画像を保存時に最適化する。
	 *
	 * @param array<int> $attachment_ids Attachment IDs.
	 */
	private function maybe_optimize_booking_attachments( array $attachment_ids ): void {
		foreach ( $attachment_ids as $attachment_id ) {
			$this->maybe_convert_attachment_to_jpeg( $attachment_id );
		}
	}

	/**
	 * Convert booking attachment to JPEG (max 2000px) when needed.
	 * 必要に応じて予約添付画像をJPEGへ変換し、長辺2000pxに調整する。
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function maybe_convert_attachment_to_jpeg( int $attachment_id ): void {
		if ( ! get_post_meta( $attachment_id, self::META_ATTACHMENT_UPLOAD_FLAG, true ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$size = $editor->get_size();
		if ( ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
			return;
		}

		$width         = (int) $size['width'];
		$height        = (int) $size['height'];
		$max_edge      = 2000;
		$mime_type     = (string) get_post_mime_type( $attachment_id );
		$needs_convert = ! in_array( $mime_type, array( 'image/jpeg', 'image/jpg' ), true );
		$needs_resize  = $width > $max_edge || $height > $max_edge;

		if ( ! $needs_convert && ! $needs_resize ) {
			return;
		}

		$editor->set_quality( 50 );
		if ( $needs_resize ) {
			$resize_result = $editor->resize( $max_edge, $max_edge, false );
			if ( is_wp_error( $resize_result ) ) {
				return;
			}
		}

		$path_info = pathinfo( $file );
		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return;
		}

		$target_file = $needs_convert
			? $path_info['dirname'] . '/' . $path_info['filename'] . '.jpg'
			: $file;

		$saved = $editor->save( $target_file, 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! is_array( $saved ) ) {
			return;
		}

		if ( $needs_convert && $target_file !== $file ) {
			update_attached_file( $attachment_id, $target_file );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/jpeg',
				)
			);
			wp_delete_file( $file );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$metadata['width']  = isset( $saved['width'] ) ? (int) $saved['width'] : $width;
		$metadata['height'] = isset( $saved['height'] ) ? (int) $saved['height'] : $height;
		$filesize           = filesize( $target_file );
		if ( false !== $filesize ) {
			$metadata['filesize'] = (int) $filesize;
		}
		if ( $needs_convert ) {
			$metadata['file'] = _wp_relative_upload_path( $target_file );
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	/**
	 * Sanitize service base price input.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|string
	 */
	private function sanitize_base_price( $raw ) {
		if ( null === $raw ) {
			return '';
		}

		$raw = sanitize_text_field( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( ! is_numeric( $raw ) ) {
			return '';
		}

		return max( 0, (int) $raw );
	}

	/**
	 * Retrieve tax settings (enabled/rate).
	 *
	 * @return array{enabled:bool,rate:float}
	 */
	private function get_tax_settings(): array {
		return array(
			'enabled' => true,
			'rate'    => 0.0,
		);
	}

	/**
	 * Determine if staff already has another booking overlapping the slot.
	 *
	 * @param int    $post_id   Current booking post ID.
	 * @param int    $staff_id  Staff post ID.
	 * @param string $start_at  Slot start (Y-m-d H:i:s).
	 * @param string $end_at    Slot end (Y-m-d H:i:s).
	 * @return bool
	 */
	protected function has_staff_conflict( int $post_id, int $staff_id, string $start_at, string $end_at ): bool {
		if ( $staff_id <= 0 || '' === $start_at ) {
			return false;
		}

		if ( '' === $end_at ) {
			$end_at = $start_at;
		}

		// 同一スタッフの重複予約があるかを、現在の予約を除外してチェックする.
		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'post__not_in'   => array( $post_id ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_RESOURCE_ID,
						'value'   => $staff_id,
						'compare' => '=',
					),
					array(
						'key'     => self::META_STATUS,
						'value'   => array( self::STATUS_CONFIRMED, self::STATUS_PENDING ),
						'compare' => 'IN',
					),
					array(
						// 既存の予約開始が、現在の予約終了より前なら時間帯が重なる可能性がある.
						'key'     => self::META_DATE_START,
						'value'   => $end_at,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							// 既存の総終了時刻が、現在の予約開始より後なら時間帯が重なる.
							'key'     => self::META_TOTAL_END,
							'value'   => $start_at,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							// 総終了がなければ通常の終了時刻で判定する.
							'key'     => self::META_DATE_END,
							'value'   => $start_at,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Persist staff conflict notice and enqueue redirect flag.
	 *
	 * @param int    $post_id     Booking post ID.
	 * @param int    $staff_id    Selected staff ID.
	 * @param string $notice_type Notice type.
	 */
	private function set_staff_conflict_notice( int $post_id, int $staff_id, string $notice_type ): void {
		$label = vkbm_get_resource_label_singular();
		$name  = $staff_id > 0 ? (string) get_the_title( $staff_id ) : '';

		if ( '' !== $name ) {
			$message = sprintf(
				/* translators: 1: resource label, 2: resource name */
				__( 'The selected %1$s (%2$s) cannot be specified because there is another reservation in the same time slot.', 'vk-booking-manager' ),
				$label,
				$name
			);
		} else {
			$message = sprintf(
				/* translators: %s: resource label */
				__( 'The selected %1$s cannot be specified because there is another reservation in the same time slot.', 'vk-booking-manager' ),
				$label
			);
		}

		if ( self::ADMIN_NOTICE_TYPE_WARNING === $notice_type ) {
			if ( '' !== $name ) {
				$message = sprintf(
					/* translators: 1: resource label, 2: resource name */
					__( 'This %1$s (%2$s) has another reservation for the same time slot.', 'vk-booking-manager' ),
					$label,
					$name
				);
			} else {
				$message = sprintf(
					/* translators: %s: resource label */
					__( 'This %1$s has other reservations for the same time slot.', 'vk-booking-manager' ),
					$label
				);
			}
		}

		$user_id = get_current_user_id();
		$key     = self::ADMIN_NOTICE_TRANSIENT_PREFIX . $user_id . '_' . $post_id;
		set_transient(
			$key,
			array(
				'message' => $message,
				'type'    => $notice_type,
			),
			30
		);

		add_filter(
			'redirect_post_location',
			static function ( string $location ) use ( $post_id ): string {
				return add_query_arg( self::ADMIN_NOTICE_QUERY_VAR, (string) $post_id, $location );
			}
		);
	}

	/**
	 * Render staff conflict notice on booking edit screen.
	 */
	public function render_staff_conflict_notice(): void {
		if ( empty( $_GET[ self::ADMIN_NOTICE_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Booking_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = absint( $_GET[ self::ADMIN_NOTICE_QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post_id <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		$key     = self::ADMIN_NOTICE_TRANSIENT_PREFIX . $user_id . '_' . $post_id;
		$payload = get_transient( $key );
		if ( false === $payload ) {
			return;
		}

		delete_transient( $key );

		$message = is_array( $payload ) ? (string) ( $payload['message'] ?? '' ) : (string) $payload;
		$type    = is_array( $payload ) ? (string) ( $payload['type'] ?? self::ADMIN_NOTICE_TYPE_ERROR ) : self::ADMIN_NOTICE_TYPE_ERROR;
		if ( '' === $message ) {
			return;
		}

		$notice_class = self::ADMIN_NOTICE_TYPE_WARNING === $type ? 'notice-warning' : 'notice-error';

		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			esc_attr( $notice_class ),
			esc_html( $message )
		);
	}
	/**
	 * Attempt to update post title if empty.
	 *
	 * @param int     $post_id  Post ID.
	 * @param WP_Post $post     Post.
	 * @param string  $customer Customer name.
	 * @param string  $start    Start datetime string.
	 */
	private function maybe_update_post_title( int $post_id, WP_Post $post, string $customer, string $start ): void {
		if ( ! empty( $post->post_title ) ) {
			return;
		}

		$title_parts = array();

		if ( $customer ) {
			$title_parts[] = $customer;
		}

		if ( $start ) {
			$title_parts[] = $this->format_datetime_admin( $start );
		}

		if ( empty( $title_parts ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => implode( ' / ', $title_parts ),
			)
		);
	}

	/**
	 * Format stored datetime.
	 *
	 * @param string $value Datetime.
	 * @param string $mode  Display mode (datetime|time).
	 * @return string
	 */
	private function format_datetime_admin( string $value, string $mode = 'datetime' ): string {
		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( ! $datetime ) {
			return $value;
		}

		if ( 'time' === $mode ) {
			return $datetime->format( 'H:i' );
		}

		return $datetime->format( 'Y.n.j H:i' );
	}

	/**
	 * Format stored datetime for form input.
	 *
	 * @param string $value Datetime.
	 * @param string $part  date|time.
	 * @return string
	 */
	private function format_datetime_for_input( string $value, string $part ): string {
		if ( '' === $value ) {
			return '';
		}

		$timezone = wp_timezone();
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( ! $datetime ) {
			return '';
		}

		return 'date' === $part ? $datetime->format( 'Y-m-d' ) : $datetime->format( 'H:i' );
	}

	/**
	 * Sanitize date input.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private function sanitize_date( string $date ): string {
		$date = sanitize_text_field( $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		return $date;
	}

	/**
	 * Sanitize time string.
	 *
	 * @param string $time Time.
	 * @return string
	 */
	private function sanitize_time( string $time ): string {
		$time = sanitize_text_field( $time );

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return '';
		}

		return $time;
	}

	/**
	 * Ensure resource ID is valid.
	 *
	 * @param int $resource_id Resource post ID.
	 * @return int
	 */
	private function sanitize_resource_id( int $resource_id ): int {
		if ( $resource_id <= 0 ) {
			return 0;
		}

		$post = get_post( $resource_id );
		return ( $post && Resource_Post_Type::POST_TYPE === $post->post_type ) ? $resource_id : 0;
	}

	/**
	 * Ensure service menu ID is valid.
	 *
	 * @param int $service_id Service post ID.
	 * @return int
	 */
	private function sanitize_service_id( int $service_id ): int {
		if ( $service_id <= 0 ) {
			return 0;
		}

		$post = get_post( $service_id );
		return ( $post && Service_Menu_Post_Type::POST_TYPE === $post->post_type ) ? $service_id : 0;
	}

	/**
	 * Sanitize booking status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function sanitize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$options = array_keys( $this->get_status_options() );

		return in_array( $status, $options, true ) ? $status : self::STATUS_CONFIRMED;
	}

	/**
	 * Retrieve status labels.
	 *
	 * @return array<string, string>
	 */
	private function get_status_options(): array {
		return array(
			self::STATUS_CONFIRMED => __( 'Confirmed', 'vk-booking-manager' ),
			self::STATUS_PENDING   => __( 'Pending', 'vk-booking-manager' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'vk-booking-manager' ),
			self::STATUS_NO_SHOW   => __( 'No-show', 'vk-booking-manager' ),
		);
	}

	/**
	 * Fetch resource posts.
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_resources(): array {
		return get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Fetch service menu posts.
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_service_menus(): array {
		$posts = get_posts(
			array(
				'post_type'      => Service_Menu_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		return Service_Menu_Post_Type::sort_menus_by_group( $posts );
	}

	/**
	 * Remove default author meta box for booking posts.
	 */
	public function remove_author_meta_box(): void {
		remove_meta_box( 'authordiv', Booking_Post_Type::POST_TYPE, 'side' );
		remove_meta_box( 'authordiv', Booking_Post_Type::POST_TYPE, 'normal' );
		remove_meta_box( 'authordiv', Booking_Post_Type::POST_TYPE, 'advanced' );
	}

	/**
	 * Build booking author dropdown options.
	 *
	 * @param WP_Post $post Booking post.
	 * @return array<int, string>
	 */
	private function get_booking_author_options( WP_Post $post ): array {
		$roles      = wp_roles();
		$role_names = $roles ? array_keys( $roles->roles ) : array();
		$options    = array();
		$resolver   = new Customer_Name_Resolver();

		$users = get_users(
			array(
				'role__in'    => $role_names,
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$options[ (int) $user->ID ] = $this->resolve_author_label( $user, $resolver );
		}

		$selected_author_id = (int) $post->post_author;
		if ( $selected_author_id > 0 && ! isset( $options[ $selected_author_id ] ) ) {
			$selected_user = get_userdata( $selected_author_id );
			if ( $selected_user instanceof WP_User ) {
				$options[ (int) $selected_user->ID ] = $this->resolve_author_label( $selected_user, $resolver );
			}
		}

		return $options;
	}

	/**
	 * Resolve the author label with name priority.
	 *
	 * @param WP_User                $user     User instance.
	 * @param Customer_Name_Resolver $resolver Name resolver.
	 * @return string
	 */
	private function resolve_author_label( WP_User $user, Customer_Name_Resolver $resolver ): string {
		// Prefer full name > kana > display name, and fall back to user ID.
		// 姓名 > ふりがな > 表示名 を優先し、なければユーザーIDにする.
		$label = trim( $resolver->resolve_for_user( $user ) );
		if ( '' !== $label ) {
			return $label;
		}

		return (string) $user->ID;
	}

	/**
	 * Validate author ID exists.
	 *
	 * @param int $author_id Author ID.
	 * @return int
	 */
	private function sanitize_author_id( int $author_id ): int {
		if ( $author_id <= 0 ) {
			return 0;
		}

		return get_userdata( $author_id ) ? $author_id : 0;
	}

	/**
	 * Reduce booking upload quality for images.
	 *
	 * @param int    $quality   Default quality.
	 * @param string $mime_type Mime type.
	 * @return int
	 */
	public function filter_booking_upload_quality( int $quality, string $mime_type ): int {
		if ( ! $this->is_booking_upload_request() ) {
			return $quality;
		}

		if ( 'image/jpeg' !== $mime_type && 'image/jpg' !== $mime_type ) {
			return $quality;
		}

		return 50;
	}

	/**
	 * Resize large booking uploads to a maximum 2000px on the long edge.
	 *
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public function filter_booking_attachment_metadata( array $metadata, int $attachment_id ): array {
		if ( ! $this->is_booking_upload_request() ) {
			return $metadata;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $metadata;
		}

		$size = $editor->get_size();
		if ( ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
			return $metadata;
		}

		$width    = (int) $size['width'];
		$height   = (int) $size['height'];
		$max_edge = 2000;

		$editor->set_quality( 50 );

		if ( $width > $max_edge || $height > $max_edge ) {
			$resize_result = $editor->resize( $max_edge, $max_edge, false );
			if ( is_wp_error( $resize_result ) ) {
				return $metadata;
			}
		}

		$path_info = pathinfo( $file );
		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return $metadata;
		}

		$jpg_file = $path_info['dirname'] . '/' . $path_info['filename'] . '.jpg';
		$saved    = $editor->save( $jpg_file, 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! is_array( $saved ) ) {
			return $metadata;
		}

		if ( $jpg_file !== $file ) {
			update_attached_file( $attachment_id, $jpg_file );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/jpeg',
				)
			);
			wp_delete_file( $file );
			$metadata['file'] = _wp_relative_upload_path( $jpg_file );
		}

		$metadata['width']  = isset( $saved['width'] ) ? (int) $saved['width'] : $metadata['width'];
		$metadata['height'] = isset( $saved['height'] ) ? (int) $saved['height'] : $metadata['height'];
		$filesize           = filesize( $jpg_file );
		if ( false !== $filesize ) {
			$metadata['filesize'] = (int) $filesize;
		}

		update_post_meta( $attachment_id, self::META_ATTACHMENT_UPLOAD_FLAG, '1' );

		return $metadata;
	}

	/**
	 * Check whether the current upload is for booking attachments.
	 *
	 * @return bool
	 */
	private function is_booking_upload_request(): bool {
		if ( empty( $_REQUEST['vkbm_booking_upload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Uploads are validated by core.
			return false;
		}

		return '1' === sanitize_text_field( wp_unslash( $_REQUEST['vkbm_booking_upload'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Uploads are validated by core.
	}
}
