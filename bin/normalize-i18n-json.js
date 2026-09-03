#!/usr/bin/env node

const fs = require( 'fs' );
const path = require( 'path' );

// wp-cli の `wp i18n make-json` が書き込む "generator" フィールドには、実行環境の
// wp-cli バージョン（例: "WP-CLI/2.12.0"）がそのまま入る。このため、翻訳内容が
// 変わっていなくても wp-cli の版が変わるだけで全 md5 JSON にコミット差分が発生し、
// レビューのノイズや不要なコンフリクトの原因になっていた。
// generator はランタイム（翻訳ロード）では一切使われない情報なので、固定値へ
// 正規化して版差由来の差分（churn）を根絶する。
const FIXED_GENERATOR = 'vk-booking-manager';

// languages ディレクトリ（このスクリプトはリポジトリルート直下の bin/ に置く想定）。
const LANGUAGES_DIR = path.join( __dirname, '..', 'languages' );

/**
 * languages/*.json の "generator" フィールドの値を固定文字列へ正規化する。
 *
 * ファイルの整形（wp-cli の minified 出力 / 集約 JSON の pretty 出力）や、その他の
 * フィールドには一切手を加えず、"generator" の値だけを置換する。これにより
 * フォーマット差による余計な差分を出さず、版差 churn だけを取り除く。
 *
 * @return {number} 変更したファイル数。
 */
function normalizeI18nJson() {
	if ( ! fs.existsSync( LANGUAGES_DIR ) ) {
		return 0;
	}

	const files = fs
		.readdirSync( LANGUAGES_DIR )
		.filter( ( name ) => name.endsWith( '.json' ) );

	let changed = 0;
	for ( const name of files ) {
		const filePath = path.join( LANGUAGES_DIR, name );
		const before = fs.readFileSync( filePath, 'utf8' );

		// "generator" の「値」部分だけを固定文字列へ置換する。
		// 前後の空白（`"generator":"x"` / `"generator": "x"` の両整形）と引用符は
		// キャプチャで温存し、値だけを差し替える。
		const after = before.replace(
			/("generator"\s*:\s*")[^"]*(")/,
			`$1${ FIXED_GENERATOR }$2`
		);

		if ( after !== before ) {
			fs.writeFileSync( filePath, after );
			changed++;
		}
	}

	console.log(
		`[normalize-i18n-json] "generator" フィールドを ${ changed } 件のファイルで正規化しました。`
	);

	return changed;
}

module.exports = { normalizeI18nJson, FIXED_GENERATOR };

// 単体実行にも対応する（例: `node bin/normalize-i18n-json.js`）。
if ( require.main === module ) {
	normalizeI18nJson();
}
