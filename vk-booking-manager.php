<?php
/**
 * Plugin Name: VK Booking Manager
 * Plugin URI:  https://github.com/vektor-inc/vk-booking-manager/
 * Description: This is a booking plugin that supports complex service formats such as beauty, chiropractic, and private lessons. It can be used not only on websites but also as a standalone booking system.
 * Version:     0.0.26
 * Author:      Vektor,Inc.
 * Author URI:  https://vektor-inc.co.jp/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vk-booking-manager
 * Domain Path: /languages
 *
 * @package VKBookingManager
 * @copyright Copyright (C) 2026 Vektor,Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'vkbm_pro_is_pro_edition' ) ) {
	/**
	 * Check whether this plugin is the Pro edition (robust to folder naming).
	 *
	 * 日本語: ディレクトリ名だけに依存せず Pro 版かどうかを判定します。
	 *
	 * @return bool
	 */
	function vkbm_pro_is_pro_edition(): bool {
		// English: Prefer plugin header (handles folders without "-pro").
		// 日本語: まずヘッダ情報で判定します（-pro を含まないフォルダ対策）。
		if ( function_exists( 'get_file_data' ) ) {
			$plugin_data = get_file_data( __FILE__, array( 'name' => 'Plugin Name' ) );
			$name        = isset( $plugin_data['name'] ) ? (string) $plugin_data['name'] : '';
			if ( '' !== $name && false !== stripos( $name, 'pro' ) ) {
				return true;
			}
		}

		// English: Fallback to directory name heuristic.
		// 日本語: フォルダ名の判定にフォールバックします。
		return false !== strpos( basename( __DIR__ ), '-pro' );
	}
}

if ( ! function_exists( 'vkbm_pro_get_free_plugin_basename' ) ) {
	/**
	 * Get the Free edition plugin basename (for Pro-only early conflict handling).
	 *
	 * @return string Plugin basename or empty string if not found.
	 */
	function vkbm_pro_get_free_plugin_basename(): string {
		$candidates = array(
			'vk-booking-manager/vk-booking-manager.php',
			'vk-booking-manager-free/vk-booking-manager.php',
		);
		$plugins    = get_plugins();
		foreach ( $candidates as $candidate ) {
			if ( isset( $plugins[ $candidate ] ) ) {
				return $candidate;
			}
		}
		foreach ( $plugins as $plugin_file => $data ) {
			$name        = isset( $data['Name'] ) ? (string) $data['Name'] : '';
			$text_domain = isset( $data['TextDomain'] ) ? (string) $data['TextDomain'] : '';
			if ( 'vk-booking-manager' !== $text_domain ) {
				continue;
			}
			if ( '' !== $name && false !== stripos( $name, 'pro' ) ) {
				continue;
			}
			return $plugin_file;
		}
		return '';
	}
}

if ( ! function_exists( 'vkbm_pro_deactivate_free_on_activated' ) ) {
	/**
	 * Callback for 'activated_plugin': deactivate Free edition when Pro was just activated.
	 *
	 * @param string $activated_plugin Plugin basename that was activated.
	 */
	function vkbm_pro_deactivate_free_on_activated( string $activated_plugin ): void {
		if ( plugin_basename( __FILE__ ) !== $activated_plugin ) {
			return;
		}
		$free_basename = vkbm_pro_get_free_plugin_basename();
		if ( '' === $free_basename ) {
			return;
		}
		if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $free_basename ) ) {
			deactivate_plugins( $free_basename, true, true );
			return;
		}
		if ( is_plugin_active( $free_basename ) ) {
			deactivate_plugins( $free_basename, true );
		}
	}
}

