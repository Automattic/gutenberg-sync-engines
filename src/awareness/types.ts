/**
 * High-latency ("slow") awareness: the shared types.
 *
 * The realtime awareness the framework ships describes a peer's caret as a
 * Yjs relative position and refreshes it every 100 ms. That only works when
 * every editor holds the same CRDT document and updates arrive within a
 * second or so. This module describes presence at a coarser grain that
 * survives long gaps between updates (5 s, 15 s, more) and does not assume
 * the local document has caught up with the peer's:
 *
 * - A peer's activity is summarized per INTERVAL, not sampled at an instant:
 *   which block they are in now, and which blocks they touched since their
 *   previous beacon.
 * - Blocks are named by durable identity (`metadata.syncId`, which the
 *   intent-log and de-rtc engines stamp on every block; the editor clientId,
 *   which the yjs-server engine shares through the Y.Doc), plus enough
 *   descriptive context (block type, position, neighbors, an excerpt) to
 *   say something useful about a block the local editor has NOT received
 *   yet.
 *
 * Everything here is plain JSON so it can ride any channel: the sync
 * transport's awareness envelope, WordPress Heartbeat, or something else.
 */

/**
 * A reference to a block, robust to the block being absent locally.
 */
export interface BlockRef {
	/** Durable identity (`metadata.syncId`) when the block carries one. */
	syncId?: string;
	/** The editor clientId as seen by the sender. */
	clientId: string;
	/** Block type name, e.g. `core/paragraph`. */
	name: string;
	/** Index path from the root of the post content at publish time. */
	path: number[];
	/** Identity (syncId, else clientId) of the previous sibling, if any. */
	after?: string | null;
	/** Identity (syncId, else clientId) of the parent block, if any. */
	parent?: string | null;
	/** A short plain-text excerpt of the block's content. */
	excerpt?: string;
}

export type EditKind = 'edit' | 'insert' | 'remove';

/**
 * How long a block stays in the sender's trail after the last interaction
 * with it (selection entering or leaving it, or an edit inside it).
 */
export const TRAIL_WINDOW_MS = 30_000;

/**
 * Trail entries at least this old render at half strength; younger ones
 * at full strength. Entries past TRAIL_WINDOW_MS are not sent at all.
 */
export const TRAIL_HALF_MS = 15_000;

/** Upper bound on trail length, to bound the beacon's size. */
export const TRAIL_MAX_ENTRIES = 20;

/**
 * One block in the sender's recent trail.
 */
export interface TrailEntry {
	ref: BlockRef;
	/**
	 * Milliseconds between the last interaction with the block and the
	 * beacon being built, on the sender's clock. Receivers apply this as
	 * is, so clock skew between machines never matters.
	 */
	ageMs: number;
}

/**
 * One block the sender touched since the previous beacon.
 */
export interface ActivityEdit {
	ref: BlockRef;
	kind: EditKind;
	/** How many store changes were attributed to this block in the window. */
	count: number;
}

/**
 * The beacon a peer publishes once per interval.
 */
export interface ActivityBeacon {
	v: 2;
	/** Sender-incremented sequence number. */
	seq: number;
	/** Sender clock (ms since epoch) at publish time. Informational only. */
	at: number;
	/** The sender's publish cadence, so receivers can judge staleness. */
	intervalMs: number;
	/** The block containing the sender's selection at publish time. */
	focus: BlockRef | null;
	/**
	 * Every block the sender interacted with in the last TRAIL_WINDOW_MS,
	 * most recent first. The focused block is first with age 0.
	 */
	recent: TrailEntry[];
	/** Blocks touched since the previous beacon. */
	edits: ActivityEdit[];
}

/**
 * Who a beacon came from. Under the sync channel this comes from the
 * framework's `collaboratorInfo`; under the Heartbeat channel the server
 * stamps it from the authenticated user.
 */
export interface PeerIdentity {
	/** WordPress user id, or null for anonymous sessions. */
	userId: number | null;
	name: string;
	avatarUrl?: string;
}

/**
 * A peer as held by the store: identity, the latest beacon, and when the
 * local clock received it (staleness is judged on the receiver's clock so
 * skew between machines never matters).
 */
export interface PeerActivity {
	/** Stable key for the peer's session (the transport client id). */
	key: string;
	identity: PeerIdentity;
	color: string;
	beacon: ActivityBeacon;
	receivedAt: number;
}

/**
 * Whether a peer's last beacon is recent enough to keep showing. There is
 * no in-between state on purpose: what a stripe looks like is decided by
 * the sender's trail ages when a beacon arrives, never by the receiver's
 * clock ticking.
 */
export type PeerStatus = 'active' | 'expired';

/**
 * How a peer's block reference lines up with the local document.
 */
export type BlockRefResolution =
	| { kind: 'local'; clientId: string }
	| {
			kind: 'phantom';
			/** Local block to anchor the placeholder to, if any. */
			anchorClientId: string | null;
			/** Where the missing block sits relative to the anchor. */
			placement: 'after' | 'inside' | 'start';
	  };

/**
 * Settings the server hands the client.
 */
export interface SlowAwarenessSettings {
	/** 0 disables the mode (the framework's realtime awareness runs). */
	intervalMs: number;
	channel: 'sync' | 'heartbeat';
}
