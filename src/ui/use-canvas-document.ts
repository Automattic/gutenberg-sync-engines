/**
 * WordPress dependencies
 */
import { subscribe } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * The document that holds the block canvas: the editor iframe's when there
 * is one and it has loaded, else the page itself.
 *
 * @return The canvas document, or null while an iframe is still loading.
 */
export function getCanvasDocument(): Document | null {
	const iframe = document.querySelector< HTMLIFrameElement >(
		'iframe[name="editor-canvas"]'
	);
	if ( ! iframe ) {
		return document;
	}
	const doc = iframe.contentDocument;
	// The iframe starts out as about:blank; wait for the editor's document.
	if ( ! doc || ! doc.body || 'about:blank' === doc.URL ) {
		return null;
	}
	return doc;
}

/** How often to look again while the iframe has not loaded yet. */
const POLL_MS = 250;

/**
 * Tracks the block canvas document across editor changes: the iframe is
 * created after the editor mounts, reloads when the template or the
 * rendering mode changes, and is absent on screens with a non-iframed
 * canvas. Re-resolves on every block editor store change and, until a
 * document is found, on a short timer.
 *
 * @return The canvas document, or null while none is available.
 */
export function useCanvasDocument(): Document | null {
	const [ doc, setDoc ] = useState< Document | null >( getCanvasDocument );

	useEffect( () => {
		const check = () => {
			const next = getCanvasDocument();
			setDoc( ( current ) => ( current === next ? current : next ) );
		};
		check();
		const unsubscribe = subscribe( check, 'core/block-editor' );
		const timer = setInterval( check, POLL_MS );
		return () => {
			unsubscribe();
			clearInterval( timer );
		};
	}, [] );

	return doc;
}
