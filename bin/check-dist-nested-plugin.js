#!/usr/bin/env node

/**
 * 配布物（dist/<パッケージ名>）配下に、プラグインヘッダーを持つ PHP ファイルが
 * ルート直下より深い階層で入れ子になっていないかを検査するチェックスクリプト。
 *
 * 背景（#484）:
 * `dist:stage:free` / `dist:stage:pro` の rsync は作業ツリーをまるごと複製するため、
 * 作業ツリー内に誤って残った「プラグイン自身のコピー」（ディレクトリ名は問わない）が
 * そのまま配布物へ取り込まれてしまうことがある。WordPress は zip インストール直後、
 * 有効化リンクの宛先を決めるために `Plugin_Upgrader::plugin_info()` が
 * `get_plugins( '/<インストールフォルダ>' )` を呼ぶが、これはインストールフォルダの
 * 直下だけでなくサブディレクトリの中のヘッダー付き PHP も拾う。複数見つかった場合
 * WordPress 本体は先頭の1件を無条件で採用する（ソース中のコメントに
 * `// Assume the requested plugin is the first in the list.` とある通り、採用順は
 * ファイルシステムの読み出し順まかせ）。入れ子側が先頭に採用されると、有効化リンクの
 * 宛先が3階層のパス（例: `vk-booking-manager-pro/vk-booking-manager-pro/vk-booking-manager.php`）
 * になるが、有効化処理の入口 `validate_plugin()` が使う `get_plugins()`
 * （プラグインディレクトリ全体が対象）は2階層までしか拾わないため、このパスが見つからず
 * 「有効なプラグインヘッダーがありません」（no_plugin_header）エラーで有効化に失敗する。
 *
 * 方針: 同名ディレクトリ（`vk-booking-manager` / `vk-booking-manager-pro`）を rsync の
 * 除外指定に足すだけでは、壊れた状態を黙って握りつぶすうえ、別名で複製が置かれた場合に
 * 素通りしてしまう。そのため、WordPress がプラグインとして認識する条件
 * （`Plugin Name:` ヘッダーを持つこと）に合わせて、配布物ルート直下より深い階層に
 * ヘッダー付き PHP ファイルが無いかを検査し、見つかったらビルドを失敗させる。
 *
 * ヘッダー検出の正規表現は WordPress 本体の `get_file_data()`
 * （wp-includes/functions.php）が使う正規表現をそのまま踏襲する。これにより
 * 「プラグインとして認識されるファイルかどうか」の判定基準を WordPress 本体と揃え、
 * `vendor/` や `build/` 配下の通常の PHP ファイルを誤検知しないようにする。
 *
 * シンボリックリンクについて: `fs.Dirent#isDirectory()` / `isFile()` は、対象が
 * シンボリックリンクの場合どちらも false を返す（リンク自体の種別を答えるだけで、
 * リンク先の種別までは答えない仕様のため）。
 * そのままではリンクが走査から丸ごと外れてしまい、`rsync -a` がリンクをリンクのまま
 * コピーし、`zip -qr`（`-y` なし）がリンクをたどって実体を格納するという組み合わせにより、
 * リンク先に入れ子のプラグインコピーがあっても検査だけが素通りする穴になる。そのため
 * `fs.statSync()` でリンク先をたどって判定するのではなく、シンボリックリンクを見つけた
 * 時点でたどらずに違反として報告する（リンク先をたどると走査範囲が配布物の外へ出てしまう）。
 * 配布物にシンボリックリンクが含まれること自体が想定外のため、これで判定する。
 *
 * @package
 */

const fs = require( 'fs' );
const path = require( 'path' );

// WordPress の get_file_data() がヘッダー検出のために読み取るバイト数と同じ
// （wp-includes/functions.php: `fread( $fp, 8 * KB_IN_BYTES )`）。
const HEADER_READ_BYTES = 8 * 1024;

