/**
 * Playwright config for the WebSocket-transport collaboration e2e.
 *
 * Extends the default plugin config (`./playwright.config.ts`) and runs
 * only the specs under `specs/websocket-only/`, against the plugin's REAL
 * websocket transport: a second webServer selects the websocket transport
 * on the tests site and runs the `wp collaboration sync-server` PHP daemon
 * (engine seam and all) with host port 8787 published; Playwright waits on
 * the daemon's own /health endpoint and the launcher restores the previous
 * transport at teardown. The default config ignores `websocket-only/` so
 * these specs run only here.
 *
 * (The old y-websocket PEER-relay fixture lane — the test WS provider
 * plugin plus `rtc-test-ws-sync-server.mjs` — only demonstrated
 * client-merging engines and none remains; both live engines are
 * server-authoritative, which is exactly what the real daemon exercises.)
 *
 * External dependencies
 */
import { spawnSync } from 'node:child_process';
import { defineConfig, type PlaywrightTestConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import baseConfig from './playwright.config';

/*
 * Free host port 8787 BEFORE Playwright's webServer probe: with
 * reuseExistingServer false, an already-responding health URL (the DEV
 * env's auto-started daemon, which serves the WRONG database for this
 * suite, or a stale fuzz daemon) would abort the run instead of being
 * replaced by the launcher. MAIN PROCESS ONLY: Playwright workers reload
 * this config, and an unguarded cleanup here removed the live daemon
 * mid-run.
 */
if ( ! process.env.TEST_WORKER_INDEX ) {
	for ( const holder of [
		'wp-sync-ws-daemon',
		'rtc-fuzz-ws-daemon',
		'rtc-e2e-ws-daemon',
	] ) {
		spawnSync( 'docker', [ 'rm', '-f', holder ], { stdio: 'ignore' } );
	}
}

type ArrayElement< T > = T extends Array< infer Item > ? Item : T;
type WebServerConfig = ArrayElement<
	Exclude< PlaywrightTestConfig[ 'webServer' ], undefined >
>;

const baseWebServer: WebServerConfig[] = [];
if ( Array.isArray( baseConfig.webServer ) ) {
	baseWebServer.push( ...baseConfig.webServer );
} else if ( baseConfig.webServer ) {
	baseWebServer.push( baseConfig.webServer );
}

const config = defineConfig( {
	...baseConfig,
	testMatch: '**/specs/websocket-only/**/*.spec.ts',
	testIgnore: [],
	webServer: [
		...baseWebServer,
		{
			command: 'exec node ./bin/rtc-real-ws-daemon.mjs',
			reuseExistingServer: false,
			stderr: 'pipe',
			stdout: 'pipe',
			// The PHP daemon's own health endpoint.
			url: 'http://localhost:8787/health',
			// Compose pull/spin-up plus the option flip can be slow.
			timeout: 90_000,
		},
	],
} );

export default config;
