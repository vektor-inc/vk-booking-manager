#!/usr/bin/env node

/**
 * Pro 版限定の早期 return ガード（`is_pro_edition()` / `is_free_edition()` の
 * if 条件式を直接呼び出し、無料版なら早期 return する分岐）を持つメソッドを
 * src/ から自動で列挙し、それを直接呼ぶ（または ReflectionMethod で呼ぶ）
 * テストメソッドに、無料版でスキップする指定（`Pro_Upsell::is_free_edition()` の
 * 非否定条件 + `markTestSkipped()`）が無ければ検出して失敗させるチェックスクリプト。
 *
 * 背景: #416・#467 で「Pro 版限定機能のテストにスキップ指定を付け忘れる」事故が
 * 2回続けて起きた。1回目（#416）は付け忘れた箇所を直すだけで再発防止を入れなかった
 * ため、2回目（#467）が起きた。このスクリプトは、そのうち「if 条件式で直接
 * is_pro_edition()/is_free_edition() を呼び、早期 return するメソッドを、
 * テストメソッドが直接（または ReflectionMethod 経由で）呼んでいる」という
 * 具体的な形だけを検出する。「Pro 版限定機能のテストにスキップ指定が無い」という
 * 問題全般を検出できるわけではない（下記「検出できない既知の範囲」を参照）。
 *
 * 設計方針:
 * - 対象メソッドの列挙は手書きリストにせず、src/ のソースコードから自動導出する
 *   （手書きリストにすると、リスト自体の更新忘れが新たな付け忘れ経路になるため）。
 * - 完全な PHP パーサーではなく、正規表現 + 括弧の対応チェックによる軽量ヒューリスティックで
 *   実装する（wp-env 不要・数秒で終わることを優先する）。
 * - 自動導出で誤検知が出る場合は、対象のテストメソッド内に理由付きの
 *   `// @pro-gate-ignore <候補メソッド名>: <理由>` コメントを書けば、その候補メソッドの
 *   呼び出しだけを除外できる（他の候補メソッドの呼び出しがあれば、そちらは除外されない）。
 *
 * 検出できない既知の範囲（安藤レビュー指摘・#467）:
 * - 変数に一度代入してから判定する間接的なガード
 *   （例: `$is_pro = class_exists(...) && ...is_pro_edition(...); if ( $is_pro ) { ... }`）。
 *   if 条件式の中で is_pro_edition()/is_free_edition() を直接呼んでいるケースのみを対象にしている。
 * - Free 版／Pro 版で同名クラスの実装ファイルそのものを差し替える構成
 *   （#418 で問題になった `Staff_Editor_Free` 相当の型）。本リポジトリには
 *   `src/staff/class-staff-editor.php` と `src/staff/class-staff-editor-pro.php` のように、
 *   同じクラス名（`Staff_Editor`）を複数ファイルで定義し、`bin/switch-resource-config.js` が
 *   ビルド時に `class-staff-editor.php` を編集版に応じて差し替える構成があるが、
 *   このスクリプトはファイルごとに静的解析するだけで「どちらのファイルが実際に
 *   ロードされるか」を判定しないため、片方のファイルにしか無いガードを見落とす。
 * - 無料版で値を false へ上書きするだけで return を伴わない形
 *   （例: `src/provider-settings/class-settings-sanitizer.php` の
 *   `if ( Pro_Upsell::is_free_edition() ) { $data['x'] = false; }`）。
 *   return を伴う早期リターンの if ブロックだけを対象にしているため検出しない。
 * - `test_` で始まらないヘルパーメソッド経由の呼び出し。テストメソッド
 *   （`test_` で始まる名前）本体だけを直接走査するため、`test_xxx()` が
 *   非 `test_` のヘルパーメソッドを呼び、そのヘルパーの中で候補メソッドを
 *   呼んでいる場合は検出できない。
 *
 * @package
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT_DIR = path.resolve( __dirname, '..' );
const SRC_DIR = path.join( ROOT_DIR, 'src' );
const TESTS_DIR = path.join( ROOT_DIR, 'tests', 'phpunit' );

/**
 * 指定ディレクトリ配下の .php ファイルを再帰的に列挙する。
 *
 * @param {string} dir 走査対象ディレクトリ。
 * @return {string[]} 見つかった .php ファイルの絶対パス一覧。
 */
