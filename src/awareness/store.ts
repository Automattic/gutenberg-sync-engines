/**
 * The slow-awareness data store: each peer's latest block. Blocks look
 * their own peer up by identity, so a block the local editor has not
 * received yet simply matches nothing and shows nothing.
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
}

type Action =
	| { type: 'SET_PEER'; peer: Peer }
	| { type: 'REMOVE_PEER'; key: string }
	| { type: 'RESET' };

const DEFAULT_STATE: State = { peers: {} };

function samePeer( a: Peer, b: Peer ): boolean {
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
			return {
				peers: { ...state.peers, [ action.peer.key ]: action.peer },
			};
		}
		case 'REMOVE_PEER': {
			if ( ! state.peers[ action.key ] ) {
				return state;
			}
			const peers = { ...state.peers };
			delete peers[ action.key ];
			return { peers };
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
	 * The peer shown on one block: the first peer whose block matches the
	 * block's durable identity or its clientId. One peer per block for
	 * now; stacking is a later step.
	 *
	 * @param state    Store state.
	 * @param syncId   The block's syncId, if stamped.
	 * @param clientId The block's clientId.
	 * @return The peer, or null.
	 */
	getPeerForBlock(
		state: State,
		syncId: string | undefined,
		clientId: string
	): Peer | null {
		for ( const key in state.peers ) {
			const peer = state.peers[ key ];
			if (
				null !== peer.block &&
				( peer.block === syncId || peer.block === clientId )
			) {
				return peer;
			}
		}
		return null;
	},
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
