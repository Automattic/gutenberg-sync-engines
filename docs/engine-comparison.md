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

- **Merging on the server** costs server CPU (and today, for two of the
  three engines, a per-room ingest lock) and in exchange the server can
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
| P3 conflicts surfaced | **Meets.** Per-register review lane; residual over-escalation on same-paragraph bursts (rate, not silence) | **Violates, currently by design.** Register conflicts resolve by silent CRDT last-writer-wins; no review lane (TODO-7) | **Partially violates — port artifact.** Server-detected conflicts park for review, but same-block concurrency silently LWWs client-side (TODO-2) and conflict grain is the whole proposal (TODO-3) |
| P4 machine writers | **Not yet.** Ingest speaks typed intents only; the persisted `metadata.syncId` identity makes a diff lane tractable (TODO-4) | **Accepted limitation.** Ingest speaks binary CRDT updates; a diff-to-CRDT lane would be semantically worse, not just costly (TODO-4) | **Nearest, unported.** The protocol unit (base version + whole content) IS the `wp_update_post` shape and the server already derives operations for descriptor-less writers; the save-path preflight itself is unported (TODO-4) |
| P5 cheap hosting | **Partially.** Cheapest per-ingest CPU; but `GET_LOCK` assumes single-primary topology (TODO-1) | **Partially.** No lock (good); heaviest per-ingest CPU, scaling with document size | **Partially.** Cheap CPU; `GET_LOCK` (TODO-1); wire/storage bytes scale with document size |
| P6 measured economics | **Meets.** Real wire format in its benchmark profile | **Meets.** Real wire format; convergence oracle | **Meets.** Real wire format; disposition/lineage oracle |
| P7 intent & identity | **Meets.** Typed intents end-to-end; syncIds persist in saved `post_content` and round-trip genesis | **Fails.** Snapshot-diff binding inherited from the relay; no semantic ops, no stable identity in the merge | **Designed for it, half-wired.** Block identity + rich-text ops live in the merge core, but clients send `clientUpdate: null`, so intent is server-derived from whole-content diffs (TODO-2) |

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
  and per-block partial acceptance. Restoring fidelity is TODO-2 and
  TODO-3.

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
| Materialize to post_content | Yes (server-side; block identity persists as `metadata.syncId` and round-trips genesis) | Yes (server-side, from the canonical doc) | Trivially — the canonical document IS post content |
| Wire format | Small human-readable JSON intents | Opaque base64 binary (V2) + JSON snapshot rows | Human-readable JSON: whole-content proposals up, whole-content canonical rows down (bytes scale with document size) |

## Resource profile

| Concern | intent-log | yjs-server | de-rtc |
| --- | --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning — the cheapest of the three | Load + merge + re-encode the canonical y-php doc — the dominant cost of the three, scales with document size | Parse + three-way merge of three content strings (pure PHP over `parse_blocks` trees) — cheap at benchmark sizes, scales with document size |
| Locking | Per-room MySQL `GET_LOCK` serializes ingest (5 s timeout; contenders get a retryable 503). One real lock round-trip pair inside every timed request — the engine benchmark's `calibration` block exists to subtract it. Topology-fragile: see TODO-1 | None — CRDT merge needs no total order; the update log is the source of truth and a lost canonical-save race is repaired from it on the next load | Same per-room `GET_LOCK` as intent-log (three-way merges are order-dependent) — an addition of OUR port; the save-centric original used optimistic base-version validation instead. See TODO-1 |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap (the canonical doc is never touched on the read path) | Cheap (rows after cursor; canonical untouched) |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded: server checkpoint + trim every 100 rows, no client needed | Bounded: server checkpoint + trim every 100 rows — but every accepted proposal stores a FULL content row, so row bytes scale with document size |
| Row contents | Small JSON intents + periodic full-document checkpoint rows | Base64 V2 diffs (server strips what it already had) + full-state snapshot rows, plus the canonical doc in room meta | Full-content JSON rows (content + version + attribution) + snapshot rows, plus canonical content and version snapshots in room meta |

In plain terms, the locking row is a pro/con pair. intent-log and
de-rtc: one edit merges at a time per post — concurrent editors wait
briefly, and under heavy load a request may be told to retry; in
exchange, nothing ever needs to resync. yjs-server: nobody ever waits —
in exchange, every concurrent request redundantly pays the full merge
cost, and a client is occasionally asked to re-upload its state (one
extra round trip, nothing lost). Same contention, different currency:
latency queueing versus duplicate CPU plus occasional resync.

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
storage bytes dwarf the other engines' and scale with document size.
All three engines' payload/storage bytes are REAL (each benchmark
profile speaks its engine's actual wire format), all three converge
with **zero lost work** on every scenario, and the escalation policies
differ visibly on the same contended workload: intent-log parks
escalations at per-register grain, de-rtc fewer but coarser
(whole-proposal grain), yjs-server none (silent CRDT last-writer-wins).

