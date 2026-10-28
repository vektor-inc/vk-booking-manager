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

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Common\Weekday_Rule;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
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
	// メニュー単位で指名機能を無効化するメタキー（#391）。既定（未設定）は「指名を使う」。
	private const META_DISABLE_NOMINATION          = '_vkbm_disable_nomination';
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
		add_action( 'rest_api_init', array( $this, 'register_notice_route' ) );
	}

	/**
	 * 保存時のお知らせをブロックエディタへ渡す REST ルートを登録する。
	 *
	 * ブロックエディタ画面では PHP 側で表示も消費もせず、エディタの JS がこのルートで取得（取得と同時に消費）して
	 * core/notices で表示する。
	 */
	public function register_notice_route(): void {
		register_rest_route(
			'vkbm/v1',
			'/service-menu-notice',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_consume_notice' ),
				'permission_callback' => array( $this, 'can_read_notice' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * お知らせ取得ルートの権限判定（対象のサービスメニューを編集できる利用者のみ）。
	 *
	 * Cookie 認証の nonce（X-WP-Nonce）は REST 基盤が検証する。
	 *
	 * @param \WP_REST_Request $request リクエスト。
	 * @return bool
	 */
	public function can_read_notice( $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return $post_id > 0
			&& Service_Menu_Post_Type::POST_TYPE === get_post_type( $post_id )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * お知らせを一時保存するキーを返す（ログイン中の利用者ごと）。
	 *
	 * @return string 一時保存のキー。
	 */
	private static function get_notice_transient_key(): string {
		return self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * ログイン中の利用者向けのお知らせを返し、同時に消費する。
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_consume_notice() {
		$transient_key = self::get_notice_transient_key();
		$message       = get_transient( $transient_key );
		if ( ! is_string( $message ) || '' === $message ) {
			return rest_ensure_response( array( 'message' => '' ) );
		}
		delete_transient( $transient_key );
		return rest_ensure_response( array( 'message' => $message ) );
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

		// ブロックエディタ画面では、お知らせをエディタの JS が REST で取得して表示する（ここでは表示も消費もしない）。
		// 保存時の meta-box-loader の POST のリダイレクト先もブロックエディタ画面として描画されるが、その応答は
		// 利用者に見えないため、ここで消費するとお知らせが届く前に消えてしまう。
		if ( $screen->is_block_editor() ) {
			return;
		}

		$transient_key = self::get_notice_transient_key();
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
	 * サブ画像欄の文言を返す（PHP の初期描画と JS で同じ文言を使うため1か所に集約する）。
	 *
	 * @return array<string,string> キーごとの文言。
	 */
	private static function get_gallery_strings(): array {
		return array(
			'label'              => __( 'Sub images', 'vk-booking-manager' ),
			'add'                => __( '+ Add images', 'vk-booking-manager' ),
			'mediaTitle'         => __( 'Select sub images', 'vk-booking-manager' ),
			'mediaButton'        => __( 'Add to sub images', 'vk-booking-manager' ),
			/* translators: %d: number of images. */
			'countSingular'      => __( '%d image', 'vk-booking-manager' ),
			/* translators: %d: number of images. */
			'countPlural'        => __( '%d images', 'vk-booking-manager' ),
			/* translators: %d: position of the image in the list (starting from 1). */
			'removeAria'         => __( 'Remove image %d from sub images', 'vk-booking-manager' ),
			/* translators: %d: position of the image in the list (starting from 1). */
			'moveEarlierAria'    => __( 'Move image %d earlier', 'vk-booking-manager' ),
			/* translators: %d: position of the image in the list (starting from 1). */
			'moveLaterAria'      => __( 'Move image %d later', 'vk-booking-manager' ),
			'moveEarlier'        => __( 'Move earlier', 'vk-booking-manager' ),
			'moveLater'          => __( 'Move later', 'vk-booking-manager' ),
			/* translators: 1: new position of the image, 2: total number of images. */
			'movedAnnounce'      => __( 'Moved to position %1$d of %2$d.', 'vk-booking-manager' ),
			'removedAnnounce'    => __( 'Image removed.', 'vk-booking-manager' ),
			'clear'              => __( 'Remove all', 'vk-booking-manager' ),
			'cleared'            => __( 'All images removed.', 'vk-booking-manager' ),
			'undo'               => __( 'Undo', 'vk-booking-manager' ),
			'restoredAnnounce'   => __( 'All images restored.', 'vk-booking-manager' ),
			/* translators: %d: number of images. */
			'skippedSingular'    => __( '%d image that was already added was not added again.', 'vk-booking-manager' ),
			/* translators: %d: number of images. */
			'skippedPlural'      => __( '%d images that were already added were not added again.', 'vk-booking-manager' ),
			'description'        => __( 'Shown as small images below the duration, capacity, and other details on the menu card. Clicking an image enlarges it.', 'vk-booking-manager' ),
			'descriptionReorder' => __( 'To change the order, drag an image or use the move buttons.', 'vk-booking-manager' ),
			'descriptionRemove'  => __( 'Removing an image here does not delete it from the media library.', 'vk-booking-manager' ),
		);
	}

	/**
	 * サブ画像欄（キャッチコピーの直後の行）を描画する。
	 *
	 * 画像の追加・削除・並び替えは assets/js/service-menu-gallery.js が行い、
	 * 並び順どおりの添付IDを hidden 欄に「12,34,56」の形式で保持して保存時に送る。
	 *
	 * @param WP_Post $post 編集中のサービスメニュー。
	 */
	private function render_gallery_row( WP_Post $post ): void {
		$strings        = self::get_gallery_strings();
		$attachment_ids = Service_Menu_Post_Type::get_gallery_attachment_ids( $post->ID );
		$total          = count( $attachment_ids );
		?>
		<tr class="vkbm-gallery-row">
			<th scope="row">
				<span id="vkbm-gallery-label"><?php echo esc_html( $strings['label'] ); ?></span>
			</th>
			<td>
				<div class="vkbm-gallery" data-vkbm-gallery="1">
					<ul class="vkbm-gallery__list" aria-labelledby="vkbm-gallery-label">
						<?php foreach ( $attachment_ids as $index => $attachment_id ) : ?>
							<?php $position = $index + 1; ?>
							<li class="vkbm-gallery__item" data-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
								<div class="vkbm-gallery__thumb">
									<?php
									echo wp_get_attachment_image(
										$attachment_id,
										'thumbnail',
										false,
										array(
											'class' => 'vkbm-gallery__image',
											'alt'   => '',
										)
									);
									?>
									<button type="button" class="vkbm-gallery__remove" aria-label="<?php echo esc_attr( sprintf( $strings['removeAria'], $position ) ); ?>">&times;</button>
								</div>
								<div class="vkbm-gallery__move">
									<button type="button" class="vkbm-gallery__move-button vkbm-gallery__move-earlier" title="<?php echo esc_attr( $strings['moveEarlier'] ); ?>" aria-label="<?php echo esc_attr( sprintf( $strings['moveEarlierAria'], $position ) ); ?>"<?php disabled( 1 === $position ); ?>><span aria-hidden="true">&larr;</span></button>
									<button type="button" class="vkbm-gallery__move-button vkbm-gallery__move-later" title="<?php echo esc_attr( $strings['moveLater'] ); ?>" aria-label="<?php echo esc_attr( sprintf( $strings['moveLaterAria'], $position ) ); ?>"<?php disabled( $position === $total ); ?>><span aria-hidden="true">&rarr;</span></button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
					<div class="vkbm-gallery__toolbar">
						<button type="button" class="button vkbm-gallery__add"><?php echo esc_html( $strings['add'] ); ?></button>
						<span class="vkbm-gallery__count"><?php echo esc_html( sprintf( 1 === $total ? $strings['countSingular'] : $strings['countPlural'], $total ) ); ?></span>
						<button type="button" class="button-link vkbm-gallery__clear"<?php echo $total >= 2 ? '' : ' hidden'; ?>><?php echo esc_html( $strings['clear'] ); ?></button>
						<span class="vkbm-gallery__undo-notice" hidden>
							<?php echo esc_html( $strings['cleared'] ); ?>
							<button type="button" class="button-link vkbm-gallery__undo"><?php echo esc_html( $strings['undo'] ); ?></button>
						</span>
					</div>
					<p class="vkbm-gallery__status" role="status"></p>
					<input type="hidden" name="vkbm_service_menu[gallery_ids]" class="vkbm-gallery__input" value="<?php echo esc_attr( implode( ',', $attachment_ids ) ); ?>" />
					<p class="description"><?php echo esc_html( $strings['description'] ); ?><br /><?php echo esc_html( $strings['descriptionReorder'] ); ?><br /><?php echo esc_html( $strings['descriptionRemove'] ); ?></p>
				</div>
			</td>
		</tr>
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

		// サービスメニュー編集画面の独自 UI（料金・予約可能日・サブ画像など）の CSS（issue #659）。
		wp_enqueue_style( Common_Styles::ADMIN_HANDLE );

		$base_url = plugin_dir_url( VKBM_PLUGIN_FILE );
		wp_enqueue_script(
			'vkbm-service-menu-editor',
			$base_url . 'assets/js/service-menu-editor.js',
			array( 'jquery' ),
			VKBM_VERSION,
			true
		);

		// 保存時のお知らせをブロックエディタの通知（core/notices）で表示する。
		if ( $screen->is_block_editor() ) {
			wp_enqueue_script(
				'vkbm-service-menu-editor-notices',
				$base_url . 'assets/js/service-menu-editor-notices.js',
				array( 'wp-data', 'wp-api-fetch', 'wp-notices', 'wp-edit-post' ),
				VKBM_VERSION,
				true
			);
			wp_localize_script(
				'vkbm-service-menu-editor-notices',
				'vkbmServiceMenuNotices',
				array( 'postId' => (int) get_the_ID() )
			);
		}

		// サブ画像欄（#623）。メディア選択画面・並び替え・読み上げに必要な依存を読み込む。
		wp_enqueue_media();
		wp_enqueue_script(
			'vkbm-service-menu-gallery',
			$base_url . 'assets/js/service-menu-gallery.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-a11y' ),
			VKBM_VERSION,
			true
		);
		$resource_selection_strings = vkbm_get_resource_selection_strings();
		wp_localize_script(
			'vkbm-service-menu-gallery',
			'vkbmServiceMenuGallery',
			array( 'i18n' => self::get_gallery_strings() )
		);

		wp_localize_script(
			'vkbm-service-menu-editor',
			'vkbmServiceMenuEditor',
			array(
				'i18n'      => array(
					// Translators: Button label to remove a fixed start time row. / 固定開始時刻の行を削除するボタンのラベル.
					'delete'                            => __( 'Delete', 'vk-booking-manager' ),
					// Translators: Placeholder for the price category name input. / 料金区分名の入力プレースホルダー.
					'priceTierLabelPlaceholder'         => __( 'Category name (e.g. Adult)', 'vk-booking-manager' ),
					// Translators: aria-label for the price category name input. / 料金区分名入力の aria-label.
					'priceTierLabelAria'                => __( 'Price category name', 'vk-booking-manager' ),
					// Translators: aria-label for the price input. / 料金入力の aria-label.
					'priceTierPriceAria'                => __( 'Price', 'vk-booking-manager' ),
					// #393: 最少催行人数／最低申し込み人数のラベル・説明文（「指名を使う」チェック切替と
					// 連動して JS 側で即時切り替える。PHP のレンダリング分岐と同じ文言を渡す）。
					'minCapacityDefaultLabel'           => __( 'Minimum participants to confirm', 'vk-booking-manager' ),
					'minCapacityNominationLabel'        => __( 'Minimum guests to accept a booking', 'vk-booking-manager' ),
					'minCapacityDefaultDescription1'    => __( 'When bookings sharing the same time slot reach this number, the session is treated as confirmed.', 'vk-booking-manager' ),
					'minCapacityDefaultDescription2'    => __( 'Enter 0 to disable the minimum and accept any number of bookings.', 'vk-booking-manager' ),
					'minCapacityDefaultDescription3'    => __( 'Values above the time slot capacity are reduced to that capacity.', 'vk-booking-manager' ),
					'minCapacityNominationDescription1' => $resource_selection_strings['min_capacity_nomination_1'],
					'minCapacityNominationDescription2' => __( 'If the number of guests is below this value, the reservation cannot be accepted.', 'vk-booking-manager' ),
					'minCapacityNominationDescription3' => __( 'Enter 0 to remove the minimum and accept bookings from 1 guest.', 'vk-booking-manager' ),
					'minCapacityNominationDescription4' => __( 'Values above the time slot capacity (the maximum guests per group) are reduced to that capacity.', 'vk-booking-manager' ),
					// #935: 宿泊の「1名1泊あたり」プランで人数の下限・予約枠の定員の欄に出す文言（PHP 初期描画と同じ文言）。
					'minCapacityNightDescription1'      => __( 'Each booking is for one group using one room.', 'vk-booking-manager' ),
					'maxCapacityNightDescription1'      => __( 'Maximum number of guests that can be booked together in a single reservation.', 'vk-booking-manager' ),
					// #485: 担当できるリソースが「選ぶ」かつチェック0件のときに role="status" 領域へ入れる案内文
					// （PHP 初期描画 render_staff_meta_box() と同じ文言）。
					'staffUnselectedNotice'             => self::get_staff_unselected_notice(),
					// #572: 泊単位の料金の単位に合わせて JS で切り替える文言（PHP 初期描画と同じ文言）。
					'nightPriceUnitPerGuest'            => self::get_night_price_unit_suffix( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST ),
					'nightPriceUnitPerRoom'             => self::get_night_price_unit_suffix( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM ),
					// #737: 泊のプランの料金区分（平日・休前日・特定日）の列の見出しと読み上げ用の名前。新しい行を JS で作るときに使う。
					'supportsLodging'                   => Industry_Presets::supports( 'lodging' ),
					'rateRegular'                       => __( 'Regular', 'vk-booking-manager' ),
					'ratePreHoliday'                    => __( 'Pre-holiday', 'vk-booking-manager' ),
					'rateSpecial'                       => __( 'Special date', 'vk-booking-manager' ),
					'priceTierPreHolidayAria'           => __( 'Price (pre-holiday)', 'vk-booking-manager' ),
					'priceTierSpecialAria'              => __( 'Price (special date)', 'vk-booking-manager' ),
					'priceHeadDefault'                  => __( 'Price', 'vk-booking-manager' ),
					'priceHeadNight'                    => __( 'Price (per guest, per night)', 'vk-booking-manager' ),
					// #934: 1室1泊あたりで規定人数を超えた分の追加料金を使うときに切り替える、区分の列見出しと1室の最大人数の説明文。
					'priceHeadExtraGuest'               => __( 'Additional fee per extra guest, per night', 'vk-booking-manager' ),
					'maxCapacityExtraGuestNote'         => __( 'The price does not change with the number of people up to the number included in the basic price.', 'vk-booking-manager' ),
					'multipleGuestsPriceNoteDefault'    => __( 'The price is multiplied by the number of people.', 'vk-booking-manager' ),
					'multipleGuestsPriceNotePerRoom'    => __( 'The price does not change with the number of people.', 'vk-booking-manager' ),
					// #831: 1室1泊あたりのときに「予約枠の定員」から切り替える、1室の最大人数のラベル・説明文。
					'maxCapacityDefaultLabel'           => __( 'Time slot capacity', 'vk-booking-manager' ),
					'maxCapacityDefaultDescription1'    => $resource_selection_strings['max_capacity_default_1'],
					'maxCapacityDefaultDescription2'    => $resource_selection_strings['max_capacity_default_2'],
					'maxCapacityNominationDescription1' => $resource_selection_strings['max_capacity_nomination_1'],
					'maxCapacityNominationDescription2' => $resource_selection_strings['max_capacity_nomination_2'],
					'maxCapacityDefaultNote'            => __( 'Default is 1.', 'vk-booking-manager' ),
					'exclusiveWhenBookedSlot'           => $resource_selection_strings['exclusive_when_booked'],
					'exclusiveWhenBookedNight'          => $resource_selection_strings['exclusive_when_booked_night'],
					'exclusiveUserSelectableSlot'       => $resource_selection_strings['exclusive_user_selectable'],
					'exclusiveUserSelectableNight'      => $resource_selection_strings['exclusive_user_night'],
					'maxCapacityPerRoomLabel'           => __( 'Maximum guests per room', 'vk-booking-manager' ),
					'maxCapacityPerRoomDescription1'    => __( 'Enter the maximum number of guests who can stay in this room.', 'vk-booking-manager' ),
					'maxCapacityPerRoomDescription2'    => __( 'Guests can choose up to this number in the number of guests field on the reservation screen.', 'vk-booking-manager' ),
					'maxCapacityPerRoomDescription3'    => __( 'The price does not change with the number of people.', 'vk-booking-manager' ),
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
				// メタボックスのタイトルは WordPress 本体が未エスケープで出力するため、利用者入力由来のラベルはここでエスケープする。
				esc_html(
					sprintf(
						/* translators: %s: resource label (plural), e.g. "Staff". */
						__( '%s collaboration', 'vk-booking-manager' ),
						vkbm_get_resource_label_plural()
					)
				),
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
				<p class="description">
					<?php
					// 未記入の場合の説明（基本設定画面の該当欄へのリンク付き）。
					printf(
						/* translators: %s: link to the reservation deadline setting on the provider settings page. */
						esc_html__( 'If not filled in, the information entered on %s will be reflected.', 'vk-booking-manager' ),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-provider-reservation-deadline' ) ),
							esc_html__( 'Reservation deadline on the General Settings page', 'vk-booking-manager' )
						)
					);
					?>
				</p>
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
					<?php
					// 未記入の場合の説明（基本設定画面の該当欄へのリンク付き）。
					printf(
						/* translators: %s: link to the max advance booking period setting on the provider settings page. */
						esc_html__( 'If not filled in, the information entered on %s will be reflected.', 'vk-booking-manager' ),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-provider-max-advance-booking-days' ) ),
							esc_html__( 'Max advance booking period on the General Settings page', 'vk-booking-manager' )
						)
					);
					?>
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
		$disable_nomination_fee = (string) get_post_meta( $post->ID, self::META_DISABLE_NOMINATION_FEE, true );
		// サイト全体の指名機能スイッチ（基本設定）。メニュー単位トグルの表示可否にのみ使う。
		// #412 C-7: Free版では Staff_Editor::is_nomination_enabled() が常に false を返すため
		// 暗黙にPro限定になっていたが、保存側（save_post、$is_pro_edition の明示チェック）と
		// 対称にするため、表示側でも明示的に Pro 版判定を行う。
		$is_pro_edition                 = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		$is_nomination_enabled_globally = $is_pro_edition && Staff_Editor::is_nomination_enabled();
		?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_catch_copy"><?php esc_html_e( 'Catchphrase', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="text" id="vkbm_service_menu_catch_copy" name="vkbm_service_menu[catch_copy]" class="regular-text" value="<?php echo esc_attr( $catch_copy ); ?>" />
			</td>
		</tr>
		<?php
		// キャッチコピーの直後にサブ画像欄を出す（#623）。
		$this->render_gallery_row( $post );
		// #514: 「基本料金」の行は、料金区分（price tiers）が入る位置の直前に置く必要があるため、
		// render_conditions_meta_box() 側（料金区分の行の直前、または料金区分が表示されない
		// 構成ではその位置に相当する末尾）で render_base_price_field() を呼び出す形に分離した。
		// ここでは描画しない。
		//
		// #391: サイト全体の指名機能がONのときだけ、メニュー単位の「指名を使う」設定を表示する。
		// サイト全体がOFFのサイトでは、メニュー単位で切り替える余地自体が無いため表示しない
		// （このメタの auth_callback もサイト全体ONを要求しており、表示条件と保存条件を揃えている）。
		//
		// #412 B-2: 直下の「Nomination fee」はこのメニューが指名を使う場合のみ意味を持つ設定
		// （依存先）のため、依存元である本チェックボックスを先に配置する（design-rules の
		// 設定の並び順ルール）。以前は render_conditions_meta_box 側にあったが、
		// render_vkbm_meta_box() が render_basic_meta_box → render_conditions_meta_box の順で
		// 同一テーブルへ描画するため、「Nomination fee」より後ろに出てしまっていた。
		if ( $is_nomination_enabled_globally ) :
			$nomination_disabled_for_menu  = (bool) get_post_meta( $post->ID, self::META_DISABLE_NOMINATION, true );
			$use_nomination_description_id = 'vkbm-use-nomination-description';
			$resource_selection_strings    = vkbm_get_resource_selection_strings();
			$hide_nomination_row           = Service_Menu_Post_Type::is_night_unit( $post->ID );
			?>
			<tr id="vkbm-use-nomination-field" <?php echo $hide_nomination_row ? 'hidden' : ''; ?>>
				<th scope="row"><?php echo esc_html( $resource_selection_strings['heading'] ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="vkbm_service_menu_use_nomination" name="vkbm_service_menu[use_nomination]" value="1" aria-describedby="<?php echo esc_attr( $use_nomination_description_id ); ?>" <?php checked( ! $nomination_disabled_for_menu ); ?> />
						<?php echo esc_html( $resource_selection_strings['checkbox'] ); ?>
					</label>
					<p class="description" id="<?php echo esc_attr( $use_nomination_description_id ); ?>">
						<?php echo esc_html( $resource_selection_strings['unchecked'] ); ?><br>
						<?php
						// #392: 旧仕様の説明文「このチェックを外すと下の予約枠の定員・複数人一括予約の
						// 設定が現れる」は、常時表示に変えたことと矛盾するため削除した。指名を使う担当者が
						// 「外さないと設定できない」と誤解して意図せず指名をOFFにしてしまうのを防ぐため、
						// 「予約枠の定員・複数人一括予約はこのチェックの状態に関わらず常に表示される」という
						// 新しい事実に置き換える。
						// #440: 以前はこの下に「このチェックを外すと貸し切り予約系の設定が現れる」という
						// 説明文があった。最少催行人数・料金区分・貸し切り予約・予約者による貸切指定の
						// 4欄はすべて指名の有無に関わらず表示されるようになったため
						// （$show_multi_guest_dependent_fields を参照）、このチェックを外すことで新たに
						// 現れる設定は無くなった。実態に合わせて説明文自体を削除する。
						esc_html_e( 'The time slot capacity and multi-guest booking settings below are shown regardless of this checkbox.', 'vk-booking-manager' );
						?>
					</p>
				</td>
			</tr>
			<?php
		endif;

		// #391: 指名料の無効化はこのメニューが実際に指名を使う場合のみ意味を持つため、
		// メニュー単位の判定（is_nomination_enabled_for_menu）で表示可否を決める。
		// 既存メニューは新メタ未設定＝「指名を使う」が既定のため、この置き換えで挙動は変わらない。
		//
		// #412 B-4: 上のチェックボックスと保存・再読込なしで即時連動させるため、行自体は常に描画し、
		// 初期の表示/非表示は hidden 属性、以降の切り替えは service-menu-editor.js に任せる
		// （複数人一括予約の従属欄と同じ「常に描画してJSでhiddenを出し分ける」方式）。
		// 宿泊のサイトは客室ごとの追加料金を使わない（常に0円）ため、この行自体を出さない。保存済みの値は消さない。
		// 行を出さないなら以降の判定は要らないため、メニュー単位の判定より先に抜ける。
		if ( Industry_Presets::supports( 'lodging' ) ) {
			return;
		}

		$is_nomination_enabled_for_menu = Staff_Editor::is_nomination_enabled_for_menu( $post->ID );
		$nomination_fee_description_id  = 'vkbm-disable-nomination-fee-description';
		?>
		<tr id="vkbm-disable-nomination-fee-field" <?php echo $is_nomination_enabled_for_menu ? '' : 'hidden'; ?>>
			<th scope="row"><?php echo esc_html( vkbm_get_nomination_fee_label() ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="vkbm_service_menu[disable_nomination_fee]" value="1" aria-describedby="<?php echo esc_attr( $nomination_fee_description_id ); ?>" <?php checked( '1', $disable_nomination_fee ); ?> />
					<?php esc_html_e( 'Disable for this service menu', 'vk-booking-manager' ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $nomination_fee_description_id ); ?>">
					<?php
					printf(
						/* translators: %s: resource label (singular) */
						esc_html__( 'This is the fee you enter on each %s\'s edit screen.', 'vk-booking-manager' ),
						esc_html( vkbm_get_resource_label_singular() )
					);
					echo '<br>';
					esc_html_e( 'When checked, it is not added to this service menu\'s price even if the customer nominates one.', 'vk-booking-manager' );
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the providing conditions meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_conditions_meta_box( WP_Post $post ): void {
		$resource_selection_strings = vkbm_get_resource_selection_strings();
		$duration_minutes           = get_post_meta( $post->ID, '_vkbm_duration_minutes', true );
		$buffer_after_minutes       = get_post_meta( $post->ID, '_vkbm_buffer_after_minutes', true );
		$other_conditions           = get_post_meta( $post->ID, self::META_OTHER_CONDITIONS, true );

		// 宿泊の機能を使うサイトでだけ「予約の単位」、チェックイン／チェックアウト時刻、料金の単位を描画する（#570、#900）。
		// 「泊」のときは所要時間・サービス後バッファ・固定開始時刻の行を hidden にする（値は残す）。
		$supports_lodging = Industry_Presets::supports( 'lodging' );
		$is_night_unit    = Service_Menu_Post_Type::is_night_unit( $post->ID );
		$slot_only_hidden = $is_night_unit ? 'hidden' : '';
		// 泊単位で「1室1泊あたり」のプランは料金区分を使わない（#572）。
		$is_per_room_night = Service_Menu_Post_Type::is_night_price_per_room( $post->ID );
		// 1室1泊あたりで、規定人数を超えた分の追加料金を使うプランは、料金区分を超過1名・1泊あたりの追加料金として使う（#934）。
		$is_extra_guest_fee = Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $post->ID );
		if ( $supports_lodging ) {
			$this->render_booking_unit_fields( $post, $is_night_unit );
		} elseif ( ! Pro_Upsell::is_free_edition() && Service_Menu_Post_Type::BOOKING_UNIT_NIGHT === get_post_meta( $post->ID, Service_Menu_Post_Type::META_BOOKING_UNIT, true ) ) {
			$this->render_booking_unit_inactive_notice();
		}
		?>
		<tr id="vkbm-duration-field" class="vkbm-booking-unit-slot-only" <?php echo esc_attr( $slot_only_hidden ); ?>>
			<th scope="row">
				<label for="vkbm_service_menu_duration_minutes"><?php esc_html_e( 'Time required (minutes)', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_duration_minutes" name="vkbm_service_menu[duration_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $duration_minutes ); ?>" />
			</td>
		</tr>
		<tr id="vkbm-buffer-after-field" class="vkbm-booking-unit-slot-only" <?php echo esc_attr( $slot_only_hidden ); ?>>
			<th scope="row">
				<label for="vkbm_service_menu_buffer_after"><?php esc_html_e( 'Post-service buffer (min)', 'vk-booking-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="vkbm_service_menu_buffer_after" name="vkbm_service_menu[buffer_after_minutes]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $buffer_after_minutes ); ?>" />
				<p class="description">
					<?php esc_html_e( 'During the service time plus the buffer time, new reservations will not be accepted.', 'vk-booking-manager' ); ?><br>
					<?php
					// 未記入の場合の説明（基本設定画面の該当欄へのリンク付き）。
					printf(
						/* translators: %s: link to the post-service buffer setting on the provider settings page. */
						esc_html__( 'If it is left blank, the information entered on %s will be reflected.', 'vk-booking-manager' ),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-service-menu-buffer-after-default' ) ),
							esc_html__( 'Post-service buffer on the General Settings page', 'vk-booking-manager' )
						)
					);
					?>
				</p>
			</td>
		</tr>
		<?php $this->render_reservation_day_field( $post ); ?>
		<tr>
			<th scope="row">
				<label for="vkbm_service_menu_other_conditions"><?php echo esc_html( vkbm_get_other_conditions_label() ); ?></label>
			</th>
			<td>
				<textarea id="vkbm_service_menu_other_conditions" name="vkbm_service_menu[other_conditions]" class="large-text" rows="4" aria-describedby="vkbm-service-menu-other-conditions-description"><?php echo esc_textarea( (string) $other_conditions ); ?></textarea>
				<p id="vkbm-service-menu-other-conditions-description" class="description">
					<?php esc_html_e( 'If there is anything customers should know in advance, such as additional fees that may apply under certain conditions, enter it here.', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<?php
		// 最大予約人数フィールド（Pro版のみ表示）
		// Display max capacity field only in Pro edition.
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $is_pro_edition ) :
			// #935: 人数の下限・予約枠の定員の欄の文言は、受付処理（Availability_Service::get_menu_nomination_min_guests()）と
			// 同じ条件（1室1泊あたりは対象外・サイト全体とメニュー単位の両方で指名を使うメニューが対象）で切り替える。
			// render_basic_meta_box() の $hide_nomination_row（泊単位で客室の指定行を隠す判定）とは別にする。
			$is_min_guests_applied = ! $is_per_room_night && Staff_Editor::is_nomination_enabled_for_menu( $post->ID );
			$is_night_plan         = $supports_lodging && $is_night_unit;
			// 予約枠の定員（同一枠で複数人を受け入れる）機能（親スイッチ）が有効かどうかを判定する。
			$is_slot_capacity_enabled = Staff_Editor::is_slot_capacity_enabled();

			if ( ! $is_slot_capacity_enabled ) :
				// 予約枠の定員機能が親スイッチで無効な場合は、このメニューの指名設定に関わらず
				// 定員フィールドを非表示にし案内メッセージを表示する（この案内は「Staff nomination」
				// チェックボックスを切り替えても変わらないため、他の行と違い常時この内容で確定でよい）。
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
				// #514: 料金区分の行はこの構成（予約枠の定員機能OFF）では表示されないため、
				// 「料金区分が入るはずの位置」＝この分岐の末尾に基本料金の行を置く。
				$this->render_base_price_field( $post, false );
				?>
			<?php else : ?>
				<?php
				// 予約枠の定員機能はON。
				//
				// #392: 指名を使うメニューを「1枠1組（貸切）」として扱う仕様変更に伴い、予約枠の定員・
				// 複数人一括予約の2フィールドは「Staff nomination」チェックボックスの状態に関わらず
				// 常に表示する（以前はここに「指名ONなら案内メッセージのみ、指名OFFなら実フィールド」
				// という出し分けがあったが、指名を使うメニューでも定員＝1組の最大人数として使うため撤去した）。
				//
				// #393・#440: 最少催行人数（指名を使うメニューでは「最低申し込み人数」）・料金区分・
				// 貸し切り予約・予約者による貸切指定の4欄はすべて「複数人一括予約ON かつ 予約枠の定員
				// 2以上」の2条件のみで出し分ける（指名の有無を問わない）。以前は貸切系2欄だけ
				// 「指名を使わない」も条件に含めていたが、指名を使うメニューでも貸切設定を編集・
				// 利用できるようにする仕様変更でこの区別を撤廃し、$show_multi_guest_dependent_fields
				// 1つに統一した。
				//
				// #440: 料金区分は元々「指名を使わない」を条件に含めていなかった。予約画面・予約確定処理
				// （Booking_Confirmation_Controller::resolve_menu_price_tiers()）は #392 の時点で
				// 「指名を使わない」条件を外しており、指名を使うメニューでも料金区分を適用している。
				// 編集画面だけ「指名を使わない」で隠していたため、指名を使うメニューでは保存済みの
				// 料金区分を確認・変更できない不整合があった。
				//
				// #412 A-1/A-4/B-4: 実フィールドは保存前後で表示条件が変わると保存事故（値の消失・
				// 初期化）が起きるため、表示の出し分けは常に hidden 属性のみで行う（保存側は
				// 「予約枠の定員機能がONかどうか」だけで保存可否を判定し、このメニューの指名可否では
				// 保存対象を分岐しない）。
				$max_capacity      = get_post_meta( $post->ID, self::META_MAX_CAPACITY, true );
				$min_capacity      = get_post_meta( $post->ID, self::META_MIN_CAPACITY, true );
				$saved_allow_multi = (bool) get_post_meta( $post->ID, self::META_ALLOW_MULTIPLE_GUESTS, true );
				// 1室1泊あたりのプランは許可フラグが未保存・OFF でも ON として扱う（#831）。
				$allow_multi_guests = Service_Menu_Post_Type::is_multi_guest_booking_enabled( $post->ID );
				// 実効の予約枠の定員（未設定は既定1）。最少催行人数・貸切・料金区分は
				// 複数人一括予約の相乗りが前提のため、ここが2以上のときだけ意味を持つ（#320）。
				$max_capacity_value = '' === $max_capacity ? 1 : max( 1, (int) $max_capacity );
				// 最少催行人数（最低申し込み人数）・料金区分・貸し切り予約・予約者による貸切指定の
				// 4欄を初期表示するか。「複数人一括予約ON かつ 予約枠の定員2以上」のときだけ表示し、
				// それ以外は hidden で隠す（JS でも連動させる）。指名の有無はこの表示条件に含めない。
				$show_multi_guest_dependent_fields = $allow_multi_guests && $max_capacity_value >= 2;
				?>
				<tr id="vkbm-max-capacity-field">
					<th scope="row">
						<label for="vkbm_service_menu_max_capacity" id="vkbm-max-capacity-label">
							<?php
							// 1室1泊あたりのプランは「部屋を貸す」感覚で設定できるよう、ラベルと説明文を切り替える（#831）。
							if ( $is_per_room_night ) {
								esc_html_e( 'Maximum guests per room', 'vk-booking-manager' );
							} else {
								esc_html_e( 'Time slot capacity', 'vk-booking-manager' );
							}
							?>
						</label>
					</th>
					<td>
						<input type="number" id="vkbm_service_menu_max_capacity" name="vkbm_service_menu[max_capacity]" class="small-text" min="1" step="1" value="<?php echo esc_attr( $max_capacity ); ?>" />
						<p class="description" id="vkbm-max-capacity-description">
							<?php if ( $is_per_room_night ) : ?>
								<?php esc_html_e( 'Enter the maximum number of guests who can stay in this room.', 'vk-booking-manager' ); ?><br>
								<?php esc_html_e( 'Guests can choose up to this number in the number of guests field on the reservation screen.', 'vk-booking-manager' ); ?><br>
								<?php echo esc_html( $is_extra_guest_fee ? __( 'The price does not change with the number of people up to the number included in the basic price.', 'vk-booking-manager' ) : __( 'The price does not change with the number of people.', 'vk-booking-manager' ) ); ?><br>
							<?php elseif ( $is_min_guests_applied && $is_night_plan ) : ?>
									<?php esc_html_e( 'Maximum number of guests that can be booked together in a single reservation.', 'vk-booking-manager' ); ?><br>
							<?php elseif ( $is_min_guests_applied ) : ?>
								<?php echo esc_html( $resource_selection_strings['max_capacity_nomination_1'] ); ?><br>
								<?php echo esc_html( $resource_selection_strings['max_capacity_nomination_2'] ); ?><br>
							<?php else : ?>
								<?php echo esc_html( $resource_selection_strings['max_capacity_default_1'] ); ?><br>
								<?php echo esc_html( $resource_selection_strings['max_capacity_default_2'] ); ?><br>
							<?php endif; ?>
							<?php esc_html_e( 'Default is 1.', 'vk-booking-manager' ); ?>
						</p>
					</td>
				</tr>
				<tr id="vkbm-min-capacity-field" <?php echo $show_multi_guest_dependent_fields && ! $is_per_room_night ? '' : 'hidden'; ?>>
					<th scope="row">
						<label for="vkbm_service_menu_min_capacity" id="vkbm-min-capacity-label">
							<?php
							// #393: 指名を使うメニューでは「1件の予約＝1組の貸切」を占有できてしまう人数の
							// 下限を意味するため、ラベルを「最低申し込み人数」に切り替える。指名を使わない
							// メニューは従来どおり「最少催行人数」（催行状態表示のしきい値）のまま変更しない。
							if ( $is_min_guests_applied ) {
								esc_html_e( 'Minimum guests to accept a booking', 'vk-booking-manager' );
							} else {
								esc_html_e( 'Minimum participants to confirm', 'vk-booking-manager' );
							}
							?>
						</label>
					</th>
					<td>
						<input type="number" id="vkbm_service_menu_min_capacity" name="vkbm_service_menu[min_capacity]" class="small-text" min="0" step="1" value="<?php echo esc_attr( $min_capacity ); ?>" aria-describedby="vkbm-min-capacity-description" />
						<p class="description" id="vkbm-min-capacity-description">
							<?php if ( $is_min_guests_applied ) : ?>
								<?php if ( $is_night_plan ) : ?>
									<?php esc_html_e( 'Each booking is for one group using one room.', 'vk-booking-manager' ); ?><br>
								<?php else : ?>
									<?php echo esc_html( $resource_selection_strings['min_capacity_nomination_1'] ); ?><br>
								<?php endif; ?>
								<?php esc_html_e( 'If the number of guests is below this value, the reservation cannot be accepted.', 'vk-booking-manager' ); ?><br>
								<?php esc_html_e( 'Enter 0 to remove the minimum and accept bookings from 1 guest.', 'vk-booking-manager' ); ?><br>
								<?php if ( $is_night_plan ) : ?>
									<?php esc_html_e( 'Values above the time slot capacity are reduced to that capacity.', 'vk-booking-manager' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Values above the time slot capacity (the maximum guests per group) are reduced to that capacity.', 'vk-booking-manager' ); ?>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'When bookings sharing the same time slot reach this number, the session is treated as confirmed.', 'vk-booking-manager' ); ?><br>
								<?php esc_html_e( 'Enter 0 to disable the minimum and accept any number of bookings.', 'vk-booking-manager' ); ?><br>
								<?php esc_html_e( 'Values above the time slot capacity are reduced to that capacity.', 'vk-booking-manager' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr id="vkbm-allow-multiple-guests-field" <?php echo $is_per_room_night ? 'hidden' : ''; ?>>
					<th scope="row">
						<?php esc_html_e( 'Booking multiple people at once', 'vk-booking-manager' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" id="vkbm_service_menu_allow_multiple_guests" name="vkbm_service_menu[allow_multiple_guests]" value="1" <?php checked( true, $saved_allow_multi ); ?> />
							<?php esc_html_e( 'Allow the same user to book for multiple people at once', 'vk-booking-manager' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'A single booking can reserve for multiple people by entering the number of people.', 'vk-booking-manager' ); ?><br>
							<?php echo esc_html( $resource_selection_strings['multiple_guests_assignment'] ); ?><br>
							<span id="vkbm-multiple-guests-price-note">
								<?php
								if ( $is_per_room_night ) {
									esc_html_e( 'The price does not change with the number of people.', 'vk-booking-manager' );
								} else {
									esc_html_e( 'The price is multiplied by the number of people.', 'vk-booking-manager' );
								}
								?>
							</span><br>
							<?php
							// 「消えた」誤解の予防：追加設定が現れる条件を一行で案内する（#320）。
							// #440: 画面を開いた時点の指名の有無で文言が固定され、保存せず
							// 「このメニューで指名を使う」を切り替えると実際に出る欄と説明文がずれるため、
							// 指名の有無で文言を出し分けず1本の文言に統一した。「貸し切り予約」系の設定も
							// 指名の有無に関わらず表示されるため（$show_multi_guest_dependent_fields を
							// 参照）、最少催行人数・料金区分・貸し切り予約系設定をまとめて1文で言い切る
							// （「など」は使わず、実際に表示される設定をすべて列挙する）。
							echo esc_html( $resource_selection_strings['multiple_guests_conditions'] );
							?>
						</p>
					</td>
				</tr>
				<?php
				// 1室1泊あたりのプランは部屋ごとに1組だけが泊まるため、枠を貸し切りにする設定は意味を持たず、欄を出さない（#831）。
				$this->render_exclusive_when_booked_field( $post, $show_multi_guest_dependent_fields && ! $is_per_room_night );
				?>
				<?php
				// 泊単位のプランでは予約者が選ぶ貸切の欄を出さない（#572）。
				$this->render_exclusive_user_selectable_field( $post, $show_multi_guest_dependent_fields && ! $is_night_unit );
				// 1室1泊あたりのプランは、追加料金を使うときだけ料金区分の行を出す（#572、#934）。
				$show_price_tiers = $show_multi_guest_dependent_fields && ( ! $is_per_room_night || $is_extra_guest_fee );
				// #514: 基本料金の行を料金区分の行の直前に配置する。
				// 1室1泊あたりは基本料金が常に使われる（区分は超過分の追加料金）ため、基本料金を区分で上書き扱いにしない。
				$this->render_base_price_field( $post, $show_price_tiers && ! $is_per_room_night );
				$this->render_price_tiers_field( $post, $show_price_tiers );
				?>
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
			<?php
			// #514: 無料版には料金区分自体が存在しないため、この分岐の末尾（料金区分が
			// 入るはずの位置に相当）に基本料金の行を置く。
			$this->render_base_price_field( $post, false );
			?>
		<?php endif; ?>
		<?php $this->render_fixed_start_times_field( $post, $slot_only_hidden ); ?>
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
	 * 予約の単位とチェックイン／チェックアウト時刻を保存する（#570）。
	 *
	 * 宿泊の機能を使うサイトで、フォームに「予約の単位」が含まれているときだけ保存する。
	 * それ以外（宿泊以外のサイト・項目が送られない保存）では既存のメタに触れない。
	 *
	 * @param int                  $post_id 投稿ID。
	 * @param array<string, mixed> $data    送信された vkbm_service_menu 配列。
	 */
	private function save_booking_unit_meta( int $post_id, array $data ): void {
		if ( ! Industry_Presets::supports( 'lodging' ) || ! isset( $data['booking_unit'] ) ) {
			return;
		}

		// 「泊」のときだけ保存し、時間枠（既定）はメタを削除する。
		if ( Service_Menu_Post_Type::BOOKING_UNIT_NIGHT === $data['booking_unit'] ) {
			update_post_meta( $post_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		} else {
			delete_post_meta( $post_id, Service_Menu_Post_Type::META_BOOKING_UNIT );
		}

		// 料金の単位（#572）。「1室1泊あたり」のときだけ保存し、それ以外はメタを削除する。
		// 予約の単位に関わらず保存する（時間枠へ戻しても選択を残すため。読み取り側が泊単位のときだけ使う）。
		if ( isset( $data['night_price_unit'] ) ) {
			if ( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $data['night_price_unit'] ) {
				update_post_meta( $post_id, Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT, Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM );
			} else {
				delete_post_meta( $post_id, Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT );
			}
		}

		// 休前日・特定日の基本料金（#737）。予約の単位に関わらず保存する（時間枠へ戻しても入力値を残すため）。
		// 空欄はメタを削除し、その泊は平日の料金（休前日の料金が空欄のとき）または休前日の判定（特定日の料金が空欄のとき）で計算する。
		$night_price_fields = array(
			'night_price_pre_holiday' => Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY,
			'night_price_special'     => Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL,
		);
		foreach ( $night_price_fields as $field_name => $meta_key ) {
			if ( array_key_exists( $field_name, $data ) ) {
				$this->update_meta_value( $post_id, $meta_key, $this->sanitize_numeric_value( $data, $field_name ) );
			}
		}

		// 時刻は単位に関わらず保存する（時間枠へ戻しても入力値を残すため）。不正値は削除して既定へ戻す。
		$time_fields = array(
			'check_in'  => Service_Menu_Post_Type::META_CHECK_IN_TIME,
			'check_out' => Service_Menu_Post_Type::META_CHECK_OUT_TIME,
		);
		foreach ( $time_fields as $name_base => $meta_key ) {
			if ( ! isset( $data[ $name_base . '_hour' ] ) ) {
				continue;
			}
			$time = $this->sanitize_time_selection( $data[ $name_base . '_hour' ], $data[ $name_base . '_minute' ] ?? '' );
			$this->update_meta_value( $post_id, $meta_key, $time );
		}
	}

	/**
	 * 1室1泊あたりで、規定人数を超えた分の追加料金に関する設定を保存する（#934）。
	 *
	 * - 追加料金を使うか（チェック）と、基本料金に含まれる人数を保存する。予約の単位や料金の単位に関わらず保存する
	 *   （時間枠・1名1泊あたりへ戻しても入力を残すため。読み取り側が1室1泊あたりのときだけ使う）。
	 * - 基本料金に含まれる人数は 1 以上にする。予約枠の定員機能が ON のときは、1室の最大人数を超える値を最大人数に丸めて保存し、
	 *   追加料金を使うときは丸めたことを管理画面の上部で知らせる（OFF のときは入力値を保持する）。
	 * - 追加料金を使うのに料金区分が1件も無いときは、追加料金が加算されないことを管理画面の上部で知らせる（保存は止めない）。
	 * - 1室1泊あたりで追加料金を使うときは、料金区分を超過1名・1泊あたりの追加料金として保存する
	 *   （「複数人一括予約を許可」のチェックは1室1泊あたりでは使わないため、そのチェックに関わらず保存する）。
	 * 宿泊以外のサイト・項目が送られない保存（料金の単位の欄が無い画面）では何もしない。
	 *
	 * @param int                              $post_id        投稿ID。
	 * @param array<string, mixed>             $data           送信された vkbm_service_menu 配列。
	 * @param array<int, array<string, mixed>> $price_tiers    サニタイズ済みの料金区分。
	 * @param bool                             $is_pro_edition Pro 版か。
	 */
	private function save_night_extra_guest_meta( int $post_id, array $data, array $price_tiers, bool $is_pro_edition ): void {
		if ( ! Industry_Presets::supports( 'lodging' ) || ! isset( $data['night_price_unit'] ) ) {
			return;
		}

		$is_per_room = Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $data['night_price_unit'];
		$is_enabled  = isset( $data['night_extra_guest_enabled'] ) && '1' === (string) $data['night_extra_guest_enabled'];
		if ( $is_enabled ) {
			update_post_meta( $post_id, Service_Menu_Post_Type::META_NIGHT_EXTRA_GUEST_ENABLED, '1' );
		} else {
			delete_post_meta( $post_id, Service_Menu_Post_Type::META_NIGHT_EXTRA_GUEST_ENABLED );
		}

		// 基本料金に含まれる人数。1 未満・空欄は 1。予約枠の定員機能が ON のときだけ、最大人数を超える値を最大人数に丸める。
		// 宿泊プリセットでは定員機能は ON に固定されるため、OFF の分岐は vkbm_industry_presets フィルターでプリセットを変えた場合だけ通る。
		// OFF のときは最大人数の欄が送られず保存済みの値が古いことがあるため、入力値を保持する（#412 の親スイッチ OFF は保持する方針）。
		$is_capacity_enabled = $is_pro_edition && Staff_Editor::is_slot_capacity_enabled();
		$requested           = max( 1, (int) $this->sanitize_integer_value( $data, 'night_included_guests' ) );
		$max_capacity_raw    = get_post_meta( $post_id, self::META_MAX_CAPACITY, true );
		$max_capacity        = '' === $max_capacity_raw ? 1 : max( 1, (int) $max_capacity_raw );
		$included_guests     = $is_capacity_enabled ? min( $max_capacity, $requested ) : $requested;
		update_post_meta( $post_id, Service_Menu_Post_Type::META_NIGHT_INCLUDED_GUESTS, $included_guests );
		if ( $is_per_room && $is_enabled && $included_guests < $requested ) {
			$this->add_admin_notice(
				sprintf(
					/* translators: %d: maximum number of guests per room (the number of guests included in the basic price was reduced to this value). */
					__( 'The number of guests included in the basic price exceeded the maximum guests per room, so it was reduced to %d.', 'vk-booking-manager' ),
					$max_capacity
				)
			);
		}

		// 追加料金を使う1室1泊あたりは、複数人一括予約のチェックの保存分岐を通らないため、料金区分をここで保存する。
		if ( $is_capacity_enabled && $is_per_room && $is_enabled ) {
			if ( ! empty( $price_tiers ) ) {
				update_post_meta( $post_id, self::META_PRICE_TIERS, wp_slash( $price_tiers ) );
			} else {
				delete_post_meta( $post_id, self::META_PRICE_TIERS );
				// 料金区分が無いと予約画面に区分ごとの人数欄が出ず、追加料金が加算されないため知らせる（保存は止めない）。
				$this->add_admin_notice(
					__( 'No price category is entered, so the extra guest fee is not added to the price. Enter at least one price category to charge the extra guest fee.', 'vk-booking-manager' )
				);
			}
		}
	}

	/**
	 * 管理画面の上部に出すお知らせを、保存後の画面表示のために一時保存へ追記する。
	 *
	 * 予約可能日の保存のお知らせ（render_reservation_day_notice()）と同じ一時保存に、同じ利用者向けの文として追記する。
	 *
	 * @param string $message お知らせの文。
	 */
	private function add_admin_notice( string $message ): void {
		$transient_key = self::get_notice_transient_key();
		$current       = get_transient( $transient_key );
		$messages      = is_string( $current ) && '' !== $current ? $current . ' ' : '';

		set_transient( $transient_key, $messages . $message, MINUTE_IN_SECONDS );
	}

	/**
	 * 泊単位のメニューで使わない設定のメタを削除する（#572）。
	 *
	 * - 予約者が選ぶ貸切（貸切指定・貸切料金・適用外人数）は泊単位では使わない。
	 * - 1室1泊あたりで規定人数を超えた分の追加料金を使わないときは、料金区分を使わない。値が残ると読み取り側の分岐漏れで1室料金に人数が掛かるため削除する。
	 * 宿泊が有効でない、または時間枠のメニューでは何もしない。
	 *
	 * @param int $post_id 投稿ID。
	 */
	private function delete_night_unit_unsupported_meta( int $post_id ): void {
		if ( ! Service_Menu_Post_Type::is_night_unit( $post_id ) ) {
			return;
		}

		delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
		delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
		delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );

		// 1室1泊あたりでも、規定人数を超えた分の追加料金を使うときは区分を超過分の追加料金として使うため残す（#934）。
		if ( Service_Menu_Post_Type::is_night_price_per_room( $post_id ) && ! Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $post_id ) ) {
			delete_post_meta( $post_id, self::META_PRICE_TIERS );
		}
	}

	/**
	 * 時・分のプルダウン値を HH:MM に組み立てる。不正値は空文字。
	 *
	 * @param mixed $hour   時の送信値。
	 * @param mixed $minute 分の送信値。
	 * @return string HH:MM、不正なら空文字。
	 */
	private function sanitize_time_selection( $hour, $minute ): string {
		if ( ! is_scalar( $hour ) || ! is_scalar( $minute ) ) {
			return '';
		}

		$hour   = sanitize_text_field( (string) $hour );
		$minute = sanitize_text_field( (string) $minute );
		if ( 1 !== preg_match( '/^\d{2}$/', $hour ) || 1 !== preg_match( '/^\d{2}$/', $minute ) ) {
			return '';
		}

		return Service_Menu_Post_Type::sanitize_time_value( $hour . ':' . $minute );
	}

	/**
	 * 「予約の単位」ラジオ、チェックイン／チェックアウト時刻、料金の単位の行を出力する（#570、#572、#900）。
	 *
	 * 宿泊の機能を使うサイト（Industry_Presets::supports( 'lodging' )）でだけ呼ぶ。
	 * 時刻の行は「時間枠」のときも hidden にするだけで DOM に残し、送信値を保持する
	 * （時間枠へ戻しても入力した時刻が消えないようにするため）。
	 *
	 * @param WP_Post $post          Current post object.
	 * @param bool    $is_night_unit 予約の単位が「泊」か。
	 */
	private function render_booking_unit_fields( WP_Post $post, bool $is_night_unit ): void {
		$night_only_hidden = $is_night_unit ? '' : 'hidden';
		$description_id    = 'vkbm-booking-unit-description';
		// 時間枠へ戻したメニューでも、料金の単位ラジオには保存済みの選択を表示する。
		$night_price_unit = Service_Menu_Post_Type::get_stored_night_price_unit( $post->ID );
		?>
		<tr id="vkbm-booking-unit-field">
			<th scope="row"><?php esc_html_e( 'Booking unit', 'vk-booking-manager' ); ?></th>
			<td>
				<fieldset aria-describedby="<?php echo esc_attr( $description_id ); ?>">
					<legend class="screen-reader-text"><?php esc_html_e( 'Booking unit', 'vk-booking-manager' ); ?></legend>
					<label>
						<input type="radio" name="vkbm_service_menu[booking_unit]" value="slot" <?php checked( false, $is_night_unit ); ?> />
						<?php esc_html_e( 'Time slot', 'vk-booking-manager' ); ?>
					</label>
					&nbsp;
					<label>
						<input type="radio" name="vkbm_service_menu[booking_unit]" value="<?php echo esc_attr( Service_Menu_Post_Type::BOOKING_UNIT_NIGHT ); ?>" <?php checked( true, $is_night_unit ); ?> />
						<?php esc_html_e( 'Nights', 'vk-booking-manager' ); ?>
					</label>
				</fieldset>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
					<?php esc_html_e( 'If you choose "Nights", bookings are accepted by check-in date and check-out date.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'If you choose "Nights" and save, the private booking by customer settings are deleted.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'If you choose "Nights", the time required, post-service buffer, and fixed start times fields are not used, so they are hidden.', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<tr id="vkbm-check-in-time-field" class="vkbm-booking-unit-night-only" <?php echo esc_attr( $night_only_hidden ); ?>>
			<th scope="row"><?php esc_html_e( 'Check-in time', 'vk-booking-manager' ); ?></th>
			<td>
				<?php $this->render_time_selects( 'check_in', Service_Menu_Post_Type::get_check_in_time( $post->ID ), __( 'Check-in time', 'vk-booking-manager' ) ); ?>
			</td>
		</tr>
		<tr id="vkbm-check-out-time-field" class="vkbm-booking-unit-night-only" <?php echo esc_attr( $night_only_hidden ); ?>>
			<th scope="row"><?php esc_html_e( 'Check-out time (departure day)', 'vk-booking-manager' ); ?></th>
			<td>
				<?php $this->render_time_selects( 'check_out', Service_Menu_Post_Type::get_check_out_time( $post->ID ), __( 'Check-out time', 'vk-booking-manager' ) ); ?>
				<p class="description">
					<?php esc_html_e( 'This is the time on the day after the last night of the stay.', 'vk-booking-manager' ); ?>
				</p>
			</td>
		</tr>
		<?php
		// 表示が切り替わる時間枠専用の欄より前に、泊の料金設定をまとめて表示する（#900）。
		$this->render_night_price_unit_field( $post, $night_price_unit, $is_night_unit );
	}

	/**
	 * 基本料金の入力欄の後ろに出す、泊単位の料金の単位表記を返す（#572）。
	 *
	 * @param string $night_price_unit 料金の単位（NIGHT_PRICE_UNIT_PER_ROOM / NIGHT_PRICE_UNIT_PER_GUEST）。
	 * @return string 単位表記。
	 */
	public static function get_night_price_unit_suffix( string $night_price_unit ): string {
		if ( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $night_price_unit ) {
			return __( '/ per room, per night', 'vk-booking-manager' );
		}

		return __( '/ per guest, per night', 'vk-booking-manager' );
	}

	/**
	 * 「料金の単位」ラジオの行を出力する（#572）。
	 *
	 * 宿泊が有効なサイトで render_booking_unit_fields() から呼び、チェックアウト時刻の行の直後に置く。
	 * 時間枠のメニューでは hidden にするだけで DOM に残す（泊へ切り替えたときに選択を保てるようにするため）。
	 *
	 * 「1室1泊あたり」を選んだときだけ、規定人数を超えた分の追加料金のチェックと、基本料金に含まれる人数の欄を出す（#934）。
	 * 欄は hidden にするだけで DOM に残し、1名1泊あたりへ切り替えても入力を保持する。
	 *
	 * @param WP_Post $post             Current post object.
	 * @param string  $night_price_unit 現在の料金の単位。
	 * @param bool    $is_night_unit    予約の単位が「泊」か。
	 */
	private function render_night_price_unit_field( WP_Post $post, string $night_price_unit, bool $is_night_unit ): void {
		$is_per_room    = Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM === $night_price_unit;
		$description_id = 'vkbm-night-price-unit-description';
		// 時間枠・1名1泊あたりへ戻したメニューでも、保存済みの選択と人数を表示する（再保存で消さないため）。
		$is_extra_guest_checked = '1' === (string) get_post_meta( $post->ID, Service_Menu_Post_Type::META_NIGHT_EXTRA_GUEST_ENABLED, true );
		$included_guests        = Service_Menu_Post_Type::get_night_included_guests( $post->ID );
		?>
		<tr id="vkbm-night-price-unit-field" class="vkbm-booking-unit-night-only" <?php echo $is_night_unit ? '' : 'hidden'; ?>>
			<th scope="row"><?php esc_html_e( 'Price unit', 'vk-booking-manager' ); ?></th>
			<td>
				<fieldset aria-describedby="<?php echo esc_attr( $description_id ); ?>">
					<legend class="screen-reader-text"><?php esc_html_e( 'Price unit', 'vk-booking-manager' ); ?></legend>
					<label>
						<input type="radio" name="vkbm_service_menu[night_price_unit]" value="<?php echo esc_attr( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST ); ?>" <?php checked( false, $is_per_room ); ?> />
						<?php esc_html_e( 'Per guest, per night', 'vk-booking-manager' ); ?>
					</label>
					&nbsp;
					<label>
						<input type="radio" name="vkbm_service_menu[night_price_unit]" value="<?php echo esc_attr( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM ); ?>" <?php checked( true, $is_per_room ); ?> />
						<?php esc_html_e( 'Per room, per night', 'vk-booking-manager' ); ?>
					</label>
				</fieldset>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
					<?php esc_html_e( 'If you choose "Per room, per night", enter the price of one room per night as the basic price. It is not multiplied by the number of guests.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'Price categories can be used with "Per room, per night" only when the additional fee for exceeding the specified number is enabled.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'If it is not enabled, price categories you have already set are deleted when you save.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'When you switch between "Per room, per night" with the additional fee and "Per guest, per night", review the amounts in the price categories.', 'vk-booking-manager' ); ?>
				</p>
				<div id="vkbm-night-extra-guest-fields" class="vkbm-night-extra-guest" <?php echo $is_per_room ? '' : 'hidden'; ?>>
					<label>
						<input type="checkbox" id="vkbm_service_menu_night_extra_guest_enabled" name="vkbm_service_menu[night_extra_guest_enabled]" value="1" <?php checked( true, $is_extra_guest_checked ); ?> />
						<?php esc_html_e( 'An additional fee applies, depending on the number of guests, when the specified number is exceeded', 'vk-booking-manager' ); ?>
					</label>
					<div id="vkbm-night-included-guests-field" class="vkbm-night-included-guests" <?php echo $is_extra_guest_checked ? '' : 'hidden'; ?>>
						<label for="vkbm_service_menu_night_included_guests"><?php esc_html_e( 'Guests included in the basic price', 'vk-booking-manager' ); ?></label>
						<span class="vkbm-night-included-guests__input text-nowrap">
							<input type="number" id="vkbm_service_menu_night_included_guests" name="vkbm_service_menu[night_included_guests]" class="small-text" min="1" step="1" value="<?php echo esc_attr( (string) $included_guests ); ?>" aria-describedby="vkbm-night-included-guests-description" />
							<?php esc_html_e( 'guests or fewer', 'vk-booking-manager' ); ?>
						</span>
						<p class="description" id="vkbm-night-included-guests-description">
							<?php esc_html_e( 'Enter the specified number of guests (the number included in the basic price) as 1 or more.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'Set the basic price in the "Basic price" field below.', 'vk-booking-manager' ); ?><br>
							<?php esc_html_e( 'Values above the maximum guests per room are reduced to that maximum.', 'vk-booking-manager' ); ?>
						</p>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * 「泊」で保存されたメニューを宿泊以外のサイトで開いたときの案内を出力する（#570）。
	 *
	 * 欄が消えるだけで、泊のメニューが黙って時間枠として扱われるのを避けるための案内。
	 */
	private function render_booking_unit_inactive_notice(): void {
		?>
		<tr id="vkbm-booking-unit-inactive-notice">
			<th scope="row"><?php esc_html_e( 'Booking unit', 'vk-booking-manager' ); ?></th>
			<td>
				<p class="description">
					<?php esc_html_e( 'This menu is saved with the "Nights" booking unit. Because the industry preset is not "Lodging", it is currently handled as a time-slot menu.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'To switch back to "Lodging", change the industry preset to "Lodging" at the link below.', 'vk-booking-manager' ); ?><br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=store#vkbm-industry-preset' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Industry preset on the General Settings page', 'vk-booking-manager' ); ?></a>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * 時と分（10分刻み）のプルダウンを出力する。固定開始時刻と同じ入力の形。
	 *
	 * @param string $name_base  フォーム名のベース（{$name_base}_hour / {$name_base}_minute）。
	 * @param string $time       現在値（HH:MM）。
	 * @param string $aria_label 時・分のプルダウンの aria-label に使う名前。
	 */
	private function render_time_selects( string $name_base, string $time, string $aria_label ): void {
		$hour    = substr( $time, 0, 2 );
		$minute  = substr( $time, 3, 2 );
		$minutes = array( '00', '10', '20', '30', '40', '50' );
		// REST などで10分刻み以外の値が保存されていても、値が変わらないよう選択肢に加える。
		if ( ! in_array( $minute, $minutes, true ) ) {
			$minutes[] = $minute;
			sort( $minutes );
		}
		/* translators: %s: field name such as "Check-in time". */
		$hour_label = sprintf( __( '%s (hour)', 'vk-booking-manager' ), $aria_label );
		/* translators: %s: field name such as "Check-in time". */
		$minute_label = sprintf( __( '%s (minute)', 'vk-booking-manager' ), $aria_label );
		?>
		<select name="vkbm_service_menu[<?php echo esc_attr( $name_base ); ?>_hour]" aria-label="<?php echo esc_attr( $hour_label ); ?>">
			<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
				<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>" <?php selected( sprintf( '%02d', $h ), $hour ); ?>><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
			<?php endfor; ?>
		</select>
		<span>:</span>
		<select name="vkbm_service_menu[<?php echo esc_attr( $name_base ); ?>_minute]" aria-label="<?php echo esc_attr( $minute_label ); ?>">
			<?php foreach ( $minutes as $min ) : ?>
				<option value="<?php echo esc_attr( $min ); ?>" <?php selected( $min, $minute ); ?>><?php echo esc_html( $min ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the fixed start times field.
	 *
	 * @param WP_Post $post        Current post object.
	 * @param string  $hidden_attr 行に付ける hidden 属性（非表示にしないときは空文字列）。
	 */
	private function render_fixed_start_times_field( WP_Post $post, string $hidden_attr = '' ): void {
		$fixed_start_times = get_post_meta( $post->ID, self::META_FIXED_START_TIMES, true );
		$fixed_start_times = is_array( $fixed_start_times ) ? $fixed_start_times : array();
		?>
		<tr id="vkbm-fixed-start-times-field" class="vkbm-booking-unit-slot-only" <?php echo esc_attr( $hidden_attr ); ?>>
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
	 * 指名を使うメニューでは、この排他は担当スタッフ単位（その予約の担当者の時間帯のみ）で効く。
	 * 複数人一括予約の相乗り（複数人一括予約ON かつ 予約枠の定員2以上）のときだけ意味を持つ設定のため、
	 * 料金区分欄と同じく条件未達時は hidden で隠し、JS（service-menu-editor.js）で
	 * 複数人一括予約チェック・予約枠の定員の変更と表示を連動させる（#320）。
	 * これにより条件未達のまま入力 → 保存で削除され入力が消える事故を防ぐ。
	 *
	 * @param WP_Post $post       Current post object.
	 * @param bool    $show_field 初期表示するか（複数人一括予約ON かつ 予約枠の定員2以上）。
	 */
	private function render_exclusive_when_booked_field( WP_Post $post, bool $show_field ): void {
		$exclusive_when_booked      = (bool) get_post_meta( $post->ID, self::META_EXCLUSIVE_WHEN_BOOKED, true );
		$resource_selection_strings = vkbm_get_resource_selection_strings();
		$is_night_unit              = Service_Menu_Post_Type::is_night_unit( $post->ID );
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
					<?php
					// 編集画面は保存せずに「このメニューで指名を使う」を切り替えられるため、
					// この文言も指名の有無で出し分けず1本の文言に統一し、両方の動きを1文で説明する。
					echo '<span id="vkbm-exclusive-when-booked-resource-description">' . esc_html( $is_night_unit ? $resource_selection_strings['exclusive_when_booked_night'] : $resource_selection_strings['exclusive_when_booked'] ) . '</span>';
					?>
					<br>
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
	 * チェックON時のみ第二段（貸し切り料金・適用しない申込人数）を表示する。指名を使うメニューでは、
	 * 「最初の予約者のみ」の判定・排他とも担当スタッフ単位（その予約の担当者の時間帯のみ）で効く。
	 * 複数人一括予約の相乗り（複数人一括予約ON かつ 予約枠の定員2以上）のときだけ意味を持つ設定のため、
	 * 貸し切り予約・料金区分欄と同じく条件未達時は hidden で隠し、
	 * JS（service-menu-editor.js）で複数人一括予約チェック・予約枠の定員の変更と表示を連動させる（#320）。
	 *
	 * @param WP_Post $post       Current post object.
	 * @param bool    $show_field 初期表示するか（複数人一括予約ON かつ 予約枠の定員2以上）。
	 */
	private function render_exclusive_user_selectable_field( WP_Post $post, bool $show_field ): void {
		$user_selectable            = (bool) get_post_meta( $post->ID, self::META_EXCLUSIVE_USER_SELECTABLE, true );
		$resource_selection_strings = vkbm_get_resource_selection_strings();
		$is_night_unit              = Service_Menu_Post_Type::is_night_unit( $post->ID );
		$fee_per_person             = get_post_meta( $post->ID, self::META_EXCLUSIVE_FEE_PER_PERSON, true );
		$fee_exempt                 = get_post_meta( $post->ID, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS, true );
		// 貸し切り料金欄に表示する通貨記号（税込ラベルは付けない＝1人あたり単価のため）。
		$currency_symbol = VKBM_Helper::get_currency_symbol();
		// 説明文と input を aria-describedby で紐付けるための ID。
		$description_id = 'vkbm-exclusive-user-selectable-description';
		?>
		<tr id="vkbm-exclusive-user-selectable-field" <?php echo $show_field ? '' : 'hidden'; ?>>
			<th scope="row"><?php esc_html_e( 'Private booking by customer', 'vk-booking-manager' ); ?></th>
			<td>
				<label class="vkbm-exclusive-user-selectable-label">
					<input type="checkbox" id="vkbm_service_menu_exclusive_user_selectable" name="vkbm_service_menu[exclusive_user_selectable]" value="1" aria-describedby="<?php echo esc_attr( $description_id ); ?>" <?php checked( true, $user_selectable ); ?> />
					<?php esc_html_e( 'Accept private booking requests from customers', 'vk-booking-manager' ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
					<?php esc_html_e( 'Only the first person to book the same date and time can choose this.', 'vk-booking-manager' ); ?><br>
					<?php
					// 編集画面は保存せずに「このメニューで指名を使う」を切り替えられるため、
					// この文言も指名の有無で出し分けず1本の文言に統一し、両方の動きを1文で説明する。
					echo '<span id="vkbm-exclusive-user-selectable-resource-description">' . esc_html( $is_night_unit ? $resource_selection_strings['exclusive_user_night'] : $resource_selection_strings['exclusive_user_selectable'] ) . '</span>';
					?>
					<br>
					<?php
					// #440: 「最低人数」が画面の正式ラベル（指名を使わないメニューは「最少催行人数」、
					// 指名を使うメニューは「最低申し込み人数」）のどちらとも異なる表記だったため、
					// 上の「複数人一括予約を許可する」の説明文と同じ「the minimum participants
					// （指名を使うメニューでは "minimum guests to accept a booking"）」という
					// 言い回しに揃えた。ラベル違いをこの1文で説明できるため、重複していた
					// 「指名を使うメニューでは…最低申し込み人数です」という別文は削除した。
					echo esc_html( $resource_selection_strings['exclusive_minimum_condition'] );
					?>
					<br>
					<?php esc_html_e( 'This setting cannot be used together with "Private booking" for this menu.', 'vk-booking-manager' ); ?><br>
					<?php esc_html_e( 'While "Private booking" is on, this setting is ignored.', 'vk-booking-manager' ); ?>
				</p>
				<div id="vkbm-exclusive-fee-fields" class="vkbm-exclusive-fee-fields" <?php echo $user_selectable ? '' : 'hidden'; ?>>
					<p>
						<label for="vkbm_service_menu_exclusive_fee_per_person" class="vkbm-exclusive-fee-label">
							<span class="text-nowrap"><?php esc_html_e( 'Private booking fee', 'vk-booking-manager' ); ?></span>
							<span class="text-nowrap"><?php echo esc_html( $currency_symbol ); ?><input type="number" id="vkbm_service_menu_exclusive_fee_per_person" name="vkbm_service_menu[exclusive_fee_per_person]" class="small-text vkbm-price-input" min="0" step="1" value="<?php echo esc_attr( (string) $fee_per_person ); ?>" /> 
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
						<?php esc_html_e( 'The private booking fee is not added when the number of guests is this value or more.', 'vk-booking-manager' ); ?><br>
						<?php
						/* translators: the numbers are an example threshold. Keep the second number one less than the first. */
						esc_html_e( 'Example: if you enter 5, the fee is added for up to 4 guests and not added for 5 or more.', 'vk-booking-manager' );
						?>
						<br>
						<?php esc_html_e( 'If left blank or set to 0, the private booking fee is always added regardless of the number of guests.', 'vk-booking-manager' ); ?>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * 「基本料金（税込）」フィールドを出力する（#514）。
	 *
	 * 料金区分（price tiers）が入る位置の直前に置く必要があるため、render_basic_meta_box() では
	 * 描画せず render_conditions_meta_box() から呼び出す。料金区分の行が表示されない構成
	 * （無料版、または予約枠の定員機能OFF）でも、この行自体は必ず描画し、料金区分が入るはずの
	 * 位置（その構成の末尾側）に置く（$price_tiers_visible に false を渡す）。
	 *
	 * 料金区分の行が表示中（hidden でない）かつ、区分名（trim後）が空でない行が1件以上
	 * 登録されているときは、基本料金は使われないため readonly にしてグレーアウト表示し、
	 * 理由を説明文で示す（aria-describedby で関連付け）。disabled ではなく readonly にする
	 * のは、disabled にすると入力値が送信されず save_post() で基本料金メタが削除されて
	 * しまうため（readonly なら値は保持されたまま送信される）。以降の切り替え（料金区分の
	 * 追加・削除・区分名入力、複数人一括予約チェック、予約枠の定員の変更）は
	 * service-menu-editor.js の syncBasePriceOverride() が担う。
	 *
	 * @param WP_Post $post                 Current post object.
	 * @param bool    $price_tiers_visible  料金区分の行が初期表示される構成かどうか
	 *                                      （render_price_tiers_field() に渡す $show_field と同じ値）。
	 */
	private function render_base_price_field( WP_Post $post, bool $price_tiers_visible ): void {
		$base_price = get_post_meta( $post->ID, '_vkbm_base_price', true );
		$tax_label  = VKBM_Helper::get_tax_included_label();

		// 有効な区分（区分名が空でない行）が1件以上あるかどうかを Price_Tiers::sanitize_tiers() と
		// 同じ基準で判定する。normalize_tiers() は空ラベル行を既に除外して返すため、
		// 1件でも残っていれば有効な区分が登録されていることになる。
		// 料金区分の行自体が表示されない構成では、基本料金は常に使われるため判定しない。
		$has_active_tier = $price_tiers_visible
			&& array() !== Price_Tiers::normalize_tiers( get_post_meta( $post->ID, self::META_PRICE_TIERS, true ) );

		$description_id = 'vkbm-base-price-overridden-description';

		// 宿泊が有効なサイトでは、泊単位のプランに基本料金の単位表記を出す（#572）。
		$supports_lodging = Industry_Presets::supports( 'lodging' );
		$is_night_unit    = Service_Menu_Post_Type::is_night_unit( $post->ID );
		// 時間枠へ戻したメニューでも、基本料金の単位表記には保存済みの選択を使う。
		$night_price_unit = Service_Menu_Post_Type::get_stored_night_price_unit( $post->ID );
		$unit_suffix_id   = 'vkbm-base-price-unit';

		// 基本料金の入力欄に関連付ける説明の ID（泊単位の単位表記、料金区分で上書き中の説明）。
		$describedby_ids = array();
		if ( $is_night_unit ) {
			$describedby_ids[] = $unit_suffix_id;
		}
		if ( $has_active_tier ) {
			$describedby_ids[] = $description_id;
		}

		// 泊のプランの休前日・特定日の基本料金（#737）。空欄の欄には、平日の金額を灰色のプレースホルダーで示す。
		$weekday_placeholder     = is_numeric( $base_price ) ? (string) (int) $base_price : '';
		$night_base_extra_fields = array(
			array(
				'name'       => 'night_price_pre_holiday',
				'caption'    => __( 'Pre-holiday', 'vk-booking-manager' ),
				'aria_label' => __( 'Basic price (pre-holiday)', 'vk-booking-manager' ),
				'value'      => (string) get_post_meta( $post->ID, Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY, true ),
			),
			array(
				'name'       => 'night_price_special',
				'caption'    => __( 'Special date', 'vk-booking-manager' ),
				'aria_label' => __( 'Basic price (special date)', 'vk-booking-manager' ),
				'value'      => (string) get_post_meta( $post->ID, Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL, true ),
			),
		);
		?>
		<tr id="vkbm-base-price-field">
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
				<?php // 泊のプランは「平日・休前日・特定日」の 3 つを 1 つのまとまりにして並べ、狭い画面ではまとまりごと折り返す（#737）。 ?>
				<div class="vkbm-night-rate-fields">
					<span class="vkbm-night-rate-field">
						<?php if ( $supports_lodging ) : ?>
							<span class="vkbm-night-rate-caption vkbm-booking-unit-night-only" aria-hidden="true" <?php echo $is_night_unit ? '' : 'hidden'; ?>><?php esc_html_e( 'Regular', 'vk-booking-manager' ); ?></span>
						<?php endif; ?>
						<input
							type="number"
							id="vkbm_service_menu_base_price"
							name="vkbm_service_menu[base_price]"
							class="small-text vkbm-price-input<?php echo $has_active_tier ? ' vkbm-base-price-overridden' : ''; ?>"
							min="0"
							step="1"
							value="<?php echo esc_attr( $base_price ); ?>"
							<?php
							// #514 レビュー対応（安藤・LOW）: aria-describedby はグレーアウト中（＝説明文が
							// 実際に表示されている）ときだけ出力する。常時出力すると、非表示の説明文を
							// 支援技術に関連付けてしまう。JS 側（syncBasePriceOverride()）も同じ考え方で
							// 状態変化に合わせて属性ごと付け外しする。
							if ( array() !== $describedby_ids ) {
								echo 'aria-describedby="' . esc_attr( implode( ' ', $describedby_ids ) ) . '"';
							}
							if ( $has_active_tier ) {
								echo ' readonly';
							}
							?>
						/>
					</span>
					<?php if ( $supports_lodging ) : ?>
						<?php foreach ( $night_base_extra_fields as $extra_index => $extra_field ) : ?>
							<span class="vkbm-night-rate-field vkbm-booking-unit-night-only" <?php echo $is_night_unit ? '' : 'hidden'; ?>>
								<span class="vkbm-night-rate-caption" aria-hidden="true"><?php echo esc_html( $extra_field['caption'] ); ?></span>
								<?php // 入力欄と単位は 1 つのまとまりにして、狭い幅でも単位だけが次の行に落ちないようにする。 ?>
								<span class="vkbm-night-rate-input-row">
									<input
										type="number"
										name="vkbm_service_menu[<?php echo esc_attr( $extra_field['name'] ); ?>]"
										class="small-text vkbm-price-input vkbm-night-base-price-extra<?php echo $has_active_tier ? ' vkbm-base-price-overridden' : ''; ?>"
										min="0"
										step="1"
										value="<?php echo esc_attr( $extra_field['value'] ); ?>"
										placeholder="<?php echo esc_attr( $weekday_placeholder ); ?>"
										aria-label="<?php echo esc_attr( $extra_field['aria_label'] ); ?>"
										<?php echo $has_active_tier ? 'readonly' : ''; ?>
									/>
									<?php if ( array_key_last( $night_base_extra_fields ) === $extra_index ) : ?>
										<span id="<?php echo esc_attr( $unit_suffix_id ); ?>" class="vkbm-night-rate-suffix text-nowrap"><?php echo esc_html( self::get_night_price_unit_suffix( $night_price_unit ) ); ?></span>
									<?php endif; ?>
								</span>
							</span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php if ( $supports_lodging ) : ?>
					<p class="description vkbm-booking-unit-night-only" <?php echo $is_night_unit ? '' : 'hidden'; ?>>
						<?php
						printf(
							/* translators: %s: link to the pre-holiday and special date settings on the provider settings page. */
							esc_html__( 'If the pre-holiday price is empty, the regular price is used. If the special date price is empty, the night is priced as a pre-holiday or regular night. Set the days of the week and dates in %s.', 'vk-booking-manager' ),
							sprintf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								esc_url( admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-lodging-rate-row' ) ),
								esc_html__( 'Pre-holidays and special dates on the General Settings page', 'vk-booking-manager' )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description" id="<?php echo esc_attr( $description_id ); ?>" <?php echo $has_active_tier ? '' : 'hidden'; ?>>
					<?php
					// #514 レビュー対応（植草・中）: 料金区分欄側の説明文（render_price_tiers_field()）と
					// 同一文言が並んで表示されると重複に見えるため、基本料金側は専用の短い文言にする。
					esc_html_e( 'Price categories are set, so the basic price is not used.', 'vk-booking-manager' );
					?>
				</p>
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
		$price_aria       = __( 'Price (regular)', 'vk-booking-manager' );
		$label_head_label = __( 'Category name', 'vk-booking-manager' );
		$price_head_label = __( 'Price', 'vk-booking-manager' );
		// 泊単位のときは列見出しに単位を付ける（#572）。切り替えは JS が行う。
		$is_night_unit = Service_Menu_Post_Type::is_night_unit( $post->ID );
		// 1室1泊あたりで追加料金を使うときは、区分の料金を超過1名・1泊あたりの追加料金として扱う（#934）。
		$is_extra_guest_fee = Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $post->ID );
		if ( $is_extra_guest_fee ) {
			$price_head_label = __( 'Additional fee per extra guest, per night', 'vk-booking-manager' );
		} elseif ( $is_night_unit ) {
			$price_head_label = __( 'Price (per guest, per night)', 'vk-booking-manager' );
		}
		// 宿泊のサイトでは、区分ごとの料金を「平日・休前日・特定日」の 3 つにする（#737）。
		$supports_lodging = Industry_Presets::supports( 'lodging' );
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
							<span class="vkbm-night-rate-fields">
								<span class="vkbm-night-rate-field">
									<?php if ( $supports_lodging ) : ?>
										<span class="vkbm-night-rate-caption vkbm-booking-unit-night-only" aria-hidden="true" <?php echo $is_night_unit ? '' : 'hidden'; ?>><?php esc_html_e( 'Regular', 'vk-booking-manager' ); ?></span>
									<?php endif; ?>
									<span class="vkbm-night-rate-input-row">
										<input
											type="number"
											name="vkbm_service_menu[price_tiers][price][]"
											class="small-text vkbm-price-input vkbm-price-tier-price"
											min="0"
											step="1"
											value="<?php echo esc_attr( (string) $tier['price'] ); ?>"
											aria-label="<?php echo esc_attr( $price_aria ); ?>"
										/>
										<span class="vkbm-price-tier-unit"><?php echo esc_html( '' !== $tax_label ? $price_unit . $tax_label : $price_unit ); ?></span>
									</span>
								</span>
								<?php foreach ( self::get_price_tier_extra_fields() as $extra_key => $extra_field ) : ?>
									<?php if ( $supports_lodging ) : ?>
										<span class="vkbm-night-rate-field vkbm-booking-unit-night-only" <?php echo $is_night_unit ? '' : 'hidden'; ?>>
											<span class="vkbm-night-rate-caption" aria-hidden="true"><?php echo esc_html( $extra_field['caption'] ); ?></span>
											<span class="vkbm-night-rate-input-row">
												<input
													type="number"
													name="vkbm_service_menu[price_tiers][<?php echo esc_attr( $extra_key ); ?>][]"
													class="small-text vkbm-price-input vkbm-price-tier-extra"
													min="0"
													step="1"
													value="<?php echo esc_attr( isset( $tier[ $extra_key ] ) ? (string) $tier[ $extra_key ] : '' ); ?>"
													placeholder="<?php echo esc_attr( (string) $tier['price'] ); ?>"
													aria-label="<?php echo esc_attr( $extra_field['aria_label'] ); ?>"
												/>
												<span class="vkbm-price-tier-unit"><?php echo esc_html( '' !== $tax_label ? $price_unit . $tax_label : $price_unit ); ?></span>
											</span>
										</span>
									<?php else : ?>
										<?php // 宿泊以外の業種に切り替えても、入力済みの休前日・特定日の料金を消さないよう、値を送り直す（#570 の方針）。 ?>
										<input type="hidden" name="vkbm_service_menu[price_tiers][<?php echo esc_attr( $extra_key ); ?>][]" value="<?php echo esc_attr( isset( $tier[ $extra_key ] ) ? (string) $tier[ $extra_key ] : '' ); ?>" />
									<?php endif; ?>
								<?php endforeach; ?>
							</span>
							<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-price-tier-remove"><?php esc_html_e( 'Delete', 'vk-booking-manager' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" id="vkbm-price-tier-add" class="button"><?php esc_html_e( '+ Add price category', 'vk-booking-manager' ); ?></button>
				<p class="description"><?php esc_html_e( 'Define categories such as "Adult" and "Child", each with its own price.', 'vk-booking-manager' ); ?></p>
				<?php // 追加料金を使う1室1泊あたりでは基本料金は区分で上書きされないため、既定の 2 文ではなく追加料金用の 2 文を出す（#934）。 ?>
				<p class="description vkbm-price-tiers-description-default" <?php echo $is_extra_guest_fee ? 'hidden' : ''; ?>><?php esc_html_e( 'When one or more categories are defined, the basic price is ignored and the price is calculated from the categories.', 'vk-booking-manager' ); ?></p>
				<p class="description vkbm-price-tiers-description-default" <?php echo $is_extra_guest_fee ? 'hidden' : ''; ?>><?php esc_html_e( 'If no category is defined, the basic price multiplied by the number of guests is used.', 'vk-booking-manager' ); ?></p>
				<p class="description vkbm-price-tiers-description-extra-guest" <?php echo $is_extra_guest_fee ? '' : 'hidden'; ?>><?php esc_html_e( 'Enter the fee added for each guest over the number included in the basic price, per night.', 'vk-booking-manager' ); ?></p>
				<p class="description vkbm-price-tiers-description-extra-guest" <?php echo $is_extra_guest_fee ? '' : 'hidden'; ?>><?php esc_html_e( 'Guests in the highest-priced categories are included in the basic price first, and the remaining guests are charged.', 'vk-booking-manager' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * 料金区分の行が持つ、休前日・特定日の料金の欄（#737）。
	 *
	 * キーは料金区分の行（Price_Tiers）に保存するキーと、フォームの name に使うキーを兼ねる。
	 *
	 * @return array<string, array{caption: string, aria_label: string}> キー => 欄の見出しと読み上げ用の名前。
	 */
	private static function get_price_tier_extra_fields(): array {
		return array(
			'pre_holiday_price' => array(
				'caption'    => __( 'Pre-holiday', 'vk-booking-manager' ),
				'aria_label' => __( 'Price (pre-holiday)', 'vk-booking-manager' ),
			),
			'special_price'     => array(
				'caption'    => __( 'Special date', 'vk-booking-manager' ),
				'aria_label' => __( 'Price (special date)', 'vk-booking-manager' ),
			),
		);
	}

	/**
	 * Render the staff linkage meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_staff_meta_box( WP_Post $post ): void {
		// 個別選択（保存値そのもの）。「すべて」選択中も保持しているため、そのままチェック状態に使う。
		$selected_staff = Service_Menu_Post_Type::get_selected_staff_ids( $post->ID );
		// 「すべてのリソースが担当できる」フラグ（#485）。既存・新規メニューとも未設定＝「選ぶ」が既定。
		$is_all_staff = Service_Menu_Post_Type::is_all_staff_assigned( $post->ID );

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

		$plural_label = vkbm_get_resource_label_plural();
		// ラジオと説明文・チェックリストを aria で関連付けるための ID。
		$list_id               = 'vkbm-service-menu-staff-list';
		$all_description_id    = 'vkbm-service-menu-staff-all-description';
		$choose_description_id = 'vkbm-service-menu-staff-choose-description';
		$status_id             = 'vkbm-service-menu-staff-status';
		// 「選ぶ」かつチェック0件のときだけ案内を出す（「すべて」のときは出さない）。
		// リソースが1件も無いときは、チェックできる対象が無く「チェックを入れる」が実行できないため出さない
		// （空状態の「〇〇が登録されていません。〇〇を追加」だけを見せる。植草レビュー）。
		// 初期表示でも同条件ならサーバー側で最初から文言を入れておき、以降は service-menu-editor.js が同じ条件で更新する。
		$show_unselected_notice = ! $is_all_staff && empty( $selected_staff ) && ! empty( $resources );
		?>
		<fieldset class="vkbm-service-menu-staff">
			<legend class="vkbm-service-menu-staff__legend"><?php echo esc_html( Service_Menu_Post_Type::get_available_staff_label() ); ?></legend>
			<div class="vkbm-service-menu-staff__option">
				<label>
					<input
						type="radio"
						id="vkbm-service-menu-staff-all"
						name="vkbm_service_menu[staff_all]"
						value="1"
						aria-controls="<?php echo esc_attr( $list_id ); ?>"
						aria-describedby="<?php echo esc_attr( $all_description_id ); ?>"
						<?php checked( $is_all_staff ); ?>
					/>
					<?php echo esc_html( Service_Menu_Post_Type::get_all_staff_option_label() ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $all_description_id ); ?>">
					<?php
					printf(
						/* translators: %s: resource label (plural), e.g. "Staff". */
						esc_html__( '%s added later will also be able to handle this menu automatically.', 'vk-booking-manager' ),
						esc_html( $plural_label )
					);
					?>
				</p>
			</div>
			<div class="vkbm-service-menu-staff__option">
				<label>
					<input
						type="radio"
						id="vkbm-service-menu-staff-choose"
						name="vkbm_service_menu[staff_all]"
						value="0"
						aria-controls="<?php echo esc_attr( $list_id ); ?>"
						aria-describedby="<?php echo esc_attr( $choose_description_id ); ?>"
						<?php checked( ! $is_all_staff ); ?>
					/>
					<?php echo esc_html( Service_Menu_Post_Type::get_choose_staff_option_label() ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $choose_description_id ); ?>">
					<?php
					printf(
						/* translators: %s: resource label (plural), e.g. "Staff". */
						esc_html__( 'After adding %s, check them here so they can be in charge.', 'vk-booking-manager' ),
						esc_html( $plural_label )
					);
					?>
				</p>
				<?php
				// 「すべて」選択中は個別チェックリストを hidden で非表示にする（disabled の灰色表示にはしない。
				// チェック済みと未チェックが灰色で混在すると「すべてのはずなのに一部外れている」と読まれるため）。
				// 個別選択の値は hidden でも送信されるため、「すべて」選択中も保持される。
				?>
				<div id="<?php echo esc_attr( $list_id ); ?>" class="vkbm-service-menu-staff__list" <?php echo $is_all_staff ? 'hidden' : ''; ?>>
					<?php if ( empty( $resources ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: resource label (plural), e.g. "Staff". */
								esc_html__( 'No %s are registered.', 'vk-booking-manager' ),
								esc_html( $plural_label )
							);
							?>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Resource_Post_Type::POST_TYPE ) ); ?>">
								<?php
								printf(
									/* translators: %s: resource label (plural), e.g. "Staff". */
									esc_html__( 'Add %s', 'vk-booking-manager' ),
									esc_html( $plural_label )
								);
								?>
							</a>
						</p>
					<?php else : ?>
						<ul class="vkbm-service-menu-staff__checkboxes">
							<?php foreach ( $resources as $resource ) : ?>
								<li>
									<label>
										<input
											type="checkbox"
											class="vkbm-service-menu-staff__checkbox"
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
				</div>
			</div>
			<?php
			// 未選択の案内。role="status" の領域を最初から置き、条件を満たしたときだけ文言を入れる
			// （フォーカスは動かさない）。「予約を受け付けられません」のような結果は書かない
			// （指名ありのメニューでは担当未設定でも指名スタッフを受け付ける現状挙動があり、事実と違うため）。
			// 開始タグ・文言・終了タグを1行に置き、案内を出さないときに空白文字を含めない（CSS の :empty で余白を消すため）。
			?>
			<p class="description vkbm-service-menu-staff__status" id="<?php echo esc_attr( $status_id ); ?>" role="status"><?php echo $show_unselected_notice ? esc_html( self::get_staff_unselected_notice() ) : ''; ?></p>
		</fieldset>
		<?php
	}

	/**
	 * 「担当できるリソースが選ばれていません」の案内文を返す（#485）。
	 *
	 * PHP の初期描画と service-menu-editor.js（wp_localize_script 経由）で同じ文言を使う。
	 * 翻訳関数は1文ごとに分け、リソースラベル（複数形）を差し込んで連結する。
	 *
	 * @return string 案内文（2文）。
	 */
	private static function get_staff_unselected_notice(): string {
		$plural_label = vkbm_get_resource_label_plural();

		return sprintf(
			/* translators: %s: resource label (plural), e.g. "Staff". */
			__( 'No %s have been selected to be in charge.', 'vk-booking-manager' ),
			$plural_label
		) . ' ' . sprintf(
			/* translators: 1: resource label (plural), e.g. "Staff". 2: label of the "All ... can be in charge" option. */
			__( 'Check the %1$s, or select "%2$s".', 'vk-booking-manager' ),
			$plural_label,
			Service_Menu_Post_Type::get_all_staff_option_label()
		);
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
				self::get_notice_transient_key(),
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
		// 「すべてのリソースが担当できる」ラジオ（#485）。value="1" のときだけ true。
		$staff_all         = isset( $data['staff_all'] ) && '1' === (string) $data['staff_all'];
		$fixed_start_times = $this->sanitize_fixed_start_times(
			$data['fixed_start_times'] ?? array(),
			$data['fixed_start_minutes'] ?? array()
		);

			$this->update_meta_value( $post_id, '_vkbm_catch_copy', $catch_copy );
		// サブ画像（#623）。フォームに欄が無い送信（欄の無い画面など）では既存の値を消さない。
		if ( isset( $data['gallery_ids'] ) && is_string( $data['gallery_ids'] ) ) {
			$this->update_meta_value(
				$post_id,
				Service_Menu_Post_Type::META_GALLERY_IDS,
				Service_Menu_Post_Type::sanitize_gallery_ids( $data['gallery_ids'] ),
				true
			);
		}
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
		// 宿泊のサイトではこの欄を出さず送信されないため、保存済みの値を空で上書きしない（プリセットを戻したときに元へ戻すため）。
		if ( ! Industry_Presets::supports( 'lodging' ) ) {
			$this->update_meta_value( $post_id, self::META_DISABLE_NOMINATION_FEE, $disable_nomination_fee );
		}
		$is_pro_edition = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );

		// メニュー単位の指名機能トグル（#391）。
		// サイト全体の指名機能がONのときだけフォームにこのフィールドが存在するため、
		// OFF時は送信されず、既存のメニュー単位設定をそのまま保持する
		// （他の「親スイッチOFF時はフォームに項目が無いため保持する」箇所と同じ方針）。
		$is_lodging_night_submission = Industry_Presets::supports( 'lodging' )
			&& Service_Menu_Post_Type::BOOKING_UNIT_NIGHT === ( $data['booking_unit'] ?? '' );
		if ( $is_pro_edition && Staff_Editor::is_nomination_enabled() && ! $is_lodging_night_submission ) {
			$use_nomination = isset( $data['use_nomination'] );
			if ( $use_nomination ) {
				// 「使う」（既定）に戻すため、無効化メタを削除する。
				delete_post_meta( $post_id, self::META_DISABLE_NOMINATION );
			} else {
				// 明示的にこのメニューでは指名を使わない。
				update_post_meta( $post_id, self::META_DISABLE_NOMINATION, true );
			}
		}

		$this->save_booking_unit_meta( $post_id, $data );

		// 予約枠の定員はPro版かつ予約枠の定員機能ON時のみ保存する。
		//
		// #412 A-1/A-4/B-4: 以前はここで「このメニューの指名が無効か」（保存後の値）も条件に
		// 加えていた。しかしフォーム側の描画条件（保存"前"の値）とズレるため、指名ONの
		// メニューでチェックを外した最初の保存では実フィールドがまだ描画されておらず
		// $_POST に max_capacity 等が含まれないまま保存が走り、定員・複数人一括予約・
		// 料金区分・貸切設定が意図せず削除／初期化されていた（A-1）。逆に指名を使うへ
		// 戻す保存でも複数人一括予約系メタが明示的に削除され復元できなかった（A-4）。
		//
		// render_conditions_meta_box() 側を「予約枠の定員機能さえONなら、このメニューの
		// 指名可否に関わらずフィールドを常に描画し、表示/非表示は hidden 属性のみで出し分ける」
		// 方式へ変更したことで（B-4。保存の前後で描画条件が変わらなくなった）、保存側も
		// このメニューの指名可否では保存対象を分岐する必要が無くなった。
		//
		// #392: 以前は「指名を使うメニューでも値は保存され続けるが、判定側が1対1へ抑止するため
		// 保持していても無害」という多層防御の考え方だった。指名を使うメニューを1枠1組（貸切）
		// として扱う仕様変更に伴い、予約枠の定員・複数人一括予約・料金区分は
		// `Staff_Editor::is_multi_guest_available_for_menu()` から「指名OFF」条件を除去したため、
		// ここで保存された値は指名ON中も実際に適用される（=「保持していても無害」ではなく
		// 「意図どおり効く値」に変わった）。#440で「貸し切り予約・予約者による貸切指定・
		// 貸切料金」3設定も `Staff_Editor::is_exclusive_booking_available_for_menu()`
		// から「指名OFF」条件を除去したため、指名を使うメニューでも保存した値がそのまま適用される
		// （3設定だけを別扱いにしていた以前の多層防御は無くなった。ただし予約時の排他制御は
		// 「メニュー全体」ではなく「担当スタッフ単位」に変わる。実装は
		// Booking_Draft_Controller / Booking_Confirmation_Controller 側）。
		if ( $is_pro_edition && Staff_Editor::is_slot_capacity_enabled() ) {
			$this->update_meta_value( $post_id, self::META_MAX_CAPACITY, $max_capacity );
			// 最小催行人数を保存。0（制約なし）はメタを削除して従来挙動へ戻す。
			if ( $min_capacity > 0 ) {
				update_post_meta( $post_id, self::META_MIN_CAPACITY, $min_capacity );
			} else {
				delete_post_meta( $post_id, self::META_MIN_CAPACITY );
			}
			// 複数人一括予約の許可フラグを保存（Pro版かつ予約枠の定員機能ON時。このメニューの
			// 指名可否では保存を分岐しない。#392 で読み取り側 `is_multi_guest_available_for_menu()`
			// から「指名OFF」条件を除去したため、指名を使うメニューで保存した値も実際に適用される）。
			// 1予約あたりの最大人数は「予約枠の定員」に統一したため、専用メタは保存せず削除する。
			if ( $allow_multiple_guests ) {
				// 登録時のメタ型（boolean）に合わせて真偽値で保存する。
				update_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS, true );
				// 料金区分は複数人一括予約ON時のみ保存する。空配列ならメタを削除する（基本料金×人数へ戻す）。
				if ( ! empty( $price_tiers ) ) {
					update_post_meta( $post_id, self::META_PRICE_TIERS, wp_slash( $price_tiers ) );
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
				//
				// 「貸し切り予約」（$exclusive_when_booked）とは排他にする（#388）。
				// 両方ONで保存されると、予約者の指定有無に関わらず枠全体が貸切扱いになるのに、
				// 指定した予約者だけ貸し切り料金を負担する不整合（払った人が損をする）が起きるため、
				// 「貸し切り予約」ON時は「ユーザーによる貸し切り指定」側のチェック状態を無視し、
				// 従属メタ（本体・料金・適用外人数）を #330 の「複数人一括予約OFF時」と同じ書き方で削除する。
				if ( $exclusive_when_booked ) {
					delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
					delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
					delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
				} elseif ( $exclusive_user_selectable ) {
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
				delete_post_meta( $post_id, self::META_ALLOW_MULTIPLE_GUESTS );
				delete_post_meta( $post_id, self::META_PRICE_TIERS );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_WHEN_BOOKED );
				// ユーザー貸し切り指定（#305）の3メタも兄弟と揃えて削除する（保持しない）。
				delete_post_meta( $post_id, self::META_EXCLUSIVE_USER_SELECTABLE );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_PER_PERSON );
				delete_post_meta( $post_id, self::META_EXCLUSIVE_FEE_EXEMPT_GUESTS );
			}
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
		} elseif ( $is_pro_edition ) {
			// Pro版だが予約枠の定員機能が親スイッチで無効な場合。フォームに予約枠の定員・
			// 複数人一括予約・料金区分・貸切設定のフィールド自体が無いため、既存のメニュー単位設定は
			// 削除せずそのまま保持する。これにより、親スイッチを再度ONにすれば元の設定で復帰でき、
			// 後方互換を維持する（#412: このメニューの指名可否に関わらず同じ「保持」方針にした。
			// 指名ONのメニューも指名OFFのメニューも、フォームに項目が無い理由は共通のため）。
			// なお、実際の人数判定はゲート側（get_max_guests / resolve_guests / get_menu_max_capacity /
			// is_menu_exclusive_when_booked / is_user_exclusive_selectable）が予約枠の定員機能OFFを
			// 評価し1名へ抑止するため、メタを残しても挙動上の影響はない。
			// 廃止済みの専用メタ（1予約あたり最大人数）は他分岐と同様に削除する。
			delete_post_meta( $post_id, self::META_MAX_GUESTS_PER_BOOKING );
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
		// 1室1泊あたりで規定人数を超えた分の追加料金を使う設定（#934）。料金区分を残すか消すかの判断に使うため、下の削除より先に保存する。
		$this->save_night_extra_guest_meta( $post_id, $data, $price_tiers, $is_pro_edition );
		// 予約枠の定員機能がオフでフォームに貸切・料金区分の欄が無い構成でも、泊単位では使わない値を残さない。
		$this->delete_night_unit_unsupported_meta( $post_id );
		if ( Staff_Editor::is_enabled() ) {
			// 個別選択は「すべて」選択中も消さずに保持する（「選ぶ」へ戻したとき以前の選択を復元するため。#485）。
			$this->update_meta_value( $post_id, '_vkbm_staff_ids', $staff_ids, true );
			// '' のときは update_meta_value() がメタを削除し、未設定（既定 false＝「選ぶ」）に戻る。
			$this->update_meta_value( $post_id, Service_Menu_Post_Type::META_STAFF_ALL, $staff_all ? '1' : '' );
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

		return sanitize_text_field( (string) $data[ $key ] );
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

		return sanitize_textarea_field( (string) $data[ $key ] );
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

		$raw = trim( (string) $data[ $key ] );

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

		$raw = trim( (string) $data[ $key ] );

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
		$value = sanitize_text_field( (string) $raw );
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
			$h = (int) sanitize_text_field( (string) $hour );
			$m = (int) sanitize_text_field( (string) ( $minutes[ $index ] ?? '0' ) );

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
	 * フォームは `price_tiers[label][]`・`price_tiers[price][]`・`price_tiers[pre_holiday_price][]`・
	 * `price_tiers[special_price][]` を並行配列で送るため、インデックスで突き合わせて
	 * `[ [ 'label' => ..., 'price' => ..., 'pre_holiday_price' => ..., 'special_price' => ... ], ... ]` に変換してから
	 * Price_Tiers::sanitize_tiers() へ委譲する（空行除去・負数クランプ・件数上限。休前日・特定日の料金は空欄なら保存しない）。
	 *
	 * @param mixed $raw 料金区分の生入力。
	 * @return array<int, array{label: string, price: int, pre_holiday_price?: int, special_price?: int}>
	 */
	private function sanitize_price_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$labels            = isset( $raw['label'] ) && is_array( $raw['label'] ) ? array_values( $raw['label'] ) : array();
		$prices            = isset( $raw['price'] ) && is_array( $raw['price'] ) ? array_values( $raw['price'] ) : array();
		$pre_holiday_price = isset( $raw['pre_holiday_price'] ) && is_array( $raw['pre_holiday_price'] ) ? array_values( $raw['pre_holiday_price'] ) : array();
		$special_price     = isset( $raw['special_price'] ) && is_array( $raw['special_price'] ) ? array_values( $raw['special_price'] ) : array();

		$rows = array();
		foreach ( $labels as $index => $label ) {
			$rows[] = array(
				'label'             => sanitize_text_field( (string) $label ),
				'price'             => isset( $prices[ $index ] ) && is_scalar( $prices[ $index ] ) ? (string) $prices[ $index ] : 0,
				'pre_holiday_price' => isset( $pre_holiday_price[ $index ] ) && is_scalar( $pre_holiday_price[ $index ] ) ? (string) $pre_holiday_price[ $index ] : '',
				'special_price'     => isset( $special_price[ $index ] ) && is_scalar( $special_price[ $index ] ) ? (string) $special_price[ $index ] : '',
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

		// update_post_meta() は渡された値を unslash するため、先に slash して「\」が消えないようにする。
		// update_post_meta() unslashes its input, so slash first to keep backslashes.
		update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
	}
}
