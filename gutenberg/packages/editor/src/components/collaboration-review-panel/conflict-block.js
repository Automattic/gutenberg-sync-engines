import { useMemo, useState } from '@wordpress/element';
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
import CollaborationTableMergeDialog from './table-merge-dialog';
import TableDiffGrid from './table-diff-grid';
import { mergeTableGrids } from './merge-table-grids';
import { MOCK_TABLE_CONFLICT } from './mock-table-conflict';
import { itemsTargetingBlock, useResolveReviewItems } from './review-data';

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
	.editor-collaboration-table-diff__wrapper {
		overflow-x: auto;
		width: 100%;
	}
	.editor-collaboration-table-diff {
		border-collapse: collapse;
		width: 100%;
	}
	.editor-collaboration-table-diff__cell {
		border: 1px solid #ccc;
		padding: 4px 8px;
		text-align: left;
		vertical-align: top;
	}
	.editor-collaboration-table-diff--compact .editor-collaboration-table-diff__cell {
		font-size: 12px;
		padding: 2px 6px;
	}
	.editor-collaboration-table-diff__cell--added,
	.editor-collaboration-table-diff__cell--changed {
		background: rgba(74, 184, 102, 0.25);
	}
	.editor-collaboration-table-diff__cell--contested {
		background: rgba(240, 184, 73, 0.4);
	}
`;

/**
 * The open CONFLICT review items targeting a block; empty for an
 * unconflicted block (see itemsTargetingBlock for the matching rules).
 * Items held for security approval (`requires-approval`) are excluded:
 * those present as the sequestered-block card instead (see the
 * collaboration-sequestered-block editor hook).
 *
 * @param {string} clientId The block's client id.
 * @return {Array} The block's open conflict review items.
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

			const matches = itemsTargetingBlock(
				select,
				items,
				clientId
			).filter( ( item ) => 'requires-approval' !== item.reason );

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
 * action, and, below them, a preview of the conflict. For most blocks the
 * preview is the word diff with add/remove highlighting; for a table
 * block it is a compact table showing both sides' changes, contested
 * cells marked. PROTOTYPE: the preview shows the fabricated mock
 * conflict, not the block's real contents.
 *
 * The block-recovery Warning component keeps everything but its actions
 * inside the message paragraph, so the box is rendered directly with the
 * same class names; the canvas's recovery styles apply to it either way,
 * and the preview can sit inside the box as its own full-width row.
 *
 * Position-independent so it can be unit-tested without the block editor.
 *
 * @param {Object}   props
 * @param {string}   props.blockName The conflicted block's name.
 * @param {Function} props.onReview  Open the merge dialog.
 */
export function ConflictBlockBody( { blockName, onReview } ) {
	const isTable = 'core/table' === blockName;
	const tableModel = useMemo( () => {
		if ( ! isTable ) {
			return null;
		}

		return mergeTableGrids(
			MOCK_TABLE_CONFLICT.base,
			MOCK_TABLE_CONFLICT.yours,
			MOCK_TABLE_CONFLICT.current
		);
	}, [ isTable ] );

	let message = __( 'This block has conflicting edits.' );
	let preview = <DiffText parts={ mockConflictParts() } />;
	if ( isTable ) {
		message = __( 'This table has conflicting edits.' );
		preview = <TableDiffGrid model={ tableModel } compact />;
	}

	return (
		<>
			<style>{ CANVAS_CSS }</style>
			<div className="block-editor-warning">
				<div className="block-editor-warning__contents">
					<p className="block-editor-warning__message">{ message }</p>
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
					{ preview }
				</div>
			</div>
		</>
	);
}

/**
 * The in-place replacement for a conflicted block: rendered INSTEAD of the
 * block's edit UI (see the collaboration-conflict-block editor hook), so
 * the content is read-only until the conflict is reviewed. The merge
 * dialog opens from here; its modal renders outside the canvas. A table
 * block opens the table-shaped dialog, every other block the paragraph
 * one.
 *
 * @param {Object} props
 * @param {string} props.clientId  The block's client id.
 * @param {string} props.blockName The block's name.
 * @param {Array}  props.items     The block's open review items.
 */
export default function ConflictBlock( { clientId, blockName, items } ) {
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

	let dialog = null;
	if ( isReviewing && 'core/table' === blockName ) {
		dialog = (
			<CollaborationTableMergeDialog
				onClose={ () => setIsReviewing( false ) }
				onAccept={ ( { head, body } ) => {
					// PROTOTYPE resolution: write the merged table into
					// the block's head and body attributes as an ordinary
					// edit (other attributes like the caption keep their
					// live values) and clear the parked items. Real
					// engine-backed resolution replaces this.
					updateBlockAttributes( clientId, { head, body } );
					onResolve( items, 'dismissed' );
					setIsReviewing( false );
				} }
			/>
		);
	} else if ( isReviewing ) {
		dialog = (
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
		);
	}

	return (
		<div { ...blockProps }>
			<ConflictBlockBody
				blockName={ blockName }
				onReview={ () => setIsReviewing( true ) }
			/>
			{ dialog }
		</div>
	);
}
