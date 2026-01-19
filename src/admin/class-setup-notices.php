<?php

declare( strict_types=1 );

namespace VKBookingManager\Admin;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;

/**
 * Provides setup notices in wp-admin.
 */
class Setup_Notices {
	private const NONCE_ACTION = 'vkbm_dismiss_notice';

	private const USER_META_KEY   = 'vkbm_dismissed_notices_user';
	private const OPTION_META_KEY = 'vkbm_dismissed_notices_global';
	private const SHIFT_META_YEAR = '_vkbm_shift_year';
	private const SHIFT_META_MONTH = '_vkbm_shift_month';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_vkbm_dismiss_notice', [ $this, 'handle_dismiss' ] );
		add_action( 'vkbm_shift_dashboard_notices', [ $this, 'render_shift_dashboard_notice' ] );
	}

	/**
	 * Render setup notices.
	 */
	public function render_notices(): void {
		if ( $this->is_shift_dashboard_screen() ) {
			return;
		}

		if ( ! $this->is_setup_complete() ) {
			$missing_items = $this->get_missing_setup_items_for_user();
			if ( [] !== $missing_items ) {
				$this->render_notice_markup( $missing_items );
			}
		}

		$missing_shift_months = $this->get_missing_shift_months();
		if ( [] !== $missing_shift_months ) {
			$this->render_shift_notice_markup( $missing_shift_months, ! $this->is_shift_post_type_screen() );
		}
	}

	/**
	 * Render setup notices for shift dashboard screen.
	 */
	public function render_shift_dashboard_notice(): void {
		if ( ! $this->is_setup_complete() ) {
			$missing_items = $this->get_missing_setup_items_for_user();
			if ( [] !== $missing_items ) {
				$this->render_notice_markup( $missing_items );
			}
		}

		$missing_shift_months = $this->get_missing_shift_months();
		if ( [] !== $missing_shift_months ) {
			$this->render_shift_notice_markup( $missing_shift_months, ! $this->is_shift_post_type_screen() );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $missing_items Missing setup items.
	 */
	private function render_notice_markup( array $missing_items ): void {
		?>
		<div class="notice vkbm-notice vkbm-notice__warning">
			<h3><?php echo esc_html__( 'Some items have not been set', 'vk-booking-manager' ); ?></h3>
			<p><?php echo esc_html__( 'Please set the following items.', 'vk-booking-manager' ); ?></p>
			<ul>
				<?php foreach ( $missing_items as $item ) : ?>
					<li>
						<?php if ( 'link' === ( $item['action_style'] ?? '' ) ) : ?>
							<?php
							$link = sprintf(
								'<a href="%1$s">%2$s</a>',
								esc_url( $item['primary_url'] ),
								esc_html( $item['primary_label'] )
							);
							$message = sprintf( $item['message'], $link );
							?>
							<p><?php echo wp_kses( $message, [ 'a' => [ 'href' => [] ] ] ); ?></p>
						<?php else : ?>
							<p><?php echo esc_html( $item['message'] ); ?></p>
							<div class="vkbm-buttons">
								<a class="button button-primary" href="<?php echo esc_url( $item['primary_url'] ); ?>">
									<?php echo esc_html( $item['primary_label'] ); ?>
								</a>
								<?php if ( '' !== $item['secondary_url'] ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( $item['secondary_url'] ); ?>">
										<?php echo esc_html( $item['secondary_label'] ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Enqueue assets needed for notices.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		unset( $hook_suffix );

		$has_setup_notice = ! $this->is_setup_complete() && [] !== $this->get_missing_setup_items_for_user();
		$has_shift_notice = [] !== $this->get_missing_shift_months();
		if ( ! $has_setup_notice && ! $has_shift_notice ) {
			return;
		}

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );
	}

	/**
	 * AJAX handler to dismiss a notice.
	 */
	public function handle_dismiss(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( [ 'message' => 'invalid_nonce' ], 403 );
		}

		if ( ! $this->current_user_can_manage_notices() ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( $_POST['notice_id'] ) ) : '';
		if ( '' === $notice_id ) {
			wp_send_json_error( [ 'message' => 'missing_notice_id' ], 400 );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'user';
		if ( 'global' === $scope ) {
			$this->dismiss_notice_globally( $notice_id );
			wp_send_json_success( [ 'scope' => 'global' ] );
		}

		$this->dismiss_notice_for_user( get_current_user_id(), $notice_id );
		wp_send_json_success( [ 'scope' => 'user' ] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_setup_items(): array {
		$items = [
			[
				'id'              => 'provider_name',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_provider_name(),
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Business name', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-provider-name' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
			[
				'id'              => 'regular_holidays',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_regular_holidays_or_disabled(),
				'message'         => __( '%s is not set. Please register "No regular holidays" or regular holidays.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Regular holiday', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-regular-holiday-disabled' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
			[
				'id'              => 'business_hours_basic',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_basic_business_hours(),
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Basic business hours', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-basic-business-hours' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
			[
				'id'              => 'reservation_page_url',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_reservation_page_url(),
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Reservation page URL', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-reservation-page-url' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
			[
				'id'              => 'provider_email',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_provider_email(),
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Representative email address', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-provider-email' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
			[
				'id'              => 'privacy_policy_mode',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_privacy_policy_mode(),
				'message'         => __( 'Please select %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Privacy policy', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-privacy-policy-mode' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			],
		];

		if ( Staff_Editor::is_enabled() ) {
			$items[] = [
				'id'              => 'staff',
				'capability'      => Capabilities::MANAGE_STAFF,
				'is_missing'      => fn () => ! $this->has_posts( Resource_Post_Type::POST_TYPE ),
				'message'         => __( 'No staff members have been registered yet. First, add staff.', 'vk-booking-manager' ),
				'primary_label'   => __( 'add staff', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'post-new.php?post_type=' . Resource_Post_Type::POST_TYPE ),
				'secondary_label' => __( 'Staff list', 'vk-booking-manager' ),
				'secondary_url'   => admin_url( 'edit.php?post_type=' . Resource_Post_Type::POST_TYPE ),
			];
		}

		$items[] = [
			'id'              => 'service_menu',
			'capability'      => Capabilities::MANAGE_SERVICE_MENUS,
			'is_missing'      => fn () => ! $this->has_posts( Service_Menu_Post_Type::POST_TYPE ),
			'message'         => __( 'Service not registered yet. First, add a service menu.', 'vk-booking-manager' ),
			'primary_label'   => __( 'Add service', 'vk-booking-manager' ),
			'primary_url'     => admin_url( 'post-new.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ),
			'secondary_label' => __( 'Service list', 'vk-booking-manager' ),
			'secondary_url'   => admin_url( 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ),
		];

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_missing_setup_items(): array {
		$missing = [];
		foreach ( $this->get_setup_items() as $item ) {
			if ( ! isset( $item['is_missing'] ) || ! is_callable( $item['is_missing'] ) ) {
				continue;
			}

			if ( ! (bool) call_user_func( $item['is_missing'] ) ) {
				continue;
			}

			$missing[] = $item;
		}

		return $missing;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_missing_setup_items_for_user(): array {
		$missing = [];
		foreach ( $this->get_missing_setup_items() as $item ) {
			if ( isset( $item['capability'] ) && ! current_user_can( (string) $item['capability'] ) ) {
				continue;
			}

			$missing[] = $item;
		}

		return $missing;
	}

	private function is_setup_complete(): bool {
		return [] === $this->get_missing_setup_items();
	}

	private function has_posts( string $post_type ): bool {
		$counts = wp_count_posts( $post_type );
		if ( ! is_object( $counts ) ) {
			return false;
		}

		$total = 0;
		foreach ( [ 'publish', 'future', 'draft', 'pending', 'private' ] as $status ) {
			if ( isset( $counts->$status ) ) {
				$total += (int) $counts->$status;
			}
		}

		return $total > 0;
	}

	private function has_basic_business_hours(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$basic      = $settings['provider_business_hours_basic'] ?? [];

		return is_array( $basic ) && [] !== $basic;
	}

	private function has_provider_name(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$name       = isset( $settings['provider_name'] ) ? trim( (string) $settings['provider_name'] ) : '';

		return '' !== $name;
	}

	private function has_regular_holidays_or_disabled(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$disabled   = ! empty( $settings['provider_regular_holidays_disabled'] );

		if ( $disabled ) {
			return true;
		}

		$holidays = $settings['provider_regular_holidays'] ?? [];

		return is_array( $holidays ) && [] !== $holidays;
	}

	private function has_reservation_page_url(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$url        = isset( $settings['reservation_page_url'] ) ? trim( (string) $settings['reservation_page_url'] ) : '';

		return '' !== $url;
	}

	/**
	 * @return array<int, array{year:int,month:int}>
	 */
	private function get_missing_shift_months(): array {
		if ( ! $this->has_published_resources() ) {
			return [];
		}

		$months_ahead = $this->get_shift_alert_months();
		$months_ahead = max( 0, $months_ahead );

		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );
		$current  = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 );

		$missing = [];

		for ( $offset = 0; $offset <= $months_ahead; $offset++ ) {
			$target = $current->modify( sprintf( '+%d months', $offset ) );
			if ( ! $target instanceof \DateTimeImmutable ) {
				continue;
			}

			$year  = (int) $target->format( 'Y' );
			$month = (int) $target->format( 'n' );

			if ( $this->has_shift_for_month( $year, $month ) ) {
				continue;
			}

			$missing[] = [
				'year'  => $year,
				'month' => $month,
			];
		}

		return $missing;
	}

	private function has_published_resources(): bool {
		$counts = wp_count_posts( Resource_Post_Type::POST_TYPE );
		if ( ! is_object( $counts ) ) {
			return false;
		}

		return isset( $counts->publish ) && (int) $counts->publish > 0;
	}

	private function has_shift_for_month( int $year, int $month ): bool {
		$posts = get_posts(
			[
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'     => self::SHIFT_META_YEAR,
						'value'   => (string) $year,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
					[
						'key'     => self::SHIFT_META_MONTH,
						'value'   => (string) $month,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);

		return ! empty( $posts );
	}

	private function get_shift_alert_months(): int {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$value      = isset( $settings['shift_alert_months'] ) ? (int) $settings['shift_alert_months'] : 1;

		return min( 4, max( 1, $value ) );
	}

	/**
	 * @param array<int, array{year:int,month:int}> $missing_months Missing shift months.
	 * @param bool                                 $show_action     Whether to show action button.
	 */
	private function render_shift_notice_markup( array $missing_months, bool $show_action ): void {
		?>
		<div class="notice vkbm-notice vkbm-notice__warning">
			<h3><?php echo esc_html__( 'Shift not registered', 'vk-booking-manager' ); ?></h3>
			<ul>
				<?php foreach ( $missing_months as $item ) : ?>
					<li>
						<?php
						printf(
							/* translators: %d: month number */
							esc_html__( '%d Monthly shift is not registered.', 'vk-booking-manager' ),
							(int) $item['month']
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $show_action ) : ?>
				<div class="vkbm-buttons">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Shift_Post_Type::POST_TYPE ) ); ?>">
						<?php echo esc_html__( 'Register shift', 'vk-booking-manager' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function is_shift_dashboard_screen(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && 'toplevel_page_vkbm-shift-dashboard' === $screen->id ) {
				return true;
			}
		}

		return isset( $_GET['page'] ) && 'vkbm-shift-dashboard' === (string) $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
	}

	private function is_shift_post_type_screen(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->post_type ) && Shift_Post_Type::POST_TYPE === $screen->post_type ) {
				return true;
			}
		}

		return isset( $_GET['post_type'] ) && Shift_Post_Type::POST_TYPE === (string) $_GET['post_type']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
	}

	private function has_provider_email(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$email      = isset( $settings['provider_email'] ) ? trim( (string) $settings['provider_email'] ) : '';

		return '' !== $email;
	}

	private function has_privacy_policy_mode(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$mode       = isset( $settings['provider_privacy_policy_mode'] )
			? sanitize_key( (string) $settings['provider_privacy_policy_mode'] )
			: 'none';

		return 'none' !== $mode;
	}

	private function is_notice_dismissed_for_user( string $notice_id ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$dismissed = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		return array_key_exists( $notice_id, $dismissed );
	}

	private function dismiss_notice_for_user( int $user_id, string $notice_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$dismissed = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		$dismissed[ $notice_id ] = time();
		update_user_meta( $user_id, self::USER_META_KEY, $dismissed );
	}

	private function dismiss_notice_globally( string $notice_id ): void {
		$dismissed = get_option( self::OPTION_META_KEY, [] );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		$dismissed[ $notice_id ] = time();
		update_option( self::OPTION_META_KEY, $dismissed, false );
	}

	private function current_user_can_manage_notices(): bool {
		foreach ( $this->get_setup_items() as $item ) {
			if ( isset( $item['capability'] ) && current_user_can( (string) $item['capability'] ) ) {
				return true;
			}
		}

		return false;
	}
}
