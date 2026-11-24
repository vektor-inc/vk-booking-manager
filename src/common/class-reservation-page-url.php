<?php
/**
 * 予約ページ（BM 設定）のリンク先を決め、使えない状態を判定する共通処理。
 *
 * 予約ページは固定ページの選択（reservation_page_id）か、「その他」の URL（reservation_page_url）で設定される。
 * サイトのドメインを移したあとに URL が旧ドメインのまま残ると、予約ボタンなどが警告なく旧ドメインへ
 * 移動してしまうため、予約ページのリンク先を使う全箇所がここを通る（#1049・#1050）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use function esc_html;
use function esc_html__;
use function __;
use function admin_url;
use function apply_filters;
use function current_user_can;
use function get_edit_post_link;
use function array_filter;
use function array_values;
use function esc_url_raw;
use function get_permalink;
use function home_url;
use function function_exists;
use function idn_to_ascii;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_replace;
use function rtrim;
use function site_url;
use function sprintf;
use function strtolower;
use function trim;
use function vkbm_normalize_reservation_page_url;
use function wp_parse_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 *
 * 判定は「ホスト名」だけで行う。誤って正しい自サイトURLを弾くと予約ボタンが消えるため、
 * 次の違いは同じサイトとして扱う（弾かない）。
 * - http / https の違い
 * - ポート番号の違い
 * - 先頭の www. の有無
 * - 大文字小文字・ホスト末尾のドット
 * - サブディレクトリ・パス・末尾スラッシュ・クエリ
 * - home_url() と site_url() のどちらのホストでも可
 * - `allowed_redirect_hosts` フィルターで許可されたホスト（WPML・Polylang の言語別ドメイン等が追加する）
 */
class Reservation_Page_Url {

	/**
	 * 設定のキー: 予約ページとして選んだ固定ページの ID（0 は未選択。URL の設定を使う）。
	 */
	private const SETTING_PAGE_ID = 'reservation_page_id';

	/**
	 * 設定のキー: 「その他（URL を入力）」の予約ページ URL。
	 */
	private const SETTING_URL = 'reservation_page_url';

	/**
	 * 警告の種類: 「その他」の URL がこのサイトのドメインでない（ドメインを移す前の URL のまま等）。
	 */
	public const PROBLEM_MISMATCH = 'mismatch';

	/**
	 * 設定から、リンク先・移動先に使える予約ページの URL を返す。
	 *
	 * 固定ページを選んでいれば、そのページが公開中のときだけパーマリンクを返す（未公開・ゴミ箱・削除済みは
	 * 空文字。残っている URL の設定へは戻さない）。選んでいなければ URL の設定を resolve_usable() で判定する。
	 *
	 * @param array<string,mixed> $settings BM 設定（Settings_Repository::get_settings() の戻り値）。
	 * @return string 使える予約ページの URL。未設定・使えないときは空文字。
	 */
	public static function get_url( array $settings ): string {
		$page_id = self::read_page_id( $settings );
		if ( $page_id > 0 ) {
			if ( Page_Reference::PROBLEM_NONE !== Page_Reference::get_page_problem( $page_id ) ) {
				return '';
			}

			$permalink = get_permalink( $page_id );

			return is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
		}

		return self::resolve_usable( self::read_url( $settings ) );
	}

