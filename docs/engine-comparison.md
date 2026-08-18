# Choosing a sync engine and transport

This plugin exists to make the engine/transport decision a matter of
evidence. This guide is the interpretation layer for that evidence: what
each engine actually is, where the numbers come from, which differences are
performance and which are policy, and the known gaps that should color any
conclusion. Read it alongside the two benchmark harnesses
(`tests/benchmarks/README.md` and `tests/benchmarks/transport/README.md`).

To regenerate every number in this guide on YOUR hardware, run
`npm run bench` against a running tests env — the whole decision matrix,
with per-scenario comparison tables and hosting cost cards, failing loudly
if any engine loses work. `npm run bench -- certify=10` re-certifies the
never-lose-work invariant across ten seeds per engine.

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
byte-compatible with rooms it wrote. Where the relay appears below it is as
historical context.

## One architectural choice drives everything

- **Merging on the server** costs server CPU (and possibly a per-room
  ingest lock) and in exchange the server can *observe* outcomes: per-edit
  dispositions (applied / escalated / voided), a convergence oracle in the
  benchmark, a review lane for conflicts, and capability enforcement at
  ingest (an author without `unfiltered_html` gets raw-HTML content parked
  for review by someone who has it — the server is not relaying bytes it
  cannot inspect).
- **Merging on the clients (the retired yjs-relay)** made the server nearly
  free — append a row, read rows — and in exchange the server could observe
  *nothing*: no merge outcomes, no conflict surfacing, no content-level
  capability enforcement before save, and no benchmarkable quality metrics
  (the harness printed "NOT SERVER-OBSERVABLE" rather than faking them).
  That blindness, plus unbounded growth in abandoned rooms, is why the
  relay was retired in favor of yjs-server.

Neither remaining engine is strictly better; they price the same work
differently. The tables below are how the price shows up.

## Feature parity

