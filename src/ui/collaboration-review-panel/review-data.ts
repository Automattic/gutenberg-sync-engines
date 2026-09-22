/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { store as hostStore } from '../../host/store';
import { getAnnouncedSync } from '../../sync';
import type { SyncReviewItem } from '../../sync';
import { blockEditorSelectors, editorSelectors } from '../stores';

export type ReviewResolution = 'restored' | 'dismissed';

export type ResolveReviewItems = (
	items: SyncReviewItem[],
	resolution: ReviewResolution
) => void;

export interface ReviewAnchorMaps {
	/** syncId to clientId. */
	clientIdByTarget: Record< string, string >;
	/** Top-level block index to clientId. */
	clientIdByIndex: Record< number, string >;
}

export const REASON_LABELS: Record< string, string > = {
	'frame-conflict': __( 'It conflicted with a collaborator’s change.' ),
	'dependent-on-escalated': __(
		'It depended on another edit that was set aside.'
	),
	'requires-approval': __(
		'It contains content that needs approval from someone allowed to publish unfiltered HTML.'
	),
};

/**
 * Whether the current user may restore a group of review items. Restoring
 * a requires-approval conflict IS the approval (the content re-publishes
 * under the restorer's account), so it is reserved for users who can
 * publish unfiltered HTML. UI hint only: ingest re-enforces per the
 * authoring user's capability regardless.
 *
 * @param items The group's review items.
 * @return Whether restore is available.
 */
export function canRestoreItems( items: SyncReviewItem[] ): boolean {
	return (
		items.every( ( item ) => 'requires-approval' !== item.reason ) ||
		false !== getAnnouncedSync()?.canUnfilteredHtml
	);
}

const EMPTY_CLIENT_IDS: Record< string, string > = {};

/**
 * Groups review items by their unit (a batch of edits made together), so a
 * burst of typing reads as one conflict with one set of actions.
 *
 * @param items Review items.
 * @return Groups of items sharing a unitId.
 */
export function groupByUnit( items: SyncReviewItem[] ): SyncReviewItem[][] {
	const groups = new Map< string, SyncReviewItem[] >();
	for ( const item of items ) {
		const group = groups.get( item.unitId );
		if ( group ) {
			group.push( item );
		} else {
			groups.set( item.unitId, [ item ] );
		}
	}
	return Array.from( groups.values() );
}

/**
 * The clientId a review item anchors to in the canvas, or undefined for an
 * unanchored item (its block no longer exists, or it targets no block).
 * Identity (`targetId`/syncId) wins over the positional `targetIndex`
 * fallback used by engines that address blocks by top-level index.
 *
 * @param item                  Review item.
 * @param maps                  The maps returned by useReviewData.
 * @param maps.clientIdByTarget
 * @param maps.clientIdByIndex
 * @return Anchor clientId.
 */
export function itemAnchorClientId(
	item: SyncReviewItem,
	{ clientIdByTarget, clientIdByIndex }: ReviewAnchorMaps
): string | undefined {
	if ( item.targetId ) {
		return clientIdByTarget[ item.targetId ];
	}
	if ( undefined !== item.targetIndex ) {
		return clientIdByIndex[ item.targetIndex ];
	}
	return undefined;
}

export interface ReviewData extends ReviewAnchorMaps {
	postType: string | undefined;
	postId: number | undefined;
	items: SyncReviewItem[];
}

/**
 * The current post's sync review state: open review items, and a map from
 * each item's target block identity (syncId) to the block's clientId in the
 * editor, for anchoring conflicts to canvas blocks. Targets whose block no
 * longer exists are absent from the map. `clientIdByIndex` maps top-level
 * block indexes to clientIds for positionally-addressed items
 * (`targetIndex`).
 *
 * @return The review data.
 */
export function useReviewData(): ReviewData {
	const { postType, postId } = useSelect( ( select ) => {
		const { getCurrentPostType, getCurrentPostId } =
			editorSelectors( select );
		return {
			postType: getCurrentPostType(),
			postId: getCurrentPostId(),
		};
	}, [] );
	const items = useSelect(
		( select ) =>
			select( hostStore ).getSyncReviewItems(
				'postType',
				postType ?? '',
				postId ?? null
			),
		[ postType, postId ]
	);
	const clientIdByTarget = useSelect(
		( select ) => {
			// Resolve both on-block conflict targets AND the anchor sibling
			// of parked insertions (so an inline approval card can position
			// itself where the proposed block would land).
			const targetIds = items
				.flatMap( ( item ) => [
					item.targetId,
					item.proposedInsertion?.afterSiblingId,
				] )
				.filter( ( id ): id is string => Boolean( id ) );
			if ( ! targetIds.length ) {
				return EMPTY_CLIENT_IDS;
			}
			const { getClientIdsWithDescendants, getBlockAttributes } =
				blockEditorSelectors( select );
			const wanted = new Set( targetIds );
			const map: Record< string, string > = {};
			for ( const clientId of getClientIdsWithDescendants() ) {
				const syncId = getBlockAttributes( clientId )?.metadata?.syncId;
				if ( syncId && wanted.has( syncId ) ) {
					map[ syncId ] = clientId;
				}
			}
			return map;
		},
		[ items ]
	);
	const clientIdByIndex = useSelect(
		( select ) => {
			const indexes = items
				.map( ( item ) => item.targetIndex )
				.filter( ( index ): index is number => undefined !== index );
			if ( ! indexes.length ) {
				return EMPTY_CLIENT_IDS;
			}
			const order = blockEditorSelectors( select ).getBlockOrder();
			const map: Record< number, string > = {};
			for ( const index of indexes ) {
				if ( order[ index ] ) {
					map[ index ] = order[ index ];
				}
			}
			return map;
		},
		[ items ]
	);

	return { postType, postId, items, clientIdByTarget, clientIdByIndex };
}

/**
 * Returns a callback resolving a group of review items: 'restored'
 * re-authors each item's lost content as an ordinary edit, 'dismissed'
 * discards it. Either way the proposals close for every collaborator.
 *
 * @param postType Current post type.
 * @param postId   Current post ID.
 * @return ( items, resolution ) => void.
 */
export function useResolveReviewItems(
	postType: string | undefined,
	postId: number | undefined
): ResolveReviewItems {
	const { resolveSyncProposal, restoreSyncProposal } =
		useDispatch( hostStore );

	return useCallback(
		( groupItems: SyncReviewItem[], resolution: ReviewResolution ) => {
			for ( const item of groupItems ) {
				if ( 'restored' === resolution ) {
					restoreSyncProposal(
						'postType',
						postType ?? '',
						postId ?? null,
						item.id
					);
				} else {
					resolveSyncProposal(
						'postType',
						postType ?? '',
						postId ?? null,
						item.id,
						'dismissed'
					);
				}
			}
		},
		[ postType, postId, resolveSyncProposal, restoreSyncProposal ]
	);
}
