/**
 * External dependencies
 */
import { Awareness } from 'y-protocols/awareness';

/**
 * Internal dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type {
	EngineDisposition,
	EngineLocalUpdateListener,
	EngineSessionCodec,
	EngineUpdate,
} from '@wordpress/sync';
import { applyServerAwarenessStates } from '../awareness-sync';
import { DE_RTC_REMOTE_ORIGIN, type DeRtcDocBridge } from './doc-bridge';

/**
 * Slug of the de-rtc engine. Must match WP_De_RTC_Engine::SLUG on the PHP
 * side.
 */
export const DE_RTC_ENGINE_SLUG = 'de-rtc';

/**
 * Protocol version of the de-rtc engine. Must match
 * WP_De_RTC_Engine::PROTOCOL_VERSION on the PHP side.
 */
export const DE_RTC_ENGINE_PROTOCOL = 1;

/**
 * Client-sent row type: a whole-content proposal against a named base
 * version. Matches WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL.
 */
export const DE_RTC_PROPOSAL_TYPE = 'proposal';

/**
 * Server-emitted row type: accepted canonical content at a version.
 * Matches WP_De_RTC_Engine::UPDATE_TYPE_CONTENT. Receive-only.
 */
export const DE_RTC_CONTENT_TYPE = 'content';

/**
 * Server-emitted row type: genesis/checkpoint snapshot. Matches
 * WP_De_RTC_Engine::UPDATE_TYPE_SNAPSHOT. Receive-only.
 */
export const DE_RTC_SNAPSHOT_TYPE = 'snapshot';

/**
 * Options for creating a de-rtc session codec.
 */
export interface DeRtcSessionOptions {
	/**
	 * The awareness instance tracking collaborator presence. When omitted, a
	 * standalone instance is created so remote awareness states can still be
	 * applied.
	 */
	awareness?: Awareness;

	/** The shared doc bridge for the entity. */
	bridge: DeRtcDocBridge;
}

/**
 * Creates the de-rtc engine's session codec for one entity/room.
 *
 * The wire is DE-RTC's save-centric shape mapped onto the room protocol:
 * the client sends whole-content PROPOSALS against the version it last
 * incorporated, and the server answers with three-way-merged canonical
 * CONTENT rows plus per-proposal dispositions. Two rules keep the client
 * honest without doing any merging of its own:
 *
 * - ONE proposal in flight, coalesced: local edits mark the doc dirty;
 *   a proposal is built from the doc's current content only when none is
 *   pending, so a burst of typing costs one proposal per poll cycle. The
 *   base version is the version last APPLIED to the doc — a stale base is
 *   fine, that is exactly what the server's three-way merge is for.
 * - Canonical rows are DEFERRED while local edits are dirty or in
 *   flight: applying the server's content would overwrite edits the
 *   server has not seen yet. The newest deferred row applies once the
 *   local state settles (the accepted row for our own proposal already
 *   contains our edits, merged). On a genuine conflict the server
 *   escalates, the local proposal is abandoned, and the canonical state
 *   wins locally once applied — surfacing conflicts to humans is the
 *   upstream review lane this port does not carry yet.
 *
 * @param options The doc bridge and optional awareness to wrap.
 * @return The transport-facing session codec.
 */