| Area | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge, but ON THE SERVER — outcomes observable, still no review lane (conflict DETECTION is the undesigned prerequisite) | Three-way merge on the server; genuine conflicts PARK as durable `proposal-parked` rows and present in the same review panel (restore re-proposes under the reviewer; dismiss resolves; retention survives compaction — e2e-verified) |
| Collaborative undo | Inverse intents over the accepted log (`src/engines/intent-log-undo.ts`): per-user undo/redo, transformed over peers' rows, conflicts park for review; arms once the unit settles (rows + acks, ~a poll cycle) | Per-peer undo manager (`src/engines/yjs/undo.ts`, inherited from the retired relay) | Per-peer undo manager (shared `src/engines/yjs/undo.ts` over the local doc bridge); undone state propagates as an ordinary proposal |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | Server holds the canonical doc; a rejoining client re-bootstraps from the retained snapshot + tail and uploads its own state idempotently. Solo edits flush every poll (`syncWhileSolo`) — REQUIRED here, not an optimization: a page reload holds no local state to upload, so a room that never saw the solo session's updates would bootstrap the editor back to its stale snapshot, wiping the freshly loaded record (e2e-covered: the solo save-and-reload spec) | Server holds canonical content + version snapshots; a rejoining client re-bootstraps from the retained snapshot + content rows. Un-acked local edits re-propose (the server merges); the save-centric model keeps the room tracking saves, so a solo save-and-reload survives without `syncWhileSolo` (verified) |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update, IDEMPOTENT server-side (the server diffs out what it already has — redelivery settles as a benign `already-merged` void); the server explicitly requests it with a `resync-required` void when an update's dependencies are missing from the room | Recovery re-proposes the doc's current state; if the lost send landed, the re-proposal merges as a no-op |
| History compaction | Server checkpoints every 100 intent rows and trims | Server checkpoints every 100 rows and trims — abandoned rooms stay bounded | Server checkpoints every 100 rows and trims (same retention invariant) |
| Genesis | Server, from post content | Server, from post content — deterministic build, so racing initializers merge idempotently | Server, from post content — deterministic, and ADOPTS an upstream DE-RTC sync-meta block if one is embedded (version lineage continues) |
| Capability enforcement | At ingest (kses lane; escalation for `unfiltered_html`-gated content parks for approval — restore by a privileged reviewer IS the approval) | At ingest, sanitize-and-compensate: blocks a filtered author's batch touched that kses would rewrite are REPLACED with their sanitized form and the compensating delta broadcasts (filter-on-save semantics; nothing parks — coarser than intent-log by design) | At ingest, per-block SEQUESTRATION (upstream's model): risky blocks revert to their base form and park for review while the safe remainder of the proposal merges and lands; markup-bearing property values park per property; restore under a privileged reviewer approves. Whole-proposal escalation remains the fallback (freeform boundaries, descriptor-carrying proposals) |
| Synced entity properties | The framework's full set as per-name registers: the scalar whitelist (title, excerpt, slug, status, comment_status, ping_status, format, sticky, author, featured_media, date, template), attached taxonomies (whole term-ID arrays by rest_base), and registered post meta (per-key `meta.<key>` registers, `_crdt_document` excluded). Collection rooms implement the framework's save-notification contract (per-client save registers), so a newly created term reaches every peer's term list by refetch | Whatever the sync config maps into the CRDT (the full framework set, including per-key post meta and taxonomies), and genesis seeds the same shared REST-shaped property map the other engines seed; collection rooms carry the savedAt state key for the same refetch contract | The full flattened register map rides every proposal beside the content (title, scalars, taxonomies, `meta.<key>`); the server three-way-merges per property against the base version — sole-writer changes and agreements apply, concurrent divergent writes park per property for review. Genesis seeds the shared property map |
| Presence/awareness | Yes (shared Yjs-free awareness doc) | Yes (Yjs awareness, relayed opaquely — the server does not decode it) | Yes (Yjs awareness over the doc bridge, relayed opaquely) |
| Server observability | Dispositions, debug envelope, benchmark quality metrics | Per-update dispositions, CRDT convergence oracle, materialization | Per-proposal dispositions (applied/escalated/voided with reasons), version lineage, materialization |
| Materialize to post_content | Yes (server-side) | Yes (server-side, from the canonical doc) | Trivially — the canonical document IS post content |
| Wire format | Small human-readable JSON intents | Opaque base64 binary (V2) + JSON snapshot rows | Human-readable JSON: whole-content proposals up, whole-content canonical rows down (bytes scale with document size) |

## Resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning — the cheapest of the three | Load + merge + re-encode the canonical y-php doc — the dominant cost of the three, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees) — cheap at benchmark sizes, scales with document size |
| Locking | Per-room MySQL `GET_LOCK` serializes ingest (5 s timeout; contenders get a retryable 503). One real lock round-trip pair inside every timed request — the engine benchmark's `calibration` block exists to subtract it | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | Same per-room `GET_LOCK` as intent-log — three-way merges are order-dependent |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows — but every accepted proposal stores a FULL content row, so row bytes scale with document size |
| Row contents | Small JSON intents + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Full-content JSON rows (content + version + attribution) + snapshot rows, plus canonical content and version snapshots in room meta |

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
pays is bytes, not cycles: whole documents travel in every proposal and
every accepted proposal stores a full content row, so its request and
storage bytes dwarf the other engines' and scale with document size.
All three engines' payload/storage bytes are REAL (each benchmark
profile speaks its engine's actual wire format), all three converge
with **zero lost work** on every scenario, and the escalation policies
differ visibly on the same contended workload: intent-log parks
escalations at per-register grain, de-rtc fewer but coarser
(whole-proposal, block-level grain), yjs-server none (silent CRDT
last-writer-wins).

