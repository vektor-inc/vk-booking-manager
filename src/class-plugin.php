<?php

/**
 * Main plugin orchestrator.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Owner_Admin_Menu_Filter;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Admin\User_Profile_Fields;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Blocks\Menu_Search_Block;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\OEmbed\OEmbed_Override;
use VKBookingManager\PostOrder\Post_Order_Manager;
use VKBookingManager\TermOrder\Term_Order_Manager;
use VKBookingManager\REST\Availability_Controller;
use VKBookingManager\REST\Auth_Form_Controller;
use VKBookingManager\REST\Current_User_Controller;
use VKBookingManager\REST\Menu_Preview_Controller;
use VKBookingManager\REST\Provider_Settings_Controller;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Resources\Resource_Schedule_Meta_Box;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Staff\Staff_Editor;

/**
 * Main plugin orchestrator.
 */
class Plugin {
	private const META_SHIFT_DEFAULT_STAFF_FLAG = '_vkbm_shift_default_staff';
	/**
	 * Common style enqueuer.
	 *
	 * @var Common_Styles
	 */
	private $common_styles;

	/**
	 * Provider settings handler.
	 *
	 * @var Provider_Settings_Page
	 */
	private $provider_settings_page;

	/**
	 * Roles handler.
	 *
	 * @var Roles_Manager
	 */
	private $roles_manager;

	/**
	 * Resource schedule handler.
	 *
	 * @var Resource_Schedule_Meta_Box
	 */
	private $resource_schedule_meta_box;

	/**
	 * Shift editor handler.
	 *
	 * @var Shift_Editor
	 */
	private $shift_editor;

	/**
	 * Staff editor handler.
	 *
	 * @var Staff_Editor
	 */
	private $staff_editor;

	/**
	 * Service menu editor handler.
	 *
	 * @var Service_Menu_Editor
	 */
	private $service_menu_editor;

	/**
	 * Shift dashboard page handler.
	 *
	 * @var Shift_Dashboard_Page
	 */
	private $shift_dashboard_page;

	/**
	 * Owner admin menu filter handler.
	 *
	 * @var Owner_Admin_Menu_Filter
	 */
	private $owner_admin_menu_filter;

	/**
	 * Resource post type handler.
	 *
	 * @var Resource_Post_Type
	 */
	private $resource_post_type;

	/**
	 * Shift post type handler.
	 *
	 * @var Shift_Post_Type
	 */
	private $shift_post_type;

	/**
	 * Service menu post type handler.
	 *
	 * @var Service_Menu_Post_Type
	 */
	private $service_menu_post_type;

	/**
	 * Booking post type handler.
	 *
	 * @var Booking_Post_Type
	 */
	private $booking_post_type;

	/**
	 * Booking admin UI handler.
	 *
	 * @var Booking_Admin
	 */
	private $booking_admin;

	/**
	 * Temporary reservation data persistence handler.
	 *
	 * @var Booking_Draft_Controller
	 */
	private $booking_draft_controller;

	/**
	 * Current user bookings REST controller.
	 *
	 * @var My_Bookings_Controller
	 */
	private $my_bookings_controller;

	/**
	 * Menu search block handler.
	 *
	 * @var Menu_Search_Block
	 */
	private $menu_search_block;

	/**
	 * Menu loop block handler.
	 *
	 * @var Menu_Loop_Block
	 */
	private $menu_loop_block;

	/**
	 * Reservation block handler.
	 *
	 * @var Reservation_Block
	 */
	private $reservation_block;

	/**
	 * Availability REST controller.
	 *
	 * @var Availability_Controller
	 */
	private $availability_controller;

	/**
	 * Current user REST controller.
	 *
	 * @var Current_User_Controller
	 */
	private $current_user_controller;

	/**
	 * Booking confirmation REST controller.
	 *
	 * @var Booking_Confirmation_Controller
	 */
	private $booking_confirmation_controller;

	/**
	 * Menu preview REST controller.
	 *
	 * @var Menu_Preview_Controller
	 */
	private $menu_preview_controller;

	/**
	 * Provider settings REST controller.
	 *
	 * @var Provider_Settings_Controller
	 */
	private $provider_settings_controller;

	/**
	 * Booking notification handler.
	 *
	 * @var Booking_Notification_Service
	 */
	private $booking_notification_service;

	/**
	 * OEmbed override handler.
	 *
	 * @var OEmbed_Override
	 */
	private $oembed_override;

	/**
	 * Auth shortcode handler.
	 *
	 * @var Auth_Shortcodes
	 */
	private $auth_shortcodes;

