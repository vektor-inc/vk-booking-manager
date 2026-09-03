const fs = require( 'fs' );

// 自動生成ファイルの先頭に挿入する警告バナー（PHP コメント）。
// このバナー文言は src 内の生成ファイル先頭に入っているものと
// バイト単位で一致させる必要がある（冪等判定が一致前提のため）。
const BANNER = `/**
 * ⚠️ 自動生成ファイル — 直接編集しないでください。
 *
 * このファイルはビルド／dist 処理（bin/switch-resource-config.js /
 * bin/switch-resource-config-dev.js）が \`*-free.php\` / \`*-pro.php\` から
 * コピーして生成し、ビルドのたびに上書きします。直接編集しても次のビルドで失われます。
 * 変更が必要な場合は対応する \`*-free.php\` / \`*-pro.php\`（差し替え元）を編集してください。
 */`;

// 冪等判定に使う固定文字列。これがファイル中にあれば既にバナー注入済みとみなす。
// 新バナー1行目に含まれる文言と一致させること。
const BANNER_MARKER = '自動生成ファイル';

/**
 * 生成済みの PHP ファイルへ警告バナーを注入する。
 *
 * `<?php` 開きタグ直後（既存 docblock の前）にバナーを挿入する。
 * すでにバナーマーカーを含む場合は何もしない（冪等）。
 *
 * @param {string} targetPath 対象ファイルの絶対パス。
 * @return {void}
 */
function injectGeneratedFileBanner( targetPath ) {
	const contents = fs.readFileSync( targetPath, 'utf8' );

	// すでにバナーが入っていれば何もしない（冪等）。
	if ( contents.includes( BANNER_MARKER ) ) {
		return;
	}

	// `<?php` の直後（改行の有無に関わらず）にバナーを挿入する。
	const openTagMatch = contents.match( /^<\?php[ \t]*\r?\n?/ );
	if ( ! openTagMatch ) {
		// `<?php` 開きタグが無いファイルは想定外。バナー未挿入の生成物を
		// 黙って通さず、早期検知のため例外を投げる。
		throw new Error(
			`Expected a PHP file with an opening <?php tag: ${ targetPath }`
		);
	}

	const openTag = openTagMatch[ 0 ];
	const rest = contents.slice( openTag.length );

	// 開きタグ → 改行 → バナー → 空行 → 既存の中身、の順で組み立てる。
	const updated = '<?php\n' + BANNER + '\n\n' + rest;

	fs.writeFileSync( targetPath, updated );
}

module.exports = {
	BANNER,
	BANNER_MARKER,
	injectGeneratedFileBanner,
};
