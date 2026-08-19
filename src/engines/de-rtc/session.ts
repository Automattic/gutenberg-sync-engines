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
import { buildDeRtcClientUpdate } from './descriptor';
import { DE_RTC_REMOTE_ORIGIN, type DeRtcDocBridge } from './doc-bridge';
import type { DeRtcParkedProposal, DeRtcReviewState } from './review';

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
 * Server-emitted row type: an escalated proposal parked for review.
 * Matches WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL_PARKED. Receive-only.
 */
export const DE_RTC_PROPOSAL_PARKED_TYPE = 'proposal-parked';

/**
 * Row type closing a parked proposal (client-sent; the server relays its
 * stamped copy). Matches WP_De_RTC_Engine::UPDATE_TYPE_RESOLVED.
 */
export const DE_RTC_RESOLVED_TYPE = 'resolved';

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

	/**
	 * The revert-edit undo manager's row feed (TODO-16): every canonical
	 * row this session decodes is published into it, own accepted
	 * proposals tagged. Optional: collections and undo-less tests skip it.
	 */
	undoFeed?: import('./revert-undo').DeRtcUndoFeed;

	/**
	 * The entity's review ledger. Parked/resolved rows feed it, and it
	 * emits resolution rows through this session's local-update lane.
	 * Optional: collection codecs and tests without a review surface
	 * simply drop review rows.
	 */
	review?: DeRtcReviewState;
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
	const { bridge, review } = options;
	const doc = bridge.doc;
	const awareness = options.awareness ?? new Awareness( doc );

	let localUpdateListener: EngineLocalUpdateListener | null = null;
	let isDocListenerAttached = false;
	let dirty = false;
	let inFlight = false;
	let inFlightProposalId: string | null = null;
	let proposalCounter = 0;
	let lastProposedContent: string | null = null;
	let lastProposedProperties: Record< string, unknown > = {};
	let pendingCanonical: {
		version: string;
		content: string;
		properties?: Record< string, unknown >;
	} | null = null;
	// Canonical content BY VERSION, as the server sent it (the doc may
	// hold kept local blocks, so its serialization is not the canonical
	// string). The descriptor builder (TODO-2a) needs the exact content
	// of the proposal's declared base version. Bounded: old versions can
	// never become a proposal base again.
	const canonicalContents = new Map< string, string >();
	const recordCanonicalContent = ( version: string, content: string ) => {
		canonicalContents.set( version, content );
		while ( canonicalContents.size > 8 ) {
			const oldest = canonicalContents.keys().next().value as string;
			canonicalContents.delete( oldest );
		}
	};

	function buildProposal(): EngineUpdate {
		proposalCounter += 1;
		lastProposedContent = bridge.buildContent();
		lastProposedProperties = bridge.buildProperties();
		inFlightProposalId = `p-${ doc.clientID }-${ proposalCounter }`;
		// Per-block base honesty (TODO-2b): blocks kept through colliding
		// incorporations declare the version their text was really
		// written against, so the server merges them from THEIR base
		// instead of reading a clean sole-writer change.
		const blockBaseVersions = bridge.blockBaseVersions();
		const baseVersion = bridge.lastVersion() ?? '';
		// The block-native descriptor (TODO-2a): TAMPER EVIDENCE the
		// server validates against the PLAIN declared base and then
		// drops (merge outcomes are identical either way — the server
		// derives the same update itself). Built only when this session
		// still holds the base version's exact canonical string; omitted
		// otherwise (the server's engine-unaware-writer lane covers
		// descriptor-less proposals).
		const baseContent = canonicalContents.get( baseVersion );
		let clientUpdate = null;
		if ( undefined !== baseContent && null !== lastProposedContent ) {
			try {
				clientUpdate = buildDeRtcClientUpdate(
					baseContent,
					lastProposedContent,
					`client-${ doc.clientID }`
				);
			} catch {
				clientUpdate = null; // Evidence is optional; never block the save.
			}
		}
		const payload = {
			proposalId: inFlightProposalId,
			baseVersion,
			...( Object.keys( blockBaseVersions ).length > 0
				? { blockBaseVersions }
				: {} ),
			proposedContent: lastProposedContent,
			// The FULL property map every time (save-centric, like the
			// content): the server three-way-diffs it against the base, so
			// unchanged properties are no-ops and an abandoned escalation
			// self-heals on the next proposal.
			proposedProperties: lastProposedProperties,
			clientUpdate,
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

	function applyOrDeferCanonical(
		version: string,
		content: string,
		properties?: Record< string, unknown >
	): void {
		if ( dirty || inFlight ) {
			pendingCanonical = { version, content, properties };
			return;
		}
		bridge.applyCanonical( version, content, properties );
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

		// Review-lane rows carry no canonical content; they feed the ledger.
		if ( DE_RTC_PROPOSAL_PARKED_TYPE === update.type ) {
			if (
				'string' === typeof decoded?.proposalId &&
				'' !== decoded.proposalId &&
				'string' === typeof decoded?.reason
			) {
				review?.noteParked( {
					...decoded,
					changedBlocks: Array.isArray( decoded.changedBlocks )
						? decoded.changedBlocks
						: [],
				} as DeRtcParkedProposal );
			}
			return;
		}
		if ( DE_RTC_RESOLVED_TYPE === update.type ) {
			if ( 'string' === typeof decoded?.proposalId ) {
				review?.noteResolved( decoded.proposalId );
			}
			return;
		}

		if (
			'string' !== typeof decoded?.version ||
			'string' !== typeof decoded?.content
		) {
			return;
		}

		const rowProperties =
			decoded.properties && 'object' === typeof decoded.properties
				? ( decoded.properties as Record< string, unknown > )
				: undefined;

		// The revert-edit undo manager (TODO-16) derives from canonical
		// rows: feed it every row, tagging our own accepted proposals.
		if (
			DE_RTC_CONTENT_TYPE === update.type ||
			DE_RTC_SNAPSHOT_TYPE === update.type
		) {
			if (
				'string' === typeof decoded.version &&
				'string' === typeof decoded.content
			) {
				// The descriptor builder's base-content ledger (TODO-2a).
				recordCanonicalContent( decoded.version, decoded.content );
			}
			options.undoFeed?.noteRow( {
				version: decoded.version,
				baseVersion:
					'string' === typeof decoded.baseVersion
						? decoded.baseVersion
						: null,
				content: decoded.content,
				own:
					DE_RTC_CONTENT_TYPE === update.type &&
					decoded.authorClientId === doc.clientID,
				...( 'number' === typeof decoded.author
					? { author: decoded.author }
					: {} ),
				...( 'number' === typeof decoded.authorClientId
					? { authorClientId: decoded.authorClientId }
					: {} ),
			} );
		}

		switch ( update.type ) {
			case DE_RTC_SNAPSHOT_TYPE:
				applyOrDeferCanonical(
					decoded.version,
					decoded.content,
					rowProperties
				);
				return;

			case DE_RTC_CONTENT_TYPE:
				if (
					decoded.authorClientId === doc.clientID &&
					decoded.proposalId === inFlightProposalId
				) {
					// The accepted row for OUR CURRENT proposal, merged by
					// the server: the in-flight slot is free again
					// (dispositions confirm the same thing when this row and
					// they share a response). Rows for older proposals fall
					// through to the generic path — settling on them would
					// free the slot early and let a peer row clobber
					// unproposed local edits.
					inFlight = false;
					inFlightProposalId = null;
					if ( decoded.content === lastProposedContent ) {
						// Round-tripped unchanged: the doc already holds this
						// content (plus any NEWER local keystrokes, which an
						// application would clobber). Advance the version
						// only, so the next coalesced chunk proposes against
						// it instead of colliding with our own accepted edit.
						// Properties the server merged from peers (values we
						// did not touch since proposing) still incorporate.
						pendingCanonical = null;
						if ( rowProperties ) {
							bridge.incorporateProperties(
								rowProperties,
								lastProposedProperties
							);
						}
						bridge.advanceVersion( decoded.version );
						settleQueued();
						return;
					}
					if (
						null !== lastProposedContent &&
						bridge.incorporateCanonicalPreservingLocalEdits(
							decoded.version,
							decoded.content,
							lastProposedContent
						)
					) {
						// The server merged peers' work into our proposal:
						// adopt their blocks, keep the blocks we edited since
						// proposing (the next proposal reconciles them), and
						// rebase onto the new version.
						pendingCanonical = null;
						if ( rowProperties ) {
							bridge.incorporateProperties(
								rowProperties,
								lastProposedProperties
							);
						}
						settleQueued();
						return;
					}
				}
				applyOrDeferCanonical(
					decoded.version,
					decoded.content,
					rowProperties
				);
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
			const { version, content, properties } = pendingCanonical;
			pendingCanonical = null;
			bridge.applyCanonical( version, content, properties );
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
			review?.setEmitter( null );
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
			// Resolutions ride the same outbound lane as proposals.
			review?.setEmitter(
				( update ) =>
					localUpdateListener?.( update, update.data.length )
			);
			bridge.onBootstrap( () => maybePropose() );
		},
		receiveUpdate: ( update ) => processRow( update ),
		receiveDispositions( dispositions: EngineDisposition[] ) {
			// ONLY the disposition for the CURRENT in-flight proposal
			// settles the slot: a previous proposal's disposition arrives in
			// the response that follows the one whose rows already settled
			// it, after a NEWER proposal may have gone out. Applied rows
			// have already been (or will be) received as content rows;
			// escalated/voided proposals are abandoned — the canonical state
			// wins locally when it applies.
			const settlesCurrent = dispositions.some(
				( disposition ) => disposition.intentId === inFlightProposalId
			);
			if ( ! settlesCurrent ) {
				return;
			}
			inFlight = false;
			inFlightProposalId = null;
			settleQueued();
		},
	};
}
