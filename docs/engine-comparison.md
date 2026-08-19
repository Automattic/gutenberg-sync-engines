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
  implemented the way Core would implement them.
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
| P3 conflicts surfaced | **Meets.** Per-register review lane; residual over-escalation on same-paragraph bursts (rate, not silence) | **Violates, by documented policy.** Register conflicts resolve by silent CRDT last-writer-wins; no review lane. Stated on the settings screen; pinned by the escalation-criteria fixture | **Meets.** Conflicts park for review at BLOCK grain (per-block salvage: the clean remainder lands, exactly the conflicted blocks park; whole-proposal parking only for structural divergence), and the client-side same-block LWW is retired: kept blocks declare their TRUE base (`blockBaseVersions`), so real same-block concurrency merges when non-overlapping and parks when it overlaps. Residual: a client that omits the map (legacy/simple writers) still presents sole-writer changes |
| P4 machine writers | **Met for read-modify-write.** `wp_update_post( …, 'intent_log_base_seq' => N )` (REST: `base_seq`) diffs the save against the declared base by persisted syncId and authors typed intents — transforms merge concurrent work, collisions park for review, the save lands as merged canonical. Unaware writers still bypass the room (no detection stamp) | **Accepted limitation.** Ingest speaks binary CRDT updates; a diff-to-CRDT lane would be semantically worse, not just costly | **Met.** Unaware writers heal in (scenario F) and cooperating writers merge through the room: `wp_update_post( …, 'base_version' => 'vN' )` — WP-CLI, plugins, REST (`base_version` param on posts/pages) — three-way-merges via the ingest lane with per-block salvage and review parking; conflicts reject the save with a rich 409 |
| P5 cheap hosting | **Meets.** Cheapest per-ingest CPU; Core-style options-row lock, topology-safe | **Partially.** No lock (good); heaviest per-ingest CPU, scaling with document size | **Partially.** Cheap CPU; lock-free optimistic claims, topology-safe; wire/storage bytes still scale with document size |
| P6 measured economics | **Meets.** Real wire format in its benchmark profile | **Meets.** Real wire format; convergence oracle | **Meets.** Real wire format; disposition/lineage oracle |
| P7 intent & identity | **Meets.** Typed intents end-to-end; syncIds persist in saved `post_content` and round-trip genesis | **Fails.** Snapshot-diff binding inherited from the relay; no semantic ops, no stable identity in the merge | **Designed for it, wired.** Block identity + rich-text ops live in the merge core, and sessions now author the block-native descriptor client-side (hash-pinned base/proposed + operation fingerprints, byte-parity with the PHP derivation enforced by 75 PHP-generated vectors); the server validates it once against the plain declared base and rejects mismatches (`de_rtc_sync_meta_tampered`). Merge outcomes remain server-derived and identical either way — the descriptor is a P1 integrity surface |

Three honest readings of that table:

- **intent-log** is the closest fit to the principles today. Its main
  risk is a rate problem, not a design problem: same-paragraph bursts
  can over-escalate while an editor is behind on a peer's change. The
  escalation-criteria fixture polices that rate as a policy band.
- **yjs-server** is a deliberate P3/P7 trade: it buys the lowest
  escalation rate and lock-free ingest by hiding conflicts. That is a
  defensible *policy* only if we say it out loud everywhere the engine
  is offered — and we do: silent register-LWW is the engine's formal,
  documented policy, stated on the settings screen and pinned by the
  escalation-criteria fixture. Revisiting that decision starts with
  building conflict detection, a research project the CRDT's design
  premise resists.
