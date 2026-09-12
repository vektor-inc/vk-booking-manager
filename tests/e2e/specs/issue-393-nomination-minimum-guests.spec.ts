/**
 * issue #393: 指名を使うメニューに「最低申し込み人数」（受付制限）を追加する機能の e2e 検証。
 *
 * 「予約枠の定員」（#392）で指名を使うメニューが1枠1組（貸切）として扱われるようになったことを受け、
 * 店側が「1名の予約に定員3の枠を占有されるのを避けたい」ときに使う受付制限。既存の
 * 「最少催行人数（グループ開催型・表示専用）」とはメタキー（_vkbm_min_capacity）を共用するが、
 * 指名を使うメニューでは意味が「1組の最低人数」の受付制限に変わる。
 *
 * 検証内容（issueの完了条件に対応）:
 * 1. フロント: 指名を使う・最低申し込み人数2のメニューで、申込人数入力欄の下限・初期値が
 *    最低申し込み人数（2）に合わせて補正され、案内文が常時表示される。
 * 2. フロント: 同じメニューで2名（下限どおり）なら「予約へ進む」が有効になる。
 * 3. REST: 下書き保存（POST /vkbm/v1/drafts）で、1名は nomination_min_guests エラー（400）、
 *    2名・3名は正常に保存できる。
 * 4. REST: 指名を使わないメニューでは、最低申し込み人数の設定に関わらず1名でも
 *    下書き保存できる（従来どおりの表示専用の挙動が変わっていないこと）。
 * 5. REST: 複数人一括予約がOFFのメニューでは、最低申し込み人数に2以上が保存されていても
 *    1名で下書き保存できる（実効0のクランプが効いていること）。
 *
 * 予約確定（POST /vkbm/v1/bookings）側のサーバ検証は、ログインを要するため e2e ではなく
 * PHPUnit（Booking_Confirmation_Controller_Test::test_create_booking_rejects_nomination_min_guests）
 * でカバーする。指名を使うメニューのフロントに催行状態（「あと◯人で開催確定」）が
 * 表示されないことは issue #392 の e2e（issue-392-nomination-slot-capacity.spec.ts）で
 * 既に検証済みのため、本ファイルでは重複させない。
 *
 * 対象外（意図的に e2e を用意していない）: app.js の slotBelowNominationMinGuests
 * （選択中スロットの残り人数が最低申し込み人数に満たない枠で「この時間帯は最低人数を
 * 受け付けられません」を表示する分岐）。#392 以降、指名を使うメニューの枠は「満席か空きか」の
 * 二値になっており（issue-392-nomination-slot-capacity.spec.ts:461-469 参照。1件でも予約が入ると
 * remaining は 0 になり、相乗みはしない）、この分岐が到達する「残り人数が1名以上あり、かつ
 * 最低申し込み人数に満たない」状態は #392 より前に入って枠を部分的に消費している旧データに
 * 対してしか起こり得ない。e2e は新規データしかシードできないためこの状態を再現できず、
 * 対象から外している（安藤レビュー指摘）。実装自体は旧データへの防御として維持している
 * （詳細はテストファイル末尾のコメントと docs/specification-multiple-booking-capacity.md を参照）。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto / page.request には相対パスを渡す。
 * - REST 検証は既存 issue-392-nomination-slot-capacity.spec.ts と同じ手法で、
 *   POST /vkbm/v1/drafts の permission_callback が __return_true（認証不要）であることを利用する。
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

const MENU_TITLE = 'Nomination Min Guests Menu';
const NO_NOMINATION_MENU_TITLE = 'Nomination Min Guests Menu (No Nomination)';
const NO_ALLOW_MULTI_MENU_TITLE = 'Nomination Min Guests Menu (No Multi Guest)';
const MENU_MAX_CAPACITY = 3;
const MENU_MIN_GUESTS = 2;
const MENU_BASE_PRICE = 1000;

let originalStaffEnabled = true;
let staffId = '';
let menuId = '';
let noNominationMenuId = '';
let noAllowMultiMenuId = '';

/**
 * 検証用メニューを作成する。同名メニューがあれば作り直す（冪等）。
 *
 * @param title               メニュータイトル
 * @param useNomination       指名を使うメニューにするか（false なら _vkbm_disable_nomination を立てる）
 * @param allowMultipleGuests 複数人一括予約を許可するか
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedMenu(
	title: string,
	useNomination: boolean,
	allowMultipleGuests: boolean
): string {
	const disableNominationLine = useNomination
		? ''
		: `update_post_meta( $menu_id, '_vkbm_disable_nomination', true );`;
	const allowMultiLine = allowMultipleGuests
		? `update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );`
		: '';
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };

		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ title }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_max_capacity', ${ MENU_MAX_CAPACITY } );
		update_post_meta( $menu_id, '_vkbm_min_capacity', ${ MENU_MIN_GUESTS } );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ MENU_BASE_PRICE } );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		${ allowMultiLine }
		${ disableNominationLine }
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Menu seeding failed ("${ title }"): "${ result }"` );
	}
	return result;
}

/**
 * POST /vkbm/v1/drafts を叩き、ステータスとレスポンスボディを返すヘルパー。
 *
 * @param request Playwright の APIRequestContext
 * @param id      対象メニューの post ID（数値文字列）
 * @param guests  申込人数
 * @return ステータスコードとレスポンスボディ
 */
