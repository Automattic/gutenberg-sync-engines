/**
 * External dependencies
 */
import * as Y from 'yjs';
import { Awareness } from 'y-protocols/awareness';

/**
 * Internal dependencies
 */
import type {
	EngineLocalUpdateListener,
	EngineSessionCodec,
	EngineUpdate,
} from '@wordpress/sync';
import { applyServerAwarenessStates } from '../awareness-sync';
import { SyncUpdateType } from '../../providers/http-polling/types';
import {
	base64ToUint8Array,
	createSyncUpdate,
} from '../../providers/http-polling/utils';

/**
 * Origin tag for Yjs transactions applied by this session, so updates the
 * session applies are not reported back as local updates.
 */
const YJS_SERVER_SESSION_ORIGIN = 'yjs-server-session';

/**
 * Slug of the yjs-server engine. Must match WP_Yjs_Server_Engine::SLUG on
 * the PHP side.
 */
export const YJS_SERVER_ENGINE_SLUG = 'yjs-server';

/**
 * Protocol version of the yjs-server engine. Must match
 * WP_Yjs_Server_Engine::PROTOCOL_VERSION on the PHP side.
 */
export const YJS_SERVER_ENGINE_PROTOCOL = 1;

/**
 * Server-emitted row type carrying a full-state snapshot as JSON
 * `{ doc: <base64 V2> }`: the room genesis and compaction checkpoints.
 * Matches WP_Yjs_Server_Engine::UPDATE_TYPE_SNAPSHOT. Receive-only —
 * clients never send it.
 */
export const YJS_SERVER_SNAPSHOT_TYPE = 'snapshot';

/**
 * Options for creating a yjs-server session codec.
 */
export interface YjsServerSessionOptions {
	/**
	 * The awareness instance tracking collaborator presence. When omitted, a
	 * standalone instance is created so remote awareness states can still be
	 * applied.
	 */
	awareness?: Awareness;

	/** The Yjs document holding the entity state. */
	doc: Y.Doc;
}

/**
 * Encodes the document's full state as an `update` row. Full-state uploads
 * are safe against this engine: the server merges idempotently and stores
 * only the diff of what it did not already have.
 *
 * @param doc The Yjs document.
 */
function createFullStateUpdate( doc: Y.Doc ): EngineUpdate {
	return createSyncUpdate(
		Y.encodeStateAsUpdateV2( doc ),
		SyncUpdateType.UPDATE
	);
}

/**
 * Creates the yjs-server engine's session codec for one entity/room.
 *
 * Unlike the relay codec there is no sync_step1/step2 peer dance: the
 * SERVER holds the canonical document. A joining client receives the
 * genesis/checkpoint snapshot plus the update tail, sends its own edits as
 * incremental V2 `update` rows, and — when it already holds local state
 * (a rejoin) — uploads its full state as an ordinary update the server
 * dedups via diffing.
 *
 * @param options The Yjs document and optional awareness to wrap.
 * @return The transport-facing session codec.
 */
export function createYjsServerSessionCodec(
	options: YjsServerSessionOptions
): EngineSessionCodec {
	const { doc } = options;
	const awareness = options.awareness ?? new Awareness( doc );

	let localUpdateListener: EngineLocalUpdateListener | null = null;
	let isDocListenerAttached = false;

	function onDocUpdate( update: Uint8Array, origin: unknown ): void {
		if ( YJS_SERVER_SESSION_ORIGIN === origin ) {
			return;
		}

		localUpdateListener?.(
			createSyncUpdate( update, SyncUpdateType.UPDATE ),
			update.byteLength
		);
	}

	function processDocUpdate( update: EngineUpdate ): EngineUpdate | void {
		switch ( update.type ) {
			case YJS_SERVER_SNAPSHOT_TYPE: {
				// Snapshot rows carry JSON, not raw base64 (the transport
				// moves `data` opaquely either way).
				try {
					const decoded = JSON.parse( update.data );
					if ( 'string' === typeof decoded?.doc ) {
						Y.applyUpdateV2(
							doc,
							base64ToUint8Array( decoded.doc ),
							YJS_SERVER_SESSION_ORIGIN
						);
					}
				} catch {
					// A malformed snapshot cannot be applied; the next
					// checkpoint or update row resynchronizes.
				}
				return;
			}

			case SyncUpdateType.UPDATE: {
				Y.applyUpdateV2(
					doc,
					base64ToUint8Array( update.data ),
					YJS_SERVER_SESSION_ORIGIN
				);
			}
		}
	}

	return {
		applyRemoteAwareness: ( state ) =>
			applyServerAwarenessStates(
				state,
				awareness,
				YJS_SERVER_SESSION_ORIGIN
			),
		clientId: doc.clientID,
		engineSlug: YJS_SERVER_ENGINE_SLUG,
		engineProtocol: YJS_SERVER_ENGINE_PROTOCOL,
		// The server compacts by itself and never nominates a client, so a
		// compaction request should not occur — but the contract requires an
		// answer, and a full-state update is the correct, idempotent one.
		createCompactionUpdate: () => createFullStateUpdate( doc ),
		// Unknown-outcome recovery re-sends full state; the server stores
		// only what it was actually missing.
		createRecoveryUpdate: () => createFullStateUpdate( doc ),
		createCompactionFromUpdates: () => createFullStateUpdate( doc ),
		destroy() {
			if ( isDocListenerAttached ) {
				doc.off( 'updateV2', onDocUpdate );
				isDocListenerAttached = false;
			}
			localUpdateListener = null;
		},
		// A fresh client has nothing to announce: the server's snapshot row
		// bootstraps it. A client that already holds state (a rejoin after a
		// dropped session) uploads it; the server diffs out what it already
		// has, so redundancy costs bytes, never correctness.
		getInitialUpdates: () =>
			0 === doc.store.clients.size
				? []
				: [ createFullStateUpdate( doc ) ],
		getLocalAwareness: () => awareness.getLocalState() ?? {},
		onLocalUpdate( listener ) {
			localUpdateListener = listener;
			if ( ! isDocListenerAttached ) {
				doc.on( 'updateV2', onDocUpdate );
				isDocListenerAttached = true;
			}
		},
		receiveUpdate: ( update ) => processDocUpdate( update ),
	};
}
