/**
 * WordPress dependencies
 */
import { createPortal, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Overlay } from './overlay';
import { type CursorRegistry } from './cursor-registry';
import { useCanvasDocument } from '../use-canvas-document';

interface Props {
	postId: number | null;
	postType: string | null;
	cursorRegistry?: CursorRegistry;
}

/** Where the overlay lives in the canvas document. */
const COVER_ID = 'gutenberg-sync-engines-canvas-cover';

/**
 * A full-size, click-through layer at the origin of the canvas document,
 * the stand-in for the block editor's private canvas cover. Created once
 * per document and reused.
 *
 * @param doc The canvas document.
 * @return The cover element.
 */
function ensureCover( doc: Document ): HTMLElement {
	const existing = doc.getElementById( COVER_ID );
	if ( existing ) {
		return existing;
	}
	const cover = doc.createElement( 'div' );
	cover.id = COVER_ID;
	cover.className = 'block-canvas-cover';
	doc.body.appendChild( cover );
	return cover;
}

/**
 * Collaborators Overlay component: draws the peers' carets, selections and
 * block labels into the block canvas. Rendered into the editor iframe's
 * document (or the page, for a non-iframed canvas) through a portal.
 *
 * @param props                - The props for the CollaboratorsOverlay component
 * @param props.postId         - The ID of the post
 * @param props.postType       - The type of the post
 * @param props.cursorRegistry - The shared cursor registry
 * @return The CollaboratorsOverlay component
 */
export function CollaboratorsOverlay( {
	postId,
	postType,
	cursorRegistry,
}: Props ) {
	const doc = useCanvasDocument();
	const [ cover, setCover ] = useState< HTMLElement | null >( null );

	useEffect( () => {
		if ( ! doc ) {
			setCover( null );
			return;
		}
		setCover( ensureCover( doc ) );
	}, [ doc ] );

	if ( ! doc || ! cover ) {
		return null;
	}

	return createPortal(
		<Overlay
			blockEditorDocument={ doc }
			postId={ postId }
			postType={ postType }
			cursorRegistry={ cursorRegistry }
		/>,
		cover
	);
}
