/**
 * External dependencies
 */
import * as Y from 'yjs';

/**
 * WordPress dependencies
 */
// __unstableSerializeAndClean is the exact serializer core-data uses when
// comparing CRDT blocks against persisted content; sharing it keeps proposal
// content byte-consistent with WordPress saves.
// eslint-disable-next-line import/no-unresolved, @wordpress/no-unsafe-wp-apis -- Provided at runtime as wp.blocks.
import { parse, __unstableSerializeAndClean } from '@wordpress/blocks';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

/**
 * Internal dependencies
 */
import { CRDT_RECORD_MAP_KEY } from '../yjs/constants';

/**
 * Origin tag for Yjs transactions that apply server-accepted canonical
 * content, so they are not mistaken for local edits (which would echo a
 * proposal) and so the entity's observers report them as remote changes.
 */
export const DE_RTC_REMOTE_ORIGIN = 'de-rtc-remote';

/**
 * Origin tag for Yjs transactions that restore a parked proposal's blocks
 * into the doc. Dual-natured by design: the entity's observers report it
 * to the EDITOR like a remote change (the restored blocks must reach the
 * canvas), while the session codec treats it as a LOCAL edit (the doc is
 * dirty and the restored state must re-propose under the restorer's
 * capability).
 */
export const DE_RTC_RESTORE_ORIGIN = 'de-rtc-restore';

/**
 * The shared per-entity state the engine entity and its session codec
 * both close over: the local Y.Doc that bridges the editor, and the
 * canonical version/content tracking the proposal wire needs.
 *
 * The doc is an EDITOR BRIDGE, not the sync substrate: the server's
 * canonical document is a serialized-block string, and this bridge
 * translates between that string and the editor's block model using the
 * editor's own parser/serializer (via the sync config's record↔doc
 * mapping, shared with the yjs engines).
 */
export interface DeRtcDocBridge {
	/** The Yjs document bridging the editor. */
	doc: Y.Doc;

	/** Whether the server's genesis (or any canonical row) has applied. */
	isBootstrapped: () => boolean;

	/** The version label of the last canonical state APPLIED to the doc. */
	lastVersion: () => string | null;

	/**
	 * Applies a server-accepted canonical state into the doc (remote
	 * origin) and advances the version tracking. Fires bootstrap
	 * listeners on the first application. Rows at or behind the version
	 * already incorporated are ignored (a deferred stale row must never
	 * regress the doc).
	 *
	 * @param version    Canonical version label.
	 * @param content    Canonical serialized-block content.
	 * @param properties Canonical entity-property map (flat, meta.<key>).
	 */
	applyCanonical: (
		version: string,
		content: string,
		properties?: Record< string, unknown >
	) => void;

	/**
	 * Advances the version WITHOUT touching the doc — for the client's
	 * own accepted proposal when it round-tripped unchanged: the doc
	 * already holds that content (plus any newer local keystrokes, which
	 * an application would clobber).
	 *
	 * @param version Canonical version label.
	 */
	advanceVersion: ( version: string ) => void;

	/**
	 * Incorporates a canonical state that arrived while local edits are
	 * pending: adopts canonical blocks the doc has NOT locally edited
	 * since `proposedContent` was proposed, keeps locally-edited blocks
	 * (the server merges them on the next proposal), and advances the
	 * version. Only valid when the block structure is unchanged on every
	 * side (equal block counts); returns false when it cannot align, in
	 * which case the caller keeps deferring.
	 *
	 * Per-block base honesty (TODO-2b in docs/engine-comparison.md):
	 * when a kept block was ALSO changed in the arriving canonical (a
	 * true same-block collision), the version the doc held BEFORE this
	 * incorporation is recorded as that block's base. The next proposal
	 * carries the map, and the server merges the collided block from
	 * its TRUE base — non-overlapping concurrent edits merge, real
	 * overlaps park for review — instead of reading the re-proposal as
	 * a clean sole-writer change (the silent block-level
	 * last-writer-wins this client policy used to cause).
	 *
	 * @param version         Canonical version label.
	 * @param content         Canonical serialized-block content.
	 * @param proposedContent The content this client last proposed.
	 * @return Whether the canonical state was incorporated.
	 */
	incorporateCanonicalPreservingLocalEdits: (
		version: string,
		content: string,
		proposedContent: string
	) => boolean;

