/**
 * External dependencies
 */
import * as Y from 'yjs';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type {
	EngineCollection,
	EngineEntity,
	SyncEngine,
} from '@wordpress/sync';

/**
 * Internal dependencies
 *
 * The local Y.Doc is an EDITOR BRIDGE only (block model, undo scope,
 * awareness anchor) reusing the shared `engines/yjs/` schema; the sync
 * substrate is de-rtc's proposal wire — the server's canonical document
 * is a serialized-block string, never a CRDT.
 */
import { CRDT_RECORD_MAP_KEY } from '../yjs/constants';
import { createYjsDoc, serializeCrdtDoc } from '../yjs/doc';
import { docContainsSnapshot, encodeDocSnapshot } from '../yjs/snapshot';
import { createUndoManager } from '../yjs/undo';
import { applyServerAwarenessStates } from '../awareness-sync';
import { createDeRtcDocBridge, DE_RTC_REMOTE_ORIGIN } from './doc-bridge';
import {
	createDeRtcSessionCodec,
	DE_RTC_ENGINE_PROTOCOL,
	DE_RTC_ENGINE_SLUG,
	DE_RTC_SNAPSHOT_TYPE,
} from './session';

/**
 * An awareness-only codec for de-rtc collection rooms: presence flows,
 * rows are ignored, nothing is ever proposed.
 *
 * @param ydoc      The collection's anchor doc (client id source).
 * @param awareness Optional awareness instance.
 * @return The transport-facing session codec.
 */
function createInertDeRtcCollectionCodec(
	ydoc: Y.Doc,
	awareness?: import('y-protocols/awareness').Awareness
): ReturnType< typeof createDeRtcSessionCodec > {
	const noopUpdate = () => ( {
		data: JSON.stringify( { inert: true } ),
		type: DE_RTC_SNAPSHOT_TYPE,
	} );
	return {
		applyRemoteAwareness( state ) {
			if ( awareness ) {
				applyServerAwarenessStates(
					state,
					awareness,
					DE_RTC_REMOTE_ORIGIN
				);
			}
		},
		clientId: ydoc.clientID,
		engineSlug: DE_RTC_ENGINE_SLUG,
		engineProtocol: DE_RTC_ENGINE_PROTOCOL,
		// Never sent: the server compacts by itself and this codec has no
		// local updates whose outcome could need recovery.
		createCompactionUpdate: noopUpdate,
		createRecoveryUpdate: noopUpdate,
		createCompactionFromUpdates: noopUpdate,
		destroy() {},
		getInitialUpdates: () => [],
		getLocalAwareness: () => awareness?.getLocalState() ?? {},
		onLocalUpdate() {},
		receiveUpdate() {},
	};
}

/**
 * The DE-RTC engine, client half.
 *
 * Distributed Editing's client obligations are deliberately small: it
 * never merges. The editor's edits land in the local doc; the session
 * codec proposes the doc's content against the version it last
 * incorporated; the SERVER three-way-merges every proposal and answers
 * with canonical content rows this entity folds back into the doc (and
 * so into the editor). Like the yjs-server engine:
 *
 * - `hydrate` is a no-op: the server's genesis snapshot row is the
 *   document's origin (seeding from the loaded record would fork a
 *   duplicate universe).
 * - Local changes made before that snapshot arrives are BUFFERED and
 *   replayed once it does.
 * - `getEditorChanges` reports nothing until bootstrap, so an empty
 *   pre-sync document can never be dispatched into the editor as a
 *   mass deletion.
 *
 * Undo is the shared per-peer Yjs undo manager: undo is client-local
 * machinery, and an undo transaction marks the doc dirty like any other
 * local edit, so undone state propagates as an ordinary proposal.
 *
 * Known v1 gaps (see docs/engine-comparison.md): the title is not
 * synced (proposals carry content only), and a proposal the server
 * escalates as a genuine conflict is abandoned locally once the
 * canonical state applies — the upstream review/decision UI is not
 * ported yet.
 *
 * @return {SyncEngine} The de-rtc engine.
 */