	/**
	 * 設定から、使えるかどうかを問わずに予約ページとして設定されている URL を返す。
	 *
	 * 固定ページを選んでいてそのページが存在すれば（ゴミ箱・未公開でも）パーマリンク、
	 * それ以外は URL の設定を絶対 URL にしたもの。ウェブサイト URL との重複判定など、
	 * 「同じページか」だけを見たい場面で使う（自サイト判定・公開状態の判定はしない）。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return string 設定上の予約ページの URL。未設定なら空文字。
	 */
	public static function get_configured_url( array $settings ): string {
		$page_id = self::read_page_id( $settings );
		if ( $page_id > 0 && null !== Page_Reference::get_page( $page_id ) ) {
			$permalink = get_permalink( $page_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return vkbm_normalize_reservation_page_url( self::read_url( $settings ) );
	}

	/**
	 * 予約ページの設定が「設定済みなのに使えない」ときの警告用情報を返す。
	 *
	 * 固定ページを選んでいればそのページの状態（未公開・ゴミ箱・削除済み）を、選んでいなければ URL の
	 * 設定がこのサイトのドメインかを見る。未設定のときは null（未設定の案内は初期設定通知が担当する）。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return array{type:string,page_id?:int,page_title?:string,entered_host?:string,site_host?:string}|null 警告が要るときだけ配列。
	 */
	public static function get_problem( array $settings ): ?array {
		$page_id = self::read_page_id( $settings );
		if ( $page_id > 0 ) {
			$problem = Page_Reference::get_page_problem( $page_id );
			if ( Page_Reference::PROBLEM_NONE === $problem ) {
				return null;
			}

			$page = Page_Reference::get_page( $page_id );

			return array(
				'type'       => $problem,
				'page_id'    => $page_id,
				'page_title' => null !== $page ? Page_Reference::get_page_label( $page ) : '',
			);
		}

		$mismatch = self::get_mismatch( self::read_url( $settings ) );
		if ( null === $mismatch ) {
			return null;
		}

		return array(
			'type'         => self::PROBLEM_MISMATCH,
			'entered_host' => $mismatch['entered_host'],
			'site_host'    => $mismatch['site_host'],
		);
	}

	/**
	 * get_problem() の結果から警告文（HTML）を組み立てる。
	 *
	 * 入力欄の下と管理画面上部の通知で同じ文面を使うため、ここに集約する。
	 * 「何が起きているか → 次にすること（状態ごとに 1 つ） → 直すまでどうなるか」の順に並べ、1つの翻訳関数に1文だけを入れる。
	 * 行き先のリンクは get_problem_action() で別に出す。「次にすること」はその行き先と一致させ、固定ページを直せない利用者
	 * （業務オーナーなど。行き先が予約ページの設定欄になる）には、未公開・ゴミ箱でも選び直しを勧める。
	 * ページ名はエスケープ済みなので、そのまま出力してよい。
	 *
	 * @param array{type:string,page_id?:int,page_title?:string,entered_host?:string,site_host?:string} $problem get_problem() の戻り値。
	 * @return string 警告文（エスケープ済みの HTML）。種類が不明なら空文字。
	 */
	public static function build_problem_message( array $problem ): string {
		$type = (string) ( $problem['type'] ?? '' );

		if ( self::PROBLEM_MISMATCH === $type ) {
			return self::build_mismatch_message(
				array(
					'entered_host' => (string) ( $problem['entered_host'] ?? '' ),
					'site_host'    => (string) ( $problem['site_host'] ?? '' ),
				)
			);
		}

		$page_title   = esc_html( (string) ( $problem['page_title'] ?? '' ) );
		$select_again = esc_html__( 'Please select the reservation page again.', 'vk-booking-manager' );
		$until_then   = esc_html__( 'Until then, it is not used for reservation buttons and links.', 'vk-booking-manager' );

		switch ( $type ) {
			case Page_Reference::PROBLEM_UNPUBLISHED:
				$sentences = array(
					/* translators: %s: the title of the page selected as the reservation page. */
					sprintf( esc_html__( 'The page "%s" selected as the reservation page is not published.', 'vk-booking-manager' ), $page_title ),
					// 行き先が設定欄になる（ページを公開できない）利用者には、できない行動を勧めない。
					self::get_problem_action( $problem )['is_settings'] ? $select_again : esc_html__( 'Please publish the page.', 'vk-booking-manager' ),
					$until_then,
				);
				break;
			case Page_Reference::PROBLEM_TRASH:
				$sentences = array(
					/* translators: %s: the title of the page selected as the reservation page. */
					sprintf( esc_html__( 'The page "%s" selected as the reservation page is in the trash.', 'vk-booking-manager' ), $page_title ),
					// ゴミ箱から戻した固定ページは下書きになる（WordPress 5.6 以降）ため、公開までを 1 つの行動として書く。
					self::get_problem_action( $problem )['is_settings'] ? $select_again : esc_html__( 'Please restore the page from the trash and publish it.', 'vk-booking-manager' ),
					$until_then,
				);
				break;
			case Page_Reference::PROBLEM_NOT_FOUND:
				$sentences = array(
					esc_html__( 'The page selected as the reservation page has been deleted.', 'vk-booking-manager' ),
					$select_again,
					$until_then,
				);
				break;
			default:
				return '';
		}

		return implode( ' ', $sentences );
	}

	/**
	 * get_problem() の結果に対して、利用者が次にすることの行き先（URL とラベル）を 1 つ返す。
	 *
	 * そのページを直せる（公開できる）利用者には、未公開のページは編集画面、ゴミ箱のページは固定ページのゴミ箱一覧を返す。
	 * それ以外（削除済み・旧ドメインの URL・業務オーナーなど固定ページを直す権限が無いとき）は予約ページの設定欄で選び直してもらう。
	 * build_problem_message() の「次にすること」はこの行き先に合わせるため、条件を変えるときは文面も一緒に確かめること。
	 *
	 * @param array{type:string,page_id?:int,page_title?:string,entered_host?:string,site_host?:string} $problem get_problem() の戻り値。
	 * @return array{url:string,label:string,is_settings:bool} 行き先の URL・ボタンのラベル・予約ページの設定欄への案内かどうか。
	 */
	public static function get_problem_action( array $problem ): array {
		$type    = (string) ( $problem['type'] ?? '' );
		$page_id = (int) ( $problem['page_id'] ?? 0 );
		// 未公開・ゴミ箱のどちらも、最後に公開するところまでできないと直らない。
		$can_publish = $page_id > 0 && current_user_can( 'publish_pages' );

		if ( Page_Reference::PROBLEM_UNPUBLISHED === $type && $can_publish ) {
			// そのページを編集できないときは空が返り、設定欄の案内になる。
			$edit_url = get_edit_post_link( $page_id, 'raw' );
			if ( is_string( $edit_url ) && '' !== $edit_url ) {
				return array(
					'url'         => $edit_url,
					'label'       => __( 'Edit the page', 'vk-booking-manager' ),
					'is_settings' => false,
				);
			}
		}

		// ゴミ箱の一覧を開けて（edit_pages）、そのページを戻せる（delete_post）ときだけ、ゴミ箱の一覧へ案内する。
		if ( Page_Reference::PROBLEM_TRASH === $type && $can_publish && current_user_can( 'edit_pages' ) && current_user_can( 'delete_post', $page_id ) ) {
			return array(
				'url'         => admin_url( 'edit.php?post_status=trash&post_type=page' ),
				'label'       => __( 'Open the trash', 'vk-booking-manager' ),
				'is_settings' => false,
			);
		}

		return array(
			'url'         => admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-reservation-page-url' ),
			'label'       => __( 'Set the reservation page', 'vk-booking-manager' ),
			'is_settings' => true,
		);
	}

	/**
	 * 設定から固定ページ ID を読む。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return int 固定ページ ID。未設定なら 0。
	 */
	private static function read_page_id( array $settings ): int {
		$page_id = $settings[ self::SETTING_PAGE_ID ] ?? 0;

		return is_numeric( $page_id ) ? (int) $page_id : 0;
	}

	/**
	 * 設定から「その他」の URL を読む。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return string 未加工の URL。未設定なら空文字。
	 */
	private static function read_url( array $settings ): string {
		$url = $settings[ self::SETTING_URL ] ?? '';

		return is_string( $url ) ? $url : '';
	}

	/**
	 * 設定値を、リンク先・移動先に使える自サイトのURLにして返す。
	 *
	 * 相対パスは home_url() 基準の絶対URLにする。空文字・自サイト以外のURLは空文字にする。
	 *
	 * @param string $raw_url 設定された予約ページURL（未加工）。
	 * @return string 自サイトのURLとして使えるときは正規化済みURL。未設定・自サイト以外のときは空文字。
	 */
	public static function resolve_usable( string $raw_url ): string {
		$url = vkbm_normalize_reservation_page_url( $raw_url );

		if ( '' === $url || ! self::is_own_site_url( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * 絶対URLのホストが、このサイトのものかを判定する。
	 *
	 * @param string $absolute_url http(s) から始まる絶対URL。
	 * @return bool 自サイトのホストなら true。ホストが取れないときは false。
	 */
	public static function is_own_site_url( string $absolute_url ): bool {
		$host = self::extract_host( $absolute_url );
		if ( '' === $host ) {
			return false;
		}

		return in_array( $host, self::get_own_hosts( $host ), true );
	}

	/**
	 * 予約ページURLが入力済みなのに、自サイトのURLでないときの警告用情報を返す。
	 *
	 * @param string $raw_url 設定された予約ページURL（未加工）。
	 * @return array{entered_host:string,site_host:string}|null 警告が要るときだけ配列。未設定・自サイトのURLなら null。
	 */
	public static function get_mismatch( string $raw_url ): ?array {
		$url = vkbm_normalize_reservation_page_url( $raw_url );

		if ( '' === $url || self::is_own_site_url( $url ) ) {
			return null;
		}

		// 警告には、利用者が見分けやすいよう正規化前の実際のホスト名を出す。
		$entered_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'entered_host' => '' !== $entered_host ? $entered_host : $url,
			'site_host'    => $site_host,
		);
	}

	/**
	 * 予約ページURLが自サイトのURLでないときの警告文（HTML）を組み立てる。
	 *
	 * 入力欄の横と管理画面上部の通知で同じ文面を使うため、ここに集約する。
	 * 「何が起きているか → 何をすればよいか → 直すまでどうなるか」の順に並べる。
	 * 1つの翻訳関数に1文だけを入れ、文ごとに分けてから連結する。
	 * 入力中のドメインだけを `<code>` で強調する。出力側でエスケープ済みなので、そのまま出力してよい。
	 *
	 * @param array{entered_host:string,site_host:string} $mismatch get_mismatch() の戻り値。
	 * @return string 警告文（エスケープ済みの HTML）。
	 */
	public static function build_mismatch_message( array $mismatch ): string {
		$sentences = array(
			sprintf(
				/* translators: 1: the domain currently set in the reservation page URL, 2: the domain of this site. */
				esc_html__( 'The domain of the reservation page URL is "%1$s", but the domain of this site is "%2$s".', 'vk-booking-manager' ),
				'<code>' . esc_html( $mismatch['entered_host'] ) . '</code>',
				esc_html( $mismatch['site_host'] )
			),
			esc_html__( 'Please select the reservation page again from the pages of this site.', 'vk-booking-manager' ),
			esc_html__( 'Until then, it is not used for reservation buttons and links.', 'vk-booking-manager' ),
		);

		return implode( ' ', $sentences );
	}

	/**
	 * このサイトとして扱うホストの一覧（比較用に整形済み）を返す。
	 *
	 * @param string $target_host 判定対象のホスト（`allowed_redirect_hosts` フィルターの第2引数に渡す）。
	 * @return string[] 整形済みホストの一覧。
	 */
	private static function get_own_hosts( string $target_host ): array {
		$hosts = array(
			self::extract_host( home_url() ),
			self::extract_host( site_url() ),
		);

		// 言語別ドメイン等を追加するプラグインのため、WordPress 標準のフィルターに従う。
		$allowed = apply_filters( 'allowed_redirect_hosts', array( $hosts[0] ), $target_host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress 本体のフィルター。
		if ( is_array( $allowed ) ) {
			foreach ( $allowed as $allowed_host ) {
				if ( is_string( $allowed_host ) ) {
					$hosts[] = self::format_host( $allowed_host );
				}
			}
		}

		return array_values( array_filter( $hosts, static fn ( string $host ): bool => '' !== $host ) );
	}

	/**
	 * URLからホスト名を取り出し、比較用に整形して返す。
	 *
	 * @param string $url URL。
	 * @return string 整形済みホスト。取れないときは空文字。
	 */
	private static function extract_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? self::format_host( $host ) : '';
	}

	/**
	 * ホスト名を比較用に整える（小文字化・末尾ドット除去・先頭の www. 除去）。
	 *
	 * @param string $host ホスト名。
	 * @return string 整形済みホスト。
	 */
	private static function format_host( string $host ): string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );

		// Unicode ドメインと punycode 表記を同じホストとして比べられるよう、ASCII 表記にそろえる（関数が無い環境は素通し）。
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) && '' !== $ascii ? $ascii : $host;
		}

		return (string) preg_replace( '/^www\./', '', $host );
	}
}
