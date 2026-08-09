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
 */
import {
	CRDT_RECORD_MAP_KEY,
	CRDT_STATE_MAP_KEY,
	CRDT_STATE_MAP_SAVED_AT_KEY as SAVED_AT_KEY,
	LOCAL_SYNC_MANAGER_ORIGIN,
} from './constants';
import {
	createYjsDoc,
	deserializeCrdtDoc,
	initializeYjsDoc,
	markEntityAsSaved,
	serializeCrdtDoc,
} from './doc';
import { docContainsSnapshot, encodeDocSnapshot } from './snapshot';
import {
	createYjsSessionCodec,
	YJS_RELAY_ENGINE_PROTOCOL,
	YJS_RELAY_ENGINE_SLUG,
} from './session';

/**
 * The incumbent Yjs relay engine: it syncs entities as Yjs CRDT documents.
 *
 * This is the per-entity/collection Yjs machinery, behind the engine-neutral
 * `EngineEntity`/`EngineCollection` contract so the framework's generic sync
 * manager can drive it. It now lives entirely in this plugin; the framework
 * ships only the generic shell and the engine SPI.
 *
 * @return {SyncEngine} The Yjs relay engine.
 */
export function createYjsEngine(): SyncEngine {
	return {
		slug: YJS_RELAY_ENGINE_SLUG,
		protocolVersion: YJS_RELAY_ENGINE_PROTOCOL,
		createEntity( { syncConfig, objectType, objectId } ): EngineEntity {
			const ydoc = createYjsDoc( { objectType } );
			const recordMap = ydoc.getMap( CRDT_RECORD_MAP_KEY );
			const stateMap = ydoc.getMap( CRDT_STATE_MAP_KEY );
			const now = Date.now();

			// If the sync config supports awareness, create it.
			const awareness = syncConfig.createAwareness?.( ydoc, objectId );

			let observersAttached = false;
			let onRecordUpdate:
				| ( (
						events: Y.YEvent< any >[],
						transaction: Y.Transaction
				  ) => void )
				| undefined;
			let onStateMapUpdate:
				| ( (
						event: Y.YMapEvent< unknown >,
						transaction: Y.Transaction
				  ) => void )
				| undefined;

			return {
				awareness,

				createSession: () =>
					createYjsSessionCodec( { awareness, doc: ydoc } ),

				hydrate( record, persist ) {
					const {
						applyChangesToCRDTDoc,
						getChangesFromCRDTDoc,
						getPersistedCRDTDoc,
					} = syncConfig;

					// Initialize the Yjs document with the necessary CRDT state.
					initializeYjsDoc( ydoc );

					// Get the persisted CRDT document, if it exists.
					const serialized = getPersistedCRDTDoc?.( record );
					const tempDoc = serialized
						? deserializeCrdtDoc( serialized )
						: null;

					if ( ! tempDoc ) {
						// Apply the current record as changes and request that the
						// CRDT doc be persisted with the entity.
						ydoc.transact( () => {
							applyChangesToCRDTDoc( ydoc, record );
							persist();
						}, LOCAL_SYNC_MANAGER_ORIGIN );
						return;
					}

					// Apply the persisted document as a single update. This is done
					// even if invalidated, to prevent a newly joining peer (or
					// refreshing user) from re-initializing the CRDT document (the
					// "initialization problem").
					//
					// IMPORTANT: Do not wrap this in a transaction with the local
					// origin. It effectively advances the state vector for the
					// current client, which causes Yjs to think that another client
					// is using this client ID.
					const update = Y.encodeStateAsUpdateV2( tempDoc );
					Y.applyUpdateV2( ydoc, update );

					// Compute the differences between the persisted doc and the
					// record (server-on-save mutations, out-of-band updates, or a
					// peer's changes synced before this ran).
					const invalidations = getChangesFromCRDTDoc(
						tempDoc,
						record
					);
					const invalidatedKeys = Object.keys( invalidations );

					// Destroy the temporary document to prevent leaks.
					tempDoc.destroy();

					if ( 0 === invalidatedKeys.length ) {
						// The persisted CRDT document is valid; nothing to apply.
						return;
					}

					const changes = invalidatedKeys.reduce(
						( acc, key ) =>
							Object.assign( acc, { [ key ]: record[ key ] } ),
						{}
					);

					ydoc.transact( () => {
						applyChangesToCRDTDoc( ydoc, changes );
						persist();
					}, LOCAL_SYNC_MANAGER_ORIGIN );
				},

				applyLocalChanges( changes, origin, options ) {
					ydoc.transact( () => {
						syncConfig.applyChangesToCRDTDoc( ydoc, changes );
						if ( options.isSave ) {
							markEntityAsSaved( ydoc );
						}
					}, origin );
				},

				getEditorChanges: ( editedRecord ) =>
					syncConfig.getChangesFromCRDTDoc( ydoc, editedRecord ),

				encodeSnapshot: () => encodeDocSnapshot( ydoc ),

				containsSnapshot: ( encoded ) =>
					docContainsSnapshot( ydoc, encoded ),

				serialize: () => serializeCrdtDoc( ydoc ),

				observe( observers ) {
					// When the CRDT document is updated by an UndoManager or a
					// connection (not a local origin), update the local store.
					onRecordUpdate = ( _events, transaction ) => {
						if (
							transaction.local &&
							! ( transaction.origin instanceof Y.UndoManager )
						) {
							return;
						}
						observers.onRemoteChange();
					};

					onStateMapUpdate = ( event, transaction ) => {
						if ( transaction.local ) {
							return;
						}
						event.keysChanged.forEach( ( key ) => {
							if ( SAVED_AT_KEY === key ) {
								const savedAt = stateMap.get( SAVED_AT_KEY );
								if (
									'number' === typeof savedAt &&
									savedAt > now
								) {
									// Another peer saved the entity.
									observers.onPeerSave();
								}
							}
						} );
					};

					recordMap.observeDeep( onRecordUpdate );
					stateMap.observe( onStateMapUpdate );
					observersAttached = true;
				},

				addToUndoScope( undoManager, meta ) {
					undoManager.addToScope( recordMap, meta );
				},

				destroy() {
					if ( observersAttached ) {
						if ( onRecordUpdate ) {
							recordMap.unobserveDeep( onRecordUpdate );
						}
						if ( onStateMapUpdate ) {
							stateMap.unobserve( onStateMapUpdate );
						}
					}
					ydoc.destroy();
				},
			};
		},

		createCollection( { syncConfig, objectType } ): EngineCollection {
			const ydoc = createYjsDoc( { collection: true, objectType } );
			const stateMap = ydoc.getMap( CRDT_STATE_MAP_KEY );
			const now = Date.now();

			// If the sync config supports awareness, create it.
			const awareness = syncConfig.createAwareness?.( ydoc );

			let observersAttached = false;
			let onStateMapUpdate:
				| ( (
						event: Y.YMapEvent< unknown >,
						transaction: Y.Transaction
				  ) => void )
				| undefined;

			return {
				awareness,

				createSession: () =>
					createYjsSessionCodec( { awareness, doc: ydoc } ),

				initialize: () => initializeYjsDoc( ydoc ),

				observe( observers ) {
					onStateMapUpdate = ( event, transaction ) => {
						if ( transaction.local ) {
							return;
						}
						event.keysChanged.forEach( ( key ) => {
							if ( SAVED_AT_KEY === key ) {
								const newValue = stateMap.get( SAVED_AT_KEY );
								if (
									'number' === typeof newValue &&
									newValue > now
								) {
									// A peer performed a user-facing save that
									// may affect the collection.
									observers.onPeerSave();
								}
							}
						} );
					};

					stateMap.observe( onStateMapUpdate );
					observersAttached = true;
				},

				markSaved( origin ) {
					ydoc.transact( () => markEntityAsSaved( ydoc ), origin );
				},

				destroy() {
					if ( observersAttached && onStateMapUpdate ) {
						stateMap.unobserve( onStateMapUpdate );
					}
					ydoc.destroy();
				},
			};
		},
	};
}
