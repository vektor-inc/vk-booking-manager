<?php
/**
 * プライバシーポリシー（BM 設定）の表示方法とリンク先を決め、選んだ固定ページが使えない状態を判定する共通処理。
 *
 * 「プライバシーポリシーのページを指定」では、固定ページの選択（provider_privacy_policy_page_id）か、
 * 「その他」の URL（provider_privacy_policy_url）でリンク先を決める。固定ページを選んでいれば、その時点の
 * get_permalink() を使うため、サイトのドメインを移しても設定し直さずにリンクが正しいページへ行く（#1168）。
 * 会員登録フォームと予約ブロックのフッターは、リンク先をここで求める。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use function __;
use function admin_url;
use function esc_html;
use function esc_html__;
use function esc_url_raw;
use function get_permalink;
use function implode;
use function in_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function sanitize_key;
use function sprintf;
use function trim;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態を持たない静的ユーティリティクラス。
 */
class Privacy_Policy_Url {

	/**
	 * 表示方法: 表示しない（未選択）。
	 */
	public const MODE_NONE = 'none';

	/**
	 * 表示方法: プライバシーポリシーのページを指定する（固定ページの選択、または「その他」の URL）。
	 */
	public const MODE_URL = 'url';

	/**
	 * 表示方法: この画面で本文を入力する。
	 */
	public const MODE_CONTENT = 'content';

	/**
	 * 設定のキー: 表示方法。
	 */
	private const SETTING_MODE = 'provider_privacy_policy_mode';

	/**
	 * 設定のキー: プライバシーポリシーとして選んだ固定ページの ID（0 は未選択。URL の設定を使う）。
	 */
	private const SETTING_PAGE_ID = 'provider_privacy_policy_page_id';

	/**
	 * 設定のキー: 「その他（URL を入力）」のプライバシーポリシーの URL。
	 */
	private const SETTING_URL = 'provider_privacy_policy_url';

	/**
	 * 設定から表示方法を読む（不正な値は「表示しない」にする）。
	 *
	 * @param array<string,mixed> $settings BM 設定（Settings_Repository::get_settings() の戻り値）。
	 * @return string MODE_NONE／MODE_URL／MODE_CONTENT のいずれか。
	 */
	public static function get_mode( array $settings ): string {
		$mode = $settings[ self::SETTING_MODE ] ?? self::MODE_NONE;
		$mode = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : self::MODE_NONE;

		return in_array( $mode, array( self::MODE_NONE, self::MODE_URL, self::MODE_CONTENT ), true ) ? $mode : self::MODE_NONE;
	}

	/**
	 * 設定から、リンク先に使えるプライバシーポリシーの URL を返す。
	 *
	 * 表示方法が「プライバシーポリシーのページを指定」のときだけ値を返す。
	 * 固定ページを選んでいれば、そのページが公開中のときだけパーマリンクを返す（未公開・ゴミ箱・削除済みは空文字。
	 * 残っている URL の設定へは戻さない）。選んでいなければ URL の設定をそのまま返す（外部サイトの URL も使う）。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return string 使えるプライバシーポリシーの URL。表示方法が違う・未設定・使えないときは空文字。
	 */
	public static function get_url( array $settings ): string {
		if ( self::MODE_URL !== self::get_mode( $settings ) ) {
			return '';
		}

		$page_id = self::read_page_id( $settings );
		if ( $page_id > 0 ) {
			if ( Page_Reference::PROBLEM_NONE !== Page_Reference::get_page_problem( $page_id ) ) {
				return '';
			}

			$permalink = get_permalink( $page_id );

			return is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
		}

		return esc_url_raw( trim( self::read_url( $settings ) ) );
	}

