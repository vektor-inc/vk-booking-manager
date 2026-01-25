<?php

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use DateTimeImmutable;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Bookings\Customer_Name_Resolver;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use WP_Query;

/**
 * Provides the Booking Manager shift dashboard.
 */
class Shift_Dashboard_Page {
	private const MENU_SLUG = 'vkbm-shift-dashboard';

	private const META_SHIFT_RESOURCE = '_vkbm_shift_resource_id';
	private const META_SHIFT_YEAR     = '_vkbm_shift_year';
	private const META_SHIFT_MONTH    = '_vkbm_shift_month';
	private const META_SHIFT_DAYS     = '_vkbm_shift_days';

	private const STATUS_OPEN             = 'open';
	private const STATUS_TEMPORARY_OPEN   = 'temporary_open';
	private const STATUS_REGULAR_HOLIDAY  = 'regular_holiday';
	private const STATUS_TEMPORARY_CLOSED = 'temporary_closed';
	private const STATUS_UNAVAILABLE      = 'unavailable';
	private const STATUS_NOT_SET          = 'not_set';

	/**
	 * Statuses that represent a closed/non-working day.
	 *
	 * @var array<int, string>
	 */
	private const CLOSED_STATUSES = [
		self::STATUS_REGULAR_HOLIDAY,
		self::STATUS_TEMPORARY_CLOSED,
		self::STATUS_UNAVAILABLE,
	];

	private const META_BOOKING_START      = '_vkbm_booking_service_start';
	private const META_BOOKING_END        = '_vkbm_booking_service_end';
	private const META_BOOKING_TOTAL_END  = '_vkbm_booking_total_end';
	private const META_BOOKING_RESOURCE   = '_vkbm_booking_resource_id';
	private const META_BOOKING_SERVICE  = '_vkbm_booking_service_id';
	private const META_BOOKING_CUSTOMER = '_vkbm_booking_customer_name';
	private const META_BOOKING_TEL      = '_vkbm_booking_customer_tel';
	private const META_BOOKING_EMAIL    = '_vkbm_booking_customer_email';
	private const META_BOOKING_STATUS   = '_vkbm_booking_status';
	private const META_BOOKING_NOTE     = '_vkbm_booking_note';

	private const BOOKING_STATUS_CONFIRMED = 'confirmed';
	private const BOOKING_STATUS_PENDING   = 'pending';
	private const BOOKING_STATUS_CANCELLED = 'cancelled';
	private const BOOKING_STATUS_NO_SHOW   = 'no_show';

	/**
	 * @var string
	 */
	private $capability;

	/**
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param string $capability Capability required to access the page.
	 */
	public function __construct( string $capability = 'manage_options' ) {
		$this->capability = $capability;
	}

	/**
	 * Register WordPress hooks for the dashboard page.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_vkbm_confirm_booking', [ $this, 'ajax_confirm_booking' ] );
	}

	/**
	 * Register the Booking Manager menu and dashboard page.
	 */
	public function register_menu(): void {
		$this->page_hook = add_menu_page(
			__( 'Shift/reservation table', 'vk-booking-manager' ),
			__( 'BM shift/reservation', 'vk-booking-manager' ),
			$this->capability,
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-calendar-alt',
			56
		);
	}

