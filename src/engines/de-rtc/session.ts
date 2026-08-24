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
import type { DeRtcCommitAdapter } from './commit';
import { buildDeRtcClientUpdate, hashDeRtcContent } from './descriptor';
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
export const DE_RTC_ENGINE_PROTOCOL = 2;

/**
 * Client-sent row type: a whole-content proposal against a named base
 * version. Matches WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL.
 */
export const DE_RTC_PROPOSAL_TYPE = 'proposal';

/**
 * Server-emitted row type: accepted canonical content at a version.
 * Matches WP_De_RTC_Engine::UPDATE_TYPE_CONTENT. Receive-only.
 * LEGACY (protocol 1): rooms written before the announce model still
 * replay these; the server no longer writes them.
 */
export const DE_RTC_CONTENT_TYPE = 'content';

/**
 * Server-emitted row type: a canonical version ANNOUNCEMENT — version,
 * base version, content hash, author attribution, merged properties, NO
 * content (the transport carries advisories, not documents).
 * Matches WP_De_RTC_Engine::UPDATE_TYPE_ANNOUNCE. Receive-only.
 */
export const DE_RTC_ANNOUNCE_TYPE = 'announce';

/**
 * Client-sent row type: request the canonical content when behind
 * (payload `haveVersion`); the server answers in the same poll with one
 * synthesized snapshot row. Matches WP_De_RTC_Engine::UPDATE_TYPE_FETCH.
 */
export const DE_RTC_FETCH_TYPE = 'fetch';

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
	 * The revert-edit undo manager's row feed: every canonical
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

	/**
	 * The commit carrier (Save/Sync inversion): when present, proposals go
	 * through the autosave endpoint instead of transport rows — the
	 * poll lane stays advisory. Absent (collections, unsupported post
	 * types, tests of the transport lane), proposals ride the transport
	 * as before.
	 */
	commit?: DeRtcCommitAdapter;
}

/*
 * How long the doc must be free of LOCAL edits before a canonical
 * snapshot may be applied to it (see the quiet gate inside the session).
 * Module-level so tests can compress the window.
 */
let burstQuietMs = 500;

/**
 * Test-only override for the typing-burst quiet window.
 *
 * @param ms Quiet window in milliseconds (0 disables the deferral).
 */
