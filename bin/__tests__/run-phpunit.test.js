const { describe, expect, test } = require( '@jest/globals' );
const { buildEnvCwd } = require( '../run-phpunit' );

describe( 'buildEnvCwd', () => {
	test.each( [
		{
			name: '通常クローン',
			freeMode: false,
			cwd: '/workspace/vk-booking-manager-pro',
			expected: 'wp-content/plugins/vk-booking-manager-pro',
		},
		{
			name: 'worktree',
			freeMode: false,
			cwd: '/workspace/codex-1885',
			expected: 'wp-content/plugins/codex-1885',
		},
		{
			name: '無料版',
			freeMode: true,
			cwd: '/workspace/codex-1885',
			expected: 'wp-content/plugins/vk-booking-manager',
		},
	] )( '$name のパスを作る', ( testCase ) => {
		expect( buildEnvCwd( testCase.freeMode, testCase.cwd ) ).toBe(
			testCase.expected
		);
	} );

	test( '安全でない作業ディレクトリ名は拒否する', () => {
		expect( () =>
			buildEnvCwd( false, '/workspace/unsafe name' )
		).toThrow();
	} );
} );