	/**
	 * Enqueue styles for the dashboard page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script(
			'vkbm-shift-dashboard-view-toggle',
			plugins_url( 'assets/js/shift-dashboard.js', dirname( __DIR__ ) ),
			[],
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-shift-dashboard-view-toggle',
			'vkbmShiftDashboard',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'confirmNonce' => wp_create_nonce( 'vkbm_confirm_booking' ),
				'i18n'        => [
					'confirm'   => __( 'Confirmed', 'vk-booking-manager' ),
					'confirming'=> __( 'Confirmation processing in progress...', 'vk-booking-manager' ),
					'error'     => __( 'Confirmation failed. Please try again later.', 'vk-booking-manager' ),
				],
			]
		);
	}

	/**
	 * Render the dashboard.
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		$reservation_page_url = $this->get_reservation_page_url();

		$selected_view = $this->get_selected_view();
		$selected_date = $this->get_selected_date();

		$year       = (int) $selected_date->format( 'Y' );
		$month      = (int) $selected_date->format( 'n' );
		$day        = (int) $selected_date->format( 'j' );
		$month_key  = $selected_date->format( 'Y-m' );
		$iso_date   = $selected_date->format( 'Y-m-d' );
		$date_format = get_option( 'date_format' );
		$day_label   = wp_date( $date_format, $selected_date->getTimestamp() );
		
		// Format month label based on locale
		// Japanese: "2026年2月", English: "February 2026"
		$locale = determine_locale();
		$month_format = ( 'ja' === substr( $locale, 0, 2 ) ) ? 'Y年n月' : 'F Y';
		$month_label  = wp_date( $month_format, $selected_date->getTimestamp() );

		$resources = $this->get_resource_posts();
		$shift_map = $this->get_shift_map( $resources, $year, $month );

		$resource_names = [];
		foreach ( $resources as $resource ) {
			$resource_names[ $resource->ID ] = get_the_title( $resource );
		}

		$bookings_map    = $this->get_bookings_for_day( $selected_date );
		$month_bookings  = $this->get_bookings_for_month( $year, $month );
		$pending_notifications = $this->get_pending_booking_notifications();

		$day_view   = $this->build_day_view_data( $resources, $shift_map, $bookings_map, $selected_date );
		$month_view = $this->build_month_view_data( $shift_map, $year, $month, count( $resources ), $resource_names, $month_bookings );

		$prev_day_url   = $this->get_dashboard_url(
			[
				'vkbm_date' => $selected_date->modify( '-1 day' )->format( 'Y-m-d' ),
				'vkbm_view' => 'day',
			]
		);
		$next_day_url   = $this->get_dashboard_url(
			[
				'vkbm_date' => $selected_date->modify( '+1 day' )->format( 'Y-m-d' ),
				'vkbm_view' => 'day',
			]
		);
		$prev_month_url = $this->get_dashboard_url(
			[
				'vkbm_date' => $selected_date->modify( 'first day of previous month' )->format( 'Y-m-d' ),
				'vkbm_view' => 'month',
			]
		);
		$next_month_url = $this->get_dashboard_url(
			[
				'vkbm_date' => $selected_date->modify( 'first day of next month' )->format( 'Y-m-d' ),
				'vkbm_view' => 'month',
			]
		);

		$day_button_is_active   = ( 'day' === $selected_view );
		$month_button_is_active = ( 'month' === $selected_view );

		$resource_count = max( 1, count( $day_view['resource_cards'] ) );

		$month_weekdays = [
			__( 'Mon', 'vk-booking-manager' ),
			__( 'Tue', 'vk-booking-manager' ),
			__( 'Wed', 'vk-booking-manager' ),
			__( 'Thu', 'vk-booking-manager' ),
			__( 'Fri', 'vk-booking-manager' ),
			__( 'Sat', 'vk-booking-manager' ),
			__( 'Sun', 'vk-booking-manager' ),
		];

		?>
		<div class="wrap vkbm-shift-dashboard">
			<div class="vkbm-shift-dashboard__heading">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Shift/reservation table', 'vk-booking-manager' ); ?></h1>
				<?php if ( '' !== $reservation_page_url ) : ?>
					<a class="button button-primary vkbm-shift-dashboard__reservation-link" href="<?php echo esc_url( $reservation_page_url ); ?>" rel="noopener noreferrer">
						<?php esc_html_e( 'Reservation Page', 'vk-booking-manager' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php do_action( 'vkbm_shift_dashboard_notices' ); ?>

			<div class="vkbm-shift-dashboard__view<?php echo $day_button_is_active ? ' is-active' : ''; ?>" data-vkbm-view-panel="day">
				<div class="vkbm-shift-dashboard__layout">
					<section class="vkbm-shift-dashboard__main vkbm-day-view">
						<header class="vkbm-calendar-header vkbm-day-view__header" data-vkbm-date="<?php echo esc_attr( $iso_date ); ?>">
							<div class="vkbm-calendar-header__nav">
								<span class="vkbm-calendar-header__label" data-vkbm-date-label aria-live="polite">
									<?php echo esc_html( $day_label ); ?>
								</span>
								<div class="vkbm-calendar-header__actions">
									<a class="button button-secondary" href="<?php echo esc_url( $prev_day_url ); ?>">
										<?php esc_html_e( 'Previous day', 'vk-booking-manager' ); ?>
									</a>
									<a class="button button-secondary" href="<?php echo esc_url( $next_day_url ); ?>">
										<?php esc_html_e( 'Next day', 'vk-booking-manager' ); ?>
									</a>
								</div>
							</div>
							<div class="vkbm-calendar-header__views" role="tablist">
								<button
									type="button"
									class="button button-secondary<?php echo $day_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="day"
									aria-pressed="<?php echo $day_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php esc_html_e( 'Sun', 'vk-booking-manager' ); ?>
								</button>
								<button
									type="button"
									class="button button-secondary<?php echo $month_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="month"
									aria-pressed="<?php echo $month_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?>
								</button>
							</div>
						</header>

						<div
							class="vkbm-day-view__content"
							style="--vkbm-resource-columns: <?php echo esc_attr( (string) $resource_count ); ?>;"
							data-vkbm-day="<?php echo esc_attr( $iso_date ); ?>"
						>
							<div class="vkbm-schedule-table" style="--vkbm-resource-columns: <?php echo esc_attr( (string) $resource_count ); ?>;">
								<div class="vkbm-schedule-table__inner">
									<header class="vkbm-timeline-header">
										<div class="vkbm-timeline-header__time-label"><?php esc_html_e( 'time', 'vk-booking-manager' ); ?></div>
										<?php foreach ( $day_view['resource_cards'] as $resource_card ) : ?>
											<div class="vkbm-timeline-header__resource">
												<div class="vkbm-resource-card vkbm-resource-card--<?php echo esc_attr( $resource_card['status_type'] ); ?>">
													<div class="vkbm-resource-card__meta">
														<span class="vkbm-resource-card__role"><?php echo esc_html( $resource_card['role'] ); ?></span>
														<span class="vkbm-resource-card__status"><?php echo esc_html( $resource_card['status'] ); ?></span>
													</div>
													<div class="vkbm-resource-card__name"><?php echo esc_html( $resource_card['name'] ); ?></div>
												</div>
											</div>
										<?php endforeach; ?>
									</header>

									<div class="vkbm-timeline-grid">
										<div class="vkbm-timeline-grid__axis">
											<?php foreach ( $day_view['timeline'] as $time_label ) : ?>
												<div class="vkbm-timeline-grid__time">
													<span><?php echo esc_html( $time_label ); ?></span>
												</div>
											<?php endforeach; ?>
										</div>

										<div class="vkbm-timeline-grid__lanes">
											<?php foreach ( $day_view['lanes'] as $lane ) : ?>
												<?php
												$lane_style = sprintf(
													'--timeline-start:%s; --timeline-end:%s;',
													esc_attr( (string) $lane['timeline_start'] ),
													esc_attr( (string) $lane['timeline_end'] )
												);
												?>
												<div class="vkbm-resource-lane" style="<?php echo esc_attr( $lane_style ); ?>">
													<?php if ( empty( $lane['shifts'] ) ) : ?>
														<div class="vkbm-lane-empty">
															<?php
															if ( $lane['is_closed'] ) {
																echo esc_html( $lane['status_label'] );
															} else {
																esc_html_e( 'Shift not set.', 'vk-booking-manager' );
															}
															?>
														</div>
													<?php endif; ?>

													<?php foreach ( $lane['shifts'] as $shift_index => $shift ) : ?>
														<?php
														$shift_classes = [ 'vkbm-shift-block' ];

														if ( 0 === $shift_index ) {
															$shift_classes[] = 'vkbm-shift-block--edge-top';
														}

														if ( ( $shift_index + 1 ) === count( $lane['shifts'] ) ) {
															$shift_classes[] = 'vkbm-shift-block--edge-bottom';
														}

														$shift_style = sprintf(
															'--start:%s; --end:%s;',
															esc_attr( (string) $shift['start'] ),
															esc_attr( (string) $shift['end'] )
														);
														?>
														<div class="<?php echo esc_attr( implode( ' ', $shift_classes ) ); ?>" style="<?php echo esc_attr( $shift_style ); ?>">
															<div class="vkbm-shift-block__label">
																<span class="vkbm-shift-block__time"><?php echo esc_html( $shift['time'] ); ?></span>
																<span class="vkbm-shift-block__status"><?php echo esc_html( $shift['status'] ); ?></span>
															</div>

															<?php if ( ! empty( $shift['bookings'] ) ) : ?>
																<div class="vkbm-bookings">
																	<?php foreach ( $shift['bookings'] as $booking ) : ?>
																		<?php
																		$booking_classes = [ 'vkbm-booking-card' ];
																		if ( ! empty( $booking['class'] ) ) {
																			$booking_classes[] = $booking['class'];
																		}

																		$booking_style = sprintf(
																			'--booking-start:%s; --booking-end:%s;',
																			esc_attr( (string) $booking['start_decimal'] ),
																			esc_attr( (string) $booking['end_decimal'] )
																		);
																		?>
																		<?php if ( ! empty( $booking['url'] ) ) : ?>
																			<a class="<?php echo esc_attr( implode( ' ', $booking_classes ) ); ?>" style="<?php echo esc_attr( $booking_style ); ?>" href="<?php echo esc_url( $booking['url'] ); ?>">
																		<?php else : ?>
																			<div class="<?php echo esc_attr( implode( ' ', $booking_classes ) ); ?>" style="<?php echo esc_attr( $booking_style ); ?>">
																		<?php endif; ?>
																			<?php if ( ! empty( $booking['time'] ) || ! empty( $booking['customer'] ) ) : ?>
																				<span class="vkbm-booking-card__summary">
																					<?php if ( ! empty( $booking['time'] ) ) : ?>
																						<span class="vkbm-booking-card__time"><?php echo esc_html( $booking['time'] ); ?></span>
																					<?php endif; ?>
																					<?php if ( ! empty( $booking['time'] ) && ! empty( $booking['customer'] ) ) : ?>
																						<span class="vkbm-booking-card__divider"> / </span>
																					<?php endif; ?>
																					<?php if ( ! empty( $booking['customer'] ) ) : ?>
																						<span class="vkbm-booking-card__customer"><?php echo esc_html( $booking['customer'] ); ?></span>
																					<?php endif; ?>
																				</span>
																			<?php endif; ?>

																			<?php if ( ! empty( $booking['service'] ) ) : ?>
																				<span class="vkbm-booking-card__service"><?php echo esc_html( $booking['service'] ); ?></span>
																			<?php endif; ?>
																		<?php if ( ! empty( $booking['url'] ) ) : ?>
																			</a>
																		<?php else : ?>
																			</div>
																		<?php endif; ?>
																	<?php endforeach; ?>
																</div>
															<?php endif; ?>
														</div>
													<?php endforeach; ?>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</div>
						</div>
					</section>
					<?php $this->render_pending_notifications_panel( $pending_notifications ); ?>
				</div>
			</div>

			<div class="vkbm-shift-dashboard__view<?php echo $month_button_is_active ? ' is-active' : ''; ?>" data-vkbm-view-panel="month">
				<div class="vkbm-shift-dashboard__layout">
					<section class="vkbm-month-view">
						<header class="vkbm-calendar-header vkbm-month-view__header" data-vkbm-month="<?php echo esc_attr( $month_key ); ?>">
							<div class="vkbm-calendar-header__nav">
								<span class="vkbm-calendar-header__label"><?php echo esc_html( $month_label ); ?></span>
								<div class="vkbm-calendar-header__actions">
									<a class="button button-secondary" href="<?php echo esc_url( $prev_month_url ); ?>">
										<?php
										$prev_month_text = __( 'previous month', 'vk-booking-manager' );
										// Capitalize first letter of each word for English locales
										$locale = determine_locale();
										if ( 'en' === substr( $locale, 0, 2 ) ) {
											$prev_month_text = ucwords( $prev_month_text );
										}
										echo esc_html( $prev_month_text );
										?>
									</a>
									<a class="button button-secondary" href="<?php echo esc_url( $next_month_url ); ?>">
										<?php
										$next_month_text = __( 'next month', 'vk-booking-manager' );
										// Capitalize first letter of each word for English locales
										if ( 'en' === substr( $locale, 0, 2 ) ) {
											$next_month_text = ucwords( $next_month_text );
										}
										echo esc_html( $next_month_text );
										?>
									</a>
								</div>
							</div>
							<div class="vkbm-calendar-header__views" role="tablist">
								<button
									type="button"
									class="button button-secondary<?php echo $day_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="day"
									aria-pressed="<?php echo $day_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php esc_html_e( 'Sun', 'vk-booking-manager' ); ?>
								</button>
								<button
									type="button"
									class="button button-secondary<?php echo $month_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="month"
									aria-pressed="<?php echo $month_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?>
								</button>
							</div>
						</header>

						<div class="vkbm-month-view__content">
							<div class="vkbm-month-calendar">
								<div class="vkbm-month-calendar__weekdays">
									<?php foreach ( $month_weekdays as $weekday ) : ?>
										<span class="vkbm-month-calendar__weekday"><?php echo esc_html( $weekday ); ?></span>
									<?php endforeach; ?>
								</div>
								<div class="vkbm-month-calendar__grid">
									<?php foreach ( $month_view['weeks'] as $week ) : ?>
										<?php foreach ( $week as $cell ) : ?>
											<?php
											$cell_classes = [ 'vkbm-month-calendar__cell' ];

											if ( ! empty( $cell['type'] ) ) {
												$cell_classes[] = 'vkbm-month-calendar__cell--' . $cell['type'];
											}
											?>
											<?php if ( '' !== (string) $cell['day'] ) : ?>
												<div class="<?php echo esc_attr( implode( ' ', $cell_classes ) ); ?>">
													<div class="vkbm-month-calendar__dayline">
														<?php if ( ! empty( $cell['url'] ) ) : ?>
															<a class="vkbm-month-calendar__day-link" href="<?php echo esc_url( $cell['url'] ); ?>">
																<?php echo esc_html( (string) $cell['day'] ); ?>
															</a>
														<?php else : ?>
															<span class="vkbm-month-calendar__day"><?php echo esc_html( (string) $cell['day'] ); ?></span>
														<?php endif; ?>
													</div>

													<?php if ( ! empty( $cell['entries'] ) ) : ?>
														<div class="vkbm-month-calendar__bookings">
															<?php foreach ( $cell['entries'] as $entry ) : ?>
																<?php
																$entry_classes = [ 'vkbm-month-booking' ];
																if ( ! empty( $entry['class'] ) ) {
																	$entry_classes[] = $entry['class'];
																}
																?>

																<?php if ( ! empty( $entry['url'] ) ) : ?>
																	<a class="<?php echo esc_attr( implode( ' ', $entry_classes ) ); ?>" href="<?php echo esc_url( $entry['url'] ); ?>">
																		<span class="vkbm-month-booking__time"><?php echo esc_html( $entry['time_line'] ); ?></span>
																		<span class="vkbm-month-booking__customer"><?php echo esc_html( $entry['customer_line'] ); ?></span>
																	</a>
																<?php else : ?>
																	<div class="<?php echo esc_attr( implode( ' ', $entry_classes ) ); ?>">
																		<span class="vkbm-month-booking__time"><?php echo esc_html( $entry['time_line'] ); ?></span>
																		<span class="vkbm-month-booking__customer"><?php echo esc_html( $entry['customer_line'] ); ?></span>
																	</div>
																<?php endif; ?>
															<?php endforeach; ?>
														</div>
													<?php endif; ?>
												</div>
											<?php else : ?>
												<div class="<?php echo esc_attr( implode( ' ', $cell_classes ) ); ?>"></div>
											<?php endif; ?>
										<?php endforeach; ?>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</section>
					<?php $this->render_pending_notifications_panel( $pending_notifications ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Determine the currently selected view (day/month).
	 *
	 * @return string
	 */
	private function get_selected_view(): string {
		if ( isset( $_GET['vkbm_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$view = sanitize_key( wp_unslash( $_GET['vkbm_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.

			if ( in_array( $view, [ 'day', 'month' ], true ) ) {
				return $view;
			}
		}

		return 'day';
	}

	/**
	 * Return the selected date as a DateTimeImmutable in site timezone.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_selected_date(): DateTimeImmutable {
		$timezone = wp_timezone();

		if ( isset( $_GET['vkbm_date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$date_string = sanitize_text_field( wp_unslash( $_GET['vkbm_date'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$date        = DateTimeImmutable::createFromFormat( 'Y-m-d', $date_string, $timezone );

			if ( $date instanceof DateTimeImmutable ) {
				return $date->setTime( 0, 0 );
			}
		}

		return ( new DateTimeImmutable( 'now', $timezone ) )->setTime( 0, 0 );
	}

	/**
	 * Retrieve all resource posts to be displayed on the dashboard.
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_resource_posts(): array {
		return get_posts(
			[
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish' ],
				'orderby'        => [
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);
	}

	/**
	 * Build a map of shift data for each resource for the requested month.
	 *
	 * @param array<int, WP_Post> $resources Resource posts.
	 * @param int                 $year      Target year.
	 * @param int                 $month     Target month (1-12).
	 * @return array<int, array<string, mixed>>
	 */
	private function get_shift_map( array $resources, int $year, int $month ): array {
		$map          = [];
		$resource_ids = [];

		foreach ( $resources as $resource ) {
			$resource_ids[]          = (int) $resource->ID;
			$map[ (int) $resource->ID ] = [
				'days' => [],
			];
		}

		if ( empty( $resource_ids ) ) {
			return $map;
		}

		$shift_posts = get_posts(
			[
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => self::META_SHIFT_YEAR,
						'value' => $year,
					],
					[
						'key'   => self::META_SHIFT_MONTH,
						'value' => $month,
					],
				],
			]
		);

		foreach ( $shift_posts as $shift_post ) {
			$resource_id = (int) get_post_meta( $shift_post->ID, self::META_SHIFT_RESOURCE, true );

			if ( ! in_array( $resource_id, $resource_ids, true ) ) {
				continue;
			}

			if ( ! isset( $map[ $resource_id ] ) ) {
				$map[ $resource_id ] = [
					'days' => [],
				];
			}

			$raw_days = get_post_meta( $shift_post->ID, self::META_SHIFT_DAYS, true );

			if ( is_array( $raw_days ) ) {
				$map[ $resource_id ]['days'] = $this->normalize_day_map( $raw_days );
			}
		}

		return $map;
	}

