# Sync-engine benchmark harness

Compares server sync engines **through the production seam** — the same
`WP_Sync_Engine::handle_updates()` / `get_updates_since()` calls the polling
transport makes — so the numbers are the real engine's, not a model's. It
exists to make the architecture decision (which engine, or keep both) a
matter of evidence.

Engines are resolved through the framework's registry (the
`wp_sync_engines` filter), so **any engine registered by an active plugin is
benchmarkable by slug** — run with an unknown slug to list what's
registered. This plugin registers three:

- **`intent-log`** (`WP_Intent_Log_Engine`) — server-authoritative: the
  server transforms each edit against the log, so it can report exactly how
  every edit settled.
- **`yjs-server`** (`WP_Yjs_Server_Engine`) — server-authoritative CRDT:
  the server (via the vendored y-php) merges every update into a canonical
  room document, compacts by itself, and materializes post content.
- **`de-rtc`** (`WP_De_RTC_Engine`) — server-governed three-way merges:
  clients propose whole content against a named base version; the server
  merges each proposal with the ported DE-RTC merge core and broadcasts
  canonical content rows; genuine conflicts escalate.

(A fourth engine, `yjs-relay` — a dumb relay whose merge happened in each
client's CRDT — has been removed; historical numbers for it remain below
as context.)

HOW the runner speaks to an engine is an **authoring profile** — a
first-class SPI (`WP_Sync_Bench_Authoring_Profile`) that owns everything
engine-specific: translating the workload's abstract edits into the
engine's wire vocabulary, playing the client's part between requests
(applying read responses, tracking observed state, answering compaction
nominations), classifying void reasons, and scoring quality with an oracle
matched to the engine's merge semantics. The measurement loop
(`WP_Sync_Bench_Runner`) is engine-neutral: it times whatever requests the
profile hands it. Profiles are resolved by engine slug through
`WP_Sync_Bench_Profiles`; an engine plugin can register its own via the
`wp_sync_bench_authoring_profiles` filter (mapping slug to a class
constructed as `new $class( int $post_id, array $workload )`).

