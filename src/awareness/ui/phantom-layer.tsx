/**
 * Markers for phantoms: blocks a peer is working in that this editor has
 * not received. Each marker is a dashed stripe and a label placed where the
 * block will land (below the anchor block, inside the parent, or at the top
 * of the document), rendered into the canvas document through a portal.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { createPortal, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { store } from '../store';
import type { PhantomMarker } from '../store';
import {
	STRIPE_OFFSET,
	ensureCanvasStyles,
	getBlockElement,
	getCanvasDocument,
} from './canvas-styles';
import { describePhantom } from './labels';

interface Placed {
	marker: PhantomMarker;
	top: number;
	left: number;
	label: string;
}

/**
 * Computes where each marker goes, in the canvas document's coordinates.
 *
 * @param markers Phantom markers.
 * @param doc     The canvas document.
 * @param now     Now.
 * @return Placed markers (unplaceable ones are skipped).
 */
function place(
	markers: PhantomMarker[],
	doc: Document,
	now: number
): Placed[] {
	const win = doc.defaultView;
	const scrollX = win?.scrollX ?? 0;
	const scrollY = win?.scrollY ?? 0;
	const placed: Placed[] = [];
	// Stack markers that share an anchor.
	const used = new Map< string, number >();
	for ( const marker of markers ) {
		const anchorKey = marker.anchorClientId ?? '__start__';
		const stack = used.get( anchorKey ) ?? 0;
		used.set( anchorKey, stack + 1 );
		let top: number;
		let left: number;
		if ( marker.anchorClientId ) {
			const element = getBlockElement( marker.anchorClientId );
			if ( ! element || element.ownerDocument !== doc ) {
				continue;
			}
			const rect = element.getBoundingClientRect();
			left = rect.left + scrollX - STRIPE_OFFSET;
			top =
				'inside' === marker.placement
					? rect.bottom + scrollY - 28
					: rect.bottom + scrollY + 4;
		} else {
			const root = doc.querySelector( '.is-root-container' );
			const rect = root?.getBoundingClientRect();
			left = ( rect?.left ?? 24 ) + scrollX - STRIPE_OFFSET;
			top = ( rect?.top ?? 0 ) + scrollY + 4;
		}
		placed.push( {
			marker,
			top: top + stack * 28,
			left,
			label: describePhantom( marker, now ),
		} );
	}
	return placed;
}

/**
 * Renders the phantom markers into the block canvas.
 *
 * @return The layer, or null when there is nothing to show.
 */
export function PhantomLayer() {
	const markers = useSelect(
		( select ) => select( store ).getPhantoms(),
		[]
	);
	const now = useSelect(
		( select ) => ( markers.length ? select( store ).getNow() : 0 ),
		[ markers.length ]
	);
	const [ doc, setDoc ] = useState< Document | null >( null );

	// The canvas iframe can mount after the editor; re-check each tick.
	useEffect( () => {
		if ( ! markers.length ) {
			return;
		}
		const canvas = getCanvasDocument();
		ensureCanvasStyles( canvas );
		setDoc( canvas );
	}, [ markers.length, now ] );

	if ( ! markers.length || ! doc?.body ) {
		return null;
	}

	const placed = place( markers, doc, now );
	return createPortal(
		<div className="gse-phantom-layer">
			{ placed.map( ( { marker, top, left, label } ) => {
				const style: React.CSSProperties & {
					'--gse-peer-color': string;
					'--gse-peer-opacity': string;
				} = {
					top: `${ top }px`,
					left: `${ left }px`,
					'--gse-peer-color': marker.color,
					'--gse-peer-opacity': String( marker.opacity ),
				};
				return (
					<div
						key={ `${ marker.peerKey }:${
							marker.ref.syncId ?? marker.ref.clientId
						}` }
						className="gse-phantom"
						style={ style }
						title={ label }
					>
						<span className="gse-phantom__stripe" />
						<span className="gse-phantom__label">{ label }</span>
					</div>
				);
			} ) }
		</div>,
		doc.body
	);
}
