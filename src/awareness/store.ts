/**
 * The slow-awareness data store: each peer's latest block. Blocks look
 * their own peers up by identity, so a block the local editor has not
 * received yet simply matches nothing and shows nothing.
 *
 * Several peers can share a block. The store remembers the order they
 * entered it (`Peer.entered`), so the block can keep the color of whoever
 * arrived first for as long as they stay, and stack the others behind
 * them in arrival order.
 */

/**
 * WordPress dependencies
 */
import { createReduxStore, createSelector, register } from '@wordpress/data';

/**
 * Internal dependencies
 */
import type { Peer, PeerIdentity } from './types';

export const STORE_NAME = 'gutenberg-sync-engines/awareness';

interface State {
	peers: Record< string, Peer >;
	/** The `entered` value the next block change receives. */
	nextEntered: number;
}

/** What a channel reports: a peer, without the store-owned entry order. */
export type PeerReport = Omit< Peer, 'entered' >;

type Action =
	| { type: 'SET_PEER'; peer: PeerReport }
	| { type: 'REMOVE_PEER'; key: string }
	| { type: 'RESET' };

const DEFAULT_STATE: State = { peers: {}, nextEntered: 1 };

const NO_PEERS: Peer[] = [];

function samePeer( a: PeerReport, b: PeerReport ): boolean {
	return (
		a.key === b.key &&
		a.block === b.block &&
		a.color === b.color &&
		a.identity.userId === b.identity.userId &&
		a.identity.name === b.identity.name &&
		a.identity.avatarUrl === b.identity.avatarUrl
	);
}

function reducer( state: State = DEFAULT_STATE, action: Action ): State {
	switch ( action.type ) {
		case 'SET_PEER': {
			const existing = state.peers[ action.peer.key ];
			if ( existing && samePeer( existing, action.peer ) ) {
				return state;
			}
			// The same block again (a name or avatar change) keeps the
			// peer's place in the block; a different block is a new entry.
			const staysInBlock =
				existing && existing.block === action.peer.block;
			const entered = staysInBlock ? existing.entered : state.nextEntered;
			return {
				peers: {
					...state.peers,
					[ action.peer.key ]: { ...action.peer, entered },
				},
				nextEntered: staysInBlock
					? state.nextEntered
					: state.nextEntered + 1,
			};
		}
		case 'REMOVE_PEER': {
			if ( ! state.peers[ action.key ] ) {
				return state;
			}
			const peers = { ...state.peers };
			delete peers[ action.key ];
			return { ...state, peers };
		}
		case 'RESET':
			return DEFAULT_STATE;
	}
	return state;
}

const actions = {
	setPeer(
		key: string,
		identity: PeerIdentity,
		color: string,
		block: string | null
	): Action {
		return { type: 'SET_PEER', peer: { key, identity, color, block } };
	},
	removePeer( key: string ): Action {
		return { type: 'REMOVE_PEER', key };
	},
	reset(): Action {
		return { type: 'RESET' };
	},
};

const selectors = {
	getPeers: createSelector(
		( state: State ): Peer[] => Object.values( state.peers ),
		( state: State ) => [ state.peers ]
	),
	/**
	 * The peers in one block, in the order they entered it: every peer
	 * whose block matches the block's durable identity or its clientId.
	 * The first one is the block's primary peer (the outline color); the
	 * rest stack behind them. Returns one shared empty array when nobody
	 * is there, so unaffected blocks see no change.
	 *
	 * @param state    Store state.
	 * @param syncId   The block's syncId, if stamped.
	 * @param clientId The block's clientId.
	 * @return The peers, oldest entry first.
	 */
	getPeersForBlock: createSelector(
		(
			state: State,
			syncId: string | undefined,
			clientId: string
		): Peer[] => {
			const peers: Peer[] = [];
			for ( const key in state.peers ) {
				const peer = state.peers[ key ];
				if (
					null !== peer.block &&
					( peer.block === syncId || peer.block === clientId )
				) {
					peers.push( peer );
				}
			}
			if ( ! peers.length ) {
				return NO_PEERS;
			}
			return peers.sort( ( a, b ) => a.entered - b.entered );
		},
		( state: State ) => [ state.peers ]
	),
};

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

let registered = false;

/**
 * Registers the store once.
 */
export function registerAwarenessStore(): void {
	if ( registered ) {
		return;
	}
	registered = true;
	register( store );
}
