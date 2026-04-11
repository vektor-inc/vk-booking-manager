import { execSync } from 'child_process';
import type { Page } from '@playwright/test';

/**
 * WP-CLI コマンドを実行するヘルパー。
 * Helper to run WP-CLI commands via wp-env.
 *
 * @param command WP-CLI コマンド文字列（'wp' 以降の部分）
 * @return コマンドの標準出力（trimmed）
 */
export const wpCli = ( command: string ): string => {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf-8',
	} ).trim();
};

/**
 * WP管理画面にログインする共通ヘルパー。
 * Common helper to log in to the WP admin dashboard.
 *
 * @param page Playwright の Page オブジェクト
 * @param user ユーザー名（デフォルト: admin）
 * @param password パスワード（デフォルト: password）
 */
export const loginAsAdmin = async (
	page: Page,
	user: string = 'admin',
	password: string = 'password'
) => {
	// まずダッシュボードにアクセスして、既にログイン済みか確認
	// First check if already logged in by accessing wp-admin
	await page.goto( '/wp-admin/' );
	await page.waitForLoadState( 'domcontentloaded' );

	// ログインフォームが表示された場合のみログイン処理を行う
	// Only perform login if the login form is displayed
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.locator( '#user_login' ).fill( user );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/, { timeout: 30000 } );
	}
};

/**
 * スタッフ（リソース）の post ID を取得するヘルパー。
 * Helper to get the first published staff (resource) post ID.
 *
 * @return 最初の公開済みスタッフの post ID
 */
export const getStaffId = (): string => {
	const result = wpCli(
		'post list --post_type=vkbm_resource --post_status=publish --field=ID --format=ids'
	)
		.trim()
		.split( /\s+/ )[ 0 ];
	if ( ! result ) {
		throw new Error(
			'No published vkbm_resource found. Ensure global setup has run.'
		);
	}
	return result;
};

/**
 * サービスメニューの post ID を取得するヘルパー。
 * Helper to get the first published service menu post ID.
 *
 * @return 最初の公開済みサービスメニューの post ID
 */
export const getServiceMenuId = (): string => {
	const result = wpCli(
		'post list --post_type=vkbm_service_menu --post_status=publish --field=ID --format=ids'
	)
		.trim()
		.split( /\s+/ )[ 0 ];
	if ( ! result ) {
		throw new Error(
			'No published vkbm_service_menu found. Ensure global setup has run.'
		);
	}
	return result;
};

/**
 * Asia/Tokyo タイムゾーンの年・月・日を formatToParts() で取得するヘルパー。
 * locale 文字列のフォーマットに依存せず、安定して YYYY/MM/DD を返す。
 * Helper to get year/month/day parts in Asia/Tokyo timezone using formatToParts().
 *
 * @param date Date オブジェクト
 * @return year, month, day の各文字列（ゼロパディング済み）
 */
export const getTokyoDateParts = (
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
 * Helper to format a Date as YYYY-MM-DD in Asia/Tokyo timezone.
 *
 * @param date Date オブジェクト
 * @return YYYY-MM-DD 形式の文字列（Asia/Tokyo）
 */
export const formatDateTokyo = ( date: Date ): string => {
	const { year, month, day } = getTokyoDateParts( date );
	return `${ year }-${ month }-${ day }`;
};

/**
 * 指定月のシフトを作成するヘルパー。
 * 既存のシフトがあれば更新、なければ新規作成する。
 * Helper to create or update a shift for a given month.
 *
 * @param staffId スタッフの post ID
 * @param year    シフト年
 * @param month   シフト月（1-12）
 */
export const createShiftForMonth = (
	staffId: string,
	year: number,
	month: number
) => {
	// PHP コードで既存シフトのチェック → 更新 or 新規作成を一括実行
	// Execute PHP code to check for existing shift and update or create
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
 * プロバイダー設定で staff_enabled を切り替えるヘルパー。
 * Helper to toggle the staff_enabled provider setting.
 *
 * @param enabled true で有効、false で無効
 */
export const setStaffEnabled = ( enabled: boolean ): void => {
	const val = enabled ? '1' : '0';
	// PHP の !empty() に合わせて 1 または 0 をセットする
	// Set 1 or 0 to match PHP's !empty() check
	// base64 エンコーディングでシェルエスケープ問題を回避
	// Use base64 encoding to avoid shell escaping issues
	const phpCode = `
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['staff_enabled'] = ${ val };
		update_option( 'vkbm_provider_settings', $s );
	`;
	const base64Code = Buffer.from( phpCode ).toString( 'base64' );
	execSync(
		`npx wp-env run cli wp eval 'eval(base64_decode("${ base64Code }"));'`
	);
};
