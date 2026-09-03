/**
 * Human-readable descriptions of peers' activity.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { getBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import type { BlockPresence, PhantomMarker } from '../store';
import type { BlockRef } from '../types';

/**
 * The block type's title, falling back to its name.
 *
 * @param name Block name.
 * @return A title such as "Paragraph".
 */
export function blockTitle( name: string ): string {
	return getBlockType( name )?.title ?? name;
}

/**
 * "12 seconds ago" style text for a number of milliseconds.
 *
 * @param ms Milliseconds.
 * @return The text.
 */
export function agoText( ms: number ): string {
	const seconds = Math.max( 0, Math.floor( ms / 1000 ) );
	if ( seconds < 2 ) {
		return __( 'just now' );
	}
	return sprintf(
		/* translators: %d: number of seconds */
		_n( '%d second ago', '%d seconds ago', seconds ),
		seconds
	);
}

/**
 * "12 seconds ago" for something that happened at a receiver-clock time.
 *
 * @param at  When it happened.
 * @param now Now.
 * @return The text.
 */
export function ageText( at: number, now: number ): string {
	return agoText( now - at );
}

/**
 * How long ago the peer last interacted with a block: the age the sender
 * reported plus the time since that beacon arrived.
 *
 * @param entry A presence entry.
 * @param now   Now.
 * @return Milliseconds.
 */
export function interactionAge(
	entry: Pick< BlockPresence, 'ageMs' | 'receivedAt' >,
	now: number
): number {
	return entry.ageMs + Math.max( 0, now - entry.receivedAt );
}

/**
 * Describes one peer's relationship to the block the reader is looking at.
 *
 * @param entry A presence entry.
 * @param now   Now.
 * @return One sentence.
 */
export function describePresence( entry: BlockPresence, now: number ): string {
	const { name, role, typing } = entry;
	const ago = () => agoText( interactionAge( entry, now ) );
	switch ( role ) {
		case 'focus':
			return typing
				? sprintf(
						/* translators: %s: collaborator name */
						__( '%s is typing in this block' ),
						name
				  )
				: sprintf(
						/* translators: %s: collaborator name */
						__( '%s is in this block' ),
						name
				  );
		case 'insert':
			return sprintf(
				/* translators: 1: collaborator name, 2: age */
				__( '%1$s added this block %2$s' ),
				name,
				ago()
			);
		case 'remove':
			return sprintf(
				/* translators: %s: collaborator name */
				__(
					'%s removed this block. That change has not reached you yet.'
				),
				name
			);
		case 'edit':
			return sprintf(
				/* translators: 1: collaborator name, 2: age */
				__( '%1$s edited this block %2$s' ),
				name,
				ago()
			);
		default:
			return sprintf(
				/* translators: 1: collaborator name, 2: age */
				__( '%1$s was in this block %2$s' ),
				name,
				ago()
			);
	}
}

/**
 * A short status for the hover list, where the name is shown separately.
 *
 * @param entry A presence entry.
 * @param now   Now.
 * @return A few words.
 */
export function describePresenceShort(
	entry: BlockPresence,
	now: number
): string {
	const ago = () => agoText( interactionAge( entry, now ) );
	switch ( entry.role ) {
		case 'focus':
			return entry.typing ? __( 'typing here' ) : __( 'in this block' );
		case 'insert':
			return sprintf(
				/* translators: %s: age */
				__( 'added this block %s' ),
				ago()
			);
		case 'remove':
			return __( 'removed this block (not synced to you yet)' );
		case 'edit':
			return sprintf(
				/* translators: %s: age */
				__( 'edited %s' ),
				ago()
			);
		default:
			return sprintf(
				/* translators: %s: age */
				__( 'was here %s' ),
				ago()
			);
	}
}

/**
 * Describes every peer on a block, one sentence each.
 *
 * @param entries Presence entries.
 * @param now     Now.
 * @return The sentences joined.
 */
export function describeBlock( entries: BlockPresence[], now: number ): string {
	return entries
		.filter( ( entry ) => ! entry.leaving )
		.map( ( entry ) => describePresence( entry, now ) )
		.join( ' ' );
}

/**
 * A short description of a block reference for a block we do not have.
 *
 * @param ref The reference.
 * @return e.g. `Paragraph “Hello…”`.
 */
export function describeRef( ref: BlockRef ): string {
	const title = blockTitle( ref.name );
	if ( ! ref.excerpt ) {
		return title;
	}
	return sprintf(
		/* translators: 1: block type title, 2: excerpt */
		__( '%1$s “%2$s”' ),
		title,
		ref.excerpt
	);
}

/**
 * Describes a phantom: activity in a block this editor has not received.
 *
 * @param marker The phantom marker.
 * @param now    Now.
 * @return One sentence.
 */
export function describePhantom( marker: PhantomMarker, now: number ): string {
	const what = describeRef( marker.ref );
	if ( 'focus' !== marker.role && 'insert' !== marker.role ) {
		return sprintf(
			/* translators: 1: collaborator name, 2: block description, 3: age */
			__( '%1$s was in a block you have not received yet (%2$s), %3$s.' ),
			marker.name,
			what,
			agoText( interactionAge( marker, now ) )
		);
	}
	if ( 'insert' === marker.role ) {
		return sprintf(
			/* translators: 1: collaborator name, 2: block description */
			__(
				'%1$s is adding a block here that has not reached you yet: %2$s'
			),
			marker.name,
			what
		);
	}
	return sprintf(
		/* translators: 1: collaborator name, 2: block description */
		__( '%1$s is working in a block that has not reached you yet: %2$s' ),
		marker.name,
		what
	);
}
