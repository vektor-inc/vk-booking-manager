/**
 * サービスメニューの提供期間: 同じメニューのお気に入りを押し直したときの表示月（#994）
 *
 * 検証内容:
 * - 提供期間の開始日が先の月にあるメニューをお気に入り（「いつもの」）から選ぶと、カレンダーが開始月で開く。
 * - 同じメニューのお気に入りをもう一度押しても、カレンダーは開始月のまま（今月に戻らない）。
 *
 * テストデータと後片付け:
 * - プランとユーザーは固有の接頭辞（E2E994 / user_e2e994_）を付けて作り、afterAll で削除する。
 * - 提供期間の開始日は「来月の 10 日」にする。
 */
import { test, expect, Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp, getStaffId } from '../utils/helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';
const PREFIX = 'E2E994';
const FAVORITE_LABEL = `${ PREFIX } いつもの`;
const password = 'TestPassword123!';
const username = `user_e2e994_${ Date.now() }`;

let menuId = '';
let userId = '';
let nextMonthYear = '';
let nextMonthNumber = 0;

/**
 * このspecで作ったプランとユーザーを削除する。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		foreach ( get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'numberposts' => -1,
			's'           => $prefix,
		) ) as $p ) {
			if ( 0 === strpos( $p->post_title, $prefix ) ) { wp_delete_post( $p->ID, true ); }
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user = get_user_by( 'login', '${ username }' );
		if ( $user ) { wp_delete_user( $user->ID ); }
		echo 'ok';
	` );
}

// @title Issue #994: 提供期間の開始日が先のメニューのお気に入りを押し直しても、カレンダーは開始月のまま
test.describe( 'Issue #994: service period start month on favorite re-select', () => {
	// ログイン状態は使わず、作成したユーザーで自分でログインする。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		// @step 来月 10 日から提供が始まるプラン「E2E994 Future Plan」と、そのプランをお気に入りに持つ一般ユーザーを作っておく
		const dates = wpEvalPhp( `
			$next = new DateTimeImmutable( 'first day of next month', wp_timezone() );
			echo $next->format( 'Y-m-' ) . '10,' . $next->format( 'Y' ) . ',' . $next->format( 'n' );
		` )
			.trim()
			.split( ',' );
		const startIso = dates[ 0 ];
		nextMonthYear = dates[ 1 ];
		nextMonthNumber = Number( dates[ 2 ] );

		const staffId = getStaffId();
		menuId = wpEvalPhp( `
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Future Plan',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( staffId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 5000 );
			update_post_meta( $id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $id, '_vkbm_service_period_start', '${ startIso }' );
			echo $id;
		` ).trim();
		expect( menuId ).toMatch( /^\d+$/ );

		userId = wpCliArgs( [
			'user',
			'create',
			username,
			`${ username }@example.com`,
			'--role=subscriber',
			`--user_pass=${ password }`,
			'--porcelain',
		] ).trim();
		const seeded = wpEvalPhp( `
			update_user_meta( ${ Number(
				userId
			) }, '_vkbm_favorites', wp_slash( array( array(
				'id'          => wp_generate_uuid4(),
				'menu_id'     => ${ Number( menuId ) },
				'resource_id' => 0,
				'label'       => '${ FAVORITE_LABEL }',
				'created_at'  => time(),
			) ) ) );
			echo 'ok';
		` );
		expect( seeded ).toContain( 'ok' );
	} );

	test.afterAll( () => {
		cleanupSeededData();
	} );

	/**
	 * カレンダーの表示月が来月（提供期間の開始月）になっていることを確かめる。
	 *
	 * @param page Playwright ページ。
	 */
	const expectCalendarAtStartMonth = async ( page: Page ) => {
		await expect( page.locator( '.vkbm-calendar__current' ) ).toContainText(
			new RegExp(
				`${ nextMonthYear }.*${ nextMonthNumber }|${ nextMonthNumber }.*${ nextMonthYear }`
			),
			{ timeout: 15000 }
		);
	};

	// @title 同じメニューのお気に入りを押し直しても開始月のまま
	test( '提供期間の開始日が来月のメニューのお気に入りを押すとカレンダーが来月で開き、もう一度押しても来月のまま', async ( {
		page,
	} ) => {
		// @step 作っておいた一般ユーザーでログインし、予約ページを開く
		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
		await page.goto( `${ WP_BASE_URL }/booking/` );

		// @step 「いつもの」の「E2E994 いつもの」を押す
		const favoriteButton = page.locator( '.vkbm-favorites__apply', {
			hasText: FAVORITE_LABEL,
		} );
		await expect( favoriteButton ).toBeVisible( { timeout: 15000 } );
		await favoriteButton.click();
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );

		// @check カレンダーが提供期間の開始月（来月）で開いている
		await expectCalendarAtStartMonth( page );

		// @step 同じ「E2E994 いつもの」をもう一度押す
		await expect( favoriteButton ).toBeVisible();
		await favoriteButton.click();
		// 押した直後の再描画（月の巻き戻り）を取りこぼさないよう、空き状況の読み込みが終わるまで待つ。
		await page
			.locator( '.vkbm-calendar__spinner' )
			.waitFor( { state: 'detached', timeout: 15000 } )
			.catch( () => undefined );

		// @check カレンダーが今月に戻らず、提供期間の開始月（来月）のまま表示される
		await expectCalendarAtStartMonth( page );
	} );
} );