The session-shaped scenarios add the time dimension single workloads
miss. Under `structural-churn` (concurrent block inserts/removals plus
typing) the conflict policies separate hardest: intent-log and
yjs-server merge everything cleanly while de-rtc escalates a large share
of proposals — whole-document proposals against a structurally-shifting
base are what its three-way merge refuses to auto-resolve; nothing is
lost on any engine. `remove-contention` isolates the edit-vs-remove
conflict class; `field-sync` separates the same policies at field grain
(see the scenario narratives below for what actually happens on the
wire). Under a ten-minute three-user `editorial-session` (joins, typing
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
  content against the version it last incorporated. The server locks,
  three-way-merges (a fast-forward solo), broadcasts a full canonical
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
  so it *incorporates*: adopts canonical blocks it hasn't touched,
  keeps its own version of the contested block, and advances its base.
  Its next proposal reads as a clean sole-writer change — the server
  never sees a conflict, and the peer's text is overwritten. Block-level
  last-writer-wins, silently (P3 violation — a port artifact, not the
  upstream design; TODO-2). Only when both proposals are in flight from
  the same stale base with overlapping ranges does the server's merge
  refuse (`de_rtc_rebase_failed`) and park — and then it parks the
  WHOLE proposal, clean blocks included (TODO-3).

### D. Edit versus remove (one client types into a block another removes)

intent-log escalates the trailing side: if the removal lands first, the
trailing keystrokes park as `target-deleted`; if the text lands first,
both apply and the token vanishes with the removed block. yjs-server
escalates nothing — CRDT deletion dissolves the edit with the deleted
block, deterministically and invisibly. de-rtc escalates the most: the
structural change shifts the base under the whole-content proposal, and
its whole-proposal grain sends the entire trailing proposal to review
(roughly one escalation per contended pair, plus collateral). The
benchmark's `remove-contention` scenario measures exactly this spread.

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
  boundaries and descriptor-carrying proposals.

### F. An out-of-band machine write lands mid-session (P4 — honest: unsolved)

A scheduled integration fetched the post before the session and writes
back its modified copy while two editors are collaborating. **Today, on
all three engines, the room never learns about that write**: no engine
hooks the save path, the room's canonical state diverges from
`post_content`, and the session's next materializing save clobbers the
integration's work — exactly the content-loss scenario the problem
statement names. Nothing in the current plugin passes this scenario;
TODO-4 is the plan, and the engines start from very different distances:
de-rtc's ingest unit already *is* what the integration produces (whole
content + a base), intent-log has the identity substrate to diff
against (persisted syncIds), and yjs-server would need to invent
semantic operations no writer expressed.

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
  more). Beyond the window the proposal voids as
  `unknown-base-version` and the client must retry against a fresher
  base: fetch canonical, rebase, re-propose. The benchmark models one
  retry per edit; nothing is lost either way.

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
noise under intent-log).

## Current state versus desired state

The desired state is the principles, fully honored, by whichever engine
wins the bake-off. The current state is close enough to compare engines
honestly and far enough that pretending otherwise would corrupt the
comparison. The enumerated TODOs below are the delta. TODO-1 through
TODO-4 are the ones that change engine *verdicts*; the rest change
polish and confidence.

- **TODO-1 — Implement room locking the way WordPress Core would.**
  intent-log and de-rtc serialize ingest with per-room MySQL `GET_LOCK`.
  Core never uses `GET_LOCK` — Core locks with atomic option writes and
  TTLs (the upgrader/cron pattern) precisely to stay topology-agnostic —
  and `GET_LOCK` quietly loses its meaning under connection
  pooling/multiplexing, under read/write-splitting drop-ins (a
  `SELECT GET_LOCK(...)` pattern-matches as a read and can land on a
  replica), on multi-primary clusters (user locks are node-local), and
  on SQLite builds (the function does not exist). Replace with a
  Core-style advisory lock (atomic CAS + TTL + retryable contention
  response), or with the upstream DE-RTC approach for that engine
  (optimistic base-version validation + retry). Note the original DE-RTC
  has NO lock — the `GET_LOCK` is this port's addition. Principle: P5.
- **TODO-2 — Port the DE-RTC client descriptor lane (`clientUpdate`) —
  timeboxed.** Restores Dennis's intended semantics for same-block
  concurrency and retires the silent client-side keep-local LWW
  (scenario C). The server side already ships: the block-native
  retry-save path validates descriptors, transforms rich-text splices,
  merges non-overlapping same-block edits, and escalates true overlaps.
  Unported: the client-side descriptor builder, its cross-language
  fingerprint vectors, and a per-block-base rework of the incorporation
  policy. **Timebox the initial investigation and bail if the solution
  spirals in complexity** — the fallback is the cheap policy swap (park
  the losing block text for review instead of keeping it), which honors
  P3 at the cost of review noise. Principles: P3, P7.
