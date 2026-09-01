/**
 * External dependencies
 */
import type * as Y from 'yjs';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- The exact serializer the doc bridge and core-data use; sharing it keeps revert content byte-consistent.
import { __unstableSerializeAndClean } from '@wordpress/blocks';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncUndoManager } from '@wordpress/sync';

/**
 * Internal dependencies
 */
import {
	changedBlockIndexes,
	parseCanonicalBlocks,
	serializeBlock,
	type DeRtcDocBridge,
} from './doc-bridge';

/*
 * DE-RTC's revert-edit undo.
 *
 * The vision: "Undo and Redo never undo, but rather apply revert edits
 * that return the document to an earlier state by means of adding a new
 * change." This manager implements exactly that, replacing the local
 * Yjs snapshot undo the port had borrowed:
 *
 * - The undo stack is the client's OWN accepted canonical rows (the
 *   collaborative history), not local document snapshots.
 * - undo() derives a REVERT: for each top-level block the popped row
 *   changed against its base, the block reverts to its base form iff
 *   the current document still holds the row's form (untouched since —
 *   clients never merge, they select). The reverted document applies as
 *   an ordinary dirty edit and travels as an ordinary PROPOSAL: the
 *   revert is a new change in the shared history, visible to and
 *   mergeable with everyone, exactly like upstream prescribes.
 * - redo() re-applies the reverted row's base→row delta the same way.
 * - Blocks peers have since edited are left alone (their work is never
 *   collateral), structural divergence makes a row underivable (it is
 *   dropped and the next older row is tried — the intent-log undo's
 *   walk-back rule), and a revert that the server merges further simply
 *   becomes a new own row like any other edit.
 *
 * The history-slider UI the vision sketches would read the same
 * version-content record this manager keeps; it remains future
 * editor UX work.
 */

/** A canonical row as the session feed reports it. */
export interface DeRtcUndoFeedRow {
	version: string;
	baseVersion: string | null;
	content: string;
	/** Whether this row is the local client's own accepted proposal. */
	own: boolean;
	/** Server-stamped author user id (stamped on content rows). */
	author?: number;
	/** The authoring transport client id. */
	authorClientId?: number;
}

/** The session→undo feed: sessions publish every canonical row. */
export interface DeRtcUndoFeed {
	noteRow: ( row: DeRtcUndoFeedRow ) => void;
	subscribe: ( listener: ( row: DeRtcUndoFeedRow ) => void ) => () => void;
}

/**
 * Creates the per-entity session→undo row feed.
 *
 * @return The feed.
 */