	/**
	 * Authentication form REST controller.
	 *
	 * @var Auth_Form_Controller
	 */
	private $auth_form_controller;

	/**
	 * Post order handler.
	 *
	 * @var Post_Order_Manager
	 */
	private $post_order_manager;

	/**
	 * Term order handler.
	 *
	 * @var Term_Order_Manager
	 */
	private $term_order_manager;

	/**
	 * User profile fields handler.
	 *
	 * @var User_Profile_Fields
	 */
	private $user_profile_fields;

	/**
	 * Constructor.
	 *
	 * @param Common_Styles                   $common_styles          Common style enqueuer.
	 * @param Provider_Settings_Page          $provider_settings_page Provider settings handler.
	 * @param Roles_Manager                   $roles_manager          Roles handler.
	 * @param Resource_Schedule_Meta_Box      $resource_schedule_meta_box Resource schedule handler.
	 * @param Shift_Editor                    $shift_editor           Shift editor handler.
	 * @param Staff_Editor                    $staff_editor           Staff editor handler.
	 * @param Service_Menu_Editor             $service_menu_editor    Service menu editor handler.
	 * @param Shift_Dashboard_Page            $shift_dashboard_page   Shift dashboard page handler.
	 * @param Owner_Admin_Menu_Filter         $owner_admin_menu_filter Owner admin menu filter handler.
	 * @param Resource_Post_Type              $resource_post_type     Resource post type handler.
	 * @param Shift_Post_Type                 $shift_post_type        Shift post type handler.
	 * @param Service_Menu_Post_Type          $service_menu_post_type Service menu post type handler.
	 * @param Booking_Post_Type               $booking_post_type      Booking post type handler.
	 * @param Booking_Admin                   $booking_admin          Booking admin UI handler.
	 * @param Booking_Draft_Controller        $booking_draft_controller Temporary reservation data persistence handler.
	 * @param My_Bookings_Controller          $my_bookings_controller Current user bookings REST controller.
	 * @param Menu_Search_Block               $menu_search_block      Menu search block handler.
	 * @param Menu_Loop_Block                 $menu_loop_block        Menu loop block handler.
	 * @param Reservation_Block               $reservation_block      Reservation block handler.
	 * @param Availability_Controller         $availability_controller Availability REST controller.
	 * @param Current_User_Controller         $current_user_controller Current user REST controller.
	 * @param Booking_Confirmation_Controller $booking_confirmation_controller Booking confirmation REST controller.
	 * @param Menu_Preview_Controller         $menu_preview_controller Menu preview REST controller.
	 * @param Provider_Settings_Controller    $provider_settings_controller Provider settings REST controller.
	 * @param Booking_Notification_Service    $booking_notification_service Booking notification handler.
	 * @param OEmbed_Override                 $oembed_override             oEmbed override handler.
	 * @param Auth_Shortcodes                 $auth_shortcodes         Auth shortcode handler.
	 * @param Auth_Form_Controller            $auth_form_controller    Authentication form REST controller.
	 * @param Post_Order_Manager              $post_order_manager      Post order handler.
	 * @param Term_Order_Manager              $term_order_manager      Term order handler.
	 * @param User_Profile_Fields             $user_profile_fields     User profile fields handler.
	 */
	public function __construct(
		Common_Styles $common_styles,
		Provider_Settings_Page $provider_settings_page,
		Roles_Manager $roles_manager,
		Resource_Schedule_Meta_Box $resource_schedule_meta_box,
		Shift_Editor $shift_editor,
		Staff_Editor $staff_editor,
		Service_Menu_Editor $service_menu_editor,
		Shift_Dashboard_Page $shift_dashboard_page,
		Owner_Admin_Menu_Filter $owner_admin_menu_filter,
		Resource_Post_Type $resource_post_type,
		Shift_Post_Type $shift_post_type,
		Service_Menu_Post_Type $service_menu_post_type,
		Booking_Post_Type $booking_post_type,
		Booking_Admin $booking_admin,
		Booking_Draft_Controller $booking_draft_controller,
		My_Bookings_Controller $my_bookings_controller,
		Menu_Search_Block $menu_search_block,
		Menu_Loop_Block $menu_loop_block,
		Reservation_Block $reservation_block,
		Availability_Controller $availability_controller,
		Current_User_Controller $current_user_controller,
		Booking_Confirmation_Controller $booking_confirmation_controller,
		Menu_Preview_Controller $menu_preview_controller,
		Provider_Settings_Controller $provider_settings_controller,
		Booking_Notification_Service $booking_notification_service,
		OEmbed_Override $oembed_override,
		Auth_Shortcodes $auth_shortcodes,
		Auth_Form_Controller $auth_form_controller,
		Post_Order_Manager $post_order_manager,
		Term_Order_Manager $term_order_manager,
		User_Profile_Fields $user_profile_fields
	) {
		$this->common_styles                   = $common_styles;
		$this->provider_settings_page          = $provider_settings_page;
		$this->roles_manager                   = $roles_manager;
		$this->resource_schedule_meta_box      = $resource_schedule_meta_box;
		$this->shift_editor                    = $shift_editor;
		$this->staff_editor                    = $staff_editor;
		$this->service_menu_editor             = $service_menu_editor;
		$this->shift_dashboard_page            = $shift_dashboard_page;
		$this->owner_admin_menu_filter         = $owner_admin_menu_filter;
		$this->resource_post_type              = $resource_post_type;
		$this->shift_post_type                 = $shift_post_type;
		$this->service_menu_post_type          = $service_menu_post_type;
		$this->booking_post_type               = $booking_post_type;
		$this->booking_admin                   = $booking_admin;
		$this->booking_draft_controller        = $booking_draft_controller;
		$this->my_bookings_controller          = $my_bookings_controller;
		$this->menu_search_block               = $menu_search_block;
		$this->menu_loop_block                 = $menu_loop_block;
		$this->reservation_block               = $reservation_block;
		$this->availability_controller         = $availability_controller;
		$this->current_user_controller         = $current_user_controller;
		$this->booking_confirmation_controller = $booking_confirmation_controller;
		$this->menu_preview_controller         = $menu_preview_controller;
		$this->provider_settings_controller    = $provider_settings_controller;
		$this->booking_notification_service    = $booking_notification_service;
		$this->oembed_override                 = $oembed_override;
		$this->auth_shortcodes                 = $auth_shortcodes;
		$this->auth_form_controller            = $auth_form_controller;
		$this->post_order_manager              = $post_order_manager;
		$this->term_order_manager              = $term_order_manager;
		$this->user_profile_fields             = $user_profile_fields;
	}

