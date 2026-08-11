/**
 * Global setup for the plugin's collaboration e2e.
 *
 * A trimmed version of the Gutenberg subtree's own e2e global setup: it
 * authenticates once and resets the site to a clean, predictable state. It
 * deliberately omits the monorepo-suite-specific steps (e.g. deactivating
 * Gutenberg's CSS-animation test plugin) that assume test plugins this
 * environment does not map. The RTC WebSocket provider setup is gated on
 * GUTENBERG_RTC_TEST_WS_PROVIDER (set by playwright.rtc-websocket.config.ts);
 * the default suite runs over the HTTP-polling transport and just deactivates
 * a stale provider activation.
 *
 * External dependencies
 */
import { request } from '@playwright/test';
import type { FullConfig } from '@playwright/test';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Resolved via the plugin's own devDependency.
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { setupRtcWebSocketProvider } from './rtc-websocket-setup';

async function globalSetup( config: FullConfig ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );

	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	// Authenticate and persist the storage state to disk.
	await requestUtils.setupRest();

	// wp-env does not reliably activate mapped plugins on the *tests* site,
	// so ensure both the Gutenberg framework (the vendored subtree) and this
	// plugin are active — collaboration is inert without them.
	await requestUtils.activatePlugin( 'gutenberg' );
	await requestUtils.activatePlugin( 'gutenberg-sync-engines' );

	// Reset the environment to a clean slate before the tests run.
	await Promise.all( [
		requestUtils.activateTheme( 'twentytwentyone' ),
		requestUtils.deleteAllPosts(),
		requestUtils.deleteAllPages(),
		requestUtils.deleteAllBlocks(),
		requestUtils.resetPreferences(),
		setupRtcWebSocketProvider( requestUtils ),
	] );

	await requestContext.dispose();
}

export default globalSetup;
