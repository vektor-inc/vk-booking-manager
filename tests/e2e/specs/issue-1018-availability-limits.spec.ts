// @category 予約画面・カレンダー
import { test, expect } from '@playwright/test';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

const calendarUrl = /\/vkbm\/v1\/calendar-meta[?]/;
const dailyUrl = /\/vkbm\/v1\/availabilities[?]/;

test( 'PC幅で受付案内と429の表示を切り替えても左右の列を保つ', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	let limited = false;
	let lastDate = '';
	// @step 翌月を受付最終月にし、月移動で案内を表示・非表示にする
	await page.route( calendarUrl, async ( route ) => {
		if ( limited ) {
			await route.fulfill( {
				status: 429,
				json: { code: 'rate_limited', data: { status: 429 } },
			} );
			return;
		}
		const response = await route.fetch();
		const body = await response.json();
		if ( ! lastDate ) {
			const url = new URL( route.request().url() );
			const next = new Date(
				Number( url.searchParams.get( 'year' ) ),
				Number( url.searchParams.get( 'month' ) ),
				1
			);
			lastDate = `${ next.getFullYear() }-${ String(
				next.getMonth() + 1
			).padStart( 2, '0' ) }-28`;
		}
		body.booking_range = {
			first_date: '2000-01-01',
			last_date: lastDate,
			in_range: true,
		};
		await route.fulfill( { response, json: body } );
	} );
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	const calendar = page.locator( '.vkbm-calendar' );
	const slots = page.locator( '.vkbm-reservation-content__slots' );
	const notice = page.locator( '.vkbm-availability-notice' );
	await expect( calendar ).toBeVisible();
	await expect( page.locator( '.vkbm-calendar__spinner' ) ).toHaveCount( 0 );
	await expect( notice ).toHaveCount( 0 );
	const initialCalendar = ( await calendar.boundingBox() )!;
	const initialSlots = ( await slots.boundingBox() )!;
	const body = page.locator( '.vkbm-reservation-content__body' );
	expect( initialSlots.x ).toBeGreaterThan(
		initialCalendar.x + initialCalendar.width
	);
	const assertColumns = async () => {
		const left = ( await calendar.boundingBox() )!;
		const right = ( await slots.boundingBox() )!;
		expect( left.x ).toBeCloseTo( initialCalendar.x, 0 );
		expect( right.x ).toBeCloseTo( initialSlots.x, 0 );
		expect( left.y ).toBeCloseTo( right.y, 0 );
		if ( await notice.count() ) {
			const box = ( await notice.boundingBox() )!;
			expect( box.x ).toBeCloseTo( left.x, 0 );
			expect( box.x + box.width ).toBeCloseTo( right.x + right.width, 0 );
			expect( box.height ).toBeLessThan( left.height / 2 );
			expect( left.y - box.y - box.height ).toBeCloseTo( 24, 0 );
		} else {
			// @check 空の案内による余分な行やgapが残らない
			const offset = await body.evaluate( ( element ) => {
				const calendarElement =
					element.querySelector( '.vkbm-calendar' )!;
				return (
					calendarElement.getBoundingClientRect().top -
					element.getBoundingClientRect().top
				);
			} );
			expect( offset ).toBeCloseTo( 0, 0 );
		}
	};
	await calendar.locator( '.vkbm-calendar__nav' ).last().click();
	await expect( notice ).toContainText( '予約できる月はここまでです' );
	await assertColumns();
	await calendar.locator( '.vkbm-calendar__nav' ).first().click();
	await expect( notice ).toHaveCount( 0 );
	await assertColumns();
	// @step 月次APIを429にしてから回復させ、同じ列位置と内容に合う高さを確かめる
	limited = true;
	await calendar.locator( '.vkbm-calendar__nav' ).last().click();
	await expect( notice.getByRole( 'button' ) ).toBeVisible();
	await assertColumns();
	// 通信中に制限を解除すると案内が切り替わるため、完了してから再試行する。
	await expect( notice.getByRole( 'button' ) ).toHaveAttribute(
		'aria-busy',
		'false'
	);
	limited = false;
	await notice.getByRole( 'button' ).click();
	await expect( notice ).toContainText( '予約できる月はここまでです' );
	await expect( notice.getByRole( 'button' ) ).toHaveCount( 0 );
	await assertColumns();
	await calendar.locator( '.vkbm-calendar__nav' ).first().click();
	await expect( notice ).toHaveCount( 0 );
	await assertColumns();
	await page.unrouteAll( { behavior: 'wait' } );
} );

