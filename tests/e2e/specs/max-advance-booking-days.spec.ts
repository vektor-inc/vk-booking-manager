import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import {
	configureProviderSettings,
	disableEmailVerification,
} from '../utils/setup';

const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'password';

/**
 * 管理画面にログインするヘルパー関数。
 * WordPress のログイン画面を経由してログインし、ダッシュボードに到達する。
 */
const loginAsAdmin = async ( page: any ) => {
	// WordPress のログインページに直接遷移
	await page.goto( '/wp-login.php' );
	await page.waitForSelector( '#loginform', { timeout: 10000 } );

	// ユーザー名フィールドをクリアしてから入力
	await page.locator( '#user_login' ).fill( '' );
	await page.locator( '#user_login' ).type( ADMIN_USER );

	// パスワードフィールドをクリアしてから入力
	await page.locator( '#user_pass' ).fill( '' );
	await page.locator( '#user_pass' ).type( ADMIN_PASSWORD );

	// Remember Me にチェックを入れてからログインボタンをクリック
	await page.locator( '#rememberme' ).check();
	await Promise.all( [
		page.waitForNavigation( { timeout: 15000 } ),
		page.locator( '#wp-submit' ).click(),
	] );
};

/**
 * サービスメニューの post ID を取得するヘルパー。
 * wp-cli で最初のサービスメニューの ID を返す。
 */
const getServiceMenuId = (): string => {
	return execSync(
		'npx wp-env run cli wp post list --post_type=vkbm_service_menu --post_status=publish --field=ID --format=ids',
		{ encoding: 'utf-8' }
	).trim().split( /\s+/ )[ 0 ];
};

/**
 * Asia/Tokyo タイムゾーンの年・月・日を formatToParts() で取得するヘルパー。
 * locale 文字列のフォーマットに依存せず、安定して YYYY/MM/DD を返す。
 *
 * @param date Date オブジェクト
 * @return year, month, day の各文字列（ゼロパディング済み）
 */
