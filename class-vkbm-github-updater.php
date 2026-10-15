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
		 * $github_api_result が transient（保存済みの値）由来かどうか。
		 *
		 * @var bool
		 */
		private $github_api_from_cache = false;

		/**
		 * 最新リリース情報を保存する transient のキー。
		 *
		 * @var string
		 */
		const RELEASE_TRANSIENT_KEY = 'vkbm_github_updater_release';

		/**
		 * 最新リリース情報の transient の保存秒数。
		 *
		 * @var int
		 */
		const RELEASE_TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

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
		 *
		 * 同一リクエスト内では取得済みの結果（$this->github_api_result）を再利用する。
		 * ただし transient 由来の結果は、$use_cache が false の呼び出し（定期の更新確認）では
		 * 再利用せず、GitHub へ問い合わせ直す。
		 * $use_cache が true のときは、GitHub へ問い合わせる前に transient を確認する。
		 * 取得に成功した場合は、どちらの経路でも transient に保存する。
		 *
		 * @param bool $use_cache true なら transient に保存済みのリリース情報があればそれを使う。
		 */
		private function get_repository_info( bool $use_cache = false ): void {
			if ( ! empty( $this->github_api_result ) ) {
				// 保存済みの値をメモしている状態で最新の取得を求められた場合は、メモを捨てる。
				if ( $use_cache || ! $this->github_api_from_cache ) {
					return;
				}
				$this->github_api_result     = null;
				$this->github_api_from_cache = false;
			}

			if ( $use_cache ) {
				$cached_release = $this->get_cached_release();
				if ( null !== $cached_release ) {
					$this->github_api_result     = $cached_release;
					$this->github_api_from_cache = true;
					return;
				}
			}

			$url  = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases?per_page=100";
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

			// ベータ版・下書きを除いた中で、バージョン番号が最大のリリースを採用する。
			$latest_release = $this->select_latest_release( $releases );
			if ( null === $latest_release ) {
				return;
			}

			// 保存・参照するのは必要な項目だけに詰め直したものにする。
			$compact_release = $this->compact_release( $latest_release );

			$this->github_api_result     = $compact_release;
			$this->github_api_from_cache = false;

			// 取得に成功したときだけ保存する（失敗を保存すると、復旧後も古い失敗が残るため）。
			// update_plugins はマルチサイトではネットワーク全体の site transient のため、こちらも揃える。
			set_site_transient( self::RELEASE_TRANSIENT_KEY, $compact_release, self::RELEASE_TRANSIENT_TTL );
		}

		/**
		 * リリース情報から、本プラグインが使う項目（tag_name・published_at・自分の zip）だけを残す。
		 *
		 * @param object $release select_latest_release() で選んだリリース。
		 * @return object tag_name・published_at・assets（自分の zip のみ）を持つオブジェクト。
		 */
		private function compact_release( object $release ): object {
			$compact               = new stdClass();
			$compact->tag_name     = (string) $release->tag_name;
			$compact->published_at = isset( $release->published_at ) && is_string( $release->published_at ) ? $release->published_at : '';
			$compact->assets       = array();

			if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
				$package = $this->find_asset_package( $release->assets );
				if ( '' !== $package ) {
					$asset                       = new stdClass();
					$asset->name                 = $this->asset_filename;
					$asset->browser_download_url = $package;
					$compact->assets[]           = $asset;
				}
			}

			return $compact;
		}

		/**
		 * transient に保存済みの最新リリース情報を取り出す。
		 *
		 * 壊れた値（オブジェクトでない・tag_name が空・published_at が文字列でない・
		 * assets が配列でない、または assets の要素の name・URL が不正）は無いものとして扱い、
		 * 呼び出し側が GitHub へ問い合わせ直せるよう null を返す。
		 *
		 * @return object|null 有効なリリース情報。無い・不正なら null。
		 */
		private function get_cached_release(): ?object {
			$cached = get_site_transient( self::RELEASE_TRANSIENT_KEY );

			if ( ! is_object( $cached ) || empty( $cached->tag_name ) || ! is_string( $cached->tag_name ) ) {
				return null;
			}

			if ( isset( $cached->published_at ) && ! is_string( $cached->published_at ) ) {
				return null;
			}

			if ( isset( $cached->assets ) ) {
				if ( ! is_array( $cached->assets ) ) {
					return null;
				}
				foreach ( $cached->assets as $asset ) {
					if (
						! is_object( $asset )
						|| ! isset( $asset->name ) || ! is_string( $asset->name )
						|| ! isset( $asset->browser_download_url ) || ! $this->is_valid_package_url( $asset->browser_download_url )
					) {
						return null;
					}
				}
			}

			return $cached;
		}

		/**
		 * リリース一覧から、ベータ版（prerelease）・下書き（draft）を除いた最大バージョンのリリースを選ぶ。
		 *
		 * 一覧の並びは作成日時順のため、先頭を採用せず version_compare で最大のものを選ぶ。
		 *
		 * @param array $releases GitHub のリリース一覧（json_decode 後の配列）。
		 * @return object|null 採用するリリース。該当が無ければ null。
		 */
		private function select_latest_release( array $releases ): ?object {
			$latest_release = null;
			$latest_version = '';

			foreach ( $releases as $release ) {
				// オブジェクトでない要素・タグ名の無い要素は対象外。
				if ( ! is_object( $release ) || empty( $release->tag_name ) || ! is_string( $release->tag_name ) ) {
					continue;
				}

				// ベータ版・下書きは対象外。
				if ( ! empty( $release->prerelease ) || ! empty( $release->draft ) ) {
					continue;
				}

				$version = $this->normalize_version( $release->tag_name );
				if ( null === $latest_release || version_compare( $version, $latest_version, '>' ) ) {
					$latest_release = $release;
					$latest_version = $version;
				}
			}

			return $latest_release;
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
			// 定期の更新確認は、最新の状態を知るため transient を使わず毎回問い合わせる。
			$this->get_repository_info( false );

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
			// plugins_api は他のプラグイン・テーマの情報取得でも呼ばれるため、
			// 詳細表示以外の action では GitHub に問い合わせない。
			if ( 'plugin_information' !== $action ) {
				return $false;
			}

			// slug の判定を GitHub API 呼び出しより前に行い、他のプラグインのときは問い合わせない。
			$this->init_plugin_data();
			if ( ! is_object( $response ) || empty( $response->slug ) || $response->slug !== $this->plugin_slug ) {
				return $false;
			}

			// 詳細表示は transient のリリース情報で足りる。無いときだけ GitHub へ問い合わせる。
			// 取得に失敗した場合はバージョンとダウンロードリンクを空にする（失敗は保存せず、次回また問い合わせる）。
			$this->get_repository_info( true );

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
				if ( $name === $this->asset_filename && isset( $asset->browser_download_url ) && $this->is_valid_package_url( $asset->browser_download_url ) ) {
					return $asset->browser_download_url;
				}
			}

			return '';
		}

		/**
		 * ダウンロード URL が、文字列・https・github.com ホストのすべてを満たすか確認する。
		 *
		 * wp_http_validate_url() は名前解決を行い、DNS 制限下で更新通知が消えるため使わない。
		 *
		 * @param mixed $url 確認する値。
		 * @return bool 満たす場合 true。
		 */
		private function is_valid_package_url( $url ): bool {
			if ( ! is_string( $url ) || '' === $url ) {
				return false;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
				return false;
			}

			return 'https' === $parts['scheme'] && 'github.com' === strtolower( $parts['host'] );
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