This plugin ships three dedicated profiles. The **intent-log profile**
speaks typed intents authored from each client's observed base and scores
quality with the disposition-based oracle. The **yjs-server profile**
speaks **real Yjs**: each simulated client holds a y-php document, authors
genuine incremental V2 updates (text inserts into the paragraph's content
Y.Text; align set on the attributes Y.Map — exactly what the editor's
session codec sends), and applies read responses into its document;
payload and storage bytes are therefore REAL for this engine, and quality
is scored with a CRDT oracle (see below). The **de-rtc profile** speaks
whole-content proposals: each simulated client keeps a local working copy
and its base version (base = last version applied to the doc, the client
adapter's rule), adopts the server's canonical content rows on read, and —
because retry is part of that protocol — re-proposes edits the engine
voided at an aged-out base as a coalesced follow-up proposal against the
base it just observed (one retry per edit); payload and storage bytes are
REAL and scale with document size, and quality is scored with a
disposition + version-lineage oracle (see below). Every other engine gets
the **opaque-relay fallback profile** — relay-convention
`update`/`compaction` blobs with quality reported as not
server-observable. A third-party relay-style engine benchmarks
meaningfully out of the box; an engine with its own wire vocabulary will
void the generic updates, and the dispositions/storage counts will show
that rather than fake a result — its plugin should register a real profile
instead.

## What it measures

**Cost** (all engines):

- `service_us` — per-request service time of `handle_updates` (p50/p90/p99/
  max/mean, reported in ms), measured with `hrtime()` and pooled across
  measured repetitions (warmup reps are excluded). Storage is swapped for an
  in-memory implementation, so this mostly isolates *engine* CPU (the
  intent-log planner and replay; the relay's append) from database I/O —
  with one deliberate exception: the intent-log and de-rtc ingests hold a
  per-room MySQL `GET_LOCK` for the length of each request (their merges
  are order-dependent), so each of their samples includes one lock/release
  pair of real DB round-trips (yjs-server and the relay pay none). The
  reported `calibration` block times that lock pair and a bare `SELECT 1`
  in the same environment so the number can be decomposed.
- `read_us` / `idle_poll_us` — per-request time of `get_updates_since`,
  split into catch-up reads (during and after the session) and idle polls
  (nothing new to deliver). Idle polls dominate request volume in a live
  deployment, so their cost is reported on its own.
- `join_us` — the later-joiner read: a COLD `get_updates_since` at cursor 0
  by a client that was never in the session, after all the session's
  history — what a fresh visitor pays to enter the room (snapshot + tail,
  per the engine's retention). Its response size is reported as
  `payload_bytes.join_response_*`: the payload that visitor downloads,
  which differs sharply per engine (full-content rows vs binary diffs).
- `materialize_us` — the save path: `materialize()` timed on a FRESH
  engine instance per sample (a save request starts with no per-request
  room cache). Engines without the materialize convention report null.
- `memory.ingest_peak_bytes` / `memory.materialize_peak_bytes` — peak PHP
  memory allocated on top of the baseline during the worst ingest and the
  cold materialize (via `memory_reset_peak_usage()`, PHP 8.2+; null
  otherwise) — the number a constrained PHP-FPM pool actually OOMs on.
- `payload_bytes` — request and response sizes of the engine-level updates
  payload (the transport envelope and awareness add overhead on top).
- `storage.rows` / `storage.bytes` — how the room grows. This is measured
  exactly even though storage is in-memory, because growth is a real
  differentiator — but of a different KIND per engine: the intent log
  checkpoints and trims on the server; the relay relies on a client to
  compact (the engine nominates the lowest-id session member once the room
  passes 50 rows). The runner plays that compactor's part — it submits a
  synthetic full-state snapshot whenever a read answers `should_compact` —
  so relay growth reflects a session with live clients, not an abandoned
  room. `storage.followups` counts protocol follow-up ingests generally
  (the relay's compaction snapshots; de-rtc's stale-base retry proposals);
  their requests and dispositions are included in cost and quality.
  `storage.trims` counts history-trim events — every engine's checkpoint
  path trims once per checkpoint (as does an accepted relay compaction),
  so the server's checkpoint cadence is visible per engine instead of
  hiding as unlabeled spikes in `service_us` p99.

**Quality** — policy-correct, and only where the server can observe it:

- `dispositions` — for the intent log, the count of edits that were
  `applied` (merged), `escalated` (set aside for human review), or `voided`.
- `escalation_rate` — escalated / total. This is **reported, not
  penalized**: sending a genuine conflict to review is the point, not a
  failure.
- `lost_work` — edits that were dropped without being applied or preserved
  for review (a `voided` with a non-benign reason; each authoring profile
  classifies which void reasons are benign for ITS engine). The project's
  policy is *never lose work*; this asserts it. It is `0` in every scenario
  here.
- `converged` — the materialized document matches the engine's own account
  of the session: every `applied` edit's unique token appears in the content
  exactly once, no `escalated` edit's token leaked into the content, the
  block structure is intact, and each block's final attribute value is the
  last applied write in server order. Failures are itemized in
  `quality.convergence_failures`.

For `yjs-server`, quality is scored with a **CRDT oracle** matched to CRDT
semantics: after full catch-up every simulated client's document must be
identical (the convergence guarantee), every applied text token must appear
in the server-materialized content exactly once (text merges are lossless —
nothing lost), the block structure must be intact, and the materialized
attribute registers must equal the converged CRDT value. Attribute
conflicts resolve by CRDT rules (deterministic, but NOT server arrival
order) rather than escalating, so `escalated` is always 0 for this engine —
that is the policy difference with intent-log, reported honestly: the same
contended workload that intent-log sends to review, yjs-server silently
last-writer-wins.

For `de-rtc`, quality is scored with a **disposition + lineage oracle**:
applied tokens appear in the canonical exactly once, escalated (parked)
tokens not at all, structure intact, each align register equal to the last
applied write that actually CHANGED it against its own base (three-way
merges preserve untouched registers, so a no-op write must not move the
expectation), the broadcast `content` rows chain v(N)→v(N+1) with no gaps
and match the applied dispositions exactly, and after full catch-up every
client's adopted copy equals the materialized canonical. Two accounting
notes: de-rtc escalates the WHOLE proposal (its escalation grain is a
proposal, not a single register write — rates are not directly comparable
with intent-log's per-intent grain), and `voided`/`unknown-base-version`
is NOT lost work — it is the protocol asking the client to retry against
a fresher base, which the profile models (the retry's settlement is what
counts).

For an engine that merges on the client (the retired `yjs-relay` did, and
the opaque-relay fallback profile models one), quality is reported as
**not server-observable**: there is no PHP CRDT in the loop to score
convergence or conflict outcome, so the harness says so rather than
inventing a number.

### Why not a "merge retention" score

An earlier harness scored quality as *silent-merge retention*: how much
concurrently-typed content survived an automatic server merge. That rewards
last-write-wins — precisely the behaviour this project rejects, because it
silently discards one editor's work. Under that metric a lossy engine that
quietly overwrites can outscore one that surfaces the conflict. This harness
inverts it: the signal is **nothing lost**, with conflicts *surfaced for
review* (an outcome, not a demerit).

## Scenarios

| Slug                  | Shape                                                        |
| --------------------- | ----------------------------------------------------------- |
| `solo-typing`         | One editor, one document. Baseline cost, no contention.     |
| `long-form`           | One editor, ~600 chars per paragraph (~5 KB at defaults). Does cost scale with document size? |
| `parallel-paragraphs` | N editors, each in their own paragraph. Clean concurrency.  |
| `contended-paragraph` | N editors restyling the SAME block. High escalation.        |
| `mixed-newsroom`      | Mostly parallel, ~25% of rounds collide on one block.       |
| `laggy-newsroom`      | Mixed newsroom, but the last client reads only every 10th round: stale bases, deep transforms, heavy catch-up reads. |

Contention is modelled as concurrent writes to a versioned register (a
block's alignment), because concurrent *text* inserts merge cleanly (the
text interleaves — correct, not a conflict). Same seed ⇒ same workload.

Clients author from the state observed at their own last read, so a laggy
client's `baseSeq` genuinely lags the server head. After the rounds, every
client catches up, then polls the idle room 25 times — the steady-state
request a live deployment mostly serves.

## Running

The engines need WordPress (`get_post`, `serialize_block`, and a `$wpdb` for
the ingest lock), so run inside the environment under test via wp-cli.
Options are bare `key=value` tokens — wp-cli would claim `--flags` itself.

```bash
wp eval-file tests/benchmarks/benchmark.php \
    engine=intent-log scenario=mixed-newsroom \
    rounds=200 clients=4 paragraphs=8 seed=42

# Head-to-head: run both engines over the same scenario and seed.
for e in intent-log yjs-server; do
  wp eval-file tests/benchmarks/benchmark.php \
      engine=$e scenario=contended-paragraph rounds=200 clients=4 seed=42
done
```

Under wp-env, the plugin is mounted at
`wp-content/plugins/<checkout-dir-name>`:

```bash
npx wp-env run cli --env-cwd=wp-content/plugins/$(basename "$PWD") \
    wp eval-file tests/benchmarks/benchmark.php engine=intent-log
```

Each run executes `reps=3` repetitions of the identical workload and
discards `warmup=1` of them (autoload, opcache, and first-lock costs land
there); timing percentiles pool the measured reps, and per-rep means with a
stddev expose run-to-run spread. Counted metrics are asserted identical
across reps. Add `json=out.json` to also write the full report, which
includes an `environment` stanza (PHP/WP/DB versions, opcache) and the
`calibration` block — always quote those when comparing runs from different
machines.

### Comparing runs

`compare.js` renders any number of `json=` outputs side by side — engine
runs, transport-benchmark runs (`tests/benchmarks/transport/`), or a mix;
each kind gets its own table, one column per run:

```bash
node tests/benchmarks/compare.js intent.json relay.json            # console
node tests/benchmarks/compare.js intent.json relay.json md=1      # Markdown
```

It warns when engine runs used different workloads (scenario/seed/rounds/
clients/paragraphs) or environments (PHP/DB/opcache) — those numbers are
not directly comparable, and the warning says so instead of silently lining
them up.

## Reading the results

Representative run (`mixed-newsroom`, 150 rounds, 4 clients, 8 paragraphs;
wp-env Docker, PHP 8.3 / MariaDB — quote your own `environment` +
`calibration` stanzas with any numbers you report):

| Metric              | intent-log       | yjs-relay (retired)    | yjs-server             | de-rtc                 |
| ------------------- | ---------------- | ---------------------- | ---------------------- | ---------------------- |
| service ms (mean)   | ~0.7 (incl. ~0.03 lock pair) | ~0.0005 (timer floor) | ~35 (canonical-doc load/merge/save per ingest) | ~2.2 (content three-way merge, incl. lock pair) |
| service ms (p99)    | ~1.4             | ~0.008                 | ~97                    | ~3.6                   |
| idle poll ms (mean) | ~0.0003          | ~0.0002                | ~0.0003                | ~0.0002                |
| storage rows        | 296 (server checkpoints + trims) | 30 (11 scripted client compactions) | 102 (server checkpoints + trims; no client help) | 185 (server checkpoints + trims) |
| storage bytes       | ~108 KB (JSON intents + checkpoints) | (synthetic) | ~61 KB (binary diffs + snapshots) | ~817 KB (every accepted proposal stores a FULL content row) |
| trims (checkpoints) | 3                | —                      | 6                      | 4                      |
| join (cold read)    | ~0.24 ms, ~105 KB payload | —             | ~0.09 ms, ~60 KB payload | ~0.94 ms, **~813 KB payload** (the retained full-content tail) |
| materialize (cold save path) | ~2.7 ms | n/a (no document)     | **~164 ms** (decode the whole canonical doc; the in-session ingest keeps it cached, a fresh save request does not) | ~0.0004 ms (the canonical IS post content) |
| ingest peak memory  | ~0.7 MB          | —                      | ~0.8 MB                | ~0.7 MB                |
| quality             | 480 applied, 114 to review, **0 lost**, content-verified converged | not observable | 600 applied, **0 lost**, all-client CRDT convergence verified | 582 applied, 18 to review, **0 lost**, lineage-verified converged |

Document-size scaling (`long-form`, one editor in a ~5 KB document, 100
rounds): intent-log mean service ~0.42 ms; de-rtc ~2.7 ms with ~5.7 KB
request payloads (the whole document travels in every proposal — both its
merge time and its wire/storage bytes scale with document size);
yjs-server ~26 ms (the canonical-doc rebuild dominates regardless of edit
size). The `laggy-newsroom` scenario (one client reading every 10th round)
settles differently per engine and loses nothing on any of them:
intent-log absorbs stale bases with deeper transforms (more benign voids,
heavier catch-up reads); de-rtc escalates more (~23% — cumulative
stale-base proposals conflict more often) and exercises its retry lane
once the laggy client's base ages out of the engine's 20-version snapshot
window (visible as `unknown-base-version` voids + `followups`).

The comparison the decision turns on:

- **intent-log** spends real server CPU per request (it transforms and plans
  the merge) and in return keeps storage bounded through checkpointing and
  gives a server-side, policy-correct quality signal — nothing lost, every
  conflict surfaced for review. Under a `contended-paragraph` load (4
  editors on one block) it escalates ~74% and still loses nothing.
- **yjs-relay (retired)** was a near-free relay, but the merge cost and
  conflict outcome lived on the client where the server could not see
  them, and the server could not compact (it had no document to snapshot)
  — it depended on a live client volunteering. In a room whose clients
  left or never volunteered, storage grew one row per edit forever. Its
  `storage.bytes` and snapshot sizes in the table are synthetic (see
  Limitations).
- **yjs-server** buys back the relay's missing server authority (bounded
  storage without client help, observable convergence, materialization)
  while keeping CRDT merge semantics and needing NO ingest lock — but at
  the price of loading, merging, and re-encoding the canonical y-php
  document on every ingest: tens of ms per request at this document size
  in pure PHP, roughly 50× intent-log's transform. Its idle polls and
  reads stay as cheap as the relay's (pure row reads — the canonical doc
  is never touched on the read path). That ingest cost scales with
  document size, so `long-form` runs matter before drawing conclusions.
- **de-rtc** sits between them on CPU (~3× intent-log per ingest, ~16×
  cheaper than yjs-server at this size) and buys the same escalate-honest
  conflict policy as intent-log — but it pays in BYTES, not cycles: whole
  documents travel in every proposal and every accepted proposal stores a
  full content row (~8× intent-log's row bytes here, and both scale
  linearly with document size). Its escalation rate on the same contended
  workload is lower than intent-log's (block-level three-way merges treat
  identical concurrent writes as agreement; intent-log's versioned
  registers escalate every later writer), and a client that reads rarely
  escalates more and eventually needs the retry lane — the deep-lag
  behaviors are where the engines differ most, so run `laggy-newsroom`
  before concluding.

## Limitations

- **Single-process, no queueing model.** This measures per-request service
  time and growth, not tail latency under a saturated worker pool. The
  DE-RTC harness's multi-process request-queue simulation could be layered
  on top of these engine adapters later.
- **Opaque-relay quality is unmeasured here** by construction (a
  client-merging engine's merge runs in browser clients, outside the
  harness), not by omission. This limitation does not apply to yjs-server:
  its profile IS the y-php client oracle.
- **Opaque-relay payloads are synthetic.** Per-edit updates and compaction
  snapshots are size-modelled blobs (a few dozen bytes per keystroke batch;
  a snapshot proportional to accumulated document size), not real Yjs
  binary. On that profile `payload_bytes` and `storage.bytes` are therefore
  order-of-magnitude estimates; `storage.rows` and the compaction cadence
  are exact.
- **In-memory storage** understates absolute per-request time (no real DB
  round-trip for reads/writes) but keeps the *engine* comparison clean;
  storage growth is exact. For end-to-end latency including MySQL, point
  the runner at `WP_Sync_Post_Meta_Storage` instead.
- **The intent-log and de-rtc ingest locks ARE real DB I/O inside
  `service_us`** (one `GET_LOCK`/`RELEASE_LOCK` pair per request), so their
  absolute timings move with the environment's DB latency — a Docker MySQL
  and a local socket differ by an order of magnitude. Use the
  `calibration.lock_pair_p50_ms` figure to subtract it out, and never
  compare `service_us` across environments without the `environment` +
  `calibration` stanzas.
- **Opaque-relay service times sit near the timer floor** (single-digit
  µs): they say "an append-only relay's server cost is negligible", not
  anything more precise.
- **The de-rtc client model is deliberately simplified in two places.**
  Escalated proposals are reverted from the simulated client's local copy
  (production keeps the author's copy on screen pending a human decision;
  the harness reverts so later proposals stay clean and the oracle can
  assert the conflict stayed OUT of the canonical), and a retried
  proposal's applied disposition advances the client's base directly —
  sound here because the runner is synchronous, so a retry authored
  against a just-observed head is always a fast-forward; production's
  adapter reaches the same state from its own broadcast row. Each edit is
  retried once; a second stale-base void parks it (never silently drops
  it), and score() flags any edit left unsettled.
