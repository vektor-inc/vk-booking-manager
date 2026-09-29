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
		 * Expected asset filename.
		 *
		 * @var string
		 */
		private $asset_filename = 'vk-booking-manager.zip';

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
		 * WordPress のプラグイン一覧は、対象プラグインが $transient->response（更新あり）
		 * または $transient->no_update（更新なし）のどちらかに登録されていないと
		 * 「自動更新」欄自体を表示しない（class-wp-plugins-list-table.php 参照）。
		 * そのため、更新が無い場合も no_update 側へ明示的に登録する。
		 *
		 * @param object $transient Plugin update information.
		 * @return object Updated plugin update information.
		 */
		public function set_transient( $transient ) {
			// $transient がオブジェクトでない、または response / no_update プロパティが
			// 無い場合に備えてガードする（plugin-update-checker の実装に合わせる）。
			if ( ! is_object( $transient ) ) {
				return $transient;
			}
			if ( empty( $transient->checked ) ) {
				return $transient;
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$this->init_plugin_data();
			$this->get_repository_info();

			$current_version = $this->normalize_version( (string) $this->plugin_data['Version'] );

			// GitHub API の取得に失敗した場合も自動更新欄が消えないよう、
			// 現在のバージョンのまま no_update に登録しておく（更新の有無が
			// 確認できないだけで、プラグイン自体は正常に動作しているため）。
			if ( empty( $this->github_api_result ) ) {
				// response と no_update の両方に同時登録されないことをコード上で保証するための防御。
				// 現状の分岐は排他的で両方に載る手順は確認できていないが、
				// 今後の分岐追加で崩れても片方には確実に載る状態を保つ。
				unset( $transient->response[ $this->plugin_slug ] );
				$transient->no_update[ $this->plugin_slug ] = $this->build_no_update_item( $current_version );
				return $transient;
			}

			$tag_version = $this->normalize_version( (string) $this->github_api_result->tag_name );
			$do_update   = version_compare( $tag_version, $current_version, '>' );

			if ( $do_update && ! empty( $this->github_api_result->assets ) ) {
				$package = $this->find_asset_package( $this->github_api_result->assets );

				if ( '' !== $package ) {
					$obj              = new stdClass();
					$obj->slug        = $this->plugin_slug;
					$obj->plugin      = $this->plugin_slug;
					$obj->new_version = $this->github_api_result->tag_name;
					$obj->url         = $this->plugin_data['PluginURI'] ?? '';
					$obj->package     = $package;

					// response と no_update の両方に同時登録されないことをコード上で保証するための防御。
					// 現状の分岐は排他的で両方に載る手順は確認できていないが、
					// 今後の分岐追加で崩れても片方には確実に載る状態を保つ。
					unset( $transient->no_update[ $this->plugin_slug ] );
					$transient->response[ $this->plugin_slug ] = $obj;

					return $transient;
				}
			}

			// 更新が無い、またはパッケージが見つからない場合は no_update に登録する。
			// response と no_update の両方に同時登録されないことをコード上で保証するための防御。
			// 現状の分岐は排他的で両方に載る手順は確認できていないが、
			// 今後の分岐追加で崩れても片方には確実に載る状態を保つ。
			unset( $transient->response[ $this->plugin_slug ] );
			$transient->no_update[ $this->plugin_slug ] = $this->build_no_update_item( $current_version );

			return $transient;
		}

		/**
		 * no_update に登録するプラグイン情報オブジェクトを組み立てる。
		 *
		 * plugin-update-checker の addNoUpdateItem() / getNoUpdateItemFields() が
		 * 生成する項目に倣い、WordPress が期待するフィールドを埋める。
		 * package を空文字にすることで「更新パッケージが無い＝最新」を表す。
		 *
		 * @param string $current_version 現在のプラグインバージョン.
		 * @return object no_update に登録するオブジェクト。
		 */
		private function build_no_update_item( string $current_version ) {
			$obj                = new stdClass();
			$obj->id            = $this->plugin_slug;
			$obj->slug          = $this->plugin_slug;
			$obj->plugin        = $this->plugin_slug;
			$obj->new_version   = $current_version;
			$obj->url           = $this->plugin_data['PluginURI'] ?? '';
			$obj->package       = '';
			$obj->icons         = array();
			$obj->banners       = array();
			$obj->banners_rtl   = array();
			$obj->tested        = '';
			$obj->requires_php  = '';
			$obj->compatibility = new stdClass();

			return $obj;
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
				$response->download_link = $this->find_asset_package( $this->github_api_result->assets );
			}

			return $response;
		}

		/**
		 * Find the package URL by asset filename.
		 *
		 * @param array $assets GitHub release assets.
		 * @return string Package URL or empty string.
		 */
		private function find_asset_package( array $assets ): string {
			foreach ( $assets as $asset ) {
				$name = is_object( $asset ) && isset( $asset->name ) ? (string) $asset->name : '';
				if ( $name === $this->asset_filename && isset( $asset->browser_download_url ) ) {
					return (string) $asset->browser_download_url;
				}
			}

			return '';
		}

		/**
		 * Perform additional actions to successfully install our plugin.
		 *
		 * upgrader_post_install は本プラグイン専用のフックではなく、サイト上の
		 * すべてのプラグイン・テーマのインストール／更新で発火する WordPress コア共通フックのため、
		 * $hook_extra（今回インストール・更新された対象を示す情報。プラグインなら
		 * $hook_extra['plugin']、テーマなら $hook_extra['theme'] にスラッグが入る）を見て、
		 * 今回の対象が本プラグイン自身かどうかを判定してから処理する。
		 * 対象が本プラグイン以外（他のプラグイン・テーマの更新）の場合は、
		 * ファイル移動・再有効化を一切行わず $result をそのまま返し、他の更新処理に干渉しない。
		 *
		 * @param bool  $true Install result.
		 * @param array $hook_extra Hook extra.
		 * @param array $result Install result data.
		 * @return array Updated install result data.
		 */
		public function post_install( $true, $hook_extra, $result ) {
			global $wp_filesystem;

			// plugin_slug は init_plugin_data() 等を呼ぶまで未セットのため、
			// $hook_extra との比較前に必ずセットしておく
			// （set_transient() 等が同一リクエスト内で先に呼ばれているとは限らないため）。
			// upgrader_post_install はパッケージ展開後、WordPress がファイルを
			// 既存のプラグインフォルダへ移動した「後」に発火するため、この時点では
			// $this->plugin_file（コンストラクタで受け取った元のファイルパス）が
			// 既に存在しない可能性がある。init_plugin_data() は内部で get_plugin_data()
			// を呼びファイルを fopen() で読み込むため、ここではファイル読み込みを
			// 伴わない plugin_basename() のみでスラッグを設定する。
			$this->plugin_slug = plugin_basename( $this->plugin_file );

			// $hook_extra はプラグインなら 'plugin' キー、テーマなら 'theme' キーにスラッグが入る。
			// いずれのキーも無い場合は空文字とし、本プラグインのスラッグとは一致しない値にする。
			$target_slug = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : ( isset( $hook_extra['theme'] ) ? $hook_extra['theme'] : '' );

			// 今回の対象が本プラグイン自身でなければ、ここで処理を打ち切って $result をそのまま返す。
			// plugin_slug が未取得（空）の場合も、誤って他の対象を移動しないよう同様に打ち切る。
			if ( empty( $this->plugin_slug ) || $target_slug !== $this->plugin_slug ) {
				return $result;
			}

			// dirname(null) の非推奨警告を避けるため、result destination が空でない場合のみ移動する。
			$plugin_folder      = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );
			$result_destination = $result['destination'] ?? '';
			if ( '' !== $result_destination ) {
				$wp_filesystem->move( $result_destination, $plugin_folder );
				$result['destination'] = $plugin_folder;
			}

			if ( is_plugin_active( $this->plugin_slug ) ) {
				activate_plugin( $this->plugin_slug );
			}

			return $result;
		}
	}
}
