/**
 * PR #317 (issue #247): 予約確認画面の料金表示を指名機能の ON/OFF から分離する修正のフロント検証。
 *
 * 修正前の不具合:
 * - 料金表示ブロック（基本料金・指名料・合計）が丸ごと `staffEnabled`（指名機能 ON/OFF）の
 *   条件で囲まれていたため、指名機能を OFF（自動割り当て）にすると確認画面に
 *   基本料金・合計が一切表示されず、ユーザーが金額を確認できなかった。
 *
 * 本 spec の検証内容:
 * - 指名OFF: 確認画面で「サービス基本料金」「基本料金合計」が表示される／「指名料」は表示されない
 * - 指名ON:  確認画面で「サービス基本料金」「指名料」「基本料金合計」がすべて表示される（デグレ確認）
 * - 指名OFF: 「基本料金合計」行が二重表示されない（PR #279 由来のフォールバック分岐削除の確認）
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - 料金付きの単価メニュー（指名料を持つスタッフ付き）を beforeAll で WP-CLI / wpEvalPhp により
 *   セットアップし、afterAll で削除・指名設定を元へ復元する。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// 料金付き単価メニューの想定値。
// Fixture values for the priced single menu.
const PRICED_MENU_TITLE = 'Priced Menu 317';
const BASE_PRICE = 5000; // メニュー基本料金
const NOMINATION_FEE = 1500; // スタッフ指名料

// 確認画面の料金行のラベル（ja ロケール）。日本語訳が po で定義済み。
// Fee row labels on the confirm screen (ja locale, defined in the .po files).
const LABEL_BASE = 'サービス基本料金'; // Service basic fee
const LABEL_NOMINATION = '指名料'; // Nomination fee
const LABEL_TOTAL = '基本料金合計'; // Total basic fee

let originalStaffEnabled = true;
let pricedMenuId = '';
// 共有スタッフの指名料メタの退避先。afterAll で確実に元へ戻し、他 spec への状態リークを防ぐ。
// Holds the shared staff's original nomination-fee meta so afterAll can restore it
// and avoid leaking state into other specs that reuse the same staff.
let originalNominationFee = '';
let nominationStaffId = '';

/**
 * 共有スタッフの現在の指名料メタ（`_vkbm_nomination_fee`）を読み出して退避し、
 * テスト用の指名料（NOMINATION_FEE）を書き込む。退避値は afterAll で復元する。
 * 未設定の場合は空文字を退避し、復元時にメタを削除する。
 *
 * Save the shared staff's current nomination-fee meta, then write the test value.
 * The saved value is restored in afterAll (an empty saved value means the meta was
 * unset and is deleted on restore).
 */
function seedStaffNominationFee(): void {
	nominationStaffId = getStaffId();
	// 退避: 現在値を読み出す（未設定なら空文字）。
	originalNominationFee = wpEvalPhp(
		`echo (string) get_post_meta( ${ Number.parseInt(
			nominationStaffId,
			10
		) }, '_vkbm_nomination_fee', true );`
	).trim();
	// テスト用の指名料を書き込む（指名ON時の「指名料」行の値確認用）。
	wpEvalPhp(
		`update_post_meta( ${ Number.parseInt(
			nominationStaffId,
			10
		) }, '_vkbm_nomination_fee', ${ NOMINATION_FEE } );`
	);
}

/**
 * seedStaffNominationFee で退避したスタッフの指名料メタを元の状態へ復元する。
 * 退避値が空（＝元々未設定）の場合はメタを削除する。
 *
 * Restore the staff's nomination-fee meta saved by seedStaffNominationFee.
 * Delete the meta when the saved value was empty (i.e. it was originally unset).
 */
function restoreStaffNominationFee(): void {
	if ( ! nominationStaffId ) {
		return;
	}
	const staffIdNum = Number.parseInt( nominationStaffId, 10 );
	if ( originalNominationFee === '' ) {
		wpEvalPhp(
			`delete_post_meta( ${ staffIdNum }, '_vkbm_nomination_fee' );`
		);
	} else {
		// 退避値は数値文字列のはず。数値化して埋め込み、PHP リテラル脱出を防ぐ。
		wpEvalPhp(
			`update_post_meta( ${ staffIdNum }, '_vkbm_nomination_fee', ${ Number.parseInt(
				originalNominationFee,
				10
			) } );`
		);
	}
	nominationStaffId = '';
}

/**
 * 料金付き単価メニュー（基本料金 5000）を作成し、グローバルセットアップ済みのスタッフを
 * 割り当てる。同名メニューがあれば作り直す（冪等）。
 * スタッフの指名料は seedStaffNominationFee で別途設定・退避する。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedPricedMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };

		// 既存の同名メニューを削除して決定的な状態にする。
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ PRICED_MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ PRICED_MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create priced menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ BASE_PRICE } );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Priced menu seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 予約ページを開き、料金付き単価メニューを選んで日付・スロットまで進め、確認画面を開くヘルパー。
 * Open the booking page, pick the priced menu, select a date and slot, then open the confirm screen.
 */
