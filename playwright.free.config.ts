import { defineConfig, devices } from '@playwright/test';

/**
 * 無料版（vk-booking-manager）専用の Playwright 設定。
 *
 * メインの playwright.config.ts は global-setup で Pro 版（vk-booking-manager-pro）の
 * 有効化を前提とするため、Free 版単独テストではセットアップが失敗してしまう。
 * 本設定はそれを回避し、Free 版固有のテストだけを独立して実行するためのもの。
 *
 * テストファイルは tests/e2e/specs/free-*.spec.ts のみを対象とする。
 */
export default defineConfig( {
	testDir: './tests/e2e/specs',
	testMatch: /free-.*\.spec\.ts/,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: 0,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:9174',
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
