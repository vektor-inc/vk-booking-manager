<?php
/**
 * プラグイン削除（アンインストール）時の片付け。
 *
 * 仕様は docs/specification-uninstall.md を参照。
 *
 * このクラスはプラグイン本体が読み込まれていない状態（uninstall.php から）でも動くよう、
 * 他のクラスに依存しない。Google カレンダー連携のクラスだけは、ファイルがあるとき（Pro 版）に限り
 * 読み込んで、Google 側の連携許可を取り消すために使う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Uninstall;

use VKBookingManager\Common\Scheduled_Events;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 削除時の片付けを行う、状態を持たない静的ユーティリティクラス。
 */
class Uninstaller {

	/**
	 * 「プラグイン削除時の片付け」の選択肢: 設定や予約データを残す（Google 連携情報は削除する）。
	 */
	public const MODE_KEEP = 'keep';

	/**
	 * 「プラグイン削除時の片付け」の選択肢: プラグインが作ったデータをすべて削除する。
	 */
	public const MODE_DELETE_ALL = 'delete_all';

	/**
	 * BM 設定（Settings_Repository::OPTION_KEY と同じ値）の中で、片付けの選択肢を保存するキー。
	 */
	public const SETTINGS_KEY = 'uninstall_cleanup';

	/**
	 * BM 設定を保存する option 名（Settings_Repository::OPTION_KEY と同じ値）。
	 */
	public const SETTINGS_OPTION = 'vkbm_provider_settings';

	/**
	 * 無料版・Pro 版の両方が使うテキストドメイン。他のエディションがインストールされているかの判定に使う。
	 */
	public const TEXT_DOMAIN = 'vk-booking-manager';

	/**
	 * Google 連携の認証情報（暗号化したトークン）を保存する option 名
	 * （Google_Calendar_Connection::OPTION_NAME と同じ値）。
	 */
	public const GOOGLE_CONNECTION_OPTION = 'vkbm_google_calendar_connection';

	/**
	 * Pro 版のライセンスキーを保存する option 名。
	 */
	public const LICENSE_KEY_OPTION = 'vk-booking-manager-pro-license-key';

	/**
	 * Pro 版の自動更新ライブラリ（plugin-update-checker）が保存する option 名。
	 */
	public const UPDATE_CHECKER_OPTION = 'external_updates-vk-booking-manager-pro';

	/**
	 * Pro 版の自動更新ライブラリが登録する予定処理の名前。
	 */
	public const UPDATE_CHECKER_CRON_HOOK = 'puc_cron_check_updates-vk-booking-manager-pro';

	/**
	 * 無料版が Pro 版に置き換わったことの通知を覚えておく option 名
	 * （Free_Version_Deactivator::NOTICE_OPTION と同じ値）。
	 */
	public const FREE_VERSION_NOTICE_OPTION = 'vkbm_free_version_deactivated_notice';

	/**
	 * 名前の先頭にこの文字列が付く option・投稿メタ・ユーザーメタ・transient は、プラグインが作ったものとして扱う。
	 */
	public const DATA_PREFIX = 'vkbm_';

	/**
	 * 投稿メタの名前の先頭に付く文字列（隠しメタ）。
	 */
	public const HIDDEN_META_PREFIX = '_vkbm_';

	/**
	 * プラグインのカスタム投稿タイプ。
	 */
	public const POST_TYPES = array(
		'vkbm_booking',
		'vkbm_resource',
		'vkbm_shift',
		'vkbm_service_menu',
	);

	/**
	 * プラグインのタクソノミー。
	 */
	public const TAXONOMIES = array(
		'vkbm_service_menu_tag',
		'vkbm_service_menu_group',
		'vkbm_resource_tag',
	);

	/**
	 * プラグインが作るロール。
	 */
	public const ROLES = array(
		'provider_site_owner',
		'provider_owner',
	);

	/**
	 * 1 回の取得で処理する投稿・ユーザーなどの件数。
	 */
	private const BATCH_SIZE = 200;

