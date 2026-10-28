/**
 * issue #934: 1室1泊あたりのプランで、規定人数を超えた分を1名ごとに加算する。
 *
 * 検証内容（宿泊のサイト・Pro 版のみ）:
 *   1. 編集画面: 「1室1泊あたり」のときだけ追加料金のチェックが出る。チェックを入れると
 *      「基本料金に含まれる人数」と料金区分の行が出て、区分の見出しが「超過1名・1泊あたりの追加料金」に変わる。
 *      最大人数を超える人数を入れて保存すると、最大人数に丸めて保存される。
 *   2. 予約画面: 区分ごとの人数を入れると、規定人数以内は基本料金のみ、超えると高い区分の人から基本料金に含めて
 *      超過分の追加料金が加算される。確認画面の合計金額が一致する。
 *
 * 注: 文言は日本語で確かめる（e2e のサイトは日本語）。
 * テストデータは接頭辞（E2E934）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 * 予約は確定させない（確認画面まで）。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
	loginAsAdmin,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E934';
const OPTION_NAME = 'vkbm_provider_settings';

// 1室1泊あたり 20,000円、基本料金に含まれる人数 4 名、1室の最大人数 6 名。
// 追加料金は大人 3,000円・子供 2,000円（1名・1泊あたり）。
const BASE_PRICE = 20000;
const INCLUDED_GUESTS = 4;
const MAX_GUESTS = 6;
const ADULT_FEE = 3000;
const CHILD_FEE = 2000;
const NIGHTS = 2;

let originalSettingsJson: string | null = null;
let roomId = '';
let frontMenuId = '';
let adminMenuId = '';

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
 * 1室1泊あたり・最大 6 名の泊のプランを作る。
 *
 * @param title        プラン名
 * @param room         担当の客室 ID
 * @param extraEnabled 規定人数を超えた分の追加料金を使うか
 * @return プランの post ID
 */
