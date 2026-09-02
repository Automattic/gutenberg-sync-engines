/**
 * Internal dependencies
 */
import { changedBlockIndexes, parseCanonicalBlocks } from './doc-bridge';
import type { DeRtcUndoFeed, DeRtcUndoFeedRow } from './revert-undo';

/*
 * Per-edit authorship: "Edits retain authorship and it's possible to
 * hover over a user's avatar and highlight the changes they applied."
 *
 * This is the DATA surface: every accepted canonical row carries its
 * author (user id + client id, server-stamped), and diffing each row
 * against its base yields which top-level blocks that edit touched —
 * so the last author of every block is derivable client-side with no
 * extra wire cost. The hover-highlight overlay itself is future
 * editor UX; range-grain attribution (which characters) needs
 * the descriptor lane.
 *
 * Honest bounds: attribution is block-grain and positional. A
 * structural change (block count differs from the base) resets the map
 * to unknown — a lying attribution is worse than none.
 */

/** The last-known author of one top-level block. */
export interface DeRtcBlockAuthorship {
	author: number;
	authorClientId: number;
	version: string;
}

export interface DeRtcAuthorshipTracker {
	/**
	 * The last author of each top-level block, by current index. `null`
	 * entries are unknown (untouched since genesis, or reset by a
	 * structural change).
	 */
	getBlockAuthorship: () => Array< DeRtcBlockAuthorship | null >;
}

/**
 * Creates the per-entity authorship tracker over the canonical row feed.
 *
 * @param feed The session's canonical-row feed.
 * @return The tracker.
 */
export function createDeRtcAuthorship(
	feed: DeRtcUndoFeed
): DeRtcAuthorshipTracker {
	const versionContents = new Map< string, string >();
	let authorship: Array< DeRtcBlockAuthorship | null > = [];

	feed.subscribe( ( row: DeRtcUndoFeedRow ) => {
		versionContents.set( row.version, row.content );
		while ( versionContents.size > 60 ) {
			const oldest = versionContents.keys().next().value;
			if ( undefined === oldest ) {
				break;
			}
			versionContents.delete( oldest );
		}

		const blocks = parseCanonicalBlocks( row.content );
		if ( authorship.length !== blocks.length ) {
			// Structural change (or first sight): re-anchor to unknown
			// rather than attribute positionally across a shift.
			authorship = blocks.map( () => null );
		}

		const baseContent =
			null !== row.baseVersion
				? versionContents.get( row.baseVersion )
				: undefined;
		if (
			undefined === baseContent ||
			undefined === row.author ||
			undefined === row.authorClientId
		) {
			return; // Unattributable row (genesis, aged-out base).
		}
		const changed = changedBlockIndexes(
			parseCanonicalBlocks( baseContent ),
			blocks
		);
		if ( null === changed ) {
			return; // Structural row: the reset above already applied.
		}
		for ( const index of changed ) {
			authorship[ index ] = {
				author: row.author,
				authorClientId: row.authorClientId,
				version: row.version,
			};
		}
	} );

	return {
		getBlockAuthorship: () => authorship.slice(),
	};
}
