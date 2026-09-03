/**
 * The slow-awareness data store: peers' latest beacons, the per-block
 * presence derived from them, and phantom markers for blocks the local
 * editor has not received.
 *
 * Per-block presence is derived only when a beacon arrives or a peer
 * leaves, never on the clock tick: a stripe's strength comes from the
 * sender's trail ages at send time, so it holds steady between beacons.
 * Entries that a new beacon drops linger briefly at zero strength (the
 * "leaving" state) so the stripe can animate out, then a prune removes
 * them.
 */

/**
 * WordPress dependencies
 */
import { createReduxStore, createSelector, register } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { getPeerStatus, trailOpacity } from './staleness';
import type {
	ActivityBeacon,
	BlockRef,
	EditKind,
	PeerActivity,
	PeerIdentity,
	SlowAwarenessSettings,
} from './types';

export const STORE_NAME = 'gutenberg-sync-engines/awareness';

/** How long a dropped entry lingers at zero strength for its animation. */
export const LEAVE_MS = 400;

export type PresenceRole = 'focus' | EditKind | 'recent';

/**
 * One peer's relationship to one block.
 */
export interface BlockPresence {
	peerKey: string;
	name: string;
	userId: number | null;
	color: string;
	role: PresenceRole;
	/** The peer both sits in the block and edited it this window. */
	typing: boolean;
	/** Stripe strength from the sender's trail age: 1, 0.5, or 0 (leaving). */
	opacity: number;
	/** Age of the last interaction when the beacon was built (sender clock). */
	ageMs: number;
	/** When the beacon carrying this entry arrived (receiver clock). */
	receivedAt: number;
	intervalMs: number;
	/** Dropped by the latest beacon; kept only for the exit animation. */
	leaving?: boolean;
}

/**
 * A peer's reference to a block this editor does not have.
 */
export interface PhantomMarker {
	peerKey: string;
	name: string;
	color: string;
	opacity: number;
	role: PresenceRole;
	ref: BlockRef;
	anchorClientId: string | null;
	placement: 'after' | 'inside' | 'start';
	ageMs: number;
	receivedAt: number;
	intervalMs: number;
}

/** A phantom as the controller resolves it, before peer details attach. */
export type PhantomInput = Omit<
	PhantomMarker,
	'name' | 'color' | 'receivedAt' | 'intervalMs'
>;

interface State {
	settings: SlowAwarenessSettings;
	now: number;
	peers: Record< string, PeerActivity >;
	presence: Record< string, BlockPresence[] >;
	presenceSignature: string;
	phantoms: PhantomInput[];
}

type Action =
	| {
			type: 'RECEIVE_BEACON';
			key: string;
			identity: PeerIdentity;
			color: string;
			beacon: ActivityBeacon;
			receivedAt: number;
	  }
	| { type: 'REMOVE_PEER'; key: string }
	| { type: 'TICK'; now: number }
	| { type: 'PRUNE_LEAVING' }
	| { type: 'SET_SETTINGS'; settings: SlowAwarenessSettings }
	| { type: 'SET_PHANTOMS'; phantoms: PhantomInput[] }
	| { type: 'RESET' };

const EMPTY_PRESENCE: BlockPresence[] = [];
const EMPTY_PHANTOMS: PhantomMarker[] = [];

const DEFAULT_STATE: State = {
	settings: { intervalMs: 0, channel: 'sync' },
	now: 0,
	peers: {},
	presence: {},
	presenceSignature: '',
	phantoms: [],
};

/**
 * Every identity a reference answers to.
 *
 * @param ref A block reference.
 * @return syncId (when present) and clientId.
 */
function identitiesOf( ref: BlockRef ): string[] {
	return ref.syncId ? [ ref.syncId, ref.clientId ] : [ ref.clientId ];
}

/**
 * Whether two references name the same block.
 *
 * @param a A reference.
 * @param b Another reference.
 * @return True on a shared identity.
 */
export function isSameBlock( a: BlockRef, b: BlockRef ): boolean {
	if ( a.syncId && b.syncId ) {
		return a.syncId === b.syncId;
	}
	return a.clientId === b.clientId;
}

/**
 * The trail a beacon describes. Older beacons without one are read as
 * "the focused block plus this window's edits, all at full strength".
 *
 * @param beacon A beacon.
 * @return Trail entries.
 */