- **TODO-3 — Per-block parking for `manual-conflict-required`.** Extend
  the sequestration pattern (already per-block for the kses lane, per
  upstream's partial-acceptance model) to merge conflicts: align the
  base/current/proposed block records, land the clean remainder, park
  only the conflicted blocks. Whole-proposal parking remains the
  fallback for structural conflicts, where block alignment itself is
  what broke. Engine-layer orchestration; the frozen merge core stays
  untouched. Principle: P3.
- **TODO-4 — Machine-writer participation (scenario F).** Per engine:
  (a) **de-rtc**: port the upstream `wp_update_post` base-version
  preflight so REST/CLI/plugin writes become ordinary proposals — the
  engine's ingest already accepts descriptor-less whole-content writers;
  (b) **intent-log**: two layered lanes — an intents API for
  intent-aware machines (agents, plugins) that can state what they
  mean, and a save-path diff-to-intents lane keyed by the block
  identity `materialize()` already persists (`metadata.syncId`), which
  doubles as DE-RTC-style self-healing when an unaware plugin mangles a
  collaborative post; (c) **yjs-server**: accept and document the
  limitation — a diff-to-CRDT lane is mechanically feasible but
  semantically wrong (inferred character operations no writer
  expressed). Principles: P4, P2.
- **TODO-5 — Close the intent-log undo arming gap.** Undo pressed
  within the settle window (capture delay + ack round trip) is a silent
  no-op; the yjs engines arm instantly. Closing it means canceling
  pending outbox intents — inverses must keep deriving from ACCEPTED
  rows (outbox originals carry offsets the transforms never updated).
  Also in scope: an undo whose inverse intents are unacked at tab
  reload loses them with the outbox, resurrecting the undone edit.
- **TODO-6 — Promote escalation rate to an acceptance criterion.** The
  benchmark already prints per-scenario escalation shares; build the
  conflict-fixture suite around them and set thresholds. "Too high"
  fails an engine as surely as lost work does — this is the P3
  fine line made measurable.
- **TODO-7 — Decide yjs-server's conflict story.** Either build
  conflict detection plus a review lane (detection is the undesigned
  prerequisite), or formally accept silent register-LWW as that
  engine's documented policy and say so wherever the engine is
  offered. The current "by design" label defers the decision; P3 says
  we owe one.
- **TODO-8 — Police post-genesis room growth in yjs-server.** The
  genesis size gate refuses to *initialize* oversized rooms, but a room
  that grows past any threshold after genesis is unpoliced. The parked
  three-tier design (observability, terminal ceiling, epoch compaction)
  applies; do the compaction tier together with incremental canonical
  maintenance.
- **TODO-9 — Harden the websocket transport.** The one-time auth token
  travels as a URL query parameter, and plaintext `ws://` must never
  leave a dev box. Experimental until fixed.
- **TODO-10 — Validate the hosting cost cards end-to-end.** The
  per-user-hour projections compose exactly-measured engine-seam costs,
  but no browser-driven multi-client soak (three windows, an hour,
  through the real transport stack) has confirmed the composed totals.
  Until then, treat the cards as engine-seam floors, not hosting bills.
- **TODO-11 — Round-trip complex sourced attributes through
  materialization.** intent-log and yjs-server share the Phase-2a
  simplification: rich-text content maps onto a block's single wrapper
  element (genesis wrappers kept server-side; per-type defaults for
  blocks born in-session). Sourced attributes beyond the content field
  don't survive server materialization yet.

## Known gaps and qualifications

Residual facts that color conclusions but don't rise to TODOs of their
own:

- **de-rtc storage/wire bytes scale with document size.** A multiple of
  intent-log's stored bytes over the same session even at a small
  document, growing linearly — and the same tail is what a later joiner
  downloads (several times the other engines' join payloads). Run
  `long-form` at YOUR document sizes before concluding. Deep-lag
  behavior is distinct: rarely-reading clients escalate more, and past
  the 20-version snapshot window their proposals void and retry
  (scenario G).
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
  upload. The per-room-lock engines showed zero voids under the same
  load, paying instead with measured lock queueing. The benchmark
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
- **yjs-server's genesis size gate is genesis-only** (default 1 MB via
  `wp_sync_yjs_server_max_genesis_bytes`; RTC never activates above it,
  writes 413). Post-genesis growth is TODO-8.
- **de-rtc clients do not author block-native update descriptors yet**
  (`clientUpdate: null`; the server's engine-unaware-writer lane derives
  operations). Tamper detection is active only for descriptor-carrying
  clients. This is the client half of TODO-2.
- **Genesis blocks must set `isValid: true`** or the editor renders
  them as invalid-content recovery blocks (has bitten).
