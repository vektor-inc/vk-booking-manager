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
	// @step 管理者でログインし、BM 設定の「店舗」タブを開く
	await loginAsAdmin( page );
	await page.goto( SETTINGS_URL );
	await expect( page.locator( PROVIDER_NAME ) ).toBeVisible();
} );

test.describe( 'Issue #560 BM 設定画面の未保存変更確認', () => {
	// @title 変更がなければ確認せず別タブへ移動できる
	test( '変更なしならタブ移動で確認が出ない', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );

		// @step 何も変更せずに「システム」タブを押す
		await page.locator( SYSTEM_TAB ).click();
		// @check 確認ダイアログが出ずに「システム」タブへ移動する
		await expect( page ).toHaveURL( /[?&]tab=system(?:&|$)/ );
		expect( dialogCount ).toBe( 0 );
	} );

	// @title 未保存変更の確認をキャンセルすると入力内容を保持する
	test( '変更ありなら確認が出て、キャンセルで画面と値が残る', async ( {
		page,
	} ) => {
		const input = page.locator( PROVIDER_NAME );
		const changedValue = `未保存店舗 ${ Date.now() }`;
		// @step 店舗名を書き換える
		await input.fill( changedValue );

		let dialogType = '';
		let dialogMessage = '';
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogType = dialog.type();
			dialogMessage = dialog.message();
			await dialog.dismiss();
		} );
		// @step 「システム」タブを押し、確認ダイアログで「キャンセル」を押す
		await page.locator( SYSTEM_TAB ).click();

		// @check 「保存していない変更」がある旨の確認ダイアログが出て、キャンセル後も「店舗」タブに留まり、書き換えた店舗名が残っている
		expect( dialogType ).toBe( 'confirm' );
		expect( dialogMessage ).toContain( '保存していない変更' );
		await expect( page ).toHaveURL( /[?&]tab=store(?:&|$)/ );
		await expect( input ).toHaveValue( changedValue );
	} );

	// @title 未保存変更の確認を承認すると別タブへ移動する
	test( 'OK で移動する', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.accept();
		} );

		// @step 店舗名を書き換えて「システム」タブを押し、確認ダイアログで「OK」を押す
		await page.locator( PROVIDER_NAME ).fill( `移動確認 ${ Date.now() }` );
		await page.locator( SYSTEM_TAB ).click();
		// @check 確認ダイアログは1回だけ出て、「システム」タブへ移動する
		await expect( page ).toHaveURL( /[?&]tab=system(?:&|$)/ );
		expect( dialogCount ).toBe( 1 );
	} );

	// @title 設定保存では離脱確認を表示しない
	test( '保存では確認が出ない', async ( { page } ) => {
		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );

		const input = page.locator( PROVIDER_NAME );
		const originalValue = await input.inputValue();
		// @step 店舗名を書き換えて「変更を保存」を押す
		await input.fill( `保存確認 ${ Date.now() }` );
		await page.locator( 'input[type="submit"]' ).last().click();
		// @check 確認ダイアログが出ずに保存され、保存完了のメッセージが表示される
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
	test( 'tab なし URL の同一タブ内アンカーでは確認が出ない', async ( {
		page,
	} ) => {
		// @step タブ指定のない BM 設定画面（既定で「店舗」タブ）を開き、店舗名を書き換える
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
		// @step 同じページ内の店舗名の欄へ移動するリンクを押す
		await page.locator( '#issue-560-same-tab-anchor' ).click();
		// @check 確認ダイアログが出ずに、同じページ内の店舗名の欄へ移動する
		await expect( page ).toHaveURL( /#vkbm-provider-name$/ );
		expect( dialogCount ).toBe( 0 );
	} );

	// @title 店舗ロゴの差し替えも未保存の変更として検出する
	test( 'ロゴ相当の名前付き hidden の変更で確認が出る', async ( {
		page,
	} ) => {
		// @step 店舗ロゴの画像を別の画像に差し替える
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
		// @step 「システム」タブを押し、確認ダイアログで「キャンセル」を押す
		await page.locator( SYSTEM_TAB ).click();
		// @check 未保存の変更がある旨の確認ダイアログが出て、「店舗」タブに留まる
		expect( dialogCount ).toBe( 1 );
		await expect( page ).toHaveURL( /[?&]tab=store(?:&|$)/ );
	} );

	// @title 保存確認モーダルをキャンセルしても未保存変更の監視を続ける
	test( '保存確認モーダルのキャンセル後もタブ移動で確認が出る', async ( {
		page,
	} ) => {
		// @step BM 設定の「高度な設定」タブを開き、予約データの自動匿名化の期間を3か月に変えて「変更を保存」を押す
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=advanced'
		);
		await page
			.locator( '#vkbm-booking-auto-anonymize-months' )
			.selectOption( '3' );
		await page.locator( '#submit' ).click();
		const modal = page.getByRole( 'alertdialog' );
		await expect( modal ).toBeVisible();
		// @step 表示された保存確認の画面を Esc キーで閉じる（キャンセルする）
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeHidden();

		let dialogCount = 0;
		page.on( 'dialog', async ( dialog: Dialog ) => {
			dialogCount += 1;
			await dialog.dismiss();
		} );
		// @step 「システム」タブを押し、確認ダイアログで「キャンセル」を押す
		await page.locator( SYSTEM_TAB ).click();
		// @check 未保存の変更がある旨の確認ダイアログが出て、「高度な設定」タブに留まる
		expect( dialogCount ).toBe( 1 );
		await expect( page ).toHaveURL( /[?&]tab=advanced(?:&|$)/ );
	} );
} );
