/**
 * The per-block indicator: a thin bar to the left of every block a peer
 * is working in, split vertically between peers, plus a hover list.
 * Applied through the public `editor.BlockListBlock` filter by adding a
 * class, CSS custom properties, and hover handlers to the block wrapper.
 */

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { getSyncId } from '../block-refs';
import { layoutBar } from '../slots';
import { store } from '../store';
import type { BlockPresence } from '../store';
import { ensureCanvasStyles, getBlockElement } from './canvas-styles';

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
 * Whether the pointer is over the bar zone, left of the block's own box.
 *
 * @param event  The mouse event.
 * @param target The block wrapper.
 * @return True when hovering the bar.
 */
function isOverBar( event: MouseEvent, target: HTMLElement ): boolean {
	const rect = target.getBoundingClientRect();
	return event.clientX < rect.left;
}

/**
 * The peers drawn on the bar, in slot order, as a comparable string.
 *
 * @param entries Presence entries.
 * @return A signature.
 */
function slotSignature( entries: BlockPresence[] ): string {
	return entries.map( ( entry ) => entry.peerKey ).join( '|' );
}

/**
 * Whether the change from one entry list to the next is only the prune of
 * leaving entries. That shifts the peers below them up a slot, which must
 * happen without animation (their segments have not moved on screen).
 *
 * @param previous The previous entries.
 * @param next     The next entries.
 * @return True on a prune.
 */
function isPrune( previous: BlockPresence[], next: BlockPresence[] ): boolean {
	if ( ! previous.some( ( entry ) => entry.leaving ) ) {
		return false;
	}
	if ( next.some( ( entry ) => entry.leaving ) ) {
		return false;
	}
	const kept = previous.filter( ( entry ) => ! entry.leaving );
	return slotSignature( kept ) === slotSignature( next );
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
			const { setHoveredBlock } = useDispatch( store );
			const hasPresence = presence.length > 0;
			const [ hovering, setHovering ] = useState( false );

			// The prune of leaving entries re-indexes the slots below them
			// without moving anything on screen; suppress the transition
			// for that one render, then let it run again next frame.
			const previousRef = useRef< BlockPresence[] >( presence );
			const [ , rerender ] = useState( 0 );
			const suppress = isPrune( previousRef.current, presence );
			useEffect( () => {
				previousRef.current = presence;
				if ( ! suppress ) {
					return;
				}
				const frame = requestAnimationFrame( () =>
					rerender( ( n ) => n + 1 )
				);
				return () => cancelAnimationFrame( frame );
			}, [ presence, suppress ] );

			useEffect( () => {
				if ( ! hasPresence ) {
					return;
				}
				const element = getBlockElement( clientId );
				if ( element ) {
					ensureCanvasStyles( element.ownerDocument );
				}
			}, [ hasPresence, clientId ] );

			useEffect( () => {
				if ( ! hovering || ! hasPresence ) {
					return undefined;
				}
				setHoveredBlock( { clientId, syncId } );
				return () => {
					setHoveredBlock( null );
				};
			}, [ hovering, hasPresence, clientId, syncId, setHoveredBlock ] );

			if ( ! hasPresence ) {
				return <BlockListBlock { ...props } />;
			}

			const { boundaries, colors } = layoutBar( presence );
			const classes = [
				props.wrapperProps?.className,
				'gse-peer-presence',
				suppress ? 'gse-no-transition' : null,
			]
				.filter( Boolean )
				.join( ' ' );
			const wrapperProps = {
				...props.wrapperProps,
				className: classes,
				style: {
					...props.wrapperProps?.style,
					'--gse-b1': `${ boundaries[ 0 ] }%`,
					'--gse-b2': `${ boundaries[ 1 ] }%`,
					'--gse-b3': `${ boundaries[ 2 ] }%`,
					'--gse-c1': colors[ 0 ],
					'--gse-c2': colors[ 1 ],
					'--gse-c3': colors[ 2 ],
					'--gse-c4': colors[ 3 ],
				},
				onMouseMove: ( event: MouseEvent ) => {
					const target = event.currentTarget as HTMLElement | null;
					if ( target ) {
						setHovering( isOverBar( event, target ) );
					}
				},
				onMouseLeave: () => setHovering( false ),
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
