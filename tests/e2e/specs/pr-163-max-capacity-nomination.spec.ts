/**
 * issue #392: 指名機能を「1枠1組（貸切）」として扱う仕様変更に伴う、
 * メニュー詳細の「予約枠の定員（最大人数）」フィールド表示のテスト。
 *
 * 【旧仕様（PR #163・#412）】指名機能が有効なメニューは max_capacity 入力フィールドを
 * 非表示にし、代わりに「指名機能を使っているため定員設定は使えない」案内メッセージ
 * （id="vkbm-nomination-enabled-notice"）を表示していた。
 *
 * 【新仕様（issue #392）】指名を使うメニューを「1枠1組（貸切）」として扱い、
 * 予約枠の定員を「1組の最大人数」として使うようになったため、指名機能が有効なメニューでも
 * max_capacity 入力フィールドを常に表示する。案内メッセージ（vkbm-nomination-enabled-notice）
 * 自体を撤去したため、DOM から要素そのものが消えている（hidden 属性で隠すのではない）。
 * 説明文は、指名を使うか否かで「1組の最大人数」向け／「スタッフ自動割り当て時の相乗り人数」向けの
 * 2パターンに出し分けられる。
 *
 * CI 環境（GitHub Actions）で Gutenberg メタボックスが iframe 内にレンダリングされる際の
 * セレクタ差異により不安定だった問題（issue #175）を踏襲し、引き続き WP-CLI ベースの
 * HTML 出力検証で行う（ブラウザベースのメタボックス操作テストにはしない）。
 *
 * issue #392: Tests for the "time slot capacity (max_capacity)" field on the service menu
 * detail screen after the spec change that treats a nomination-enabled menu as
 * "one group per slot (private)". Before #392, the field was hidden and replaced by a
 * guidance message when nomination was enabled; after #392 the field (and the
 * "Booking multiple people at once" checkbox) is always shown regardless of nomination,
 * and the old guidance message element has been removed entirely (not just hidden).
 *
 * 確認項目:
 * 1. 指名機能が有効な場合: max_capacity 入力フィールドが表示され（hidden なし）、
 *    指名向けの説明文（1組の最大人数・スタッフが枠を専有する旨）が出力される
 * 2. 指名機能が有効な場合: 旧仕様の案内メッセージ要素（vkbm-nomination-enabled-notice）が
 *    DOM に存在しない（撤去済みであることの回帰確認）
 * 3. 指名機能が無効な場合: max_capacity 入力フィールドが表示され、
 *    非指名向けの説明文（スタッフ自動割り当て時の相乗り人数）が出力される
 * 4. 指名機能の有効/無効いずれの場合も: max_capacity の値を変更して保存し、
 *    再度読み込むと値が保持されている（回帰確認）
 */
