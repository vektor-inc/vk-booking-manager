import { test, expect, Dialog } from '@playwright/test';
import { loginAsAdmin } from '../utils/helpers';

const SETTINGS_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=store';
const SETTINGS_URL_WITHOUT_TAB =
	'/wp-admin/admin.php?page=vkbm-provider-settings';
const PROVIDER_NAME = '#vkbm-provider-name';
const SYSTEM_TAB =
	'.vkbm-provider-settings__tabs a.nav-tab[href*="tab=system"]';

test.beforeEach( async ( { page } ) => {
	// 各シナリオを保存済みの店舗タブから開始する。
	await loginAsAdmin( page );
	await page.goto( SETTINGS_URL );
	await expect( page.locator( PROVIDER_NAME ) ).toBeVisible();
} );

test.describe( 'Issue #560 BM 設定画面の未保存変更確認', () => {
	// @title 変更がなければ確認せず別タブへ移動できる
	// @step 店舗タブを開き、値を変更せずシステムタブを押す
	// @check 確認ダイアログが出ずシステムタブへ移動する
	test( '変更なしならタブ移動で確認が出ない', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );

		await page.locator( SYSTEM_TAB ).click();
		await expect( page ).toHaveURL( /[?&]tab=system(?:&|$)/ );
		expect( dialogCount ).toBe( 0 );
	} );

	// @title 未保存変更の確認をキャンセルすると入力内容を保持する
	// @step 店舗名を変更してシステムタブを押し、確認をキャンセルする
	// @check 店舗タブに留まり、変更した店舗名が残る
	test( '変更ありなら確認が出て、キャンセルで画面と値が残る', async ( {
		page,
	} ) => {
		const input = page.locator( PROVIDER_NAME );
		const changedValue = `未保存店舗 ${ Date.now() }`;
		await input.fill( changedValue );

		let dialogType = '';
		let dialogMessage = '';
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogType = dialog.type();
			dialogMessage = dialog.message();
			await dialog.dismiss();
		} );
		await page.locator( SYSTEM_TAB ).click();

		expect( dialogType ).toBe( 'confirm' );
		expect( dialogMessage ).toContain( '保存していない変更' );
		await expect( page ).toHaveURL( /[?&]tab=store(?:&|$)/ );
		await expect( input ).toHaveValue( changedValue );
	} );

	// @title 未保存変更の確認を承認すると別タブへ移動する
	// @step 店舗名を変更してシステムタブを押し、確認を承認する
	// @check 二重確認なしでシステムタブへ移動する
	test( 'OK で移動する', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.accept();
		} );

		await page.locator( PROVIDER_NAME ).fill( `移動確認 ${ Date.now() }` );
		await page.locator( SYSTEM_TAB ).click();
		await expect( page ).toHaveURL( /[?&]tab=system(?:&|$)/ );
		expect( dialogCount ).toBe( 1 );
	} );

	// @title 設定保存では離脱確認を表示しない
	// @step 店舗名を変更して「変更を保存」を押す
	// @check 確認ダイアログなしで保存処理が完了する
	test( '保存では確認が出ない', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );

		const input = page.locator( PROVIDER_NAME );
		const originalValue = await input.inputValue();
		await input.fill( `保存確認 ${ Date.now() }` );
		await page.locator( 'input[type="submit"]' ).last().click();
		await expect(
			page.locator( '#setting-error-vkbm_provider_settings_save' )
		).toBeVisible();
		expect( dialogCount ).toBe( 0 );

		// 他のテストへ保存値を残さないよう、元の店舗名へ戻す。
		await page.locator( PROVIDER_NAME ).fill( originalValue );
		await page.locator( '#submit' ).click();
		await expect(
			page.locator( '#setting-error-vkbm_provider_settings_save' )
		).toBeVisible();
	} );

	// @title 既定タブの同一ページ内アンカーでは確認を表示しない
	// @step tab のない設定 URL を開き、店舗タブ内のアンカーリンクを押す
	// @check 未保存変更があっても確認なしで同じページ内を移動する
	test( 'tab なし URL の同一タブ内アンカーでは確認が出ない', async ( {
		page,
	} ) => {
		await page.goto( SETTINGS_URL_WITHOUT_TAB );
		await page
			.locator( PROVIDER_NAME )
			.fill( `アンカー確認 ${ Date.now() }` );
		await page.evaluate( () => {
			const link = document.createElement( 'a' );
			link.id = 'issue-560-same-tab-anchor';
			link.href = '#vkbm-provider-name';
			link.textContent = '同一タブ内リンク';
			document.querySelector( '.vkbm-provider-settings' )?.append( link );
		} );

		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );
		await page.locator( '#issue-560-same-tab-anchor' ).click();
		await expect( page ).toHaveURL( /#vkbm-provider-name$/ );
		expect( dialogCount ).toBe( 0 );
	} );

	// @title 名前付き hidden の変更も未保存変更として検出する
	// @step ロゴ ID の hidden 値を変更してシステムタブを押す
	// @check 未保存変更の確認が表示される
	test( 'ロゴ相当の名前付き hidden の変更で確認が出る', async ( {
		page,
	} ) => {
		await page
			.locator( 'input[name="vkbm_provider_settings[provider_logo_id]"]' )
			.evaluate( ( control: HTMLInputElement ) => {
				control.value = control.value === '123' ? '124' : '123';
			} );

		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );
		await page.locator( SYSTEM_TAB ).click();
		expect( dialogCount ).toBe( 1 );
		await expect( page ).toHaveURL( /[?&]tab=store(?:&|$)/ );
	} );

	// @title 保存確認モーダルをキャンセルしても未保存変更の監視を続ける
	// @step 高度な設定で自動匿名化を有効にして保存し、確認モーダルをキャンセルして別タブへ移動する
	// @check タブ移動時に未保存変更の確認が表示される
	test( '保存確認モーダルのキャンセル後もタブ移動で確認が出る', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=advanced'
		);
		await page
			.locator( '#vkbm-booking-auto-anonymize-months' )
			.selectOption( '3' );
		await page.locator( '#submit' ).click();
		const modal = page.getByRole( 'alertdialog' );
		await expect( modal ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeHidden();

		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );
		await page.locator( SYSTEM_TAB ).click();
		expect( dialogCount ).toBe( 1 );
		await expect( page ).toHaveURL( /[?&]tab=advanced(?:&|$)/ );
	} );
} );
