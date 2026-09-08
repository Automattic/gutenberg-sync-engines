/**
 * Internal dependencies
 */
import type {
	AwarenessState,
	EngineDisposition,
	EngineUpdate,
	LocalAwarenessState,
} from '@wordpress/sync';

export type { AwarenessState, LocalAwarenessState };

/**
 * Well-known update types on the wire (originally defined by the retired
 * yjs-relay engine; yjs-server still speaks `update`):
 * - sync_step1: State vector announcement
 * - sync_step2: Acknowledgment, missing updates response
 * - update: Regular document update (persisted until save)
 * - compaction: Merged updates using Y.mergeUpdates replacing all prior updates
 *
 * The transport does not interpret these; it moves typed updates opaquely.
 * `compaction` still matters to the queue: restore() drops compaction rows
 * rather than re-queueing them.
 */
export enum SyncUpdateType {
	COMPACTION = 'compaction',
	UPDATE = 'update',
}

/**
 * A typed update on the wire. The engine-defined `type` and base64 `data`
 * are opaque to the transport.
 */
export type SyncUpdate = EngineUpdate;

interface SyncEnvelopeFromClient {
	after: number;
	awareness: LocalAwarenessState;
	client_id: number;
	/**
	 * Engine identity stamp (see EngineSessionCodec.engineSlug): lets the
	 * server fence a stale tab speaking the wrong engine with a 409 before
	 * storing any of its updates.
	 */
	engine?: string;
	engine_protocol?: number;
	/** Sync-inspector opt-in: ask the engine for a `_debug` envelope. */
	debug?: boolean;
	room: string;
	updates: SyncUpdate[];
}

interface SyncEnvelopeFromServer {
	/** Engine diagnostics, present only when the request opted in. */
	_debug?: Record< string, unknown >;
	awareness: AwarenessState;
	dispositions?: EngineDisposition[];
	end_cursor: number; // use as `after` in next request
	should_compact?: boolean;
	room: string;
	updates: SyncUpdate[];
}

export interface SyncPayload {
	rooms: SyncEnvelopeFromClient[];
	/**
	 * The advisory channel's signaling probe (per-tab token and queued
	 * handshake messages; see providers/advisory/signaling.ts). Answered
	 * alongside the rooms: an active poll loop is a faster carrier than
	 * the heartbeat.
	 */
	advisory?: Record< string, unknown > & { seq?: number };
}

export interface SyncResponse {
	rooms: SyncEnvelopeFromServer[];
	/** The server's answer to the request's advisory probe, when sent. */
	advisory?: unknown;
}

export interface UpdateQueue {
	add: ( update: SyncUpdate ) => void;
	addBulk: ( updates: SyncUpdate[] ) => void;
	clear: () => void;
	/** Returns AND removes all queued updates, paused or not (teardown). */
	drain: () => SyncUpdate[];
	get: () => SyncUpdate[];
	pause: () => void;
	peek: () => SyncUpdate[];
	restore: ( updates: SyncUpdate[] ) => void;
	restoreExact: ( updates: SyncUpdate[] ) => void;
	resume: () => void;
	size: () => number;
	take: ( count: number ) => SyncUpdate[];
}