// English: If this is Pro and Free is active, schedule deactivation of Free on 'activated_plugin' and bail out without loading any Pro code. This avoids fatal errors (e.g. class redeclaration) when both editions would be in memory.
// 日本語: Pro であり無料版が有効な場合は、'activated_plugin' で無料版を無効化するだけ登録して何も読み込まずに終了する。両エディションがメモリに乗る際の致命的エラー（クラス重複等）を防ぐ.
if ( is_admin() ) {
	if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) || ! function_exists( 'get_file_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( vkbm_pro_is_pro_edition() ) {
		$vkbm_free_basename = vkbm_pro_get_free_plugin_basename();
		$free_is_active     = ( '' !== $vkbm_free_basename ) && is_plugin_active( $vkbm_free_basename );
		$free_is_network    = ( '' !== $vkbm_free_basename ) && is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $vkbm_free_basename );
		if ( $free_is_active || $free_is_network ) {
			add_action( 'activated_plugin', 'vkbm_pro_deactivate_free_on_activated', 10, 1 );
			return;
		}
	}
}

require_once __DIR__ . '/src/admin/class-edition-plugin-deactivator.php';

// English: Ensure both editions can be active without fatal errors and prefer Pro.
// 日本語: 両エディションの同時有効化で致命的エラーが起きないようにし、Proを優先します.
$vkbm_current_edition = \VKBookingManager\Admin\Edition_Plugin_Deactivator::detect_current_edition( __FILE__ );
if ( \VKBookingManager\Admin\Edition_Plugin_Deactivator::handle_conflict( $vkbm_current_edition, __FILE__ ) ) {
	return;
}

if ( ! defined( 'VKBM_VERSION' ) ) {
	$vkbm_plugin_data = get_file_data( __FILE__, array( 'version' => 'Version' ) );
	define( 'VKBM_VERSION', $vkbm_plugin_data['version'] );
}