	/**
	 * Register plugin hooks.
	 */
	public function register(): void {
		$this->common_styles->register();
		$this->booking_notification_service->register();
		$this->oembed_override->register();
		$this->roles_manager->register();
		add_filter( 'load_script_translation_file', array( $this, 'filter_script_translation_file' ), 10, 3 );
		add_action( 'init', array( $this, 'maybe_create_default_staff' ), 11 );
		$this->shift_dashboard_page->register();
		$this->owner_admin_menu_filter->register();
		$this->provider_settings_page->register();
		$this->resource_post_type->register();
		$this->shift_post_type->register();
		$this->resource_schedule_meta_box->register();
		$this->shift_editor->register();
		$this->staff_editor->register();
		$this->service_menu_editor->register();
		$this->service_menu_post_type->register();
		$this->booking_post_type->register();
		$this->booking_admin->register();
		$this->booking_draft_controller->register();
		$this->my_bookings_controller->register();
		$this->menu_search_block->register();
		$this->menu_loop_block->register();
		$this->reservation_block->register();
		$this->availability_controller->register();
		$this->current_user_controller->register();
		$this->booking_confirmation_controller->register();
		$this->menu_preview_controller->register();
		$this->provider_settings_controller->register();
		$this->auth_shortcodes->register();
		$this->auth_form_controller->register();
		$this->post_order_manager->register();
		$this->term_order_manager->register();
		$this->user_profile_fields->register();
	}

