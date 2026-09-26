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
 * #515: 料金区分の注記（基本料金欄のラベル・注記の hidden・「料金区分を編集」リンク・
 * 基本料金 input が disabled/readonly になっていないこと）も同じ出力に対する検証のため、
 * このテストクラスにまとめている。render_quick_edit_fields() は「一覧全体を1回だけ描画する」
 * 実装（`static $rendered` で2回目以降の呼び出しを無視する）のため、同じテストクラス・
 * 同じ PHPUnit プロセス内で複数回呼ぶテストメソッドに分けると2件目以降が必ず空文字を返して
 * 失敗する。そのため関連する検証は分割せず、1回の出力に対して同じテストメソッド内で
 * まとめて行う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function preg_quote;
use function strpos;
use function strrpos;
use function substr;
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
	 * - #515: 基本料金欄のラベルが既存 msgid「Basic price」であること。料金区分の注記
	 *   （説明文＋編集画面へのリンク）が既定で hidden 属性付きで出力され、JS が行ごとに
	 *   表示を切り替えられる構造になっていること。基本料金の input が disabled/readonly に
	 *   なっておらず常に編集可能なままであること。
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

		// #515: 基本料金欄のラベルが「Price」から既存 msgid「Basic price」に変わっていること.
		$this->assertStringContainsString( 'Basic price', $output );

		// #515: 料金区分の注記本体：id・hidden 属性・説明文言.
		$this->assertStringContainsString( 'id="vkbm-qe-price-tiers-notice"', $output );
		$this->assertStringContainsString(
			'This menu uses price categories, so the basic price is not used for bookings.',
			$output
		);

		// hidden 属性が id と同じ div タグに付いていることを確認する（開始タグの範囲だけを見る）.
		// #515 レビュー対応: 注記は p ではなく div でラップし、説明文とリンクを別々の p に分ける
		// （<br> で余白を作らないため。design-rules 参照）。
		$price_tiers_notice_open_pos = strpos( $output, '<div class="description vkbm-qe-price-tiers-notice"' );
		$this->assertNotFalse( $price_tiers_notice_open_pos, '料金区分の注記の div タグが出力に含まれていない' );
		$price_tiers_notice_open_end = strpos( $output, '>', $price_tiers_notice_open_pos );
		$this->assertNotFalse( $price_tiers_notice_open_end, '料金区分の注記の div タグの開始タグが閉じていない' );
		$price_tiers_notice_open_tag = substr( $output, $price_tiers_notice_open_pos, $price_tiers_notice_open_end - $price_tiers_notice_open_pos );
		$this->assertStringContainsString(
			'hidden',
			$price_tiers_notice_open_tag,
			'料金区分の注記は既定で hidden（JS が行ごとに表示を切り替える）'
		);

		// 説明文とリンクは、それぞれ別の p 要素（vkbm-qe-price-tiers-notice-text /
		// vkbm-qe-price-tiers-notice-link）に分かれており、<br> で余白を作っていないこと.
		$this->assertStringContainsString( 'class="vkbm-qe-price-tiers-notice-text"', $output );
		$this->assertStringContainsString( 'class="vkbm-qe-price-tiers-notice-link"', $output );
		$price_tiers_notice_close_pos = strpos( $output, '</div>', $price_tiers_notice_open_pos );
		$this->assertNotFalse( $price_tiers_notice_close_pos, '料金区分の注記の div の終了タグが見つからない' );
		$price_tiers_notice_inner = substr( $output, $price_tiers_notice_open_pos, $price_tiers_notice_close_pos - $price_tiers_notice_open_pos );
		$this->assertStringNotContainsString( '<br', $price_tiers_notice_inner, '料金区分の注記内で <br> による余白を作っていないこと' );

		// #515: 「料金区分を編集」リンク。既定の href は "#"（JS が data-price-tiers-edit-url から差し込む）.
		$this->assertStringContainsString( 'class="vkbm-qe-price-tiers-link"', $output );
		$this->assertStringContainsString( 'Edit price categories', $output );
		$this->assertStringContainsString( 'href="#" class="vkbm-qe-price-tiers-link"', $output );

		// #515: 基本料金の input が disabled/readonly になっておらず、常に編集可能なままであること
		// （save_quick_edit() -> update_meta_value() は空送信でメタを削除するため）.
		$base_price_input_pos = strpos( $output, 'class="vkbm-qe-base-price"' );
		$this->assertNotFalse( $base_price_input_pos, '基本料金の input が出力に含まれていない' );
		$base_price_input_end = strpos( $output, '/>', $base_price_input_pos );
		$this->assertNotFalse( $base_price_input_end, '基本料金 input タグが閉じていない' );
		$base_price_input_start = strrpos( substr( $output, 0, $base_price_input_pos ), '<input' );
		$this->assertNotFalse( $base_price_input_start, '基本料金 input の開始位置が見つからない' );
		$base_price_input_tag = substr( $output, $base_price_input_start, $base_price_input_end - $base_price_input_start );

		$this->assertStringNotContainsString( 'disabled', $base_price_input_tag );
		$this->assertStringNotContainsString( 'readonly', $base_price_input_tag );

		// #515 レビュー対応: aria-describedby は PHP側で固定せず、JS
		// （service-menu-quick-edit.js）が行ごとの usesPriceTiers の値で付け外しする。
		// 静的テンプレート側には常に付いていないことを確認する（安藤レビュー指摘）。
		$this->assertStringNotContainsString(
			'aria-describedby',
			$base_price_input_tag,
			'aria-describedby は静的テンプレートに固定せず、JS が行ごとに付け外しする'
		);
	}
}