export function createDeRtcEngine(): SyncEngine {
	return {
		slug: DE_RTC_ENGINE_SLUG,
		protocolVersion: DE_RTC_ENGINE_PROTOCOL,
		createUndoManager,
		createEntity( { syncConfig, objectType } ): EngineEntity {
			const ydoc = createYjsDoc( { objectType } );
			const recordMap = ydoc.getMap( CRDT_RECORD_MAP_KEY );
			const awareness = syncConfig.createAwareness?.( ydoc );
			const bridge = createDeRtcDocBridge( ydoc, syncConfig );

			// Edits made before the server snapshot arrives, replayed in
			// order once it does.
			let pendingLocalChanges: Array< {
				changes: Parameters<
					typeof syncConfig.applyChangesToCRDTDoc
				>[ 1 ];
				origin: unknown;
			} > = [];

			const applyChanges = (
				changes: Parameters<
					typeof syncConfig.applyChangesToCRDTDoc
				>[ 1 ],
				origin: unknown
			) => {
				ydoc.transact( () => {
					syncConfig.applyChangesToCRDTDoc( ydoc, changes );
				}, origin );
			};

			bridge.onBootstrap( () => {
				const pending = pendingLocalChanges;
				pendingLocalChanges = [];
				for ( const entry of pending ) {
					applyChanges( entry.changes, entry.origin );
				}
			} );

			let observersAttached = false;
			let onRecordUpdate:
				| ( (
						events: Y.YEvent< any >[],
						transaction: Y.Transaction
				  ) => void )
				| undefined;

			return {
				awareness,

				createSession: () =>
					createDeRtcSessionCodec( { awareness, bridge } ),

				hydrate() {
					// Deliberately empty: the server's genesis snapshot is
					// the document's origin (see the engine docblock).
				},

				applyLocalChanges( changes, origin ) {
					if ( ! bridge.isBootstrapped() ) {
						pendingLocalChanges.push( { changes, origin } );
						return;
					}
					applyChanges( changes, origin );
				},

				getEditorChanges: ( editedRecord ) =>
					bridge.isBootstrapped()
						? syncConfig.getChangesFromCRDTDoc( ydoc, editedRecord )
						: {},

				encodeSnapshot: () => encodeDocSnapshot( ydoc ),

				containsSnapshot: ( encoded ) =>
					docContainsSnapshot( ydoc, encoded ),

				serialize: () => serializeCrdtDoc( ydoc ),

				observe( observers ) {
					onRecordUpdate = ( _events, transaction ) => {
						// Canonical applications (remote origin) and undo
						// transactions must reach the editor; the editor's
						// own edits must not echo back into it.
						if (
							DE_RTC_REMOTE_ORIGIN !== transaction.origin &&
							! ( transaction.origin instanceof Y.UndoManager )
						) {
							return;
						}
						observers.onRemoteChange();
					};

					recordMap.observeDeep( onRecordUpdate );
					observersAttached = true;
				},

				addToUndoScope( undoManager, meta ) {
					undoManager.addToScope( recordMap, meta );
				},

				destroy() {
					if ( observersAttached && onRecordUpdate ) {
						recordMap.unobserveDeep( onRecordUpdate );
					}
					ydoc.destroy();
				},
			};
		},

		createCollection( { syncConfig, objectType } ): EngineCollection {
			// Collections are INERT under de-rtc (the intent-log precedent):
			// proposals are serialized post content, which collection rooms
			// do not have. Awareness still flows so presence works; the
			// server's collection rooms simply hold an empty canonical whose
			// rows this codec ignores.
			const ydoc = createYjsDoc( { collection: true, objectType } );
			const awareness = syncConfig.createAwareness?.( ydoc );

			return {
				awareness,

				createSession: () =>
					createInertDeRtcCollectionCodec( ydoc, awareness ),

				initialize: () => {},

				observe() {},

				markSaved() {},

				destroy() {
					ydoc.destroy();
				},
			};
		},
	};
}
