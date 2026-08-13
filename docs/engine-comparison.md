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
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge, but ON THE SERVER — outcomes observable, still no review lane | Three-way merge on the server; genuine conflicts ESCALATE as dispositions (`manual-conflict-required`) — but no review UI yet, so the client abandons the proposal and canonical wins locally |
| Collaborative undo | **Not yet** — WP's global undo applies (can undo a peer's work); designed fix is inverse intents | Per-peer undo manager (`src/engines/yjs/undo.ts`, inherited from the retired relay) | Per-peer undo manager (shared `src/engines/yjs/undo.ts` over the local doc bridge); undone state propagates as an ordinary proposal |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | Server holds the canonical doc; a rejoining client re-bootstraps from the retained snapshot + tail and uploads its own state idempotently | Server holds canonical content + version snapshots; a rejoining client re-bootstraps from the retained snapshot + content rows. Un-acked local edits re-propose (the server merges) |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update, IDEMPOTENT server-side (the server diffs out what it already has — redelivery settles as a benign `already-merged` void) | Recovery re-proposes the doc's current state; if the lost send landed, the re-proposal merges as a no-op |
| History compaction | Server checkpoints every 100 intent rows and trims | Server checkpoints every 100 rows and trims — abandoned rooms stay bounded | Server checkpoints every 100 rows and trims (same retention invariant) |
| Genesis | Server, from post content | Server, from post content — deterministic build, so racing initializers merge idempotently | Server, from post content — deterministic, and ADOPTS an upstream DE-RTC sync-meta block if one is embedded (version lineage continues) |
| Capability enforcement | At ingest (kses lane; escalation for `unfiltered_html`-gated content) | **Not yet** — the server CAN decode content (the prerequisite the retired relay structurally lacked), but the per-update kses lane is undesigned; see Known gaps | At ingest, coarse: a proposal kses would rewrite escalates whole (`requires-unfiltered-html`). Upstream DE-RTC sequesters just the risky blocks for review — that partial-safe lane is unported |
| Synced entity properties | Whitelist (currently the title) | Whatever the sync config maps into the CRDT | Content only (**no title sync yet**) |
| Presence/awareness | Yes (shared Yjs-free awareness doc) | Yes (Yjs awareness, relayed opaquely — the server does not decode it) | Yes (Yjs awareness over the doc bridge, relayed opaquely) |
| Server observability | Dispositions, debug envelope, benchmark quality metrics | Per-update dispositions, CRDT convergence oracle, materialization | Per-proposal dispositions (applied/escalated/voided with reasons), version lineage, materialization |
| Materialize to post_content | Yes (server-side) | Yes (server-side, from the canonical doc) | Trivially — the canonical document IS post content |
| Wire format | Small human-readable JSON intents | Opaque base64 binary (V2) + JSON snapshot rows | Human-readable JSON: whole-content proposals up, whole-content canonical rows down (bytes scale with document size) |

## Resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning | Load + merge + re-encode the canonical y-php doc — the dominant cost, tens of ms at benchmark document sizes, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees) — ~2 ms at benchmark sizes, scales with document size |
| Locking | Per-room MySQL `GET_LOCK` serializes ingest (5 s timeout; contenders get a retryable 503). One real lock round-trip pair inside every timed request — the engine benchmark's `calibration` block exists to subtract it | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | Same per-room `GET_LOCK` as intent-log — three-way merges are order-dependent |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows — but every accepted proposal stores a FULL content row, so row bytes scale with document size |
| Row contents | JSON intents (~200 B typical) + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Full-content JSON rows (content + version + attribution) + snapshot rows, plus canonical content and version snapshots in room meta |