async function postDraft(
	request: APIRequestContext,
	id: string,
	guests: number
): Promise< { status: number; body: Record< string, unknown > } > {
	const dateStr = '2026-09-01';
	const response = await request.post( '/wp-json/vkbm/v1/drafts', {
		data: {
			menu_id: Number( id ),
			date: dateStr,
			guests,
			slot: {
				slot_id: `${ id }-393-slot`,
				start_at: `${ dateStr }T10:00:00+09:00`,
				end_at: `${ dateStr }T10:30:00+09:00`,
			},
			meta: {
				timezone: 'Asia/Tokyo',
			},
		},
	} );
	const body = await response.json();
	return { status: response.status(), body };
}

test.describe( 'issue #393: 指名を使うメニューの最低申し込み人数（受付制限）', () => {
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		// サイト全体の指名機能をONにする（メニューは既定で指名を使う）。
		setStaffEnabled( true );

		staffId = getStaffId();
		menuId = seedMenu( MENU_TITLE, true, true );
		noNominationMenuId = seedMenu( NO_NOMINATION_MENU_TITLE, false, true );
		noAllowMultiMenuId = seedMenu( NO_ALLOW_MULTI_MENU_TITLE, true, false );

		// 以降のテストで使う来月分のシフトを作成しておく。
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		createShiftForMonth(
			staffId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);
	} );

	test.afterAll( () => {
		[ menuId, noNominationMenuId, noAllowMultiMenuId ].forEach( ( id ) => {
			if ( id ) {
				wpCliArgs( [ 'post', 'delete', id, '--force' ], {
					stdio: 'ignore',
				} );
			}
		} );
		setStaffEnabled( originalStaffEnabled );
	} );

	test( 'フロント: 指名を使う・最低申し込み人数2のメニューで、申込人数の下限・初期値が2になり案内文が出る', async ( {
		page,
	} ) => {
		await page.goto( `/booking/?menu_id=${ menuId }` );
		await page.waitForLoadState( 'networkidle' );

		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await selectAvailableCalendarDay( page, calendarMetaResponse );
		await page.waitForTimeout( 1000 );

		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		await slots.first().click();
		await page.waitForTimeout( 1000 );

		const guestsInput = page.locator( '#vkbm-reservation-guests' );
		await guestsInput.waitFor( { state: 'visible', timeout: 10000 } );

		// #393: 初期値・下限が最低申し込み人数（2）に合わせて補正されるため、
		// 複数人一括予約メニューの既定初期値である1にはならない。
		await expect( guestsInput ).toHaveValue( String( MENU_MIN_GUESTS ) );
		await expect( guestsInput ).toHaveAttribute(
			'min',
			String( MENU_MIN_GUESTS )
		);

		// 1名にしようとしても、onChange のクランプにより下限未満へは下がらない。
		await guestsInput.fill( '1' );
		await page.waitForTimeout( 300 );
		await expect( guestsInput ).toHaveValue( String( MENU_MIN_GUESTS ) );

		// #393: 常時案内文（buildNominationMinGuestsHint()。状態に依存しない中立の文言。
		// 植草レビュー指摘を受け、命令形のエラー・警告文言（buildNominationMinGuestsMessage()）
		// とは別の文言になっている）。要素を特定して照合し、無関係な箇所に当たって
		// 検証が素通りしないようにする。
		const guestsHint = page.locator( '#vkbm-reservation-guests-hint' );
		await expect( guestsHint ).toBeVisible();
		await expect( guestsHint ).toContainText( 'からのお申し込みです' );
		await expect( guestsHint ).not.toContainText( '以上にしてください' );

		// 下限どおり（2名）なら「予約へ進む」が有効になる。
		const proceed = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( proceed ).toBeEnabled();
	} );

	test( 'REST: 指名を使う・最低申し込み人数2のメニューで、下書き保存は1名だとエラー、2名・3名なら成功する', async ( {
		request,
	} ) => {
		const rejected = await postDraft( request, menuId, 1 );
		expect( rejected.status ).toBe( 400 );
		expect( rejected.body.code ).toBe( 'nomination_min_guests' );

		const acceptedAtMinimum = await postDraft( request, menuId, 2 );
		expect( acceptedAtMinimum.status ).toBe( 200 );
		expect( typeof acceptedAtMinimum.body.token ).toBe( 'string' );

		const acceptedAboveMinimum = await postDraft( request, menuId, 3 );
		expect( acceptedAboveMinimum.status ).toBe( 200 );
		expect( typeof acceptedAboveMinimum.body.token ).toBe( 'string' );
	} );

	test( 'REST: 指名を使わないメニューでは、最低申し込み人数の設定に関わらず1名でも下書き保存できる（従来どおり表示専用）', async ( {
		request,
	} ) => {
		const result = await postDraft( request, noNominationMenuId, 1 );
		expect( result.status ).toBe( 200 );
		expect( typeof result.body.token ).toBe( 'string' );
	} );

	test( 'REST: 複数人一括予約OFFのメニューでは、最低申し込み人数に2以上が保存されていても1名で下書き保存できる（実効0のクランプ）', async ( {
		request,
	} ) => {
		const result = await postDraft( request, noAllowMultiMenuId, 1 );
		expect( result.status ).toBe( 200 );
		expect( typeof result.body.token ).toBe( 'string' );
	} );

	// #393: slotBelowNominationMinGuests（選択中スロットの残り人数が最低申し込み人数に満たない枠で
	// 「この時間帯は最低人数を受け付けられません」を表示する分岐）の e2e ケースは、意図的に用意していない。
	//
	// 実装（app.js の slotBelowNominationMinGuests）そのものは、#392 より前に入って枠を部分的に
	// 消費している予約が残っている場合の防御として正しく、サーバー側の判定とも矛盾しない。
	// 削除の理由は「不要な分岐だから」ではなく、e2e で新規データからこの状態を再現できないため
	// （安藤レビュー指摘）。
	//
	// 指名を使うメニューの枠は #392 以降「満席か空きか」の二値になっている
	// （issue-392-nomination-slot-capacity.spec.ts:461-469 の
	// 「REST: 定員はスタッフ1人あたり。「指名なし」の予約も1組で占有し、定員未達でも相乗りさせない」を参照。
	// 1件でも予約が入った時点でその枠の remaining は 0 になり、「定員3 − 予約2 = 残り1」のような
	// 中間状態は新規シードでは作れない）。そのため e2e で新しく seedBooking() のような予約を
	// シードしても、maxSelectableGuests は 0（満枠）になるだけで、意図した
	// 「残り1名（最低申し込み人数2未満）」の状態には到達しない。
	// この分岐が実際に到達し得るのは、#392 より前に保存され、枠を部分的に消費したまま残っている
	// 旧データに対してのみである。
} );
