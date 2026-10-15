/**
 * issue #324 の月境界回帰テスト。通常の CI スイートで実行する。
 *
 * JST 月初（UTC は前月末）にブラウザ時計とサイト日付を固定し、対象月だけに
 * シフトを投入する。メニュー選択時の表示月は provider-settings の site_today で
 * 決まるため、ブラウザ時計だけを変えて旧来の RED を期待すると実際の今月になる（#766）。
 * UTC・Asia/Tokyo のどちらでもサイト日付の月を表示し、空き日を選べることを検証する。
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

	// 対象月の前月（UTC のブラウザ時計が示す、シフト無しの月）。
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
 * 初期表示月ラベルと空き枠のある日数を返す。
 *
 * 空き枠のある日だけを数え、月が合っていてもシフトが表示されない退行を検出する。
 *
 * @param browser    Playwright の Browser（newContext で TZ を切り替える）
 * @param timezoneId 適用するタイムゾーン（'UTC' / 'Asia/Tokyo'）
 * @return 初期表示月ラベルと空き枠のある日数
 */
async function openCalendar(
	browser: Browser,
	timezoneId: string
): Promise< { monthLabel: string; availableDayCount: number } > {
	const context = await browser.newContext( {
		timezoneId,
		locale: 'ja-JP',
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
	} );
	const page = await context.newPage();
	// ブラウザ時計を JST 月初の瞬間に固定（React 初期描画の new Date() を制御）。
	await page.clock.setFixedTime( new Date( fixture.fixedInstantISO ) );

	// 設定全体は実レスポンスを使い、サイト日付だけを固定時計に合わせる。
	await page.route( '**/vkbm/v1/provider-settings*', async ( route ) => {
		const response = await route.fetch();
		expect( response.ok() ).toBe( true );
		const settings = await response.json();
		await route.fulfill( {
			response,
			json: {
				...settings,
				site_today: `${ fixture.targetYear }-${ String(
					fixture.targetMonth
				).padStart( 2, '0' ) }-01`,
			},
		} );
	} );
	// 時計の月が UTC では前月、東京では対象月であることも確認する。
	const browserMonth = await page.evaluate( () => ( {
		year: new Date().getFullYear(),
		month: new Date().getMonth() + 1,
	} ) );
	expect( browserMonth ).toEqual(
		timezoneId === 'UTC'
			? { year: fixture.prevYear, month: fixture.prevMonth }
			: { year: fixture.targetYear, month: fixture.targetMonth }
	);

	// 差し替え済みのサイト日付を受信してからメニューを選ぶ（遷移前に待機を登録）。
	const providerSettingsResponse = page.waitForResponse(
		'**/vkbm/v1/provider-settings*'
	);
	await page.goto( '/booking/' );
	await providerSettingsResponse;
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

	// 初期表示月ラベル（例: "2026/7"）。
	const monthLabel = (
		await page.locator( '.vkbm-calendar__current' ).first().innerText()
	).trim();

	// 空き枠のある日が描画されるまで待ち、単なる有効日の数で代用しない。
	const availableDays = page.locator(
		'.vkbm-calendar__day--available:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
	);
	await expect( availableDays.first() ).toBeVisible();
	const availableDayCount = await availableDays.count();

	await context.close();
	return { monthLabel, availableDayCount };
}

test.describe( 'issue #324 月境界: ブラウザTZとサイト日付', () => {
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

	for ( const timezoneId of [ 'UTC', 'Asia/Tokyo' ] ) {
		test( `ブラウザTZ=${ timezoneId } でもサイト日付の対象月と空き日を表示する`, async ( {
			browser,
		} ) => {
			const { monthLabel, availableDayCount } = await openCalendar(
				browser,
				timezoneId
			);
			// ブラウザの月が前月でも、サイト日付の対象月を表示する。
			expect( monthLabel ).toBe(
				`${ fixture.targetYear }/${ fixture.targetMonth }`
			);
			expect( availableDayCount ).toBeGreaterThan( 0 );
		} );
	}
} );
