// @wordpress/scripts 37 以降も、既存の Jest テストを WordPress の設定で実行する。
module.exports = {
	preset: '@wordpress/jest-preset-default',
	testPathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/.claude/',
		'<rootDir>/dist/',
	],
	modulePathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/.claude/',
		'<rootDir>/dist/',
	],
	transform: {
		'\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
};
