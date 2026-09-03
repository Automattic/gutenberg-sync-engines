/**
 * The hover list for a block's bar: every peer on the block at once, each
 * with a color dot (solid for peers drawn on the bar, hollow for those
 * beyond the slot limit), their name, and what they are doing. Rendered
 * into the canvas document through a portal, beside the bar.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { createPortal } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { layoutBar, withAlpha } from '../slots';
import { store } from '../store';
import type { BlockPresence } from '../store';
import {
	STRIPE_OFFSET,
	ensureCanvasStyles,
	getBlockElement,
} from './canvas-styles';
import { describePresenceShort } from './labels';

const TOOLTIP_WIDTH = 240;
const GAP = 8;

interface Placement {
	top: number;
	left: number;
}

/**
 * Where to put the list: left of the bar when the margin has room, else
 * above the block.
 *
 * @param element The block wrapper.
 * @return Coordinates in the block's document.
 */
function placeBeside( element: HTMLElement ): Placement {
	const rect = element.getBoundingClientRect();
	const win = element.ownerDocument.defaultView;
	const scrollX = win?.scrollX ?? 0;
	const scrollY = win?.scrollY ?? 0;
	const barLeft = rect.left - STRIPE_OFFSET;
	if ( barLeft - GAP - TOOLTIP_WIDTH >= GAP ) {
		return {
			top: rect.top + scrollY,
			left: barLeft - GAP - TOOLTIP_WIDTH + scrollX,
		};
	}
	return {
		top: rect.top + scrollY - GAP,
		left: barLeft + scrollX,
	};
}

/**
 * Renders the hover list for the hovered block, if any.
 *
 * @return The list, or null.
 */
export function PresenceTooltip() {
	const hovered = useSelect(
		( select ) => select( store ).getHoveredBlock(),
		[]
	);
	const entries = useSelect(
		( select ) =>
			hovered
				? select( store ).getBlockPresence(
						hovered.syncId,
						hovered.clientId
				  )
				: [],
		[ hovered ]
	);
	const now = useSelect(
		( select ) => ( hovered ? select( store ).getNow() : 0 ),
		[ hovered ]
	);
	const shown = entries.filter( ( entry ) => ! entry.leaving );
	if ( ! hovered || ! shown.length ) {
		return null;
	}
	const element = getBlockElement( hovered.clientId );
	if ( ! element ) {
		return null;
	}
	const doc = element.ownerDocument;
	ensureCanvasStyles( doc );
	const { slots } = layoutBar( entries );
	const onBar = new Set(
		slots.filter( ( entry ) => ! entry.leaving ).map( ( e ) => e.peerKey )
	);
	const { top, left } = placeBeside( element );
	const above = left + TOOLTIP_WIDTH > element.getBoundingClientRect().left;

	return createPortal(
		<div
			className="gse-presence-tooltip"
			style={ {
				top: `${ top }px`,
				left: `${ left }px`,
				transform: above ? 'translateY(-100%)' : undefined,
			} }
		>
			{ shown.map( ( entry: BlockPresence ) => (
				<div
					className="gse-presence-tooltip__row"
					key={ entry.peerKey }
				>
					<span
						className={ `gse-presence-tooltip__dot${
							onBar.has( entry.peerKey ) ? '' : ' is-list-only'
						}` }
						style={
							{
								background: withAlpha(
									entry.color,
									entry.opacity
								),
								'--gse-dot-color': entry.color,
							} as React.CSSProperties
						}
					/>
					<span className="gse-presence-tooltip__name">
						{ entry.name }
					</span>
					<span className="gse-presence-tooltip__status">
						{ describePresenceShort( entry, now ) }
					</span>
				</div>
			) ) }
		</div>,
		doc.body
	);
}
