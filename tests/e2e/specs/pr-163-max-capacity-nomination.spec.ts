/**
 * PR #163: 指名機能有効時にメニュー詳細の「1枠あたりの最大予約受付数」を非表示にし案内メッセージを表示するテスト
 *
 * CI 環境（GitHub Actions）で Gutenberg メタボックスが iframe 内にレンダリングされる際の
 * セレクタ差異により不安定だった問題（issue #175）を解消するため、
 * ブラウザベースのメタボックス操作テストから WP-CLI ベースの HTML 出力検証に変更。
 *
 * PR #163: Tests for hiding "max bookings per time slot" field when the nomination feature is enabled.
 * Converted from browser-based metabox tests to WP-CLI-based HTML output verification
 * to fix CI instability caused by Gutenberg metabox iframe rendering differences (issue #175).
 *
 * 確認項目:
 * 1. 指名機能が有効な場合: max_capacity 入力フィールドが出力されず、案内メッセージが出力される
 * 2. 指名機能が有効な場合: 案内メッセージ内の設定ページリンクが正しいURLで target="_blank" である
 * 3. 指名機能が無効な場合: max_capacity 入力フィールドが出力される
 * 4. 指名機能が無効な場合: max_capacity の値を変更して保存し、再度読み込むと値が保持されている（回帰確認）
 */
import { test, expect } from '@playwright/test';
import {
	wpCli,
	wpEvalPhp,
	setStaffEnabled,
	getServiceMenuId,
} from '../utils/helpers';

/**
 * render_conditions_meta_box の HTML 出力を WP-CLI 経由で取得するヘルパー。
 * ob_start / ob_end_clean を使ってメタボックスのレンダリング結果を文字列として返す。
 * Helper to get the HTML output of render_conditions_meta_box via WP-CLI.
 * Uses ob_start / ob_end_clean to capture the metabox rendering result as a string.
 *
 * @param menuId サービスメニューの投稿ID / Service menu post ID
 * @return メタボックスの HTML 出力 / HTML output of the metabox
 */
function getConditionsMetaboxHtml( menuId: string ): string {
	// PHP コードで Service_Menu_Editor のインスタンスを生成し、render_conditions_meta_box を実行
	// Generate a Service_Menu_Editor instance in PHP and execute render_conditions_meta_box
	const phpCode = `
		// 管理画面コンテキストを模擬するために current_screen を設定
		// Set current_screen to simulate admin context
		set_current_screen( 'post' );

		$post = get_post( ${ menuId } );
		if ( ! $post ) {
			echo 'ERROR: Post not found';
			return;
		}

		// Service_Menu_Editor インスタンスを生成してメタボックスをレンダリング
		// Create Service_Menu_Editor instance and render the metabox
		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		ob_start();
		$editor->render_conditions_meta_box( $post );
		$html = ob_get_clean();
		echo $html;
	`;
	// execFileSync 化に伴い、shell のシングルクォート展開が効かなくなったため
	// 旧式の `eval 'eval(base64_decode("..."));'` 形は使えない。
	// wpEvalPhp が base64 ラップと WP-CLI 引数渡しを 1 ヘルパーに集約しているのでそれを使用する。
	// After moving to execFileSync, the shell-single-quote form of
	// `eval 'eval(base64_decode("..."));'` no longer works. wpEvalPhp wraps
	// the base64 + wpCliArgs call into one helper, which is the correct entry point.
	return wpEvalPhp( phpCode );
}

