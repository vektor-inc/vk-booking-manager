<?php
/**
 * Registers the Service Menu custom post type.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use VKBookingManager\TermOrder\Term_Order_Manager;
use WP_Post;
use function add_action;
use function current_user_can;
use function get_current_screen;
use function get_term_meta;
use function get_the_terms;
use function is_wp_error;
use function register_post_meta;
use function register_rest_field;
use function wp_enqueue_script;
use function wp_enqueue_style;

/**
 * Registers the Service Menu custom post type and related taxonomy.
 */
class Service_Menu_Post_Type {
	public const POST_TYPE                         = 'vkbm_service_menu';
	public const TAXONOMY                          = 'vkbm_service_menu_tag';
	public const TAXONOMY_GROUP                    = 'vkbm_service_menu_group';
	private const TERM_GROUP_DISPLAY_MODE_META_KEY = 'vkbm_menu_group_display_mode';
	private const META_OTHER_CONDITIONS            = '_vkbm_other_conditions';
	private const META_STAFF_IDS                   = '_vkbm_staff_ids';
	private const META_RESERVATION_DAY_TYPE        = '_vkbm_reservation_day_type';
	private const META_DISABLE_NOMINATION_FEE      = '_vkbm_disable_nomination_fee';
	private const META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const META_ALLOW_MULTIPLE_GUESTS       = '_vkbm_allow_multiple_guests';
	private const META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';
	private const META_EXCLUSIVE_USER_SELECTABLE   = '_vkbm_exclusive_user_selectable';
	private const META_EXCLUSIVE_FEE_PER_PERSON    = '_vkbm_exclusive_fee_per_person';
	private const META_EXCLUSIVE_FEE_EXEMPT_GUESTS = '_vkbm_exclusive_fee_exempt_guests';
	private const META_PRICE_TIERS                 = '_vkbm_price_tiers';

	/**
	 * Staff title cache.
	 *
	 * @var array<int, string>
	 */
	private array $staff_title_cache = array();

	/**
	 * Hook registrations for the post type and taxonomy.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		// カスタムメタもリビジョンに保存・復元できるよう、対象メタキーを登録する。
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'filter_revision_meta_keys' ), 10, 2 );
		add_action( self::TAXONOMY_GROUP . '_add_form_fields', array( $this, 'render_group_term_add_fields' ) );
		add_action( self::TAXONOMY_GROUP . '_edit_form_fields', array( $this, 'render_group_term_edit_fields' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY_GROUP, array( $this, 'save_group_term_display_mode' ) );
		add_action( 'edited_' . self::TAXONOMY_GROUP, array( $this, 'save_group_term_display_mode' ) );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'filter_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_fields' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_assets' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_quick_edit' ), 20, 3 );
	}

	/**
	 * Register the Service Menu custom post type.
	 */
	public function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$labels = array(
			'name'                  => __( 'Service Menu', 'vk-booking-manager' ),
			'singular_name'         => __( 'Service Menu', 'vk-booking-manager' ),
			'menu_name'             => __( 'BM Service', 'vk-booking-manager' ),
			'name_admin_bar'        => __( 'Service Menu', 'vk-booking-manager' ),
			'add_new'               => __( 'New addition', 'vk-booking-manager' ),
			'add_new_item'          => __( 'Add service', 'vk-booking-manager' ),
			'edit_item'             => __( 'Edit service menu', 'vk-booking-manager' ),
			'new_item'              => __( 'New service menu', 'vk-booking-manager' ),
			'view_item'             => __( 'Display service menu', 'vk-booking-manager' ),
			'search_items'          => __( 'Search service menu', 'vk-booking-manager' ),
			'not_found'             => __( 'Service menu not found.', 'vk-booking-manager' ),
			'not_found_in_trash'    => __( 'There is no service menu in the trash can.', 'vk-booking-manager' ),
			'all_items'             => __( 'All services', 'vk-booking-manager' ),
			'archives'              => __( 'Service menu archive', 'vk-booking-manager' ),
			'attributes'            => __( 'Service menu attributes', 'vk-booking-manager' ),
			'insert_into_item'      => __( 'Insert into service menu', 'vk-booking-manager' ),
			'uploaded_to_this_item' => __( 'Upload to this service menu', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
			'has_archive'         => false,
			'hierarchical'        => false,
			'rewrite'             => false,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-clipboard',
			'capabilities'        => $this->get_post_type_capabilities(),
			'map_meta_cap'        => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Add custom columns to the admin list table.
	 *
	 * @param array<string, string> $columns Current column definitions.
	 * @return array<string, string>
	 */
	public function filter_admin_columns( array $columns ): array {
		if ( empty( $columns['title'] ) ) {
			return $columns;
		}

		$reordered = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}

			$reordered[ $key ] = $label;

			if ( 'title' !== $key ) {
				continue;
			}

			$reordered['vkbm_price'] = __( 'Fee', 'vk-booking-manager' );
			// Use the configurable duration label from provider settings.
			// 基本設定の所要時間ラベルを使用する。
			$reordered['vkbm_duration']             = vkbm_get_duration_label();
			$reordered['vkbm_reservation_deadline'] = __( 'Reservation deadline', 'vk-booking-manager' );
			$reordered['vkbm_buffer_after']         = __( 'Post-service buffer', 'vk-booking-manager' );
			if ( Staff_Editor::is_enabled() ) {
				// Only show the staff column when staff editor is enabled. / スタッフ編集が有効な場合のみスタッフ列を表示します.
				$reordered['vkbm_staff'] = __( 'Staff available', 'vk-booking-manager' );
			}
			// Use the configurable other conditions label from provider settings.
			// 基本設定のその他条件ラベルを使用する。
			$reordered['vkbm_other_conditions']     = vkbm_get_other_conditions_label();
			$reordered['vkbm_reservation_day_type'] = __( 'Reservation date', 'vk-booking-manager' );
		}

		return $reordered;
	}

