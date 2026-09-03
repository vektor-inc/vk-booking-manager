/**
 * ESLint flat config（ESLint 9 / wp-scripts 32）。
 *
 * ESLint 9 / @wordpress/scripts v32 以降はレガシーな .eslintrc.js を読み込まず、
 * フラットコンフィグのみをサポートする。wp-scripts のデフォルト設定
 * （@wordpress/eslint-plugin recommended + テスト用上書き + build/vendor 等の無視）
 * を継承し、本プロジェクト固有の除外パス・ルール緩和・領域別設定を末尾で上書きする。
 */

const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );
const globals = require( 'globals' );
const tseslint = require( 'typescript-eslint' );

module.exports = [
	// 生成物・依存・翻訳ファイルは lint 対象外にする。
	{
		ignores: [
			'**/build/**',
			'**/dist/**',
			'**/node_modules/**',
			'**/vendor/**',
			'**/languages/**',
			'**/*.min.js',
			// skills:sync が各 AI ツール用に複製する生成物（編集対象外）。
			'.agent/**',
			'.claude/**',
			'.codex/**',
			'.cursor/**',
			'.github/**',
		],
	},

	// wp-scripts のデフォルト設定を継承する。
	...wpConfig,

	// プロジェクト共通のルール緩和（旧来の運用に合わせた内容）。
	{
		languageOptions: {
			// ESLint 9 では /* eslint-env browser */ コメントが無効化されたため、
			// フロントエンドスクリプトが使うブラウザのグローバルを設定側で定義する。
			globals: {
				...globals.browser,
			},
		},
		// 冗長な eslint-disable ディレクティブの警告は抑制する。
		linterOptions: {
			reportUnusedDisableDirectives: 'off',
		},
		rules: {
			// アンダースコア接頭辞の意図的な未使用変数、rest spread からの除外、
			// catch 節の未使用引数を許容する。
			'no-unused-vars': [
				'error',
				{
					ignoreRestSiblings: true,
					caughtErrors: 'none',
					varsIgnorePattern: '^_',
					argsIgnorePattern: '^_',
				},
			],
			// webpack エイリアスや WordPress 提供パッケージは ESLint の
			// import リゾルバが解決できないため無効化する。
			'import/no-unresolved': 'off',
			'import/no-extraneous-dependencies': 'off',
			camelcase: 'off',
			'no-shadow': 'off',
			'@wordpress/no-unsafe-wp-apis': 'off',
			'@wordpress/i18n-translator-comments': 'off',
			'react-hooks/exhaustive-deps': 'off',
			'jsdoc/no-undefined-types': 'off',
			// 入れ子三項・早期 return 前の変数・確認ダイアログなど、既存実装の修正に
			// 回帰リスクを伴うスタイル系ルールは無効化する
			// （VK Blocks Pro も exhaustive-deps 等を無効化しており組織方針として整合）。
			'@wordpress/no-unused-vars-before-return': 'off',
			'no-nested-ternary': 'off',
			'no-alert': 'off',
			'@wordpress/no-global-active-element': 'off',
		},
	},

	// アクセシビリティ系ルールは新規コードでは有効に保ち、回帰リスクのある
	// 既存コンポーネントに限定して無効化する（段階的に解消する想定）。
	{
		files: [
			'src/blocks/reservation/app.js',
			'src/blocks/reservation/booking-ui/selected-plan-summary.js',
		],
		rules: {
			'jsx-a11y/click-events-have-key-events': 'off',
			'jsx-a11y/no-static-element-interactions': 'off',
			'jsx-a11y/label-has-associated-control': 'off',
		},
	},

	// TypeScript ファイル向けのルール緩和（プラグインを同一オブジェクトで登録）。
	{
		files: [ '**/*.{ts,tsx}' ],
		plugins: {
			'@typescript-eslint': tseslint.plugin,
		},
		rules: {
			// TS では型構文を理解できない base ルールを無効化し、
			// TS 用ルールに一本化する（二重適用・誤検知の回避）。
			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					ignoreRestSiblings: true,
					caughtErrors: 'none',
					varsIgnorePattern: '^_',
					argsIgnorePattern: '^_',
				},
			],
		},
	},

	// Node 製の CLI スクリプト（CommonJS・console 許可）。
	{
		files: [ 'bin/**/*.js' ],
		languageOptions: {
			sourceType: 'commonjs',
			globals: {
				...globals.node,
			},
		},
		rules: {
			'no-console': 'off',
		},
	},

	// e2e テスト（デバッグ用の console 出力を許可）。
	{
		files: [ 'tests/**/*.{js,jsx,ts,tsx}' ],
		rules: {
			'no-console': 'off',
		},
	},

	// 管理画面用スクリプト（ブラウザ + jQuery + WP 管理画面のグローバル）。
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			globals: {
				...globals.jquery,
				ajaxurl: 'readonly',
				wp: 'readonly',
				// クイック編集で利用する WordPress コアのグローバル。
				inlineEditPost: 'readonly',
			},
		},
	},
];
