/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ReviewGroup from './review-group';
import {
	groupByUnit,
	itemAnchorClientId,
	useReviewData,
	useResolveReviewItems,
} from './review-data';
import { blockEditorActions } from '../stores';
import './style.scss';

/**
 * A summary-only index of edits that were set aside for review after a
 * sync conflict (open proposals). Resolution happens at the inline block
 * card in the canvas: an anchored conflict here is a link that navigates
 * to its block. Only conflicts whose block no longer exists carry their
 * Adopt/Reject verbs in the panel, since they have no card to resolve at.
 *
 * Rendered inside the plugin's document settings panel, so this is the
 * panel's body only.
 */
export default function CollaborationReviewPanel() {
	const { postType, postId, items, clientIdByTarget, clientIdByIndex } =
		useReviewData();
	const onResolve = useResolveReviewItems( postType, postId );
	const { selectBlock, flashBlock } = blockEditorActions( useDispatch );

	if ( ! items.length ) {
		return (
			<p className="editor-collaboration-review-panel__description">
				{ __( 'No edits are waiting for review.' ) }
			</p>
		);
	}

	const groups = groupByUnit( items );

	return (
		<div className="editor-collaboration-review-panel__body">
			<p className="editor-collaboration-review-panel__description">
				{ sprintf(
					/* translators: %d: number of conflicting edits awaiting review. */
					_n(
						'%d edit conflicted with a collaborator’s changes and was set aside. Review it at its block.',
						'%d edits conflicted with collaborators’ changes and were set aside. Review them at their blocks.',
						items.length
					),
					items.length
				) }
			</p>
			{ groups.map( ( groupItems ) => {
				const clientId = itemAnchorClientId( groupItems[ 0 ], {
					clientIdByTarget,
					clientIdByIndex,
				} );
				return (
					<ReviewGroup
						key={ groupItems[ 0 ].unitId }
						items={ groupItems }
						onResolve={ onResolve }
						summaryOnly={ !! clientId }
						onNavigate={
							clientId
								? () => {
										// Selection scrolls the canvas to
										// the block; the flash points at it.
										selectBlock( clientId );
										flashBlock( clientId, 500 );
								  }
								: undefined
						}
					/>
				);
			} ) }
		</div>
	);
}