function collectPhpFiles( dir ) {
	const results = [];
	if ( ! fs.existsSync( dir ) ) {
		return results;
	}
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const fullPath = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			results.push( ...collectPhpFiles( fullPath ) );
		} else if ( entry.isFile() && entry.name.endsWith( '.php' ) ) {
			results.push( fullPath );
		}
	}
	return results;
}

/**
 * openIndex（開き括弧の位置）から対応する閉じ括弧の位置を探す。
 *
 * 文字列リテラル（シングル／ダブルクォート・エスケープ考慮）と
 * コメント（`//`・`#`・`/* *‍/`）の中の括弧は数えないようにスキップする。
 * ヒアドキュメント／ナウドキュメントは本リポジトリの src/・tests/ では
 * 未使用のため未対応（使われた場合、括弧の対応チェックが崩れる可能性がある。
 * その場合は下記 `extractFunctions()` の「括弧の対応が取れず除外した関数」の
 * カウントに現れ、CI が失敗として検出する）。
 *
 * @param {string} text      走査対象の文字列。
 * @param {number} openIndex 開き括弧の位置（text[openIndex] === openChar であること）。
 * @param {string} openChar  開き括弧の文字（例: '(' '{'）。
 * @param {string} closeChar 閉じ括弧の文字（例: ')' '}'）。
 * @return {number} 対応する閉じ括弧の位置。見つからなければ -1。
 */
function findMatchingBracket( text, openIndex, openChar, closeChar ) {
	let depth = 0;
	let i = openIndex;
	const len = text.length;

	while ( i < len ) {
		const ch = text[ i ];

		// 行コメント（//）をスキップする。
		if ( ch === '/' && text[ i + 1 ] === '/' ) {
			const nl = text.indexOf( '\n', i );
			i = nl === -1 ? len : nl + 1;
			continue;
		}
		// 行コメント（#）をスキップする。
		if ( ch === '#' ) {
			const nl = text.indexOf( '\n', i );
			i = nl === -1 ? len : nl + 1;
			continue;
		}
		// ブロックコメント（/* ... */）をスキップする。
		if ( ch === '/' && text[ i + 1 ] === '*' ) {
			const close = text.indexOf( '*/', i + 2 );
			i = close === -1 ? len : close + 2;
			continue;
		}
		// シングルクォート文字列をスキップする（\' エスケープ考慮）。
		// 文字列中の `/*` `//` `#` をコメント開始と誤認しないよう、文字列全体を
		// 一つのトークンとして読み飛ばす（中身が何であれコメント判定より先に処理する）。
		if ( ch === "'" ) {
			i++;
			while ( i < len && text[ i ] !== "'" ) {
				i += text[ i ] === '\\' ? 2 : 1;
			}
			i++;
			continue;
		}
		// ダブルクォート文字列をスキップする（\" エスケープ考慮）。同上の理由。
		if ( ch === '"' ) {
			i++;
			while ( i < len && text[ i ] !== '"' ) {
				i += text[ i ] === '\\' ? 2 : 1;
			}
			i++;
			continue;
		}

		if ( ch === openChar ) {
			depth++;
		} else if ( ch === closeChar ) {
			depth--;
			if ( depth === 0 ) {
				return i;
			}
		}
		i++;
	}

	return -1;
}

/**
 * fromIndex 以降で最初に現れる '{'（本体の開始）の位置を返す。
 * 先に ';' が現れた場合は本体を持たない宣言（抽象メソッド等）とみなし -1 を返す。
 * 文字列リテラルの中身は読み飛ばす（中の `;` `{` をコメント・本体境界と誤認しないため）。
 *
 * @param {string} text      走査対象の文字列。
 * @param {number} fromIndex 走査開始位置。
 * @return {number} '{' の位置。本体が無ければ -1。
 */
