<?php
/**
 * サービスメニュー一覧のクイック編集の説明文から基本設定画面の該当欄へ
 * アンカー付きリンクが張られていることを検証するテスト（#424）。
 *
 * クイック編集の「サービス後バッファ」説明文「未記入の場合は基本設定画面での
 * 入力内容が反映されます」の『基本設定画面』部分が、基本設定画面（システムタブ）の
 * 該当フォーム欄へのアンカーリンクになっていることを確認する。
 *
 * あわせて、この説明文（リンク付き）が入力欄の `<label>` の外に置かれていることも検証する
 * （label 内にあると、スクリーンリーダーが入力欄の名前としてリンク文言まで連結して
 * 読み上げてしまうため）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function preg_quote;
use function strpos;
use function wp_set_current_user;

/**
 * render_quick_edit_fields() のリンク出力・DOM 構造を検証するテスト。
 *
 * @group post-types
 */
class Service_Menu_Quick_Edit_Basic_Settings_Anchor_Link_Test extends WP_UnitTestCase {

	/**
	 * テスト前に管理者としてログインする。
	 */
	protected function setUp(): void {
		parent::setUp();

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * テスト後にログイン状態をリセットする。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * render_quick_edit_fields() の出力を検証する。
	 *
	 * - 「サービス後バッファ」の説明文から基本設定画面の該当欄への完全なリンク
	 *   （href・target・rel・文言）が1本のリンクとして含まれること。
	 * - その説明文（`<p class="description">` 以下）が、入力欄を包む `<label>...</label>`
	 *   の外側（直後の兄弟要素）に置かれていること。
	 */
	public function test_render_quick_edit_fields(): void {
		$post_type = new Service_Menu_Post_Type();

		ob_start();
		// column_name には $columns 配列に含まれる値であれば何を渡しても、
		// 一覧全体を1回だけ描画する render_quick_edit_fields() の実装上、同じ出力になる.
		$post_type->render_quick_edit_fields( 'vkbm_buffer_after', Service_Menu_Post_Type::POST_TYPE );
		$output = (string) ob_get_clean();

		// リンク1本を、href（アンカー）・target・rel・リンク文言をまとめて検証する.
		// esc_url() が "&" を "&#038;" にエンコードするため、アンカーより手前は
		// ワイルドカードで吸収し、正規表現に "&" を含めない.
		$pattern = sprintf(
			'/<a href="[^"]*page=vkbm-provider-settings[^"]*tab=system#%s" target="_blank" rel="noopener noreferrer">%s<\/a>/',
			preg_quote( 'vkbm-service-menu-buffer-after-default', '/' ),
			preg_quote( 'Post-service buffer on the General Settings page', '/' )
		);
		$this->assertMatchesRegularExpression(
			$pattern,
			$output,
			'サービス後バッファの説明文に基本設定画面の該当欄への完全なリンクが含まれる'
		);

		// 入力欄を包む label（「サービス後バッファ」の title を含む label）の範囲を取り出し、
		// その中に説明文（description）が含まれていないこと（label の外に出ていること）を確認する.
		$input_pos = strpos( $output, 'class="vkbm-qe-buffer-after-minutes"' );
		$this->assertNotFalse( $input_pos, '対象の input が出力に含まれていない' );

		$label_close_pos = strpos( $output, '</label>', $input_pos );
		$this->assertNotFalse( $label_close_pos, '対象 input を包む label の終了タグが見つからない' );

		$label_segment = substr( $output, $input_pos, $label_close_pos - $input_pos );
		$this->assertStringNotContainsString(
			'class="description',
			$label_segment,
			'説明文（description）が label の内側に残っている（スクリーンリーダーが入力欄名に連結して読み上げてしまう）'
		);

		// 説明文自体は、label の終了タグより後ろ（外側）に出力されていることを確認する.
		$description_pos = strpos( $output, 'vkbm-qe-buffer-after-description' );
		$this->assertNotFalse( $description_pos, '説明文の要素が出力に含まれていない' );
		$this->assertGreaterThan(
			$label_close_pos,
			$description_pos,
			'説明文が label の終了タグより前（内側）に出力されている'
		);
	}
}
