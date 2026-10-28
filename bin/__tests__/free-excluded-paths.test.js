/**
 * bin/lib/free-excluded-paths.js のユニットテスト（#695）。
 *
 * 無料版の dist から Pro 版専用のファイル・ディレクトリを削除する処理を、
 * os.tmpdir() 配下の使い捨てディレクトリで検証する。
 */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const {
	FREE_EXCLUDED_PATHS,
	removeFreeExcludedPaths,
} = require( '../lib/free-excluded-paths' );

let tmpDir;

beforeEach( () => {
	tmpDir = fs.mkdtempSync( path.join( os.tmpdir(), 'vkbm-free-excluded-' ) );
} );

afterEach( () => {
	fs.rmSync( tmpDir, { recursive: true, force: true } );
} );

/**
 * 使い捨てディレクトリ内にファイルを作る。
 *
 * @param {string} relativePath 作成するファイルの相対パス。
 */
function touch( relativePath ) {
	const filePath = path.join( tmpDir, relativePath );
	fs.mkdirSync( path.dirname( filePath ), { recursive: true } );
	fs.writeFileSync( filePath, '' );
}

describe( 'removeFreeExcludedPaths', () => {
	const testCases = [
		{
			name: 'ファイルとディレクトリが存在する場合 => 削除され、削除した相対パスが返る',
			files: [ 'a.php', 'dir/b.php', 'dir/sub/c.php', 'keep.php' ],
			paths: [ 'a.php', 'dir' ],
			expectedRemoved: [ 'a.php', 'dir' ],
			expectedExisting: [ 'keep.php' ],
			expectedMissing: [ 'a.php', 'dir/b.php', 'dir/sub/c.php' ],
		},
		{
			name: '対象が存在しない場合 => エラーにならず、返り値は空配列',
			files: [ 'keep.php' ],
			paths: [ 'none.php', 'nodir' ],
			expectedRemoved: [],
			expectedExisting: [ 'keep.php' ],
			expectedMissing: [ 'none.php' ],
		},
	];

	test.each( testCases )( '$name', ( testCase ) => {
		testCase.files.forEach( touch );

		const removed = removeFreeExcludedPaths( tmpDir, testCase.paths );

		expect( removed ).toEqual( testCase.expectedRemoved );
		testCase.expectedExisting.forEach( ( file ) => {
			expect( fs.existsSync( path.join( tmpDir, file ) ) ).toBe( true );
		} );
		testCase.expectedMissing.forEach( ( file ) => {
			expect( fs.existsSync( path.join( tmpDir, file ) ) ).toBe( false );
		} );
	} );
} );

describe( 'removeFreeExcludedPaths の安全確認', () => {
	test.each( [ [ '' ], [ '../x' ], [ '.' ] ] )(
		'相対パスが %p の場合 => 例外を投げ、dist の外を消さない',
		( relativePath ) => {
			expect( () =>
				removeFreeExcludedPaths( tmpDir, [ relativePath ] )
			).toThrow();
			expect( fs.existsSync( tmpDir ) ).toBe( true );
		}
	);
} );

describe( 'FREE_EXCLUDED_PATHS', () => {
	test( '絶対パスや上位ディレクトリ参照を含まず、Pro 版専用の自動更新ファイルを含む', () => {
		FREE_EXCLUDED_PATHS.forEach( ( relativePath ) => {
			expect( path.isAbsolute( relativePath ) ).toBe( false );
			expect( relativePath.includes( '..' ) ).toBe( false );
		} );
		expect( FREE_EXCLUDED_PATHS ).toContain( 'class-vkbm-pro-updater.php' );
		expect( FREE_EXCLUDED_PATHS ).toContain(
			'src/integrations/google-calendar'
		);
		expect( FREE_EXCLUDED_PATHS ).toContain(
			'src/integrations/ical-import'
		);
		expect( FREE_EXCLUDED_PATHS ).toContain(
			'tests/phpunit/integrations/ical-import'
		);
		expect( FREE_EXCLUDED_PATHS ).toContain(
			'assets/js/external-calendar.js'
		);
		expect( FREE_EXCLUDED_PATHS ).toContain(
			'tests/e2e/specs/issue-947-ical-import-admin.spec.ts'
		);
	} );
} );
