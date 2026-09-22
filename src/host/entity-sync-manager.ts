/**
 * The host bridge: the one object this plugin registers through core-data's
 * entity sync seam. It resolves the engine the server announced, negotiates
 * a transport, and translates the seam's record lifecycle (load, edit, save,
 * delete, undo) into the sync manager's calls. Everything the old
 * core-data integration did around the manager (connection status, the
 * review list and its notices, undo selection metadata, the "no longer
 * supported" stand-down) lives here.
 */

/**
 * External dependencies
 */
import fastDeepEqual from 'fast-deep-equal/es6/index.js';

/**
 * WordPress dependencies
 */
import { dispatch, select, subscribe } from '@wordpress/data';
import {
	store as coreStore,
	privateApis as coreDataPrivateApis,
} from '@wordpress/core-data';
import type {
	EntitySyncEditOptions,
	EntitySyncManager,
	EntitySyncRecord,
	EntitySyncRecordHandlers,
	EntitySyncRecordId,
} from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { unlock } from './unlock';
import { store as hostStore } from './store';
import { setActiveSyncManager } from './manager';
import { getEntitySyncConfig } from './sync-config';
import {
	ConnectionErrorCode,
	getAnnouncedSync,
	getProviderCreators,
	LOCAL_EDITOR_ORIGIN,
	LOCAL_UNDO_IGNORED_ORIGIN,
	resolveEngineAdapter,
} from '../sync';
import type {
	ConnectionStatus,
	ObjectID,
	RecordHandlers,
	SyncManager,
	SyncReviewItem,
	Y,
} from '../sync';
import {
	getSelectionHistory,
	restoreSelection,
} from '../engines/yjs/crdt/crdt-selection';
import { getRawValue } from '../engines/yjs/crdt/crdt';

const { registerEntitySyncManager, getEntitySyncManager } =
	unlock( coreDataPrivateApis );

/** Past this many open conflicts, one counter notice replaces the per-item ones. */
const AGGREGATE_NOTICE_THRESHOLD = 3;

/**
 * Whether the debug envelope and the manager's performance logging are on
 * (the wire inspector persists the flag per profile).
 *
 * @return Whether to run the manager in debug mode.
 */
function isDebugEnabled(): boolean {
	try {
		return '1' === window.localStorage?.getItem( 'wpSyncDebug' );
	} catch {
		return false;
	}
}

// The sync manager templates ids into room names, so a record id passes
// through as core-data holds it (a number for posts).
function toObjectId( recordId: EntitySyncRecordId ): ObjectID {
	return recordId as ObjectID;
}

function getServerMutatedMetaFields(
	updatedMeta: EntitySyncRecord | undefined,
	persistedMeta: EntitySyncRecord | undefined,
	syncedMeta: EntitySyncRecord | undefined
): EntitySyncRecord {
	const baseline = { ...persistedMeta, ...syncedMeta };
	return Object.fromEntries(
		Object.entries( updatedMeta ?? {} ).filter(
			( [ key, value ] ) => ! fastDeepEqual( value, baseline[ key ] )
		)
	);
}

/**
 * The fields of a save response that differ from what the client sent (or
 * held before the save): the changes the server made on its own, which the
 * shared document must learn about.
 *
 * @param updatedRecord   The save response.
 * @param persistedRecord The record before the save.
 * @param syncedChanges   The edits that were sent.
 * @return The server-mutated fields.
 */
export function getServerMutatedFields(
	updatedRecord: EntitySyncRecord,
	persistedRecord: EntitySyncRecord,
	syncedChanges: EntitySyncRecord
): EntitySyncRecord {
	return Object.fromEntries(
		Object.entries( updatedRecord ).flatMap( ( [ key, value ] ) => {
			if ( key === 'meta' ) {
				const serverMutatedMeta = getServerMutatedMetaFields(
					value as EntitySyncRecord | undefined,
					persistedRecord.meta as EntitySyncRecord | undefined,
					syncedChanges.meta as EntitySyncRecord | undefined
				);
				return Object.keys( serverMutatedMeta ).length
					? [ [ key, serverMutatedMeta ] ]
					: [];
			}

			const baseline =
				key in syncedChanges
					? syncedChanges[ key ]
					: persistedRecord[ key ];

			// The save response nests raw attributes as `{ raw, rendered }`
			// while the baseline holds raw strings; compare raw values so the
			// shape difference does not read as a server mutation.
			const wasServerMutated = ! fastDeepEqual(
				getRawValue( value ) ?? value,
				getRawValue( baseline ) ?? baseline
			);

			return wasServerMutated ? [ [ key, value ] ] : [];
		} )
	);
}

