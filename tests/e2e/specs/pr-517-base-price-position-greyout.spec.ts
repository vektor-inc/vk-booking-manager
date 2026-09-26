/**
 * PR #517 (issue #514): サービスメニュー編集画面の「基本料金（税込）」欄の位置移動と
 * グレーアウト表示の e2e 検証。
 *
 * 変更内容:
 * - 「基本料金（税込）」の行を、キャッチコピー直後から「料金区分」の行の直前へ移動。
 *   料金区分の行が描画されない構成（無料版・予約枠の定員機能OFF）でも、基本料金の行は
 *   必ず表示され、位置は同じ並びの末尾側（料金区分が入るはずの位置）になる。
 * - 料金区分の行が表示中（hidden でない）かつ、区分名（trim後）が空でない行が1件以上
 *   あるときは、基本料金の入力欄を readonly にしてグレー表示し、
 *   「料金区分が設定されているため、基本料金は使われません。」を表示する。
 *   料金区分の追加・削除、区分名の入力、「複数人一括予約」チェック、
 *   「予約枠の定員」の変更に合わせて即時に切り替わる。
 * - readonly（disabled ではない）を使うため、グレー表示のまま保存しても基本料金の値は
 *   消えずに残る。
 *
 * 対象コード:
 * - src/admin/class-service-menu-editor.php
 *   render_base_price_field()（基本料金の行を単体で出力する新設メソッド）と、
 *   render_conditions_meta_box() からの3箇所の呼び出し（定員機能ON・OFF・無料版）。
 * - assets/js/service-menu-editor.js の syncBasePriceOverride()（即時グレーアウト切替）。
 * - assets/scss/admin-core.scss の .vkbm-base-price-overridden（グレー表示のスタイル）。
 *
 * 本 spec の検証内容:
 * 1. 並び順: 予約枠の定員機能ON・料金区分行が表示される構成で、基本料金の行が
 *    キャッチコピー直後ではなく「料金区分」の行の直前にあること。
 *    二重描画（基本料金の入力欄が2つ出る）がないことも合わせて確認する（#514再レビュー対応）。
 * 2. グレー表示の切り替え: 区分名の追加・空欄（半角スペースのみ含む）・削除、
 *    「複数人一括予約」チェック、「予約枠の定員」の変更に応じて即時に切り替わること。
 * 3. 保存しても基本料金が消えないこと（グレー表示のまま保存→再読込でも値が残る、
 *    区分をすべて削除して保存→通常表示に戻り値は残る）。
 * 4. デグレ確認: 予約枠の定員機能をサイト全体でOFFにした構成でも、基本料金の行は
 *    表示され、料金区分の行はDOMに存在しない（描画分岐自体が異なるため）。
 *
 * 前提:
 * - Pro版が有効（グローバルセットアップの既定環境）。
 * - 指名機能はOFFにして検証する（指名の有無はこの表示条件に関係しないため、
 *   他spec（multi-guest-fields-visibility.spec.ts）と同じ前提に揃えてノイズを減らす）。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';

// 基本料金・料金区分まわりの要素ロケーター。
const BASE_PRICE_ROW = '#vkbm-base-price-field';
const BASE_PRICE_INPUT = '#vkbm_service_menu_base_price';
const BASE_PRICE_DESCRIPTION = '#vkbm-base-price-overridden-description';
const PRICE_TIERS_ROW = '#vkbm-price-tiers-field';
const PRICE_TIER_ADD = '#vkbm-price-tier-add';
const PRICE_TIER_REMOVE = '.vkbm-price-tier-remove';
const PRICE_TIER_LABEL = '.vkbm-price-tier-label';
const ALLOW_MULTI = '#vkbm_service_menu_allow_multiple_guests';
const MAX_CAPACITY = '#vkbm_service_menu_max_capacity';
const OVERRIDDEN_CLASS = 'vkbm-base-price-overridden';

// グレー表示中に表示される説明文（ja ロケール、po 定義済み）。
const OVERRIDDEN_DESCRIPTION_TEXT =
	'料金区分が設定されているため、基本料金は使われません。';

const SHOTS_DIR = '/tmp/vkbm-shots/pr-517-base-price';

/**
 * 指定した投稿タイプ・タイトルの既存投稿をすべて force delete する PHP スニペットを返す。
 *
 * @param postType 対象の投稿タイプ
 * @param title    削除対象のタイトル
 * @return 同名投稿削除の PHP スニペット
 */