	/**
	 * Render custom admin columns for the list table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Current post ID.
	 */
	public function render_admin_columns( string $column, int $post_id ): void {
		$price                          = (int) get_post_meta( $post_id, '_vkbm_base_price', true );
		$duration                       = (int) get_post_meta( $post_id, '_vkbm_duration_minutes', true );
		$buffer_meta                    = get_post_meta( $post_id, '_vkbm_buffer_after_minutes', true );
		$buffer_has_value               = '' !== $buffer_meta || metadata_exists( 'post', $post_id, '_vkbm_buffer_after_minutes' );
		$buffer                         = $buffer_has_value ? (int) $buffer_meta : 0;
		$reservation_deadline_meta      = get_post_meta( $post_id, '_vkbm_reservation_deadline_hours', true );
		$reservation_deadline_has_value = '' !== $reservation_deadline_meta || metadata_exists( 'post', $post_id, '_vkbm_reservation_deadline_hours' );
		$reservation_deadline           = $reservation_deadline_has_value ? (int) $reservation_deadline_meta : 0;
		$staff_ids                      = get_post_meta( $post_id, self::META_STAFF_IDS, true );
		$staff_ids                      = is_array( $staff_ids ) ? array_map( 'intval', $staff_ids ) : array();
		$staff_ids                      = array_values(
			array_filter(
				$staff_ids,
				static function ( int $staff_id ): bool {
					return $staff_id > 0;
				}
			)
		);
		$other_conditions               = get_post_meta( $post_id, self::META_OTHER_CONDITIONS, true );
		$other_conditions               = is_string( $other_conditions ) ? $other_conditions : '';
		$reservation_day_type           = (string) get_post_meta( $post_id, self::META_RESERVATION_DAY_TYPE, true );
		$disable_nomination_fee         = (string) get_post_meta( $post_id, self::META_DISABLE_NOMINATION_FEE, true );

		$data_price                  = $price > 0 ? (string) $price : '';
		$data_duration               = $duration > 0 ? (string) $duration : '';
		$data_buffer                 = $buffer_has_value ? (string) $buffer : '';
		$data_staff_ids              = wp_json_encode( $staff_ids );
		$data_other_conditions       = wp_json_encode( $other_conditions );
		$data_reservation_deadline   = $reservation_deadline_has_value ? (string) $reservation_deadline : '';
		$data_reservation_day_type   = $reservation_day_type;
		$data_disable_nomination_fee = '1' === $disable_nomination_fee ? '1' : '';

		switch ( $column ) {
			case 'vkbm_price':
				echo esc_html( $price > 0 ? number_format_i18n( $price ) : '—' );
				printf(
					'<span class="vkbm-service-menu-qe" style="display:none" data-base-price="%1$s" data-duration-minutes="%2$s" data-buffer-after-minutes="%3$s" data-staff-ids="%4$s" data-other-conditions="%5$s" data-reservation-deadline-hours="%6$s" data-reservation-day-type="%7$s" data-disable-nomination-fee="%8$s"></span>',
					esc_attr( $data_price ),
					esc_attr( $data_duration ),
					esc_attr( $data_buffer ),
					esc_attr( (string) $data_staff_ids ),
					esc_attr( (string) $data_other_conditions ),
					esc_attr( $data_reservation_deadline ),
					esc_attr( $data_reservation_day_type ),
					esc_attr( $data_disable_nomination_fee )
				);
				break;

			case 'vkbm_duration':
				echo esc_html(
					$duration > 0
						? sprintf(
							/* translators: %s: minutes. */
							__( '%s minutes', 'vk-booking-manager' ),
							number_format_i18n( $duration )
						)
						: '—'
				);
				printf(
					'<span class="vkbm-service-menu-qe" style="display:none" data-base-price="%1$s" data-duration-minutes="%2$s" data-buffer-after-minutes="%3$s" data-staff-ids="%4$s" data-other-conditions="%5$s" data-reservation-deadline-hours="%6$s" data-reservation-day-type="%7$s" data-disable-nomination-fee="%8$s"></span>',
					esc_attr( $data_price ),
					esc_attr( $data_duration ),
					esc_attr( $data_buffer ),
					esc_attr( (string) $data_staff_ids ),
					esc_attr( (string) $data_other_conditions ),
					esc_attr( $data_reservation_deadline ),
					esc_attr( $data_reservation_day_type ),
					esc_attr( $data_disable_nomination_fee )
				);
				break;

			case 'vkbm_reservation_deadline':
				$effective_deadline = $reservation_deadline_has_value
					? $reservation_deadline
					: $this->get_provider_reservation_deadline_default();
				echo esc_html(
					sprintf(
						/* translators: %s: hours. */
						__( '%s hours ago', 'vk-booking-manager' ),
						number_format_i18n( $effective_deadline )
					)
				);
				printf(
					'<span class="vkbm-service-menu-qe" style="display:none" data-base-price="%1$s" data-duration-minutes="%2$s" data-buffer-after-minutes="%3$s" data-staff-ids="%4$s" data-other-conditions="%5$s" data-reservation-deadline-hours="%6$s" data-reservation-day-type="%7$s" data-disable-nomination-fee="%8$s"></span>',
					esc_attr( $data_price ),
					esc_attr( $data_duration ),
					esc_attr( $data_buffer ),
					esc_attr( (string) $data_staff_ids ),
					esc_attr( (string) $data_other_conditions ),
					esc_attr( $reservation_deadline_has_value ? (string) $reservation_deadline : '' ),
					esc_attr( $data_reservation_day_type ),
					esc_attr( $data_disable_nomination_fee )
				);
				break;

			case 'vkbm_buffer_after':
				$effective_buffer = $buffer_has_value ? $buffer : $this->get_provider_buffer_after_default();
				echo esc_html(
					sprintf(
						/* translators: %s: buffer minutes. */
						__( '%s minutes', 'vk-booking-manager' ),
						number_format_i18n( $effective_buffer )
					)
				);
				printf(
					'<span class="vkbm-service-menu-qe" style="display:none" data-base-price="%1$s" data-duration-minutes="%2$s" data-buffer-after-minutes="%3$s" data-staff-ids="%4$s" data-other-conditions="%5$s" data-reservation-deadline-hours="%6$s" data-reservation-day-type="%7$s" data-disable-nomination-fee="%8$s"></span>',
					esc_attr( $data_price ),
					esc_attr( $data_duration ),
					esc_attr( $data_buffer ),
					esc_attr( (string) $data_staff_ids ),
					esc_attr( (string) $data_other_conditions ),
					esc_attr( $data_reservation_deadline ),
					esc_attr( $data_reservation_day_type ),
					esc_attr( $data_disable_nomination_fee )
				);
				break;

			case 'vkbm_staff':
				if ( ! Staff_Editor::is_enabled() ) {
					return;
				}

				if ( empty( $staff_ids ) ) {
					echo esc_html( '—' );
					break;
				}

				$staff_posts = get_posts(
					array(
						'post_type'      => Resource_Post_Type::POST_TYPE,
						'post_status'    => array( 'publish' ),
						'posts_per_page' => -1,
						'orderby'        => array(
							'menu_order' => 'ASC',
							'title'      => 'ASC',
						),
						'include'        => $staff_ids,
					)
				);

				$names = array_values(
					array_filter(
						array_map(
							static function ( WP_Post $post ): string {
								return get_the_title( $post );
							},
							array_filter(
								$staff_posts,
								static function ( $post ): bool {
									return $post instanceof WP_Post;
								}
							)
						),
						static function ( string $name ): bool {
							return '' !== $name;
						}
					)
				);

				echo $names ? wp_kses_post( implode( '<br>', array_map( 'esc_html', $names ) ) ) : esc_html( '—' );
				break;

			case 'vkbm_other_conditions':
				if ( '' === $other_conditions ) {
					echo esc_html( '—' );
				} else {
					$excerpt = wp_html_excerpt( wp_strip_all_tags( $other_conditions ), 80, '…' );
					echo esc_html( $excerpt );
				}
				printf(
					'<span class="vkbm-service-menu-qe" style="display:none" data-base-price="%1$s" data-duration-minutes="%2$s" data-buffer-after-minutes="%3$s" data-staff-ids="%4$s" data-other-conditions="%5$s" data-reservation-deadline-hours="%6$s" data-reservation-day-type="%7$s" data-disable-nomination-fee="%8$s"></span>',
					esc_attr( $data_price ),
					esc_attr( $data_duration ),
					esc_attr( $data_buffer ),
					esc_attr( (string) $data_staff_ids ),
					esc_attr( (string) $data_other_conditions ),
					esc_attr( $data_reservation_deadline ),
					esc_attr( $data_reservation_day_type ),
					esc_attr( $data_disable_nomination_fee )
				);
				break;
			case 'vkbm_reservation_day_type':
				if ( '' === $reservation_day_type ) {
					echo esc_html( '—' );
					break;
				}

				// 種別→ラベルの写像は共有ヘルパーに集約している（曜日指定・日付指定にも対応）。
				echo esc_html( Reservation_Day::label( $reservation_day_type ) );
				break;
		}
	}

