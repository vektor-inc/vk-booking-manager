/**
 * issue #1003: アクティビティ・ツアーの「1ツアーあたり」の料金で、規定人数を超えた人数分だけ追加料金を取れる。
 *
 * 検証内容（業種プリセットが「アクティビティ・ツアー」のサイト・Pro 版のみ）:
 *   1. 編集画面: 「1ツアーあたり」のときだけ追加料金のチェックが出て、オンにすると「基本料金に含まれる人数」の欄と
 *      料金区分の行が出る。区分（大人 ¥3,000・子供 ¥1,500）と含まれる人数 4 を入力して保存すると保存される。
 *   2. 予約画面: 区分ごとの人数欄になり、区分の金額に「+」が付く（狭い幅でも「+」だけが次の行に落ちない）。
 *      大人 4 名＋子供 1 名にすると、ツアー料金と追加料金の内訳（¥30,000 ＋ ¥1,500）と合計 ¥31,500 が出る。
 *      確認画面はツアー料金と追加料金の行を分けて出す。
 *   3. 予約を確定すると、人数 5・合計 ¥31,500・超過の内訳が保存され、管理画面の予約詳細でツアー料金と追加料金が分かれて見える。
 *
 * 注: 文言は日本語で確かめる（e2e のサイトは日本語）。
 * テストデータは接頭辞（E2E1003）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
// @category 料金表示
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	loginAsAdmin,
	setAdminBlockEditorPreferences,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

const PREFIX = 'E2E1003';
const OPTION_NAME = 'vkbm_provider_settings';
const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// 1ツアーあたり 30,000円、1ツアーの最大人数 8 名、4 名まで含む。超過 1 名あたり 大人 ¥3,000・子供 ¥1,500。
const BASE_PRICE = 30000;
const MAX_GUESTS = 8;
const INCLUDED_GUESTS = 4;
const ADULTS = 4;
const CHILDREN = 1;
const EXTRA_FEE = 1500;
const TOTAL = BASE_PRICE + EXTRA_FEE;

const password = 'TestPassword123!';
const username = `user_extra_guest_${ Date.now() }`;

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
 * @return 予約の ID・人数・基本料金合計・予約時点の料金の単位・超過の内訳。予約が無ければ null
 */
