/**
 * bin/build-holidays.js のユニットテスト。ファイル I/O は行わず、純粋関数を検証する。
 */

// Jest の jsdom 環境にはグローバルの TextDecoder が無いため、Node 標準のものを使う。
const { TextDecoder } = require( 'util' );
const {
	toYmd,
	parseHolidays,
	escapePhpString,
	buildPhpSource,
	validateHolidays,
} = require( '../build-holidays' );

describe( 'toYmd', () => {
	test.each( [
		[ '2026/1/1', '2026-01-01' ],
		[ '2026/12/31', '2026-12-31' ],
		[ '1955/1/15', '1955-01-15' ],
		[ ' 2026/5/6 ', '2026-05-06' ],
		[ '2026/2/30', null ],
		[ '国民の祝日・休日月日', null ],
		[ '', null ],
	] )( '%s => %s', ( input, expected ) => {
		expect( toYmd( input ) ).toBe( expected );
	} );
} );

describe( 'parseHolidays', () => {
	test( '見出し行と空行を除き、日付順に並べてゼロ埋めする', () => {
		const csv = [
			'国民の祝日・休日月日,国民の祝日・休日名称',
			'2026/5/6,休日',
			'2026/1/1,元日',
			'',
		].join( '\r\n' );

		expect( parseHolidays( csv ) ).toEqual( [
			[ '2026-01-01', '元日' ],
			[ '2026-05-06', '休日' ],
		] );
	} );

	test( 'Shift_JIS のバイト列を TextDecoder で復号した文字列を処理できる', () => {
		// 「2026/1/1,元日」の Shift_JIS バイト列。
		const bytes = Uint8Array.from( [
			0x32, 0x30, 0x32, 0x36, 0x2f, 0x31, 0x2f, 0x31, 0x2c, 0x8c, 0xb3,
			0x93, 0xfa,
		] );
		const text = new TextDecoder( 'shift_jis' ).decode( bytes );

		expect( parseHolidays( text ) ).toEqual( [ [ '2026-01-01', '元日' ] ] );
	} );

	test( '同じ日付が重複したら後の行で上書きする', () => {
		expect( parseHolidays( '2026/1/1,A\n2026/1/1,B' ) ).toEqual( [
			[ '2026-01-01', 'B' ],
		] );
	} );
} );

describe( 'escapePhpString', () => {
	test( '単一引用符とバックスラッシュをエスケープする', () => {
		expect( escapePhpString( "a'b\\c" ) ).toBe( "a\\'b\\\\c" );
	} );
} );

describe( 'buildPhpSource', () => {
	test( '自動生成コメントと配列を含む PHP を返す', () => {
		const source = buildPhpSource( [ [ '2026-01-01', '元日' ] ] );

		expect( source ).toContain( '自動生成' );
		expect( source ).toContain( 'node bin/build-holidays.js' );
		expect( source ).toContain( "\t'2026-01-01' => '元日'," );
		expect( source ).toContain( 'return array(' );
	} );
} );

describe( 'validateHolidays', () => {
	test( '元日を含む一覧は問題なし', () => {
		expect( validateHolidays( [ [ '2026-01-01', '元日' ] ] ) ).toBeNull();
	} );

	test( '空の一覧はエラー', () => {
		expect( validateHolidays( [] ) ).toContain( 'No holidays' );
	} );

	test( '元日が無い一覧（文字化けなど）はエラー', () => {
		expect( validateHolidays( [ [ '2026-01-01', '蜈・律' ] ] ) ).toContain(
			'元日'
		);
	} );
} );
