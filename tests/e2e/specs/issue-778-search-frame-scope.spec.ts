/**
 * issue #778: 「絞り込み検索のデザイン（枠で囲う）」を ON にしたとき、枠で囲う範囲は
 * 絞り込み検索の欄（メニュー・客室のプルダウン、属性チェックボックス）までにする。
 * その下の「選んだメニューのカード」と「料金欄」は枠の外に出す。
 *
 * 検証するシナリオ:
 *   1. 「枠で囲う」ON・メニュー未選択: 枠の要素の中にプルダウンがあり、
 *      「メニューを選択してください」のアラート（料金欄の位置）は枠の外にある。
 *   2. 「枠で囲う」ON・メニュー選択後: メニューのプレビューと料金欄が枠の外にあり、
 *      プルダウンは枠の中に残る。
 *   3. 「枠で囲う」OFF: 枠のクラスがどの要素にも付かない。
 *   4. 「枠で囲う」ON・日付で絞り込む ON・メニュー未選択: 日付欄も枠の中に入る。
 *   5. 余白の階層（design-rules.md）: 枠 ON のとき、見えている端どうしで
 *      「枠内の欄どうしの最大の間隔 ≦ 枠の内側の余白（上下左右） ＜ 枠の下端から次の要素」が成り立つ
 *      （PC 1280px・375px、日付欄あり／なし、メニュー未選択／選択後）。
 *
 * 枠の要素は、クラス名の実装詳細に依存しないよう `[class*="--framed"]` で探す
 * （修正前は外側の .vkbm-plan-summary にこのクラスが付き、プレビューと料金欄まで入っていた）。
 *
 * BM 設定は beforeAll で控えて、afterAll で必ず戻す。
 */
import { test, expect, type Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp } from '../utils/helpers';

const OPTION_NAME = 'vkbm_provider_settings';
const FRAME = '[class*="--framed"]';

/**
 * 枠の内側の余白（上下左右）、枠内の欄どうしの縦の最大の間隔、枠の下端から次の要素までの距離を、
 * 見えている端どうしで測る（px）。
 *
 * 見えている端の取り方（design-rules.md「余白は見た目の間隔で判定する」）:
 * - 線や背景のある部品（プルダウン・入力欄・チェックボックス・ボタン・背景か線のある箱）は、その箱の端
 * - 文字は、文字の範囲（Range）の上下を (範囲の高さ − 文字サイズ) ÷ 2 ずつ内側へ寄せた字面の端
 * - 視覚的に隠した要素（legend など、幅か高さが 2px 以下）は数えない
 *
 * @param page Playwright のページ
 */