Reference numbers from one dev machine (wp-env, Docker MariaDB, Aug 2026;
`mixed-newsroom`, 150 rounds, 4 clients, 8 paragraphs — regenerate
locally, and never compare across machines without the harnesses'
`environment` stanzas): intent-log service time ~0.7 ms mean per edit
including the lock pair; de-rtc ~2.2 ms mean including the same lock pair
(the content three-way merge — roughly 3× intent-log); yjs-server ~31 ms
p50 / ~35 ms mean (roughly 50× intent-log — pure y-php CPU: the canonical
document is decoded, merged, and re-encoded in PHP on every ingest). For
scale, the retired append-only relay sat at the timer floor (single-digit
µs — read it as "negligible"). Where de-rtc pays is bytes, not cycles:
whole documents travel in every proposal (~2.9 KB p50 requests vs
intent-log's ~220 B at this size) and every accepted proposal stores a
full content row (~817 KB stored vs intent-log's ~108 KB / yjs-server's
~61 KB over the same session) — both scale with document size. All three
engines' payload/storage bytes are REAL (each benchmark profile speaks its
engine's actual wire format), all three converge with **zero lost work**
on every scenario, and the escalation policies differ visibly: the same
contended workload settles as 114 review escalations under intent-log
(per-register grain), 18 under de-rtc (whole-proposal grain; identical
concurrent writes merge as agreement), and 0 under yjs-server (silent
CRDT last-writer-wins).

The session-shaped scenarios add the time dimension single workloads
miss. Under `structural-churn` (concurrent block inserts/removals plus
typing) the conflict policies separate hardest: intent-log and yjs-server
merge everything cleanly while de-rtc escalates ~50% of proposals —
whole-document proposals against a structurally-shifting base are what
its three-way merge refuses to auto-resolve; nothing is lost on any
engine. Under a ten-minute three-user `editorial-session` (joins, typing
bursts, per-second polling, autosaves), intent-log holds ~0.6 ms flat,
de-rtc holds ~2.9 ms but its room tail (and therefore the next joiner's
download) reaches ~1.2 MB, and **yjs-server's ingest degrades with the
growing document** — p50 ~65 ms but p90 ~203 ms by session end. Run
`editorial-session rounds=3600` for the full hour before concluding
about long sessions.

Two costs live off the edit path and are easy to miss. The **later-joiner
read** (a cold read at cursor 0 — what a fresh visitor downloads to enter
the room after the session above): ~105 KB under intent-log, ~60 KB under
yjs-server, **~813 KB under de-rtc** (the retained tail is full-content
rows). The **save path** (`materialize()` on a cold engine, as a real save
request runs it): ~2.7 ms under intent-log, near-zero under de-rtc (the
canonical IS post content), and **~164 ms under yjs-server** — the whole
canonical document is decoded from scratch; the ~35 ms ingest figure never
shows this because the engine instance keeps the decoded doc cached within
a request. Ingest peak memory is ~0.7–0.8 MB per request for all three at
this document size.

## Transports are a separate axis

Engines run over any transport. Measured with the transport benchmark
(same machine, 30 trials, under the retired yjs-relay engine — transport
latency is engine-independent, so the numbers stand; re-measure under
yjs-server to confirm on your hardware):

| | edit-to-visible p50 | idle traffic per collaborator |
| --- | --- | --- |
| http-polling | ~1.7 s | ~56 req/min |
| http-long-polling | ~0.5–0.65 s | ~94–98 req/min (held requests wake on awareness heartbeats), each holding a PHP worker up to its wait budget |
| websocket | ~30 ms | ~14 frames/30 s — plus a persistent daemon, TLS termination, and an exposed port |

Transport latency is engine-independent (the HTTP rows replicate within
noise under intent-log), with one exception noted below.

## Known gaps — read before concluding

- **de-rtc storage/wire bytes scale with document size.** The benchmark
  profile (whole-content proposals + a disposition/version-lineage oracle)
  measures it directly: ~8× intent-log's stored bytes over the same
  session at a small document, growing linearly — and the same tail is
  what a later joiner downloads (~813 KB to enter the benchmark room vs
  ~60–105 KB under the other engines). Run `long-form` at YOUR document
  sizes before concluding. Deep-lag behavior is also distinct:
  a client that reads rarely escalates more (cumulative stale-base
  proposals conflict more often), and once its base ages out of the
  engine's 20-version snapshot window its proposals void as
  `unknown-base-version` and the client must retry against a fresher base
  (the benchmark models one retry per edit; nothing is lost either way).
- **de-rtc has no review lane UI and no title sync.** Escalations
  (`manual-conflict-required`, `requires-unfiltered-html`) are observable
  dispositions, but nothing presents them to a human yet — the client
  abandons an escalated proposal once canonical applies. Proposals carry
  content only; title edits stay local.
- **de-rtc same-block concurrency is block-level last-writer-wins.** When
  truly concurrent edits hit the SAME block, the client's incorporation
  policy keeps its local block and re-proposes it (the yjs-server
  silent-register-LWW class, at coarser grain). Different-block
  concurrency merges losslessly via the server's three-way merge — but
  under concurrent STRUCTURE changes the whole-proposal grain bites
  hard: the benchmark's `structural-churn` scenario measures ~50% of
  proposals escalating (vs 0 for intent-log and yjs-server on the same
  workload). Nothing is lost, but every escalation is a human decision
  the review-lane UI (above) does not exist to present yet — the two
  gaps compound.
- **de-rtc clients do not author block-native update descriptors yet**
  (`clientUpdate: null`; the server's engine-unaware-writer lane derives
  operations). Porting the client-side descriptor builder and its
  cross-language fingerprint vectors would restore DE-RTC's
  proof-carrying proposals (tamper detection is active only for clients
  that send descriptors).

- **Intent-log echo race.** Editor pushes racing live keystrokes can
  corrupt canvas text; it is rare over the HTTP transports' batched cadence
  and severe over websocket's per-keystroke cadence — benchmark the
  websocket transport under yjs-server until it is fixed. The fix is a
  session/bridge redesign (capture against the editor's last-observed
  document state); see the `KNOWN LIMITATION` comment in
  `src/engines/intent-log-manager.ts` and the AGENTS.md known issue.
- **Intent-log has no collaborative undo yet** — for many editorial teams
  this is the biggest day-to-day parity gap.
- **The websocket transport is experimental** (one-time auth token travels
  as a URL query parameter; plaintext `ws://` must never leave a dev box).
- **yjs-server rejects most updates under REAL write concurrency (found
  by `npm run bench -- concurrency=4`).** With four worker processes
  writing to one room through the real postmeta storage, 85 of 120
  updates settled as `voided: invalid-payload` (zero voids with one
  worker). The signature fits the lock-free design's save race: two
  ingests load canonical vN, the last save wins, the loser's merged
  items are absent from canonical, and that client's NEXT update
  references now-missing items — the engine catches the apply failure
  and voids it. The advertised update-log repair does not appear to
  close the race under sustained concurrency, and since the client's
  content never lands, this is potential REAL work loss (the
  single-process harness classifies `invalid-payload` as benign — an
  assumption this measurement invalidates for concurrent writers).
  Needs engine-side investigation before any production claim of
  lock-free superiority; the per-room-lock engines (intent-log, de-rtc)
  showed zero errors and zero voids under the same load, paying instead
  with measured queueing (+1.9 ms and +10.5 ms p50 respectively at 4
  writers).
- **y-php crashes applying certain post-removal diffs (found by
  `npm run bench -- certify=…`).** Under concurrent block removals
  (`structural-churn`), the vendored y-php throws `Unexpected case`
  (`getMissing` → `getItem`, preceded by an
  `Undefined property: ContentDeleted::$type` warning in `Item.php`) when
  a simulated y-php CLIENT applies the server's diff rows — on roughly
  half of tested seeds (43/44/46 crash, 42/45/47 pass; repro:
  `wp eval-file tests/benchmarks/benchmark.php engine=yjs-server
  scenario=structural-churn rounds=40 clients=3 seed=43`). Production
  browsers run JS Yjs (which guards this case), so the demonstrated
  crash is harness-client-side — but the ENGINE also replays stored rows
  through the same y-php apply path when rebuilding canonical state, so
  server-side exposure needs ruling out upstream before production use.
  The library is vendored/frozen (byte-parity contract with JS Yjs);
  the fix belongs upstream in y-php's `Item::getMissing`.
- **The websocket transport drops the client's engine stamps.** The
  daemon's room-request validation
  (`WP_WebSocket_Sync_Server::validate_room_request()`) normalizes away
  the `engine`/`engine_protocol` fields the HTTP transports forward to the
  engine layer. Two consequences: there is no stale-tab engine fence over
  websocket, and the switched-engine collection-room healing
  (`reset_switched_room`, HTTP polling only) can never trigger — a global
  collection/taxonomy room with stale engine lineage 409s
  (`rest_sync_engine_mismatch`) forever over websocket while an HTTP
  client would heal it. Since comparing engines means switching them,
  flip engines over an HTTP transport first (letting it heal the global
  rooms) before benchmarking websocket.
- **yjs-server ingest cost is real and scales with document size.** Every
  ingest decodes, merges, and re-encodes the canonical document in pure
  PHP (~30 ms at benchmark sizes vs intent-log's ~0.6 ms) — and the SAVE
  path is worse: a save request starts with no per-request cache and
  decodes the whole canonical doc cold (~164 ms measured after a
  600-edit session; `materialize_us` in the benchmark). Before
  production use this needs either an incremental canonical-maintenance
  strategy, y-php optimization, or acceptance of the latency at target
  document sizes — run `long-form` benchmarks at YOUR sizes first.
- **yjs-server has no kses/capability lane yet.** The prerequisite the
  retired relay structurally lacked is now present (the server can decode
  and inspect CRDT content), but per-update capability enforcement is not
  designed or built. Until it is, content security is enforced at save
  only.
- **yjs-server has no document-size gate for later joiners.** The
  framework's size guard fences a client whose OWN outgoing update exceeds
  the limit (that still works — the author's tab drops out of
  collaboration). Under the retired relay every visitor re-authored the
  whole document at init, so later joiners tripped the same guard and fell
  back to the post lock; under yjs-server the server owns genesis and
  clients only send small diffs, so an oversized document never fences a
  joiner — a server-side genesis size policy is undesigned.
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
