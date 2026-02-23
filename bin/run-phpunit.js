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
	const i18nEnvPrefix = skipI18n ? 'VK_BOOKING_MANAGER_SKIP_I18N_TESTS=1 ' : '';

	const shellCommand =
		`${ i18nEnvPrefix }composer install && ` +
		'( wp language core is-installed ja >/dev/null 2>&1 || wp language core install ja ) && ' +
		'wp site switch-language ja && ' +
		'( wp db query "CREATE DATABASE IF NOT EXISTS wordpress_test" >/dev/null 2>&1 || true ) && ' +
		`${ i18nEnvPrefix }vendor/bin/phpunit -c phpunit.xml.dist`;

	const result = spawnSync(
		'wp-env',
		[ 'run', 'tests-cli', `--env-cwd=${ envCwd }`, 'sh', '-c', shellCommand ],
		{
			stdio: 'inherit',
		}
	);

	if ( result.error ) {
		console.error( '[phpunit] Failed to start wp-env:', result.error.message );
		return 1;
	}

	return typeof result.status === 'number' ? result.status : 1;
}

process.exit( runPhpunit() );
