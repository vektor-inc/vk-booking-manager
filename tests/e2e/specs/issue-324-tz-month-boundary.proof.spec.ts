/**
 * issue #324 の原因実証用スペック（4-0 ルール）。決定論的 red/green。
 *
 * 目的:
 * - 「ブラウザ TZ（UTC）とシフト seeding 月（Asia/Tokyo）のズレ」が、JST 月初に
 *   予約カレンダーの初期表示月を1か月前へずらし、空き枠0件を引き起こすことを実証する。
 * - 修正（playwright.config.ts の timezoneId: 'Asia/Tokyo'）でこのズレが解消することを示す。
 *
 * 手法:
 * - 単一の Asia/Tokyo「対象月」だけにシフトを投入する（その前月にはシフト無し）。
 * - page.clock で JST 月初の瞬間（= UTC では前月の月末夜）にブラウザ時計を固定する。
 * - 2 つのブラウザコンテキストで同じ瞬間・同じ seeding に対しカレンダーを開く:
 *   - RED:   timezoneId='UTC'       → 初期表示月 = 前月（シフト無し）→ 空き枠0件
 *   - GREEN: timezoneId='Asia/Tokyo'→ 初期表示月 = 対象月（シフトあり）→ 空き枠>0
 *
 * このスペックは検証用（CI の通常スイートには含めない想定。ファイル名 *.proof.spec.ts）。
 */
import { test, expect, Browser } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';

const PROOF_MENU_TITLE = 'Issue324 Proof Menu';

/**
 * 対象月（Asia/Tokyo）の「1日 00:30 JST」に相当する UTC の瞬間と、年・月を算出する。
 * UTC=+0、JST=+9 なので「JST 1日 00:30」= 「前日 15:30 UTC」。
 * この瞬間、UTC の日付は前月の月末、Tokyo の日付は対象月の1日になる。
 *
 * 実行時の実時刻に依存しないよう、現在の Tokyo 月の「2か月後」を対象月に採用する
 * （globalSetup が当月・翌月を埋めても、対象月とその前月の状態を本スペックが
 *  完全に制御できるようにするため）。
 */
function computeBoundaryFixture(): {
	targetYear: number;
	targetMonth: number;
	prevYear: number;
	prevMonth: number;
	fixedInstantISO: string;
} {
	// 現在の Tokyo 年月を取得。
	const now = new Date();
	const tokyoParts = new Intl.DateTimeFormat( 'en', {
		timeZone: 'Asia/Tokyo',
		year: 'numeric',
		month: '2-digit',
	} ).formatToParts( now );
	const curYear = Number.parseInt(
		tokyoParts.find( ( p ) => p.type === 'year' )?.value ?? '0',
		10
	);
	const curMonth = Number.parseInt(
		tokyoParts.find( ( p ) => p.type === 'month' )?.value ?? '0',
		10
	);

	// 対象月 = 現在の Tokyo 月の2か月後（年跨ぎを繰り上げる）。
	let targetMonth = curMonth + 2;
	let targetYear = curYear;
	while ( targetMonth > 12 ) {
		targetMonth -= 12;
		targetYear += 1;
	}

	// 対象月の前月（= RED で UTC が表示してしまう、シフト無しの月）。
	let prevMonth = targetMonth - 1;
	let prevYear = targetYear;
	if ( prevMonth < 1 ) {
		prevMonth = 12;
		prevYear -= 1;
	}

	// 固定する瞬間: 「対象月 1日 00:30 JST」= 「前月末日 15:30 UTC」。
	// 前月末日を求めるため Date.UTC で prevMonth の翌月0日を使う。
	const lastDayOfPrevMonth = new Date(
		Date.UTC( prevYear, prevMonth, 0 )
	).getUTCDate();
	// 15:30 UTC = 翌日（Tokyo）00:30 JST。
	const fixedInstant = new Date(
		Date.UTC( prevYear, prevMonth - 1, lastDayOfPrevMonth, 15, 30, 0 )
	);

	return {
		targetYear,
		targetMonth,
		prevYear,
		prevMonth,
		fixedInstantISO: fixedInstant.toISOString(),
	};
}

const fixture = computeBoundaryFixture();

/**
 * 全シフトを削除し、対象月（Asia/Tokyo）だけにシフトを投入する。
 * これにより「対象月にはシフトあり／その前月にはシフト無し」を保証する。
 *
 * @param staffId スタッフ（リソース）の post ID（数値）
 * @param year    シフトを投入する年（整数）
 * @param month   シフトを投入する月（1-12）
 */
