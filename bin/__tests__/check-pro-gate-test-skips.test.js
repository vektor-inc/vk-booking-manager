/**
 * bin/check-pro-gate-test-skips.js のユニットテスト（#467 安藤レビュー指摘）。
 *
 * ファイル I/O（src/・tests/phpunit/ の実走査）はテストせず、純粋関数として
 * 切り出した各ロジックを直接呼び出して端ケースを検証する。
 */

const {
	maskCommentsAndStrings,
	extractFunctions,
	hasProGateEarlyReturn,
	testCallsCandidate,
	hasSkipGuard,
	getIgnoreReasons,
	decideExitCode,
} = require( '../check-pro-gate-test-skips' );

describe( 'maskCommentsAndStrings', () => {
	it( '文字列内の /* をコメント開始と誤認しない（maskStrings=false）', () => {
		const src = "function foo() {\n\t$x = 'image/*.png';\n\treturn $x;\n}";
		// maskStrings=false のときは文字列の中身も含め、元のテキストと完全に一致するはず
		// （コメントが存在しないコードなので、コメントマスクによる変化も起きない）。
		expect( maskCommentsAndStrings( src, false ) ).toBe( src );
	} );

	it( '文字列内の // をコメント開始と誤認しない（maskStrings=false）', () => {
		const src = "$url = 'http://example.com/path';\nreturn $url;";
		expect( maskCommentsAndStrings( src, false ) ).toBe( src );
	} );

	it( '文字列内の # をコメント開始と誤認しない（maskStrings=false）', () => {
		const src = "$color = '#fff';\nreturn $color;";
		expect( maskCommentsAndStrings( src, false ) ).toBe( src );
	} );

	it( 'maskStrings=true のときは文字列の中身だけをスペースに置き換える（長さは保つ）', () => {
		const src = "$x = 'abc';";
		const masked = maskCommentsAndStrings( src, true );
		expect( masked ).toHaveLength( src.length );
		expect( masked ).not.toContain( 'abc' );
		expect( masked ).toContain( '$x = ' );
	} );

	it( '実際のコメントは maskStrings の値に関わらずマスクされる', () => {
		const src = '// is_pro_edition の説明コメント\nreturn 1;';
		const masked = maskCommentsAndStrings( src, false );
		expect( masked ).not.toContain( 'is_pro_edition' );
		expect( masked ).toContain( 'return 1;' );
	} );
} );

describe( 'getIgnoreReasons', () => {
	it( 'コロンも理由も無い除外コメントは無視する', () => {
		const body = '// @pro-gate-ignore get_current_preset\nsome_call();';
		expect( getIgnoreReasons( body ).size ).toBe( 0 );
	} );

	it( 'コロンはあるが同じ行に理由が無い除外コメントは無視する（次行を理由として取り込まない）', () => {
		const body =
			'// @pro-gate-ignore get_current_preset:\n理由らしき文章がここにある';
		expect( getIgnoreReasons( body ).size ).toBe( 0 );
	} );

	it( '対象メソッド名・コロン・理由がそろっていれば読み取る', () => {
		const body =
			'// @pro-gate-ignore get_current_preset: ちゃんと理由がある\nsome_call();';
		const reasons = getIgnoreReasons( body );
		expect( reasons.get( 'get_current_preset' ) ).toBe(
			'ちゃんと理由がある'
		);
	} );

	it( '複数の候補メソッドをそれぞれ個別に除外できる（片方だけの除外が漏れない）', () => {
		const body = [
			'// @pro-gate-ignore method_a: 理由A',
			'// @pro-gate-ignore method_b: 理由B',
		].join( '\n' );
		const reasons = getIgnoreReasons( body );
		expect( reasons.get( 'method_a' ) ).toBe( '理由A' );
		expect( reasons.get( 'method_b' ) ).toBe( '理由B' );
		expect( reasons.has( 'method_c' ) ).toBe( false );
	} );
} );

describe( 'hasSkipGuard', () => {
	it( '正しい向き（is_free_edition が非否定）のガードを認める', () => {
		const body =
			"if ( Pro_Upsell::is_free_edition() ) {\n\t$this->markTestSkipped( 'x' );\n}";
		expect( hasSkipGuard( body ) ).toBe( true );
	} );

	it( '逆向き（! is_free_edition）のガードは認めない', () => {
		const body =
			"if ( ! Pro_Upsell::is_free_edition() ) {\n\t$this->markTestSkipped( 'x' );\n}";
		expect( hasSkipGuard( body ) ).toBe( false );
	} );

	it( 'is_free_edition と markTestSkipped が同じ if ブロックに無ければ認めない', () => {
		const body =
			"if ( Pro_Upsell::is_free_edition() ) {\n\t$x = 1;\n}\n$this->markTestSkipped( 'x' );";
		expect( hasSkipGuard( body ) ).toBe( false );
	} );
} );