/**
 * Puts the classic post lock back in front of the user when collaboration
 * stops after the page loaded (the server had suppressed the lock because
 * it expected collaboration to run). Only when someone else holds the lock;
 * otherwise this tab holds it and nothing changes.
 */
function reengagePostLock(): void {
	const lockedBy = getAnnouncedSync()?.screen?.lockedBy;
	if ( ! lockedBy ) {
		return;
	}
	dispatch( editorStore ).updatePostLock( {
		isLocked: true,
		isTakeover: false,
		user: { name: lockedBy.name, avatar: lockedBy.avatar },
	} );
}

/**
 * Tells the editor collaboration stopped: the sync manager unloads
 * everything, undo returns to the editor's own, and the lock comes back.
 *
 * @param manager The sync manager to stand down.
 */
function standDown( manager: SyncManager | undefined ): void {
	manager?.unloadAll();
	unlock( dispatch( coreStore ) ).__unstableNotifySyncUndoManagerChange( {
		hasUndo: false,
		hasRedo: false,
	} );
	reengagePostLock();
}

/**
 * The notice logic around an entity's parked conflicts: per-item notices
 * with Restore/Discard, collapsed into one counter notice past the
 * threshold, kept in step with the settled open list.
 *
 * @param kind Entity kind.
 * @param name Entity name.
 * @param key  Record id.
 * @return The two review handlers for the sync manager.
 */
function createReviewHandlers(
	kind: string,
	name: string,
	key: EntitySyncRecordId
): Pick< RecordHandlers, 'onProposalsChange' | 'onEscalation' > {
	const escalationNoticeId = ( proposalId: string ) =>
		`gutenberg-sync-engines-escalation-${ kind }-${ name }-${ key }-${ proposalId }`;
	const aggregateNoticeId = `gutenberg-sync-engines-review-aggregate-${ kind }-${ name }-${ key }`;
	let aggregateNoticeActive = false;
	let knownProposalIds: string[] = [];

	return {
		onProposalsChange: ( items: SyncReviewItem[] ) => {
			dispatch( hostStore ).setSyncReviewItems( kind, name, key, items );

			const notices = dispatch( noticesStore );
			const openIds = new Set( items.map( ( item ) => item.id ) );
			const useAggregate = items.length > AGGREGATE_NOTICE_THRESHOLD;

			if ( useAggregate && ! aggregateNoticeActive ) {
				// Entering aggregate mode: sweep per-item notices so they
				// don't stack under the counter notice.
				for ( const id of knownProposalIds ) {
					notices.removeNotice( escalationNoticeId( id ) );
				}
			}
			aggregateNoticeActive = useAggregate;

			if ( useAggregate ) {
				notices.createNotice(
					'warning',
					sprintf(
						/* translators: %d: number of edits set aside for review. */
						_n(
							'%d edit was set aside because of conflicting changes. Review it in the Collaboration panel of the document settings.',
							'%d edits were set aside because of conflicting changes. Review them in the Collaboration panel of the document settings.',
							items.length,
							'gutenberg-sync-engines'
						),
						items.length
					),
					{ id: aggregateNoticeId, isDismissible: true }
				);
			} else {
				notices.removeNotice( aggregateNoticeId );
				// Remove notices for proposals resolved elsewhere (another
				// collaborator, the review panel, or another tab).
				for ( const id of knownProposalIds ) {
					if ( ! openIds.has( id ) ) {
						notices.removeNotice( escalationNoticeId( id ) );
					}
				}
			}

			knownProposalIds = items.map( ( item ) => item.id );
		},

		onEscalation: ( { isLocal, proposalId, summary } ) => {
			// While aggregated, the counter notice and the review panel carry
			// the information; skip the per-item notice.
			if ( aggregateNoticeActive ) {
				return;
			}
			const base = isLocal
				? __(
						"One of your recent edits conflicted with a collaborator's change and was set aside.",
						'gutenberg-sync-engines'
				  )
				: __(
						"A collaborator's edit conflicted with recent changes and was set aside.",
						'gutenberg-sync-engines'
				  );
			const content = summary
				? sprintf(
						/* translators: 1: conflict description. 2: the lost content. */
						__(
							'%1$s Lost content: “%2$s”',
							'gutenberg-sync-engines'
						),
						base,
						summary
				  )
				: base;
			const noticeId = escalationNoticeId( proposalId );
			const close = ( resolution: 'restored' | 'dismissed' ) => {
				if ( 'restored' === resolution ) {
					dispatch( hostStore ).restoreSyncProposal(
						kind,
						name,
						key,
						proposalId
					);
				} else {
					dispatch( hostStore ).resolveSyncProposal(
						kind,
						name,
						key,
						proposalId,
						'dismissed'
					);
				}
				dispatch( noticesStore ).removeNotice( noticeId );
			};
			dispatch( noticesStore ).createNotice( 'warning', content, {
				id: noticeId,
				isDismissible: true,
				actions: [
					{
						label: __( 'Restore', 'gutenberg-sync-engines' ),
						onClick: () => close( 'restored' ),
					},
					{
						label: __( 'Discard', 'gutenberg-sync-engines' ),
						onClick: () => close( 'dismissed' ),
					},
				],
			} );
		},
	};
}

