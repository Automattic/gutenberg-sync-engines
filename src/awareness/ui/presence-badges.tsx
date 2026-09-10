/**
 * The avatar badges: one per block a peer is in, placed above the block's
 * top-left corner like the framework's own block label. Rendered into the
 * block canvas document (the editor iframe) at page coordinates measured
 * from the marked block wrappers, so they scroll with the content.
 *
 * Badges cannot live inside the block wrapper: for text blocks that
 * wrapper is the editable element itself, and a child there would become
 * part of the content. So, like the framework, this measures the blocks
 * and draws in a layer of its own.
 */

/**
 * WordPress dependencies
 */
import { subscribe, useSelect } from '@wordpress/data';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { store } from '../store';
import type { Peer } from '../types';
import { PEER_ATTRIBUTE } from './block-indicator';
import { ensureCanvasStyles, getCanvasDocument } from './canvas-styles';

/** How long after the last editor change to re-measure the blocks. */
const RELAYOUT_DELAY_MS = 500;

/** Inline styles that may carry CSS custom properties. */
type StyleWithVars = React.CSSProperties & Record< `--${ string }`, string >;

interface PlacedBadge {
	peer: Peer;
	top: number;
	left: number;
}

/**
 * The initials shown when a peer has no avatar image.
 *
 * @param name Display name.
 * @return Up to two initials.
 */
export function initialsOf( name: string ): string {
	return name
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( word ) => word[ 0 ] )
		.join( '' )
		.toUpperCase();
}

/**
 * Text color that reads on a badge color: dark on light backgrounds.
 *
 * @param color A `#rrggbb` color.
 * @return `#1e1e1e` or `#fff`.
 */
export function nameColorOn( color: string ): string {
	const match = /^#([0-9a-f]{6})$/i.exec( color );
	if ( ! match ) {
		return '#fff';
	}
	const value = parseInt( match[ 1 ], 16 );
	// eslint-disable-next-line no-bitwise
	const [ r, g, b ] = [ value >> 16, ( value >> 8 ) & 255, value & 255 ];
	const luminance = ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;
	return luminance > 0.6 ? '#1e1e1e' : '#fff';
}

function Badge( { peer }: { peer: Peer } ) {
	const [ loaded, setLoaded ] = useState( false );
	const name = peer.identity.name || __( 'Anonymous User' );
	const src = peer.identity.avatarUrl;
	const className = [
		'gse-avatar',
		'is-badge',
		'is-small',
		loaded ? 'has-src' : null,
	]
		.filter( Boolean )
		.join( ' ' );
	const style: StyleWithVars = {
		'--gse-avatar-outline-color': peer.color,
		'--gse-avatar-name-color': nameColorOn( peer.color ),
	};
	return (
		<div
			className={ className }
			style={ style }
			role="img"
			aria-label={ name }
		>
			<span className="gse-avatar__image">
				{ src && (
					<img
						src={ src }
						alt=""
						crossOrigin="anonymous"
						className="gse-avatar__img"
						onLoad={ () => setLoaded( true ) }
						onError={ () => setLoaded( false ) }
					/>
				) }
				{ ! loaded && initialsOf( name ) }
			</span>
			<span className="gse-avatar__name">{ name }</span>
		</div>
	);
}

/**
 * Measures every marked block in the canvas document.
 *
 * @param doc   The canvas document.
 * @param peers The peers, by key.
 * @return Badges to draw.
 */
function measure( doc: Document, peers: Map< string, Peer > ): PlacedBadge[] {
	const win = doc.defaultView;
	const scrollX = win?.scrollX ?? 0;
	const scrollY = win?.scrollY ?? 0;
	const placed: PlacedBadge[] = [];
	doc.querySelectorAll< HTMLElement >( `[${ PEER_ATTRIBUTE }]` ).forEach(
		( element ) => {
			const peer = peers.get(
				element.getAttribute( PEER_ATTRIBUTE ) ?? ''
			);
			if ( ! peer ) {
				return;
			}
			const rect = element.getBoundingClientRect();
			if ( 0 === rect.width && 0 === rect.height ) {
				return;
			}
			placed.push( {
				peer,
				top: rect.top + scrollY,
				left: rect.left + scrollX,
			} );
		}
	);
	return placed;
}

/**
 * The badge layer. Measures on the next animation frame after: a peer
 * change (the block filter, in the editor's own React tree, applies the
 * marker attribute on its own schedule, so the frame lets it land first),
 * any marker or block change in the canvas (a MutationObserver: the
 * peer's block arriving, moving, or losing its marker), an editor change
 * settling (blocks resized by typing), and a canvas resize.
 */
export function PresenceBadges() {
	const peers = useSelect( ( select ) => select( store ).getPeers(), [] );
	const [ layoutTick, setLayoutTick ] = useState( 0 );
	const [ placed, setPlaced ] = useState< PlacedBadge[] >( [] );
	const doc = getCanvasDocument();

	// Editor changes, debounced: the canvas settles before we measure.
	useEffect( () => {
		let timer: ReturnType< typeof setTimeout > | null = null;
		const unsubscribe = subscribe( () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => {
				timer = null;
				setLayoutTick( ( tick ) => tick + 1 );
			}, RELAYOUT_DELAY_MS );
		}, 'core/block-editor' );
		return () => {
			unsubscribe();
			if ( timer ) {
				clearTimeout( timer );
			}
		};
	}, [] );

	// Canvas resizes (window width, content height) and marker changes.
	useEffect( () => {
		const body = doc.body;
		if ( ! body ) {
			return;
		}
		let frame: number | null = null;
		const relayout = () => {
			if ( null !== frame ) {
				return;
			}
			frame = requestAnimationFrame( () => {
				frame = null;
				setLayoutTick( ( tick ) => tick + 1 );
			} );
		};
		const resize =
			'undefined' !== typeof ResizeObserver
				? new ResizeObserver( relayout )
				: null;
		resize?.observe( body );
		const mutation =
			'undefined' !== typeof MutationObserver
				? new MutationObserver( relayout )
				: null;
		mutation?.observe( body, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: [ PEER_ATTRIBUTE ],
		} );
		return () => {
			resize?.disconnect();
			mutation?.disconnect();
			if ( null !== frame ) {
				cancelAnimationFrame( frame );
			}
		};
	}, [ doc ] );

	useEffect( () => {
		if ( ! peers.length ) {
			setPlaced( ( current ) => ( current.length ? [] : current ) );
			return;
		}
		ensureCanvasStyles( doc );
		const byKey = new Map( peers.map( ( peer ) => [ peer.key, peer ] ) );
		// The editor's React tree applies the markers on its own schedule;
		// measure once it has had a frame to do so.
		const frame = requestAnimationFrame( () => {
			setPlaced( measure( doc, byKey ) );
		} );
		return () => cancelAnimationFrame( frame );
	}, [ peers, layoutTick, doc ] );

	if ( ! doc.body || ! placed.length ) {
		return null;
	}

	return createPortal(
		<div className="gse-presence-layer">
			{ placed.map( ( badge ) => (
				<div
					key={ badge.peer.key }
					className="gse-presence-badge"
					style={ {
						top: `${ badge.top }px`,
						left: `${ badge.left }px`,
					} }
				>
					<Badge peer={ badge.peer } />
				</div>
			) ) }
		</div>,
		doc.body
	);
}
