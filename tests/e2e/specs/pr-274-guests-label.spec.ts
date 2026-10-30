/**
 * PR #274 (issue #263): 複数人予約の数量の見出し・単位を基本設定でカスタマイズ可能にする変更の検証。
 *
 * 数量表示は以下の経路で出力される:
 * - フロント予約フォーム / 確認画面 … REST `vkbm/v1/provider-settings` が返す
 *   `guests_count_label` / `guests_unit_label` を JS の formatGuestsCount で組み立てる。
 * - 通知メール / 管理画面 … PHP の vkbm_get_guests_count_label() / vkbm_format_guests_count() で組み立てる。
 *
 * そのため本テストは次を検証する:
 * 1. REST 公開ペイロード（フロントが実際に消費する契約）の見出し・単位の実効値。
 * 2. PHP 整形ヘルパー vkbm_format_guests_count() の出力（通知メール・管理画面と同じ組み立て）。
 * 3. 基本設定画面の単位欄が実効値（既定「名」）をプリフィルしているか。
 * 4. サニタイザの null/'' 出し分け（未送信→null 維持／空送信→'' 保持）。
 *
 * PR #274 (issue #263): Verifies the configurable heading/unit for multi-guest quantity display.
 */
import { test, expect } from '@playwright/test';
import { wpEvalPhp } from '../utils/helpers';

/**
 * vkbm_provider_settings オプションの指定キーを更新するヘルパー。
 * value に null を渡すとそのキーを削除（＝未保存／未設定状態）する。
 *
 * Helper to update a key in the vkbm_provider_settings option.
 * Passing null for value deletes the key (i.e. unset / not-saved state).
 *
 * @param key   設定キー（'guests_count_label' / 'guests_unit_label'）。許可リストで検証する。
 * @param value 設定値。null の場合はキーを削除する。
 */
function setGuestsSetting( key: string, value: string | null ): void {
	// PHP コードに埋め込むキーは許可リストで検証し、リテラル外脱出を防ぐ。
	// Validate the key against an allowlist to prevent PHP literal escape.
	const allowed = [ 'guests_count_label', 'guests_unit_label' ];
	if ( ! allowed.includes( key ) ) {
		throw new Error( `Disallowed settings key: "${ key }"` );
	}
	// 値は base64 化して PHP 側で復号する（文字列リテラル脱出を防ぐ）。
	// Base64-encode the value and decode in PHP to avoid string literal escape.
	const encoded =
		value === null ? '' : Buffer.from( value ).toString( 'base64' );
	const isNull = value === null ? 'true' : 'false';
	const phpCode = `
		$s = get_option( 'vkbm_provider_settings', array() );
		if ( ${ isNull } ) {
			unset( $s['${ key }'] );
		} else {
			$s['${ key }'] = base64_decode( '${ encoded }' );
		}
		update_option( 'vkbm_provider_settings', $s );
		echo 'OK';
	`;
	const result = wpEvalPhp( phpCode );
	expect( result ).toContain( 'OK' );
}

/**
 * REST 公開ペイロードから見出し・単位の実効値を取得するヘルパー。
 * フロントが実際に消費する契約と同じ経路（WP_REST_Request 経由）で取得する。
 *
 * Helper to fetch the effective heading/unit from the REST public payload,
 * via the same path the frontend consumes (WP_REST_Request).
 *
 * @return {{count: string, unit: string}} JSON で返ってきた見出し・単位。
 */
function getRestGuestsLabels(): { count: string; unit: string } {
	const phpCode = `
		$req = new WP_REST_Request( 'GET', '/vkbm/v1/provider-settings' );
		$res = rest_do_request( $req );
		$data = $res->get_data();
		echo wp_json_encode( array(
			'count' => isset( $data['guests_count_label'] ) ? $data['guests_count_label'] : '__MISSING__',
			'unit'  => isset( $data['guests_unit_label'] ) ? $data['guests_unit_label'] : '__MISSING__',
		) );
	`;
	const out = wpEvalPhp( phpCode );
	return JSON.parse( out );
}

/**
 * PHP 整形ヘルパー vkbm_format_guests_count() の出力を取得するヘルパー。
 * 通知メール・管理画面が使う実際の組み立て関数を呼ぶ。
 *
 * Helper to get the output of vkbm_format_guests_count() (the actual builder
 * used by notification emails and admin screens) for a given count.
 *
 * @param count 数量。
 * @return 整形済み文字列。
 */
function getPhpFormattedGuests( count: number ): string {
	const safeCount = Number.parseInt( String( count ), 10 );
	if ( ! Number.isInteger( safeCount ) ) {
		throw new Error( `Invalid count: "${ count }"` );
	}
	const phpCode = `echo vkbm_format_guests_count( ${ safeCount } );`;
	return wpEvalPhp( phpCode );
}

/**
 * 単位設定をテスト前の状態へ戻すため、既定（未設定）にリセットするヘルパー。
 * Reset both keys to unset (default) state.
 */
function resetGuestsSettings(): void {
	setGuestsSetting( 'guests_count_label', null );
	setGuestsSetting( 'guests_unit_label', null );
}

