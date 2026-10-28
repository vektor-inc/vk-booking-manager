import { test, expect } from '@playwright/test';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

test( '押せない日は薄く表示され、押しても選択日と枠一覧が変わらない', async ( {
	page,
} ) => {
	await page.goto( '/booking/' );
	const calendarMetaResponse = page.waitForResponse( ( response ) =>
		response.url().includes( '/vkbm/v1/calendar-meta' )
	);
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await calendarMetaResponse;

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
	await expect( disabledDay ).toBeVisible();
	await expect( disabledDay ).toBeDisabled();
	await expect( disabledDay ).toHaveCSS( 'opacity', '0.4' );

	// disabled の状態を保ったまま通常のクリック操作を強制し、アプリ側の選択状態が変わらないことを確かめる。
	const selectedBefore = await page
		.locator( '.vkbm-calendar__day--selected' )
		.count();
	const slotsBefore = await page.locator( '.vkbm-slot-list' ).textContent();
	await disabledDay.click( { force: true } );
	expect(
		await page.locator( '.vkbm-calendar__day--selected' ).count()
	).toBe( selectedBefore );
	expect( await page.locator( '.vkbm-slot-list' ).textContent() ).toBe(
		slotsBefore
	);

	// 既存の予約フロー用ヘルパーでも、押せる日を選択できることを回帰確認する。
	await selectAvailableCalendarDay( page );
	await expect( page.locator( '.vkbm-calendar__day--selected' ) ).toHaveCount(
		1
	);
} );
