import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	globalSetup: './tests/e2e/global-setup.ts',
	testDir: './tests/e2e/specs',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: 'html',
	use: {
		// e2e はテスト用 wp-env 環境（tests / 既定ポート 8889）に対して実行する。
		// 開発用環境（development / 8888）と分離し、テストデータが開発環境を汚さないようにする。
		// Run e2e against the tests wp-env environment (port 8889 by default),
		// keeping it separate from the development environment (8888).
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		// ブラウザのタイムゾーンを Asia/Tokyo に固定する。
		// 予約カレンダーの初期表示月（app.js の monthCursor）はブラウザの new Date() から
		// 算出される一方、シフトの seeding（global-setup.ts）は WP の wp_date() で
		// Asia/Tokyo の月を投入する。timezoneId を指定しないと CI のブラウザは UTC で動くため、
		// JST 月初の 0〜9 時帯にブラウザ月（UTC）と seeding 月（Tokyo）が1か月ズレ、
		// カレンダーが空き枠の無い前月を表示してフロント系 spec が一斉に失敗する（issue #324）。
		// WP/seeding と同じ Asia/Tokyo に揃えることでこの時刻依存のズレを根絶する。
		timezoneId: 'Asia/Tokyo',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	timeout: 45000,
} );
