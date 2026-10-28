import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getServiceMenuId,
} from '../utils/helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// 設定オプション名（Settings_Repository::OPTION_KEY）。
const OPTION_NAME = 'vkbm_provider_settings';

// スクリーンショットの保存先（指定されたときだけ撮影する）。
const SHOT_DIR = process.env.VKBM_SHOT_DIR || '';

// 「予約ステータス」説明文の先頭に追加された文言（日本語 / 英語ロケール両対応）。
const NEW_DESCRIPTION =
	/仮予約にすると、予約は「仮予約」で届きます。予約一覧でステータスを「確定」に変更すると確定になります。|With tentative reservations, reservations are received as "Tentative"/;

/**
 * Issue #924: 基本設定の「予約ステータス」の初期値を、基本設定を一度も保存していない
 * サイトでは「仮予約にする」にする。
 *
 * 検証内容:
 * - 手順1: 基本設定が未保存のサイトでは「仮予約にする」が選ばれ、案内文欄と新しい説明文が出る。
 *          一般会員が予約すると予約は「仮予約」で保存され、完了画面も仮予約の案内になる。
 * - 手順2: 「即時確定」を保存したサイトでは案内文欄が隠れ、予約は「確定」になる。
 * - 手順3: 保存済みで予約ステータスのキーが無いサイトは「即時確定」のまま。別項目だけ保存しても変わらない。
 * - 手順4: 基本設定が未保存でも、管理者の代理予約は「確定」になる。
 */
