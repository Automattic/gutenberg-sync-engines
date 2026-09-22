/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Popover } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useCanvasDocument } from '../use-canvas-document';
import { blockEditorSelectors } from '../stores';
import {
	canRestoreItems,
	groupByUnit,
	itemAnchorClientId,
	REASON_LABELS,
	useReviewData,
	useResolveReviewItems,
} from './review-data';
import type { ResolveReviewItems } from './review-data';
import type { SyncReviewItem } from '../../sync';

type Placement = 'top-end' | 'top-start' | 'bottom-start';

interface BlockPopoverProps {
	/** The block to anchor to. */
	clientId: string;
	placement: Placement;
	className: string;
	children: React.ReactNode;
}

/**
 * A popover anchored to a block's element in the canvas document. The
 * public `Popover` stands in for the block editor's private block popover;
 * it follows the block but does not step around the block toolbar.
 *
 * @param props           Props.
 * @param props.clientId
 * @param props.placement
 * @param props.className
 * @param props.children
 */
function BlockPopover( {
	clientId,
	placement,
	className,
	children,
}: BlockPopoverProps ) {
	const doc = useCanvasDocument();
	const [ anchor, setAnchor ] = useState< Element | null >( null );

	// The block element may arrive after the item does (a joiner's canvas
	// still mounting), so look it up again whenever the canvas or the
	// block list changes.
	const blockOrderKey = useSelect(
		( select ) =>
			blockEditorSelectors( select )
				.getClientIdsWithDescendants()
				.join( ',' ),
		[]
	);
	useEffect( () => {
		setAnchor(
			doc?.querySelector( `[data-block="${ clientId }"]` ) ?? null
		);
	}, [ doc, clientId, blockOrderKey ] );

	if ( ! anchor ) {
		return null;
	}

	return (
		<Popover
			anchor={ anchor }
			placement={ placement }
			focusOnMount={ false }
			className={ className }
			variant="unstyled"
			animate={ false }
		>
			{ children }
		</Popover>
	);
}

interface BlockCardBodyProps {
	/** Every review group targeting the block. */
	groups: SyncReviewItem[][];
	onResolve: ResolveReviewItems;
}

/**
 * The content of the inline pending-edit card: ONE merged task per block
 * (the prototype's merge-not-stack decision), no count chip, and the two
 * verbs: Adopt takes the set-aside edit, Reject discards it. Adopting a
 * requires-approval edit is reserved for users who may publish unfiltered
 * HTML. Position-independent so it can be unit-tested without the block
 * popover.
 *
 * @param props           Props.
 * @param props.groups
 * @param props.onResolve
 */
export function BlockCardBody( { groups, onResolve }: BlockCardBodyProps ) {
	const items = groups.flat();
	const allLocal = items.every( ( item ) => item.isLocal );
	const restorable = canRestoreItems( items );
	const reasons = Array.from(
		new Set(
			items
				.map( ( item ) => REASON_LABELS[ item.reason ] )
				.filter( Boolean )
		)
	);
	const summaries = items
		.map( ( item ) => item.summary ?? item.excerpt )
		.filter( Boolean );

	return (
		<div className="editor-collaboration-pending-card__body">
			<p className="editor-collaboration-pending-card__attribution">
				{ allLocal
					? __( 'Your edit on this block is pending.' )
					: __(
							'A collaborator’s edit on this block is pending.'
					  ) }{ ' ' }
				{ reasons.join( ' ' ) }
			</p>
			{ summaries.length > 0 && (
				<p className="editor-collaboration-pending-card__summary">
					{ sprintf(
						/* translators: %s: the content of the edit that is pending. */
						__( 'Pending content: “%s”' ),
						summaries.join( ' ' )
					) }
				</p>
			) }
			<div className="editor-collaboration-pending-card__actions">
				{ restorable ? (
					<Button
						__next40pxDefaultSize
						size="compact"
						variant="primary"
						onClick={ () => onResolve( items, 'restored' ) }
					>
						{ __( 'Adopt' ) }
					</Button>
				) : (
					<span className="editor-collaboration-pending-card__hint">
						{ __(
							'Only someone allowed to publish unfiltered HTML can adopt this.'
						) }
					</span>
				) }
				<Button
					__next40pxDefaultSize
					size="compact"
					variant="tertiary"
					isDestructive
					onClick={ () => onResolve( items, 'dismissed' ) }
				>
					{ __( 'Reject' ) }
				</Button>
			</div>
		</div>
	);
}

interface BlockCardProps extends BlockCardBodyProps {
	clientId: string;
}

function BlockCard( { clientId, groups, onResolve }: BlockCardProps ) {
	return (
		<BlockPopover
			clientId={ clientId }
			placement="top-end"
			className="editor-collaboration-pending-card"
		>
			<BlockCardBody groups={ groups } onResolve={ onResolve } />
		</BlockPopover>
	);
}

interface InsertionCardBodyProps {
	/** The parked insertion review item. */
	item: SyncReviewItem;
	onResolve: ResolveReviewItems;
}