	/**
	 * 削除する側の他に、無料版・Pro 版のもう一方がインストールされているかを返す。
	 *
	 * 無料版と Pro 版は設定・予約データ・Google 連携の認証情報を共有するため、もう一方が残っている間は
	 * 共有データを消してはいけない。プラグインの一覧は、一括削除で先に消したプラグインが残らないよう、
	 * キャッシュを捨ててから取り直す。
	 *
	 * @param string $own_basename 削除するプラグイン自身の basename（例: vk-booking-manager-pro/vk-booking-manager.php）。
	 * @return bool もう一方のエディションが残っていれば true。
	 */
	public static function has_other_edition_installed( string $own_basename ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		wp_clean_plugins_cache( false );

		return array() !== self::find_other_edition_plugins( $own_basename, get_plugins() );
	}

	/**
	 * インストール済みのプラグインから、削除する側以外の無料版・Pro 版を探す。
	 *
	 * フォルダ名は環境によって変わるため、フォルダ名ではなくテキストドメインで同じ製品かを判定する。
	 *
	 * @param string                              $own_basename 削除するプラグイン自身の basename。
	 * @param array<string, array<string, mixed>> $plugins      get_plugins() の戻り値（basename => ヘッダー情報）。
	 * @return array<int, string> もう一方のエディションの basename。
	 */
	public static function find_other_edition_plugins( string $own_basename, array $plugins ): array {
		$others = array();

		foreach ( $plugins as $basename => $header ) {
			if ( (string) $basename === $own_basename ) {
				continue;
			}

			$text_domain = isset( $header['TextDomain'] ) ? (string) $header['TextDomain'] : '';
			if ( self::TEXT_DOMAIN === $text_domain ) {
				$others[] = (string) $basename;
			}
		}

		return $others;
	}

	/**
	 * BM 設定の値から、削除時の片付けの選択肢を決める。
	 *
	 * 「すべて削除する」と明示されたときだけ削除側にする。未設定・不正な値は、データを守る側（残す）に倒す。
	 *
	 * @param mixed $settings BM 設定（option の値）。
	 * @return string MODE_KEEP または MODE_DELETE_ALL。
	 */
	public static function resolve_mode( $settings ): string {
		if ( is_array( $settings ) && isset( $settings[ self::SETTINGS_KEY ] ) && self::MODE_DELETE_ALL === $settings[ self::SETTINGS_KEY ] ) {
			return self::MODE_DELETE_ALL;
		}

		return self::MODE_KEEP;
	}

	/**
	 * 保存・表示する値を、許可された選択肢のどちらかに揃える。
	 *
	 * @param mixed $value 送信された値。
	 * @return string MODE_KEEP または MODE_DELETE_ALL。
	 */
	public static function normalize_mode( $value ): string {
		return self::MODE_DELETE_ALL === $value ? self::MODE_DELETE_ALL : self::MODE_KEEP;
	}

	/**
	 * 削除時の片付けを実行する。uninstall.php から呼ぶ。
	 *
	 * もう一方のエディションが残っているときは何もしない（共有データ・予定処理・Google 連携を守るため）。
	 * マルチサイトでは、ネットワーク内のすべてのサイトで同じ片付けを行う。
	 *
	 * @param string $own_basename 削除するプラグイン自身の basename。
	 * @param string $plugin_dir   削除するプラグインのフォルダ。一括削除で先に削除された方のフォルダを指さないよう、
	 *                             呼び出し元（uninstall.php）の場所を受け取る。空なら、このファイルがあるフォルダ。
	 * @return void
	 */
	public static function run( string $own_basename, string $plugin_dir = '' ): void {
		// もう一方のエディションが残っているなら、共有データを消さず Google 側の連携許可も取り消さない。
		if ( self::has_other_edition_installed( $own_basename ) ) {
			return;
		}

		// サイトごとの片付けを行い、各サイトの選択肢を集める。
		$modes = array();
		foreach ( self::get_site_ids() as $site_id ) {
			if ( is_multisite() ) {
				switch_to_blog( $site_id );
			}

			$modes[] = self::cleanup_current_site( $plugin_dir );

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		// ユーザーメタはネットワーク全体で共有されるため、全サイトが「すべて削除する」のときだけ消す。
		// 1 つでも「残す」のサイトがあれば、そのサイトの利用者情報を巻き込んで消さない。
		if ( array() !== $modes && array( self::MODE_DELETE_ALL ) === array_values( array_unique( $modes ) ) ) {
			self::delete_user_meta();
		}

		if ( is_multisite() ) {
			delete_site_option( self::FREE_VERSION_NOTICE_OPTION );
		}
	}

	/**
	 * 片付けの対象にするサイトの ID を返す。シングルサイトでは現在のサイトだけ。
	 *
	 * @return array<int, int> サイト ID の一覧。
	 */
	private static function get_site_ids(): array {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}

		$site_ids = array();
		$offset   = 0;

		// 大きなネットワークでも一度に取りすぎないよう、一定件数ずつ取得する。
		do {
			$batch = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::BATCH_SIZE,
					'offset' => $offset,
				)
			);

