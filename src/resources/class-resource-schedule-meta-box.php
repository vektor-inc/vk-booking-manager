<?php

declare( strict_types=1 );

namespace VKBookingManager\Resources;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;

/**
 * Provides the schedule template meta box for resources.
 */
class Resource_Schedule_Meta_Box {
	private const NONCE_ACTION = 'vkbm_resource_schedule_meta';
	private const NONCE_NAME   = '_vkbm_resource_schedule_nonce';

	/**
	 * Repository instance.
	 *
	 * @var Resource_Schedule_Template_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Resource_Schedule_Template_Repository $repository Template repository.
	 */
	public function __construct( Resource_Schedule_Template_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . Resource_Post_Type::POST_TYPE, [ $this, 'save_post' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Returns the day labels used for schedule configuration.
	 *
	 * @return array<string, string>
	 */
	private function get_day_labels(): array {
		return [
			'mon'         => __( 'Monday', 'vk-booking-manager' ),
			'tue'         => __( 'Tuesday', 'vk-booking-manager' ),
			'wed'         => __( 'Wednesday', 'vk-booking-manager' ),
			'thu'         => __( 'Thursday', 'vk-booking-manager' ),
			'fri'         => __( 'Friday', 'vk-booking-manager' ),
			'sat'         => __( 'Saturday', 'vk-booking-manager' ),
			'sun'         => __( 'Sunday', 'vk-booking-manager' ),
			'holiday'     => __( 'holiday', 'vk-booking-manager' ),
			'holiday_eve' => __( 'The day before a public holiday', 'vk-booking-manager' ),
		];
	}

	/**
	 * Add the meta box to the resource edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'vkbm-resource-schedule',
			__( 'work template', 'vk-booking-manager' ),
			[ $this, 'render_meta_box' ],
			Resource_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the schedule template meta box.
	 *
	 * @param WP_Post $post Current resource post.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$template       = $this->repository->get_template( $post->ID );
		$hour_options   = $this->get_hour_options();
		$end_hour_options = $this->get_end_hour_options();
		$minute_options = $this->get_minute_options();
		$empty_parts    = [
			'hour'   => '',
			'minute' => '',
		];

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label>
				<input type="checkbox" id="vkbm-resource-schedule-use-provider-hours" name="vkbm_resource_schedule[use_provider_hours]" value="1" <?php checked( ! empty( $template['use_provider_hours'] ) ); ?> />
				<?php esc_html_e( "Use the same working hours as the store's business hours", 'vk-booking-manager' ); ?>
			</label>
			<br />
			<span class="description"><?php esc_html_e( 'If you uncheck it, you can set individual working hours for each day of the week.', 'vk-booking-manager' ); ?></span>
		</p>

		<div class="vkbm-resource-schedule-container vkbm-schedule-container" id="vkbm-resource-schedule-days">
			<table class="form-table vkbm-setting-table vkbm-resource-schedule-table vkbm-schedule-table">
				<tbody>
					<?php foreach ( $this->get_day_labels() as $day_key => $day_label ) : ?>
						<?php
						$slots = is_array( $template['days'][ $day_key ] ?? null ) ? $template['days'][ $day_key ] : [];
						$slots = array_values(
							array_filter(
								$slots,
								static function ( $slot ): bool {
									return is_array( $slot ) && isset( $slot['start'], $slot['end'] );
								}
							)
						);

						if ( empty( $slots ) ) {
							$slots = [
								[
									'start' => '',
									'end'   => '',
								],
							];
						}
						?>
						<tr class="vkbm-resource-schedule-day" data-day="<?php echo esc_attr( $day_key ); ?>">
							<th scope="row">
								<?php echo esc_html( $day_label ); ?>
							</th>
							<td>
								<div class="vkbm-resource-schedule-slots vkbm-schedule-slot-list">
									<?php foreach ( $slots as $slot_index => $slot ) : ?>
										<?php
										$start_parts = $this->split_time( (string) ( $slot['start'] ?? '' ) );
										$end_parts   = $this->split_time( (string) ( $slot['end'] ?? '' ) );

										$this->render_slot_row(
											$day_key,
											(string) $slot_index,
											$start_parts,
											$end_parts,
											$hour_options,
											$end_hour_options,
											$minute_options
										);
										?>
									<?php endforeach; ?>
								</div>
								<button type="button" class="button vkbm-resource-schedule-add-slot vkbm-schedule-add-slot" data-day="<?php echo esc_attr( $day_key ); ?>">
									<?php esc_html_e( 'Add time zone', 'vk-booking-manager' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<script type="text/template" id="vkbm-resource-schedule-slot-template">
			<?php
			$this->render_slot_row(
				'__DAY__',
				'__INDEX__',
				$empty_parts,
				$empty_parts,
				$hour_options,
				$end_hour_options,
				$minute_options
			);
			?>
		</script>
		<?php
	}

	/**
	 * Render a slot row.
	 *
	 * @param string                $day            Day key.
	 * @param string                $index          Slot index.
	 * @param array<string, string> $start_parts    Start components.
	 * @param array<string, string> $end_parts      End components.
	 * @param array<string, string> $start_hour_options Start hour options.
	 * @param array<string, string> $end_hour_options End hour options.
	 * @param array<string, string> $minute_options Minute options.
	 */
	private function render_slot_row( string $day, string $index, array $start_parts, array $end_parts, array $start_hour_options, array $end_hour_options, array $minute_options ): void {
		$name_prefix    = sprintf( 'vkbm_resource_schedule[days][%s][%s]', $day, $index );
		$start_hour     = $start_parts['hour'] ?? '';
		$start_minute   = $start_parts['minute'] ?? '';
		$end_hour       = $end_parts['hour'] ?? '';
		$end_minute     = $end_parts['minute'] ?? '';
		$start_hour_id  = sprintf( 'vkbm-resource-schedule-%s-%s-start_hour', $day, $index );
		$start_min_id   = sprintf( 'vkbm-resource-schedule-%s-%s-start_minute', $day, $index );
		$end_hour_id    = sprintf( 'vkbm-resource-schedule-%s-%s-end_hour', $day, $index );
		$end_minute_id  = sprintf( 'vkbm-resource-schedule-%s-%s-end_minute', $day, $index );
		?>
		<div class="vkbm-resource-schedule-slot vkbm-schedule-slot">
			<div class="vkbm-resource-schedule-time-range vkbm-schedule-time-range">
				<div class="vkbm-resource-schedule-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" data-field="start_hour" for="<?php echo esc_attr( $start_hour_id ); ?>">
						<?php esc_html_e( 'Work start time (hour)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $start_hour_id ); ?>"
						class="vkbm-resource-schedule-hour vkbm-schedule-hour"
						name="<?php echo esc_attr( $name_prefix . '[start_hour]' ); ?>"
						data-field="start_hour"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $start_hour_options as $value => $label ) : ?>
							<?php if ( '00' === $value ) { continue; } ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $start_hour, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="vkbm-resource-schedule-colon vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" data-field="start_minute" for="<?php echo esc_attr( $start_min_id ); ?>">
						<?php esc_html_e( 'Work start time (minutes)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $start_min_id ); ?>"
						class="vkbm-resource-schedule-minute vkbm-schedule-minute"
						name="<?php echo esc_attr( $name_prefix . '[start_minute]' ); ?>"
						data-field="start_minute"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $minute_options as $value => $label ) : ?>
							<?php if ( '00' === $value ) { continue; } ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $start_minute, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<span class="vkbm-resource-schedule-range-delimiter vkbm-schedule-range-delimiter">〜</span>
				<div class="vkbm-resource-schedule-time-select vkbm-schedule-time-select">
					<label class="screen-reader-text" data-field="end_hour" for="<?php echo esc_attr( $end_hour_id ); ?>">
						<?php esc_html_e( 'Ending time (hours)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $end_hour_id ); ?>"
						class="vkbm-resource-schedule-hour vkbm-schedule-hour"
						name="<?php echo esc_attr( $name_prefix . '[end_hour]' ); ?>"
						data-field="end_hour"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $end_hour_options as $value => $label ) : ?>
							<?php if ( '00' === $value ) { continue; } ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $end_hour, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="vkbm-resource-schedule-colon vkbm-schedule-colon">:</span>
					<label class="screen-reader-text" data-field="end_minute" for="<?php echo esc_attr( $end_minute_id ); ?>">
						<?php esc_html_e( 'End time of shift (minutes)', 'vk-booking-manager' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( $end_minute_id ); ?>"
						class="vkbm-resource-schedule-minute vkbm-schedule-minute"
						name="<?php echo esc_attr( $name_prefix . '[end_minute]' ); ?>"
						data-field="end_minute"
					>
						<option value="00"><?php esc_html_e( '00', 'vk-booking-manager' ); ?></option>
						<?php foreach ( $minute_options as $value => $label ) : ?>
							<?php if ( '00' === $value ) { continue; } ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $end_minute, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="button" class="button-link-delete vkbm-resource-schedule-remove-slot vkbm-schedule-remove-slot" aria-label="<?php esc_attr_e( 'Remove time slot', 'vk-booking-manager' ); ?>">
					<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( Resource_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_STAFF, $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$payload = $_POST['vkbm_resource_schedule'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- intentionally handled.

		if ( ! is_array( $payload ) ) {
			$this->repository->delete_template( $post_id );
			return;
		}

		$existing_template = get_post_meta( $post_id, Resource_Schedule_Template_Repository::META_KEY, true );
		$had_template      = is_array( $existing_template );
		$had_provider_mode = $had_template && ! empty( $existing_template['use_provider_hours'] );

		$payload  = wp_unslash( $payload );
		$template = $this->sanitize_template( $payload );

		if ( empty( $template['use_provider_hours'] ) && empty( $template['days'] ) && ( ! $had_template || $had_provider_mode ) ) {
			$default_days = $this->get_provider_default_days();
			if ( ! empty( $default_days ) ) {
				$template['days'] = $default_days;
			}
		}

		$this->repository->save_template( $post_id, $template );
	}

	/**
	 * Enqueue assets when editing resources.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || Resource_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$base_url = plugin_dir_url( VKBM_PLUGIN_FILE );

		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		wp_enqueue_script(
			'vkbm-resource-schedule',
			$base_url . 'assets/js/resource-schedule.js',
			[ 'jquery' ],
			VKBM_VERSION,
			true
		);

		wp_localize_script(
			'vkbm-resource-schedule',
			'vkbmResourceSchedule',
			[
				'useProviderHoursSelector' => '#vkbm-resource-schedule-use-provider-hours',
				'daysContainerSelector'    => '#vkbm-resource-schedule-days',
				'defaultSlotsByDay'        => $this->get_provider_default_slots_by_day(),
			]
		);
	}

	/**
	 * Build provider default days payload for templates.
	 *
	 * @return array<string, array<int, array{start:string,end:string}>>
	 */
	private function get_provider_default_days(): array {
		$default_slots_by_day = $this->get_provider_default_slots_by_day();
		$default_days         = [];

		foreach ( array_keys( $this->get_day_labels() ) as $day_key ) {
			if ( isset( $default_slots_by_day[ $day_key ] ) ) {
				$default_days[ $day_key ] = [ $default_slots_by_day[ $day_key ] ];
			}
		}

		return $default_days;
	}

	/**
	 * Get provider default slot per day (first slot only).
	 *
	 * @return array<string, array{start:string,end:string}>
	 */
	private function get_provider_default_slots_by_day(): array {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cached = [];

		if ( ! class_exists( Settings_Repository::class ) ) {
			return $cached;
		}

		$settings = ( new Settings_Repository() )->get_settings();
		$weekly   = isset( $settings['provider_business_hours_weekly'] ) && is_array( $settings['provider_business_hours_weekly'] )
			? $settings['provider_business_hours_weekly']
			: [];
		$basic    = isset( $settings['provider_business_hours_basic'] ) && is_array( $settings['provider_business_hours_basic'] )
			? $settings['provider_business_hours_basic']
			: [];

		foreach ( array_keys( $this->get_day_labels() ) as $day_key ) {
			$slots = [];

			if ( isset( $weekly[ $day_key ] ) && ! empty( $weekly[ $day_key ]['use_custom'] ) ) {
				$slots = $weekly[ $day_key ]['time_slots'] ?? [];
			}

			if ( empty( $slots ) ) {
				$slots = $basic;
			}

			$normalized = $this->normalize_slot_collection( is_array( $slots ) ? $slots : [] );

			if ( ! empty( $normalized[0] ) ) {
				$cached[ $day_key ] = $normalized[0];
			}
		}

		return $cached;
	}

	/**
	 * Sanitize schedule payload.
	 *
	 * @param array $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_template( array $payload ): array {
		$use_provider_hours = ! empty( $payload['use_provider_hours'] );
		$sanitized_days     = [];

		if ( ! $use_provider_hours && isset( $payload['days'] ) && is_array( $payload['days'] ) ) {
			foreach ( $this->get_day_labels() as $day_key => $unused ) {
				$day_slots = $payload['days'][ $day_key ] ?? [];

				if ( ! is_array( $day_slots ) ) {
					continue;
				}

				$sanitized_slots = [];

				foreach ( $day_slots as $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}

					$start = $this->combine_time_from_payload( $slot, 'start' );
					$end   = $this->combine_time_from_payload( $slot, 'end' );

					if ( '' === $start || '' === $end ) {
						continue;
					}

					if ( strcmp( $end, $start ) <= 0 ) {
						continue;
					}

					$sanitized_slots[] = [
						'start' => $start,
						'end'   => $end,
					];
				}

				if ( ! empty( $sanitized_slots ) ) {
					$sanitized_days[ $day_key ] = $sanitized_slots;
				}
			}
		}

		return [
			'use_provider_hours' => $use_provider_hours,
			'days'               => $sanitized_days,
		];
	}

	/**
	 * Normalize raw slot collection to start/end pairs.
	 *
	 * @param array<int, array<string, mixed>> $slots Raw slot definition.
	 * @return array<int, array{start:string,end:string}>
	 */
	private function normalize_slot_collection( array $slots ): array {
		$normalized = [];

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

			if ( '24:00' === $start ) {
				$start = '';
			}

			if ( '' === $start || '' === $end || strcmp( $end, $start ) <= 0 ) {
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
	 * Combine time from payload.
	 *
	 * @param array  $slot Slot data.
	 * @param string $key  Base key (start|end).
	 * @return string
	 */
	private function combine_time_from_payload( array $slot, string $key ): string {
		$hour_key   = $key . '_hour';
		$minute_key = $key . '_minute';

		$hour   = isset( $slot[ $hour_key ] ) ? $this->sanitize_hour( (string) $slot[ $hour_key ], 'end' === $key ) : '';
		$minute = isset( $slot[ $minute_key ] ) ? $this->sanitize_minute( (string) $slot[ $minute_key ] ) : '';

		if ( '' !== $hour && '' !== $minute ) {
			$time = $this->sanitize_time( sprintf( '%s:%s', $hour, $minute ) );
			if ( '' === $time ) {
				return '';
			}

			if ( 'start' === $key && '24:00' === $time ) {
				return '';
			}

			return $time;
		}

		return $this->sanitize_time( $slot[ $key ] ?? '' );
	}

	/**
	 * Split time string.
	 *
	 * @param string $time Time string.
	 * @return array<string, string>
	 */
	private function split_time( string $time ): array {
		$time = $this->sanitize_time( $time );

		if ( '' === $time ) {
			return [
				'hour'   => '',
				'minute' => '',
			];
		}

		[ $hour, $minute ] = explode( ':', $time );

		return [
			'hour'   => $hour,
			'minute' => $minute,
		];
	}

	/**
	 * Sanitize time string HH:MM.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	private function sanitize_time( string $time ): string {
		$time = trim( (string) $time );

		if ( '' === $time ) {
			return '';
		}

		if ( ! preg_match( '/^(2[0-4]|[01]?[0-9]):([0-5][0-9])$/', $time ) ) {
			return '';
		}

		[ $hour, $minute ] = explode( ':', $time );

		if ( 24 === (int) $hour && '00' !== $minute ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $hour, (int) $minute );
	}

	/**
	 * Sanitize hour component.
	 *
	 * @param string $hour Raw hour.
	 * @return string
	 */
	private function sanitize_hour( string $hour, bool $allow_24 = false ): string {
		$hour = trim( $hour );

		if ( '' === $hour || ! is_numeric( $hour ) ) {
			return '';
		}

		$int = (int) $hour;

		if ( $int < 0 || $int > 23 ) {
			if ( $allow_24 && 24 === $int ) {
				return sprintf( '%02d', $int );
			}

			return '';
		}

		return sprintf( '%02d', $int );
	}

	/**
	 * Sanitize minute component.
	 *
	 * @param string $minute Raw minute.
	 * @return string
	 */
	private function sanitize_minute( string $minute ): string {
		$minute = trim( $minute );

		if ( '' === $minute || ! is_numeric( $minute ) ) {
			return '';
		}

		$allowed = array_keys( $this->get_minute_options() );
		$value   = sprintf( '%02d', (int) $minute );

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Hour options 00-23.
	 *
	 * @return array<string, string>
	 */
	private function get_hour_options(): array {
		$options = [];

		for ( $i = 0; $i < 24; $i++ ) {
			$value             = sprintf( '%02d', $i );
			$options[ $value ] = $value;
		}

		return $options;
	}

	/**
	 * Hour options for end time (includes 24:00).
	 *
	 * @return array<string, string>
	 */
	private function get_end_hour_options(): array {
		$options        = $this->get_hour_options();
		$options['24'] = '24';

		return $options;
	}

	/**
	 * Minute options (10-minute increments).
	 *
	 * @return array<string, string>
	 */
	private function get_minute_options(): array {
		$values  = [ '00', '10', '20', '30', '40', '50' ];
		$options = [];

		foreach ( $values as $value ) {
			$options[ $value ] = $value;
		}

		return $options;
	}
}