function findBodyStart( text, fromIndex ) {
	let i = fromIndex;
	const len = text.length;
	while ( i < len ) {
		const ch = text[ i ];
		if ( ch === '/' && text[ i + 1 ] === '/' ) {
			const nl = text.indexOf( '\n', i );
			i = nl === -1 ? len : nl + 1;
			continue;
		}
		if ( ch === '/' && text[ i + 1 ] === '*' ) {
			const close = text.indexOf( '*/', i + 2 );
			i = close === -1 ? len : close + 2;
			continue;
		}
		if ( ch === "'" || ch === '"' ) {
			const quote = ch;
			i++;
			while ( i < len && text[ i ] !== quote ) {
				i += text[ i ] === '\\' ? 2 : 1;
			}
			i++;
			continue;
		}
		if ( ch === '{' ) {
			return i;
		}
		if ( ch === ';' ) {
			return -1;
		}
		i++;
	}
	return -1;
}

/**
 * コメント（`//`・`#`・`/* *‍/`）をスペースに置き換えたテキストを返す。
 * `maskStrings` が true のときは、文字列リテラルの中身もスペースに置き換える。
 * `maskStrings` が false のときも、文字列リテラルの範囲そのものは常に「1つの
 * トークン」として読み飛ばす（実際の文字はそのまま出力する）ため、文字列の中に
 * 含まれる `/*` `//` `#` をコメントの開始と誤認することはない
 * （例: `'image/*'` `'http://example.com'` `'#fff'` はいずれもコメント扱いされない）。
 * 改行は保持するため、以降の行番号計算は狂わない。長さは元のテキストと完全に
 * 一致するため、返り値に対する正規表現マッチの `index` は元のテキストの位置として
 * そのまま使える。
 *
 * `class Xxx` や `function xxx(` をコメント・文字列内の紛らわしいテキスト
 * （例: `/* translators: ... class name. *‍/` というコメント）と誤認しないために使う。
 *
 * @param {string}  text        対象テキスト。
 * @param {boolean} maskStrings true ならシングル／ダブルクォート文字列の中身もマスクする。
 * @return {string} マスク後のテキスト（長さは元と同じ）。
 */
function maskCommentsAndStrings( text, maskStrings ) {
	let out = '';
	let i = 0;
	const len = text.length;

	while ( i < len ) {
		const ch = text[ i ];

		if ( ch === '/' && text[ i + 1 ] === '/' ) {
			let j = i;
			while ( j < len && text[ j ] !== '\n' ) {
				j++;
			}
			out += text.slice( i, j ).replace( /[^\n]/g, ' ' );
			i = j;
			continue;
		}
		if ( ch === '#' ) {
			let j = i;
			while ( j < len && text[ j ] !== '\n' ) {
				j++;
			}
			out += text.slice( i, j ).replace( /[^\n]/g, ' ' );
			i = j;
			continue;
		}
		if ( ch === '/' && text[ i + 1 ] === '*' ) {
			let j = text.indexOf( '*/', i + 2 );
			j = j === -1 ? len : j + 2;
			out += text.slice( i, j ).replace( /[^\n]/g, ' ' );
			i = j;
			continue;
		}
		// 文字列リテラルは maskStrings の値に関わらず、常に1トークンとして読み飛ばす。
		// こうしないと、文字列の中身に含まれる `/*` `//` `#` を上のコメント判定が
		// 拾ってしまい、以降のコードを丸ごとコメット扱いして読み飛ばしてしまう
		// （安藤レビュー指摘・#467: `'image/*'` 等での見落とし）。
		if ( ch === "'" || ch === '"' ) {
			const quote = ch;
			let j = i + 1;
			while ( j < len && text[ j ] !== quote ) {
				j += text[ j ] === '\\' ? 2 : 1;
			}
			j = Math.min( j + 1, len );
			const segment = text.slice( i, j );
			out += maskStrings ? segment.replace( /[^\n]/g, ' ' ) : segment;
			i = j;
			continue;
		}

		out += ch;
		i++;
	}

	return out;
}