- **de-rtc** is the engine whose upstream vision aligns best with P3/P4
  — and our RTC adaptation is currently *less* faithful to that vision
  than the save-centric original. The client-side keep-local rebase
  (block-level last-writer-wins) and whole-proposal conflict parking are
  artifacts of our port, not of Dennis's design; the original resolves
  the same situations through explicit human adoption of pending edits
  and per-block partial acceptance. Restoring fidelity was a program,
  not a patch — and it is essentially complete: the audit below records
  what each restoration did, and the remaining deltas are UI and
  cadence surface, not merge semantics (V1.md B3–B5).

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
| Save and Sync are distinct, deliberate operations; "pending edits" are the unit of adoption | Editors confirm their own changes and *choose* to adopt others'; sync may be polled, socketed, or run manually with long delays | Contested-only pending: a peer's edit landing on a block you are editing raises ONE merge-not-stack pending item resolved by explicit Adopt/Reject. And the commit lane IS the save lane: sessions commit through the autosave endpoint (real saves through the base-version preflight, settle-and-held), the transport carries no proposals at all — advisory announces, on-demand snapshots, review rows, presence. The commit cadence is the settle cycle (the 10 s dial is a setting away) | **Restored** (residuals: the commit-cadence dial is not yet exposed and review resolutions still ride transport rows — V1.md B4/B5) |
| Sync metadata co-located with saved `post_content` (a `wp/post-sync-meta` pseudo-block); revisions become a backup mechanism | The document's history travels with the post; any writer that round-trips content carries the lineage; recovery mines revisions and autosaves | Restored as write-through: every save of a de-rtc-roomed post embeds the room's sync-meta (upstream's exact grammar) at the content edge, revisions copy it, and genesis adopts it back — resuming the version lineage after a room reset. Room meta remains the *working* store; the full inversion (post as sole durable store) is a recorded future direction (architecture item 2) | **Restored (write-through)** |
| Self-healing when unaware writers mangle the document | The server detects CRDT/content divergence, recovers from revisions or autosaves, and appends a repairing edit so "operations which would otherwise wipe-out a post appear as any other collaborative update" | Restored: room load detects out-of-band `post_content` writes (the co-location `content_hash` stamp is the tell), three-way-merges meta-carrying external edits with concurrent session work, converges to meta-less replacements, refuses to roll back stale copies, and parks genuine conflicts for review | **Restored** (scenario F) |
| Arbitrarily long offline editing still recombines | Old bases recoverable via the co-located history and revision copies | Restored: a base past the room's 20-version window resolves from post revisions (each aware save embeds its own snapshot window, hash-verified), so deep-lag proposals merge with intervening work intact; only a base no revision carries still voids | **Restored** |
| Undo/redo "never undo, but rather apply revert edits"; a history slider scrubs versions | Explicitly offered to RTC: "This could easily be adopted by RTC" | Restored: de-rtc's undo derives revert edits from the client's OWN accepted canonical rows (per-block, with an untouched-since guard so peer work is never collateral) and applies them as ordinary dirty edits that propose like any change; redo re-applies the reverted delta. The history-slider UI remains future editor UX | **Restored** |
| Reviewers can modify before adopting | The prototype's review schema carries `reviewed_block_source` ("modify-and-adopt"); approvals are hash-pinned | Restored: `restoreProposalWithChanges()` applies the reviewer's edited replacements for specific parked blocks — what the reviewer supplies is exactly what applies and re-proposes under their capability, pinning approval and content by construction. API-level; the review panel UI still offers plain restore/dismiss | **Restored (API)** |
| Per-edit authorship: "hover over a user's avatar and highlight the changes they applied" | Range-grain attribution; the prototype shipped authorship-focus overlays | Data surface restored: content rows carry the server-stamped author user id, and the client derives block-grain "who last touched this" from row-vs-base diffs at zero extra wire cost (`engine.authorship.getBlockAuthorship()`; structural shifts reset to unknown rather than lie). The hover overlay is future editor UX; range grain could draw on the descriptor lane's operation evidence | **Restored (data)** |
| Per-block kses sequestration — "accept partial edits, adopting the safe parts" | Prototype-proven | Restored as the shipping capability lane | **Faithful** |
| The shipping merge is the hand-written block-aware three-way merge; Automerge backs only the legacy lane | Same | Same — ported verbatim as a frozen call-graph closure | **Faithful** |
| Optimistic concurrency; no database lock | Base-version preflight, hash validation, merge-and-retry on the save path | Lock-free again: accepted proposals atomically claim their version advancement and a lost claim reloads + re-merges (`WP_Sync_Atomic_Option` CAS) | **Restored** |
| Clients need no CRDT library; Gutenberg couples via semantic Redux actions | Stage 3 of the development plan | The client rides a `Y.Doc` editor bridge (awareness reuse); sessions author the block-native descriptor but the doc bridge itself remains a CRDT editor adapter | **Adaptation debt narrowed.** The Y.Doc bridge remains (architecture item 4) |
| Cheap-host cadence is a feature: "that $3/mo host … can still support multiple concurrent edit sessions polling … once every ten seconds" | Polling interval scales to the host's comfort; presence is separate from content | Measured fairly now: the `save-sync-session` scenario runs every engine at the vision's cadence — where de-rtc escalates nothing and intent-log becomes the escalation-heavy engine (its stale-observation residual), inverting the per-second ranking | **Measured**; the operating-cadence dial itself is open (V1.md B4) |

The pattern across the corrupted rows is one pattern: wherever DE-RTC's
save-centric, post-co-located design met this plugin's room protocol,
the protocol won. The fidelity program reverses that default.

## Feature parity

| Area | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge, but ON THE SERVER — outcomes observable, still no review lane (conflict DETECTION is the undesigned prerequisite) | Three-way merge on the server; genuine conflicts PARK as durable `proposal-parked` rows and present in the same review panel (restore re-proposes under the reviewer; dismiss resolves; retention survives compaction — e2e-verified) |
| Collaborative undo | Inverse intents over the accepted log (`src/engines/intent-log-undo.ts`): per-user undo/redo, transformed over peers' rows, conflicts park for review. Armed immediately: a still-pending unit CANCELS (outbox + a wire-chasing `cancel` row; a lost race resurrects the unit as a settled candidate), a settled unit inverts | Per-peer undo manager (`src/engines/yjs/undo.ts`, inherited from the retired relay) | Revert-edit undo (the vision's model): undo derives a revert from the client's own accepted canonical rows (per-block, untouched-since guard) and proposes it as an ordinary new change; redo re-applies the reverted delta |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | Server holds the canonical doc; a rejoining client re-bootstraps from the retained snapshot + tail and uploads its own state idempotently. Solo edits flush every poll (`syncWhileSolo`) — REQUIRED here, not an optimization: a page reload holds no local state to upload, so a room that never saw the solo session's updates would bootstrap the editor back to its stale snapshot, wiping the freshly loaded record (e2e-covered: the solo save-and-reload spec) | Server holds canonical content + version snapshots; a rejoining client re-bootstraps from the retained snapshot + content rows. Un-acked local edits re-propose (the server merges); the save-centric model keeps the room tracking saves, so a solo save-and-reload survives without `syncWhileSolo` (verified) |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update, IDEMPOTENT server-side (the server diffs out what it already has — redelivery settles as a benign `already-merged` void); the server explicitly requests it with a `resync-required` void when an update's dependencies are missing from the room | Recovery re-proposes the doc's current state; if the lost send landed, the re-proposal merges as a no-op |
| History compaction | Server checkpoints every 100 intent rows and trims | Server checkpoints every 100 rows and trims — abandoned rooms stay bounded | Server checkpoints every 100 rows and trims (same retention invariant) |
| Genesis | Server, from post content | Server, from post content — deterministic build, so racing initializers merge idempotently | Server, from post content — deterministic, and ADOPTS an upstream DE-RTC sync-meta block if one is embedded (version lineage continues) |
| Capability enforcement | At ingest (kses lane; escalation for `unfiltered_html`-gated content parks for approval — restore by a privileged reviewer IS the approval) | At ingest, sanitize-and-compensate: blocks a filtered author's batch touched that kses would rewrite are REPLACED with their sanitized form and the compensating delta broadcasts (filter-on-save semantics; nothing parks — coarser than intent-log by design) | At ingest, per-block SEQUESTRATION (upstream's model): risky blocks revert to their base form and park for review while the safe remainder of the proposal merges and lands; markup-bearing property values park per property; restore under a privileged reviewer approves. Whole-proposal escalation remains the fallback (freeform boundaries) |
| Synced entity properties | The framework's full set as per-name registers: the scalar whitelist (title, excerpt, slug, status, comment_status, ping_status, format, sticky, author, featured_media, date, template), attached taxonomies (whole term-ID arrays by rest_base), and registered post meta (per-key `meta.<key>` registers, `_crdt_document` excluded). Collection rooms implement the framework's save-notification contract (per-client save registers), so a newly created term reaches every peer's term list by refetch | Whatever the sync config maps into the CRDT (the full framework set, including per-key post meta and taxonomies), and genesis seeds the same shared REST-shaped property map the other engines seed; collection rooms carry the savedAt state key for the same refetch contract | The full flattened register map rides every proposal beside the content (title, scalars, taxonomies, `meta.<key>`); the server three-way-merges per property against the base version — sole-writer changes and agreements apply, concurrent divergent writes park per property for review. Genesis seeds the shared property map |
| Presence/awareness | Yes (shared Yjs-free awareness doc) | Yes (Yjs awareness, relayed opaquely — the server does not decode it) | Yes (Yjs awareness over the doc bridge, relayed opaquely) |
| Server observability | Dispositions, debug envelope, benchmark quality metrics | Per-update dispositions, CRDT convergence oracle, materialization | Per-proposal dispositions (applied/escalated/voided with reasons), version lineage, materialization |
| Materialize to post_content | Yes (server-side; block identity persists as `metadata.syncId` and round-trips genesis) | Yes (server-side, from the canonical doc) | Trivially — the canonical document IS post content |
| Wire format | Small human-readable JSON intents | Opaque base64 binary (V2) + JSON snapshot rows | Human-readable JSON: whole-content commits up (via the autosave endpoint), constant-size announce advisories down, with on-demand synthesized snapshots for behind clients (upload bytes scale with document size; rows do not) |

## Resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning — the cheapest of the three | Load + merge + re-encode the canonical y-php doc — the dominant cost of the three, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees) — cheap at benchmark sizes, scales with document size |
| Locking | Per-room Core-style options-row lock (`WP_Sync_Room_Lock`, the upgrader pattern: atomic INSERT IGNORE + TTL; 5 s wait budget, contenders get a retryable 503). One claim/release pair of DB writes inside every timed request — the engine benchmark's `calibration` block exists to subtract it. Topology-safe by construction | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | None — lock-free optimistic version claims (`WP_Sync_Atomic_Option` CAS): an accepted proposal atomically claims v(n+1), a lost claim reloads + re-merges, exhaustion returns the retryable 503. Upstream's validate-and-retry model, restored |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows, and accepted proposals store ~200-byte ANNOUNCE rows (version + content hash; canonical content lives once per room, fetched on demand) — row bytes no longer scale with document size, closing the PHP-memory cliff the hour-scale soak found under the old full-content rows |
| Row contents | Small JSON intents + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Constant-size `announce` advisories (version + canonical content hash + merged property registers) and tiny `fetch` requests; canonical content lives ONCE per room in a chained options row, and a behind client's fetch is answered with one synthesized, never-stored snapshot. Version snapshots ride room meta |

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
than no numbers. Run `npm run bench` for
current numbers on your hardware — every claim below is a shape the
tables it prints make concrete. The stable shape: intent-log is the
cheapest per ingest; de-rtc costs a small multiple of it (the content
three-way merge); yjs-server is the most expensive per ingest (the
canonical document is decoded, merged, and re-encoded in PHP on every
request) and is the one whose service time grows with document size.
For scale, the retired append-only relay sat at the timer floor — read
it as "negligible", and as the price of observing nothing. Where de-rtc
pays is upload bytes and request count, not cycles: each commit still
carries the session's whole content UP (through the ordinary autosave
endpoint — the transport itself carries no session proposals), and
commit POSTs roughly double the per-typist request rate at
pseudo-realtime cadence. The broadcast and storage side no longer
scales with document size: accepted work broadcasts a constant-size
announce, canonical content is stored once per room, and only a client
whose content hash disagrees downloads a document (one synthesized
fetch answer) — the active typist advances by hash and downloads
nothing. One fairness caveat stands: the session scenarios run at the
harness's RTC poll cadence, which measures de-rtc as we adapted it, not
as designed; the `save-sync-session` scenario measures the vision's
save-and-sync cadence, where both profiles differ.
All three engines' payload/storage bytes are REAL (each benchmark
profile speaks its engine's actual wire format), all three converge
with **zero lost work** on every scenario, and the escalation policies
differ visibly on the same contended workload: intent-log parks
escalations at per-register grain, de-rtc at per-block grain
(whole-proposal only for structural divergence), yjs-server
none (silent CRDT last-writer-wins).