	/**
	 * Retrieve the provider's default buffer setting.
	 *
	 * @return int
	 */
	private function get_provider_buffer_after_default(): int {
		static $default = null;

		if ( null !== $default ) {
			return $default;
		}

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$default    = isset( $settings['provider_service_menu_buffer_after_minutes'] ) ? (int) $settings['provider_service_menu_buffer_after_minutes'] : 0;

		return $default;
	}

	/**
	 * Retrieve the provider's default reservation deadline.
	 *
	 * @return int
	 */
	private function get_provider_reservation_deadline_default(): int {
		static $default = null;

		if ( null !== $default ) {
			return $default;
		}

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$default    = isset( $settings['provider_reservation_deadline_hours'] ) ? (int) $settings['provider_reservation_deadline_hours'] : 0;

		return $default;
	}

	/**
	 * Resolve staff title from cache.
	 *
	 * @param int $staff_id Staff post ID.
	 * @return string
	 */
	private function get_staff_title( int $staff_id ): string {
		if ( isset( $this->staff_title_cache[ $staff_id ] ) ) {
			return $this->staff_title_cache[ $staff_id ];
		}

		$post = get_post( $staff_id );
		if ( ! $post instanceof WP_Post ) {
			$this->staff_title_cache[ $staff_id ] = '';
			return '';
		}

		$this->staff_title_cache[ $staff_id ] = vkbm_get_resource_display_name( $staff_id );

		return $this->staff_title_cache[ $staff_id ];
	}