if ( ! defined( 'VKBM_PLUGIN_FILE' ) ) {
	define( 'VKBM_PLUGIN_FILE', __FILE__ );
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/src/post-types/class-booking-post-type.php';
require_once __DIR__ . '/src/bookings/class-booking-admin.php';
require_once __DIR__ . '/src/bookings/class-booking-draft-controller.php';
require_once __DIR__ . '/src/bookings/class-my-bookings-controller.php';
require_once __DIR__ . '/src/common/class-vkbm-helpers.php';
require_once __DIR__ . '/src/assets/class-common-styles.php';
require_once __DIR__ . '/src/term-order/class-term-order-manager.php';
require_once __DIR__ . '/src/blocks/class-menu-search-block.php';
require_once __DIR__ . '/src/blocks/class-menu-loop-block.php';
require_once __DIR__ . '/src/blocks/class-reservation-block.php';
require_once __DIR__ . '/src/availability/class-availability-service.php';
require_once __DIR__ . '/src/rest/class-availability-controller.php';
require_once __DIR__ . '/src/rest/class-menu-preview-controller.php';
require_once __DIR__ . '/src/rest/class-auth-form-controller.php';
require_once __DIR__ . '/src/rest/class-provider-settings-controller.php';
require_once __DIR__ . '/src/rest/class-current-user-controller.php';
require_once __DIR__ . '/src/bookings/class-customer-name-resolver.php';
require_once __DIR__ . '/src/bookings/class-booking-confirmation-controller.php';
require_once __DIR__ . '/src/staff/class-staff-editor.php';
require_once __DIR__ . '/src/notifications/class-booking-notification-service.php';
require_once __DIR__ . '/src/auth/class-auth-shortcodes.php';
require_once __DIR__ . '/src/post-order/class-post-order-manager.php';
require_once __DIR__ . '/src/admin/class-owner-admin-menu-filter.php';
require_once __DIR__ . '/src/admin/class-style-guide-page.php';
require_once __DIR__ . '/src/admin/class-setup-notices.php';
require_once __DIR__ . '/src/admin/class-user-profile-fields.php';
require_once __DIR__ . '/src/admin/class-email-log-repository.php';
require_once __DIR__ . '/src/admin/class-email-log-page.php';
require_once __DIR__ . '/src/oembed/class-oembed-override.php';
require_once __DIR__ . '/src/resources/resource-labels.php';

use VKBookingManager\Admin\Email_Log_Page;
use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\Admin\Owner_Admin_Menu_Filter;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Admin\Style_Guide_Page;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Admin\User_Profile_Fields;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\REST\Auth_Form_Controller;
use VKBookingManager\REST\Current_User_Controller;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Bookings\Customer_Name_Resolver;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Blocks\Menu_Search_Block;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\OEmbed\OEmbed_Override;
use VKBookingManager\PostOrder\Post_Order_Manager;
use VKBookingManager\TermOrder\Term_Order_Manager;
use VKBookingManager\Plugin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\REST\Availability_Controller;
use VKBookingManager\REST\Menu_Preview_Controller;
use VKBookingManager\REST\Provider_Settings_Controller;
use VKBookingManager\Resources\Resource_Schedule_Meta_Box;
use VKBookingManager\Resources\Resource_Schedule_Template_Repository;
use VKBookingManager\Shifts\Shift_Editor;
use VKBookingManager\Staff\Staff_Editor;

/**
 * Builds the plugin instance.
 *
 * @return Plugin|null
 */
if ( ! function_exists( 'vkbm_plugin' ) ) {
	function vkbm_plugin(): ?Plugin {
		static $plugin = null;

		if ( null !== $plugin ) {
			return $plugin;
		}

		if ( ! class_exists( Plugin::class ) ) {
			return null;
		}

	$settings_repository = new Settings_Repository();
	$settings_sanitizer  = new Settings_Sanitizer();
	$settings_service    = new Settings_Service( $settings_repository, $settings_sanitizer );

	$common_styles          = new Common_Styles();
	$roles_manager          = new Roles_Manager();
	$shift_dashboard_page   = new Shift_Dashboard_Page( Capabilities::MANAGE_PROVIDER_SETTINGS );
	$provider_settings_page = new Provider_Settings_Page( $settings_service, Capabilities::MANAGE_PROVIDER_SETTINGS, '' );
	$email_log_page         = new Email_Log_Page( 'vkbm-provider-settings', Capabilities::MANAGE_PROVIDER_SETTINGS );
	// Development-only: keep access permissive (file presence is the main gate).
	$style_guide_page                = new Style_Guide_Page( 'read' );
	$setup_notices                   = new Setup_Notices();
	$user_profile_fields             = new User_Profile_Fields();
	$resource_schedule_repository    = new Resource_Schedule_Template_Repository();
	$resource_schedule_meta_box      = new Resource_Schedule_Meta_Box( $resource_schedule_repository );
	$shift_editor                    = new Shift_Editor();
	$staff_editor                    = new Staff_Editor();
	$service_menu_editor             = new Service_Menu_Editor();
	$resource_post_type              = new Resource_Post_Type();
	$owner_admin_menu_filter         = new Owner_Admin_Menu_Filter();
	$shift_post_type                 = new Shift_Post_Type();
	$service_menu_post_type          = new Service_Menu_Post_Type();
	$booking_post_type               = new Booking_Post_Type();
	$customer_name_resolver          = new Customer_Name_Resolver();
	$booking_notification_service    = new Booking_Notification_Service( $settings_repository, $customer_name_resolver );
	$oembed_override                 = new OEmbed_Override();
	$booking_admin                   = new Booking_Admin( $booking_notification_service );
	$booking_draft_controller        = new Booking_Draft_Controller( $settings_repository );
	$availability_service            = new Availability_Service( $settings_repository );
	$booking_confirmation_controller = new Booking_Confirmation_Controller( $booking_notification_service, $settings_repository, $customer_name_resolver, $availability_service );
	$my_bookings_controller          = new My_Bookings_Controller( $settings_repository, $booking_notification_service );
	$menu_search_block               = new Menu_Search_Block();
	$menu_loop_block                 = new Menu_Loop_Block();
	$reservation_block               = new Reservation_Block();
	$availability_controller         = new Availability_Controller( $availability_service );
	$current_user_controller         = new Current_User_Controller( $settings_service );
	$menu_preview_controller         = new Menu_Preview_Controller( $menu_loop_block );
	$provider_settings_controller    = new Provider_Settings_Controller( $settings_repository );
	$auth_shortcodes                 = new Auth_Shortcodes( $settings_service );
	$auth_form_controller            = new Auth_Form_Controller( $auth_shortcodes );
	$post_order_manager              = new Post_Order_Manager(
		array(
			Resource_Post_Type::POST_TYPE,
			Service_Menu_Post_Type::POST_TYPE,
		)
	);
	$term_order_manager              = new Term_Order_Manager(
		array(
			Service_Menu_Post_Type::TAXONOMY,
			Service_Menu_Post_Type::TAXONOMY_GROUP,
		)
	);

	// Register development-only style guide page (menu appears only when docs/ui/style-guide.html exists).
	$style_guide_page->register();
	$setup_notices->register();
	$email_log_page->register();
	$plugin = new Plugin(
		$common_styles,
		$provider_settings_page,
		$roles_manager,
		$resource_schedule_meta_box,
		$shift_editor,
		$staff_editor,
		$service_menu_editor,
		$shift_dashboard_page,
		$owner_admin_menu_filter,
		$resource_post_type,
		$shift_post_type,
		$service_menu_post_type,
		$booking_post_type,
		$booking_admin,
		$booking_draft_controller,
		$my_bookings_controller,
		$menu_search_block,
		$menu_loop_block,
		$reservation_block,
		$availability_controller,
		$current_user_controller,
		$booking_confirmation_controller,
		$menu_preview_controller,
		$provider_settings_controller,
		$booking_notification_service,
		$oembed_override,
		$auth_shortcodes,
		$auth_form_controller,
		$post_order_manager,
		$term_order_manager,
		$user_profile_fields
	);

		return $plugin;
	}
}

/**
 * Bootstraps the plugin.
 */
if ( ! function_exists( 'vkbm_init_plugin' ) ) {
	function vkbm_init_plugin(): void {
		$plugin = vkbm_plugin();

		if ( ! $plugin instanceof Plugin ) {
			return;
		}

		// Load textdomain (excluded in free edition GitHub releases for WordPress.org).
		$textdomain_file = __DIR__ . '/load-plugin-textdomain.php';
		if ( file_exists( $textdomain_file ) ) {
			require_once $textdomain_file;
		}

		// Load GitHub updater for free edition (excluded from .org package).
		$updater_file = __DIR__ . '/class-vkbm-github-updater.php';
		if ( ! vkbm_pro_is_pro_edition() && file_exists( $updater_file ) ) {
			require_once $updater_file;
			if ( class_exists( 'VKBM_GitHub_Updater' ) ) {
				new VKBM_GitHub_Updater( __FILE__ );
			}
		}

		$plugin->register();

		add_action(
			'after_setup_theme',
			function () {
				if ( is_admin() ) {
					return;
				}

				if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
					return;
				}

				show_admin_bar( false );
			}
		);
	}
}