export function trailOf( beacon: ActivityBeacon ): ActivityBeacon[ 'recent' ] {
	if ( Array.isArray( beacon.recent ) ) {
		return beacon.recent;
	}
	const entries: ActivityBeacon[ 'recent' ] = [];
	if ( beacon.focus ) {
		entries.push( { ref: beacon.focus, ageMs: 0 } );
	}
	for ( const edit of beacon.edits ) {
		if ( 'remove' === edit.kind ) {
			continue;
		}
		if ( ! entries.some( ( e ) => isSameBlock( e.ref, edit.ref ) ) ) {
			entries.push( { ref: edit.ref, ageMs: 0 } );
		}
	}
	return entries;
}

/**
 * Builds one peer's presence entries from its beacon.
 *
 * @param peer The peer.
 * @return Entries with the reference each belongs to.
 */
function entriesFor(
	peer: PeerActivity
): Array< { ref: BlockRef; entry: BlockPresence } > {
	const { focus, edits } = peer.beacon;
	const base = {
		peerKey: peer.key,
		name: peer.identity.name,
		userId: peer.identity.userId,
		color: peer.color,
		receivedAt: peer.receivedAt,
		intervalMs: peer.beacon.intervalMs,
	};
	const out: Array< { ref: BlockRef; entry: BlockPresence } > = [];
	const covered: BlockRef[] = [];

	for ( const { ref, ageMs } of trailOf( peer.beacon ) ) {
		const opacity = trailOpacity( ageMs );
		if ( 0 === opacity ) {
			continue;
		}
		const edit = edits.find(
			( e ) => 'remove' !== e.kind && isSameBlock( e.ref, ref )
		);
		const isFocus = Boolean( focus && isSameBlock( focus, ref ) );
		let role: PresenceRole = 'recent';
		if ( isFocus ) {
			role = 'focus';
		} else if ( edit ) {
			role = edit.kind;
		}
		covered.push( ref );
		out.push( {
			ref,
			entry: {
				...base,
				role,
				typing: isFocus && Boolean( edit ),
				opacity,
				ageMs,
			},
		} );
	}

	// Removals are not in the trail (the block is gone for the sender) but
	// matter to a receiver that still holds the block.
	for ( const edit of edits ) {
		if ( 'remove' !== edit.kind ) {
			continue;
		}
		if ( covered.some( ( ref ) => isSameBlock( ref, edit.ref ) ) ) {
			continue;
		}
		out.push( {
			ref: edit.ref,
			entry: {
				...base,
				role: 'remove',
				typing: false,
				opacity: 1,
				ageMs: 0,
			},
		} );
	}
	return out;
}

/**
 * Rebuilds the per-block presence map from the live peers.
 *
 * @param peers Peers by key.
 * @return identity → presence entries.
 */
function derivePresence(
	peers: Record< string, PeerActivity >
): Record< string, BlockPresence[] > {
	const map: Record< string, BlockPresence[] > = {};
	for ( const peer of Object.values( peers ) ) {
		for ( const { ref, entry } of entriesFor( peer ) ) {
			for ( const identity of identitiesOf( ref ) ) {
				( map[ identity ] ??= [] ).push( entry );
			}
		}
	}
	return map;
}

/**
 * Adds zero-strength "leaving" copies of entries the previous map had and
 * the new one lacks, so their stripes can animate out before the prune.
 *
 * @param previous The previous map.
 * @param next     The new map.
 * @return The new map with leaving entries added.
 */
function withLeaving(
	previous: Record< string, BlockPresence[] >,
	next: Record< string, BlockPresence[] >
): Record< string, BlockPresence[] > {
	let changed = false;
	const out = { ...next };
	for ( const [ identity, entries ] of Object.entries( previous ) ) {
		const current = next[ identity ] ?? [];
		const gone = entries.filter(
			( entry ) =>
				! entry.leaving &&
				! current.some( ( c ) => c.peerKey === entry.peerKey )
		);
		if ( ! gone.length ) {
			continue;
		}
		changed = true;
		out[ identity ] = [
			...current,
			...gone.map( ( entry ) => ( {
				...entry,
				opacity: 0,
				leaving: true,
			} ) ),
		];
	}
	return changed ? out : next;
}

/**
 * The peers whose last beacon has not expired.
 *
 * @param state Store state.
 * @return Live peers by key.
 */
function livePeers( state: State ): Record< string, PeerActivity > {
	const peers: Record< string, PeerActivity > = {};
	for ( const [ key, peer ] of Object.entries( state.peers ) ) {
		if (
			'expired' !==
			getPeerStatus( peer.receivedAt, peer.beacon.intervalMs, state.now )
		) {
			peers[ key ] = peer;
		}
	}
	return peers;
}

function signatureOf( peers: Record< string, PeerActivity > ): string {
	return Object.values( peers )
		.map( ( peer ) => `${ peer.key }:${ peer.beacon.seq }` )
		.sort()
		.join( '|' );
}