The session-shaped scenarios add the time dimension single workloads
miss. Under `structural-churn` (concurrent block inserts/removals plus
typing) the conflict policies separate hardest: intent-log and
yjs-server merge everything cleanly while de-rtc escalates a share of
proposals — structural divergence is what per-block salvage
correctly refuses, so those conflicts still park whole; since salvage,
the non-structural share lands its clean blocks and the overall
escalation rate dropped markedly (run the scenario for the current
share); nothing is lost on any engine. `remove-contention` isolates the edit-vs-remove
conflict class; `field-sync` separates the same policies at field grain
(see the scenario narratives below for what actually happens on the
wire). Under a ten-minute three-user `editorial-session` (joins, typing
bursts, per-second polling, autosaves), intent-log's service time holds
flat, de-rtc's holds nearly flat (its room tail is constant-size
advisories since the announce inversion — the megabyte-scale tail
growth the old full-content rows showed here is structurally gone, and
a joiner downloads one synthesized snapshot instead of that tail), and
**yjs-server's ingest grows with the accumulating document**. Run `editorial-session
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
surfaced conflicts under per-block salvage: partial acceptance parks blocks while
the proposal reports applied, so the honest metric is escalations PLUS
parked rows, which is what the fixture measures.

Two costs live off the edit path and are easy to miss; the benchmark
reports both. The **later-joiner read** (a cold read at cursor 0 — what
a fresh visitor downloads to enter the room after a session): modest
under all three engines since the announce inversion — de-rtc's
retained tail is constant-size advisories, and the joiner's actual
content arrives as ONE synthesized snapshot rather than the
full-content row tail that used to make this read the largest by a
wide margin. The **save path**
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
  after the burst quiets, the unit "settles". Undo works throughout: a
  still-pending unit cancels (outbox removal plus a wire-chasing
  `cancel` row), a settled unit inverts.
