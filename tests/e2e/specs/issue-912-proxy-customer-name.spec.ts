import { test, expect, type Page } from '@playwright/test';
import { loginAsAdmin, getServiceMenuId, wpCliArgs } from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// 代理予約と会員本人の予約で、同じ予約確認画面まで進む。
const gotoConfirmScreen = async ( page: Page ) => {
	await page.goto( '/booking/' );
	const menuId = getServiceMenuId();
	const calendarResponse = page.waitForResponse( /calendar-meta/ );
	await page
		.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
		)
		.click();
	await selectAvailableCalendarDay( page, calendarResponse );
	const slot = page.locator( '.vkbm-slot-list__item' ).first();
	await slot.click();
	await expect( slot ).toHaveClass( /is-selected/ );
	await page.locator( '.vkbm-plan-summary__action' ).first().click();
};

// 補足が次の項目より入力欄（エラー時はアラート）に近いことを、字面の端で確認する。
const expectHelpNearName = async ( page: Page ) => {
	const gaps = await page
		.locator( '#vkbm-confirm-customer-name-help' )
		.evaluate( ( help ) => {
			const previous = help.previousElementSibling!;
			const phoneLabel = document.querySelector(
				'label[for="vkbm-confirm-customer-phone"]'
			)!;
			const helpStyle = getComputedStyle( help );
			const labelStyle = getComputedStyle( phoneLabel );
			const helpLeading =
				( parseFloat( helpStyle.lineHeight ) -
					parseFloat( helpStyle.fontSize ) ) /
				2;
			const labelLeading =
				( parseFloat( labelStyle.lineHeight ) -
					parseFloat( labelStyle.fontSize ) ) /
				2;
			return {
				within:
					help.getBoundingClientRect().top +
					helpLeading -
					previous.getBoundingClientRect().bottom,
				between:
					phoneLabel.getBoundingClientRect().top +
					labelLeading -
					help.getBoundingClientRect().bottom +
					helpLeading,
			};
		} );
	expect( gaps.within ).toBeGreaterThan( 0 );
	expect( gaps.within ).toBeLessThan( gaps.between );
};