import { test, expect } from '@playwright/test';
import {
	wpCli,
	wpEvalPhp,
	setStaffEnabled,
	getServiceMenuId,
	extractOpeningTagById,
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

/**
 * このメニューで指名機能を使うか（_vkbm_disable_nomination）を明示的に設定するヘルパー。
 * 未設定（既定）に戻したい場合は post meta を削除する。
 *
 * @param menuId  サービスメニューの投稿ID
 * @param disable true の場合はこのメニューで指名を使わない
 */
function setMenuDisableNomination( menuId: string, disable: boolean ): void {
	if ( disable ) {
		wpCli( `post meta update ${ menuId } _vkbm_disable_nomination 1` );
	} else {
		try {
			wpCli( `post meta delete ${ menuId } _vkbm_disable_nomination` );
		} catch ( e ) {
			// 既定（指名を使う）状態ではこのメタキーが元々存在しないため、
			// WP-CLI の削除失敗（Failed to delete custom field）は無視してよい。
		}
	}
}

test.describe( 'issue #392: 指名を使うメニューでもmax_capacityフィールドを表示する（WP-CLI検証）', () => {
	const menuId = getServiceMenuId();

	// 各テスト後に指名機能・メニュー単位の指名設定をデフォルト状態へ戻す
	// テスト途中で assertion が失敗しても次のテストに状態が漏れないよう afterEach で復元する
	// Restore nomination feature and menu-level nomination setting to default state after each test
	// Using afterEach ensures state is restored even if an assertion fails mid-test
	test.afterEach( () => {
		setStaffEnabled( true );
		setMenuDisableNomination( menuId, false );
	} );

	test( '指名機能が有効な場合: max_capacity入力フィールドが表示され、指名向けの説明文が出力される', () => {
		// --- 準備: サイト全体・このメニューともに指名機能を使う状態にする ---
		// Setup: Enable the nomination feature site-wide, and make sure this menu
		// does not opt out of nomination (uses the default "use nomination" state).
		setStaffEnabled( true );
		setMenuDisableNomination( menuId, false );

		// メタボックスの HTML 出力を取得
		// Get the HTML output of the metabox
		const html = getConditionsMetaboxHtml( menuId );

		// #392: max_capacity フィールドは指名の有無に関わらず常に表示される（hidden 属性が付かない）。
		// #392: the max_capacity field is always shown regardless of nomination
		// (no `hidden` attribute).
		const maxCapacityTag = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTag ).not.toBe( '' );
		expect( maxCapacityTag ).not.toContain( 'hidden' );

		// 入力フィールド自体も出力されていることを確認する。
		// Verify the input field itself is present.
		expect( html ).toContain( 'id="vkbm_service_menu_max_capacity"' );
		expect( html ).toContain( 'name="vkbm_service_menu[max_capacity]"' );

		// #392: 指名向けの説明文（1組の最大人数・スタッフが枠を専有する旨）が出力される。
		// ja ロケールの翻訳文言をアサートする（英語原文の直接アサートはしない）。
		// #392: the nomination-specific description (max group size per booking,
		// staff becomes fully booked for the group) is present. Assert against
		// the actual ja translation, not the untranslated English source.
		expect( html ).toContain(
			'スタッフを指名した予約で、1回の予約にまとめて申し込める最大人数です。'
		);
		expect( html ).toContain(
			'その時間帯は貸切（その1組専用）扱いとなり、他のお客様と共有されません。'
		);

		// 旧仕様（PR #163・#412）の非指名向け説明文は出力されない。
		// ja ロケールの翻訳文言で否定チェックする（英語原文は実際には描画されないため
		// 英語のままだと常に成立してしまい、検証になっていなかった）。
		// The non-nomination description from before #392 is not present.
		// Assert against the actual ja translation — the untranslated English
		// source never renders, so checking it would trivially always pass.
		expect( html ).not.toContain(
			'スタッフ指名なし（自動割り当て）の予約で、1つの予約枠を共有できる最大人数です。'
		);
	} );

	test( '指名機能が有効な場合: 旧仕様の案内メッセージ要素は撤去されている（回帰確認）', () => {
		setStaffEnabled( true );
		setMenuDisableNomination( menuId, false );

		const html = getConditionsMetaboxHtml( menuId );

		// #392: 「指名機能を使っているため定員設定は使えない」案内メッセージ
		// （id="vkbm-nomination-enabled-notice"）自体を撤去した。hidden 属性で隠すのではなく
		// 要素そのものが DOM に存在しないことを確認する。
		// #392: the old guidance message element itself has been removed (not
		// merely hidden). Verify the element is entirely absent from the DOM.
		expect(
			extractOpeningTagById( html, 'vkbm-nomination-enabled-notice' )
		).toBe( '' );
	} );

	test( '指名機能が無効な場合: max_capacity入力フィールドが表示され、非指名向けの説明文が出力される', () => {
		// --- 準備: 指名機能を無効にする ---
		// Setup: Disable the nomination feature
		setStaffEnabled( false );

		// メタボックスの HTML 出力を取得
		// Get the HTML output of the metabox
		const html = getConditionsMetaboxHtml( menuId );

		// max_capacity 入力フィールドが出力されていることを確認
		// Verify max_capacity input field IS present in the output
		expect( html ).toContain( 'id="vkbm_service_menu_max_capacity"' );
		expect( html ).toContain( 'name="vkbm_service_menu[max_capacity]"' );
		expect( html ).toContain( 'type="number"' );

		// フィールドの表示/非表示は hidden 属性で切り替わるため、
		// 「入力欄が表示されている」ことは hidden 属性が付いていないことで検証する。
		// Visibility toggles via the `hidden` attribute, so verify the field is
		// visible by the absence of `hidden`.
		const maxCapacityTag = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTag ).not.toBe( '' );
		expect( maxCapacityTag ).not.toContain( 'hidden' );

		// 非指名向けの説明文（スタッフ自動割り当て時の相乗り人数）が出力される。
		// ja ロケールの翻訳文言をアサートする（この文言は#392より前から翻訳済み）。
		// The non-nomination description (max people sharing a slot under
		// auto-assignment) is present. This string already had a ja translation
		// before #392, so assert against the actual translated text.
		expect( html ).toContain(
			'スタッフ指名なし（自動割り当て）の予約で、1つの予約枠を共有できる最大人数です。'
		);

		// 指名向けの説明文は出力されない。
		// ja ロケールの翻訳文言で否定チェックする（理由は上のコメントと同じ）。
		// The nomination-specific description is not present. Assert against
		// the actual ja translation for the same reason as above.
		expect( html ).not.toContain(
			'スタッフを指名した予約で、1回の予約にまとめて申し込める最大人数です。'
		);

		// #392: 旧仕様の案内メッセージ要素も、指名OFF時から一貫して DOM に存在しない。
		// The old guidance message element is likewise absent when nomination is
		// disabled.
		expect(
			extractOpeningTagById( html, 'vkbm-nomination-enabled-notice' )
		).toBe( '' );
	} );

	// #392: 値の保持は「1組の最大人数」として使われる指名ON側でこそ重要な回帰確認のため、
	// 指名の有効/無効の両方でテストする。
	// #392: Since the value is now meaningfully used as "max group size" even
	// when nomination is enabled, verify persistence in both nomination states.
	for ( const nominationEnabled of [ true, false ] ) {
		test( `max_capacityの値をWP-CLIで保存すると値が保持される（回帰確認・指名${
			nominationEnabled ? '有効' : '無効'
		}）`, () => {
			setStaffEnabled( nominationEnabled );
			setMenuDisableNomination( menuId, false );

			// テスト前の元の値を退避（assertion 失敗時にも必ず復元するため）
			// Save original value before test (to ensure restoration even on assertion failure)
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
				expect( html ).toContain(
					'id="vkbm_service_menu_max_capacity"'
				);
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
	}
} );