	/**
	 * The recorded true-base versions of blocks kept through a colliding
	 * incorporation, keyed by top-level block index (JSON-string keys).
	 * Empty when no collision is pending.
	 */
	blockBaseVersions: () => Record< string, string >;

	/**
	 * Contested-block lifecycle (TODO-12, the validated pending-edits
	 * model): fired each time an incorporation keeps a locally-edited
	 * block that the arriving canonical ALSO changed. Repeats for the
	 * same block REFRESH the one contest (merge-not-stack) — the event
	 * always carries the LATEST canonical version and serialized form
	 * of the block.
	 */
	onContested: (
		listener: ( event: {
			index: number;
			version: string;
			html: string;
		} ) => void
	) => void;

	/**
	 * Fired when a contest resolves: the block adopted canonical (its
	 * kept form finally merged, a wholesale apply or version-only
	 * advance settled it), or an explicit Adopt/Reject verb ran.
	 */
	onContestResolved: ( listener: ( index: number ) => void ) => void;

	/**
	 * ADOPT: apply the contest's latest canonical block into the doc.
	 * Applied under the remote origin — it already IS canonical, so it
	 * must not mark the doc dirty or re-propose. Resolves the contest
	 * and clears the block's recorded base.
	 *
	 * @return Whether a contest existed for the index.
	 */
	adoptContestedBlock: ( index: number ) => boolean;

	/**
	 * REJECT: resolve the contest, KEEPING the local block and its
	 * recorded true base — the next proposal still declares it, so the
	 * server merges honestly (compatible edits merge, true overlaps
	 * park to the peer's review). A later peer edit to the same block
	 * raises a fresh contest.
	 *
	 * @return Whether a contest existed for the index.
	 */
	rejectContestedBlock: ( index: number ) => boolean;

	/** Serializes the doc's current blocks to proposal content. */
	buildContent: () => string;

	/**
	 * The doc's current entity-property registers in the wire shape: every
	 * record-map entry except `blocks`, Yjs values plainified, the `meta`
	 * map flattened to `meta.<key>` entries, taxonomy term-ID arrays in
	 * canonical numeric order (matching the server genesis seed).
	 */
	buildProperties: () => Record< string, unknown >;

	/**
	 * Incorporates the property half of the client's OWN accepted row:
	 * adopts a canonical value the client has NOT locally changed since
	 * proposing (the server merged a peer's property change into our
	 * row), and keeps locally-edited values (the next proposal
	 * reconciles them) — the property twin of the block incorporation
	 * policy.
	 *
	 * @param properties         Canonical property map from the row.
	 * @param proposedProperties The map this client last proposed.
	 */
	incorporateProperties: (
		properties: Record< string, unknown >,
		proposedProperties: Record< string, unknown >
	) => void;

	/**
	 * Registers a one-shot listener fired when the first canonical state
	 * applies (the bootstrap moment).
	 *
	 * @param listener Bootstrap callback.
	 */
	onBootstrap: ( listener: () => void ) => void;
}

/**
 * Parses canonical content into editor blocks, dropping the empty
 * freeform artifacts inter-block whitespace can produce (the server
 * merge core's wp_de_rtc_remove_empty_freeform_blocks twin).
 *
 * @param content Serialized block content.
 * @return Editor blocks.
 */
export function parseCanonicalBlocks(
	content: string
): ReturnType< typeof parse > {
	if ( ! content ) {
		return [];
	}
	return parse( content ).filter( ( block ) => {
		if ( ! block.name ) {
			return false;
		}
		if ( 'core/freeform' === block.name ) {
			const text = String( block.attributes?.content ?? '' ).trim();
			return '' !== text;
		}
		return true;
	} );
}

/**
 * Order-tolerant value equality for property registers: term-ID arrays
 * are sets (numeric lists compare sorted); everything else compares by
 * JSON encoding. The client twin of the server's property comparison.
 *
 * @param a One value.
 * @param b Other value.
 * @return Whether the values are equal.
 */
