/**
 * Test-only WebSocket sync provider for RTC e2e tests.
 *
 * Implements the framework's engine-generic PROVIDER contract: the provider
 * creator receives `{ objectType, objectId, session }` where `session` is an
 * EngineSessionCodec, and moves the codec's opaque update envelopes between
 * peers. The wire is a per-room relay Y.Doc holding an append-only Y.Array of
 * envelopes ({ type, data }), replicated by y-websocket against
 * tests/e2e/bin/rtc-test-ws-sync-server.mjs (built on @y/websocket-server, the
 * same wire format as production y-websocket deployments). Local updates are
 * appended to the array; remotely-appended envelopes are fed back through
 * `session.receiveUpdate()`. Awareness rides y-websocket's own awareness,
 * carrying each client's `session.getLocalAwareness()` state keyed by the
 * session's client id.
 *
 * The provider never interprets payloads, so it works with any engine.
 * Exposes a small debug surface on window.__gutenbergTestWebSocketSync.rooms
 * that the Playwright fixtures poll.
 */

import { WebsocketProvider } from 'y-websocket';
// Aliased to ./yjs-external.js by the esbuild config so the relay doc shares
// wp.sync.Y with the framework.
import * as Y from 'yjs';

const TEST_PROVIDER_NAMESPACE = 'gutenberg-test/rtc-websocket-provider';
const DEFAULT_URL = 'ws://127.0.0.1:18991';
// Origin tag for relay-doc transactions made by this client, so the array
// observer can tell its own appends from replicated ones.
const LOCAL_ORIGIN = 'rtc-test-ws-local';
// How often the local awareness state is re-read from the session codec.
// Mirrors the pull-based cadence of the polling transports.
const AWARENESS_PUSH_INTERVAL_MS = 250;

const settings = window.gutenbergTestWebSocketSync || {};
const globalState = ( window.__gutenbergTestWebSocketSync = {
	rooms: {},
	tick: 0,
	url: settings.url || DEFAULT_URL,
} );

function ensureRoomDebugState( room ) {
	if ( ! globalState.rooms[ room ] ) {
		globalState.rooms[ room ] = {
			awarenessCount: 0,
			clientId: null,
			status: 'disconnected',
			synced: false,
		};
	}
	return globalState.rooms[ room ];
}

function updateDebugState( room, patch ) {
	Object.assign( ensureRoomDebugState( room ), patch );
	globalState.tick += 1;
}

function createTestWebSocketProvider() {
	return async ( { objectType, objectId, session } ) => {
		const room = objectId ? `${ objectType }:${ objectId }` : objectType;

		updateDebugState( room, {
			clientId: session.clientId,
			status: 'connecting',
			synced: false,
		} );

		const relayDoc = new Y.Doc();
		const envelopes = relayDoc.getArray( 'updates' );
		const provider = new WebsocketProvider(
			globalState.url,
			room,
			relayDoc,
			{
				// Disable BroadcastChannel so cross-tab sync always goes through
				// the WebSocket. Tests need to exercise the wire transport.
				disableBc: true,
			}
		);
		const awareness = provider.awareness;

		const pushEnvelopes = ( toPush ) => {
			if ( ! toPush.length ) {
				return;
			}
			relayDoc.transact( () => {
				// Store plain JSON copies; EngineUpdate envelopes are
				// { type, data } with base64 string payloads.
				envelopes.push(
					toPush.map( ( { type, data } ) => ( { type, data } ) )
				);
			}, LOCAL_ORIGIN );
		};

		// Feed envelopes appended by OTHER clients (or replayed by the server
		// on rejoin) into the session codec. Replaying this client's own
		// earlier envelopes after a reconnect is harmless: engines are
		// expected to tolerate redelivery (Yjs updates are idempotent, the
		// intent log dedupes per intent id).
		const onEnvelopes = ( event, txn ) => {
			if ( txn.origin === LOCAL_ORIGIN ) {
				return;
			}
			const inserted = [];
			for ( const delta of event.changes.delta ) {
				if ( delta.insert ) {
					inserted.push( ...delta.insert );
				}
			}
			const responses = [];
			for ( const envelope of inserted ) {
				const response = session.receiveUpdate( envelope );
				if ( response ) {
					responses.push( response );
				}
			}
			pushEnvelopes( responses );
		};
		envelopes.observe( onEnvelopes );

		// Local edits → relay array.
		session.onLocalUpdate( ( update ) => pushEnvelopes( [ update ] ) );

		// Announce this session to the room (e.g. a state-vector announcement
		// peers answer via their receiveUpdate() return value).
		pushEnvelopes( session.getInitialUpdates() );

		// Awareness: publish the session's local state through y-websocket's
		// awareness, keyed by the session client id, and apply every peer's
		// published state through the codec.
		let lastLocalAwareness;
		const pushLocalAwareness = () => {
			const state = session.getLocalAwareness();
			const serialized = JSON.stringify( state );
			if ( serialized === lastLocalAwareness ) {
				return;
			}
			lastLocalAwareness = serialized;
			awareness.setLocalState( {
				sessionClientId: session.clientId,
				state,
			} );
		};
		pushLocalAwareness();
		const awarenessTimer = setInterval(
			pushLocalAwareness,
			AWARENESS_PUSH_INTERVAL_MS
		);

		const onAwarenessChange = () => {
			const states = {};
			for ( const published of awareness.getStates().values() ) {
				if ( published && published.sessionClientId !== undefined ) {
					states[ published.sessionClientId ] = published.state;
				}
			}
			session.applyRemoteAwareness( states );
			updateDebugState( room, {
				awarenessCount: awareness.getStates().size,
			} );
		};
		awareness.on( 'change', onAwarenessChange );
		onAwarenessChange();

		const statusListeners = new Set();

		const onStatus = ( event ) => {
			// A fresh socket means the previous sync handshake (if any) is
			// no longer current. y-websocket re-fires 'sync' once sync step 2
			// completes on the new connection.
			const patch = { status: event.status };
			if ( event.status !== 'connected' ) {
				patch.synced = false;
			}
			updateDebugState( room, patch );
			for ( const callback of statusListeners ) {
				callback( { status: event.status } );
			}
		};
		provider.on( 'status', onStatus );

		// y-websocket distinguishes socket connection from sync completion.
		// 'connected' means the WS is open; 'sync' fires once sync step 2 has
		// landed and the relay doc reflects the server state. Tests that need
		// real convergence should wait on `synced`, not just `status`.
		const onSync = ( isSynced ) => {
			updateDebugState( room, { synced: !! isSynced } );
		};
		provider.on( 'sync', onSync );

		return {
			destroy: () => {
				clearInterval( awarenessTimer );
				awareness.off( 'change', onAwarenessChange );
				envelopes.unobserve( onEnvelopes );
				provider.off( 'status', onStatus );
				provider.off( 'sync', onSync );
				provider.destroy();
				relayDoc.destroy();
				session.destroy();
				updateDebugState( room, {
					status: 'disconnected',
					synced: false,
				} );
			},
			on: ( event, callback ) => {
				if ( event === 'status' ) {
					statusListeners.add( callback );
				}
			},
		};
	};
}

window.wp.hooks.addFilter( 'sync.providers', TEST_PROVIDER_NAMESPACE, () => [
	createTestWebSocketProvider(),
] );
