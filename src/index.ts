/**
 * Client entry: registers this plugin's sync ENGINES and TRANSPORTS into the
 * collaborative-editing framework shipped by Gutenberg (`@wordpress/sync`).
 *
 * The framework exposes its registration surface through UNLOCKABLE PRIVATE
 * APIs. This plugin unlocks them with the shared consent string and adds:
 *   - engine adapters (intent-log, yjs-server) via
 *     `registerSyncEngine`
 *   - transport providers (http-polling, http-long-polling, websocket) via
 *     `registerSyncTransport`
 *
 * With this plugin inactive the framework registers nothing, so a session
 * finds no engine/transport to negotiate and the editor falls back to the
 * classic post lock — real-time collaboration effectively disabled.
 *
 * NOTE: the moved engine adapters and providers under `engines/` and
 * `providers/` still import framework internals by relative path (their
 * origin inside `@wordpress/sync`). Those imports, and the exact shape of
 * the unlocked surface below, are the coordinated Gutenberg change tracked
 * in PORTING.md. `@wordpress/sync` is externalized to the `wp.sync` runtime
 * global at build time (dependency extraction), so this plugin ships no copy
 * of the framework.
 */

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import { privateApis } from '@wordpress/sync';

/**
 * Internal dependencies
 */
import { unlock } from './lock-unlock';
import { createIntentLogEngineAdapter } from './engines/intent-log-adapter';
import { createYjsServerEngineAdapter } from './engines/yjs-server-adapter';
import { createDeRtcEngineAdapter } from './engines/de-rtc-adapter';
import { createHttpPollingProvider } from './providers/http-polling/http-polling-provider';
import { createHttpLongPollingProvider } from './providers/http-long-polling/http-long-polling-provider';
import { createWebSocketProvider } from './providers/websocket/websocket-provider';

const { registerSyncEngine, registerSyncTransport } = unlock( privateApis );

// Engines: how concurrent edits merge.
registerSyncEngine( createIntentLogEngineAdapter() );
registerSyncEngine( createYjsServerEngineAdapter() );
registerSyncEngine( createDeRtcEngineAdapter() );

// Transports: how updates move. Each carries the slug + protocol the server
// announces and negotiates against.
registerSyncTransport( {
	slug: 'http-polling',
	protocolVersion: 1,
	create: createHttpPollingProvider,
} );
registerSyncTransport( {
	slug: 'http-long-polling',
	protocolVersion: 1,
	create: createHttpLongPollingProvider,
} );
registerSyncTransport( {
	slug: 'websocket',
	protocolVersion: 1,
	create: createWebSocketProvider,
} );