- **yjs-server**: Keystrokes apply to the local Y.Doc instantly, so
  undo is armed from the first character. The provider encodes an
  incremental binary update; the next poll delivers it; the server
  decodes the canonical doc, merges, re-encodes, and stores the diff
  row. No lock, no transform.
- **de-rtc**: Keystrokes edit the doc and mark it dirty. When the burst
  settles and no commit is in flight, the client COMMITS its WHOLE
  content against the version it last incorporated — through the
  ordinary autosave endpoint (`WP_De_RTC_Autosave_Commits` intercepts
  the commit shape; the room transport carries no session proposals).
  The server three-way-merges (a fast-forward solo), claims the version
  advance (an uncontended CAS write), persists canonical once in the
  room's chained options row, and stores a ~200-byte `announce`
  (version + content hash + property registers). The next poll delivers
  the announce; the hash matches the typist's own content, so it
  advances its version downloading nothing.

### B. Two editors, different blocks (the common concurrent case)

All three merge losslessly; they differ in *how* and in *what travels*.
intent-log transforms each editor's intents over the other's rows — the
transforms are no-ops because the frames don't intersect. yjs-server's
CRDT merges the updates commutatively. de-rtc three-way-merges each
whole-content commit against base and current: each editor's block
change is a sole-writer change to its block, so both land. The upload
side carries each editor's entire document per commit; the download
side is an announce whose hash doesn't match (the peer merged new
work), so each editor fetches ONE synthesized snapshot of the merged
canonical (P5/P6: de-rtc pays in upload bytes and in a
document-per-incorporation download — no longer in stored rows).

### C. Two editors, the same paragraph (the policy separator)

- **intent-log**: When both edits are observed before the next burst,
  offsets transform and the texts merge. The residual: while this editor
  is still *behind* on the peer's change to the same paragraph, the later
  keystrokes of its burst escalate as `frame-conflict` — parked in the
  review lane, never lost — and normal merging resumes once the peer's
  change is observed. An over-escalation rate problem (P3 honored,
  arguably too eagerly; the escalation-criteria fixture polices the
  rate).
- **yjs-server**: Character-level CRDT merge interleaves both texts
  deterministically; block-attribute (register) collisions resolve by
  silent last-writer-wins. Nothing surfaces to a human (P3 violation,
  documented policy).
- **de-rtc**: The peer's accepted commit announces mid-burst; the
  local hash disagrees, so the client fetches the canonical it names
  (one synthesized snapshot). The client cannot merge (clients never
  merge) and cannot apply it verbatim (that would clobber unsent
  keystrokes), so it *incorporates*: adopts canonical blocks it hasn't
  touched and keeps its own version of the contested block — but it
  also RECORDS the version that block's text was really written
  against, and its next commit declares it (`blockBaseVersions`).
  The server merges the contested block from its TRUE base:
  non-overlapping concurrent edits to the same block merge (both texts
  land), true overlaps park for review at block grain while
  the clean remainder lands. The silent block-level last-writer-wins
  this moment used to cause is retired; and the
  interaction model matches the vision's shape: the colliding
  incorporation raises ONE pending item on the review surface (later
  peer edits to the same block refresh it rather than stacking),
  resolved by explicit Adopt (take the latest canonical form) or
  Reject (keep yours — the recorded base keeps the next server merge
  honest). One timing rule guards the burst itself: while the server
  has merged PEER work into this client's own accepted commit (a newer
  version exists whose content the client does not hold yet), the
  client holds its commit lane — committing against the dead pre-merge
  base would have the server treat its own just-accepted keystrokes as
  a foreign concurrent change and park them (the fuzzer found exactly
  this eating the tail of bursts that straddled a commit round trip).
  The residual: a map-less legacy client still presents
  sole-writer changes. Structural divergence still parks the commit
  whole.