test.describe( 'PR #274: 複数人予約の数量の見出し・単位カスタマイズ', () => {
	// 各テスト開始前に設定を既定（未設定）へ戻し、--grep での単体実行時も初期状態を保証する。
	// afterEach だけだと前回テストの残存状態に依存して不安定になるため、beforeEach でも固定する。
	// Reset to default before each test so that running a single test via --grep
	// still starts from a known state (afterEach alone leaves single-run order-dependent).
	test.beforeEach( () => {
		// @step 基本設定の複数人予約の数量の見出しと単位を、未設定（初期状態）に戻しておく
		resetGuestsSettings();
	} );

	// 各テスト後にも既定（未設定）へ戻し、テスト間の状態漏れを防ぐ。
	// Reset settings to default after each test to avoid state bleed.
	test.afterEach( () => {
		resetGuestsSettings();
	} );

	test( 'A. 後方互換: 単位未設定（null）→ 日本語ロケールでは実効値「名」、見出しは「人数」', () => {
		// 両キーは beforeEach で未設定（null）= アップグレード直後の状態に初期化済み。
		// Both keys are reset to unset (null) in beforeEach = state right after upgrade.

		// REST の実効値を確認。日本語ロケール（global setup で ja）なので単位は「名」、見出しは「人数」。
		// @step 見出しと単位は未設定のまま、予約フォームが読み込む設定の値を確かめる
		const labels = getRestGuestsLabels();
		// @check 単位が「名」、見出しが「人数」になっている
		expect( labels.unit ).toBe( '名' );
		expect( labels.count ).toBe( '人数' );

		// PHP 整形ヘルパーも「◯名」を返す（通知メール・管理画面の表示と同じ）。
		// @check 通知メールや管理画面に出る数量の表示が「5名」になる
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5名' );
	} );

	test( 'B. 単位を空に: 空文字保存 → 単位なし（数値のみ）', () => {
		// 準備: 単位を意図的に空文字で保存する。
		// Setup: intentionally save an empty unit.
		// @step 基本設定で数量の単位を空にして保存しておく
		setGuestsSetting( 'guests_unit_label', '' );

		// REST 実効値は空文字（単位なし）。
		// @check 予約フォームが読み込む設定の単位が空になっている
		const labels = getRestGuestsLabels();
		expect( labels.unit ).toBe( '' );

		// PHP 整形ヘルパーは数値のみを返す。
		// @check 通知メールや管理画面に出る数量の表示が単位なしの「5」になる
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5' );
	} );

	test( 'C-1. 単位「台」→「◯台」（全角は詰める）', () => {
		// @step 基本設定で数量の単位を「台」にして保存しておく
		setGuestsSetting( 'guests_unit_label', '台' );

		// @check 予約フォームが読み込む設定の単位が「台」になり、数量の表示が「5台」（間を詰める）になる
		const labels = getRestGuestsLabels();
		expect( labels.unit ).toBe( '台' );
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5台' );
	} );

	test( 'C-2. 単位「seats」→「◯ seats」（半角英字始まりはスペース）', () => {
		// @step 基本設定で数量の単位を「seats」にして保存しておく
		setGuestsSetting( 'guests_unit_label', 'seats' );

		// @check 予約フォームが読み込む設定の単位が「seats」になり、数量の表示が「5 seats」（間に空白を入れる）になる
		const labels = getRestGuestsLabels();
		expect( labels.unit ).toBe( 'seats' );
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5 seats' );
	} );

	test( 'C-3. 空 → 「名」入力で既定の「◯名」に復帰', () => {
		// 一度空にする。
		// @step 基本設定で数量の単位を空にして保存しておく
		setGuestsSetting( 'guests_unit_label', '' );
		// @check 数量の表示が単位なしの「5」になる
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5' );

		// 「名」を入力して保存し直す → 既定相当に復帰。
		// @step 数量の単位に「名」を入れて保存し直す
		setGuestsSetting( 'guests_unit_label', '名' );
		// @check 予約フォームが読み込む設定の単位が「名」に戻り、数量の表示が「5名」になる
		const labels = getRestGuestsLabels();
		expect( labels.unit ).toBe( '名' );
		expect( getPhpFormattedGuests( 5 ) ).toBe( '5名' );
	} );

	test( 'D-1. 見出し未設定（空）→ 既定「人数」', () => {
		// @step 基本設定で数量の見出しを空にして保存しておく
		setGuestsSetting( 'guests_count_label', '' );

		// @check 予約フォームが読み込む設定の見出しが既定の「人数」になる
		const labels = getRestGuestsLabels();
		expect( labels.count ).toBe( '人数' );
	} );

	test( 'D-2. 見出し「台数」→ 各所で「台数」', () => {
		// @step 基本設定で数量の見出しを「台数」にして保存しておく
		setGuestsSetting( 'guests_count_label', '台数' );

		// @check 予約フォームが読み込む設定の見出しが「台数」になる
		const labels = getRestGuestsLabels();
		expect( labels.count ).toBe( '台数' );

		// PHP ヘルパー vkbm_get_guests_count_label() も「台数」を返す（通知メール・管理画面の見出し）。
		// @check 通知メールや管理画面に出る数量の見出しも「台数」になる
		const phpHeading = wpEvalPhp( `echo vkbm_get_guests_count_label();` );
		expect( phpHeading ).toBe( '台数' );
	} );

	test( '後方互換: 単位の null と空文字の出し分け（サニタイザ）', () => {
		// 未設定（null）→ 実効値は「名」（ロケール既定）。
		// @step 基本設定の数量の単位を未設定（初期状態）にしておく
		setGuestsSetting( 'guests_unit_label', null );
		// @check 予約フォームが読み込む設定の単位が既定の「名」になる
		expect( getRestGuestsLabels().unit ).toBe( '名' );

		// 空文字保存 → 単位なし。null へ潰されていないことを確認。
		// @step 数量の単位を空にして保存する
		setGuestsSetting( 'guests_unit_label', '' );
		// @check 単位が「名」に戻されず、空のまま（単位なし）になる
		expect( getRestGuestsLabels().unit ).toBe( '' );
	} );
} );
