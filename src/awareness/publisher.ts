/**
 * The activity publisher: watches the local editor and emits one beacon per
 * interval describing where the user has been.
 *
 * Each beacon carries a TRAIL: every block the user interacted with in the
 * last 30 seconds, with the age of that last interaction on the sender's
 * clock. An interaction is the selection entering a block, leaving it, or
 * an edit inside it, so a block the user sat in for a while counts from the
 * moment they left. The focused block is always first with age 0. Beside
 * the trail, `edits` summarizes what changed since the previous beacon so
 * receivers can say "typing", "added", or "removed".
 *
 * What counts as "the user's own edit" is heuristic here. The block-editor
 * store does not label a change as local or remote, so this publisher
 * attributes a change to the local user when the editor reports typing or
 * when the user interacted (moved the selection) within the last couple of
 * seconds. Every engine already knows precisely which changes are local
 * (intent-log's capture bridge, yjs-server's local-origin transactions,
 * de-rtc's proposals); a production version should source edits from the
 * engine and keep only the beacon shape from here.
 */

/**
 * Internal dependencies
 */
import { makeBlockRef } from './block-refs';
import type { BlockTreeReader } from './block-refs';
import { TRAIL_MAX_ENTRIES, TRAIL_WINDOW_MS } from './types';
import type {
	ActivityBeacon,
	ActivityEdit,
	BlockRef,
	EditKind,
	TrailEntry,
} from './types';

export interface PublisherOptions {
	reader: BlockTreeReader;
	/** Subscribes to block-editor store changes; returns an unsubscribe. */
	subscribe: ( listener: () => void ) => () => void;
	intervalMs: number;
	onBeacon: ( beacon: ActivityBeacon ) => void;
	now?: () => number;
	/** How long after a selection change edits still count as local. */
	localWindowMs?: number;
	/**
	 * `timer` publishes on its own interval; `manual` leaves publishing to
	 * the caller's `flush()` (the Heartbeat channel flushes on each send).
	 */
	schedule?: 'timer' | 'manual';
}

export interface ActivityPublisher {
	start: () => void;
	stop: () => void;
	/** Publishes a beacon now and starts a fresh edit window. */
	flush: () => ActivityBeacon;
	/** The edits accumulated so far in the current window. */
	getPendingEdits: () => ActivityEdit[];
}

const DEFAULT_LOCAL_WINDOW_MS = 2000;

/**
 * Creates a publisher. Call `start()` to begin; the first beacon goes out
 * immediately (a join announcement) and then once per interval.
 *
 * @param options Publisher options.
 * @return The publisher.
 */
