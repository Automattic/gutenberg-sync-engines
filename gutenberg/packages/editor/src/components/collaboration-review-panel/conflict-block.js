import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { store as editorStore } from '../../store';
import { unlock } from '../../lock-unlock';
import DiffText from './diff-text';
import { mockConflictParts } from './mock-conflict';
import CollaborationMergeDialog from './merge-dialog';
import { useResolveReviewItems } from './review-data';

const EMPTY_ITEMS = [];

/*
 * The replacement renders inside the editor canvas, where the admin
 * stylesheet carrying the review panel's styles does not load (the
 * block-recovery Warning styles do), so it brings its own styles for the
 * preview and the diff highlighting.
 */
const CANVAS_CSS = `
	.editor-collaboration-conflict-block__preview {
		background: #f0f0f0;
		border-radius: 2px;
		box-sizing: border-box;
		margin-top: 12px;
		padding: 8px;
		white-space: pre-wrap;
		width: 100%;
		word-break: break-word;
	}
	.editor-collaboration-diff__added {
		background: rgba(74, 184, 102, 0.25);
		text-decoration: none;
	}
	.editor-collaboration-diff__removed {
		background: rgba(204, 24, 24, 0.2);
		text-decoration: line-through;
	}
`;

/**
 * The open review items targeting a block; empty for an unconflicted
 * block. Matches by durable block identity (syncId) first, then by
 * top-level index for positionally addressed engines. Parked insertions
 * are excluded: they propose a block that does not exist yet, so there is
 * nothing to replace (their inline approval card remains).
 *
 * @param {string} clientId The block's client id.
 * @return {Array} The block's open review items.
 */
export function useBlockConflicts( clientId ) {
	return useSelect(
		( select ) => {
			const { getCurrentPostType, getCurrentPostId } =
				select( editorStore );
			const postType = getCurrentPostType();
			const postId = getCurrentPostId();

			if ( ! postType || ! postId ) {
				return EMPTY_ITEMS;
			}

			const items = unlock( select( coreStore ) ).getSyncReviewItems(
				'postType',
				postType,
				postId
			);

			if ( ! items.length ) {
				return EMPTY_ITEMS;
			}

			const { getBlockAttributes, getBlockRootClientId, getBlockIndex } =
				select( blockEditorStore );
			const syncId = getBlockAttributes( clientId )?.metadata?.syncId;
			const isTopLevel = '' === getBlockRootClientId( clientId );
			const index = getBlockIndex( clientId );

			const matches = items.filter( ( item ) => {
				if ( item.proposedInsertion ) {
					return false;
				}

				if ( item.targetId ) {
					return item.targetId === syncId;
				}

				return (
					undefined !== item.targetIndex &&
					isTopLevel &&
					item.targetIndex === index
				);
			} );

			if ( ! matches.length ) {
				return EMPTY_ITEMS;
			}

			return matches;
		},
		[ clientId ]
	);
}

/**
 * The body of the in-place conflict replacement, styled like block
 * recovery: one warning box holding the message, the "Review conflict"
 * action, and, below them, a preview of the conflict with add/remove
 * highlighting. PROTOTYPE: the preview shows the fabricated mock
 * conflict, not the block's real texts.
 *
 * The block-recovery Warning component keeps everything but its actions
 * inside the message paragraph, so the box is rendered directly with the
 * same class names; the canvas's recovery styles apply to it either way,
 * and the preview can sit inside the box as its own full-width row.
 *
 * Position-independent so it can be unit-tested without the block editor.
 *
 * @param {Object}   props
 * @param {Function} props.onReview Open the merge dialog.
 */
export function ConflictBlockBody( { onReview } ) {
	return (
		<>
			<style>{ CANVAS_CSS }</style>
			<div className="block-editor-warning">
				<div className="block-editor-warning__contents">
					<p className="block-editor-warning__message">
						{ __( 'This block has conflicting edits.' ) }
					</p>
					<div className="block-editor-warning__actions">
						<span className="block-editor-warning__action">
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ onReview }
							>
								{ __( 'Review conflict' ) }
							</Button>
						</span>
					</div>
				</div>
				<div className="editor-collaboration-conflict-block__preview">
					<DiffText parts={ mockConflictParts() } />
				</div>
			</div>
		</>
	);
}

/**
 * The in-place replacement for a conflicted block: rendered INSTEAD of the
 * block's edit UI (see the collaboration-conflict-block editor hook), so
 * the content is read-only until the conflict is reviewed. The merge
 * dialog opens from here; its modal renders outside the canvas.
 *
 * @param {Object} props
 * @param {string} props.clientId The block's client id.
 * @param {Array}  props.items    The block's open review items.
 */
export default function ConflictBlock( { clientId, items } ) {
	const blockProps = useBlockProps();
	const { postType, postId } = useSelect( ( select ) => {
		const { getCurrentPostType, getCurrentPostId } = select( editorStore );

		return {
			postType: getCurrentPostType(),
			postId: getCurrentPostId(),
		};
	}, [] );
	const onResolve = useResolveReviewItems( postType, postId );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	const [ isReviewing, setIsReviewing ] = useState( false );

	return (
		<div { ...blockProps }>
			<ConflictBlockBody onReview={ () => setIsReviewing( true ) } />
			{ isReviewing && (
				<CollaborationMergeDialog
					onClose={ () => setIsReviewing( false ) }
					onAccept={ ( mergedText ) => {
						// PROTOTYPE resolution: write the merged text into
						// the block as an ordinary edit (assumes a
						// paragraph-shaped block) and clear the parked
						// items. Real engine-backed resolution replaces
						// this.
						updateBlockAttributes( clientId, {
							content: mergedText,
						} );
						onResolve( items, 'dismissed' );
						setIsReviewing( false );
					} }
				/>
			) }
		</div>
	);
}