add_action( 'plugins_loaded', 'vkbm_init_plugin' );

/**
 * Activation callback.
 */
if ( ! function_exists( 'vkbm_activate_plugin' ) ) {
	function vkbm_activate_plugin(): void {
		$plugin = vkbm_plugin();

		if ( ! $plugin instanceof Plugin ) {
			return;
		}

		$plugin->activate();
	}
}

register_activation_hook( __FILE__, 'vkbm_activate_plugin' );

/**
 * Normalize reservation page URL against the current site.
 *
 * @param string $url Raw URL from settings.
 * @return string
 */
if ( ! function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
	function vkbm_normalize_reservation_page_url( string $url ): string {
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
}

/**
 * Retrieve and cache the reservation page URL for logout redirects.
 *
 * @return string
 */
if ( ! function_exists( 'vkbm_get_reservation_page_logout_url' ) ) {
	function vkbm_get_reservation_page_logout_url(): string {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$raw_url    = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';

		$cached = vkbm_normalize_reservation_page_url( $raw_url );

		return $cached;
	}
}

/**
 * Get the configured singular label for resources (default: スタッフ).
 *
 * @return string
 */
add_action(
	'wp_logout',
	static function (): void {
		$redirect = vkbm_get_reservation_page_logout_url();

		if ( '' === $redirect ) {
			return;
		}

		wp_safe_redirect( $redirect );
		exit;
	},
	0
);