test( '429でも選択日を保ち、手動で再試行できる', async ( { page } ) => {
	// @step カレンダーで日を選ぶ直前に、日別APIだけ一度429を返す
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible();
	let limited = true;
	let hold = false;
	const pending: Array< () => void > = [];
	await page.route( dailyUrl, async ( route ) => {
		if ( hold ) {
			await new Promise< void >( ( resolve ) => pending.push( resolve ) );
		}
		if ( limited ) {
			await route.fulfill( {
				status: 429,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'rate_limited',
					data: { status: 429 },
				} ),
			} );
		} else {
			await route.continue();
		}
	} );
	await selectAvailableCalendarDay( page );
	const notice = page
		.getByRole( 'alert' )
		.filter( { hasText: '選んだ内容はそのままです' } );
	await expect( notice ).toHaveCount( 1 );
	await page.setViewportSize( { width: 375, height: 812 } );
	await notice.screenshot( { path: '/tmp/1018-notice-mobile.png' } );
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= window.innerWidth
		)
	).toBe( true );
	const spacing = await notice.evaluate( ( element ) => {
		const text = element.querySelector( 'p' )!;
		const button = element.querySelector( 'button' )!;
		const style = getComputedStyle( text );
		const halfLeading =
			( parseFloat( style.lineHeight ) - parseFloat( style.fontSize ) ) /
			2;
		return {
			gap:
				button.getBoundingClientRect().top -
				text.getBoundingClientRect().bottom +
				halfLeading,
			padding: parseFloat( getComputedStyle( element ).paddingTop ),
		};
	} );
	const size = await notice.getByRole( 'button' ).boundingBox();
	expect( size?.height ).toBeGreaterThanOrEqual( 44 );
	expect( size?.width ).toBeGreaterThanOrEqual( 44 );

	expect( spacing.gap ).toBeLessThanOrEqual( spacing.padding );
	console.log(
		`ISSUE1018_LAYOUT text_button_gap=${ spacing.gap } padding=${ spacing.padding }`
	);

	const selected = await page
		.locator( '.vkbm-calendar__day--selected' )
		.textContent();
	// @check 制限中に古い枠や空きなしを表示せず、選択日は残る
	await expect( page.locator( '.vkbm-calendar__day--selected' ) ).toHaveCount(
		1
	);
	await expect( page.locator( '.vkbm-slot-list__item' ) ).toHaveCount( 0 );
	await expect( page.locator( '.vkbm-plan-summary__action' ) ).toBeDisabled();
	// @step 同じ再試行で月次・日次の両方が429になっても通知を重ねない
	await page.route( calendarUrl, async ( route ) => {
		if ( hold ) {
			await new Promise< void >( ( resolve ) => pending.push( resolve ) );
		}
		if ( limited ) {
			await route.fulfill( {
				status: 429,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'rate_limited',
					data: { status: 429 },
				} ),
			} );
		} else {
			await route.continue();
		}
	} );
	hold = true;
	const blocked = Promise.all( [
		page.waitForResponse( calendarUrl ),
		page.waitForResponse( dailyUrl ),
	] );
	await notice.getByRole( 'button', { name: '再試行' } ).click();
	const waiting = notice.getByRole( 'button' );
	await expect( waiting ).toHaveAttribute( 'aria-busy', 'true' );
	await expect( waiting ).toBeFocused();
	await waiting.press( 'Enter' );
	await expect.poll( () => pending.length ).toBe( 2 );
	hold = false;
	pending.forEach( ( resolve ) => resolve() );
	await blocked;
	await expect( notice.getByRole( 'button' ) ).toBeFocused();
	await expect( notice ).toHaveCount( 1 );
	limited = false;
	// @step 再試行ボタンをキーボードで押す
	const retry = notice.getByRole( 'button', { name: '再試行' } );
	await retry.focus();
	await page.keyboard.press( 'Enter' );
	// @check 同じ選択日の枠を読み直せる
	await expect( notice ).toHaveCount( 0 );
	expect(
		await page.evaluate( () =>
			Boolean( document.activeElement?.closest( '.vkbm-calendar' ) )
		)
	).toBe( true );
	await expect(
		page.locator( '.vkbm-slot-list__item' ).first()
	).toBeVisible();
	expect(
		await page.locator( '.vkbm-calendar__day--selected' ).textContent()
	).toBe( selected );
} );