// @title 代理予約では予約者名を入力してから確定する
test( '代理予約の名前必須・入力欄への案内・サーバーエラー・入力後の確定', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );
	// @step 管理者でログインし、予約ページでメニューの「予約する」・空いている日・時間を選んで予約確認画面へ進む
	await loginAsAdmin( page );
	await gotoConfirmScreen( page );

	// @step 名前が空のまま、押せる確定ボタンを押す。
	const name = page.locator( '#vkbm-confirm-customer-name' );
	const confirm = page.locator( '.vkbm-confirm__button' ).first();
	const error = page.locator( '#vkbm-confirm-customer-name-error' );
	const help = page.locator( '#vkbm-confirm-customer-name-help' );
	// @check 予約者名の欄に「必須」が付き、すぐ下に「お名前が分からない場合は「不明」などを入力してください。」の補足が出ている。電話番号は必須ではない
	await expect( help ).toHaveText(
		'お名前が分からない場合は「不明」などを入力してください。'
	);
	await expect( help ).toBeVisible();
	await expectHelpNearName( page );
	await expect( name ).toHaveAttribute(
		'aria-describedby',
		'vkbm-confirm-customer-name-help'
	);
	await expect( error ).toHaveCount( 0 );
	// フォーカスした瞬間に、エラーと ARIA 属性が既に反映されていることも記録する。
	await name.evaluate( ( input ) => {
		( window as any ).proxyNameFocusStates = [];
		input.addEventListener( 'focus', () => {
			( window as any ).proxyNameFocusStates.push( {
				invalid: input.getAttribute( 'aria-invalid' ),
				description: input.getAttribute( 'aria-describedby' ),
				error: document.getElementById(
					'vkbm-confirm-customer-name-error'
				)?.textContent,
			} );
		} );
	} );
	await expect( name ).toHaveValue( '' );
	await expect( name ).toHaveAttribute( 'required', '' );
	await expect( name ).toHaveAttribute( 'aria-required', 'true' );
	await expect(
		page.locator( 'label[for="vkbm-confirm-customer-name"]' )
	).toContainText( '必須' );
	await expect(
		page.locator( '#vkbm-confirm-customer-phone' )
	).not.toHaveAttribute( 'required' );
	const buttonDescription = await confirm.getAttribute( 'aria-describedby' );
	const bookingsBefore = wpCliArgs( [
		'post',
		'list',
		'--post_type=vkbm_booking',
		'--format=ids',
	] );
	let bookingRequests = 0;
	page.on( 'request', ( request ) => {
		if (
			request.method() === 'POST' &&
			/\/vkbm\/v1\/bookings(?:\?|$)/.test( request.url() )
		) {
			bookingRequests++;
		}
	} );
	await page.evaluate( () => {
		( window as any ).proxyPaymentCalls = 0;
		( window as any ).vkbmOnBeforeConfirm = () => {
			( window as any ).proxyPaymentCalls++;
			return true;
		};
	} );
	for ( const value of [ '', ' 　\t ' ] ) {
		await name.fill( value );
		await page.evaluate( () => {
			( window as any ).proxyNameFocusStates = [];
		} );
		await expect( confirm ).toBeEnabled();
		await confirm.click();
		// @check 入力欄直下の日本語エラー・フォーカス・支援技術の関連付け。
		await expect( error ).toHaveText(
			'お客様のお名前を入力してください。'
		);
		await expect( name ).toBeFocused();
		await expect( name ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( name ).toHaveAttribute(
			'aria-describedby',
			'vkbm-confirm-customer-name-error vkbm-confirm-customer-name-help'
		);
		expect( await confirm.getAttribute( 'aria-describedby' ) ).toBe(
			buttonDescription
		);
		expect(
			await name.evaluate( ( input ) => input.nextElementSibling?.id )
		).toBe( 'vkbm-confirm-customer-name-error' );
		await expect( help ).toBeVisible();
		await expectHelpNearName( page );
		expect(
			await error.evaluate(
				( element ) => element.nextElementSibling?.id
			)
		).toBe( 'vkbm-confirm-customer-name-help' );
		// @check エラーが残ったまま再度押しても、入力欄へフォーカスが戻る。
		await confirm.click();
		await expect( name ).toBeFocused();
		expect(
			await page.evaluate( () => ( window as any ).proxyNameFocusStates )
		).toEqual(
			[ 1, 2 ].map( () => ( {
				invalid: 'true',
				description:
					'vkbm-confirm-customer-name-error vkbm-confirm-customer-name-help',
				error: 'お客様のお名前を入力してください。',
			} ) )
		);
	}
	// @check 予約の送信・作成も決済の事前処理も行われない。
	expect( bookingRequests ).toBe( 0 );
	expect(
		await page.evaluate( () => ( window as any ).proxyPaymentCalls )
	).toBe( 0 );
	expect(
		wpCliArgs( [
			'post',
			'list',
			'--post_type=vkbm_booking',
			'--format=ids',
		] )
	).toBe( bookingsBefore );
	await page.screenshot( {
		path: 'test-results/issue-912-name-error.png',
		fullPage: true,
	} );

	// @step 送信中に名前が空になった場合も、既存の送信エラー表示へ出る。
	await name.fill( '代理予約のお客様' );
	await expect( error ).toHaveCount( 0 );
	await expect( help ).toBeVisible();
	await expect( name ).toHaveAttribute(
		'aria-describedby',
		'vkbm-confirm-customer-name-help'
	);
	await page.route(
		/\/vkbm\/v1\/bookings(?:\?|$)/,
		async ( route ) => {
			const payload = route.request().postDataJSON();
			await route.continue( {
				postData: JSON.stringify( { ...payload, customer_name: '' } ),
			} );
		},
		{ times: 1 }
	);
	const rejected = page.waitForResponse(
		( response ) =>
			response.request().method() === 'POST' &&
			/\/vkbm\/v1\/bookings(?:\?|$)/.test( response.url() )
	);
	await confirm.click();
	const rejection = await rejected;
	expect( rejection.status() ).toBe( 400 );
	expect( ( await rejection.json() ).code ).toBe( 'customer_name_required' );
	// @check 確定ボタンの近くに「お客様のお名前を入力してください。」のエラーが表示される
	await expect(
		page.locator( '.vkbm-confirm__actions .vkbm-alert__danger' )
	).toContainText( 'お客様のお名前を入力してください。' );

	// @step 名前を入れた代理予約は、電話番号なしで確定できる。
	const accepted = page.waitForResponse(
		( response ) =>
			response.request().method() === 'POST' &&
			/\/vkbm\/v1\/bookings(?:\?|$)/.test( response.url() )
	);
	await confirm.click();
	const response = await accepted;
	// @check 予約が確定し、予約の管理画面で予約者名が「代理予約のお客様」になっている
	expect( response.ok() ).toBe( true );
	const result = await response.json();
	const bookingId = Number( result.booking_id );
	try {
		expect( bookingId ).toBeGreaterThan( 0 );
		expect(
			wpCliArgs( [
				'post',
				'meta',
				'get',
				String( bookingId ),
				'_vkbm_booking_customer_name',
			] )
		).toBe( '代理予約のお客様' );
	} finally {
		// このテストで確定した予約だけを片付ける。
		if ( bookingId > 0 ) {
			wpCliArgs( [ 'post', 'delete', String( bookingId ), '--force' ] );
		}
	}
} );

// @title 一般会員の予約確認には代理予約用の補足を表示しない
test( '会員本人の画面には予約者名の入力欄と補足が出ない', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );
	const login = `issue912member${ Date.now() }`;
	const userId = wpCliArgs( [
		'user',
		'create',
		login,
		`${ login }@example.test`,
		'--role=subscriber',
		'--user_pass=issue912-test-password',
		'--porcelain',
	] );
	try {
		// @step 管理画面で購読者の会員を作っておき、その会員でログインして予約確認画面まで進む
		await loginAsAdmin( page, login, 'issue912-test-password' );
		await gotoConfirmScreen( page );
		// @check 確認画面が表示されても、代理予約用の入力や補足は存在しない。
		await expect(
			page.locator( '.vkbm-confirm__button' ).first()
		).toBeVisible();
		await expect( page.locator( '.vkbm-confirm__auth' ) ).toHaveCount( 0 );
		await expect(
			page.locator( '#vkbm-confirm-customer-name' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '#vkbm-confirm-customer-name-help' )
		).toHaveCount( 0 );
	} finally {
		// このテストで作った会員だけを片付ける。
		wpCliArgs( [ 'user', 'delete', userId, '--reassign=1', '--yes' ] );
	}
} );