describe( 'hasProGateEarlyReturn / extractFunctions: 今回の2件と同じ型の検出', () => {
	it( '完全修飾クラス名（先頭 \\）の否定ガード + return を検出する（#467 の実例と同じ型）', () => {
		const src = [
			'class Shift_Dashboard_Page {',
			'\tprivate function get_staff_conflict_notifications(): array {',
			"\t\tif ( ! class_exists( 'Free_Version_Deactivator' ) || ! \\Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE ) ) {",
			"\t\t\treturn array( 'items' => array(), 'total' => 0 );",
			'\t\t}',
			"\t\treturn array( 'items' => array( 1 ), 'total' => 1 );",
			'\t}',
			'}',
		].join( '\n' );

		const { functions, skipped } = extractFunctions( src );
		expect( skipped ).toHaveLength( 0 );
		const fn = functions.find(
			( f ) => f.name === 'get_staff_conflict_notifications'
		);
		expect( fn ).toBeDefined();
		expect( fn.className ).toBe( 'Shift_Dashboard_Page' );
		expect( hasProGateEarlyReturn( src, fn ) ).toBe( true );
	} );

	it( 'is_free_edition() の非否定ガード + return を検出する', () => {
		const src = [
			'class Industry_Presets {',
			'\tpublic static function get_current_preset( array $settings, array $defaults ): string {',
			'\t\tif ( Pro_Upsell::is_free_edition() ) {',
			"\t\t\treturn 'custom';",
			'\t\t}',
			"\t\treturn 'seminar';",
			'\t}',
			'}',
		].join( '\n' );

		const { functions } = extractFunctions( src );
		const fn = functions.find( ( f ) => f.name === 'get_current_preset' );
		expect( hasProGateEarlyReturn( src, fn ) ).toBe( true );
	} );

	it( 'ReflectionMethod 経由の呼び出し（文字列引数）も testCallsCandidate が検出する', () => {
		const testBody =
			"$method = new ReflectionMethod( $page, 'get_staff_conflict_notifications' );\n$method->invoke( $page );";
		expect(
			testCallsCandidate( testBody, 'get_staff_conflict_notifications' )
		).toBe( true );
	} );

	it( '直接呼び出し（->method(）も testCallsCandidate が検出する', () => {
		const testBody = 'Industry_Presets::get_current_preset( $a, $b );';
		expect( testCallsCandidate( testBody, 'get_current_preset' ) ).toBe(
			true
		);
	} );

	it( '逆方向（Pro 版だけ早期 return する Free 専用ヘルパー）は候補にしない', () => {
		// Pro_Upsell 自身のメソッドのような「無料版だけ動く」形（is_free_edition が否定されている）は対象外。
		const src = [
			'class Pro_Upsell {',
			'\tpublic static function register(): void {',
			'\t\tif ( ! self::is_free_edition() ) {',
			'\t\t\treturn;',
			'\t\t}',
			'\t\tdo_something();',
			'\t}',
			'}',
		].join( '\n' );

		const { functions } = extractFunctions( src );
		const fn = functions.find( ( f ) => f.name === 'register' );
		expect( hasProGateEarlyReturn( src, fn ) ).toBe( false );
	} );
} );

describe( 'decideExitCode', () => {
	it( '候補が0件なら失敗（1）にする', () => {
		expect(
			decideExitCode( {
				candidateCount: 0,
				skippedCount: 0,
				violationCount: 0,
			} )
		).toBe( 1 );
	} );

	it( '括弧不整合による除外が1件でもあれば失敗（1）にする', () => {
		expect(
			decideExitCode( {
				candidateCount: 4,
				skippedCount: 1,
				violationCount: 0,
			} )
		).toBe( 1 );
	} );

	it( '違反が1件でもあれば失敗（1）にする', () => {
		expect(
			decideExitCode( {
				candidateCount: 4,
				skippedCount: 0,
				violationCount: 1,
			} )
		).toBe( 1 );
	} );

	it( '候補があり・除外0・違反0なら成功（0）にする', () => {
		expect(
			decideExitCode( {
				candidateCount: 4,
				skippedCount: 0,
				violationCount: 0,
			} )
		).toBe( 0 );
	} );
} );
