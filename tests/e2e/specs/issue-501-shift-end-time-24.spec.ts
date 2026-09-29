import { test, expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import {
	loginAsAdmin,
	getStaffId,
	getServiceMenuId,
	getTokyoDateParts,
	createShiftForMonth,
	wpCliArgs,
	wpEvalPhp,
} from '../utils/helpers';

/**
 * Issue #501: シフト編集画面で終了時刻 24:00 を保存できない不具合の確認。
 * Issue #501: the shift editor could not save an end time of 24:00.
 *
 * 確認すること:
 * 1. 15:00〜24:00 を保存して開き直すと、そのまま残る（24:00 以外の時間帯も今までどおり残る）
 * 2. 終了（時）を 24 にすると終了（分）が 00 になり、10〜50 は選べない（disabled）。
 *    23 に戻すと全部選べる。「時間帯を追加」で増やした行でも同じ
 * 3. 予約ページ（空き枠 API と画面）で 24:00 まで空き枠が出る（所要 60 分なら 23:00 開始の枠）
 *
 * 1 → 3 の順に同じシフトを使うため serial で実行する。
 */

// 対象は翌月のシフト（global-setup で当月・翌月分が作成済み）。
// 当月だと実行日によっては対象日が過去日になるため、翌月の固定日を使う。
const tokyoNow = getTokyoDateParts( new Date() );
const currentYear = Number.parseInt( tokyoNow.year, 10 );
const currentMonth = Number.parseInt( tokyoNow.month, 10 );
const TARGET_MONTH = currentMonth === 12 ? 1 : currentMonth + 1;
const TARGET_YEAR = currentMonth === 12 ? currentYear + 1 : currentYear;

// 15:00〜24:00 を保存する日
const DAY_END_24 = 10;
// デグレ確認用に 10:30〜20:50 を保存する日
const DAY_REGULAR = 11;
// 何も変更しない日（既定の 09:00〜18:00 のまま）
const DAY_UNTOUCHED = 12;

/**
 * YYYY-MM-DD 形式の対象日
 *
 * @param day 対象月の日（1〜31）
 */
const targetDate = ( day: number ): string =>
	`${ TARGET_YEAR }-${ String( TARGET_MONTH ).padStart( 2, '0' ) }-${ String(
		day
	).padStart( 2, '0' ) }`;

let staffId = '';
let menuId = '';
let shiftId = '';
// テスト後に元へ戻すため、変更前のメニュー設定を控えておく
let originalMenuMeta = '';

/**
 * 対象月のシフト投稿 ID を取得する。
 * Resolve the shift post ID for the target staff / month.
 */
const findShiftId = (): string => {
	const id = wpEvalPhp( `
		$ids = get_posts( array(
			'post_type'   => 'vkbm_shift',
			'post_status' => 'any',
			'fields'      => 'ids',
			'meta_query'  => array(
				array( 'key' => '_vkbm_shift_resource_id', 'value' => ${ Number( staffId ) } ),
				array( 'key' => '_vkbm_shift_year', 'value' => ${ TARGET_YEAR } ),
				array( 'key' => '_vkbm_shift_month', 'value' => ${ TARGET_MONTH } ),
			),
		) );
		echo $ids ? (int) $ids[0] : 0;
	` ).trim();
	if ( ! /^\d+$/.test( id ) || Number( id ) <= 0 ) {
		throw new Error( `Shift post not found: "${ id }"` );
	}
	return id;
};

/**
 * 保存済みシフトの指定日の時間帯を DB から読む。
 * Read the saved slots of a day from post meta.
 *
 * @param day 対象月の日（1〜31）
 */
const readSavedSlots = (
	day: number
): Array< { start: string; end: string } > => {
	const json = wpEvalPhp( `
		$days = get_post_meta( ${ Number( shiftId ) }, '_vkbm_shift_days', true );
		$day  = is_array( $days ) && isset( $days[ ${ day } ] ) ? $days[ ${ day } ] : array();
		echo wp_json_encode( isset( $day['slots'] ) ? $day['slots'] : array() );
	` ).trim();
	return JSON.parse( json || '[]' );
};

/**
 * 指定日の行
 *
 * @param page Playwright の Page
 * @param day  対象月の日（1〜31）
 */
const dayRow = ( page: Page, day: number ): Locator =>
	page.locator( `.vkbm-shift-day-row[data-day="${ day }"]` );

/**
 * 行内の時間帯（.vkbm-shift-slot）の各プルダウン
 *
 * @param slot 時間帯の行（.vkbm-shift-slot）
 */
const slotSelects = ( slot: Locator ) => ( {
	startHour: slot.locator( 'select[data-field="start_hour"]' ),
	startMinute: slot.locator( 'select[data-field="start_minute"]' ),
	endHour: slot.locator( 'select[data-field="end_hour"]' ),
	endMinute: slot.locator( 'select[data-field="end_minute"]' ),
} );

/**
 * 時間帯を「HH:MM-HH:MM」の文字列で読む（比較しやすくするため）
 *
 * @param slot 時間帯の行（.vkbm-shift-slot）
 */
const readSlot = async ( slot: Locator ): Promise< string > => {
	const s = slotSelects( slot );
	return `${ await s.startHour.inputValue() }:${ await s.startMinute.inputValue() }-${ await s.endHour.inputValue() }:${ await s.endMinute.inputValue() }`;
};

/**
 * 終了（分）プルダウンで disabled になっている選択肢の値一覧
 *
 * @param slot 時間帯の行（.vkbm-shift-slot）
 */
const disabledMinuteValues = async ( slot: Locator ): Promise< string[] > =>
	slotSelects( slot ).endMinute.evaluate( ( el ) =>
		Array.from( ( el as HTMLSelectElement ).options )
			.filter( ( o ) => o.disabled )
			.map( ( o ) => o.value )
	);

/**
 * シフト編集画面を開き、日ごとの行が描画されるまで待つ
 *
 * @param page Playwright の Page
 */
const openShiftEditor = async ( page: Page ) => {
	await page.goto( `/wp-admin/post.php?post=${ shiftId }&action=edit` );
	await dayRow( page, DAY_END_24 )
		.locator( '.vkbm-shift-slot' )
		.first()
		.waitFor( { state: 'visible', timeout: 15000 } );
};

test.describe.serial( 'Issue #501: シフトの終了時刻 24:00', () => {
	test.beforeAll( () => {
		staffId = getStaffId();
		menuId = getServiceMenuId();

		// 対象月のシフトを全日 09:00〜18:00 に戻しておく（前回実行の影響を消す）
		createShiftForMonth( staffId, TARGET_YEAR, TARGET_MONTH );
		shiftId = findShiftId();

		// メニューを「所要 60 分・後ろ余白 0 分・開始時刻を固定しない・予約受付期間は無制限」にする。
		// 予約受付期間（何日先まで受け付けるか）を 0（無制限）にするのは、翌月の対象日が
		// 受付期間の外になって枠が出ない、という検証と無関係な失敗を避けるため。
		// 変更前の値は afterAll で戻せるように控える。
		originalMenuMeta = wpEvalPhp( `
			$keys = array( '_vkbm_duration_minutes', '_vkbm_buffer_after_minutes', '_vkbm_fixed_start_times', '_vkbm_max_advance_booking_days' );
			$out  = array();
			foreach ( $keys as $k ) {
				$out[ $k ] = metadata_exists( 'post', ${ Number( menuId ) }, $k )
					? get_post_meta( ${ Number( menuId ) }, $k, true )
					: null;
			}
			echo wp_json_encode( $out );
		` ).trim();
		wpEvalPhp( `
			update_post_meta( ${ Number( menuId ) }, '_vkbm_duration_minutes', 60 );
			update_post_meta( ${ Number( menuId ) }, '_vkbm_buffer_after_minutes', 0 );
			update_post_meta( ${ Number( menuId ) }, '_vkbm_max_advance_booking_days', 0 );
			delete_post_meta( ${ Number( menuId ) }, '_vkbm_fixed_start_times' );
		` );
	} );

	test.afterAll( () => {
		// メニュー設定を元に戻し、シフトも全日 09:00〜18:00 に戻す（他 spec への影響を避ける）。
		// beforeAll が ID を取る前に失敗した場合は、復元処理の例外で本来の失敗理由が
		// 隠れないよう、ID が無い復元は飛ばす。
		if ( menuId && originalMenuMeta ) {
			const b64 = Buffer.from( originalMenuMeta ).toString( 'base64' );
			wpEvalPhp( `
				$orig = json_decode( base64_decode( '${ b64 }' ), true );
				foreach ( (array) $orig as $k => $v ) {
					if ( null === $v ) {
						delete_post_meta( ${ Number( menuId ) }, $k );
					} else {
						update_post_meta( ${ Number( menuId ) }, $k, $v );
					}
				}
			` );
		}
		if ( staffId ) {
			createShiftForMonth( staffId, TARGET_YEAR, TARGET_MONTH );
		}
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test( '15:00〜24:00 を保存して開き直すと、そのまま残る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await openShiftEditor( page );

		// 10日: 15:00〜24:00 にする
		const slot24 = slotSelects(
			dayRow( page, DAY_END_24 ).locator( '.vkbm-shift-slot' ).first()
		);
		await slot24.startHour.selectOption( '15' );
		await slot24.startMinute.selectOption( '00' );
		await slot24.endHour.selectOption( '24' );
		await slot24.endMinute.selectOption( '00' );

		// 11日: デグレ確認として 10:30〜20:50 にする
		const slotRegular = slotSelects(
			dayRow( page, DAY_REGULAR ).locator( '.vkbm-shift-slot' ).first()
		);
		await slotRegular.startHour.selectOption( '10' );
		await slotRegular.startMinute.selectOption( '30' );
		await slotRegular.endHour.selectOption( '20' );
		await slotRegular.endMinute.selectOption( '50' );

		// 「更新」を押して保存し、保存後の画面遷移を待つ
		await Promise.all( [
			page.waitForURL( /post\.php\?post=\d+&action=edit&message=/, {
				timeout: 30000,
			} ),
			page.locator( '#publish' ).click(),
		] );

		// 編集画面を開き直す
		await openShiftEditor( page );

		// 画面上の値: 10日は 15:00〜24:00、11日は 10:30〜20:50、12日は既定の 09:00〜18:00 のまま
		await expect(
			dayRow( page, DAY_END_24 ).locator( '.vkbm-shift-slot' )
		).toHaveCount( 1 );
		expect(
			await readSlot(
				dayRow( page, DAY_END_24 ).locator( '.vkbm-shift-slot' ).first()
			)
		).toBe( '15:00-24:00' );
		expect(
			await readSlot(
				dayRow( page, DAY_REGULAR )
					.locator( '.vkbm-shift-slot' )
					.first()
			)
		).toBe( '10:30-20:50' );
		expect(
			await readSlot(
				dayRow( page, DAY_UNTOUCHED )
					.locator( '.vkbm-shift-slot' )
					.first()
			)
		).toBe( '09:00-18:00' );

		// DB に保存された値も確認する（画面の既定値表示と取り違えないため）
		expect( readSavedSlots( DAY_END_24 ) ).toEqual( [
			{ start: '15:00', end: '24:00' },
		] );
		expect( readSavedSlots( DAY_REGULAR ) ).toEqual( [
			{ start: '10:30', end: '20:50' },
		] );
		expect( readSavedSlots( DAY_UNTOUCHED ) ).toEqual( [
			{ start: '09:00', end: '18:00' },
		] );
	} );

	test( '終了（時）を 24 にすると分は 00 に固定され、23 に戻すと全部選べる', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await openShiftEditor( page );

		// 保存済みの 24:00 の行（初回表示）でも、分は 00 のみ選べる
		const loaded24 = dayRow( page, DAY_END_24 )
			.locator( '.vkbm-shift-slot' )
			.first();
		expect( await disabledMinuteValues( loaded24 ) ).toEqual( [
			'10',
			'20',
			'30',
			'40',
			'50',
		] );

		// 12日: 15:00〜23:30 にしてから、終了（時）だけを 24 に変える
		const slotEl = dayRow( page, DAY_UNTOUCHED )
			.locator( '.vkbm-shift-slot' )
			.first();
		const slot = slotSelects( slotEl );
		await slot.startHour.selectOption( '15' );
		await slot.endHour.selectOption( '23' );
		await slot.endMinute.selectOption( '30' );
		expect( await disabledMinuteValues( slotEl ) ).toEqual( [] );

		await slot.endHour.selectOption( '24' );
		// 分が 00 になり、10〜50 は選べない
		await expect( slot.endMinute ).toHaveValue( '00' );
		expect( await disabledMinuteValues( slotEl ) ).toEqual( [
			'10',
			'20',
			'30',
			'40',
			'50',
		] );

		// 終了（時）を 23 に戻すと全部選べる（値は 00 のまま）
		await slot.endHour.selectOption( '23' );
		await expect( slot.endMinute ).toHaveValue( '00' );
		expect( await disabledMinuteValues( slotEl ) ).toEqual( [] );

		// 「時間帯を追加」で行を増やし、その行の終了（時）を 24 にしても同じ
		await dayRow( page, DAY_UNTOUCHED )
			.locator( '.vkbm-shift-add-slot' )
			.click();
		await expect(
			dayRow( page, DAY_UNTOUCHED ).locator( '.vkbm-shift-slot' )
		).toHaveCount( 2 );
		const addedEl = dayRow( page, DAY_UNTOUCHED )
			.locator( '.vkbm-shift-slot' )
			.last();
		const added = slotSelects( addedEl );
		await added.endMinute.selectOption( '30' );
		await added.endHour.selectOption( '24' );
		await expect( added.endMinute ).toHaveValue( '00' );
		expect( await disabledMinuteValues( addedEl ) ).toEqual( [
			'10',
			'20',
			'30',
			'40',
			'50',
		] );

		// 保存用の隠し項目にも 24:00 で入っている（24:30 などの不正値にならない）
		const daysJson = await page
			.locator( '#vkbm-shift-days-json' )
			.inputValue();
		const days = JSON.parse( daysJson );
		const day12 = days[ String( DAY_UNTOUCHED ) ] ?? days[ DAY_UNTOUCHED ];
		expect(
			( day12?.slots ?? [] ).some(
				( s: { end: string } ) => s.end === '24:00'
			)
		).toBe( true );
	} );

	test( '予約ページで 24:00 まで空き枠が出る（所要 60 分なら 23:00 開始の枠）', async ( {
		page,
	} ) => {
		// 空き枠 API のキャッシュを消してから取得する
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
		const res = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ targetDate(
				DAY_END_24
			) }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`
		);
		expect( res.ok() ).toBe( true );
		const data = await res.json();
		const starts: string[] = (
			Array.isArray( data?.slots ) ? data.slots : []
		).map( ( s: { start_at: string } ) => String( s.start_at ) );

		// 15:00 開始の枠から 23:00 開始の枠まである
		expect( starts.some( ( s ) => s.endsWith( 'T15:00:00+09:00' ) ) ).toBe(
			true
		);
		expect( starts.some( ( s ) => s.endsWith( 'T23:00:00+09:00' ) ) ).toBe(
			true
		);

		// 画面でも確認する: 予約ページで対象日を選ぶと 23:00 の枠が表示される
		await page.goto( '/booking/' );
		// 対象メニュー（menuId）の予約ボタンに絞る。予約ページ未設定の環境では
		// href が menu_id を含まないメニューのパーマリンクになるため、href ではなく
		// カードの data-menu-id で特定する（値の完全一致なので 12 と 123 も区別できる）。
		const reserveLink = page
			.locator(
				`.vkbm-menu-loop__item[data-menu-id="${ menuId }"] a.vkbm-menu-loop__button--reserve`
			)
			.first();
		await reserveLink.waitFor( { state: 'visible', timeout: 15000 } );
		await reserveLink.click();
		await page
			.locator( '.vkbm-calendar' )
			.first()
			.waitFor( { state: 'visible', timeout: 15000 } );

		// 対象日は翌月のため「次の月」へ進める（ナビの最後のボタンが「次の月」）
		await page.locator( '.vkbm-calendar__nav' ).last().click();
		const targetDay = page
			.locator(
				'.vkbm-calendar__day--available:not(.vkbm-calendar__day--muted)'
			)
			.filter( {
				has: page
					.locator( '.vkbm-calendar__day-label' )
					.filter( { hasText: new RegExp( `^${ DAY_END_24 }$` ) } ),
			} );
		await targetDay.first().waitFor( { state: 'visible', timeout: 15000 } );
		await targetDay.first().click();

		const slotItems = page.locator( '.vkbm-slot-list__item' );
		await slotItems.first().waitFor( { state: 'visible', timeout: 15000 } );
		// 「22:00 - 23:00」に一致しないよう、時刻表示が 23:00 で始まる枠を探す
		await expect(
			slotItems
				.filter( {
					has: page
						.locator( '.vkbm-slot-list__time' )
						.filter( { hasText: /^\s*23:00\s*-/ } ),
				} )
				.first()
		).toBeVisible();
	} );
} );
