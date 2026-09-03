/**
 * Styles for the block stripe, its hover label, and phantom markers. The
 * editor canvas is usually an iframe, so these are injected as a <style>
 * element into whichever document holds the blocks.
 */

export const CANVAS_STYLE_ID = 'gutenberg-sync-engines-awareness-styles';

/** Width of one peer's stripe, in px. */
export const STRIPE_WIDTH = 3;

/** How far left of the block edge the stripe sits, in px. */
export const STRIPE_OFFSET = 10;

/** Width of the invisible hover zone around the stripe, in px. */
export const STRIPE_HIT_WIDTH = 16;

export const CANVAS_STYLES = `
.block-editor-block-list__block.gse-peer-presence {
	position: relative;
}
.block-editor-block-list__block.gse-peer-presence::before {
	content: "";
	position: absolute;
	top: 0;
	bottom: 0;
	left: -${ STRIPE_OFFSET + ( STRIPE_HIT_WIDTH - STRIPE_WIDTH ) / 2 }px;
	width: ${ STRIPE_HIT_WIDTH }px;
	background-image: var(--gse-peer-stripe);
	background-repeat: no-repeat;
	background-position: ${ ( STRIPE_HIT_WIDTH - STRIPE_WIDTH ) / 2 }px 0;
	background-size: var(--gse-peer-stripe-width) 100%;
	border-radius: 2px;
	pointer-events: auto;
	cursor: help;
	z-index: 1;
	opacity: var(--gse-peer-opacity, 1);
	transition: opacity 0.25s ease;
}
.block-editor-block-list__block.gse-peer-presence.is-gse-label-visible::after {
	content: var(--gse-peer-label);
	position: absolute;
	left: -${ STRIPE_OFFSET }px;
	top: -6px;
	transform: translateY(-100%);
	background: var(--gse-peer-color);
	color: #fff;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	font-size: 11px;
	line-height: 1.4;
	font-weight: 500;
	padding: 3px 8px;
	border-radius: 4px;
	white-space: nowrap;
	max-width: min(60vw, 480px);
	overflow: hidden;
	text-overflow: ellipsis;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
	pointer-events: none;
	z-index: 100;
}
.gse-phantom-layer {
	position: absolute;
	top: 0;
	left: 0;
	width: 0;
	height: 0;
	overflow: visible;
	pointer-events: none;
	z-index: 50;
}
.gse-phantom {
	position: absolute;
	display: flex;
	align-items: flex-start;
	gap: 8px;
	pointer-events: none;
	opacity: var(--gse-peer-opacity, 1);
	transition: opacity 0.25s ease;
}
.gse-phantom__stripe {
	flex: none;
	width: ${ STRIPE_WIDTH }px;
	height: 22px;
	border-radius: 2px;
	background-image: repeating-linear-gradient(
		to bottom,
		var(--gse-peer-color) 0 4px,
		transparent 4px 7px
	);
}
.gse-phantom__label {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	font-size: 11px;
	line-height: 1.4;
	color: var(--gse-peer-color);
	border: 1px dashed var(--gse-peer-color);
	border-radius: 4px;
	padding: 2px 8px;
	background: rgba(255, 255, 255, 0.92);
	max-width: min(60vw, 480px);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
`;

/**
 * Injects the styles into a document once.
 *
 * @param doc The document holding the blocks.
 */
export function ensureCanvasStyles( doc: Document ): void {
	if ( doc.getElementById( CANVAS_STYLE_ID ) ) {
		return;
	}
	const style = doc.createElement( 'style' );
	style.id = CANVAS_STYLE_ID;
	style.textContent = CANVAS_STYLES;
	( doc.head ?? doc.documentElement ).appendChild( style );
}

/**
 * The document that holds the block canvas: the editor iframe's when there
 * is one, else the page.
 *
 * @return The document.
 */
export function getCanvasDocument(): Document {
	const iframe = document.querySelector< HTMLIFrameElement >(
		'iframe[name="editor-canvas"]'
	);
	return iframe?.contentDocument ?? document;
}

/**
 * Finds a block's wrapper element in either document.
 *
 * @param clientId Block clientId.
 * @return The element, or null.
 */
export function getBlockElement( clientId: string ): HTMLElement | null {
	const id = `block-${ clientId }`;
	return (
		getCanvasDocument().getElementById( id ) ??
		document.getElementById( id )
	);
}
