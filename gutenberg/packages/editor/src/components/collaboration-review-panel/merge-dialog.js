import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import { TextareaControl } from '@wordpress/ui';
import { diffWordsWithSpace } from 'diff';
import DiffText from './diff-text';
import { MOCK_CONFLICT } from './mock-conflict';

/**
 * One version pane: a heading, the diff-highlighted text, and a button
 * copying this version into the merged result.
 *
 * @param {Object}   props
 * @param {string}   props.label     Pane heading.
 * @param {Array}    props.parts     diffWords change objects to render.
 * @param {Function} props.onRestore Copy this version into the merged
 *                                   result.
 */
function Pane( { label, parts, onRestore } ) {
	return (
		<div className="editor-collaboration-merge-dialog__pane">
			<h3 className="editor-collaboration-merge-dialog__pane-label">
				{ label }
			</h3>
			<div className="editor-collaboration-merge-dialog__pane-content">
				<DiffText parts={ parts } />
			</div>
			<Button
				__next40pxDefaultSize
				size="compact"
				variant="secondary"
				onClick={ onRestore }
			>
				{ __( 'Restore this version' ) }
			</Button>
		</div>
	);
}

/**
 * The merge dialog's content: your version and the current version side by
 * side, each word-diffed against the SHARED BASE both started from (so
 * each pane highlights only that side's own changes), and each restorable
 * into the editable merged result below them. Accept hands the merged
 * result back; Cancel closes without changing anything.
 *
 * Position-independent so it can be unit-tested without the modal.
 *
 * @param {Object}   props
 * @param {string}   props.baseText    The text both versions started from.
 * @param {string}   props.yourText    The author's version of the text.
 * @param {string}   props.currentText The document's current version.
 * @param {Function} props.onAccept    ( mergedText ) => void.
 * @param {Function} props.onCancel    Close without resolving.
 */
export function MergeDialogBody( {
	baseText,
	yourText,
	currentText,
	onAccept,
	onCancel,
} ) {
	// The merged result starts as the current version. Either pane's
	// "Restore this version" replaces it, and it stays hand-editable.
	const [ merged, setMerged ] = useState( currentText );

	return (
		<div className="editor-collaboration-merge-dialog__body">
			<p className="editor-collaboration-merge-dialog__description">
				{ __(
					'These edits could not be merged automatically. Compare the versions and choose what to keep.'
				) }
			</p>
			<div className="editor-collaboration-merge-dialog__panes">
				<Pane
					label={ __( 'Your version' ) }
					parts={ diffWordsWithSpace( baseText, yourText ) }
					onRestore={ () => setMerged( yourText ) }
				/>
				<Pane
					label={ __( 'Current version' ) }
					parts={ diffWordsWithSpace( baseText, currentText ) }
					onRestore={ () => setMerged( currentText ) }
				/>
			</div>
			<TextareaControl
				label={ __( 'Merged result' ) }
				description={ __(
					'This text replaces the conflicted content when you accept.'
				) }
				value={ merged }
				onValueChange={ setMerged }
				rows={ 4 }
			/>
			<div className="editor-collaboration-merge-dialog__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => onAccept( merged ) }
				>
					{ __( 'Accept' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * The collaboration merge dialog, opened from a conflicted block's
 * "Review conflict" card. PROTOTYPE: the compared versions are the
 * fabricated mock conflict, not the block's real texts; real conflicts
 * open it, but its contents are pre-set while the UI design settles.
 *
 * @param {Object}   props
 * @param {Function} props.onAccept ( mergedText ) => void.
 * @param {Function} props.onClose  Close without resolving.
 */
export default function CollaborationMergeDialog( { onAccept, onClose } ) {
	return (
		<Modal
			title={ __( 'Review conflicting edits' ) }
			onRequestClose={ onClose }
			className="editor-collaboration-merge-dialog"
			size="large"
		>
			<MergeDialogBody
				baseText={ MOCK_CONFLICT.baseText }
				yourText={ MOCK_CONFLICT.yourText }
				currentText={ MOCK_CONFLICT.currentText }
				onAccept={ onAccept }
				onCancel={ onClose }
			/>
		</Modal>
	);
}
