/**
 * Building and resolving block references.
 *
 * The publisher turns a local block into a `BlockRef` (durable identity +
 * descriptive context); the receiver resolves a peer's `BlockRef` against
 * its own block tree. Resolution can fail honestly: with awareness and
 * document updates on separate channels, a peer may name a block that has
 * not reached this editor yet. That case resolves to a PHANTOM anchored to
 * the nearest block we do have, rather than being dropped.
 *
 * The block-editor store is injected as a small reader interface so this
 * logic runs under plain Jest, with no editor.
 */

/**
 * Internal dependencies
 */
import type { BlockRef, BlockRefResolution } from './types';

export interface EditorBlock {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
	innerBlocks: EditorBlock[];
}

/**
 * The subset of `core/block-editor` selectors this module reads.
 */
export interface BlockTreeReader {
	getSelectedBlockClientId: () => string | null;
	getSelectionStart: () => { clientId?: string };
	getClientIdsWithDescendants: () => string[];
	getBlock: ( clientId: string ) => EditorBlock | null;
	getBlockAttributes: (
		clientId: string
	) => Record< string, unknown > | null;
	getBlockName: ( clientId: string ) => string | null;
	/** Empty string for top-level blocks. */
	getBlockRootClientId: ( clientId: string ) => string | null;
	getBlockIndex: ( clientId: string ) => number;
	getPreviousBlockClientId: ( clientId: string ) => string | null;
	isTyping: () => boolean;
}

const EXCERPT_LENGTH = 60;

/**
 * Attribute names most likely to hold a block's visible text, in order.
 */
const TEXT_ATTRIBUTE_KEYS = [
	'content',
	'value',
	'values',
	'citation',
	'caption',
	'text',
	'title',
	'label',
	'alt',
	'url',
];

/**
 * The durable identity for a local block: its syncId when stamped, else
 * its clientId (shared across peers under the yjs-server engine).
 *
 * @param reader   Block tree reader.
 * @param clientId Local clientId.
 * @return The identity string.
 */
export function identityOf(
	reader: BlockTreeReader,
	clientId: string
): string {
	const syncId = getSyncId( reader.getBlockAttributes( clientId ) );
	return syncId ?? clientId;
}

/**
 * Reads `metadata.syncId` off a block's attributes.
 *
 * @param attributes Block attributes.
 * @return The syncId, or undefined.
 */
export function getSyncId(
	attributes: Record< string, unknown > | null
): string | undefined {
	const metadata = attributes?.metadata;
	if ( ! metadata || 'object' !== typeof metadata ) {
		return undefined;
	}
	const syncId = ( metadata as { syncId?: unknown } ).syncId;
	return 'string' === typeof syncId && syncId ? syncId : undefined;
}

/**
 * Reduces a block's attributes to a short plain-text excerpt so a peer can
 * describe the block even when it has not received it.
 *
 * @param attributes Block attributes.
 * @return An excerpt, possibly empty.
 */
export function excerptOf(
	attributes: Record< string, unknown > | null
): string {
	if ( ! attributes ) {
		return '';
	}
	const candidates = [ ...TEXT_ATTRIBUTE_KEYS, ...Object.keys( attributes ) ];
	for ( const key of candidates ) {
		const text = asText( attributes[ key ] );
		if ( text ) {
			return truncate( text, EXCERPT_LENGTH );
		}
	}
	return '';
}

function asText( value: unknown ): string {
	if ( 'string' === typeof value ) {
		return stripTags( value );
	}
	if ( value && 'object' === typeof value ) {
		const richText = value as {
			toHTMLString?: () => string;
			toString?: () => string;
		};
		if ( 'function' === typeof richText.toHTMLString ) {
			return stripTags( richText.toHTMLString() );
		}
		if (
			'function' === typeof richText.toString &&
			richText.toString !== Object.prototype.toString
		) {
			return stripTags( richText.toString() );
		}
	}
	return '';
}

function stripTags( html: string ): string {
	return html
		.replace( /<[^>]*>/g, ' ' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
}

function truncate( text: string, max: number ): string {
	if ( text.length <= max ) {
		return text;
	}
	return text.slice( 0, max - 1 ).trimEnd() + '…';
}

/**
 * The index path of a block from the post content root.
 *
 * @param reader   Block tree reader.
 * @param clientId Local clientId.
 * @return The path, e.g. [0, 2] for the third child of the first block.
 */
export function pathOf( reader: BlockTreeReader, clientId: string ): number[] {
	const path: number[] = [];
	let current: string | null = clientId;
	let guard = 0;
	while ( current && guard < 100 ) {
		path.unshift( reader.getBlockIndex( current ) );
		const root = reader.getBlockRootClientId( current );
		current = root ? root : null;
		guard += 1;
	}
	return path;
}

/**
 * Builds the reference the publisher sends for a local block.
 *
 * @param reader   Block tree reader.
 * @param clientId Local clientId.
 * @return The reference, or null when the block does not exist.
 */
export function makeBlockRef(
	reader: BlockTreeReader,
	clientId: string
): BlockRef | null {
	const name = reader.getBlockName( clientId );
	if ( ! name ) {
		return null;
	}
	const attributes = reader.getBlockAttributes( clientId );
	const root = reader.getBlockRootClientId( clientId );
	const previous = reader.getPreviousBlockClientId( clientId );
	const ref: BlockRef = {
		clientId,
		name,
		path: pathOf( reader, clientId ),
		after: previous ? identityOf( reader, previous ) : null,
		parent: root ? identityOf( reader, root ) : null,
	};
	const syncId = getSyncId( attributes );
	if ( syncId ) {
		ref.syncId = syncId;
	}
	const excerpt = excerptOf( attributes );
	if ( excerpt ) {
		ref.excerpt = excerpt;
	}
	return ref;
}

/**
 * Maps every identity the local tree answers to (syncIds and clientIds) to
 * the local clientId. Rebuild whenever the tree changes.
 *
 * @param reader Block tree reader.
 * @return identity → clientId.
 */
export function buildIdentityIndex(
	reader: BlockTreeReader
): Map< string, string > {
	const index = new Map< string, string >();
	for ( const clientId of reader.getClientIdsWithDescendants() ) {
		index.set( clientId, clientId );
		const syncId = getSyncId( reader.getBlockAttributes( clientId ) );
		if ( syncId ) {
			index.set( syncId, clientId );
		}
	}
	return index;
}

/**
 * Resolves a peer's block reference against the local tree.
 *
 * Identity wins: a syncId match, then a clientId match. Anything else is a
 * phantom, anchored to the previous sibling when we have it, else to the
 * parent, else to the start of the document.
 *
 * @param ref   The peer's reference.
 * @param index The local identity index (see buildIdentityIndex).
 * @return How the reference lines up locally.
 */
export function resolveBlockRef(
	ref: BlockRef,
	index: Map< string, string >
): BlockRefResolution {
	if ( ref.syncId && index.has( ref.syncId ) ) {
		return { kind: 'local', clientId: index.get( ref.syncId ) as string };
	}
	if ( index.has( ref.clientId ) ) {
		return { kind: 'local', clientId: index.get( ref.clientId ) as string };
	}
	if ( ref.after && index.has( ref.after ) ) {
		return {
			kind: 'phantom',
			anchorClientId: index.get( ref.after ) as string,
			placement: 'after',
		};
	}
	if ( ref.parent && index.has( ref.parent ) ) {
		return {
			kind: 'phantom',
			anchorClientId: index.get( ref.parent ) as string,
			placement: 'inside',
		};
	}
	return { kind: 'phantom', anchorClientId: null, placement: 'start' };
}
