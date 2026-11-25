<?php
/**
 * プライバシーポリシーのページの設定を、URL から固定ページの選択へ一度だけ切り替える処理（#1168）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

use VKBookingManager\Common\Page_Reference;
use function add_action;
use function get_option;
use function is_array;
use function is_string;
use function update_option;
use function vkbm_normalize_reservation_page_url;
use function wp_doing_ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新前に URL で保存されていたプライバシーポリシーのページを、このサイトの公開中の固定ページなら ID の選択に切り替える。
 *
 * 予約ページの切り替え（Reservation_Page_Migration）と同じ方式で、実行済みの目印は別に持つ
 * （予約ページの切り替えを済ませたサイトでも、この切り替えは一度だけ実行する）。
 */
class Privacy_Policy_Page_Migration {

	/**
	 * 切り替えを実行済みであることを記録する option 名。
	 *
	 * 変換する設定が無くても最初の1回で立てる。後から（e2e や別の経路で）書かれた URL を勝手に ID へ変えないため。
	 */
	public const OPTION_DONE = 'vkbm_privacy_policy_page_id_migrated';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		// 受け取る引数を 0 にしているのは、admin_init が渡す値を受け取らないようにするため。
		add_action( 'admin_init', array( $this, 'maybe_migrate' ), 10, 0 );
	}

	/**
	 * 管理画面を最初に開いたときに一度だけ、URL の設定を固定ページ ID へ切り替える。
	 *
	 * 切り替えるのは次をすべて満たすときだけ（判定は Page_Reference::find_page_id_by_url()）。
	 * - 固定ページがまだ選ばれていない（provider_privacy_policy_page_id が 0）
	 * - URL がこのサイトの URL で、公開中の固定ページを指し、そのページのパーマリンクと同じページ（`?` や `#` 付きは対象外）
	 * 表示方法（provider_privacy_policy_mode）は問わない。後から「プライバシーポリシーのページを指定」に切り替えたときも、同じページが選ばれているようにするため。
	 * 切り替えても URL の設定は残す（旧バージョンに戻しても動くようにするため）。
	 * 外部サイトの URL・旧ドメインの URL・固定ページ以外の URL は切り替えず、「その他（URL を入力）」として従来どおり使う。
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		if ( '1' === (string) get_option( self::OPTION_DONE ) ) {
			return;
		}

		// Ajax（ハートビート等）では実行せず、通常の管理画面の表示にだけ寄せる。
		if ( wp_doing_ajax() ) {
			return;
		}

		// 設定の option を直接読み書きし、他の項目（業種プリセットの反映など）には触れない。
		$stored = get_option( Settings_Repository::OPTION_KEY );
		if ( is_array( $stored ) ) {
			$page_id = $this->find_page_id_to_select( $stored );
			if ( $page_id > 0 ) {
				$stored['provider_privacy_policy_page_id'] = $page_id;
				update_option( Settings_Repository::OPTION_KEY, $stored );
			}
		}

		update_option( self::OPTION_DONE, '1', true );
	}

	/**
	 * 保存済みの設定から、切り替え先にできる固定ページの ID を求める。
	 *
	 * @param array<string,mixed> $stored 保存済みの BM 設定（既定値はマージしない）。
	 * @return int 固定ページ ID。切り替えない（既に選択済み・URL 未設定・条件を満たさない）ときは 0。
	 */
	private function find_page_id_to_select( array $stored ): int {
		if ( (int) ( $stored['provider_privacy_policy_page_id'] ?? 0 ) > 0 ) {
			return 0;
		}

		$raw_url = $stored['provider_privacy_policy_url'] ?? '';
		if ( ! is_string( $raw_url ) || '' === trim( $raw_url ) ) {
			return 0;
		}

		// 相対パスは自サイトの絶対 URL にしてから判定する。
		return Page_Reference::find_page_id_by_url( vkbm_normalize_reservation_page_url( $raw_url ) );
	}
}
