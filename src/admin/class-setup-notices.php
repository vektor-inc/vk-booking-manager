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
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Staff\Staff_Editor;
use WP_Query;

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
		add_action( 'admin_notices', array( $this, 'render_google_calendar_sync_broken_notice' ) );
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
	 * Google カレンダー連携: 再試行しても反映できない予約がある場合のお知らせ。
	 *
	 * `get_setup_items()`（「未設定の項目があります」の枠）には含めない。あの枠は「まだ
	 * 設定していない項目」を並べる場所で、見出し・案内文が「設定してください」前提のため、
	 * 「反映に失敗している」お知らせを混ぜると見出しと中身が合わない（安藤レビュー指摘）。
	 * `render_shift_auto_register_notice()` と同じく、独立した通知として出す。
	 *
	 * 「連携」タブを開ける権限の人にだけ、復旧するまで消えない形で出す（司の decision
	 * record 参照。開けない人に出すと行き止まりになるため）。
	 *
	 * @return void
	 */
	public function render_google_calendar_sync_broken_notice(): void {
		if ( $this->is_shift_dashboard_screen() ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PROVIDER_SETTINGS ) ) {
			return;
		}

		if ( ! $this->is_google_calendar_integration_reachable() ) {
			return;
		}

		$reason = $this->get_google_calendar_sync_broken_reason();

		if ( '' === $reason ) {
			return;
		}

		$button = $this->get_google_calendar_sync_broken_button( $reason );

		// REASON_OTHER のときだけ、失敗中の予約を一覧で示す（植草レビュー指摘 中。「連携」タブには
		// どの予約が失敗しているかの手がかりが無いため）。REASON_AUTH は再接続で全体が直る
		// 想定のため、個別の予約を並べる必要が無い。
		$failed_bookings     = Google_Calendar_Event_Sync::REASON_OTHER === $reason
			? $this->get_failed_google_calendar_bookings( 5 )
			: null;
		$has_listed_bookings = null !== $failed_bookings && array() !== $failed_bookings->posts;

		?>
		<div class="notice vkbm-notice vkbm-notice__warning">
			<h3><?php echo esc_html__( 'Some bookings could not be reflected in Google Calendar', 'vk-booking-manager' ); ?></h3>
			<p><?php echo esc_html( $this->get_google_calendar_sync_broken_message( $reason, $has_listed_bookings ) ); ?></p>
			<?php if ( $has_listed_bookings ) : ?>
				<ul>
					<?php foreach ( $failed_bookings->posts as $booking_id ) : ?>
						<li>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $booking_id . '&action=edit' ) ); ?>">
								<?php echo esc_html( get_the_title( (int) $booking_id ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $failed_bookings->found_posts > count( $failed_bookings->posts ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: number of additional bookings not shown in the list above. */
							esc_html__( 'There are %d more.', 'vk-booking-manager' ),
							(int) ( $failed_bookings->found_posts - count( $failed_bookings->posts ) )
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<div class="vkbm-buttons">
				<a class="button button-primary" href="<?php echo esc_url( $button['url'] ); ?>">
					<?php echo esc_html( $button['label'] ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * サイト全体のお知らせに列挙する、反映に失敗している予約（新しい順）を取得する。
	 *
	 * `_vkbm_google_calendar_sync_failed` が立っている予約を対象にする（植草レビュー指摘 中。
	 * 「連携」タブにはどの予約が失敗しているかの手がかりが無いため、このお知らせ自体に
	 * 対象を並べる）。「新しい順」は `modified`（予約の状態変化のたびに更新される）で
	 * 判定する。失敗した正確な日時は保持していないため、予約が最後に動いた日時を近似として
	 * 使う。
	 *
	 * このお知らせは管理画面のページを開くたびに実行されるため、クエリを軽くする
	 * （安藤レビュー指摘の考え方と同じ、無駄な負荷を避ける）。`fields => 'ids'` で
	 * 必要最小限の列だけ取得し、一覧の表示（タイトル・編集リンク）に使わない
	 * meta・term キャッシュのプライムは `update_post_meta_cache` / `update_post_term_cache`
	 * を false にして省く。総件数（「ほか n 件」の算出）に `found_posts` を使うため、
	 * `no_found_rows` は使わない（既定の false のまま）。
	 *
	 * @param int $limit 一覧に出す最大件数。
	 * @return WP_Query 予約の WP_Query（`posts` が投稿ID配列、`found_posts` が総件数）。
	 */
	private function get_failed_google_calendar_bookings( int $limit ): WP_Query {
		return new WP_Query(
			array(
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 失敗フラグは post_status 等では表現できず、このメタキーでの絞り込みが必須.
					array(
						'key'   => Google_Calendar_Event_Sync::META_SYNC_FAILED,
						'value' => '1',
					),
				),
			)
		);
	}

	/**
	 * Google カレンダー連携の「連携」タブが画面に出ている状態かどうかを返す。
	 *
	 * Pro 版であることと、中継サーバーの接続先が決まっていることの両方を満たす場合のみ
	 * true。タブが出ていない状態でお知らせのリンク先だけ出すと行き止まりになるため、
	 * このお知らせ自体を出さない判定に使う。
	 *
	 * @return bool
	 */
	private function is_google_calendar_integration_reachable(): bool {
		return Google_Calendar_Connect_Controller::is_integration_enabled()
			&& ( new Google_Calendar_Relay_Client() )->is_configured();
	}

	/**
	 * Google カレンダー連携の「反映できていない予約がある」お知らせの本文を組み立てる。
	 *
	 * 再試行しても直らない失敗の原因（{@see Google_Calendar_Event_Sync::REASON_AUTH} /
	 * {@see Google_Calendar_Event_Sync::REASON_OTHER}）によって文言を分ける。認可が
	 * 失われたわけではない失敗（例: 予約に日時が無い）にまで「アクセスが失われている
	 * 可能性」と出すと、オーナーが再接続しても直らず混乱する（植草レビュー指摘・安藤
	 * レビュー指摘）。1つの翻訳関数に複数文を入れないよう、文ごとに分けてから連結する
	 * （coding-rules.md の国際化ルールに準拠。`get_permalink_htaccess_notice_message()` と
	 * 同じ方式）。
	 *
	 * REASON_OTHER のときは以前「「連携」タブで詳細を確認してください。」と案内していたが、
	 * 「連携」タブにはどの予約が失敗しているかの詳細は無く、実際に取れる行動と文言が
	 * ずれていた（植草レビュー指摘）。失敗した予約自体は分からないため、当初は「予約一覧を
	 * 開いて該当の予約を確認し…」という案内文に差し替えたが、その後さらに植草レビュー指摘
	 * （中）で、お知らせ自体に対象の予約を最大5件並べるよう変更した
	 * （{@see get_failed_google_calendar_bookings()}）。一覧に1件以上出せた場合は「以下の
	 * 予約を開いて…」に、対象が1件も見つからない場合（稀。フラグは立っているが該当する
	 * 予約が見つからない等）は従来どおり「予約一覧を開いて…」にフォールバックする。
	 * ボタンの行き先・文言は {@see get_google_calendar_sync_broken_button()} を参照。
	 *
	 * @param string $reason              Google_Calendar_Event_Sync::REASON_* のいずれか。
	 * @param bool   $has_listed_bookings REASON_OTHER のとき、失敗中の予約を1件以上
	 *                                    一覧に出せたか。REASON_AUTH では使わない。
	 * @return string お知らせ文。
	 */
	private function get_google_calendar_sync_broken_message( string $reason, bool $has_listed_bookings = false ): string {
		if ( Google_Calendar_Event_Sync::REASON_AUTH === $reason ) {
			$message  = __( 'Reflecting to Google Calendar has failed repeatedly, possibly because access to Google was lost.', 'vk-booking-manager' );
			$message .= __( ' Please check the connection on the Integration tab.', 'vk-booking-manager' );

			return $message;
		}

		$message = __( 'Reflecting some bookings to Google Calendar has failed repeatedly.', 'vk-booking-manager' );

		if ( $has_listed_bookings ) {
			$message .= __( ' Open one of the bookings below and use the "Retry now" button on its edit screen.', 'vk-booking-manager' );
		} else {
			$message .= __( ' Please open the bookings list, find the affected booking, and use the "Retry now" button on its edit screen.', 'vk-booking-manager' );
		}

		return $message;
	}

	/**
	 * サイト全体のお知らせに添えるボタンの行き先・文言を、失敗理由に応じて返す。
	 *
	 * REASON_AUTH は連携の再接続が解決策なので「連携」タブへ、REASON_OTHER は
	 * どの予約が失敗しているか「連携」タブでは分からないため、予約一覧へ案内する
	 * （{@see get_google_calendar_sync_broken_message()} の文言と行き先を一致させる。
	 * 植草レビュー指摘）。
	 *
	 * @param string $reason Google_Calendar_Event_Sync::REASON_* のいずれか。
	 * @return array{label:string, url:string} ボタンの文言と行き先URL。
	 */
	private function get_google_calendar_sync_broken_button( string $reason ): array {
		if ( Google_Calendar_Event_Sync::REASON_AUTH === $reason ) {
			return array(
				'label' => __( 'Open the Integration tab', 'vk-booking-manager' ),
				'url'   => Google_Calendar_Connect_Controller::get_settings_tab_url(),
			);
		}

		return array(
			'label' => __( 'Open the bookings list', 'vk-booking-manager' ),
			'url'   => admin_url( 'edit.php?post_type=' . Booking_Post_Type::POST_TYPE ),
		);
	}

	/**
	 * Google カレンダー連携で、再試行しても反映できていない予約があるかどうかと、
	 * その理由を返す。
	 *
	 * `Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN` は、再試行の上限に達した時点で
	 * 理由付きで立ち、反映に成功すると消える（`Google_Calendar_Event_Sync` 参照）。
	 *
	 * @return string Google_Calendar_Event_Sync::REASON_* のいずれか。立っていなければ空文字。
	 */
	private function get_google_calendar_sync_broken_reason(): string {
		$reason = (string) get_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, '' );

		return in_array( $reason, array( Google_Calendar_Event_Sync::REASON_AUTH, Google_Calendar_Event_Sync::REASON_OTHER ), true ) ? $reason : '';
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
