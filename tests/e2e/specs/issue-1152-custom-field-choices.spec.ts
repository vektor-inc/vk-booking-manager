/**
 * issue #1152: 予約時の入力項目で選択肢と数値の既定値を設定する。
 */
// @category 管理画面
import { test, expect } from '@playwright/test';
import { loginAsAdmin, wpEvalPhp } from '../utils/helpers';

const SETTINGS_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=booking-fields';

test.describe( 'issue #1152: 予約時の入力項目', () => {
	let originalSettings: string;

	test.beforeAll( () => {
		originalSettings = wpEvalPhp(
			"echo base64_encode( wp_json_encode( get_option( 'vkbm_provider_settings', false ) ) );"
		).trim();
	} );

	test.afterAll( () => {
		wpEvalPhp( `
			$original = json_decode( base64_decode( '${ originalSettings }' ), true );
			if ( false === $original ) {
				delete_option( 'vkbm_provider_settings' );
			} else {
				update_option( 'vkbm_provider_settings', $original );
			}
		` );
	} );

	test( '選択肢と数値の既定値を保存し、再表示できる', async ( { page } ) => {
		wpEvalPhp(
			" $settings = get_option( 'vkbm_provider_settings', array() ); $settings['booking_custom_fields'] = array(); update_option( 'vkbm_provider_settings', $settings ); "
		);
		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );

		// @step 既存の設定を残したまま、新しい3種類の入力項目を追加する。
		for ( const [ label, type, detail ] of [
			[ 'Dinner course E2E', 'select', 'Pine\nBamboo' ],
			[ 'Breakfast E2E', 'radio', 'Yes\nNo' ],
			[ 'Age E2E', 'number', '0' ],
		] ) {
			await page.locator( '#vkbm-booking-custom-field-add' ).click();
			const row = page.locator( '.vkbm-booking-custom-field-row' ).last();
			await row.locator( 'input[name$="[label]"]' ).fill( label );
			await row.locator( 'select[name$="[type]"]' ).selectOption( type );
			if ( type === 'number' ) {
				await row
					.locator( 'input[name$="[default_value]"]' )
					.fill( detail );
			} else {
				await row
					.locator( 'textarea[name$="[choices]"]' )
					.fill( detail );
			}
		}
		await page.locator( '#submit' ).click();
		await page.goto( SETTINGS_URL );

		// @step 保存後も種類、選択肢、明示的な 0 が残ることを確認する。
		const course = page
			.locator( '.vkbm-booking-custom-field-row' )
			.filter( {
				has: page.locator( 'input[value="Dinner course E2E"]' ),
			} );
		const breakfast = page
			.locator( '.vkbm-booking-custom-field-row' )
			.filter( { has: page.locator( 'input[value="Breakfast E2E"]' ) } );
		const age = page
			.locator( '.vkbm-booking-custom-field-row' )
			.filter( { has: page.locator( 'input[value="Age E2E"]' ) } );
		await expect( course.locator( 'select[name$="[type]"]' ) ).toHaveValue(
			'select'
		);
		await expect(
			course.locator( 'textarea[name$="[choices]"]' )
		).toHaveValue( 'Pine\nBamboo' );
		await expect(
			breakfast.locator( 'select[name$="[type]"]' )
		).toHaveValue( 'radio' );
		await expect(
			breakfast.locator( 'textarea[name$="[choices]"]' )
		).toHaveValue( 'Yes\nNo' );
		await expect( age.locator( 'select[name$="[type]"]' ) ).toHaveValue(
			'number'
		);
		await expect(
			age.locator( 'input[name$="[default_value]"]' )
		).toHaveValue( '0' );
		// @step 共通の必須設定を先に置き、種類固有の入力を子グループに収める。
		await expect(
			age.locator( '.vkbm-booking-custom-field-row__required' )
		).toBeVisible();
		await expect(
			age.locator( 'select[name$="[type]"]' )
		).toHaveAccessibleName( '入力欄の種類' );
		await expect(
			age.locator( '.vkbm-booking-custom-field-row__type-options' )
		).toHaveAccessibleName( '種類別の設定' );
		await expect(
			age.locator(
				'.vkbm-booking-custom-field-row__type-options input[name$="[default_value]"]'
			)
		).toBeVisible();
		expect(
			await age
				.locator( '.vkbm-booking-custom-field-row__required' )
				.evaluate(
					( element ) =>
						element.nextElementSibling?.tagName === 'FIELDSET'
				)
		).toBe( true );
	} );

	test( '項目名エラーが増えても種類選択と上端が揃う', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );
		await page.locator( '#vkbm-booking-custom-field-add' ).click();
		const row = page.locator( '.vkbm-booking-custom-field-row' ).last();
		const labelInput = row.locator(
			'.vkbm-booking-custom-field-row__label input'
		);
		const typeSelect = row.locator( 'select[name$="[type]"]' );

		for ( const width of [ 1280, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			// @step 通常時と入力直下にエラーが増えた状態の両方で配置を測る。
			for ( const showError of [ false, true ] ) {
				await row
					.locator( '.vkbm-booking-custom-field-row__label' )
					.evaluate( ( element, withError ) => {
						element.querySelector( '[role="alert"]' )?.remove();
						if ( withError ) {
							const alert = document.createElement( 'p' );
							alert.className = 'vkbm-alert vkbm-alert__danger';
							alert.setAttribute( 'role', 'alert' );
							alert.textContent = '項目名を入力してください';
							element.append( alert );
						}
					}, showError );
				const labelBox = ( await labelInput.boundingBox() )!;
				const typeBox = ( await typeSelect.boundingBox() )!;
				if ( width === 1280 ) {
					expect(
						Math.abs( labelBox.y - typeBox.y )
					).toBeLessThanOrEqual( 1 );
				} else {
					expect( typeBox.y ).toBeGreaterThan(
						labelBox.y + labelBox.height
					);
				}
				await expect(
					row.locator( '.vkbm-booking-custom-field-row__required' )
				).toBeVisible();
				await expect(
					row
						.locator(
							'.vkbm-booking-custom-field-row__actions button'
						)
						.first()
				).toBeVisible();
			}
		}
	} );
} );
