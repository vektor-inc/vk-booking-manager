/**
 * issue #787: BM 設定「デザイン設定」の絞り込み検索のデザイン「枠で囲う」が、
 * 「予約ページの表示要素」の絞り込み検索の ON/OFF に関係なく、検索欄が表示されるすべての画面に当たる。
 *
 * 修正前は、絞り込み検索を OFF（サービスメニュー一覧だけ ON）にしていると、
 * 一覧のカードからメニューを選んだ後に出る検索欄（メニュー・スタッフのプルダウン）が
 * プレーンのまま描かれ、「枠で囲う」を選んでいても枠が付かなかった。
 *
 * 検証するシナリオ:
 *   1. 枠 ON・検索 OFF・一覧 ON・メニュー選択後: プルダウンを含む検索欄が枠で囲われる（修正前は枠なし）。
 *   2. 枠 ON・検索 OFF・一覧 ON・メニュー未選択: 検索欄が描画されないので、枠も余白の箱も出ない。
 *   3. 枠 ON・検索 ON・メニュー選択後: 従来どおり枠が付く（退行しない）。
 *   4. 枠 OFF（プレーン）・検索 OFF・メニュー選択後: 枠は付かない。
 *
 * BM 設定は beforeAll で控えて、afterAll で必ず戻す。
 */
import { test, expect } from '@playwright/test';
import { wpCliArgs, wpEvalPhp, getServiceMenuId } from '../utils/helpers';

const OPTION_NAME = 'vkbm_provider_settings';
const FRAME = '[class*="--framed"]';

let originalSettingsJson: string | null = null;
// beforeAll で控えの取得（option の有無の判定を含む）に成功したときだけ true。
// 取得に失敗した（中止した）ときは false のままにして、afterAll で何も触らない。
let backupCaptured = false;

/**
 * BM 設定の option があるかを返す。判定できないとき（wp-cli の失敗など）は throw する。
 *
 * @return 'present'（ある）または 'absent'（無い）
 */
function getOptionState(): 'present' | 'absent' {
	const state = wpEvalPhp(
		`echo null === get_option( '${ OPTION_NAME }', null ) ? 'absent' : 'present';`,
		{ stdio: 'pipe' }
	);
	if ( state !== 'present' && state !== 'absent' ) {
		throw new Error(
			`BM 設定の有無を判定できませんでした（結果: ${ state }）。`
		);
	}
	return state;
}

/**
 * BM 設定の絞り込み検索のデザインと、予約ページの表示要素（絞り込み検索・サービスメニュー一覧）を書き換える。
 *
 * @param framed     「枠で囲う」を選ぶか（false はプレーン）
 * @param showSearch 「絞り込み検索」を ON にするか
 */
function configure( framed: boolean, showSearch: boolean ): void {
	wpEvalPhp( `
		$s = get_option( '${ OPTION_NAME }', array() );
		$s['design_menu_search_style']     = '${ framed ? 'framed' : 'plain' }';
		$s['reservation_show_menu_search'] = ${ showSearch ? 'true' : 'false' };
		$s['reservation_show_menu_list']   = true;
		update_option( '${ OPTION_NAME }', $s );
	` );
}

