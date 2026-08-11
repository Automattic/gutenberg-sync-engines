# Sync-engine benchmark harness

Compares server sync engines **through the production seam** — the same
`WP_Sync_Engine::handle_updates()` / `get_updates_since()` calls the polling
transport makes — so the numbers are the real engine's, not a model's. It
exists to make the architecture decision (which engine, or keep both) a
matter of evidence.

Engines are resolved through the framework's registry (the
`wp_sync_engines` filter), so **any engine registered by an active plugin is
benchmarkable by slug** — run with an unknown slug to list what's
registered. This plugin registers two:

- **`intent-log`** (`WP_Intent_Log_Engine`) — server-authoritative: the
  server transforms each edit against the log, so it can report exactly how
  every edit settled.
- **`yjs-server`** (`WP_Yjs_Server_Engine`) — server-authoritative CRDT:
  the server (via the vendored y-php) merges every update into a canonical
  room document, compacts by itself, and materializes post content.

(A third engine, `yjs-relay` — a dumb relay whose merge happened in each
client's CRDT — has been removed; historical numbers for it remain below
as context.)

The runner has three authoring profiles. It speaks to intent-log in typed
intents (and scores quality with the disposition-based oracle). It speaks
to yjs-server in **real Yjs**: each simulated client holds a y-php
document, authors genuine incremental V2 updates (text inserts into the
paragraph's content Y.Text; align set on the attributes Y.Map — exactly
what the editor's session codec sends), and applies read responses into its
document; payload and storage bytes are therefore REAL for this engine, and
quality is scored with a CRDT oracle (see below). Every other engine gets
the **opaque-relay profile** — relay-convention `update`/`compaction` blobs
with quality reported as not server-observable. A third-party relay-style
engine benchmarks meaningfully out of the box; an engine with its own wire
vocabulary will void the generic updates, and the dispositions/storage
counts will show that rather than fake a result.

## What it measures

**Cost** (both engines):

- `service_us` — per-request service time of `handle_updates` (p50/p90/p99/
  max/mean, reported in ms), measured with `hrtime()` and pooled across
  measured repetitions (warmup reps are excluded). Storage is swapped for an
  in-memory implementation, so this mostly isolates *engine* CPU (the
  intent-log planner and replay; the relay's append) from database I/O —
  with one deliberate exception: the intent log's ingest holds a per-room
  MySQL `GET_LOCK` for the length of each request, so each of its samples
  includes one lock/release pair of real DB round-trips (the relay pays
  none). The reported `calibration` block times that lock pair and a bare
  `SELECT 1` in the same environment so the number can be decomposed.
- `read_us` / `idle_poll_us` — per-request time of `get_updates_since`,
  split into catch-up reads (during and after the session) and idle polls
  (nothing new to deliver). Idle polls dominate request volume in a live
  deployment, so their cost is reported on its own.
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
  room. `storage.compactions` counts those snapshots; their requests are
  included in cost.

**Quality** — policy-correct, and only where the server can observe it:

- `dispositions` — for the intent log, the count of edits that were
  `applied` (merged), `escalated` (set aside for human review), or `voided`.
- `escalation_rate` — escalated / total. This is **reported, not
  penalized**: sending a genuine conflict to review is the point, not a
  failure.
- `lost_work` — edits that were dropped without being applied or preserved
  for review (a `voided` with a non-benign reason). The project's policy is
  *never lose work*; this asserts it. It is `0` in every scenario here.
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

| Metric              | intent-log       | yjs-relay (retired)    | yjs-server             |
| ------------------- | ---------------- | ---------------------- | ---------------------- |
| service ms (mean)   | ~0.64 (incl. ~0.03 lock pair) | ~0.0005 (timer floor) | ~33 (canonical-doc load/merge/save per ingest) |
| service ms (p99)    | ~1.25            | ~0.008                 | ~83                    |
| idle poll ms (mean) | ~0.0003          | ~0.0002                | ~0.0003                |
| storage rows        | 296 (server checkpoints + trims) | 30 (11 scripted client compactions) | 102 (server checkpoints + trims; no client help) |
| quality             | 480 applied, 114 to review, **0 lost**, content-verified converged | not observable | 600 applied, **0 lost**, all-client CRDT convergence verified |

Document-size scaling (`long-form`, ~5 KB document vs `solo-typing`'s
near-empty one, same rounds): mean service ~0.36 ms vs ~0.28 ms — replay
cost grows with document size, but modestly at this scale. The
`laggy-newsroom` scenario (one client reading every 10th round) settles
with more benign voids (26 vs 6) and heavier catch-up reads, and still
loses nothing.

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
- **The intent-log ingest lock IS real DB I/O inside `service_us`** (one
  `GET_LOCK`/`RELEASE_LOCK` pair per request), so its absolute timings move
  with the environment's DB latency — a Docker MySQL and a local socket
  differ by an order of magnitude. Use the `calibration.lock_pair_p50_ms`
  figure to subtract it out, and never compare `service_us` across
  environments without the `environment` + `calibration` stanzas.
- **Opaque-relay service times sit near the timer floor** (single-digit
  µs): they say "an append-only relay's server cost is negligible", not
  anything more precise.