	/**
	 * Normalize the stored day map into a predictable structure.
	 *
	 * @param array<int|string, mixed> $days Raw map.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_day_map( array $days ): array {
		$normalized = [];

		foreach ( $days as $day => $entry ) {
			$day_number = (int) $day;

			if ( $day_number < 1 || $day_number > 31 ) {
				continue;
			}

			$status = self::STATUS_OPEN;
			$slots  = [];

			if ( is_array( $entry ) ) {
				if ( isset( $entry['status'] ) ) {
					$status_candidate = (string) $entry['status'];
					$status           = $status_candidate ?: self::STATUS_OPEN;
				}

				if ( isset( $entry['slots'] ) && is_array( $entry['slots'] ) ) {
					$slots = $entry['slots'];
				}
			}

			$normalized[ $day_number ] = [
				'status' => $status,
				'slots'  => $slots,
			];
		}

		return $normalized;
	}

	/**
	 * Assemble data required to render the day view.
	 *
	 * @param array<int, WP_Post>        $resources Resource posts.
	 * @param array<int, array>          $shift_map Month shift map.
	 * @param int                        $day       Selected day of month.
	 * @return array<string, mixed>
	 */
	private function build_day_view_data( array $resources, array $shift_map, array $bookings_map, DateTimeImmutable $date ): array {
		$timeline_start = null;
		$timeline_end   = null;
		$day_start      = $date->setTime( 0, 0 );
		$day            = (int) $date->format( 'j' );

		$resource_cards = [];
		$lanes          = [];

		foreach ( $resources as $resource ) {
			$resource_id = (int) $resource->ID;
			$days        = $shift_map[ $resource_id ]['days'] ?? [];
			$day_entry   = $days[ $day ] ?? null;
			$resource_bookings = $bookings_map[ $resource_id ] ?? [];
			$unassigned_bookings = $resource_bookings;

			$status_key = self::STATUS_NOT_SET;
			$slots      = [];

			if ( is_array( $day_entry ) ) {
				$status_key = (string) ( $day_entry['status'] ?? self::STATUS_OPEN );
				$slots      = $this->normalize_slots( $day_entry['slots'] ?? [] );
			}

			$shifts       = [];
			$total_hours  = 0.0;
			$last_end     = null;
			$slot_counter = 0;

			foreach ( $slots as $slot ) {
				$start = $this->time_to_decimal( $slot['start'] );
				$end   = $this->time_to_decimal( $slot['end'] );

				if ( null === $start || null === $end || $end <= $start ) {
					continue;
				}

				$timeline_start = null === $timeline_start ? $start : min( $timeline_start, $start );
				$timeline_end   = null === $timeline_end ? $end : max( $timeline_end, $end );

				$total_hours += ( $end - $start );
				$slot_counter++;
				$last_end = $end;

				$slot_bookings = $this->collect_bookings_for_slot( $unassigned_bookings, $start, $end );
				foreach ( $slot_bookings as $booking_slot ) {
					$timeline_start = min( $timeline_start, $booking_slot['start_decimal'] );
					$timeline_end   = max( $timeline_end, $booking_slot['end_decimal'] );
				}

				$booking_cards = array_map(
					function ( array $booking ) {
						return $this->map_booking_to_card( $booking );
					},
					$slot_bookings
				);

				$shifts[] = [
					'start'  => $start,
					'end'    => $end,
					'time'   => $this->format_time_range( $slot['start'], $slot['end'] ),
					'status' => $this->get_slot_status_label( $status_key ),
					'bookings' => $booking_cards,
				];
			}

			if ( null === $timeline_start || null === $timeline_end ) {
				$timeline_start = $timeline_start ?? 9.0;
				$timeline_end   = $timeline_end ?? 18.0;
			}

			foreach ( $resource_bookings as $booking ) {
				$timeline_start = min( $timeline_start, $booking['start_decimal'] );
				$timeline_end   = max( $timeline_end, $booking['end_decimal'] );
			}

			$status_info = $this->resolve_resource_status( $status_key, $slot_counter, $total_hours );

			$resource_cards[] = [
				'id'          => $resource_id,
				'name'        => get_the_title( $resource ),
				'role'        => $this->derive_resource_role( $resource ),
				'status'      => $status_info['label'],
				'status_type' => $status_info['type'],
			];

			$unassigned_bookings = array_values( $unassigned_bookings );

			foreach ( $unassigned_bookings as $booking_index => $booking ) {
				$booking_cards = [ $this->map_booking_to_card( $booking ) ];
				$shifts[]      = [
					'start'    => $booking['start_decimal'],
					'end'      => $booking['end_decimal'],
					'time'     => $booking['time_range'],
					'status'   => __( 'Reservation', 'vk-booking-manager' ),
					'bookings' => array_map(
						function ( array $card ) use ( $booking ) {
							$card['url'] = $booking['edit_url'] ?? '';
							return $card;
						},
						$booking_cards
					),
				];
				unset( $unassigned_bookings[ $booking_index ] );
			}

			$lanes[] = [
				'resource_id'    => $resource_id,
				'shifts'         => $shifts,
				'status_label'   => $status_info['label'],
				'is_closed'      => ( 'off' === $status_info['type'] ),
				'timeline_start' => null,
				'timeline_end'   => null,
			];
		}

		if ( null === $timeline_start || null === $timeline_end ) {
			$timeline_start = 9.0;
			$timeline_end   = 18.0;
		}

		$start_hour = (int) floor( $timeline_start );
		$end_hour   = (int) ceil( $timeline_end );

		if ( $end_hour <= $start_hour ) {
			$end_hour = $start_hour + 1;
		}

		$timeline_labels = $this->build_timeline_labels( $start_hour, $end_hour );

		foreach ( $lanes as &$lane ) {
			$lane['timeline_start'] = $start_hour;
			$lane['timeline_end']   = $end_hour;
		}
		unset( $lane );

		return [
			'timeline'      => $timeline_labels,
			'resource_cards'=> $resource_cards,
			'lanes'         => $lanes,
		];
	}