function deletePostsByTitlePhp( postType: string, title: string ): string {
	return `
		$existing = get_posts( array(
			'post_type'   => '${ postType }',
			'post_status' => 'any',
			'title'       => '${ title }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
	`;
}

/**
 * シード PHP が echo した post ID を検証し、正の整数でなければ throw する。
 *
 * @param result  wpEvalPhp の戻り値（trim 済みの文字列）
 * @param context 失敗メッセージに含める文脈
 * @return 検証済みの post ID（数値文字列）
 */
function assertSeededId( result: string, context: string ): string {
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ context } seeding failed: "${ result }"` );
	}
	return result;
}

// 予約枠の定員機能（親スイッチ）を on/off するヘルパー。
function setSlotCapacityEnabled( enabled: boolean ): void {
	const val = enabled ? '1' : '0';
	wpEvalPhp( `
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['slot_capacity_enabled'] = ${ val };
		unset( $s['multiple_guests_enabled'] );
		update_option( 'vkbm_provider_settings', $s );
	` );
}

/**
 * 検証用のサービスメニューを作成する。
 * 複数人一括予約ON・予約枠の定員2・料金区分は空（未登録）・基本料金9000で作成する。
 * 同名メニューがあれば作り直す（冪等）。
 *
 * @param title 作成するメニューのタイトル
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedMenu( title: string ): string {
	const phpCode = `
		${ deletePostsByTitlePhp( 'vkbm_service_menu', title ) }
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_base_price', 9000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		delete_post_meta( $menu_id, '_vkbm_price_tiers' );
		echo $menu_id;
	`;
	return assertSeededId( wpEvalPhp( phpCode ).trim(), `Menu (${ title })` );
}

/**
 * 予約枠の定員機能がOFFの構成用の、最小限のサービスメニューを作成する。
 *
 * @param title 作成するメニューのタイトル
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedSimpleMenu( title: string ): string {
	const phpCode = `
		${ deletePostsByTitlePhp( 'vkbm_service_menu', title ) }
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_base_price', 4500 );
		echo $menu_id;
	`;
	return assertSeededId( wpEvalPhp( phpCode ).trim(), `Menu (${ title })` );
}

function deleteMenu( id: string ): void {
	if ( id ) {
		wpCliArgs( [ 'post', 'delete', id, '--force' ], { stdio: 'ignore' } );
	}
}

/**
 * サービスメニュー編集画面を開き、メタボックス領域を可視化するヘルパー。
 * vkbm_service_menu はブロックエディタ（Gutenberg）を使うため、クラシックメタボックスは
 * 画面下部の「メタボックス」パネルに折りたたまれて出る（他 spec と同じ作法）。
 *
 * @param page   Playwright の Page
 * @param postId 開くサービスメニューの post ID
 */
async function gotoMenuEditor( page: Page, postId: string ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForLoadState( 'domcontentloaded' );

	// 「エディターへようこそ」モーダルが出たら閉じる。
	const welcomeClose = page.locator(
		'.components-modal__frame .components-modal__header button[aria-label="閉じる"], .components-modal__frame .components-modal__header button[aria-label="Close"]'
	);
	try {
		await welcomeClose.first().waitFor( { state: 'visible', timeout: 3000 } );
		await welcomeClose.first().click();
		await page.waitForTimeout( 200 );
	} catch {
		// モーダルが出なければ何もせず進む。
	}

	// 基本料金 input が DOM に存在するまで待つ（hidden でもよい＝メタボックス内）。
	await page
		.locator( BASE_PRICE_INPUT )
		.waitFor( { state: 'attached', timeout: 15000 } );

	// クラシックメタボックス領域（.edit-post-layout__metaboxes）を開く。
	await page.evaluate( () => {
		const buttons = Array.from(
			document.querySelectorAll< HTMLButtonElement >(
				'button[aria-expanded]'
			)
		);
		const toggle = buttons.find(
			( b ) => ( b.textContent || '' ).trim() === 'メタボックス'
		);
		if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'false' ) {
			toggle.click();
		}
	} );
	await page.waitForTimeout( 400 );

	await page.locator( BASE_PRICE_INPUT ).scrollIntoViewIfNeeded();
	await page.waitForTimeout( 300 );
	await expect( page.locator( BASE_PRICE_INPUT ) ).toBeVisible();
}

// 基本料金入力欄がグレー表示（readonly + クラス + 説明文表示）中であることを検証する。
async function expectOverridden( page: Page ) {
	const input = page.locator( BASE_PRICE_INPUT );
	await expect( input ).toHaveAttribute( 'readonly', '' );
	await expect( input ).toHaveClass( new RegExp( OVERRIDDEN_CLASS ) );
	await expect( input ).toHaveAttribute(
		'aria-describedby',
		'vkbm-base-price-overridden-description'
	);
	const description = page.locator( BASE_PRICE_DESCRIPTION );
	await expect( description ).toBeVisible();
	await expect( description ).toContainText( OVERRIDDEN_DESCRIPTION_TEXT );
}

// 基本料金入力欄が通常表示（readonly なし・クラスなし・説明文非表示）であることを検証する。
async function expectNotOverridden( page: Page ) {
	const input = page.locator( BASE_PRICE_INPUT );
	await expect( input ).not.toHaveAttribute( 'readonly', '' );
	await expect( input ).not.toHaveClass( new RegExp( OVERRIDDEN_CLASS ) );
	await expect( input ).not.toHaveAttribute( 'aria-describedby' );
	await expect( page.locator( BASE_PRICE_DESCRIPTION ) ).toBeHidden();
}

let originalStaffEnabled = true;

test.describe( 'PR #517: 基本料金の行の位置移動とグレー表示（予約枠の定員機能ON）', () => {
	let menuId = '';

	test.beforeAll( () => {
		// 指名OFF・予約枠の定員機能ONが前提。指名の有無はこの表示条件に含まれないため、
		// ノイズを減らすために他 spec と同じ前提（指名OFF）に揃える。
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		setSlotCapacityEnabled( true );
		menuId = seedMenu( 'PR517 Position Greyout Menu' );
	} );

	test.afterAll( () => {
		deleteMenu( menuId );
		menuId = '';
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '1. 並び順: 基本料金の行が「料金区分」の行の直前にあり、キャッチコピー直後にはない。二重描画もない', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page, menuId );

		// 前提: 料金区分の行が表示される構成（複数人一括予約ON・定員2）。
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();

		// 本丸1: 基本料金の行の直後の兄弟要素が「料金区分」の行である。
		const nextIdAfterBasePrice = await page
			.locator( BASE_PRICE_ROW )
			.evaluate( ( el ) => ( el.nextElementSibling as HTMLElement )?.id );
		expect( nextIdAfterBasePrice ).toBe( 'vkbm-price-tiers-field' );

		// 本丸2: キャッチコピーの行の直後は、もはや基本料金の行ではない。
		const catchCopyRow = page.locator(
			'#vkbm_service_menu_catch_copy'
		);
		const nextIdAfterCatchCopy = await catchCopyRow.evaluate( ( el ) => {
			const tr = el.closest( 'tr' );
			return ( tr?.nextElementSibling as HTMLElement )?.id ?? '';
		} );
		expect( nextIdAfterCatchCopy ).not.toBe( 'vkbm-base-price-field' );

		// #514再レビュー対応（二重描画検出漏れの修正）: 基本料金の入力欄・行はそれぞれ1つだけ。
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveCount( 1 );
		await expect( page.locator( BASE_PRICE_ROW ) ).toHaveCount( 1 );

		await page.locator( BASE_PRICE_ROW ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/after-position.png`,
			fullPage: true,
		} );
	} );

	test( '2. グレー表示の切り替え: 区分名の追加・空欄・削除、複数人一括予約、予約枠の定員の変更で即時切替', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page, menuId );

		// 初期状態（料金区分なし）: 通常表示。
		await expectNotOverridden( page );

		// 区分行を追加しただけ（区分名は空）ではグレー表示にならない。
		await page.locator( PRICE_TIER_ADD ).click();
		await page.waitForTimeout( 200 );
		await expectNotOverridden( page );

		// 区分名を入力するとグレー表示になる。
		const labelInput = page.locator( PRICE_TIER_LABEL ).first();
		await labelInput.fill( '大人' );
		await page.waitForTimeout( 200 );
		await expectOverridden( page );

		// スクリーンショット（グレー表示中）。
		await page.locator( BASE_PRICE_ROW ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/after-overridden.png`,
			fullPage: true,
		} );

		// 区分名を半角スペースだけにすると、trim 後は空扱いになりグレー表示が解除される。
		await labelInput.fill( '   ' );
		await page.waitForTimeout( 200 );
		await expectNotOverridden( page );

		// 区分名を再度入力し直すとグレー表示に戻る。
		await labelInput.fill( '大人' );
		await page.waitForTimeout( 200 );
		await expectOverridden( page );

		// 「複数人一括予約」のチェックを外す→料金区分の行が隠れ、基本料金は通常表示に戻る。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();
		await expectNotOverridden( page );

		// 再度チェックを入れる→料金区分の行が復帰し、区分名（大人）が残っているため
		// グレー表示にも戻る。
		await page.locator( ALLOW_MULTI ).check();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();
		await expectOverridden( page );

		// 予約枠の定員を1にする→複数人一括予約系設定が非表示になり、基本料金は通常表示に戻る。
		await page.locator( MAX_CAPACITY ).fill( '1' );
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();
		await expectNotOverridden( page );

		// 定員を2に戻すと、区分名が残っているためグレー表示に戻る。
		await page.locator( MAX_CAPACITY ).fill( '2' );
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();
		await expectOverridden( page );

		// 区分行を削除するとグレー表示が解除される。
		await page.locator( PRICE_TIER_REMOVE ).first().click();
		await page.waitForTimeout( 200 );
		await expectNotOverridden( page );
	} );

	test( '3. 保存しても基本料金が消えない: グレー表示のまま保存→再読込でも値が残り、区分を全削除して保存すると通常表示に戻る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page, menuId );

		// 区分名を入力してグレー表示にする。
		await page.locator( PRICE_TIER_ADD ).click();
		await page.locator( PRICE_TIER_LABEL ).first().fill( '大人' );
		await page
			.locator( '.vkbm-price-tier-price' )
			.first()
			.fill( '5000' );
		await page.waitForTimeout( 200 );
		await expectOverridden( page );
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveValue( '9000' );

		// 保存する（REST + クラシックメタボックス保存の両方の応答を待つ）。
		const saveResponse = page.waitForResponse(
			( res ) =>
				new RegExp( `/wp/v2/vkbm_service_menu/${ menuId }` ).test(
					res.url()
				) && res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		const metaBoxSaveResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'meta-box-loader=1' ) &&
				res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		await page.locator( '.editor-post-publish-button__button' ).click();
		await saveResponse;
		await metaBoxSaveResponse;

		// PHP側: 基本料金メタは削除されず、値はそのまま残っている（readonly を使っているため）。
		const savedBasePrice = wpEvalPhp( `
			echo get_post_meta( ${ menuId }, '_vkbm_base_price', true );
		` ).trim();
		expect( savedBasePrice ).toBe( '9000' );

		// 再読込しても、グレー表示のまま・値も 9000 のまま。
		await gotoMenuEditor( page, menuId );
		await expectOverridden( page );
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveValue( '9000' );

		// 区分の「削除」を押して区分をすべて削除し、保存する。
		const saveResponse2 = page.waitForResponse(
			( res ) =>
				new RegExp( `/wp/v2/vkbm_service_menu/${ menuId }` ).test(
					res.url()
				) && res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		const metaBoxSaveResponse2 = page.waitForResponse(
			( res ) =>
				res.url().includes( 'meta-box-loader=1' ) &&
				res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		await page.locator( PRICE_TIER_REMOVE ).first().click();
		await page.waitForTimeout( 200 );
		await expectNotOverridden( page );
		await page.locator( '.editor-post-publish-button__button' ).click();
		await saveResponse2;
		await metaBoxSaveResponse2;

		// PHP側: 料金区分メタは空になり、基本料金の値は 9000 のまま残っている。
		const savedTiersAfterDelete = wpEvalPhp( `
			$v = get_post_meta( ${ menuId }, '_vkbm_price_tiers', true );
			echo empty( $v ) ? 'empty' : 'not-empty';
		` ).trim();
		expect( savedTiersAfterDelete ).toBe( 'empty' );
		const basePriceAfterDelete = wpEvalPhp( `
			echo get_post_meta( ${ menuId }, '_vkbm_base_price', true );
		` ).trim();
		expect( basePriceAfterDelete ).toBe( '9000' );

		// 再読込しても通常表示に戻ったまま・値は 9000 のまま。
		await gotoMenuEditor( page, menuId );
		await expectNotOverridden( page );
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveValue( '9000' );

		await page.locator( BASE_PRICE_ROW ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/after-saved-not-overridden.png`,
			fullPage: true,
		} );
	} );
} );

