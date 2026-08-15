/**
 * Internal dependencies
 */
/**
 * External dependencies
 */
import type { Awareness } from 'y-protocols/awareness';

/**
 * WordPress dependencies
 */
import { parse as parseBlockDelimiters } from '@wordpress/block-serialization-default-parser';
// eslint-disable-next-line import/no-unresolved -- Provided by the editor runtime.
import { getBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { createAwarenessDoc } from './awareness-sync';
import {
	applyDerivedIntents,
	deriveIntents,
	documentDistance,
	engineDocumentToBlocks,
	summarizeEditorTree,
	type RawContentAdapter,
	type RichTextFieldsResolver,
	type BridgeBlock,
} from './intent-log-bridge';
import {
	createIntentLogSession,
	type IntentLogSession,
} from './intent-log-session';
import { mintSyncId } from './intent-log/sync-id.js';
import { fieldToHtml } from './intent-log/rich-text.js';
import { getProviderCreators } from '../framework';
import type { EngineDocument } from './intent-log/engine-types';
import type {
	ObjectData,
	ObjectID,
	ObjectType,
	ProviderCreator,
	ProviderCreatorResult,
	RecordHandlers,
	SyncConfig,
	SyncManager,
} from '@wordpress/sync';

/*
 * The intent-log SyncManager: the engine adapter surface core-data drives,
 * implemented over an IntentLogSession per entity plus the capture bridge.
 *
 * Where the Yjs manager maintains a CRDT doc and diffs into Y types, this
 * manager keeps the session's engine document as the shared state:
 *
 * - editor → wire: update() derives verified intents from the incoming
 *   block tree (capture bridge) and authors them through the session, which
 *   emits wire updates to the transport;
 * - wire → editor: session change events map the engine document back to
 *   blocks and dispatch editRecord, guarded against echo loops by
 *   canonical-state comparison.
 *
 * THE OBSERVED BASELINE. An editor tree is not testimony about the CURRENT
 * shared document — it is testimony about the document state the editor
 * last saw, plus the user's edits on top. Capture therefore diffs each tree
 * against that observed state and authors the result AT ITS SEQ, leaving
 * the engine's transform to merge it with everything that landed since.
 * Diffing against the head instead is what made a push racing live
 * keystrokes destructive: the tree, still lacking the pushed remote text,
 * read as a deletion of it.
 *
 * Which state the editor last saw is not directly observable (the block
 * editor reports nothing when it applies a push, and a push can be
 * superseded by an in-flight editor change before it ever renders), so the
 * manager keeps candidates: the last CONFIRMED observed state plus every
 * push still unconfirmed. An arriving tree is matched to the candidate it
 * differs from least (documentDistance) — a tree is always one of them plus
 * a small edit. Ties keep the confirmed one: an unconfirmed push must never
 * be the excuse for authoring a destructive diff. A push nobody contradicts
 * within PUSH_OBSERVED_DELAY is confirmed on its own, since a quiet editor
 * has certainly rendered it.
 *
 * Scope (documented in ARCHITECTURE.md):
 * - Blocks sync through the capture bridge; whitelisted entity properties
 *   (SYNCED_PROPERTIES — currently the title) sync as per-name registers
 *   via set_property intents. Other entity properties (status, …) flow
 *   through WordPress saves as usual.
 * - Undo rides core's default WPUndoManager (`undoManager` stays
 *   undefined; core-data falls back automatically). Escalated intents are
 *   surfaced via console warning; the review-lane UI is Phase 2d.
 * - No CRDT-doc persistence/snapshots (server materializes instead), so
 *   createPersistedCRDTDoc/getEntitySnapshot return null/undefined and
 *   entityContainsSnapshot returns false (callers fail open).
 */

/**
 * A document state the editor is (or may be) displaying: the module note's
 * observed baseline, and every unconfirmed push candidate.
 */
interface ObservedState {
	/** The engine document. */
	doc: EngineDocument;
	/** Engine log position it was read at (the seq captures author at). */
	seq: number;
	/** Canonical bridge-block form, for cheap divergence tests. */
	json: string;
}

/**
 * How long a push goes unchallenged before it counts as observed. Only a
 * tree arriving from the editor can contradict it, and the block editor
 * applies a push within a render — so silence for this long means it landed.
 */
const PUSH_OBSERVED_DELAY = 1200;

/**
 * How long a capture-driven editor sync waits for the typing burst to fall
 * quiet (see scheduleEditorSync). Long enough that it does not reset the
 * block store between keystrokes, short enough that identity write-backs
 * land while the user is still on the same paragraph.
 */
const CAPTURE_SYNC_DELAY = 1200;

/**
 * Cap on unconfirmed push candidates. Pushes supersede each other in the
 * editor (only the last value of the controlled block list is rendered), so
 * the recent ones are the only plausible baselines.
 */
const MAX_PENDING_PUSHES = 8;

interface EntityState {
	session: IntentLogSession;
	handlers: RecordHandlers;
	providers: ProviderCreatorResult[];
	unloaded: boolean;
	/** Presence surface for the collaborator UI (see getAwareness). */
	awareness?: Awareness;
	/**
	 * Stable clientId per syncId for blocks pushed to the editor. The
	 * block-editor store keys blocks by clientId; pushing without one makes
	 * the canvas silently drop the tree (debug bundles) or remount blocks
	 * on every push (losing selection). Stability across pushes lets React
	 * reconcile in place.
	 */
	clientIds: Map< string, string >;
	/** Monotonic push counter, guarding stale confirmation timers. */
	pushSeq: number;
	/** Pending capture-driven editor sync (see scheduleEditorSync). */
	syncTimer: ReturnType< typeof setTimeout > | null;
	/** Whether that pending sync must push regardless of divergence. */
	syncForce: boolean;
	/**
	 * The state the editor is understood to display, and the seq captures
	 * are authored at. Null until the room snapshot arrives.
	 */
	observed: ObservedState | null;
	/**
	 * Pushes dispatched but not yet confirmed as displayed (see the module
	 * note): candidate baselines for the next tree, newest last.
	 */
	pendingPushes: ObservedState[];
	/**
	 * Whether update() is currently authoring captured intents. The
	 * session emits change events synchronously per authored intent;
	 * those are the editor's own state and must not bounce back.
	 */
	capturing: boolean;
	/**
	 * Ids the editor has actually displayed (last agreed view). Only these
	 * may be DELETED by a capture diff: a document block missing from the
	 * editor tree but never displayed is staleness, not a deletion.
	 *
	 * Three seeding sources, all proofs of display:
	 * 1. persisted syncIds parsed from the record the editor loaded and
	 *    rendered (covers blocks saved after the room's genesis — a late
	 *    joiner bootstraps from the old genesis or a checkpoint yet has
	 *    displayed the newer saved blocks);
	 * 2. the seq-0 genesis bootstrap document, derived from the same saved
	 *    content the editor parsed (covers legacy content whose ids exist
	 *    only as deterministic genesis mints);
	 * 3. every tree the editor hands to update() — its ongoing testimony.
	 */
	editorIds: Set< string >;
	/**
	 * Whether the bootstrap document has been offered for genesis seeding
	 * (one-shot, on the first post-initialization change event).
	 */
	genesisSeeded: boolean;
	/** Document ids as of the previous session change (tombstone diffing). */
	prevDocIds: Set< string >;
	/**
	 * Ids removed from the document by remote intents. A stale editor tree
	 * still showing them must not resurrect them.
	 */
	docTombstones: Set< string >;
	/**
	 * Last property values pushed to (or captured from) the editor, for
	 * property echo suppression. Seeded from the loaded record so the
	 * genesis snapshot's properties are not re-pushed as edits.
	 */
	lastPushedProps: Record< string, string >;
	/**
	 * Rich-text attribute names per block type (from the entity syncConfig,
	 * backed by the block registry). Names both the fields the bridge
	 * captures and the fields it serializes back into attributes.
	 */
	fieldsResolver: RichTextFieldsResolver;
	rawContent?: RawContentAdapter;
}

/**
 * Entity properties synced as per-name registers (set_property intents).
 * Must be raw strings in both the edited record and the engine document.
 */
const SYNCED_PROPERTIES = [ 'title' ];

/**
 * Reads a synced property from a record or edits object as a raw string.
 * REST records carry title as `{ raw, rendered }`; editor edits carry it as
 * a plain string.
 *
 * @param source Record or edits object.
 * @param name   Property name.
 * @return The raw string value, or undefined.
 */
function rawPropertyValue(
	source: Record< string, unknown >,
	name: string
): string | undefined {
	const value = source[ name ];
	if ( 'string' === typeof value ) {
		return value;
	}
	if (
		value &&
		'object' === typeof value &&
		'string' === typeof ( value as { raw?: unknown } ).raw
	) {
		return ( value as { raw: string } ).raw;
	}
	return undefined;
}

/**
 * Collects the persisted syncIds carried in a record's serialized content.
 *
 * Reads only the block delimiters (grammar-level parse, no block registry),
 * so it sees exactly what the editor parsed and rendered from this content.
 * Legacy content without persisted ids yields an empty set — deterministic
 * genesis seeding covers that shape instead.
 *
 * @param content Raw serialized post content, or undefined.
 * @return The persisted syncIds.
 */
function collectPersistedSyncIds( content: string | undefined ): Set< string > {
	const ids = new Set< string >();
	if ( ! content || ! content.includes( '"syncId"' ) ) {
		return ids;
	}
	type ParsedNode = {
		attrs: { metadata?: { syncId?: unknown } } | null;
		innerBlocks: ParsedNode[];
	};
	const walk = ( nodes: ParsedNode[] ) => {
		for ( const node of nodes ) {
			const syncId = node.attrs?.metadata?.syncId;
			if ( 'string' === typeof syncId ) {
				ids.add( syncId );
			}
			walk( node.innerBlocks );
		}
	};
	walk( parseBlockDelimiters( content ) as ParsedNode[] );
	return ids;
}

/**
 * Collects all syncIds in a bridge block tree (metadata.syncId).
 *
 * @param blocks Bridge blocks.
 * @param into   Accumulator.
 * @return The accumulator.
 */
function collectBlockIds(
	blocks: BridgeBlock[],
	into: Set< string > = new Set()
): Set< string > {
	for ( const block of blocks ) {
		const metadata = block.attributes?.metadata as
			| { syncId?: string }
			| undefined;
		if ( metadata?.syncId ) {
			into.add( metadata.syncId );
		}
		collectBlockIds( block.innerBlocks, into );
	}
	return into;
}

/**
 * Canonical form of a bridge block tree for echo suppression.
 *
 * @param blocks Bridge blocks.
 * @return Canonical JSON.
 */
function canonicalBlocksJson( blocks: BridgeBlock[] ): string {
	return JSON.stringify( blocks );
}

/**
 * A bridge block extended with the editor-required clientId.
 */
type EditorBlock = BridgeBlock & {
	clientId: string;
	isValid: boolean;
	innerBlocks: EditorBlock[];
};

/**
 * Assigns stable clientIds (keyed by syncId) to a bridge block tree so the
 * block-editor store accepts and reconciles it.
 *
 * @param blocks    Bridge blocks (syncId present in metadata).
 * @param clientIds syncId → clientId map (grown as needed).
 * @return Editor-ready blocks.
 */
/**
 * Merges a block type's attribute DEFAULTS under the given attributes.
 *
 * Pushed blocks are built from the engine document, whose attrs mirror the
 * serialized comment JSON — which OMITS default-valued attributes. Most
 * blocks tolerate the gaps, but some save() implementations dereference a
 * defaulted attribute (core/group renders `<TagName>` from `tagName`,
 * default 'div'): with the attribute absent, save() throws, and the
 * serializer silently emits the block as a VOID comment — children and
 * wrapper gone from saved content, which then parses as an invalid
 * recovery block for every future visitor (found by the fuzzer as
 * post-reload invalid groups whenever a genesis-sourced group was saved).
 * createBlock() is deliberately NOT used here: its schema sanitization
 * would drop attributes the block type does not declare, and the push
 * must remain lossless.
 *
 * @param name       Block name.
 * @param attributes Attributes from the engine document.
 * @return Attributes with block-type defaults filled in.
 */
function withBlockDefaults(
	name: string,
	attributes: Record< string, unknown >
): Record< string, unknown > {
	const blockType = getBlockType( name ) as
		| { attributes?: Record< string, { default?: unknown } > }
		| undefined;
	if ( ! blockType?.attributes ) {
		return attributes;
	}
	let merged: Record< string, unknown > | null = null;
	for ( const [ key, schema ] of Object.entries( blockType.attributes ) ) {
		if ( undefined === schema.default || key in attributes ) {
			continue;
		}
		if ( ! merged ) {
			merged = { ...attributes };
		}
		merged[ key ] = schema.default;
	}
	return merged ?? attributes;
}

function toEditorBlocks(
	blocks: BridgeBlock[],
	clientIds: Map< string, string >
): EditorBlock[] {
	return blocks.map( ( block ) => {
		const syncId = ( block.attributes?.metadata as { syncId?: string } )
			?.syncId;
		let clientId = syncId ? clientIds.get( syncId ) : undefined;
		if ( ! clientId ) {
			clientId = globalThis.crypto.randomUUID();
			if ( syncId ) {
				clientIds.set( syncId, clientId );
			}
		}
		return {
			...block,
			attributes: withBlockDefaults(
				block.name,
				( block.attributes ?? {} ) as Record< string, unknown >
			),
			clientId,
			isValid: true,
			innerBlocks: toEditorBlocks( block.innerBlocks, clientIds ),
		};
	} );
}

/**
 * Maps an engine document to the bridge blocks the editor is handed.
 *
 * @param state Entity state.
 * @param doc   Engine document.
 * @return Bridge blocks.
 */
function documentBlocks(
	state: EntityState,
	doc: EngineDocument
): BridgeBlock[] {
	return engineDocumentToBlocks(
		doc,
		state.fieldsResolver,
		state.rawContent
	);
}

/**
 * Collects every block id in an engine document.
 *
 * @param doc  Engine document.
 * @param into Accumulator.
 * @return The accumulator.
 */
function collectDocumentIds(
	doc: EngineDocument,
	into: Set< string > = new Set()
): Set< string > {
	const walk = ( blocks: EngineDocument[ 'root' ] ) => {
		for ( const block of blocks ) {
			into.add( block.syncId );
			walk( block.children );
		}
	};
	walk( doc.root );
	return into;
}

/**
 * Records the state the editor is understood to display, and with it the
 * seq captures are authored at (the session keeps its replica's log
 * sliceable from there).
 *
 * @param state Entity state.
 * @param next  Observed state.
 */
function setObserved( state: EntityState, next: ObservedState ): void {
	// The session clamps the seq into its replica's authorable range (and
	// skips entries this client authored itself); keep the record in step.
	state.session.setObservedSeq( next.seq );
	state.observed = { ...next, seq: state.session.getObservedSeq() };
}

/**
 * Dispatches a document to the editor and files it as an unconfirmed
 * baseline candidate (see the module note).
 *
 * @param state  Entity state.
 * @param next   State being pushed.
 * @param blocks Its bridge blocks.
 */
function pushDocument(
	state: EntityState,
	next: ObservedState,
	blocks: BridgeBlock[]
): void {
	state.pendingPushes.push( next );
	if ( state.pendingPushes.length > MAX_PENDING_PUSHES ) {
		state.pendingPushes.shift();
	}
	state.handlers.editRecord(
		{ blocks: toEditorBlocks( blocks, state.clientIds ) },
		{ undoIgnore: true }
	);
	const token = ++state.pushSeq;
	setTimeout( () => {
		/*
		 * Nothing arrived from the editor since this push, so it rendered:
		 * the block editor reports nothing back for changes it applied
		 * itself, and an editor change would have consumed the pending
		 * candidates. Promoting it also releases the replica's log
		 * retention down to this seq.
		 */
		const latest = state.pendingPushes.at( -1 );
		if ( state.unloaded || token !== state.pushSeq || ! latest ) {
			return;
		}
		state.pendingPushes = [];
		setObserved( state, latest );
	}, PUSH_OBSERVED_DELAY );
}

/**
 * Brings the editor up to the shared document when it is behind it.
 *
 * @param state Entity state.
 * @param force Push even when the document matches what the editor is
 *              believed to show — for the cases where that belief is
 *              knowably incomplete (identity write-backs, retained blocks).
 */
function syncEditor( state: EntityState, force = false ): void {
	const doc = state.session.getDocument();
	if ( ! doc || ! state.observed ) {
		return;
	}
	const blocks = documentBlocks( state, doc );
	const json = canonicalBlocksJson( blocks );
	const shown = state.pendingPushes.at( -1 ) ?? state.observed;
	if ( ! force && json === shown.json ) {
		return;
	}
	pushDocument( state, { doc, seq: state.session.getSeq(), json }, blocks );
}

/**
 * Queues an editor sync for after the capture that asked for it.
 *
 * A push dispatched from INSIDE update() never reaches the editor:
 * core-data hands the sync manager the edits (where this runs) BEFORE it
 * commits them, and every editor edit carries the editor's own block tree —
 * so its commit lands on top of anything we dispatch here. Only a push made
 * outside that call survives, which is why capture-driven syncs (identity
 * write-backs, retained blocks, a merged document the tree is behind on)
 * wait for the burst to fall quiet. Remote-driven pushes are dispatched
 * immediately: they run from a transport callback, with no editor edit
 * following them.
 *
 * @param state Entity state.
 * @param force See syncEditor.
 */
function scheduleEditorSync( state: EntityState, force = false ): void {
	state.syncForce = state.syncForce || force;
	if ( state.syncTimer ) {
		clearTimeout( state.syncTimer );
	}
	state.syncTimer = setTimeout( () => {
		state.syncTimer = null;
		const forced = state.syncForce;
		state.syncForce = false;
		if ( ! state.unloaded ) {
			syncEditor( state, forced );
		}
	}, CAPTURE_SYNC_DELAY );
}

/**
 * Resolves which state an arriving editor tree was authored against, and
 * makes it the observed baseline.
 *
 * Every unconfirmed push is a candidate alongside the confirmed baseline;
 * the tree is that state plus the user's own edit, so the candidate it
 * differs from least is the one it came from. A tie is no evidence that the
 * editor rendered a push, and treating an unrendered push as observed is
 * exactly what turns a lost race into a destructive diff — so ties keep the
 * confirmed baseline. Either way the candidates are consumed: the record now
 * carries the editor's own tree, so any push it did not render is gone.
 *
 * @param state  Entity state.
 * @param blocks The arriving editor tree.
 */
function chooseObservedBaseline(
	state: EntityState,
	blocks: BridgeBlock[]
): void {
	const observed = state.observed;
	// Candidates that carry the same content as the confirmed baseline
	// cannot be told apart from it by any tree, and choosing one would
	// change nothing but the seq — drop them before doing real work (this
	// is the common case: a push the editor was never behind on).
	const pending = state.pendingPushes.filter(
		( candidate ) => candidate.json !== observed?.json
	);
	state.pendingPushes = [];
	// Invalidate the pending confirmation timers: this tree is the answer.
	state.pushSeq++;
	if ( 0 === pending.length || ! observed ) {
		return;
	}
	const options = {
		richTextFields: state.fieldsResolver,
		rawContent: state.rawContent,
	};
	const summary = summarizeEditorTree( blocks, options );
	const relevantIds = new Set< string >();
	for ( const candidate of [ observed, ...pending ] ) {
		collectDocumentIds( candidate.doc, relevantIds );
	}
	let best = observed;
	let bestDistance = documentDistance(
		summary,
		best.doc,
		relevantIds,
		options
	);
	for ( const candidate of pending ) {
		const distance = documentDistance(
			summary,
			candidate.doc,
			relevantIds,
			options
		);
		if ( distance < bestDistance ) {
			best = candidate;
			bestDistance = distance;
		}
	}
	if ( best !== observed ) {
		setObserved( state, best );
	}
}

/**
 * Creates an intent-log sync manager.
 *
 * @param debug Whether to log debug output.
 * @return Sync manager.
 */
export function createIntentLogManager( debug = false ): SyncManager {
	const entityStates = new Map< string, EntityState >();
	const userId =
		Number(
			( window as { _wpCollaborationUserId?: unknown } )
				._wpCollaborationUserId
		) || 0;

	const log = ( message: string, context: object = {} ) => {
		if ( debug ) {
			// eslint-disable-next-line no-console
			console.log( `[IntentLogManager]: ${ message }`, context );
		}
	};

	const entityKey = ( objectType: ObjectType, objectId: ObjectID | null ) =>
		`${ objectType }_${ objectId }`;

	async function loadEntity(
		syncConfig: SyncConfig,
		objectType: ObjectType,
		objectId: ObjectID,
		record: ObjectData,
		handlers: RecordHandlers
	): Promise< void > {
		const key = entityKey( objectType, objectId );
		if ( entityStates.has( key ) ) {
			return;
		}
		if ( false === syncConfig.shouldSync?.( objectType, objectId ) ) {
			return;
		}
		const providerCreators = getProviderCreators();
		if ( 0 === providerCreators.length ) {
			return;
		}

		/*
		 * The presence surface: the entity's syncConfig constructs the typed
		 * Awareness (e.g. PostEditorAwareness with collaborator info and
		 * selection tracking). The y-protocols Awareness base only reads
		 * `clientID` (and a destroy listener) from its doc argument, so a
		 * stub suffices — presence is transport data, engine-independent.
		 */
		const clientId = Math.floor( Math.random() * ( 2 ** 31 - 1 ) ) + 1;
		const awareness = syncConfig.createAwareness?.(
			createAwarenessDoc( clientId ) as never,
			objectId
		);
		const session = createIntentLogSession( {
			userId,
			clientId,
			awareness,
		} );
		/*
		 * Seed property echo suppression from the record the editor loaded:
		 * a genesis snapshot whose properties match what the editor already
		 * shows must not be re-pushed as an edit. A ROOM value that differs
		 * (another client changed the title before we joined) still pushes.
		 */
		const initialProps: Record< string, string > = {};
		for ( const name of SYNCED_PROPERTIES ) {
			const value = rawPropertyValue(
				record as Record< string, unknown >,
				name
			);
			if ( undefined !== value ) {
				initialProps[ name ] = value;
			}
		}

		const state: EntityState = {
			session,
			awareness,
			handlers,
			providers: [],
			unloaded: false,
			observed: null,
			pendingPushes: [],
			capturing: false,
			// Record seeding (source 1 of the editorIds contract): the ids
			// persisted in the content this editor loaded and rendered.
			editorIds: collectPersistedSyncIds(
				rawPropertyValue(
					record as Record< string, unknown >,
					'content'
				)
			),
			genesisSeeded: false,
			prevDocIds: new Set(),
			docTombstones: new Set(),
			clientIds: new Map(),
			pushSeq: 0,
			syncTimer: null,
			syncForce: false,
			lastPushedProps: initialProps,
			fieldsResolver:
				syncConfig.richTextFields ?? ( () => [ 'content' ] ),
			rawContent:
				syncConfig.isRawContentBlock && syncConfig.serializeRawContent
					? {
							is: syncConfig.isRawContentBlock,
							serialize: syncConfig.serializeRawContent,
							hydrate: syncConfig.hydrateRawContent,
					  }
					: undefined,
		};
		entityStates.set( key, state );

		/**
		 * Pushes engine property values the editor has not seen yet.
		 */
		const pushPropertyChanges = () => {
			const doc = session.getDocument();
			if ( ! doc ) {
				return;
			}
			const edits: Record< string, string > = {};
			for ( const name of SYNCED_PROPERTIES ) {
				const value = doc.props?.[ name ];
				if ( 'string' !== typeof value ) {
					continue;
				}
				if ( state.lastPushedProps[ name ] === value ) {
					continue;
				}
				state.lastPushedProps[ name ] = value;
				edits[ name ] = value;
			}
			if ( Object.keys( edits ).length > 0 ) {
				handlers.editRecord( edits, { undoIgnore: true } );
			}
		};

		session.onChange( () => {
			if ( state.unloaded || ! session.isInitialized() ) {
				return;
			}
			const doc = session.getDocument()!;
			const blocks = documentBlocks( state, doc );
			const docIds = collectBlockIds( blocks );
			/*
			 * Genesis seeding (source 2 of the editorIds contract): a seq-0
			 * bootstrap is the room GENESIS, derived from the saved post
			 * content this editor itself parsed and rendered — its blocks
			 * are provably displayed, so they are removable immediately.
			 * Without this, a WHOLESALE first edit (select-all paste)
			 * captures a tree that never contained the genesis blocks, their
			 * absence reads as staleness, and the dropped deletions resurrect
			 * on every client. Checkpoint bootstraps (seq > 0) may carry
			 * blocks a late joiner never displayed and must NOT seed (record
			 * seeding covers the ids that joiner did render). The first
			 * post-init change event fires synchronously on snapshot receipt,
			 * so `blocks` here is exactly the bootstrap document.
			 */
			if ( ! state.genesisSeeded ) {
				state.genesisSeeded = true;
				if ( 0 === session.getBootstrapSeq() ) {
					for ( const id of docIds ) {
						state.editorIds.add( id );
					}
				}
			}
			/*
			 * Tombstone maintenance: ids that left the document through
			 * REMOTE intents (own authorship is under the capturing guard)
			 * must not be resurrected by a stale editor tree. Reappearing
			 * ids clear their tombstone.
			 */
			if ( ! state.capturing ) {
				for ( const id of state.prevDocIds ) {
					if ( ! docIds.has( id ) ) {
						state.docTombstones.add( id );
					}
				}
			}
			for ( const id of docIds ) {
				state.docTombstones.delete( id );
			}
			state.prevDocIds = docIds;

			if ( state.capturing ) {
				return;
			}
			// Entity properties push independently of the block logic below
			// (its early returns must not swallow a title change).
			pushPropertyChanges();
			if ( ! state.observed ) {
				/*
				 * Bootstrap. The snapshot is the best account of what the
				 * editor displays: the genesis is derived from the saved
				 * content this editor itself parsed and rendered, and a
				 * compaction checkpoint is the nearest approximation (the
				 * editorIds retention guard covers the difference).
				 */
				const bootstrap = {
					doc,
					seq: session.getBootstrapSeq() ?? 0,
					json: canonicalBlocksJson( blocks ),
				};
				setObserved( state, bootstrap );
				/*
				 * Never push an EMPTY shared document over a live editor as
				 * the first push (fresh post: the genesis is empty while the
				 * user may already be typing). The first capture seeds the
				 * document instead.
				 */
				if ( 0 === blocks.length ) {
					return;
				}
				pushDocument( state, bootstrap, blocks );
				return;
			}
			/*
			 * Deliberately NOT marking the pushed ids as editor-displayed:
			 * a push only proves we dispatched, not that the editor
			 * rendered it. Ids become removable when the editor itself
			 * hands us a tree containing them (its echo of this push).
			 */
			syncEditor( state );
		} );

		session.onReset( () => {
			/*
			 * Horizon reset: the replica re-bootstrapped from a server
			 * checkpoint and pending intents were dropped. Drop the observed
			 * baseline (its seq belongs to trimmed history) so the change
			 * event that follows re-seeds from the checkpoint and pushes it,
			 * and clear staleness bookkeeping derived from the old replica.
			 * The editor tree still holds any un-acked local work; the next
			 * capture diffs it against the reset document and re-authors.
			 */
			state.observed = null;
			state.pendingPushes = [];
			state.pushSeq++;
			state.lastPushedProps = {};
			state.docTombstones.clear();
			state.prevDocIds = new Set();
			log( 'session reset from server checkpoint', { key } );
		} );

		session.onDiscard( ( updates ) => {
			/*
			 * The transport dropped the room with unsent updates (terminal
			 * error: lost permission, engine mismatch, a limit). The content
			 * is still in the editor but will never sync — surface the loss
			 * instead of letting it stay silent. The editor keeps the post
			 * dirty, so saving preserves the work through the classic path.
			 */
			// eslint-disable-next-line no-console
			console.error(
				'[gutenberg-sync-engines] Real-time sync stopped with unsent changes:',
				updates.map( ( update ) => update.type )
			);
			// Duck-typed runtime global: the manager deliberately avoids a
			// module dependency on the data registry; outside wp-admin (unit
			// tests) this is a no-op.
			const wpGlobal = (
				window as Window & {
					wp?: {
						data?: {
							dispatch?: ( store: string ) => {
								createErrorNotice?: (
									message: string,
									options?: Record< string, unknown >
								) => void;
							};
						};
					};
				}
			 ).wp;
			wpGlobal?.data
				?.dispatch?.( 'core/notices' )
				?.createErrorNotice?.(
					'Real-time collaboration stopped before your latest changes were sent. They are still in this editor — save the post to keep them.',
					{
						id: 'gutenberg-sync-engines-discarded-updates',
						isDismissible: true,
					}
				);
		} );

		/*
		 * Escalation notices derive from the SETTLED open-proposal list, on
		 * a microtask after the delivery batch: a bootstrap replay delivers
		 * proposal rows before their resolution rows, and notifying on raw
		 * arrival would re-surface long-resolved conflicts on every reload.
		 */
		const notifiedProposalIds = new Set< string >();
		let proposalsNotifyScheduled = false;
		const summarizeProposal = ( proposal: {
			intent: { type: string; payload: Record< string, unknown > };
		} ): string | undefined => {
			const { type, payload } = proposal.intent;
			switch ( type ) {
				case 'insert_text':
				case 'replace_text':
					return payload.text as string;
				case 'replace_attr_content':
					return payload.newText as string;
				case 'delete_text':
					return undefined; // A lost deletion has no content to show.
				case 'set_attr':
					return `${ payload.key as string }: ${ JSON.stringify(
						payload.value
					) }`;
				case 'set_property':
					return `${ payload.name as string }: ${ JSON.stringify(
						payload.value
					) }`;
				case 'format_text':
					return payload.format as string;
				case 'insert_block': {
					// The reviewer must SEE what they would approve —
					// notably a raw-attr block's markup (core/html).
					const block = payload.block as
						| {
								blockType?: string;
								text?: string;
								fields?: {
									content?: { text?: string };
								};
								attrs?: Record< string, unknown >;
						  }
						| undefined;
					const text =
						block?.fields?.content?.text ??
						block?.text ??
						( typeof block?.attrs?.content === 'string'
							? ( block.attrs.content as string )
							: undefined );
					return text
						? `${ block?.blockType ?? 'block' }: ${ text }`
						: block?.blockType;
				}
				default:
					return undefined;
			}
		};
		// A parked new-block proposal (insert_block) has no block in the
		// reviewer's canvas to anchor to. Surface its intended position and
		// a readable content preview so the editor can render it INLINE
		// where it would land, with approve/discard in place.
		const proposedInsertionFor = ( proposal: {
			intent: { type: string; payload: Record< string, unknown > };
		} ) => {
			if ( 'insert_block' !== proposal.intent.type ) {
				return undefined;
			}
			const payload = proposal.intent.payload;
			const block = payload.block as
				| {
						blockType?: string;
						fields?: {
							content?: { text: string; formats?: unknown[] };
						};
						attrs?: Record< string, unknown >;
				  }
				| undefined;
			const field = block?.fields?.content;
			let html = '';
			if ( field ) {
				html = fieldToHtml( field as never );
			} else if ( typeof block?.attrs?.content === 'string' ) {
				html = block.attrs.content as string;
			}
			return {
				blockType: block?.blockType,
				html,
				afterSiblingId:
					typeof payload.afterSiblingId === 'string'
						? payload.afterSiblingId
						: undefined,
				parentId:
					typeof payload.parentId === 'string'
						? payload.parentId
						: undefined,
			};
		};
		const mapReviewItems = () =>
			session.getOpenProposals().map( ( proposal ) => ( {
				id: proposal.intent.intentId,
				unitId: proposal.intent.txnId ?? proposal.intent.intentId,
				isLocal: proposal.actorId === session.actorId,
				actorId: proposal.actorId,
				reason: proposal.reason,
				intentType: proposal.intent.type,
				summary: summarizeProposal( proposal ),
				excerpt: proposal.context?.excerpt,
				targetId:
					typeof proposal.intent.payload.syncId === 'string'
						? proposal.intent.payload.syncId
						: undefined,
				proposedInsertion: proposedInsertionFor( proposal ),
			} ) );
		session.onProposalsChange( () => {
			if ( proposalsNotifyScheduled ) {
				return;
			}
			proposalsNotifyScheduled = true;
			void Promise.resolve().then( () => {
				proposalsNotifyScheduled = false;
				if ( state.unloaded ) {
					return;
				}
				const items = mapReviewItems();
				handlers.onProposalsChange?.( items );
				for ( const item of items ) {
					if ( notifiedProposalIds.has( item.id ) ) {
						continue;
					}
					notifiedProposalIds.add( item.id );
					if ( handlers.onEscalation ) {
						handlers.onEscalation( {
							reason: item.reason,
							isLocal: item.isLocal,
							proposalId: item.id,
							summary: item.summary,
							excerpt: item.excerpt,
						} );
					} else {
						// eslint-disable-next-line no-console
						console.warn(
							'[IntentLog] An edit was escalated for review (%s): %s',
							item.reason,
							item.id
						);
					}
				}
			} );
		} );

		log( 'connecting', { key } );
		state.providers = await Promise.all(
			providerCreators.map( async ( create: ProviderCreator ) => {
				const provider = await create( {
					objectType,
					objectId,
					session,
				} );
				provider.on( 'status', handlers.onStatusChange );
				return provider;
			} )
		);

		if ( state.unloaded ) {
			state.providers.forEach( ( provider ) => provider.destroy() );
			return;
		}
		void record;
	}

	return {
		load: loadEntity,

		loadCollection: async () => {
			// Collection rooms (post lists, taxonomies) are not part of the
			// intent-log v1 scope; entities cover the editing surface.
		},

		update( objectType, objectId, changes, origin ) {
			const state = entityStates.get( entityKey( objectType, objectId ) );
			if ( ! state || state.unloaded ) {
				return;
			}
			if ( ! state.session.isInitialized() ) {
				return; // Snapshot not yet received; the editor still owns state.
			}

			/*
			 * Entity property capture: an edits object carries a property
			 * only when the editor changed it, so presence IS intent (unlike
			 * block-tree absence). Same-value writes are echoes of our own
			 * push or of the document state and are suppressed.
			 */
			const doc = state.session.getDocument()!;
			for ( const name of SYNCED_PROPERTIES ) {
				if ( ! ( name in changes ) ) {
					continue;
				}
				const value = rawPropertyValue(
					changes as Record< string, unknown >,
					name
				);
				if ( undefined === value || doc.props?.[ name ] === value ) {
					continue;
				}
				state.lastPushedProps[ name ] = value;
				state.capturing = true;
				try {
					state.session.author( 'set_property', {
						name,
						value,
						observedVersion: doc.propVersions?.[ name ] ?? 0,
					} );
				} finally {
					state.capturing = false;
				}
			}

			const blocks = changes.blocks as BridgeBlock[] | undefined;
			if ( ! blocks ) {
				return; // Only whitelisted properties and blocks sync.
			}

			/*
			 * The incoming tree is the editor's own testimony about what it
			 * displays: every id it carries becomes removable from now on.
			 * (A push becomes removable when its echo arrives here.)
			 */
			const treeIds = collectBlockIds( blocks );
			for ( const id of treeIds ) {
				state.editorIds.add( id );
			}

			/*
			 * Capture against the state this tree was authored against, not
			 * the current head (see the module note): remote work that
			 * landed since is simply absent from the baseline, so the diff
			 * can neither delete it nor re-author it, and the intents carry
			 * the baseline's seq for the transform to merge over.
			 */
			chooseObservedBaseline( state, blocks );
			if ( ! state.observed ) {
				// Defensive: initialized without a change event.
				setObserved( state, {
					doc,
					seq: state.session.getSeq(),
					json: canonicalBlocksJson( documentBlocks( state, doc ) ),
				} );
			}
			const baseDoc = state.observed!.doc;

			const derived = deriveIntents( baseDoc, blocks, {
				// Only blocks the editor has displayed may be deleted
				// by its tree's absence; never-seen blocks are retained.
				removableIds: state.editorIds,
				// Remotely removed blocks in a stale tree are not
				// resurrected.
				excludeIds: state.docTombstones,
				richTextFields: state.fieldsResolver,
				rawContent: state.rawContent,
			} );

			if ( derived ) {
				// Id-less blocks in the tree confirm their adopted/minted ids.
				const confirmIds = ( specs: typeof derived.specs ) => {
					for ( const spec of specs ) {
						state.editorIds.add( spec.syncId as string );
						confirmIds(
							( spec.children as typeof derived.specs ) ?? []
						);
					}
				};
				confirmIds( derived.specs );
				if ( derived.coarseBlockCount > 0 ) {
					log( 'coarse capture', {
						origin,
						blocks: derived.coarseBlockCount,
					} );
				}
				state.capturing = true;
				try {
					state.session.authorBatch( derived.intents );
				} finally {
					state.capturing = false;
				}
				/*
				 * The editor now displays the baseline plus what it just
				 * told us — NOT the merged head, which it has not seen. Its
				 * seq is unchanged: this tree observed no new remote work.
				 */
				const observedDoc = applyDerivedIntents(
					baseDoc,
					derived.intents
				);
				setObserved( state, {
					doc: observedDoc,
					seq: state.session.getObservedSeq(),
					json: canonicalBlocksJson(
						documentBlocks( state, observedDoc )
					),
				} );
			}

			/*
			 * Push the editor forward when its tree was behind the shared
			 * document. Content divergence is caught by syncEditor's own
			 * comparison; these are the cases where the tree diverges from
			 * the baseline in ways that comparison cannot see:
			 *
			 * - missing identities (freshly parsed or new blocks needing
			 *   their adopted/minted ids — the churn bug): the baseline
			 *   carries the ids, the editor's tree does not;
			 * - retained never-displayed blocks: the baseline keeps them,
			 *   the editor does not show them. Without this forced push,
			 *   deleting a just-arrived remote block BEFORE any tree
			 *   testified it left the editor silently behind the document
			 *   forever (found by the fuzzer after a peer re-joined). The
			 *   push visibly resurrects the block; the next tree then makes
			 *   the id removable, so repeating the deletion works;
			 * - blocks dropped because a remote deletion outranked the
			 *   stale tree.
			 */
			const editorHadAllIds = blocks.every( function hasId(
				block: BridgeBlock
			): boolean {
				const metadata = block.attributes?.metadata as
					| { syncId?: string }
					| undefined;
				return !! metadata?.syncId && block.innerBlocks.every( hasId );
			} );
			const treeHasTombstoned = [ ...treeIds ].some( ( id ) =>
				state.docTombstones.has( id )
			);
			const specIdSet = new Set< string >();
			const collectFromSpecs = (
				specs: Array< Record< string, unknown > > = []
			) => {
				for ( const spec of specs ) {
					specIdSet.add( spec.syncId as string );
					collectFromSpecs(
						spec.children as Array< Record< string, unknown > >
					);
				}
			};
			collectFromSpecs( derived?.specs );
			/*
			 * Identity remapping also counts as "behind": when adoption
			 * resolved a tree id onto a different document identity (or
			 * minted one), the editor must receive the document's
			 * authoritative ids — the document is the identity authority,
			 * and saved content must carry ITS ids, identical across all
			 * peers, for identity to be durable across sessions.
			 */
			const idsRemapped = [ ...specIdSet ].some(
				( id ) => ! treeIds.has( id )
			);
			scheduleEditorSync(
				state,
				! editorHadAllIds ||
					idsRemapped ||
					treeHasTombstoned ||
					( derived?.retainedIds.size ?? 0 ) > 0
			);
		},

		getAwareness: < State extends Awareness >(
			objectType: ObjectType,
			objectId: ObjectID | null
		) => {
			return entityStates.get( entityKey( objectType, objectId ) )
				?.awareness as State | undefined;
		},

		resolveProposal( objectType, objectId, proposalId, resolution ) {
			const state = entityStates.get( entityKey( objectType, objectId ) );
			if ( ! state || state.unloaded ) {
				return;
			}
			state.session.resolveProposal( proposalId, resolution );
		},

		restoreProposal( objectType, objectId, proposalId ) {
			const state = entityStates.get( entityKey( objectType, objectId ) );
			if ( ! state || state.unloaded ) {
				return;
			}
			const session = state.session;
			const proposal = session
				.getOpenProposals()
				.find( ( open ) => open.intent.intentId === proposalId );
			if ( ! proposal ) {
				return;
			}
			/*
			 * Best-effort re-author at the current head — restoration is an
			 * ORDINARY edit through the normal planning rules, never a
			 * privileged replay. Text appends to the target field (or a
			 * fresh paragraph when the block is gone); register writes
			 * re-apply at current observed versions. Types with no sensible
			 * auto-restore just resolve; the notice showed the content for
			 * manual recovery.
			 */
			const { type, payload } = proposal.intent;
			const doc = session.getDocument();
			const findBlock = (
				blocks: import('./intent-log/engine-types').EngineBlock[],
				id: string
			): import('./intent-log/engine-types').EngineBlock | null => {
				for ( const block of blocks ) {
					if ( block.syncId === id ) {
						return block;
					}
					const inChildren = findBlock( block.children, id );
					if ( inChildren ) {
						return inChildren;
					}
				}
				return null;
			};
			const restoreText = ( text: string ) => {
				const targetId = payload.syncId as string;
				const field = ( payload.field as string ) ?? 'content';
				const block = doc ? findBlock( doc.root, targetId ) : null;
				if ( block ) {
					const current = block.fields[ field ]?.text ?? '';
					session.author( 'insert_text', {
						syncId: targetId,
						field,
						offset: current.length,
						text,
					} );
				} else {
					session.author( 'insert_block', {
						block: {
							syncId: mintSyncId(),
							blockType: 'core/paragraph',
							text,
						},
						parentId: null,
						afterSiblingId: doc?.root.at( -1 )?.syncId ?? null,
					} );
				}
			};
			let restoredText: string | null = null;
			if ( 'insert_text' === type || 'replace_text' === type ) {
				restoredText = payload.text as string;
			} else if ( 'replace_attr_content' === type ) {
				restoredText = payload.newText as string;
			}
			if ( restoredText ) {
				restoreText( restoredText );
			} else if ( 'set_attr' === type && doc ) {
				const block = findBlock( doc.root, payload.syncId as string );
				if ( block ) {
					session.author( 'set_attr', {
						syncId: payload.syncId as string,
						key: payload.key as string,
						value: payload.value,
						observedVersion:
							block.attrVersions[ payload.key as string ] ?? 0,
					} );
				}
			} else if ( 'set_property' === type && doc ) {
				session.author( 'set_property', {
					name: payload.name as string,
					value: payload.value,
					observedVersion:
						doc.propVersions?.[ payload.name as string ] ?? 0,
				} );
			} else if ( 'insert_block' === type && doc ) {
				/*
				 * Re-insert the parked block spec under FRESH identities
				 * (the original insert never applied; reminting sidesteps
				 * any duplicate/tombstone history). Anchors degrade: a
				 * vanished parent falls back to the root, a vanished
				 * sibling to the end. This is what makes restoring a
				 * requires-approval block an approval — the re-authored
				 * intent carries the RESTORER's capability.
				 */
				type SpecShape = {
					syncId: string;
					children?: SpecShape[];
					[ key: string ]: unknown;
				};
				const remint = ( spec: SpecShape ): SpecShape => ( {
					...spec,
					syncId: mintSyncId(),
					children: ( spec.children ?? [] ).map( remint ),
				} );
				const parentId =
					typeof payload.parentId === 'string' &&
					findBlock( doc.root, payload.parentId )
						? ( payload.parentId as string )
						: null;
				const afterSiblingId =
					typeof payload.afterSiblingId === 'string' &&
					findBlock( doc.root, payload.afterSiblingId )
						? ( payload.afterSiblingId as string )
						: ( ! parentId && doc.root.at( -1 )?.syncId ) || null;
				session.author( 'insert_block', {
					block: remint( payload.block as SpecShape ),
					parentId,
					afterSiblingId,
				} );
			}
			session.resolveProposal( proposalId, 'restored' );
		},

		// The server materializes; there is no client-side persisted doc.
		createPersistedCRDTDoc: async () => null,
		getEntitySnapshot: () => undefined,
		entityContainsSnapshot: () => false,

		// Core's default undo manager applies (see module note).
		undoManager: undefined,

		unload( objectType, objectId ) {
			const key = entityKey( objectType, objectId );
			const state = entityStates.get( key );
			if ( ! state ) {
				return;
			}
			state.unloaded = true;
			if ( state.syncTimer ) {
				clearTimeout( state.syncTimer );
			}
			state.providers.forEach( ( provider ) => provider.destroy() );
			state.awareness?.destroy();
			state.session.destroy();
			entityStates.delete( key );
		},

		unloadAll() {
			for ( const [ , state ] of entityStates ) {
				state.unloaded = true;
				if ( state.syncTimer ) {
					clearTimeout( state.syncTimer );
				}
				state.providers.forEach( ( provider ) => provider.destroy() );
				state.awareness?.destroy();
				state.session.destroy();
			}
			entityStates.clear();
		},

		// Transport-agnostic retry: ask every live provider to retry after a
		// connection error (wired to the editor's connection-error modal).
		retry() {
			for ( const [ , state ] of entityStates ) {
				state.providers.forEach( ( provider ) => provider.retry?.() );
			}
		},
	};
}
