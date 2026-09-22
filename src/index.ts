/**
 * Client entry: the whole real-time collaboration experience for the
 * block editor, plugged into core-data through its entity sync seam.
 *
 * What runs here, in order:
 *   1. the sync ENGINES (how concurrent edits merge) and TRANSPORTS (how
 *      updates move) register with the plugin's own sync core;
 *   2. the host bridge resolves the engine the server announced,
 *      negotiates a transport, and registers ONE manager with core-data
 *      (`registerEntitySyncManager`);
 *   3. the collaboration UI (presence, cursors, conflict review, the
 *      connection error modal, preferences) mounts;
 *   4. slow awareness, when the site turned it on.
 *
 * Third-party engine or transport plugins reuse this plugin's Yjs
 * instance and registries through `window.gutenbergSyncEngines`.
 */

/**
 * Internal dependencies
 */
import {
	registerSyncEngine,
	registerSyncTransport,
	Y,
	YJS_VERSION,
} from './sync';
import { createIntentLogEngineAdapter } from './engines/intent-log-adapter';
import { createYjsServerEngineAdapter } from './engines/yjs-server-adapter';
import { createDeRtcEngineAdapter } from './engines/de-rtc-adapter';
import { createHttpPollingProvider } from './providers/http-polling/http-polling-provider';
import { createHttpLongPollingProvider } from './providers/http-long-polling/http-long-polling-provider';
import { createWebSocketProvider } from './providers/websocket/websocket-provider';
import { installHostBridge } from './host/entity-sync-manager';
import { installUi } from './ui';
import { bootstrapSlowAwareness } from './awareness';

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

// The extension surface for other plugins: the shared Yjs instance and the
// two registries. Register before the bridge resolves the announced engine.
window.gutenbergSyncEngines = {
	Y,
	YJS_VERSION,
	registerSyncEngine,
	registerSyncTransport,
};

installHostBridge();
installUi();

// Slow awareness (block presence on a slow cadence), when the site has
// turned it on; see src/awareness/.
bootstrapSlowAwareness();

declare global {
	interface Window {
		gutenbergSyncEngines?: {
			Y: typeof Y;
			YJS_VERSION: string;
			registerSyncEngine: typeof registerSyncEngine;
			registerSyncTransport: typeof registerSyncTransport;
		};
	}
}
