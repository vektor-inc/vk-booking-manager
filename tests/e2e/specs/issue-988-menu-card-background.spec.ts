import { test, expect } from '@playwright/test';
import { loginAsAdmin, wpEvalPhp } from '../utils/helpers';
import { getTextContrast } from '../utils/contrast';

const themes = [
	{
		name: 'Lightning dark',
		background: '#272727',
		text: '#eeeeee',
		heading: '#eeeeee',
	},
	{
		name: 'Twenty Twenty-Four dark',
		background: '#333333',
		text: '#dddddd',
		heading: '#f9f9f9',
	},
	{
		name: 'Light beige',
		background: '#f5efdf',
		text: '#333333',
		heading: '#333333',
	},
];
let pageId = 0;
let menuId = 0;
let savedSettings = '';

/**
 * 選んだ背景・検索デザインを保存する。
 *
 * @param background 背景の選択値。
 * @param search     検索デザイン。
 */
function setBackground( background: string, search = 'framed' ) {
	wpEvalPhp( `$settings = get_option( 'vkbm_provider_settings', array() );
		$settings['design_menu_card_background'] = '${ background }';
		$settings['design_menu_search_style'] = '${ search }';
		$settings['reservation_show_menu_search'] = true;
		$settings['reservation_show_menu_list'] = true;
		$settings['design_menu_card_catch_color'] = '';
		$settings['design_menu_card_price_color'] = '';
		update_option( 'vkbm_provider_settings', $settings );` );
}

