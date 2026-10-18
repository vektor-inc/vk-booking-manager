/**
 * 宿泊モード（業種プリセット「宿泊」）の予約の流れ（#577）
 *
 * 検証内容:
 * 1. 管理画面: BM 設定の業種プリセットの選択肢に「宿泊」が出て、選んで保存できる。
 *    保存後、サービスメニューの編集画面に「予約の単位」（時間枠／泊）が出る。
 * 2. 予約画面（泊のプラン）: チェックイン日・チェックアウト日をカレンダーで選ぶと空いている客室が出る。
 *    客室を選んで「予約に進む」を押すと、確認画面にチェックイン／チェックアウトの日付・時刻・泊数・客室が出る。
 * 3. 予約画面（時間枠のプラン）: 宿泊プリセットのサイトでも、時間枠のプランは従来どおり
 *    日付 → 時間枠 → 確認画面の流れで、宿泊用の画面（.vkbm-lodging）は出ない。
 * 4. 予約画面（時間枠のプラン・販売日の手動切り替え, #743）: 基本営業時間を登録した宿泊のサイトで、
 *    シフトの販売日の画面で「販売しない」から「販売する」へ切り替えた日に、時間枠のプランの予約枠が出る。
 * 5. 既存サイト（業種プリセット「カスタム」）: 時間枠のプランの予約が従来どおり確認画面まで進み、
 *    管理画面のサービスメニュー編集に「予約の単位」は出ない。
 *
 * テストデータと後片付け（#567 の連鎖失敗の教訓）:
 * - 客室・シフト・プランは固有の接頭辞（E2E577）を付けて作り、afterAll で接頭辞を手がかりに
 *   予約 → シフト → プラン → 客室の順で消す（beforeAll が途中で失敗しても残りを消せる）。
 * - BM 設定（vkbm_provider_settings）は beforeAll で控え、afterAll の finally で必ず元へ戻す
 *   （業種プリセットが宿泊のまま次の spec へ残ると、他の spec が一斉に壊れるため）。
 * - 予約は確定させない（確認画面まで）。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
	getServiceMenuId,
} from '../utils/helpers';
import { selectAvailableCalendarDay } from '../utils/calendar-helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E577';
const OPTION_NAME = 'vkbm_provider_settings';

// 泊のプランの料金（1名1泊あたり）。
const NIGHT_BASE_PRICE = 10000;
// 泊数（チェックイン日 = 今日 + CHECK_IN_OFFSET 日、チェックアウト日 = チェックイン + NIGHTS 日）。
const CHECK_IN_OFFSET = 2;
const NIGHTS = 2;

let originalSettingsJson: string | null = null;
let roomAId = '';
let roomBId = '';
let nightMenuId = '';
let slotMenuId = '';
// 販売日の手動切り替えの確認（#743）で使う、専用の客室・時間枠のプラン・シフト。
let roomCId = '';
let slotMenuCId = '';
let switchedShiftId = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP コード（最後に ID を echo すること）
 * @param label エラーメッセージ用の名前
 * @return 作成した post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 客室（リソース）を1件作成する。
 *
 * @param title 客室名
 * @return 客室の post ID
 */
function seedRoom( title: string ): string {
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		echo $id;
	`,
		title
	);
}

/**
 * サービスメニュー（プラン）を1件作成し、担当の客室を割り当てる。
 *
 * @param title   プラン名
 * @param isNight 泊単位にするか（false は時間枠）
 * @param roomIds 担当の客室 ID
 * @return プランの post ID
 */
function seedMenu( title: string, isNight: boolean, roomIds: string[] ): string {
	const ids = roomIds.map( ( id ) => Number( id ) ).join( ', ' );
	const unitLines = isNight
		? `update_post_meta( $id, '_vkbm_booking_unit', 'night' );`
		: `update_post_meta( $id, '_vkbm_duration_minutes', 60 );`;
	return insertAndGetId(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
		update_post_meta( $id, '_vkbm_staff_ids', array( ${ ids } ) );
		update_post_meta( $id, '_vkbm_base_price', ${ NIGHT_BASE_PRICE } );
		${ unitLines }
		echo $id;
	`,
		title
	);
}

