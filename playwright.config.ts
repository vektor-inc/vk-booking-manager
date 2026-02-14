import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
	testDir: './tests/e2e/specs',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: 'html',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:1900',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
	timeout: 120000,
});