function seedOnlyTargetMonth( staffId: number, year: number, month: number ) {
	const phpCode = `
		// 既存シフトを全削除（前月にシフトが残らないようにする）。
		$shifts = get_posts([
			'post_type' => 'vkbm_shift',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
		]);
		foreach ( $shifts as $sid ) { wp_delete_post( $sid, true ); }

		// 対象月のシフトのみ投入。
		$days_in_month = (int) date('t', mktime(0, 0, 0, ${ month }, 1, ${ year }));
		$days = [];
		for ($d = 1; $d <= $days_in_month; $d++) {
			$days[$d] = [ 'status' => 'open', 'slots' => [['start' => '09:00', 'end' => '18:00']] ];
		}
		$post_id = wp_insert_post([
			'post_type'   => 'vkbm_shift',
			'post_status' => 'publish',
			'post_title'  => sprintf('Proof %d-%02d', ${ year }, ${ month }),
		]);
		update_post_meta($post_id, '_vkbm_shift_resource_id', ${ staffId });
		update_post_meta($post_id, '_vkbm_shift_year', ${ year });
		update_post_meta($post_id, '_vkbm_shift_month', ${ month });
		update_post_meta($post_id, '_vkbm_shift_days', $days);
		echo $post_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) ) {
		throw new Error( `Proof shift seeding failed: "${ result }"` );
	}
}

/**
 * 全シフトを削除し、globalSetup と同じ「当月＋翌月（Asia/Tokyo 基準）」を再 seed する。
 *
 * 本 proof spec は seedOnlyTargetMonth で全シフトを消して対象月のみを残すため、
 * このまま終わると globalSetup（1回のみ実行）が用意した当月＋翌月シフトが失われ、
 * 後続 spec（pr-* 系など）が全シフト消失状態で動いて汚染される。afterAll でこの関数を
 * 呼び、共有 DB を globalSetup 直後と同じ状態へ復元する。
 *
 * @param staffId スタッフ（リソース）の post ID（数値）
 */
function restoreGlobalSetupShifts( staffId: number ): void {
	// まず全シフトを削除（対象月だけ残った状態を一掃する）。
	wpEvalPhp( `
		$shifts = get_posts([
			'post_type' => 'vkbm_shift',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
		]);
		foreach ( $shifts as $sid ) { wp_delete_post( $sid, true ); }
	` );

	// globalSetup と同じ共通ヘルパーで Asia/Tokyo 基準の当月＋翌月（年跨ぎ考慮）を再 seed する。
	for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
		createShiftForMonth( String( staffId ), year, month );
	}
}

/**
 * 専用メニューを作成し、共有スタッフを割り当てる（冪等）。
 *
 * @param staffId スタッフ（リソース）の post ID（数値）
 */
function seedProofMenu( staffId: number ): void {
	const phpCode = `
		$existing = get_posts([
			'post_type' => 'vkbm_service_menu',
			'post_status' => 'any',
			'title' => '${ PROOF_MENU_TITLE }',
			'fields' => 'ids',
			'numberposts' => -1,
		]);
		foreach ( $existing as $eid ) { wp_delete_post( $eid, true ); }
		$menu_id = wp_insert_post([
			'post_type' => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title' => '${ PROOF_MENU_TITLE }',
		]);
		update_post_meta($menu_id, '_vkbm_staff_ids', array((int)${ staffId }));
		update_post_meta($menu_id, '_vkbm_base_price', 3000);
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) ) {
		throw new Error( `Proof menu seeding failed: "${ result }"` );
	}
}

/**
 * 指定 TZ・固定時刻のコンテキストで予約カレンダーを開き、
 * 初期表示月ラベルと「掴める日（selectable）」の件数を返す。
 *
 * selectableCount は実 helper（selectAvailableCalendarDay）と同じ判定にする:
 * available（空き枠あり日）があればその件数、無ければ enabled 件数。
 * これにより「旧 helper が timeout する = selectable 0 件」を厳密に証明できる
 * （available のみだと、enabled フォールバックで掴めるケースを 0 と誤判定しうる）。
 *
 * @param browser    Playwright の Browser（newContext で TZ を切り替える）
 * @param timezoneId 適用するタイムゾーン（'UTC' / 'Asia/Tokyo'）
 * @return 初期表示月ラベルと selectable（available/enabled）日数
 */
