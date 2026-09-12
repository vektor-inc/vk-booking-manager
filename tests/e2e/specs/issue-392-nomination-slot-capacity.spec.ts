/**
 * issue #392: 指名を使うメニューを「1枠1組（貸切）」として扱い、
 * 予約枠の定員を「1組の最大人数」に使えるようにする機能の e2e 検証。
 *
 * 親issue #251 の決定事項1・3・5・6・7 に対応する。決定事項4（最低申し込み人数）・
 * 管理画面/通知まわりの表示対応は別サブissueのためここでは扱わない。
 *
 * 検証内容（issueの完了条件に対応）:
 * 1. フロント: 指名を使うメニューで、ガイドを指名して定員（3名）までの人数で
 *    予約内容確認画面まで進められる。4名は自動的に3名へクランプされる
 *    （入力欄の上限＝選択中スロットのremaining＝定員）。
 * 2. フロント: 指名を使うメニューの空き枠一覧に、残数（「残X/満Y」の数値）・
 *    催行状態（「開催決定」「あとN名で開催」）は表示されない。ただし「満枠（Fully booked）」
 *    ラベルと選択不可（disabled）状態は、指名を使うメニューでも従来どおり表示される（#392）。
 * 3. フロント: 確認画面の合計金額に、指名料が人数（3名）に関わらず1回分だけ
 *    加算されていること（基本料金×3＋指名料×1。指名料×3ではない）。
 * 4. REST: ガイドAを指名した予約（3名）が入った時間帯は、そのスタッフを指名した
 *    空き枠一覧から見えなくなる（他の予約者からは選べない）。同じ時間帯の
 *    別スタッフ（ガイドB）は引き続き選べる。
 * 5. REST: 定員はスタッフ1人あたりのため、ガイドAが占有されていても「指名なし」の
 *    空き枠一覧はガイドB経由で定員いっぱい（3）まで受け付けられる。
 *    ただし「指名なし」で申し込んだ予約（ガイドBに1名だけ）が入ると、
 *    その時間帯は両ガイドとも占有済みとなり「指名なし」の空き枠自体が
 *    受付不可（remaining=0）になる（＝1組で枠を占有し、定員未達でも相乗りさせない）。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto / page.request には相対パスを渡す。
 * - REST 検証（4・5）は既存 vkbm_booking 投稿を直接シードして状態を作る
 *   （exclusive-booking.spec.ts / menu-level-nomination-toggle.spec.ts と同じ手法）。
 *   ブラウザ経由の実予約確定（ログイン＋登録フロー）は本 spec のスコープ外とする。
 */