	/**
	 * Render custom fields for Quick Edit.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 */
	public function render_quick_edit_fields( string $column_name, string $post_type ): void {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Render once (WordPress calls this per visible custom column).
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		$columns = array( 'vkbm_price', 'vkbm_duration', 'vkbm_buffer_after', 'vkbm_other_conditions' );
		if ( Staff_Editor::is_enabled() ) {
			$columns[] = 'vkbm_staff';
		}
		if ( ! in_array( $column_name, $columns, true ) ) {
			return;
		}

		$rendered = true;

		$staff_posts = array();
		if ( Staff_Editor::is_enabled() ) {
			$staff_posts = get_posts(
				array(
					'post_type'      => Resource_Post_Type::POST_TYPE,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			);
		}
		$tax_label = VKBM_Helper::get_tax_included_label();

		wp_nonce_field( 'vkbm_service_menu_quick_edit', '_vkbm_service_menu_quick_nonce' );
		?>
			<fieldset class="inline-edit-col-right vkbm-service-menu-quick-edit">
				<div class="inline-edit-col">
					<div class="inline-edit-group">
						<label>
							<span class="title">
								<?php
								esc_html_e( 'Price', 'vk-booking-manager' );
								if ( '' !== $tax_label ) {
									echo ' ' . esc_html( $tax_label );
								}
								?>
							</span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[base_price]" class="vkbm-qe-base-price" min="0" step="1" value="" />
							</span>
						</label>
						<?php if ( Staff_Editor::is_nomination_enabled() ) : ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_nomination_fee_label() ); ?></span>
							<span class="input-text-wrap">
								<label>
									<input type="checkbox" name="vkbm_service_menu_quick[disable_nomination_fee]" class="vkbm-qe-disable-nomination-fee" value="1" />
									<?php esc_html_e( 'Disable for this service menu', 'vk-booking-manager' ); ?>
								</label>
							</span>
						</label>
						<?php endif; ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_duration_label() ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[duration_minutes]" class="vkbm-qe-duration-minutes" min="0" step="1" value="" /> <?php esc_html_e( 'minutes', 'vk-booking-manager' ); ?>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Reservation deadline', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[reservation_deadline_hours]" class="vkbm-qe-reservation-deadline-hours" min="0" step="1" value="" /> <?php esc_html_e( 'hours ago', 'vk-booking-manager' ); ?>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Post-service buffer', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<input type="number" name="vkbm_service_menu_quick[buffer_after_minutes]" class="vkbm-qe-buffer-after-minutes" min="0" step="1" value="" /> <?php esc_html_e( 'minutes', 'vk-booking-manager' ); ?>
							</span>
							<p class="description">
								<?php esc_html_e( 'If it is left blank, the information entered on the basic settings screen will be reflected.', 'vk-booking-manager' ); ?>
							</p>
						</label>
						<?php if ( Staff_Editor::is_enabled() ) : ?>
							<label>
								<span class="title"><?php esc_html_e( 'Staff available', 'vk-booking-manager' ); ?></span>
								<span class="input-text-wrap">
									<ul class="vkbm-qe-staff-checkboxes">
										<?php foreach ( $staff_posts as $staff_post ) : ?>
											<?php if ( ! $staff_post instanceof WP_Post ) : ?>
												<?php continue; ?>
											<?php endif; ?>
											<li>
												<label>
													<input type="checkbox" name="vkbm_service_menu_quick[staff_ids][]" class="vkbm-qe-staff-id" value="<?php echo esc_attr( (string) $staff_post->ID ); ?>" />
													<?php echo esc_html( get_the_title( $staff_post ) ); ?>
												</label>
											</li>
										<?php endforeach; ?>
									</ul>
								</span>
							</label>
						<?php endif; ?>
						<label>
							<span class="title"><?php echo esc_html( vkbm_get_other_conditions_label() ); ?></span>
							<span class="input-text-wrap">
								<textarea name="vkbm_service_menu_quick[other_conditions]" class="vkbm-qe-other-conditions" rows="6"></textarea>
							</span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Reservation date', 'vk-booking-manager' ); ?></span>
							<span class="input-text-wrap">
								<select name="vkbm_service_menu_quick[reservation_day_type]" class="vkbm-qe-reservation-day-type">
									<option value=""><?php esc_html_e( 'Not specified', 'vk-booking-manager' ); ?></option>
									<option value="weekend"><?php esc_html_e( 'Saturdays and Sundays only', 'vk-booking-manager' ); ?></option>
									<option value="weekday"><?php esc_html_e( 'Weekdays only', 'vk-booking-manager' ); ?></option>
									<?php
									// 曜日指定・日付指定は詳細設定が必要でクイック編集では設定できない。
									// 既定は disabled にして新規選択を防ぎ（詳細未設定のまま選ぶと保存時に無通知で
									// 指定なしへ戻るのを防ぐ）、現在値が custom のメニューでのみ JS で有効化して
									// 既存値を表示・保持できるようにする。title で理由を説明する。
									$custom_option_title = esc_attr__( 'This option requires detailed settings. Edit it in the full service menu editor.', 'vk-booking-manager' );
									?>
									<option value="custom_weekday" disabled title="<?php echo esc_attr( $custom_option_title ); ?>"><?php esc_html_e( 'Specified days of the week', 'vk-booking-manager' ); ?></option>
									<option value="custom_date" disabled title="<?php echo esc_attr( $custom_option_title ); ?>"><?php esc_html_e( 'Specified dates', 'vk-booking-manager' ); ?></option>
								</select>
							</span>
						</label>
					</div>
				</div>
			</fieldset>
			<?php
	}