// WordPress の get_file_data() が `Plugin Name` ヘッダーを検出する際に使う
// 正規表現と同じもの（`/^[ \t\/*#@]*' . preg_quote( 'Plugin Name', '/' ) . ':(.*)$/mi`）。
const PLUGIN_NAME_HEADER_PATTERN = /^[ \t/*#@]*Plugin Name:(.*)$/im;

/**
 * 指定した PHP ファイルの先頭 8KB に、WordPress が認識するプラグインヘッダー
 * （`Plugin Name:`）が含まれているかを判定する。
 *
 * @param {string} filePath 判定対象の PHP ファイルの絶対パス。
 * @return {boolean} プラグインヘッダーを検出できれば true。
 */
function hasPluginHeader( filePath ) {
	const fd = fs.openSync( filePath, 'r' );
	try {
		const buffer = Buffer.alloc( HEADER_READ_BYTES );
		const bytesRead = fs.readSync( fd, buffer, 0, HEADER_READ_BYTES, 0 );
		const contents = buffer.toString( 'utf8', 0, bytesRead );
		return PLUGIN_NAME_HEADER_PATTERN.test( contents );
	} finally {
		fs.closeSync( fd );
	}
}

/**
 * 配布物ルート配下を再帰的に走査し、入れ子のプラグインコピーとして違反となる項目を列挙する。
 *
 * 検出する違反は2種類:
 * - `nested-header`: ルート直下（深さ1）より深い階層にある、プラグインヘッダー付き PHP
 *   ファイル。ルート直下のヘッダー付き PHP はプラグイン本体（例: `vk-booking-manager.php`）
 *   そのものなので対象外。
 * - `symlink`: 配布物の中に含まれるシンボリックリンク。リンク先はたどらない（上部のコメント参照）。
 *
 * @param {string} rootDir 配布物パッケージのルートディレクトリ（絶対パス）。
 * @return {Array<{path: string, reason: 'nested-header'|'symlink'}>} 見つかった違反の一覧。
 */
function collectDistViolations( rootDir ) {
	const violations = [];

	/**
	 * @param {string} dir   走査対象ディレクトリの絶対パス。
	 * @param {number} depth rootDir からの深さ（rootDir 直下のファイルが深さ1）。
	 */
	function walk( dir, depth ) {
		for ( const entry of fs.readdirSync( dir, {
			withFileTypes: true,
		} ) ) {
			const entryPath = path.join( dir, entry.name );

			// シンボリックリンクは isDirectory()/isFile() がどちらも false になり、
			// 判定より先に見つけないと走査から丸ごと外れてしまう。たどらずに違反として報告する。
			if ( entry.isSymbolicLink() ) {
				violations.push( { path: entryPath, reason: 'symlink' } );
				continue;
			}

			if ( entry.isDirectory() ) {
				walk( entryPath, depth + 1 );
				continue;
			}

			if (
				! entry.isFile() ||
				! entry.name.toLowerCase().endsWith( '.php' )
			) {
				continue;
			}

			// ルート直下（深さ1）はプラグイン本体ファイルなので対象外。
			if ( depth <= 1 ) {
				continue;
			}

			if ( hasPluginHeader( entryPath ) ) {
				violations.push( { path: entryPath, reason: 'nested-header' } );
			}
		}
	}

	walk( rootDir, 1 );

	return violations;
}

/**
 * 検査対象ディレクトリ1件を検査し、結果をコンソールへ出力する。
 *
 * 走査中の例外（対象がディレクトリでない・読み取り権限が無い等）は catch し、
 * 生のスタックトレースではなく他の NG 出力と同じ形式のメッセージを出して 1 を返す
 * （fail-closed の性質、つまり検査できない状況では成功扱いにしない、という点は変えない）。
 *
 * @param {string} target 検査対象ディレクトリ（相対パス／絶対パスいずれも可）。
 * @return {number} 問題が無ければ 0、問題があれば 1。
 */
function checkTarget( target ) {
	const rootDir = path.resolve( target );

	if ( ! fs.existsSync( rootDir ) ) {
		console.error(
			`[check-dist-nested-plugin] NG: 検査対象のディレクトリが見つかりません: ${ rootDir }`
		);
		return 1;
	}

	let violations;
	try {
		violations = collectDistViolations( rootDir );
	} catch ( error ) {
		console.error( '' );
		console.error(
			`[check-dist-nested-plugin] NG: ${ rootDir } の検査中にエラーが発生したため、` +
				'ビルドを中止します。'
		);
		console.error( `  - ${ error.message }` );
		console.error( '' );
		return 1;
	}

	if ( violations.length === 0 ) {
		console.log(
			`[check-dist-nested-plugin] OK: ${ rootDir } に入れ子のプラグインコピーは見つかりませんでした。`
		);
		return 0;
	}

	const nestedHeaderViolations = violations.filter(
		( violation ) => violation.reason === 'nested-header'
	);
	const symlinkViolations = violations.filter(
		( violation ) => violation.reason === 'symlink'
	);

	console.error( '' );
	console.error(
		`[check-dist-nested-plugin] NG: ${ rootDir } 配下で、配布物として想定しない状態が見つかりました。`
	);

	if ( nestedHeaderViolations.length > 0 ) {
		console.error(
			'  ルート直下より深い階層に、プラグインヘッダー（Plugin Name:）を持つ PHP ファイルがあります。'
		);
		for ( const violation of nestedHeaderViolations ) {
			console.error( `    - ${ violation.path }` );
		}
	}

	if ( symlinkViolations.length > 0 ) {
		console.error(
			'  配布物の中にシンボリックリンクがあります（リンク先はたどらず検出しています）。'
		);
		for ( const violation of symlinkViolations ) {
			console.error( `    - ${ violation.path }` );
		}
	}

	console.error( '' );

	if ( nestedHeaderViolations.length > 0 ) {
		console.error(
			'  このまま配布すると、利用者がこの zip をインストールした直後、WordPress の' +
				'「有効化」リンクが上記の入れ子側ファイルを指してしまい、' +
				'「有効なプラグインヘッダーがありません」エラーで有効化に失敗します（#484）。'
		);
		console.error(
			'  対処: 作業ツリーの中に、プラグイン自身のコピー（上記パスを含むディレクトリ）が' +
				'紛れ込んでいます。作業ツリーから該当ディレクトリを取り除いてから、ビルドをやり直してください。'
		);
	}

	if ( symlinkViolations.length > 0 ) {
		console.error(
			'  配布物を作る rsync -a はシンボリックリンクをリンクのままコピーし、' +
				'zip 化する zip -qr（-y なし）はリンクをたどって実体を格納するため、' +
				'リンク先に入れ子のプラグインコピーがあると、利用者側では #484 と同じ有効化失敗が再現します。'
		);
		console.error(
			'  対処: 上記のシンボリックリンクが作業ツリーやビルド元に誤って含まれていないか確認し、' +
				'不要であれば取り除いてからビルドをやり直してください。'
		);
	}

	console.error( '' );

	return 1;
}

/**
 * エントリーポイント。
 *
 * コマンドライン引数で渡された配布物ディレクトリ（1件以上）をすべて検査する。
 *
 * @return {number} 全て問題無ければ 0、いずれかで問題があれば 1。
 */
function main() {
	const targets = process.argv.slice( 2 );

	if ( targets.length === 0 ) {
		console.error(
			'[check-dist-nested-plugin] 使い方: node bin/check-dist-nested-plugin.js <配布物ディレクトリ> [<配布物ディレクトリ> ...]'
		);
		return 1;
	}

	let exitCode = 0;
	for ( const target of targets ) {
		if ( checkTarget( target ) !== 0 ) {
			exitCode = 1;
		}
	}

	return exitCode;
}

if ( require.main === module ) {
	// process.exit() は保留中の書き込みを待たずにプロセスを終わらせるため、
	// npm 経由の実行（出力が常にパイプになる）では違反メッセージが途中で
	// 切れる可能性がある。処理はすべて同期のため、exitCode の設定だけで
	// 自然に終了させれば書き込みは完了してから終わる。
	process.exitCode = main();
}

module.exports = {
	hasPluginHeader,
	collectDistViolations,
	checkTarget,
	main,
};
