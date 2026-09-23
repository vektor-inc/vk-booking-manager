<?php
/**
 * Provides setup notices in wp-admin.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Staff\Staff_Editor;

/**
 * Provides setup notices in wp-admin.
 */
class Setup_Notices {
	private const NONCE_ACTION = 'vkbm_dismiss_notice';

	private const USER_META_KEY    = 'vkbm_dismissed_notices_user';
	private const OPTION_META_KEY  = 'vkbm_dismissed_notices_global';
	private const SHIFT_META_YEAR  = '_vkbm_shift_year';
	private const SHIFT_META_MONTH = '_vkbm_shift_month';

	/**
	 * Cached result of has_missing_permalink_htaccess_rules() for the current request.
	 * null は未判定、true/false は判定済みの結果.
	 *
	 * @var bool|null
	 */
	private ?bool $missing_permalink_htaccess_rules_cache = null;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_shift_auto_register_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vkbm_dismiss_notice', array( $this, 'handle_dismiss' ) );
		add_action( 'vkbm_shift_dashboard_notices', array( $this, 'render_shift_dashboard_notice' ) );
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
			if ( array() !== $missing_items ) {
				$this->render_notice_markup( $missing_items );
			}
		}

		$missing_shift_months = $this->get_missing_shift_months();
		if ( array() !== $missing_shift_months ) {
			$this->render_shift_notice_markup( $missing_shift_months, ! $this->is_shift_post_type_screen() );
		}
	}

	/**
	 * Render setup notices for shift dashboard screen.
	 */
	public function render_shift_dashboard_notice(): void {
		if ( ! $this->is_setup_complete() ) {
			$missing_items = $this->get_missing_setup_items_for_user();
			if ( array() !== $missing_items ) {
				$this->render_notice_markup( $missing_items );
			}
		}

		$missing_shift_months = $this->get_missing_shift_months();
		if ( array() !== $missing_shift_months ) {
			$this->render_shift_notice_markup( $missing_shift_months, ! $this->is_shift_post_type_screen() );
		}
	}

	/**
	 * Render notice markup for missing setup items.
	 *
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
							$link    = sprintf(
								'<a href="%1$s">%2$s</a>',
								esc_url( $item['primary_url'] ),
								esc_html( $item['primary_label'] )
							);
							$message = sprintf( $item['message'], $link );
							?>
							<p><?php echo wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ); ?></p>
						<?php else : ?>
							<?php if ( '' !== ( $item['heading'] ?? '' ) ) : ?>
								<?php // 外側の h3（「Some items have not been set」）の1段下の見出しとして h4 にする（植草レビュー指摘）。 ?>
								<h4><?php echo esc_html( $item['heading'] ); ?></h4>
							<?php endif; ?>
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

		$has_setup_notice         = ! $this->is_setup_complete() && array() !== $this->get_missing_setup_items_for_user();
		$has_shift_notice         = array() !== $this->get_missing_shift_months();
		$has_auto_register_notice = Shift_Editor::is_shift_list_screen() && $this->has_shift_auto_register_enabled();
		if ( ! $has_setup_notice && ! $has_shift_notice && ! $has_auto_register_notice ) {
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
			wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
		}

		if ( ! $this->current_user_can_manage_notices() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( $_POST['notice_id'] ) ) : '';
		if ( '' === $notice_id ) {
			wp_send_json_error( array( 'message' => 'missing_notice_id' ), 400 );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'user';
		if ( 'global' === $scope ) {
			$this->dismiss_notice_globally( $notice_id );
			wp_send_json_success( array( 'scope' => 'global' ) );
		}

		$this->dismiss_notice_for_user( get_current_user_id(), $notice_id );
		wp_send_json_success( array( 'scope' => 'user' ) );
	}

	/**
	 * Get setup items definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_setup_items(): array {
		// 初期設定で不足している項目の定義一覧.
		$items = array(
			array(
				'id'              => 'provider_name',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_provider_name(),
				/* translators: %s: item name */
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Business name', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-provider-name' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
			array(
				'id'              => 'regular_holidays',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_regular_holidays_or_disabled(),
				/* translators: %s: item name */
				'message'         => __( '%s is not set. Please register "No regular holidays" or regular holidays.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Regular holiday', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-regular-holiday-disabled' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
			array(
				'id'              => 'business_hours_basic',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_basic_business_hours(),
				/* translators: %s: item name */
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Basic business hours', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-basic-business-hours' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
			array(
				'id'              => 'reservation_page_url',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_reservation_page_url(),
				/* translators: %s: item name */
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Reservation page URL', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-reservation-page-url' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
			array(
				'id'              => 'provider_email',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_provider_email(),
				/* translators: %s: item name */
				'message'         => __( 'Please register %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Representative email address', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-provider-email' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
			array(
				'id'              => 'privacy_policy_mode',
				'capability'      => Capabilities::MANAGE_PROVIDER_SETTINGS,
				'is_missing'      => fn () => ! $this->has_privacy_policy_mode(),
				/* translators: %s: item name */
				'message'         => __( 'Please select %s.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Privacy policy', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-privacy-policy-mode' ),
				'secondary_label' => '',
				'secondary_url'   => '',
				'action_style'    => 'link',
			),
		);

		// スタッフ機能が有効な場合のみスタッフ設定を促す.
		if ( Staff_Editor::is_enabled() ) {
			$items[] = array(
				'id'              => 'staff',
				'capability'      => Capabilities::MANAGE_STAFF,
				'is_missing'      => fn () => ! $this->has_posts( Resource_Post_Type::POST_TYPE ),
				'message'         => __( 'No staff members have been registered yet. First, add staff.', 'vk-booking-manager' ),
				'primary_label'   => __( 'Add staff', 'vk-booking-manager' ),
				'primary_url'     => admin_url( 'post-new.php?post_type=' . Resource_Post_Type::POST_TYPE ),
				'secondary_label' => __( 'Staff list', 'vk-booking-manager' ),
				'secondary_url'   => admin_url( 'edit.php?post_type=' . Resource_Post_Type::POST_TYPE ),
			);
		}

		// サービスメニューの登録は常に必須.
		$items[] = array(
			'id'              => 'service_menu',
			'capability'      => Capabilities::MANAGE_SERVICE_MENUS,
			'is_missing'      => fn () => ! $this->has_posts( Service_Menu_Post_Type::POST_TYPE ),
			'message'         => __( 'Service not registered yet. First, add a service menu.', 'vk-booking-manager' ),
			'primary_label'   => __( 'Add service', 'vk-booking-manager' ),
			'primary_url'     => admin_url( 'post-new.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ),
			'secondary_label' => __( 'Service list', 'vk-booking-manager' ),
			'secondary_url'   => admin_url( 'edit.php?post_type=' . Service_Menu_Post_Type::POST_TYPE ),
		);

		// サーバー側（.htaccess）に WordPress の書き換えルールが反映されていないと、
		// 予約ページの REST 通信が失敗して予約フォームやログインが使えなくなる（issue #489）。
		// 他の項目とは性質が異なるため、専用の見出し（heading）付きで表示する。パーマリンク設定は
		// WordPress コアの manage_options 権限が必要な画面のため、権限もそれに合わせる。
		$items[] = array(
			'id'              => 'permalink_htaccess_rules',
			'capability'      => 'manage_options',
			'is_missing'      => fn () => $this->has_missing_permalink_htaccess_rules(),
			'heading'         => __( 'Permalink settings may not have been saved', 'vk-booking-manager' ),
			'message'         => $this->get_permalink_htaccess_notice_message(),
			'primary_label'   => __( 'Open permalink settings', 'vk-booking-manager' ),
			'primary_url'     => admin_url( 'options-permalink.php' ),
			'secondary_label' => '',
			'secondary_url'   => '',
		);

		return $items;
	}

	/**
	 * Build the message body for the permalink .htaccess notice.
	 *
	 * 1つの __() に複数文を入れないよう、文ごとに分けてから連結する
	 * （coding-rules.md の国際化ルールに準拠）。2文目・3文目は英語表示時のみ
	 * 先頭に半角スペースが要るため msgid 側に含めている（日本語訳は先頭スペース無しで連結する）。
	 *
	 * @return string
	 */
	private function get_permalink_htaccess_notice_message(): string {
		$message  = __( "The server's URL rewrite settings (.htaccess) do not contain the WordPress rules.", 'vk-booking-manager' );
		$message .= __( ' The booking page may fail to communicate in this state.', 'vk-booking-manager' );
		$message .= __( ' Open the permalink settings and click "Save Changes" without changing anything.', 'vk-booking-manager' );

		return $message;
	}

	/**
	 * Check whether the .htaccess is missing the WordPress rewrite rules even though
	 * mod_rewrite-based (pretty) permalinks are configured.
	 *
	 * パーマリンク設定（DB）はあるのに、サーバー側（.htaccess）に WordPress の
	 * 書き換えルールが反映されていないと、REST API への通信が 404 になり
	 * 予約ページが使えなくなる（issue #489）。nginx 等、mod_rewrite を使わない環境は
	 * .htaccess で判定できないため、ここでは対象外（false を返す）とする。
	 *
	 * マルチサイトでは `.htaccess` がネットワーク全体で共有され、かつ
	 * `get_home_path()` の前提（1サイト1ドキュメントルート）が成り立たないため、
	 * 判定対象外（false を返す）とする（安藤レビュー指摘 MEDIUM-2）。
	 *
	 * 判定結果は1リクエスト内で複数回呼ばれうる（`render_notices()` /
	 * `render_shift_dashboard_notice()` / `enqueue_assets()` など）ため、
	 * ファイル I/O とマーカー解析を毎回行わないようインスタンスプロパティへ
	 * キャッシュする（安藤レビュー指摘 LOW-1）。
	 *
	 * @return bool
	 */
	private function has_missing_permalink_htaccess_rules(): bool {
		if ( null !== $this->missing_permalink_htaccess_rules_cache ) {
			return $this->missing_permalink_htaccess_rules_cache;
		}

		$this->missing_permalink_htaccess_rules_cache = $this->detect_missing_permalink_htaccess_rules();

		return $this->missing_permalink_htaccess_rules_cache;
	}

	/**
	 * Actually detect whether the .htaccess is missing the WordPress rewrite rules.
	 *
	 * `has_missing_permalink_htaccess_rules()` のキャッシュ機構から分離した実処理.
	 *
	 * @return bool
	 */
	private function detect_missing_permalink_htaccess_rules(): bool {
		if ( is_multisite() ) {
			return false;
		}

		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return false;
		}

		if ( ! $wp_rewrite->using_mod_rewrite_permalinks() ) {
			return false;
		}

		if ( ! function_exists( 'got_mod_rewrite' ) || ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! got_mod_rewrite() ) {
			return false;
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// BEGIN/END WordPress マーカー内が空（またはファイル自体が無い）なら、
		// 書き換えルールが反映されていない状態とみなす.
		$htaccess_rules = extract_from_markers( get_home_path() . '.htaccess', 'WordPress' );

		return array() === $htaccess_rules;
	}

	/**
	 * Get missing setup items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_missing_setup_items(): array {
		$missing = array();
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
	 * Get missing setup items for current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_missing_setup_items_for_user(): array {
		$missing = array();
		foreach ( $this->get_missing_setup_items() as $item ) {
			if ( isset( $item['capability'] ) && ! current_user_can( (string) $item['capability'] ) ) {
				continue;
			}

			$missing[] = $item;
		}

		return $missing;
	}

	/**
	 * Check if setup is complete.
	 *
	 * @return bool
	 */
	private function is_setup_complete(): bool {
		return array() === $this->get_missing_setup_items();
	}

	/**
	 * Check if posts exist for the given post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function has_posts( string $post_type ): bool {
		$counts = wp_count_posts( $post_type );
		if ( ! is_object( $counts ) ) {
			return false;
		}

		$total = 0;
		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $status ) {
			if ( isset( $counts->$status ) ) {
				$total += (int) $counts->$status;
			}
		}

		return $total > 0;
	}

	/**
	 * Check if basic business hours are set.
	 *
	 * @return bool
	 */
	private function has_basic_business_hours(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$basic      = $settings['provider_business_hours_basic'] ?? array();

		return is_array( $basic ) && array() !== $basic;
	}

	/**
	 * Check if provider name is set.
	 *
	 * @return bool
	 */
	private function has_provider_name(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$name       = isset( $settings['provider_name'] ) ? trim( (string) $settings['provider_name'] ) : '';

		return '' !== $name;
	}

	/**
	 * Check if regular holidays are set or disabled.
	 *
	 * @return bool
	 */
	private function has_regular_holidays_or_disabled(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$disabled   = ! empty( $settings['provider_regular_holidays_disabled'] );

		if ( $disabled ) {
			return true;
		}

		$holidays = $settings['provider_regular_holidays'] ?? array();

		return is_array( $holidays ) && array() !== $holidays;
	}

	/**
	 * Check if reservation page URL is set.
	 *
	 * @return bool
	 */
	private function has_reservation_page_url(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$url        = isset( $settings['reservation_page_url'] ) ? trim( (string) $settings['reservation_page_url'] ) : '';

		return '' !== $url;
	}

	/**
	 * Get missing shift months.
	 *
	 * @return array<int, array{year:int,month:int}>
	 */
	private function get_missing_shift_months(): array {
		if ( ! $this->has_published_resources() ) {
			return array();
		}

		$months_ahead = $this->get_shift_alert_months();
		$months_ahead = max( 0, $months_ahead );

		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );
		$current  = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 1 );

		$missing = array();

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

			$missing[] = array(
				'year'  => $year,
				'month' => $month,
			);
		}

		return $missing;
	}

	/**
	 * Check if published resources exist.
	 *
	 * @return bool
	 */
	private function has_published_resources(): bool {
		$counts = wp_count_posts( Resource_Post_Type::POST_TYPE );
		if ( ! is_object( $counts ) ) {
			return false;
		}

		return isset( $counts->publish ) && (int) $counts->publish > 0;
	}

	/**
	 * Check if shift exists for the given month.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return bool
	 */
	private function has_shift_for_month( int $year, int $month ): bool {
		$posts = get_posts(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::SHIFT_META_YEAR,
						'value'   => (string) $year,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::SHIFT_META_MONTH,
						'value'   => (string) $month,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return ! empty( $posts );
	}

	/**
	 * Get shift alert months setting.
	 *
	 * @return int
	 */
	private function get_shift_alert_months(): int {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$value      = isset( $settings['shift_alert_months'] ) ? (int) $settings['shift_alert_months'] : 1;

		return min( 4, max( 1, $value ) );
	}

	/**
	 * Render shift notice markup.
	 *
	 * @param array<int, array{year:int,month:int}> $missing_months Missing shift months.
	 * @param bool                                  $show_action     Whether to show action button.
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

	/**
	 * Check if current screen is shift dashboard.
	 *
	 * @return bool
	 */
	private function is_shift_dashboard_screen(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && 'toplevel_page_vkbm-shift-dashboard' === $screen->id ) {
				return true;
			}
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		return 'vkbm-shift-dashboard' === $page;
	}

	/**
	 * Check if current screen is shift post type.
	 *
	 * @return bool
	 */
	private function is_shift_post_type_screen(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->post_type ) && Shift_Post_Type::POST_TYPE === $screen->post_type ) {
				return true;
			}
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		return Shift_Post_Type::POST_TYPE === $post_type;
	}

	/**
	 * Check whether shift auto registration is currently enabled.
	 *
	 * 許可値の判定基準は Shift_Editor::is_auto_register_enabled_for() に一本化している
	 * （Settings_Sanitizer・Shift_Editor 自身の判定と基準をそろえるため。安藤レビュー指摘）。
	 *
	 * @return bool
	 */
	private function has_shift_auto_register_enabled(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$value      = isset( $settings['shift_auto_register_months'] ) ? (int) $settings['shift_auto_register_months'] : 0;

		return Shift_Editor::is_auto_register_enabled_for( $value );
	}

	/**
	 * Render an alert on the shift list screen when shift auto registration is configured.
	 *
	 * シフトの自動登録が設定されている場合、公開シフトが管理者の目視確認なしに自動で
	 * 登録されるため、シフト一覧画面で内容確認を促す注意喚起を表示する。
	 * 画面判定は Shift_Editor::is_shift_list_screen() に一本化している（安藤レビュー指摘）。
	 * BM設定への権限（MANAGE_PROVIDER_SETTINGS）が無い利用者には、リンクにせず文字だけで表示する。
	 */
	public function render_shift_auto_register_notice(): void {
		if ( ! Shift_Editor::is_shift_list_screen() ) {
			return;
		}

		if ( ! $this->has_shift_auto_register_enabled() ) {
			return;
		}

		$label = __( 'Shift auto registration', 'vk-booking-manager' );

		if ( current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) {
			$link_url = admin_url( 'admin.php?page=vkbm-provider-settings&tab=advanced#vkbm-shift-auto-register-months' );
			$label    = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link_url ), esc_html( $label ) );
		} else {
			$label = esc_html( $label );
		}

		// 1つの __() に複数の文を入れないよう、文ごとに分けてから連結する。
		// 2文目は英語表示時のみ先頭に半角スペースが要るため、msgid 側に含めている
		// （日本語訳は先頭スペース無しで連結する）。
		$message = sprintf(
			/* translators: %s: link to the shift auto registration setting, or plain text if the user lacks permission to view the setting */
			__( '%s is configured.', 'vk-booking-manager' ),
			$label
		);
		$message .= __( ' Please check carefully that there are no discrepancies between the registered shift information and the actual shifts.', 'vk-booking-manager' );
		?>
		<div class="notice vkbm-notice vkbm-notice__warning">
			<p><?php echo wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Check if provider email is set.
	 *
	 * @return bool
	 */
	private function has_provider_email(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$email      = isset( $settings['provider_email'] ) ? trim( (string) $settings['provider_email'] ) : '';

		return '' !== $email;
	}

	/**
	 * Check if privacy policy mode is set.
	 *
	 * @return bool
	 */
	private function has_privacy_policy_mode(): bool {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$mode       = isset( $settings['provider_privacy_policy_mode'] )
			? sanitize_key( (string) $settings['provider_privacy_policy_mode'] )
			: 'none';

		return 'none' !== $mode;
	}

	/**
	 * Check if notice is dismissed for user.
	 *
	 * @param string $notice_id Notice ID.
	 * @return bool
	 */
	private function is_notice_dismissed_for_user( string $notice_id ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$dismissed = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		return array_key_exists( $notice_id, $dismissed );
	}

	/**
	 * Dismiss notice for user.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $notice_id Notice ID.
	 */
	private function dismiss_notice_for_user( int $user_id, string $notice_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$dismissed = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		$dismissed[ $notice_id ] = time();
		update_user_meta( $user_id, self::USER_META_KEY, $dismissed );
	}

	/**
	 * Dismiss notice globally.
	 *
	 * @param string $notice_id Notice ID.
	 */
	private function dismiss_notice_globally( string $notice_id ): void {
		$dismissed = get_option( self::OPTION_META_KEY, array() );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		$dismissed[ $notice_id ] = time();
		update_option( self::OPTION_META_KEY, $dismissed, false );
	}

	/**
	 * Check if current user can manage notices.
	 *
	 * @return bool
	 */
	private function current_user_can_manage_notices(): bool {
		foreach ( $this->get_setup_items() as $item ) {
			if ( isset( $item['capability'] ) && current_user_can( (string) $item['capability'] ) ) {
				return true;
			}
		}

		return false;
	}
}