	/**
	 * Build data required to render the monthly overview grid.
	 *
	 * @param array<int, array> $shift_map      Month shift map.
	 * @param int               $year           Target year.
	 * @param int               $month          Target month (1-12).
	 * @param int               $resource_count Number of resources.
	 * @return array<string, array<int, array<int, array<string, string>>>>
	 */
	private function get_bookings_for_day( DateTimeImmutable $date ): array {
		$timezone     = wp_timezone();
		$start_of_day = $date->setTime( 0, 0, 0 );
		$end_of_day   = $date->setTime( 23, 59, 59 );

		$query = new WP_Query(
			[
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => self::META_BOOKING_START,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => self::META_BOOKING_START,
						'value'   => [
							$start_of_day->format( 'Y-m-d H:i:s' ),
							$end_of_day->format( 'Y-m-d H:i:s' ),
						],
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					],
					[
						'key'     => self::META_BOOKING_RESOURCE,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$map            = [];
		$start_of_day_ts = $start_of_day->getTimestamp();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$post_id     = get_the_ID();
				$resource_id = (int) get_post_meta( $post_id, self::META_BOOKING_RESOURCE, true );

				if ( $resource_id <= 0 ) {
					continue;
				}

				$start_raw     = (string) get_post_meta( $post_id, self::META_BOOKING_START, true );
				$service_end_raw = (string) get_post_meta( $post_id, self::META_BOOKING_END, true );
				$total_end_raw = (string) get_post_meta( $post_id, self::META_BOOKING_TOTAL_END, true );

				if ( '' === $start_raw ) {
					continue;
				}

				$start_dt       = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_raw, $timezone );
				$service_end_dt = $service_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $service_end_raw, $timezone ) : null;
				$block_end_dt   = $total_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $total_end_raw, $timezone ) : $service_end_dt;

