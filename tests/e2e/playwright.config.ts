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
	// WebSocket-transport specs need the test WS provider + sync server and
	// run only under playwright.rtc-websocket.config.ts.
	testIgnore: '**/specs/websocket-only/**',
	webServer: {
		...baseConfig.webServer,
		// Start this plugin's wp-env (Gutenberg subtree + this plugin).
		command: 'npm run env:start',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
			grepInvert: /-chromium/,
		},
	],
} );