/**
 * PHP ソース文字列から、名前付き関数・メソッドの本体範囲を列挙する。
 * 無名関数（クロージャ）は名前を持たないため対象外。
 *
 * 括弧の対応が取れず本体範囲を確定できなかった関数は `skipped` に集めて返す
 * （抽象メソッド・interface 宣言など、そもそも本体を持たない宣言は含めない。
 * それらは括弧不整合ではなく正常なケースのため）。呼び出し側はこの一覧を
 * 集計し、1件以上あれば検出漏れの可能性があるとして CI を失敗させる
 * （安藤レビュー指摘・#467）。
 *
 * @param {string} content PHP ソース全文。
 * @return {{functions: Array<{name: string, className: (string|null), bodyStart: number, bodyEnd: number, line: number}>, skipped: Array<{name: string, line: number, reason: string}>}}
 *   見つかった関数・メソッドの一覧と、括弧不整合で除外した関数の一覧。
 */
function extractFunctions( content ) {
	const functions = [];
	const skipped = [];

	// class・function の名前検出は、コメント・文字列の中身を誤認しないようマスク済みテキストに対して行う
	// （マスクは同じ長さを保つため、ここで得た index はそのまま content の位置として使える）。
	const declScanText = maskCommentsAndStrings( content, true );

	// class 宣言の位置を先に集め、関数の直前に現れた最後の class 名を所属クラスとみなす。
	const classPositions = [];
	const classRegex = /\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/g;
	let classMatch;
	while ( ( classMatch = classRegex.exec( declScanText ) ) ) {
		classPositions.push( {
			index: classMatch.index,
			name: classMatch[ 1 ],
		} );
	}

	/**
	 * 指定位置の直前で最後に見つかった class 名を返す。
	 *
	 * @param {number} index 判定対象の位置。
	 * @return {string|null} 所属クラス名。見つからなければ null。
	 */
	function classNameAt( index ) {
		let name = null;
		for ( const cp of classPositions ) {
			if ( cp.index <= index ) {
				name = cp.name;
			} else {
				break;
			}
		}
		return name;
	}

	const funcRegex = /\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/g;
	let m;
	while ( ( m = funcRegex.exec( declScanText ) ) ) {
		const name = m[ 1 ];
		const line = content.slice( 0, m.index ).split( '\n' ).length;
		const parenOpen = content.indexOf( '(', m.index );
		const parenClose = findMatchingBracket( content, parenOpen, '(', ')' );
		if ( parenClose === -1 ) {
			skipped.push( {
				name,
				line,
				reason: '引数リストの括弧 ( ) の対応が取れない',
			} );
			continue;
		}
		const bodyStart = findBodyStart( content, parenClose + 1 );
		if ( bodyStart === -1 ) {
			// 本体を持たない宣言（抽象メソッド・interface 等）。括弧不整合ではないので数えない。
			continue;
		}
		const bodyEnd = findMatchingBracket( content, bodyStart, '{', '}' );
		if ( bodyEnd === -1 ) {
			skipped.push( {
				name,
				line,
				reason: '本体の波括弧 { } の対応が取れない',
			} );
			continue;
		}
		functions.push( {
			name,
			className: classNameAt( m.index ),
			bodyStart,
			bodyEnd,
			line,
		} );
		// 本体内に定義された無名クロージャ等を誤って別関数として拾わないよう、
		// 走査位置を本体の終わりまで進める（無名クロージャは名前を持たないため
		// funcRegex 自体には元々マッチしないが、念のための安全策）。
		funcRegex.lastIndex = bodyEnd;
	}

	return { functions, skipped };
}

/**
 * body 内の if（elseif・else if 含む）ブロックを順に走査し、ブロック形式
 * （`if ( ... ) { ... }`）のものだけを対象に、条件式と本体をコールバックへ渡す。
 * 波括弧を持たない単文 if は対象外とする（このリポジトリの WPCS 準拠コードでは
 * 常に波括弧が付くため、対象外にしても実害は無い）。
 *
 * `hasProGateEarlyReturn()` と `hasSkipGuard()` の両方が使う共通ロジック。
 *
 * @param {string}                                     body     走査対象のテキスト（コメントをマスク済みであることを想定）。
 * @param {(condition: string, block: string) => void} callback 各 if ブロックに対して呼ばれる。
 */
