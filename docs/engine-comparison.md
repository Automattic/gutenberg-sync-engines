# Choosing a sync engine and transport

**Short answer: start with intent-log** (it is also the default). It is
the cheapest engine to run, it never loses an edit, and when two people
genuinely clash it stops and asks a human. Choose **de-rtc** if scripts
and plugins also write to your posts, or if you want a slow
save-and-sync rhythm on a cheap host. Choose **yjs-server** if you want
two people to type in the same sentence and have the letters interleave
— and you accept that some clashes get resolved silently, with nobody
told. The rest of this guide explains those trade-offs.

The engines are judged against the seven principles in
[principles.md](principles.md); [scenarios.md](scenarios.md) traces
concrete situations through all three engines;
[transports.md](transports.md) covers the separate transport axis. This
guide deliberately carries no measured numbers (they go stale and
mislead). To regenerate every number behind it on YOUR hardware, run
`npm run bench` against a running tests env — the whole decision
matrix, with per-scenario comparison tables and hosting cost cards,
failing loudly if any engine loses work. `npm run bench -- certify=10`
re-certifies the never-lose-work invariant across ten seeds per engine.

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
  ([de-rtc-fidelity.md](de-rtc-fidelity.md) audits this port against
  its upstream design.)

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
| P3 conflicts surfaced | **Meets.** Per-register review lane; residual over-escalation on same-paragraph bursts (rate, not silence) | **Violates, by documented policy.** Register conflicts resolve by silent CRDT last-writer-wins; no review lane. Stated on the settings screen; pinned by the escalation-criteria fixture | **Meets.** When an edit clashes, only the blocks that actually clash are held for review — the rest lands (the whole edit is held only when the two sides changed the document's *structure*, where blocks can no longer be matched up). Each client declares which version each kept block was really written against (`blockBaseVersions`), so simultaneous edits to the same block merge when they don't overlap and park when they do. Residual: an old or simple client that omits the declaration still presents its changes as if it were editing alone |
| P4 machine writers | **Met for read-modify-write.** `wp_update_post( …, 'intent_log_base_seq' => N )` (REST: `base_seq`) diffs the save against the declared base by persisted syncId and authors typed intents — transforms merge concurrent work, collisions park for review, the save lands as merged canonical. Unaware writers still bypass the room (no detection stamp) | **Accepted limitation.** Ingest speaks binary CRDT updates; a diff-to-CRDT lane would be semantically worse, not just costly | **Met.** Unaware writers heal in ([scenario F](scenarios.md)) and cooperating writers merge through the room: `wp_update_post( …, 'base_version' => 'vN' )` — WP-CLI, plugins, REST (`base_version` param on posts/pages) — three-way-merges via the ingest lane with per-block salvage and review parking; conflicts reject the save with a rich 409 |
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
  — and restoring that vision after our port damaged it was a program,
  not a patch. It is essentially complete: the
  [fidelity audit](de-rtc-fidelity.md) records what each restoration
  did, and the remaining deltas are UI surface, not merge semantics
  (V1.md B3–B5).

## Feature parity

| Area | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge, but ON THE SERVER — outcomes observable, still no review lane (conflict DETECTION is the undesigned prerequisite) | Three-way merge on the server; genuine conflicts PARK as durable `proposal-parked` rows and present in the same review panel (restore re-proposes under the reviewer; dismiss resolves; retention survives compaction — e2e-verified) |
| Collaborative undo | Inverse intents over the accepted log (`src/engines/intent-log-undo.ts`): per-user undo/redo, transformed over peers' rows, conflicts park for review. Armed immediately: a still-pending unit CANCELS (outbox + a wire-chasing `cancel` row; a lost race resurrects the unit as a settled candidate), a settled unit inverts | Per-peer undo manager (`src/engines/yjs/undo.ts`, inherited from the retired relay) | Revert-edit undo (the vision's model): undo derives a revert from the client's own accepted canonical rows (per-block, untouched-since guard) and proposes it as an ordinary new change; redo re-applies the reverted delta |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | Server holds the canonical doc; a rejoining client re-bootstraps from the retained snapshot + tail and uploads its own state idempotently. Solo edits flush every poll (`syncWhileSolo`) — REQUIRED here, not an optimization: a page reload holds no local state to upload, so a room that never saw the solo session's updates would bootstrap the editor back to its stale snapshot, wiping the freshly loaded record (e2e-covered: the solo save-and-reload spec) | Server holds canonical content + version snapshots; a rejoining client re-bootstraps from the retained snapshot + content rows. Un-acked local edits re-propose (the server merges); the save-centric model keeps the room tracking saves, so a solo save-and-reload survives without `syncWhileSolo` (verified) |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update, IDEMPOTENT server-side (the server diffs out what it already has — redelivery settles as a benign `already-merged` void); the server explicitly requests it with a `resync-required` void when an update's dependencies are missing from the room | Recovery re-proposes the doc's current state; if the lost send landed, the re-proposal merges as a no-op |
| History compaction | Server checkpoints every 500 intent rows and trims (live-authoring-sized: coarse captures cost ~3 rows per keystroke, and a trim crossing mid-burst voided the burst's tail — V1 A15) | Server checkpoints every 100 rows and trims — abandoned rooms stay bounded | Server checkpoints every 100 rows and trims (same retention invariant) |
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
| Storage growth | Bounded: checkpoint + trim every 500 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows, and accepted proposals store ~200-byte ANNOUNCE rows (version + content hash; canonical content lives once per room, fetched on demand) — row bytes no longer scale with document size, closing the PHP-memory cliff the hour-scale soak found under the old full-content rows |
| Row contents | Small JSON intents + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Constant-size `announce` advisories (version + canonical content hash + merged property registers) and tiny `fetch` requests; canonical content lives ONCE per room in a chained options row, and a behind client's fetch is answered with one synthesized, never-stored snapshot. Version snapshots ride room meta |

All three engines cope with two people saving at the same moment. They
each pay for it differently:

- **intent-log** handles one edit at a time per post. The second
  editor's request waits a moment. Under heavy load a request may be
  told "try again". Nothing ever has to be re-sent.
- **de-rtc** never makes anyone wait. If two edits arrive together, the
  loser redoes its merge against the newer document — the server does
  that merge work twice. Nothing has to be re-sent. Only sustained
  heavy load turns into a "try again".
- **yjs-server** never makes anyone wait either, but every simultaneous
  request pays the full merge cost, and occasionally the server asks a
  browser to send its whole document again. That is one extra round
  trip; nothing is lost.

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
(see [scenarios.md](scenarios.md) for what actually happens on the
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

## Known gaps and qualifications

Residual facts that color conclusions but don't rise to work items of
their own (open work items live in `V1.md`), grouped by engine.

### intent-log

- **Same-paragraph typing can escalate instead of merging**
  ([scenario C](scenarios.md)). The echo race that corrupted canvas
  text is fixed — capture diffs the editor tree against the document
  state that tree reflects and authors at its seq (see "THE OBSERVED
  BASELINE" in `src/engines/intent-log-manager.ts`); what remains is an
  escalation *rate* residual, policed by the escalation-criteria
  fixture's policy bands. AGENTS.md lists the remaining residuals.

### yjs-server

- **Under heavy write concurrency the server can ask a client to
  resync** ([scenario G](scenarios.md)). Measured with
  `npm run bench -- concurrency=8`: most runs settle fully applied with
  zero voids, the occasional run a handful of benign `resync-required`
  voids that heal by full-state upload. intent-log showed zero voids
  under the same load, paying with measured lock queueing. de-rtc
  (lock-free) pays with optimistic re-merge retries — and at hammer
  cadence a share of contenders settles as escalations or stale-base
  voids instead of waiting: surfaced, retryable outcomes, zero lost
  work by the same oracle, and exactly the shape upstream's
  validate-and-retry model predicts under contention it was never
  designed to queue. The benchmark treats `resync-required` as benign
  and `invalid-payload` as REAL loss that fails the run.
- **Ingest cost is real and scales with document size**, and the save
  path is worse (a cold request decodes the whole canonical doc;
  `materialize_us` in the benchmark). This used to be an order of
  magnitude worse: the dominant cost was a quadratic in the vendored
  y-php V2 string decoder, fixed 2026-08-18 by a marked DELTA in
  `includes/lib/y-php/src/Lib0/StringDecoder.php` (held to byte-parity
  by the conformance suite). The remaining one-decode-two-encodes per
  request is the structural floor for a server-authoritative CRDT in
  per-request PHP.
- **Rooms are size-gated at both ends**: genesis refuses to initialize
  above `wp_sync_yjs_server_max_genesis_bytes` (default 1 MB; RTC never
  activates, writes 413), and a room that GROWS past
  `wp_sync_yjs_server_max_room_bytes` (default 8 MB) rejects further
  writes with 413 while reads/saves continue. What the ceiling cannot
  do is shrink an over-limit room — epoch compaction, which would, is
  parked as post-v1 work.
- **Materialization still carries the wrapper simplification**
  (intent-log's twin was fixed by client-authored save markup; the yjs
  fix needs framework changes — V1.md B1), and its genesis wrongly
  stores stripped inner markup in the first rich-text-source attribute
  (e.g. `<img>` in an image's `caption` — V1.md A4).

### de-rtc

- **Document-size costs live on the commit path, not in storage.**
  Since the announce inversion, stored rows are constant-size
  advisories and a later joiner downloads one synthesized snapshot —
  the old linearly-growing full-content tail (once a multiple of
  intent-log's stored bytes, and the largest join payload) is
  structurally gone. What still scales with document size: each
  commit's upload body (whole content up), the fetch answer a behind
  client downloads, and per-ingest merge CPU/memory. Run `long-form` at
  YOUR document sizes before concluding. Deep-lag behavior is distinct:
  rarely-reading clients escalate more, and past the 20-version
  snapshot window their proposals fall back to revision-mined bases —
  voiding and retrying only when no revision carries the base
  ([scenario G](scenarios.md)).
- **Sessions author block-native descriptors**: every session proposal
  carries hash-pinned tamper evidence the server validates once against
  the plain declared base, then drops before the kses/salvage lanes.
  Machine writers and the save lane stay descriptor-less by design (the
  server's engine-unaware-writer lane derives operations); merge
  behavior is identical either way.
- **Sync-meta co-location residuals**: the `wp/post-sync-meta`
  pseudo-block is visible to non-collaborative editors and in raw
  front-end markup (upstream's protection periphery is unported), and
  autosave REST writes update the autosave revision directly without
  re-embedding.
- **The plain-save blind spot**: a machine write through
  `wp_update_post` WITHOUT `base_version` passes the co-location
  filter, gets a fresh matching `content_hash` stamp, and therefore
  looks "aware" — it neither merges nor heals, and the room diverges
  until the next session save. The covered classes are preflighted
  writers (merge) and filter-bypassing writers (heal); resolving the
  plain-save class principledly belongs to the deeper Save/Sync
  inversion (post-v1).
- **Commit POSTs roughly double the per-typist request rate** at
  pseudo-realtime cadence (bytes collapsed under the announce model;
  request counts did not), and collections plus unsupported post types
  keep the transport proposal lane as a fallback.

### All engines

- **Genesis blocks must set `isValid: true`** or the editor renders
  them as invalid-content recovery blocks (has bitten).