/**
 * このspecで作ったデータを接頭辞を手がかりに削除する（予約 → シフト → プラン → 客室の順）。
 * 客室 ID が控えられていない（beforeAll が途中で失敗した）場合でも消せるよう、タイトルで探す。
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
		// 対象の客室・プランに紐づく予約を先に削除する（リソース削除ガード対策）。
		$meta_or = array( 'relation' => 'OR' );
		if ( $menus ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_service_id', 'value' => $menus, 'compare' => 'IN' );
		}
		if ( $rooms ) {
			$meta_or[] = array( 'key' => '_vkbm_booking_resource_id', 'value' => $rooms, 'compare' => 'IN' );
		}
		if ( count( $meta_or ) > 1 ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_or,
			) ) as $bid ) { wp_delete_post( $bid, true ); }
		}
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
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ（元から option が無かった）option を削除して戻す。
 */
function restoreProviderSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs(
			[ 'option', 'update', OPTION_NAME, originalSettingsJson, '--format=json' ],
			{ stdio: 'pipe' }
		);
		return;
	}
	// 控えが無い = 元は option が無かったので、option ごと消して元の状態へ戻す。
	// option が未作成だと WP-CLI がエラー終了するため、その場合は無視する。
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
	} catch {
		// 元から無い（削除済み）なら何もしない。
	}
}

/**
 * 保存済みの業種プリセットのキーを返す。
 *
 * @return 業種プリセットのキー（未設定は空文字）
 */