	/**
	 * Enqueue Quick Edit JS for service menu list table.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_quick_edit_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script(
			'vkbm-service-menu-quick-edit',
			VKBM_PLUGIN_DIR_URL . 'assets/js/service-menu-quick-edit.js',
			array( 'jquery', 'inline-edit-post' ),
			VKBM_VERSION,
			true
		);
	}

	/**
	 * Persist Quick Edit submissions (price/duration/buffer).
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

		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['_vkbm_service_menu_quick_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verification just below.
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_vkbm_service_menu_quick_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
		if ( ! wp_verify_nonce( $nonce, 'vkbm_service_menu_quick_edit' ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS, $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['vkbm_service_menu_quick'] ) || ! is_array( $_POST['vkbm_service_menu_quick'] ) ) {
			return;
		}

		$base_price             = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['base_price'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['base_price'] ) ) : '' );
		$duration               = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['duration_minutes'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['duration_minutes'] ) ) : '' );
		$buffer_after           = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['buffer_after_minutes'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['buffer_after_minutes'] ) ) : '' );
		$reservation_deadline   = $this->sanitize_numeric_value( isset( $_POST['vkbm_service_menu_quick']['reservation_deadline_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['reservation_deadline_hours'] ) ) : '' );
		$staff_ids              = Staff_Editor::is_enabled() ? $this->sanitize_staff_ids( isset( $_POST['vkbm_service_menu_quick']['staff_ids'] ) && is_array( $_POST['vkbm_service_menu_quick']['staff_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['vkbm_service_menu_quick']['staff_ids'] ) ) : array() ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by array_map( 'absint' ) and sanitize_staff_ids().
		$other_conditions       = isset( $_POST['vkbm_service_menu_quick']['other_conditions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vkbm_service_menu_quick']['other_conditions'] ) ) : '';
		$reservation_day_type   = $this->sanitize_reservation_day_type( isset( $_POST['vkbm_service_menu_quick']['reservation_day_type'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_service_menu_quick']['reservation_day_type'] ) ) : '', $post_id );
		$disable_nomination_fee = ! empty( $_POST['vkbm_service_menu_quick']['disable_nomination_fee'] ) ? '1' : '';

		$this->update_meta_value( $post_id, '_vkbm_base_price', $base_price );
		$this->update_meta_value( $post_id, '_vkbm_duration_minutes', $duration );
		$this->update_meta_value( $post_id, '_vkbm_buffer_after_minutes', $buffer_after );
		$this->update_meta_value( $post_id, '_vkbm_reservation_deadline_hours', $reservation_deadline );
		if ( null !== $staff_ids ) {
			$this->update_meta_value( $post_id, self::META_STAFF_IDS, $staff_ids, true );
		}
		$this->update_meta_value( $post_id, self::META_OTHER_CONDITIONS, $other_conditions );
		$this->update_meta_value( $post_id, self::META_RESERVATION_DAY_TYPE, $reservation_day_type );
		$this->update_meta_value( $post_id, self::META_DISABLE_NOMINATION_FEE, $disable_nomination_fee );
	}

	/**
	 * Sanitize numeric values. Returns empty string if non-numeric or blank.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private function sanitize_numeric_value( $raw ): string {
		$raw = trim( (string) $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		$value = max( 0, (int) $raw );

		return (string) $value;
	}

	/**
	 * Sanitize reservation day type.
	 *
	 * 許容値への正規化は共有ヘルパーに集約している。曜日指定・日付指定はクイック編集では
	 * 詳細設定を編集できないため、対応する詳細メタが既に保存されている場合のみ許可し、
	 * そうでなければ「指定なし」へ戻す（詳細未設定の custom 種別で全曜日不可になるのを防ぐ）。
	 *
	 * @param mixed $raw     Raw value.
	 * @param int   $post_id 対象の投稿ID（custom 種別の詳細メタ有無の確認に使用）。
	 * @return string
	 */
	private function sanitize_reservation_day_type( $raw, int $post_id = 0 ): string {
		$value = Reservation_Day::sanitize_type( sanitize_text_field( wp_unslash( (string) $raw ) ) );

		if ( Reservation_Day::TYPE_CUSTOM_WEEKDAY === $value ) {
			// メタキーは Reservation_Day を単一の定義元として参照する（保存側との drift 防止）。
			$config = get_post_meta( $post_id, Reservation_Day::META_CUSTOM_WEEKDAYS, true );
			if ( ! is_array( $config ) || empty( $config ) ) {
				return Reservation_Day::TYPE_NONE;
			}
		} elseif ( Reservation_Day::TYPE_CUSTOM_DATE === $value ) {
			$config = get_post_meta( $post_id, Reservation_Day::META_CUSTOM_DATES, true );
			if ( ! is_array( $config ) || empty( $config ) ) {
				return Reservation_Day::TYPE_NONE;
			}
		}

		return $value;
	}

	/**
	 * Update or delete post meta.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $meta_key    Meta key.
	 * @param mixed  $value       Value to store.
	 * @param bool   $allow_array Whether to allow array values.
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

	/**
	 * Sanitize staff ID array for quick edit.
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
	 * Register the taxonomy used to group service menus.
	 */
	public function register_taxonomy(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			$this->register_tag_taxonomy();
		}