	/**
	 * WordPressの翻訳ファイル読み込み処理をフィルタリングして、プラグインの翻訳ファイルを優先的に読み込む
	 *
	 * プラグインの languages ディレクトリの翻訳 JSON を優先します。
	 * ハッシュが一致しない場合でも、利用可能なJSONファイルを検索します。
	 *
	 * @param string|false $file Translation file path.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public function filter_script_translation_file( $file, string $handle, string $domain ) {
		// このプラグインのテキストドメインでない場合はそのまま返す.
		if ( 'vk-booking-manager' !== $domain ) {
			return $file;
		}

		// ファイルパスが無い場合でも、menu-loopブロックの場合は処理を続行（edit.jsのJSONを探すため）.
		// その他の場合は早期リターン.
		if ( ! $file && false === strpos( $handle, 'menu-loop' ) ) {
			return $file;
		}

		// プラグインの languages ディレクトリのパスを取得.
		$translation_path = VKBM_PLUGIN_DIR_PATH . 'languages/';

		// ステップ1: WordPressが期待するファイル名（ハッシュ値付き）で完全一致するファイルを探す.
		// 例: vk-booking-manager-ja-5a65dc19bd83bf90afeedaaf518e966b.json.
		if ( $file ) {
			$candidate = $translation_path . basename( $file );
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		// ステップ2: 完全一致が見つからなかった場合、このハンドル用の翻訳を含む可能性のあるJSONファイルを検索する.
		// マージ処理（bin/merge-json-translations.js）により、booking-uiコンポーネントの翻訳は全てapp.jsのJSONに統合されているため、
		// reservationブロックの場合はapp.jsのJSONファイルを優先的に返すだけで十分.
		if ( ! is_dir( $translation_path ) ) {
			return $file;
		}

		// 現在のロケール（例: ja）を取得し、該当するJSONファイルのパターンを作成.
		// 例: vk-booking-manager-ja-*.json.
		$locale     = get_locale();
		$pattern    = sprintf( '%s-%s-*.json', $domain, $locale );
		$json_files = glob( $translation_path . $pattern );

		// JSONファイルが見つからない場合は、元のファイルパスをそのまま返す.
		if ( empty( $json_files ) ) {
			return $file;
		}

		// ステップ3: reservationブロックの場合は、
		// editorScript なら edit.js のJSON、その他は app.js のJSON を優先的に検索.
		// （app.jsにはcalendar-grid.js、daily-slot-list.js、selected-plan-summary.jsなどが全てバンドルされ、
		// build:i18n:json実行時にbin/merge-json-translations.jsで全ての翻訳がマージされている）.
		if ( false !== strpos( $handle, 'reservation' ) ) {
			if ( false !== strpos( $handle, 'editor' ) ) {
				foreach ( $json_files as $json_file ) {
					$json_content = file_get_contents( $json_file );
					if ( false === $json_content ) {
						continue;
					}

					$json_data = json_decode( $json_content, true );
					if ( ! is_array( $json_data ) || ! isset( $json_data['source'] ) ) {
						continue;
					}

					$source = $json_data['source'];

					// edit.js のJSONファイルを優先的に探す.
					if ( false !== strpos( $source, 'src/blocks/reservation/edit.js' ) ) {
						return $json_file;
					}
				}
			}

			foreach ( $json_files as $json_file ) {
				$json_content = file_get_contents( $json_file );
				if ( false === $json_content ) {
					continue;
				}

				$json_data = json_decode( $json_content, true );
				if ( ! is_array( $json_data ) || ! isset( $json_data['source'] ) ) {
					continue;
				}

				$source = $json_data['source'];

				// app.jsのJSONファイルを探す（全てのbooking-uiコンポーネントの翻訳がマージされている）.
				if ( false !== strpos( $source, 'src/blocks/reservation/app.js' ) ) {
					return $json_file;
				}
			}
		}

		// ステップ3-2: menu-loopブロックの場合は、index.jsのJSONファイルを優先的に検索.
		// （index.jsにはダミーの翻訳文字列が追加され、build:i18n:json実行時にbin/merge-json-translations.jsで
		// edit.jsの翻訳がindex.jsのJSONにマージされている）.
		// ハンドル名のパターン: vk-booking-manager-menu-loop-editor-script または vk-booking-manager/menu-loop-editor-script.
		if ( false !== strpos( $handle, 'menu-loop' ) ) {
			foreach ( $json_files as $json_file ) {
				$json_content = file_get_contents( $json_file );
				if ( false === $json_content ) {
					continue;
				}

				$json_data = json_decode( $json_content, true );
				if ( ! is_array( $json_data ) || ! isset( $json_data['source'] ) ) {
					continue;
				}

				$source = $json_data['source'];

				// index.jsのJSONファイルを優先的に探す（edit.jsの翻訳がマージされている）.
				if ( false !== strpos( $source, 'src/blocks/menu-loop/index.js' ) ||
					false !== strpos( $source, 'blocks/menu-loop/index.js' ) ||
					false !== strpos( $source, 'menu-loop/index.js' ) ) {
					return $json_file;
				}
			}

			// index.jsのJSONが見つからない場合、edit.jsのJSONをフォールバックとして探す.
			foreach ( $json_files as $json_file ) {
				$json_content = file_get_contents( $json_file );
				if ( false === $json_content ) {
					continue;
				}

				$json_data = json_decode( $json_content, true );
				if ( ! is_array( $json_data ) || ! isset( $json_data['source'] ) ) {
					continue;
				}

				$source = $json_data['source'];

				// edit.jsのJSONファイルを探す（フォールバック）.
				if ( false !== strpos( $source, 'src/blocks/menu-loop/edit.js' ) ||
					false !== strpos( $source, 'blocks/menu-loop/edit.js' ) ||
					false !== strpos( $source, 'menu-loop/edit.js' ) ) {
					return $json_file;
				}
			}
		}

		// ステップ4: その他のブロックの場合は、ハンドル名からブロック名を抽出して検索.
		$handle_clean = str_replace( array( 'vk-booking-manager-', 'booking-manager-' ), '', $handle );
		$handle_clean = preg_replace( '/-(editor|view|script)$/', '', $handle_clean );

		if ( ! empty( $handle_clean ) ) {
			foreach ( $json_files as $json_file ) {
				$json_content = file_get_contents( $json_file );
				if ( false === $json_content ) {
					continue;
				}

				$json_data = json_decode( $json_content, true );
				if ( ! is_array( $json_data ) || ! isset( $json_data['source'] ) ) {
					continue;
				}

				$source = $json_data['source'];

				// ハンドル名がsourceパスに含まれているか確認.
				if ( false !== strpos( $source, $handle_clean ) ) {
					return $json_file;
				}
			}
		}

		// ステップ7: どの方法でも見つからなかった場合、元のファイルパスをそのまま返す.
		// （WordPressのデフォルトの動作にフォールバック）.
		return $file;
	}

	/**
	 * Run activation routines.
	 */
	public function activate(): void {
		$this->roles_manager->activate();
	}

