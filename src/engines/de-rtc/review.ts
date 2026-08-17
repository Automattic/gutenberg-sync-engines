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
	excerpt?: string;
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

	return {
		noteParked( parked ) {
			if (
				resolvedIds.has( parked.proposalId ) ||
				open.has( parked.proposalId )
			) {
				return; // Redelivery (or resolved before this replica saw it).
			}
			open.set( parked.proposalId, parked );
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
			resolvedIds.add( proposalId );
			if ( open.delete( proposalId ) ) {
				notify();
			}
			emitter?.( {
				data: JSON.stringify( { proposalId, resolution } ),
				type: DE_RTC_RESOLVED_TYPE,
			} );
		},
	};
}