	/**
	 * 選んだ固定ページが「選んであるのに使えない」ときの警告用情報を返す。
	 *
	 * 固定ページを選んでいないとき（「その他」の URL・未設定）は null。外部サイトの URL を意図して指定する人がいるため、
	 * 「その他」の URL がこのサイトのものかは判定しない。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return array{type:string,page_id:int,page_title:string}|null 警告が要るときだけ配列。
	 */
	public static function get_problem( array $settings ): ?array {
		$page_id = self::read_page_id( $settings );
		if ( $page_id <= 0 ) {
			return null;
		}

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

	/**
	 * get_problem() の結果から警告文（HTML）を組み立てる。
	 *
	 * 入力欄の下と管理画面上部の通知で同じ文面を使うため、ここに集約する。
	 * 「何が起きているか → 次にすること（状態ごとに 1 つ） → 直すまでどうなるか」の順に並べ、1つの翻訳関数に1文だけを入れる。
	 * 「次にすること」は get_problem_action() の行き先と一致させ、そのページを直せない利用者には選び直しを勧める。
	 * ページ名はエスケープ済みなので、そのまま出力してよい。
	 *
	 * @param array{type:string,page_id?:int,page_title?:string} $problem get_problem() の戻り値。
	 * @return string 警告文（エスケープ済みの HTML）。種類が不明なら空文字。
	 */
	public static function build_problem_message( array $problem ): string {
		$type         = (string) ( $problem['type'] ?? '' );
		$page_title   = esc_html( (string) ( $problem['page_title'] ?? '' ) );
		$can_fix      = ! self::get_problem_action( $problem )['is_settings'];
		$select_again = esc_html__( 'Please select the privacy policy page again.', 'vk-booking-manager' );
		$until_then   = esc_html__( 'Until then, the user registration form does not ask for consent to the privacy policy, and the footer of the reservation page does not show the link either.', 'vk-booking-manager' );

		switch ( $type ) {
			case Page_Reference::PROBLEM_UNPUBLISHED:
				$sentences = array(
					/* translators: %s: the title of the page selected as the privacy policy page. */
					sprintf( esc_html__( 'The page "%s" selected as the privacy policy page is not published.', 'vk-booking-manager' ), $page_title ),
					// ページを公開できない利用者には、できない行動を勧めない。
					$can_fix ? esc_html__( 'Please publish the page.', 'vk-booking-manager' ) : $select_again,
					$until_then,
				);
				break;
			case Page_Reference::PROBLEM_TRASH:
				$sentences = array(
					/* translators: %s: the title of the page selected as the privacy policy page. */
					sprintf( esc_html__( 'The page "%s" selected as the privacy policy page is in the trash.', 'vk-booking-manager' ), $page_title ),
					// ゴミ箱から戻した固定ページは下書きになる（WordPress 5.6 以降）ため、公開までを 1 つの行動として書く。
					$can_fix ? esc_html__( 'Please restore the page from the trash and publish it.', 'vk-booking-manager' ) : $select_again,
					$until_then,
				);
				break;
			case Page_Reference::PROBLEM_NOT_FOUND:
				$sentences = array(
					esc_html__( 'The page selected as the privacy policy page has been deleted.', 'vk-booking-manager' ),
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
	 * そのページを直せる利用者には、Page_Reference::get_fix_action() の行き先（未公開は編集画面、ゴミ箱は固定ページのゴミ箱一覧）を返す。
	 * それ以外（削除済み・業務オーナーなど固定ページを直す権限が無いとき）は、同意項目タブのプライバシーポリシーページの欄で選び直してもらう。
	 * build_problem_message() の「次にすること」はこの行き先に合わせる。
	 *
	 * @param array{type:string,page_id?:int,page_title?:string} $problem get_problem() の戻り値。
	 * @return array{url:string,label:string,is_settings:bool} 行き先の URL・ボタンのラベル・設定欄への案内かどうか。
	 */
	public static function get_problem_action( array $problem ): array {
		$fix_action = Page_Reference::get_fix_action( (string) ( $problem['type'] ?? '' ), (int) ( $problem['page_id'] ?? 0 ) );
		if ( null !== $fix_action ) {
			return array(
				'url'         => $fix_action['url'],
				'label'       => $fix_action['label'],
				'is_settings' => false,
			);
		}

		return array(
			'url'         => admin_url( 'admin.php?page=vkbm-provider-settings&tab=consent#vkbm-provider-privacy-policy-page' ),
			'label'       => __( 'Set the privacy policy page', 'vk-booking-manager' ),
			'is_settings' => true,
		);
	}

	/**
	 * 今の設定で使われている固定ページが使えないときだけ、警告用情報を返す（管理画面上部の通知用）。
	 *
	 * get_problem() は表示方法を見ない（設定欄では表示方法を切り替えた直後にも警告を見せるため）。
	 * 上部の通知は、選んだページを実際に使う「プライバシーポリシーページを指定」のときだけ出す。
	 *
	 * @param array<string,mixed> $settings BM 設定。
	 * @return array{type:string,page_id:int,page_title:string}|null 警告が要るときだけ配列。
	 */
	public static function get_active_problem( array $settings ): ?array {
		if ( self::MODE_URL !== self::get_mode( $settings ) ) {
			return null;
		}

		return self::get_problem( $settings );
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
}
