/**
 * Playwright config for the plugin's collaboration e2e.
 *
 * The plugin brings its own e2e runner (`@playwright/test`,
 * `@wordpress/e2e-test-utils-playwright`, `@wordpress/scripts`); the **runtime**
 * Gutenberg comes from the pinned subtree in `../../gutenberg` (mounted by this
 * plugin's `.wp-env.json`). Specs live in `./specs` and import Gutenberg's
 * collaboration fixtures from the subtree while importing engine internals
 * (e.g. `genesisSyncId`) from this plugin's own source.
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

// A trimmed, plugin-local global setup (auth + clean state) — see its header
// for why we don't reuse the subtree's monorepo-suite-specific one.
const globalSetup = fileURLToPath(
	new URL( './config/global-setup.ts', 'file:' + __filename ).href
);

export default defineConfig( {
	...baseConfig,
	testDir: './specs',
	globalSetup,
	workers: 1,
	// Fail hung tests fast. The base config's 100 s per-test cap is the
	// only bound on several failure modes, so a wedged test burns minutes
	// across CI retries. Collaboration setup (two logged-in editors plus
	// mutual discovery) legitimately takes 20-40 s on CI runners, so keep
	// headroom above that; known-long specs opt up with test.setTimeout().
	// TIMEOUT env overrides, same as the base config.
	timeout: parseInt( process.env.TIMEOUT || '', 10 ) || 60_000,
	use: {
		...baseConfig.use,
		// The base config caps actions (10 s) but not navigations, so a
		// hung page.goto()/reload() (the suite's known under-load flake is
		// a fixture login navigation) would otherwise consume the whole
		// test budget and report a generic test timeout instead of the
		// failing call.
		navigationTimeout: 30_000,
	},
	// WebSocket-transport specs need the test WS provider + sync server and
	// run only under playwright.rtc-websocket.config.ts.
	testIgnore: '**/specs/websocket-only/**',
	webServer: {
		...baseConfig.webServer,
		// Start this plugin's TESTS wp-env (Gutenberg subtree + this
		// plugin, .wp-env.tests.json). Rarely runs: reuseExistingServer is
		// true, so an already-running env on the port is used as-is (see
		// the AGENTS.md warning about a FOREIGN wp-env holding the port).
		command: 'npm run env:tests start',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
			grepInvert: /-chromium/,
		},
	],
} );
