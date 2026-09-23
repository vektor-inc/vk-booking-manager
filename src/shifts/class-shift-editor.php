<?php
/**
 * Shift editor for managing shift posts.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Shifts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_Post;

/**
 * Handles the Shift editing UI and meta persistence.
 */
class Shift_Editor {
	private const NONCE_ACTION      = 'vkbm_shift_meta';
	private const NONCE_NAME        = '_vkbm_shift_meta_nonce';
	private const BULK_NONCE_ACTION = 'vkbm_shift_bulk_create';
	private const BULK_NONCE_NAME   = '_vkbm_shift_bulk_create_nonce';
	private const BULK_ACTION       = 'vkbm_shift_bulk_create';

	// シフトの自動登録（WP-Cron）で使うフック名。
	private const AUTO_REGISTER_ACTION = 'vkbm_shift_auto_register_daily';
	// シフトの自動登録を許可する月数（0=無効、1〜3=翌月〜3ヶ月先まで）。
	private const AUTO_REGISTER_ALLOWED_MONTHS = array( 0, 1, 2, 3 );
	// 毎日の自動登録を実行する固定時刻（サイトのタイムゾーン）。
	private const AUTO_REGISTER_DAILY_HOUR   = 0;
	private const AUTO_REGISTER_DAILY_MINUTE = 5;
	// 有効化・自己修復（admin_init）で daily スケジュールを新規登録した際、次回の daily 実行を
	// 待たせずに結果を反映させるための、単発実行までの遅延（秒）。設定保存時はこの遅延を待たず
	// その場で実行するため使わない（ensure_auto_register_schedule() でのみ使用）。
	private const AUTO_REGISTER_IMMEDIATE_DELAY = 60;
	// 自動登録の多重実行を防ぐための簡易ロック（オプション名・タイムアウト秒）。
	private const AUTO_REGISTER_LOCK_OPTION  = 'vkbm_shift_auto_register_lock';
	private const AUTO_REGISTER_LOCK_TIMEOUT = 300;

	public const META_RESOURCE            = '_vkbm_shift_resource_id';
	public const META_YEAR                = '_vkbm_shift_year';
	public const META_MONTH               = '_vkbm_shift_month';
	private const META_DAYS               = '_vkbm_shift_days';
	private const META_DEFAULT_STAFF_FLAG = '_vkbm_shift_default_staff';

	private const DAY_STATUS_OPEN             = 'open';
	private const DAY_STATUS_REGULAR_HOLIDAY  = 'regular_holiday';
	private const DAY_STATUS_TEMPORARY_OPEN   = 'temporary_open';
	private const DAY_STATUS_TEMPORARY_CLOSED = 'temporary_closed';
	private const DAY_STATUS_UNAVAILABLE      = 'unavailable';

	/**
	 * Day statuses that should be treated as closed (no slots).
	 *
	 * @var array<int, string>
	 */
	private const CLOSED_DAY_STATUSES = array(
		self::DAY_STATUS_REGULAR_HOLIDAY,
		self::DAY_STATUS_TEMPORARY_CLOSED,
		self::DAY_STATUS_UNAVAILABLE,
	);

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Shift_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vkbm_shift_get_template', array( $this, 'ajax_get_template' ) );
		add_action( 'admin_notices', array( $this, 'render_bulk_create_panel' ), 1 );
		add_action( 'admin_notices', array( $this, 'render_bulk_create_notice' ), 5 );
		add_action( 'admin_post_' . self::BULK_ACTION, array( $this, 'handle_bulk_create' ) );

