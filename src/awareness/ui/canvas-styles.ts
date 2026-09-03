/**
 * Styles for the block bar, the hover list, and phantom markers. The
 * editor canvas is usually an iframe, so these are injected as a <style>
 * element into whichever document holds the blocks.
 *
 * The bar is one vertical gradient on the block wrapper's `::before`,
 * split into four color slots by three boundaries. Boundaries and colors
 * are registered custom properties (`@property`), which is what lets a
 * change animate: a new peer's segment grows in from the bottom while the
 * ones above shrink up, and a departing peer's segment shrinks to nothing
 * at the top. Browsers without `@property` (Firefox before 128) show the
 * same bar without the animation.
 */

export const CANVAS_STYLE_ID = 'gutenberg-sync-engines-awareness-styles';

/** Width of the bar, in px. */
export const STRIPE_WIDTH = 3;

/** How far left of the block edge the bar sits, in px. */
export const STRIPE_OFFSET = 10;

/** Width of the invisible hover zone around the bar, in px. */
export const STRIPE_HIT_WIDTH = 16;

/** How long segment and strength changes take to animate, in ms. */
export const BAR_TRANSITION_MS = 250;

const BOUNDARIES = [ '--gse-b1', '--gse-b2', '--gse-b3' ];
const COLORS = [ '--gse-c1', '--gse-c2', '--gse-c3', '--gse-c4' ];

const propertyRules = [
	...BOUNDARIES.map(
		( name ) =>
			`@property ${ name } { syntax: "<percentage>"; inherits: true; initial-value: 100%; }`
	),
	...COLORS.map(
		( name ) =>
			`@property ${ name } { syntax: "<color>"; inherits: true; initial-value: transparent; }`
	),
].join( '\n' );

const transitions = [ ...BOUNDARIES, ...COLORS ]
	.map( ( name ) => `${ name } ${ BAR_TRANSITION_MS }ms ease` )
	.join( ', ' );

export const CANVAS_STYLES = `
${ propertyRules }
.block-editor-block-list__block.gse-peer-presence {
	position: relative;
	transition: ${ transitions };
}
.block-editor-block-list__block.gse-peer-presence.gse-no-transition {
	transition: none;
}
.block-editor-block-list__block.gse-peer-presence::before {
	content: "";
	position: absolute;
	top: 0;
	bottom: 0;
	left: -${ STRIPE_OFFSET + ( STRIPE_HIT_WIDTH - STRIPE_WIDTH ) / 2 }px;
	width: ${ STRIPE_HIT_WIDTH }px;
	background-image: linear-gradient(
		to bottom,
		var(--gse-c1) 0%,
		var(--gse-c1) var(--gse-b1),
		var(--gse-c2) var(--gse-b1),
		var(--gse-c2) var(--gse-b2),
		var(--gse-c3) var(--gse-b2),
		var(--gse-c3) var(--gse-b3),
		var(--gse-c4) var(--gse-b3),
		var(--gse-c4) 100%
	);
	background-repeat: no-repeat;
	background-position: ${ ( STRIPE_HIT_WIDTH - STRIPE_WIDTH ) / 2 }px 0;
	background-size: ${ STRIPE_WIDTH }px 100%;
	border-radius: 2px;
	pointer-events: auto;
	cursor: help;
	z-index: 1;
}
.gse-presence-tooltip {
	position: absolute;
	width: 240px;
	box-sizing: border-box;
	padding: 8px 10px;
	border-radius: 6px;
	background: #1e1e1e;
	color: #fff;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	font-size: 12px;
	line-height: 1.4;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
	pointer-events: none;
	z-index: 100;
}
.gse-presence-tooltip__row {
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 2px 0;
}
.gse-presence-tooltip__dot {
	flex: none;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	align-self: center;
	box-sizing: border-box;
}
.gse-presence-tooltip__dot.is-list-only {
	background: transparent !important;
	border: 2px solid var(--gse-dot-color);
}
.gse-presence-tooltip__name {
	font-weight: 600;
	white-space: nowrap;
}
.gse-presence-tooltip__status {
	color: rgba(255, 255, 255, 0.75);
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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
	transition: opacity ${ BAR_TRANSITION_MS }ms ease;
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
