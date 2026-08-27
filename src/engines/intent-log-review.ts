/**
 * Internal dependencies
 */
import { getBlock } from './intent-log/document.js';
import { applyDerivedIntents, textDelta } from './intent-log-bridge';
import type {
	EngineDocument,
	EngineField,
	IntentEnvelope,
} from './intent-log/engine-types';
import type { IntentLogProposal } from './intent-log-session';

/*
 * The merge view's intent-log supply: which parked proposals the dialog
 * can serve, how they group, and how the three texts (base, intended,
 * current) are reconstructed from the retained log. Pure functions; the
 * manager wires them to its sessions.
 */

/**
 * Escalation reasons whose parked TEXT edits the merge view presents. The
 * excluded reasons are excluded deliberately: kses approvals have
 * different capability semantics, and a deleted/absorbed target block
 * leaves no field to open a dialog on.
 */
const MERGE_REVIEW_REASONS = new Set( [
	'frame-conflict',
	'dependent-on-escalated',
	'concurrent-insert-in-range',
	'position-in-deleted-range',
	'concurrent-replace-overlap',
	'content-replaced',
	'range-crosses-split',
] );

/**
 * Intent types that express text edits on one field. `format_text` is
 * included: it can park inside an escalated unit and contributes format
 * spans to the replayed intended field.
 */
const MERGE_REVIEW_TEXT_TYPES = new Set( [
	'insert_text',
	'delete_text',
	'replace_text',
	'format_text',
	'replace_attr_content',
] );

/**
 * Whether a parked proposal is the SHAPE the merge view understands (a
 * text edit on an existing block field, or a property-register loss),
 * regardless of author. Any-author candidates group for combined DISPLAY
 * (one summary per changeset on cards, panel, and notices); the dialog
 * itself stays scoped to the local author's items.
 *
 * @param proposal Parked proposal.
 * @return Whether the proposal has a merge-viewable shape.
 */
export function isMergeReviewCandidate( proposal: IntentLogProposal ): boolean {
	const { type, payload } = proposal.intent;
	if ( 'set_property' === type ) {
		return 'property-conflict' === proposal.reason;
	}
	return (
		MERGE_REVIEW_TEXT_TYPES.has( type ) &&
		MERGE_REVIEW_REASONS.has( proposal.reason ) &&
		'string' === typeof payload.syncId &&
		'string' === typeof payload.field
	);
}

/**
 * Whether the merge view can serve a parked proposal: the author's own
 * text edit on an existing block field, or their own property-register
 * loss (the two-pane property variant). Everything else keeps its card.
 *
 * @param proposal    Parked proposal.
 * @param selfActorId The session's own actor id (v1 scopes the dialog to
 *                    local items).
 * @return Whether the proposal is merge-viewable.
 */
export function isMergeReviewProposal(
	proposal: IntentLogProposal,
	selfActorId: string
): boolean {
	return (
		proposal.actorId === selfActorId && isMergeReviewCandidate( proposal )
	);
}

/**
 * The merge-view grouping key of a proposal: one dialog per author per
 * field (property registers group per name). Grouping is forced by the
 * resolution shape: a whole-field rewrite settles all of an author's
 * open items on that field together.
 *
 * @param proposal Parked proposal.
 * @return Group key.
 */
export function mergeReviewGroupKey( proposal: IntentLogProposal ): string {
	const { type, payload } = proposal.intent;
	if ( 'set_property' === type ) {
		return `${ proposal.actorId }::prop::${ payload.name as string }`;
	}
	return `${ proposal.actorId }::${ payload.syncId as string }::${
		payload.field as string
	}`;
}

/**
 * One coalesced display run: a stretch of text the author added or
 * removed, merged from subsequent intents whose ranges touch or overlap.
 */
export interface MergeReviewRun {
	kind: 'insert' | 'delete';
	text: string;
}

/**
 * Coalesces a group's parked intents into display runs, so a burst like
 * " 123" reads as one change instead of one run per keystroke. Only the
 * LAST run is a merge candidate (bursts are sequential); an intent that
 * does not touch it starts a new run. Runs are display-only; they never
 * split the resolution.
 *
 * @param intents The group's parked intents, in authored order.
 * @return Display runs.
 */
export function coalesceRuns( intents: IntentEnvelope[] ): MergeReviewRun[] {
	interface OpenRun extends MergeReviewRun {
		start: number;
		end: number;
	}
	const runs: OpenRun[] = [];
	const last = (): OpenRun | undefined => runs[ runs.length - 1 ];

	const addInsert = ( offset: number, text: string ) => {
		const run = last();
		if (
			run &&
			'insert' === run.kind &&
			offset >= run.start &&
			offset <= run.end
		) {
			const at = offset - run.start;
			run.text = run.text.slice( 0, at ) + text + run.text.slice( at );
			run.end += text.length;
			return;
		}
		runs.push( {
			kind: 'insert',
			text,
			start: offset,
			end: offset + text.length,
		} );
	};

	const addDelete = ( start: number, end: number, removedText: string ) => {
		const run = last();
		// Two shapes touch: forward deletes at the run's start, and
		// backspace runs walking backwards onto the run's start.
		if (
			run &&
			'delete' === run.kind &&
			( ( start >= run.start && start <= run.end ) || end === run.start )
		) {
			if ( end === run.start ) {
				run.text = removedText + run.text;
				run.start = start;
			} else {
				run.text += removedText;
				run.end += removedText.length;
			}
			return;
		}
		runs.push( {
			kind: 'delete',
			text: removedText,
			start,
			end: start + removedText.length,
		} );
	};

	for ( const intent of intents ) {
		const payload = intent.payload;
		switch ( intent.type ) {
			case 'insert_text':
				addInsert( payload.offset as number, payload.text as string );
				break;
			case 'delete_text':
				addDelete(
					payload.start as number,
					payload.end as number,
					( payload.removedText as string ) ?? ''
				);
				break;
			case 'replace_text':
				addDelete(
					payload.start as number,
					payload.end as number,
					( payload.removedText as string ) ?? ''
				);
				addInsert( payload.start as number, payload.text as string );
				break;
			case 'replace_attr_content':
				runs.push( {
					kind: 'insert',
					text: payload.newText as string,
					start: 0,
					end: ( payload.newText as string ).length,
				} );
				break;
			default:
				// format_text and anything structural carry no run text.
				break;
		}
	}

	return runs
		.filter( ( run ) => run.text.length > 0 )
		.map( ( { kind, text } ) => ( { kind, text } ) );
}