The session-shaped scenarios add the time dimension single workloads
miss. Under `structural-churn` (concurrent block inserts/removals plus
typing) the conflict policies separate hardest: intent-log and
yjs-server merge everything cleanly while de-rtc escalates a large share
of proposals — whole-document proposals against a structurally-shifting
base are what its three-way merge refuses to auto-resolve; nothing is
lost on any engine. `remove-contention` isolates the edit-vs-remove
conflict class (one client types into an inserted block another client
concurrently removes): intent-log escalates the trailing edit (the
trailing keystrokes park as `target-deleted`; when the text lands first
both apply and the token vanishes with the removed block), yjs-server
escalates nothing (CRDT deletion dissolves the edit with the deleted
block; deterministic, never surfaced), and de-rtc escalates the most
(its whole-proposal grain sends the entire trailing proposal to review,
roughly one escalation per contended pair plus collateral from the
shifting base). `field-sync` (entity properties, taxonomy term sets,
post meta alongside typing) separates the same policies at field grain:
intent-log parks each later concurrent register writer
(`property-conflict`), de-rtc parks a conflicting property as its own
review row while the carrying proposal still applies, and yjs-server
resolves every register silently by CRDT last-writer-wins. Zero lost
work on all of these, verified by the engines' respective oracles.
Under a ten-minute three-user `editorial-session` (joins, typing
bursts, per-second polling, autosaves), intent-log's service time holds
flat, de-rtc's holds nearly flat while its room tail (and therefore the
next joiner's download) grows past a megabyte, and **yjs-server's
ingest grows with the accumulating document**. Run `editorial-session
rounds=3600` for the full hour before concluding about long sessions.

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
noise under intent-log), with one exception noted below.

## Known gaps — read before concluding

