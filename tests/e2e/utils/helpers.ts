import { execFileSync, ExecFileSyncOptions } from 'child_process';
import type { Page } from '@playwright/test';

/**
 * WP-CLI コマンドを実行するヘルパー（推奨：引数を配列で渡す版）。
 * Helper to run WP-CLI commands via wp-env using execFileSync (recommended).
 *
 * execSync ではなく execFileSync を使用することで、shell 経由のコマンド解釈を
 * 完全にスキップし、コマンドインジェクションの構造的リスクを排除する。
 * Using execFileSync skips shell interpretation entirely, eliminating
 * the structural risk of command injection.
 *
 * @param args WP-CLI に渡す引数の配列（'wp' 以降の各トークン）
 * @param opts execFileSync オプション（encoding は utf-8 で上書き可能）
 * @return コマンドの標準出力（trimmed）
 */
export const wpCliArgs = (
	args: string[],
	opts?: ExecFileSyncOptions
): string => {
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', ...args ],
		{
			encoding: 'utf-8',
			...opts,
		}
	);
	// stdio: 'inherit' を指定すると execFileSync は null を返すため、
	// その場合は空文字として扱う（呼び出し側が戻り値を使わないケース用）。
	// execFileSync returns null when stdio is 'inherit'; treat it as
	// an empty string so callers that ignore the return value still work.
	if ( out === null ) {
		return '';
	}
	return out.toString().trim();
};

/**
 * WP-CLI コマンドを実行するヘルパー（後方互換：文字列を空白で分割）。
 * Backwards-compatible helper to run WP-CLI commands from a single string.
 *
 * 既存のスペックファイルは `wpCli('post list --post_type=...')` 形式で
 * 呼んでいるため、引数を空白で分割して wpCliArgs に委譲する。
 * 新規コードでは wpCliArgs を直接使うこと。
 * Existing specs call `wpCli('post list --post_type=...')`; this wrapper
 * splits the string on whitespace and delegates to wpCliArgs.
 * Prefer wpCliArgs for new code.
 *
 * @deprecated 新規コードでは {@link wpCliArgs} を使用してください。
 *
 * このヘルパーは内部で `command.split(/\s+/)` により引数に分解するため、
 * 以下のケースで壊れる/挙動が変わる:
 * - 引数自体に空白を含むケース（例: post タイトル "Hello World"）
 * - シェルの引用符・パイプ・リダイレクトに依存した記法
 *   （execFileSync 化により shell パースを経由しなくなったため）
 *
 * 動的値や空白含み引数を扱う場合は wpCliArgs を、複数文 PHP を渡す場合は
 * {@link wpEvalPhp} を使用してください。
 *
 * This helper splits the input on `\\s+`, so it breaks when an argument
 * itself contains whitespace (e.g. `post_title=Hello World`) or relies on
 * shell-level quoting (which is no longer interpreted after the
 * execFileSync migration). Use {@link wpCliArgs} for dynamic / spaced
 * arguments and {@link wpEvalPhp} for multi-statement PHP.
 *
 * @param      command WP-CLI コマンド文字列（'wp' 以降の部分）
 * @return コマンドの標準出力（trimmed）
 */
export const wpCli = ( command: string ): string =>
	wpCliArgs( command.split( /\s+/ ).filter( Boolean ) );

/**
 * 任意の PHP コードを WP-CLI eval 経由で実行するヘルパー。
 * Helper to run arbitrary PHP code via WP-CLI eval.
 *
 * WP-CLI eval は単一の PHP 式しか受け付けないため、複数文を含む PHP コードは
 * base64 でラップして eval(base64_decode(...)) の形で渡す。
 * shell パース経由のリスクは execFileSync で排除済みなので、base64 ラップは
 * 純粋に WP-CLI 側のパース回避のためのもの。
 * execFileSync removes shell-side parsing risk; the base64 wrap is purely
 * to bypass WP-CLI eval's single-expression constraint.
 *
 * **重要 / IMPORTANT**:
 * base64 エンコードは "shell・WP-CLI のパース" レイヤの脱出を防ぐ目的のもので、
 * **PHP リテラル外への脱出は防げない**。
 * PHP コード本文に動的値を埋め込む場合（例: ``echo ${ id };``）は、その値が
 * `;` や `echo`、引用符などを含む文字列だと PHP 構文として脱出されうる。
 * 動的値を埋め込む際は呼び出し側で **数値化（Number/parseInt）またはホワイトリスト
 * 検証**、文字列の場合は addslashes 相当の処理を必ず行うこと。
 *
 * base64 encoding only escapes the shell / WP-CLI parse layer; it does NOT
 * sandbox values you interpolate inside the PHP literal. Callers are
 * responsible for numeric coercion / allowlist validation of any dynamic
 * value embedded into the PHP code.
 *
 * @param phpCode 実行する PHP コード（複数文可、末尾セミコロン推奨）
 * @param opts    execFileSync オプション
 * @return コマンドの標準出力（trimmed）
 */