function forEachIfBlock( body, callback ) {
	const ifRegex = /\b(?:else\s+if|elseif|if)\s*\(/g;
	let ifMatch;

	while ( ( ifMatch = ifRegex.exec( body ) ) ) {
		const parenOpen = body.indexOf( '(', ifMatch.index );
		const parenClose = findMatchingBracket( body, parenOpen, '(', ')' );
		if ( parenClose === -1 ) {
			continue;
		}
		const condition = body.slice( parenOpen + 1, parenClose );

		const braceStart = findBodyStart( body, parenClose + 1 );
		if ( braceStart === -1 ) {
			continue;
		}
		const braceEnd = findMatchingBracket( body, braceStart, '{', '}' );
		if ( braceEnd === -1 ) {
			continue;
		}
		const block = body.slice( braceStart, braceEnd + 1 );

		callback( condition, block );
	}
}

/**
 * 条件式の文字列（空白除去済み）の中に、指定した極性の
 * is_pro_edition()/is_free_edition() 呼び出しがあるかを判定する。
 *
 * @param {string}                             condNoSpace 空白を除去した条件式。
 * @param {'is_pro_edition'|'is_free_edition'} targetFn    対象の関数名。
 * @param {boolean}                            requireBang true なら `!` 付きの呼び出しだけを真とみなす。
 *                                                         false なら `!` の付かない呼び出しだけを真とみなす。
 * @return {boolean} 条件に合致する呼び出しがあれば true。
 */
function conditionCallsEditionCheck( condNoSpace, targetFn, requireBang ) {
	// クラス名の直前に完全修飾名を示す `\`（例: `\Free_Version_Deactivator::`）が
	// 付くケースを許容するため、先頭の `\` を任意（0〜1個）として許容する。
	const callRegex =
		/(!)?(\\?[A-Za-z_][\w\\]*::)?(is_pro_edition|is_free_edition)\(/g;
	let callMatch;
	while ( ( callMatch = callRegex.exec( condNoSpace ) ) ) {
		const hasBang = callMatch[ 1 ] === '!';
		const calledFn = callMatch[ 3 ];
		if ( calledFn === targetFn && hasBang === requireBang ) {
			return true;
		}
	}
	return false;
}

/**
 * 関数本体の中に「Pro 版限定の早期 return ガード」があるかどうかを判定する。
 *
 * 対象とするのは、次のいずれかの極性を持つ if 条件式の直下ブロックに
 * return 文が含まれるケースのみ（変数に一度代入してからの間接判定は対象外。
 * 詳しくはスクリプト冒頭の「検出できない既知の範囲」を参照）。
 *
 *   - `is_pro_edition(` の直前に `!` がある（＝Pro 版でなければ return）
 *   - `is_free_edition(` の直前に `!` が無い（＝無料版なら return）
 *
 * @param {string}                               content 関数を含む PHP ソース全文。
 * @param {{bodyStart: number, bodyEnd: number}} fn      extractFunctions() が返した関数情報。
 * @return {boolean} Pro 版限定の早期 return ガードを持てば true。
 */
function hasProGateEarlyReturn( content, fn ) {
	// コメント中の紛らわしい文字列（例: `is_pro_edition` に触れた説明コメント）を
	// 実コードと誤認しないよう、コメントをマスクしたテキストに対して走査する
	// （マスクは同じ長さを保つため、以降のインデックスは元の body とそのまま対応する）。
	const rawBody = content.slice( fn.bodyStart, fn.bodyEnd + 1 );
	const body = maskCommentsAndStrings( rawBody, false );

	let isProGateEarlyReturn = false;
	forEachIfBlock( body, ( condition, block ) => {
		if ( isProGateEarlyReturn ) {
			return;
		}
		const condNoSpace = condition.replace( /\s+/g, '' );
		const isProGate =
			conditionCallsEditionCheck( condNoSpace, 'is_pro_edition', true ) ||
			conditionCallsEditionCheck( condNoSpace, 'is_free_edition', false );
		if ( isProGate && /\breturn\b/.test( block ) ) {
			isProGateEarlyReturn = true;
		}
	} );
	return isProGateEarlyReturn;
}

/**
 * src/ 配下から Pro 版限定の早期 return ガードを持つメソッドを列挙する。
 *
 * @return {{candidates: Map<string, Array<{file: string, line: number, className: (string|null)}>>, skipped: Array<{file: string, name: string, line: number, reason: string}>}}
 *   候補メソッド（メソッド名 → 定義箇所一覧。同名メソッドが複数クラスに
 *   存在する場合に備え配列で保持する）と、括弧不整合で解析から除外した関数の一覧。
 */
function collectCandidates() {
	const candidates = new Map();
	const skipped = [];
	for ( const file of collectPhpFiles( SRC_DIR ) ) {
		const content = fs.readFileSync( file, 'utf8' );
		const { functions, skipped: fileSkipped } = extractFunctions( content );
		const relFile = path.relative( ROOT_DIR, file );

		for ( const s of fileSkipped ) {
			skipped.push( { file: relFile, ...s } );
		}

		for ( const fn of functions ) {
			if ( ! hasProGateEarlyReturn( content, fn ) ) {
				continue;
			}
			const list = candidates.get( fn.name ) || [];
			list.push( {
				file: relFile,
				line: fn.line,
				className: fn.className,
			} );
			candidates.set( fn.name, list );
		}
	}
	return { candidates, skipped };
}

/**
 * テストメソッドの本体が、指定した候補メソッド名を呼び出しているかを判定する。
 * `->name(` `::name(` の直接呼び出しと、ReflectionMethod 経由（'name' 文字列リテラル）の
 * 両方を検出する（private メソッドを ReflectionMethod でテストする書き方がこのリポジトリに多いため）。
 *
 * @param {string} body      テストメソッド本体。
 * @param {string} candidate 候補メソッド名。
 * @return {boolean} 呼び出しがあれば true。
 */
function testCallsCandidate( body, candidate ) {
	const escaped = candidate.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const callRegex = new RegExp( '(?:->|::)' + escaped + '\\s*\\(' );
	const reflectionRegex = new RegExp( '([\'"])' + escaped + '\\1' );
	return callRegex.test( body ) || reflectionRegex.test( body );
}

/**
 * テストメソッド本体が、無料版スキップの定型パターン
 * （`if ( Pro_Upsell::is_free_edition() ) { ... markTestSkipped( ... ); ... }`）を
 * 含むかを判定する。`is_free_edition()` の呼び出しと `markTestSkipped()` の呼び出しが
 * それぞれ存在するだけでは真としない。`is_free_edition()` を**否定せずに**条件とする
 * if ブロックの中に `markTestSkipped(` があることまで確認する（安藤レビュー指摘・#467:
 * `if ( ! Pro_Upsell::is_free_edition() ) { ... }` のような逆向きの条件で
 * 誤って「ガード済み」と判定しないため）。
 *
 * @param {string} body テストメソッド本体（コメントをマスク済みであることを想定）。
 * @return {boolean} 正しい向きのガードがあれば true。
 */
function hasSkipGuard( body ) {
	let guarded = false;
	forEachIfBlock( body, ( condition, block ) => {
		if ( guarded ) {
			return;
		}
		const condNoSpace = condition.replace( /\s+/g, '' );
		const isNonNegatedFreeCheck = conditionCallsEditionCheck(
			condNoSpace,
			'is_free_edition',
			false
		);
		if ( isNonNegatedFreeCheck && /markTestSkipped\s*\(/.test( block ) ) {
			guarded = true;
		}
	} );
	return guarded;
}

/**
 * テストメソッド本体から明示的な除外指定（候補メソッド名ごとの理由コメント）を読み取る。
 *
 * 書式: `// @pro-gate-ignore <候補メソッド名>: <理由>`（1行コメント限定。
 * コロンと理由（空でない1文字以上）が同じ行に無ければ除外として扱わない）。
 * 誤検知を人間が明示的に逃がすための唯一の抜け道として用意する。除外は
 * 指定した候補メソッドの呼び出しだけに効き、同じテストメソッドが別の候補
 * メソッドも呼んでいる場合、そちらは除外されない（安藤レビュー指摘・#467:
 * 後から別の Pro 版限定メソッドの呼び出しが増えても黙って除外されたままに
 * ならないようにするため）。
 *
 * @param {string} body テストメソッド本体（コメントをマスクしていない生のテキスト）。
 * @return {Map<string, string>} 候補メソッド名 → 除外理由。
 */
function getIgnoreReasons( body ) {
	const reasons = new Map();
	const regex =
		/\/\/[ \t]*@pro-gate-ignore\s+([A-Za-z_][A-Za-z0-9_]*)\s*:[ \t]*(\S[^\n]*)/g;
	let match;
	while ( ( match = regex.exec( body ) ) ) {
		reasons.set( match[ 1 ], match[ 2 ].trim() );
	}
	return reasons;
}

/**
 * 収集結果から、CI の合否（プロセス終了コード）を決める純粋関数。
 * ファイル I/O を含まないため、Jest から直接ユニットテストできる。
 *
 * @param {{candidateCount: number, skippedCount: number, violationCount: number}} summary
 * @return {number} 0（成功）または 1（失敗）。
 */
function decideExitCode( summary ) {
	// 候補メソッドが1件も見つからない場合（src/ が存在しない場合を含む）は、
	// 検出ロジックそのものが壊れている可能性が高いため、無検出のまま成功扱いにしない
	// （安藤レビュー指摘・#467）。
	if ( summary.candidateCount === 0 ) {
		return 1;
	}
	// 括弧の対応が取れず解析から除外した関数が1件でもあれば、検出漏れの可能性が
	// あるため失敗にする（安藤レビュー指摘・#467）。
	if ( summary.skippedCount > 0 ) {
		return 1;
	}
	if ( summary.violationCount > 0 ) {
		return 1;
	}
	return 0;
}

/**
 * メイン処理。src/ から候補メソッドを収集し、tests/phpunit/ を走査して違反を検出する。
 *
 * @return {number} プロセス終了コード（違反・検出漏れがあれば 1、無ければ 0）。
 */
function main() {
	if ( ! fs.existsSync( SRC_DIR ) ) {
		console.error(
			`[pro-gate-check] NG: ${ path.relative(
				ROOT_DIR,
				SRC_DIR
			) } が見つかりません。チェック対象を検出できないため失敗として扱います。`
		);
		return 1;
	}

	const { candidates, skipped: srcSkipped } = collectCandidates();
	const candidateNames = Array.from( candidates.keys() );

	if ( candidateNames.length === 0 ) {
		console.error(
			'[pro-gate-check] NG: src/ から Pro 版限定の早期 return ガードを持つメソッドが1件も見つかりませんでした。検出ロジックが壊れているか、対象コードの書き方が変わった可能性があるため失敗として扱います。'
		);
		return 1;
	}

	const violations = [];
	const excluded = [];
	const testSkipped = [];

	for ( const file of collectPhpFiles( TESTS_DIR ) ) {
		const content = fs.readFileSync( file, 'utf8' );
		const { functions, skipped: fileSkipped } = extractFunctions( content );
		const relFile = path.relative( ROOT_DIR, file );

		for ( const s of fileSkipped ) {
			testSkipped.push( { file: relFile, ...s } );
		}

		for ( const fn of functions.filter( ( f ) =>
			f.name.startsWith( 'test_' )
		) ) {
			const rawBody = content.slice( fn.bodyStart, fn.bodyEnd + 1 );
			// 呼び出し検出・スキップガード検出は、コメント中の紛らわしい記述に惑わされないよう
			// コメントをマスクしたテキストに対して行う（`// @pro-gate-ignore` 自体はコメントなので、
			// 除外理由の読み取りだけは下で rawBody に対して行う）。
			const body = maskCommentsAndStrings( rawBody, false );
			const matchedCandidates = candidateNames.filter( ( name ) =>
				testCallsCandidate( body, name )
			);
			if ( matchedCandidates.length === 0 ) {
				continue;
			}

			const ignoreReasons = getIgnoreReasons( rawBody );
			const guarded = hasSkipGuard( body );

			for ( const name of matchedCandidates ) {
				if ( ignoreReasons.has( name ) ) {
					excluded.push( {
						file: relFile,
						line: fn.line,
						test: fn.name,
						method: name,
						reason: ignoreReasons.get( name ),
					} );
					continue;
				}
				if ( guarded ) {
					continue;
				}
				violations.push( {
					file: relFile,
					line: fn.line,
					test: fn.name,
					method: name,
					defs: candidates.get( name ),
				} );
			}
		}
	}

	console.log(
		`[pro-gate-check] src/ から Pro 版限定の早期 return ガードを持つメソッドを ${ candidateNames.length } 件検出しました:`
	);
	for ( const name of candidateNames ) {
		for ( const def of candidates.get( name ) ) {
			const cls = def.className ? `${ def.className }::` : '';
			console.log(
				`  - ${ cls }${ name }()  (${ def.file }:${ def.line })`
			);
		}
	}

	if ( excluded.length > 0 ) {
		console.log( '[pro-gate-check] 明示的に除外されたテスト:' );
		for ( const e of excluded ) {
			console.log(
				`  - ${ e.file }:${ e.line } ${ e.test }() → ${ e.method }()  理由: ${ e.reason }`
			);
		}
	}

	const allSkipped = [ ...srcSkipped, ...testSkipped ];
	if ( allSkipped.length > 0 ) {
		console.error( '' );
		console.error(
			`[pro-gate-check] NG: 括弧の対応が取れず解析から除外した関数が ${ allSkipped.length } 件あります（検出漏れの可能性があるため失敗として扱います）:`
		);
		for ( const s of allSkipped ) {
			console.error(
				`  - ${ s.file }:${ s.line } ${ s.name }()  理由: ${ s.reason }`
			);
		}
	}

	const exitCode = decideExitCode( {
		candidateCount: candidateNames.length,
		skippedCount: allSkipped.length,
		violationCount: violations.length,
	} );

	if ( violations.length === 0 ) {
		if ( exitCode === 0 ) {
			console.log(
				'[pro-gate-check] OK: Pro 版限定メソッドを呼ぶテストに、無料版スキップ指定の付け忘れは見つかりませんでした。'
			);
		}
		return exitCode;
	}

	console.error( '' );
	console.error(
		`[pro-gate-check] NG: 無料版スキップ指定の付け忘れを ${ violations.length } 件検出しました。`
	);
	for ( const v of violations ) {
		const def = v.defs[ 0 ];
		const cls = def && def.className ? `${ def.className }::` : '';
		console.error( '' );
		console.error( `  ${ v.file }:${ v.line } ${ v.test }()` );
		console.error(
			`    Pro 版限定メソッド ${ cls }${ v.method }()${
				def ? `（${ def.file }:${ def.line }）` : ''
			} を呼んでいますが、無料版のスキップ指定がありません。`
		);
		console.error( '    対応例:' );
		console.error( '      if ( Pro_Upsell::is_free_edition() ) {' );
		console.error(
			"        $this->markTestSkipped( '<Pro 版限定機能であることを説明する理由>' );"
		);
		console.error( '      }' );
		console.error(
			'    誤検知の場合は、テストメソッド内に対象メソッド名と理由を添えて次のコメントを追加してください:'
		);
		console.error(
			`      // @pro-gate-ignore ${ v.method }: <このテストが対象外である理由>`
		);
	}
	console.error( '' );

	return exitCode;
}

if ( require.main === module ) {
	process.exit( main() );
}

module.exports = {
	findMatchingBracket,
	findBodyStart,
	maskCommentsAndStrings,
	extractFunctions,
	forEachIfBlock,
	conditionCallsEditionCheck,
	hasProGateEarlyReturn,
	testCallsCandidate,
	hasSkipGuard,
	getIgnoreReasons,
	collectCandidates,
	decideExitCode,
};