import { test, expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	getStaffEnabled,
	setStaffEnabled,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

const MENU_TITLE = 'Nomination Capacity Menu';
const STAFF_B_TITLE = 'Nomination Capacity Staff B';
const MENU_MAX_CAPACITY = 3;
const MENU_BASE_PRICE = 1000;
const STAFF_A_NOMINATION_FEE = 500;

let originalStaffEnabled = true;
let menuId = '';
let staffAId = '';
let staffBId = '';

/**
 * 検証用の2人目のスタッフ（ガイドB）を作成する。同名スタッフがあれば作り直す（冪等）。
 *
 * @return 作成したスタッフの post ID（数値文字列）
 */
function seedStaffB(): string {
	const phpCode = `
		$existing = get_posts( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'any',
			'title'       => '${ STAFF_B_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
		$staff_id = wp_insert_post( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'publish',
			'post_title'  => '${ STAFF_B_TITLE }',
		) );
		if ( is_wp_error( $staff_id ) || ! $staff_id ) {
			echo 'Error: failed to create staff B';
			return;
		}
		echo $staff_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Staff B seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 指名を使う検証用メニュー（定員3・複数人一括予約許可・ガイドA/B割当）を作成する。
 * 同名メニューがあれば作り直す（冪等）。ガイドAに指名料（500）を設定する。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedNominationMenu(): string {
	const phpCode = `
		$staff_a = ${ Number.parseInt( staffAId, 10 ) };
		$staff_b = ${ Number.parseInt( staffBId, 10 ) };

		update_post_meta( $staff_a, '_vkbm_nomination_fee', ${ STAFF_A_NOMINATION_FEE } );

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
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create nomination menu';
			return;
		}
		// このメニューでは指名を使う（既定＝使う。_vkbm_disable_nomination は保存しない）。
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_a, (int) $staff_b ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', ${ MENU_MAX_CAPACITY } );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ MENU_BASE_PRICE } );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Nomination menu seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 指定スタッフ・日時に予約を1件シードするヘルパー（exclusive-booking.spec.ts と同じ手法）。
 *
 * @param staffId 担当スタッフの post ID（数値文字列）
 * @param dateStr 予約日（YYYY-MM-DD）
 * @param guests  予約人数
 * @return 作成した予約の post ID（数値文字列）
 */
function seedBooking(
	staffId: string,
	dateStr: string,
	guests: number
): string {
	const phpCode = `
		$booking_id = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => 'Nomination Capacity E2E Booking',
		) );
		if ( is_wp_error( $booking_id ) || ! $booking_id ) {
			echo 'Error: failed to create booking';
			return;
		}
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '${ dateStr } 09:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '${ dateStr } 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', '${ dateStr } 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', ${ Number.parseInt(
			staffId,
			10
		) } );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', ${ Number.parseInt(
			menuId,
			10
		) } );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', ${ guests } );
		echo $booking_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Booking seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * availability REST（/vkbm/v1/availabilities）を叩き、指定日の slots 配列を返すヘルパー。
 *
 * @param request    Playwright の APIRequestContext
 * @param dateStr    対象日（YYYY-MM-DD）
 * @param resourceId スタッフを指名する場合はその post ID。指名なし（自動割当）は省略する。
 * @return slots 配列
 */
async function getDailySlots(
	request: APIRequestContext,
	dateStr: string,
	resourceId?: string
): Promise< Array< Record< string, unknown > > > {
	const resourceParam = resourceId ? `&resource_id=${ resourceId }` : '';
	const response = await request.get(
		`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ dateStr }${ resourceParam }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`
	);
	expect( response.status() ).toBe( 200 );
	const data = await response.json();
	return Array.isArray( data ) ? data : data.slots || [];
}

test.describe( 'issue #392: 指名を使うメニュー（1枠1組・貸切）', () => {
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		// サイト全体の指名機能をONにする（メニューは既定で指名を使う）。
		setStaffEnabled( true );

		staffAId = getStaffId();
		staffBId = seedStaffB();
		menuId = seedNominationMenu();

		// 以降のテストで使う来月分のシフトを、ここで両ガイド分まとめて作成しておく
		// （個別テスト内で作成すると、そのテストが失敗した場合に後続テストの前提が
		// 崩れてしまうため #392）。
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		createShiftForMonth(
			staffAId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);
		createShiftForMonth(
			staffBId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);
	} );

	test.afterAll( () => {
		if ( menuId ) {
			wpCliArgs( [ 'post', 'delete', menuId, '--force' ], {
				stdio: 'ignore',
			} );
			menuId = '';
		}
		if ( staffBId ) {
			wpCliArgs( [ 'post', 'delete', staffBId, '--force' ], {
				stdio: 'ignore',
			} );
			staffBId = '';
		}
		if ( staffAId ) {
			wpEvalPhp(
				`delete_post_meta( ${ Number.parseInt(
					staffAId,
					10
				) }, '_vkbm_nomination_fee' );`
			);
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( 'フロント: ガイドを指名して定員3名で予約内容確認まで進められ、4名入力は3名にクランプされる', async ( {
		page,
	} ) => {
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// メニューカードの予約ボタンをクリックする。
		const menuCard = page
			.locator( '.vkbm-menu-loop__item', { hasText: MENU_TITLE } )
			.first();
		await menuCard
			.locator( '.vkbm-menu-loop__button--reserve' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await menuCard
			.locator( '.vkbm-menu-loop__button--reserve' )
			.first()
			.click();

		// このメニューは指名を使うため、スタッフ選択欄が表示される。ガイドAを指名する。
		const staffSelect = page.locator(
			'.vkbm-plan-summary__selectors select'
		);
		await expect
			.poll( async () => staffSelect.count(), { timeout: 10000 } )
			.toBe( 2 );
		await staffSelect.nth( 1 ).selectOption( { value: staffAId } );

		await selectAvailableCalendarDay( page, calendarMetaResponse );
		await page.waitForTimeout( 1000 );

		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		await slots.first().click();
		await page.waitForTimeout( 1000 );

		// 人数入力欄が表示され、3名まで入力できる。
		const guestsInput = page.locator( '#vkbm-reservation-guests' );
		await guestsInput.waitFor( { state: 'visible', timeout: 10000 } );
		await guestsInput.fill( '3' );
		await page.waitForTimeout( 300 );
		await expect( guestsInput ).toHaveValue( '3' );

		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeEnabled();

		// 4名を入力しても、定員（3）を超えた分は自動的にクランプされる
		// （app.js の onChange が maxSelectableGuests＝選択中スロットの remaining でクランプする）。
		await guestsInput.fill( '4' );
		await page.waitForTimeout( 300 );
		await expect( guestsInput ).toHaveValue( '3' );

		// 予約内容確認へ進む。
		await proceed.click();
		await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
		await page.waitForTimeout( 2000 );

		// 合計金額は 基本料金1000×3名 + 指名料500×1回 = 3500。
		// 指名料が人数分（500×3=1500）加算されていれば 4500 になるはずで、3500 と 4500 を
		// 区別することで「指名料は人数によらず1回だけ」を確認する。
		await expect( page.locator( 'body' ) ).toContainText( '3,500' );
		await expect( page.locator( 'body' ) ).not.toContainText( '4,500' );
	} );

	test( 'フロント: 指名を使うメニューの空き枠一覧に残数・催行状態が表示されない（満枠ラベルは表示される・#392）', async ( {
		page,
	} ) => {
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		const menuCard = page
			.locator( '.vkbm-menu-loop__item', { hasText: MENU_TITLE } )
			.first();
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await menuCard
			.locator( '.vkbm-menu-loop__button--reserve' )
			.first()
			.click();

		await selectAvailableCalendarDay( page, calendarMetaResponse );
		await page.waitForTimeout( 1000 );

		await page
			.locator( '.vkbm-slot-list__item' )
			.first()
			.waitFor( { state: 'visible', timeout: 10000 } );

		// #392: 指名を使うメニューでは「残 X / 満 Y」の数値バッジ・催行状態
		// （「開催決定」「あとN名で開催」）を表示しない。
		// #392: ただし「満枠（Fully booked）」ラベル自体は残数の数値を含まない
		// 二値表示のため、他クラスと区別して「数値バッジ（--full・--closed 修飾子が付かない方）」
		// だけが無いことを確認する（満枠ラベル自体は次のテストで別途確認する）。
		await expect(
			page.locator(
				'.vkbm-slot-list__remaining:not(.vkbm-slot-list__remaining--full):not(.vkbm-slot-list__remaining--closed)'
			)
		).toHaveCount( 0 );
		await expect(
			page.locator( '.vkbm-slot-list__fulfillment' )
		).toHaveCount( 0 );
	} );

	test( 'フロント: 指名を使うメニューでも満枠のスタッフ枠には理由（Fully booked）が表示され選択できない（#392）', async ( {
		page,
	} ) => {
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		// 他のテストと日付が競合しないよう別日（17日）を使う。両ガイドとも占有済みにする。
		const dateStr = `${ year }-${ month }-17`;
		seedBooking( staffAId, dateStr, MENU_MAX_CAPACITY );
		seedBooking( staffBId, dateStr, 1 );

		await page.goto( `/booking/?menu_id=${ menuId }` );
		await page.waitForLoadState( 'networkidle' );

		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );

		// 対象は翌月のため「次の月」を1回送ってから対象日（17日）を選ぶ。
		await page.getByRole( 'button', { name: '次の月' } ).click();
		await page.waitForTimeout( 800 );
		await page
			.locator( '.vkbm-calendar__day', {
				hasText: new RegExp( '^17$' ),
			} )
			.first()
			.click();
		await page.waitForTimeout( 1000 );

		// #392: 「指名なし」の一覧で、両ガイドとも占有済みの09:00枠は
		// disabled で選択できない（表示文言は翻訳後だと「満枠」になるため、
		// ここでは可視・非活性の二値のみを検証し、文言自体はアサートしない）。
		const fullSlot = page
			.locator( '.vkbm-slot-list__item.is-full' )
			.first();
		await expect( fullSlot ).toBeVisible();
		await expect( fullSlot ).toBeDisabled();
	} );

	test( 'REST: ガイドを指名した予約が入った時間帯は、そのガイドの枠が他の予約者から見えなくなる（別ガイドは引き続き選べる）', async ( {
		request,
	} ) => {
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		const dateStr = `${ year }-${ month }-15`;

		// ガイドAを指名して3名で予約が入っている状態を再現する。
		seedBooking( staffAId, dateStr, MENU_MAX_CAPACITY );

		// ガイドAを指名して空き枠を見ると、09:00の枠は（予約済みのため）出てこない。
		const staffASlots = await getDailySlots( request, dateStr, staffAId );
		const staffANineOClock = staffASlots.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( staffANineOClock ).toBeUndefined();

		// ガイドBを指名すれば、同じ時間帯でも影響を受けず定員（3）のまま選べる。
		const staffBSlots = await getDailySlots( request, dateStr, staffBId );
		const staffBNineOClock = staffBSlots.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( staffBNineOClock ).toBeDefined();
		expect( staffBNineOClock?.capacity ).toBe( MENU_MAX_CAPACITY );
		expect( staffBNineOClock?.remaining ).toBe( MENU_MAX_CAPACITY );
	} );

	test( 'REST: 定員はスタッフ1人あたり。「指名なし」の予約も1組で占有し、定員未達でも相乗りさせない', async ( {
		request,
	} ) => {
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		// 前のテストと日付が競合しないよう別日（16日）を使う。
		const dateStr = `${ year }-${ month }-16`;

		// ガイドAを指名して3名で予約が入っている状態（前テストと同じ状況を別日で再現）。
		seedBooking( staffAId, dateStr, MENU_MAX_CAPACITY );

		// 「指名なし」（自動割当）の空き枠は、ガイドBがまだ空いているため
		// 定員（3）まで受け付けられる（定員はスタッフ1人あたりのため）。
		const autoSlotsBeforeStaffB = await getDailySlots( request, dateStr );
		const autoNineOClockBefore = autoSlotsBeforeStaffB.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( autoNineOClockBefore ).toBeDefined();
		expect( autoNineOClockBefore?.remaining ).toBe( MENU_MAX_CAPACITY );

		// 続けて「指名なし」でガイドBに1名だけ予約が入る（定員3名のうち1名だけ）。
		seedBooking( staffBId, dateStr, 1 );
		// availability のトランジェントキャッシュ（日別スロットはTTL1分）をクリアする。
		// これを忘れると、直前の autoSlotsBeforeStaffB 取得時にキャッシュされた古い
		// remaining が返り続け、このseedBookingの反映が見えない（#392）。
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		// #392: 指名を使うメニューは1枠1組（貸切）のため、ガイドBの残り2名分の余地があっても
		// 相乗りはさせない。両ガイドとも予約済みとなり、「指名なし」の空き枠は受付不可
		// （remaining=0）になる（#392より前の複数人相乗り仕様なら 3-1=2 が残るはずだった）。
		const autoSlotsAfterStaffB = await getDailySlots( request, dateStr );
		const autoNineOClockAfter = autoSlotsAfterStaffB.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( autoNineOClockAfter ).toBeDefined();
		expect( autoNineOClockAfter?.remaining ).toBe( 0 );
	} );
} );