function getStoredIndustryPreset(): string {
	return wpEvalPhp( `
		$settings = get_option( '${ OPTION_NAME }', array() );
		echo is_array( $settings ) ? (string) ( $settings['industry_preset'] ?? '' ) : '';
	` ).trim();
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
 * 宿泊のカレンダーで、指定した日をクリックする（表示月が違えば翌月へ送る）。
 *
 * @param page Playwright の Page
 * @param date 選ぶ日（Asia/Tokyo の年・月・日）
 */
async function clickStayDay(
	page: Page,
	date: { year: number; month: number; day: number }
) {
	const calendar = page.locator( '.vkbm-stay-calendar' );
	await calendar.waitFor( { state: 'visible', timeout: 15000 } );

	// 表示月の見出し（例: 2026年10月）が目的の月になるまで翌月へ送る（最大3回）。
	const monthPattern = new RegExp(
		`${ date.year }\\D+0?${ date.month }\\D`
	);
	for ( let i = 0; i < 3; i++ ) {
		const heading = ( await calendar
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
 * 予約ページでプランを開く。
 *
 * @param page   Playwright の Page
 * @param menuId 対象プランの post ID
 */
async function openMenu( page: Page, menuId: string ) {
	await page.goto( `/booking/?menu_id=${ menuId }` );
	await page.waitForLoadState( 'networkidle' );
}

/**
 * 確認画面のサマリの表示を待つ。
 *
 * @param page Playwright の Page
 */
async function waitForConfirm( page: Page ) {
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } );
	await page
		.locator( '.vkbm-confirm__summary' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
}

test.describe.serial( '宿泊モード（業種プリセット「宿泊」）の予約の流れ', () => {
	test.beforeAll( () => {
		// BM 設定を控える（afterAll で必ず戻す）。
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		roomAId = seedRoom( `${ PREFIX } Room A` );
		roomBId = seedRoom( `${ PREFIX } Room B` );
		// 当月＋翌月のシフト（泊の販売日・時間枠のスロットの両方に使う。月境界でも残るよう両方）。
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomAId, year, month );
			createShiftForMonth( roomBId, year, month );
		}
		nightMenuId = seedMenu( `${ PREFIX } Night Plan`, true, [
			roomAId,
			roomBId,
		] );
		slotMenuId = seedMenu( `${ PREFIX } Slot Plan`, false, [ roomAId ] );
	} );

	test.afterAll( () => {
		// 片付けが例外で止まっても、BM 設定は必ず元へ戻す（次の spec への状態漏れ防止）。
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '管理画面: 業種プリセットの選択肢に「宿泊」が出て、選んで保存でき、サービスメニューに「予約の単位」が出る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// 保存前は宿泊ではない（既存サイトと同じ状態から始める）。
		expect( getStoredIndustryPreset() ).not.toBe( 'lodging' );

		// 業種プリセットは「システム」タブにある。
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		const select = page.locator( '#vkbm-industry-preset' );
		await expect( select ).toBeVisible();

		// 選択肢の並び（既存の4つの後ろに「宿泊」が加わる）。
		const values = await select
			.locator( 'option' )
			.evaluateAll( ( options ) =>
				options.map( ( option ) => ( option as HTMLOptionElement ).value )
			);
		expect( values ).toEqual( [
			'custom',
			'salon',
			'activity',
			'seminar',
			'lodging',
		] );

		// 「宿泊」を選んで保存する。
		await select.selectOption( 'lodging' );
		await page.locator( '#submit' ).click();
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '#vkbm-industry-preset' ) ).toHaveValue(
			'lodging'
		);
		expect( getStoredIndustryPreset() ).toBe( 'lodging' );

		// サービスメニューの編集画面に「予約の単位」（時間枠／泊）が出て、泊のプランは「泊」が選ばれている。
		await page.goto(
			`/wp-admin/post.php?post=${ nightMenuId }&action=edit`
		);
		const unitField = page.locator( '#vkbm-booking-unit-field' );
		await expect( unitField ).toBeAttached( { timeout: 30000 } );
		await expect(
			unitField.locator(
				'input[name="vkbm_service_menu[booking_unit]"][value="night"]'
			)
		).toBeChecked();
	} );

	test( '予約画面（泊のプラン）: 日付と客室を選んで確認画面まで進み、チェックイン／チェックアウト・泊数・客室が出る', async ( {
		page,
	} ) => {
		await openMenu( page, nightMenuId );

		// 泊のプランでは宿泊用の画面が出て、時間枠の選択（スロット）は出ない。
		await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
			timeout: 15000,
		} );
		await expect( page.locator( '.vkbm-slot-list__item' ) ).toHaveCount(
			0
		);

		// チェックイン日・チェックアウト日を選ぶ。
		const checkIn = tokyoDateAfter( CHECK_IN_OFFSET );
		const checkOut = tokyoDateAfter( CHECK_IN_OFFSET + NIGHTS );
		await clickStayDay( page, checkIn );
		await clickStayDay( page, checkOut );

		// 空いている客室（2室）が出て、1室を選ぶ。
		const rooms = page.locator( '.vkbm-lodging__room' );
		await expect( rooms ).toHaveCount( 2, { timeout: 15000 } );
		await expect( rooms.first() ).toContainText( `${ NIGHTS }泊` );
		await rooms.filter( { hasText: `${ PREFIX } Room A` } ).click();

		// 選択中のサマリに泊数が出る。
		await expect( page.locator( '.vkbm-lodging__summary' ) ).toContainText(
			`${ NIGHTS }泊`
		);

		// 「予約に進む」で確認画面へ。
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await waitForConfirm( page );

		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( confirm ).toContainText( 'チェックイン' );
		await expect( confirm ).toContainText( 'チェックアウト' );
		await expect( confirm ).toContainText( `${ NIGHTS }泊` );
		await expect( confirm ).toContainText( `${ PREFIX } Room A` );
		// チェックイン／チェックアウトの日付・時刻。表記はサイトの日付・時刻形式に従う
		// （例: 「10月 5, 2026(月) 3:00 pm」「2026年10月5日(月) 3:00 PM」。24時間表記のサイトでは 15:00）ため、
		// 月日と時刻は表記ゆれを許す正規表現で確かめる。既定の時刻はチェックイン 15:00・チェックアウト 10:00。
		// 「メニュー」「客室」と同じ行の形（.vkbm-confirm__summary-item）で、サマリの先頭 2 行に並ぶ。
		const rows = confirm.locator( '.vkbm-confirm__summary-item' );
		const checkInRow = rows.nth( 0 );
		const checkOutRow = rows.nth( 1 );
		await expect(
			checkInRow.locator( '.vkbm-confirm__summary-item-title' )
		).toHaveText( 'チェックイン' );
		await expect(
			checkOutRow.locator( '.vkbm-confirm__summary-item-title' )
		).toHaveText( 'チェックアウト' );
		const checkInValue = checkInRow.locator(
			'.vkbm-confirm__summary-item-value'
		);
		const checkOutValue = checkOutRow.locator(
			'.vkbm-confirm__summary-item-value'
		);
		await expect( checkInValue ).toContainText(
			new RegExp(
				`(?:\\d{4}年)?${ checkIn.month }月\\s*${ checkIn.day }\\D`
			)
		);
		await expect( checkOutValue ).toContainText(
			new RegExp(
				`(?:\\d{4}年)?${ checkOut.month }月\\s*${ checkOut.day }\\D`
			)
		);
		await expect( checkInValue ).toContainText( /(15:00|3:00\s*pm)/i );
		await expect( checkOutValue ).toContainText( /10:00/i );
	} );

	test( '予約画面（泊のプラン）: 選択中の客室は通常時・ホバー中・押下中のどれでも淡い選択色の背景になる（issue #844）', async ( {
		page,
	} ) => {
		await openMenu( page, nightMenuId );
		await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
			timeout: 15000,
		} );

		await clickStayDay( page, tokyoDateAfter( CHECK_IN_OFFSET ) );
		await clickStayDay(
			page,
			tokyoDateAfter( CHECK_IN_OFFSET + NIGHTS )
		);

		const rooms = page.locator( '.vkbm-lodging__room' );
		await expect( rooms ).toHaveCount( 2, { timeout: 15000 } );
		const selected = rooms.filter( { hasText: `${ PREFIX } Room A` } );
		const other = rooms.filter( { hasText: `${ PREFIX } Room B` } );
		await selected.click();
		await expect( selected ).toHaveAttribute( 'aria-pressed', 'true' );

		const background = ( locator: typeof selected ) =>
			locator.evaluate(
				( el ) => window.getComputedStyle( el ).backgroundColor
			);
		const isWhiteOrClear = ( color: string ) =>
			color === 'rgb(255, 255, 255)' || color === 'rgba(0, 0, 0, 0)';

		// 通常時: 選択中は白ではなく、選択していない客室は白のまま。
		await page.mouse.move( 0, 0 );
		expect( isWhiteOrClear( await background( selected ) ) ).toBe( false );
		expect( await background( other ) ).toBe( 'rgb(255, 255, 255)' );
		const normal = await background( selected );

		// ホバー中・押下中も同じ選択色のまま（テーマのホバー色で透明や白に変わらない）。
		await selected.hover();
		expect( await background( selected ) ).toBe( normal );
		await page.mouse.down();
		expect( await background( selected ) ).toBe( normal );
		await page.mouse.up();
	} );

	test( '予約画面（時間枠のプラン）: 宿泊プリセットのサイトでも従来どおり日付 → 時間枠 → 確認画面の流れ', async ( {
		page,
	} ) => {
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await page.goto( `/booking/?menu_id=${ slotMenuId }` );
		await page.waitForLoadState( 'networkidle' );

		// 宿泊用の画面は出ない。
		await expect( page.locator( '.vkbm-lodging' ) ).toHaveCount( 0 );

		await selectAvailableCalendarDay( page, calendarMetaResponse );
		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		await slots.first().click();

		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await waitForConfirm( page );
		const confirm = page.locator( '.vkbm-confirm__summary' ).first();
		// 泊の予約の表示（チェックイン／チェックアウト・泊数）は出ない。
		await expect( confirm ).not.toContainText( 'チェックアウト' );
		await expect( confirm ).not.toContainText( `${ NIGHTS }泊` );
		// チェックイン／チェックアウトの行（.vkbm-confirm__summary-item）は出ない。
		await expect(
			confirm.locator( '.vkbm-confirm__summary-item-title', {
				hasText: /^チェックイン$/,
			} )
		).toHaveCount( 0 );
	} );

	test( '予約画面（時間枠のプラン）: 販売日の画面で「販売しない」から「販売する」へ切り替えた日に、基本営業時間の時間帯で予約枠が出る', async ( {
		page,
	} ) => {
		// 切り替える日（今日から3日後）の年・月・日。
		const target = tokyoDateAfter( 3 );

		// 基本営業時間（09:00-18:00）を登録する（BM 設定のその他の値は変えない。afterAll で元へ戻る）。
		wpEvalPhp( `
			$settings = get_option( '${ OPTION_NAME }', array() );
			$settings['provider_business_hours_basic'] = array( array( 'start' => '09:00', 'end' => '18:00' ) );
			update_option( '${ OPTION_NAME }', $settings );
			echo 'ok';
		` );

		// 専用の客室とプランを作り、対象の月のシフトを「全日 販売しない（時間帯なし）」で用意する。
		roomCId = seedRoom( `${ PREFIX } Room C` );
		slotMenuCId = seedMenu( `${ PREFIX } Slot Plan C`, false, [ roomCId ] );
		switchedShiftId = insertAndGetId(
			`
			$days = array();
			$last = (int) gmdate( 't', gmmktime( 0, 0, 0, ${ target.month }, 1, ${ target.year } ) );
			for ( $d = 1; $d <= $last; $d++ ) {
				$days[ $d ] = array( 'status' => 'unavailable', 'slots' => array() );
			}
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Shift C',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_shift_resource_id', ${ roomCId } );
			update_post_meta( $id, '_vkbm_shift_year', ${ target.year } );
			update_post_meta( $id, '_vkbm_shift_month', ${ target.month } );
			update_post_meta( $id, '_vkbm_shift_days', $days );
			echo $id;
		`,
			'switched shift'
		);

		// 切り替え前: 全日が販売しないので、予約画面に予約枠のある日は無い。
		await openMenu( page, slotMenuCId );
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );
		await expect(
			page.locator( '.vkbm-calendar__day--available' )
		).toHaveCount( 0 );

		// 管理画面の販売日の画面で、対象の日だけ「販売する」に切り替えて保存する。
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ switchedShiftId }&action=edit`
		);
		const saleCheckbox = page.locator(
			`.vkbm-shift-lodging-row[data-day="${ target.day }"] .vkbm-shift-lodging-sale`
		);
		await expect( saleCheckbox ).not.toBeChecked( { timeout: 30000 } );
		await saleCheckbox.check();
		await page.locator( '#publish' ).click();
		await page.waitForLoadState( 'networkidle' );
		await expect( saleCheckbox ).toBeChecked();

		// 保存された日は「販売する」で、基本営業時間の時間帯が入っている。
		const savedDay = wpEvalPhp( `
			$days = get_post_meta( ${ switchedShiftId }, '_vkbm_shift_days', true );
			echo wp_json_encode( $days[ ${ target.day } ] ?? null );
		` ).trim();
		expect( JSON.parse( savedDay ) ).toEqual( {
			status: 'open',
			slots: [ { start: '09:00', end: '18:00' } ],
		} );

		// 予約画面: 切り替えた日に予約枠が出る（ほかの日は販売しないままなので、空きのある日はこの日だけ）。
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await openMenu( page, slotMenuCId );
		await selectAvailableCalendarDay( page, calendarMetaResponse );
		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		expect( await slots.count() ).toBeGreaterThan( 0 );
	} );

	test( '既存サイト（業種プリセット「カスタム」）: 時間枠のプランの予約が従来どおり進み、管理画面に「予約の単位」は出ない', async ( {
		page,
	} ) => {
		// 業種プリセットを「カスタム」へ戻す（BM 設定のその他の値は変えない）。
		wpEvalPhp( `
			$settings = get_option( '${ OPTION_NAME }', array() );
			$settings['industry_preset'] = 'custom';
			update_option( '${ OPTION_NAME }', $settings );
			echo 'ok';
		` );

		// 管理画面: サービスメニュー編集に「予約の単位」は出ない。
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ slotMenuId }&action=edit`
		);
		await expect( page.locator( '#vkbm-duration-field' ) ).toBeAttached( {
			timeout: 30000,
		} );
		await expect( page.locator( '#vkbm-booking-unit-field' ) ).toHaveCount(
			0
		);

		// 予約画面: 全体設定の既存プラン（global-setup の Service Menu 1）が、確認画面まで進む。
		const defaultMenuId = getServiceMenuId();
		const calendarMetaResponse = page.waitForResponse(
			( res ) => /calendar-meta/.test( res.url() ),
			{ timeout: 15000 }
		);
		await page.goto( `/booking/?menu_id=${ defaultMenuId }` );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '.vkbm-lodging' ) ).toHaveCount( 0 );
		await selectAvailableCalendarDay( page, calendarMetaResponse );
		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		await slots.first().click();
		await page.locator( '.vkbm-plan-summary__action' ).first().click();
		await waitForConfirm( page );
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).not.toContainText( 'チェックアウト' );
	} );
} );
