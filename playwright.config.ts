import { defineConfig, devices } from '@playwright/test';
import { BASE_URL } from './tests/acceptance/support/wp';

// Acceptance suite for a running Local by Flywheel site; see tests/acceptance/CRITERIA.md.
export default defineConfig( {
	testDir: './tests/acceptance/specs',
	globalSetup: './tests/acceptance/support/global-setup.ts',
	globalTeardown: './tests/acceptance/support/global-teardown.ts',
	outputDir: './test-results',
	// The drain lock is not atomic, and every test talks to one shared site.
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 180_000,
	expect: { timeout: 15_000 },
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'playwright-report' } ] ],
	use: {
		// Trailing slash: navigate with relative paths such as 'wp-admin/', never '/wp-admin/'.
		baseURL: `${ BASE_URL }/`,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