test.describe( '#988 サービスメニューカードの背景', () => {
	test.beforeAll( () => {
		savedSettings = wpEvalPhp(
			`echo base64_encode( serialize( get_option( 'vkbm_provider_settings', array() ) ) );`
		);
		// 一覧・単体カード・予約ページの実際の描画を一つのページで比較する。
		menuId = Number(
			wpEvalPhp( `$id = wp_insert_post( array( 'post_type' => 'vkbm_service_menu', 'post_status' => 'publish', 'post_title' => 'Issue 988 Menu', 'post_excerpt' => '<a href="#detail">Excerpt link</a> readable text' ) );
			update_post_meta( $id, '_vkbm_base_price', 5000 );
			update_post_meta( $id, '_vkbm_catch_copy', 'Readable catchphrase' );
			echo $id;` )
		);
		pageId = Number(
			wpEvalPhp(
				`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Issue 988 cards', 'post_content' => '<!-- wp:vk-booking-manager/menu-loop {"loopId":"issue988"} /--><!-- wp:vk-booking-manager/menu-card {"selectedMenuId":${ menuId }} /--><!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->' ) );`
			)
		);
	} );

	test.afterAll( () => {
		wpEvalPhp(
			`update_option( 'vkbm_provider_settings', unserialize( base64_decode( '${ savedSettings }' ) ) ); wp_delete_post( ${ pageId }, true ); wp_delete_post( ${ menuId }, true );`
		);
	} );

	test( '共通測定関数は透明な祖先と oklch を sRGB として扱う', async ( {
		page,
	} ) => {
		// @step 背景が白い領域に濃い文字を表示する
		// @check 透明な入れ物を挟んでも文字と背景の読みやすさを測定できる
		await page.setContent(
			'<div style="background: white"><div><span id="sample" style="color:oklch(from #333 l c h)">Sample</span></div></div>'
		);
		expect(
			await getTextContrast( page.locator( '#sample' ) )
		).toBeCloseTo( 12.63, 1 );
	} );

	test( '共通測定関数は読めない色を例外にする', async ( { page } ) => {
		// @step ブラウザの計算済み色に未対応の値が返る状況を再現する
		// @check 前の色で計算を続けず、読めない色を知らせる
		await page.setContent( '<span id="sample">Sample</span>' );
		await page.evaluate( () => {
			window.getComputedStyle = () =>
				( {
					backgroundColor: 'invalid-color',
					color: '#333',
				} ) as CSSStyleDeclaration;
		} );
		await expect(
			getTextContrast( page.locator( '#sample' ) )
		).rejects.toThrow( '読めない色です: invalid-color' );
	} );

	for ( const theme of themes ) {
		for ( const background of [ 'transparent', 'white' ] ) {
			test( `${ theme.name } / ${ background }: カードと枠付き検索の可読性`, async ( {
				page,
			} ) => {
				// @step カード背景を選び、暗い背景またはベージュのページを開く
				// @check 一覧・単体・予約ページのカードと枠付き検索の文字が読める
				setBackground( background );
				await page.goto( `/?page_id=${ pageId }` );
				await expect(
					page.locator( '.vkbm-plan-summary__search--framed' )
				).toBeVisible();
				// 背景・本文・見出し・リンクのテーマ色を再現する。
				await page.addStyleTag( {
					content: `body { background: ${ theme.background }; color: ${ theme.text }; --wp--preset--color--text-normal: ${ theme.text }; } h3, a { color: ${ theme.heading }; }`,
				} );
				const cards = page.locator(
					`article.vkbm-menu-loop__card-item[data-menu-id="${ menuId }"]`
				);
				await expect( cards ).toHaveCount( 3 );
				for ( const card of await cards.all() ) {
					await expect( card ).toHaveClass(
						background === 'white'
							? /--has-surface/
							: /^(?!.*--has-surface).*$/
					);
					if ( background === 'transparent' ) {
						await expect( card ).toHaveCSS(
							'background-color',
							'rgba(0, 0, 0, 0)'
						);
						continue;
					}
					const selectors = [
						'.vkbm-menu-loop__card-title',
						'.vkbm-menu-loop__card-catch',
						'.vkbm-menu-loop__card-excerpt',
						'.vkbm-menu-loop__card-price',
						'.vkbm-menu-loop__card-prices-value',
					];
					for ( const selector of selectors ) {
						for ( const text of await card
							.locator( selector )
							.all() ) {
							expect(
								await getTextContrast( text ),
								selector
							).toBeGreaterThanOrEqual( 4.5 );
						}
					}
					if ( background === 'white' ) {
						for ( const link of await card
							.locator( '.vkbm-menu-loop__card-excerpt a' )
							.all() ) {
							expect(
								await getTextContrast( link )
							).toBeGreaterThanOrEqual( 4.5 );
						}
					}
				}
				const search = page.locator( '.vkbm-plan-summary__search' );
				await expect( search ).toHaveClass(
					background === 'white'
						? /--has-surface/
						: /^(?!.*--has-surface).*$/
				);
				expect(
					await getTextContrast( search )
				).toBeGreaterThanOrEqual( 4.5 );
				await page.screenshot( {
					path: test
						.info()
						.outputPath( `${ background }-${ theme.name }.png` ),
					fullPage: true,
				} );
			} );
		}
	}

	test( '白でもプレーン検索は透明、明るい個別色は保持する', async ( {
		page,
	} ) => {
		// @step 白いカード背景と明るい個別色を選び、プレーン検索を表示する
		// @check 検索は透明で、料金の個別色が上書きされない
		setBackground( 'white', 'plain' );
		wpEvalPhp(
			`$s = get_option( 'vkbm_provider_settings' ); $s['design_menu_card_catch_color'] = '#eeeeee'; $s['design_menu_card_price_color'] = '#eeeeee'; update_option( 'vkbm_provider_settings', $s );`
		);
		await page.goto( `/?page_id=${ pageId }` );
		await expect(
			page.locator( '.vkbm-plan-summary__search' )
		).toBeVisible();
		await expect(
			page.locator( '.vkbm-plan-summary__search' )
		).not.toHaveClass( /--has-surface/ );
		const price = page
			.locator(
				'.vkbm-menu-loop__card-price, .vkbm-menu-loop__card-prices-value'
			)
			.first();
		expect( await getTextContrast( price ) ).toBeLessThan( 4.5 );
	} );

	test( '設定の順序・ラベル・説明と別タブ保存、エディタープレビュー', async ( {
		page,
	} ) => {
		// @step デザイン設定で白を保存し、店舗タブでも保存して投稿を編集する
		// @check 設定値と説明文が保持され、エディターにも白いカードが表示される
		setBackground( 'transparent' );
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=design'
		);
		const select = page.locator( '#vkbm-design-menu-card-background' );
		await expect( select ).toHaveValue( 'transparent' );
		await expect(
			page.getByLabel( 'サービスメニューカードの背景', { exact: true } )
		).toBeVisible();
		// フォーム様式・高さ・配置を、既存の同種プルダウンと計算後の値で照合する。
		const metrics = await select.evaluate( ( element ) => {
			const search = document.querySelector(
				'#vkbm-design-menu-search-style'
			)!;
			const row = element.closest( 'tr' )!;
			const style = getComputedStyle( element );
			return {
				height: element.getBoundingClientRect().height,
				existingHeight: search.getBoundingClientRect().height,
				x: element.getBoundingClientRect().x,
				existingX: search.getBoundingClientRect().x,
				padding: getComputedStyle( row.querySelector( 'td' )! ).padding,
				fontSize: style.fontSize,
			};
		} );
		expect( metrics.height ).toBe( metrics.existingHeight );
		expect( metrics.x ).toBe( metrics.existingX );
		const labelLines = await page
			.locator( 'label[for="vkbm-design-menu-card-background"]' )
			.evaluate( ( label ) => {
				const text = label.firstChild!;
				const lines: Record< string, string > = {};
				// 各文字の表示位置を測り、末尾一文字だけの折り返しを検出する。
				for ( let i = 0; i < text.textContent!.length; i++ ) {
					const range = document.createRange();
					range.setStart( text, i );
					range.setEnd( text, i + 1 );
					const top = range.getBoundingClientRect().top;
					lines[ top ] =
						( lines[ top ] || '' ) + text.textContent![ i ];
				}
				return Object.values( lines );
			} );
		expect(
			labelLines.every(
				( line ) =>
					line.length > 1 && ! /^[ーァィゥェォッャュョ]/.test( line )
			)
		).toBeTruthy();
		console.log( 'Design rules label lines', labelLines );
		console.log( 'Design rules form metrics', metrics );
		await select.locator( 'xpath=ancestor::tr' ).screenshot( {
			path: test.info().outputPath( 'design-settings-desktop-row.png' ),
		} );
		await page.screenshot( {
			path: test.info().outputPath( 'design-settings.png' ),
			fullPage: true,
		} );
		await select.selectOption( 'white' );
		await page.locator( '#submit' ).click();
		await expect( select ).toHaveValue( 'white' );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=store'
		);
		await page.locator( '#submit' ).click();
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=design'
		);
		await expect( select ).toHaveValue( 'white' );
		await expect(
			page.locator( '#vkbm-design-menu-card-background-description' )
		).toContainText( '明るい色' );
		await page.setViewportSize( { width: 375, height: 812 } );
		await select.scrollIntoViewIfNeeded();
		const bounds = await select.boundingBox();
		expect( bounds!.x + bounds!.width ).toBeLessThanOrEqual( 375 );
		await select.locator( 'xpath=ancestor::tr' ).screenshot( {
			path: test.info().outputPath( 'design-settings-mobile.png' ),
		} );
		await page.setViewportSize( { width: 1280, height: 720 } );
		// サーバープレビューは編集画面でも同じクラスを持つ。
		await page.goto( `/wp-admin/post.php?post=${ pageId }&action=edit` );
		const editor = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect(
			editor.locator( '.vkbm-menu-loop__card-item--has-surface' ).first()
		).toBeVisible();
	} );
} );
