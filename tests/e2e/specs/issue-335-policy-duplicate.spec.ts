/**
 * issue #335: 予約確認画面でキャンセルポリシー案内文が2重表示される不具合のフロント検証。
 *
 * 不具合の内容:
 * - キャンセルポリシー案内文（`p.vkbm-confirm__policy` / 文言 "Please check our cancellation policy
 *   before booking." = ja「予約前にキャンセルポリシーをご確認ください。」）が、
 *   予約確認画面で 2 箇所に表示されてしまう。
 * - 原因: booking-confirm-app.js で policyText が
 *     (1) JSX 直書きの `<p className="vkbm-confirm__policy">`（confirm 本体）と
 *     (2) renderAgreements() 内の `{ ! termsOfServiceText && policyText && ... }`
 *   の 2 箇所から出力されていた。両方の表示条件が同時に真になる状況で二重表示になる。
 *
 * 二重表示が発生する再現条件（両方の分岐が同時に真になる条件）:
 * - キャンセルポリシー（provider_cancellation_policy）: 未入力
 * - 利用規約（provider_terms_of_service）: 未入力
 *     → termsOfServiceText が空 = renderAgreements() 内の policyText 分岐が真になる
 * - 決済方法テキスト（provider_payment_method）: 入力あり
 *     → hasAgreements が真 = renderAgreements() が描画される（分岐に到達する）
 * - 予約管理権限を持たないユーザー（canManageReservations = false）
 *     → 未ログインでも canManageReservations は false なので policyText 分岐に入る。
 *       本 spec は未ログイン（storageState 空）で予約確認画面まで到達して検証する。
 *
 * 修正後の期待:
 * - `p.vkbm-confirm__policy` の要素数はちょうど 1（renderAgreements() 内の 1 箇所に集約）。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - beforeAll でプロバイダー設定を上記の再現条件へ切り替え、afterAll で
 *   global-setup が投入する既定値へ復元して他 spec への状態リークを防ぐ。
 */
import { test, expect, Page } from '@playwright/test';
import { wpEvalPhp } from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// キャンセルポリシー案内文（policyText）の ja ロケール文言。
// これが `p.vkbm-confirm__policy` として描画される。
// The cancellation-policy notice text (policyText) in ja locale.
const POLICY_NOTICE_JA = '予約前にキャンセルポリシーをご確認ください。';

// 決済方法テキスト（再現条件: 入力ありにして renderAgreements() を描画させる）。
// Payment method text (must be non-empty so renderAgreements() renders).
const PAYMENT_METHOD_TEXT = '現地にて現金でお支払いください。';

// キャンセルポリシー本文（回帰確認: 設定した場合の agreement 表示確認用）。
// Cancellation policy body (regression check when a policy is configured).
const CANCELLATION_POLICY_TEXT = '3日前までにご連絡ください。';

// global-setup が投入する既定値（afterAll での復元先）。
// Defaults seeded by global-setup, restored in afterAll.
const DEFAULT_TERMS = '利用規約に同意してください。';
const DEFAULT_CANCELLATION = 'キャンセルポリシーに同意してください。';

/**
 * global-setup で作成済みの "Service Menu 1"（料金なし・スタッフ割当済み）を使って
 * 予約ページを開き、日付・スロットまで進めて確認画面を開くヘルパー。
 * Open the booking page using the "Service Menu 1" seeded by global-setup,
 * pick a date/slot and open the confirm screen.
 */