test.describe( 'issue #787: 絞り込み検索の ON/OFF によらず「枠で囲う」が当たる', () => {
	test.beforeAll( () => {
		// option の有無を先に確かめる。wp-cli の一時的な失敗を「option が無い」と取り違えると、
		// afterAll が既存の BM 設定を option delete してしまうため、
		// 取得エラーはここで throw して中止し（backupCaptured は false のまま）、afterAll は何もしない。
		const exists = getOptionState();
		if ( exists === 'present' ) {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} else {
			originalSettingsJson = null;
		}
		backupCaptured = true;
	} );

	test.afterAll( () => {
		// 控えの取得に失敗して中止したときは、既存の設定を壊さないよう何もしない。
		if ( ! backupCaptured ) {
			return;
		}
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
		} catch ( error ) {
			// 削除に失敗しても、option が既に無ければ問題ない。
			// 残っているときは共有の e2e 環境に設定が残り、後続 spec が読んでしまうので元のエラーを再送出する。
			// 状態を判定できないときは getOptionState が throw する。
			if ( getOptionState() === 'present' ) {
				throw error;
			}
		}
	} );

	test( '枠 ON・検索 OFF・メニュー選択後: プルダウンを含む検索欄が枠で囲われる', async ( {
		page,
	} ) => {
		// @step 共通設定の「デザイン設定」で絞り込み検索のデザインを「枠で囲う」にし、「予約ページの表示要素」で絞り込み検索 OFF・サービスメニュー一覧 ON にする
		configure( true, false );
		// @step 「Service Menu 1」を選んだ状態の予約ページを開く
		await page.goto( `/booking/?menu_id=${ getServiceMenuId() }` );
		await page.waitForLoadState( 'networkidle' );

		// @check メニュー・スタッフのプルダウンが表示される
		const selectors = page.locator( '.vkbm-plan-summary__selectors' );
		await expect( selectors ).toBeVisible( { timeout: 10000 } );

		// @check プルダウンの欄が枠で囲われ、1px の枠線と内側の余白が付いている
		// プルダウンの行が、枠の要素の中にある。
		const frame = page.locator( FRAME );
		await expect( frame ).toHaveCount( 1 );
		await expect(
			frame.locator( '.vkbm-plan-summary__selectors select' ).first()
		).toBeVisible();

		// 枠の線と内側の余白が実際に効いている。
		const style = await frame.evaluate( ( el ) => {
			const cs = getComputedStyle( el );
			return {
				border: cs.borderTopWidth,
				padding: cs.paddingTop,
			};
		} );
		expect( style.border ).toBe( '1px' );
		expect( parseFloat( style.padding ) ).toBeGreaterThan( 0 );
	} );

	test( '枠 ON・検索 OFF・メニュー未選択: 検索欄が描画されず、枠も出ない', async ( {
		page,
	} ) => {
		// @step 共通設定で絞り込み検索のデザインを「枠で囲う」にし、絞り込み検索 OFF・サービスメニュー一覧 ON にする
		configure( true, false );
		// @step メニューを選ばずに予約ページを開く
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// @check サービスメニュー一覧のカードが表示される
		// 一覧（カード）は出ている。
		await expect(
			page.locator( '.vkbm-reservation-content__menu-list' )
		).toBeVisible( { timeout: 10000 } );

		// @check 検索欄は無く、枠も出ない
		// 検索欄そのものが無く、枠のクラスも付かない。
		await expect(
			page.locator( '.vkbm-plan-summary__search' )
		).toHaveCount( 0 );
		await expect( page.locator( FRAME ) ).toHaveCount( 0 );
	} );

	test( '枠 ON・検索 ON・メニュー選択後: 従来どおり枠が付く', async ( {
		page,
	} ) => {
		// @step 共通設定で絞り込み検索のデザインを「枠で囲う」にし、絞り込み検索 ON・サービスメニュー一覧 ON にする
		configure( true, true );
		// @step 「Service Menu 1」を選んだ状態の予約ページを開く
		await page.goto( `/booking/?menu_id=${ getServiceMenuId() }` );
		await page.waitForLoadState( 'networkidle' );

		// @check プルダウンの欄が表示され、枠で囲われている
		await expect(
			page.locator( '.vkbm-plan-summary__selectors' )
		).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( FRAME ) ).toHaveCount( 1 );
	} );

	test( '枠 OFF（プレーン）・検索 OFF・メニュー選択後: 枠は付かない', async ( {
		page,
	} ) => {
		// @step 共通設定で絞り込み検索のデザインを「プレーン」にし、絞り込み検索 OFF・サービスメニュー一覧 ON にする
		configure( false, false );
		// @step 「Service Menu 1」を選んだ状態の予約ページを開く
		await page.goto( `/booking/?menu_id=${ getServiceMenuId() }` );
		await page.waitForLoadState( 'networkidle' );

		// @check プルダウンの欄は表示されるが、枠で囲われていない
		await expect(
			page.locator( '.vkbm-plan-summary__selectors' )
		).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( FRAME ) ).toHaveCount( 0 );
	} );
} );