/**
 * Recomputes derived presence when the set of live beacons changed.
 *
 * @param state Store state.
 * @return The state, rebuilt when needed.
 */
function withDerived( state: State ): State {
	const peers = livePeers( state );
	const signature = signatureOf( peers );
	if ( signature === state.presenceSignature ) {
		return state;
	}
	return {
		...state,
		peers,
		presence: withLeaving( state.presence, derivePresence( peers ) ),
		presenceSignature: signature,
	};
}

function reducer( state: State = DEFAULT_STATE, action: Action ): State {
	switch ( action.type ) {
		case 'RECEIVE_BEACON': {
			const peer: PeerActivity = {
				key: action.key,
				identity: action.identity,
				color: action.color,
				beacon: action.beacon,
				receivedAt: action.receivedAt,
			};
			return withDerived( {
				...state,
				now: Math.max( state.now, action.receivedAt ),
				peers: { ...state.peers, [ action.key ]: peer },
			} );
		}
		case 'REMOVE_PEER': {
			if ( ! state.peers[ action.key ] ) {
				return state;
			}
			const peers = { ...state.peers };
			delete peers[ action.key ];
			return withDerived( { ...state, peers } );
		}
		case 'TICK':
			return withDerived( { ...state, now: action.now } );
		case 'PRUNE_LEAVING': {
			const hasLeaving = Object.values( state.presence ).some(
				( entries ) => entries.some( ( entry ) => entry.leaving )
			);
			if ( ! hasLeaving ) {
				return state;
			}
			return { ...state, presence: derivePresence( state.peers ) };
		}
		case 'SET_SETTINGS':
			return { ...state, settings: action.settings };
		case 'SET_PHANTOMS':
			return { ...state, phantoms: action.phantoms };
		case 'RESET':
			return { ...DEFAULT_STATE, settings: state.settings };
	}
	return state;
}

const actions = {
	receiveBeacon(
		key: string,
		identity: PeerIdentity,
		color: string,
		beacon: ActivityBeacon,
		receivedAt: number
	): Action {
		return {
			type: 'RECEIVE_BEACON',
			key,
			identity,
			color,
			beacon,
			receivedAt,
		};
	},
	removePeer( key: string ): Action {
		return { type: 'REMOVE_PEER', key };
	},
	tick( now: number ): Action {
		return { type: 'TICK', now };
	},
	pruneLeaving(): Action {
		return { type: 'PRUNE_LEAVING' };
	},
	setSettings( settings: SlowAwarenessSettings ): Action {
		return { type: 'SET_SETTINGS', settings };
	},
	setPhantoms( phantoms: PhantomInput[] ): Action {
		return { type: 'SET_PHANTOMS', phantoms };
	},
	reset(): Action {
		return { type: 'RESET' };
	},
};

const selectors = {
	getSettings( state: State ): SlowAwarenessSettings {
		return state.settings;
	},
	isEnabled( state: State ): boolean {
		return state.settings.intervalMs > 0;
	},
	getNow( state: State ): number {
		return state.now;
	},
	/**
	 * The peers' presence on one block, looked up by its durable identity
	 * first and its clientId second.
	 *
	 * @param state    Store state.
	 * @param syncId   The block's syncId, if stamped.
	 * @param clientId The block's clientId.
	 * @return Presence entries (a stable empty array when none).
	 */
	getBlockPresence(
		state: State,
		syncId: string | undefined,
		clientId: string
	): BlockPresence[] {
		if ( syncId && state.presence[ syncId ] ) {
			return state.presence[ syncId ];
		}
		return state.presence[ clientId ] ?? EMPTY_PRESENCE;
	},
	getPeers: createSelector(
		( state: State ): PeerActivity[] => Object.values( state.peers ),
		( state: State ) => [ state.peers ]
	),
	getPeer( state: State, key: string ): PeerActivity | undefined {
		return state.peers[ key ];
	},
	getPhantoms: createSelector(
		( state: State ): PhantomMarker[] => {
			const markers: PhantomMarker[] = [];
			for ( const phantom of state.phantoms ) {
				const peer = state.peers[ phantom.peerKey ];
				if ( ! peer ) {
					continue;
				}
				markers.push( {
					...phantom,
					name: peer.identity.name,
					color: peer.color,
					receivedAt: peer.receivedAt,
					intervalMs: peer.beacon.intervalMs,
				} );
			}
			return markers.length ? markers : EMPTY_PHANTOMS;
		},
		( state: State ) => [ state.phantoms, state.peers ]
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
