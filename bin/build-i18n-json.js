#!/usr/bin/env node

const { spawnSync } = require( 'child_process' );
const { normalizeI18nJson } = require( './normalize-i18n-json' );

/**
 * コマンドを実行し、標準入出力を継承して結果を返す。
 *
 * @param {string}   command コマンド名。
 * @param {string[]} args    引数配列。
 * @return {{ status: number, stderr: string }} 実行結果。
 */
function runCommand( command, args ) {
	const result = spawnSync( command, args, {
		encoding: 'utf8',
		stdio: [ 'inherit', 'inherit', 'pipe' ],
	} );

	if ( result.stderr ) {
		process.stderr.write( result.stderr );
	}

	return {
		status: typeof result.status === 'number' ? result.status : 1,
		stderr: result.stderr || '',
	};
}

/**
 * バンドル JSON をマージし、成功したら generator フィールドを正規化する。
 *
 * wp-cli の版差で generator（例: "WP-CLI/2.12.0"）が変わるだけで全 md5 JSON に
 * 差分が出るのを防ぐため、マージ後に必ず正規化を通す。
 *
 * @return {number} 終了コード。
 */
function mergeAndNormalize() {
	const status = runCommand( 'node', [
		'bin/merge-json-translations.js',
	] ).status;
	if ( status !== 0 ) {
		return status;
	}

	// 生成された md5 JSON 群の "generator" を固定値へ正規化する（churn 防止）。
	normalizeI18nJson();
	return 0;
}

/**
 * make-json 実行時に --no-purge 未対応ならフラグなしで再実行する。
 *
 * @return {number} 終了コード。
 */
function buildI18nJson() {
	const baseArgs = [
		'i18n',
		'make-json',
		'languages/vk-booking-manager-ja.po',
		'languages',
	];

	const withNoPurge = runCommand( 'wp', [ ...baseArgs, '--no-purge' ] );

	if ( withNoPurge.status === 0 ) {
		return mergeAndNormalize();
	}

	const unsupportedNoPurge = /unknown --purge parameter/i.test(
		withNoPurge.stderr
	);

	if ( ! unsupportedNoPurge ) {
		return withNoPurge.status;
	}

	console.warn(
		'[build:i18n:json] `--no-purge` が未対応のため、フラグなしで再実行します。'
	);

	const fallback = runCommand( 'wp', baseArgs );
	if ( fallback.status !== 0 ) {
		return fallback.status;
	}

	return mergeAndNormalize();
}

process.exit( buildI18nJson() );
