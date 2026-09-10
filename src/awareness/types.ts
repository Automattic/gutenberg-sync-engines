/**
 * Slow awareness: which block each editor is in, exchanged on a slow
 * cadence (seconds, not milliseconds) for connections that cannot carry
 * live cursors. The whole wire contract is one value per peer: the
 * identity of the block their selection is in, or null.
 *
 * Blocks are named by durable identity when they have one
 * (`metadata.syncId`, which the intent-log and de-rtc engines stamp on
 * every block) and by the editor clientId otherwise (the yjs-server engine
 * shares clientIds through the Y.Doc, so they are cross-peer there). A
 * receiver that does not hold the named block shows nothing until it does;
 * awareness and content may travel on different channels, so this is
 * expected, not an error.
 */

/**
 * Who a peer is, for the avatar and its color.
 */
export interface PeerIdentity {
	/** WordPress user id, or null for an anonymous session. */
	userId: number | null;
	name: string;
	avatarUrl?: string;
}

/**
 * One peer as the store holds it.
 */
export interface Peer {
	/** The transport client id, as a string. */
	key: string;
	identity: PeerIdentity;
	/** A hex color, stable per user. */
	color: string;
	/** The block the peer is in (syncId or clientId), or null. */
	block: string | null;
}

/**
 * The site settings for the mode, read from the server's inline script.
 */
export interface SlowAwarenessSettings {
	/** Publish cadence in milliseconds; 0 means the mode is off. */
	intervalMs: number;
	channel: 'sync' | 'heartbeat';
}

/**
 * Called with a peer's latest block. Idempotent: the same block again is
 * a no-op downstream.
 */
export type PeerListener = (
	key: string,
	identity: PeerIdentity,
	block: string | null
) => void;

/**
 * A way for the local block to reach peers and theirs to reach us.
 */
export interface Channel {
	start: () => void;
	stop: () => void;
	publish: ( block: string | null ) => void;
}
