/**
 * @file
 * 予約確認画面の同意欄（キャンセルポリシー・利用規約）にチェックを入れる共通ヘルパー。
 *
 * 背景（#816）: 確認画面は、まとめ（.vkbm-confirm__summary）を先に描画し、
 * ポリシー・規約の文面は REST（/vkbm/v1/provider-settings）の応答後に描画する。
 * そのため「まとめが見えた直後に isVisible() で一度だけ確認する」書き方だと、
 * 応答が遅い環境で同意欄がまだ出ておらず、チェックを飛ばして予約ボタンが
 * 無効のまま失敗することがあった。
 */

import type { Page } from '@playwright/test';

/** 同意欄ごとのチェックボックスと、provider-settings 応答のキー。 */
const CONSENT_FIELDS = [
	{
		selector: '#vkbm-confirm-cancellation-policy',
		settingKey: 'cancellation_policy',
	},
	{ selector: '#vkbm-confirm-terms', settingKey: 'terms_of_service' },
] as const;

/**
 * 確認画面の同意欄に、表示されるべきものだけチェックを入れる。
 *
 * 「同意欄が出るか」はフロントの描画タイミングではなく、画面が文面の取得元に
 * している REST（/vkbm/v1/provider-settings）の内容で先に確定させる。
 * - 文面が設定されている欄: チェックボックスが描画されるまで待ってからチェックする
 *   （固定時間の待ちは使わない。描画されなければタイムアウトで失敗させる）。
 * - 文面が空の欄: 同意欄は出ないため、待たずに何もしない。
 *
 * 確認画面（.vkbm-confirm__summary が見えた後）で呼び出すこと。
 *
 * @param page    確認画面を開いている Page
 * @param timeout 同意欄の描画を待つ最大ミリ秒（既定 15000）
 */
export const checkConfirmConsents = async (
	page: Page,
	timeout = 15000
): Promise< void > => {
	// 文面の有無を、画面と同じ公開 REST から取得する。
	const response = await page.request.get(
		'/wp-json/vkbm/v1/provider-settings'
	);
	if ( ! response.ok() ) {
		throw new Error(
			`provider-settings の取得に失敗しました: HTTP ${ response.status() }`
		);
	}
	const settings = ( await response.json() ) as Record< string, unknown >;

	for ( const field of CONSENT_FIELDS ) {
		const text = settings[ field.settingKey ];
		const hasText = typeof text === 'string' && text.trim() !== '';
		if ( ! hasText ) {
			// 文面が無い欄は同意欄が出ない。待たずに次へ進む。
			continue;
		}
		const checkbox = page.locator( field.selector );
		// 文面の描画完了（=チェックボックスの出現）を待ってからチェックする。
		await checkbox.waitFor( { state: 'visible', timeout } );
		await checkbox.check();
	}
};
