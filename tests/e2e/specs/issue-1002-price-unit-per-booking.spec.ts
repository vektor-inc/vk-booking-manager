/**
 * issue #1002: アクティビティ・ツアーのメニューで、人数にかかわらず「1ツアーあたり」の定額料金にできる。
 *
 * 検証内容（業種プリセットが「アクティビティ・ツアー」のサイト・Pro 版のみ）:
 *   1. 編集画面: 基本料金の直前に「料金の単位」（1名あたり／1ツアーあたり）が出て、基本料金の後ろに単位「/ 1名」が出る。
 *      「1ツアーあたり」を選ぶと、単位が「/ 1ツアー」に変わり、複数人一括予約・貸し切り予約・料金区分の行が隠れ、
 *      「予約枠の定員」が「1ツアーの最大人数」に変わる。保存すると料金区分が削除される。
 *   2. 予約画面: 人数を 4 名にしても料金は変わらず、人数欄の下に補足が出る。確認画面は「× ◯名」を出さず、合計は基本料金のまま。
 *   3. 予約を確定すると、管理画面の予約編集画面の合計が基本料金のままで、人数を変えても変わらない。
 *
 * 注: 文言は日本語で確かめる（e2e のサイトは日本語）。
 * テストデータは接頭辞（E2E1002）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	loginAsAdmin,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

const PREFIX = 'E2E1002';
const OPTION_NAME = 'vkbm_provider_settings';
const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// 1ツアーあたり 30,000円、1ツアーの最大人数 6 名。料金区分（大人・子供）は削除されることを確かめるために入れておく。
const BASE_PRICE = 30000;
const MAX_GUESTS = 6;
const GUESTS = 4;

const password = 'TestPassword123!';
const username = `user_price_unit_${ Date.now() }`;

let originalSettingsJson: string | null = null;
let staffId = '';
let menuId = '';
let userId = 0;

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP（最後に ID を echo する）
 * @param label エラーメッセージ用の名前
 * @return post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * このスペックが作ったデータを消す（予約 → シフト → メニュー → ガイド → 会員の順）。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$staff = ${ Number( staffId ) || 0 };
		$menu = ${ Number( menuId ) || 0 };
		$user = ${ Number( userId ) || 0 };
		if ( $menu ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_vkbm_booking_service_id', 'value' => $menu ) ),
			) ) as $bid ) { wp_delete_post( $bid, true ); }
		}
		if ( $staff ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_vkbm_shift_resource_id',
				'meta_value'  => $staff,
			) ) as $sid ) { wp_delete_post( $sid, true ); }
		}
		if ( $menu ) { wp_delete_post( $menu, true ); }
		if ( $staff ) { wp_delete_post( $staff, true ); }
		if ( $user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		}
		echo 'ok';
	` );
}

/**
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ option ごと消す。
 */
function restoreProviderSettings(): void {
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
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
	} catch {
		// 元から無いなら何もしない。
	}
}

/**
 * ブロックエディターの「ようこそ」案内を出さず、メタボックス欄を開いた状態にする。
 */
function prepareBlockEditorPreferences(): void {
	const result = wpEvalPhp( `
		global $wpdb;
		$user = get_user_by( 'login', 'admin' );
		update_user_meta(
			$user->ID,
			$wpdb->get_blog_prefix() . 'persisted_preferences',
			array(
				'core/edit-post' => array(
					'welcomeGuide'        => false,
					'metaBoxesMainIsOpen' => true,
				),
				'core'           => array(
					'enableChoosePatternModal' => false,
				),
			)
		);
		echo 'OK';
	` );
	expect( result ).toContain( 'OK' );
}

/**
 * メニューの保存済みメタを wp eval で JSON 取得する。
 *
 * @param key メタキー
 * @return メタの値（無ければ null）
 */
function getMenuMeta( key: string ): unknown {
	return JSON.parse(
		wpEvalPhp( `
			$exists = metadata_exists( 'post', ${ Number( menuId ) }, '${ key }' );
			echo wp_json_encode( $exists ? get_post_meta( ${ Number(
				menuId
			) }, '${ key }', true ) : null );
		` ).trim()
	);
}

/**
 * このメニューの最新の予約を読む。
 *
 * @return 予約の ID・人数・基本料金合計・予約時点の料金の単位。予約が無ければ null
 */
function readLatestBooking(): {
	id: number;
	guests: number;
	total: number;
	unit: string;
} | null {
	const raw = wpEvalPhp( `
		$ids = get_posts( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array( array( 'key' => '_vkbm_booking_service_id', 'value' => ${ Number(
				menuId
			) } ) ),
		) );
		if ( ! $ids ) { echo ''; return; }
		echo wp_json_encode( array(
			'id'     => $ids[0],
			'guests' => (int) get_post_meta( $ids[0], '_vkbm_booking_guests', true ),
			'total'  => (int) get_post_meta( $ids[0], '_vkbm_booking_base_total_price', true ),
			'unit'   => (string) get_post_meta( $ids[0], '_vkbm_booking_price_unit', true ),
		) );
	` ).trim();
	return '' === raw ? null : JSON.parse( raw );
}

