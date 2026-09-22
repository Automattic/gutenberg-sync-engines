/**
 * Typed views of the editor stores this UI reads. The editor and block
 * editor packages register their stores without selector types, so a
 * plain `select( editorStore )` knows nothing about `getCurrentPostType`.
 * These helpers name the handful of selectors and actions the UI uses.
 */

/**
 * WordPress dependencies
 */
import type { Block as WPBlock } from '@wordpress/blocks';
// @ts-expect-error `@wordpress/block-editor` does not expose type declarations for its entry point.
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';

export interface EditorSelectors {
	getCurrentPostType: () => string | undefined;
	getCurrentPostId: () => number | undefined;
	getCurrentPostAttribute: ( attribute: string ) => unknown;
}

export interface BlockEditorSelectors {
	getBlocks: () => WPBlock[];
	getBlockOrder: ( rootClientId?: string ) => string[];
	getClientIdsWithDescendants: () => string[];
	getBlockAttributes: (
		clientId: string
	) =>
		| ( Record< string, unknown > & { metadata?: { syncId?: string } } )
		| null;
}

export interface BlockEditorActions {
	selectBlock: ( clientId: string ) => void;
	flashBlock: ( clientId: string, duration?: number ) => void;
}

type Select = (
	store: typeof editorStore | typeof blockEditorStore
) => unknown;
type Dispatch = ( store: typeof blockEditorStore ) => unknown;

/**
 * The editor store's selectors, typed.
 *
 * @param select A `select` function (from `useSelect`, `registry`, or data).
 * @return The selectors.
 */
export function editorSelectors( select: Select ): EditorSelectors {
	return select( editorStore ) as EditorSelectors;
}

/**
 * The block editor store's selectors, typed.
 *
 * @param select A `select` function.
 * @return The selectors.
 */
export function blockEditorSelectors( select: Select ): BlockEditorSelectors {
	return select( blockEditorStore ) as BlockEditorSelectors;
}

/**
 * The block editor store's actions, typed.
 *
 * @param dispatch A `dispatch` function (from `useDispatch` or data).
 * @return The actions.
 */
export function blockEditorActions( dispatch: Dispatch ): BlockEditorActions {
	return dispatch( blockEditorStore ) as BlockEditorActions;
}
