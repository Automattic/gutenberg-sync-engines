import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import { diffWordsWithSpace } from 'diff';
import DiffText from './diff-text';

/**
 * The dialog's content for reviewing a block held for security approval.
 *
 * A NEW-block proposal shows one "Proposed block" pane. An UPDATE shows
 * "Original" and "Proposed block" side by side as a split diff: one word
 * diff from the original to the proposal feeds both panes, the original
 * keeping the removals and the proposal keeping the additions. Either
 * shape offers Approve, Remove block, and an Edit toggle opening the
 * proposed markup for plain-text editing below; the panes re-diff live
 * while editing, and Approve hands back the (possibly edited) markup.
 *
 * All held markup renders as inert text, never live DOM. The point of the
 * approval gate is that this markup has not been trusted.
 *
 * Position-independent so it can be unit-tested without the modal.
 *
 * @param {Object}   props
 * @param {Object}   props.sequestration The held scenario (see mock-kses).
 * @param {Function} props.onApprove     ( proposedHtml ) => void.
 * @param {Function} props.onRemove      Remove the held block.
 */
export function KsesReviewDialogBody( { sequestration, onApprove, onRemove } ) {
	const [ proposedHtml, setProposedHtml ] = useState(
		sequestration.proposed
	);
	const [ isEditing, setIsEditing ] = useState( false );
	const isUpdate = 'update' === sequestration.kind;

	let originalParts = null;
	let proposedParts = null;

	if ( isUpdate ) {
		const parts = diffWordsWithSpace(
			sequestration.original,
			proposedHtml
		);
		originalParts = parts.filter( ( part ) => ! part.added );
		proposedParts = parts.filter( ( part ) => ! part.removed );
	}

	return (
		<div className="editor-collaboration-kses-dialog__body">
			<p className="editor-collaboration-kses-dialog__description">
				{ isUpdate
					? __(
							'This edit contains content that needs approval from someone allowed to publish unfiltered HTML.'
					  )
					: __(
							'This proposed block contains content that needs approval from someone allowed to publish unfiltered HTML.'
					  ) }
			</p>
			{ isUpdate ? (
				<div className="editor-collaboration-kses-dialog__panes">
					<div className="editor-collaboration-kses-dialog__pane">
						<h3 className="editor-collaboration-kses-dialog__pane-label">
							{ __( 'Original' ) }
						</h3>
						<pre className="editor-collaboration-kses-dialog__pane-content">
							<DiffText parts={ originalParts } />
						</pre>
					</div>
					<div className="editor-collaboration-kses-dialog__pane">
						<h3 className="editor-collaboration-kses-dialog__pane-label">
							{ __( 'Proposed block' ) }
						</h3>
						<pre className="editor-collaboration-kses-dialog__pane-content">
							<DiffText parts={ proposedParts } />
						</pre>
					</div>
				</div>
			) : (
				<div className="editor-collaboration-kses-dialog__pane">
					<h3 className="editor-collaboration-kses-dialog__pane-label">
						{ __( 'Proposed block' ) }
					</h3>
					<pre className="editor-collaboration-kses-dialog__pane-content">
						{ proposedHtml }
					</pre>
				</div>
			) }
			{ isEditing && (
				<div className="editor-collaboration-kses-dialog__editor">
					<h3 className="editor-collaboration-kses-dialog__pane-label">
						{ __( 'Edit proposed block' ) }
					</h3>
					<textarea
						className="editor-collaboration-kses-dialog__editor-textarea"
						aria-label={ __( 'Proposed block HTML' ) }
						rows={ 6 }
						value={ proposedHtml }
						onChange={ ( event ) =>
							setProposedHtml( event.target.value )
						}
					/>
				</div>
			) }
			<div className="editor-collaboration-kses-dialog__actions">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isPressed={ isEditing }
					onClick={ () => setIsEditing( ! isEditing ) }
				>
					{ __( 'Edit' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					isDestructive
					onClick={ onRemove }
				>
					{ __( 'Remove block' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => onApprove( proposedHtml ) }
				>
					{ __( 'Approve' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * The security review dialog, opened from a held block's "Review changes"
 * card. PROTOTYPE: the reviewed contents are the fabricated mock scenario
 * carried by the card, not the block's real held markup.
 *
 * @param {Object}   props
 * @param {Object}   props.sequestration The held scenario (see mock-kses).
 * @param {Function} props.onApprove     ( proposedHtml ) => void.
 * @param {Function} props.onRemove      Remove the held block.
 * @param {Function} props.onClose       Close without resolving.
 */
export default function KsesReviewDialog( {
	sequestration,
	onApprove,
	onRemove,
	onClose,
} ) {
	return (
		<Modal
			title={ __( 'Review proposed changes' ) }
			onRequestClose={ onClose }
			className="editor-collaboration-kses-dialog"
			size="large"
		>
			<KsesReviewDialogBody
				sequestration={ sequestration }
				onApprove={ onApprove }
				onRemove={ onRemove }
			/>
		</Modal>
	);
}