/**
 * Wraps the seam's record handlers with everything the sync manager needs
 * beyond them.
 *
 * @param kind     Entity kind.
 * @param name     Entity name.
 * @param key      Record id.
 * @param handlers The seam handlers.
 * @return The manager's record handlers.
 */
function createRecordHandlers(
	kind: string,
	name: string,
	key: EntitySyncRecordId,
	handlers: EntitySyncRecordHandlers
): RecordHandlers {
	return {
		editRecord: handlers.editRecord,
		getEditedRecord: handlers.getEditedRecord,
		refetchRecord: handlers.refetchRecord,
		onUndoStackChange: handlers.onUndoStackChange,
		...createReviewHandlers( kind, name, key ),

		onStatusChange: ( status: ConnectionStatus | null ) => {
			dispatch( hostStore ).setSyncConnectionStatus(
				kind,
				name,
				key,
				status
			);
			// A document too large for the transport ends collaboration for
			// this page; the classic lock takes over.
			if (
				'disconnected' === status?.status &&
				ConnectionErrorCode.DOCUMENT_SIZE_LIMIT_EXCEEDED ===
					status.error?.code
			) {
				dispatch( hostStore ).setCollaborationSupported( false );
			}
		},

		addUndoMeta: ( ydoc: Y.Doc, meta: Map< string, unknown > ) => {
			const selectionHistory = getSelectionHistory( ydoc );
			if ( selectionHistory ) {
				meta.set( 'selectionHistory', selectionHistory );
			}
		},

		restoreUndoMeta: ( ydoc: Y.Doc, meta: Map< string, unknown > ) => {
			const selectionHistory = meta.get( 'selectionHistory' );
			if ( selectionHistory ) {
				// Yjs initiates the undo, so wait until the content is
				// restored before moving the selection.
				setTimeout( () => {
					restoreSelection(
						selectionHistory as Parameters<
							typeof restoreSelection
						>[ 0 ],
						ydoc
					);
				}, 0 );
			}
		},
	};
}

function getEditOrigin( options: EntitySyncEditOptions ): string {
	// An untracked origin keeps undo-ignored changes out of the undo
	// history while still syncing them to peers.
	return options.undoIgnore ? LOCAL_UNDO_IGNORED_ORIGIN : LOCAL_EDITOR_ORIGIN;
}

function isNewUndoLevel( options: EntitySyncEditOptions ): boolean {
	// Transient changes (typing) merge into the current undo level;
	// completed ones start a new level.
	return options.undoIgnore ? false : ! options.isCached;
}

/**
 * Builds the seam manager over a sync manager.
 *
 * @param inner The engine's sync manager.
 * @return The manager to register with core-data.
 */