test( '同一月の全日無効カレンダーで再試行成功後に月見出しへ戻る', async ( {
	page,
} ) => {
	let limited = true;
	// @step 最初は429、再試行は同一月内の受付期間と全日無効を返す
	await page.route( calendarUrl, async ( route ) => {
		if ( limited ) {
			await route.fulfill( {
				status: 429,
				json: { code: 'rate_limited', data: { status: 429 } },
			} );
			return;
		}
		const response = await route.fetch();
		const body = await response.json();
		const url = new URL( route.request().url() );
		const month = `${ url.searchParams.get( 'year' ) }-${ String(
			url.searchParams.get( 'month' )
		).padStart( 2, '0' ) }`;
		body.days = body.days.map( ( day ) => ( {
			...day,
			is_disabled: true,
		} ) );
		body.booking_range = {
			first_date: `${ month }-01`,
			last_date: `${ month }-28`,
			in_range: true,
		};
		await route.fulfill( { response, json: body } );
	} );
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	const retry = page.getByRole( 'button', { name: '再試行' } );
	await expect( retry ).toBeVisible();
	limited = false;
	await retry.focus();
	await retry.press( 'Enter' );
	// @check 操作可能な日付も月送りもなく、月見出しがフォーカスを保持する
	await expect( retry ).toHaveCount( 0 );
	await expect(
		page.locator( '.vkbm-calendar__day:not(:disabled)' )
	).toHaveCount( 0 );
	await expect( page.locator( '.vkbm-calendar__current' ) ).toBeFocused();
} );

test( 'サーバーが返した最後の月で次の月を止める', async ( { page } ) => {
	let lastDate = '';
	let limited = true;
	// @step 応答の境界を翌月の末日にして、画面がサーバー情報を使うことを確かめる
	await page.route( calendarUrl, async ( route ) => {
		const url = new URL( route.request().url() );
		if ( ! lastDate ) {
			const next = new Date(
				Number( url.searchParams.get( 'year' ) ),
				Number( url.searchParams.get( 'month' ) ),
				1
			);
			lastDate = `${ next.getFullYear() }-${ String(
				next.getMonth() + 1
			).padStart( 2, '0' ) }-28`;
		}
		if ( limited ) {
			await route.fulfill( {
				status: 429,
				json: { code: 'rate_limited', data: { status: 429 } },
			} );
			return;
		}
		const response = await route.fetch();
		const body = await response.json();

		body.booking_range = {
			first_date: '2000-01-01',
			last_date: lastDate,
			in_range: true,
		};
		await route.fulfill( { response, json: body } );
	} );
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await expect(
		page.getByRole( 'button', { name: '再試行' } )
	).toBeVisible();
	limited = false;
	await page.locator( '.vkbm-calendar__nav' ).last().click();
	// @check 最後の月の理由が読み上げ領域に表示され、次ボタンが操作不可になる
	await expect(
		page
			.getByRole( 'status' )
			.filter( { hasText: '予約できる月はここまでです' } )
	).toBeVisible();
	await expect( page.locator( '.vkbm-calendar__nav' ).last() ).toBeDisabled();
	await expect
		.poll( () =>
			page.locator( '.vkbm-calendar' ).evaluate( ( calendar ) => {
				const target =
					calendar.querySelector(
						'button.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"])'
					) ||
					calendar.querySelector(
						'.vkbm-calendar__nav:not(:disabled)'
					) ||
					calendar.querySelector( '.vkbm-calendar__current' );
				return document.activeElement === target;
			} )
		)
		.toBe( true );
	await expect( page.locator( '.vkbm-calendar__spinner' ) ).toHaveCount( 0 );
	await page.unrouteAll( { behavior: 'wait' } );
	await expect( page.getByRole( 'button', { name: '再試行' } ) ).toHaveCount(
		0
	);
} );

