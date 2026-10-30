/**
 * issue #830: 1室1泊あたりでは最低申し込み人数の行を隠す。
 */
import { test, expect } from '@playwright/test';
import { loginAsAdmin, wpCliArgs, wpEvalPhp } from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const TITLE = 'E2E830 Per Room Min Guests';
let menuId = '';
let originalSettings = '';

test.describe( 'issue #830: 料金の単位と最低申し込み人数の表示連動', () => {
	test.beforeAll( async () => {
		originalSettings = wpCliArgs(
			[ 'option', 'get', 'vkbm_provider_settings', '--format=json' ],
			{ stdio: 'pipe' }
		);
		// @step 管理画面で業種を宿泊にし、予約枠の定員を有効にしておく
		await configureProviderSettings( {
			industry_preset: 'lodging',
			slot_capacity_enabled: true,
		} );
		// @step 「1室1泊あたり」料金・最大人数 3 名・最低申し込み人数 2 名のサービスメニューを作成しておく
		menuId = wpEvalPhp( `
			$id = wp_insert_post( array(
				'post_type' => 'vkbm_service_menu', 'post_status' => 'publish', 'post_title' => '${ TITLE }',
			) );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			update_post_meta( $id, '_vkbm_night_price_unit', 'per_room' );
			update_post_meta( $id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $id, '_vkbm_max_capacity', 3 );
			update_post_meta( $id, '_vkbm_min_capacity', 2 );
			echo $id;
		` ).trim();
	} );

	test.afterAll( () => {
		if ( menuId ) {
			wpCliArgs( [ 'post', 'delete', menuId, '--force' ], {
				stdio: 'ignore',
			} );
		}
		wpCliArgs(
			[
				'option',
				'update',
				'vkbm_provider_settings',
				originalSettings,
				'--format=json',
			],
			{ stdio: 'pipe' }
		);
	} );

	test( '1室1泊あたりでは非表示、1名1泊あたりへ切り替えると表示する', async ( {
		page,
	} ) => {
		// @step 管理者でログインし、そのサービスメニューの編集画面を開く
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		const minGuestsRow = page.locator( '#vkbm-min-capacity-field' );
		await minGuestsRow.waitFor( { state: 'attached', timeout: 15000 } );
		// @check 料金の単位が「1室1泊あたり」のとき、最低申し込み人数の行が隠れている
		await expect( minGuestsRow ).toHaveAttribute( 'hidden', '' );

		// @step 料金の単位を「1名1泊あたり」に切り替える
		await page.evaluate( () => {
			const radio = document.querySelector< HTMLInputElement >(
				'input[name="vkbm_service_menu[night_price_unit]"][value="per_guest"]'
			);
			if ( ! radio ) {
				throw new Error(
					'1名1泊あたりのラジオボタンが見つかりません。'
				);
			}
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		// @check 最低申し込み人数の行が表示される
		await expect( minGuestsRow ).not.toHaveAttribute( 'hidden', '' );
	} );
} );