export function createHostEntitySyncManager(
	inner: SyncManager
): EntitySyncManager {
	const disabledPostTypes = getAnnouncedSync()?.disabledPostTypes ?? [];

	return {
		shouldSync( kind, name ) {
			if ( ! select( hostStore ).isCollaborationSupported() ) {
				return false;
			}
			if ( 'postType' === kind && disabledPostTypes.includes( name ) ) {
				return false;
			}
			return Boolean( getEntitySyncConfig( kind, name ) );
		},

		load( kind, name, recordId, record, handlers ) {
			const syncConfig = getEntitySyncConfig( kind, name );
			if ( ! syncConfig ) {
				return;
			}
			return inner.load(
				syncConfig,
				`${ kind }/${ name }`,
				toObjectId( recordId ),
				record,
				createRecordHandlers( kind, name, recordId, handlers )
			);
		},

		loadCollection( kind, name, handlers ) {
			const syncConfig = getEntitySyncConfig( kind, name );
			if (
				! syncConfig ||
				! select( hostStore ).isCollaborationSupported()
			) {
				return;
			}
			return inner.loadCollection( syncConfig, `${ kind }/${ name }`, {
				refetchRecords: handlers.refetchRecords,
				onStatusChange: ( status ) => {
					dispatch( hostStore ).setSyncConnectionStatus(
						kind,
						name,
						null,
						status
					);
				},
			} );
		},

		update( kind, name, recordId, edits, options ) {
			if ( ! getEntitySyncConfig( kind, name ) ) {
				return;
			}
			inner.update(
				`${ kind }/${ name }`,
				toObjectId( recordId ),
				edits,
				getEditOrigin( options ),
				{ isNewUndoLevel: isNewUndoLevel( options ) }
			);
		},

		beforeSave( kind, name, recordId, edits, { persistedRecord } ) {
			// A direct `saveEntityRecord` call bypasses `editEntityRecord`,
			// so make sure its changes enter the document first.
			if ( ! getEntitySyncConfig( kind, name ) || ! persistedRecord ) {
				return;
			}
			inner.update(
				`${ kind }/${ name }`,
				toObjectId( recordId ),
				edits,
				LOCAL_UNDO_IGNORED_ORIGIN
			);
		},

		afterSave(
			kind,
			name,
			recordId,
			{ savedRecord, persistedRecord, edits }
		) {
			if ( ! getEntitySyncConfig( kind, name ) ) {
				return;
			}
			const syncChanges = persistedRecord
				? getServerMutatedFields( savedRecord, persistedRecord, edits )
				: savedRecord;
			// An untracked origin so the save response creates no undo level.
			inner.update(
				`${ kind }/${ name }`,
				undefined === recordId ? null : toObjectId( recordId ),
				syncChanges,
				LOCAL_UNDO_IGNORED_ORIGIN,
				{ isSave: true }
			);
		},

		unload( kind, name, recordId ) {
			if ( ! getEntitySyncConfig( kind, name ) ) {
				return;
			}
			inner.unload( `${ kind }/${ name }`, toObjectId( recordId ) );
		},

		unloadAll() {
			inner.unloadAll();
		},

		// A getter: the undo manager only exists once a synced entity loaded.
		get undoManager() {
			return inner.undoManager;
		},
	};
}

let warned = false;

function warnOnce( message: string ): void {
	if ( warned ) {
		return;
	}
	warned = true;
	// eslint-disable-next-line no-console
	console.warn( message );
}

/**
 * Registers nothing and tells the user why: the server expected
 * collaboration to run on this screen, so the lock was suppressed and
 * must come back.
 *
 * @param message The notice text.
 */
function declineWithNotice( message: string ): void {
	warnOnce( message );
	dispatch( hostStore ).setCollaborationSupported( false );
	reengagePostLock();
	dispatch( noticesStore ).createNotice( 'warning', message, {
		id: 'gutenberg-sync-engines-unavailable',
		isDismissible: true,
	} );
}

/**
 * Installs the host bridge for this page, when the server announced a
 * usable engine and transport for a supported screen. Returns a function
 * that tears it down (test use).
 *
 * @return Uninstall function.
 */
export function installHostBridge(): () => void {
	const announced = getAnnouncedSync();
	if ( ! announced?.screen?.supported ) {
		return () => {};
	}

	if ( getEntitySyncManager() ) {
		warnOnce(
			'Gutenberg Sync Engines: another entity sync manager is already registered (is the Gutenberg real-time collaboration experiment still on?). This plugin registered nothing; turn that experiment off.'
		);
		return () => {};
	}

	const adapter = resolveEngineAdapter();
	if ( ! adapter ) {
		declineWithNotice(
			__(
				'Real-time collaboration is unavailable: this site uses a collaboration engine this editor does not support. Standard post locking is in effect; try refreshing the page.',
				'gutenberg-sync-engines'
			)
		);
		return () => {};
	}

	if ( 0 === getProviderCreators().length ) {
		declineWithNotice(
			__(
				'Real-time collaboration is unavailable: this site uses a collaboration transport this editor does not support. Standard post locking is in effect.',
				'gutenberg-sync-engines'
			)
		);
		return () => {};
	}

	const inner = adapter.createManager( isDebugEnabled() );
	setActiveSyncManager( inner );
	const unregister = registerEntitySyncManager(
		createHostEntitySyncManager( inner )
	);

	// When the page stops supporting collaboration (a legacy meta box, an
	// oversized document), the manager stands down and the lock returns.
	let wasSupported = true;
	const unsubscribe = subscribe( () => {
		const supported = select( hostStore ).isCollaborationSupported();
		if ( wasSupported && ! supported ) {
			standDown( inner );
		}
		wasSupported = supported;
	}, hostStore );

	return () => {
		unsubscribe();
		unregister();
		setActiveSyncManager( undefined );
	};
}
