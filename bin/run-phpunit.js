#!/usr/bin/env node

const { spawnSync } = require( 'child_process' );

/**
 * 引数に free 指定があるかを判定する。
 *
 * @return {boolean} free 実行なら true。
 */
function isFreeMode() {
	return process.argv.includes( '--free' );
}

/**
 * 単一の引数を sh のシングルクォート文字列として安全にエスケープする。
 *
 * シングルクォート内では `'` 以外の全ての文字（スペース・`;`・`$(...)` 等）が
 * リテラル扱いになるため、引数全体を `'...'` で囲む。引数中に含まれる `'` は
 * `'\''`（クォート閉じ → エスケープした `'` → クォート開き）に置換して無害化する。
 * これによりコマンドインジェクションを防止する。
 *
 * @param {string} arg エスケープ対象の引数。
 * @return {string} sh に渡せる安全なクォート済み文字列。
 */
function shellQuote( arg ) {
	return `'${ String( arg ).replace( /'/g, "'\\''" ) }'`;
}

/**
 * argv（process.argv.slice(2) 相当）から `--free` を除いた残りの引数を、
 * phpunit 呼び出し末尾へ転送するための安全エスケープ済みシェル文字列に組み立てる。
 *
 * 純粋関数として実装し、副作用を持たない（テスト容易性のため module.exports で公開する）。
 * 引数が無い場合は空文字列を返し、呼び出し側で余計な空白が付かないようにする。
 *
 * @param {string[]} argv 転送候補の引数配列（通常は process.argv.slice(2)）。
 * @return {string} スペース区切りでクォート済みの引数文字列。該当が無ければ空文字列。
 */
function buildPassthroughArgs( argv ) {
	// `--free` は env-cwd 切り替え用のフラグなので phpunit へは転送しない。
	const passthrough = ( argv || [] ).filter( ( arg ) => arg !== '--free' );

	// 各引数をシングルクォートで安全に囲んでからスペースで連結する。
	return passthrough.map( shellQuote ).join( ' ' );
}

/**
 * i18n テストスキップの有効化状態を判定する。
 *
 * @return {boolean} スキップする場合は true。
 */
function shouldSkipI18nTests() {
	return (
		process.env.VK_BOOKING_MANAGER_SKIP_I18N_TESTS === '1' ||
		process.env.VKBM_SKIP_I18N_TESTS === '1'
	);
}

/**
 * wp-env 経由で PHPUnit を実行する。
 *
 * @return {number} 終了コード。
 */
function runPhpunit() {
	const freeMode = isFreeMode();
	const envCwd = freeMode
		? 'wp-content/plugins/vk-booking-manager'
		: 'wp-content/plugins/vk-booking-manager-pro';

	const skipI18n = shouldSkipI18nTests();
	const i18nEnvPrefix = skipI18n
		? 'VK_BOOKING_MANAGER_SKIP_I18N_TESTS=1 '
		: '';

	// `--free` 以外の追加引数（--filter / positional パス / --group 等）を
	// 安全にエスケープして phpunit 呼び出し末尾へ転送する。
	const phpunitArgs = buildPassthroughArgs( process.argv.slice( 2 ) );
	// 末尾に余計な空白を付けないよう、引数がある場合のみスペースを挟む。
	const phpunitArgsSuffix = phpunitArgs ? ` ${ phpunitArgs }` : '';

	const shellCommand =
		`${ i18nEnvPrefix }composer install && ` +
		'( wp language core is-installed ja >/dev/null 2>&1 || wp language core install ja ) && ' +
		'wp site switch-language ja && ' +
		'( wp db query "CREATE DATABASE IF NOT EXISTS wordpress_test" >/dev/null 2>&1 || true ) && ' +
		`${ i18nEnvPrefix }vendor/bin/phpunit -c phpunit.xml.dist${ phpunitArgsSuffix }`;

	// テスト用 wp-env 環境（別設定ファイル）の cli コンテナで実行する。
	// wp-env v11 では開発用と同一設定での tests 環境同時起動が非推奨のため、
	// テスト専用の .wp-env-tests.json（ポート 8889）を --config で指定する。
	// リリース時の dist 検証などで対象設定を差し替えたい場合は
	// WP_ENV_TESTS_CONFIG で上書きできる。
	const testsConfig = process.env.WP_ENV_TESTS_CONFIG || '.wp-env-tests.json';

	const result = spawnSync(
		'wp-env',
		[
			'run',
			'--config',
			testsConfig,
			'cli',
			`--env-cwd=${ envCwd }`,
			'sh',
			'-c',
			shellCommand,
		],
		{
			stdio: 'inherit',
		}
	);

	if ( result.error ) {
		console.error(
			'[phpunit] Failed to start wp-env:',
			result.error.message
		);
		return 1;
	}

	return typeof result.status === 'number' ? result.status : 1;
}

// 引数組み立てロジックをテストから検証できるよう公開する。
module.exports = { buildPassthroughArgs, shellQuote };

// このファイルが直接実行された場合のみ phpunit を走らせる。
// require されたとき（テスト時）は副作用を起こさない。
if ( require.main === module ) {
	process.exit( runPhpunit() );
}