- **de-rtc storage/wire bytes scale with document size.** The benchmark
  profile (whole-content proposals + a disposition/version-lineage oracle)
  measures it directly: a multiple of intent-log's stored bytes over the
  same session even at a small document, growing linearly — and the same
  tail is what a later joiner downloads (several times the other
  engines' join payloads). Run `long-form` at YOUR document
  sizes before concluding. Deep-lag behavior is also distinct:
  a client that reads rarely escalates more (cumulative stale-base
  proposals conflict more often), and once its base ages out of the
  engine's 20-version snapshot window its proposals void as
  `unknown-base-version` and the client must retry against a fresher base
  (the benchmark models one retry per edit; nothing is lost either way).
- **de-rtc same-block concurrency is block-level last-writer-wins.** When
  truly concurrent edits hit the SAME block, the client's incorporation
  policy keeps its local block and re-proposes it (the yjs-server
  silent-register-LWW class, at coarser grain). Different-block
  concurrency merges losslessly via the server's three-way merge — but
  under concurrent STRUCTURE changes the whole-proposal grain bites
  hard: the benchmark's `structural-churn` scenario measures roughly
  half its proposals escalating (vs none for intent-log and yjs-server
  on the same workload), and `remove-contention` (edit-vs-remove on one
  block) measures the highest escalation share of the three (intent-log
  escalates only the trailing edit; yjs-server silently dissolves it).
  Nothing is lost, and every escalation now parks durably and
  presents in the review panel — but a workload that escalates half its
  proposals is still a workload asking humans to arbitrate constantly;
  prefer another engine for structure-churn-heavy sessions.
- **de-rtc clients do not author block-native update descriptors yet**
  (`clientUpdate: null`; the server's engine-unaware-writer lane derives
  operations). Porting the client-side descriptor builder and its
  cross-language fingerprint vectors would restore DE-RTC's
  proof-carrying proposals (tamper detection is active only for clients
  that send descriptors).

- **Intent-log same-paragraph typing can escalate instead of merging.**
  The echo race that corrupted canvas text is fixed: capture diffs the
  editor tree against the document state that tree reflects and authors at
  its seq, so a push racing live keystrokes merges instead of destroying
  (see "THE OBSERVED BASELINE" in `src/engines/intent-log-manager.ts`). The
  residual: while this editor is still behind on a peer's edit to the SAME
  paragraph, the later keystrokes of a burst escalate as `frame-conflict`
  rather than merging — parked in the review lane, never lost, and normal
  merging resumes once the editor observes the peer's change. AGENTS.md
  lists the rest of the residuals.
- **Intent-log undo arms after the settle round trip.** An undo unit
  becomes available once its rows and acks land (~a poll cycle after the
  burst quiets), unlike the yjs engines' instant local undo. Measured
  (fuzzer undo profile, 2026-08-17): undo dispatched within a second of
  typing was armed nearly every time on yjs-server/de-rtc and almost
  never on intent-log — inside the window it is a silent no-op. Two inverse
  derivations are best-effort: un-merging blocks restores only the joined
  field (the merge dropped the rest — editor semantics), and un-formatting
  need not restore pre-existing overlapping format spans exactly. An undo
  whose inverse intents are still unacked when that tab reloads loses
  them with the outbox — the undone (already-accepted) edit resurrects.
- **The websocket transport is experimental** (one-time auth token travels
  as a URL query parameter; plaintext `ws://` must never leave a dev box).
- **yjs-server under heavy write concurrency can ask a client to
  resync.** When concurrent lock-free ingests race, an update can
  settle as `voided: resync-required`; the client recovers by uploading
  its full state on its next submission (one extra round trip,
  idempotent server-side, nothing lost). Measured with `npm run bench
  -- concurrency=8`: most runs settle fully applied with zero voids,
  the occasional run a handful of `resync-required` voids that heal
  that way.
  The benchmark treats `resync-required` as benign (its profile models
  the recovery) and `invalid-payload` as REAL loss that fails the run,
  since the engine reserves it for genuinely malformed bytes. The
  per-room-lock engines (intent-log, de-rtc) showed zero voids under
  the same load, paying instead with measured lock queueing.
- **yjs-server ingest cost is real and scales with document size.** Every
  ingest decodes, merges, and re-encodes the canonical document in pure
  PHP — the most expensive per-ingest path of the three engines — and
  the SAVE path is worse: a save request starts with no per-request
  cache and decodes the whole canonical doc cold (`materialize_us` in
  the benchmark). One full-doc decode + two encodes per request is the
  structural floor for a server-authoritative CRDT in per-request PHP;
  it scales with document size, so run `long-form` benchmarks at YOUR
  sizes first.
- **yjs-server kses is sanitize, not park.** The per-update capability
  lane replaces a filtered author's offending blocks with their
  kses-sanitized form and broadcasts the compensation (WordPress's
  filter-on-save semantics at per-update grain) — the protected markup is
  gone, but no human reviews it, unlike intent-log's and de-rtc's parked
  `requires-approval` lanes.
- **yjs-server's genesis size gate is genesis-only.** Rooms refuse to
  initialize above `wp_sync_yjs_server_max_genesis_bytes` (default 1 MB;
  RTC never activates, writes 413). A room that GROWS past any threshold
  after genesis is unpoliced — per-room growth limits remain future work.
- **yjs-server has no review lane.** Register conflicts (two editors
  restyling the same block) resolve by deterministic CRDT last-writer-wins,
  silently — observable in dispositions, but not surfaced to humans. That
  is the central *policy* difference with intent-log, unchanged from the
  relay.
- **The hosting cost card's projections are not yet validated
  end-to-end.** Its per-user-hour numbers compose exactly-measured
  engine-seam costs, and the `concurrency=N` mode measures real lock
  waits — but no browser-driven multi-client soak (a three-window,
  hour-long run through the real transport stack, extending
  `tests/benchmarks/transport/` beyond two windows) has confirmed the
  composed totals against an end-to-end measurement. Until that soak
  exists, treat the cards as engine-seam floors, not full hosting
  bills.
- **yjs-server materialization mirrors intent-log's Phase 2a
  simplification**: rich-text content maps opaquely onto a block's single
  wrapper element (genesis wrappers kept server-side; per-type defaults
  for blocks born in-session). Complex sourced attributes beyond the
  content field don't round-trip through server materialization yet —
  the same class of gap intent-log carries.
