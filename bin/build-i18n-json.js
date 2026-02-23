#!/usr/bin/env node

const { spawnSync } = require( 'child_process' );

/**
 * コマンドを実行し、標準入出力を継承して結果を返す。
 *
 * @param {string} command コマンド名。
 * @param {string[]} args 引数配列。
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
		return runCommand( 'node', [ 'bin/merge-json-translations.js' ] ).status;
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

	return runCommand( 'node', [ 'bin/merge-json-translations.js' ] ).status;
}

process.exit( buildI18nJson() );
