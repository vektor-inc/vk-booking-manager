<?php
/**
 * サービスメニュー編集画面の説明文から基本設定画面の該当欄へアンカー付きリンクが
 * 張られていることを検証するテスト（#424）。
 *
 * 「予約締切」「予約可能な最大日数」「サービス後バッファ（分）」の説明文
 * 「未記入の場合は基本設定画面での入力内容が反映されます」の『基本設定画面』部分が、
 * 基本設定画面（システムタブ）の該当フォーム欄へのアンカーリンクになっていることを確認する。
 *
 * `target="_blank"` や `rel="noopener noreferrer"` を出力全体から個別に探すのではなく、
 * href（アンカー）・target・rel・リンク文言を1つの正規表現でまとめて検証する
 * （render_conditions_meta_box() 内には基本設定への別リンクが他にも存在するため、
 * リンク単位で確認しないと属性の欠落を見逃す）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Service_Menu_Editor;
use WP_UnitTestCase;
use function admin_url;
use function get_post;
use function preg_quote;
use function wp_set_current_user;

/**
 * render_vkbm_meta_box() / render_conditions_meta_box() のリンク出力を検証するテスト群。
 *
 * @group admin
 */
class Service_Menu_Editor_Basic_Settings_Anchor_Link_Test extends WP_UnitTestCase {

	/**
	 * テスト用投稿ID。
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * テスト前にサービスメニュー投稿を用意し、管理者としてログインする。
	 */
	protected function setUp(): void {
		parent::setUp();

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * テスト後にログイン状態をリセットする。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 基本設定画面（システムタブ）の指定アンカーへのリンク1本を、
	 * href・target・rel・リンク文言をまとめて検証する正規表現に組み立てる。
	 *
	 * esc_url() は URL 中の "&" を "&#038;" にエンコードするため、href 部分は
	 * "&" を直接書かず、アンカーより手前を [^"]* のワイルドカードで吸収する。
	 *
	 * @param string $anchor_id 基本設定画面側フォーム欄の id（例: vkbm-provider-reservation-deadline）。
	 * @param string $link_text リンク文言（英語 msgid のまま）。
	 * @return string preg_match 用の正規表現。
	 */
	private function build_link_pattern( string $anchor_id, string $link_text ): string {
		return sprintf(
			'/<a href="[^"]*page=vkbm-provider-settings[^"]*tab=system#%s" target="_blank" rel="noopener noreferrer">%s<\/a>/',
			preg_quote( $anchor_id, '/' ),
			preg_quote( $link_text, '/' )
		);
	}

	/**
	 * render_vkbm_meta_box() の出力に、「予約締切」「予約可能な最大日数」それぞれの
	 * 説明文から基本設定画面の該当欄へのアンカーリンク（href・target・rel・文言）が
	 * リンク単位で正しく含まれることを確認する。
	 */
	public function test_render_vkbm_meta_box(): void {
		$post   = get_post( $this->post_id );
		$editor = new Service_Menu_Editor();

		ob_start();
		$editor->render_vkbm_meta_box( $post );
		$output = (string) ob_get_clean();

		$test_cases = array(
			array(
				'test_condition_name' => '予約締切の説明文に基本設定画面の予約締切欄への完全なリンク（href・target・rel・文言）が含まれる',
				'anchor_id'           => 'vkbm-provider-reservation-deadline',
				'link_text'           => 'Reservation deadline on the General Settings page',
			),
			array(
				'test_condition_name' => '予約可能な最大日数の説明文に基本設定画面の該当欄への完全なリンクが含まれる',
				'anchor_id'           => 'vkbm-provider-max-advance-booking-days',
				'link_text'           => 'Max advance booking period on the General Settings page',
			),
		);

		foreach ( $test_cases as $case ) {
			$pattern = $this->build_link_pattern( $case['anchor_id'], $case['link_text'] );
			$this->assertMatchesRegularExpression(
				$pattern,
				$output,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * render_conditions_meta_box() の出力に、「サービス後バッファ（分）」の説明文から
	 * 基本設定画面の該当欄への完全なリンクが含まれることを確認する。
	 *
	 * この出力には基本設定画面への別リンク（指名機能の設定リンク等）も含まれるため、
	 * リンク単位の正規表現で検証しないと、対象リンクの target/rel 欠落を見逃してしまう。
	 */
	public function test_render_conditions_meta_box(): void {
		$post   = get_post( $this->post_id );
		$editor = new Service_Menu_Editor();

		ob_start();
		$editor->render_conditions_meta_box( $post );
		$output = (string) ob_get_clean();

		$pattern = $this->build_link_pattern(
			'vkbm-service-menu-buffer-after-default',
			'Post-service buffer on the General Settings page'
		);
		$this->assertMatchesRegularExpression(
			$pattern,
			$output,
			'サービス後バッファの説明文に基本設定画面の該当欄への完全なリンクが含まれる'
		);
	}
}