		if ( ! taxonomy_exists( self::TAXONOMY_GROUP ) ) {
			$this->register_group_taxonomy();
		}
	}

	/**
	 * Register the taxonomy used to tag service menus.
	 */
	private function register_tag_taxonomy(): void {
		$labels = array(
			'name'              => __( 'Service Tag', 'vk-booking-manager' ),
			'singular_name'     => __( 'Service Tag', 'vk-booking-manager' ),
			'search_items'      => __( 'Find your service tag', 'vk-booking-manager' ),
			'all_items'         => __( 'All service tags', 'vk-booking-manager' ),
			'parent_item'       => __( 'Parent service tag', 'vk-booking-manager' ),
			'parent_item_colon' => __( 'Parent service tag:', 'vk-booking-manager' ),
			'edit_item'         => __( 'Edit service tag', 'vk-booking-manager' ),
			'update_item'       => __( 'Update service tag', 'vk-booking-manager' ),
			'add_new_item'      => __( 'Add service tag', 'vk-booking-manager' ),
			'new_item_name'     => __( 'New Service Tag Name', 'vk-booking-manager' ),
			'menu_name'         => __( 'Service Tag', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'capabilities'      => array(
				'manage_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'edit_terms'   => Capabilities::MANAGE_SERVICE_MENUS,
				'delete_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'assign_terms' => Capabilities::MANAGE_SERVICE_MENUS,
			),
		);

		register_taxonomy( self::TAXONOMY, self::POST_TYPE, $args );
	}

	/**
	 * Register the taxonomy used to group service menus.
	 */
	private function register_group_taxonomy(): void {
		$labels = array(
			'name'              => __( 'Service Menu Group', 'vk-booking-manager' ),
			'singular_name'     => __( 'Service Menu Group', 'vk-booking-manager' ),
			'search_items'      => __( 'Search service menu group', 'vk-booking-manager' ),
			'all_items'         => __( 'All service menu groups', 'vk-booking-manager' ),
			'parent_item'       => __( 'Parent service menu group', 'vk-booking-manager' ),
			'parent_item_colon' => __( 'Parent service menu group:', 'vk-booking-manager' ),
			'edit_item'         => __( 'Edit service menu group', 'vk-booking-manager' ),
			'update_item'       => __( 'Update service menu group', 'vk-booking-manager' ),
			'add_new_item'      => __( 'Add service menu group', 'vk-booking-manager' ),
			'new_item_name'     => __( 'New Service Menu Group Name', 'vk-booking-manager' ),
			'menu_name'         => __( 'Service Group', 'vk-booking-manager' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'capabilities'      => array(
				'manage_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'edit_terms'   => Capabilities::MANAGE_SERVICE_MENUS,
				'delete_terms' => Capabilities::MANAGE_SERVICE_MENUS,
				'assign_terms' => Capabilities::MANAGE_SERVICE_MENUS,
			),
		);

		register_taxonomy( self::TAXONOMY_GROUP, self::POST_TYPE, $args );
	}

	/**
	 * Render group display mode field on add form.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function render_group_term_add_fields( string $taxonomy ): void {
		if ( self::TAXONOMY_GROUP !== $taxonomy ) {
			return;
		}
		?>
		<div class="form-field term-display-mode-wrap">
			<label for="vkbm-menu-group-display-mode"><?php esc_html_e( 'Display mode when displaying all items', 'vk-booking-manager' ); ?></label>
			<select id="vkbm-menu-group-display-mode" name="vkbm_menu_group_display_mode">
				<option value="inherit"><?php esc_html_e( 'Use common settings', 'vk-booking-manager' ); ?></option>
				<option value="text"><?php esc_html_e( 'text', 'vk-booking-manager' ); ?></option>
				<option value="card"><?php esc_html_e( 'card', 'vk-booking-manager' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Applies only when the menu loop displays all groups.', 'vk-booking-manager' ); ?></p>
		</div>
		<?php
		wp_nonce_field( 'vkbm_menu_group_display_mode', 'vkbm_menu_group_display_mode_nonce' );
	}

	/**
	 * Render group display mode field on edit form.
	 *
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy slug.
	 */
	public function render_group_term_edit_fields( \WP_Term $term, string $taxonomy ): void {
		if ( self::TAXONOMY_GROUP !== $taxonomy ) {
			return;
		}

		$value = (string) get_term_meta( (int) $term->term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY, true );
		$value = '' === $value ? 'inherit' : $value;
		?>
		<tr class="form-field term-display-mode-wrap">
			<th scope="row"><label for="vkbm-menu-group-display-mode"><?php esc_html_e( 'Display mode when displaying all items', 'vk-booking-manager' ); ?></label></th>
			<td>
				<select id="vkbm-menu-group-display-mode" name="vkbm_menu_group_display_mode">
					<option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Use common settings', 'vk-booking-manager' ); ?></option>
					<option value="text" <?php selected( $value, 'text' ); ?>><?php esc_html_e( 'text', 'vk-booking-manager' ); ?></option>
					<option value="card" <?php selected( $value, 'card' ); ?>><?php esc_html_e( 'card', 'vk-booking-manager' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Applies only when the menu loop displays all groups.', 'vk-booking-manager' ); ?></p>
				<?php wp_nonce_field( 'vkbm_menu_group_display_mode', 'vkbm_menu_group_display_mode_nonce' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save display mode for menu group terms.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_group_term_display_mode( int $term_id ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SERVICE_MENUS ) ) {
			return;
		}

		$nonce = isset( $_POST['vkbm_menu_group_display_mode_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_menu_group_display_mode_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'vkbm_menu_group_display_mode' ) ) {
			return;
		}

		$raw_value = isset( $_POST['vkbm_menu_group_display_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['vkbm_menu_group_display_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$value     = sanitize_key( is_string( $raw_value ) ? $raw_value : '' );
		$allowed   = array( 'inherit', 'text', 'card' );

		if ( ! in_array( $value, $allowed, true ) ) {
			$value = 'inherit';
		}

		if ( 'inherit' === $value ) {
			delete_term_meta( $term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY );
		} else {
			update_term_meta( $term_id, self::TERM_GROUP_DISPLAY_MODE_META_KEY, $value );
		}

		wp_cache_delete( $term_id, 'term_meta' );
	}

	/**
	 * Register REST-exposed post meta.
	 */
	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			'_vkbm_base_price',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_price_meta' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_DISABLE_NOMINATION_FEE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// 最大同時予約人数（デフォルト1。Pro版で2以上に設定可能）。
		// Maximum simultaneous bookings per slot (default 1, configurable in Pro edition).
		register_post_meta(
			self::POST_TYPE,
			self::META_MAX_CAPACITY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 1,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					$int = (int) $value;
					return max( 1, $int );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// 最小催行人数（グループ開催型）。同じ時間枠の合計予約人数がこの値に達したら開催確定とみなす表示用設定。
		// デフォルト0は「制約なし（催行判定なし）」で従来挙動と完全互換。上限（max_capacity）との整合は
		// 保存ゲート（Service_Menu_Editor::save_post）でクランプする（register_meta では単体メタしか参照できないため）。
		register_post_meta(
			self::POST_TYPE,
			self::META_MIN_CAPACITY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（0=制約なし）。負値は0へ。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、save_post() と同じ業務ゲート（Pro版 かつ 指名OFF かつ 複数人予約ON）を
					// REST メタAPI経由の書き込みにも適用する。ゲート外で値を保持させると、後から
					// Pro版化・複数人予約有効化した際に意図せず催行判定が効き始めてしまうため、保存経路で防ぐ。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled() && Staff_Editor::is_slot_capacity_enabled();
				},
			)
		);

		// 複数人予約を許可するかどうか（Pro版・指名OFF時のみ有効。既定OFF）。
		register_post_meta(
			self::POST_TYPE,
			self::META_ALLOW_MULTIPLE_GUESTS,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、save_post() と同じゲート（Pro版 かつ 指名OFF）をREST書き込みにも適用する。
					// これにより REST メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// 貸し切り予約（予約が入ったらその時間帯を貸し切りにする）。複数人予約ON時のみ意味を持つ（既定OFF）。
		// ONのメニューでは予約確定時に予約レコードへ _vkbm_booking_exclusive が付与され、
		// 1件でも予約が入ると残り枠があっても他のユーザーは予約できなくなる（#304）。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_WHEN_BOOKED,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 複数人予約フラグ・料金区分と同じゲート（Pro版 かつ 指名OFF）を REST 書き込みに適用する。
					// メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					//
					// NOTE: ここでは _vkbm_allow_multiple_guests（保存済み値）は読まない。price_tiers と同様、
					// REST で「複数人予約ON＋貸し切りON」を同一リクエストで保存する際にメタの処理順序によって
					// stale な保存済み値で誤って拒否され得る（race）ため。複数人予約OFFのメニューに貸し切りメタが
					// 混入しても、受付停止の判定側 is_menu_exclusive_when_booked() がフルゲート（Pro＋全体の
					// 複数人予約ON＋指名OFF＋メニューの allow ON）を再適用して無害化する（load-bearing な主防御）。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// ユーザーによる貸し切り指定を受け付けるか（#305）。複数人予約ON時のみ意味を持つ（既定OFF）。
		// ONのメニューでは、予約者がフロントの予約画面で「この時間帯を貸切にする」を選択でき、
		// 選択された予約には _vkbm_booking_exclusive が付与され、以降その枠は他のユーザーが予約できなくなる。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_USER_SELECTABLE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): bool {
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 複数人予約フラグ・料金区分と同じゲート（Pro版 かつ 指名OFF）を REST 書き込みに適用する。
					// メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					// NOTE: ここでは保存済みの複数人予約フラグ（_vkbm_allow_multiple_guests）は読まない。
					// 料金区分・貸し切り設定と同様、同一リクエストで複数メタを保存する際の stale 値による
					// 誤拒否（race）を避けるため。複数人予約OFFのメニューにこのメタが混入しても、
					// フロント表示・サーバ側ガード（is_user_exclusive_selectable 相当のフルゲート再適用）で無害化する。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// 貸し切り料金（1人あたり・#305）。ユーザー貸し切り指定ONのメニューでのみ意味を持つ（既定0）。
		// 実際の課金額は「per_person × 申込人数」で、サーバ側（予約確定・下書き）で権威的に再計算する。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_FEE_PER_PERSON,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（負値は0）。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// 貸し切り料金を適用しない申込人数（#305）。この人数「以上」の申し込みでは貸し切り料金を加算しない。
		// 0（または空＝デフォルト0）は「上限なし＝常に加算」を意味する。ユーザー貸し切り指定ON時のみ意味を持つ。
		register_post_meta(
			self::POST_TYPE,
			self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): int {
					// 0以上の整数に丸める（0=上限なし＝常に加算）。負値は0へ。
					return max( 0, (int) $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		// 料金区分（大人料金・子供料金など）。複数人予約ON時のみ意味を持つ。
		// 1件でも定義すると基本料金×人数ではなく区分料金で計算する（併用なし）。
		register_post_meta(
			self::POST_TYPE,
			self::META_PRICE_TIERS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'array',
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'label' => array( 'type' => 'string' ),
								'price' => array( 'type' => 'integer' ),
							),
						),
						'context' => array( 'view', 'edit' ),
					),
				),
				'sanitize_callback' => static function ( $value ): array {
					return Price_Tiers::sanitize_tiers( $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					// 編集権限に加え、複数人予約フラグと同じゲート（Pro版 かつ 指名OFF）を REST 書き込みに適用する。
					// メタAPI経由で業務ロジック制約を迂回されるのを防ぐ（読み取りは show_in_rest で別途許可）。
					//
					// NOTE: ここで _vkbm_allow_multiple_guests（保存済み値）は読まない。
					// REST で「複数人予約ON＋料金区分」を同一リクエストで保存する際、メタの処理順序によっては
					// allow フラグ書き込み前に料金区分の auth が走り、stale な保存済み値で誤って拒否され得る（race）。
					// 複数人予約OFFのメニューに区分が混入しても、料金計算側がフルゲート（allow＋指名OFF＋Pro）を
					// 再適用して無害化するため、多層防御は計算側で担保される。
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
					return $is_pro && ! Staff_Editor::is_nomination_enabled();
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STAFF_IDS,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'array',
						'items'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'context' => array( 'view', 'edit' ),
					),
				),
				'sanitize_callback' => array( $this, 'sanitize_staff_ids' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * リビジョンに保存・復元するメタキーの一覧を返す。
	 *
	 * サービスメニューの設定値（料金・所要時間・スタッフ等）はすべてカスタムメタで管理しているため、
	 * リビジョンへ含めないとタイトル・本文のみが記録され、設定値の履歴が残らない。
	 * ここに列挙したキーは WordPress の標準リビジョン機構（WP 6.4 以降）によって
	 * 保存時にリビジョンへコピーされ、復元時に投稿へ書き戻される。
	 *
	 * 廃止済みのメタ（_vkbm_max_guests_per_booking）と旧仕様の互換用メタ（_vkbm_online_available）は
	 * 保存時に削除されるため、リビジョン対象には含めない。
	 *
	 * @return array<int, string> リビジョン対象のメタキー。
	 */
	public function get_revisioned_meta_keys(): array {
		return array(
			'_vkbm_base_price',
			self::META_DISABLE_NOMINATION_FEE,
			self::META_MAX_CAPACITY,
			self::META_MIN_CAPACITY,
			self::META_ALLOW_MULTIPLE_GUESTS,
			self::META_EXCLUSIVE_WHEN_BOOKED,
			self::META_EXCLUSIVE_USER_SELECTABLE,
			self::META_EXCLUSIVE_FEE_PER_PERSON,
			self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS,
			self::META_PRICE_TIERS,
			self::META_STAFF_IDS,
			'_vkbm_catch_copy',
			'_vkbm_internal_memo',
			'_vkbm_duration_minutes',
			'_vkbm_buffer_after_minutes',
			'_vkbm_reservation_deadline_hours',
			'_vkbm_max_advance_booking_days',
			self::META_RESERVATION_DAY_TYPE,
			'_vkbm_online_unavailable',
			'_vkbm_is_archived',
			'_vkbm_use_detail_page',
			self::META_OTHER_CONDITIONS,
			'_vkbm_fixed_start_times',
		);
	}

	/**
	 * サービスメニューのカスタムメタをリビジョン対象に追加する。
	 *
	 * `wp_post_revision_meta_keys` フィルターのコールバック。対象投稿タイプが
	 * サービスメニューのときのみ、このプラグインのメタキーを既存リストへ追加する。
	 *
	 * @param array<int, string> $keys      現在のリビジョン対象メタキー。
	 * @param string             $post_type 対象の投稿タイプ。
	 * @return array<int, string> 追加後のメタキー一覧（重複は排除）。
	 */
	public function filter_revision_meta_keys( array $keys, string $post_type ): array {
		// 対象外の投稿タイプでは何も変更しない。
		if ( self::POST_TYPE !== $post_type ) {
			return $keys;
		}

		// 既存のキーと統合し、重複を除いて返す。
		return array_values( array_unique( array_merge( $keys, $this->get_revisioned_meta_keys() ) ) );
	}

	/**
	 * Register REST-exposed fields for menu group ordering.
	 */
	public function register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE,
			'vkbm_menu_group',
			array(
				'get_callback' => array( $this, 'get_menu_group_rest_field' ),
				'schema'       => array(
					'description' => __( 'Primary service menu group information.', 'vk-booking-manager' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'id'    => array( 'type' => 'integer' ),
						'name'  => array( 'type' => 'string' ),
						'order' => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Resolve the primary group term for REST responses.
	 *
	 * @param array<string, mixed> $post REST post data.
	 * @return array<string, mixed>|null
	 */
	public function get_menu_group_rest_field( array $post ): ?array {
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		$terms = get_the_terms( $post_id, self::TAXONOMY_GROUP );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}

		$primary = self::resolve_primary_group_term( $terms );
		if ( ! $primary ) {
			return null;
		}

		return array(
			'id'    => (int) $primary->term_id,
			'name'  => (string) $primary->name,
			'order' => self::get_group_order_value( (int) $primary->term_id ),
		);
	}

	/**
	 * Sort service menus by group order and menu order.
	 *
	 * @param array<int, WP_Post> $posts Service menu posts.
	 * @return array<int, WP_Post>
	 */
	public static function sort_menus_by_group( array $posts ): array {
		$posts = array_values( $posts );
		$index = array();

		foreach ( $posts as $post ) {
			$terms   = get_the_terms( $post, self::TAXONOMY_GROUP );
			$primary = ( empty( $terms ) || is_wp_error( $terms ) )
				? null
				: self::resolve_primary_group_term( $terms );

			$index[ $post->ID ] = array(
				'group_order' => $primary ? self::get_group_order_value( (int) $primary->term_id ) : PHP_INT_MAX,
				'group_name'  => $primary ? (string) $primary->name : '',
				'has_group'   => $primary ? 1 : 0,
				'menu_order'  => (int) $post->menu_order,
				'title'       => (string) $post->post_title,
			);
		}

		usort(
			$posts,
			static function ( WP_Post $a, WP_Post $b ) use ( $index ): int {
				$meta_a = $index[ $a->ID ] ?? null;
				$meta_b = $index[ $b->ID ] ?? null;

				if ( ! $meta_a || ! $meta_b ) {
					return 0;
				}

				if ( $meta_a['group_order'] !== $meta_b['group_order'] ) {
					return $meta_a['group_order'] <=> $meta_b['group_order'];
				}

				if ( $meta_a['has_group'] !== $meta_b['has_group'] ) {
					return $meta_a['has_group'] > $meta_b['has_group'] ? -1 : 1;
				}

				$group_name_compare = strcmp( $meta_a['group_name'], $meta_b['group_name'] );
				if ( 0 !== $group_name_compare ) {
					return $group_name_compare;
				}

				if ( $meta_a['menu_order'] !== $meta_b['menu_order'] ) {
					return $meta_a['menu_order'] <=> $meta_b['menu_order'];
				}

				return strcmp( $meta_a['title'], $meta_b['title'] );
			}
		);

		return $posts;
	}

	/**
	 * Pick primary group term based on stored order (fallback: name).
	 *
	 * @param array<int, mixed> $terms Term list.
	 * @return object|null
	 */
	private static function resolve_primary_group_term( array $terms ): ?object {
		usort(
			$terms,
			static function ( $a, $b ): int {
				$order_a = self::get_group_order_value( (int) $a->term_id );
				$order_b = self::get_group_order_value( (int) $b->term_id );

				if ( $order_a !== $order_b ) {
					return $order_a <=> $order_b;
				}

				return strcmp( (string) $a->name, (string) $b->name );
			}
		);

		return $terms[0] ?? null;
	}

	/**
	 * Get group order value (smaller comes first).
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	private static function get_group_order_value( int $term_id ): int {
		$value = (string) get_term_meta( $term_id, Term_Order_Manager::META_KEY, true );
		$value = trim( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return PHP_INT_MAX;
		}

		return (int) $value;
	}

	/**
	 * Sanitize stored price meta.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_price_meta( $value ): int {
		if ( is_numeric( $value ) ) {
			$int_value = (int) $value;
			return $int_value > 0 ? $int_value : 0;
		}

		return 0;
	}

	/**
	 * Capability map for the post type.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_capabilities(): array {
		return array(
			'edit_post'              => Capabilities::MANAGE_SERVICE_MENUS,
			'read_post'              => Capabilities::VIEW_SERVICE_MENUS,
			'delete_post'            => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_posts'             => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_others_posts'      => Capabilities::MANAGE_SERVICE_MENUS,
			'publish_posts'          => Capabilities::MANAGE_SERVICE_MENUS,
			'read_private_posts'     => Capabilities::VIEW_SERVICE_MENUS,
			'delete_posts'           => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_private_posts'   => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_published_posts' => Capabilities::MANAGE_SERVICE_MENUS,
			'delete_others_posts'    => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_private_posts'     => Capabilities::MANAGE_SERVICE_MENUS,
			'edit_published_posts'   => Capabilities::MANAGE_SERVICE_MENUS,
			'create_posts'           => Capabilities::MANAGE_SERVICE_MENUS,
		);
	}
}
