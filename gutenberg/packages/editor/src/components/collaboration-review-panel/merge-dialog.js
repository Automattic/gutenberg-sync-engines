import { useCallback, useEffect, useState } from '@wordpress/element';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	TextareaControl as WCTextareaControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { diffWords } from 'diff';
import { unlock } from '../../lock-unlock';
import { mergeViewGroupItems } from './review-data';

/**
 * Renders word-diff parts, marking additions and removals.
 *
 * @param {Object} props
 * @param {Array}  props.parts diffWords parts.
 */
function DiffText( { parts } ) {
	return parts.map( ( part, index ) => {
		if ( part.added ) {
			return (
				<ins
					key={ index }
					className="editor-collaboration-merge-dialog__added"
				>
					{ part.value }
				</ins>
			);
		}
		if ( part.removed ) {
			return (
				<del
					key={ index }
					className="editor-collaboration-merge-dialog__removed"
				>
					{ part.value }
				</del>
			);
		}
		return <span key={ index }>{ part.value }</span>;
	} );
}

/**
 * One read-only comparison pane.
 *
 * @param {Object} props
 * @param {string} props.label Pane heading.
 * @param {Array}  props.parts diffWords parts to render.
 */
function Pane( { label, parts } ) {
	return (
		<div className="editor-collaboration-merge-dialog__pane">
			<h3 className="editor-collaboration-merge-dialog__pane-label">
				{ label }
			</h3>
			<div className="editor-collaboration-merge-dialog__pane-content">
				<DiffText parts={ parts } />
			</div>
		</div>
	);
}

/**
 * The merge dialog's content: comparison panes for the author's intended
 * text and the current text (against the base text when it is known), the
 * three resolution verbs, and an optional hand-merge editor.
 *
 * Pure and position-independent so it can be unit-tested without the
 * modal or the data stores. Pane contents come frozen from the caller;
 * the caller re-renders with refreshed contents when a confirm attempt
 * detects the document changed underneath the dialog (`isStale`).
 *
 * @param {Object}   props
 * @param {Object}   props.description   The group description (see the
 *                                       sync package's
 *                                       SyncReviewGroupDescription).
 * @param {boolean}  props.isStale       Whether the panes were just
 *                                       refreshed after a stale confirm.
 * @param {Function} props.onKeepCurrent Keep the current version.
 * @param {Function} props.onRestoreMine Restore the author's version.
 * @param {Function} props.onApplyMerged ( mergedContent ) => void.
 */
export function MergeDialogBody( {
	description,
	isStale,
	onKeepCurrent,
	onRestoreMine,
	onApplyMerged,
} ) {
	const {
		baseText,
		proposedText,
		proposedHtml,
		currentText,
		currentHtml,
		runs,
	} = description;
	const mergedSeed = currentHtml ?? currentText;
	const [ merged, setMerged ] = useState( mergedSeed );
	const [ isEditingMerged, setIsEditingMerged ] = useState( false );

	// A pane refresh re-seeds the merge editor only while the user has not
	// started editing; a hand-merge in progress is never thrown away.
	useEffect( () => {
		if ( ! isEditingMerged ) {
			setMerged( mergedSeed );
		}
	}, [ mergedSeed, isEditingMerged ] );

	const canRestore = null !== proposedText || undefined !== proposedHtml;

	let panes;
	if ( null !== baseText && null !== proposedText ) {
		// Three texts known: compare each side against the base.
		panes = (
			<div className="editor-collaboration-merge-dialog__panes">
				<Pane
					label={ __( 'Your version' ) }
					parts={ diffWords( baseText, proposedText ) }
				/>
				<Pane
					label={ __( 'Current version' ) }
					parts={ diffWords( baseText, currentText ) }
				/>
			</div>
		);
	} else if ( null !== proposedText ) {
		// No base: mark what differs between the two versions directly.
		const parts = diffWords( currentText, proposedText );
		panes = (
			<div className="editor-collaboration-merge-dialog__panes">
				<Pane
					label={ __( 'Your version' ) }
					parts={ parts.filter( ( part ) => ! part.removed ) }
				/>
				<Pane
					label={ __( 'Current version' ) }
					parts={ parts.filter( ( part ) => ! part.added ) }
				/>
			</div>
		);
	} else {
		// The intended text could not be reconstructed: list the set-aside
		// changes next to the current text.
		panes = (
			<div className="editor-collaboration-merge-dialog__panes">
				<div className="editor-collaboration-merge-dialog__pane">
					<h3 className="editor-collaboration-merge-dialog__pane-label">
						{ __( 'Your set-aside changes' ) }
					</h3>
					<ul className="editor-collaboration-merge-dialog__runs">
						{ ( runs ?? [] ).map( ( run, index ) => (
							<li key={ index }>
								{ 'insert' === run.kind
									? __( 'Added:' )
									: __( 'Removed:' ) }{ ' ' }
								<q>{ run.text }</q>
							</li>
						) ) }
					</ul>
				</div>
				<Pane
					label={ __( 'Current version' ) }
					parts={ [ { value: currentText } ] }
				/>
			</div>
		);
	}

	return (
		<div className="editor-collaboration-merge-dialog__body">
			{ isStale && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The document changed while this dialog was open. The panes were refreshed; please confirm again.'
					) }
				</Notice>
			) }
			<p className="editor-collaboration-merge-dialog__description">
				{ __(
					'Part of your typing could not be merged automatically and was set aside. Compare the versions and choose what to keep.'
				) }
			</p>
			{ panes }
			{ isEditingMerged && (
				<WCTextareaControl
					label={ __( 'Merged result' ) }
					help={ __(
						'Edit the text to combine both versions. Applying it replaces the conflicted content and any text formatting in it.'
					) }
					value={ merged }
					onChange={ setMerged }
					rows={ 6 }
				/>
			) }
			<div className="editor-collaboration-merge-dialog__actions">
				{ ! isEditingMerged && (
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => setIsEditingMerged( true ) }
					>
						{ __( 'Edit a merged result' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ onKeepCurrent }
				>
					{ __( 'Keep current version' ) }
				</Button>
				{ isEditingMerged ? (
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => onApplyMerged( merged ) }
					>
						{ __( 'Apply merged result' ) }
					</Button>
				) : (
					canRestore && (
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ onRestoreMine }
						>
							{ __( 'Restore my version' ) }
						</Button>
					)
				) }
			</div>
		</div>
	);
}

