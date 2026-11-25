// @category 予約の確定・確認・キャンセル
import { test, expect, type Page } from '@playwright/test';
import {
	loginAsAdmin,
	getServiceMenuId,
	wpCliArgs,
	wpEvalPhp,
} from '../utils/helpers';
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
	// @check スタッフ用の欄は予約時に入力する情報の箱の中にあり、お客様の情報が先頭、管理用メモがご要望・備考より後にある。枠線は箱にだけ付いている（#1173）
	const inputsBox = page.locator( '.vkbm-confirm__inputs' );
	await expect( inputsBox ).toHaveCount( 1 );
	await expect(
		inputsBox.locator( '> .vkbm-confirm__admin-fields:first-child' )
	).toHaveCount( 1 );
	await expect(
		inputsBox.locator(
			'.vkbm-confirm__form:has( #vkbm-confirm-memo ) ~ .vkbm-confirm__admin-staff-fields #vkbm-confirm-provider-note'
		)
	).toHaveCount( 1 );
	await expect( inputsBox ).toHaveCSS( 'border-top-width', '1px' );
	await expect(
		inputsBox.locator( '.vkbm-confirm__admin-fields' )
	).toHaveCSS( 'border-top-width', '0px' );
	// @check 箱の中の 1 行の入力欄・プルダウンは、どのまとまりでも最小の高さ 42px が付き、同じ高さで並ぶ（#1173）
	const lineHeights = await inputsBox.evaluate( ( box ) =>
		Array.from(
			box.querySelectorAll< HTMLInputElement | HTMLSelectElement >(
				'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), select'
			)
		)
			.filter(
				( el ) =>
					el.getClientRects().length > 0 &&
					! el.closest( '[aria-hidden="true"]' )
			)
			.map( ( el ) => ( {
				minHeight: getComputedStyle( el ).minHeight,
				height: Math.round( el.getBoundingClientRect().height ),
			} ) )
	);
	expect( lineHeights.length ).toBeGreaterThan( 1 );
	for ( const { minHeight } of lineHeights ) {
		expect( minHeight ).toBe( '42px' );
	}
	expect( new Set( lineHeights.map( ( { height } ) => height ) ).size ).toBe(
		1
	);
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
		// @check 会員予約でも、ご要望・備考は枠線の付いた入力欄の箱の中にある（#1173）
		const inputsBox = page.locator( '.vkbm-confirm__inputs' );
		await expect( inputsBox.locator( '#vkbm-confirm-memo' ) ).toHaveCount(
			1
		);
		await expect( inputsBox ).toHaveCSS( 'border-top-width', '1px' );
	} finally {
		// このテストで作った会員だけを片付ける。
		wpCliArgs( [ 'user', 'delete', userId, '--reassign=1', '--yes' ] );
	}
} );

// @title 暗い配色のテーマでも、入力欄の箱の中のラジオ・チェックボックスは箱の白い背景に合う配色で描く
test( '暗い配色のテーマでも箱の中の選択肢が明るい配色になる', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );
	const login = `issue1173member${ Date.now() }`;
	const userId = wpCliArgs( [
		'user',
		'create',
		login,
		`${ login }@example.test`,
		'--role=subscriber',
		'--user_pass=issue1173-test-password',
		'--porcelain',
	] );
	// BM 設定のうち、このテストで変える「ご利用になる方」の 3 項目だけを控え、テストの後で元へ戻す。
	const rosterKeys = `array( 'booking_attendee_name_mode', 'attendee_roster_enabled', 'attendee_roster_show_self' )`;
	const originalRoster = wpEvalPhp(
		`$s = get_option( 'vkbm_provider_settings', array() ); $o = array(); foreach ( ${ rosterKeys } as $key ) { $o[ $key ] = array_key_exists( $key, $s ) ? $s[ $key ] : null; } echo wp_json_encode( $o );`
	);
	try {
		// @step 管理画面の BM 設定で「ご利用になる方」の名前の欄を「使用する（入力は任意）」にして名簿を有効にし、購読者の会員でログインして予約確認画面まで進む
		wpEvalPhp(
			`$s = get_option( 'vkbm_provider_settings', array() ); $s['booking_attendee_name_mode'] = 'optional'; $s['attendee_roster_enabled'] = true; $s['attendee_roster_show_self'] = true; update_option( 'vkbm_provider_settings', $s );`
		);
		await loginAsAdmin( page, login, 'issue1173-test-password' );
		await gotoConfirmScreen( page );
		const inputsBox = page.locator( '.vkbm-confirm__inputs' );
		await expect( inputsBox ).toBeVisible();

		// @step テーマが :root に color-scheme: dark を指定している状態にし、「ご利用になる方」で名前を入力する選択肢を選ぶ
		await page.addStyleTag( { content: ':root { color-scheme: dark; }' } );
		await inputsBox.locator( '#vkbm-confirm-attendee-manual' ).check();
		await expect(
			inputsBox.locator( '#vkbm-confirm-attendee-save' )
		).toBeVisible();

		// @check ページは暗い配色のまま、箱の中のラジオ・チェックボックスはすべて明るい配色（color-scheme: light）で描かれる（#1173）
		expect(
			await page.evaluate(
				() => getComputedStyle( document.documentElement ).colorScheme
			)
		).toBe( 'dark' );
		const schemes = await inputsBox.evaluate( ( box ) =>
			Array.from(
				box.querySelectorAll< HTMLInputElement >(
					'input[type="radio"], input[type="checkbox"]'
				)
			)
				.filter( ( el ) => el.getClientRects().length > 0 )
				.map( ( el ) => ( {
					type: el.type,
					colorScheme: getComputedStyle( el ).colorScheme,
				} ) )
		);
		expect( schemes.some( ( { type } ) => type === 'radio' ) ).toBe( true );
		expect( schemes.some( ( { type } ) => type === 'checkbox' ) ).toBe(
			true
		);
		for ( const { colorScheme } of schemes ) {
			expect( colorScheme ).toBe( 'light' );
		}
	} finally {
		// このテストで変えた名簿の設定を元へ戻し、作った会員を片付ける。
		const restore = Buffer.from( originalRoster ).toString( 'base64' );
		wpEvalPhp(
			`$o = json_decode( base64_decode( '${ restore }' ), true ); $s = get_option( 'vkbm_provider_settings', array() ); foreach ( $o as $key => $value ) { if ( null === $value ) { unset( $s[ $key ] ); } else { $s[ $key ] = $value; } } update_option( 'vkbm_provider_settings', $s );`
		);
		wpCliArgs( [ 'user', 'delete', userId, '--reassign=1', '--yes' ] );
	}
} );