function seedNightMenu(
	title: string,
	room: string,
	extraEnabled: boolean
): string {
	const extraLines = extraEnabled
		? `
			update_post_meta( $id, '_vkbm_night_extra_guest_enabled', '1' );
			update_post_meta( $id, '_vkbm_night_included_guests', ${ INCLUDED_GUESTS } );
		`
		: '';
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( room ) } ) );
		update_post_meta( $id, '_vkbm_base_price', ${ BASE_PRICE } );
		update_post_meta( $id, '_vkbm_booking_unit', 'night' );
		update_post_meta( $id, '_vkbm_night_price_unit', 'per_room' );
		update_post_meta( $id, '_vkbm_max_capacity', ${ MAX_GUESTS } );
		update_post_meta( $id, '_vkbm_price_tiers', array(
			array( 'label' => '大人', 'price' => ${ ADULT_FEE } ),
			array( 'label' => '子供', 'price' => ${ CHILD_FEE } ),
		) );
		${ extraLines }
		echo $id;
	`,
		title
	);
}

/**
 * このスペックが作ったデータを接頭辞で探して消す（シフト → プラン → 客室の順）。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		$find = static function ( $post_type ) use ( $prefix ) {
			$ids = array();
			foreach ( get_posts( array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				's'           => $prefix,
			) ) as $p ) {
				if ( 0 === strpos( $p->post_title, $prefix ) ) { $ids[] = $p->ID; }
			}
			return $ids;
		};
		$rooms = $find( 'vkbm_resource' );
		$menus = $find( 'vkbm_service_menu' );
		if ( $rooms ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_vkbm_shift_resource_id', 'value' => $rooms, 'compare' => 'IN' ) ),
			) ) as $sid ) { wp_delete_post( $sid, true ); }
		}
		foreach ( $menus as $mid ) { wp_delete_post( $mid, true ); }
		foreach ( $rooms as $rid ) { wp_delete_post( $rid, true ); }
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
 * 案内やメタボックス欄の折りたたみが、欄の操作を妨げるため。
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
 * 今日（Asia/Tokyo）から offset 日後の年・月・日を返す。
 *
 * @param offset 日数
 * @return 年・月・日（数値）
 */
function tokyoDateAfter( offset: number ) {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return {
		year: Number( year ),
		month: Number( month ),
		day: Number( day ),
	};
}

/**
 * 宿泊のカレンダーで、指定した日を押す（表示月が違えば翌月へ送る）。
 *
 * @param page       Playwright の Page
 * @param date       選ぶ日（Asia/Tokyo の年・月・日）
 * @param date.year  年
 * @param date.month 月
 * @param date.day   日
 */
async function clickStayDay(
	page: Page,
	date: { year: number; month: number; day: number }
) {
	const calendar = page.locator( '.vkbm-stay-calendar' );
	await calendar.waitFor( { state: 'visible', timeout: 15000 } );
	const monthPattern = new RegExp( `${ date.year }\\D+0?${ date.month }\\D` );
	for ( let i = 0; i < 3; i++ ) {
		const heading =
			( await calendar
				.locator( '.vkbm-calendar__current' )
				.innerText() ) + ' ';
		if ( monthPattern.test( heading ) ) {
			break;
		}
		await calendar.locator( '.vkbm-calendar__nav' ).last().click();
	}
	await calendar
		.locator( 'button.vkbm-calendar__day' )
		.filter( {
			has: page.locator( '.vkbm-calendar__day-label', {
				hasText: new RegExp( `^${ date.day }$` ),
			} ),
		} )
		.click();
}

/**
 * 「¥44,000」のような金額の文字列を数値にする。
 *
 * @param text 金額の文字列
 * @return 数値
 */
function toAmount( text: string ): number {
	return Number( text.replace( /[^\d]/g, '' ) );
}

/**
 * 区分ごとの人数（大人・子供の順）を入力する。
 *
 * @param page  Playwright の Page
 * @param adult 大人の人数
 * @param child 子供の人数
 */
async function fillGuestTiers( page: Page, adult: number, child: number ) {
	const inputs = page.locator( '.vkbm-plan-summary__guest-tier input' );
	await expect( inputs ).toHaveCount( 2 );
	await inputs.nth( 0 ).fill( String( adult ) );
	await inputs.nth( 1 ).fill( String( child ) );
}

test.describe
	.serial( 'issue #934: 1室1泊あたりの規定人数を超えた分の追加料金', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// 客室 1 室と、追加料金を使う予約画面用のプラン・追加料金を使わない編集画面用のプランを作る。
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Room',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			echo $id;
		`,
			'room'
		);
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}
		frontMenuId = seedNightMenu( `${ PREFIX } Front Plan`, roomId, true );
		adminMenuId = seedNightMenu( `${ PREFIX } Admin Plan`, roomId, false );

		// 宿泊のサイトにする（予約枠の定員機能も有効になる）。
		await configureProviderSettings( {
			industry_preset: 'lodging',
			resource_label_singular: '客室',
			resource_label_plural: '客室',
			// 既存の設定値（予約締切・予約可能日数）に左右されず、
			// 2 日後・4 日後のチェックイン／アウトを常に受付内にする。
			provider_reservation_deadline_hours: 0,
			provider_max_advance_booking_days: 0,
		} );
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	// @title 編集画面で追加料金のチェックを入れて、基本料金に含まれる人数と追加料金の区分を保存する
	test( 'admin: extra guest fee settings', async ( { page } ) => {
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		// @step サービスメニューの編集画面を開く（料金の単位は「1室1泊あたり」、追加料金のチェックは外れている）
		await page.goto(
			`/wp-admin/post.php?post=${ adminMenuId }&action=edit`
		);

		const checkbox = page.locator(
			'#vkbm_service_menu_night_extra_guest_enabled'
		);
		const includedField = page.locator(
			'#vkbm-night-included-guests-field'
		);
		const tiersRow = page.locator( '#vkbm-price-tiers-field' );
		await checkbox.waitFor( { state: 'attached', timeout: 15000 } );

		// @check 「規定人数を超える場合は人数に応じて追加料金あり」のチェックが出ていて、人数の欄と料金区分の行は隠れている
		await expect( checkbox ).toBeVisible();
		await expect(
			page.locator( '#vkbm-night-extra-guest-fields' )
		).toContainText( '規定人数を超える場合は人数に応じて追加料金あり' );
		await expect( checkbox ).not.toBeChecked();
		await expect( includedField ).toBeHidden();
		await expect( tiersRow ).toBeHidden();

		// @step チェックを入れて、基本料金に含まれる人数に最大人数（6 名）を超える 9 を入れる
		await checkbox.check();
		// @check 「基本料金に含まれる人数」の欄と料金区分の行が出て、区分の見出しが「超過1名・1泊あたりの追加料金」になる。基本料金は入力できる状態のまま
		await expect( includedField ).toBeVisible();
		await expect( includedField ).toContainText( '基本料金に含まれる人数' );
		await expect( tiersRow ).toBeVisible();
		await expect(
			tiersRow.locator( '.vkbm-price-tier-head-price' )
		).toHaveText( '超過1名・1泊あたりの追加料金' );
		await expect(
			page.locator( '#vkbm_service_menu_base_price' )
		).not.toHaveAttribute( 'readonly', /.*/ );
		await page
			.locator( '#vkbm_service_menu_night_included_guests' )
			.fill( '9' );

		// @step 保存する
		await page.locator( '.editor-post-publish-button' ).click();

		// @check 追加料金のチェックが保存され、基本料金に含まれる人数は最大人数（6 名）に丸められ、料金区分も残っている
		await expect
			.poll(
				() =>
					wpCliArgs(
						[
							'post',
							'meta',
							'get',
							adminMenuId,
							'_vkbm_night_included_guests',
						],
						{ stdio: 'pipe' }
					),
				{ timeout: 20000 }
			)
			.toBe( String( MAX_GUESTS ) );
		expect(
			wpCliArgs(
				[
					'post',
					'meta',
					'get',
					adminMenuId,
					'_vkbm_night_extra_guest_enabled',
				],
				{ stdio: 'pipe' }
			)
		).toBe( '1' );
		const savedLabels = wpEvalPhp( `
			$tiers = get_post_meta( ${ Number( adminMenuId ) }, '_vkbm_price_tiers', true );
			echo wp_json_encode( array_column( is_array( $tiers ) ? $tiers : array(), 'label' ) );
		` ).trim();
		expect( JSON.parse( savedLabels ) ).toEqual( [ '大人', '子供' ] );

		// @check 保存後、手動で再読み込みしなくても、人数を最大人数に丸めた警告がエディタの通知に出る
		await expect(
			page.locator( '.components-notice.is-warning' )
		).toContainText( `${ MAX_GUESTS }人に変更しました`, {
			timeout: 20000,
		} );

		// @step 料金の単位を「1名1泊あたり」に切り替える
		await page.evaluate( () => {
			const radio = document.querySelector< HTMLInputElement >(
				'input[name="vkbm_service_menu[night_price_unit]"][value="per_guest"]'
			);
			if ( ! radio ) {
				throw new Error(
					'1名1泊あたりのラジオボタンが見つかりません。'
				);
			}
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		// @check 追加料金のチェックと人数の欄は隠れ、区分の見出しは1名1泊あたりの料金に戻る
		await expect(
			page.locator( '#vkbm-night-extra-guest-fields' )
		).toBeHidden();
		await expect(
			tiersRow.locator( '.vkbm-price-tier-head-price' )
		).not.toHaveText( '超過1名・1泊あたりの追加料金' );
	} );

	// @title 追加料金ありで料金区分を空にして保存すると、追加料金が加算されない旨の警告がエディタに出る
	test( 'admin: warning when no price category', async ( { page } ) => {
		prepareBlockEditorPreferences();
		const noTierMenuId = seedNightMenu(
			`${ PREFIX } NoTier Plan`,
			roomId,
			true
		);
		await loginAsAdmin( page );
		// @step 追加料金ありのプランの編集画面を開き、料金区分の名前と金額をすべて空にする
		await page.goto(
			`/wp-admin/post.php?post=${ noTierMenuId }&action=edit`
		);
		await page
			.locator( '#vkbm-price-tiers-field' )
			.waitFor( { state: 'visible', timeout: 15000 } );
		await page.evaluate( () => {
			document
				.querySelectorAll< HTMLInputElement >(
					'#vkbm-price-tiers-field input[type="text"], #vkbm-price-tiers-field input[type="number"]'
				)
				.forEach( ( input ) => {
					input.value = '';
					input.dispatchEvent(
						new Event( 'input', { bubbles: true } )
					);
					input.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				} );
		} );

		// @step 保存する
		await page.locator( '.editor-post-publish-button' ).click();

		// @check 保存後、手動で再読み込みしなくても、料金区分が無いため追加料金が加算されない旨の警告がエディタの通知に出る
		await expect(
			page.locator( '.components-notice.is-warning' )
		).toContainText(
			'料金区分が入力されていないため、追加料金は加算されません',
			{
				timeout: 20000,
			}
		);

		// @step タイトルを少し変えて、同じ警告が出る保存をもう一度行う
		const secondNoticeResponse = page.waitForResponse(
			async ( response ) => {
				if ( ! response.url().includes( 'service-menu-notice' ) ) {
					return false;
				}
				const body = await response.json().catch( () => null );
				return !! ( body && body.message );
			},
			{ timeout: 30000 }
		);
		await page.evaluate( ( title ) => {
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			( window as any ).wp.data
				.dispatch( 'core/editor' )
				.editPost( { title } );
		}, `${ PREFIX } NoTier Plan 2` );
		await page.locator( '.editor-post-publish-button' ).click();
		await secondNoticeResponse;

		// @check 同じ警告が2回続けて出ても、通知は積み重ならず1つだけになる
		await expect(
			page.locator( '.components-notice.is-warning' )
		).toHaveCount( 1 );
	} );

	// @title 予約画面で区分ごとの人数を入れると、規定人数を超えた分の追加料金が加算された合計が確認画面に出る
	test( 'front: total with extra guests', async ( { page } ) => {
		// @step 追加料金を使うプランの予約画面を開き、2 日後にチェックイン、4 日後にチェックアウトの日程を選ぶ
		await page.goto( `/booking/?menu_id=${ frontMenuId }` );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
			timeout: 15000,
		} );
		await clickStayDay( page, tokyoDateAfter( 2 ) );
		await clickStayDay( page, tokyoDateAfter( 2 + NIGHTS ) );
		const room = page.locator( '.vkbm-lodging__room' ).first();
		await expect( room ).toBeVisible( { timeout: 15000 } );

		// @check 大人・子供の区分ごとの人数欄と追加料金（+¥3,000・+¥2,000）が出て、追加料金がかかる旨の案内が出る
		const tierLabels = page.locator(
			'.vkbm-plan-summary__guest-tier-label'
		);
		await expect( tierLabels ).toHaveCount( 2 );
		await expect( tierLabels.nth( 0 ) ).toContainText( '大人' );
		await expect( tierLabels.nth( 0 ) ).toContainText( '+¥3,000' );
		await expect( tierLabels.nth( 1 ) ).toContainText( '子供' );
		await expect( tierLabels.nth( 1 ) ).toContainText( '+¥2,000' );
		const feeNote = page.locator( '.vkbm-plan-summary__hint', {
			hasText: '4名までのご利用を含みます',
		} );
		await expect( feeNote ).toBeVisible();
		await expect( feeNote ).toContainText(
			'超えた1名につき1泊ごとに加算されます'
		);

		// @step 大人 2 名・子供 2 名（規定人数の 4 名以内）を入れる
		await fillGuestTiers( page, 2, 2 );
		// @check 客室の合計が基本料金だけ（20,000円 × 2 泊 = 40,000円）になる
		await expect( room ).toContainText(
			`¥${ ( BASE_PRICE * NIGHTS ).toLocaleString( 'en-US' ) }`,
			{ timeout: 15000 }
		);

		// @step 大人 3 名・子供 2 名（規定人数を 1 名超える）を入れる
		await fillGuestTiers( page, 3, 2 );
		// @check 高い大人 3 名＋子供 1 名が基本料金に含まれ、子供 1 名分の 2,000円が1泊ごとに加算された合計（（20,000 + 2,000）× 2 泊 = 44,000円）になる
		const expectedTotal = ( BASE_PRICE + CHILD_FEE ) * NIGHTS;
		await expect( room ).toContainText(
			`¥${ expectedTotal.toLocaleString( 'en-US' ) }`,
			{ timeout: 15000 }
		);

		// @step 大人 5 名・子供 1 名（最大人数の 6 名。規定人数を 2 名超える）を入れて、客室を選び、予約に進む
		await fillGuestTiers( page, 5, 1 );
		const expectedMaxTotal =
			( BASE_PRICE + ADULT_FEE + CHILD_FEE ) * NIGHTS;
		await expect( room ).toContainText(
			`¥${ expectedMaxTotal.toLocaleString( 'en-US' ) }`,
			{ timeout: 15000 }
		);
		await room.click();
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );

		// @check 確認画面の合計金額が、高い大人 4 名が基本料金に含まれ、大人 1 名と子供 1 名が加算された金額（（20,000 + 3,000 + 2,000）× 2 泊 = 50,000円）になる
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await confirm.waitFor( { state: 'visible', timeout: 15000 } );
		const total = toAmount(
			await confirm
				.locator( '.vkbm-confirm__summary-item-value--price' )
				.first()
				.innerText()
		);
		expect( total ).toBe( expectedMaxTotal );
	} );
} );
