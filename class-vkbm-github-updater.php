<?php
/**
 * GitHub Updater for free edition.
 *
 * @package VKBookingManager
 */

if ( ! class_exists( 'VKBM_GitHub_Updater' ) ) {
	/**
	 * GitHub Updater Class
	 */
	class VKBM_GitHub_Updater {
		/**
		 * Plugin slug.
		 *
		 * @var string
		 */
		private $plugin_slug;

		/**
		 * Plugin data.
		 *
		 * @var array
		 */
		private $plugin_data;

		/**
		 * GitHub username.
		 *
		 * @var string
		 */
		private $username;

		/**
		 * GitHub repository name.
		 *
		 * @var string
		 */
		private $repo;

		/**
		 * Plugin file path.
		 *
		 * @var string
		 */
		private $plugin_file;

		/**
		 * GitHub API result.
		 *
		 * @var object
		 */
		private $github_api_result;

		/**
		 * Constructor.
		 *
		 * @param string $plugin_file Plugin file path.
		 */
		public function __construct( string $plugin_file ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'set_transient' ) );
			add_filter( 'plugins_api', array( $this, 'set_plugin_info' ), 10, 3 );
			add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

			$this->plugin_file = $plugin_file;
			$this->username    = 'vektor-inc';
			$this->repo        = 'vk-booking-manager';
		}

		/**
		 * Get information regarding our plugin from WordPress.
		 */
		private function init_plugin_data(): void {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$this->plugin_slug = plugin_basename( $this->plugin_file );
			$this->plugin_data = get_plugin_data( $this->plugin_file );
		}

		/**
		 * Get information regarding our plugin from GitHub.
		 */
		private function get_repository_info(): void {
			if ( ! empty( $this->github_api_result ) ) {
				return;
			}

			$url  = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases";
			$args = array(
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			);

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				return;
			}

			$response_body = wp_remote_retrieve_body( $response );
			$releases      = json_decode( $response_body );

			if ( ! is_array( $releases ) || empty( $releases ) ) {
				return;
			}

			$this->github_api_result = $releases[0];
		}

		/**
		 * Normalize version value (strip leading "v" if present).
		 *
		 * @param string $version Raw version string.
		 * @return string Normalized version string.
		 */
		private function normalize_version( string $version ): string {
			return ltrim( $version, 'vV' );
		}

		/**
		 * Push in plugin version information to get the update notification.
		 *
		 * @param object $transient Plugin update information.
		 * @return object Updated plugin update information.
		 */
		public function set_transient( $transient ) {
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			$this->init_plugin_data();
			$this->get_repository_info();

			if ( empty( $this->github_api_result ) ) {
				return $transient;
			}

			$tag_version    = $this->normalize_version( (string) $this->github_api_result->tag_name );
			$current_version = $this->normalize_version( (string) $this->plugin_data['Version'] );

			$do_update = version_compare( $tag_version, $current_version, '>' );

			if ( $do_update && ! empty( $this->github_api_result->assets ) ) {
				$package = $this->github_api_result->assets[0]->browser_download_url ?? '';

				if ( '' === $package ) {
					return $transient;
				}

				$obj              = new stdClass();
				$obj->slug        = $this->plugin_slug;
				$obj->new_version = $this->github_api_result->tag_name;
				$obj->url         = $this->plugin_data['PluginURI'] ?? '';
				$obj->package     = $package;

				$transient->response[ $this->plugin_slug ] = $obj;
			}

			return $transient;
		}

		/**
		 * Push in plugin version information to display in the details lightbox.
		 *
		 * @param object|bool $false Plugin information.
		 * @param string      $action Action.
		 * @param object      $response Response.
		 * @return object|bool Updated plugin information.
		 */
		public function set_plugin_info( $false, $action, $response ) {
			$this->init_plugin_data();
			$this->get_repository_info();

			if ( empty( $response->slug ) || $response->slug !== $this->plugin_slug ) {
				return $false;
			}

			$response->last_updated = $this->github_api_result->published_at ?? '';
			$response->slug         = $this->plugin_slug;
			$response->plugin_name  = $this->plugin_data['Name'] ?? '';
			$response->version      = $this->github_api_result->tag_name ?? '';
			$response->author       = $this->plugin_data['Author'] ?? '';
			$response->homepage     = $this->plugin_data['PluginURI'] ?? '';

			$response->sections = array(
				'description' => $this->plugin_data['Description'] ?? '',
			);

			if ( ! empty( $this->github_api_result->assets ) ) {
				$response->download_link = $this->github_api_result->assets[0]->browser_download_url ?? '';
			}

			return $response;
		}

		/**
		 * Perform additional actions to successfully install our plugin.
		 *
		 * @param bool  $true Install result.
		 * @param array $hook_extra Hook extra.
		 * @param array $result Install result data.
		 * @return array Updated install result data.
		 */
		public function post_install( $true, $hook_extra, $result ) {
			global $wp_filesystem;

			// English: Avoid deprecated dirname(null) error and only process if plugin_slug is set.
			// 日本語: dirname(null) の非推奨警告を避けるため plugin_slug がある時のみ処理する。
			if ( ! empty( $this->plugin_slug ) ) {
				$plugin_folder       = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );
				$result_destination  = $result['destination'] ?? '';
				if ( '' !== $result_destination ) {
					$wp_filesystem->move( $result_destination, $plugin_folder );
					$result['destination'] = $plugin_folder;
				}

				if ( is_plugin_active( $this->plugin_slug ) ) {
					activate_plugin( $this->plugin_slug );
				}
			}

			return $result;
		}
	}
}