export function createActivityPublisher(
	options: PublisherOptions
): ActivityPublisher {
	const {
		reader,
		subscribe,
		intervalMs,
		onBeacon,
		now = () => Date.now(),
		localWindowMs = DEFAULT_LOCAL_WINDOW_MS,
		schedule = 'timer',
	} = options;

	let seq = 0;
	let started = false;
	let timer: ReturnType< typeof setInterval > | null = null;
	let unsubscribe: ( () => void ) | null = null;

	// The block the selection is in, and the block object last seen for it.
	let trackedId: string | null = null;
	let trackedBlock: unknown = null;
	let lastLocalInteractionAt = Number.NEGATIVE_INFINITY;

	// Membership of the tree, for insert/remove detection.
	let lastIds: string[] = [];
	let knownIds = new Set< string >();

	// The trail: last interaction time per block, on this clock.
	const lastInteractionAt = new Map< string, number >();
	// Edits in the current window, keyed by clientId.
	const edits = new Map< string, ActivityEdit >();
	// References remembered for blocks that may vanish before the flush.
	const refCache = new Map< string, BlockRef >();

	function isLocalActivity(): boolean {
		return (
			reader.isTyping() || now() - lastLocalInteractionAt <= localWindowMs
		);
	}

	function remember( clientId: string ): BlockRef | null {
		const ref = makeBlockRef( reader, clientId );
		if ( ref ) {
			refCache.set( clientId, ref );
		}
		return ref;
	}

	function touch( clientId: string ): void {
		lastInteractionAt.set( clientId, now() );
	}

	function record( kind: EditKind, clientId: string ): void {
		const existing = edits.get( clientId );
		const ref =
			'remove' === kind
				? refCache.get( clientId ) ?? existing?.ref ?? null
				: remember( clientId );
		if ( ! ref ) {
			return;
		}
		if ( 'remove' === kind ) {
			// A removed block leaves the trail; it has nothing to stripe.
			lastInteractionAt.delete( clientId );
		} else {
			touch( clientId );
		}
		if ( existing ) {
			existing.count += 1;
			// A block inserted then edited in one window stays an insert; a
			// block that vanished is a removal whatever came before.
			if ( 'remove' === kind ) {
				existing.kind = 'remove';
			}
			existing.ref = ref;
			return;
		}
		edits.set( clientId, { ref, kind, count: 1 } );
	}

	function selectedClientId(): string | null {
		return (
			reader.getSelectedBlockClientId() ??
			reader.getSelectionStart().clientId ??
			null
		);
	}

	function onStoreChange(): void {
		const selected = selectedClientId();

		if ( selected !== trackedId ) {
			// Leaving a block is its last interaction; entering one starts it.
			if ( trackedId && lastInteractionAt.has( trackedId ) ) {
				touch( trackedId );
			}
			trackedId = selected;
			trackedBlock = selected ? reader.getBlock( selected ) : null;
			lastLocalInteractionAt = now();
			if ( selected ) {
				remember( selected );
				touch( selected );
			}
		} else if ( selected ) {
			const block = reader.getBlock( selected );
			if ( block !== trackedBlock ) {
				trackedBlock = block;
				if ( isLocalActivity() ) {
					record( 'edit', selected );
				}
			}
		}

		const ids = reader.getClientIdsWithDescendants();
		if ( ids !== lastIds ) {
			const next = new Set( ids );
			const local = isLocalActivity();
			for ( const id of ids ) {
				if ( ! knownIds.has( id ) && local ) {
					record( 'insert', id );
				}
			}
			for ( const id of knownIds ) {
				if ( ! next.has( id ) ) {
					if ( local ) {
						record( 'remove', id );
					}
					lastInteractionAt.delete( id );
				}
			}
			lastIds = ids;
			knownIds = next;
		}
	}

	/**
	 * The trail as of now: prunes entries past the window, refreshes each
	 * remaining block's reference, and orders most recent first with the
	 * focused block (age 0) leading.
	 *
	 * @param selected The focused block.
	 * @param at       Now.
	 * @return Trail entries.
	 */
	function buildTrail( selected: string | null, at: number ): TrailEntry[] {
		if ( selected ) {
			lastInteractionAt.set( selected, at );
		}
		const entries: TrailEntry[] = [];
		for ( const [ clientId, time ] of lastInteractionAt ) {
			const ageMs = Math.max( 0, at - time );
			if ( ageMs >= TRAIL_WINDOW_MS ) {
				lastInteractionAt.delete( clientId );
				continue;
			}
			const ref = makeBlockRef( reader, clientId );
			if ( ! ref ) {
				lastInteractionAt.delete( clientId );
				continue;
			}
			entries.push( { ref, ageMs } );
		}
		entries.sort( ( a, b ) => {
			if ( selected && a.ref.clientId === selected ) {
				return -1;
			}
			if ( selected && b.ref.clientId === selected ) {
				return 1;
			}
			return a.ageMs - b.ageMs;
		} );
		return entries.slice( 0, TRAIL_MAX_ENTRIES );
	}

	function flush(): ActivityBeacon {
		const at = now();
		const selected = selectedClientId();
		const focus = selected ? makeBlockRef( reader, selected ) : null;
		const recent = buildTrail( focus ? selected : null, at );
		const windowEdits: ActivityEdit[] = [];
		for ( const edit of edits.values() ) {
			// Refresh the reference for blocks that still exist so the
			// excerpt and position reflect the end of the window.
			const fresh =
				'remove' === edit.kind
					? null
					: makeBlockRef( reader, edit.ref.clientId );
			windowEdits.push( fresh ? { ...edit, ref: fresh } : edit );
		}
		edits.clear();
		seq += 1;
		const beacon: ActivityBeacon = {
			v: 2,
			seq,
			at,
			intervalMs,
			focus,
			recent,
			edits: windowEdits,
		};
		onBeacon( beacon );
		return beacon;
	}

	return {
		start() {
			if ( started ) {
				return;
			}
			started = true;
			lastIds = reader.getClientIdsWithDescendants();
			knownIds = new Set( lastIds );
			trackedId = selectedClientId();
			trackedBlock = trackedId ? reader.getBlock( trackedId ) : null;
			if ( trackedId ) {
				touch( trackedId );
			}
			unsubscribe = subscribe( onStoreChange );
			if ( 'timer' === schedule ) {
				flush();
				timer = setInterval( flush, intervalMs );
			}
		},
		stop() {
			started = false;
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
			if ( unsubscribe ) {
				unsubscribe();
				unsubscribe = null;
			}
			edits.clear();
			lastInteractionAt.clear();
		},
		flush,
		getPendingEdits: () => Array.from( edits.values() ),
	};
}