/**
 * 会員としてログインする。
 *
 * @param page Playwright の Page
 */
async function loginAsMember( page: Page ) {
	await page.goto( `${ WP_BASE_URL }/wp-login.php` );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForLoadState( 'networkidle' );
}

test.describe
	.serial( 'issue #1002: 料金の単位（1名あたり／1ツアーあたり）', () => {
	// 各テストで必要に応じて自分でログインするため、保存済みのログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// ガイド 1 名（指名は使わない）と、今月・来月のシフトを用意する。
		staffId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Guide',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			echo $id;
		`,
			'guide'
		);
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( staffId, year, month );
		}

		// 1名あたり（保存なし）のメニュー。大人・子供の料金区分と、複数人一括予約を設定済み。
		menuId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Tour',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( staffId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', ${ BASE_PRICE } );
			update_post_meta( $id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $id, '_vkbm_max_capacity', ${ MAX_GUESTS } );
			update_post_meta( $id, '_vkbm_allow_multiple_guests', 1 );
			update_post_meta( $id, '_vkbm_price_tiers', array(
				array( 'label' => '大人', 'price' => 5000 ),
				array( 'label' => '子供', 'price' => 3000 ),
			) );
			echo $id;
		`,
			'tour menu'
		);

		// アクティビティ・ツアーのサイトにする（指名は使わない）。
		await configureProviderSettings( {
			industry_preset: 'activity',
			staff_enabled: false,
			slot_capacity_enabled: true,
			provider_reservation_deadline_hours: 0,
			provider_max_advance_booking_days: 0,
			provider_customer_booking_limit: 0,
		} );

		userId = Number(
			wpCliArgs( [
				'user',
				'create',
				username,
				`${ username }@example.com`,
				'--role=subscriber',
				`--user_pass=${ password }`,
				'--porcelain',
			] )
		);
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	// @title 編集画面で料金の単位を「1ツアーあたり」にして保存する
	test( 'admin: choose the price unit per tour', async ( { page } ) => {
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		// @step サービスメニューの編集画面を開く（料金の単位は「1名あたり」のまま）
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );

		const unitRow = page.locator( '#vkbm-price-unit-field' );
		await unitRow.waitFor( { state: 'attached', timeout: 15000 } );
		const unitSuffix = page.locator( '#vkbm-base-price-slot-unit' );
		const tiersRow = page.locator( '#vkbm-price-tiers-field' );
		const allowRow = page.locator( '#vkbm-allow-multiple-guests-field' );
		const exclusiveRow = page.locator(
			'#vkbm-exclusive-when-booked-field'
		);
		const maxCapacityLabel = page.locator( '#vkbm-max-capacity-label' );

		// @check 基本料金の直前に「料金の単位」の行があり、「1名あたり」が選ばれていて、基本料金の後ろに「/ 1名」が出ている
		await expect( unitRow ).toBeVisible();
		await expect( unitRow ).toContainText( '料金の単位' );
		await expect(
			unitRow.locator( 'input[value="per_guest"]' )
		).toBeChecked();
		await expect( unitSuffix ).toHaveText( '/ 1名' );
		const precedesBasePrice = await page.evaluate(
			() =>
				document.querySelector( '#vkbm-base-price-field' )
					?.previousElementSibling?.id === 'vkbm-price-unit-field'
		);
		expect( precedesBasePrice ).toBe( true );
		// @check 1名あたりのときは、複数人一括予約・料金区分の行が出ていて、「予約枠の定員」のままになっている
		await expect( allowRow ).toBeVisible();
		await expect( tiersRow ).toBeVisible();
		await expect( maxCapacityLabel ).toHaveText( '予約枠の定員' );

		// @step 料金の単位を「1ツアーあたり」にする
		await unitRow.locator( 'input[value="per_booking"]' ).check();

		// @check 単位が「/ 1ツアー」に変わり、複数人一括予約・貸し切り予約・予約者による貸切指定・料金区分の行が隠れる
		await expect( unitSuffix ).toHaveText( '/ 1ツアー' );
		await expect( allowRow ).toBeHidden();
		await expect( exclusiveRow ).toBeHidden();
		await expect(
			page.locator( '#vkbm-exclusive-user-selectable-field' )
		).toBeHidden();
		await expect( tiersRow ).toBeHidden();
		// @check 「予約枠の定員」が「1ツアーの最大人数」に変わり、説明文に「人数を入力しても料金は変わりません。」が出る
		await expect( maxCapacityLabel ).toHaveText( '1ツアーの最大人数' );
		await expect(
			page.locator( '#vkbm-max-capacity-description' )
		).toContainText( '人数を入力しても料金は変わりません。' );
		// @check 最少人数の欄は残り、貸し切りが有効になるため「最低申し込み人数」の表記になる
		await expect(
			page.locator( '#vkbm-min-capacity-field' )
		).toBeVisible();
		await expect( page.locator( '#vkbm-min-capacity-label' ) ).toHaveText(
			'最低申し込み人数'
		);

		// @step 保存する
		await page.locator( '.editor-post-publish-button' ).click();

		// @check 料金の単位が保存され、料金区分は削除されている（貸し切り予約の保存値は書き換わらない）
		await expect
			.poll( () => getMenuMeta( '_vkbm_price_unit' ), { timeout: 20000 } )
			.toBe( 'per_booking' );
		expect( getMenuMeta( '_vkbm_price_tiers' ) ).toBeNull();
		expect( getMenuMeta( '_vkbm_exclusive_when_booked' ) ).toBeNull();

		// @check 保存後、料金区分を削除したことが画面上部の通知に出る
		await expect(
			page.locator( '.components-notice' ).filter( {
				hasText: '料金区分を削除しました',
			} )
		).toBeVisible( { timeout: 20000 } );
	} );

	// @title 予約画面で人数を変えても料金が変わらず、確認画面・管理画面の合計が基本料金のままになる
	test( 'front: the price does not change with the number of guests', async ( {
		page,
	} ) => {
		// @step 会員としてログインし、メニューの予約ページを開いて、空きのある日と最初の時間枠を選ぶ
		await loginAsMember( page );
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await page.goto( `${ WP_BASE_URL }/booking/?menu_id=${ menuId }` );
		await page.waitForLoadState( 'networkidle' );
		await selectAvailableCalendarDay( page, calendarMetaResponse );
		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		await slots.first().click();

		// @check 人数欄の下に「料金は 1ツアーあたり ¥30,000 です。人数を入力しても料金は変わりません。」が出ていて、料金区分と貸切指定の欄は出ない
		const note = page.locator( '#vkbm-reservation-per-booking-note' );
		await expect( note ).toBeVisible( { timeout: 10000 } );
		await expect( note ).toHaveText(
			'料金は 1ツアーあたり ¥30,000 です。人数を入力しても料金は変わりません。'
		);
		await expect( page.locator( '#vkbm-reservation-tier-0' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( '.vkbm-plan-summary__exclusive' )
		).toHaveCount( 0 );

		// @step 人数を 4 名にする
		const guests = page.locator( '#vkbm-reservation-guests' );
		await guests.fill( String( GUESTS ) );
		// @check 人数欄が補足文に関連付けられていて、補足の金額は変わらない
		await expect( guests ).toHaveAttribute(
			'aria-describedby',
			/vkbm-reservation-per-booking-note/
		);
		await expect( note ).toContainText( '¥30,000' );

		// @step 「予約に進む」ボタンを押して確認画面へ進む
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page
			.locator( '.vkbm-confirm__summary' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

		// @check 確認画面の基本料金の行が「ツアー料金（1ツアーあたり）」で、金額は ¥30,000 のまま、「× ◯名」の表記は出ない
		const summary = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( summary ).toContainText( 'ツアー料金（1ツアーあたり）' );
		await expect( summary ).toContainText( '¥30,000' );
		await expect( summary ).not.toContainText( '×' );

		// @step 同意欄にチェックを入れて予約を確定する
		await checkConfirmConsents( page );
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();

		// @check 予約が保存され、人数は 4 名・基本料金合計は ¥30,000・予約時点の料金の単位は 1ツアーあたりになっている
		await expect
			.poll( () => readLatestBooking() !== null, { timeout: 20000 } )
			.toBe( true );
		const booking = readLatestBooking() as {
			id: number;
			guests: number;
			total: number;
			unit: string;
		};
		expect( booking.guests ).toBe( GUESTS );
		expect( booking.total ).toBe( BASE_PRICE );
		expect( booking.unit ).toBe( 'per_booking' );

		// @step 管理者でログインして、その予約の編集画面を開く
		prepareBlockEditorPreferences();
		await page.context().clearCookies();
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ booking.id }&action=edit`
		);
		// 見出しが完全一致する行だけを選ぶ（「請求金額合計」の説明文にも「基本料金合計」の語が出るため）。
		const rowByHeading = ( heading: string ) =>
			page.locator( '.vkbm-booking-meta tr' ).filter( {
				has: page.locator( 'th', {
					hasText: new RegExp( `^\\s*${ heading }\\s*$` ),
				} ),
			} );
		const totalRow = rowByHeading( '基本料金合計' );
		await totalRow.first().waitFor( { state: 'attached', timeout: 15000 } );

		// @check 基本料金合計は ¥30,000 で、サービス基本料金の隣に「/ 1ツアー」が付いている
		await expect(
			totalRow.first().locator( '.vkbm-booking-meta__value' )
		).toHaveText( '¥30,000' );
		await expect(
			rowByHeading( 'サービス基本料金' ).first()
		).toContainText( '¥30,000 / 1ツアー' );
	} );
} );