async function gotoConfirmScreen( page: Page ) {
	// baseURL（playwright.config）に対する相対パスで開く。
	await page.goto( '/booking/' );
	await page.waitForLoadState( 'networkidle' );

	// 料金付きメニュー（Priced Menu 317）の予約ボタンをクリックする。
	const reserveButtons = page.locator( '.vkbm-menu-loop__button--reserve' );
	await reserveButtons
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
	// 対象メニューのカードを名前で特定してその予約ボタンを押す。
	const menuCard = page
		.locator( '.vkbm-menu-loop__item', { hasText: PRICED_MENU_TITLE } )
		.first();
	// 予約ボタンのクリック前に calendar-meta のレスポンス待ちをセットアップする。
	const calendarMetaResponse = page.waitForResponse(
		( res ) => /calendar-meta/.test( res.url() ),
		{ timeout: 15000 }
	);
	await menuCard
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first()
		.click();

	// 空き枠のある日を選ぶ（共有ヘルパーでフレーク回避）。
	// 直後の slots.waitFor が次ステップを明示的にゲートするため固定 wait は不要。
	await selectAvailableCalendarDay( page, calendarMetaResponse );

	// 最初の時間枠を選ぶ（出現を待ってからクリック）。
	const slots = page.locator( '.vkbm-slot-list__item' );
	await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
	await slots.first().click();

	// スロット選択後にプラン概算が再計算され「予約へ進む」が活性化するのを待つ。
	// 固定 wait の代わりにボタンの enabled 状態を明示的に待ってクリックする。
	const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( proceed ).toBeEnabled( { timeout: 10000 } );
	await proceed.click();
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

	// 確認サマリの表示を待つ。
	const summary = page.locator( '.vkbm-confirm__summary, .vkbm-confirm' );
	await summary.first().waitFor( { state: 'visible', timeout: 15000 } );
	// サマリ内の料金行（サービス基本料金）が描画されるまで待つ。
	// 固定 wait の代わりに、確認対象である基本料金行の出現を明示的に待つことで
	// React の再レンダリング収束を決定的に待機する。
	await summaryRowByTitle( page, LABEL_BASE )
		.first()
		.waitFor( { state: 'attached', timeout: 15000 } );
}

// 指定ラベル（dt のタイトル）を持つ確認サマリ行の数を返す。
// Count confirm summary rows whose title (dt) equals the given label.
function summaryRowByTitle( page: Page, title: string ) {
	return page.locator( '.vkbm-confirm__summary-item', {
		has: page.locator( '.vkbm-confirm__summary-item-title', {
			hasText: title,
		} ),
	} );
}

test.describe( 'PR #317: 確認画面の料金表示（指名OFFでも基本料金・合計を表示）', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		// 共有スタッフの指名料メタを退避してからテスト値を書き込む。
		seedStaffNominationFee();
		pricedMenuId = seedPricedMenu();
	} );

	test.afterAll( () => {
		// テストデータを削除し、指名料メタ・指名設定を元の状態へ復元してテスト間の状態漏れを防ぐ。
		if ( pricedMenuId ) {
			wpCliArgs( [ 'post', 'delete', pricedMenuId, '--force' ], {
				stdio: 'ignore',
			} );
			pricedMenuId = '';
		}
		// 共有スタッフの指名料メタを元へ戻す（他 spec への状態リーク防止）。
		restoreStaffNominationFee();
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '指名OFF: 確認画面に基本料金・合計が表示され、指名料は表示されない', async ( {
		page,
	} ) => {
		// 指名機能を OFF（自動割り当て）にする。
		setStaffEnabled( false );

		await gotoConfirmScreen( page );

		// 「サービス基本料金」行が表示される（本不具合の本丸）。
		const baseRow = summaryRowByTitle( page, LABEL_BASE );
		await expect( baseRow ).toHaveCount( 1 );
		await expect( baseRow ).toBeVisible();
		// 基本料金 5,000 が表示される。
		await expect( baseRow ).toContainText( '5,000' );

		// 「基本料金合計」行が表示される。
		const totalRow = summaryRowByTitle( page, LABEL_TOTAL );
		await expect( totalRow ).toHaveCount( 1 ); // ← 二重表示でないこと（厳密に 1 行）
		await expect( totalRow ).toBeVisible();
		await expect( totalRow ).toContainText( '5,000' );

		// 「指名料」行は表示されない（指名OFFのため）。
		await expect(
			summaryRowByTitle( page, LABEL_NOMINATION )
		).toHaveCount( 0 );

		// スクリーンショット（指名OFFの確認画面・料金欄）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/pr317-after-confirm-staff-off.png',
			fullPage: true,
		} );
	} );

	test( '指名ON: 確認画面に基本料金・指名料・合計がすべて表示される（デグレ確認）', async ( {
		page,
	} ) => {
		// 指名機能を ON にする。
		setStaffEnabled( true );

		await gotoConfirmScreen( page );

		// 「サービス基本料金」行が表示される。
		const baseRow = summaryRowByTitle( page, LABEL_BASE );
		await expect( baseRow ).toHaveCount( 1 );
		await expect( baseRow ).toBeVisible();
		await expect( baseRow ).toContainText( '5,000' );

		// 「指名料」行が表示され、指名料 1,500 が出る（価格計算の回帰確認）。
		const nominationRow = summaryRowByTitle( page, LABEL_NOMINATION );
		await expect( nominationRow ).toHaveCount( 1 );
		await expect( nominationRow ).toBeVisible();
		await expect( nominationRow ).toContainText( '1,500' );

		// 「基本料金合計」行が表示される（1 行のみ＝二重表示でない）。
		// 合計は 基本料金 5,000 ＋ 指名料 1,500 = 6,500（価格計算の回帰確認）。
		const totalRow = summaryRowByTitle( page, LABEL_TOTAL );
		await expect( totalRow ).toHaveCount( 1 );
		await expect( totalRow ).toBeVisible();
		await expect( totalRow ).toContainText( '6,500' );

		// スクリーンショット（指名ONの確認画面・料金欄）。
		await page.screenshot( {
			path: '/tmp/vkbm-shots/pr317-after-confirm-staff-on.png',
			fullPage: true,
		} );
	} );
} );
