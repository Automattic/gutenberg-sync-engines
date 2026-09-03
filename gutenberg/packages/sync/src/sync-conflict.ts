import type { ObjectID, ObjectType } from './types';

/**
 * One parked edit, described for review.
 */
export interface SyncConflict {
	/** The engine's id for the parked edit. */
	id: string;
	/**
	 * Why a person has to decide.
	 *
	 * - `merge`: the engine could not combine the edit with a
	 *   collaborator's.
	 * - `sequestration`: the markup needs approval from someone allowed
	 *   to publish unfiltered HTML.
	 */
	kind: 'merge' | 'sequestration';
	/** The WordPress user id of whoever authored the proposed side. */
	authorId: number;
	/**
	 * The canvas block that shows the card: its syncId, or its top-level
	 * index for engines without durable block identity.
	 */
	blockId?: string;
	index?: number;
	/**
	 * What the author started from. Null when the engine can no longer
	 * recover it; the editor then compares proposed against current.
	 */
	base: string | null;
	/** The author's intended result, with the parked edit applied. */
	proposed: string;
	/** The document as it stands now. */
	current: string;
}

/**
 * The reviewer's decision.
 *
 * - `accept`: replace the conflicted blocks with `content` as an
 *   ordinary edit under the reviewer's account, then close the parked
 *   edit. Empty content removes the blocks.
 * - `dismiss`: close the parked edit and keep the document as it is.
 */
export type SyncConflictDecision =
	| { action: 'accept'; content: string }
	| { action: 'dismiss' };

/**
 * What an engine implements to take part in conflict review. Attach it
 * as the engine's `conflicts` member (see the example above).
 */
export interface SyncConflictSource {
	getOpenConflicts: (
		objectType: ObjectType,
		objectId: ObjectID | null
	) => SyncConflict[];
	/** Called on every change to the open list. Returns an unsubscribe. */
	subscribe: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		listener: () => void
	) => () => void;
	resolveConflict: (
		objectType: ObjectType,
		objectId: ObjectID | null,
		conflictId: string,
		decision: SyncConflictDecision
	) => void;
}

/*
 * The engine-neutral conflict shape.
 *
 * A sync engine that sets edits aside for a person to decide publishes
 * them as a list of SyncConflict records. Each record is one card in the
 * canvas and one review dialog. The editor shows the two sides against
 * the base they started from, lets the reviewer edit a merged result, and
 * hands the decision back to the engine as content.
 *
 * Every side is serialized block content (block comments plus markup),
 * whatever the block type. The editor picks the presentation from the
 * kind and the block name; the engine never says how a conflict should
 * look. The engine owns the replacement: it turns the accepted content
 * into the smallest edit against its own document and closes the parked
 * edits in the same round, so the two can never race each other.
 * 
 * Example registration, from an engine adapter:
 * 
 * ```ts
 * const conflicts: SyncConflictSource = {
 *     getOpenConflicts: ( objectType, objectId ) =>
 *         ledgerFor( objectType, objectId ).open(),
 *     subscribe: ( objectType, objectId, listener ) =>
 *         ledgerFor( objectType, objectId ).onChange( listener ),
 *     resolveConflict: ( objectType, objectId, conflictId, decision ) => {
 *         const ledger = ledgerFor( objectType, objectId );
 *         if ( 'accept' === decision.action ) {
 *             ledger.replaceWith( conflictId, decision.content );
 *         }
 *         ledger.close( conflictId );
 *     },
 * };
 *
 * registerSyncEngine( {
 *     slug: 'my-engine',
 *     protocolVersion: 1,
 *     createEntity,
 *     conflicts,
 * } );
 * ```
 */