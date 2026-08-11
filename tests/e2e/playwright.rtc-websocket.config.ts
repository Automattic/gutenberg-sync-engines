/**
 * Playwright config for the WebSocket-transport collaboration e2e.
 *
 * Extends the default plugin config (`./playwright.config.ts`) and runs only
 * the specs under `specs/websocket-only/`, with the test WebSocket provider
 * plugin activated by globalSetup (via GUTENBERG_RTC_TEST_WS_PROVIDER) and
 * the y-websocket sync server started as a second webServer. The default
 * config ignores `websocket-only/` so these specs run only here.
 *
 * External dependencies
 */
import { defineConfig, type PlaywrightTestConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import baseConfig from './playwright.config';

const wsPort = process.env.GUTENBERG_RTC_TEST_WS_PORT || '18991';
process.env.GUTENBERG_RTC_TEST_WS_PORT = wsPort;
process.env.GUTENBERG_RTC_TEST_WS_PROVIDER = '1';
process.env.GUTENBERG_RTC_TEST_WS_URL =
	process.env.GUTENBERG_RTC_TEST_WS_URL || `ws://127.0.0.1:${ wsPort }`;

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
			command: `exec node ./bin/rtc-test-ws-sync-server.mjs --port ${ wsPort }`,
			reuseExistingServer:
				process.env.GUTENBERG_RTC_TEST_WS_REUSE_SERVER === '1',
			stderr: 'pipe',
			stdout: 'pipe',
			url: `http://127.0.0.1:${ wsPort }/health`,
		},
	],
} );

export default config;
