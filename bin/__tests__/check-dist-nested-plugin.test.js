/**
 * bin/check-dist-nested-plugin.js のユニットテスト（#484 真因対応）。
 *
 * 配布物に含まれる、プラグインヘッダー付き PHP ファイルの入れ子検出ロジックを検証する。
 * 実ファイルの走査が主目的の関数のため、os.tmpdir() 配下に使い捨てのディレクトリ構成を
 * 作って検証する（各テストで作成・afterEach で必ず削除する）。
 */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const {
	hasPluginHeader,
	collectDistViolations,
	checkTarget,
	main,
} = require( '../check-dist-nested-plugin' );

// 各テストで使う一時ディレクトリ。afterEach で確実に削除する。
let tmpDir;

beforeEach( () => {
	tmpDir = fs.mkdtempSync(
		path.join( os.tmpdir(), 'vkbm-check-dist-nested-plugin-' )
	);
} );

afterEach( () => {
	fs.rmSync( tmpDir, { recursive: true, force: true } );
} );

/**
 * 指定パスへ、親ディレクトリを含めてファイルを書き出すテスト用ヘルパー。
 *
 * @param {string} filePath 書き出し先の絶対パス。
 * @param {string} contents 書き出す内容。
 */
function writeFile( filePath, contents ) {
	fs.mkdirSync( path.dirname( filePath ), { recursive: true } );
	fs.writeFileSync( filePath, contents );
}

describe( 'hasPluginHeader', () => {
	it( '`Plugin Name:` ヘッダーを持つ PHP ファイルを検出する', () => {
		const filePath = path.join( tmpDir, 'sample.php' );
		writeFile(
			filePath,
			[
				'<?php',
				'/**',
				' * Plugin Name: Sample Plugin',
				' * Description: テスト用のダミープラグイン',
				' */',
			].join( '\n' )
		);

		expect( hasPluginHeader( filePath ) ).toBe( true );
	} );

	it( 'プラグインヘッダーが無い通常の PHP ファイルは検出しない', () => {
		const filePath = path.join( tmpDir, 'class-sample.php' );
		writeFile(
			filePath,
			[
				'<?php',
				'/**',
				' * サンプルクラス。',
				' */',
				'class Sample {}',
			].join( '\n' )
		);

		expect( hasPluginHeader( filePath ) ).toBe( false );
	} );

	it( '`Plugin Name` という文字列がコード中に出てくるだけでは誤検知しない', () => {
		const filePath = path.join( tmpDir, 'no-header.php' );
		writeFile(
			filePath,
			[
				'<?php',
				"// ここでは 'Plugin Name' という語を紹介しているだけで、",
				'// ヘッダー形式（行頭 + コロン）になっていない。',
				"echo 'Plugin Name is just a string here';",
			].join( '\n' )
		);

		expect( hasPluginHeader( filePath ) ).toBe( false );
	} );
} );

describe( 'collectDistViolations', () => {
	it( 'ルート直下のプラグイン本体ファイルは対象外にする', () => {
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		expect( collectDistViolations( tmpDir ) ).toEqual( [] );
	} );

	it( 'サブディレクトリに入れ子になったプラグインヘッダー付き PHP を検出する', () => {
		// ルート本体（対象外）。
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);
		// 入れ子のプラグインコピー（#484 の実際の事例と同じ構造）。
		const nestedFile = path.join(
			tmpDir,
			'vk-booking-manager-pro',
			'vk-booking-manager.php'
		);
		writeFile(
			nestedFile,
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		expect( collectDistViolations( tmpDir ) ).toEqual( [
			{ path: nestedFile, reason: 'nested-header' },
		] );
	} );

	it( '同名でなくても、深い階層のヘッダー付き PHP を検出する（同名限定にしない）', () => {
		const nestedFile = path.join(
			tmpDir,
			'some-other-dir-name',
			'entry.php'
		);
		writeFile(
			nestedFile,
			'<?php\n/**\n * Plugin Name: Some Other Plugin\n */'
		);

		expect( collectDistViolations( tmpDir ) ).toEqual( [
			{ path: nestedFile, reason: 'nested-header' },
		] );
	} );

	it( 'シンボリックリンクはたどらず、それ自体を違反として検出する', () => {
		// リンクの参照先（配布物ルートの外に置く。参照先の中身までは走査対象にしない）。
		const externalDir = fs.mkdtempSync(
			path.join( os.tmpdir(), 'vkbm-symlink-target-' )
		);
		try {
			writeFile(
				path.join( externalDir, 'vk-booking-manager.php' ),
				'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
			);

			const linkPath = path.join( tmpDir, 'linked-copy' );
			fs.symlinkSync( externalDir, linkPath, 'dir' );

			// リンク自体が違反として報告され、リンク先の中身（externalDir 配下）は
			// 走査対象になっていないこと（＝リンクをたどっていないこと）を確認する。
			expect( collectDistViolations( tmpDir ) ).toEqual( [
				{ path: linkPath, reason: 'symlink' },
			] );
		} finally {
			fs.rmSync( externalDir, { recursive: true, force: true } );
		}
	} );

	it( 'vendor 配下などヘッダーの無い通常の PHP ファイル群は誤検知しない', () => {
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);
		writeFile(
			path.join( tmpDir, 'vendor', 'some-lib', 'src', 'Class.php' ),
			'<?php\nclass Some_Lib_Class {}'
		);
		writeFile(
			path.join( tmpDir, 'src', 'class-example.php' ),
			'<?php\nclass Example {}'
		);

		expect( collectDistViolations( tmpDir ) ).toEqual( [] );
	} );
} );