test.describe( 'PR #517: デグレ確認（予約枠の定員機能OFFのサイトでも基本料金の行は表示される）', () => {
	let menuId = '';

	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		// 予約枠の定員機能を親スイッチごとOFFにする。この構成では「料金区分」の行自体が
		// 描画されない別の分岐になる（render_conditions_meta_box() の is_slot_capacity_enabled()
		// 分岐）。
		setSlotCapacityEnabled( false );
		menuId = seedSimpleMenu( 'PR517 Capacity Off Menu' );
	} );

	test.afterAll( () => {
		deleteMenu( menuId );
		menuId = '';
		// 他 spec が予約枠の定員機能ONを前提にしているため、既定値（ON）へ戻しておく。
		setSlotCapacityEnabled( true );
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '予約枠の定員機能OFFでも基本料金の行が表示され、通常どおり入力・保存できる（料金区分の行はDOMに存在しない）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page, menuId );

		// 本丸: 基本料金の行は表示され、通常表示（readonly ではない）。
		await expect( page.locator( BASE_PRICE_ROW ) ).toBeVisible();
		await expectNotOverridden( page );
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveValue( '4500' );

		// この構成では料金区分の行自体がDOMに存在しない（hidden ではなく非描画）。
		await expect( page.locator( PRICE_TIERS_ROW ) ).toHaveCount( 0 );

		// 二重描画がないことも確認する。
		await expect( page.locator( BASE_PRICE_INPUT ) ).toHaveCount( 1 );

		// 値を変更して保存できることを確認する（デグレ確認）。
		await page.locator( BASE_PRICE_INPUT ).fill( '6000' );
		const saveResponse = page.waitForResponse(
			( res ) =>
				new RegExp( `/wp/v2/vkbm_service_menu/${ menuId }` ).test(
					res.url()
				) && res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		const metaBoxSaveResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'meta-box-loader=1' ) &&
				res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		await page.locator( '.editor-post-publish-button__button' ).click();
		await saveResponse;
		await metaBoxSaveResponse;

		const savedBasePrice = wpEvalPhp( `
			echo get_post_meta( ${ menuId }, '_vkbm_base_price', true );
		` ).trim();
		expect( savedBasePrice ).toBe( '6000' );

		await page.locator( BASE_PRICE_ROW ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/after-capacity-off.png`,
			fullPage: true,
		} );
	} );
} );