test( '通常操作の空き状況API呼び出し回数を測る', async ( { page } ) => {
	const calls: string[] = [];
	page.on( 'request', ( request ) => {
		if (
			calendarUrl.test( request.url() ) ||
			dailyUrl.test( request.url() )
		) {
			calls.push( request.url() );
		}
	} );
	// @step カレンダーを開き、5か月先へ進んでから5か月戻る
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await expect(
		page.locator( '.vkbm-calendar__day:not(:disabled)' ).first()
	).toBeVisible();
	for ( const direction of [ 1, -1 ] ) {
		for ( let i = 0; i < 5; i++ ) {
			const response = page.waitForResponse( calendarUrl );
			await page
				.locator( '.vkbm-calendar__nav' )
				.nth( direction > 0 ? 1 : 0 )
				.click();
			await response;
			await expect(
				page.locator( '.vkbm-calendar__spinner' )
			).toHaveCount( 0 );
		}
	}
	// @step 同じ月で日付を10回切り替える
	const days = page.locator(
		'button.vkbm-calendar__day--available:not(:disabled)'
	);
	await expect( days.first() ).toBeVisible();
	for ( let i = 0; i < 10; i++ ) {
		const response = page.waitForResponse( dailyUrl );
		await days.nth( i % 2 ).click();
		await response;
	}
	// @check 通常操作が共通上限の範囲に収まる（実測をログに記録する）
	console.log(
		`ISSUE1018_MEASURE calendar=${
			calls.filter( ( url ) => calendarUrl.test( url ) ).length
		} daily=${
			calls.filter( ( url ) => dailyUrl.test( url ) ).length
		} total=${ calls.length }`
	);
	expect( calls.length ).toBeLessThan( 120 );
} );

test( '受付範囲外の月には再試行と選べる日を出さない', async ( { page } ) => {
	// @step 範囲外の空の成功応答を表示する
	await page.route( calendarUrl, async ( route ) => {
		const response = await route.fetch();
		const body = await response.json();
		body.days = [];
		body.booking_range = {
			first_date: '2000-01-01',
			last_date: '2000-01-31',
			in_range: false,
		};
		await route.fulfill( { response, json: body } );
	} );
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	// @check 範囲外の案内があり、日付と再試行から取得を繰り返せない
	await expect(
		page
			.getByRole( 'status' )
			.filter( { hasText: 'この月は予約受付期間外です' } )
	).toBeVisible();
	await expect( page.getByRole( 'button', { name: '再試行' } ) ).toHaveCount(
		0
	);
	await expect(
		page.locator( 'button.vkbm-calendar__day:not(:disabled)' )
	).toHaveCount( 0 );
} );

test( '月次の成功後に遅れた日次が429でも再試行のフォーカスを保つ', async ( {
	page,
} ) => {
	// @step 最初は月次だけ429にし、選択済みの日次は成功させる。
	await page.goto( '/booking/' );
	await page.locator( '.vkbm-menu-loop__button--reserve' ).first().click();
	await selectAvailableCalendarDay( page );
	let monthLimited = true;
	let releaseDaily: () => void = () => {};
	let holdDaily = false;
	let dailyPending = false;
	await page.route( calendarUrl, async ( route ) => {
		if ( monthLimited ) {
			await route.fulfill( {
				status: 429,
				json: { code: 'rate_limited', data: { status: 429 } },
			} );
		} else {
			await route.continue();
		}
	} );
	await page.locator( '.vkbm-calendar__nav' ).last().click();
	const notice = page.locator( '.vkbm-availability-notice' );
	const retry = notice.getByRole( 'button' );
	await expect( retry ).toBeVisible();
	await expect( retry ).toHaveAttribute( 'aria-busy', 'false' );
	await page.route( dailyUrl, async ( route ) => {
		if ( holdDaily ) {
			dailyPending = true;
			await new Promise< void >( ( resolve ) => {
				releaseDaily = resolve;
			} );
			await route.fulfill( {
				status: 429,
				json: { code: 'rate_limited', data: { status: 429 } },
			} );
		} else {
			await route.continue();
		}
	} );
	monthLimited = false;
	holdDaily = true;
	const original = await retry.elementHandle();
	await Promise.all( [ page.waitForResponse( calendarUrl ), retry.click() ] );
	// @check 月次成功・日次待機の間も同じDOMとフォーカスを保つ。
	await expect( retry ).toHaveAttribute( 'aria-busy', 'true' );
	await expect( retry ).toBeFocused();
	expect(
		await original?.evaluate(
			( element ) => element === document.activeElement
		)
	).toBe( true );
	await expect.poll( () => dailyPending ).toBe( true );
	releaseDaily();
	await expect( retry ).toHaveAttribute( 'aria-busy', 'false' );
	await expect( retry ).toBeFocused();
	holdDaily = false;
	await retry.click();
	await expect( notice ).toHaveCount( 0 );
	expect(
		await page.evaluate( () =>
			Boolean( document.activeElement?.closest( '.vkbm-calendar' ) )
		)
	).toBe( true );
} );