		// シフトの自動登録（WP-Cron）。
		// その場（同期的）での自動登録の実行は、設定画面の保存成功時
		// （Provider_Settings_Page::handle_form_submission() から run_auto_register_on_settings_saved()
		// 経由）に一本化する（安藤レビュー指摘T1）。WordPress の update_option() は、保存前後で
		// 値が変わらない場合 update_option_{OPTION_KEY} フックを発火させないため、このフックだけに
		// 頼ると「設定値を変えない保存」で自動登録が動かず、完全に削除したシフトを作り直せない。
		// add_option_ / update_option_ フックは、設定画面以外（WP-CLI の `wp option update` 等）で
		// option が更新された場合に daily スケジュール（毎日の再実行）だけを現在の設定値へ追随
		// させる役割に限定し、その場での自動登録は行わない（値が変わった保存で二重に実行しない
		// ための整理）。
		// daily スケジュールの確認は、全リクエストで走る 'init' ではなく上記フックと管理画面表示時
		// （admin_init）に寄せる。admin_init はプラグイン更新直後など「既に有効な設定があるが
		// 予約が無い」状態の取りこぼしに対する保険（次に管理画面を開いたときに自己修復する）で、
		// 管理画面の表示を遅くしないよう、こちらは同期実行せず従来どおり予約（単発実行を含む）
		// のままにする。
		add_action( self::AUTO_REGISTER_ACTION, array( $this, 'handle_auto_register' ) );
		add_action( 'add_option_' . Settings_Repository::OPTION_KEY, array( $this, 'handle_settings_added' ) );
		add_action( 'update_option_' . Settings_Repository::OPTION_KEY, array( $this, 'handle_settings_updated' ) );
		add_action( 'admin_init', array( $this, 'self_heal_auto_register_schedule' ) );
	}

	/**
	 * Derive default day entries for the given resource and month.
	 *
	 * @param int $resource_id Resource ID.
	 * @param int $year        Year.
	 * @param int $month       Month (1-12).
	 * @return array<int, array<string, mixed>>
	 */
	public function derive_default_days( int $resource_id, int $year, int $month ): array {
		$resource_id = $this->sanitize_resource_id( $resource_id );
		$year        = $this->sanitize_year( $year );
		$month       = $this->sanitize_month( $month );

		return $this->derive_days_from_template( $resource_id, $year, $month );
	}

	/**
	 * Add the shift meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'vkbm-shift-editor',
			__( 'Shift details', 'vk-booking-manager' ),
			array( $this, 'render_meta_box' ),
			Shift_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the shift editor meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$state = $this->get_editor_state( $post->ID );

		$resource_id     = $state['resource_id'];
		$year            = $state['year'];
		$month           = $state['month'];
		$days_for_editor = $state['days_for_editor'];
		$year_options    = $this->get_year_options( $state['current_year'] );
		$resource_posts  = $this->get_resource_posts();

		$days_json = wp_json_encode( $days_for_editor );
		?>
		<p>
			<label for="vkbm-shift-resource"><?php esc_html_e( 'Staff (resources)', 'vk-booking-manager' ); ?></label><br />
			<?php if ( Staff_Editor::is_enabled() ) : ?>
				<select id="vkbm-shift-resource" name="vkbm_shift[resource_id]" class="widefat">
					<option value="0"><?php esc_html_e( 'Select staff', 'vk-booking-manager' ); ?></option>
					<?php foreach ( $resource_posts as $resource ) : ?>
						<option value="<?php echo esc_attr( (string) $resource->ID ); ?>" <?php selected( $resource_id, $resource->ID ); ?>>
							<?php echo esc_html( vkbm_get_resource_display_name( (int) $resource->ID ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="hidden" id="vkbm-shift-resource" name="vkbm_shift[resource_id]" value="<?php echo esc_attr( (string) $resource_id ); ?>" />
				<span class="description"><?php esc_html_e( 'In the free version, the staff is fixed.', 'vk-booking-manager' ); ?></span>
			<?php endif; ?>
		</p>
		<div class="vkbm-shift-period">
			<label class="screen-reader-text" for="vkbm-shift-year"><?php esc_html_e( 'year', 'vk-booking-manager' ); ?></label>
			<select id="vkbm-shift-year" name="vkbm_shift[year]">
				<?php foreach ( $year_options as $option_year ) : ?>
					<option value="<?php echo esc_attr( (string) $option_year ); ?>" <?php selected( $year, $option_year ); ?>>
						<?php echo esc_html( $option_year ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="vkbm-shift-period-suffix"><?php esc_html_e( 'year', 'vk-booking-manager' ); ?></span>
			<label class="screen-reader-text" for="vkbm-shift-month"><?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?></label>
			<select id="vkbm-shift-month" name="vkbm_shift[month]">
				<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
					<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $month, $m ); ?>>
						<?php echo esc_html( sprintf( '%02d', $m ) ); ?>
					</option>
				<?php endfor; ?>
			</select>
			<span class="vkbm-shift-period-suffix"><?php esc_html_e( 'Mon', 'vk-booking-manager' ); ?></span>
		</div>

		<input type="hidden" id="vkbm-shift-days-json" name="vkbm_shift[days_json]" value="<?php echo esc_attr( (string) $days_json ); ?>" />

		<div class="vkbm-shift-days vkbm-schedule-container" id="vkbm-shift-days">
			<table class="form-table vkbm-setting-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'date', 'vk-booking-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'time', 'vk-booking-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="vkbm-shift-days-body">
					<tr>
						<td colspan="2"><?php esc_html_e( 'By setting the year and month, you can edit the daily working hours.', 'vk-booking-manager' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

			<script type="text/template" id="vkbm-shift-day-row-template">
			<tr class="vkbm-shift-day-row" data-day="__DAY__">
				<th scope="row" class="vkbm-shift-day-label">
					<div class="vkbm-shift-day-label-text"></div>
					<div class="vkbm-shift-day-status-wrap">
						<select class="vkbm-shift-day-status" data-day="__DAY__"></select>
					</div>
				</th>
				<td class="vkbm-shift-time-cell">
					<div class="vkbm-shift-day-message" aria-live="polite"></div>
					<div class="vkbm-shift-day-slots vkbm-schedule-slot-list"></div>
					<button type="button" class="button vkbm-shift-add-slot vkbm-schedule-add-slot" data-day="__DAY__">
						<?php esc_html_e( 'Add time zone', 'vk-booking-manager' ); ?>
					</button>
				</td>
			</tr>
		</script>

		<script type="text/template" id="vkbm-shift-slot-template">
			<div class="vkbm-shift-slot vkbm-schedule-slot" data-index="__INDEX__">
				<div class="vkbm-schedule-time-range">
					<div class="vkbm-schedule-time-select">
						<label class="screen-reader-text" data-field="start_hour"><?php esc_html_e( 'Start time (hour)', 'vk-booking-manager' ); ?></label>
						<select class="vkbm-schedule-hour" data-field="start_hour">
							<option value="00">00</option>
							<?php for ( $h = 1; $h <= 23; $h++ ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>"><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
							<?php endfor; ?>
						</select>
						<span class="vkbm-schedule-colon">:</span>
						<label class="screen-reader-text" data-field="start_minute"><?php esc_html_e( 'Start time (minutes)', 'vk-booking-manager' ); ?></label>
						<select class="vkbm-schedule-minute" data-field="start_minute">
							<?php foreach ( array( '00', '10', '20', '30', '40', '50' ) as $minute ) : ?>
								<option value="<?php echo esc_attr( $minute ); ?>"><?php echo esc_html( $minute ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<span class="vkbm-schedule-range-delimiter">〜</span>
					<div class="vkbm-schedule-time-select">
						<label class="screen-reader-text" data-field="end_hour"><?php esc_html_e( 'End time (hour)', 'vk-booking-manager' ); ?></label>
						<select class="vkbm-schedule-hour" data-field="end_hour">
							<option value="00">00</option>
							<?php for ( $h = 1; $h <= 23; $h++ ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>"><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
							<?php endfor; ?>
							<option value="24">24</option>
						</select>
						<span class="vkbm-schedule-colon">:</span>
						<label class="screen-reader-text" data-field="end_minute"><?php esc_html_e( 'End time (minutes)', 'vk-booking-manager' ); ?></label>
						<select class="vkbm-schedule-minute" data-field="end_minute">
							<?php foreach ( array( '00', '10', '20', '30', '40', '50' ) as $minute ) : ?>
								<option value="<?php echo esc_attr( $minute ); ?>"><?php echo esc_html( $minute ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<button type="button" class="button-link-delete vkbm-shift-remove-slot vkbm-schedule-remove-slot" aria-label="<?php esc_attr_e( 'Remove time slot', 'vk-booking-manager' ); ?>">
						<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
					</button>
				</div>
			</div>
		</script>
		<?php
	}

	/**
	 * Save shift meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( Shift_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_STAFF, $post_id ) ) {
			return;
		}

		$payload = array(
			'resource_id' => 0,
			'year'        => 0,
			'month'       => 0,
			'days_json'   => '',
		);
		if ( isset( $_POST['vkbm_shift'] ) && is_array( $_POST['vkbm_shift'] ) ) {
			$payload['resource_id'] = absint( wp_unslash( $_POST['vkbm_shift']['resource_id'] ?? 0 ) );
			$payload['year']        = absint( wp_unslash( $_POST['vkbm_shift']['year'] ?? 0 ) );
			$payload['month']       = absint( wp_unslash( $_POST['vkbm_shift']['month'] ?? 0 ) );
			$payload['days_json']   = sanitize_text_field( (string) wp_unslash( $_POST['vkbm_shift']['days_json'] ?? '' ) );
		}
		$resource_id = $payload['resource_id'];
		$year        = $payload['year'];
		$month       = $payload['month'];
		$days_json   = $payload['days_json'];

		$resource_id = $this->sanitize_resource_id( $resource_id );
		$year        = $this->sanitize_year( $year );
		$month       = $this->sanitize_month( $month );
		$days        = $this->sanitize_days_json( $days_json, $year, $month );

		if ( empty( $days ) ) {
			$days = $this->derive_days_from_template( $resource_id, $year, $month );
		}

		update_post_meta( $post_id, self::META_RESOURCE, $resource_id );
		update_post_meta( $post_id, self::META_YEAR, $year );
		update_post_meta( $post_id, self::META_MONTH, $month );
		update_post_meta( $post_id, self::META_DAYS, $days );

		if ( ! Staff_Editor::is_enabled() && $resource_id > 0 ) {
			update_post_meta( $post_id, self::META_DEFAULT_STAFF_FLAG, 1 );
		}

		if ( $resource_id && $year && $month ) {
			$this->maybe_update_post_title( $post_id, $resource_id, $year, $month );
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();

		if ( ! $screen || Shift_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$base_url = plugin_dir_url( VKBM_PLUGIN_FILE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context for asset enqueuing.
		$get_post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context for asset enqueuing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only context for asset enqueuing. Value sanitized with absint().
		$post_id = $get_post > 0 ? $get_post : ( isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0 );
		$state   = $this->get_editor_state( $post_id );

		if ( 'edit.php' === $hook ) {
			wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

			wp_enqueue_script(
				'vkbm-shift-bulk-create',
				$base_url . 'assets/js/shift-bulk-create.js',
				array(),
				VKBM_VERSION,
				true
			);

			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script(
			'vkbm-shift-editor',
			$base_url . 'assets/js/shift-editor.js',
			array( 'jquery' ),
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-shift-editor',
			'vkbmShiftEditor',
			array(
				'daysJsonField'    => '#vkbm-shift-days-json',
				'daysContainer'    => '#vkbm-shift-days',
				'daysTableBody'    => '#vkbm-shift-days-body',
				'yearSelector'     => '#vkbm-shift-year',
				'monthSelector'    => '#vkbm-shift-month',
				'resourceSelector' => '#vkbm-shift-resource',
				'dayRowTemplate'   => '#vkbm-shift-day-row-template',
				'slotTemplate'     => '#vkbm-shift-slot-template',
				'daysData'         => $state['days_for_editor'],
				'holidayRules'     => $state['holiday_rules'],
				'statusOptions'    => $this->get_day_status_options(),
				'defaultDays'      => $state['default_days'],
				'weekdayDefaults'  => $state['weekday_defaults'],
				'initialYear'      => $state['year'],
				'initialMonth'     => $state['month'],
				'strings'          => array(
					'daySuffix'     => __( 'Sun', 'vk-booking-manager' ),
					'noResource'    => __( 'Please select staff (resources).', 'vk-booking-manager' ),
					'statusLabel'   => __( 'Operational status', 'vk-booking-manager' ),
					'closedMessage' => __( 'You cannot set the time zone in this status.', 'vk-booking-manager' ),
					'weekdayShort'  => array(
						'sun' => __( 'Sun', 'vk-booking-manager' ),
						'mon' => __( 'Mon', 'vk-booking-manager' ),
						'tue' => __( 'Tue', 'vk-booking-manager' ),
						'wed' => __( 'Wed', 'vk-booking-manager' ),
						'thu' => __( 'Thu', 'vk-booking-manager' ),
						'fri' => __( 'Fri', 'vk-booking-manager' ),
						'sat' => __( 'Sat', 'vk-booking-manager' ),
					),
				),
				'ajax'             => array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'vkbm_shift_template' ),
				),
			)
		);
	}

	/**
	 * Retrieve resource posts.
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
			)
		);
	}

	/**
	 * AJAX: return template-derived days for a resource.
	 */
	public function ajax_get_template(): void {
		check_ajax_referer( 'vkbm_shift_template' );

		if ( ! current_user_can( Capabilities::MANAGE_STAFF ) ) {
			wp_send_json_error( array( 'message' => __( "You don't have permission.", 'vk-booking-manager' ) ), 403 );
		}

		$resource_id = isset( $_POST['resource_id'] ) ? (int) $_POST['resource_id'] : 0;
		$year        = isset( $_POST['year'] ) ? (int) $_POST['year'] : 0;
		$month       = isset( $_POST['month'] ) ? (int) $_POST['month'] : 0;

		if ( $resource_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please select a staff member.', 'vk-booking-manager' ) ), 400 );
		}

		$days = $this->derive_days_from_template( $resource_id, $year, $month );

		wp_send_json_success( array( 'days' => $days ) );
	}

	/**
	 * Render bulk create panel for shift list screen.
	 */
	public function render_bulk_create_panel(): void {
		if ( ! self::is_shift_list_screen() ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_STAFF ) ) {
			return;
		}

		if ( array() === $this->get_resource_posts() ) {
			return;
		}

		$options = $this->get_bulk_month_options();
		if ( empty( $options ) ) {
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="vkbm-shift-bulk-create">
			<h2 class="vkbm-shift-bulk-create__title"><?php esc_html_e( 'Add shifts in bulk', 'vk-booking-manager' ); ?></h2>
			<p class="vkbm-shift-bulk-create__description">
				<?php esc_html_e( 'Register the shifts for the specified month as a draft for all staff members at once.', 'vk-booking-manager' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="vkbm-shift-bulk-create__form">
				<?php wp_nonce_field( self::BULK_NONCE_ACTION, self::BULK_NONCE_NAME ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::BULK_ACTION ); ?>" />
				<label class="screen-reader-text" for="vkbm-shift-bulk-month"><?php esc_html_e( 'year and month', 'vk-booking-manager' ); ?></label>
				<select id="vkbm-shift-bulk-month" name="vkbm_shift_bulk[period]" class="vkbm-shift-bulk-create__select">
					<?php foreach ( $options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary vkbm-shift-bulk-create__button">
					<?php esc_html_e( 'Bulk registration', 'vk-booking-manager' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle bulk create action.
	 */
	public function handle_bulk_create(): void {
		if ( ! current_user_can( Capabilities::MANAGE_STAFF ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vk-booking-manager' ) );
		}

		check_admin_referer( self::BULK_NONCE_ACTION, self::BULK_NONCE_NAME );

		$redirect_base = add_query_arg(
			array(
				'post_type' => Shift_Post_Type::POST_TYPE,
			),
			admin_url( 'edit.php' )
		);

		$period = '';
		if ( isset( $_POST['vkbm_shift_bulk'] ) && is_array( $_POST['vkbm_shift_bulk'] ) && isset( $_POST['vkbm_shift_bulk']['period'] ) ) {
			$period = sanitize_text_field( (string) wp_unslash( $_POST['vkbm_shift_bulk']['period'] ) );
		}

		$period_parts = explode( '-', $period );
		$year         = isset( $period_parts[0] ) ? (int) $period_parts[0] : 0;
		$month        = isset( $period_parts[1] ) ? (int) $period_parts[1] : 0;

		$year  = $this->sanitize_year( $year );
		$month = $this->sanitize_month( $month );

		if ( $year <= 0 || $month <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'vkbm_shift_bulk_error' => 'invalid_period',
					),
					$redirect_base
				)
			);
			exit;
		}

		$resources = $this->get_resource_posts();
		// 手動の一括登録では、ゴミ箱のシフトは「登録済み」に数えない（従来どおりの挙動）。
		$result = $this->create_shift_posts_for_resources( $resources, $year, $month, 'draft', false );

		$redirect_url = add_query_arg(
			array(
				'vkbm_shift_bulk_created' => (string) $result['created'],
				'vkbm_shift_bulk_skipped' => (string) $result['skipped'],
				'vkbm_shift_bulk_year'    => (string) $year,
				'vkbm_shift_bulk_month'   => (string) $month,
			),
			$redirect_base
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * 指定したリソース・年・月へシフト投稿を作成する（既にシフトがあるリソースはスキップする）。
	 *
	 * シフトの一括登録（下書き）とシフトの自動登録（公開）の両方から呼ばれる共通処理。
	 * 重複防止は shift_exists()（リソース×年×月）に委譲する。
	 *
	 * @param array<int, WP_Post> $resources               シフトを作成する対象のリソース投稿一覧。
	 * @param int                 $year                    年。
	 * @param int                 $month                   月（1〜12）。
	 * @param string              $post_status             新規作成するシフトの投稿ステータス（'draft' または 'publish'）。
	 * @param bool                $include_trash_in_dedup 重複判定でゴミ箱のシフトも「登録済み」として扱うか。
	 *                                                      自動登録では true を渡し、手動の一括登録では従来どおり
	 *                                                      false（ゴミ箱は数えない）にする。
	 * @return array{created:int, skipped:int}
	 */
	private function create_shift_posts_for_resources( array $resources, int $year, int $month, string $post_status, bool $include_trash_in_dedup = false ): array {
		$created = 0;
		$skipped = 0;

		foreach ( $resources as $resource ) {
			$resource_id = (int) $resource->ID;
			if ( $resource_id <= 0 ) {
				continue;
			}

			if ( $this->shift_exists( $resource_id, $year, $month, $include_trash_in_dedup ) ) {
				++$skipped;
				continue;
			}

			$title = sprintf(
				/* translators: 1: year, 2: month, 3: staff name */
				__( '%1$d year %2$02d month %3$s', 'vk-booking-manager' ),
				$year,
				$month,
				get_the_title( $resource )
			);

			$post_id = wp_insert_post(
				array(
					'post_type'   => Shift_Post_Type::POST_TYPE,
					'post_status' => $post_status,
					'post_title'  => $title,
				),
				true
			);

			// wp_insert_post() 失敗時はスキップのみとし、管理者通知は行わない（仕様どおり）。
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			$days = $this->derive_default_days( $resource_id, $year, $month );
			update_post_meta( (int) $post_id, self::META_RESOURCE, $resource_id );
			update_post_meta( (int) $post_id, self::META_YEAR, $year );
			update_post_meta( (int) $post_id, self::META_MONTH, $month );
			update_post_meta( (int) $post_id, self::META_DAYS, $days );
			if ( ! Staff_Editor::is_enabled() && $resource_id > 0 ) {
				update_post_meta( (int) $post_id, self::META_DEFAULT_STAFF_FLAG, 1 );
			}

			++$created;
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * WP-Cron callback: automatically register shifts (published) for the configured month range.
	 *
	 * 「今月〜今月+Nヶ月」の範囲で、未登録の月だけをリソースごとに公開ステータスで登録する。
	 * 登録済みの月は create_shift_posts_for_resources() 内の shift_exists() でスキップされるため、
	 * 同じ月に対して何度実行しても重複登録は起きない。自動登録の重複判定はゴミ箱のシフトも
	 * 「登録済み」として数える（完全に削除すると、その月は次回実行時に再登録される）。
	 * cron の多重起動に備え、簡易ロックで排他制御する。WP-Cron からだけでなく、
	 * run_auto_register_on_settings_saved()（設定画面の保存成功時）からも同期的に呼ばれる。
	 * 登録する月数は、ロックを取得した後に読み直す（安藤レビュー指摘T2(2)）。ロックを取る前に
	 * 読んでしまうと、WP-Cron の実行中に管理画面から月数を増やして保存しても、その増分がロック
	 * 取得までの間に反映されず、翌日の daily 実行まで登録されない可能性がある。
	 */
	public function handle_auto_register(): void {
		$lock_value = $this->acquire_auto_register_lock();
		if ( '' === $lock_value ) {
			// 既に別の実行がロックを保持している（または取得自体に失敗した）。多重実行を避けて何もしない。
			return;
		}

		try {
			// 設定保存の直後に同一リクエスト内で同期実行される場合に備え、get_provider_settings()
			// のリクエスト内キャッシュ（static）を保存後の最新値で読み直しておく。ここで
			// 読み直さないと、この実行より前に一度でも呼ばれていた場合、保存前の古い値のまま
			// シフトの日別ステータス（休業日等）を組み立ててしまう（司からの指摘）。
			$this->get_provider_settings( true );

			// 登録する月数は、ロックを取得した後に読み直す（安藤レビュー指摘T2(2)。理由は上記
			// メソッド PHPDoc を参照）。
			$months_ahead = $this->get_auto_register_months_ahead();

			if ( $months_ahead <= 0 ) {
				return;
			}

			$resources = $this->get_resource_posts();
			if ( array() === $resources ) {
				return;
			}

			$timezone = wp_timezone();
			$now      = new \DateTimeImmutable( 'now', $timezone );
			$base     = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 );

			for ( $offset = 0; $offset <= $months_ahead; $offset++ ) {
				$target = $base->modify( sprintf( '+%d month', $offset ) );
				if ( ! $target instanceof \DateTimeImmutable ) {
					continue;
				}

				$year  = (int) $target->format( 'Y' );
				$month = (int) $target->format( 'n' );

				$this->create_shift_posts_for_resources( $resources, $year, $month, 'publish', true );
			}
		} finally {
			$this->release_auto_register_lock( $lock_value );
		}
	}

	/**
	 * 自動登録の実行ロックの取得を試みる。
	 *
	 * WordPress 本体の WP_Upgrader::create_lock() と同じ方式（INSERT IGNORE による排他制御）を
	 * 使う（安藤レビュー指摘N2）。add_option() は内部で「既に存在する場合は更新する」
	 * INSERT ... ON DUPLICATE KEY UPDATE を使うため、同時に呼ばれた複数の実行がどちらも
	 * 「取得できた」と判定してしまう可能性がある。INSERT IGNORE は既に行があれば何もしないため、
	 * 新規に行を作成できた1つの実行だけがロックを取得したと判定できる。
	 *
	 * ロックの値は time() だけでなく `"<取得時刻>:<ランダム文字列>"` にしている
	 * （安藤レビュー指摘R1）。同じ秒に2つの実行が取得を試みても、値が一意になるため
	 * release_auto_register_lock() の一致判定で確実に区別できる。経過時間の判定は
	 * 先頭のコロンまでの数値部分（取得時刻）だけを使う。
	 *
	 * 一定時間（AUTO_REGISTER_LOCK_TIMEOUT）を過ぎたロックは、前回の実行が異常終了した
	 * ものとみなして奪い取る（WP_Upgrader::create_lock() と同じ方式）。ループの試行回数は
	 * 最大2回（初回の取得試行＋期限切れロックを奪った直後の再試行1回）に限定しており
	 * （安藤レビュー指摘R2）、DBへの書き込みが失敗し続けても無限に再試行しない。ロックの値が
	 * 読めない（空文字）場合は、期限切れかどうかの判定自体ができないため、奪い取りを試みず
	 * その場で取得失敗として抜ける。
	 *
	 * @return string ロックを取得できた場合は保存した値。取得できなかった場合は空文字列。
	 */
	private function acquire_auto_register_lock(): string {
		global $wpdb;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$lock_value = $this->generate_auto_register_lock_value();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Upgrader::create_lock() と同じ排他制御のため直接クエリが必要（add_option() のキャッシュ経由の判定では複数実行の同時取得を防げない）。
			$inserted = (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::AUTO_REGISTER_LOCK_OPTION,
					$lock_value
				)
			);

			if ( $inserted ) {
				// ロックを取得できたので、通常の保存関数を通して値とキャッシュの状態をそろえておく
				// （WP_Upgrader::create_lock() と同じ手順）。
				update_option( self::AUTO_REGISTER_LOCK_OPTION, $lock_value, false );
				return $lock_value;
			}

			// 直接クエリで挿入を試みた直後の読み取りのため、このオプションが未作成だった時点の
			// キャッシュ（個別のオプションキャッシュ・'notoptions'・'alloptions'）が残っていれば
			// 無効化してから読み直す（WordPress 本体のキャッシュの扱いに倣う）。
			wp_cache_delete( self::AUTO_REGISTER_LOCK_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );

			$existing = (string) get_option( self::AUTO_REGISTER_LOCK_OPTION, '' );

			if ( '' === $existing ) {
				// 値が読めない（DBへの書き込みが失敗し続けている等）。期限切れの判定ができない
				// ため奪い取りを試みず、再帰・再試行せずに取得失敗として抜ける（安藤レビュー指摘R2）。
				return '';
			}

			$locked_at = $this->parse_auto_register_lock_timestamp( $existing );

			if ( $locked_at <= 0 || ( time() - $locked_at ) <= self::AUTO_REGISTER_LOCK_TIMEOUT ) {
				// まだ有効なロックを他の実行が保持している（あるいは形式が不正で期限切れと判定できない）。
				return '';
			}

			// タイムアウトを超えた古いロックを奪い取り、ループの次の回で取得を再試行する
			// （最大1回だけ再試行し、それでも失敗すれば取得失敗として抜ける）。
			$this->release_auto_register_lock( $existing );
		}

		return '';
	}

	/**
	 * 自動登録の実行ロックの値を生成する。
	 *
	 * `"<取得時刻>:<ランダム文字列>"` の形式にすることで、同じ秒に複数の実行が取得を試みても
	 * 値が一意になり、release_auto_register_lock() の一致判定で確実に区別できる
	 * （安藤レビュー指摘R1）。
	 *
	 * @return string
	 */
	private function generate_auto_register_lock_value(): string {
		return time() . ':' . wp_generate_password( 12, false );
	}

	/**
	 * 自動登録の実行ロックの値から、取得時刻（先頭のコロンまでの数値部分）を取り出す。
	 *
	 * @param string $lock_value ロックの値。
	 * @return int 取得時刻（Unix タイムスタンプ）。形式が不正な場合は 0。
	 */
	private function parse_auto_register_lock_timestamp( string $lock_value ): int {
		$timestamp_part = strtok( $lock_value, ':' );

		return is_numeric( $timestamp_part ) ? (int) $timestamp_part : 0;
	}

	/**
	 * 自動登録の実行ロックを解放する。
	 *
	 * 比較（取得時に保存した値と現在の値が一致するか）と削除を1本の SQL
	 * （`DELETE ... WHERE option_name = %s AND option_value = %s`）で行う（安藤レビュー指摘R1）。
	 * get_option() で読んでから delete_option() する2段階だと、比較に使う get_option() は
	 * 個別のオプションキャッシュ（非autoloadのオプションが入る。取得成功時の update_option() が
	 * このキャッシュへ自分の値を書き込んでいる）を読んでしまう。オブジェクトキャッシュが有効な
	 * 環境（永続キャッシュが無くても、WordPress は1リクエスト内では常に非永続のオブジェクト
	 * キャッシュを使う）では、他の実行が直接クエリで値を書き換えていてもこのキャッシュには
	 * 反映されないため、比較が常に「一致」と誤判定し、他の実行に奪われたロックまで
	 * 削除してしまう。DB の実値を条件に含めた1クエリの削除であれば、この誤判定は起きない。
	 *
	 * @param string $expected_value acquire_auto_register_lock() が返した、取得時に保存した値。
	 */
	private function release_auto_register_lock( string $expected_value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 比較付き削除を1クエリで原子的に行う必要があるため直接クエリが必要（get_option() のキャッシュを介した比較では他の実行のロックを誤って削除しうる）。
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::AUTO_REGISTER_LOCK_OPTION,
				$expected_value
			)
		);

		// 直接クエリのためキャッシュが追随しない。個別のオプションキャッシュと、
		// 'notoptions' / 'alloptions' の両方を無効化しておく（WordPress 本体のキャッシュの扱いに倣う）。
		wp_cache_delete( self::AUTO_REGISTER_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * 自動登録の daily スケジュール（毎日の再実行）だけを、現在の設定値と一致させる。
	 *
	 * 設定が「無効」（またはリポジトリ既定の0）であればスケジュールを解除する。
	 * 有効な月数が設定されていて、まだ daily スケジュールが登録されていない場合は、
	 * 毎日の実行時刻を固定した daily スケジュールを登録する。保存直後の反映（今すぐの
	 * 登録・単発実行の予約）はここでは行わない。呼び出し元（handle_settings_added() /
	 * handle_settings_updated() / ensure_auto_register_schedule()）が、それぞれの文脈に応じて
	 * 即時実行するか単発予約するかを選ぶ。
	 *
	 * @return bool 新たに daily スケジュールを登録した場合は true。それ以外
	 *              （既に登録済み・無効化して解除した・もともと無効で何もしなかった）は false。
	 */
	private function ensure_daily_schedule(): bool {
		$months_ahead   = $this->get_auto_register_months_ahead();
		$next_scheduled = wp_next_scheduled( self::AUTO_REGISTER_ACTION );

		if ( $months_ahead <= 0 ) {
			if ( $next_scheduled ) {
				wp_clear_scheduled_hook( self::AUTO_REGISTER_ACTION );
			}
			return false;
		}

		if ( $next_scheduled ) {
			return false;
		}

		wp_schedule_event( $this->get_next_daily_run_timestamp(), 'daily', self::AUTO_REGISTER_ACTION );
		return true;
	}

	/**
	 * 自動登録の WP-Cron スケジュールを、現在の設定値と一致させる。
	 *
	 * プラグイン有効化時（Plugin::activate()）と admin_init の自己修復
	 * （self_heal_auto_register_schedule()）から呼ばれる。どちらも「今このリクエストで
	 * 登録処理そのものを実行する」用途ではなく、管理画面の表示を遅くしないための保険的な
	 * 経路のため、新たに daily スケジュールを登録した場合はこれまでどおり単発実行も1件
	 * 予約するに留める（設定保存時の即時実行は handle_settings_added() /
	 * handle_settings_updated() が担う。司からの指示）。
	 */
	public function ensure_auto_register_schedule(): void {
		if ( $this->ensure_daily_schedule() ) {
			// daily スケジュールは翌回まで実行されないため、反映用に単発実行も予約する。
			wp_schedule_single_event( time() + self::AUTO_REGISTER_IMMEDIATE_DELAY, self::AUTO_REGISTER_ACTION );
		}
	}

	/**
	 * 設定画面の保存が成功した直後に呼ばれる、シフト自動登録の実行口。
	 *
	 * Provider_Settings_Page::handle_form_submission() から、権限・nonce の確認を通り、かつ
	 * 設定の保存自体が成功した（バリデーションエラーが無かった）場合にだけ呼ばれる想定
	 * （安藤レビュー指摘T1）。WordPress の update_option() は、保存前後で値が変わらない場合
	 * update_option_{OPTION_KEY} フックを発火させないため、update_option_ / add_option_ フック
	 * （handle_settings_updated() / handle_settings_added()）だけに頼ると、設定値を何も変えずに
	 * 保存したときに自動登録が動かない。完全に削除したシフトを、設定を変えずに保存して
	 * 作り直そうとしても、その場では作られない不具合があった。
	 *
	 * この呼び出し口を「設定画面からの保存成功時にその場で実行する」経路として一本化し、
	 * handle_settings_added() / handle_settings_updated() は daily スケジュールの管理だけを
	 * 行う（値が変わった保存で二重に実行しないための整理）。daily スケジュールを現在の設定値に
	 * 合わせたうえで、handle_auto_register()（ロック付き。無効な場合は内部で何もしない）を実行する。
	 */
	public function run_auto_register_on_settings_saved(): void {
		$this->ensure_daily_schedule();
		$this->handle_auto_register();
	}

	/**
	 * add_option_{OPTION_KEY} フックのコールバック（設定の初回保存）。
	 *
	 * daily スケジュール（毎日の再実行）を現在の設定値に合わせるだけを行う。設定画面の保存成功時に
	 * その場で自動登録を実行する処理は run_auto_register_on_settings_saved() に一本化したため
	 * （安藤レビュー指摘T1。値を変えない保存ではこのフック自体が発火しないため、フック側に
	 * その場実行を残すと不整合になる）、ここでは行わない。設定画面以外（WP-CLI の
	 * `wp option update` 等）で option が新規作成された場合の daily スケジュールの追随は
	 * 引き続きここで担う。
	 */
	public function handle_settings_added(): void {
		$this->ensure_daily_schedule();
	}

	/**
	 * update_option_{OPTION_KEY} フックのコールバック。
	 *
	 * daily スケジュール（毎日の再実行）を現在の設定値に合わせるだけを行う。設定画面の保存成功時に
	 * その場で自動登録を実行する処理は run_auto_register_on_settings_saved() に一本化したため
	 * （安藤レビュー指摘T1）、ここでは行わない。値が変わった保存でも、設定画面からの保存であれば
	 * run_auto_register_on_settings_saved() 側で実行されるため、このフックでも重ねて実行すると
	 * 二重実行になってしまう。設定画面以外（WP-CLI の `wp option update` 等）で option が更新
	 * された場合の daily スケジュールの追随は引き続きここで担う。
	 *
	 * WordPress からは update_option_{$option} フックとして第1・第2引数（変更前後の値）を渡されて
	 * 呼ばれるが、daily スケジュールの判定は ensure_daily_schedule() 内で Settings_Repository から
	 * 都度取得した最新値を使うため、この値自体は使わない。
	 */
	public function handle_settings_updated(): void {
		$this->ensure_daily_schedule();
	}

	/**
	 * admin_init から呼ばれる自己修復の受け口。
	 *
	 * プラグイン更新直後など「既に有効な設定があるが予約が無い」状態の取りこぼしに対する保険として、
	 * 管理画面表示のたびに ensure_auto_register_schedule() を呼び、スケジュールを現在の設定値と
	 * 一致させる。Heartbeat（admin-ajax.php）のリクエストも admin_init を通過するため、そのたびに
	 * get_settings() が走ってしまわないよう、Ajax リクエストではここで抜ける（安藤レビュー指摘N3）。
	 */
	public function self_heal_auto_register_schedule(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$this->ensure_auto_register_schedule();
	}

	/**
	 * 次回の毎日実行時刻（サイトのタイムゾーンで固定した時刻）のタイムスタンプを算出する。
	 *
	 * @return int
	 */
	private function get_next_daily_run_timestamp(): int {
		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );
		$next_run = $now->setTime( self::AUTO_REGISTER_DAILY_HOUR, self::AUTO_REGISTER_DAILY_MINUTE, 0 );

		if ( $next_run <= $now ) {
			$next_run = $next_run->modify( '+1 day' );
		}

		return $next_run->getTimestamp();
	}

	/**
	 * 自動登録の WP-Cron スケジュールを解除する。
	 *
	 * プラグイン無効化時に呼び出し、WP-Cron に登録済みのイベントを解除する。
	 */
	public function clear_auto_register_schedule(): void {
		wp_clear_scheduled_hook( self::AUTO_REGISTER_ACTION );
	}

	/**
	 * シフトの自動登録で許可される月数の範囲（0=無効、1〜3=翌月〜3ヶ月先まで）へ値を正規化する。
	 *
	 * Settings_Sanitizer（保存時の検証）・Setup_Notices（注意喚起の表示判定）・本クラス
	 * （cron の実行月数判定）の3箇所で同じ判定基準を共有するために public static にしている。
	 *
	 * @param int $value 判定対象の値。
	 * @return int 0〜3 のいずれか（範囲外は0=無効）。
	 */
	public static function normalize_auto_register_months( int $value ): int {
		return in_array( $value, self::AUTO_REGISTER_ALLOWED_MONTHS, true ) ? $value : 0;
	}

	/**
	 * 指定した値がシフトの自動登録「有効」を表すかどうかを判定する。
	 *
	 * @param int $value 判定対象の値。
	 * @return bool
	 */
	public static function is_auto_register_enabled_for( int $value ): bool {
		return self::normalize_auto_register_months( $value ) > 0;
	}

	/**
	 * シフトの自動登録に設定されている月数（0=無効、1〜3=翌月〜3ヶ月先まで）を取得する。
	 *
	 * @return int
	 */
	private function get_auto_register_months_ahead(): int {
		// get_provider_settings() はリクエスト内キャッシュ（static）を持つため、
		// 設定変更を即座に反映したいこの用途（cron のたびに最新値を見る必要がある）では使わず、
		// 都度 Settings_Repository から取得する。
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();

		return $this->extract_auto_register_months( $settings );
	}

	/**
	 * 設定配列からシフトの自動登録の月数（0=無効、1〜3=翌月〜3ヶ月先まで）を取り出す。
	 *
	 * get_auto_register_months_ahead()（現在の設定値を都度取得する用途。cron や設定保存の
	 * たびに最新値を見る必要があるため使う）から使う共通処理。設定配列から値を取り出し、
	 * normalize_auto_register_months() で許可範囲へ正規化する処理をまとめている（安藤レビュー
	 * 指摘T3。以前は handle_settings_updated() が変更前後の設定配列を比較する用途でも使っていたが、
	 * その場での自動登録の実行を run_auto_register_on_settings_saved() に一本化したことに伴い、
	 * handle_settings_updated() 側の呼び出しは無くなった）。
	 *
	 * @param array<string, mixed> $settings 設定配列。
	 * @return int
	 */
	private function extract_auto_register_months( array $settings ): int {
		$value = isset( $settings['shift_auto_register_months'] ) ? (int) $settings['shift_auto_register_months'] : 0;

		return self::normalize_auto_register_months( $value );
	}

	/**
	 * Render notice after bulk create.
	 */
	public function render_bulk_create_notice(): void {
		if ( ! self::is_shift_list_screen() ) {
			return;
		}

		$error = isset( $_GET['vkbm_shift_bulk_error'] ) ? sanitize_key( (string) $_GET['vkbm_shift_bulk_error'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'invalid_period' === $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Bulk registration of shifts failed. The year and month specification is invalid.', 'vk-booking-manager' ); ?></p>
			</div>
			<?php
			return;
		}

		$created = isset( $_GET['vkbm_shift_bulk_created'] ) ? (int) $_GET['vkbm_shift_bulk_created'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( null === $created ) {
			return;
		}

		$skipped = isset( $_GET['vkbm_shift_bulk_skipped'] ) ? (int) $_GET['vkbm_shift_bulk_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$year    = isset( $_GET['vkbm_shift_bulk_year'] ) ? (int) $_GET['vkbm_shift_bulk_year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$month   = isset( $_GET['vkbm_shift_bulk_month'] ) ? (int) $_GET['vkbm_shift_bulk_month'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		$message = sprintf(
			/* translators: 1: year, 2: month, 3: created count, 4: skipped count */
			__( 'Shifts for %2$02d month of %1$d were registered in bulk (Created: %3$d / Skip: %4$d).', 'vk-booking-manager' ),
			$year,
			$month,
			max( 0, $created ),
			max( 0, $skipped )
		);
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * 現在の管理画面がシフト一覧画面（edit.php?post_type=vkbm_shift）かどうかを判定する。
	 *
	 * Setup_Notices からも同じ判定基準で使うため public static にしている（安藤レビュー指摘での一本化）。
	 * 画面情報が取得できない場合は false を返す。admin_notices が発火する時点では、通常の WordPress の
	 * 実行順序上 $current_screen は必ず設定済みのため、リクエストパラメータへのフォールバックは持たない。
	 *
	 * @return bool
	 */
	public static function is_shift_list_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return 'edit' === $screen->base && Shift_Post_Type::POST_TYPE === $screen->post_type;
	}

	/**
	 * Build selectable month options (current month + next 4 months).
	 *
	 * @return array<string, string> map YYYY-MM => label.
	 */
	private function get_bulk_month_options(): array {
		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );
		$base     = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 );

		$options = array();

		// Use locale-appropriate date format.
		// Japanese: "2026年1月", English: "January 2026".
		$locale = determine_locale();
		$format = ( 'ja' === substr( $locale, 0, 2 ) ) ? 'Y年n月' : 'F Y';

		for ( $i = 0; $i <= 4; $i++ ) {
			$target = $base->modify( sprintf( '+%d month', $i ) );
			if ( ! $target instanceof \DateTimeImmutable ) {
				continue;
			}

			$year  = (int) $target->format( 'Y' );
			$month = (int) $target->format( 'n' );
			$value = sprintf( '%04d-%02d', $year, $month );

			// Use wp_date() for locale-aware formatting.
			$timestamp         = $target->getTimestamp();
			$options[ $value ] = wp_date( $format, $timestamp, $timezone );
		}

		return $options;
	}

	/**
	 * Check whether a shift already exists for resource/year/month.
	 *
	 * @param int  $resource_id    Resource ID.
	 * @param int  $year           Year.
	 * @param int  $month          Month.
	 * @param bool $include_trash ゴミ箱のシフトも「既に存在する」として扱うか。
	 *                              自動登録から true で呼ぶと、ゴミ箱のシフトも登録済みとして扱う
	 *                              （完全に削除された場合のみ再登録の対象になる）。手動の一括登録は
	 *                              従来どおり false（ゴミ箱は数えない）で呼ぶ。
	 * @return bool
	 */
	private function shift_exists( int $resource_id, int $year, int $month, bool $include_trash = false ): bool {
		$post_statuses = array( 'publish', 'draft', 'pending', 'private' );

		if ( $include_trash ) {
			$post_statuses[] = 'trash';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => $post_statuses,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_RESOURCE,
						'value'   => $resource_id,
						'compare' => '=',
					),
					array(
						'key'     => self::META_YEAR,
						'value'   => $year,
						'compare' => '=',
					),
					array(
						'key'     => self::META_MONTH,
						'value'   => $month,
						'compare' => '=',
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * Build the current editor state for the meta box and assets.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function get_editor_state( int $post_id ): array {
		$resource_id = (int) get_post_meta( $post_id, self::META_RESOURCE, true );
		$year        = (int) get_post_meta( $post_id, self::META_YEAR, true );
		$month       = (int) get_post_meta( $post_id, self::META_MONTH, true );
		$days        = get_post_meta( $post_id, self::META_DAYS, true );

		if ( ! is_array( $days ) ) {
			$days = array();
		}

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
		$timestamp     = current_time( 'timestamp' );
		$current_year  = (int) wp_date( 'Y', $timestamp );
		$current_month = (int) wp_date( 'n', $timestamp );

		if ( $year < 1 ) {
			$year = $current_year;
		}

		if ( $month < 1 ) {
			$month = $current_month;
		}

		$resource_id = $this->sanitize_resource_id( $resource_id );

		$days_for_editor = array();
		$default_days    = $this->derive_days_from_template( $resource_id, $year, $month );

		if ( ! empty( $days ) ) {
			$days_for_editor = $this->normalize_day_entries( $days, $year, $month );
		}

		if ( empty( $days_for_editor ) ) {
			$days_for_editor = $default_days;
		}

		return array(
			'resource_id'      => $resource_id,
			'year'             => $year,
			'month'            => $month,
			'days'             => $days_for_editor,
			'days_for_editor'  => $days_for_editor,
			'current_year'     => $current_year,
			'current_month'    => $current_month,
			'holiday_rules'    => $this->get_provider_holiday_rules(),
			'default_days'     => $default_days,
			'weekday_defaults' => $this->build_weekday_defaults( $default_days, $year, $month ),
		);
	}

	/**
	 * Returns the selectable day status options.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_day_status_options(): array {
		return array(
			array(
				'value' => self::DAY_STATUS_OPEN,
				'label' => __( 'Normal', 'vk-booking-manager' ),
			),
			array(
				'value' => self::DAY_STATUS_UNAVAILABLE,
				'label' => __( 'Off', 'vk-booking-manager' ),
			),
			array(
				'value' => self::DAY_STATUS_REGULAR_HOLIDAY,
				'label' => __( 'Regular holiday', 'vk-booking-manager' ),
			),
			array(
				'value' => self::DAY_STATUS_TEMPORARY_OPEN,
				'label' => __( 'Special opening', 'vk-booking-manager' ),
			),
			array(
				'value' => self::DAY_STATUS_TEMPORARY_CLOSED,
				'label' => __( 'Temporary closure', 'vk-booking-manager' ),
			),
		);
	}

	/**
	 * Retrieve provider-defined regular holiday rules.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_provider_holiday_rules(): array {
		$settings = $this->get_provider_settings();
		$rules    = $settings['provider_regular_holidays'] ?? array();

		if ( ! is_array( $rules ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$frequency = isset( $rule['frequency'] ) ? (string) $rule['frequency'] : '';
			$weekday   = isset( $rule['weekday'] ) ? (string) $rule['weekday'] : '';

			if ( '' === $frequency || '' === $weekday ) {
				continue;
			}

			$normalized[] = array(
				'frequency' => $frequency,
				'weekday'   => $weekday,
			);
		}

		return $normalized;
	}

	/**
	 * Build default templates for each weekday based on derived days.
	 *
	 * @param array<int, array<string, mixed>> $days  Derived day map.
	 * @param int                              $year Year.
	 * @param int                              $month Month.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_weekday_defaults( array $days, int $year, int $month ): array {
		$map = array();

		foreach ( $days as $day => $entry ) {
			$day_number = (int) $day;

			if ( $day_number < 1 ) {
				continue;
			}

			$weekday_key = $this->get_weekday_key( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $day_number, $year ) ) );

			if ( isset( $map[ $weekday_key ] ) ) {
				continue;
			}

			$status = self::DAY_STATUS_OPEN;
			$slots  = array();

			if ( is_array( $entry ) ) {
				if ( isset( $entry['status'] ) && is_string( $entry['status'] ) && in_array( $entry['status'], $this->get_day_status_keys(), true ) ) {
					$status = $entry['status'];
				}

				if ( isset( $entry['slots'] ) && is_array( $entry['slots'] ) ) {
					$slots = array_values( $entry['slots'] );
				}
			}

			$map[ $weekday_key ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}

		return $map;
	}

	/**
	 * Return the list of valid day status keys.
	 *
	 * @return array<int, string>
	 */
	private function get_day_status_keys(): array {
		return array(
			self::DAY_STATUS_OPEN,
			self::DAY_STATUS_REGULAR_HOLIDAY,
			self::DAY_STATUS_TEMPORARY_OPEN,
			self::DAY_STATUS_TEMPORARY_CLOSED,
			self::DAY_STATUS_UNAVAILABLE,
		);
	}

	/**
	 * Determine whether the provided status represents a closed day.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	private function is_closed_status( string $status ): bool {
		return in_array( $status, self::CLOSED_DAY_STATUSES, true );
	}

	/**
	 * Ensure day entries contain normalized status and slot data.
	 *
	 * @param array<int|string, mixed> $days  Raw day map.
	 * @param int                      $year  Year context.
	 * @param int                      $month Month context.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_day_entries( array $days, int $year, int $month ): array {
		$normalized     = array();
		$valid_statuses = $this->get_day_status_keys();

		foreach ( $days as $day => $entry ) {
			$day_number = (int) $day;

			if ( $day_number < 1 || $day_number > (int) wp_date( 't', gmmktime( 0, 0, 0, $month, 1, $year ) ) ) {
				continue;
			}

			$status      = self::DAY_STATUS_OPEN;
			$slots_input = array();

			if ( is_array( $entry ) ) {
				if ( isset( $entry['status'] ) ) {
					$status_candidate = (string) $entry['status'];
					if ( in_array( $status_candidate, $valid_statuses, true ) ) {
						$status = $status_candidate;
					}
				}

				if ( isset( $entry['slots'] ) && is_array( $entry['slots'] ) ) {
					$slots_input = $entry['slots'];
				} elseif ( array_key_exists( 0, $entry ) ) {
					$slots_input = $entry;
				}
			}

			$slots = $this->normalize_slot_collection( $slots_input );

			if ( $this->is_closed_status( $status ) ) {
				$slots = array();
			}

			$normalized[ $day_number ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}

		return $normalized;
	}

	/**
	 * Retrieves the resource template repository if available.
	 *
	 * @return \VKBookingManager\Resources\Resource_Schedule_Template_Repository|null
	 */
	private function get_resource_template_repository() {
		if ( ! class_exists( '\\VKBookingManager\\Resources\\Resource_Schedule_Template_Repository' ) ) {
			return null;
		}

		static $instance = null;

		if ( null === $instance ) {
			$instance = new \VKBookingManager\Resources\Resource_Schedule_Template_Repository();
		}

		return $instance;
	}

	/**
	 * Derive day slots for a shift from the resource template and provider settings.
	 *
	 * @param int $resource_id Resource ID.
	 * @param int $year        Year.
	 * @param int $month       Month.
	 * @return array<int, array<string, mixed>>
	 */
	private function derive_days_from_template( int $resource_id, int $year = 0, int $month = 0 ): array {
		$template_repository = $this->get_resource_template_repository();

		if ( $year <= 0 ) {
			// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
			$year = (int) wp_date( 'Y', current_time( 'timestamp' ) );
		}

		if ( $month < 1 || $month > 12 ) {
			// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Intentional site-local timestamp used consistently for comparisons.
			$month = (int) wp_date( 'n', current_time( 'timestamp' ) );
		}

		if ( $resource_id <= 0 || ! $template_repository ) {
			return $this->derive_days_from_provider( $year, $month );
		}

		$template      = $template_repository->get_template( $resource_id );
		$holiday_rules = $this->get_provider_holiday_rules();

		if ( empty( $template ) ) {
			return $this->derive_days_from_provider( $year, $month );
		}

		$days_in_month      = (int) wp_date( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$template_days      = is_array( $template['days'] ?? null ) ? $template['days'] : array();
		$use_provider_hours = ! empty( $template['use_provider_hours'] );
		$provider_settings  = $use_provider_hours ? $this->get_provider_settings() : array();
		$derived            = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$weekday_key    = $this->get_weekday_key( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $day, $year ) ) );
			$template_slots = $this->normalize_slot_collection( $template_days[ $weekday_key ] ?? array() );

			if ( empty( $template_slots ) && $use_provider_hours ) {
				$template_slots = $this->get_provider_day_slots( $provider_settings, $weekday_key );
			}

			$status = $this->determine_default_day_status( $weekday_key, $year, $month, $day, $holiday_rules );

			if ( ! empty( $template_slots ) && $this->is_closed_status( $status ) && self::DAY_STATUS_REGULAR_HOLIDAY !== $status ) {
				$status = self::DAY_STATUS_TEMPORARY_OPEN;
			}

			if ( ! empty( $template_slots ) || $this->is_closed_status( $status ) ) {
				$derived[ $day ] = $this->build_day_entry( $status, $template_slots );
			}
		}

		return $derived;
	}

	/**
	 * Derive days from provider settings (basic + weekly hours).
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return array<int, array<string, mixed>>
	 */
	private function derive_days_from_provider( int $year, int $month ): array {
		$provider_settings = $this->get_provider_settings();
		$holiday_rules     = $this->get_provider_holiday_rules();
		$days_in_month     = (int) wp_date( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$derived           = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$weekday_key = $this->get_weekday_key( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $day, $year ) ) );
			$day_slots   = $this->get_provider_day_slots( $provider_settings, $weekday_key );
			$status      = $this->determine_default_day_status( $weekday_key, $year, $month, $day, $holiday_rules );

			if ( ! empty( $day_slots ) || $this->is_closed_status( $status ) ) {
				$derived[ $day ] = $this->build_day_entry( $status, $day_slots );
			}
		}

		return $derived;
	}

	/**
	 * Determine the default day status based on provider holiday rules.
	 *
	 * @param string                            $weekday_key Weekday key (sun, mon, ...).
	 * @param int                               $year        Year.
	 * @param int                               $month       Month.
	 * @param int                               $day         Day of month.
	 * @param array<int, array<string, string>> $holiday_rules Holiday rules.
	 * @return string
	 */
	private function determine_default_day_status( string $weekday_key, int $year, int $month, int $day, array $holiday_rules ): string {
		return $this->is_regular_holiday_date( $weekday_key, $year, $month, $day, $holiday_rules )
			? self::DAY_STATUS_REGULAR_HOLIDAY
			: self::DAY_STATUS_OPEN;
	}

	/**
	 * Check if the given date matches a provider-defined regular holiday.
	 *
	 * @param string                            $weekday_key Weekday key.
	 * @param int                               $year        Year.
	 * @param int                               $month       Month.
	 * @param int                               $day         Day of month.
	 * @param array<int, array<string, string>> $holiday_rules Holiday rules.
	 * @return bool
	 */
	private function is_regular_holiday_date( string $weekday_key, int $year, int $month, int $day, array $holiday_rules ): bool {
		foreach ( $holiday_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			if ( ( $rule['weekday'] ?? '' ) !== $weekday_key ) {
				continue;
			}

			$frequency = (string) ( $rule['frequency'] ?? '' );

			if ( 'weekly' === $frequency ) {
				return true;
			}

			if ( 0 === strpos( $frequency, 'nth-' ) ) {
				$nth = (int) substr( $frequency, 4 );

				if ( $nth > 0 && $this->get_weekday_occurrence_in_month( $year, $month, $day ) === $nth ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the occurrence number (1-5) of a weekday within the month for a given date.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $day   Day of month.
	 * @return int
	 */
	private function get_weekday_occurrence_in_month( int $year, int $month, int $day ): int {
		$timestamp            = gmmktime( 0, 0, 0, $month, $day, $year );
		$current_weekday      = (int) gmdate( 'w', $timestamp );
		$first_day_weekday    = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$first_occurrence_gap = ( $current_weekday - $first_day_weekday + 7 ) % 7;
		$first_occurrence_day = 1 + $first_occurrence_gap;

		if ( $day < $first_occurrence_day ) {
			return 0;
		}

		return (int) floor( ( $day - $first_occurrence_day ) / 7 ) + 1;
	}

	/**
	 * Retrieve provider settings.
	 *
	 * リクエスト内キャッシュ（static）を持つ。$force_refresh を渡すと、このリクエスト内で
	 * 既にキャッシュされていても保存済みの最新値で読み直す。設定保存の直後に同一リクエスト内で
	 * handle_auto_register() を同期実行する場合など、保存前の古い値を読んでしまうと困る呼び出し元
	 * が使う（司からの指摘）。
	 *
	 * @param bool $force_refresh キャッシュを無視して読み直す場合は true。
	 * @return array<string, mixed>
	 */
	private function get_provider_settings( bool $force_refresh = false ): array {
		static $settings = null;

		if ( $force_refresh || null === $settings ) {
			$repository = new Settings_Repository();
			$settings   = $repository->get_settings();
		}

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Get provider slots for a specific weekday key.
	 *
	 * @param array<string, mixed> $provider_settings Provider settings array.
	 * @param string               $weekday_key      Weekday key (sun, mon, ...).
	 * @return array<int, array<string, string>>
	 */
	private function get_provider_day_slots( array $provider_settings, string $weekday_key ): array {
		$weekly = isset( $provider_settings['provider_business_hours_weekly'] ) && is_array( $provider_settings['provider_business_hours_weekly'] )
			? $provider_settings['provider_business_hours_weekly']
			: array();
		$basic  = isset( $provider_settings['provider_business_hours_basic'] ) && is_array( $provider_settings['provider_business_hours_basic'] )
			? $provider_settings['provider_business_hours_basic']
			: array();

		$slots = array();

		if ( isset( $weekly[ $weekday_key ] ) && ! empty( $weekly[ $weekday_key ]['use_custom'] ) ) {
			$slots = $weekly[ $weekday_key ]['time_slots'] ?? array();
		}

		if ( empty( $slots ) ) {
			$slots = $basic;
		}

		return $this->normalize_slot_collection( $slots );
	}

	/**
	 * Normalize raw slot data to start/end pairs.
	 *
	 * @param array<int, array<string, mixed>> $slots Raw slot definition.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_slot_collection( array $slots ): array {
		$normalized = array();

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = '';
			$end   = '';

			if ( isset( $slot['start'], $slot['end'] ) ) {
				$start = $this->sanitize_time( (string) $slot['start'] );
				$end   = $this->sanitize_time( (string) $slot['end'] );
			} elseif ( isset( $slot['start_hour'], $slot['start_minute'], $slot['end_hour'], $slot['end_minute'] ) ) {
				$start = $this->sanitize_time( sprintf( '%02d:%02d', (int) $slot['start_hour'], (int) $slot['start_minute'] ) );
				$end   = $this->sanitize_time( sprintf( '%02d:%02d', (int) $slot['end_hour'], (int) $slot['end_minute'] ) );
			}

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
	 * Build a normalized day entry.
	 *
	 * @param string                            $status Status value.
	 * @param array<int, array<string, string>> $slots Slot collection.
	 * @return array<string, mixed>
	 */
	private function build_day_entry( string $status, array $slots ): array {
		if ( $this->is_closed_status( $status ) ) {
			$slots = array();
		}

		return array(
			'status' => $status,
			'slots'  => $slots,
		);
	}

	/**
	 * Derive initial day slots from resource template when available.
	 *
	 * @param int $resource_id Resource ID.
	 * @return array<int, array<int, array<string, string>>>
	 */

	/**
	 * Sanitize resource ID.
	 *
	 * @param int $resource_id Resource ID.
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
	 * Sanitize year value.
	 *
	 * @param int $year Year value.
	 * @return int
	 */
	private function sanitize_year( int $year ): int {
		if ( $year < 2000 || $year > 2100 ) {
			return 0;
		}

		return $year;
	}

	/**
	 * Sanitize month value.
	 *
	 * @param int $month Month value.
	 * @return int
	 */
	private function sanitize_month( int $month ): int {
		if ( $month < 1 || $month > 12 ) {
			return 0;
		}

		return $month;
	}

	/**
	 * Sanitize days JSON payload.
	 *
	 * @param string $json  JSON string.
	 * @param int    $year  Year.
	 * @param int    $month Month.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_days_json( string $json, int $year, int $month ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || $year <= 0 || $month <= 0 ) {
			return array();
		}

		$normalized = $this->normalize_day_entries( $data, $year, $month );
		$filtered   = array();

		foreach ( $normalized as $day_number => $entry ) {
			if ( self::DAY_STATUS_OPEN === ( $entry['status'] ?? '' ) && empty( $entry['slots'] ) ) {
				continue;
			}

			$filtered[ $day_number ] = $entry;
		}

		return $filtered;
	}

	/**
	 * Sanitize time string HH:MM.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	private function sanitize_time( string $time ): string {
		$time = trim( $time );

		if ( ! preg_match( '/^(2[0-4]|[01][0-9]):([0-5][0-9])$/', $time ) ) {
			return '';
		}

		if ( '24:00' !== $time && str_starts_with( $time, '24:' ) ) {
			return '';
		}

		return $time;
	}

	/**
	 * Convert weekday index to template key.
	 *
	 * @param int $index Weekday index (0-6).
	 * @return string Weekday key.
	 */
	private function get_weekday_key( int $index ): string {
		$map = array(
			0 => 'sun',
			1 => 'mon',
			2 => 'tue',
			3 => 'wed',
			4 => 'thu',
			5 => 'fri',
			6 => 'sat',
		);

		return $map[ $index ] ?? 'sun';
	}

	/**
	 * Update post title automatically based on resource and period.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $resource_id Resource ID.
	 * @param int $year        Year.
	 * @param int $month       Month.
	 */
	private function maybe_update_post_title( int $post_id, int $resource_id, int $year, int $month ): void {
		$resource_title = get_the_title( $resource_id );

		if ( ! $resource_title ) {
			return;
		}

		$new_title = sprintf(
			/* translators: 1: year, 2: month, 3: staff name */
			__( '%1$d year %2$02d month %3$s', 'vk-booking-manager' ),
			$year,
			$month,
			$resource_title
		);

		remove_action( 'save_post_' . Shift_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10 );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $new_title,
			)
		);
		add_action( 'save_post_' . Shift_Post_Type::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
	}

	/**
	 * Generate year options (current year ±2).
	 *
	 * @param int $current_year Current year.
	 * @return array<int>
	 */
	private function get_year_options( int $current_year ): array {
		$years = array();

		for ( $y = $current_year - 2; $y <= $current_year + 2; $y++ ) {
			$years[] = $y;
		}

		return $years;
	}
}
