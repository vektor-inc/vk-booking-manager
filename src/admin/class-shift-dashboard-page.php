<?php
/**
 * Provides the Booking Manager shift dashboard.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Availability\Night_Strategy;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\Common\Staff_Conflict_Detector;
use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Sale_Days;
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
	private const CLOSED_STATUSES = array(
		self::STATUS_REGULAR_HOLIDAY,
		self::STATUS_TEMPORARY_CLOSED,
		self::STATUS_UNAVAILABLE,
	);

	private const META_BOOKING_START     = '_vkbm_booking_service_start';
	private const META_BOOKING_END       = '_vkbm_booking_service_end';
	private const META_BOOKING_TOTAL_END = '_vkbm_booking_total_end';
	private const META_BOOKING_RESOURCE  = '_vkbm_booking_resource_id';
	private const META_BOOKING_SERVICE   = '_vkbm_booking_service_id';
	private const META_BOOKING_CUSTOMER  = '_vkbm_booking_customer_name';
	private const META_BOOKING_TEL       = '_vkbm_booking_customer_tel';
	private const META_BOOKING_EMAIL     = '_vkbm_booking_customer_email';
	private const META_BOOKING_STATUS    = '_vkbm_booking_status';
	private const META_BOOKING_GUESTS    = '_vkbm_booking_guests';

	/**
	 * サービスメニューの「1枠あたり最大予約受付数」メタキー。
	 *
	 * @var string
	 */
	private const META_MENU_MAX_CAPACITY = '_vkbm_max_capacity';

	/**
	 * サービスメニューの最小催行人数メタキー（グループ開催型）。
	 *
	 * @var string
	 */
	private const META_MENU_MIN_CAPACITY = '_vkbm_min_capacity';

	private const BOOKING_STATUS_CONFIRMED = 'confirmed';
	private const BOOKING_STATUS_PENDING   = 'pending';
	private const BOOKING_STATUS_CANCELLED = 'cancelled';
	private const BOOKING_STATUS_NO_SHOW   = 'no_show';

	/**
	 * Capability required to access the page.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Page hook for the admin page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * 通知サービス（#474 レビュー対応: 従来は ajax_confirm_booking() のたびに都度生成していた。
	 * 他クラスと同じ「未注入時のみ都度生成」の任意引数注入に揃える）。
	 *
	 * @var Booking_Notification_Service|null
	 */
	private $notification_service;

	/**
	 * 予約の状態変化を外部連携へ橋渡しするディスパッチャー（#474）。
	 *
	 * @var Booking_Event_Dispatcher
	 */
	private $event_dispatcher;

	/**
	 * 予約カードに顧客フラグの印を出すための顧客フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $customer_flag_service;

	/**
	 * Constructor.
	 *
	 * @param string                            $capability Capability required to access the page.
	 * @param Booking_Notification_Service|null $notification_service 通知サービス（未指定時は従来どおり ajax_confirm_booking() で都度生成する）。
	 * @param Booking_Event_Dispatcher|null     $event_dispatcher 予約の状態変化を外部連携へ橋渡しするディスパッチャー。
	 * @param Customer_Flag_Service|null        $customer_flag_service 顧客フラグサービス。未指定なら既定実装を生成する。
	 */
	public function __construct(
		string $capability = 'manage_options',
		?Booking_Notification_Service $notification_service = null,
		?Booking_Event_Dispatcher $event_dispatcher = null,
		?Customer_Flag_Service $customer_flag_service = null
	) {
		$this->capability            = $capability;
		$this->notification_service  = $notification_service;
		$this->event_dispatcher      = $event_dispatcher ?? new Booking_Event_Dispatcher();
		$this->customer_flag_service = $customer_flag_service ?? new Customer_Flag_Service();
	}

	/**
	 * Register WordPress hooks for the dashboard page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vkbm_confirm_booking', array( $this, 'ajax_confirm_booking' ) );
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
			array( $this, 'render_page' ),
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
			VKBM_PLUGIN_DIR_URL . 'assets/js/shift-dashboard.js',
			array(),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-shift-dashboard-view-toggle',
			'vkbmShiftDashboard',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'confirmNonce' => wp_create_nonce( 'vkbm_confirm_booking' ),
				'i18n'         => array(
					'confirm'    => __( 'Confirmed', 'vk-booking-manager' ),
					'confirming' => __( 'Confirmation processing in progress...', 'vk-booking-manager' ),
					'error'      => __( 'Confirmation failed. Please try again later.', 'vk-booking-manager' ),
				),
			)
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

		$year        = (int) $selected_date->format( 'Y' );
		$month       = (int) $selected_date->format( 'n' );
		$day         = (int) $selected_date->format( 'j' );
		$month_key   = $selected_date->format( 'Y-m' );
		$iso_date    = $selected_date->format( 'Y-m-d' );
		$date_format = get_option( 'date_format' );
		$day_label   = wp_date( $date_format, $selected_date->getTimestamp() );

		// Format month label based on locale.
		// Japanese: "2026年2月", English: "February 2026".
		$locale       = determine_locale();
		$month_format = ( 'ja' === substr( $locale, 0, 2 ) ) ? 'Y年n月' : 'F Y';
		$month_label  = wp_date( $month_format, $selected_date->getTimestamp() );

		$resources = $this->get_resource_posts();
		$shift_map = $this->get_shift_map( $resources, $year, $month );

		$resource_names = array();
		foreach ( $resources as $resource ) {
			$resource_names[ $resource->ID ] = vkbm_get_resource_display_name( (int) $resource->ID );
		}

		$bookings_map      = $this->get_bookings_for_day( $selected_date );
		$has_stay_bookings = false;
		foreach ( $bookings_map as $booking_rows ) {
			foreach ( $booking_rows as $booking ) {
				if ( '' !== (string) ( $booking['stay_type'] ?? '' ) ) {
					$has_stay_bookings = true;
					break 2;
				}
			}
		}

		$month_bookings        = $this->get_bookings_for_month( $year, $month );
		$pending_notifications = $this->get_pending_booking_notifications();
		$staff_conflicts       = $this->get_staff_conflict_notifications();

		$day_view   = $this->build_day_view_data( $resources, $shift_map, $bookings_map, $selected_date );
		$month_view = $this->build_month_view_data( $shift_map, $year, $month, count( $resources ), $resource_names, $month_bookings );

		$prev_day_url   = $this->get_dashboard_url(
			array(
				'vkbm_date' => $selected_date->modify( '-1 day' )->format( 'Y-m-d' ),
				'vkbm_view' => 'day',
			)
		);
		$next_day_url   = $this->get_dashboard_url(
			array(
				'vkbm_date' => $selected_date->modify( '+1 day' )->format( 'Y-m-d' ),
				'vkbm_view' => 'day',
			)
		);
		$prev_month_url = $this->get_dashboard_url(
			array(
				'vkbm_date' => $selected_date->modify( 'first day of previous month' )->format( 'Y-m-d' ),
				'vkbm_view' => 'month',
			)
		);
		$next_month_url = $this->get_dashboard_url(
			array(
				'vkbm_date' => $selected_date->modify( 'first day of next month' )->format( 'Y-m-d' ),
				'vkbm_view' => 'month',
			)
		);

		$day_button_is_active   = ( 'day' === $selected_view );
		$month_button_is_active = ( 'month' === $selected_view );

		$resource_count = max( 1, count( $day_view['resource_cards'] ) );

		$month_weekdays = array(
			__( 'Mon', 'vk-booking-manager' ),
			__( 'Tue', 'vk-booking-manager' ),
			__( 'Wed', 'vk-booking-manager' ),
			__( 'Thu', 'vk-booking-manager' ),
			__( 'Fri', 'vk-booking-manager' ),
			__( 'Sat', 'vk-booking-manager' ),
			__( 'Sun', 'vk-booking-manager' ),
		);

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
									<?php echo esc_html_x( 'Day', 'calendar view', 'vk-booking-manager' ); ?>
								</button>
								<button
									type="button"
									class="button button-secondary<?php echo $month_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="month"
									aria-pressed="<?php echo $month_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php echo esc_html_x( 'Month', 'calendar view', 'vk-booking-manager' ); ?>
								</button>
							</div>
						</header>

						<div
							class="vkbm-day-view__content<?php echo $has_stay_bookings ? ' vkbm-day-view__content--lodging' : ''; ?>"
							style="--vkbm-resource-columns: <?php echo esc_attr( (string) $resource_count ); ?>;"
							data-vkbm-day="<?php echo esc_attr( $iso_date ); ?>"
						>
							<div class="vkbm-schedule-table-wrap">
							<div
								class="vkbm-schedule-table"
								style="--vkbm-resource-columns: <?php echo esc_attr( (string) $resource_count ); ?>;"
								tabindex="0"
								role="region"
								aria-label="<?php echo esc_attr__( 'Schedule table (scrollable horizontally)', 'vk-booking-manager' ); ?>"
							>
								<div class="vkbm-schedule-table__inner">
									<header class="vkbm-timeline-header">
										<div class="vkbm-timeline-header__time-label"><?php esc_html_e( 'time', 'vk-booking-manager' ); ?></div>
										<?php foreach ( $day_view['resource_cards'] as $resource_card ) : ?>
											<div class="vkbm-timeline-header__resource">
												<div class="vkbm-resource-card vkbm-resource-card--<?php echo esc_attr( $resource_card['status_type'] ); ?>">
													<div class="vkbm-resource-card__meta">
														<?php if ( '' !== $resource_card['role'] ) : ?>
															<span class="vkbm-resource-card__role"><?php echo esc_html( $resource_card['role'] ); ?></span>
														<?php endif; ?>
														<span class="vkbm-resource-card__status"><?php echo esc_html( $resource_card['status'] ); ?></span>
													</div>
													<div class="vkbm-resource-card__name"><?php echo esc_html( $resource_card['name'] ); ?></div>
													<?php if ( ! empty( $resource_card['shift_summaries'] ) ) : ?>
														<div class="vkbm-resource-card__shifts">
															<?php foreach ( $resource_card['shift_summaries'] as $shift_summary ) : ?>
																<span class="vkbm-resource-card__shift-time"><?php echo esc_html( $shift_summary['time'] ); ?> <?php echo esc_html( $shift_summary['status'] ); ?></span>
															<?php endforeach; ?>
														</div>
													<?php endif; ?>
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
													<?php if ( empty( $lane['shifts'] ) && '' !== $lane['empty_label'] ) : ?>
														<div class="vkbm-lane-empty">
															<?php echo esc_html( $lane['empty_label'] ); ?>
														</div>
													<?php endif; ?>

													<?php foreach ( $lane['shifts'] as $shift_index => $shift ) : ?>
														<?php
														$shift_classes = array( 'vkbm-shift-block' );

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
															<?php if ( ! empty( $shift['bookings'] ) ) : ?>
																<div class="vkbm-bookings">
																	<?php foreach ( $shift['bookings'] as $booking ) : ?>
																		<?php
																		$booking_classes = array( 'vkbm-booking-card' );
																		if ( ! empty( $booking['class'] ) ) {
																			$booking_classes[] = $booking['class'];
																		}
																		// 最小催行人数（グループ開催型）が未達の枠は色付けで可視化する。
																		// 色だけに頼らずカード内にテキストラベルも併記する（後述）。
																		if ( 'pending' === ( $booking['min_capacity_state'] ?? 'none' ) ) {
																			$booking_classes[] = 'is-min-capacity-pending';
																		}

																		$booking_style = sprintf(
																			'--booking-start:%s; --booking-end:%s; --overlap-index:%d; --overlap-total:%d;',
																			esc_attr( (string) $booking['start_decimal'] ),
																			esc_attr( (string) $booking['end_decimal'] ),
																			(int) ( $booking['overlap_index'] ?? 0 ),
																			max( 1, (int) ( $booking['overlap_total'] ?? 1 ) )
																		);
																		?>
																		<?php if ( ! empty( $booking['url'] ) ) : ?>
																			<a class="<?php echo esc_attr( implode( ' ', $booking_classes ) ); ?>" style="<?php echo esc_attr( $booking_style ); ?>" href="<?php echo esc_url( $booking['url'] ); ?>">
																		<?php else : ?>
																			<div class="<?php echo esc_attr( implode( ' ', $booking_classes ) ); ?>" style="<?php echo esc_attr( $booking_style ); ?>">
																		<?php endif; ?>
																		<?php if ( ! empty( $booking['stay_label'] ) ) : ?>
																			<span class="vkbm-booking-card__stay-label"><?php echo esc_html( $booking['stay_label'] ); ?></span>
																		<?php endif; ?>
																		<?php if ( ! empty( $booking['continuing'] ) ) : ?>
																			<span class="vkbm-booking-card__continuing"><?php echo esc_html( $booking['continuing'] ); ?></span>
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
																					<?php $this->render_customer_flag_mark( $booking['customer_flag'] ?? null ); ?>
																					<?php
																					// 人数（>1）または受付上限（>1）のいずれかがあれば人数情報を表示する。
																					// 上限を後から1へ減らしても、人数>1 の既存予約では人数表示を維持する。
																					$card_guests = (int) ( $booking['guests'] ?? 1 );
																					// get_booking_slot_capacity() は常に 1 以上を返すため、欠損時も「X / 0」表示を避けて 1 を既定とする。
																						$card_capacity = (int) ( $booking['slot_capacity'] ?? 1 );
																					?>
																				<?php if ( $card_guests > 1 || $card_capacity > 1 ) : ?>
																						<span class="vkbm-booking-card__divider"> / </span>
																						<span class="vkbm-booking-card__guests">
																							<?php
																							printf(
																								/* translators: 1: booked guests for this reservation, 2: per-slot reception capacity. リソースが人とは限らないため単位語は付けない（空き枠リストの残数表示と揃える）。 */
																								esc_html__( '%1$d / %2$d', 'vk-booking-manager' ),
																								(int) $card_guests,
																								(int) $card_capacity
																							);
																							?>
																						</span>
																					<?php endif; ?>
																				</span>
																			<?php endif; ?>

																			<?php if ( ! empty( $booking['service'] ) ) : ?>
																				<span class="vkbm-booking-card__service"><?php echo esc_html( $booking['service'] ); ?></span>
																			<?php endif; ?>
																			<?php
																			// 最小催行人数（グループ開催型）が未達の枠は、色付けに加えてテキストでも明示する。
																			// 色だけで催行状態を区別しないアクセシビリティ要件への対応。
																			if ( 'pending' === ( $booking['min_capacity_state'] ?? 'none' ) ) :
																				?>
																				<span class="vkbm-booking-card__min-capacity">
																					<?php
																					printf(
																						/* translators: 1: current total guests for the time slot, 2: minimum participants required to confirm. */
																						esc_html__( 'Below minimum participants (%1$d / %2$d)', 'vk-booking-manager' ),
																						(int) ( $booking['group_guests'] ?? 0 ),
																						(int) ( $booking['min_capacity'] ?? 0 )
																					);
																					?>
																				</span>
																			<?php endif; ?>
																		<?php if ( ! empty( $booking['url'] ) ) : ?>
																			</a>
																		<?php else : ?>
																			</div>
																		<?php endif; ?>
																	<?php endforeach; ?>

																	<?php
																	// 4件以上の場合は「+N件」バッジを表示 / Show "+N" badge when 4 or more bookings exist.
																	if ( ! empty( $shift['hidden_count'] ) ) :
																		$modal_id = 'vkbm-modal-' . esc_attr( (string) ( $lane['resource_id'] ?? '0' ) ) . '-' . esc_attr( (string) $shift_index );
																		?>
																		<button
																			type="button"
																			class="vkbm-bookings__more-badge js-vkbm-more-badge"
																			data-vkbm-modal-target="<?php echo esc_attr( $modal_id ); ?>"
																			aria-label="
																			<?php
																			echo esc_attr(
																				sprintf(
																				/* translators: 1: number of hidden bookings, 2: time range */
																					__( 'Show %1$d more bookings for %2$s', 'vk-booking-manager' ),
																					(int) $shift['hidden_count'],
																					$shift['time']
																				)
																			);
																			?>
																			"
																		>
																			<?php
																			printf(
																				/* translators: %d: number of hidden bookings */
																				'+%d',
																				(int) $shift['hidden_count']
																			);
																			?>
																		</button>
																	<?php endif; ?>
																</div>
															<?php endif; ?>

															<?php
															// モーダル: 全予約一覧（4件以上の場合のみ） / Modal: full booking list when 4+ bookings.
															if ( ! empty( $shift['hidden_count'] ) ) :
																?>
																<div id="<?php echo esc_attr( $modal_id ); ?>" class="vkbm-booking-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id . '-title' ); ?>" hidden>
																	<div class="vkbm-booking-modal__overlay js-vkbm-modal-close"></div>
																	<div class="vkbm-booking-modal__content">
																		<div class="vkbm-booking-modal__header">
																			<h3 id="<?php echo esc_attr( $modal_id . '-title' ); ?>" class="vkbm-booking-modal__title">
																				<?php
																				printf(
																					/* translators: %s: time range of the shift */
																					esc_html__( 'Bookings — %s', 'vk-booking-manager' ),
																					esc_html( $shift['time'] )
																				);
																				?>
																				<span class="vkbm-booking-modal__count"><?php echo esc_html( (string) count( $shift['all_bookings'] ) ); ?></span>
																			</h3>
																			<button type="button" class="vkbm-booking-modal__close js-vkbm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'vk-booking-manager' ); ?>">&times;</button>
																		</div>
																		<ul class="vkbm-booking-modal__list">
																			<?php foreach ( $shift['all_bookings'] as $modal_booking ) : ?>
																				<li class="vkbm-booking-modal__item">
																					<?php if ( ! empty( $modal_booking['url'] ) ) : ?>
																						<a class="vkbm-booking-modal__link" href="<?php echo esc_url( $modal_booking['url'] ); ?>">
																					<?php else : ?>
																						<span class="vkbm-booking-modal__link">
																					<?php endif; ?>
																						<?php if ( ! empty( $modal_booking['time'] ) ) : ?>
																							<span class="vkbm-booking-modal__time"><?php echo esc_html( $modal_booking['time'] ); ?></span>
																						<?php endif; ?>
																						<?php if ( ! empty( $modal_booking['customer'] ) ) : ?>
																							<span class="vkbm-booking-modal__customer"><?php echo esc_html( $modal_booking['customer'] ); ?></span>
																						<?php endif; ?>
																						<?php if ( ! empty( $modal_booking['service'] ) ) : ?>
																							<span class="vkbm-booking-modal__service"><?php echo esc_html( $modal_booking['service'] ); ?></span>
																						<?php endif; ?>
																					<?php if ( ! empty( $modal_booking['url'] ) ) : ?>
																						</a>
																					<?php else : ?>
																						</span>
																					<?php endif; ?>
																				</li>
																			<?php endforeach; ?>
																		</ul>
																	</div>
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
						</div>
					</section>
					<?php $this->render_pending_notifications_panel( $pending_notifications, $staff_conflicts ); ?>
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
										// Capitalize first letter of each word for English locales.
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
										// Capitalize first letter of each word for English locales.
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
									<?php echo esc_html_x( 'Day', 'calendar view', 'vk-booking-manager' ); ?>
								</button>
								<button
									type="button"
									class="button button-secondary<?php echo $month_button_is_active ? ' is-active' : ''; ?>"
									data-vkbm-view="month"
									aria-pressed="<?php echo $month_button_is_active ? 'true' : 'false'; ?>"
								>
									<?php echo esc_html_x( 'Month', 'calendar view', 'vk-booking-manager' ); ?>
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
											$cell_classes = array( 'vkbm-month-calendar__cell' );

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
																$entry_classes = array( 'vkbm-month-booking' );
																if ( ! empty( $entry['class'] ) ) {
																	$entry_classes[] = $entry['class'];
																}
																?>

																<?php if ( ! empty( $entry['url'] ) ) : ?>
																	<a class="<?php echo esc_attr( implode( ' ', $entry_classes ) ); ?>" href="<?php echo esc_url( $entry['url'] ); ?>">
																		<span class="vkbm-month-booking__time"><?php echo esc_html( $entry['time_line'] ); ?></span>
																		<span class="vkbm-month-booking__customer"><?php echo esc_html( $entry['customer_line'] ); ?><?php $this->render_customer_flag_mark( $entry['customer_flag'] ?? null ); ?></span>
																	</a>
																<?php else : ?>
																	<div class="<?php echo esc_attr( implode( ' ', $entry_classes ) ); ?>">
																		<span class="vkbm-month-booking__time"><?php echo esc_html( $entry['time_line'] ); ?></span>
																		<span class="vkbm-month-booking__customer"><?php echo esc_html( $entry['customer_line'] ); ?><?php $this->render_customer_flag_mark( $entry['customer_flag'] ?? null ); ?></span>
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
					<?php $this->render_pending_notifications_panel( $pending_notifications, $staff_conflicts ); ?>
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

			if ( in_array( $view, array( 'day', 'month' ), true ) ) {
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
	 * Build a map of shift data for each resource for the requested month.
	 *
	 * @param array<int, WP_Post> $resources Resource posts.
	 * @param int                 $year      Target year.
	 * @param int                 $month     Target month (1-12).
	 * @return array<int, array<string, mixed>>
	 */
	private function get_shift_map( array $resources, int $year, int $month ): array {
		$map          = array();
		$resource_ids = array();

		foreach ( $resources as $resource ) {
			$resource_ids[]             = (int) $resource->ID;
			$map[ (int) $resource->ID ] = array(
				'days' => array(),
			);
		}

		if ( empty( $resource_ids ) ) {
			return $map;
		}

		$shift_posts = get_posts(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_SHIFT_YEAR,
						'value' => $year,
					),
					array(
						'key'   => self::META_SHIFT_MONTH,
						'value' => $month,
					),
				),
			)
		);

		foreach ( $shift_posts as $shift_post ) {
			$resource_id = (int) get_post_meta( $shift_post->ID, self::META_SHIFT_RESOURCE, true );

			if ( ! in_array( $resource_id, $resource_ids, true ) ) {
				continue;
			}

			if ( ! isset( $map[ $resource_id ] ) ) {
				$map[ $resource_id ] = array(
					'days' => array(),
				);
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
		$normalized = array();

		foreach ( $days as $day => $entry ) {
			$day_number = (int) $day;

			if ( $day_number < 1 || $day_number > 31 ) {
				continue;
			}

			$status = self::STATUS_OPEN;
			$slots  = array();

			if ( is_array( $entry ) ) {
				if ( isset( $entry['status'] ) ) {
					$status_candidate = (string) $entry['status'];
					$status           = '' !== $status_candidate ? $status_candidate : self::STATUS_OPEN;
				}

				if ( isset( $entry['slots'] ) && is_array( $entry['slots'] ) ) {
					$slots = $entry['slots'];
				}
			}

			$normalized[ $day_number ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}

		return $normalized;
	}

	/**
	 * Assemble data required to render the day view.
	 *
	 * @param array<int, WP_Post> $resources     Resource posts.
	 * @param array<int, array>   $shift_map     Month shift map.
	 * @param array<int, array>   $bookings_map  Bookings map.
	 * @param DateTimeImmutable   $date          Selected date.
	 * @return array<string, mixed>
	 */
	private function build_day_view_data( array $resources, array $shift_map, array $bookings_map, DateTimeImmutable $date ): array {
		$timeline_start = null;
		$timeline_end   = null;
		$day_start      = $date->setTime( 0, 0 );
		$day            = (int) $date->format( 'j' );

		$resource_cards = array();
		$lanes          = array();

		foreach ( $resources as $resource ) {
			$resource_id         = (int) $resource->ID;
			$days                = $shift_map[ $resource_id ]['days'] ?? array();
			$day_entry           = $days[ $day ] ?? null;
			$resource_bookings   = $bookings_map[ $resource_id ] ?? array();
			$unassigned_bookings = $resource_bookings;

			$status_key = self::STATUS_NOT_SET;
			$slots      = array();

			if ( is_array( $day_entry ) ) {
				$status_key = (string) ( $day_entry['status'] ?? self::STATUS_OPEN );
				$slots      = $this->normalize_slots( $day_entry['slots'] ?? array() );
			}

			$shifts       = array();
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
				++$slot_counter;
				$last_end = $end;

				$slot_bookings = $this->collect_bookings_for_slot( $unassigned_bookings, $start, $end );

				// 時間が実際に重なる予約をクラスタリングし、クラスタ単位で重なり情報を付与.
				// 4件以上重なるクラスタのみ表示を3件に制限し、残りはモーダルで表示する.
				$max_visible = 3;

				// start_decimal でソートしてクラスタを構築.
				usort(
					$slot_bookings,
					function ( array $a, array $b ) {
						return $a['start_decimal'] <=> $b['start_decimal'];
					}
				);

				$clusters = array();
				foreach ( $slot_bookings as $sb ) {
					$last = count( $clusters ) - 1;
					if ( $last >= 0 && $sb['start_decimal'] < $clusters[ $last ]['end'] ) {
						$clusters[ $last ]['bookings'][] = $sb;
						$clusters[ $last ]['end']        = max( $clusters[ $last ]['end'], $sb['end_decimal'] );
					} else {
						$clusters[] = array(
							'end'      => $sb['end_decimal'],
							'bookings' => array( $sb ),
						);
					}
				}

				// クラスタ単位でスイープラインを実行し、同時重なり数に基づいて表示・非表示を決定.
				// Sweep-line per cluster: assign column indices based on actual concurrent overlap.
				$visible_slot = array();
				$hidden_slot  = array();

				foreach ( $clusters as $cluster ) {
					// スイープラインでカラムインデックスを割り当て / Assign column indices via sweep-line.
					$columns        = array(); // 各カラムの終了時刻を保持 / Holds end time of each column.
					$max_concurrent = 0;
					$assignments    = array(); // booking index => column index.

					foreach ( $cluster['bookings'] as $ci => $cb ) {
						// 空きカラムを探す / Find a free column whose end <= this booking start.
						$assigned_col = null;
						foreach ( $columns as $col_idx => $col_end ) {
							if ( $col_end <= $cb['start_decimal'] ) {
								$assigned_col = $col_idx;
								break;
							}
						}

						if ( null === $assigned_col ) {
							// 空きカラムがないので新しいカラムを追加 / No free column; add a new one.
							$assigned_col             = count( $columns );
							$columns[ $assigned_col ] = $cb['end_decimal'];
						} else {
							$columns[ $assigned_col ] = $cb['end_decimal'];
						}

						$assignments[ $ci ] = $assigned_col;
						$max_concurrent     = max( $max_concurrent, count( $columns ) );
					}

					// 最大同時重なり数でカラム幅と表示・非表示を判定 / Determine visibility by peak concurrent count.
					$visible_total = min( $max_visible, $max_concurrent );

					foreach ( $cluster['bookings'] as $ci => $cb ) {
						$col_index           = $assignments[ $ci ];
						$cb['overlap_index'] = $col_index;
						$cb['overlap_total'] = $visible_total;

						if ( $col_index < $max_visible ) {
							$visible_slot[] = $cb;
						} else {
							$hidden_slot[] = $cb;
						}
					}
				}

				$hidden_count = count( $hidden_slot );

				foreach ( $slot_bookings as $booking_slot ) {
					$timeline_start = min( $timeline_start, $booking_slot['start_decimal'] );
					$timeline_end   = max( $timeline_end, $booking_slot['end_decimal'] );
				}

				// 表示用カードとモーダル用カード（非表示分がある場合は全件）を構築.
				$visible_booking_cards = array_map(
					function ( array $booking ) {
						return $this->map_booking_to_card( $booking );
					},
					$visible_slot
				);

				// モーダル用は start_decimal 順にソートして時系列を維持 / Sort by start_decimal for chronological modal list.
				$all_slot = array_merge( $visible_slot, $hidden_slot );
				usort(
					$all_slot,
					function ( array $a, array $b ) {
						return $a['start_decimal'] <=> $b['start_decimal'];
					}
				);
				$all_booking_cards = array_map(
					function ( array $booking ) {
						return $this->map_booking_to_card( $booking );
					},
					$all_slot
				);

				$shifts[] = array(
					'start'        => $start,
					'end'          => $end,
					'time'         => $this->format_time_range( $slot['start'], $slot['end'] ),
					'status'       => $this->get_slot_status_label( $status_key ),
					'bookings'     => $visible_booking_cards,
					'all_bookings' => $all_booking_cards,
					'hidden_count' => $hidden_count,
				);
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

			// シフトスロットの時間・ステータスをヘッダー表示用に収集する / Collect shift slot time & status for header display.
			$shift_summaries = array();
			foreach ( $shifts as $shift_item ) {
				$shift_summaries[] = array(
					'time'   => $shift_item['time'],
					'status' => $shift_item['status'],
				);
			}

			$resource_cards[] = array(
				'id'              => $resource_id,
				'name'            => vkbm_get_resource_display_name( (int) $resource->ID ),
				'role'            => $this->derive_resource_role( $resource ),
				'status'          => $status_info['label'],
				'status_type'     => $status_info['type'],
				'shift_summaries' => $shift_summaries,
			);

			$unassigned_bookings = array_values( $unassigned_bookings );

			foreach ( $unassigned_bookings as $booking_index => $booking ) {
				$booking_cards = array( $this->map_booking_to_card( $booking ) );
				$shifts[]      = array(
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
				);
				unset( $unassigned_bookings[ $booking_index ] );
			}

			$lanes[] = array(
				'resource_id'    => $resource_id,
				'shifts'         => $shifts,
				'empty_label'    => $this->resolve_empty_lane_label( $status_info ),
				'timeline_start' => null,
				'timeline_end'   => null,
			);
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

		return array(
			'timeline'       => $timeline_labels,
			'resource_cards' => $resource_cards,
			'lanes'          => $lanes,
		);
	}

	/**
	 * Get bookings for a specific day.
	 *
	 * @param DateTimeImmutable $date Target date.
	 * @return array<int, array>
	 */
	private function get_bookings_for_day( DateTimeImmutable $date ): array {
		$day_rows = $this->get_booking_occurrences_for_range( $date->setTime( 0, 0, 0 ), $date->setTime( 23, 59, 59 ) );
		$map      = array();

		foreach ( $day_rows[ $date->format( 'Y-m-d' ) ] ?? array() as $booking ) {
			$map[ (int) $booking['resource_id'] ][] = $booking;
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

		// 最小催行人数（グループ開催型）の催行状態を各予約へ付与する。
		// 同一サービス・同一時間帯に相乗りした合計予約人数をスタッフ横断で集計し、
		// メニューの最小催行人数と比較して未達フラグを立てる（表示・可視化のみ）。
		$this->annotate_min_capacity_state( $map );

		return $map;
	}

	/**
	 * 日表示の予約一覧に最小催行人数（グループ開催型）の催行状態を付与する。
	 *
	 * 「1回 = 同じ日時（同じ開始・終了時刻）のサービススロット」を1セッションとみなし、
	 * 同一サービス・同一スロット（開始/終了が一致）に相乗りした合計予約人数（キャンセル以外）を
	 * スタッフ横断で集計してメニューの最小催行人数と比較する。
	 * 時間帯の重なり（overlap）では別開始時刻の別セッションやバッファ込み隣接枠まで巻き込み
	 * 誤判定するため、開始・終了時刻が厳密に一致する枠だけを束ねる。
	 *
	 * 各予約レコードに以下を追記する（参照渡しで $map を更新）:
	 * - group_guests:        当該スロットの合計予約人数（スタッフ横断・同一開始終了）。
	 * - min_capacity:        メニューの最小催行人数（0=制約なし）。
	 * - min_capacity_state:  'none'/'pending'/'fulfilled'（VKBM_Helper::get_min_capacity_status と同じ）。
	 *
	 * @param array<int, array<int, array<string, mixed>>> $map 予約マップ（参照渡し）。
	 * @return void
	 */
	private function annotate_min_capacity_state( array &$map ): void {
		// メニューごとの最小催行人数をキャッシュして get_post_meta の重複呼び出しを抑える。
		$min_capacity_cache = array();
		// 予約人数（_vkbm_booking_guests）を post_id 単位でキャッシュする。
		$guest_cache = array();

		// セッション単位（サービス×開始時刻×終了時刻）に合計予約人数を集計する。
		// キャンセル済みは催行人数に数えない。スタッフ横断で同一スロットを1セッションとして束ねる。
		$session_guests = array();
		foreach ( $map as $rows ) {
			foreach ( $rows as $row ) {
				$service_id = (int) ( $row['service_id'] ?? 0 );
				if ( $service_id <= 0 ) {
					continue;
				}
				if ( self::BOOKING_STATUS_CANCELLED === (string) ( $row['status'] ?? '' ) ) {
					continue;
				}

				$session_key = $this->build_session_key( $row );
				$post_id     = (int) ( $row['post_id'] ?? 0 );
				if ( ! isset( $guest_cache[ $post_id ] ) ) {
					$guest_cache[ $post_id ] = max( 1, (int) get_post_meta( $post_id, self::META_BOOKING_GUESTS, true ) );
				}

				$session_guests[ $session_key ] = ( $session_guests[ $session_key ] ?? 0 ) + $guest_cache[ $post_id ];
			}
		}

		foreach ( $map as $resource_id => $rows ) {
			foreach ( $rows as $index => $row ) {
				$service_id = (int) ( $row['service_id'] ?? 0 );

				// サービス未設定の予約は催行判定の対象外。
				if ( $service_id <= 0 ) {
					$map[ $resource_id ][ $index ]['group_guests']       = 0;
					$map[ $resource_id ][ $index ]['min_capacity']       = 0;
					$map[ $resource_id ][ $index ]['min_capacity_state'] = 'none';
					continue;
				}

				// メニューの最小催行人数（0=制約なし）。最大受付数も考慮してクランプする。
				if ( ! isset( $min_capacity_cache[ $service_id ] ) ) {
					$min_capacity_cache[ $service_id ] = $this->get_menu_min_capacity_for_service( $service_id );
				}
				$min_capacity = $min_capacity_cache[ $service_id ];

				// 同一セッション（同一サービス・同一開始/終了時刻）の合計予約人数。
				// キャンセル済みのみの枠は集計に現れないため 0 とする。
				$session_key  = $this->build_session_key( $row );
				$group_guests = (int) ( $session_guests[ $session_key ] ?? 0 );

				$status = \VKBookingManager\Common\VKBM_Helper::get_min_capacity_status( $min_capacity, $group_guests );

				$map[ $resource_id ][ $index ]['group_guests']       = $group_guests;
				$map[ $resource_id ][ $index ]['min_capacity']       = $min_capacity;
				$map[ $resource_id ][ $index ]['min_capacity_state'] = $status['state'];
			}
		}
	}

	/**
	 * 催行集計用のセッションキー（サービス×開始時刻×終了時刻）を組み立てる。
	 *
	 * 「1回 = 同じ日時のサービススロット」を表すキー。開始・終了時刻が一致する枠だけを
	 * 同一セッションとして束ねるため、サービスIDと開始/終了の十進時刻を結合する。
	 * 浮動小数の表記揺れを避けるため小数第4位で丸めて文字列化する。
	 *
	 * @param array<string, mixed> $row 予約レコード（service_id / start_decimal / end_decimal を含む）。
	 * @return string セッションキー。
	 */
	private function build_session_key( array $row ): string {
		return sprintf(
			'%d|%.4F|%.4F',
			(int) ( $row['service_id'] ?? 0 ),
			(float) ( $row['start_decimal'] ?? 0 ),
			(float) ( $row['end_decimal'] ?? 0 )
		);
	}

	/**
	 * サービスメニューの最小催行人数を取得する（管理画面用）。
	 *
	 * Availability_Service::get_menu_min_capacity と同じく、最大受付数（1枠あたり最大受付数）を
	 * 上限としてクランプし、最大受付数が1以下のメニューでは0（制約なし）を返す。
	 *
	 * @param int $service_id サービスメニューの投稿ID。
	 * @return int 最小催行人数（0=制約なし）。
	 */
	private function get_menu_min_capacity_for_service( int $service_id ): int {
		if ( $service_id <= 0 ) {
			return 0;
		}

		$max_capacity = max( 1, (int) get_post_meta( $service_id, self::META_MENU_MAX_CAPACITY, true ) );
		// 1対1予約（最大受付数1）のメニューでは催行人数の概念がないため制約なし扱い。
		if ( $max_capacity <= 1 ) {
			return 0;
		}

		$meta = get_post_meta( $service_id, self::META_MENU_MIN_CAPACITY, true );
		$min  = '' === $meta ? 0 : (int) $meta;

		return max( 0, min( $max_capacity, $min ) );
	}

	/**
	 * 指定期間の日ごとに、客室・予約・IN／滞在中／OUT の表示データを組み立てる。
	 *
	 * 宿泊モードでは期間と一部でも重なる予約を取得し、泊の予約を滞在日ごとに展開する。
	 * 宿泊以外では開始日時が期間内の予約だけを従来どおり開始日に置く。
	 *
	 * @param DateTimeImmutable $range_start 表示期間の開始。
	 * @param DateTimeImmutable $range_end   表示期間の終了。
	 * @return array<string, array<int, array<string, mixed>>> 日付をキーにした予約表示データ。
	 */
	private function get_booking_occurrences_for_range( DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$is_lodging  = Industry_Presets::supports( 'lodging' );
		$start_raw   = $range_start->format( 'Y-m-d H:i:s' );
		$end_raw     = $range_end->format( 'Y-m-d H:i:s' );
		$start_floor = $range_start->modify( sprintf( '-%d days', Night_Strategy::MAX_NIGHTS ) )->format( 'Y-m-d H:i:s' );

		$start_condition = array(
			'key'     => self::META_BOOKING_START,
			'value'   => $is_lodging ? array( $start_floor, $end_raw ) : array( $start_raw, $end_raw ),
			'compare' => 'BETWEEN',
			'type'    => 'DATETIME',
		);
		$meta_query      = array(
			'relation' => 'AND',
			$start_condition,
			array(
				'key'     => self::META_BOOKING_RESOURCE,
				'compare' => 'EXISTS',
			),
		);
		if ( $is_lodging ) {
			$meta_query[] = array(
				'key'     => self::META_BOOKING_TOTAL_END,
				'value'   => $start_raw,
				'compare' => '>',
				'type'    => 'DATETIME',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => self::META_BOOKING_START,
				'meta_query'     => $meta_query,
			)
		);

		$timezone       = wp_timezone();
		$range_first    = $range_start->setTime( 0, 0 );
		$range_last     = $range_end->setTime( 0, 0 );
		$stay_type_rank = array(
			'out'  => 0,
			'stay' => 1,
			'in'   => 2,
			''     => 3,
		);
		$rows           = array();

		// 取得した予約を表示期間へ切り詰め、日ごとの表示行へ展開する。
		foreach ( $query->posts as $post ) {
			$post_id     = (int) $post->ID;
			$resource_id = (int) get_post_meta( $post_id, self::META_BOOKING_RESOURCE, true );
			$start       = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) get_post_meta( $post_id, self::META_BOOKING_START, true ), $timezone );
			$service_end = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) get_post_meta( $post_id, self::META_BOOKING_END, true ), $timezone );
			$total_end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) get_post_meta( $post_id, self::META_BOOKING_TOTAL_END, true ), $timezone );
			if ( $resource_id <= 0 || ! $start ) {
				continue;
			}
			$label_end = $service_end ? $service_end : $total_end;
			$block_end = $total_end ? $total_end : $service_end;
			if ( ! $label_end || $label_end <= $start ) {
				$label_end = $start->modify( '+30 minutes' );
			}
			if ( ! $block_end || $block_end <= $start ) {
				$block_end = $start->modify( '+30 minutes' );
			}

			$is_stay    = $is_lodging && Lodging_Booking::is_stay_booking( $post_id ) && $total_end;
			$stay_first = $start->setTime( 0, 0 );
			$stay_last  = $total_end ? $total_end->setTime( 0, 0 ) : $stay_first;
			if ( ! $is_stay && ( $stay_first < $range_first || $stay_first > $range_last ) ) {
				continue;
			}
			$first_date = $is_stay && $range_first > $stay_first ? $range_first : $stay_first;
			$last_date  = $is_stay && $range_last < $stay_last ? $range_last : ( $is_stay ? $stay_last : $first_date );
			$service_id = (int) get_post_meta( $post_id, self::META_BOOKING_SERVICE, true );
			$nights     = Lodging_Booking::get_booking_nights( $post_id );
			$customer   = (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true );
			$status     = (string) get_post_meta( $post_id, self::META_BOOKING_STATUS, true );
			$guests     = max( 1, (int) get_post_meta( $post_id, self::META_BOOKING_GUESTS, true ) );
			$service    = $service_id > 0 ? get_the_title( $service_id ) : '';
			$edit_link  = get_edit_post_link( $post_id, '', true );
			$edit_url   = $edit_link ? $edit_link : admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );

			for ( $date = $first_date; $date <= $last_date; $date = $date->modify( '+1 day' ) ) {
				$date_key  = $date->format( 'Y-m-d' );
				$stay_type = '';
				if ( $is_stay ) {
					if ( $date_key === $start->format( 'Y-m-d' ) ) {
						$stay_type = 'in';
					} elseif ( $date_key === $total_end->format( 'Y-m-d' ) ) {
						$stay_type = 'out';
					} else {
						$stay_type = 'stay';
					}
				}
				$day_start           = $date->setTime( 0, 0 );
				$start_decimal       = max( 0.0, min( 24.0, ( $start->getTimestamp() - $day_start->getTimestamp() ) / 3600 ) );
				$end_decimal         = min( 24.0, max( $start_decimal + 0.25, ( $block_end->getTimestamp() - $day_start->getTimestamp() ) / 3600 ) );
				$rows[ $date_key ][] = array(
					'post_id'       => $post_id,
					'resource_id'   => $resource_id,
					'service_id'    => $service_id,
					'start_decimal' => $start_decimal,
					'end_decimal'   => $end_decimal,
					'start_label'   => $start->format( 'H:i' ),
					'end_label'     => $label_end->format( 'H:i' ),
					'start_date'    => $start->format( 'm/d' ),
					'end_date'      => $label_end->format( 'm/d' ),
					'time_range'    => $is_stay
						? sprintf(
							/* translators: 1: check-in date, 2: check-in time, 3: check-out date, 4: check-out time, 5: formatted nights label. */
							__( '%1$s %2$s → %3$s %4$s (%5$s)', 'vk-booking-manager' ),
							$start->format( 'm/d' ),
							$start->format( 'H:i' ),
							$label_end->format( 'm/d' ),
							$label_end->format( 'H:i' ),
							Lodging_Booking::format_nights_label( $nights )
						)
						: sprintf( '%s - %s', $start->format( 'H:i' ), $label_end->format( 'H:i' ) ),
					'stay_type'     => $stay_type,
					'nights'        => $nights,
					'stay_day'      => $is_stay ? max( 1, (int) $start->setTime( 0, 0 )->diff( $date )->days + 1 ) : 0,
					'customer'      => $customer,
					'service'       => $service,
					'status'        => $status,
					'staff_guests'  => $guests,
					'edit_url'      => $edit_url,
				);
			}
		}

		foreach ( $rows as &$day_rows ) {
			usort( $day_rows, static fn( array $a, array $b ): int => $stay_type_rank[ $a['stay_type'] ] <=> $stay_type_rank[ $b['stay_type'] ] );
		}
		unset( $day_rows );

		return $rows;
	}

	/**
	 * Retrieve bookings for the entire month grouped by day.
	 *
	 * @param int $year  Year.
	 * @param int $month Month (1-12).
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function get_bookings_for_month( int $year, int $month ): array {
		$timezone  = wp_timezone();
		$first_day = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
		$start     = $first_day->setTime( 0, 0, 0 );
		$end       = $first_day->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		$rows      = $this->get_booking_occurrences_for_range( $start, $end );
		$map       = array();

		foreach ( $rows as $date_key => $bookings ) {
			if ( 0 !== strpos( $date_key, $first_day->format( 'Y-m-' ) ) ) {
				continue;
			}
			$day         = (int) substr( $date_key, 8, 2 );
			$map[ $day ] = $bookings;
		}

		return $map;
	}

	/**
	 * Collect pending bookings that require confirmation.
	 *
	 * @return array<int, array<string, string|bool>>
	 */
	private function get_pending_booking_notifications(): array {
		$timezone = wp_timezone();
		$query    = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 10,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => self::META_BOOKING_START,
				'meta_query'     => array(
					array(
						'key'   => self::META_BOOKING_STATUS,
						'value' => self::BOOKING_STATUS_PENDING,
					),
				),
			)
		);

		$notifications = array();

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
				$resource_name = $resource_id > 0 ? vkbm_get_resource_display_name( $resource_id ) : '';
				$resource_name = '' !== $resource_name ? $resource_name : __( 'Person in charge undecided', 'vk-booking-manager' );

				$customer_name = (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true );
				$customer_name = '' !== trim( $customer_name ) ? $customer_name : __( 'Name not entered', 'vk-booking-manager' );

				$edit_url = get_edit_post_link( $post_id, '', true );

				if ( ! $edit_url ) {
					$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
				}

					$date_format     = get_option( 'date_format' );
					$time_format     = get_option( 'time_format' );
					$notifications[] = array(
						'id'          => (string) $post_id,
						'time_label'  => sprintf(
							'%s - %s',
							wp_date( sprintf( '%s %s', $date_format, $time_format ), $start_dt->getTimestamp() ),
							wp_date( $time_format, $block_end_dt->getTimestamp() )
						),
						'customer'    => $customer_name,
						'staff'       => $resource_name,
						'url'         => $edit_url,
						// 担当未定の予約は、確定ボタンではなく担当者の割り当てへ誘導する（#652）。
						'needs_staff' => $resource_id <= 0,
					);
			}

			wp_reset_postdata();
		}

		return $notifications;
	}

	/**
	 * 今後30日以内に開始する、担当スタッフが対応できない予約を一括収集する。
	 *
	 * @return array{items:array<int, array<string, string>>,total:int,allow_overlap:bool} 通知表示データ。
	 */
	private function get_staff_conflict_notifications(): array {
		// この通知区画は複数スタッフ管理を含む Pro 版だけで提供する。
		if ( ! class_exists( 'Free_Version_Deactivator' ) || ! \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE ) ) {
			return array(
				'items'         => array(),
				'total'         => 0,
				'allow_overlap' => false,
			);
		}
		$timezone    = wp_timezone();
		$range_start = ( new DateTimeImmutable( 'now', $timezone ) )->setTime( 0, 0 );
		$display_end = $range_start->modify( '+30 days' )->setTime( 23, 59, 59 );
		// 30日後の予約と翌日開始の予約の重なりも判定できるよう、比較対象の取得期間だけを1日広げる。
		$query_end   = $range_start->modify( '+31 days' )->setTime( 23, 59, 59 );
		$bookings    = Staff_Conflict_Detector::get_bookings_intersecting_range( $range_start->format( 'Y-m-d H:i:s' ), $query_end->format( 'Y-m-d H:i:s' ) );
		$bookings    = array_values(
			array_filter(
				$bookings,
				static fn( array $booking ): bool => ! in_array( (string) $booking['status'], array( 'cancelled', 'no_show' ), true )
			)
		);
		$capacities  = array();
		$nominations = array();
		$service_ids = array_values( array_unique( array_map( static fn( array $booking ): int => (int) $booking['service_id'], $bookings ) ) );
		$service_ids = array_values( array_filter( $service_ids ) );
		update_meta_cache( 'post', $service_ids );

		foreach ( $service_ids as $service_id ) {
			$capacities[ $service_id ]  = Staff_Conflict_Detector::get_menu_capacity( $service_id );
			$nominations[ $service_id ] = \VKBookingManager\Staff\Staff_Editor::is_nomination_enabled_for_menu( $service_id );
		}

		$bookings_by_staff = array();
		foreach ( $bookings as $booking ) {
			$bookings_by_staff[ (int) $booking['resource_id'] ][] = $booking;
		}
		foreach ( $bookings_by_staff as &$staff_bookings ) {
			usort( $staff_bookings, static fn( array $a, array $b ): int => strcmp( (string) $a['start'], (string) $b['start'] ) );
		}
		unset( $staff_bookings );

		$conflicts = array();
		foreach ( $bookings as $booking ) {
			$status = (string) $booking['status'];
			$start  = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $booking['start'], $timezone );
			if ( 'publish' !== (string) $booking['post_status'] || ! in_array( $status, array( self::BOOKING_STATUS_CONFIRMED, self::BOOKING_STATUS_PENDING ), true ) || ! $start || $start < $range_start || $start > $display_end ) {
				continue;
			}
			$resource_id = (int) $booking['resource_id'];
			$reasons     = Staff_Conflict_Detector::detect_reasons( $booking, $bookings_by_staff[ $resource_id ] ?? array(), $capacities, $nominations, true );
			if ( empty( $reasons ) ) {
				continue;
			}
			$post_id       = (int) $booking['id'];
			$customer_name = trim( (string) get_post_meta( $post_id, self::META_BOOKING_CUSTOMER, true ) );
			$end_raw       = max( (string) $booking['total_end'], (string) $booking['service_end'] );
			$end           = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end_raw, $timezone );
			$edit_url      = get_edit_post_link( $post_id, '', true );
			$staff_name    = vkbm_get_resource_display_name( $resource_id );
			$conflicts[]   = array(
				'id'         => (string) $post_id,
				'start_raw'  => (string) $booking['start'],
				'time_label' => sprintf( '%s - %s', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start->getTimestamp() ), $end ? wp_date( get_option( 'time_format' ), $end->getTimestamp() ) : '' ),
				'customer'   => '' !== $customer_name ? $customer_name : __( 'Name not entered', 'vk-booking-manager' ),
				'staff'      => $staff_name ? $staff_name : __( 'Person in charge undecided', 'vk-booking-manager' ),
				'url'        => $edit_url ? $edit_url : admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) ),
				'status'     => $status,
				'reason'     => in_array( Staff_Conflict_Detector::REASON_OVERLAP, $reasons, true ) ? Staff_Conflict_Detector::REASON_OVERLAP : Staff_Conflict_Detector::REASON_INSUFFICIENT_CAPACITY,
			);
		}

		usort( $conflicts, static fn( array $a, array $b ): int => strcmp( $a['start_raw'], $b['start_raw'] ) );
		$settings = ( new Settings_Repository() )->get_settings();
		return array(
			'items'         => array_slice( $conflicts, 0, 10 ),
			'total'         => count( $conflicts ),
			'allow_overlap' => ! empty( $settings['provider_allow_staff_overlap_admin'] ),
		);
	}

	/**
	 * Build data required to render the monthly overview grid.
	 *
	 * @param array<int, array>  $shift_map         Month shift map keyed by resource.
	 * @param int                $year              Target year.
	 * @param int                $month             Target month (1-12).
	 * @param int                $resource_count    Total resources.
	 * @param array<int, string> $resource_names   Resource display names.
	 * @param array<int, array>  $monthly_bookings  Bookings grouped by day.
	 * @return array<string, mixed>
	 */
	private function build_month_view_data( array $shift_map, int $year, int $month, int $resource_count, array $resource_names, array $monthly_bookings ): array {
		$timezone      = wp_timezone();
		$first_day     = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
		$days_in_month = (int) $first_day->format( 't' );
		$start_weekday = (int) $first_day->format( 'N' ); // 1 (Mon) .. 7 (Sun).

		$weeks      = array();
		$week       = array();
		$is_lodging = Industry_Presets::supports( 'lodging' );

		for ( $i = 1; $i < $start_weekday; $i++ ) {
			$week[] = array(
				'day'     => '',
				'type'    => 'muted',
				'url'     => '',
				'entries' => array(),
			);
		}

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$working_count = 0;
			$closed_count  = 0;

			foreach ( $shift_map as $resource_id => $data ) {
				$entry = $data['days'][ $day ] ?? null;

				if ( ! is_array( $entry ) ) {
					continue;
				}

				$slots  = $this->normalize_slots( $entry['slots'] ?? array() );
				$status = (string) ( $entry['status'] ?? self::STATUS_OPEN );

				if ( ! empty( $slots ) ) {
					++$working_count;
				} elseif ( $this->is_closed_status( $status ) ) {
					++$closed_count;
				}
			}

			$day_bookings = $monthly_bookings[ $day ] ?? array();
			if ( $is_lodging ) {
				$occupied_bookings = array_filter(
					$day_bookings,
					static fn( array $booking ): bool => 'out' !== ( $booking['stay_type'] ?? '' ) && ! in_array( (string) ( $booking['status'] ?? '' ), array( 'cancelled', 'no_show' ), true )
				);
				$booking_count     = count( array_unique( array_column( $occupied_bookings, 'resource_id' ) ) );
			} else {
				$booking_count = count( $day_bookings );
			}
			$cell_type = $this->resolve_month_cell_type( $working_count, $closed_count, $resource_count, $booking_count );
			$entries   = $this->format_month_cell_bookings( $monthly_bookings[ $day ] ?? array(), $resource_names );

			$cell_date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

			$week[] = array(
				'day'     => $day,
				'type'    => $cell_type,
				'entries' => $entries,
				'url'     => $this->get_dashboard_url(
					array(
						'vkbm_date' => $cell_date,
						'vkbm_view' => 'day',
					)
				),
			);

			if ( 7 === count( $week ) ) {
				$weeks[] = $week;
				$week    = array();
			}
		}

		if ( ! empty( $week ) ) {
			$week_count = count( $week );
			while ( $week_count < 7 ) {
				$week[] = array(
					'day'     => '',
					'type'    => 'muted',
					'url'     => '',
					'entries' => array(),
				);
				++$week_count;
			}
			$weeks[] = $week;
		}

		return array(
			'weeks' => $weeks,
		);
	}

	/**
	 * Format monthly booking records for template consumption.
	 *
	 * @param array<int, array<string, mixed>> $bookings Raw booking rows.
	 * @param array<int, string>               $resource_names Resource display names.
	 * @return array<int, array<string, mixed>> customer_flag は顧客フラグの印に使う値（get_card_customer_flag() の戻り値）。
	 */
	private function format_month_cell_bookings( array $bookings, array $resource_names ): array {
		$entries = array();

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

			$customer_label = '' !== $customer ? $customer : __( 'Name not entered', 'vk-booking-manager' );
			$staff_label    = $resource_names[ $resource_id ] ?? __( 'Person in charge not set', 'vk-booking-manager' );

			$status_badge = $this->get_month_booking_status_badge( $status );
			$stay_type    = (string) ( $booking['stay_type'] ?? '' );
			$nights       = (int) ( $booking['nights'] ?? 0 );
			$stay_day     = (int) ( $booking['stay_day'] ?? 0 );
			if ( 'in' === $stay_type ) {
				$time_label = sprintf(
					/* translators: 1: room, 2: check-in date, 3: check-in time, 4: check-out date, 5: check-out time, 6: formatted nights label. */
					__( '%1$s IN %2$s %3$s ~ %4$s %5$s (%6$s)', 'vk-booking-manager' ),
					$staff_label,
					(string) ( $booking['start_date'] ?? '' ),
					$start_label,
					(string) ( $booking['end_date'] ?? '' ),
					$end_label,
					Lodging_Booking::format_nights_label( $nights )
				);
			} elseif ( 'stay' === $stay_type ) {
				$time_label = sprintf(
					/* translators: 1: room, 2: current night, 3: total nights. */
					__( '%1$s Staying (night %2$d of %3$d)', 'vk-booking-manager' ),
					$staff_label,
					$stay_day,
					$nights
				);
			} elseif ( 'out' === $stay_type ) {
				$time_label = sprintf(
					/* translators: 1: room, 2: check-out time. */
					__( '%1$s OUT %2$s', 'vk-booking-manager' ),
					$staff_label,
					$end_label
				);
			} else {
				$time_label = sprintf( '%s ～ %s', $start_label, $end_label );
			}

			if ( '' !== $status_badge ) {
				$time_label .= sprintf( ' [ %s ]', $status_badge );
			}

			if ( 'in' === $stay_type ) {
				$customer_line = $customer_label;
			} elseif ( '' !== $stay_type ) {
				$customer_line = '';
			} else {
				$customer_line = sprintf(
					/* translators: 1: Customer name, 2: Staff name */
					__( '%1$s / %2$s', 'vk-booking-manager' ),
					$customer_label,
					$staff_label
				);
			}

			$entries[] = array(
				'id'            => (string) ( $booking['post_id'] ?? '' ),
				'time_line'     => $time_label,
				'customer_line' => $customer_line,
				// 宿泊の中日・OUT の小さな札は顧客名を出さないため、印も出さない。
				'customer_flag' => '' !== $customer_line ? $this->get_card_customer_flag( (int) ( $booking['post_id'] ?? 0 ) ) : null,
				'url'           => $url,
				'class'         => 'vkbm-month-booking--' . $this->booking_status_to_month_modifier( $status ) . ( in_array( $stay_type, array( 'stay', 'out' ), true ) ? ' vkbm-month-booking--compact' : '' ),
			);
		}

		return $entries;
	}

	/**
	 * Render the notifications panel shown alongside the dashboard views.
	 *
	 * @param array<int, array<string, string|bool>>                                      $notifications Pending booking data.
	 * @param array{items:array<int, array<string, string>>,total:int,allow_overlap:bool} $staff_conflicts Staff conflict data.
	 */
	private function render_pending_notifications_panel( array $notifications, array $staff_conflicts = array() ): void {
		$has_pending    = ! empty( $notifications );
		$conflict_items = $staff_conflicts['items'] ?? array();
		$has_conflicts  = ! empty( $conflict_items );
		$allow_overlap  = ! empty( $staff_conflicts['allow_overlap'] );
		?>
		<aside class="vkbm-shift-dashboard__panel vkbm-notification-panel" aria-label="<?php esc_attr_e( 'Booking notifications', 'vk-booking-manager' ); ?>">
			<div class="vkbm-notification-panel__inner">
				<h2 class="vkbm-notification-panel__title"><?php esc_html_e( 'Notice', 'vk-booking-manager' ); ?></h2>
				<?php if ( $has_conflicts ) : ?>
					<?php $conflict_resource_label = vkbm_get_resource_label_singular(); ?>
					<section class="vkbm-notification-panel__section vkbm-alert vkbm-alert__<?php echo $allow_overlap ? 'warning' : 'danger'; ?>" role="<?php echo $allow_overlap ? 'status' : 'alert'; ?>">
						<h3>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: resource label (singular), e.g. "staff". */
									__( 'Bookings the assigned %s cannot accommodate', 'vk-booking-manager' ),
									$conflict_resource_label
								)
							);
							?>
						</h3>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: resource label (singular), e.g. "staff". */
									__( 'There are bookings the assigned %s cannot accommodate.', 'vk-booking-manager' ),
									$conflict_resource_label
								)
							);
							echo ' ';
							if ( $allow_overlap ) {
								esc_html_e( 'They were saved because overlapping bookings are allowed, but we recommend reviewing the details.', 'vk-booking-manager' );
							} else {
								echo esc_html(
									sprintf(
										/* translators: %s: resource label (singular), e.g. "staff". */
										__( 'Please review the details and reassign the %s.', 'vk-booking-manager' ),
										$conflict_resource_label
									)
								);
							}
							?>
						</p>
						<ul class="vkbm-notification-panel__list">
							<?php foreach ( $conflict_items as $notification ) : ?>
								<li class="vkbm-notification-panel__item">
									<div class="vkbm-notification-card">
									<div class="vkbm-notification-card__details">
										<div class="vkbm-notification-card__time"><?php echo esc_html( $notification['time_label'] ); ?>
										<?php
										if ( self::BOOKING_STATUS_PENDING === $notification['status'] ) :
											?>
											<small><?php esc_html_e( 'Pending', 'vk-booking-manager' ); ?></small><?php endif; ?></div>
										<div class="vkbm-notification-card__user">
											<?php
											printf(
												/* translators: 1: Customer name, 2: Staff name */
												esc_html__( '%1$s / %2$s', 'vk-booking-manager' ),
												esc_html( $notification['customer'] ),
												esc_html( $notification['staff'] )
											);
											?>
										</div>
										<div class="vkbm-notification-card__reason"><?php echo esc_html( Staff_Conflict_Detector::REASON_OVERLAP === $notification['reason'] ? __( 'There is another booking in the same time period.', 'vk-booking-manager' ) : __( 'There is not enough remaining capacity.', 'vk-booking-manager' ) ); ?></div>
									</div>
									<div class="vkbm-notification-card__actions"><a class="button button-secondary button-small" href="<?php echo esc_url( $notification['url'] ); ?>"><?php esc_html_e( 'Detail', 'vk-booking-manager' ); ?></a></div>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php
						if ( (int) $staff_conflicts['total'] > 10 ) :
							?>
								<p>
									<?php
									printf(
										/* translators: %d: Number of additional bookings */
										esc_html__( 'There are %d more.', 'vk-booking-manager' ),
										(int) $staff_conflicts['total'] - 10
									);
									echo ' ';
									esc_html_e( 'Please review them starting with the nearest date and time.', 'vk-booking-manager' );
									?>
								</p>
							<?php endif; ?>
					</section>
				<?php endif; ?>
				<section class="vkbm-notification-panel__section">
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
											<?php if ( ! empty( $notification['needs_staff'] ) ) : ?>
												<?php
												// 担当未定の行は行き先を割り当てリンク1つにまとめる。URL が空でも編集画面へ誘導できるようにする。
												$assign_url = '' !== $url ? $url : admin_url( sprintf( 'post.php?post=%d&action=edit', (int) ( $notification['id'] ?? 0 ) ) );
												?>
												<a
													class="button button-primary button-small"
													href="<?php echo esc_url( $assign_url ); ?>"
												>
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: resource label. */
															__( 'Assign %s', 'vk-booking-manager' ),
															vkbm_get_resource_label_singular()
														)
													);
													?>
												</a>
											<?php else : ?>
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
											<?php endif; ?>
										</div>
									</div>
								</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				</section>
			</div>
		</aside>
		<?php
	}

	/**
	 * Resolve the cell type for the month calendar.
	 *
	 * @param int $working_count  Number of working resources.
	 * @param int $closed_count    Number of closed resources.
	 * @param int $resource_count  Total resources.
	 * @param int $booking_count   Number of bookings.
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
	 * 役職（_vkbm_resource_role）→ 抜粋の順で返す。どちらも無ければ空文字（バッジを出さない）。
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

		// 役職も抜粋も無い列は、固定の「スタッフ」を出さずバッジごと省く。
		return '';
	}

	/**
	 * 担当未割当のため確定を止めたときの利用者向けメッセージを返す。
	 *
	 * @return string メッセージ。
	 */
	private function get_confirm_staff_required_message(): string {
		$label = vkbm_get_resource_label_singular();

		$message = sprintf(
			/* translators: %s: resource label. */
			__( 'No %s is assigned, so the reservation was not confirmed.', 'vk-booking-manager' ),
			$label
		);
		// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。
		$message .= ' ' . sprintf(
			/* translators: %s: resource label. */
			__( 'Please assign a %s in the reservation details.', 'vk-booking-manager' ),
			$label
		);

		return $message;
	}

	/**
	 * 担当の時間が重なるため確定を止めたときの利用者向けメッセージを返す。
	 *
	 * @param int $staff_id 競合した担当スタッフID。
	 * @return string メッセージ。
	 */
	private function get_confirm_staff_conflict_message( int $staff_id ): string {
		$label = vkbm_get_resource_label_singular();
		$name  = (string) get_the_title( $staff_id );

		if ( '' !== $name ) {
			$message = sprintf(
				/* translators: 1: resource label, 2: resource name. */
				__( 'The %1$s (%2$s) has another reservation in the same time slot, so this reservation cannot be confirmed.', 'vk-booking-manager' ),
				$label,
				$name
			);
		} else {
			$message = sprintf(
				/* translators: %s: resource label. */
				__( 'The %s has another reservation in the same time slot, so this reservation cannot be confirmed.', 'vk-booking-manager' ),
				$label
			);
		}

		// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。
		$message .= ' ' . sprintf(
			/* translators: %s: resource label. */
			__( 'Please change the %s in the reservation details.', 'vk-booking-manager' ),
			$label
		);

		return $message;
	}

	/**
	 * AJAX handler: confirm a pending booking from the dashboard.
	 */
	public function ajax_confirm_booking(): void {
		check_ajax_referer( 'vkbm_confirm_booking', 'nonce' );

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error(
				array( 'message' => __( "You don't have permission.", 'vk-booking-manager' ) ),
				403
			);
		}

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'No reservations found.', 'vk-booking-manager' ) ),
				400
			);
		}

		$booking_post = get_post( $booking_id );
		if ( ! $booking_post || Booking_Post_Type::POST_TYPE !== $booking_post->post_type ) {
			wp_send_json_error(
				array( 'message' => __( 'The target reservation could not be retrieved.', 'vk-booking-manager' ) ),
				404
			);
		}

		$current_status = (string) get_post_meta( $booking_id, self::META_BOOKING_STATUS, true );
		if ( self::BOOKING_STATUS_CONFIRMED === $current_status ) {
			wp_send_json_success( array( 'status' => self::BOOKING_STATUS_CONFIRMED ) );
		}

		// #652: ステータスを更新して確定メールを送る前に、予約の編集画面・クイック編集と同じ
		// 担当スタッフのガード（担当未割当・競合）を通す。確定メールは取り消せないため、更新より前に判定する。
		$resource_id = (int) get_post_meta( $booking_id, self::META_BOOKING_RESOURCE, true );
		if ( Staff_Conflict_Detector::is_staff_unassigned( self::BOOKING_STATUS_CONFIRMED, $resource_id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'staff_required',
					'message' => $this->get_confirm_staff_required_message(),
				),
				400
			);
		}

		// 終了日時は、バッファ込みの終了（total_end）とサービス終了（service_end）の遅い方で判定する。
		// 形式は同じ 'Y-m-d H:i:s' のため文字列比較で足りる（片方が空なら max() は他方を返す）。
		$conflict_end = max(
			(string) get_post_meta( $booking_id, self::META_BOOKING_TOTAL_END, true ),
			(string) get_post_meta( $booking_id, self::META_BOOKING_END, true )
		);

		$has_conflict = Staff_Conflict_Detector::is_staff_in_conflict(
			$resource_id,
			$booking_id,
			(string) get_post_meta( $booking_id, self::META_BOOKING_START, true ),
			$conflict_end,
			(int) get_post_meta( $booking_id, self::META_BOOKING_SERVICE, true ),
			max( 1, (int) get_post_meta( $booking_id, self::META_BOOKING_GUESTS, true ) )
		);
		if ( $has_conflict && ! Staff_Conflict_Detector::is_staff_overlap_allowed_in_admin() ) {
			wp_send_json_error(
				array(
					'code'    => 'staff_conflict',
					'message' => $this->get_confirm_staff_conflict_message( $resource_id ),
				),
				409
			);
		}

		// #474: このメソッド自身のメタ書き込みより前にスナップショットを確保する（外部連携用の
		// ディスパッチャーへ「変更前の内容」として渡す）。
		$event_before = $this->event_dispatcher->capture_snapshot( $booking_id );

		$updated = update_post_meta( $booking_id, self::META_BOOKING_STATUS, self::BOOKING_STATUS_CONFIRMED );
		if ( ! $updated ) {
			wp_send_json_error(
				array( 'message' => __( 'Your reservation could not be updated.', 'vk-booking-manager' ) ),
				500
			);
		}

		// #474 レビュー対応: 従来はここで都度生成していた（他クラスとの書き方の不揃い。司の
		// decision record 参照）。他クラスと同じ「未注入時のみ都度生成」に揃える（既存挙動は変えない）。
		$notification_service = $this->notification_service ?? new Booking_Notification_Service( new Settings_Repository() );
		$notification_service->handle_status_transition( $booking_id, $current_status, self::BOOKING_STATUS_CONFIRMED );

		$this->event_dispatcher->dispatch_change( $booking_id, $event_before );

		wp_send_json_success( array( 'status' => self::BOOKING_STATUS_CONFIRMED ) );
	}

	/**
	 * Normalize raw slot data to start/end pairs.
	 *
	 * @param array<int, array<string, string>> $slots Raw slot definition.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_slots( array $slots ): array {
		$normalized = array();

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = isset( $slot['start'] ) ? $this->sanitize_time_string( (string) $slot['start'] ) : '';
			$end   = isset( $slot['end'] ) ? $this->sanitize_time_string( (string) $slot['end'] ) : '';

			if ( '' === $start || '' === $end || $end <= $start ) {
				continue;
			}

			$normalized[] = array(
				'start' => $start,
				'end'   => $end,
			);
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
		// 宿泊の日表示では時間帯の有無ではなく、販売日の共通規則で状態を決める。
		if ( Industry_Presets::supports( 'lodging' ) && self::STATUS_NOT_SET !== $status_key ) {
			if ( Shift_Sale_Days::is_sale_entry( array( 'status' => $status_key ) ) ) {
				return array(
					'label' => __( 'Days on sale', 'vk-booking-manager' ),
					'type'  => 'working',
				);
			}

			return array(
				'label' => __( 'Not on sale', 'vk-booking-manager' ),
				'type'  => 'off',
			);
		}

		if ( 0 === $slot_count ) {
			if ( $this->is_closed_status( $status_key ) ) {
				return array(
					'label' => $this->get_closed_status_label( $status_key ),
					'type'  => 'off',
				);
			}

			return array(
				'label' => __( 'Shift not set', 'vk-booking-manager' ),
				'type'  => 'pending',
			);
		}

		if ( self::STATUS_TEMPORARY_OPEN === $status_key ) {
			return array(
				'label' => __( 'Temporary work', 'vk-booking-manager' ),
				'type'  => 'working',
			);
		}

		return array(
			'label' => __( 'Work schedule', 'vk-booking-manager' ),
			'type'  => 'working',
		);
	}

	/**
	 * 時間帯の無いレーンに表示する文言を決める。
	 *
	 * @param array{label:string,type:string} $status_info 表示用の状態。
	 * @return string 空の場合はレーンに文言を表示しない。
	 */
	private function resolve_empty_lane_label( array $status_info ): string {
		if ( 'off' === $status_info['type'] ) {
			return $status_info['label'];
		}

		// 宿泊の販売日は時間帯を持たないため、未設定の案内を表示しない。
		if ( Industry_Presets::supports( 'lodging' ) && 'working' === $status_info['type'] ) {
			return '';
		}

		return __( 'Shift not set.', 'vk-booking-manager' );
	}

	/**
	 * Partition bookings that intersect the provided slot.
	 *
	 * @param array<int, array<string, mixed>> &$bookings Bookings list (will be mutated).
	 * @param float                            $slot_start Slot start (hours).
	 * @param float                            $slot_end   Slot end (hours).
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_bookings_for_slot( array &$bookings, float $slot_start, float $slot_end ): array {
		$assigned = array();

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
				array(
					'start_decimal' => $clamped_start,
					'end_decimal'   => max( $clamped_start + 0.1, $clamped_end ),
				)
			);
			unset( $bookings[ $index ] );
		}

		return $assigned;
	}

	/**
	 * 予約が属するスロットの最大受付数（メニューの1枠あたり最大受付数）を取得する。
	 *
	 * @param int $post_id 予約投稿ID。
	 * @return int 最大受付数（最小1）。
	 */
	private function get_booking_slot_capacity( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 1;
		}

		$service_id = (int) get_post_meta( $post_id, self::META_BOOKING_SERVICE, true );
		if ( $service_id <= 0 ) {
			return 1;
		}

		return max( 1, (int) get_post_meta( $service_id, self::META_MENU_MAX_CAPACITY, true ) );
	}

	/**
	 * Map booking data to card structure for templates.
	 *
	 * @param array<string, mixed> $booking Booking payload.
	 * @return array<string, mixed>
	 */
	private function map_booking_to_card( array $booking ): array {
		$status = (string) ( $booking['status'] ?? self::BOOKING_STATUS_CONFIRMED );
		$class  = $this->booking_status_to_class( $status );
		if ( in_array( (string) ( $booking['stay_type'] ?? '' ), array( 'stay', 'out' ), true ) ) {
			$class .= ' vkbm-booking-card--continues';
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

		$stay_type  = (string) ( $booking['stay_type'] ?? '' );
		$customer   = (string) ( $booking['customer'] ?? '' );
		$stay_label = '';
		$continuing = '';
		if ( 'in' === $stay_type ) {
			$stay_label = sprintf( /* translators: %s: check-in time. */ __( 'IN %s~', 'vk-booking-manager' ), (string) ( $booking['start_label'] ?? '' ) );
		} elseif ( 'stay' === $stay_type ) {
			$stay_label = sprintf(
				/* translators: 1: current night, 2: total nights. */
				__( 'Staying (night %1$d of %2$d)', 'vk-booking-manager' ),
				(int) ( $booking['stay_day'] ?? 0 ),
				(int) ( $booking['nights'] ?? 0 )
			);
			$continuing = __( 'Continued from previous day', 'vk-booking-manager' );
		} elseif ( 'out' === $stay_type ) {
			$stay_label = sprintf( /* translators: %s: check-out time. */ __( 'OUT ~%s', 'vk-booking-manager' ), (string) ( $booking['end_label'] ?? '' ) );
			$continuing = __( 'Continued from previous day', 'vk-booking-manager' );
		}

		return array(
			'class'              => $class,
			'time'               => $booking['time_range'] ?? '',
			'stay_label'         => $stay_label,
			'continuing'         => $continuing,
			'customer'           => $customer,
			'customer_flag'      => $this->get_card_customer_flag( (int) ( $booking['post_id'] ?? 0 ) ),
			'service'            => $booking['service'] ?? '',
			// 予約人数。複数スタッフへ配分された予約は、このレーン担当分（staff_guests）を表示する。
			// 配分情報が無い場合は予約全体の人数を表示する（未設定の既存予約は1名）。
			'guests'             => isset( $booking['staff_guests'] )
				? max( 1, (int) $booking['staff_guests'] )
				: max( 1, (int) get_post_meta( (int) ( $booking['post_id'] ?? 0 ), self::META_BOOKING_GUESTS, true ) ),
			// この予約が属するスロットの最大受付数（メニューの1枠あたり最大受付数）。
			// カードに「何人中何人」を表示するための分母として使う。
			'slot_capacity'      => $this->get_booking_slot_capacity( (int) ( $booking['post_id'] ?? 0 ) ),
			// 最小催行人数（グループ開催型）の催行状態。'none'/'pending'/'fulfilled'。
			// 'pending'（未達）の枠を管理画面で色付け可視化するために使う。
			'min_capacity'       => (int) ( $booking['min_capacity'] ?? 0 ),
			'group_guests'       => (int) ( $booking['group_guests'] ?? 0 ),
			'min_capacity_state' => (string) ( $booking['min_capacity_state'] ?? 'none' ),
			'start_decimal'      => $booking['start_decimal'] ?? 0,
			'end_decimal'        => $booking['end_decimal'] ?? 0,
			'url'                => $edit_url,
			'overlap_index'      => $booking['overlap_index'] ?? 0,
			'overlap_total'      => $booking['overlap_total'] ?? 1,
		);
	}

	/**
	 * 予約カードに出す顧客フラグ（予約者に割り当てられた種別名とブロック有無）を返す。
	 *
	 * @param int $booking_id 予約投稿 ID。
	 * @return array{label: string, blocks_booking: bool}|null フラグが無い場合は null。
	 */
	private function get_card_customer_flag( int $booking_id ): ?array {
		if ( $booking_id <= 0 ) {
			return null;
		}

		$flag = $this->customer_flag_service->get_flag( (int) get_post_field( 'post_author', $booking_id ) );
		if ( null === $flag ) {
			return null;
		}

		return array(
			'label'          => (string) $flag['label'],
			'blocks_booking' => ! empty( $flag['blocks_booking'] ),
		);
	}

	/**
	 * 予約カード（日表示・月表示）に顧客フラグの印を出力する。
	 *
	 * 種別名は文字で出さず印だけを出す（受付の画面などを顧客に覗かれても意味が分からないように）。
	 * 種別名はマウスを重ねたとき（title）と読み上げでだけ伝える。
	 *
	 * @param array{label: string, blocks_booking: bool}|null $flag get_card_customer_flag() の戻り値。
	 * @return void
	 */
	private function render_customer_flag_mark( ?array $flag ): void {
		if ( null === $flag ) {
			return;
		}

		$classes = array( 'vkbm-customer-flag-mark' );
		if ( ! empty( $flag['blocks_booking'] ) ) {
			$classes[] = 'vkbm-customer-flag-mark--blocks';
		}

		/* translators: %s: 顧客フラグのラベル名。 */
		$text = sprintf( __( 'Customer flag: %s', 'vk-booking-manager' ), (string) $flag['label'] );

		echo '<span class="' . esc_attr( implode( ' ', $classes ) ) . '" title="' . esc_attr( $text ) . '">';
		echo '<span class="dashicons dashicons-flag" aria-hidden="true"></span>';
		echo '<span class="screen-reader-text">' . esc_html( $text ) . '</span>';
		echo '</span>';
	}

	/**
	 * Retrieve booking status labels.
	 *
	 * @return array<string, string>
	 */
	private function get_booking_status_labels(): array {
		return array(
			self::BOOKING_STATUS_CONFIRMED => __( 'Confirmed', 'vk-booking-manager' ),
			self::BOOKING_STATUS_PENDING   => __( 'Pending', 'vk-booking-manager' ),
			self::BOOKING_STATUS_CANCELLED => __( 'Cancelled', 'vk-booking-manager' ),
			self::BOOKING_STATUS_NO_SHOW   => __( 'No-show', 'vk-booking-manager' ),
		);
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
		$labels = array();

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
	private function get_dashboard_url( array $args = array() ): string {
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
