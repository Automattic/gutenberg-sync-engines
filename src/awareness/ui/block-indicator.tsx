/**
 * The per-block outline: applied through the public `editor.BlockListBlock`
 * filter by adding a class, the peer's color, and the peer's key to the
 * block wrapper. Each block looks up its own peer by its durable identity
 * and its clientId, so no global index exists: a peer naming a block this
 * editor does not hold matches no block and shows nothing until the block
 * renders. When the peer moves on, the old block's lookup empties on the
 * same store change, so the outline goes at once.
 */

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { getSyncId } from '../block-id';
import { store } from '../store';
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

/** The class on a block wrapper a peer is in. */
export const PRESENCE_CLASS = 'gse-presence';

/** The wrapper attribute naming that peer, read by the badge overlay. */
export const PEER_ATTRIBUTE = 'data-gse-peer';

const withPeerPresence = createHigherOrderComponent(
	( BlockListBlock: BlockListBlockComponent ) =>
		function PeerPresenceBlock( props: BlockListBlockProps ) {
			const { clientId } = props;
			const syncId = getSyncId( props.attributes );
			const peer = useSelect(
				( select ) =>
					select( store ).getPeerForBlock( syncId, clientId ),
				[ syncId, clientId ]
			);

			useEffect( () => {
				if ( ! peer ) {
					return;
				}
				const element = getBlockElement( clientId );
				if ( element ) {
					ensureCanvasStyles( element.ownerDocument );
				}
			}, [ peer, clientId ] );

			if ( ! peer ) {
				return <BlockListBlock { ...props } />;
			}

			const className = [ props.wrapperProps?.className, PRESENCE_CLASS ]
				.filter( Boolean )
				.join( ' ' );
			const wrapperProps = {
				...props.wrapperProps,
				className,
				style: {
					...props.wrapperProps?.style,
					'--gse-outline-color': peer.color,
				},
				[ PEER_ATTRIBUTE ]: peer.key,
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
