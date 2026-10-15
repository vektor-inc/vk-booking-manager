#!/usr/bin/env node

/**
 * 内閣府「国民の祝日」CSV（Shift_JIS）から、同梱の祝日一覧
 * src/common/data/holidays-jp.php を生成するスクリプト。
 *
 * 使い方（年 1 回、内閣府が翌年分を公表した後に実行する）:
 *   1. https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv をダウンロードする
 *   2. node bin/build-holidays.js <ダウンロードした CSV のパス>
 *
 * ネットワーク取得は行わない（通信失敗時の扱いを増やさないため）。
 */

const fs = require( 'fs' );
const path = require( 'path' );

const SOURCE_URL = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';
const OUTPUT_PATH = path.join(
	__dirname,
	'..',
	'src',
	'common',
	'data',
	'holidays-jp.php'
);

/**
 * CSV の日付（例: 2026/1/1）を Y-m-d（ゼロ埋め）へ変換する。
 *
 * @param {string} value CSV の日付セル。
 * @return {string|null} 変換結果。日付として読めない場合は null。
 */
function toYmd( value ) {
	const match = /^\s*(\d{4})\/(\d{1,2})\/(\d{1,2})\s*$/.exec( value );
	if ( ! match ) {
		return null;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] );
	const day = Number( match[ 3 ] );

	// 存在しない日付（2/30 など）を弾く。
	const date = new Date( Date.UTC( year, month - 1, day ) );
	if (
		date.getUTCFullYear() !== year ||
		date.getUTCMonth() !== month - 1 ||
		date.getUTCDate() !== day
	) {
		return null;
	}

	const mm = String( month ).padStart( 2, '0' );
	const dd = String( day ).padStart( 2, '0' );
	return `${ match[ 1 ] }-${ mm }-${ dd }`;
}

/**
 * CSV 全体の文字列から、日付順の [Y-m-d, 祝日名] の配列を作る。
 * 見出し行・空行・日付として読めない行は無視する。
 *
 * @param {string} csvText UTF-8 に変換済みの CSV 文字列。
 * @return {Array<[string, string]>} 日付昇順の祝日一覧。
 */
function parseHolidays( csvText ) {
	const byDate = new Map();

	csvText.split( /\r?\n/ ).forEach( ( line ) => {
		const commaIndex = line.indexOf( ',' );
		if ( commaIndex < 0 ) {
			return;
		}

		const ymd = toYmd( line.slice( 0, commaIndex ) );
		const name = line.slice( commaIndex + 1 ).trim();
		if ( null === ymd || '' === name ) {
			return;
		}

		byDate.set( ymd, name );
	} );

	return Array.from( byDate.entries() ).sort( ( a, b ) => {
		if ( a[ 0 ] === b[ 0 ] ) {
			return 0;
		}
		return a[ 0 ] < b[ 0 ] ? -1 : 1;
	} );
}

/**
 * PHP の単一引用符文字列として安全になるようエスケープする。
 *
 * @param {string} value エスケープ対象。
 * @return {string} エスケープ後の文字列。
 */
function escapePhpString( value ) {
	return value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" );
}

/**
 * 祝日一覧から holidays-jp.php の中身を組み立てる。
 *
 * @param {Array<[string, string]>} holidays 日付昇順の祝日一覧。
 * @return {string} PHP ファイルの内容。
 */
function buildPhpSource( holidays ) {
	const rows = holidays
		.map(
			( [ ymd, name ] ) =>
				`\t'${ ymd }' => '${ escapePhpString( name ) }',`
		)
		.join( '\n' );

	return `<?php
/**
 * 国民の祝日一覧（Y-m-d => 祝日名）。
 *
 * このファイルは自動生成です。直接編集しないでください。
 * 生成元: ${ SOURCE_URL }
 * 生成コマンド: node bin/build-holidays.js <ダウンロードした CSV のパス>
 *
 * @package VKBookingManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
${ rows }
);
`;
}

/**
 * 変換結果が祝日一覧として妥当かを確認する。
 * 文字化けや形式変更で読めなかった CSV を、そのまま書き出さないための検査。
 *
 * @param {Array<[string, string]>} holidays 日付昇順の祝日一覧。
 * @return {string|null} 問題があればエラーメッセージ。問題なければ null。
 */
function validateHolidays( holidays ) {
	if ( 0 === holidays.length ) {
		return 'No holidays found in the CSV.';
	}

	if ( ! holidays.some( ( [ , name ] ) => '元日' === name ) ) {
		return "The CSV has no 元日 (New Year's Day). The file may be garbled or its format may have changed.";
	}

	return null;
}

/**
 * CLI のエントリーポイント。
 *
 * @param {string[]} argv 引数（CSV のパス）。
 * @return {number} 終了コード。
 */
function main( argv ) {
	if ( ! argv[ 0 ] ) {
		console.error(
			'Usage: node bin/build-holidays.js <path-to-syukujitsu.csv>'
		);
		return 1;
	}

	const csvText = new TextDecoder( 'shift_jis' ).decode(
		fs.readFileSync( argv[ 0 ] )
	);
	const holidays = parseHolidays( csvText );
	const validationError = validateHolidays( holidays );
	if ( validationError ) {
		console.error( validationError );
		return 1;
	}

	fs.writeFileSync( OUTPUT_PATH, buildPhpSource( holidays ) );
	console.log( `Wrote ${ holidays.length } holidays to ${ OUTPUT_PATH }` );
	return 0;
}

if ( require.main === module ) {
	process.exit( main( process.argv.slice( 2 ) ) );
}

module.exports = {
	toYmd,
	parseHolidays,
	escapePhpString,
	buildPhpSource,
	validateHolidays,
};
