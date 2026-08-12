# Choosing a sync engine (and transport)

This plugin exists to make the engine/transport decision a matter of
evidence. This guide is the interpretation layer for that evidence: what
each engine actually is, where the numbers come from, which differences are
performance and which are policy, and the known gaps that should color any
conclusion. Read it alongside the two benchmark harnesses
(`tests/benchmarks/README.md` and `tests/benchmarks/transport/README.md`).

## The engines in one sentence each

- **intent-log** — the server owns the document: every edit is a typed
  intent the server transforms against the log, so it can say exactly how
  every edit settled — and park genuine conflicts for human review instead
  of auto-merging them.
- **yjs-server** — the server owns the document AND it is a CRDT: the
  vendored y-php library merges every update into a canonical room
  document server-side, the server compacts by itself and materializes
  post content, while clients keep the CRDT machinery inherited from the
  retired relay engine (same wire documents, same undo).
- **de-rtc** — the server owns the document and clients never merge:
  each client proposes its WHOLE content against the version it last
  incorporated, and the server three-way-merges every proposal with the
  Distributed Editing merge core ported verbatim from wordpress-develop
  (serialized-block + rich-text + block-identity merges); most edits
  merge cleanly, and genuine conflicts ESCALATE for a human decision
  instead of silently merging.

**Retired: yjs-relay** — the incumbent design this plugin started from: the
clients owned the document, and the server stored and forwarded opaque CRDT
updates it could not inspect. It was removed once yjs-server matched its
client-side behavior with server-side authority; its shared client modules
live on in `src/engines/yjs/` (constants, doc schema, snapshot, undo), and
yjs-server's wire documents remain byte-compatible with rooms it wrote.
Where the relay appears below it is as historical context.

## One architectural choice drives everything

Where the merge happens decides almost every other property — and
yjs-server deliberately sits in the middle: server-side authority over a
CRDT merge. It gains the relay's lock-free ingest and lossless text
merging plus the intent log's observability, bounded storage, and
materialization; it does NOT gain the intent log's conflict *policy*
(register conflicts still resolve silently, last-writer-wins) and it pays
the cost of rebuilding the canonical document in PHP on every ingest.

- **Merging on the server (intent-log)** costs server CPU and a per-room
  ingest lock, and in exchange the server can *observe* outcomes: per-edit
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

## Host-facing resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning | Load + merge + re-encode the canonical y-php doc — the dominant cost, tens of ms at benchmark document sizes, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees); unmeasured — no benchmark profile yet |
| Locking | Per-room MySQL `GET_LOCK` serializes ingest (5 s timeout; contenders get a retryable 503). One real lock round-trip pair inside every timed request — the engine benchmark's `calibration` block exists to subtract it | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | Same per-room `GET_LOCK` as intent-log — three-way merges are order-dependent |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows — but every accepted proposal stores a FULL content row, so row bytes scale with document size |
| Row contents | JSON intents (~200 B typical) + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Full-content JSON rows (content + version + attribution) + snapshot rows, plus canonical content and version snapshots in room meta |

Reference numbers from one dev machine (wp-env, Docker MariaDB, Aug 2026 —
regenerate locally, and never compare across machines without the
harnesses' `environment` stanzas): intent-log service time ~0.6 ms p50 per
edit including the lock pair; yjs-server ~29 ms p50 / ~33 ms mean per edit
(roughly 50× intent-log — pure y-php CPU: the canonical document is
decoded, merged, and re-encoded in PHP on every ingest). For scale, the
retired append-only relay sat at the timer floor (single-digit µs — read
it as "negligible"). yjs-server payload/storage bytes are REAL (its
benchmark profile authors genuine Yjs updates through y-php).

## Transports are a separate axis

Both engines run over any transport. Measured with the transport benchmark
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

- **de-rtc has no benchmark authoring profile yet.** The engine benchmark
  harness cannot drive it; the resource-profile column above is
  architectural, not measured. Building the profile (and a convergence
  oracle over version lineage) is the first step before performance
  claims.
- **de-rtc has no review lane UI and no title sync.** Escalations
  (`manual-conflict-required`, `requires-unfiltered-html`) are observable
  dispositions, but nothing presents them to a human yet — the client
  abandons an escalated proposal once canonical applies. Proposals carry
  content only; title edits stay local.
- **de-rtc same-block concurrency is block-level last-writer-wins.** When
  truly concurrent edits hit the SAME block, the client's incorporation
  policy keeps its local block and re-proposes it (the yjs-server
  silent-register-LWW class, at coarser grain). Different-block
  concurrency merges losslessly via the server's three-way merge.
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
- **yjs-server ingest cost is real and scales with document size.** Every
  ingest decodes, merges, and re-encodes the canonical document in pure
  PHP (~30 ms at benchmark sizes vs intent-log's ~0.6 ms). Before
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
- **yjs-server materialization mirrors intent-log's Phase 2a
  simplification**: rich-text content maps opaquely onto a block's single
  wrapper element (genesis wrappers kept server-side; per-type defaults
  for blocks born in-session). Complex sourced attributes beyond the
  content field don't round-trip through server materialization yet —
  the same class of gap intent-log carries.

## Bottom line

- If the priority is **accountable merges** — no silently lost or
  silently merged work, conflicts reviewable by humans, quality metrics a
  host can actually monitor — intent-log buys that for roughly half a
  millisecond of serialized server work per edit, with collaborative undo
  and the echo-race fix as the open engineering items.
- If the priority is **CRDT merge semantics with server authority** —
  lossless text merging and per-peer undo like the retired relay, but with
  server-side genesis, bounded storage without client help, observable
  convergence, materialization, and lock-free ingest — yjs-server now
  provides it, at a real price: tens of milliseconds of y-php CPU per
  edit at benchmark document sizes, no review lane, and the kses lane
  still to build. It is the measurement platform for deciding whether
  server-side Yjs can be made cheap enough, not yet a production default.
- If the priority is **WordPress-native merge semantics with escalation
  over silent conflict** — proposals in plain serialized-block JSON, a
  server that merges the way upstream Distributed Editing's save path
  does, version lineage per room, and conflicts that refuse to
  auto-merge — de-rtc now provides the engine core, with the review UI,
  title sync, client-side proof descriptors, and a benchmark profile as
  the open items. It is the migration path for evaluating Distributed
  Editing inside the pluggable framework, not yet a production default.
- Transport choice is independent and mostly a hosting decision: polling
  works everywhere at ~1.7 s perceived latency, long-polling roughly
  triples responsiveness for the price of held PHP workers, and websocket
  is effectively instant for the price of running a daemon.