async function openCalendar(
	browser: Browser,
	timezoneId: string
): Promise< { monthLabel: string; selectableCount: number } > {
	const context = await browser.newContext( {
		timezoneId,
		locale: 'ja-JP',
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
	} );
	const page = await context.newPage();
	// ブラウザ時計を JST 月初の瞬間に固定（React 初期描画の new Date() を制御）。
	await page.clock.setFixedTime( new Date( fixture.fixedInstantISO ) );

	await page.goto( '/booking/' );
	await page.waitForLoadState( 'networkidle' );

	// 専用メニューの予約ボタンを押してカレンダーを開く。
	const menuCard = page
		.locator( '.vkbm-menu-loop__item', { hasText: PROOF_MENU_TITLE } )
		.first();
	await menuCard
		.locator( '.vkbm-menu-loop__button--reserve' )
		.first()
		.click();

	// カレンダー表示とローディング収束を待つ。
	await page.waitForSelector( '.vkbm-calendar', {
		state: 'visible',
		timeout: 15000,
	} );
	await page
		.locator( '.vkbm-calendar__spinner' )
		.waitFor( { state: 'detached', timeout: 15000 } )
		.catch( () => {} );

	// 初期表示月ラベル（例: "2026年7月"）。
	const monthLabel = (
		await page.locator( '.vkbm-calendar__current' ).first().innerText()
	).trim();

	// 実 helper（selectAvailableCalendarDay）と同じ disabled 除外セレクタで
	// available 日と enabled 日を数える。
	const availableDays = page.locator(
		'.vkbm-calendar__day--available:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
	);
	const enabledDays = page.locator(
		'.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
	);
	// 件数が安定するまで少し待つ（meta ロード後の再レンダリング収束）。
	await page.waitForTimeout( 2000 );
	// helper と同じ優先順位: available があれば available 件数、無ければ enabled 件数。
	const availableCount = await availableDays.count();
	const selectableCount =
		availableCount > 0 ? availableCount : await enabledDays.count();

	await context.close();
	return { monthLabel, selectableCount };
}

test.describe( 'issue #324 原因実証: ブラウザTZ/seeding月ズレ', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( () => {
		const staffId = Number.parseInt( getStaffId(), 10 );
		seedOnlyTargetMonth( staffId, fixture.targetYear, fixture.targetMonth );
		seedProofMenu( staffId );
	} );

	test.afterAll( () => {
		// staffId を先頭で確定しておく（メニュー削除が throw しても finally で使う）。
		const staffId = Number.parseInt( getStaffId(), 10 );
		try {
			// 専用メニューを削除（共有スタッフはそのまま）。
			const ids = wpCliArgs(
				[
					'post',
					'list',
					'--post_type=vkbm_service_menu',
					`--title=${ PROOF_MENU_TITLE }`,
					'--post_status=any',
					'--format=ids',
				],
				{ stdio: 'pipe' }
			);
			if ( ids ) {
				for ( const id of ids.split( /\s+/ ).filter( Boolean ) ) {
					wpCliArgs( [ 'post', 'delete', id, '--force' ], {
						stdio: 'ignore',
					} );
				}
			}
		} finally {
			// 共有 DB のシフト状態を globalSetup 直後（当月＋翌月）へ必ず復元する。
			// メニュー削除が途中で throw しても finally で実行されるため、
			// seedOnlyTargetMonth で消した当月＋翌月シフトが復活せず後続 spec
			// （pr-* 系）が全シフト消失状態で動いて汚染される事態を防ぐ。
			restoreGlobalSetupShifts( staffId );
		}
	} );

	test( 'RED: ブラウザTZ=UTC では前月（シフト無し）を表示し空き枠0件', async ( {
		browser,
	} ) => {
		const { monthLabel, selectableCount } = await openCalendar(
			browser,
			'UTC'
		);
		// eslint-disable-next-line no-console
		console.log(
			`[RED utc] target=${ fixture.targetYear }/${ fixture.targetMonth } prev=${ fixture.prevYear }/${ fixture.prevMonth } displayedLabel="${ monthLabel }" selectableCount=${ selectableCount }`
		);
		// ja ロケールのラベルは "YYYY/M" 形式（calendar-grid.js の Intl.DateTimeFormat）。
		// UTC では前月を表示してしまう（＝対象月ではなく prev 月）。
		expect( monthLabel ).toBe(
			`${ fixture.prevYear }/${ fixture.prevMonth }`
		);
		// 前月にはシフトが無いため掴める日は0件（＝旧ヘルパーが timeout する条件）。
		// available だけでなく enabled フォールバックも含めて 0 であることを厳密に示す。
		expect( selectableCount ).toBe( 0 );
	} );

	test( 'GREEN: ブラウザTZ=Asia/Tokyo では対象月（シフトあり）を表示し空き枠>0', async ( {
		browser,
	} ) => {
		const { monthLabel, selectableCount } = await openCalendar(
			browser,
			'Asia/Tokyo'
		);
		// eslint-disable-next-line no-console
		console.log(
			`[GREEN tokyo] target=${ fixture.targetYear }/${ fixture.targetMonth } displayedLabel="${ monthLabel }" selectableCount=${ selectableCount }`
		);
		// Asia/Tokyo では対象月そのものを表示する（"YYYY/M" 形式）。
		expect( monthLabel ).toBe(
			`${ fixture.targetYear }/${ fixture.targetMonth }`
		);
		// 対象月にはシフトがあるため掴める日>0（修正後の挙動）。
		expect( selectableCount ).toBeGreaterThan( 0 );
	} );
} );