/**
 * The collaboration merge dialog: one modal for a whole conflicted field
 * (or block), opened from the inline pending-edit card, the sidebar
 * conflict panel, or an escalation notice. Pane contents FREEZE when the
 * dialog opens; a confirm attempt against a document that changed
 * meanwhile refreshes the panes and asks again instead of authoring
 * against stale text.
 */
export default function CollaborationMergeDialog() {
	const registry = useRegistry();
	const request = useSelect(
		( select ) => unlock( select( coreStore ) ).getSyncReviewMergeRequest(),
		[]
	);
	const {
		describeSyncReviewGroup,
		resolveSyncReviewGroup,
		closeSyncReviewMerge,
	} = unlock( useDispatch( coreStore ) );
	const [ snapshot, setSnapshot ] = useState( null );
	const [ isStale, setIsStale ] = useState( false );

	// The live group and its description, read imperatively so the open
	// snapshot freezes and a confirm can re-check against fresh state.
	// Async because dispatching the describe thunk resolves to its value.
	const readLiveGroup = useCallback( async () => {
		if ( ! request ) {
			return null;
		}
		const items = unlock( registry.select( coreStore ) ).getSyncReviewItems(
			request.kind,
			request.name,
			request.recordId
		);
		const group = mergeViewGroupItems( items, request.itemIds );
		if ( ! group.length ) {
			return null;
		}
		const ids = group.map( ( item ) => item.id );
		const description = await describeSyncReviewGroup(
			request.kind,
			request.name,
			request.recordId,
			ids
		);
		if ( ! description ) {
			return null;
		}
		return { ids, description };
	}, [ request, registry, describeSyncReviewGroup ] );

	useEffect( () => {
		if ( ! request ) {
			setSnapshot( null );
			setIsStale( false );
			return;
		}
		let cancelled = false;
		void readLiveGroup().then( ( opened ) => {
			if ( cancelled ) {
				return;
			}
			if ( ! opened ) {
				closeSyncReviewMerge();
				return;
			}
			setSnapshot( opened );
			setIsStale( false );
		} );
		return () => {
			cancelled = true;
		};
		// The snapshot freezes at open; only a new request re-reads it.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ request ] );

	if ( ! request || ! snapshot ) {
		return null;
	}

	const resolve = async ( resolution, mergedContent ) => {
		const live = await readLiveGroup();
		if ( ! live ) {
			// Everything in the group was resolved elsewhere meanwhile.
			closeSyncReviewMerge();
			return;
		}
		// Authoring against text the user has not seen is the one
		// unacceptable outcome: when the document (or the group) changed
		// while the dialog was open, refresh and re-ask. This gates every
		// verb — keep-current can author too (it removes the changeset's
		// already-merged fragment so the field matches its pane).
		const changed =
			live.description.currentText !== snapshot.description.currentText ||
			( live.description.currentHtml ?? null ) !==
				( snapshot.description.currentHtml ?? null ) ||
			live.ids.join( ',' ) !== snapshot.ids.join( ',' );
		if ( changed ) {
			setSnapshot( live );
			setIsStale( true );
			return;
		}
		resolveSyncReviewGroup(
			request.kind,
			request.name,
			request.recordId,
			live.ids,
			resolution,
			mergedContent
		);
		closeSyncReviewMerge();
	};

	return (
		<Modal
			title={ __( 'Review conflicting edits' ) }
			onRequestClose={ closeSyncReviewMerge }
			className="editor-collaboration-merge-dialog"
			size="large"
		>
			<MergeDialogBody
				description={ snapshot.description }
				isStale={ isStale }
				onKeepCurrent={ () => resolve( 'dismissed' ) }
				onRestoreMine={ () => resolve( 'restored' ) }
				onApplyMerged={ ( mergedContent ) =>
					resolve( 'restored', mergedContent )
				}
			/>
		</Modal>
	);
}
