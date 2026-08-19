# Choosing a sync engine and transport

This plugin exists to make the engine/transport decision a matter of
evidence. This guide is the interpretation layer for that evidence: the
principles we hold every engine to, how each engine measures against them,
what each engine actually is, how data flows through it in the scenarios
that matter, and an honest account of current state versus desired state.
Read it alongside the two benchmark harnesses
(`tests/benchmarks/README.md` and `tests/benchmarks/transport/README.md`).

To regenerate every number behind this guide on YOUR hardware, run
`npm run bench` against a running tests env — the whole decision matrix,
with per-scenario comparison tables and hosting cost cards, failing loudly
if any engine loses work. `npm run bench -- certify=10` re-certifies the
never-lose-work invariant across ten seeds per engine.

## Core principles

These are not aspirations; they are the acceptance criteria this project
is judged against. They synthesize the team's problem statement and
principles ([Collaborative editing: problems and
strategies](https://collaborativeediting.wordpress.com/2026/08/04/collaborative-editing-problems-and-strategies/))
and the Distributed Editing design principles ([Distributed Editing with
unlimited
Codex](https://collaborativeediting.wordpress.com/2026/07/02/distributed-editing-with-unlimited-codex/)).
Every engine decision below is measured against them, and every violation
is named — including the ones our own porting choices introduced.

- **P1 — The server is the authority.** WordPress stands in the path of
  every update: it authorizes, attributes, and can inspect each one at
  ingest. A relay that cannot say who wrote what cannot enforce
  capabilities — an admin's save must never launder a script tag an
  author injected into the shared document. The retired yjs-relay engine
  failed this principle, and that is why it was retired.
- **P2 — No edit is ever silently lost.** Reloads, network loss, delayed
  saves, out-of-band writes: the design must degrade toward escalation
  and review, never toward disappearance. The benchmark's zero-lost-work
  oracle certifies this per engine on every scenario.
- **P3 — Real conflicts are surfaced, not hidden.** "Conflict-free"
  hides conflicts. When changes overlap meaningfully, the system detects
  it and asks a human — while taking care not to overburden humans with
  constant review. The escalation rate on contended workloads is a
  first-class metric, not an afterthought: too high is a failure of
  mergeability, and *silently zero is a failure of honesty*.
- **P4 — Collaboration is not just for humans.** Agents, CLI tools,
  REST/XML-RPC integrations, and plugins must be able to use existing
  WordPress APIs without disrupting collaborative sessions — and ideally
  participate in them meaningfully. A scheduled integration that
  read-modify-writes a post must not erase five minutes of two editors'
  work with no record that a conflict existed.
- **P5 — Cheap hosting is normal hosting.** Functional everywhere,
  progressively enhanced where the host commits resources. Nothing on
  the core path may assume database or process topology beyond what
  WordPress Core itself assumes. Locks, in particular, must be
  implemented the way Core would implement them (see TODO-1).
- **P6 — Host economics are measured, not asserted.** Resource usage is
  demonstrated with repeatable benchmarks. This guide deliberately
  carries no numbers (they go stale and mislead); it describes stable
  shapes and points at `npm run bench`.
- **P7 — Capture intent and identity, not snapshots.** Semantic
  operations ("split block", "move block") and stable block identity
  make merges match what the user actually did. Diffing before/after
  snapshots reconstructs a guess — and a guess is what mangles prose
  when edits collide.

## The engines

- **intent-log**: The server owns the document and every edit is a typed
  intent the server transforms against the log, so it can say exactly how
  every edit settled. It parks genuine conflicts for human review instead
  of auto-merging them.
- **yjs-server**: The server owns the document AND it is a CRDT. The
  vendored y-php library merges every update into a canonical room
  document server-side, the server compacts by itself and materializes
  post content, while clients keep the CRDT machinery inherited from the
  retired relay engine (same wire documents, same undo).
- **de-rtc**: The server owns the document and clients never merge.
  Each client proposes its WHOLE content against the version it last
  incorporated, and the server three-way-merges every proposal. Most
  edits merge cleanly, and genuine conflicts escalate for a human
  decision instead of auto-merging.

**Retired: yjs-relay**: The incumbent design had clients own the document.
The server stored and forwarded opaque CRDT updates it could not inspect.
Its shared client modules live on in `src/engines/yjs/` (constants, doc
schema, snapshot, undo), and yjs-server's wire documents remain
byte-compatible with rooms it wrote. Where the relay appears below it is
as historical context.

## One architectural choice drives everything

- **Merging on the server** costs server CPU (and, for intent-log, a
  per-room Core-style lock) and in exchange the server can
  *observe* outcomes: per-edit dispositions (applied / escalated /
  voided), a convergence oracle in the benchmark, a review lane for
  conflicts, and capability enforcement at ingest (an author without
  `unfiltered_html` gets raw-HTML content parked for review by someone
  who has it — the server is not relaying bytes it cannot inspect). This
  is P1 made concrete.
- **Merging on the clients (the retired yjs-relay)** made the server
  nearly free — append a row, read rows — and in exchange the server
  could observe *nothing*: no merge outcomes, no conflict surfacing, no
  content-level capability enforcement before save, and no benchmarkable
  quality metrics (the harness printed "NOT SERVER-OBSERVABLE" rather
  than faking them). That blindness, plus unbounded growth in abandoned
  rooms, is why the relay was retired in favor of yjs-server.

None of the three remaining engines is strictly better; they price the
same work differently. The scorecard and tables below are how the price
shows up.

## Scorecard: engines against the principles

| Principle | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| P1 server authority | **Meets.** Typed intents authorized, attributed, and transformed at ingest | **Meets.** Server merges and materializes; per-update dispositions | **Meets.** Server merges every proposal; per-proposal dispositions with version lineage |
| P2 no silent loss | **Meets, one window.** Oracle-certified; unacked outbox intents die with a tab reload (undo makes the loss visible) | **Meets.** Oracle-certified; races heal via idempotent full-state recovery | **Meets.** Oracle-certified; escalations park durably, voided proposals re-propose |
| P3 conflicts surfaced | **Meets.** Per-register review lane; residual over-escalation on same-paragraph bursts (rate, not silence) | **Violates, by documented policy (TODO-7 decided).** Register conflicts resolve by silent CRDT last-writer-wins; no review lane. Stated on the settings screen; pinned by the escalation-criteria fixture | **Meets (since TODO-2b/3).** Conflicts park for review at BLOCK grain (per-block salvage: the clean remainder lands, exactly the conflicted blocks park; whole-proposal parking only for structural divergence), and the client-side same-block LWW is retired: kept blocks declare their TRUE base (`blockBaseVersions`), so real same-block concurrency merges when non-overlapping and parks when it overlaps. Residual: a client that omits the map (legacy/simple writers) still presents sole-writer changes |
| P4 machine writers | **Met for read-modify-write.** `wp_update_post( …, 'intent_log_base_seq' => N )` (REST: `base_seq`) diffs the save against the declared base by persisted syncId and authors typed intents — transforms merge concurrent work, collisions park for review, the save lands as merged canonical (TODO-4b). Unaware writers still bypass the room (no detection stamp) | **Accepted limitation.** Ingest speaks binary CRDT updates; a diff-to-CRDT lane would be semantically worse, not just costly (TODO-4) | **Met.** Unaware writers heal in (TODO-14, scenario F) and cooperating writers merge through the room: `wp_update_post( …, 'base_version' => 'vN' )` — WP-CLI, plugins, REST (`base_version` param on posts/pages) — three-way-merges via the ingest lane with per-block salvage and review parking; conflicts reject the save with a rich 409 (TODO-4a) |
| P5 cheap hosting | **Meets.** Cheapest per-ingest CPU; Core-style options-row lock, topology-safe (TODO-1 done) | **Partially.** No lock (good); heaviest per-ingest CPU, scaling with document size | **Partially.** Cheap CPU; lock-free optimistic claims, topology-safe (TODO-1 done); wire/storage bytes still scale with document size |
| P6 measured economics | **Meets.** Real wire format in its benchmark profile | **Meets.** Real wire format; convergence oracle | **Meets.** Real wire format; disposition/lineage oracle |
| P7 intent & identity | **Meets.** Typed intents end-to-end; syncIds persist in saved `post_content` and round-trip genesis | **Fails.** Snapshot-diff binding inherited from the relay; no semantic ops, no stable identity in the merge | **Designed for it, wired (since TODO-2a).** Block identity + rich-text ops live in the merge core, and sessions now author the block-native descriptor client-side (hash-pinned base/proposed + operation fingerprints, byte-parity with the PHP derivation enforced by 75 PHP-generated vectors); the server validates it once against the plain declared base and rejects mismatches (`de_rtc_sync_meta_tampered`). Merge outcomes remain server-derived and identical either way — the descriptor is a P1 integrity surface |

Three honest readings of that table:

- **intent-log** is the closest fit to the principles today. Its risks
  are rate problems, not design problems: same-paragraph bursts can
  over-escalate while an editor is behind on a peer's change, and undo
  arms only after the settle round trip. Both are tracked (TODO-5,
  TODO-6).
- **yjs-server** is a deliberate P3/P7 trade: it buys the lowest
  escalation rate and lock-free ingest by hiding conflicts. That is a
  defensible *policy* only if we say it out loud everywhere the engine
  is offered — and we still owe a decision on whether conflict detection
  ever gets built (TODO-7).
- **de-rtc** is the engine whose upstream vision aligns best with P3/P4
  — and our RTC adaptation is currently *less* faithful to that vision
  than the save-centric original. The client-side keep-local rebase
  (block-level last-writer-wins) and whole-proposal conflict parking are
  artifacts of our port, not of Dennis's design; the original resolves
  the same situations through explicit human adoption of pending edits
  and per-block partial acceptance. Restoring fidelity is a program,
  not a patch: TODO-2b and TODO-3 treat symptoms, and the fidelity audit
  below (TODO-12 through TODO-20) treats causes.

## Fidelity to the DE-RTC vision

The de-rtc engine is a port of a *design*, not just of code — and the
design has an author. This section audits our adaptation against the
vision stated in [Distributed Editing with unlimited
Codex](https://collaborativeediting.wordpress.com/2026/07/02/distributed-editing-with-unlimited-codex/)
and, secondarily, against the wordpress-develop prototype. The
standard, per project direction: **compromises made so DE-RTC fits this
plugin's protocol or transports are not acceptable.** It is fine for
DE-RTC to work differently from the other engines, and fine for it not
to support every transport. "Faithful" below means the vision survived
our port; "corrupted" means our adaptation replaced a vision element
with something protocol-convenient.

| Vision element | The vision / prototype | Our port | Verdict |
| --- | --- | --- | --- |
| Save and Sync are distinct, deliberate operations; "pending edits" are the unit of adoption | Editors confirm their own changes and *choose* to adopt others'; sync may be polled, socketed, or run manually with long delays | Contested-only pending, v1 behavior layer: clean merges keep flowing live, but a peer's edit landing on a block you are editing raises ONE pending item (merge-not-stack — later peer edits refresh it) resolved by explicit Adopt (take the latest canonical) or Reject (keep yours; per-block base honesty makes the next merge honest). Editor saves carry `base_version` through the room. The deeper inversion — canonical advances only at saves, no auto-propose — remains open | **Partially restored** (TODO-12 v1 done; the same-block LWW of scenario C is dissolved — a contest, never a silent overwrite) |
| Sync metadata co-located with saved `post_content` (a `wp/post-sync-meta` pseudo-block); revisions become a backup mechanism | The document's history travels with the post; any writer that round-trips content carries the lineage; recovery mines revisions and autosaves | Restored as write-through: every save of a de-rtc-roomed post embeds the room's sync-meta (upstream's exact grammar) at the content edge, revisions copy it, and genesis adopts it back — resuming the version lineage after a room reset. Room meta remains the *working* store; the full inversion (post as sole durable store) rides with TODO-12/architecture item 2 | **Restored (write-through)** (TODO-13 done) |
| Self-healing when unaware writers mangle the document | The server detects CRDT/content divergence, recovers from revisions or autosaves, and appends a repairing edit so "operations which would otherwise wipe-out a post appear as any other collaborative update" | Restored: room load detects out-of-band `post_content` writes (the co-location `content_hash` stamp is the tell), three-way-merges meta-carrying external edits with concurrent session work, converges to meta-less replacements, refuses to roll back stale copies, and parks genuine conflicts for review. Revision *mining* for lost bases is TODO-15 | **Restored** (TODO-14 done; scenario F) |
| Arbitrarily long offline editing still recombines | Old bases recoverable via the co-located history and revision copies | Restored: a base past the room's 20-version window resolves from post revisions (each aware save embeds its own snapshot window, hash-verified), so deep-lag proposals merge with intervening work intact; only a base no revision carries still voids | **Restored** (TODO-15 done) |
| Undo/redo "never undo, but rather apply revert edits"; a history slider scrubs versions | Explicitly offered to RTC: "This could easily be adopted by RTC" | Restored: de-rtc's undo derives revert edits from the client's OWN accepted canonical rows (per-block, with an untouched-since guard so peer work is never collateral) and applies them as ordinary dirty edits that propose like any change; redo re-applies the reverted delta. The history-slider UI remains TODO-12-era editor UX | **Restored** (TODO-16 done) |
| Reviewers can modify before adopting | The prototype's review schema carries `reviewed_block_source` ("modify-and-adopt"); approvals are hash-pinned | Restored: `restoreProposalWithChanges()` applies the reviewer's edited replacements for specific parked blocks — what the reviewer supplies is exactly what applies and re-proposes under their capability, pinning approval and content by construction. API-level; the review panel UI still offers plain restore/dismiss | **Restored (API)** (TODO-17 done) |
| Per-edit authorship: "hover over a user's avatar and highlight the changes they applied" | Range-grain attribution; the prototype shipped authorship-focus overlays | Data surface restored: content rows carry the server-stamped author user id, and the client derives block-grain "who last touched this" from row-vs-base diffs at zero extra wire cost (`engine.authorship.getBlockAuthorship()`; structural shifts reset to unknown rather than lie). The hover overlay is TODO-12-era UX; range grain needs descriptors (TODO-2a) | **Restored (data)** (TODO-18 done) |
| Per-block kses sequestration — "accept partial edits, adopting the safe parts" | Prototype-proven | Restored as the shipping capability lane | **Faithful** |
| The shipping merge is the hand-written block-aware three-way merge; Automerge backs only the legacy lane | Same | Same — ported verbatim as a frozen call-graph closure | **Faithful** |
| Optimistic concurrency; no database lock | Base-version preflight, hash validation, merge-and-retry on the save path | Lock-free again: accepted proposals atomically claim their version advancement and a lost claim reloads + re-merges (`WP_Sync_Atomic_Option` CAS) | **Restored** (TODO-1 done) |
| Clients need no CRDT library; Gutenberg couples via semantic Redux actions | Stage 3 of the development plan | The client rides a `Y.Doc` editor bridge (undo + awareness reuse); sessions author the block-native descriptor (TODO-2a done) but the doc bridge itself remains a CRDT editor adapter | **Adaptation debt narrowed.** TODO-2b/2a done; the Y.Doc bridge remains (architecture item 5) |
| Cheap-host cadence is a feature: "that $3/mo host … can still support multiple concurrent edit sessions polling … once every ten seconds" | Polling interval scales to the host's comfort; presence is separate from content | Measured fairly now: the `save-sync-session` scenario runs every engine at the vision's cadence — where de-rtc escalates nothing and intent-log becomes the escalation-heavy engine (its stale-observation residual), inverting the per-second ranking | **Measured** (TODO-19 done); operating cadence itself is TODO-12 |

The pattern across the corrupted rows is one pattern: wherever DE-RTC's
save-centric, post-co-located design met this plugin's room protocol,
the protocol won. The fidelity program reverses that default.

## Feature parity

| Area | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge, but ON THE SERVER — outcomes observable, still no review lane (conflict DETECTION is the undesigned prerequisite) | Three-way merge on the server; genuine conflicts PARK as durable `proposal-parked` rows and present in the same review panel (restore re-proposes under the reviewer; dismiss resolves; retention survives compaction — e2e-verified) |
| Collaborative undo | Inverse intents over the accepted log (`src/engines/intent-log-undo.ts`): per-user undo/redo, transformed over peers' rows, conflicts park for review. Armed immediately: a still-pending unit CANCELS (outbox + a wire-chasing `cancel` row; a lost race resurrects the unit as a settled candidate — TODO-5), a settled unit inverts | Per-peer undo manager (`src/engines/yjs/undo.ts`, inherited from the retired relay) | Revert-edit undo (TODO-16, the vision's model): undo derives a revert from the client's own accepted canonical rows (per-block, untouched-since guard) and proposes it as an ordinary new change; redo re-applies the reverted delta |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | Server holds the canonical doc; a rejoining client re-bootstraps from the retained snapshot + tail and uploads its own state idempotently. Solo edits flush every poll (`syncWhileSolo`) — REQUIRED here, not an optimization: a page reload holds no local state to upload, so a room that never saw the solo session's updates would bootstrap the editor back to its stale snapshot, wiping the freshly loaded record (e2e-covered: the solo save-and-reload spec) | Server holds canonical content + version snapshots; a rejoining client re-bootstraps from the retained snapshot + content rows. Un-acked local edits re-propose (the server merges); the save-centric model keeps the room tracking saves, so a solo save-and-reload survives without `syncWhileSolo` (verified) |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update, IDEMPOTENT server-side (the server diffs out what it already has — redelivery settles as a benign `already-merged` void); the server explicitly requests it with a `resync-required` void when an update's dependencies are missing from the room | Recovery re-proposes the doc's current state; if the lost send landed, the re-proposal merges as a no-op |
| History compaction | Server checkpoints every 100 intent rows and trims | Server checkpoints every 100 rows and trims — abandoned rooms stay bounded | Server checkpoints every 100 rows and trims (same retention invariant) |
| Genesis | Server, from post content | Server, from post content — deterministic build, so racing initializers merge idempotently | Server, from post content — deterministic, and ADOPTS an upstream DE-RTC sync-meta block if one is embedded (version lineage continues) |
| Capability enforcement | At ingest (kses lane; escalation for `unfiltered_html`-gated content parks for approval — restore by a privileged reviewer IS the approval) | At ingest, sanitize-and-compensate: blocks a filtered author's batch touched that kses would rewrite are REPLACED with their sanitized form and the compensating delta broadcasts (filter-on-save semantics; nothing parks — coarser than intent-log by design) | At ingest, per-block SEQUESTRATION (upstream's model): risky blocks revert to their base form and park for review while the safe remainder of the proposal merges and lands; markup-bearing property values park per property; restore under a privileged reviewer approves. Whole-proposal escalation remains the fallback (freeform boundaries) |
| Synced entity properties | The framework's full set as per-name registers: the scalar whitelist (title, excerpt, slug, status, comment_status, ping_status, format, sticky, author, featured_media, date, template), attached taxonomies (whole term-ID arrays by rest_base), and registered post meta (per-key `meta.<key>` registers, `_crdt_document` excluded). Collection rooms implement the framework's save-notification contract (per-client save registers), so a newly created term reaches every peer's term list by refetch | Whatever the sync config maps into the CRDT (the full framework set, including per-key post meta and taxonomies), and genesis seeds the same shared REST-shaped property map the other engines seed; collection rooms carry the savedAt state key for the same refetch contract | The full flattened register map rides every proposal beside the content (title, scalars, taxonomies, `meta.<key>`); the server three-way-merges per property against the base version — sole-writer changes and agreements apply, concurrent divergent writes park per property for review. Genesis seeds the shared property map |
| Presence/awareness | Yes (shared Yjs-free awareness doc) | Yes (Yjs awareness, relayed opaquely — the server does not decode it) | Yes (Yjs awareness over the doc bridge, relayed opaquely) |
| Server observability | Dispositions, debug envelope, benchmark quality metrics | Per-update dispositions, CRDT convergence oracle, materialization | Per-proposal dispositions (applied/escalated/voided with reasons), version lineage, materialization |
| Materialize to post_content | Yes (server-side; block identity persists as `metadata.syncId` and round-trips genesis) | Yes (server-side, from the canonical doc) | Trivially — the canonical document IS post content |
| Wire format | Small human-readable JSON intents | Opaque base64 binary (V2) + JSON snapshot rows | Human-readable JSON: whole-content proposals up, whole-content canonical rows down (bytes scale with document size) |

## Resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning — the cheapest of the three | Load + merge + re-encode the canonical y-php doc — the dominant cost of the three, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees) — cheap at benchmark sizes, scales with document size |
| Locking | Per-room Core-style options-row lock (`WP_Sync_Room_Lock`, the upgrader pattern: atomic INSERT IGNORE + TTL; 5 s wait budget, contenders get a retryable 503). One claim/release pair of DB writes inside every timed request — the engine benchmark's `calibration` block exists to subtract it. Topology-safe by construction (TODO-1, done) | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | None — lock-free optimistic version claims (`WP_Sync_Atomic_Option` CAS): an accepted proposal atomically claims v(n+1), a lost claim reloads + re-merges, exhaustion returns the retryable 503. Upstream's validate-and-retry model, restored (TODO-1, done) |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows, and since TODO-20 stage 1 accepted proposals store ~200-byte ANNOUNCE rows (version + content hash; canonical content lives once per room, fetched on demand) — row bytes no longer scale with document size, closing the TODO-10 soak's PHP-memory cliff |
| Row contents | Small JSON intents + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Full-content JSON rows (content + version + attribution) + snapshot rows, plus canonical content and version snapshots in room meta |

In plain terms, the locking row is a pro/con set. intent-log: one edit
merges at a time per post — concurrent editors wait briefly, and under
heavy load a request may be told to retry; in exchange, nothing ever
needs to resync. de-rtc: nobody waits — a request that loses the
version race redoes its merge against the fresh state (duplicate merge
CPU under contention, still nothing to resync), and only sustained
contention turns into a retry. yjs-server: nobody ever waits — in
exchange, every concurrent request redundantly pays the full merge
cost, and a client is occasionally asked to re-upload its state (one
extra round trip, nothing lost). Same contention, three currencies:
latency queueing; re-merge CPU; duplicate CPU plus occasional resync.

This guide deliberately carries NO measured numbers: they vary by
machine, PHP build, and code revision, and stale numbers mislead harder
than no numbers (the yjs-server figures an earlier revision printed here
predated the vendored y-php StringDecoder fix and overstated that
engine's ingest cost by an order of magnitude). Run `npm run bench` for
current numbers on your hardware — every claim below is a shape the
tables it prints make concrete. The stable shape: intent-log is the
cheapest per ingest; de-rtc costs a small multiple of it (the content
three-way merge); yjs-server is the most expensive per ingest (the
canonical document is decoded, merged, and re-encoded in PHP on every
request) and is the one whose service time grows with document size.
For scale, the retired append-only relay sat at the timer floor — read
it as "negligible", and as the price of observing nothing. Where de-rtc
pays is bytes, not cycles: whole documents travel in every proposal and
every accepted proposal stores a full content row, so its request and
storage bytes dwarf the other engines' and scale with document size —
with one fairness caveat: the session scenarios run at the harness's
RTC poll cadence, which measures de-rtc as we adapted it, not as
designed; at the vision's save-and-sync cadence the byte and
escalation profiles would differ (TODO-19).
All three engines' payload/storage bytes are REAL (each benchmark
profile speaks its engine's actual wire format), all three converge
with **zero lost work** on every scenario, and the escalation policies
differ visibly on the same contended workload: intent-log parks
escalations at per-register grain, de-rtc at per-block grain since
TODO-3 (whole-proposal only for structural divergence), yjs-server
none (silent CRDT last-writer-wins).

The session-shaped scenarios add the time dimension single workloads
miss. Under `structural-churn` (concurrent block inserts/removals plus
typing) the conflict policies separate hardest: intent-log and
yjs-server merge everything cleanly while de-rtc escalates a share of
proposals — structural divergence is what per-block salvage (TODO-3)
correctly refuses, so those conflicts still park whole; since salvage,
the non-structural share lands its clean blocks and the overall
escalation rate dropped markedly (run the scenario for the current
share); nothing is lost on any engine. `remove-contention` isolates the edit-vs-remove
conflict class; `field-sync` separates the same policies at field grain
(see the scenario narratives below for what actually happens on the
wire). Under a ten-minute three-user `editorial-session` (joins, typing
bursts, per-second polling, autosaves), intent-log's service time holds
flat, de-rtc's holds nearly flat while its room tail (and therefore the
next joiner's download) grows past a megabyte, and **yjs-server's
ingest grows with the accumulating document**. Run `editorial-session
rounds=3600` for the full hour before concluding about long sessions.
And run `save-sync-session` before concluding about de-rtc at all: at
the vision's ten-second save-and-sync cadence the escalation ranking
INVERTS — de-rtc surfaces only a small share of parked blocks
(staggered saves rarely truly collide; per-block salvage absorbs most
of what does) while intent-log becomes the escalation-heavy engine
(its same-paragraph frame-conflict residual bites hardest when editors
observe peers seconds late). Cadence is not a detail; it is half the
comparison. The escalation-criteria fixture
(`tests/phpunit/wpSyncEscalationCriteria.php`) pins these shapes as
policy bands — note that "escalated dispositions" undercounts de-rtc's
surfaced conflicts since TODO-3: partial acceptance parks blocks while
the proposal reports applied, so the honest metric is escalations PLUS
parked rows, which is what the fixture measures.

Two costs live off the edit path and are easy to miss; the benchmark
reports both. The **later-joiner read** (a cold read at cursor 0 — what
a fresh visitor downloads to enter the room after a session): modest
under intent-log and yjs-server, largest under de-rtc by a wide margin
(the retained tail is full-content rows). The **save path**
(`materialize()` on a cold engine, as a real save request runs it):
cheap under intent-log, near-zero under de-rtc (the canonical IS post
content), and the most expensive under yjs-server — the whole canonical
document is decoded from scratch, a cost the ingest figures never show
because the engine instance keeps the decoded doc cached within a
request. The benchmark also reports per-request ingest peak memory (the
number a constrained PHP-FPM pool actually OOMs on); de-rtc's is the
largest at benchmark sizes.

## How the data flows: scenario narratives

Numbers change; these flows don't. Each narrative traces one concrete
situation through all three engines, and names the principle at stake.

### A. One editor types a sentence (the solo baseline)

- **intent-log**: Keystrokes hit the canvas immediately. Capture waits
  for the burst to quiet (a deliberate delay forced by core-data's
  update ordering), diffs the editor tree against the document state
  that tree reflects, and authors typed intents into the outbox at that
  observed seq. The next poll sends them; the server takes the room
  lock, transforms (a no-op solo), appends rows, and acks dispositions.
  The replica absorbs the authoritative rows, and roughly a poll cycle
  after the burst quiets, the unit "settles" — which is the moment undo
  arms (see TODO-5).
- **yjs-server**: Keystrokes apply to the local Y.Doc instantly, so
  undo is armed from the first character. The provider encodes an
  incremental binary update; the next poll delivers it; the server
  decodes the canonical doc, merges, re-encodes, and stores the diff
  row. No lock, no transform.
- **de-rtc**: Keystrokes edit the doc and mark it dirty. On the next
  poll, if no proposal is in flight, the client proposes its WHOLE
  content against the version it last incorporated. The server
  three-way-merges (a fast-forward solo), claims the version advance
  (an uncontended CAS write), broadcasts a full canonical
  content row, and the client advances its version without touching the
  doc (its own content came back unchanged).

### B. Two editors, different blocks (the common concurrent case)

All three merge losslessly; they differ in *how* and in *what travels*.
intent-log transforms each editor's intents over the other's rows — the
transforms are no-ops because the frames don't intersect. yjs-server's
CRDT merges the updates commutatively. de-rtc three-way-merges each
whole-content proposal against base and current: each editor's block
change is a sole-writer change to its block, so both land — but both
directions of the wire carry the entire document (P5/P6: de-rtc pays in
bytes here).

### C. Two editors, the same paragraph (the policy separator)

- **intent-log**: When both edits are observed before the next burst,
  offsets transform and the texts merge. The residual: while this editor
  is still *behind* on the peer's change to the same paragraph, the later
  keystrokes of its burst escalate as `frame-conflict` — parked in the
  review lane, never lost — and normal merging resumes once the peer's
  change is observed. An over-escalation rate problem (P3 honored,
  arguably too eagerly; TODO-6 tracks the rate).
- **yjs-server**: Character-level CRDT merge interleaves both texts
  deterministically; block-attribute (register) collisions resolve by
  silent last-writer-wins. Nothing surfaces to a human (P3 violation,
  documented; TODO-7).
- **de-rtc**: The peer's accepted proposal broadcasts a canonical row
  that arrives mid-burst. The client cannot merge (clients never merge)
  and cannot apply it verbatim (that would clobber unsent keystrokes),
  so it *incorporates*: adopts canonical blocks it hasn't touched and
  keeps its own version of the contested block — but since TODO-2b it
  also RECORDS the version that block's text was really written
  against, and its next proposal declares it (`blockBaseVersions`).
  The server merges the contested block from its TRUE base:
  non-overlapping concurrent edits to the same block merge (both texts
  land), true overlaps park for review at block grain (TODO-3) while
  the clean remainder lands. The silent block-level last-writer-wins
  this moment used to cause is retired; and since TODO-12 v1 the
  interaction model matches the vision's shape: the colliding
  incorporation raises ONE pending item on the review surface (later
  peer edits to the same block refresh it rather than stacking),
  resolved by explicit Adopt (take the latest canonical form) or
  Reject (keep yours — the recorded base keeps the next server merge
  honest). The residual: a map-less legacy client still presents
  sole-writer changes. Structural divergence still parks the proposal
  whole.

### D. Edit versus remove (one client types into a block another removes)

intent-log escalates the trailing side: if the removal lands first, the
trailing keystrokes park as `target-deleted`; if the text lands first,
both apply and the token vanishes with the removed block. yjs-server
escalates nothing — CRDT deletion dissolves the edit with the deleted
block, deterministically and invisibly. de-rtc still escalates the
most: the structural change shifts the base under the whole-content
proposal, and structural divergence is exactly the class per-block
salvage refuses — the trailing proposal parks whole (roughly one
escalation per contended pair; TODO-3 removed the *collateral* from
non-structural rounds). The benchmark's `remove-contention` scenario
measures exactly this spread.

### E. An author without `unfiltered_html` pastes risky markup (P1)

- **intent-log**: The intent escalates at ingest as requiring approval
  and parks; a privileged reviewer's restore IS the approval.
- **yjs-server**: The offending blocks are replaced with their
  kses-sanitized form and the compensating delta broadcasts. WordPress's
  filter-on-save semantics at per-update grain — the protected markup is
  gone and no human ever reviews it. Coarser than the other two by
  design.
- **de-rtc**: Per-block sequestration, upstream's model: exactly the
  risky blocks revert to their base form and park for review while the
  safe remainder of the proposal merges and lands. Restore re-proposes
  them under the RESTORER's capability, so restore is the approval.
  Whole-proposal escalation remains the fallback for freeform
  boundaries.

### F. An out-of-band machine write lands mid-session (P4 — de-rtc solves it; the others don't)

A scheduled integration fetched the post before the session and writes
back its modified copy while two editors are collaborating.

- **de-rtc** (since TODO-13/14/4a): a *cooperating* integration passes
  `base_version` with its save and gets a genuine three-way merge
  through the room — concurrent session work survives, conflicts
  reject the save with a 409 (and park for review). An *unaware*
  integration is healed after the fact: the next room access detects
  the write — an aware save's embedded `content_hash` matches its own
  content; this one doesn't — and heals it as an ordinary collaborative
  update.
  If the integration round-tripped the embedded sync-meta, the server
  three-way-merges from that base and the editors' concurrent work
  survives alongside the integration's changes; a metaless replacement
  converges the room to the accepted post state (prior canonical stays
  in history); a stale copy heals nothing (rollback guard); an edit
  colliding with concurrent session work parks for review. Connected
  editors see the external change arrive like any peer's edit.
- **intent-log** (since TODO-4b): a *cooperating* integration declares
  `intent_log_base_seq` and its save diffs into typed intents — the
  session's concurrent work merges by transform, register collisions
  park for review, and the save lands as the merged canonical. An
  *unaware* intent-log writer still bypasses the room: canonical
  diverges from `post_content` and the session's next materializing
  save clobbers the write — this engine has no divergence-detection
  stamp yet (the residual in TODO-4b).
- **yjs-server**: the room never learns about the write in either
  variant — the content-loss scenario stands, as this engine's
  accepted, documented limitation (a diff-to-CRDT lane would invent
  semantic operations no writer expressed).

### G. A lagging client comes back (deep lag, reload, reconnection)

- **intent-log**: Exact re-send, idempotent by intentId; old intents
  transform over everything that landed meanwhile. If the room compacted
  past the client's cursor, it re-bootstraps from the retained
  checkpoint (undo stacks clear — inverse derivation needs the log).
  The one P2 window: outbox intents that were never acked die with a
  reloading tab; the loss is visible when it was an undo (the undone
  edit resurrects).
- **yjs-server**: Re-bootstrap from snapshot + tail; the client uploads
  its own full state as an ordinary update — idempotent, the server
  diffs out what it already has. Under racing lock-free ingests a client
  can be asked to resync (`resync-required` void); it heals by the same
  full-state upload, one extra round trip, nothing lost.
- **de-rtc**: The client re-proposes its doc's current state; if the
  lost send actually landed, the re-proposal merges as a no-op. A stale
  base within the engine's 20-version snapshot window is fine — that's
  what the three-way merge is for (though cumulative staleness escalates
  more). Beyond the window the server first mines post revisions for
  the base (each aware save embeds its own snapshot window — TODO-15),
  so even arbitrarily old bases usually merge; only a base no revision
  carries voids as `unknown-base-version`, and the client retries
  against a fresher base: fetch canonical, rebase, re-propose. The
  benchmark models one retry per edit; nothing is lost either way.

## Transports are a separate axis

Engines run over any transport. Run the transport benchmark
(`tests/benchmarks/transport/`) for measured edit-to-visible latency and
idle traffic on your hardware; the stable shape:

| | edit-to-visible latency | idle traffic per collaborator |
| --- | --- | --- |
| http-polling | seconds-scale (bounded below by the poll interval) | roughly one request per poll interval |
| http-long-polling | sub-second (held requests wake on new rows and awareness heartbeats) | more requests than plain polling, each holding a PHP worker up to its wait budget |
| websocket | tens of milliseconds | a few frames per heartbeat — plus a persistent daemon, TLS termination, and an exposed port |

Transport latency is engine-independent (the HTTP rows replicate within
noise under intent-log). One caveat on the axis itself: "engines run
over any transport" is an inherited framework property, not a
principle. It fits the log-shaped engines; for DE-RTC it is part of the
adaptation under review (architecture item 4) — that engine is allowed
to declare its own transport story, including "manual sync with long
delays," without penalty.

## Architectural decisions to revisit

This plugin has no external users; severity is cheap now and expensive
later. These are the load-bearing early decisions the fidelity audit
and the scorecard put in question, each with the change we would scope.

1. **One wire protocol for every engine.** The framework's session
   protocol (opaque `EngineUpdate` envelopes over rows-after-cursor)
   was inherited from the relay era and imposed on every engine. It
   fits log-shaped engines (intent-log, yjs-server); it visibly
   reshaped DE-RTC — save-centric semantics were squeezed into
   poll-cadence proposals, which is where the LWW and the loss of
   pending edits came from. Revisit: narrow the engine SPI to
   principle-level obligations (authorize, attribute, merge, review,
   materialize) and let each engine own its wire surface. An engine
   that wants its own REST routes — or no live transport at all —
   should be able to say so. Feeds TODO-12.
2. **Canonical state lives in plugin storage posts for every engine.**
   Room meta was chosen for plugin containment. For the log-is-truth
   engines it is a reasonable substrate. For de-rtc it inverted the
   vision: the canonical document is supposed to BE the post, with
   sync-meta riding `post_content` and revisions as the backup
   mechanism. TODO-13 and TODO-14 have since restored de-rtc's
   co-location (write-through) and self-healing, so de-rtc now passes
   scenario F; intent-log and yjs-server still fail it. What remains of
   this revisit: make the storage substrate an engine decision and
   complete the inversion for de-rtc (room rows demote to a transport
   cache) as part of TODO-12.
3. **`GET_LOCK` as the serialization primitive.** RESOLVED by TODO-1:
   de-rtc is lock-free again (optimistic version claims, upstream's
   model) and intent-log holds a Core-style options-row lock. Kept here
   as a record of the decision smell — we had reached for a database
   lock because the room protocol forced per-request merges at RTC
   cadence.
4. **Transport universality as a design goal.** A fine property for
   log-shaped engines and a Procrustean bed for DE-RTC. Revisit:
   transports become a capability an engine declares, and the
   comparison stops treating transport-independence as a virtue worth
   buying at the price of engine fidelity.
5. **Client machinery reuse across engines.** de-rtc's client rides a
   `Y.Doc` editor bridge purely to reuse the shared Yjs undo manager
   and awareness plumbing. That bought porting speed at the price of
   two fidelity losses: local-snapshot undo (TODO-16) and a CRDT
   dependency the vision says clients don't need. Revisit together with
   TODO-2a/2b — the descriptor work is the natural moment to couple
   de-rtc's client to the editor's semantic actions instead of a shadow
   CRDT.
6. **Benchmarking every engine at RTC cadence.** The session-shaped
   scenarios poll every second, so they measure de-rtc-as-adapted, not
   DE-RTC-as-designed: its whole-content byte profile and escalation
   shares are partly artifacts of a cadence the vision does not
   prescribe. TODO-19.

## Current state versus desired state

The desired state is the principles, fully honored, by whichever engine
wins the bake-off. The current state is close enough to compare engines
honestly and far enough that pretending otherwise would corrupt the
comparison. The enumerated TODOs below are the delta. TODO-1 through
TODO-4 change engine *verdicts*; TODO-12 through TODO-20 are the DE-RTC
fidelity program — they change whether the engine we are comparing is
the engine Dennis designed; the rest change polish and confidence.

- **TODO-1 — Remove `GET_LOCK`; lock the way WordPress Core would.
  DONE (2026-08-18):** intent-log now holds a Core-style options-row
  lock (`WP_Sync_Room_Lock`, the upgrader pattern: atomic INSERT IGNORE
  claim, TTL takeover of a crashed holder, token-checked release) and
  de-rtc is lock-free — accepted proposals atomically claim their
  version advancement via an options-row CAS (`WP_Sync_Atomic_Option`)
  with reload-and-re-merge on a lost claim, orphaned-claim TTL healing,
  and a genesis re-seed so room resets cannot wedge the counter; claim
  exhaustion keeps the old retryable-503 contract. Covered by
  `tests/phpunit/wpSyncConcurrencyPrimitives.php`. The original
  rationale, kept for the record:
  intent-log and de-rtc serialized ingest with per-room MySQL `GET_LOCK`.
  Core never uses `GET_LOCK` — Core locks with atomic option writes and
  TTLs (the upgrader/cron pattern) precisely to stay topology-agnostic —
  and `GET_LOCK` quietly loses its meaning under connection
  pooling/multiplexing, under read/write-splitting drop-ins (a
  `SELECT GET_LOCK(...)` pattern-matches as a read and can land on a
  replica), on multi-primary clusters (user locks are node-local), and
  on SQLite builds (the function does not exist). The fix differs per
  engine: **de-rtc drops the lock entirely** and restores the upstream
  approach — optimistic base-version validation with merge-and-retry
  (the original DE-RTC has NO lock; `GET_LOCK` is this port's addition,
  see the fidelity audit); **intent-log** gets a Core-style advisory
  lock (atomic CAS + TTL + retryable contention response). Principles:
  P5; DE-RTC fidelity.
- **TODO-2 — Port the DE-RTC client descriptor lane (`clientUpdate`).
  INVESTIGATED (2026-08-18, timeboxed) — premise corrected, split into
  2a/2b.** The investigation falsified this entry's original premise:
  the descriptor does NOT improve merge quality. The merge core's
  descriptor-less lane derives *exactly* the update a client would have
  sent (`wp_de_rtc_create_automerge_update_for_content_change(base,
  proposed, 'client')`) and routes to the *same* block-native merge;
  when a client DOES send a descriptor, the server re-derives the
  expected one anyway and fingerprint-compares — rejecting on mismatch
  (`de_rtc_sync_meta_tampered`). Merge outcomes are byte-identical
  either way. The descriptor's sole contribution is **tamper evidence**
  (hash-pinned base/proposed + operation fingerprints), which is a P1
  integrity property, not the scenario-C fix. Split accordingly:
  - **TODO-2a — Descriptor builder for tamper evidence. DONE
    (2026-08-18), full enforcement from day one as decided.**
    `src/engines/de-rtc/descriptor.ts` is the client-side twin of
    `wp_de_rtc_create_automerge_update_for_content_change` — content
    canonicalization (core-name spelling, boundary whitespace),
    top-level record splitting with the parse→serialize round-trip
    check, sha256 content hashes, the full op lane (move / update /
    rich-text splice / rich-text format / insert / delete / replace /
    unsupported fallback), and the UTF-8 code-point text model with
    PHP-exact HTML entity decoding (semicolon-only, HTML5 table via
    isolated textarea decode). Byte-parity is enforced by 75
    PHP-generated vectors (`tests/tools/
    generate-de-rtc-descriptor-vectors.{php,mjs}` →
    `tests/js/engines/de-rtc/descriptor-vectors.test.ts`): 51 targeted
    cases (every op lane, canonicalization, escaping, emoji/ZWJ/
    combining marks, entities incl. the no-semicolon probe, freeform
    and malformed-delimiter boundaries, float-attr re-encode) plus a
    24-seed deterministic combinatorial sweep. Sessions attach the
    descriptor to EVERY proposal (built from the base version's exact
    canonical string, tracked per session; omitted only when that
    string is unknown). The engine validates ONCE against the PLAIN
    declared base — deliberately not the TODO-2b composite, which is
    what lets descriptors and `blockBaseVersions` compose (the
    scoping-era "omit on blockBaseVersions" restriction dissolved) —
    then DROPS the descriptor, so the kses-sequestration and
    per-block-salvage lanes now run for descriptor-carrying proposals
    too (previously they whole-parked; the old pins were updated).
    Mismatches VOID with the merge core's tamper reasons. One
    deliberate engine-level acceptance: a client whose parser twin
    refused to split blocks sends the single
    `document.replace_unsupported` fallback op — with both top-level
    hashes verified that is legitimate digest-only evidence, not
    tamper (this absorbs cross-language re-encode edges like
    PHP-authored float attrs without weakening the hash pin). Covered
    by `tests/phpunit/wpDeRtcDescriptorEnforcement.php` (accept /
    tampered-hash / wrong-content / fallback accept+reject / kses and
    salvage survival / 2a+2b composition / malformed) and fuzz-smoked
    live (real editor content through the TS builder against the PHP
    validator, rooms advancing normally under enforcement).
  - **TODO-2b — Per-block base honesty (the actual scenario-C fix).
    DONE (2026-08-18):** the doc bridge records the TRUE base of each
    block kept through a colliding incorporation (once — the oldest
    pending base wins; cleared when the block adopts canonical, on
    wholesale adoption, and on version-only advance), the session
    carries the map as `blockBaseVersions`, and the engine's
    `resolve_effective_base()` substitutes each declared block's
    true-base record into the merge base (snapshots first, then
    revision mining). Real same-block concurrency then MERGES when
    non-overlapping and parks for review when it overlaps — the silent
    LWW is retired for map-carrying clients, with unsound
    substitutions degrading to exactly the old behavior. Covered by
    bridge Jest tests and engine PHPUnit tests (including a
    regression pin on the map-less residual); fuzz-smoked end-to-end.
    No merge core changes.
  Neither half spirals; 2b is the fidelity-critical one. Principles:
  P1 (2a), P3/P7 (2b).
- **TODO-3 — Per-block parking for `manual-conflict-required`. DONE
  (2026-08-18):** `salvage_conflicting_blocks()` extends the
  sequestration pattern to merge conflicts: when the whole-document
  three-way merge fails, base/current/proposed block records are
  aligned positionally, each both-changed block gets its own three-way
  merge, the clean remainder lands, and exactly the truly conflicted
  blocks park for review (canonical wins their positions; the applied
  disposition carries `parkedBlocks`). Whole-proposal parking remains
  the fallback for structural divergence (unequal block counts, where
  positional alignment lies) and freeform boundaries. Also wired into the self-healing lane
  (a conflicting external save salvages before parking whole).
  Engine-layer only; the frozen merge core is untouched. The de-rtc
  benchmark profile models partial acceptance (settle-time
  classification against the just-applied canonical). Covered by
  `tests/phpunit/wpDeRtcBlockConflictSalvage.php`. Consequence worth
  knowing: a *present* client can barely age out of the snapshot window
  anymore (even its conflicts partially apply and advance its base) —
  stale-base voids now come from genuinely absent clients.
- **TODO-4 — Machine-writer participation (scenario F).** Per engine:
  (a) **de-rtc — DONE (2026-08-18)**: `WP_De_RTC_Base_Version_Preflight`
  gives any `wp_update_post()` caller (WP-CLI, plugins, REST via the
  `base_version` request param on posts/pages) the upstream single-arg
  contract: pass `base_version` and the save three-way-merges THROUGH
  the room's ingest lane — claims, per-block salvage, review parking,
  and attribution all apply — with the merged canonical written back
  (plus fresh embedded lineage); an unsalvageable conflict or unknown
  base rejects the save (Core's generic error; the rich 409 via
  `last_error()`, the same adaptation limitation upstream documents).
  The hook choreography mirrors the upstream branch's plugin adaptation
  (raw-content capture pre-kses so a filtered author's markup reaches
  the sequestration lane, preflight in `wp_insert_post_empty_content`,
  content replacement in `wp_insert_post_data`); the merge itself
  deliberately runs through OUR engine rather than porting upstream's
  content-canonical preflight closure — covered by
  `tests/phpunit/wpDeRtcBaseVersionPreflight.php`. Unaware writers were
  TODO-14; lineage carriage was TODO-13.
  (b) **intent-log — save-path diff lane DONE (2026-08-18)**:
  `WP_Intent_Log_Base_Seq_Preflight` gives `wp_update_post()` callers
  (and REST via `base_seq` on posts/pages) the single-arg contract:
  declare `intent_log_base_seq` — the room seq whose materialization
  you read — and the save is diffed against the document at that seq
  (keyed by the `metadata.syncId` identity `materialize()` persists)
  and authored as ordinary typed intents: versioned
  `replace_attr_content` field writes plus `format_text` span replays,
  versioned `set_attr`/`remove_attr`, and
  `insert_block`/`remove_block`/`move_block`. Concurrent session work
  merges by transform; register collisions ESCALATE to review (the
  engine's per-intent policy — conflicts never reject the save, unlike
  de-rtc's whole-save 409); only an unusable base aborts
  (`last_error()`; per-edit outcomes via `last_dispositions()`). The
  saved content becomes the merged canonical, so post and room stay
  convergent. Covered by
  `tests/phpunit/wpIntentLogBaseSeqPreflight.php`. Residuals: a direct
  typed-intents REST API (machines that can state intent without
  diffing) remains open, and UNAWARE intent-log writers still bypass
  the room entirely (no divergence detection stamp exists for this
  engine — the de-rtc healing pattern would need an intent-log
  co-location equivalent first);
  (c) **yjs-server**: accepted and documented — a diff-to-CRDT lane is
  mechanically feasible but semantically wrong (inferred character
  operations no writer expressed). Principles: P4, P2.
- **TODO-5 — Close the intent-log undo arming gap. DONE (2026-08-18):**
  undo inside the settle window now CANCELS the pending unit instead of
  no-oping. A fully-unacked unit is undoable immediately: its intents
  leave the outbox (the optimistic document replans and the canvas
  reverts), and a `cancel` row chases any copies already queued on the
  wire — the server drops intents canceled within the same batch
  (settling them as `voided`/`canceled` marker rows, idempotent against
  redelivery and dead against late arrival) and acks `cancel-too-late`
  when an intent was already ingested, in which case the accepted row
  resurrects the effect and the unit returns to the stack as a normal
  settled undo candidate — all-or-nothing per unit, so nothing is ever
  half-canceled. Inverses still derive ONLY from accepted rows.
  Covered by Jest (cancel, lost-race resurrection, all-or-nothing) and
  PHPUnit (same-batch drop, too-late, redelivery idempotence, dead
  late copy); undo-profile fuzz green. Remaining, unchanged: an undo
  whose INVERSE intents are unacked at tab reload still loses them
  with the outbox (the general unacked-loss window).
- **TODO-6 — Promote escalation rate to an acceptance criterion. DONE
  (2026-08-18):** `tests/phpunit/wpSyncEscalationCriteria.php` runs the
  conflict fixtures (clean, contended, structural, native-cadence) per
  engine and enforces POLICY BANDS on the surfaced-conflict rate: clean
  workloads must ask humans nothing; genuinely contended workloads must
  surface (>0 — "silently zero is a failure of honesty") while staying
  under an upper bound (overburdening is also failure); yjs-server's
  silence is pinned as documented policy (TODO-7 owns changing it).
  Metric honesty matters: for de-rtc the count is unique parked-row
  proposalIds (escalated dispositions both undercount salvage and
  double-count whole-parks); for the per-intent engines it is escalated
  dispositions. Runs in CI with the PHPUnit suite. The fixture earned
  its keep immediately — it caught this very document overstating
  de-rtc's native-cadence result.
- **TODO-7 — Decide yjs-server's conflict story. DECIDED (2026-08-18):
  silent register-LWW is the engine's formal, documented policy.**
  Rationale: conflict DETECTION in a CRDT engine is a research project
  (the CRDT's entire design premise is that there is nothing to
  detect), two review-capable engines already exist for deployments
  that need P3, and the escalation-criteria fixture (TODO-6) pins the
  silence so any change must be a deliberate act. The policy is now
  stated wherever the engine is offered: the Settings → Collaboration
  engine label says "concurrent conflicts merge silently, last writer
  wins — no review lane". Revisiting this decision starts with
  building detection, and should also produce a P3 story for what
  detection would even mean at CRDT granularity.
- **TODO-8 — Police post-genesis room growth in yjs-server. Tiers 1+2
  DONE (2026-08-18):** tier 1 observability already existed (canonical
  doc bytes in the `_debug` envelope and `wp collaboration rooms
  inspect`); tier 2 adds the terminal ceiling — a room whose canonical
  document grows past `wp_sync_yjs_server_max_room_bytes` (default
  8 MB; 0 disables) rejects further WRITES with 413 while reads and
  saves continue (nothing merged is lost; the room just stops
  accumulating), with a qm/debug warning from 75% so operators see the
  growth coming. Tier 3 (epoch compaction that actually SHRINKS the
  canonical) stays parked, to be done together with incremental
  canonical maintenance.
- **TODO-9 — Harden the websocket transport. Token lane DONE
  (2026-08-18):** the one-time auth token no longer travels as a URL
  query parameter (query strings land in server and proxy access
  logs); it rides the `Sec-WebSocket-Protocol` offer list — the one
  handshake header a browser page can influence — as
  `wp-sync-token.<hex>` beside the base `wp-sync` subprotocol, which
  the daemon echoes on accept per RFC 6455. The query lane is removed,
  not deprecated (both halves ship together). Still true and still the
  operator's job: plaintext `ws://` must never leave a dev box —
  terminate TLS in front of the daemon. The transport remains
  experimental until a real-daemon e2e lane exists (the websocket-only
  suite exercises the TEST provider, not this daemon).
- **TODO-10 — Validate the hosting cost cards end-to-end. DONE
  (2026-08-19).** The harness:
  `tests/benchmarks/transport/soak-transport.mjs` (shared plumbing in
  `lib.mjs`) — N windows on one post, each owning one paragraph and
  editing in deterministically-jittered bursts with think time,
  periodic saves through window 0 (under de-rtc these carry
  `base_version` through the room), latency probes every 30 s stamped
  in-page by every window, per-minute wire sampling, server metrics
  via the `rtc-test/v1` log, a hard convergence gate at the end, and a
  per-user-hour report in the cost cards' units. Run:
  `node tests/benchmarks/transport/soak-transport.mjs engine=<slug>
  transport=http-polling windows=3 soak=3600 json=out.json`.

  The measurement pass: one supervised hour per engine, 3 windows,
  http-polling at the 1 s with-collaborators cadence, ~1,300 tokens
  typed per window (numbers live in the run JSONs; shapes here, per
  this document's convention):

  - **intent-log**: the cheapest wire AND CPU by a wide margin —
    per-user bytes stay flat as the document grows (intents don't
    scale with document size); every save succeeded; converged
    immediately. Its probe latency is the worst of the three by a
    modest margin: the capture deferral (`CAPTURE_SYNC_DELAY`) is
    visible end-to-end, as scenario/latency discussion predicts.
  - **yjs-server**: converged clean with bounded bytes (roughly
    double intent-log's — state-vector diffs, not doc-scaled), but
    the highest server CPU by far (≈9× intent-log per user-hour) —
    the per-request canonical decode/merge/re-encode cost, previously
    measured only at the engine seam, now confirmed end-to-end.
  - **de-rtc, the run's headline finding**: at RTC cadence the
    full-content-row byte profile is not a "con", it is a CLIFF. With
    the document at tens of KB after an hour, downloads ran more than
    an order of magnitude past yjs-server (hundreds of MB per
    user-hour), and in the final minutes the FRAMEWORK storage's read
    path (`WP_Sync_Post_Meta_Storage::get_updates_after_cursor` —
    every row since a cursor fetched and json_decoded in one pass)
    exhausted a default 128 MB PHP repeatedly. Those OOM 500s failed
    the four final saves and left one window permanently stale — the
    convergence gate FAILED for an infrastructure reason, not a merge
    bug (checkpoint trimming itself worked; the room ended bounded).
    See TODO-20.

  Verdict on the cards: the composed engine-seam floors held for
  intent-log and yjs-server (measured cadence × per-request costs
  compose to the same shapes), and the de-rtc card must present
  byte growth as a hard operational ceiling at pseudo-realtime
  cadence — while the vision's own operating point (10 s polls,
  save-centric sync; TODO-19's `save-sync-session`) sits far below
  the cliff. Conditions recorded for reproducibility: dev wp-env
  (SCRIPT_DEBUG on; debug envelopes inactive without a client
  opt-in, so bytes are unbiased), Chromium headless, same-machine
  loopback.
- **TODO-20 — Bound de-rtc's row-read amplification. STAGE 1 DONE
  (2026-08-19): the announce model — the transport carries
  advisories, not documents.** Protocol 2. Accepted proposals (and
  healed external saves) store ~200-byte ANNOUNCE rows — version,
  base version, canonicalized content hash, author attribution,
  merged property registers — never content. Canonical content lives
  ONCE per room, and a behind client sends a `fetch` row answered in
  the same poll's read half by ONE synthesized (never stored)
  snapshot of the current canonical. The session advances by HASH
  for its own unchanged round-trips (`hashDeRtcContent`, the
  TODO-2a parity twin — the active typist downloads nothing),
  fetches eagerly with a single-flight-until-progress guard, and
  incorporates the fetched snapshot for merged own proposals
  (contested items raise per TODO-12, unchanged). Deferral holds a
  mid-burst snapshot until settle exactly as before. Voided
  dispositions force a catch-up fetch (stale-base recovery).

  Three defects found and fixed BY the wire-inspected soak runs
  while building this (each now pinned by tests):

  1. **The content-property double-carry**: core-data mirrors the
     serialized `content` string into the record map, and de-rtc's
     flat property lane was re-carrying the ENTIRE document as a
     property register on every proposal, every content row, and
     every announce — silently, since the old wire was
     document-sized anyway. Excluded client-side (one
     representation: content travels as content) and stripped
     server-side for legacy clients and persisted maps. This also
     killed a latent canvas-regression class (a stale content
     register incorporated post-settle makes
     `getEditedPostContent()` return the stale string).
  2. **Canonical-persistence ordering**: version claims allocate
     sequence numbers but cannot order the PERSISTENCE of canonical
     state; writer N's meta landing after writer N+1's silently
     regressed canonical, and with rows content-less the replay
     could no longer repair it (observed as unknown-base-version
     death spirals wedging a window for 40+ seconds). Canonical now
     lives in an engine-owned CHAINED options row
     (`<seq>|<payload>`, `WP_Sync_Atomic_Option::swap_prefixed`):
     each writer CASes against its predecessor's sequence prefix,
     so a write can only extend the chain; an unpersisted
     predecessor spins briefly then fails RETRYABLY (503) — that
     version was never announced or acked, so version reuse after
     claim-TTL healing is consistent. Write order is meta-first,
     row-second: the row is the advisory, the chain is the truth,
     and a lost row heals via the next conditional fetch.
  3. **Fetch starvation**: a strict single-flight fetch guard let
     one lost fetch freeze catch-up forever; the guard now unsticks
     on announced progress, and voids force a fetch.

  Measured (3-minute 3-window instrumented soaks, same workload):
  download per user-hour dropped ~3× immediately and no longer
  scales with document size (the hour-run cliff class is gone
  structurally — stored rows are constant-size, so the storage read
  path decodes kilobytes, not megabytes); probe latency p50 ~2 s,
  p90 ~3 s with the outliers eliminated; convergence clean. Covered
  by `tests/phpunit/wpDeRtcAnnounce.php` (announce shape, fetch
  semantics, ephemeral snapshots, row-size bound, convergence
  round-trip), `tests/js/engines/de-rtc/announce.test.ts`, and the
  adapted bench profile (which now pays the fetch cost the way real
  clients do). Legacy `content` rows still replay (old rooms catch
  clients up); the server no longer writes them.

  STAGE 2 (open): move the COMMIT lane to Save/autosave (the full
  Save/Sync inversion — proposals leave the transport entirely; the
  de-rtc autosaves controller routes pseudo-realtime commits through
  the room without publishing). The announce lane built here is that
  design's Sync channel, unchanged.
- **TODO-11 — Round-trip complex sourced attributes through
  materialization. INTENT-LOG HALF DONE (2026-08-18); the yjs-server
  half needs framework changes (design recorded).** The Phase-2a
  simplification mapped rich-text content onto a block's single wrapper
  element with genesis-frozen wrappers — so a paragraph's alignment
  class, an image's url/alt (sourced attributes living in markup the
  server cannot regenerate), and caption-class rich-text fields all
  went stale or lost at server materialization.

  *Intent-log fix — the client authors save-accurate state:* capture
  now renders each block's SAVE markup (`getSaveContent` with empty
  inner blocks) and (1) refreshes the `_wrapper` internal attr whenever
  the wrapper element changes (alignment/class/style survive), and
  (2) for block types whose resolver names no `content` field, authors
  the whole save-derived inner HTML as the engine `content` field
  through the rich-text codec (the img becomes an opaque object span;
  figcaption-class markup keeps real text coordinates) — the markup the
  server materializes is markup the client authored from the block's
  current attributes, so sourced-attribute edits round-trip. Peers stay
  attr-driven (engine-internal state never reaches editor trees); the
  diff and its verification extend per-block to authored content
  (adapter misses — unregistered types, save() throws — degrade to the
  old model and can never read existing document content as a
  deletion); kses judgment already covered every new surface (_wrapper
  set_attr, object-format spans). No frozen-core or server PHP changes.
  Covered by `tests/js/engines/intent-log-save-capture.test.ts` and
  `tests/phpunit/wpIntentLogSourcedAttrs.php` (materialization
  round-trip pins); simulator sweep + full suites green; fuzz-smoked.
  Honest bounds: container blocks author wrapper + pre-child static
  markup only (a block whose static markup FOLLOWS its children would
  materialize it before them — no core block known to do this); genesis
  content predating the current block save() gets a one-time
  modernization diff on first capture (the same rewrite a manual editor
  save would make).

  *yjs-server half — blocked on the framework, design recorded:* the
  Yjs block writer is core-data's `applyPostChangesToCRDTDoc` (the
  FRAMEWORK, not this plugin), so the client cannot author
  wrapper/save state without framework SPI work. Design: write
  `_wrapper`/`_save` entries into each block's Y.Map from the same
  save-markup adapter, and have the engine's `to_serializable_block`
  prefer in-doc entries over the genesis wrapper map / per-type
  defaults. DISCOVERED while scoping (record of defect): yjs-server's
  genesis puts a block's whole stripped inner markup into its FIRST
  rich-text-source attribute — for `core/image` the `<img …>` markup
  lands in `caption` — self-consistent for byte round-trips but
  semantically wrong the moment anyone edits the caption.

The DE-RTC fidelity program (see the fidelity audit for the vision
each item restores):

- **TODO-12 — Restore Save/Sync and pending edits. DECISIONS SET
  (2026-08-18): UX prototype first; the product shape is
  CONTESTED-ONLY pending. v1 behavior layer DONE (2026-08-18, record
  below); inline-card UI phase and the deeper Save/Sync inversion
  remain open.** Clean merges keep flowing live (today's
  behavior); only contested moments — a peer's edit landing on a block
  you are actively editing, and the server's parked conflicts — become
  pending edits requiring explicit adoption. The interaction model is
  validated with a clickable prototype BEFORE plumbing; framework SPI
  work follows the validated prototype, informed by it. Prototype v2
  review outcomes (2026-08-18): interruption budget and the
  Keep-mine/Take-theirs verbs approved as prototyped; the pending
  panel is SUMMARY-ONLY (no actions — each row spotlights its inline
  card; all resolution happens at the block, parked conflicts
  included, via their own inline card); no version chip in product UI
  (versions are operator/diagnostic surface only). Prototype v3
  outcomes (2026-08-18): "adopt for editing" is DISCARDED — under
  auto-propose, staged content would re-propose on the next poll
  before any editing happened, breaking its promise (and, for
  requires-approval parks, laundering unreviewed markup); the model is
  TWO VERBS, Adopt / Reject, with modify expressed as adopt-then-edit
  (the editor is always the editing surface; note that adopted
  markup is room-canonical — session-visible but unpublished — until
  edited and saved). Pending items MERGE INSTEAD OF STACKING: one
  review task per block; each subsequent accepted edit (or revised
  parked proposal) from a peer refreshes the same task's preview to
  the latest version, superseding rather than raising new tasks —
  Adopt takes the latest, Reject dismisses/keeps-yours and resolves
  all superseded parked rows with it. `restoreProposalWithChanges`
  remains an API (no longer surfaced in this flow). Original
  vision statement, for the record: editors confirm their own changes
  and choose to adopt others'; pending edits are the reviewable,
  adoptable unit; sync cadence is a host/user choice ("automated
  through polling, replaced with a WebSocket, or manually run with
  long delays").
  Replacing our auto-propose/auto-incorporate loop with this model
  dissolves the same-block LWW at its root (scenario C) rather than
  patching it. Depends on architecture item 1 — the room protocol has
  no vocabulary for "pending, not yet adopted." TODO-2b's per-block
  base honesty is the merge-quality half of the same fix; this is the
  interaction-model half.

  Design sketch — the commit path and the transport separate into two
  channels with different jobs:

  - **Save is the only commit primitive.** Whole content +
    `base_version` through the ordinary WordPress save path
    (`wp_update_post` / REST, autosaves included — the prototype
    shipped a DE-RTC autosaves controller). The server merges at save
    time, canonical state advances only at saves, and dispositions
    (merged / sequestered / needs-review) return in the save response.
    Pseudo-realtime is a save/autosave cadence dial, not a second
    commit channel — "pseudo-realtime, collaborative, and offline
    editing are one latency spectrum." This is also where TODO-12 and
    TODO-4(a) converge into one door: the browser, a curl call, an AI
    agent, and a plugin all commit through the same
    save-with-base-version lane; the browser stops being a privileged
    client with its own commit protocol.
  - **The transport narrows to Sync — advisory, never mutating.** Sync
    events exchange: presence/awareness (who is here, which block each
    person holds); canonical version announcements ("the post advanced
    to version X" as a token/hash, content fetched or included so a
    client can offer "adopt the latest"); pending edits awaiting
    adoption or review (sequestered blocks, needs-review proposals —
    summaries plus enough content to preview and act); and late-arriving
    dispositions of earlier saves. Adoption of a newer canonical is the
    client-side step our port wrongly automated: explicit when the
    change contests local work, policy-automatable only when trivially
    safe (no overlap) — which is exactly where the silent LWW
    disappears.
  - **Unsaved keystrokes never travel.** Local work stays local until a
    save commits it. Live cursors-and-keystrokes is the vision's final
    progressive-enhancement stage, not the foundation.
  - **Existing transports survive, demoted to Sync carriers.** Sync
    traffic is small, idempotent, and latency-tolerant — ten-second
    polling on a cheap host is a comfortable cadence, not a degraded
    one; a WebSocket just delivers announcements faster; manual sync or
    no transport at all degrades gracefully to plain saves that merge
    on arrival. Room rows become an ephemeral gossip cache; durable
    truth moves to `post_content` + sync-meta + revisions (TODO-13).
  - **Consequences the comparison must absorb.** The byte profile
    inverts — small sync events per poll instead of full-content rows,
    resolving most of the "de-rtc pays in bytes" con (measure under
    TODO-19 before re-judging). And edit-to-visible latency becomes
    save-cadence-bounded rather than poll-bounded: a deliberate policy
    trade of the vision, to be presented as such rather than as a
    deficiency.

  **v1 BEHAVIOR LAYER DONE (2026-08-18)** — the contested-only pending
  model is implemented beneath the editor UI:

  - *Contested lifecycle in the doc bridge:* each colliding
    incorporation (a canonical row changing a block you have locally
    edited — exactly the TODO-2b collision) raises a contest carrying
    the latest canonical version and serialized form of the block.
    Repeats for the same block REFRESH the one contest
    (merge-not-stack); a wholesale apply, a version-only advance, or
    the block's own settle resolves it naturally.
  - *Two verbs:* `adoptContestedBlock` applies the contest's LATEST
    canonical form under the remote origin (already canonical — never
    re-proposes, never launders) and clears the recorded base;
    `rejectContestedBlock` keeps the local block AND its true base, so
    the next proposal still merges honestly server-side (compatible
    edits merge, true overlaps park to the peer's review) — and a
    later peer edit to the same block raises a fresh contest.
  - *Parked tasks fold the same way:* one review task per author per
    target (property register or block-index set); a revised parked
    proposal supersedes the open task, refreshing it to the latest
    revision, and ONE resolution closes the whole lineage (resolution
    rows emitted for every superseded proposalId).
  - *Save-through-the-room:* an apiFetch middleware injects
    `base_version` (the session's last-applied version) into ordinary
    editor saves of posts/pages while a de-rtc session is live, so the
    TODO-4b preflight merges the save through the room — closing the
    TODO-14 "stamped but neither merges nor heals" gap for editor
    saves. Caller-supplied `base_version` wins; autosaves are excluded
    (their REST route updates a revision object the preflight doesn't
    cover).
  - *Interim surface:* contested blocks appear on the framework review
    panel as pending items (reason `frame-conflict`, an edit-count
    excerpt per the validated prototype), with the panel's
    restore/dismiss actions routed to Adopt/Reject. This contradicts
    the prototype's summary-only-panel decision on purpose — the
    inline block card is editor-component work that lands in the
    follow-on UI phase; the panel keeps the behavior layer exercisable
    until then.

  Jest-covered (`tests/js/engines/de-rtc/pending.test.ts` plus the
  existing bridge/review/engine suites). Honest residuals: the inline
  card + panel-goes-summary-only UI phase; the autosave commit lane;
  the deeper Save/Sync inversion (canonical advances only at saves,
  rows demoted to gossip — architecture item 1/2) remains open, since
  v1 keeps auto-propose for clean merges by design (contested-only
  pending was the validated product shape, not full manual sync).
- **TODO-13 — Co-locate de-rtc sync-meta with `post_content`. DONE
  (2026-08-18):** every save of a post whose room carries de-rtc
  lineage embeds the room's sync metadata at the content's trailing
  edge (`WP_De_RTC_Sync_Meta_Colocation`, hooked on
  `wp_insert_post_data` — the save path, upstream's co-location point,
  NOT `materialize()`, which is a read used by tests/oracles). The
  embed uses upstream's exact `data-wp-sync-meta` SCRIPT grammar plus
  `room_version`/`room_version_seq`/`content_hash`, so
  `wp_de_rtc_parse_post_content_sync_meta()` reads it back verbatim;
  revisions copy it for free (the backup mechanism); genesis adopts it
  and RESUMES the version lineage after a room reset instead of
  restarting at v1. Room lookup is non-creating (the storage API's own
  lookup creates posts). Covered by
  `tests/phpunit/wpDeRtcSyncMetaColocation.php`. Honest residuals: the
  pseudo-block is visible to non-collaborative editors and in raw
  front-end markup (upstream's sync-meta-in-content protection
  periphery is unported); autosave REST writes update the autosave
  revision directly and don't re-embed; room meta remains the working
  store — the full storage inversion rides with TODO-12/architecture
  item 2.
- **TODO-14 — Self-healing from unaware writers. DONE (2026-08-18):**
  `maybe_heal_external_save()` runs on room load: an out-of-band
  `post_content` write is detected by the co-location stamp (an aware
  save's embedded `content_hash` matches its own content; anything else
  is out-of-band), and healed as an ordinary collaborative update —
  three-way-merged from the embedded base when lineage rode along
  (including a base the room aged out but the embed still carries),
  fast-forwarded to when no lineage is usable ("WordPress accepted this
  as post state"), with stale copies of known versions stamped-and-
  skipped (the rollback guard) and genuine conflicts parked for review.
  Idempotent via a persisted `healed_hash`; claim-guarded like every
  version advancement; prior canonical survives in row history. Covered
  by `tests/phpunit/wpDeRtcSelfHealing.php`. TODO-4(a)'s base-version
  preflight remains the lane for writers that cooperate; revision
  MINING for bases neither the room nor the embed carries is TODO-15.
  Honest gap, found while building TODO-4b: a plain `wp_update_post`
  machine write WITHOUT `base_version` passes through the co-location
  filter, gets a fresh matching `content_hash` stamp, and therefore
  looks "aware" — it neither merges nor heals, and the room diverges
  until the next session save. The covered classes are preflighted
  writers (merge) and filter-bypassing writers (heal); the plain-save
  class needs the save-centric rework (TODO-12) to resolve
  principledly — bolting heal-on-save onto the current model risks the
  merge-duplication trap for mid-session editor saves.
- **TODO-15 — Revision-backed base resolution. DONE (2026-08-18):**
  `resolve_base_from_revisions()` — when a proposal's (or an external
  save's) base version aged out of the room's snapshot window, the
  newest 30 revisions are mined for embedded sync-meta carrying that
  version (a hash-verified snapshot inside the embed, or the revision's
  own content when the embed says it IS that version). Deep-lag
  proposals then three-way-merge with intervening session work intact;
  only a base no revision carries still voids `unknown-base-version`.
  Per-request cached; wired into ingest, the claim-retry path, and the
  healing lane. Covered by `tests/phpunit/wpDeRtcRevisionBases.php`.
- **TODO-16 — Revert-edit undo for de-rtc. DONE (2026-08-18):**
  `createDeRtcRevertUndoManager` replaces the borrowed local Yjs undo.
  The undo stack is the client's own accepted canonical rows (fed by
  the session as rows decode); undo() derives a REVERT — each block the
  popped row changed reverts to its base form iff the current document
  still holds the row's form (untouched-since guard: peer work is
  never collateral) — applied through the restore origin, so it lands
  as an ordinary proposal in the shared history, exactly "a new change
  that returns the document to an earlier state." redo() re-applies
  the reverted delta the same way; underivable rows (aged-out
  versions, structural divergence) drop and the next older row is
  tried; the accepted form of a revert is recognized by predicted
  content and never becomes a new undoable unit (a server-merged
  revert degrades to an ordinary own row — sane, documented). The
  version-content record it keeps is exactly what a history-slider UI
  would read (TODO-12-era UX). Jest-covered; de-rtc undo-profile fuzz
  green end-to-end.
- **TODO-17 — Modify-before-adopt in the review lane. DONE (API level,
  2026-08-18):** the decorated manager gains
  `restoreProposalWithChanges( objectType, objectId, proposalId,
  modifiedBlocks )` — the reviewer's edited replacements (keyed by the
  parked block's index) are what the overlay applies and re-proposes
  under the reviewer's capability. Approval and content are pinned by
  construction (the reviewer supplies the bytes that land), which is
  the property upstream's hash-pinning protected. Deliberately NOT on
  the framework `SyncManager` SPI (its restore verb carries no content
  parameter); the framework review panel still offers plain
  restore/dismiss — the modify-and-adopt UI is editor UX that belongs
  with TODO-12's interaction-model work.
- **TODO-18 — Per-edit authorship surface. DONE (data level,
  2026-08-18):** de-rtc content rows now carry the server-stamped
  author user id beside `authorClientId`, and the client's
  `createDeRtcAuthorship` tracker derives block-grain attribution from
  each row's diff against its base — the last author of every
  top-level block, at zero extra wire cost, exposed as
  `engine.authorship.getBlockAuthorship( objectType, objectId )`.
  Honest bounds, by design: attribution is block-grain and positional,
  and a structural change resets the map to unknown rather than
  attribute across a shift. The hover-highlight overlay is TODO-12-era
  editor UX; range-grain (which characters) attribution could now draw
  on the descriptor lane's `textSplice` evidence (TODO-2a done) but is
  not built.
- **TODO-19 — Benchmark DE-RTC at its native cadence. DONE
  (2026-08-18):** the `save-sync-session` scenario (rounds are
  wall-clock seconds; each client submits its typing burst only on
  staggered ~10s save beats and syncs every 10th round — the "$3/mo
  host polling every ten seconds" shape) runs against ALL engines and
  in the default matrix. First finding, and it flips the per-second
  ranking: at this cadence de-rtc surfaces only a small parked-block
  share (staggered saves rarely truly collide, and salvage absorbs
  most of what does — the TODO-6 fixture caught an earlier draft of
  this entry overstating "nothing") while INTENT-LOG becomes the
  escalation-heavy engine (its same-paragraph frame-conflict residual
  bites hardest when editors observe peers ~10s late); de-rtc still
  pays in bytes. Zero loss everywhere, certified by
  `test_save_sync_session_converges_on_every_engine`. Run
  `npm run bench -- scenarios=save-sync-session` for current numbers.

## Known gaps and qualifications

Residual facts that color conclusions but don't rise to TODOs of their
own:

- **de-rtc storage/wire bytes scale with document size.** A multiple of
  intent-log's stored bytes over the same session even at a small
  document, growing linearly — and the same tail is what a later joiner
  downloads (several times the other engines' join payloads). Run
  `long-form` at YOUR document sizes before concluding. Deep-lag
  behavior is distinct: rarely-reading clients escalate more, and past
  the 20-version snapshot window their proposals fall back to
  revision-mined bases (TODO-15) — voiding and retrying only when no
  revision carries the base (scenario G).
- **Intent-log same-paragraph typing can escalate instead of merging**
  (scenario C). The echo race that corrupted canvas text is fixed —
  capture diffs the editor tree against the document state that tree
  reflects and authors at its seq (see "THE OBSERVED BASELINE" in
  `src/engines/intent-log-manager.ts`); what remains is an escalation
  *rate* residual, tracked under TODO-6. AGENTS.md lists the remaining
  residuals, including one OPEN schedule-dependent fuzzer finding
  (empty-genesis room + mid-session reload).
- **yjs-server under heavy write concurrency can ask a client to
  resync** (scenario G). Measured with `npm run bench -- concurrency=8`:
  most runs settle fully applied with zero voids, the occasional run a
  handful of benign `resync-required` voids that heal by full-state
  upload. intent-log showed zero voids under the same load, paying
  with measured lock queueing. de-rtc (lock-free since TODO-1) pays
  with optimistic re-merge retries — and at hammer cadence a share of
  contenders settles as escalations or stale-base voids instead of
  waiting: surfaced, retryable outcomes, zero lost work by the same
  oracle, and exactly the shape upstream's validate-and-retry model
  predicts under contention it was never designed to queue. The benchmark
  treats `resync-required` as benign and `invalid-payload` as REAL loss
  that fails the run.
- **yjs-server ingest cost is real and scales with document size**, and
  the save path is worse (a cold request decodes the whole canonical
  doc; `materialize_us` in the benchmark). This used to be an order of
  magnitude worse: the dominant cost was a quadratic in the vendored
  y-php V2 string decoder, fixed 2026-08-18 by a marked DELTA in
  `includes/lib/y-php/src/Lib0/StringDecoder.php` (held to byte-parity
  by the conformance suite). The remaining one-decode-two-encodes per
  request is the structural floor for a server-authoritative CRDT in
  per-request PHP.
- **yjs-server rooms are size-gated at both ends**: genesis refuses to
  initialize above `wp_sync_yjs_server_max_genesis_bytes` (default 1 MB;
  RTC never activates, writes 413), and a room that GROWS past
  `wp_sync_yjs_server_max_room_bytes` (default 8 MB) rejects further
  writes with 413 while reads/saves continue (TODO-8 tier 2). What the
  ceiling cannot do is shrink an over-limit room — epoch compaction is
  the parked tier 3.
- **de-rtc sessions author block-native descriptors (TODO-2a done)**:
  every session proposal carries hash-pinned tamper evidence the server
  validates once against the plain declared base, then drops before the
  kses/salvage lanes. Machine writers and the save lane stay
  descriptor-less by design (the server's engine-unaware-writer lane
  derives operations); merge behavior is identical either way.
- **Genesis blocks must set `isValid: true`** or the editor renders
  them as invalid-content recovery blocks (has bitten).