async function gotoConfirmScreen( page: Page ) {
	// baseURL（playwright.config）に対する相対パスで開く。
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'networkidle' );

	// 予約ボタン（メニューカードの「予約に進む」）の出現を待つ。
	const reserveButtons = page.locator( '.vkbm-menu-loop__button--reserve' );
	await reserveButtons
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );

	// 予約ボタンのクリック前に calendar-meta のレスポンス待ちをセットアップする。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	// 最初のメニューの予約ボタンを押す。
	await reserveButtons.first().click();

	// 空き枠のある日を選ぶ（共有ヘルパーでフレーク回避）。
	await selectAvailableCalendarDay( page, calendarMetaResponse );

	// 最初の時間枠を選ぶ（出現を待ってからクリック）。
	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();

	// スロット選択後、「予約へ進む」が活性化するのを待ってクリックする。
	const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( proceed ).toBeEnabled( { timeout: 10000 } );
	await proceed.click();
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

	// 確認画面の表示を待つ。
	const summary = page.locator( '.vkbm-confirm__summary, .vkbm-confirm' );
	await summary.first().waitFor( { state: 'visible', timeout: 15000 } );
}

test.describe( 'issue #335: 予約確認画面のキャンセルポリシー案内文が二重表示されない', () => {
	// 未ログイン（canManageReservations = false）で検証する。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.afterEach( async () => {
		// 各テストで設定を書き換えるため、テスト後に global-setup の既定値へ復元して
		// テスト間・他 spec への状態リークを防ぐ。
		await configureProviderSettings( {
			provider_cancellation_policy: DEFAULT_CANCELLATION,
			provider_terms_of_service: DEFAULT_TERMS,
			provider_payment_method: '',
		} );
	} );

	test( '再現条件（規約・ポリシー未入力＋決済方法あり）で案内文がちょうど 1 箇所だけ表示される', async ( {
		page,
	} ) => {
		// 再現条件へ設定を切り替える:
		// - キャンセルポリシー: 空 / 利用規約: 空 / 決済方法: 入力あり
		await configureProviderSettings( {
			provider_cancellation_policy: '',
			provider_terms_of_service: '',
			provider_payment_method: PAYMENT_METHOD_TEXT,
		} );

		await gotoConfirmScreen( page );

		// 案内文（p.vkbm-confirm__policy）の要素数を検証する（期待: ちょうど 1）。
		// 修正前はここが 2 になり FAIL する（JSX 直書き＋renderAgreements() 内の 2 箇所）。
		const policy = page.locator( 'p.vkbm-confirm__policy' );
		await expect( policy.first() ).toBeVisible( { timeout: 10000 } );
		await expect( policy ).toHaveCount( 1 );
		// 文言も想定どおりであることを確認する。
		await expect( policy ).toContainText( POLICY_NOTICE_JA );

		// 決済方法テキストは agreement として表示されている（再現条件が満たされている確証）。
		await expect(
			page.locator( '.vkbm-agreements', { hasText: PAYMENT_METHOD_TEXT } )
		).toBeVisible();

		// スクリーンショット（案内文が 1 箇所の確認画面）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/issue335-after-confirm-single-policy.png',
			fullPage: true,
		} );
	} );

	test( '回帰: キャンセルポリシーを設定すると本文＋同意チェックが表示され案内文は増えない', async ( {
		page,
	} ) => {
		// キャンセルポリシー本文を設定（利用規約は空のまま・決済方法あり）。
		await configureProviderSettings( {
			provider_cancellation_policy: CANCELLATION_POLICY_TEXT,
			provider_terms_of_service: '',
			provider_payment_method: PAYMENT_METHOD_TEXT,
		} );

		await gotoConfirmScreen( page );

		// キャンセルポリシーの agreement 本文が表示される。
		await expect(
			page.locator( '.vkbm-agreement', {
				hasText: CANCELLATION_POLICY_TEXT,
			} )
		).toBeVisible( { timeout: 10000 } );
		// 同意チェックボックスが表示される。
		await expect(
			page.locator( '#vkbm-confirm-cancellation-policy' )
		).toBeVisible();

		// 案内文（p.vkbm-confirm__policy）はキャンセルポリシー設定時には出さない仕様。
		// いずれにせよ二重表示（2 箇所以上）にならないことを担保する。
		await expect(
			page.locator( 'p.vkbm-confirm__policy' ).count()
		).resolves.toBeLessThanOrEqual( 1 );
	} );
} );
