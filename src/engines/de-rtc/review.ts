/**
 * Internal dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { EngineUpdate } from '@wordpress/sync';

/**
 * A parked (escalated) proposal as the server's `proposal-parked` row
 * carries it. `changedBlocks` are the proposal's top-level blocks that
 * differed from its base, with their indices, so a restore can overlay
 * them at sensible anchors.
 */
export interface DeRtcParkedProposal {
	proposalId: string;
	reason: string;
	authorClientId: number;
	/** The escalating user's id (server-stamped). */
	author?: number;
	/** Server time at escalation. */
	at?: number;
	baseVersion?: string;
	changedBlocks: Array< { index: number; html: string } >;
	/** A conflicting entity-property register (property-conflict rows). */
	property?: { name: string; value: unknown };
	excerpt?: string;
	/**
	 * Merge-not-stack (TODO-12): how many parked revisions this ONE
	 * review task has folded (the fields above always show the latest).
	 */
	revisions?: number;
	/** proposalIds of superseded revisions — resolved with this task. */
	supersededIds?: string[];
}

/**
 * Client-sent row type closing a parked proposal. Matches
 * WP_De_RTC_Engine::UPDATE_TYPE_RESOLVED.
 */
export const DE_RTC_RESOLVED_TYPE = 'resolved';

/**
 * The per-entity open-proposal ledger the session codec feeds (parked and
 * resolved rows) and the engine's review source reads. Sessions register
 * an emitter so resolutions travel out through the ordinary local-update
 * lane.
 */
export interface DeRtcReviewState {
	noteParked: ( parked: DeRtcParkedProposal ) => void;
	noteResolved: ( proposalId: string ) => void;
	getOpen: () => DeRtcParkedProposal[];
	/** Returns an unsubscribe function. */
	onChange: ( listener: () => void ) => () => void;
	/** Registers the outbound lane for resolution rows (last one wins). */
	setEmitter: (
		emitter: ( ( update: EngineUpdate ) => void ) | null
	) => void;
	/**
	 * Optimistically closes a parked proposal and emits the `resolved` row.
	 * The `restored` resolution is sent AFTER the caller re-applied the
	 * parked content as ordinary local edits.
	 */
	resolve: (
		proposalId: string,
		resolution: 'restored' | 'dismissed'
	) => void;
}

/**
 * Creates the per-entity review ledger.
 *
 * @return The review state.
 */
export function createDeRtcReviewState(): DeRtcReviewState {
	const open = new Map< string, DeRtcParkedProposal >();
	const resolvedIds = new Set< string >();
	const listeners = new Set< () => void >();
	let emitter: ( ( update: EngineUpdate ) => void ) | null = null;

	const notify = () => {
		listeners.forEach( ( listener ) => listener() );
	};

	/**
	 * Merge-not-stack key (TODO-12): one review task per author per
	 * target — a property register, or a block index set. A revised
	 * parked proposal from the same author over the same target FOLDS
	 * into the open task instead of raising a second one.
	 *
	 * @param parked Parked proposal.
	 * @return Fold key.
	 */
	const foldKey = ( parked: DeRtcParkedProposal ): string =>
		parked.property?.name
			? `prop:${ parked.authorClientId }:${ parked.property.name }`
			: `blocks:${ parked.authorClientId }:${ (
					parked.changedBlocks ?? []
			  )
					.map( ( block ) => Number( block.index ) )
					.sort( ( a, b ) => a - b )
					.join( ',' ) }`;

	return {
		noteParked( parked ) {
			if (
				resolvedIds.has( parked.proposalId ) ||
				open.has( parked.proposalId )
			) {
				return; // Redelivery (or resolved before this replica saw it).
			}
			const key = foldKey( parked );
			for ( const [ openId, existing ] of open ) {
				if ( foldKey( existing ) !== key ) {
					continue;
				}
				// Supersede: the SAME task, refreshed to the latest
				// revision; earlier revisions resolve with it.
				open.delete( openId );
				open.set( parked.proposalId, {
					...parked,
					revisions: ( existing.revisions ?? 1 ) + 1,
					supersededIds: [
						...( existing.supersededIds ?? [] ),
						existing.proposalId,
					],
				} );
				notify();
				return;
			}
			open.set( parked.proposalId, { ...parked, revisions: 1 } );
			notify();
		},

		noteResolved( proposalId ) {
			if ( resolvedIds.has( proposalId ) ) {
				return;
			}
			resolvedIds.add( proposalId );
			if ( open.delete( proposalId ) ) {
				notify();
			}
		},

		getOpen: () => Array.from( open.values() ),

		onChange( listener ) {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},

		setEmitter( nextEmitter ) {
			emitter = nextEmitter;
		},

		resolve( proposalId, resolution ) {
			// Optimistic: the server's resolved row (and disposition) confirm
			// idempotently; an unknown id still acks as resolved server-side.
			// A folded task resolves EVERY revision it superseded with it
			// (merge-not-stack: one decision closes the whole lineage).
			const item = open.get( proposalId );
			const ids = [ proposalId, ...( item?.supersededIds ?? [] ) ];
			ids.forEach( ( id ) => resolvedIds.add( id ) );
			if ( open.delete( proposalId ) ) {
				notify();
			}
			ids.forEach(
				( id ) =>
					emitter?.( {
						data: JSON.stringify( {
							proposalId: id,
							resolution,
						} ),
						type: DE_RTC_RESOLVED_TYPE,
					} )
			);
		},
	};
}
