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
	 * @param version Canonical version label.
	 * @param content Canonical serialized-block content.
	 */
	applyCanonical: ( version: string, content: string ) => void;

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
	 * This is de-rtc v1's client rebase policy: truly concurrent edits
	 * to the SAME block resolve in favor of the local editor's text when
	 * it re-proposes (block-level last-writer-wins — the same class of
	 * silent register policy yjs-server carries, at coarser grain).
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

	/** Serializes the doc's current blocks to proposal content. */
	buildContent: () => string;

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

	return {
		doc,

		isBootstrapped: () => bootstrapped,

		lastVersion: () => version,

		applyCanonical( nextVersion, content ) {
			if ( bootstrapped && seqOf( nextVersion ) <= seqOf( version ) ) {
				return;
			}
			const blocks = parseCanonicalBlocks( content );
			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, { blocks } );
			}, DE_RTC_REMOTE_ORIGIN );
			markVersion( nextVersion );
		},

		advanceVersion( nextVersion ) {
			if ( bootstrapped && seqOf( nextVersion ) <= seqOf( version ) ) {
				return;
			}
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

			const merged = proposedBlocks
				.map( ( proposedBlock, index ) => {
					const locallyEdited =
						serializeOne( localBlocks[ index ] ) !==
						serializeOne( proposedBlock );
					return locallyEdited
						? localBlocks[ index ]
						: canonicalBlocks[ index ];
				} )
				.concat( canonicalBlocks.slice( proposedBlocks.length ) );

			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, { blocks: merged } );
			}, DE_RTC_REMOTE_ORIGIN );
			markVersion( nextVersion );

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

		onBootstrap( listener ) {
			if ( bootstrapped ) {
				listener();
				return;
			}
			bootstrapListeners.push( listener );
		},
	};
}
