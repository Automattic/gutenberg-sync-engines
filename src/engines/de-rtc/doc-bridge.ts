/**
 * External dependencies
 */
import * as Y from 'yjs';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.blocks.
import { parse, serialize } from '@wordpress/blocks';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

/**
 * Origin tag for Yjs transactions that apply server-accepted canonical
 * content, so they are not mistaken for local edits (which would echo a
 * proposal) and so the entity's observers report them as remote changes.
 */
export const DE_RTC_REMOTE_ORIGIN = 'de-rtc-remote';

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
	 * listeners on the first application.
	 *
	 * @param version Canonical version label.
	 * @param content Canonical serialized-block content.
	 */
	applyCanonical: ( version: string, content: string ) => void;

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
function parseCanonicalBlocks( content: string ): ReturnType< typeof parse > {
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

	return {
		doc,

		isBootstrapped: () => bootstrapped,

		lastVersion: () => version,

		applyCanonical( nextVersion, content ) {
			const blocks = parseCanonicalBlocks( content );
			doc.transact( () => {
				syncConfig.applyChangesToCRDTDoc( doc, { blocks } );
			}, DE_RTC_REMOTE_ORIGIN );
			version = nextVersion;

			if ( ! bootstrapped ) {
				bootstrapped = true;
				const listeners = bootstrapListeners;
				bootstrapListeners = [];
				listeners.forEach( ( listener ) => listener() );
			}
		},

		buildContent() {
			// Diffing against an empty record yields the doc's full current
			// blocks — the same mapping the editor consumes.
			const changes = syncConfig.getChangesFromCRDTDoc( doc, {
				blocks: [],
			} as any );
			const blocks = Array.isArray( ( changes as any )?.blocks )
				? ( changes as any ).blocks
				: [];
			return serialize( blocks );
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