async function measureSpacing( page: Page ) {
	return page.evaluate( ( selector ) => {
		type Box = { top: number; bottom: number; left: number; right: number };

		const frame = document.querySelector< HTMLElement >( selector );
		if ( ! frame ) {
			throw new Error( 'frame not found' );
		}

		const hasPaint = ( style: CSSStyleDeclaration ): boolean =>
			style.backgroundColor !== 'rgba(0, 0, 0, 0)' ||
			parseFloat( style.borderTopWidth ) > 0 ||
			parseFloat( style.borderBottomWidth ) > 0 ||
			parseFloat( style.borderLeftWidth ) > 0 ||
			parseFloat( style.borderRightWidth ) > 0;

		/**
		 * 親の中で見えている端（箱と字面）を集める。
		 *
		 * @param root        集める範囲の親要素
		 * @param includeRoot 親要素自身の箱も数えるか
		 */
		const collect = ( root: Element, includeRoot: boolean ): Box[] => {
			const boxes: Box[] = [];
			const visit = ( el: Element, isRoot: boolean ) => {
				const style = getComputedStyle( el );
				const rect = el.getBoundingClientRect();
				if (
					style.display === 'none' ||
					style.visibility === 'hidden' ||
					rect.width <= 2 ||
					rect.height <= 2
				) {
					return;
				}
				const isControl = [
					'SELECT',
					'INPUT',
					'BUTTON',
					'TEXTAREA',
				].includes( el.tagName );
				if (
					( ! isRoot || includeRoot ) &&
					( isControl || hasPaint( style ) )
				) {
					boxes.push( {
						top: rect.top,
						bottom: rect.bottom,
						left: rect.left,
						right: rect.right,
					} );
				}
				if ( isControl ) {
					return;
				}
				const fontSize = parseFloat( style.fontSize );
				el.childNodes.forEach( ( node ) => {
					if ( node.nodeType === Node.TEXT_NODE ) {
						if ( ! node.textContent?.trim() ) {
							return;
						}
						const range = document.createRange();
						range.selectNodeContents( node );
						// 折り返した文字は行ごとの矩形に分かれる。行ごとに字面へ寄せる。
						Array.from( range.getClientRects() ).forEach( ( r ) => {
							if ( r.width === 0 || r.height === 0 ) {
								return;
							}
							const lead = Math.max(
								0,
								( r.height - fontSize ) / 2
							);
							boxes.push( {
								top: r.top + lead,
								bottom: r.bottom - lead,
								left: r.left,
								right: r.right,
							} );
						} );
					} else if ( node.nodeType === Node.ELEMENT_NODE ) {
						visit( node as Element, false );
					}
				} );
			};
			visit( root, true );
			return boxes;
		};

		const frameRect = frame.getBoundingClientRect();
		const frameStyle = getComputedStyle( frame );
		const inner = {
			top: frameRect.top + parseFloat( frameStyle.borderTopWidth ),
			bottom:
				frameRect.bottom - parseFloat( frameStyle.borderBottomWidth ),
			left: frameRect.left + parseFloat( frameStyle.borderLeftWidth ),
			right: frameRect.right - parseFloat( frameStyle.borderRightWidth ),
		};

		const items = collect( frame, false );
		const padding = {
			top: Math.min( ...items.map( ( b ) => b.top ) ) - inner.top,
			bottom:
				inner.bottom - Math.max( ...items.map( ( b ) => b.bottom ) ),
			left: Math.min( ...items.map( ( b ) => b.left ) ) - inner.left,
			right: inner.right - Math.max( ...items.map( ( b ) => b.right ) ),
		};

		// 縦に並ぶ行どうしの間隔の最大値（横に並ぶ欄は同じ行として扱う）。
		const sorted = [ ...items ].sort( ( a, b ) => a.top - b.top );
		let innerGap = 0;
		let rowBottom = sorted[ 0 ].bottom;
		for ( let i = 1; i < sorted.length; i++ ) {
			if ( sorted[ i ].top > rowBottom ) {
				innerGap = Math.max( innerGap, sorted[ i ].top - rowBottom );
			}
			rowBottom = Math.max( rowBottom, sorted[ i ].bottom );
		}

		const next = frame.nextElementSibling;
		let below = 0;
		const nextBoxes = next ? collect( next, true ) : [];
		if ( next ) {
			below =
				Math.min( ...nextBoxes.map( ( b ) => b.top ) ) -
				frameRect.bottom;
		}

		return {
			below,
			padding,
			minPadding: Math.min(
				padding.top,
				padding.bottom,
				padding.left,
				padding.right
			),
			innerGap,
			hasNext: Boolean( next ),
			itemCount: items.length,
			nextCount: nextBoxes.length,
		};
	}, FRAME );
}

let originalSettingsJson: string | null = null;

/**
 * BM 設定の絞り込み検索のデザイン（「枠で囲う」= framed / 「プレーン」= plain）と、予約ページの表示要素（一覧 OFF・絞り込み検索 ON）を書き換える。
 * 一覧を OFF にするのは、メニュー未選択のアラートを料金欄の位置に出すため。
 *
 * @param framed     「枠で囲う」を ON にするか
 * @param dateSearch 「日付で絞り込む」を ON にするか（日付欄は絞り込み検索 ON かつメニュー未選択のときだけ出る）
 */
function configure( framed: boolean, dateSearch = false ): void {
	wpEvalPhp( `
		$s = get_option( '${ OPTION_NAME }', array() );
		$s['design_menu_search_style']     = '${ framed ? 'framed' : 'plain' }';
		$s['reservation_date_search_enabled'] = ${ dateSearch ? 'true' : 'false' };
		$s['reservation_show_menu_list']   = false;
		$s['reservation_show_menu_search'] = true;
		update_option( '${ OPTION_NAME }', $s );
	` );
}