				if ( ! $start_dt ) {
					continue;
				}

				if ( ! $block_end_dt || $block_end_dt <= $start_dt ) {
					$block_end_dt = $start_dt->modify( '+30 minutes' );
				}

				$start_decimal = max( 0.0, ( $start_dt->getTimestamp() - $start_of_day_ts ) / 3600 );
				$end_decimal   = max( $start_decimal + 0.25, ( $block_end_dt->getTimestamp() - $start_of_day_ts ) / 3600 );
				$start_decimal = min( 24.0, $start_decimal );
				$end_decimal   = min( 24.0, $end_decimal );

				$service_id    = (int) get_post_meta( $post_id, self::META_BOOKING_SERVICE, true );
				$service_title = $service_id > 0 ? get_the_title( $service_id ) : '';

				$booking = [
					'post_id'       => $post_id,
					'start_decimal' => $start_decimal,
					'end_decimal'   => $end_decimal,
					'start_label'   => $start_dt->format( 'H:i' ),
					'end_label'     => ( $service_end_dt ?? $block_end_dt )->format( 'H:i' ),
					'time_range'    => sprintf(
						'%s - %s',
						$start_dt->format( 'H:i' ),
						( $service_end_dt ?? $block_end_dt )->format( 'H:i' )
					),
					'customer'      => (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true ),
					'service'       => $service_title,
					'status'        => (string) get_post_meta( $post_id, self::META_BOOKING_STATUS, true ),
					'note'          => (string) get_post_meta( $post_id, self::META_BOOKING_NOTE, true ),
				];