### D. Edit versus remove (one client types into a block another removes)

intent-log escalates the trailing side: if the removal lands first, the
trailing keystrokes park as `target-deleted`; if the text lands first,
both apply and the token vanishes with the removed block. yjs-server
escalates nothing — CRDT deletion dissolves the edit with the deleted
block, deterministically and invisibly. de-rtc still escalates the
most: the structural change shifts the base under the whole-content
commit, and structural divergence is exactly the class per-block
salvage refuses — the trailing commit parks whole (roughly one
escalation per contended pair; per-block salvage removed the *collateral* from
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
  safe remainder of the commit merges and lands. Restore re-proposes
  them under the RESTORER's capability, so restore is the approval.
  Whole-proposal escalation remains the fallback for freeform
  boundaries.

### F. An out-of-band machine write lands mid-session (P4 — de-rtc solves it; the others don't)

A scheduled integration fetched the post before the session and writes
back its modified copy while two editors are collaborating.

- **de-rtc**: a *cooperating* integration passes
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
- **intent-log**: a *cooperating* integration declares
  `intent_log_base_seq` and its save diffs into typed intents — the
  session's concurrent work merges by transform, register collisions
  park for review, and the save lands as the merged canonical. An
  *unaware* intent-log writer still bypasses the room: canonical
  diverges from `post_content` and the session's next materializing
  save clobbers the write — this engine has no divergence-detection
  stamp yet (an accepted v1 residual).
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
- **de-rtc**: The client re-commits its doc's current state; if the
  lost send actually landed, the re-commit merges as a no-op (and its
  announce's hash confirms it). A stale
  base within the engine's 20-version snapshot window is fine — that's
  what the three-way merge is for (though cumulative staleness escalates
  more). Beyond the window the server first mines post revisions for
  the base (each aware save embeds its own snapshot window),
  so even arbitrarily old bases usually merge; only a base no revision
  carries voids as `unknown-base-version`, and the client retries
  against a fresher base: fetch canonical (one synthesized snapshot),
  rebase, re-commit. The
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
adaptation under review (architecture item 3) — that engine is allowed
to declare its own transport story, including "manual sync with long
delays," without penalty.

Websocket specifics: the one-time auth token rides the
`Sec-WebSocket-Protocol` offer list rather than the URL query string
(query strings land in server and proxy access logs), and plaintext
`ws://` must never leave a dev box — TLS termination in front of the
daemon is the operator's job. The transport remains experimental until
a real-daemon e2e lane exists; the websocket-only suite currently
exercises a test provider, not the daemon (V1.md A3). For hour-scale
per-user costs with a convergence gate, run the soak harness
(`tests/benchmarks/transport/soak-transport.mjs`).

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
   should be able to say so. Feeds the deeper Save/Sync inversion (a
   post-v1 direction; see V1.md's out-of-scope list).
2. **Canonical state lives in plugin storage posts for every engine.**
   Room meta was chosen for plugin containment. For the log-is-truth
   engines it is a reasonable substrate. For de-rtc it inverted the
   vision: the canonical document is supposed to BE the post, with
   sync-meta riding `post_content` and revisions as the backup
   mechanism. De-rtc's co-location (write-through) and self-healing
   have since been restored, so de-rtc now passes scenario F;
   intent-log and yjs-server still fail it. What remains of this
   revisit: make the storage substrate an engine decision and complete
   the inversion for de-rtc (room rows demote to a transport cache) as
   part of the deeper Save/Sync inversion.
3. **Transport universality as a design goal.** A fine property for
   log-shaped engines and a Procrustean bed for DE-RTC. Revisit:
   transports become a capability an engine declares, and the
   comparison stops treating transport-independence as a virtue worth
   buying at the price of engine fidelity.
4. **Client machinery reuse across engines.** de-rtc's client rides a
   `Y.Doc` editor bridge purely to reuse the shared Yjs awareness
   plumbing (the borrowed local-snapshot undo it once forced has been
   replaced by revert-edit undo). The CRDT dependency the vision says
   clients don't need remains; the descriptor lane is the natural
   coupling point for moving de-rtc's client onto the editor's
   semantic actions instead of a shadow CRDT.

## Remaining work

Open work items live in `V1.md` at the repo root, each with acceptance
criteria and a lane (autonomous vs human-review). This guide cites a
V1.md item wherever an open gap colors a comparison; anything completed
or explicitly rejected is simply described as current state above, and
its history lives in git.

## Known gaps and qualifications

Residual facts that color conclusions but don't rise to work items of
their own:

- **de-rtc's document-size costs live on the commit path, not in
  storage.** Since the announce inversion, stored rows are
  constant-size advisories and a later joiner downloads one
  synthesized snapshot — the old linearly-growing full-content tail
  (once a multiple of intent-log's stored bytes, and the largest join
  payload) is structurally gone. What still scales with document size:
  each commit's upload body (whole content up), the fetch answer a
  behind client downloads, and per-ingest merge CPU/memory. Run
  `long-form` at YOUR document sizes before concluding.
  Deep-lag behavior is distinct: rarely-reading clients escalate more,
  and past the 20-version snapshot window their proposals fall back to
  revision-mined bases — voiding and retrying only when no
  revision carries the base (scenario G).
- **Intent-log same-paragraph typing can escalate instead of merging**
  (scenario C). The echo race that corrupted canvas text is fixed —
  capture diffs the editor tree against the document state that tree
  reflects and authors at its seq (see "THE OBSERVED BASELINE" in
  `src/engines/intent-log-manager.ts`); what remains is an escalation
  *rate* residual, policed by the escalation-criteria fixture's policy
  bands. AGENTS.md lists the remaining residuals; the OPEN
  schedule-dependent fuzzer finding (empty-genesis room + mid-session
  reload) is V1.md A1.
- **yjs-server under heavy write concurrency can ask a client to
  resync** (scenario G). Measured with `npm run bench -- concurrency=8`:
  most runs settle fully applied with zero voids, the occasional run a
  handful of benign `resync-required` voids that heal by full-state
  upload. intent-log showed zero voids under the same load, paying
  with measured lock queueing. de-rtc (lock-free) pays
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
  writes with 413 while reads/saves continue. What the ceiling cannot
  do is shrink an over-limit room — epoch compaction, which would, is
  parked as post-v1 work.
- **de-rtc sessions author block-native descriptors**:
  every session proposal carries hash-pinned tamper evidence the server
  validates once against the plain declared base, then drops before the
  kses/salvage lanes. Machine writers and the save lane stay
  descriptor-less by design (the server's engine-unaware-writer lane
  derives operations); merge behavior is identical either way.
- **yjs-server materialization still carries the wrapper
  simplification** (intent-log's twin was fixed by client-authored save
  markup; the yjs fix needs framework changes — V1.md B1), and its
  genesis wrongly stores stripped inner markup in the first
  rich-text-source attribute (e.g. `<img>` in an image's `caption` —
  V1.md A4).
- **de-rtc sync-meta co-location residuals**: the `wp/post-sync-meta`
  pseudo-block is visible to non-collaborative editors and in raw
  front-end markup (upstream's protection periphery is unported), and
  autosave REST writes update the autosave revision directly without
  re-embedding.
- **de-rtc's plain-save blind spot**: a machine write through
  `wp_update_post` WITHOUT `base_version` passes the co-location
  filter, gets a fresh matching `content_hash` stamp, and therefore
  looks "aware" — it neither merges nor heals, and the room diverges
  until the next session save. The covered classes are preflighted
  writers (merge) and filter-bypassing writers (heal); resolving the
  plain-save class principledly belongs to the deeper Save/Sync
  inversion (post-v1).
- **de-rtc commit POSTs roughly double the per-typist request rate** at
  pseudo-realtime cadence (bytes collapsed under the announce model;
  request counts did not), and collections plus unsupported post types
  keep the transport proposal lane as a fallback.
- **Genesis blocks must set `isValid: true`** or the editor renders
  them as invalid-content recovery blocks (has bitten).
