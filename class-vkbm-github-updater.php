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
		 * WordPress の更新情報に渡す対応バージョンの項目名と、readme.txt の見出し名の対応。
		 *
		 * 更新あり・更新なし・詳細ポップアップの 3 か所すべてに、ここに並べた項目をまとめて渡す。
		 * 渡す項目を増減するときは、ここだけを変える。
		 *
		 * @var array<string,string>
		 */
		const README_REQUIREMENT_HEADERS = array(
			'tested'       => 'Tested up to',
			'requires_php' => 'Requires PHP',
			'requires'     => 'Requires at least',
		);

		/**
		 * readme.txt から採用する対応バージョンの形式（数字とドットのみ。例: 8.3・6.9.1）。
		 *
		 * @var string
		 */
		const REQUIREMENT_VERSION_PATTERN = '/^\d+(?:\.\d+){0,2}\z/';

		/**
		 * readme.txt の URL に埋め込んでよいタグ名の形式（先頭の v は可。数字とドットのみ）。
		 *
		 * 文字列の終わりは \z で判定する（$ は末尾の改行にも一致するため、$ に戻さないこと）。
		 *
		 * @var string
		 */
		const README_TAG_NAME_PATTERN = '/^[vV]?\d+(?:\.\d+){0,3}\z/';

		/**
		 * タグ時点の readme.txt を取得するときに読む最大のバイト数。
		 *
		 * 見出しはファイルの先頭にあるため、WordPress の get_file_data() と同じく先頭の 8KB だけを読む。
		 * readme.txt は更新履歴で大きくなっていくため、ファイル全体の大きさで取得を諦める形にはしない。
		 *
		 * @var int
		 */
		const README_MAX_BYTES = 8 * KB_IN_BYTES;

		/**
		 * タグ時点の readme.txt を取得するときの待ち時間（秒）。
		 *
		 * @var int
		 */
		const README_REQUEST_TIMEOUT = 5;

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
		 * GitHub へ新しく問い合わせたときだけ、タグ時点の readme.txt の対応バージョンも用意して一緒に保存する。
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

			// 新しいバージョンの対応バージョン（タグ時点の readme.txt の値）を一緒に保存する。
			// GitHub への問い合わせを増やさないよう、取得はリリース情報を新しく取ったこの経路だけで行う（#710）。
			$compact_release->requirements = $this->prepare_release_requirements( $compact_release->tag_name, $this->get_cached_release() );

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
		 * requirements が配列でない・assets が配列でない、または assets の要素の name・URL が不正）は
		 * 無いものとして扱い、呼び出し側が GitHub へ問い合わせ直せるよう null を返す。
		 * 更新前のバージョンが保存した requirements の無い値は、壊れた値として扱わない（対応バージョンなしとして読む）。
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

			if ( isset( $cached->requirements ) && ! is_array( $cached->requirements ) ) {
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
		 * 今回採用したリリースの対応バージョンを用意する。
		 *
		 * 前回保存したリリース情報が同じタグで、取得に成功した対応バージョンを持っていればそれを使い回し、
		 * そうでなければタグ時点の readme.txt を取りに行く。
		 * 前回の取得失敗（requirements が null）や、更新前のバージョンが保存した値（requirements が無い）は
		 * 使い回さないため、次の定期確認で取り直せる。
		 *
		 * @param string      $tag_name         今回採用したリリースのタグ名。
		 * @param object|null $previous_release transient に保存済みのリリース情報（無ければ null）。
		 * @return array|null 項目名をキーにした対応バージョン。取得できなかった場合は null。
		 */
		private function prepare_release_requirements( string $tag_name, ?object $previous_release ): ?array {
			if (
				null !== $previous_release
				&& $previous_release->tag_name === $tag_name
				&& isset( $previous_release->requirements )
				&& is_array( $previous_release->requirements )
			) {
				return $this->normalize_requirements( $previous_release->requirements );
			}

			return $this->fetch_release_requirements( $tag_name );
		}

		/**
		 * 無料版リポジトリから、リリースのタグ時点の readme.txt を取得して対応バージョンを取り出す。
		 *
		 * raw.githubusercontent.com から取るため、GitHub API の回数上限は使わない。
		 * 取得・読み取りに失敗しても更新の案内は止めず、呼び出し側は値なしとして扱う。
		 *
		 * @param string $tag_name リリースのタグ名。
		 * @return array|null 項目名をキーにした対応バージョン。取得できない・値が 1 つも無い場合は null。
		 */
		private function fetch_release_requirements( string $tag_name ): ?array {
			// タグ名は URL に埋め込むため、バージョン形式のものだけを通す。
			if ( 1 !== preg_match( self::README_TAG_NAME_PATTERN, $tag_name ) ) {
				return null;
			}

			$url      = "https://raw.githubusercontent.com/{$this->username}/{$this->repo}/{$tag_name}/readme.txt";
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => self::README_REQUEST_TIMEOUT,
					'limit_response_size' => self::README_MAX_BYTES,
					// 圧縮した本文を途中で切ると展開できず見出しを読めない通信方式（curl 拡張が無いサイト）があるため、圧縮なしで受け取る。
					'headers'             => array( 'Accept-Encoding' => 'identity' ),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			// 通信の仕組みによっては上限を超えて返すことがあるため、読む範囲をここでも先頭に絞る。
			$readme       = substr( wp_remote_retrieve_body( $response ), 0, self::README_MAX_BYTES );
			$requirements = $this->parse_readme_requirements( $readme );

			// 1 つも値が取れなければ失敗として扱い、保存せずに次の定期確認で取り直す。
			if ( '' === implode( '', $requirements ) ) {
				return null;
			}

			return $requirements;
		}

		/**
		 * readme.txt の本文から対応バージョンの見出しの値を取り出す。
		 *
		 * 見出しの探し方は WordPress の get_file_data() に合わせる（行頭の記号を許し、大文字小文字を区別しない）。
		 *
		 * @param string $readme readme.txt の本文（先頭部分）。
		 * @return array 項目名をキーにした対応バージョン（形式に合わない・無い値は空文字）。
		 */
		private function parse_readme_requirements( string $readme ): array {
			$readme = str_replace( "\r", "\n", $readme );
			$values = array();

			foreach ( self::README_REQUIREMENT_HEADERS as $key => $header ) {
				$values[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $readme, $match ) ? $match[1] : '';
			}

			return $this->normalize_requirements( $values );
		}

		/**
		 * インストール済み（今のバージョン）の readme.txt から対応バージョンを読む。
		 *
		 * @return array 項目名をキーにした対応バージョン（readme.txt が無い・形式に合わない値は空文字）。
		 */
		private function get_installed_requirements(): array {
			$readme_file = dirname( $this->plugin_file ) . '/readme.txt';

			// get_file_data() は無いファイルを読むと警告を出すため、先に確認する。
			if ( ! is_readable( $readme_file ) ) {
				return $this->normalize_requirements( array() );
			}

			return $this->normalize_requirements( get_file_data( $readme_file, self::README_REQUIREMENT_HEADERS ) );
		}

		/**
		 * 対応バージョンを、決まった項目がそろい、バージョン形式の値だけが入った配列にそろえる。
		 *
		 * 外から取った値（readme.txt・transient）は必ずここを通し、形式に合わない値は空文字にする。
		 *
		 * @param array $values 項目名をキーにした値。
		 * @return array README_REQUIREMENT_HEADERS の項目名をすべて持つ配列。
		 */
		private function normalize_requirements( array $values ): array {
			$requirements = array();

			foreach ( array_keys( self::README_REQUIREMENT_HEADERS ) as $key ) {
				$value                = isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? trim( $values[ $key ] ) : '';
				$requirements[ $key ] = 1 === preg_match( self::REQUIREMENT_VERSION_PATTERN, $value ) ? $value : '';
			}

			return $requirements;
		}

		/**
		 * 取得済みのリリース情報から、新しいバージョンの対応バージョンを取り出す。
		 *
		 * リリース情報が無い場合や、更新前のバージョンが保存した requirements の無い値の場合は、値なし（空文字）を返す。
		 *
		 * @return array 項目名をキーにした対応バージョン。
		 */
		private function get_release_requirements(): array {
			$stored = isset( $this->github_api_result->requirements ) && is_array( $this->github_api_result->requirements )
				? $this->github_api_result->requirements
				: array();

			return $this->normalize_requirements( $stored );
		}

		/**
		 * WordPress に渡すプラグイン情報のオブジェクトに、対応バージョンの項目を書き込む。
		 *
		 * 更新あり・更新なし・詳細ポップアップの 3 か所はすべてここを通る。
		 * tested のパッチ番号は、保存した値ではなく渡す時点の WordPress に合わせて補う。
		 *
		 * @param object $item         更新情報・詳細情報のオブジェクト。
		 * @param array  $requirements 項目名をキーにした対応バージョン。
		 */
		private function set_requirement_fields( object $item, array $requirements ): void {
			foreach ( $requirements as $key => $value ) {
				if ( 'tested' === $key ) {
					$value = $this->complete_tested_patch_version( $value );
				}
				$item->{$key} = $value;
			}
		}

		/**
		 * major.minor で書かれた「Tested up to」に、今の WordPress と同じ系列のパッチ番号を補う。
		 *
		 * WordPress は tested をパッチ番号まで含む今のバージョンと version_compare で比べるため、
		 * 7.1 のままでは 7.1.4 のサイトで互換性が「未確認」になる。plugin-update-checker の
		 * fixSupportedWordpressVersion() に倣い、今のバージョンと用意されている本体の更新のうち、
		 * 同じ系列で最大のパッチ番号を使う。同じ系列が無い・パッチ番号付きで書かれている場合はそのまま返す。
		 *
		 * @param string $tested 「Tested up to」の値（バージョン形式または空文字）。
		 * @return string パッチ番号を補った値。
		 */
		private function complete_tested_patch_version( string $tested ): string {
			if ( 1 !== preg_match( '/^\d+\.\d+\z/', $tested ) ) {
				return $tested;
			}

			// 今の WordPress と、用意されている本体の更新のバージョン（get_core_updates() は管理画面でだけ読み込まれる）。
			$wp_versions = array( (string) get_bloginfo( 'version' ) );
			if ( function_exists( 'get_core_updates' ) ) {
				$core_updates = get_core_updates();
				if ( is_array( $core_updates ) ) {
					foreach ( $core_updates as $core_update ) {
						if ( is_object( $core_update ) && isset( $core_update->current ) && is_string( $core_update->current ) ) {
							$wp_versions[] = $core_update->current;
						}
					}
				}
			}

			// 同じ系列（major.minor が一致）のうち最大のパッチ番号を探す。
			$patch = 0;
			foreach ( $wp_versions as $wp_version ) {
				if ( 1 === preg_match( '/^(\d+\.\d+)(?:\.(\d+))?/', $wp_version, $parts ) && $parts[1] === $tested ) {
					$patch = max( $patch, isset( $parts[2] ) ? (int) $parts[2] : 0 );
				}
			}

			return $patch > 0 ? $tested . '.' . $patch : $tested;
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

					// 互換性の表示と、PHP・WordPress の下限を満たさないサイトで更新を止める判断に使われる。
					$this->set_requirement_fields( $obj, $this->get_release_requirements() );

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
			$obj->compatibility = new stdClass();

			// 更新が無いときは、インストール済みのバージョンの対応バージョンを渡す。
			$this->set_requirement_fields( $obj, $this->get_installed_requirements() );

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

			// 表示するバージョン（新しいバージョン）の対応バージョン。保存済みの値を使い、ここでは取りに行かない。
			$this->set_requirement_fields( $response, $this->get_release_requirements() );

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
