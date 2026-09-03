/**
 * The per-block indicator: a thin colored stripe to the left of every block
 * a peer is working in, and a label on hovering the stripe. Applied through
 * the public `editor.BlockListBlock` filter by adding a class, CSS custom
 * properties, and hover handlers to the block wrapper.
 */

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { getSyncId } from '../block-refs';
import { store } from '../store';
import type { BlockPresence } from '../store';
import {
	STRIPE_WIDTH,
	ensureCanvasStyles,
	getBlockElement,
} from './canvas-styles';
import { describeBlock } from './labels';

interface BlockListBlockProps {
	clientId: string;
	attributes?: Record< string, unknown >;
	wrapperProps?: Record< string, unknown > & {
		className?: string;
		style?: Record< string, unknown >;
	};
	[ key: string ]: unknown;
}

type BlockListBlockComponent = ( props: BlockListBlockProps ) => JSX.Element;

/**
 * A hex color with an alpha channel.
 *
 * @param hex   `#rrggbb`.
 * @param alpha 0-1.
 * @return An `rgba(...)` string (the input when it is not a hex color).
 */
export function withAlpha( hex: string, alpha: number ): string {
	const match = /^#([0-9a-f]{6})$/i.exec( hex );
	if ( ! match || alpha >= 1 ) {
		return hex;
	}
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( start ) =>
		parseInt( hex.slice( start, start + 2 ), 16 )
	);
	return `rgba(${ r }, ${ g }, ${ b }, ${ alpha })`;
}

/**
 * The stripe element's strength: the strongest entry's. Entries weaker
 * than that get their difference baked into the gradient instead, so a
 * single element opacity can animate the common one-peer case.
 *
 * @param entries Presence entries.
 * @return 0-1.
 */
export function stripeOpacity( entries: BlockPresence[] ): number {
	return entries.reduce(
		( max, entry ) => Math.max( max, entry.opacity ),
		0
	);
}

/**
 * One stripe per peer, side by side, as a CSS gradient.
 *
 * @param entries Presence entries.
 * @return A `linear-gradient(...)` string.
 */
export function stripeGradient( entries: BlockPresence[] ): string {
	const max = stripeOpacity( entries ) || 1;
	const stops = entries.map( ( entry, index ) => {
		const from = index * STRIPE_WIDTH;
		const to = from + STRIPE_WIDTH;
		const color = withAlpha( entry.color, entry.opacity / max );
		return `${ color } ${ from }px ${ to }px`;
	} );
	return `linear-gradient(to right, ${ stops.join( ', ' ) })`;
}

/**
 * A label safe to put in a CSS custom property that `content` reads.
 *
 * @param text The label.
 * @return A quoted CSS string.
 */
export function cssString( text: string ): string {
	return JSON.stringify( text.replace( /[\r\n]+/g, ' ' ) );
}

/**
 * Whether the pointer is over the stripe zone, left of the block's own box.
 *
 * @param event  The mouse event.
 * @param target The block wrapper.
 * @return True when hovering the stripe.
 */
function isOverStripe( event: MouseEvent, target: HTMLElement ): boolean {
	const rect = target.getBoundingClientRect();
	return event.clientX < rect.left;
}

const withPeerPresence = createHigherOrderComponent(
	( BlockListBlock: BlockListBlockComponent ) =>
		function PeerPresenceBlock( props: BlockListBlockProps ) {
			const { clientId } = props;
			const syncId = getSyncId( props.attributes ?? null );
			const presence = useSelect(
				( select ) =>
					select( store ).getBlockPresence( syncId, clientId ),
				[ syncId, clientId ]
			);
			const hasPresence = presence.length > 0;
			const now = useSelect(
				( select ) => ( hasPresence ? select( store ).getNow() : 0 ),
				[ hasPresence ]
			);
			const [ labelVisible, setLabelVisible ] = useState( false );

			useEffect( () => {
				if ( ! hasPresence ) {
					return;
				}
				const element = getBlockElement( clientId );
				if ( element ) {
					ensureCanvasStyles( element.ownerDocument );
				}
			}, [ hasPresence, clientId ] );

			if ( ! hasPresence ) {
				return <BlockListBlock { ...props } />;
			}

			const leaving = presence.every( ( entry ) => entry.leaving );
			const classes = [
				props.wrapperProps?.className,
				'gse-peer-presence',
				leaving ? 'is-leaving' : 'is-active',
				labelVisible && ! leaving ? 'is-gse-label-visible' : null,
			]
				.filter( Boolean )
				.join( ' ' );
			const wrapperProps = {
				...props.wrapperProps,
				className: classes,
				style: {
					...props.wrapperProps?.style,
					'--gse-peer-color': presence[ 0 ].color,
					'--gse-peer-opacity': String( stripeOpacity( presence ) ),
					'--gse-peer-stripe': stripeGradient( presence ),
					'--gse-peer-stripe-width': `${
						presence.length * STRIPE_WIDTH
					}px`,
					'--gse-peer-label': cssString(
						describeBlock( presence, now )
					),
				},
				onMouseMove: ( event: MouseEvent ) => {
					const target = event.currentTarget as HTMLElement | null;
					if ( ! target ) {
						return;
					}
					setLabelVisible( isOverStripe( event, target ) );
				},
				onMouseLeave: () => setLabelVisible( false ),
			};
			return (
				<BlockListBlock { ...props } wrapperProps={ wrapperProps } />
			);
		},
	'withPeerPresence'
);

let registered = false;

/**
 * Registers the block wrapper filter once.
 */
export function registerBlockIndicator(): void {
	if ( registered ) {
		return;
	}
	registered = true;
	addFilter(
		'editor.BlockListBlock',
		'gutenberg-sync-engines/peer-presence',
		withPeerPresence
	);
}
