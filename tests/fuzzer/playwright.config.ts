/**
 * Playwright config for the RTC fuzzer (tests/fuzzer).
 *
 * A variant of tests/e2e/playwright.config.ts: same global setup (auth,
 * clean state, plugin activation — including the worktree duplicate-mount
 * handling), but pointed at the fuzz spec and tuned for long seeded runs:
 *
 * - retries are DISABLED: a fuzz failure must surface, not be absorbed; the
 *   matrix runner does its own recheck pass to separate flakes from
 *   reproducible failures.
 * - artifacts default to low-disk mode (no traces/videos) because a sweep
 *   intentionally produces failures; the runner re-runs failing seeds with
 *   traces on.
 * - the JSON reporter (RTC_FUZZ_JSON_REPORT) is the runner's machine-readable
 *   result channel.
 *
 * External dependencies
 */
import { fileURLToPath } from 'url';
import { defineConfig, devices } from '@playwright/test';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided by @wordpress/scripts.
import baseConfig from '@wordpress/scripts/config/playwright.config.js';

const globalSetup = fileURLToPath(
	new URL( '../e2e/config/global-setup.ts', 'file:' + __filename ).href
);

type TraceMode = 'off' | 'on' | 'retain-on-failure' | 'on-first-retry';

const trace = ( process.env.RTC_FUZZ_TRACE || 'off' ) as TraceMode;

export default defineConfig( {
	...baseConfig,
	testDir: './specs',
	globalSetup,
	workers: 1,
	retries: 0,
	reporter: process.env.RTC_FUZZ_JSON_REPORT
		? [
				[ 'list' ],
				[ 'json', { outputFile: process.env.RTC_FUZZ_JSON_REPORT } ],
		  ]
		: [ [ 'list' ] ],
	...( process.env.RTC_FUZZ_OUTPUT_DIR
		? { outputDir: process.env.RTC_FUZZ_OUTPUT_DIR }
		: {} ),
	use: {
		...baseConfig.use,
		screenshot: 'only-on-failure',
		trace,
		video: 'off',
	},
	webServer: {
		...baseConfig.webServer,
		// The matrix runner ensures the env is up before invoking Playwright;
		// this only fires for direct `playwright test` invocations.
		command: 'npm run env start',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