	/**
	 * Ensure a single default staff record exists for the Free edition.
	 *
	 * Free版ではスタッフが1名固定のため、未登録なら自動生成します。
	 */
	public function maybe_create_default_staff(): void {
		if ( Staff_Editor::is_enabled() ) {
			return;
		}

		if ( ! post_type_exists( Resource_Post_Type::POST_TYPE ) ) {
			return;
		}

		$title = __( 'Default Staff', 'vk-booking-manager' );

		$published_staff = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$keep_id = 0;
		foreach ( $published_staff as $staff_id ) {
			$post = get_post( $staff_id );
			if ( ! $post ) {
				continue;
			}

			if ( $title === $post->post_title ) {
				$keep_id = (int) $post->ID;
				break;
			}
		}

		if ( 0 === $keep_id ) {
			$default_id = wp_insert_post(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_author' => get_current_user_id(),
				)
			);

			if ( is_wp_error( $default_id ) ) {
				return;
			}

			$keep_id           = (int) $default_id;
			$published_staff[] = $keep_id;
		}

		foreach ( $published_staff as $staff_id ) {
			if ( (int) $staff_id === $keep_id ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => (int) $staff_id,
					'post_status' => 'draft',
				)
			);
		}

		$this->migrate_default_staff_shifts( $keep_id );
	}

	/**
	 * Migrate shifts flagged as Default Staff shifts to the current Default Staff.
	 *
	 * デフォルトスタッフ用フラグ付きシフトを現行の Default Staff に付け替えます。
	 *
	 * @param int $default_staff_id Default staff ID.
	 */
	private function migrate_default_staff_shifts( int $default_staff_id ): void {
		if ( $default_staff_id <= 0 || ! post_type_exists( Shift_Post_Type::POST_TYPE ) ) {
			return;
		}

		$shift_posts = get_posts(
			array(
				'post_type'      => Shift_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_SHIFT_DEFAULT_STAFF_FLAG,
						'value' => 1,
					),
				),
			)
		);

		foreach ( $shift_posts as $shift_post ) {
			$resource_id = (int) get_post_meta( $shift_post->ID, Shift_Editor::META_RESOURCE, true );
			if ( $resource_id === $default_staff_id ) {
				continue;
			}

			update_post_meta( $shift_post->ID, Shift_Editor::META_RESOURCE, $default_staff_id );

			$year  = (int) get_post_meta( $shift_post->ID, Shift_Editor::META_YEAR, true );
			$month = (int) get_post_meta( $shift_post->ID, Shift_Editor::META_MONTH, true );
			$this->update_shift_title( (int) $shift_post->ID, $default_staff_id, $year, $month );
		}
	}

	/**
	 * Update shift post title based on resource and period.
	 *
	 * @param int $post_id Post ID.
	 * @param int $resource_id Resource ID.
	 * @param int $year Year.
	 * @param int $month Month.
	 */
	private function update_shift_title( int $post_id, int $resource_id, int $year, int $month ): void {
		if ( $year <= 0 || $month <= 0 ) {
			return;
		}

		$resource_title = get_the_title( $resource_id );
		if ( ! $resource_title ) {
			return;
		}

		$new_title = sprintf( '%d year %02d month %s', $year, $month, $resource_title );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $new_title,
			)
		);
	}
}
