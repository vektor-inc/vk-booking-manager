import { defineConfig, devices } from '@playwright/test';

/**
 * PR #279 専用の Playwright 設定。
 * 既存の globalSetup（plugin activate をスラッグ固定で行うため worktree マウント名と
 * 不一致になり失敗する）を使わず、事前に WP-CLI でデータ投入済みの dev wp-env
 * （ポート 9160）に対して price-tiers スペックのみを実行する。
 */
export default defineConfig( {
	testDir: './tests/e2e/specs',
	testMatch: /pr-279-(price-tiers|legacy)\.spec\.ts/,
	fullyParallel: false,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:9160',
		// メインの playwright.config.ts と同様、ブラウザ TZ を Asia/Tokyo に固定する。
		// price-tiers spec も selectAvailableCalendarDay でカレンダーを操作するため、
		// ブラウザ月（UTC）と WP seeding 月（Tokyo）のズレ（issue #324）を防ぐ。
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
	timeout: 60000,
} );