export function setDeRtcBurstQuietMsForTesting( ms: number ): void {
	burstQuietMs = ms;
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
 *   escalates: it sets the proposal aside as a parked review row (see
 *   review.ts and the framework review panel), and the canonical state
 *   wins locally once applied — a person then decides what to keep.
 *
 * @param options The doc bridge and optional awareness to wrap.
 * @return The transport-facing session codec.
 */
export function createDeRtcSessionCodec(
	options: DeRtcSessionOptions
): EngineSessionCodec & { prepareForSave: () => Promise< () => void > } {
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
	// string). The descriptor builder needs the exact content
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

	/*
	 * Announce-model catch-up state: announcements carry no
	 * content, so the session tracks the highest announced version it
	 * has not reflected yet and fetches canonical content EAGERLY — at
	 * most one fetch in flight, so a busy room costs one canonical
	 * download per poll cycle at worst, not one per version. The
	 * existing deferral (pendingCanonical) holds a fetched snapshot
	 * that arrives mid-burst until the local state settles, so eager
	 * fetching never clobbers local edits — it just has the content
	 * READY at settle instead of adding a round trip then.
	 */
	let behindSeq = 0;
	// The behind-seq a sent fetch will cover; 0 when none is in flight.
	// Cleared when any snapshot arrives (the fetch's answer is always a
	// snapshot of the CURRENT canonical, which covers every announced
	// version at fulfillment time).
	let fetchInFlightSeq = 0;
	// An own proposal the server merged with peers' work (announce hash
	// mismatch): the fetched snapshot for it INCORPORATES (keeping
	// locally-edited blocks, raising contests) instead of applying.
	let pendingOwnMergeSeq = 0;

	const versionSeq = ( version: string | null ): number =>
		version ? parseInt( version.slice( 1 ), 10 ) || 0 : 0;
	const currentSeq = () => versionSeq( bridge.lastVersion() );

	function maybeFetch(): void {
		if (
			! localUpdateListener ||
			behindSeq <= currentSeq() ||
			// Single-flight, with a liveness backstop: a lost or unanswered
			// fetch unsticks as soon as a NEWER version is announced (the
			// wire-inspected soak caught a stuck in-flight fetch turning
			// into an unknown-base-version death spiral).
			fetchInFlightSeq >= behindSeq
		) {
			return;
		}
		fetchInFlightSeq = behindSeq;
		const data = JSON.stringify( {
			haveVersion: bridge.lastVersion() ?? '',
		} );
		localUpdateListener( { data, type: DE_RTC_FETCH_TYPE }, data.length );
	}

	function buildProposal(): EngineUpdate {
		proposalCounter += 1;
		lastProposedContent = bridge.buildContent();
		lastProposedProperties = bridge.buildProperties();
		inFlightProposalId = `p-${ doc.clientID }-${ proposalCounter }`;
		// Per-block base honesty: blocks kept through colliding
		// incorporations declare the version their text was really
		// written against, so the server merges them from THEIR base
		// instead of reading a clean sole-writer change.
		const blockBaseVersions = bridge.blockBaseVersions();
		const baseVersion = bridge.lastVersion() ?? '';
		// The block-native descriptor: TAMPER EVIDENCE the
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

	// While > 0, commits stay queued (dirty accumulates): the save lane
	// holds commits so an editor save can never race the session's own
	// in-flight commit into a self-conflict (both-changed-same-block
	// parks — found by the fuzzer the moment commits moved to REST).
	let commitsHeld = 0;

	/*
	 * The commit-cadence dial (TODO/B4): minimum spacing between commits,
	 * in milliseconds. 0 (the default) keeps the settle cycle — a commit
	 * whenever local edits settle and the slot is free (pseudo-realtime).
	 * The Distributed Editing vision's operating point is ~10 s: edits
	 * coalesce locally and the room advances at save-and-sync cadence,
	 * cutting request rate and upload bytes on cheap hosts. Read from the
	 * plugin settings the enqueue localizes; the dial changes WHEN a
	 * commit is built, never what it contains — dirty coalescing already
	 * batches everything since the last commit.
	 */
	const commitIntervalMs = ( () => {
		const settings = (
			window as Window & {
				_gutenbergSyncEnginesSettings?: {
					deRtcCommitIntervalMs?: number;
				};
			}
		 )._gutenbergSyncEnginesSettings;
		const value = Number( settings?.deRtcCommitIntervalMs ?? 0 );
		return Number.isFinite( value ) && value > 0 ? value : 0;
	} )();
	let lastCommitBuiltAt = 0;
	let cadenceTimer: ReturnType< typeof setTimeout > | null = null;

	function maybePropose(): void {
		if (
			! dirty ||
			inFlight ||
			commitsHeld > 0 ||
			// The server merged peers' work into our last proposal and the
			// catch-up snapshot has not landed: we know a newer version
			// exists but not what it contains, so bridge.lastVersion() is
			// already stale. Proposing now would declare that dead base,
			// and the server would three-way-merge our OWN just-accepted
			// keystroke as a foreign concurrent change — both sides
			// changed the block, so it parks and canonical wins. The rest
			// of a typing burst evaporated that way (e2e, slow CI hosts:
			// " from two" collapsing to " "). Queue instead: dirty holds
			// the burst until the snapshot settles it against the version
			// it was really written on top of.
			pendingOwnMergeSeq > 0 ||
			! localUpdateListener ||
			! bridge.isBootstrapped()
		) {
			return;
		}
		if ( commitIntervalMs > 0 ) {
			const wait = lastCommitBuiltAt + commitIntervalMs - Date.now();
			if ( wait > 0 ) {
				// Hold the commit to the dial's cadence; dirty keeps
				// coalescing and ONE timer re-enters at the boundary.
				if ( null === cadenceTimer ) {
					cadenceTimer = setTimeout( () => {
						cadenceTimer = null;
						maybePropose();
					}, wait );
				}
				return;
			}
		}
		lastCommitBuiltAt = Date.now();
		const update = buildProposal();
		dirty = false;
		inFlight = true;
		if ( options.commit ) {
			/*
			 * The Save/Sync inversion: the commit rides the autosave
			 * endpoint, not the transport — the poll lane stays advisory
			 * (announces, on-demand snapshots, review rows, presence).
			 * The response returns the rows this commit appended plus the
			 * dispositions, and the ordinary row machinery settles them.
			 */
			void commitThroughSave( update );
			return;
		}
		localUpdateListener( update, update.data.length );
	}

	let commitRetryTimer: ReturnType< typeof setTimeout > | null = null;
	async function commitThroughSave( update: EngineUpdate ): Promise< void > {
		try {
			const response = await options.commit!( update );
			for ( const row of response.updates ?? [] ) {
				processRow( row );
			}
			if ( response.dispositions?.length ) {
				handleDispositions( response.dispositions );
			}
		} catch {
			/*
			 * Transport failure or retryable contention (503): the edits
			 * are still in the doc — free the slot and retry shortly. A
			 * commit whose response was LOST after the server applied it
			 * re-proposes idempotently (the server merges a re-send of
			 * already-applied content as a no-op fast-forward).
			 */
			inFlight = false;
			inFlightProposalId = null;
			if ( null === commitRetryTimer ) {
				commitRetryTimer = setTimeout( () => {
					commitRetryTimer = null;
					dirty = true;
					maybePropose();
				}, 2000 );
			}
		}
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

	/*
	 * Typing-burst quiet gate: applying ANY canonical snapshot to the doc
	 * mid-burst clobbers the canvas — the framework pushes the rewritten
	 * blocks into the editor, the block under the caret remounts, and the
	 * user's REMAINING keystrokes land in a detached node and vanish (the
	 * "Second from two" -> "Second " e2e collapse; the dirty/inFlight
	 * deferral has a hole exactly one inter-keystroke gap wide, where
	 * dirty is momentarily false). Snapshots that arrive while the user
	 * typed within the last BURST_QUIET_MS are stashed (newest wins) and
	 * re-injected through processRow once the burst quiets — the same
	 * reason intent-log defers its capture-driven pushes past the burst.
	 */
	let lastLocalEditAt = 0;
	let deferredSnapshotRow: EngineUpdate | null = null;
	let quietRetryTimer: ReturnType< typeof setTimeout > | null = null;

	function typingQuiet(): boolean {
		return Date.now() - lastLocalEditAt >= burstQuietMs;
	}

	function scheduleQuietRetry(): void {
		if ( null !== quietRetryTimer ) {
			return;
		}
		quietRetryTimer = setTimeout( () => {
			quietRetryTimer = null;
			if ( ! typingQuiet() ) {
				scheduleQuietRetry();
				return;
			}
			const row = deferredSnapshotRow;
			deferredSnapshotRow = null;
			if ( row ) {
				processRow( row );
			}
		}, burstQuietMs );
	}

	function onDocUpdate( _update: Uint8Array, origin: unknown ): void {
		if ( DE_RTC_REMOTE_ORIGIN === origin ) {
			return;
		}
		lastLocalEditAt = Date.now();
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
			( 'string' !== typeof decoded?.content &&
				// Announce rows carry a hash, never content.
				DE_RTC_ANNOUNCE_TYPE !== update.type )
		) {
			return;
		}

		const rowProperties =
			decoded.properties && 'object' === typeof decoded.properties
				? ( decoded.properties as Record< string, unknown > )
				: undefined;

		// The revert-edit undo manager derives from canonical
		// rows: feed it every row, tagging our own accepted proposals.
		if (
			DE_RTC_CONTENT_TYPE === update.type ||
			DE_RTC_SNAPSHOT_TYPE === update.type
		) {
			if (
				'string' === typeof decoded.version &&
				'string' === typeof decoded.content
			) {
				// The descriptor builder's base-content ledger.
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
			case DE_RTC_ANNOUNCE_TYPE: {
				if ( 'string' !== typeof decoded.version ) {
					return;
				}
				const announcedSeq = versionSeq( decoded.version );
				if (
					decoded.authorClientId === doc.clientID &&
					decoded.proposalId === inFlightProposalId
				) {
					// The announcement for OUR CURRENT proposal: the slot
					// frees either way.
					inFlight = false;
					inFlightProposalId = null;
					if (
						null !== lastProposedContent &&
						'string' === typeof decoded.contentHash &&
						hashDeRtcContent( lastProposedContent ) ===
							decoded.contentHash
					) {
						// Round-tripped unchanged (canonicalized-hash
						// equality — the wire-safe twin of the old byte
						// compare; every server-side comparison
						// canonicalizes the same way): advance without any
						// content download, the announce model's win for
						// the active typist. Properties the server merged
						// from peers still incorporate (they ride the
						// announce).
						pendingCanonical = null;
						recordCanonicalContent(
							decoded.version,
							lastProposedContent
						);
						if ( rowProperties ) {
							bridge.incorporateProperties(
								rowProperties,
								lastProposedProperties
							);
						}
						options.undoFeed?.noteRow( {
							version: decoded.version,
							baseVersion:
								'string' === typeof decoded.baseVersion
									? decoded.baseVersion
									: null,
							content: lastProposedContent,
							own: true,
							...( 'number' === typeof decoded.author
								? { author: decoded.author }
								: {} ),
							authorClientId: doc.clientID,
						} );
						bridge.advanceVersion( decoded.version );
						if ( announcedSeq >= behindSeq ) {
							behindSeq = 0;
						}
						settleQueued();
						return;
					}
					// The server merged peers' work into our proposal: the
					// fetched snapshot for it must INCORPORATE (keep
					// locally-edited blocks, raise contests) rather than
					// apply wholesale.
					pendingOwnMergeSeq = announcedSeq;
				}
				if ( announcedSeq > currentSeq() && announcedSeq > behindSeq ) {
					behindSeq = announcedSeq;
				}
				// Eager: the fetched content defers if we're mid-burst; it
				// must be READY at settle, not a round trip away.
				maybeFetch();
				return;
			}

			case DE_RTC_SNAPSHOT_TYPE: {
				if ( ! typingQuiet() ) {
					// Mid-burst: stash (newest wins) and re-inject at quiet
					// (see BURST_QUIET_MS above).
					deferredSnapshotRow = update;
					scheduleQuietRetry();
					return;
				}
				const snapshotSeq = versionSeq( decoded.version );
				// The in-flight fetch is answered; anything announced since
				// re-fetches below (via settleQueued's maybeFetch tail).
				fetchInFlightSeq = 0;
				if ( snapshotSeq >= behindSeq ) {
					behindSeq = 0;
				}
				if (
					pendingOwnMergeSeq > 0 &&
					snapshotSeq >= pendingOwnMergeSeq &&
					null !== lastProposedContent &&
					bridge.incorporateCanonicalPreservingLocalEdits(
						decoded.version,
						decoded.content,
						lastProposedContent
					)
				) {
					// The catch-up for our merged proposal: adopt the
					// blocks we did not touch since proposing, keep the
					// ones we did (contested items raise as usual).
					pendingOwnMergeSeq = 0;
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
				if ( snapshotSeq >= pendingOwnMergeSeq ) {
					// The snapshot supersedes the pending merge (or the
					// incorporation could not align structurally); the
					// wholesale apply below resolves the room state either
					// way. An OLDER snapshot (a replayed genesis) keeps the
					// marker for the real catch-up.
					pendingOwnMergeSeq = 0;
				}
				applyOrDeferCanonical(
					decoded.version,
					decoded.content,
					rowProperties
				);
				if ( ! inFlight ) {
					settleQueued();
				}
				return;
			}

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

	/**
	 * Settles a disposition batch — shared by the transport lane and the
	 * commit lane (both deliver rows FIRST, dispositions after).
	 *
	 * @param dispositions Disposition batch.
	 */
	function handleDispositions( dispositions: EngineDisposition[] ): void {
		/*
		 * A voided proposal (a base that aged out of the snapshot
		 * window, a rejected descriptor) means our base is no longer
		 * mergeable: catch up NOW. Clear any stuck fetch marker and
		 * mark ourselves behind — a void frequently follows exactly
		 * the starvation that lost a fetch.
		 */
		const voided = dispositions.some(
			( disposition ) => 'voided' === disposition.status
		);
		if ( voided ) {
			fetchInFlightSeq = 0;
			behindSeq = Math.max( behindSeq, currentSeq() + 1 );
		}
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
			if ( voided ) {
				maybeFetch();
			}
			return;
		}
		inFlight = false;
		inFlightProposalId = null;
		settleQueued();
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
		if ( ! inFlight ) {
			// Announce model: settled and still behind an announced
			// version whose content never arrived — fetch it now.
			maybeFetch();
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
		// The server compacts by itself (no client-side compaction), and
		// unknown-outcome recovery is answered with an idempotent
		// re-proposal of the doc's current state: if the lost send was
		// applied, the merge settles as a no-op; if it was lost, this
		// carries the same edits.
		createRecoveryUpdate: () => buildProposal(),
		destroy() {
			if ( isDocListenerAttached ) {
				doc.off( 'update', onDocUpdate );
				isDocListenerAttached = false;
			}
			if ( null !== commitRetryTimer ) {
				clearTimeout( commitRetryTimer );
				commitRetryTimer = null;
			}
			if ( null !== quietRetryTimer ) {
				clearTimeout( quietRetryTimer );
				quietRetryTimer = null;
			}
			if ( null !== cadenceTimer ) {
				clearTimeout( cadenceTimer );
				cadenceTimer = null;
			}
			deferredSnapshotRow = null;
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
		receiveDispositions: ( dispositions: EngineDisposition[] ) =>
			handleDispositions( dispositions ),
		/**
		 * Prepares an editor SAVE: holds new commits and waits for the
		 * in-flight one to settle, so the save can never self-conflict
		 * with the session's own commit. Returns the release; the save
		 * lane calls it when its request finishes (either way).
		 *
		 * @return Release function.
		 */
		async prepareForSave(): Promise< () => void > {
			// Flush FIRST (holding would block the very settling we wait
			// for), then hold new commits for the save's duration.
			const deadline = Date.now() + 4000;
			while ( ( dirty || inFlight ) && Date.now() < deadline ) {
				maybePropose();
				await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
			}
			commitsHeld++;
			let released = false;
			const release = () => {
				if ( released ) {
					return;
				}
				released = true;
				commitsHeld--;
				maybePropose();
			};
			// A keystroke may have slipped a commit in between the last
			// check and the hold: wait that one out too.
			while ( inFlight && Date.now() < deadline ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
			}
			return release;
		},
	};
}