export function propertyValuesEqual( a: unknown, b: unknown ): boolean {
	if (
		Array.isArray( a ) &&
		Array.isArray( b ) &&
		a.every( ( value ) => 'number' === typeof value ) &&
		b.every( ( value ) => 'number' === typeof value )
	) {
		const aSorted = [ ...a ].sort( ( x, y ) => x - y );
		const bSorted = [ ...b ].sort( ( x, y ) => x - y );
		return JSON.stringify( aSorted ) === JSON.stringify( bSorted );
	}
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Unflattens a wire property map (`meta.<key>` entries beside plain
 * properties) into the change shape the sync config applies: meta keys
 * regroup under one partial `meta` object (the config merges meta per
 * key, so a partial object never wipes sibling keys).
 *
 * @param flat Flat wire property map.
 * @return Record changes.
 */
export function unflattenProperties(
	flat: Record< string, unknown >
): Record< string, unknown > {
	const changes: Record< string, unknown > = {};
	let meta: Record< string, unknown > | null = null;
	for ( const [ name, value ] of Object.entries( flat ) ) {
		if ( name.startsWith( 'meta.' ) ) {
			meta = meta ?? {};
			meta[ name.slice( 'meta.'.length ) ] = value;
		} else {
			changes[ name ] = value;
		}
	}
	if ( meta ) {
		changes.meta = meta;
	}
	return changes;
}

/**
 * Creates the shared doc bridge for one entity.
 *
 * @param doc        The entity's Yjs document.
 * @param syncConfig The sync config supplying the record↔doc mapping.
 * @return The doc bridge.
 */
export function createDeRtcDocBridge(
	doc: Y.Doc,
	syncConfig: SyncConfig
): DeRtcDocBridge {
	let bootstrapped = false;
	let version: string | null = null;
	// Per-block true bases of blocks kept through colliding
	// incorporations (TODO-2b): block index -> the version their local
	// text was really written against.
	const blockBases = new Map< number, string >();
	// The latest canonical form of each contested block (TODO-12):
	// refreshed on every colliding row (merge-not-stack), consumed by
	// the Adopt verb, cleared whenever the contest resolves.
	const contestedLatest = new Map<
		number,
		{ version: string; block: unknown }
	>();
	const contestedListeners = new Set<
		( event: { index: number; version: string; html: string } ) => void
	>();
	const contestResolvedListeners = new Set< ( index: number ) => void >();

	const emitContested = ( index: number ) => {
		const entry = contestedLatest.get( index );
		if ( ! entry ) {
			return;
		}
		const html = __unstableSerializeAndClean( [
			entry.block as any,
		] ).trim();
		contestedListeners.forEach( ( listener ) =>
			listener( { index, version: entry.version, html } )
		);
	};
	const resolveContest = ( index: number ) => {
		if ( contestedLatest.delete( index ) ) {
			contestResolvedListeners.forEach( ( listener ) =>
				listener( index )
			);
		}
	};
	const resolveAllContests = () => {
		for ( const index of Array.from( contestedLatest.keys() ) ) {
			resolveContest( index );
		}
	};
	let bootstrapListeners: Array< () => void > = [];

	// Version labels are the server's monotonic 'v<seq>' scheme.
	const seqOf = ( label: string | null ): number =>
		null === label ? 0 : parseInt( label.replace( /^v/, '' ), 10 ) || 0;

	const markVersion = ( nextVersion: string ) => {
		version = nextVersion;
		if ( ! bootstrapped ) {
			bootstrapped = true;
			const listeners = bootstrapListeners;
			bootstrapListeners = [];
			listeners.forEach( ( listener ) => listener() );
		}
	};

	const readFlatProperties = (): Record< string, unknown > => {
		const flat: Record< string, unknown > = {};
		doc.getMap( CRDT_RECORD_MAP_KEY ).forEach(
			( stored: any, name: string ) => {
				/*
				 * `blocks` IS the content model; a `content` record-map
				 * entry (core-data mirrors the serialized string into the
				 * doc) would duplicate the ENTIRE document as a property
				 * register on every proposal and every announce — the
				 * double-carry the TODO-20 wire inspection caught. One
				 * representation: content travels as content, never as a
				 * property.
				 */
				if ( 'blocks' === name || 'content' === name ) {
					return;
				}
				const value =
					stored && 'function' === typeof stored.toJSON
						? stored.toJSON()
						: stored;
				if ( 'meta' === name ) {
					if ( value && 'object' === typeof value ) {
						for ( const [ metaKey, metaValue ] of Object.entries(
							value as Record< string, unknown >
						) ) {
							flat[ `meta.${ metaKey }` ] = metaValue;
						}
					}
					return;
				}
				if (
					Array.isArray( value ) &&
					value.every( ( entry ) => 'number' === typeof entry )
				) {
					// Term bindings are sets: canonical numeric order,
					// matching the server genesis seed.
					flat[ name ] = [ ...value ].sort( ( a, b ) => a - b );
					return;
				}
				flat[ name ] = value;
			}
		);
		return flat;
	};

	return {
		doc,

		isBootstrapped: () => bootstrapped,

		lastVersion: () => version,

		applyCanonical( nextVersion, content, properties ) {
			if ( bootstrapped && seqOf( nextVersion ) <= seqOf( version ) ) {
				return;
			}
			const blocks = parseCanonicalBlocks( content );
			const changes: Record< string, unknown > = properties
				? { ...unflattenProperties( properties ), blocks }
				: { blocks };
			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, changes );
			}, DE_RTC_REMOTE_ORIGIN );
			// Wholesale adoption: every pending collision resolved.
			blockBases.clear();
			resolveAllContests();
			markVersion( nextVersion );
		},

		advanceVersion( nextVersion ) {
			if ( bootstrapped && seqOf( nextVersion ) <= seqOf( version ) ) {
				return;
			}
			// Our proposal round-tripped unchanged: every kept block's
			// content IS canonical now.
			blockBases.clear();
			resolveAllContests();
			markVersion( nextVersion );
		},

		incorporateCanonicalPreservingLocalEdits(
			nextVersion,
			content,
			proposedContent
		) {
			if ( bootstrapped && seqOf( nextVersion ) <= seqOf( version ) ) {
				return true; // Stale row: nothing to incorporate.
			}

			const canonicalBlocks = parseCanonicalBlocks( content );
			const proposedBlocks = parseCanonicalBlocks( proposedContent );
			const stored: any = doc
				.getMap( CRDT_RECORD_MAP_KEY )
				.get( 'blocks' );
			const localBlocks: any[] =
				stored?.toJSON?.() ?? ( Array.isArray( stored ) ? stored : [] );

			// Index alignment is only sound when the LOCAL structure is
			// unchanged since the proposal and the canonical either matches
			// it or extends it (a peer appended blocks — adopted below).
			// Anything else (local structure changes mid-flight, peer
			// deletions or mid-document insertions) keeps deferring until
			// the local state settles.
			if (
				localBlocks.length !== proposedBlocks.length ||
				canonicalBlocks.length < proposedBlocks.length
			) {
				return false;
			}
			for ( let i = 0; i < proposedBlocks.length; i++ ) {
				if ( canonicalBlocks[ i ].name !== proposedBlocks[ i ].name ) {
					return false;
				}
			}

			const serializeOne = ( block: any ) =>
				__unstableSerializeAndClean( [ block ] ).trim();

			const priorVersion = version;
			const collided: number[] = [];
			const merged = proposedBlocks
				.map( ( proposedBlock, index ) => {
					const locallyEdited =
						serializeOne( localBlocks[ index ] ) !==
						serializeOne( proposedBlock );
					if ( ! locallyEdited ) {
						// Adopting canonical resolves any pending
						// collision on this block.
						blockBases.delete( index );
						resolveContest( index );
						return canonicalBlocks[ index ];
					}
					// Kept. If canonical ALSO changed this block, that is
					// a true same-block collision: remember the version
					// our text was really written against (once — the
					// OLDEST pending base wins), so the next proposal
					// tells the server the truth instead of presenting a
					// clean sole-writer change.
					if (
						serializeOne( canonicalBlocks[ index ] ) !==
						serializeOne( proposedBlock )
					) {
						if (
							! blockBases.has( index ) &&
							null !== priorVersion
						) {
							blockBases.set( index, priorVersion );
						}
						// The contest tracks the LATEST canonical form:
						// repeats refresh the one pending item, never a
						// second one (merge-not-stack, TODO-12).
						contestedLatest.set( index, {
							version: nextVersion,
							block: canonicalBlocks[ index ],
						} );
						collided.push( index );
					}
					return localBlocks[ index ];
				} )
				.concat( canonicalBlocks.slice( proposedBlocks.length ) );

			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, { blocks: merged } );
			}, DE_RTC_REMOTE_ORIGIN );
			markVersion( nextVersion );
			collided.forEach( emitContested );

			return true;
		},

		buildContent() {
			// Serialize the doc's blocks the way core-data itself does when
			// comparing CRDT state against persisted content — proposal
			// content stays byte-consistent with what a WordPress save of
			// the same blocks would produce (the server's hash fast-paths
			// depend on that stability).
			const stored: any = doc
				.getMap( CRDT_RECORD_MAP_KEY )
				.get( 'blocks' );
			const blocks =
				stored?.toJSON?.() ?? ( Array.isArray( stored ) ? stored : [] );
			return __unstableSerializeAndClean( blocks ).trim();
		},

		buildProperties: readFlatProperties,

		blockBaseVersions() {
			const map: Record< string, string > = {};
			for ( const [ index, baseVersion ] of blockBases ) {
				map[ String( index ) ] = baseVersion;
			}
			return map;
		},

		onContested( listener ) {
			contestedListeners.add( listener );
		},

		onContestResolved( listener ) {
			contestResolvedListeners.add( listener );
		},

		adoptContestedBlock( index ) {
			const entry = contestedLatest.get( index );
			if ( ! entry ) {
				return false;
			}
			const stored: any = doc
				.getMap( CRDT_RECORD_MAP_KEY )
				.get( 'blocks' );
			const blocks: unknown[] = (
				stored?.toJSON?.() ?? ( Array.isArray( stored ) ? stored : [] )
			).slice();
			if ( index < blocks.length ) {
				blocks[ index ] = entry.block;
			}
			// Remote origin: this content already IS canonical — it must
			// not mark the doc dirty or re-propose.
			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, { blocks } );
			}, DE_RTC_REMOTE_ORIGIN );
			blockBases.delete( index );
			resolveContest( index );
			return true;
		},

		rejectContestedBlock( index ) {
			if ( ! contestedLatest.has( index ) ) {
				return false;
			}
			// Keep the local block AND its recorded true base: the next
			// proposal still declares it (TODO-2b honesty). Only the
			// pending item resolves; a later peer edit raises a fresh one.
			resolveContest( index );
			return true;
		},

		incorporateProperties( properties, proposedProperties ) {
			const currentFlat = readFlatProperties();
			const adopt: Record< string, unknown > = {};
			for ( const [ name, value ] of Object.entries( properties ) ) {
				if (
					propertyValuesEqual( value, proposedProperties[ name ] )
				) {
					continue; // Nothing changed server-side.
				}
				if (
					propertyValuesEqual(
						currentFlat[ name ],
						proposedProperties[ name ]
					)
				) {
					// Untouched locally since proposing: adopt the
					// server-merged value (a peer's change).
					adopt[ name ] = value;
				}
				// Locally edited since proposing: keep ours; the next
				// proposal reconciles (block-incorporation's LWW twin).
			}
			if ( 0 === Object.keys( adopt ).length ) {
				return;
			}
			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc(
					doc,
					unflattenProperties( adopt )
				);
			}, DE_RTC_REMOTE_ORIGIN );
		},

		onBootstrap( listener ) {
			if ( bootstrapped ) {
				listener();
				return;
			}
			bootstrapListeners.push( listener );
		},
	};
}