			foreach ( $batch as $site_id ) {
				$site_ids[] = (int) $site_id;
			}

			$offset       += self::BATCH_SIZE;
			$fetched_count = count( $batch );
		} while ( self::BATCH_SIZE === $fetched_count );

		return $site_ids;
	}

	/**
	 * 現在のサイトの片付けを行う。
	 *
	 * @param string $plugin_dir 削除するプラグインのフォルダ。空なら、このファイルがあるフォルダ。
	 * @return string このサイトで使った選択肢（MODE_KEEP または MODE_DELETE_ALL）。
	 */
	public static function cleanup_current_site( string $plugin_dir = '' ): string {
		$mode = self::resolve_mode( get_option( self::SETTINGS_OPTION, array() ) );

		// 選択肢に関わらず、Google 連携の認証情報は削除する（先に Google 側の連携許可を取り消す）。
		// ライセンスキーを使うため、ライセンスキーを消す前に行う。
		self::revoke_and_clear_google_connection( $plugin_dir );

		// 予定処理と一時的なデータ（transient・通知の記録）は、データではなく状態なので選択肢に関わらず消す。
		Scheduled_Events::clear_all();
		wp_unschedule_hook( self::UPDATE_CHECKER_CRON_HOOK );
		self::delete_transients();
		delete_option( self::FREE_VERSION_NOTICE_OPTION );

		if ( self::MODE_DELETE_ALL === $mode ) {
			self::delete_posts();
			self::delete_terms();
			self::delete_post_meta();
			self::delete_roles_and_capabilities();
			self::delete_options();

			// 本プラグインのフックが有効なまま投稿を削除すると、予約の削除に反応して予定処理
			// （Google カレンダー反映など）が登録し直されることがあるため、最後にもう一度解除する。
			Scheduled_Events::clear_all();
		}

		return $mode;
	}

	/**
	 * Google 側の連携許可を取り消し、このサイトに保存した Google 連携の認証情報を削除する。
	 *
	 * 取り消しが通信エラーなどで失敗しても、削除は止めずにこのサイトの認証情報を消す
	 * （取り消せないことでプラグインを削除できなくなるのを避けるため）。
	 * 無料版の配布物には Google 連携のクラスが無いため、その場合は取り消しを行えず認証情報の削除だけ行う。
	 *
	 * @param string $plugin_dir 削除するプラグインのフォルダ。空なら、このファイルがあるフォルダ。
	 * @return void
	 */
	private static function revoke_and_clear_google_connection( string $plugin_dir ): void {
		$plugin_dir = '' !== $plugin_dir ? untrailingslashit( $plugin_dir ) : dirname( __DIR__, 2 );
		$google_dir = $plugin_dir . '/src/integrations/google-calendar/';
		$files      = array(
			$google_dir . 'class-google-calendar-secret-store.php',
			$google_dir . 'class-google-calendar-connection.php',
			$google_dir . 'class-google-calendar-relay-client.php',
		);

		$is_available = true;
		foreach ( $files as $file ) {
			if ( ! file_exists( $file ) ) {
				$is_available = false;
			}
		}

		if ( $is_available ) {
			try {
				// 同名のクラスが別のフォルダから読み込み済みのとき（もう一方のエディションが有効、または一括削除の 2 つ目）に
				// 読み込み直すと Fatal エラーになるため、クラスが無いときだけ読み込む。
				$classes = array(
					Google_Calendar_Secret_Store::class,
					Google_Calendar_Connection::class,
					Google_Calendar_Relay_Client::class,
				);
				foreach ( $files as $index => $file ) {
					if ( ! class_exists( $classes[ $index ], false ) ) {
						require_once $file;
					}
				}

				$connection    = new Google_Calendar_Connection();
				$refresh_token = $connection->get_refresh_token();
				$relay_client  = new Google_Calendar_Relay_Client();

				if ( null !== $refresh_token && $relay_client->is_configured() ) {
					$relay_client->revoke( $refresh_token );
				}
			} catch ( \Throwable $e ) {
				// 取り消しの失敗は、削除を止める理由にしない。
				unset( $e );
			}
		}

		delete_option( self::GOOGLE_CONNECTION_OPTION );
	}

	/**
	 * プラグインが作った transient を削除する。
	 *
	 * @return void
	 */
	private static function delete_transients(): void {
		global $wpdb;

		$prefixes = array(
			'_transient_',
			'_transient_timeout_',
			'_site_transient_',
			'_site_transient_timeout_',
		);

		foreach ( $prefixes as $prefix ) {
			$like  = $wpdb->esc_like( $prefix . self::DATA_PREFIX ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $names as $name ) {
				// 有効期限の記録（timeout）は本体の transient と一緒に消えるため、本体側の名前だけ扱う。
				if ( 0 === strpos( $name, '_transient_timeout_' ) || 0 === strpos( $name, '_site_transient_timeout_' ) ) {
					continue;
				}

				if ( 0 === strpos( $name, '_site_transient_' ) ) {
					delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
				} else {
					delete_transient( substr( $name, strlen( '_transient_' ) ) );
				}
			}
		}
	}

	/**
	 * プラグインのカスタム投稿タイプの投稿を、ごみ箱を経由せずにすべて削除する。
	 *
	 * 投稿メタ・タームとの紐づきは wp_delete_post() が一緒に消す。
	 * 予約者がアップロードした添付ファイルは、予約と一緒に削除する。
	 *
	 * @return void
	 */
	private static function delete_posts(): void {
		self::delete_uploaded_booking_attachments();

		foreach ( self::POST_TYPES as $post_type ) {
			do {
				$post_ids = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => array_keys( get_post_stati() ),
						'numberposts'      => self::BATCH_SIZE,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'no_found_rows'    => true,
						'suppress_filters' => true,
					)
				);

				$fetched_count = count( $post_ids );
				$deleted       = 0;
				foreach ( $post_ids as $post_id ) {
					if ( false !== wp_delete_post( (int) $post_id, true ) ) {
						++$deleted;
					}
				}

				// 他のプラグインの処理で削除を止められた投稿だけが残ったとき、同じ投稿を取り続けて終わらなくなるのを防ぐ。
			} while ( $deleted > 0 && self::BATCH_SIZE === $fetched_count );
		}
	}

	/**
	 * 予約者が予約画面からアップロードした添付ファイルを、ファイルごと削除する。
	 *
	 * メディアライブラリにある他の画像（サービスメニューのサブ画像など）は、利用者が管理する画像のため削除しない。
	 *
	 * @return void
	 */
	private static function delete_uploaded_booking_attachments(): void {
		do {
			$attachment_ids = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'any',
					'numberposts'      => self::BATCH_SIZE,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => '_vkbm_booking_uploaded_attachment', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);

			$fetched_count = count( $attachment_ids );
			$deleted       = 0;
			foreach ( $attachment_ids as $attachment_id ) {
				if ( false !== wp_delete_attachment( (int) $attachment_id, true ) ) {
					++$deleted;
				}
			}
		} while ( $deleted > 0 && self::BATCH_SIZE === $fetched_count );
	}

	/**
	 * プラグインのタクソノミーのタームをすべて削除する（ターム説明・ターム順などのタームメタも一緒に消える）。
	 *
	 * 削除の時点ではプラグインが読み込まれておらずタクソノミーが登録されていないため、削除用に仮登録する。
	 *
	 * @return void
	 */
	private static function delete_terms(): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, 'post', array( 'public' => false ) );
			}

			$term_ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( ! is_array( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}

	/**
	 * プラグインが付けた投稿メタのうち、投稿の削除で消えずに残ったものを削除する。
	 *
	 * 通常の投稿など、プラグインの投稿タイプ以外にも付けたメタ（名前が _vkbm_ で始まるもの）が対象。
	 *
	 * @return void
	 */
	private static function delete_post_meta(): void {
		global $wpdb;

		$like     = $wpdb->esc_like( self::HIDDEN_META_PREFIX ) . '%';
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// 直接削除したため、キャッシュに残った投稿メタを捨てる。
		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
	}

	/**
	 * プラグインのロールと、他のロールに付けた権限を削除する。
	 *
	 * プラグインのロールを持っていた利用者は、サイトの新規ユーザーの既定ロールに付け替える
	 * （ロールが無いままだと管理画面に入れなくなるため）。
	 *
	 * @return void
	 */
	private static function delete_roles_and_capabilities(): void {
		// 既定のロールがプラグインのロール、または存在しないロールのときは、付け替え先を subscriber にする。
		$default_role = (string) get_option( 'default_role', 'subscriber' );
		if ( in_array( $default_role, self::ROLES, true ) || null === get_role( $default_role ) ) {
			$default_role = 'subscriber';
		}

		foreach ( self::ROLES as $role_name ) {
			$user_ids = get_users(
				array(
					'role'   => $role_name,
					'fields' => 'ID',
				)
			);

			foreach ( $user_ids as $user_id ) {
				$user = new \WP_User( (int) $user_id );
				$user->remove_role( $role_name );

				if ( array() === $user->roles ) {
					$user->add_role( $default_role );
				}
			}

			remove_role( $role_name );
		}

		// 管理者・編集者など、既存のロールに付けた権限（vkbm_ で始まるもの）を外す。
		$wp_roles = wp_roles();
		foreach ( $wp_roles->role_objects as $role ) {
			foreach ( array_keys( $role->capabilities ) as $capability ) {
				if ( 0 === strpos( (string) $capability, self::DATA_PREFIX ) ) {
					$role->remove_cap( (string) $capability );
				}
			}
		}
	}

	/**
	 * プラグインが作った option を削除する。
	 *
	 * 設定値・メールログ・顧客フラグの種別・並び順や空き状況キャッシュの世代番号・各種のロックや通知の記録・
	 * ロールの同期の目印・ライセンスキー・自動更新ライブラリの記録が対象。
	 *
	 * @return void
	 */
	private static function delete_options(): void {
		global $wpdb;

		$like  = $wpdb->esc_like( self::DATA_PREFIX ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$names[] = self::LICENSE_KEY_OPTION;
		$names[] = self::UPDATE_CHECKER_OPTION;

		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}
	}

	/**
	 * プラグインが付けたユーザーメタ（名前が vkbm_ または _vkbm_ で始まるもの）を削除する。
	 *
	 * 会員の氏名・フリガナ・生年月日・メール認証の記録・顧客フラグ・お気に入り・通知を閉じた記録が対象。
	 * 電話番号・性別は WordPress の標準的な名前（phone_number・gender）で保存しており、他のプラグインが
	 * 同じ名前を使っている可能性があるため削除しない。
	 *
	 * @return void
	 */
	private static function delete_user_meta(): void {
		global $wpdb;

		$like        = $wpdb->esc_like( self::DATA_PREFIX ) . '%';
		$hidden_like = $wpdb->esc_like( self::HIDDEN_META_PREFIX ) . '%';
		$user_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $like, $hidden_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $like, $hidden_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// 直接削除したため、キャッシュに残ったユーザーメタを捨てる。
		foreach ( $user_ids as $user_id ) {
			wp_cache_delete( (int) $user_id, 'user_meta' );
		}
	}
}