function readLatestBooking(): {
	id: number;
	guests: number;
	total: number;
	unit: string;
	extra: {
		included_guests: number;
		tiers: { label: string; price: number; count: number }[];
	} | null;
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
			'extra'  => metadata_exists( 'post', $ids[0], '_vkbm_booking_extra_guests' ) ? get_post_meta( $ids[0], '_vkbm_booking_extra_guests', true ) : null,
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
	.serial( 'issue #1003: 1ツアーあたりの規定人数を超えた分の追加料金', () => {
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

		// 「1ツアーあたり」で追加料金はまだ使っていないメニュー（料金区分も無い）。
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
			update_post_meta( $id, '_vkbm_price_unit', 'per_booking' );
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

	// @title 編集画面で「1ツアーあたり」の追加料金を設定して保存する
	test( 'admin: set the extra guest fee of the per-tour price', async ( {
		page,
	} ) => {
		setAdminBlockEditorPreferences();
		await loginAsAdmin( page );
		// @step サービスメニューの編集画面を開く（料金の単位は「1ツアーあたり」・追加料金はまだ使っていない）
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );

		const unitRow = page.locator( '#vkbm-price-unit-field' );
		await unitRow.waitFor( { state: 'attached', timeout: 15000 } );
		const extraFields = page.locator( '#vkbm-extra-guest-fields' );
		const extraCheck = page.locator(
			'#vkbm_service_menu_extra_guest_enabled'
		);
		const includedField = page.locator( '#vkbm-included-guests-field' );
		const includedInput = page.locator(
			'#vkbm_service_menu_included_guests'
		);
		const tiersRow = page.locator( '#vkbm-price-tiers-field' );
		const basePrice = page.locator( '#vkbm_service_menu_base_price' );

		// @check 「1ツアーあたり」のとき、追加料金のチェックが出ていて、オフのうちは「基本料金に含まれる人数」と料金区分の行は隠れている
		await expect( extraFields ).toBeVisible();
		await expect( extraFields ).toContainText(
			'規定人数を超える場合は人数に応じて追加料金あり'
		);
		await expect( extraCheck ).not.toBeChecked();
		await expect( includedField ).toBeHidden();
		await expect( tiersRow ).toBeHidden();

		// @step 「1名あたり」に切り替える
		await unitRow.locator( 'input[value="per_guest"]' ).check();
		// @check 1名あたりのときは、追加料金の欄は出ない
		await expect( extraFields ).toBeHidden();

		// @step 「1ツアーあたり」に戻して、追加料金のチェックを入れる
		await unitRow.locator( 'input[value="per_booking"]' ).check();
		await extraCheck.check();

		// @check 「基本料金に含まれる人数」の欄と料金区分の行が出て、基本料金は区分で上書きされない
		await expect( includedField ).toBeVisible();
		await expect( includedField ).toContainText( '基本料金に含まれる人数' );
		await expect( tiersRow ).toBeVisible();
		await expect( basePrice ).not.toHaveAttribute( 'readonly', '' );
		// @check 料金区分の説明文が追加料金用に切り替わり、「1ツアーの最大人数」の説明に「含まれる人数までは料金が変わらない」が出る
		await expect( tiersRow ).toContainText(
			'基本料金に含まれる人数を超えた1名ごとに加算する金額を入力します。'
		);
		await expect(
			page.locator( '#vkbm-max-capacity-description' )
		).toContainText(
			'基本料金に含まれる人数までは、人数を入力しても料金は変わりません。'
		);

		// @step 基本料金に含まれる人数を 4 にして、料金区分（大人 ¥3,000・子供 ¥1,500）を追加する
		await includedInput.fill( String( INCLUDED_GUESTS ) );
		await page.locator( '#vkbm-price-tier-add' ).click();
		await page.locator( '#vkbm-price-tier-add' ).click();
		const labels = page.locator( '.vkbm-price-tier-label' );
		const prices = page.locator( '.vkbm-price-tier-price' );
		await labels.nth( 0 ).fill( '大人' );
		await prices.nth( 0 ).fill( '3000' );
		await labels.nth( 1 ).fill( '子供' );
		await prices.nth( 1 ).fill( '1500' );

		// @step 保存する
		await page.locator( '.editor-post-publish-button' ).click();

		// @check 追加料金のフラグ・含まれる人数・料金区分（超過1名あたりの追加料金）が保存されている
		await expect
			.poll( () => getMenuMeta( '_vkbm_extra_guest_enabled' ), {
				timeout: 20000,
			} )
			.toBe( '1' );
		expect( Number( getMenuMeta( '_vkbm_included_guests' ) ) ).toBe(
			INCLUDED_GUESTS
		);
		expect( getMenuMeta( '_vkbm_price_tiers' ) ).toEqual( [
			{ label: '大人', price: 3000 },
			{ label: '子供', price: 1500 },
		] );
		expect( getMenuMeta( '_vkbm_price_unit' ) ).toBe( 'per_booking' );
	} );

	// @title 予約画面・確認画面・管理画面で、ツアー料金と追加料金が分かれて見える
	test( 'front: the extra guest fee is added for guests over the included number', async ( {
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

		// @check 人数を区分ごとの人数欄で受け取り、区分の金額に「+」が付いている（読み上げ用の「追加料金 ¥3,000」も添えてある）
		const adultInput = page.locator( '#vkbm-reservation-tier-0' );
		const childInput = page.locator( '#vkbm-reservation-tier-1' );
		await adultInput.waitFor( { state: 'visible', timeout: 10000 } );
		await expect( page.locator( '#vkbm-reservation-guests' ) ).toHaveCount(
			0
		);
		const adultPrice = page
			.locator( '.vkbm-plan-summary__guest-tier-price' )
			.nth( 0 );
		await expect( adultPrice.locator( '[aria-hidden="true"]' ) ).toHaveText(
			'+¥3,000'
		);
		await expect( adultPrice ).toContainText( '追加料金 ¥3,000' );
		// @check 人数欄の下の補足文が「4名までの参加を含みます」の文言になり、人数欄に関連付けられている
		const note = page.locator( '#vkbm-reservation-per-booking-note' );
		await expect( note ).toHaveText(
			'料金は 1ツアーあたり ¥30,000 で、4名までの参加を含みます。これを超える人数には、区分ごとに表示した追加料金が、超えた1名あたりで加算されます。'
		);
		await expect( adultInput ).toHaveAttribute(
			'aria-describedby',
			/vkbm-reservation-per-booking-note/
		);

		// @step 大人 4 名＋子供 1 名にする
		await adultInput.fill( String( ADULTS ) );
		await childInput.fill( String( CHILDREN ) );

		// @check 合計の上に「ツアー料金 ¥30,000 ＋ 追加料金 ¥1,500」が 1 行出て、合計は ¥31,500
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tiers-breakdown' )
		).toHaveText( 'ツアー料金 ¥30,000 ＋ 追加料金 ¥1,500' );
		await expect(
			page.locator( '.vkbm-plan-summary__guest-tiers-total' )
		).toContainText( '¥31,500' );

		// @step 画面幅を狭くする
		await page.setViewportSize( { width: 320, height: 800 } );
		// @check 「+¥3,000」の記号だけが次の行に落ちず、1 行のまま表示される
		const wrappedLines = await adultPrice
			.locator( '[aria-hidden="true"]' )
			.evaluate( ( el ) => el.getClientRects().length );
		expect( wrappedLines ).toBe( 1 );
		// @check 合計の上の「ツアー料金 ¥30,000」「＋ 追加料金 ¥1,500」も、それぞれ 1 行のまま（折り返しは 2 つの塊の境目だけ）
		const breakdownParts = page.locator(
			'.vkbm-plan-summary__guest-tiers-breakdown .vkbm-plan-summary__nowrap'
		);
		await expect( breakdownParts ).toHaveCount( 2 );
		for ( let i = 0; i < 2; i++ ) {
			const lines = await breakdownParts
				.nth( i )
				.evaluate( ( el ) => el.getClientRects().length );
			expect( lines ).toBe( 1 );
		}
		await page.setViewportSize( { width: 1280, height: 800 } );

		// @step 「予約に進む」ボタンを押して確認画面へ進む
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page
			.locator( '.vkbm-confirm__summary' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

		// @check 確認画面は、ツアー料金（¥30,000）と追加料金（子供 ¥1,500 × 1名）を分けた行で出し、合計は ¥31,500
		const summary = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( summary ).toContainText( 'ツアー料金（1ツアーあたり）' );
		await expect( summary ).toContainText( '¥30,000' );
		await expect( summary ).toContainText(
			'追加料金（子供 ¥1,500 × 1名）'
		);
		await expect( summary ).toContainText( '¥31,500' );
		// @check 人数の行に区分ごとの構成が添えてある（5名（大人4名、子供1名））
		await expect( summary ).toContainText( '5名（大人4名、子供1名）' );

		// @step 同意欄にチェックを入れて予約を確定する
		await checkConfirmConsents( page );
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();

		// @check 予約が保存され、人数は 5 名・基本料金合計は ¥31,500・超過の内訳（子供 1 名・含まれる人数 4）が保存されている
		await expect
			.poll( () => readLatestBooking() !== null, { timeout: 20000 } )
			.toBe( true );
		const booking = readLatestBooking() as NonNullable<
			ReturnType< typeof readLatestBooking >
		>;
		expect( booking.guests ).toBe( ADULTS + CHILDREN );
		expect( booking.total ).toBe( TOTAL );
		expect( booking.unit ).toBe( 'per_booking' );
		expect( booking.extra ).toEqual( {
			included_guests: INCLUDED_GUESTS,
			tiers: [ { label: '子供', price: EXTRA_FEE, count: 1 } ],
		} );

		// @step 管理者でログインして、その予約の編集画面を開く
		setAdminBlockEditorPreferences();
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

		// @check ツアー料金（サービス基本料金）は ¥30,000 / 1ツアー、追加料金の行に「子供 ¥1,500 × 1名」と合計 ¥1,500、基本料金合計は ¥31,500
		await expect(
			rowByHeading( 'サービス基本料金' ).first()
		).toContainText( '¥30,000 / 1ツアー' );
		const extraRow = rowByHeading( '追加料金' ).first();
		await expect( extraRow ).toContainText( '子供 ¥1,500 × 1名' );
		await expect(
			extraRow.locator( '.vkbm-booking-meta__value' ).last()
		).toHaveText( '¥1,500' );
		await expect(
			totalRow.first().locator( '.vkbm-booking-meta__value' )
		).toHaveText( '¥31,500' );
	} );
} );