/**
 * The content of an inline approval card for a parked NEW-block proposal:
 * who proposed it and the proposed content as inert text (NEVER live DOM:
 * the point of the approval gate is that this markup has not been
 * trusted), with Approve/Discard. Approve is reserved for users who may
 * publish it; others see why and can only Discard. Position-independent so
 * it can be unit-tested without the block popover.
 *
 * @param props           Props.
 * @param props.item
 * @param props.onResolve
 */
export function InsertionCardBody( {
	item,
	onResolve,
}: InsertionCardBodyProps ) {
	const { blockType, html } = item.proposedInsertion ?? { html: '' };
	const restorable = canRestoreItems( [ item ] );

	return (
		<div className="editor-collaboration-insertion-card__body">
			<p className="editor-collaboration-insertion-card__attribution">
				{ item.isLocal
					? __( 'You proposed adding content that needs approval.' )
					: __(
							'A collaborator proposed adding content that needs approval.'
					  ) }
			</p>
			{ blockType && (
				<p className="editor-collaboration-insertion-card__type">
					{ blockType }
				</p>
			) }
			{ html && (
				// Inert text, never live DOM: the whole point of the
				// approval gate is that this markup has not been trusted.
				<pre className="editor-collaboration-insertion-card__preview">
					{ html }
				</pre>
			) }
			<div className="editor-collaboration-insertion-card__actions">
				{ restorable ? (
					<Button
						__next40pxDefaultSize
						size="compact"
						variant="primary"
						onClick={ () => onResolve( [ item ], 'restored' ) }
					>
						{ __( 'Approve' ) }
					</Button>
				) : (
					<span className="editor-collaboration-insertion-card__hint">
						{ __(
							'Only someone allowed to publish unfiltered HTML can approve this.'
						) }
					</span>
				) }
				<Button
					__next40pxDefaultSize
					size="compact"
					variant="tertiary"
					isDestructive
					onClick={ () => onResolve( [ item ], 'dismissed' ) }
				>
					{ __( 'Discard' ) }
				</Button>
			</div>
		</div>
	);
}

interface InsertionCardProps extends InsertionCardBodyProps {
	clientId: string;
	placement: Placement;
}

function InsertionCard( {
	clientId,
	placement,
	item,
	onResolve,
}: InsertionCardProps ) {
	return (
		<BlockPopover
			clientId={ clientId }
			placement={ placement }
			className="editor-collaboration-insertion-card"
		>
			<InsertionCardBody item={ item } onResolve={ onResolve } />
		</BlockPopover>
	);
}

interface Insertion {
	clientId: string;
	placement: Placement;
	item: SyncReviewItem;
}

/**
 * In-canvas review surface: an inline pending-edit card on every block
 * whose edits were set aside (ONE merged card per block, the primary
 * resolution surface), plus an inline card for each parked new-block
 * proposal, anchored where the block would land. The document-sidebar
 * panel is a summary-only index over the same items; only conflicts whose
 * block or anchor no longer exists resolve there.
 */
export default function CollaborationConflictMarkers() {
	const { postType, postId, items, clientIdByTarget, clientIdByIndex } =
		useReviewData();
	const onResolve = useResolveReviewItems( postType, postId );
	const firstRootClientId = useSelect(
		( select ) =>
			blockEditorSelectors( select ).getBlockOrder()[ 0 ] ?? null,
		[]
	);

	if ( ! items.length ) {
		return null;
	}

	// On-block conflicts: clientId to groups of items targeting it.
	const groupsByClientId = new Map< string, SyncReviewItem[][] >();
	// Parked insertions get their own inline card, positioned relative to
	// their anchor sibling (or the top of the canvas).
	const insertions: Insertion[] = [];
	for ( const group of groupByUnit( items ) ) {
		const [ first ] = group;
		if ( first.proposedInsertion ) {
			const afterSiblingId = first.proposedInsertion.afterSiblingId;
			const anchorId = afterSiblingId
				? clientIdByTarget[ afterSiblingId ]
				: undefined;
			if ( anchorId ) {
				insertions.push( {
					clientId: anchorId,
					placement: 'bottom-start',
					item: first,
				} );
			} else if ( ! afterSiblingId && firstRootClientId ) {
				// Insert-at-top with a non-empty canvas.
				insertions.push( {
					clientId: firstRootClientId,
					placement: 'top-start',
					item: first,
				} );
			}
			// Anchor gone (or empty canvas): the sidebar panel covers it.
			continue;
		}
		const clientId = itemAnchorClientId( first, {
			clientIdByTarget,
			clientIdByIndex,
		} );
		if ( ! clientId ) {
			continue;
		}
		const groups = groupsByClientId.get( clientId );
		if ( groups ) {
			groups.push( group );
		} else {
			groupsByClientId.set( clientId, [ group ] );
		}
	}

	return (
		<>
			{ Array.from( groupsByClientId.entries() ).map(
				( [ clientId, groups ] ) => (
					<BlockCard
						key={ clientId }
						clientId={ clientId }
						groups={ groups }
						onResolve={ onResolve }
					/>
				)
			) }
			{ insertions.map( ( insertion ) => (
				<InsertionCard
					key={ insertion.item.id }
					clientId={ insertion.clientId }
					placement={ insertion.placement }
					item={ insertion.item }
					onResolve={ onResolve }
				/>
			) ) }
		</>
	);
}