export const wpEvalPhp = (
	phpCode: string,
	opts?: ExecFileSyncOptions
): string => {
	const base64 = Buffer.from( phpCode ).toString( 'base64' );
	return wpCliArgs( [ 'eval', `eval(base64_decode("${ base64 }"));` ], opts );
};

/**
 * WP管理画面にログインする共通ヘルパー。
 * Common helper to log in to the WP admin dashboard.
 *
 * @param page     Playwright の Page オブジェクト
 * @param user     ユーザー名（デフォルト: admin）
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
 * 戻り値は `/^\d+$/` で数値検証済みの文字列のみ返す。これにより
 * 呼び出し側で wpEvalPhp に埋め込んでも PHP リテラル外への脱出を防げる。
 * The return value is validated against `/^\d+$/` so it is safe to
 * interpolate into PHP code via wpEvalPhp (no literal-escape risk).
 *
 * @return 最初の公開済みスタッフの post ID（数値文字列のみ）
 */
export const getStaffId = (): string => {
	const result = wpCliArgs( [
		'post',
		'list',
		'--post_type=vkbm_resource',
		'--post_status=publish',
		'--field=ID',
		'--format=ids',
	] )
		.trim()
		.split( /\s+/ )[ 0 ];
	// 空文字または非数値は不正値として扱う（wpEvalPhp 埋め込みを堅牢化）
	// Reject empty or non-numeric IDs to harden downstream wpEvalPhp interpolation
	if ( ! result || ! /^\d+$/.test( result ) ) {
		throw new Error(
			`No published vkbm_resource found, or invalid ID returned: "${ result }". Ensure global setup has run.`
		);
	}
	return result;
};

/**
 * サービスメニューの post ID を取得するヘルパー。
 * Helper to get the first published service menu post ID.
 *
 * 戻り値は `/^\d+$/` で数値検証済みの文字列のみ返す。これにより
 * 呼び出し側で wpEvalPhp に埋め込んでも PHP リテラル外への脱出を防げる。
 * The return value is validated against `/^\d+$/` so it is safe to
 * interpolate into PHP code via wpEvalPhp (no literal-escape risk).
 *
 * @return 最初の公開済みサービスメニューの post ID（数値文字列のみ）
 */
export const getServiceMenuId = (): string => {
	const result = wpCliArgs( [
		'post',
		'list',
		'--post_type=vkbm_service_menu',
		'--post_status=publish',
		'--field=ID',
		'--format=ids',
	] )
		.trim()
		.split( /\s+/ )[ 0 ];
	// 空文字または非数値は不正値として扱う（wpEvalPhp 埋め込みを堅牢化）
	// Reject empty or non-numeric IDs to harden downstream wpEvalPhp interpolation
	if ( ! result || ! /^\d+$/.test( result ) ) {
		throw new Error(
			`No published vkbm_service_menu found, or invalid ID returned: "${ result }". Ensure global setup has run.`
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
 * 引数は PHP コードに埋め込まれるため、呼び出し時に必ず数値として検証する:
 * - staffId は数字のみで構成された文字列であること
 * - year は整数であること
 * - month は 1〜12 の整数であること
 *
 * Inputs are interpolated into PHP, so each value is validated as a number:
 * - staffId must be a numeric string
 * - year must be an integer
 * - month must be an integer in 1..12
 *
 * @param staffId スタッフの post ID（数値文字列）
 * @param year    シフト年（整数）
 * @param month   シフト月（1-12 の整数）
 */
export const createShiftForMonth = (
	staffId: string,
	year: number,
	month: number
) => {
	// staffId の数値検証（非数値文字列だと PHP リテラル外への脱出リスク）
	// Validate staffId is numeric to prevent PHP literal escape
	if ( ! /^\d+$/.test( staffId ) ) {
		throw new Error( `Invalid staffId: "${ staffId }"` );
	}
	// year / month の整数検証（month=13 等の不正値で他月を静かに作らないように）
	// Validate year/month so month=13 etc. cannot silently create a shift for the wrong month
	if (
		! Number.isInteger( year ) ||
		! Number.isInteger( month ) ||
		month < 1 ||
		month > 12
	) {
		throw new Error(
			`Invalid shift target: year=${ year }, month=${ month }`
		);
	}
	// 検証済み staffId をさらに数値化して PHP に渡す（多重の保険）
	// Coerce the validated staffId to a JS number for an additional safety layer
	const safeStaffId = Number.parseInt( staffId, 10 );

	// PHP コードで既存シフトのチェック → 更新 or 新規作成を一括実行
	// Execute PHP code to check for existing shift and update or create
	const createShiftCode = `
		$resource_id = ${ safeStaffId };
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
	// wpEvalPhp 経由で base64 ラップ＋execFileSync 実行に統一
	// Use wpEvalPhp to consolidate base64 wrapping + execFileSync execution
	wpEvalPhp( createShiftCode );
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
	const phpCode = `
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['staff_enabled'] = ${ val };
		update_option( 'vkbm_provider_settings', $s );
	`;
	// wpEvalPhp 経由で base64 ラップ＋execFileSync 実行に統一
	// Use wpEvalPhp to consolidate base64 wrapping + execFileSync execution
	wpEvalPhp( phpCode );
};
