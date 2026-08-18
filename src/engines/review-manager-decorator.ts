/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type {
	ObjectID,
	ObjectType,
	SyncManager,
	SyncReviewItem,
} from '@wordpress/sync';

/**
 * The engine-side review surface a decorated manager reads: the open
 * parked-conflict list per entity, a change subscription, and the two
 * resolution verbs. Engines that park escalations (an escalation lane)
 * implement this; the decorator adapts it to the framework's review SPI
 * (`onProposalsChange`/`onEscalation` handlers plus
 * `SyncManager.resolveProposal`/`restoreProposal`).
 */
export interface EngineReviewSource {
	getOpenItems: (
		objectType: ObjectType,
		objectId: ObjectID | null
	) => SyncReviewItem[];
	/** Returns an unsubscribe function. */
	subscribe: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		listener: () => void
	) => () => void;
	resolveProposal: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		proposalId: string,
		resolution: 'restored' | 'dismissed'
	) => void;
	/**
	 * Best-effort restore of the parked content as ordinary local edits
	 * under the restorer's capability, then resolves as restored.
	 *
	 * `modifiedBlocks` is the modify-before-adopt lane (TODO-17,
	 * upstream's `reviewed_block_source`): the reviewer's edited
	 * replacements for specific parked blocks, keyed by the parked
	 * block's index. What the reviewer supplies IS what gets applied —
	 * approval and content are pinned together by construction.
	 */
	restoreProposal: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		proposalId: string,
		modifiedBlocks?: Array< { index: number; html: string } >
	) => void;
}

/**
 * Decorates a framework `createSyncManager` manager with the proposal/review
 * plumbing the framework UI expects but the generic manager does not provide.
 *
 * The framework manager's internal handler rewrap drops the optional
 * `onEscalation`/`onProposalsChange` record handlers on the floor, so this
 * decorator captures them in `load()` BEFORE delegating and drives them from
 * the engine's review source, using the same notification discipline as the
 * intent-log manager: a microtask-coalesced `onProposalsChange` with the full
 * open list (so a bootstrap replay of long-resolved conflicts notifies once,
 * with the settled list), then `onEscalation` once per newly seen proposal id.
 *
 * Every other member delegates to the inner manager. `undoManager` MUST
 * delegate through a getter — the inner manager creates it lazily on first
 * entity load, so a spread would freeze it as `undefined` forever.
 *
 * @param inner  The manager returned by the framework's createSyncManager.
 * @param review The engine's review source.
 * @return The decorated manager.
 */
export function decorateManagerWithReview(
	inner: SyncManager,
	review: EngineReviewSource
): SyncManager & {
	restoreProposalWithChanges: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		proposalId: string,
		modifiedBlocks: Array< { index: number; html: string } >
	) => void;
} {
	interface EntityReviewState {
		unsubscribe: () => void;
		notifyScheduled: boolean;
		notifiedIds: Set< string >;
		unloaded: boolean;
	}
	const entities = new Map< string, EntityReviewState >();
	const keyOf = ( objectType: ObjectType, objectId: ObjectID | null ) =>
		`${ objectType }:${ String( objectId ) }`;

	const detach = ( key: string ) => {
		const state = entities.get( key );
		if ( state ) {
			state.unloaded = true;
			state.unsubscribe();
			entities.delete( key );
		}
	};

	const decorated: SyncManager & {
		restoreProposalWithChanges: (
			objectType: ObjectType,
			objectId: ObjectID | null,
			proposalId: string,
			modifiedBlocks: Array< { index: number; html: string } >
		) => void;
	} = {
		...inner,

		// The inner manager creates its undo manager lazily on first entity
		// load; a snapshot here would pin `undefined`.
		get undoManager() {
			return inner.undoManager;
		},

		async load( syncConfig, objectType, objectId, record, handlers ) {
			const { onEscalation, onProposalsChange } = handlers;
			const key = keyOf( objectType, objectId );

			// Re-loading an already-loaded entity is a no-op in the inner
			// manager; keep the review subscription single too.
			detach( key );

			const state: EntityReviewState = {
				unsubscribe: () => {},
				notifyScheduled: false,
				notifiedIds: new Set(),
				unloaded: false,
			};
			entities.set( key, state );

			const notify = () => {
				if ( state.notifyScheduled ) {
					return;
				}
				state.notifyScheduled = true;
				void Promise.resolve().then( () => {
					state.notifyScheduled = false;
					if ( state.unloaded ) {
						return;
					}
					const items = review.getOpenItems( objectType, objectId );
					onProposalsChange?.( items );
					for ( const item of items ) {
						if ( state.notifiedIds.has( item.id ) ) {
							continue;
						}
						state.notifiedIds.add( item.id );
						if ( onEscalation ) {
							onEscalation( {
								reason: item.reason,
								isLocal: item.isLocal,
								proposalId: item.id,
								summary: item.summary,
								excerpt: item.excerpt,
							} );
						} else {
							// eslint-disable-next-line no-console
							console.warn(
								'[Sync] An edit was escalated for review (%s): %s',
								item.reason,
								item.id
							);
						}
					}
				} );
			};

			state.unsubscribe = review.subscribe(
				objectType,
				objectId,
				notify
			);

			return inner.load(
				syncConfig,
				objectType,
				objectId,
				record,
				handlers
			);
		},

		resolveProposal( objectType, objectId, proposalId, resolution ) {
			review.resolveProposal(
				objectType,
				objectId,
				proposalId,
				resolution
			);
		},

		restoreProposal( objectType, objectId, proposalId ) {
			review.restoreProposal( objectType, objectId, proposalId );
		},

		// Not part of the framework SyncManager SPI (its restore verb has
		// no content parameter): the modify-before-adopt lane rides an
		// additional method UI extensions call directly.
		restoreProposalWithChanges(
			objectType: ObjectType,
			objectId: ObjectID | null,
			proposalId: string,
			modifiedBlocks: Array< { index: number; html: string } >
		) {
			review.restoreProposal(
				objectType,
				objectId,
				proposalId,
				modifiedBlocks
			);
		},

		unload( objectType, objectId ) {
			detach( keyOf( objectType, objectId ) );
			inner.unload( objectType, objectId );
		},

		unloadAll() {
			for ( const key of Array.from( entities.keys() ) ) {
				detach( key );
			}
			inner.unloadAll();
		},
	};

	return decorated;
}
