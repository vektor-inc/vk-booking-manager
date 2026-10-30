/**
 * issue #975: カード表示の料金区分の余白が、最終行だけ広く見える不具合の検証。
 *
 * 原因: 料金区分の各行（区分名 dt・料金 dd）は共通 CSS の text-box: trim-both text で
 * 上下の行間（ハーフレディング）を削っているが、最終行だけ trim-end text で上書きしていたため、
 * 最終行の上側の削りが外れ、最後の料金の上だけ間隔が広く見えていた。
 *
 * 検証内容:
 * - 料金区分が 3 行あるサービスをカード表示にし、各行の料金（dd）・区分名（dt）の高さが
 *   最終行も含めて揃っていること。
 * 行の箱どうしの距離は flex の gap のままで修正前後で変わらないため、検出には箱自体の高さの比較を使う。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - 料金区分は「指名OFF＋複数人予約ON」でのみ有効なため、この describe の間だけ指名を OFF にし、
 *   終了時に元へ戻す。
 */
import { test, expect } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	getStaffEnabled,
	setStaffEnabled,
} from '../utils/helpers';

const MENU_TITLE = 'Issue975 Tier Menu';
const PAGE_SLUG = 'issue-975-card-tiers';

// 高さの比較で許容する誤差（px）。サブピクセル丸めの揺れだけを吸収する。
const TOLERANCE_PX = 0.5;

let originalStaffEnabled = true;
let menuId = '';
let pageId = '';

/**
 * 料金区分が 3 行あるサービスメニューと、そのカードを表示する固定ページを作る。
 * 同名のデータがあれば作り直す（冪等）。
 *
 * @return { menuId, pageId } 作成した投稿ID（数値文字列）
 */
function seedFixtures(): { menuId: string; pageId: string } {
	const staffId = Number.parseInt( getStaffId(), 10 );
	const phpCode = `
		// 既存の検証用データを掃除する。
		$old_menus = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $old_menus as $oid ) {
			wp_delete_post( (int) $oid, true );
		}
		$old_pages = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'name'        => '${ PAGE_SLUG }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $old_pages as $oid ) {
			wp_delete_post( (int) $oid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ MENU_TITLE }',
		) );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( ${ staffId } ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		update_post_meta( $menu_id, '_vkbm_price_tiers', array(
			array( 'label' => '一般', 'price' => 4000 ),
			array( 'label' => '子供', 'price' => 3000 ),
			array( 'label' => '幼児', 'price' => 1000 ),
		) );

		// サービスカードブロックで、作成したサービスを指定して表示する。
		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => '${ PAGE_SLUG }',
			'post_title'   => 'Issue975 Card Tiers',
			'post_content' => '<!-- wp:vk-booking-manager/menu-card {"selectedMenuId":' . (int) $menu_id . '} /-->',
		) );
		echo (int) $menu_id . ',' . (int) $page_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	const match = result.match( /^(\d+),(\d+)$/ );
	if ( ! match || Number( match[ 1 ] ) <= 0 || Number( match[ 2 ] ) <= 0 ) {
		throw new Error( `Fixture seeding failed: "${ result }"` );
	}
	return { menuId: match[ 1 ], pageId: match[ 2 ] };
}

test.describe( 'issue #975: カード表示の料金区分の余白', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		// @step 指名を使わない設定にして、料金区分が 3 行あるサービスとカード表示のページを用意する
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		( { menuId, pageId } = seedFixtures() );
	} );

	test.afterAll( () => {
		for ( const id of [ menuId, pageId ] ) {
			if ( id ) {
				wpCliArgs( [ 'post', 'delete', id, '--force' ], {
					stdio: 'ignore',
				} );
			}
		}
		menuId = '';
		pageId = '';
		setStaffEnabled( originalStaffEnabled );
	} );

	// @title 料金区分の各行の余白が最終行も含めて揃っている
	test( '3 行の料金区分で、区分名・料金の高さが最終行も同じ', async ( {
		page,
	} ) => {
		// @step 料金区分が 3 行あるサービスのカードを表示するページを開く
		await page.goto( `/?page_id=${ pageId }` );
		const rows = page.locator( '.vkbm-menu-loop__card-prices-row' );
		await expect( rows ).toHaveCount( 3 );

		// 各行の区分名（dt）・料金（dd）の描画後の高さを取得する。
		const boxes = await rows.evaluateAll( ( els ) =>
			els.map( ( row ) => {
				const dt = row
					.querySelector( 'dt' )!
					.getBoundingClientRect();
				const dd = row
					.querySelector( 'dd' )!
					.getBoundingClientRect();
				return {
					dtHeight: dt.height,
					ddHeight: dd.height,
				};
			} )
		);

		// @check 3 行すべての料金の高さが同じで、最終行だけ高くなっていない
		for ( const box of boxes ) {
			expect(
				Math.abs( box.ddHeight - boxes[ 0 ].ddHeight )
			).toBeLessThanOrEqual( TOLERANCE_PX );
		}

		// @check 3 行すべての区分名の高さが同じで、最終行だけ高くなっていない
		for ( const box of boxes ) {
			expect(
				Math.abs( box.dtHeight - boxes[ 0 ].dtHeight )
			).toBeLessThanOrEqual( TOLERANCE_PX );
		}
	} );
} );
