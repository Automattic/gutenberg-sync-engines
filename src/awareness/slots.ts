/**
 * Bar segments: how a block's peers share its left bar.
 *
 * The bar is split vertically, one segment per peer, in the order the
 * peers arrived on the block (first on top). At most MAX_SLOTS peers get a
 * segment; the rest appear only in the hover list. A peer whose entry is
 * leaving keeps its slot with a zero-height segment while its exit
 * animates, so nothing below it jumps until the prune.
 *
 * The CSS side draws the bar as one vertical gradient with three movable
 * boundaries between four color slots; boundaries and colors are
 * registered custom properties so a change animates.
 */

/**
 * Internal dependencies
 */
import type { BlockPresence } from './store';

export const MAX_SLOTS = 4;

export interface BarLayout {
	/** Entries drawn on the bar, top to bottom (leaving ones included). */
	slots: BlockPresence[];
	/** Entries beyond the slot limit, hover-list only. */
	extras: BlockPresence[];
	/** The three boundaries between the four slots, as percentages 0-100. */
	boundaries: [ number, number, number ];
	/** The four slot colors, `rgba(...)` with the entry's strength as alpha. */
	colors: [ string, string, string, string ];
}

/**
 * A hex color with an alpha channel.
 *
 * @param hex   `#rrggbb`.
 * @param alpha 0-1.
 * @return An `rgba(...)` string (the input when it is not a hex color).
 */
export function withAlpha( hex: string, alpha: number ): string {
	const match = /^#([0-9a-f]{6})$/i.exec( hex );
	if ( ! match ) {
		return hex;
	}
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( start ) =>
		parseInt( hex.slice( start, start + 2 ), 16 )
	);
	return `rgba(${ r }, ${ g }, ${ b }, ${ alpha })`;
}

/**
 * Lays out a block's bar from its presence entries (already in arrival
 * order, as the store keeps them).
 *
 * @param entries Presence entries for the block.
 * @return The layout.
 */
export function layoutBar( entries: BlockPresence[] ): BarLayout {
	const slots = entries.slice( 0, MAX_SLOTS );
	const extras = entries.slice( MAX_SLOTS );
	const visible = slots.filter( ( entry ) => ! entry.leaving ).length;
	const share = visible ? 100 / visible : 0;

	const boundaries: [ number, number, number ] = [ 100, 100, 100 ];
	let cumulative = 0;
	for ( let index = 0; index < 3; index += 1 ) {
		const entry = slots[ index ];
		if ( entry && ! entry.leaving ) {
			cumulative += share;
		}
		boundaries[ index ] = entry ? round( cumulative ) : 100;
	}
	// Past the last occupied slot every boundary sits at the bottom, so
	// the empty slots have no height.
	for ( let index = slots.length; index < 3; index += 1 ) {
		boundaries[ index ] = 100;
	}

	const colors = [ 0, 1, 2, 3 ].map( ( index ) => {
		const entry = slots[ index ];
		if ( ! entry ) {
			return 'transparent';
		}
		// A leaving entry keeps its last visible strength while its
		// segment shrinks away.
		const alpha = entry.leaving ? entry.lastOpacity ?? 0.5 : entry.opacity;
		return withAlpha( entry.color, alpha );
	} ) as [ string, string, string, string ];

	return { slots, extras, boundaries, colors };
}

function round( value: number ): number {
	return Math.round( value * 100 ) / 100;
}
