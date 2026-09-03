/**
 * A tiny in-memory block tree implementing the reader interface the
 * awareness modules consume, so they run under Jest with no editor.
 */

/**
 * Internal dependencies
 */
import type {
	BlockTreeReader,
	EditorBlock,
} from '../../../src/awareness/block-refs';

export interface FakeTree extends BlockTreeReader {
	select: ( clientId: string | null ) => void;
	setTyping: ( typing: boolean ) => void;
	/** Replaces a block's attributes (a new block object, like the store). */
	edit: ( clientId: string, attributes: Record< string, unknown > ) => void;
	insertAfter: ( clientId: string | null, block: EditorBlock ) => void;
	remove: ( clientId: string ) => void;
	/** Fires the subscribed listeners, like a store change. */
	subscribe: ( listener: () => void ) => () => void;
	notify: () => void;
}

/**
 * Builds a block for the fake tree.
 *
 * @param clientId   Client id.
 * @param name       Block name.
 * @param attributes Attributes.
 * @param inner      Inner blocks.
 * @return The block.
 */
export function block(
	clientId: string,
	name = 'core/paragraph',
	attributes: Record< string, unknown > = {},
	inner: EditorBlock[] = []
): EditorBlock {
	return { clientId, name, attributes, innerBlocks: inner };
}

/**
 * Creates the fake tree.
 *
 * @param roots Top-level blocks.
 * @return The tree.
 */
export function createFakeTree( roots: EditorBlock[] ): FakeTree {
	let blocks = roots;
	let selected: string | null = null;
	let typing = false;
	let idsCache: string[] | null = null;
	const listeners = new Set< () => void >();

	function walk(
		list: EditorBlock[],
		visit: (
			b: EditorBlock,
			parent: EditorBlock | null,
			index: number
		) => void,
		parent: EditorBlock | null = null
	): void {
		list.forEach( ( b, index ) => {
			visit( b, parent, index );
			walk( b.innerBlocks, visit, b );
		} );
	}

	function find( clientId: string ): {
		block: EditorBlock;
		parent: EditorBlock | null;
		index: number;
	} | null {
		let found: ReturnType< typeof find > = null;
		walk( blocks, ( b, parent, index ) => {
			if ( b.clientId === clientId ) {
				found = { block: b, parent, index };
			}
		} );
		return found;
	}

	function siblingsOf( parent: EditorBlock | null ): EditorBlock[] {
		return parent ? parent.innerBlocks : blocks;
	}

	function invalidate(): void {
		idsCache = null;
	}

	return {
		getSelectedBlockClientId: () => selected,
		getSelectionStart: () => ( selected ? { clientId: selected } : {} ),
		getClientIdsWithDescendants: () => {
			if ( ! idsCache ) {
				const ids: string[] = [];
				walk( blocks, ( b ) => ids.push( b.clientId ) );
				idsCache = ids;
			}
			return idsCache;
		},
		getBlock: ( clientId ) => find( clientId )?.block ?? null,
		getBlockAttributes: ( clientId ) =>
			find( clientId )?.block.attributes ?? null,
		getBlockName: ( clientId ) => find( clientId )?.block.name ?? null,
		getBlockRootClientId: ( clientId ) => {
			const hit = find( clientId );
			if ( ! hit ) {
				return null;
			}
			return hit.parent ? hit.parent.clientId : '';
		},
		getBlockIndex: ( clientId ) => find( clientId )?.index ?? -1,
		getPreviousBlockClientId: ( clientId ) => {
			const hit = find( clientId );
			if ( ! hit || 0 === hit.index ) {
				return null;
			}
			return siblingsOf( hit.parent )[ hit.index - 1 ].clientId;
		},
		isTyping: () => typing,
		select: ( clientId ) => {
			selected = clientId;
		},
		setTyping: ( value ) => {
			typing = value;
		},
		edit: ( clientId, attributes ) => {
			const hit = find( clientId );
			if ( ! hit ) {
				return;
			}
			const next = { ...hit.block, attributes };
			siblingsOf( hit.parent )[ hit.index ] = next;
			// A changed block yields a new tree, like the store.
			blocks = [ ...blocks ];
		},
		insertAfter: ( clientId, newBlock ) => {
			if ( null === clientId ) {
				blocks = [ newBlock, ...blocks ];
			} else {
				const hit = find( clientId );
				if ( ! hit ) {
					return;
				}
				siblingsOf( hit.parent ).splice( hit.index + 1, 0, newBlock );
				blocks = [ ...blocks ];
			}
			invalidate();
		},
		remove: ( clientId ) => {
			const hit = find( clientId );
			if ( ! hit ) {
				return;
			}
			siblingsOf( hit.parent ).splice( hit.index, 1 );
			blocks = [ ...blocks ];
			invalidate();
		},
		subscribe: ( listener ) => {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
		notify: () => listeners.forEach( ( l ) => l() ),
	};
}
