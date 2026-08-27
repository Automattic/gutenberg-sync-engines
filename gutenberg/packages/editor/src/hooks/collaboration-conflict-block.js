import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import ConflictBlock, {
	useBlockConflicts,
} from '../components/collaboration-review-panel/conflict-block';

/**
 * Replace the edit UI of a block whose edits were set aside for review
 * with the in-place conflict card, the way block recovery replaces an
 * invalid block. The block's content is read-only until the conflict is
 * reviewed, since its editable UI is not rendered at all.
 *
 * @param {Component} BlockEdit Original component.
 *
 * @return {Component} Wrapped component.
 */
const withConflictReview = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const conflicts = useBlockConflicts( props.clientId );

		if ( ! conflicts.length ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<ConflictBlock clientId={ props.clientId } items={ conflicts } />
		);
	},
	'withConflictReview'
);

addFilter(
	'editor.BlockEdit',
	'core/editor/collaboration-conflict-block',
	withConflictReview
);
