/**
 * A sidebar panel listing every peer's activity in words: where they are,
 * where they have been in the last half minute, what they changed, and
 * when the next update is due. Over a slow channel this list carries more
 * than any in-canvas marker can, and it is the place to surface activity
 * in blocks this editor has not received.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem, PanelBody } from '@wordpress/components';
import { dispatch, useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { buildIdentityIndex, resolveBlockRef } from '../block-refs';
import type { BlockTreeReader } from '../block-refs';
import { secondsUntilNextBeacon, trailOpacity } from '../staleness';
import { store, trailOf } from '../store';
import type { PeerActivity } from '../types';
import { getBlockElement } from './canvas-styles';
import { ageText, agoText, describeRef } from './labels';

const SIDEBAR_NAME = 'gutenberg-sync-engines-awareness';

interface BlockEditorDispatch {
	selectBlock: ( clientId: string ) => void;
}

/**
 * Selects a block and scrolls it into view.
 *
 * @param clientId Block clientId.
 */
function goToBlock( clientId: string ): void {
	(
		dispatch( 'core/block-editor' ) as unknown as BlockEditorDispatch
	 ).selectBlock( clientId );
	getBlockElement( clientId )?.scrollIntoView( {
		block: 'center',
		behavior: 'smooth',
	} );
}

function PeerRow( { peer, now }: { peer: PeerActivity; now: number } ) {
	const index = useSelect( ( select ) => {
		const reader = select(
			'core/block-editor'
		) as unknown as BlockTreeReader;
		return buildIdentityIndex( reader );
	}, [] );
	const { focus, edits } = peer.beacon;
	const sinceBeacon = Math.max( 0, now - peer.receivedAt );
	const overdue = sinceBeacon > peer.beacon.intervalMs;
	const trail = trailOf( peer.beacon ).filter(
		( entry ) =>
			trailOpacity( entry.ageMs ) > 0 &&
			! ( focus && entry.ref.clientId === focus.clientId )
	);
	const removed = edits.filter( ( edit ) => 'remove' === edit.kind );
	const focusResolution = focus ? resolveBlockRef( focus, index ) : null;

	return (
		<div
			style={ {
				borderLeft: `3px solid ${ peer.color }`,
				paddingLeft: 10,
				marginBottom: 16,
			} }
		>
			<Flex justify="space-between" align="flex-start">
				<FlexItem>
					<strong>{ peer.identity.name || __( 'Anonymous' ) }</strong>
				</FlexItem>
				<FlexItem>
					<span style={ { fontSize: 11, color: '#757575' } }>
						{ overdue
							? sprintf(
									/* translators: %s: age */
									__( 'last update %s' ),
									ageText( peer.receivedAt, now )
							  )
							: sprintf(
									/* translators: %d: seconds */
									__( 'next update in %d s' ),
									secondsUntilNextBeacon(
										peer.receivedAt,
										peer.beacon.intervalMs,
										now
									)
							  ) }
					</span>
				</FlexItem>
			</Flex>
			{ focus ? (
				<p style={ { margin: '4px 0' } }>
					{ 'local' === focusResolution?.kind ? (
						<>
							{ sprintf(
								/* translators: %s: block description */
								__( 'In: %s' ),
								describeRef( focus )
							) }{ ' ' }
							<Button
								variant="link"
								size="small"
								onClick={ () =>
									goToBlock( focusResolution.clientId )
								}
							>
								{ __( 'Go to block' ) }
							</Button>
						</>
					) : (
						<em>
							{ sprintf(
								/* translators: %s: block description */
								__(
									'In a block you have not received yet: %s'
								),
								describeRef( focus )
							) }
						</em>
					) }
				</p>
			) : (
				<p style={ { margin: '4px 0', color: '#757575' } }>
					{ __( 'No block selected' ) }
				</p>
			) }
			{ ( trail.length > 0 || removed.length > 0 ) && (
				<ul style={ { margin: '4px 0 0 16px', fontSize: 12 } }>
					{ trail.map( ( entry ) => {
						const resolution = resolveBlockRef( entry.ref, index );
						const edit = edits.find(
							( e ) => e.ref.clientId === entry.ref.clientId
						);
						let verb: string = __( 'was in' );
						if ( 'insert' === edit?.kind ) {
							verb = __( 'added' );
						} else if ( 'edit' === edit?.kind ) {
							verb = __( 'edited' );
						}
						return (
							<li
								key={ entry.ref.clientId }
								style={ {
									opacity: trailOpacity( entry.ageMs ),
								} }
							>
								{ sprintf(
									/* translators: 1: verb, 2: block description, 3: age */
									__( '%1$s %2$s, %3$s' ),
									verb,
									describeRef( entry.ref ),
									agoText( entry.ageMs + sinceBeacon )
								) }
								{ 'phantom' === resolution.kind && (
									<em> { __( '(not received yet)' ) }</em>
								) }
								{ 'local' === resolution.kind && (
									<Button
										variant="link"
										size="small"
										onClick={ () =>
											goToBlock( resolution.clientId )
										}
									>
										{ __( 'Go' ) }
									</Button>
								) }
							</li>
						);
					} ) }
					{ removed.map( ( edit ) => (
						<li key={ `removed-${ edit.ref.clientId }` }>
							{ sprintf(
								/* translators: %s: block description */
								__( 'removed %s' ),
								describeRef( edit.ref )
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * The sidebar panel.
 *
 * @return The panel.
 */
export function ActivityPanel() {
	const { peers, now, settings } = useSelect(
		( select ) => ( {
			peers: select( store ).getPeers(),
			now: select( store ).getNow(),
			settings: select( store ).getSettings(),
		} ),
		[]
	);
	const seconds = Math.round( settings.intervalMs / 1000 );
	const channelLabel =
		'heartbeat' === settings.channel
			? __( 'WordPress Heartbeat' )
			: __( 'the sync transport' );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				{ __( 'Collaborator activity' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'Collaborator activity' ) }
			>
				<PanelBody>
					<p
						style={ {
							color: '#757575',
							fontSize: 12,
							marginTop: 0,
						} }
					>
						{ sprintf(
							/* translators: 1: seconds, 2: channel name */
							__(
								'Activity is exchanged every %1$d seconds over %2$s. Blocks a peer touched in the last 30 seconds show a stripe on the left: full strength under 15 seconds, half after.'
							),
							seconds,
							channelLabel
						) }
					</p>
					{ peers.length === 0 && (
						<p>{ __( 'No other editors right now.' ) }</p>
					) }
					{ peers.map( ( peer ) => (
						<PeerRow key={ peer.key } peer={ peer } now={ now } />
					) ) }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}