				$map[ $resource_id ][] = $booking;
			}

			wp_reset_postdata();
		}

		foreach ( $map as $resource_id => $rows ) {
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return $a['start_decimal'] <=> $b['start_decimal'];
				}
			);

			$map[ $resource_id ] = array_values( $rows );
		}

		return $map;
	}

	/**
	 * Retrieve bookings for the entire month grouped by day.
	 *
	 * @param int $year  Year.
	 * @param int $month Month (1-12).
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function get_bookings_for_month( int $year, int $month ): array {
		$timezone   = wp_timezone();
		$first_day  = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
		$start      = $first_day->setTime( 0, 0, 0 );
		$end        = $first_day->modify( 'last day of this month' )->setTime( 23, 59, 59 );

		$query = new WP_Query(
			[
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => self::META_BOOKING_START,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => self::META_BOOKING_START,
						'value'   => [
							$start->format( 'Y-m-d H:i:s' ),
							$end->format( 'Y-m-d H:i:s' ),
						],
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					],
					[
						'key'     => self::META_BOOKING_RESOURCE,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$map = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$post_id     = get_the_ID();
				$resource_id = (int) get_post_meta( $post_id, self::META_BOOKING_RESOURCE, true );

				if ( $resource_id <= 0 ) {
					continue;
				}

				$start_raw       = (string) get_post_meta( $post_id, self::META_BOOKING_START, true );
				$service_end_raw = (string) get_post_meta( $post_id, self::META_BOOKING_END, true );
				$total_end_raw   = (string) get_post_meta( $post_id, self::META_BOOKING_TOTAL_END, true );

				if ( '' === $start_raw ) {
					continue;
				}

				$start_dt       = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_raw, $timezone );
				$service_end_dt = $service_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $service_end_raw, $timezone ) : null;
				$block_end_dt   = $total_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $total_end_raw, $timezone ) : ( $service_end_dt ?? null );

				if ( ! $start_dt ) {
					continue;
				}

				if ( ! $block_end_dt || $block_end_dt <= $start_dt ) {
					$block_end_dt = $start_dt->modify( '+30 minutes' );
				}

				$day_index = (int) $start_dt->format( 'j' );
				if ( $day_index < 1 ) {
					continue;
				}

				$edit_url = get_edit_post_link( $post_id, '', true );
				if ( ! $edit_url ) {
					$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
				}

				$map[ $day_index ][] = [
					'post_id'      => $post_id,
					'resource_id'  => $resource_id,
					'start_label'  => $start_dt->format( 'H:i' ),
					'end_label'    => ( $service_end_dt ?? $block_end_dt )->format( 'H:i' ),
					'status'       => (string) get_post_meta( $post_id, self::META_BOOKING_STATUS, true ),
					'customer'     => (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true ),
					'edit_url'     => $edit_url,
				];
			}

			wp_reset_postdata();
		}

		foreach ( $map as $day => $rows ) {
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return strcmp( (string) $a['start_label'], (string) $b['start_label'] );
				}
			);

			$map[ $day ] = array_values( $rows );
		}

		return $map;
	}

	/**
	 * Collect pending bookings that require confirmation.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_pending_booking_notifications(): array {
		$timezone = wp_timezone();
		$query    = new WP_Query(
			[
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish' ],
				'posts_per_page' => 10,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => self::META_BOOKING_START,
				'meta_query'     => [
					[
						'key'   => self::META_BOOKING_STATUS,
						'value' => self::BOOKING_STATUS_PENDING,
					],
				],
			]
		);

		$notifications = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$post_id         = get_the_ID();
				$start_raw       = (string) get_post_meta( $post_id, self::META_BOOKING_START, true );
				$service_end_raw = (string) get_post_meta( $post_id, self::META_BOOKING_END, true );
				$total_end_raw   = (string) get_post_meta( $post_id, self::META_BOOKING_TOTAL_END, true );

				if ( '' === $start_raw ) {
					continue;
				}

				$start_dt       = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_raw, $timezone );
				$service_end_dt = $service_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $service_end_raw, $timezone ) : null;
				$block_end_dt   = $total_end_raw ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $total_end_raw, $timezone ) : ( $service_end_dt ?? null );

				if ( ! $start_dt ) {
					continue;
				}

				if ( ! $block_end_dt || $block_end_dt <= $start_dt ) {
					$block_end_dt = $start_dt->modify( '+30 minutes' );
				}

				$resource_id   = (int) get_post_meta( $post_id, self::META_BOOKING_RESOURCE, true );
				$resource_name = $resource_id > 0 ? get_the_title( $resource_id ) : '';
				$resource_name = $resource_name ?: __( 'Person in charge undecided', 'vk-booking-manager' );

				$customer_name = (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true );
				$customer_name = '' !== trim( $customer_name ) ? $customer_name : __( 'Name not entered', 'vk-booking-manager' );

				$edit_url = get_edit_post_link( $post_id, '', true );

				if ( ! $edit_url ) {
					$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
				}

				$date_format = get_option( 'date_format' );
				$time_format = get_option( 'time_format' );
				$notifications[] = [
					'id'        => (string) $post_id,
					'time_label'=> sprintf(
						'%s - %s',
						wp_date( sprintf( '%s %s', $date_format, $time_format ), $start_dt->getTimestamp() ),
						wp_date( $time_format, $block_end_dt->getTimestamp() )
					),
					'customer'  => $customer_name,
					'staff'     => $resource_name,
					'url'       => $edit_url,
				];
			}

			wp_reset_postdata();
		}

		return $notifications;
	}

	/**
	 * Build data required to render the monthly overview grid.
	 *
	 * @param array<int, array> $shift_map         Month shift map keyed by resource.
	 * @param int               $year              Target year.
	 * @param int               $month             Target month (1-12).
	 * @param int               $resource_count    Total resources.
	 * @param array<int, string> $resource_names   Resource display names.
	 * @param array<int, array> $monthly_bookings  Bookings grouped by day.
	 * @return array<string, mixed>
	 */
		private function build_month_view_data( array $shift_map, int $year, int $month, int $resource_count, array $resource_names, array $monthly_bookings ): array {
			$timezone    = wp_timezone();
			$first_day   = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
			$days_in_month = (int) $first_day->format( 't' );
			$start_weekday = (int) $first_day->format( 'N' ); // 1 (Mon) .. 7 (Sun).

		$weeks = [];
		$week  = [];

		for ( $i = 1; $i < $start_weekday; $i++ ) {
			$week[] = [
				'day' => '',
				'type' => 'muted',
				'url' => '',
				'entries' => [],
			];
		}

			for ( $day = 1; $day <= $days_in_month; $day++ ) {
				$working_count = 0;
				$closed_count  = 0;

				foreach ( $shift_map as $resource_id => $data ) {
					$entry = $data['days'][ $day ] ?? null;

					if ( ! is_array( $entry ) ) {
						continue;
					}

					$slots = $this->normalize_slots( $entry['slots'] ?? [] );
					$status = (string) ( $entry['status'] ?? self::STATUS_OPEN );

					if ( ! empty( $slots ) ) {
						$working_count++;
					} elseif ( $this->is_closed_status( $status ) ) {
						$closed_count++;
					}
				}

				$booking_count = isset( $monthly_bookings[ $day ] ) && is_array( $monthly_bookings[ $day ] ) ? count( $monthly_bookings[ $day ] ) : 0;
				$cell_type     = $this->resolve_month_cell_type( $working_count, $closed_count, $resource_count, $booking_count );
				$entries   = $this->format_month_cell_bookings( $monthly_bookings[ $day ] ?? [], $resource_names );

				$cell_date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

			$week[] = [
				'day'      => $day,
				'type'     => $cell_type,
				'entries'  => $entries,
				'url'      => $this->get_dashboard_url(
					[
						'vkbm_date' => $cell_date,
						'vkbm_view' => 'day',
					]
				),
			];

			if ( 7 === count( $week ) ) {
				$weeks[] = $week;
				$week    = [];
			}
		}

		if ( ! empty( $week ) ) {
			while ( count( $week ) < 7 ) {
				$week[] = [
					'day' => '',
					'type' => 'muted',
					'url' => '',
					'entries' => [],
				];
			}
			$weeks[] = $week;
		}

		return [
			'weeks' => $weeks,
		];
	}

	/**
	 * Format monthly booking records for template consumption.
	 *
	 * @param array<int, array<string, mixed>> $bookings Raw booking rows.
	 * @param array<int, string>               $resource_names Resource display names.
	 * @return array<int, array<string, string>>
	 */
	private function format_month_cell_bookings( array $bookings, array $resource_names ): array {
		$entries = [];

		foreach ( $bookings as $booking ) {
			$start_label = (string) ( $booking['start_label'] ?? '' );
			$end_label   = (string) ( $booking['end_label'] ?? '' );
			$status      = (string) ( $booking['status'] ?? self::BOOKING_STATUS_PENDING );
			$customer    = trim( (string) ( $booking['customer'] ?? '' ) );
			$resource_id = (int) ( $booking['resource_id'] ?? 0 );
			$url         = (string) ( $booking['edit_url'] ?? '' );

			if ( '' === $start_label || '' === $end_label ) {
				continue;
			}

			$customer_label = $customer !== '' ? $customer : __( 'Name not entered', 'vk-booking-manager' );
			$staff_label    = $resource_names[ $resource_id ] ?? __( 'Person in charge not set', 'vk-booking-manager' );

			$status_badge = $this->get_month_booking_status_badge( $status );
			$time_label   = sprintf( '%s ～ %s', $start_label, $end_label );

			if ( '' !== $status_badge ) {
				$time_label .= sprintf( ' [%s]', $status_badge );
			}

			$customer_line = sprintf(
				/* translators: 1: Customer name, 2: Staff name */
				__( '%1$s / %2$s', 'vk-booking-manager' ),
				$customer_label,
				$staff_label
			);

			$entries[] = [
				'id'            => (string) ( $booking['post_id'] ?? '' ),
				'time_line'     => $time_label,
				'customer_line' => $customer_line,
				'url'           => $url,
				'class'         => 'vkbm-month-booking--' . $this->booking_status_to_month_modifier( $status ),
			];
		}

		return $entries;
	}

	/**
	 * Render the notifications panel shown alongside the dashboard views.
	 *
	 * @param array<int, array<string, string>> $notifications Pending booking data.
	 */
	private function render_pending_notifications_panel( array $notifications ): void {
		$has_pending = ! empty( $notifications );
		?>
		<aside class="vkbm-shift-dashboard__panel vkbm-notification-panel" aria-label="<?php esc_attr_e( 'Pending booking notifications', 'vk-booking-manager' ); ?>">
			<div class="vkbm-notification-panel__inner">
				<h2 class="vkbm-notification-panel__title"><?php esc_html_e( 'Notice', 'vk-booking-manager' ); ?></h2>
				<p class="vkbm-notification-panel__lead">
					<?php
					if ( $has_pending ) {
						esc_html_e( 'I have an unconfirmed "tentative reservation". Please check and confirm the contents.', 'vk-booking-manager' );
					} else {
						esc_html_e( 'There are currently no pending reservations.', 'vk-booking-manager' );
					}
					?>
				</p>

				<?php if ( $has_pending ) : ?>
					<ul class="vkbm-notification-panel__list">
						<?php foreach ( $notifications as $notification ) : ?>
								<?php
								$time_label = (string) ( $notification['time_label'] ?? '' );
								$customer   = (string) ( $notification['customer'] ?? '' );
								$staff      = (string) ( $notification['staff'] ?? '' );
								$url        = (string) ( $notification['url'] ?? '' );
								$user_line  = sprintf(
									/* translators: 1: Customer name, 2: Staff name */
									__( '%1$s / %2$s', 'vk-booking-manager' ),
									$customer,
									$staff
								);
								?>
								<li class="vkbm-notification-panel__item">
									<div class="vkbm-notification-card">
										<div class="vkbm-notification-card__details">
											<div class="vkbm-notification-card__time"><?php echo esc_html( $time_label ); ?></div>
											<div class="vkbm-notification-card__user"><?php echo esc_html( $user_line ); ?></div>
										</div>
										<div class="vkbm-notification-card__actions">
											<?php if ( '' !== $url ) : ?>
												<a
													class="button button-secondary button-small"
													href="<?php echo esc_url( $url ); ?>"
												>
													<?php esc_html_e( 'Detail', 'vk-booking-manager' ); ?>
												</a>
											<?php endif; ?>
											<?php if ( ! empty( $notification['id'] ) ) : ?>
												<button
													type="button"
													class="button button-primary button-small js-vkbm-notification-confirm"
													data-booking-id="<?php echo esc_attr( (string) $notification['id'] ); ?>"
												>
													<?php esc_html_e( 'Confirmed', 'vk-booking-manager' ); ?>
												</button>
											<?php endif; ?>
										</div>
									</div>
								</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</aside>
		<?php
	}

	/**
	 * Resolve the cell type for the month calendar.
	 *
	 * @param int $working_count Number of working resources.
	 * @param int $closed_count  Number of closed resources.
	 * @param int $resource_count Total resources.
	 * @return string
	 */
		private function resolve_month_cell_type( int $working_count, int $closed_count, int $resource_count, int $booking_count ): string {
			if ( $resource_count <= 0 ) {
				return 'muted';
			}

			if ( 0 === $working_count ) {
				return ( $closed_count > 0 ) ? 'holiday' : 'muted';
			}

			if ( $booking_count <= 0 ) {
				return 'light';
			}

			$ratio = $booking_count / max( 1, $resource_count );

			if ( $ratio >= 0.75 ) {
				return 'busy';
			}

		if ( $ratio >= 0.4 ) {
			return 'balanced';
		}

		return 'light';
	}

	/**
	 * Retrieve a compact status badge label for the month cell.
	 *
	 * @param string $status Booking status key.
	 * @return string
	 */
	private function get_month_booking_status_badge( string $status ): string {
		switch ( $status ) {
			case self::BOOKING_STATUS_CONFIRMED:
				return __( 'Certain', 'vk-booking-manager' );
			case self::BOOKING_STATUS_PENDING:
				return __( 'Tentative', 'vk-booking-manager' );
			default:
				$labels = $this->get_booking_status_labels();
				return $labels[ $status ] ?? '';
		}
	}

	/**
	 * Map booking status to a month booking modifier key.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	private function booking_status_to_month_modifier( string $status ): string {
		switch ( $status ) {
			case self::BOOKING_STATUS_PENDING:
				return 'pending';
			case self::BOOKING_STATUS_CANCELLED:
				return 'cancelled';
			case self::BOOKING_STATUS_NO_SHOW:
				return 'no-show';
			default:
				return 'confirmed';
		}
	}

	/**
	 * Provide a formatted role/position for a resource.
	 *
	 * @param WP_Post $resource Resource post.
	 * @return string
	 */
	private function derive_resource_role( WP_Post $resource ): string {
		$custom_role = get_post_meta( $resource->ID, '_vkbm_resource_role', true );

		if ( is_string( $custom_role ) && '' !== trim( $custom_role ) ) {
			return trim( $custom_role );
		}

		$excerpt = wp_strip_all_tags( $resource->post_excerpt ?? '' );

		if ( '' !== trim( $excerpt ) ) {
			return trim( $excerpt );
		}

		return __( 'Staff', 'vk-booking-manager' );
	}

	/**
	 * AJAX handler: confirm a pending booking from the dashboard.
	 */
	public function ajax_confirm_booking(): void {
		check_ajax_referer( 'vkbm_confirm_booking', 'nonce' );

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error(
				[ 'message' => __( "You don't have permission.", 'vk-booking-manager' ) ],
				403
			);
		}

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			wp_send_json_error(
				[ 'message' => __( 'No reservations found.', 'vk-booking-manager' ) ],
				400
			);
		}

		$booking_post = get_post( $booking_id );
		if ( ! $booking_post || Booking_Post_Type::POST_TYPE !== $booking_post->post_type ) {
			wp_send_json_error(
				[ 'message' => __( 'The target reservation could not be retrieved.', 'vk-booking-manager' ) ],
				404
			);
		}

		$current_status = (string) get_post_meta( $booking_id, self::META_BOOKING_STATUS, true );
		if ( self::BOOKING_STATUS_CONFIRMED === $current_status ) {
			wp_send_json_success( [ 'status' => self::BOOKING_STATUS_CONFIRMED ] );
		}

		$updated = update_post_meta( $booking_id, self::META_BOOKING_STATUS, self::BOOKING_STATUS_CONFIRMED );
		if ( ! $updated ) {
			wp_send_json_error(
				[ 'message' => __( 'Your reservation could not be updated.', 'vk-booking-manager' ) ],
				500
			);
		}

		$notification_service = new Booking_Notification_Service( new Settings_Repository(), new Customer_Name_Resolver() );
		$notification_service->handle_status_transition( $booking_id, $current_status, self::BOOKING_STATUS_CONFIRMED );

		wp_send_json_success( [ 'status' => self::BOOKING_STATUS_CONFIRMED ] );
	}

	/**
	 * Normalize raw slot data to start/end pairs.
	 *
	 * @param array<int, array<string, string>> $slots Raw slot definition.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_slots( array $slots ): array {
		$normalized = [];

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = isset( $slot['start'] ) ? $this->sanitize_time_string( (string) $slot['start'] ) : '';
			$end   = isset( $slot['end'] ) ? $this->sanitize_time_string( (string) $slot['end'] ) : '';

			if ( '' === $start || '' === $end || $end <= $start ) {
				continue;
			}

			$normalized[] = [
				'start' => $start,
				'end'   => $end,
			];
		}

		return $normalized;
	}

	/**
	 * Sanitize a HH:MM string.
	 *
	 * @param string $time Time string.
	 * @return string
	 */
	private function sanitize_time_string( string $time ): string {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $matches ) ) {
			return '';
		}

		$hours   = min( 23, max( 0, (int) $matches[1] ) );
		$minutes = min( 59, max( 0, (int) $matches[2] ) );

		return sprintf( '%02d:%02d', $hours, $minutes );
	}

	/**
	 * Convert a HH:MM time string to decimal hours.
	 *
	 * @param string $time Time value.
	 * @return float|null
	 */
	private function time_to_decimal( string $time ): ?float {
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time, $matches ) ) {
			return null;
		}

		$hours   = (int) $matches[1];
		$minutes = (int) $matches[2];

		return $hours + ( $minutes / 60 );
	}

	/**
	 * Format a start/end time range label.
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return string
	 */
	private function format_time_range( string $start, string $end ): string {
		return sprintf( '%s - %s', $start, $end );
	}

	/**
	 * Determine the label to show for a slot given the day status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function get_slot_status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_TEMPORARY_OPEN:
				return __( 'Temporary work', 'vk-booking-manager' );
			case self::STATUS_REGULAR_HOLIDAY:
				return __( 'Fixed holidays available', 'vk-booking-manager' );
			case self::STATUS_TEMPORARY_CLOSED:
				return __( 'Temporary closure', 'vk-booking-manager' );
			case self::STATUS_UNAVAILABLE:
				return __( 'Off', 'vk-booking-manager' );
			default:
				return __( 'Work', 'vk-booking-manager' );
		}
	}

	/**
	 * Resolve resource status label/type based on status key and slot info.
	 *
	 * @param string $status_key   Status key.
	 * @param int    $slot_count   Number of slots.
	 * @param float  $total_hours  Total scheduled hours.
	 * @return array{label:string,type:string}
	 */
	private function resolve_resource_status( string $status_key, int $slot_count, float $total_hours ): array {
		if ( 0 === $slot_count ) {
			if ( $this->is_closed_status( $status_key ) ) {
				return [
					'label' => $this->get_closed_status_label( $status_key ),
					'type'  => 'off',
				];
			}

			return [
				'label' => __( 'Shift not set', 'vk-booking-manager' ),
				'type'  => 'pending',
			];
		}

		if ( self::STATUS_TEMPORARY_OPEN === $status_key ) {
			return [
				'label' => __( 'Temporary work', 'vk-booking-manager' ),
				'type'  => 'working',
			];
		}

		return [
			'label' => __( 'Work schedule', 'vk-booking-manager' ),
			'type'  => 'working',
		];
	}

	/**
	 * Partition bookings that intersect the provided slot.
	 *
	 * @param array<int, array<string, mixed>> &$bookings Bookings list (will be mutated).
	 * @param float                             $slot_start Slot start (hours).
	 * @param float                             $slot_end   Slot end (hours).
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_bookings_for_slot( array &$bookings, float $slot_start, float $slot_end ): array {
		$assigned = [];

		foreach ( $bookings as $index => $booking ) {
			$booking_start = (float) $booking['start_decimal'];
			$booking_end   = (float) $booking['end_decimal'];

			if ( $booking_end <= $slot_start || $booking_start >= $slot_end ) {
				continue;
			}

			$clamped_start = max( $slot_start, $booking_start );
			$clamped_end   = min( $slot_end, $booking_end );

			$assigned[] = array_merge(
				$booking,
				[
					'start_decimal' => $clamped_start,
					'end_decimal'   => max( $clamped_start + 0.1, $clamped_end ),
				]
			);
			unset( $bookings[ $index ] );
		}

		return $assigned;
	}

	/**
	 * Map booking data to card structure for templates.
	 *
	 * @param array<string, mixed> $booking Booking payload.
	 * @return array<string, mixed>
	 */
	private function map_booking_to_card( array $booking ): array {
		$status      = (string) ( $booking['status'] ?? self::BOOKING_STATUS_CONFIRMED );
		$status_map  = $this->get_booking_status_labels();
		$class       = $this->booking_status_to_class( $status );
		$badges      = [];
		$status_label = $status_map[ $status ] ?? '';

		if ( self::BOOKING_STATUS_CONFIRMED !== $status && '' !== $status_label ) {
			$badges[] = $status_label;
		}

		if ( ! empty( $booking['note'] ) ) {
			$badges[] = (string) $booking['note'];
		}

		$edit_url = '';

		if ( ! empty( $booking['edit_url'] ) ) {
			$edit_url = (string) $booking['edit_url'];
		} elseif ( ! empty( $booking['post_id'] ) ) {
			$post_id  = (int) $booking['post_id'];
			$edit_url = get_edit_post_link( $post_id, '', true );

			if ( empty( $edit_url ) ) {
				$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
			}
		}

		return [
			'class'    => $class,
			'time'     => $booking['time_range'] ?? '',
			'customer' => $booking['customer'] ?? '',
			'service'  => $booking['service'] ?? '',
			'badges'   => $badges,
			'start_decimal' => $booking['start_decimal'] ?? 0,
			'end_decimal'   => $booking['end_decimal'] ?? 0,
			'url'      => $edit_url,
		];
	}

	/**
	 * Retrieve booking status labels.
	 *
	 * @return array<string, string>
	 */
	private function get_booking_status_labels(): array {
		return [
			self::BOOKING_STATUS_CONFIRMED => __( 'Confirmed', 'vk-booking-manager' ),
			self::BOOKING_STATUS_PENDING   => __( 'Pending', 'vk-booking-manager' ),
			self::BOOKING_STATUS_CANCELLED => __( 'Cancelled', 'vk-booking-manager' ),
			self::BOOKING_STATUS_NO_SHOW   => __( 'No-show', 'vk-booking-manager' ),
		];
	}

	/**
	 * Map booking status to CSS modifier class.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	private function booking_status_to_class( string $status ): string {
		switch ( $status ) {
			case self::BOOKING_STATUS_PENDING:
				return 'vkbm-booking-card--pending';
			case self::BOOKING_STATUS_CANCELLED:
				return 'vkbm-booking-card--cancelled';
			case self::BOOKING_STATUS_NO_SHOW:
				return 'vkbm-booking-card--no-show';
			default:
				return 'vkbm-booking-card--confirmed';
		}
	}

	/**
	 * Get the label for a closed status key.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function get_closed_status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_REGULAR_HOLIDAY:
				return __( 'Regular holiday', 'vk-booking-manager' );
			case self::STATUS_TEMPORARY_CLOSED:
				return __( 'Temporary closure', 'vk-booking-manager' );
			case self::STATUS_UNAVAILABLE:
				return __( 'Off', 'vk-booking-manager' );
			default:
				return __( 'Closed', 'vk-booking-manager' );
		}
	}

	/**
	 * Determine if a status is a closed/non-working status.
	 *
	 * @param string $status Status key.
	 * @return bool
	 */
	private function is_closed_status( string $status ): bool {
		return in_array( $status, self::CLOSED_STATUSES, true );
	}

	/**
	 * Build timeline labels between the given hours.
	 *
	 * @param int $start_hour Start hour.
	 * @param int $end_hour   End hour.
	 * @return array<int, string>
	 */
	private function build_timeline_labels( int $start_hour, int $end_hour ): array {
		$labels = [];

		for ( $hour = $start_hour; $hour <= $end_hour; $hour++ ) {
			$labels[] = sprintf( '%02d:00', $hour );
		}

		return $labels;
	}

	/**
	 * Retrieve configured reservation page URL.
	 *
	 * @return string
	 */
	private function get_reservation_page_url(): string {
		$settings = ( new Settings_Repository() )->get_settings();
		$url      = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';

		return $this->normalize_reservation_page_url( $url );
	}

	/**
	 * Normalize reservation page URL to an absolute URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_reservation_page_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) ) {
			return esc_url_raw( $url );
		}

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return esc_url_raw( $scheme . $url );
		}

		if ( ! str_starts_with( $url, '/' ) ) {
			$url = '/' . $url;
		}

		return esc_url_raw( home_url( $url ) );
	}

	/**
	 * Build a dashboard URL with the provided query args.
	 *
	 * @param array<string, string> $args Query args.
	 * @return string
	 */
	private function get_dashboard_url( array $args = [] ): string {
		$base = menu_page_url( self::MENU_SLUG, false );

		return add_query_arg( $args, $base );
	}

	/**
	 * Returns the menu slug so other admin pages can attach to the same parent.
	 *
	 * @return string
	 */
	public static function menu_slug(): string {
		return self::MENU_SLUG;
	}
}
