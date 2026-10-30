import { test, expect } from '@playwright/test';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

test( '押せない日は薄く表示され、押しても選択日と枠一覧が変わらない', async ( {
	page,
} ) => {
	// @step 予約ページを開き、サービスメニュー一覧の「予約する」ボタンを押してカレンダーを表示する
	await page.goto( '/booking/' );
	const calendarMetaResponse = page.waitForResponse( ( response ) =>
		response.url().includes( '/vkbm/v1/calendar-meta' )
	);
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await calendarMetaResponse;

	// @step カレンダーで押せない日を探す（今月に無ければ翌月へ進む）
	let disabledDay = page
		.locator(
			'.vkbm-calendar__day--disabled:not(.vkbm-calendar__day--muted)'
		)
		.first();
	if ( ( await disabledDay.count() ) === 0 ) {
		await page.locator( '.vkbm-calendar__nav' ).first().click();
		disabledDay = page
			.locator(
				'.vkbm-calendar__day--disabled:not(.vkbm-calendar__day--muted)'
			)
			.first();
	}
	// @check 押せない日は押せない状態で、薄く表示されている
	await expect( disabledDay ).toBeVisible();
	await expect( disabledDay ).toBeDisabled();
	await expect( disabledDay ).toHaveCSS( 'opacity', '0.4' );

	// disabled の状態を保ったまま通常のクリック操作を強制し、アプリ側の選択状態が変わらないことを確かめる。
	// @step 押せない日をクリックする
	const selectedBefore = await page
		.locator( '.vkbm-calendar__day--selected' )
		.count();
	const slotsBefore = await page.locator( '.vkbm-slot-list' ).textContent();
	await disabledDay.click( { force: true } );
	// @check 選択中の日と予約枠の一覧が変わらない
	expect(
		await page.locator( '.vkbm-calendar__day--selected' ).count()
	).toBe( selectedBefore );
	expect( await page.locator( '.vkbm-slot-list' ).textContent() ).toBe(
		slotsBefore
	);

	// 既存の予約フロー用ヘルパーでも、押せる日を選択できることを回帰確認する。
	// @step 押せる日を選ぶ
	await selectAvailableCalendarDay( page );
	// @check 選択中の日が1日だけになる
	await expect( page.locator( '.vkbm-calendar__day--selected' ) ).toHaveCount(
		1
	);
} );
