#!/usr/bin/env node

const { spawnSync } = require( 'child_process' );

/**
 * コマンドが利用可能かを確認する。
 *
 * @param {string} command コマンド名。
 * @return {boolean} 利用可能な場合は true。
 */
function hasCommand( command ) {
	const result = spawnSync( command, [ '--version' ], {
		stdio: 'ignore',
	} );

	return ! result.error && result.status === 0;
}

/**
 * npm-run-all で翻訳ビルドを順番に実行する。
 *
 * @return {number} 終了コード。
 */
function runI18nBuild() {
	const result = spawnSync(
		'npm-run-all',
		[
			'-s',
			'build:i18n:pot',
			'build:i18n:po',
			'build:i18n:php',
			'build:i18n:mo',
			'build:i18n:json',
		],
		{
			stdio: 'inherit',
		}
	);

	if ( result.error ) {
		console.error(
			'[build:i18n] Failed to start npm-run-all:',
			result.error.message
		);
		return 1;
	}

	return typeof result.status === 'number' ? result.status : 1;
}

const skipByEnv = process.env.VKBM_SKIP_I18N_BUILD === '1';
const forceByEnv = process.env.VKBM_FORCE_I18N_BUILD === '1';

if ( skipByEnv ) {
	console.log( '[build:i18n] Skipped by VKBM_SKIP_I18N_BUILD=1' );
	process.exit( 0 );
}

const requiredCommands = [ 'wp', 'msgmerge', 'msgfmt' ];
const missingCommands = requiredCommands.filter(
	( command ) => ! hasCommand( command )
);

if ( missingCommands.length > 0 && ! forceByEnv ) {
	console.warn(
		`[build:i18n] Skipped because required commands are missing: ${ missingCommands.join(
			', '
		) }`
	);
	console.warn(
		'[build:i18n] Install the missing tools to run i18n build. (If VKBM_FORCE_I18N_BUILD=1 is set, missing tools cause exit 1.)'
	);
	process.exit( 0 );
}

if ( missingCommands.length > 0 && forceByEnv ) {
	console.error(
		`[build:i18n] VKBM_FORCE_I18N_BUILD=1 is set, but required commands are missing: ${ missingCommands.join(
			', '
		) }`
	);
	process.exit( 1 );
}

process.exit( runI18nBuild() );