export function createDeRtcUndoFeed(): DeRtcUndoFeed {
	const listeners = new Set< ( row: DeRtcUndoFeedRow ) => void >();
	return {
		noteRow: ( row ) =>
			listeners.forEach( ( listener ) => listener( row ) ),
		subscribe: ( listener ) => {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
	};
}

/** Retained version contents per entity (the derivation window). */
const VERSION_WINDOW = 60;

interface EntityUndoState {
	bridge: DeRtcDocBridge;
	applyRevert: ( blocks: unknown[] ) => void;
	handlers: {
		onUndoStackChange?: ( state: {
			hasUndo: boolean;
			hasRedo: boolean;
		} ) => void;
	};
	versionContents: Map< string, string >;
	undoStack: Array< { version: string; baseVersion: string } >;
	redoStack: Array< { version: string; baseVersion: string } >;
	/** Serialized contents this manager's own reverts predicted. */
	predictedReverts: Set< string >;
	/** Monotonic recency for cross-entity undo ordering. */
	lastOwnRowAt: number;
}

export type DeRtcRevertUndoManager = SyncUndoManager & {
	attachEntity: ( context: {
		key: Y.Map< unknown >;
		bridge: DeRtcDocBridge;
		feed: DeRtcUndoFeed;
		applyRevert: ( blocks: unknown[] ) => void;
	} ) => void;
};

/**
 * Creates the engine-level revert-edit undo manager.
 *
 * @return The manager.
 */
export function createDeRtcRevertUndoManager(): DeRtcRevertUndoManager {
	const entities = new Map< Y.Map< unknown >, EntityUndoState >();
	let recency = 0;

	const notify = ( state: EntityUndoState ) => {
		state.handlers.onUndoStackChange?.( {
			hasUndo: 0 < state.undoStack.length,
			hasRedo: 0 < state.redoStack.length,
		} );
	};

	const onRow = ( state: EntityUndoState, row: DeRtcUndoFeedRow ) => {
		state.versionContents.set( row.version, row.content );
		while ( state.versionContents.size > VERSION_WINDOW ) {
			const oldest = state.versionContents.keys().next().value;
			if ( undefined === oldest ) {
				break;
			}
			state.versionContents.delete( oldest );
		}
		if ( ! row.own || null === row.baseVersion ) {
			return;
		}
		if ( state.predictedReverts.has( row.content ) ) {
			// The accepted form of a revert this manager authored: part of
			// the undo/redo choreography, not a new undoable edit.
			state.predictedReverts.delete( row.content );
			notify( state );
			return;
		}
		state.undoStack.push( {
			version: row.version,
			baseVersion: row.baseVersion,
		} );
		state.lastOwnRowAt = ++recency;
		// A new real edit forks history: redo becomes unreachable.
		state.redoStack.length = 0;
		notify( state );
	};

	/**
	 * The revert (or redo) document for a row, or null when underivable.
	 * With `forward` false the revert derives (row → base); true
	 * re-applies the row's delta (base → row).
	 *
	 * @param state           Entity state.
	 * @param row             The own row to revert or re-apply.
	 * @param row.version     The row's version label.
	 * @param row.baseVersion The row's base version label.
	 * @param forward         Direction (see above).
	 * @return The next block list, or null.
	 */
	const derive = (
		state: EntityUndoState,
		row: { version: string; baseVersion: string },
		forward: boolean
	): unknown[] | null => {
		const baseContent = state.versionContents.get( row.baseVersion );
		const rowContent = state.versionContents.get( row.version );
		if ( undefined === baseContent || undefined === rowContent ) {
			return null; // Aged out of the derivation window.
		}
		const base = parseCanonicalBlocks( baseContent );
		const mine = parseCanonicalBlocks( rowContent );
		const current = parseCanonicalBlocks( state.bridge.buildContent() );
		const changed = changedBlockIndexes( base, mine );
		if ( null === changed || current.length !== mine.length ) {
			return null; // Structural divergence: positional selection lies.
		}

		const from = forward ? base : mine;
		const to = forward ? mine : base;
		const next = current.slice();
		let reverted = false;
		for ( const index of changed ) {
			if (
				serializeBlock( current[ index ] ) !==
				serializeBlock( from[ index ] )
			) {
				continue; // Touched since: a peer's work is never collateral.
			}
			next[ index ] = to[ index ];
			reverted = true;
		}

		return reverted ? next : null;
	};

	const step = ( direction: 'undo' | 'redo' ) => {
		// Cross-entity ordering: act on the entity with the most recent
		// own activity that has something to do.
		const candidates = Array.from( entities.values() )
			.filter( ( state ) =>
				'undo' === direction
					? 0 < state.undoStack.length
					: 0 < state.redoStack.length
			)
			.sort( ( a, b ) => b.lastOwnRowAt - a.lastOwnRowAt );
		for ( const state of candidates ) {
			const stack =
				'undo' === direction ? state.undoStack : state.redoStack;
			while ( 0 < stack.length ) {
				const row = stack.pop()!;
				const next = derive( state, row, 'redo' === direction );
				if ( null === next ) {
					continue; // Underivable: walk further back.
				}
				const serialized = __unstableSerializeAndClean(
					next as any[]
				).trim();
				state.predictedReverts.add( serialized );
				state.applyRevert( next );
				if ( 'undo' === direction ) {
					state.redoStack.push( row );
				} else {
					state.undoStack.push( row );
				}
				notify( state );
				return;
			}
			notify( state );
		}
	};

	return {
		attachEntity( { key, bridge, feed, applyRevert } ) {
			const state: EntityUndoState = {
				bridge,
				applyRevert,
				handlers: {},
				versionContents: new Map(),
				undoStack: [],
				redoStack: [],
				predictedReverts: new Set(),
				lastOwnRowAt: 0,
			};
			entities.set( key, state );
			feed.subscribe( ( row ) => onRow( state, row ) );
		},

		addToScope( ymap, handlers ) {
			const state = entities.get( ymap as Y.Map< unknown > );
			if ( state ) {
				state.handlers = handlers;
				notify( state );
			}
		},

		addRecord() {
			// Revert edits derive from accepted canonical rows, not from
			// editor history records.
		},

		stopCapturing() {
			// Units are accepted rows; there is no capture chain to split.
		},

		undo() {
			step( 'undo' );
			return [];
		},

		redo() {
			step( 'redo' );
			return [];
		},

		hasUndo: () =>
			Array.from( entities.values() ).some(
				( state ) => 0 < state.undoStack.length
			),

		hasRedo: () =>
			Array.from( entities.values() ).some(
				( state ) => 0 < state.redoStack.length
			),
	} as DeRtcRevertUndoManager;
}