test.describe( 'PR #163: 指名機能有効時のmax_capacityフィールド非表示（WP-CLI検証）', () => {
	// 各テスト後に指名機能を有効に戻す（グローバルセットアップのデフォルト状態）
	// テスト途中で assertion が失敗しても次のテストに状態が漏れないよう afterEach で復元する
	// Restore nomination feature to enabled after each test (default state from global setup)
	// Using afterEach ensures state is restored even if an assertion fails mid-test
	test.afterEach( () => {
		setStaffEnabled( true );
	} );

	test( '指名機能が有効な場合: max_capacity入力フィールドが出力されず案内メッセージが出力される', () => {
		// --- 準備: 指名機能を有効にする ---
		// Setup: Enable the nomination feature
		setStaffEnabled( true );

		// サービスメニューの投稿IDを取得
		// Get the service menu post ID
		const menuId = getServiceMenuId();

		// メタボックスの HTML 出力を取得
		// Get the HTML output of the metabox
		const html = getConditionsMetaboxHtml( menuId );

		// max_capacity 入力フィールドが出力されていないことを確認
		// Verify max_capacity input field is NOT present in the output
		expect( html ).not.toContain( 'id="vkbm_service_menu_max_capacity"' );
		expect( html ).not.toContain(
			'name="vkbm_service_menu[max_capacity]"'
		);

		// 案内メッセージが出力されていることを確認（英語翻訳キーで検証）
		// Verify guidance message IS present in the output
		// 日本語環境では翻訳された文字列が表示されるため、HTML 構造で検証する
		// In Japanese locale the translated string is shown, so verify by HTML structure
		expect( html ).toContain( 'class="description"' );

		// 基本設定画面へのリンクが出力されていることを確認
		// Verify the settings page link is present
		expect( html ).toContain( 'vkbm-provider-settings' );
		expect( html ).toContain( 'tab=system' );
		expect( html ).toContain( '#vkbm-staff-enabled' );

		// リンクが target="_blank" であることを確認
		// Verify the link has target="_blank"
		expect( html ).toContain( 'target="_blank"' );

		// リンクに rel="noopener noreferrer" が設定されていることを確認
		// Verify the link has rel="noopener noreferrer"
		expect( html ).toContain( 'rel="noopener noreferrer"' );
	} );

	test( '指名機能が無効な場合: max_capacity入力フィールドが出力される', () => {
		// --- 準備: 指名機能を無効にする ---
		// Setup: Disable the nomination feature
		setStaffEnabled( false );

		// サービスメニューの投稿IDを取得
		// Get the service menu post ID
		const menuId = getServiceMenuId();

		// メタボックスの HTML 出力を取得
		// Get the HTML output of the metabox
		const html = getConditionsMetaboxHtml( menuId );

		// max_capacity 入力フィールドが出力されていることを確認
		// Verify max_capacity input field IS present in the output
		expect( html ).toContain( 'id="vkbm_service_menu_max_capacity"' );
		expect( html ).toContain( 'name="vkbm_service_menu[max_capacity]"' );
		expect( html ).toContain( 'type="number"' );

		// 説明文が出力されていることを確認（HTML 構造で検証）
		// Verify description text is present (verified by HTML structure)
		expect( html ).toContain( 'class="description"' );

		// 指名機能有効時の案内メッセージ（設定ページリンク）が出力されていないことを確認
		// Verify the nomination-enabled guidance message (settings page link) is NOT present
		expect( html ).not.toContain( '#vkbm-staff-enabled' );
	} );

	test( '指名機能が無効な場合: max_capacityの値をWP-CLIで保存すると値が保持される（回帰確認）', () => {
		// --- 準備: 指名機能を無効にする ---
		// Setup: Disable the nomination feature
		setStaffEnabled( false );

		// サービスメニューの投稿IDを取得
		// Get the service menu post ID
		const menuId = getServiceMenuId();

		// テスト前の元の値を退避（assertion 失敗時にも必ず復元するため）
		// Save original value before test (to ensure restoration even on assertion failure)
		// execFileSync 化に伴い、shell のシングルクォート展開が効かなくなったため、
		// 旧式の `eval 'echo ...'` 形式ではなく wpEvalPhp 経由で PHP コードを渡す。
		// After moving to execFileSync, the shell-quoted `eval 'echo ...'`
		// pattern no longer works; route the PHP code through wpEvalPhp instead.
		const originalMaxCapacity = wpEvalPhp(
			`echo get_post_meta( ${ menuId }, "_vkbm_max_capacity", true );`
		);

		try {
			// WP-CLI で max_capacity メタ値を 5 に設定
			// Set max_capacity meta value to 5 via WP-CLI
			wpCli( `post meta update ${ menuId } _vkbm_max_capacity 5` );

			// メタボックスの HTML 出力を取得し、値が反映されていることを確認
			// Get the metabox HTML output and verify the value is reflected
			const html = getConditionsMetaboxHtml( menuId );
			expect( html ).toContain( 'id="vkbm_service_menu_max_capacity"' );
			// value="5" が input フィールドに含まれていることを確認
			// Verify value="5" is present in the input field
			expect( html ).toContain( 'value="5"' );

			// 別の値（3）に変更して保存し、再度確認
			// Change to a different value (3), save, and verify again
			wpCli( `post meta update ${ menuId } _vkbm_max_capacity 3` );
			const htmlAfter = getConditionsMetaboxHtml( menuId );
			expect( htmlAfter ).toContain( 'value="3"' );
		} finally {
			// テスト後にメタ値を元の状態に復元（失敗時にも必ず実行）
			// Restore meta value to original state after test (always runs, even on failure)
			if ( originalMaxCapacity === '' ) {
				wpCli( `post meta delete ${ menuId } _vkbm_max_capacity` );
			} else {
				wpCli(
					`post meta update ${ menuId } _vkbm_max_capacity ${ originalMaxCapacity }`
				);
			}
		}
	} );
} );