/**
 * An own row participating in the intended-field replay, with its
 * ordering key.
 */
interface ReplayRow {
	/** Session authoring order when known, else a log-position fallback. */
	order: number;
	intent: IntentEnvelope;
}

/**
 * Replays the author's intended field: the base document plus the
 * author's OWN rows (accepted rows from the retained log, plus the
 * unapplied ones, the parked payloads and any still-pending outbox tail),
 * in authored order. Offsets are valid in this frame because that is the
 * frame they were authored in (base plus own prior edits), and the
 * reducer's clamping absorbs the transformed offsets accepted rows
 * carry. The current document is never involved.
 *
 * @param baseDoc     Document at the group's smallest baseSeq.
 * @param acceptedOwn Own accepted rows at or after that seq, log order.
 * @param unapplied   The group's parked intents (arrival order), plus any
 *                    pending own outbox intents.
 * @param orderOf     Session authoring order lookup (null = unknown).
 * @param targetId    The conflicted block.
 * @param field       The conflicted field.
 * @return The intended field, or null when the block is not in the replay
 *         (deleted in the author's own timeline).
 */
export function replayIntendedField(
	baseDoc: EngineDocument,
	acceptedOwn: Array< { seq: number; entry: IntentEnvelope } >,
	unapplied: IntentEnvelope[],
	orderOf: ( intentId: string ) => number | null,
	targetId: string,
	field: string
): EngineField | null {
	/*
	 * Authored order is the session's own counter when known. Accepted own
	 * rows from before this session (a reload) have no counter; they sort
	 * ahead by log position; they ARE older than anything this session
	 * authored.
	 */
	const rows: ReplayRow[] = [];
	for ( const { seq, entry } of acceptedOwn ) {
		rows.push( {
			order: orderOf( entry.intentId ) ?? seq - Number.MAX_SAFE_INTEGER,
			intent: entry,
		} );
	}
	for ( let i = 0; i < unapplied.length; i++ ) {
		const order = orderOf( unapplied[ i ].intentId );
		rows.push( {
			// An unapplied payload with no counter (reload) keeps arrival
			// order at the tail.
			order: order ?? Number.MAX_SAFE_INTEGER - unapplied.length + i,
			intent: unapplied[ i ],
		} );
	}
	rows.sort( ( a, b ) => a.order - b.order );

	const replayed = applyDerivedIntents(
		baseDoc,
		rows.map( ( row ) => ( {
			type: row.intent.type,
			payload: row.intent.payload,
		} ) )
	);
	const block = getBlock( replayed, targetId );
	if ( ! block ) {
		return null;
	}
	const fieldValue = block.fields[ field ];
	return {
		text: fieldValue?.text ?? '',
		formats: fieldValue?.formats ?? [],
	};
}

/**
 * The whole changeset's display runs, from the base text and the
 * replayed intended text: what the author added and removed on this
 * field, as one combined change. Unlike per-parked-intent coalescing,
 * this covers the ENTIRE changeset, including the fragment of a burst
 * that already merged, so a burst whose first keystroke landed still
 * reads as its full text ("abc ", not "bc "). Prefix/suffix diffing
 * lumps multi-site edits into one run; acceptable for display.
 *
 * @param baseText The field as the author saw it.
 * @param mineText The field as the author intended it.
 * @return Display runs (empty when the texts match).
 */
export function changesetRuns(
	baseText: string,
	mineText: string
): MergeReviewRun[] {
	const { removed, inserted } = textDelta( baseText, mineText );
	const runs: MergeReviewRun[] = [];
	if ( removed.length > 0 ) {
		runs.push( { kind: 'delete', text: removed } );
	}
	if ( inserted.length > 0 ) {
		runs.push( { kind: 'insert', text: inserted } );
	}
	return runs;
}

/**
 * A candidate "intended text" from a fallback source (the observed
 * baseline document or the last editor tree) is only trusted when it
 * CONTAINS every non-empty parked insertion. The point of the pane is
 * showing the author their lost text, and a candidate without it is some
 * other state (for example the already-converged merged view).
 *
 * @param candidateText Candidate field text.
 * @param parked        The group's parked intents.
 * @return Whether the candidate plausibly is the intended text.
 */
export function candidateHoldsParkedText(
	candidateText: string,
	parked: IntentEnvelope[]
): boolean {
	return coalesceRuns( parked )
		.filter( ( run ) => 'insert' === run.kind )
		.every( ( run ) => candidateText.includes( run.text ) );
}