export function createDeRtcSessionCodec(
	options: DeRtcSessionOptions
): EngineSessionCodec {
	const { bridge } = options;
	const doc = bridge.doc;
	const awareness = options.awareness ?? new Awareness( doc );

	let localUpdateListener: EngineLocalUpdateListener | null = null;
	let isDocListenerAttached = false;
	let dirty = false;
	let inFlight = false;
	let proposalCounter = 0;
	let pendingCanonical: { version: string; content: string } | null = null;

	function buildProposal(): EngineUpdate {
		proposalCounter += 1;
		const payload = {
			proposalId: `p-${ doc.clientID }-${ proposalCounter }`,
			baseVersion: bridge.lastVersion() ?? '',
			proposedContent: bridge.buildContent(),
			// The block-native update descriptor is server-derivable; the
			// engine's "engine-unaware writer" lane authors it on our
			// behalf. Porting the client-side descriptor builder (and its
			// cross-language fingerprint vectors) is a listed follow-up.
			clientUpdate: null,
		};
		return { data: JSON.stringify( payload ), type: DE_RTC_PROPOSAL_TYPE };
	}

	function maybePropose(): void {
		if (
			! dirty ||
			inFlight ||
			! localUpdateListener ||
			! bridge.isBootstrapped()
		) {
			return;
		}
		const update = buildProposal();
		dirty = false;
		inFlight = true;
		localUpdateListener( update, update.data.length );
	}

	function applyOrDeferCanonical( version: string, content: string ): void {
		if ( dirty || inFlight ) {
			pendingCanonical = { version, content };
			return;
		}
		bridge.applyCanonical( version, content );
	}

	function onDocUpdate( _update: Uint8Array, origin: unknown ): void {
		if ( DE_RTC_REMOTE_ORIGIN === origin ) {
			return;
		}
		dirty = true;
		maybePropose();
	}

	function processRow( update: EngineUpdate ): void {
		let decoded: any;
		try {
			decoded = JSON.parse( update.data );
		} catch {
			return; // A malformed row cannot be applied; the next one resyncs.
		}
		if (
			'string' !== typeof decoded?.version ||
			'string' !== typeof decoded?.content
		) {
			return;
		}

		switch ( update.type ) {
			case DE_RTC_SNAPSHOT_TYPE:
				applyOrDeferCanonical( decoded.version, decoded.content );
				return;

			case DE_RTC_CONTENT_TYPE:
				if ( decoded.authorClientId === doc.clientID ) {
					// Our own accepted proposal, merged by the server: the
					// in-flight slot is free again (dispositions confirm the
					// same thing when this row and they share a response).
					inFlight = false;
				}
				applyOrDeferCanonical( decoded.version, decoded.content );
				if ( ! inFlight ) {
					settleQueued();
				}
		}
	}

	// Newer local edits take priority when a slot frees: they must reach
	// the server before the deferred canonical applies (their base
	// predates it, and the server merges). Otherwise adopt the newest
	// deferred canonical state.
	function settleQueued(): void {
		if ( dirty ) {
			maybePropose();
			return;
		}
		if ( ! inFlight && pendingCanonical ) {
			const { version, content } = pendingCanonical;
			pendingCanonical = null;
			bridge.applyCanonical( version, content );
		}
	}

	return {
		applyRemoteAwareness: ( state ) =>
			applyServerAwarenessStates(
				state,
				awareness,
				DE_RTC_REMOTE_ORIGIN
			),
		clientId: doc.clientID,
		engineSlug: DE_RTC_ENGINE_SLUG,
		engineProtocol: DE_RTC_ENGINE_PROTOCOL,
		// The server compacts by itself and never nominates a client, and
		// unknown-outcome recovery is answered with an idempotent
		// re-proposal of the doc's current state: if the lost send was
		// applied, the merge settles as a no-op; if it was lost, this
		// carries the same edits.
		createCompactionUpdate: () => buildProposal(),
		createRecoveryUpdate: () => buildProposal(),
		createCompactionFromUpdates: () => buildProposal(),
		destroy() {
			if ( isDocListenerAttached ) {
				doc.off( 'update', onDocUpdate );
				isDocListenerAttached = false;
			}
			localUpdateListener = null;
		},
		// The server's snapshot row bootstraps a fresh client; nothing to
		// announce. Un-acked local edits surface through the dirty flag
		// once bootstrap completes.
		getInitialUpdates: () => [],
		getLocalAwareness: () => awareness.getLocalState() ?? {},
		onLocalUpdate( listener ) {
			localUpdateListener = listener;
			if ( ! isDocListenerAttached ) {
				doc.on( 'update', onDocUpdate );
				isDocListenerAttached = true;
			}
			bridge.onBootstrap( () => maybePropose() );
		},
		receiveUpdate: ( update ) => processRow( update ),
		receiveDispositions( dispositions: EngineDisposition[] ) {
			if ( ! dispositions.length ) {
				return;
			}
			// Every disposition for this client settles its proposal:
			// applied rows have already been (or will be) received as
			// content rows; escalated/voided proposals are abandoned — the
			// canonical state wins locally when it applies.
			inFlight = false;
			settleQueued();
		},
	};
}