test.describe( 'issue #778: 絞り込み検索の枠の範囲', () => {
	test.beforeAll( () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}
	} );

	test.afterAll( () => {
		if ( originalSettingsJson ) {
			wpCliArgs(
				[
					'option',
					'update',
					OPTION_NAME,
					originalSettingsJson,
					'--format=json',
				],
				{ stdio: 'pipe' }
			);
			return;
		}
		// 開始前に option が無かったときは、テストが作った設定を残さないよう option ごと消す。
		try {
			wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
		} catch {
			// 元から無いなら何もしない。
		}
	} );

	test( '枠 ON・メニュー未選択: プルダウンは枠の中、アラートは枠の外', async ( {
		page,
	} ) => {
		configure( true );
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		const frame = page.locator( FRAME );
		await expect( frame ).toHaveCount( 1, { timeout: 10000 } );

		// メニューのプルダウンが枠の中にある。
		await expect(
			frame.locator( '.vkbm-plan-summary__selectors select' ).first()
		).toBeVisible();

		// アラートは画面にあるが、枠の中には無い。
		await expect(
			page.locator( '.vkbm-plan-summary__pricing--alert' )
		).toBeVisible();
		await expect(
			frame.locator( '.vkbm-plan-summary__pricing--alert' )
		).toHaveCount( 0 );
	} );

	test( '枠 ON・メニュー選択後: プレビューと料金欄は枠の外、プルダウンは枠の中', async ( {
		page,
	} ) => {
		configure( true );
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// メニューのプルダウンで先頭のメニューを選ぶ。
		const menuSelect = page
			.locator( '.vkbm-plan-summary__selectors select' )
			.first();
		await expect( menuSelect ).toBeVisible( { timeout: 10000 } );
		await menuSelect.selectOption( { index: 1 } );

		// 選択後は、メニューのプレビューと料金欄が画面に出る。
		await expect(
			page.locator( '.vkbm-plan-summary__menu-preview' )
		).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator( '.vkbm-plan-summary__pricing' )
		).toBeVisible( { timeout: 10000 } );

		const frame = page.locator( FRAME );
		await expect( frame ).toHaveCount( 1 );

		// プルダウンは枠の中にある。
		await expect(
			frame.locator( '.vkbm-plan-summary__selectors select' ).first()
		).toBeVisible();

		// プレビューと料金欄は枠の中に無い。
		await expect(
			frame.locator( '.vkbm-plan-summary__menu-preview' )
		).toHaveCount( 0 );
		await expect(
			frame.locator( '.vkbm-plan-summary__pricing' )
		).toHaveCount( 0 );
	} );

	test( '枠 OFF: 枠のクラスがどこにも付かない', async ( { page } ) => {
		configure( false );
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		await expect(
			page.locator( '.vkbm-plan-summary__selectors' )
		).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( FRAME ) ).toHaveCount( 0 );
	} );

	test( '枠 ON・日付で絞り込む ON・メニュー未選択: 日付欄も枠の中に入る', async ( {
		page,
	} ) => {
		configure( true, true );
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		const frame = page.locator( FRAME );
		await expect( frame ).toHaveCount( 1, { timeout: 10000 } );
		await expect(
			frame.locator( '.vkbm-plan-summary__date-input' ).first()
		).toBeVisible();
	} );

	for ( const viewport of [
		{ name: 'PC 1280px', width: 1280, height: 900 },
		{ name: 'スマホ 375px', width: 375, height: 800 },
	] ) {
		for ( const dateSearch of [ false, true ] ) {
			test( `余白の階層(${ viewport.name }・日付欄${
				dateSearch ? 'あり' : 'なし'
			}): 枠内の欄どうしの間隔 ≦ 枠の内側の余白（上下左右） ＜ 枠の下端から次の要素`, async ( {
				page,
			} ) => {
				await page.setViewportSize( {
					width: viewport.width,
					height: viewport.height,
				} );
				configure( true, dateSearch );
				await page.goto( '/booking/' );
				await page.waitForLoadState( 'networkidle' );
				await expect( page.locator( FRAME ) ).toHaveCount( 1, {
					timeout: 10000,
				} );
				if ( dateSearch ) {
					await expect(
						page.locator( '.vkbm-plan-summary__date-input' ).first()
					).toBeVisible();
				}

				const check = async ( label: string ) => {
					const m = await measureSpacing( page );
					console.log(
						`[#778 ${ viewport.name } 日付${ dateSearch } ${ label }]`,
						JSON.stringify( m )
					);
					expect( m.hasNext, label ).toBe( true );
					// 測る対象が0件だと Math.min が Infinity になり判定が素通りするため、測れていることを確かめる。
					expect( m.itemCount, label ).toBeGreaterThan( 0 );
					expect( m.nextCount, label ).toBeGreaterThan( 0 );
					expect( m.innerGap, label ).toBeGreaterThan( 0 );
					expect(
						m.padding.top,
						`${ label } 上`
					).toBeGreaterThanOrEqual( m.innerGap );
					expect(
						m.padding.bottom,
						`${ label } 下`
					).toBeGreaterThanOrEqual( m.innerGap );
					expect(
						m.padding.left,
						`${ label } 左`
					).toBeGreaterThanOrEqual( m.innerGap );
					expect(
						m.padding.right,
						`${ label } 右`
					).toBeGreaterThanOrEqual( m.innerGap );
					expect( m.below, `${ label } 枠の下端` ).toBeGreaterThan(
						m.minPadding
					);
					expect( m.below, `${ label } 枠の下端` ).toBeGreaterThan(
						m.innerGap
					);
				};

				// メニュー未選択（次の要素は案内）。
				await check( 'メニュー未選択' );

				// メニュー選択後（次の要素はメニューのカード）。
				const menuSelect = page
					.locator( '.vkbm-plan-summary__selectors select' )
					.first();
				await menuSelect.selectOption( { index: 1 } );
				await expect(
					page.locator( '.vkbm-plan-summary__menu-preview' )
				).toBeVisible( { timeout: 10000 } );
				await check( 'メニュー選択後' );
			} );
		}
	}
} );