test.describe( 'Issue #924: default booking status is pending', () => {
	// 基本設定の option を共有して書き換えるため、1本ずつ順に動かす。
	test.describe.configure( { mode: 'serial' } );

	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const username = `user_default_pending_${ Date.now() }`;

	// 元の設定値（テスト終了時に復元する）。JSON 文字列で保持する。
	let originalSettingsJson = '';
	// 元の option がもともと無かったか（無ければ終了時に削除して戻す）。
	let originalOptionAbsent = false;
	let userId = 0;

	/**
	 * 設定オプションを丸ごと置き換える。null なら option 自体を削除（未保存状態）する。
	 *
	 * @param settings 保存する設定。null なら削除。
	 */
	const setOption = ( settings: Record< string, unknown > | null ) => {
		if ( null === settings ) {
			wpEvalPhp( `delete_option( "${ OPTION_NAME }" );` );
			return;
		}
		const encoded = Buffer.from( JSON.stringify( settings ) ).toString(
			'base64'
		);
		wpEvalPhp(
			`update_option( "${ OPTION_NAME }", json_decode( base64_decode( "${ encoded }" ), true ) );`
		);
	};

	/**
	 * 元の設定から指定キーだけを除いたものを返す。
	 *
	 * @param removeKeys 除くキー。
	 */
	const baseSettings = ( removeKeys: string[] = [] ) => {
		const parsed = JSON.parse( originalSettingsJson || '{}' );
		const base: Record< string, unknown > =
			Array.isArray( parsed ) || null === parsed ? {} : parsed;
		removeKeys.forEach( ( key ) => delete base[ key ] );
		return base;
	};

	/**
	 * 指定ユーザーが作った最新の予約のステータス（_vkbm_booking_status）を返す。
	 *
	 * @param authorId 予約を作ったユーザー ID。
	 */
	const latestBookingStatus = ( authorId: number ): string =>
		wpEvalPhp(
			`$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => ${ Number(
				authorId
			) }, "post_status" => "any", "numberposts" => 1, "orderby" => "ID", "order" => "DESC", "fields" => "ids" ) );` +
				'echo $ids ? get_post_meta( $ids[0], "_vkbm_booking_status", true ) : "none";'
		);

	/**
	 * 指定ユーザーの予約をすべて削除する。
	 *
	 * @param authorId ユーザー ID。
	 */
	const deleteBookings = ( authorId: number ) => {
		wpEvalPhp(
			`$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => ${ Number(
				authorId
			) }, "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );` +
				'foreach ( $ids as $id ) { wp_delete_post( $id, true ); }'
		);
	};

	test.beforeAll( async () => {
		// 元の設定値を控える（未保存の場合は空配列）。
		originalOptionAbsent =
			'absent' ===
			wpEvalPhp(
				`echo false === get_option( "${ OPTION_NAME }", false ) ? "absent" : "present";`
			);
		originalSettingsJson = wpEvalPhp(
			`echo wp_json_encode( get_option( "${ OPTION_NAME }", array() ) );`
		);

		// 一般会員として予約するためのユーザーを作成する。
		userId = Number(
			wpCliArgs( [
				'user',
				'create',
				username,
				`${ username }@example.com`,
				'--role=subscriber',
				`--user_pass=${ password }`,
				'--porcelain',
			] )
		);
	} );

	test.afterAll( async () => {
		// テスト中に作った予約とテスト用ユーザーを削除する。
		wpEvalPhp(
			`$u = get_user_by( "login", "${ username }" );` +
				'if ( $u ) {' +
				'$ids = get_posts( array( "post_type" => "vkbm_booking", "author" => $u->ID, "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );' +
				'foreach ( $ids as $id ) { wp_delete_post( $id, true ); }' +
				'require_once ABSPATH . "wp-admin/includes/user.php";' +
				'wp_delete_user( $u->ID );' +
				'}'
		);
		// 元の設定値へ戻す。もともと無かった場合は option を削除する。
		setOption(
			originalOptionAbsent
				? null
				: JSON.parse( originalSettingsJson || '[]' )
		);
	} );

	// 各テストは独自にログインするため、保存済みのログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	/**
	 * wp-login.php 経由で一般会員としてログインする。
	 *
	 * @param page Playwright ページ。
	 */
	const loginAsMember = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
	};

	/**
	 * 予約フォームでメニュー→日付→枠を選び、予約確認画面で同意して予約を確定する。
	 *
	 * @param page Playwright ページ。
	 */
	const reserve = async ( page: Page, asAdmin = false ) => {
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );

		const menuId = getServiceMenuId();
		const reserveButton = page.locator(
			`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`
		);
		if ( await reserveButton.count() ) {
			await reserveButton.first().click();
		} else {
			await page.locator( '.vkbm-service-menu-card' ).first().click();
		}

		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );
		const availableDays = page.locator(
			'.vkbm-calendar__day--available:not(:disabled)'
		);
		await expect
			.poll( async () => availableDays.count(), { timeout: 15000 } )
			.toBeGreaterThan( 0 );
		await availableDays.first().click();

		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect
			.poll( async () => slots.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );
		await slots.first().click();
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible( { timeout: 15000 } );

		if ( asAdmin ) {
			// 代理予約では予約者名の入力が必須で、同意欄は出ない。
			await page
				.locator( '#vkbm-confirm-customer-name' )
				.fill( 'E2E 代理予約' );
		} else {
			await checkConfirmConsents( page );
		}

		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();
	};

	/**
	 * 管理者として「システム設定」タブを開く。
	 *
	 * @param page Playwright ページ。
	 */
	const openSystemTab = async ( page: Page ) => {
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await expect(
			page.locator( '#vkbm-provider-booking-status-mode' )
		).toBeVisible();
	};

	/**
	 * 設定画面の「予約ステータス」まわりを撮影する（VKBM_SHOT_DIR 指定時のみ）。
	 *
	 * @param page Playwright ページ。
	 * @param name ファイル名（拡張子なし）。
	 */
	const shootStatusField = async ( page: Page, name: string ) => {
		if ( ! SHOT_DIR ) {
			return;
		}
		const row = page
			.locator( 'tr' )
			.filter( { has: page.locator( '#vkbm-provider-booking-status-mode' ) } )
			.first();
		await row.scrollIntoViewIfNeeded();
		await row.screenshot( { path: `${ SHOT_DIR }/${ name }.png` } );
	};

	// @title 手順1: 設定を一度も保存していないサイトは仮予約で動く
	test( 'unsaved settings: pending is selected and a member reservation is saved as pending', async ( {
		page,
		browser,
	} ) => {
		// @step 基本設定を一度も保存していない状態にする
		setOption( null );
		expect(
			wpEvalPhp(
				`echo false === get_option( "${ OPTION_NAME }", false ) ? "absent" : "present";`
			)
		).toBe( 'absent' );

		// @step 管理者として「BM 設定」の「システム設定」タブを開く
		const context = await browser.newContext( { baseURL: WP_BASE_URL } );
		const adminPage = await context.newPage();
		await openSystemTab( adminPage );

		// @check 「予約ステータス」が「仮予約にする」で表示される
		const select = adminPage.locator( '#vkbm-provider-booking-status-mode' );
		await expect( select ).toHaveValue( 'pending' );
		await expect( select.locator( 'option:checked' ) ).toHaveText(
			/仮予約にする|Make a tentative reservation/
		);

		// @check 「仮予約完了時の案内文」欄が最初から表示される
		await expect(
			adminPage.locator(
				'#vkbm-provider-booking-complete-message-pending'
			)
		).toBeVisible();

		// @check 「予約ステータス」の説明文の先頭に新しい一文が表示される
		const description = adminPage
			.locator( 'p.description' )
			.filter( { has: adminPage.locator( 'br' ) } )
			.filter( { hasText: NEW_DESCRIPTION } )
			.first();
		await expect( description ).toBeVisible();
		const descriptionText = ( await description.innerText() ).trim();
		expect( descriptionText ).toMatch( NEW_DESCRIPTION );
		expect( descriptionText.search( NEW_DESCRIPTION ) ).toBe( 0 );

		await shootStatusField( adminPage, 'after-system-tab-status-unsaved' );
		await context.close();

		// @step 設定は保存せず、一般会員としてログインして予約を1件送る
		await loginAsMember( page );
		await reserve( page );

		// @check 予約完了画面が仮予約の案内になる
		const complete = page.locator( '.vkbm-confirm__complete' );
		await expect( complete ).toBeVisible( { timeout: 15000 } );
		await expect(
			complete.locator( '.vkbm-confirm__complete-title' )
		).toHaveText(
			/仮予約を受け付けました|Your tentative reservation has been received\./
		);

		// @check 予約のステータスが「仮予約」で保存される
		expect( latestBookingStatus( userId ) ).toBe( 'pending' );
		deleteBookings( userId );
	} );

	// @title 手順2: 「即時確定」を保存したサイトは確定で動く
	test( 'saved instant confirmation: case field is hidden and a member reservation is confirmed', async ( {
		page,
		browser,
	} ) => {
		// @step 基本設定が未保存の状態から、設定画面で「予約ステータス」を「即時確定」にして保存する
		setOption( null );
		const context = await browser.newContext( { baseURL: WP_BASE_URL } );
		const adminPage = await context.newPage();
		await openSystemTab( adminPage );
		await adminPage
			.locator( '#vkbm-provider-booking-status-mode' )
			.selectOption( 'confirmed' );

		// @check 「仮予約完了時の案内文」欄が非表示になる
		await expect(
			adminPage.locator(
				'#vkbm-provider-booking-complete-message-pending'
			)
		).toBeHidden();
		await adminPage
			.locator( '#submit' )
			.or( adminPage.locator( 'input[type="submit"][name="submit"]' ) )
			.first()
			.click();
		// 保存完了の通知が出るまで待つ（保存前に次の画面へ移らないため）。
		await expect(
			adminPage.locator( '#setting-error-vkbm_provider_settings_save' )
		).toBeVisible( { timeout: 15000 } );
		await adminPage.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		// @check 保存後も「即時確定」が選ばれたままで、保存値は confirmed になる
		await expect(
			adminPage.locator( '#vkbm-provider-booking-status-mode' )
		).toHaveValue( 'confirmed' );
		expect(
			wpEvalPhp(
				`$s = get_option( "${ OPTION_NAME }", array() ); echo $s["provider_booking_status_mode"] ?? "none";`
			)
		).toBe( 'confirmed' );
		await shootStatusField( adminPage, 'after-system-tab-status-confirmed' );
		await context.close();

		// @step 一般会員としてログインして予約を1件送る
		await loginAsMember( page );
		await reserve( page );

		// @check 予約完了画面が「予約が完了しました。」になり、予約のステータスが「確定」になる
		const complete = page.locator( '.vkbm-confirm__complete' );
		await expect( complete ).toBeVisible( { timeout: 15000 } );
		await expect(
			complete.locator( '.vkbm-confirm__complete-title' )
		).toHaveText(
			/予約が完了しました。|Your reservation has been completed\./
		);
		expect( latestBookingStatus( userId ) ).toBe( 'confirmed' );
		deleteBookings( userId );
	} );

	// @title 手順3: 保存済みで予約ステータスのキーが無いサイトは即時確定のまま
	test( 'saved settings without the status key stay on instant confirmation', async ( {
		browser,
	} ) => {
		// @step 保存済みの設定から予約ステータスのキーだけを取り除く
		setOption( {
			...baseSettings( [ 'provider_booking_status_mode' ] ),
			provider_name: 'E2E Legacy Site',
		} );
		expect(
			wpEvalPhp(
				`$s = get_option( "${ OPTION_NAME }", array() ); echo array_key_exists( "provider_booking_status_mode", $s ) ? "has" : "none";`
			)
		).toBe( 'none' );

		// @step 「システム設定」タブを開く
		const context = await browser.newContext( { baseURL: WP_BASE_URL } );
		const page = await context.newPage();
		await openSystemTab( page );

		// @check 「予約ステータス」が「即時確定」で表示され、案内文欄は非表示
		await expect(
			page.locator( '#vkbm-provider-booking-status-mode' )
		).toHaveValue( 'confirmed' );
		await expect(
			page.locator( '#vkbm-provider-booking-complete-message-pending' )
		).toBeHidden();
		await shootStatusField( page, 'after-system-tab-status-legacy' );

		// @step 別の項目（事業者名）だけを変えて保存する
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=store'
		);
		await page.locator( '#vkbm-provider-name' ).fill( 'E2E Legacy Site 2' );
		await page
			.locator( '#submit' )
			.or( page.locator( 'input[type="submit"][name="submit"]' ) )
			.first()
			.click();
		// 保存完了の通知が出るまで待つ（保存前に次の画面へ移らないため）。
		await expect(
			page.locator( '#setting-error-vkbm_provider_settings_save' )
		).toBeVisible( { timeout: 15000 } );

		// @check 保存された設定で、事業者名が変わり、予約ステータスは「即時確定」のまま
		const saved = JSON.parse(
			wpEvalPhp(
				`echo wp_json_encode( get_option( "${ OPTION_NAME }", array() ) );`
			)
		);
		expect( saved.provider_name ).toBe( 'E2E Legacy Site 2' );
		expect( saved.provider_booking_status_mode ).toBe( 'confirmed' );

		// @step もう一度「システム設定」タブを開く
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);

		// @check 「予約ステータス」は「即時確定」のまま
		await expect(
			page.locator( '#vkbm-provider-booking-status-mode' )
		).toHaveValue( 'confirmed' );
		await context.close();
	} );

	// @title 手順4: 管理者の代理予約は設定にかかわらず確定になる
	test( 'admin proxy reservation is confirmed even when settings are unsaved', async ( {
		page,
	} ) => {
		// @step 基本設定を一度も保存していない状態にする
		setOption( null );
		// 代理予約の投稿者は管理者とは限らないため、予約の最大 ID を控えて、その後に増えた予約を見る。
		const maxIdBefore = Number(
			wpEvalPhp(
				'$ids = get_posts( array( "post_type" => "vkbm_booking", "post_status" => "any", "numberposts" => 1, "orderby" => "ID", "order" => "DESC", "fields" => "ids" ) );' +
					'echo $ids ? $ids[0] : 0;'
			)
		);

		// @step 管理者としてログインし、予約フォームから予約を1件送る
		await loginAsAdmin( page );
		await reserve( page, true );
		await expect( page.locator( '.vkbm-confirm__complete' ) ).toBeVisible( {
			timeout: 15000,
		} );

		try {
			// @check 予約のステータスが「確定」になる
			const proxyStatus = wpEvalPhp(
				`$ids = get_posts( array( "post_type" => "vkbm_booking", "post_status" => "any", "numberposts" => 1, "orderby" => "ID", "order" => "DESC", "fields" => "ids" ) );` +
					`echo ( $ids && $ids[0] > ${ maxIdBefore } ) ? get_post_meta( $ids[0], "_vkbm_booking_status", true ) : "none";`
			);
			expect( proxyStatus ).toBe( 'confirmed' );
		} finally {
			// 確認が失敗しても、代理予約で作った予約を必ず削除する。
			wpEvalPhp(
				`$ids = get_posts( array( "post_type" => "vkbm_booking", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) );` +
					`foreach ( $ids as $id ) { if ( $id > ${ maxIdBefore } ) { wp_delete_post( $id, true ); } }`
			);
		}
	} );
} );