const getTokyoDateParts = (
	date: Date
): { year: string; month: string; day: string } => {
	const parts = new Intl.DateTimeFormat( 'en', {
		timeZone: 'Asia/Tokyo',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).formatToParts( date );

	return {
		year: parts.find( ( part ) => part.type === 'year' )?.value ?? '',
		month: parts.find( ( part ) => part.type === 'month' )?.value ?? '',
		day: parts.find( ( part ) => part.type === 'day' )?.value ?? '',
	};
};

/**
 * Asia/Tokyo タイムゾーンの YYYY-MM-DD 形式で日付文字列を返すヘルパー。
 * formatToParts() を使用し、locale 依存のフォーマット差異を回避する。
 *
 * @param date Date オブジェクト
 * @return YYYY-MM-DD 形式の文字列（Asia/Tokyo）
 */
const formatDateTokyo = ( date: Date ): string => {
	const { year, month, day } = getTokyoDateParts( date );
	return `${ year }-${ month }-${ day }`;
};

/**
 * 指定月のシフトを作成するヘルパー。
 * スタッフID・年・月を指定して、毎日 09:00-18:00 の open シフトを作成する。
 *
 * @param staffId スタッフの post ID
 * @param year    シフト年
 * @param month   シフト月（1-12）
 */
const createShiftForMonth = ( staffId: string, year: number, month: number ) => {
	const createShiftCode = `
		$resource_id = ${ staffId };
		$year = ${ year };
		$month = ${ month };
		$days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
		$days = [];
		for ($d = 1; $d <= $days_in_month; $d++) {
			$days[$d] = [
				'status' => 'open',
				'slots' => [['start' => '09:00', 'end' => '18:00']]
			];
		}
		$existing = get_posts([
			'post_type' => 'vkbm_shift',
			'post_status' => 'any',
			'meta_query' => [
				['key' => '_vkbm_shift_resource_id', 'value' => $resource_id],
				['key' => '_vkbm_shift_year', 'value' => $year],
				['key' => '_vkbm_shift_month', 'value' => $month],
			],
			'fields' => 'ids',
		]);
		if (!empty($existing)) {
			$post_id = $existing[0];
			update_post_meta($post_id, '_vkbm_shift_days', $days);
		} else {
			$post_id = wp_insert_post([
				'post_type'   => 'vkbm_shift',
				'post_status' => 'publish',
				'post_title'  => sprintf('%d-%02d Staff %d', $year, $month, $resource_id),
			]);
			update_post_meta($post_id, '_vkbm_shift_resource_id', $resource_id);
			update_post_meta($post_id, '_vkbm_shift_year', $year);
			update_post_meta($post_id, '_vkbm_shift_month', $month);
			update_post_meta($post_id, '_vkbm_shift_days', $days);
		}
		echo $post_id;
	`;
	const base64Code = Buffer.from( createShiftCode ).toString( 'base64' );
	execSync(
		`npx wp-env run cli wp eval 'eval(base64_decode("${ base64Code }"));'`
	);
};

/**
 * スタッフIDを取得するヘルパー。
 */
const getStaffId = (): string => {
	return execSync(
		'npx wp-env run cli wp post list --post_type=vkbm_resource --post_status=publish --field=ID --format=ids',
		{ encoding: 'utf-8' }
	).trim().split( /\s+/ )[ 0 ];
};

// テスト前にテスト環境をセットアップ（スタッフ・シフト・メニュー・予約ページを作成）
test.beforeAll( async () => {
	await disableEmailVerification();

	// 制限日数超過テスト用に、将来月のシフトも作成しておく
	const staffId = getStaffId();
	const now = new Date();
	// Asia/Tokyo の現在月を formatToParts で取得（locale 依存を排除）
	const { year, month } = getTokyoDateParts( now );
	const currentYear = Number( year );
	const currentMonth = Number( month );

	// 今月・翌月・翌々月のシフトを作成（将来日テスト用）
	createShiftForMonth( staffId, currentYear, currentMonth );
	const nextMonth = currentMonth === 12 ? 1 : currentMonth + 1;
	const nextMonthYear = currentMonth === 12 ? currentYear + 1 : currentYear;
	createShiftForMonth( staffId, nextMonthYear, nextMonth );
	const monthAfter = nextMonth === 12 ? 1 : nextMonth + 1;
	const monthAfterYear = nextMonth === 12 ? nextMonthYear + 1 : nextMonthYear;
	createShiftForMonth( staffId, monthAfterYear, monthAfter );
} );

// 各テスト前後で予約可能期間の設定をリセット（テスト間の状態汚染を防止）
test.beforeEach( async () => {
	await configureProviderSettings( {
		provider_max_advance_booking_days: 0,
	} );
	const menuId = getServiceMenuId();
	try {
		execSync(
			`npx wp-env run cli wp post meta delete ${ menuId } _vkbm_max_advance_booking_days`,
			{ stdio: 'ignore' }
		);
	} catch ( e ) {
		// メタが存在しない場合は無視
	}
} );

test.afterEach( async () => {
	await configureProviderSettings( {
		provider_max_advance_booking_days: 0,
	} );
	const menuId = getServiceMenuId();
	try {
		execSync(
			`npx wp-env run cli wp post meta delete ${ menuId } _vkbm_max_advance_booking_days`,
			{ stdio: 'ignore' }
		);
	} catch ( e ) {
		// メタが存在しない場合は無視
	}
} );

test.describe( '予約可能期間（Max advance booking period）の設定機能', () => {

	test( '共通設定画面に Max advance booking period フィールドが表示され、保存できること', async ( { page } ) => {
		// 管理画面にログイン
		await loginAsAdmin( page );

		// プロバイダー設定画面の「システム」タブに直接移動（tab=system クエリパラメータ指定）
		await page.goto( '/wp-admin/admin.php?page=vkbm-provider-settings&tab=system' );
		await page.waitForLoadState( 'networkidle' );

		// Max advance booking period の入力フィールドが存在することを確認
		const maxAdvanceInput = page.locator( '#vkbm-provider-max-advance-booking-days' );
		await expect( maxAdvanceInput ).toBeVisible( { timeout: 10000 } );

		// 14 を入力
		await maxAdvanceInput.fill( '14' );

		// フォームを保存
		const submitButton = page.locator( 'input[type="submit"]' ).last();
		await submitButton.click();
		await page.waitForLoadState( 'networkidle' );

		// 保存後、システムタブに再アクセスして値を確認
		await page.goto( '/wp-admin/admin.php?page=vkbm-provider-settings&tab=system' );
		await page.waitForLoadState( 'networkidle' );

		// 保存された値を確認
		const savedValue = await page.locator( '#vkbm-provider-max-advance-booking-days' ).inputValue();
		expect( savedValue ).toBe( '14' );
	} );

	test( 'サービスメニュー個別設定の _vkbm_max_advance_booking_days メタが保存・取得できること', async () => {
		// WP-CLI 経由でメタデータの保存・取得をテスト
		// （ブロックエディタではメタボックスがiframe内で表示されるため、WP-CLI で検証）
		const menuId = getServiceMenuId();
		expect( menuId ).toBeTruthy();

		// メタを 7 に設定
		execSync(
			`npx wp-env run cli wp post meta update ${ menuId } _vkbm_max_advance_booking_days 7`
		);

		// 設定した値が取得できることを確認
		const savedValue = execSync(
			`npx wp-env run cli wp post meta get ${ menuId } _vkbm_max_advance_booking_days`,
			{ encoding: 'utf-8' }
		).trim();
		expect( savedValue ).toBe( '7' );
	} );

	test( 'REST API: 制限日数を超える日付のスロットが空で、制限内はスロットが存在すること（共通設定: 7日）', async ( { page } ) => {
		// wp-cli 経由で共通設定に max_advance_booking_days = 7 を設定
		await configureProviderSettings( {
			provider_max_advance_booking_days: 7,
		} );

		const menuId = getServiceMenuId();

		// Asia/Tokyo タイムゾーンで日付を計算（UTC との日付ずれを防止）
		const now = new Date();

		// 8日後の日付（制限を超える）
		const beyondDate = new Date( now.getTime() + 8 * 24 * 60 * 60 * 1000 );
		const beyondDateStr = formatDateTokyo( beyondDate );

		// 2日後の日付（制限内）
		const withinDate = new Date( now.getTime() + 2 * 24 * 60 * 60 * 1000 );
		const withinDateStr = formatDateTokyo( withinDate );

		// 制限外の日付: スロットが空であること
		const beyondResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ beyondDateStr }&timezone=Asia/Tokyo`
		);
		expect( beyondResponse.status() ).toBe( 200 );
		const beyondData = await beyondResponse.json();
		const beyondSlots = Array.isArray( beyondData ) ? beyondData : beyondData.slots || [];
		expect( beyondSlots ).toEqual( [] );

		// 制限内の日付: スロットが存在すること（シフトが設定済みなので空でないはず）
		const withinResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ withinDateStr }&timezone=Asia/Tokyo`
		);
		expect( withinResponse.status() ).toBe( 200 );
		const withinData = await withinResponse.json();
		const withinSlots = Array.isArray( withinData ) ? withinData : withinData.slots || [];
		expect( withinSlots.length ).toBeGreaterThan( 0 );
	} );

	test( 'REST API: 0（無制限）の場合は将来日付もスロットが返ること', async ( { page } ) => {
		// beforeEach で provider_max_advance_booking_days = 0 にリセット済み

		const menuId = getServiceMenuId();

		// 30日後の日付（シフトが存在する将来日）
		const now = new Date();
		const farDate = new Date( now.getTime() + 30 * 24 * 60 * 60 * 1000 );
		const farDateStr = formatDateTokyo( farDate );

		// 無制限なので将来日でもスロットが返ること
		const response = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ farDateStr }&timezone=Asia/Tokyo`
		);
		expect( response.status() ).toBe( 200 );
		const data = await response.json();
		const slots = Array.isArray( data ) ? data : data.slots || [];
		expect( slots.length ).toBeGreaterThan( 0 );
	} );

	test( 'REST API: サービス個別設定が共通設定より優先されること', async ( { page } ) => {
		// 共通設定: 30日
		await configureProviderSettings( {
			provider_max_advance_booking_days: 30,
		} );

		// サービスメニューの個別設定: 3日
		const menuId = getServiceMenuId();
		execSync(
			`npx wp-env run cli wp post meta update ${ menuId } _vkbm_max_advance_booking_days 3`
		);

		const now = new Date();

		// 5日後の日付（共通設定30日以内だが、個別設定3日を超える）
		const beyondMenuDate = new Date( now.getTime() + 5 * 24 * 60 * 60 * 1000 );
		const beyondMenuDateStr = formatDateTokyo( beyondMenuDate );

		// 個別設定（3日）が優先されるため、5日後のスロットは空であること
		const beyondResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ beyondMenuDateStr }&timezone=Asia/Tokyo`
		);
		expect( beyondResponse.status() ).toBe( 200 );
		const beyondData = await beyondResponse.json();
		const beyondSlots = Array.isArray( beyondData ) ? beyondData : beyondData.slots || [];
		expect( beyondSlots ).toEqual( [] );

		// 2日後の日付（個別設定3日以内）: スロットが存在すること
		const withinDate = new Date( now.getTime() + 2 * 24 * 60 * 60 * 1000 );
		const withinDateStr = formatDateTokyo( withinDate );

		const withinResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ withinDateStr }&timezone=Asia/Tokyo`
		);
		expect( withinResponse.status() ).toBe( 200 );
		const withinData = await withinResponse.json();
		const withinSlots = Array.isArray( withinData ) ? withinData : withinData.slots || [];
		expect( withinSlots.length ).toBeGreaterThan( 0 );
	} );
} );
