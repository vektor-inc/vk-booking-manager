/**
 * issue #968: 「貸し切り予約」ONのメニューの最少人数欄を、受付制限（最低申し込み人数）の表記にする。
 *
 * 検証内容:
 * 1. 貸し切り予約がOFFの指名なしメニューでは、ラベルが「最少催行人数」のまま。
 * 2. 「貸し切り予約」のチェックを入れると、保存せずに即座にラベルが「最低申し込み人数」へ、
 *    説明文の1行目が貸し切り用の文言へ切り替わる（JS 連動）。予約枠の定員欄の説明文は変わらない。
 * 3. チェックを外すと元の表記へ戻る。
 * 4. 保存済みで貸し切り予約がONのメニューを開くと、JS を待たずに初期表示（PHP）から
 *    「最低申し込み人数」になっている。
 *
 * 実行環境: playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 * 「貸し切り予約」は指名OFF＋複数人予約ON でのみ意味を持つため、この describe の間だけ指名OFFへ切り替え、
 * afterAll で復元する。
 */
import { test, expect } from '@playwright/test';
import {
	loginAsAdmin,
	wpEvalPhp,
	getStaffEnabled,
	setStaffEnabled,
} from '../utils/helpers';

const MENU_TITLE = 'Issue 968 Exclusive Min Guests Menu';
const LABEL_DEFAULT = '最少催行人数';
const LABEL_LIMIT = '最低申し込み人数';
const INTRO_EXCLUSIVE =
	'貸し切り予約がオンのため、1件の予約でこの時間枠が埋まります。';

let originalStaffEnabled = true;
let menuId = '';

/**
 * 検証用メニュー（複数人予約ON・最大受付数3・最少人数2）を作る。同名があれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedMenu(): string {
	const phpCode = `
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ MENU_TITLE }',
		) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Menu seeding failed: "${ result }"` );
	}
	return result;
}

test.describe( '#968: 貸し切り予約ONの最少人数欄の表記', () => {
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		menuId = seedMenu();
	} );

	test.afterAll( () => {
		if ( menuId ) {
			try {
				wpEvalPhp(
					`wp_delete_post( ${ Number.parseInt( menuId, 10 ) }, true );`
				);
			} catch ( e ) {
				console.warn( 'Failed to delete menu:', e );
			}
		}
		try {
			setStaffEnabled( originalStaffEnabled );
		} catch ( e ) {
			console.warn( 'Failed to restore staff_enabled:', e );
		}
	} );

	test( '貸し切り予約のチェック切り替えに最少人数欄のラベル・説明文が連動する', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		await page.waitForLoadState( 'domcontentloaded' );

		const label = page.locator( '#vkbm-min-capacity-label' );
		const description = page.locator( '#vkbm-min-capacity-description' );
		const maxDescription = page.locator( '#vkbm-max-capacity-description' );
		const exclusive = page.locator(
			'#vkbm_service_menu_exclusive_when_booked'
		);
		await exclusive.waitFor( { state: 'attached', timeout: 15000 } );

		// 貸し切り予約OFF: 従来の表記。
		await expect( label ).toHaveText( LABEL_DEFAULT );
		await expect( description ).not.toContainText( INTRO_EXCLUSIVE );
		const maxBefore = ( await maxDescription.textContent() ) ?? '';

		// DOM でチェックし change を発火する（メタボックス内の要素は可視判定が不安定なため）。
		await exclusive.evaluate( ( el ) => {
			const input = el as HTMLInputElement;
			input.checked = true;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await expect( label ).toHaveText( LABEL_LIMIT );
		await expect( description ).toContainText( INTRO_EXCLUSIVE );
		// 予約枠の定員欄の説明文は「1組の最大人数」に切り替えない。
		await expect( maxDescription ).toHaveText( maxBefore );

		// チェックを外すと元の表記へ戻る。
		await exclusive.evaluate( ( el ) => {
			const input = el as HTMLInputElement;
			input.checked = false;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await expect( label ).toHaveText( LABEL_DEFAULT );
		await expect( description ).not.toContainText( INTRO_EXCLUSIVE );
	} );

	test( '保存済みで貸し切り予約がONのメニューは初期表示から最低申し込み人数になっている', async ( {
		page,
	} ) => {
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				menuId,
				10
			) }, '_vkbm_exclusive_when_booked', true );`
		);
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( '#vkbm-min-capacity-label' ) ).toHaveText(
			LABEL_LIMIT
		);
		await expect(
			page.locator( '#vkbm-min-capacity-description' )
		).toContainText( INTRO_EXCLUSIVE );
	} );
} );