describe( 'checkTarget', () => {
	// checkTarget() は結果をコンソールへ出力するため、@wordpress/jest-console の
	// 「console.log/error は明示的な期待とセットでのみ許容する」方針に従い、
	// 各テストで toHaveLogged()/toHaveErrored() を明示的に確認する。

	it( '存在しないディレクトリを指定すると失敗（1）を返し、エラーを出力する', () => {
		const missingDir = path.join( tmpDir, 'does-not-exist' );

		expect( checkTarget( missingDir ) ).toBe( 1 );
		expect( console ).toHaveErrored();
	} );

	it( '入れ子が無ければ成功（0）を返し、OK ログを出力する', () => {
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		expect( checkTarget( tmpDir ) ).toBe( 0 );
		expect( console ).toHaveLogged();
	} );

	it( '入れ子があれば失敗（1）を返し、NG メッセージを出力する', () => {
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);
		writeFile(
			path.join(
				tmpDir,
				'vk-booking-manager-pro',
				'vk-booking-manager.php'
			),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		expect( checkTarget( tmpDir ) ).toBe( 1 );
		expect( console ).toHaveErrored();
	} );

	it( 'ヘッダー入れ子とシンボリックリンクが同時に存在する場合、両方の見出しと対処文を出力する', () => {
		// ヘッダー入れ子（nested-header）。
		writeFile(
			path.join( tmpDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);
		const nestedFile = path.join(
			tmpDir,
			'vk-booking-manager-pro',
			'vk-booking-manager.php'
		);
		writeFile(
			nestedFile,
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		// シンボリックリンク（symlink）。参照先は配布物ルートの外に置く。
		const externalDir = fs.mkdtempSync(
			path.join( os.tmpdir(), 'vkbm-symlink-target-' )
		);
		try {
			writeFile(
				path.join( externalDir, 'vk-booking-manager.php' ),
				'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
			);
			const linkPath = path.join( tmpDir, 'linked-copy' );
			fs.symlinkSync( externalDir, linkPath, 'dir' );

			expect( checkTarget( tmpDir ) ).toBe( 1 );
			expect( console ).toHaveErrored();

			// 2種類の違反それぞれの見出し・対処文が両方とも出ていることを確認する
			// （どちらか一方だけが握りつぶされていないこと）。
			const errorMessages = console.error.mock.calls
				.map( ( args ) => args.join( ' ' ) )
				.join( '\n' );
			expect( errorMessages ).toContain(
				'プラグインヘッダー（Plugin Name:）を持つ PHP ファイルがあります'
			);
			expect( errorMessages ).toContain( nestedFile );
			expect( errorMessages ).toContain(
				'配布物の中にシンボリックリンクがあります'
			);
			expect( errorMessages ).toContain( linkPath );
			expect( errorMessages ).toContain(
				'#484 と同じ有効化失敗が再現します'
			);
			expect( errorMessages ).toContain(
				'有効なプラグインヘッダーがありません」エラーで有効化に失敗します'
			);
		} finally {
			fs.rmSync( externalDir, { recursive: true, force: true } );
		}
	} );

	it( '走査中に例外が発生した場合も、スタックトレースではなく整形されたメッセージで失敗（1）を返す', () => {
		// ディレクトリではなく通常ファイルを対象に指定すると、fs.existsSync() は true を返す一方、
		// collectDistViolations() 内の fs.readdirSync() が ENOTDIR で例外を投げる。
		// fail-closed の性質（検査できなければ 1 を返す）自体は変えず、出力だけを整形する。
		const notADir = path.join( tmpDir, 'not-a-directory.txt' );
		writeFile( notADir, 'dummy' );

		expect( checkTarget( notADir ) ).toBe( 1 );
		expect( console ).toHaveErrored();

		const errorMessages = console.error.mock.calls
			.map( ( args ) => args.join( ' ' ) )
			.join( '\n' );
		// 生の Error スタックトレースではなく、他の NG 出力と同じ形式の案内文が出ていることを確認する。
		expect( errorMessages ).toContain(
			'検査中にエラーが発生したため、ビルドを中止します'
		);
	} );
} );

describe( 'main', () => {
	// main() は process.argv を読むため、各テストで退避・復元する。
	let originalArgv;

	beforeEach( () => {
		originalArgv = process.argv;
	} );

	afterEach( () => {
		process.argv = originalArgv;
	} );

	it( '引数なしで実行すると失敗（1）を返す', () => {
		process.argv = [ 'node', 'check-dist-nested-plugin.js' ];

		expect( main() ).toBe( 1 );
		expect( console ).toHaveErrored();
	} );

	it( '複数ターゲットのうち1件でも違反があれば、早期に打ち切らず全件検査したうえで失敗（1）を返す', () => {
		// 1件目: 問題なし。
		const cleanDir = path.join( tmpDir, 'clean' );
		writeFile(
			path.join( cleanDir, 'vk-booking-manager.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager\n */'
		);

		// 2件目: 入れ子違反あり。
		const brokenDir = path.join( tmpDir, 'broken' );
		writeFile(
			path.join( brokenDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);
		writeFile(
			path.join(
				brokenDir,
				'vk-booking-manager-pro',
				'vk-booking-manager.php'
			),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		// 3件目: これも問題なし。早期打ち切りされていれば検査されない位置に置く。
		const anotherCleanDir = path.join( tmpDir, 'another-clean' );
		writeFile(
			path.join( anotherCleanDir, 'vk-booking-manager.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager\n */'
		);

		process.argv = [
			'node',
			'check-dist-nested-plugin.js',
			cleanDir,
			brokenDir,
			anotherCleanDir,
		];

		expect( main() ).toBe( 1 );
		expect( console ).toHaveErrored();
		expect( console ).toHaveLogged();

		// 早期打ち切りされていれば OK ログは1件（cleanDir 分）しか出ないため、
		// 3件目（anotherCleanDir）まで検査されたことをログで確認する。
		const logMessages = console.log.mock.calls
			.map( ( args ) => args.join( ' ' ) )
			.join( '\n' );
		expect( logMessages ).toContain( cleanDir );
		expect( logMessages ).toContain( anotherCleanDir );
	} );

	it( '8KB を超えた位置にあるプラグインヘッダーは検知しない（WordPress と読み取り範囲が揃っていることの担保）', () => {
		const targetDir = path.join( tmpDir, 'far-header' );
		writeFile(
			path.join( targetDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		// HEADER_READ_BYTES（8KB）を十分に超える分量のパディングをヘッダーの手前に置く。
		const padding = '// padding\n'.repeat( 1000 );
		writeFile(
			path.join( targetDir, 'nested', 'far-header.php' ),
			`<?php\n${ padding }/**\n * Plugin Name: Far Header\n */`
		);

		process.argv = [ 'node', 'check-dist-nested-plugin.js', targetDir ];

		expect( main() ).toBe( 0 );
		expect( console ).toHaveLogged();
	} );

	it( 'シンボリックリンク経由の入れ子も検知し、失敗（1）を返す', () => {
		const targetDir = path.join( tmpDir, 'with-symlink' );
		writeFile(
			path.join( targetDir, 'vk-booking-manager-pro.php' ),
			'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
		);

		const externalDir = fs.mkdtempSync(
			path.join( os.tmpdir(), 'vkbm-symlink-target-' )
		);
		try {
			writeFile(
				path.join( externalDir, 'vk-booking-manager.php' ),
				'<?php\n/**\n * Plugin Name: VK Booking Manager Pro\n */'
			);
			fs.symlinkSync(
				externalDir,
				path.join( targetDir, 'linked-copy' ),
				'dir'
			);

			process.argv = [ 'node', 'check-dist-nested-plugin.js', targetDir ];

			expect( main() ).toBe( 1 );
			expect( console ).toHaveErrored();

			// reason による出し分け（symlink 用の文面）が実際に効いていることを、
			// ターゲットがディレクトリでない場合のテストと同じ粒度で文面まで確認する。
			const errorMessages = console.error.mock.calls
				.map( ( args ) => args.join( ' ' ) )
				.join( '\n' );
			expect( errorMessages ).toContain(
				'配布物の中にシンボリックリンクがあります'
			);
		} finally {
			fs.rmSync( externalDir, { recursive: true, force: true } );
		}
	} );
} );
