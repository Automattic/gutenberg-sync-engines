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
- **yjs-relay** — the clients own the document: the server stores and
  forwards opaque CRDT updates, and every client's Yjs instance merges
  them; the incumbent design, and the baseline to beat.

## One architectural choice drives everything

Where the merge happens decides almost every other property:

- **Merging on the server (intent-log)** costs server CPU and a per-room
  ingest lock, and in exchange the server can *observe* outcomes: per-edit
  dispositions (applied / escalated / voided), a convergence oracle in the
  benchmark, a review lane for conflicts, and capability enforcement at
  ingest (an author without `unfiltered_html` gets raw-HTML content parked
  for review by someone who has it — the server is not relaying bytes it
  cannot inspect).
- **Merging on the clients (yjs-relay)** makes the server nearly free —
  append a row, read rows — and in exchange the server can observe
  *nothing*: no merge outcomes, no conflict surfacing, no content-level
  capability enforcement before save, and no benchmarkable quality metrics
  (the harness prints "NOT SERVER-OBSERVABLE" rather than faking them).

Neither is strictly better; they price the same work differently. The
tables below are how the price shows up.

## Feature parity

| Area | intent-log | yjs-relay |
| --- | --- | --- |
| Conflict handling | Transform on the server; genuine conflicts park in the editor's review panel (escalation notice, marker chip, durable resolutions — e2e-verified) | Silent CRDT auto-merge; no conflict concept |
| Collaborative undo | **Not yet** — WP's global undo applies (can undo a peer's work); designed fix is inverse intents | Per-peer undo manager (`src/engines/yjs-relay/undo.ts`) |
| Refresh/offline recovery | Server materializes the document; queued intents are memory-only. Solo edits flush every poll (`syncWhileSolo`), and discarded unsent work surfaces an editor notice | CRDT doc serialized with the entity; survives refresh |
| Error recovery | Exact re-send; ingest is idempotent by intentId | Full-state recovery update (deltas are not idempotent server-side) |
| History compaction | Server checkpoints every 100 intent rows and trims | Client-nominated at 50 rows; **an abandoned room never compacts** |
| Capability enforcement | At ingest (kses lane; escalation for `unfiltered_html`-gated content) | At save only; the relay cannot inspect payloads |
| Synced entity properties | Whitelist (currently the title) | Whatever the sync config maps into the CRDT |
| Presence/awareness | Yes (shared Yjs-free awareness doc) | Yes (Yjs awareness) |
| Server observability | Dispositions, debug envelope, benchmark quality metrics | None by design |
| Wire format | Small human-readable JSON intents | Opaque base64 binary |

## Host-facing resource profile

| Concern | intent-log | yjs-relay |
| --- | --- | --- |
| Per-ingest CPU | Replay from checkpoint + transform planning | ~zero (append) |
| Locking | Per-room MySQL `GET_LOCK` serializes ingest (5 s timeout; contenders get a retryable 503). One real lock round-trip pair inside every timed request — the engine benchmark's `calibration` block exists to subtract it | None |
| Idle reads | Cheap by design (rows after cursor; no reconstruction) | Cheap |
| Storage growth | Bounded: checkpoint + trim every 100 rows | Bounded only while clients are present; abandoned rooms grow until someone returns |
| Row contents | JSON intents (~200 B typical) + periodic full-document checkpoint rows | Base64 updates + full-state compaction rows that scale with document size |

Reference numbers from one dev machine (wp-env, Docker MariaDB, Aug 2026 —
regenerate locally, and never compare across machines without the
harnesses' `environment` stanzas): intent-log service time ~0.5 ms p50 per
edit including the lock pair; yjs-relay sits at the timer floor
(single-digit µs — read it as "negligible", nothing more precise). Relay
payload/storage *bytes* in the engine benchmark are synthetic size models;
its row counts and compaction cadence are exact.

## Transports are a separate axis

Both engines run over any transport. Measured with the transport benchmark
(yjs-relay, same machine, 30 trials):

| | edit-to-visible p50 | idle traffic per collaborator |
| --- | --- | --- |
| http-polling | ~1.7 s | ~56 req/min |
| http-long-polling | ~0.5–0.65 s | ~94–98 req/min (held requests wake on awareness heartbeats), each holding a PHP worker up to its wait budget |
| websocket | ~30 ms | ~14 frames/30 s — plus a persistent daemon, TLS termination, and an exposed port |

Transport latency is engine-independent (the HTTP rows replicate within
noise under intent-log), with one exception noted below.

## Known gaps — read before concluding

- **Intent-log echo race.** Editor pushes racing live keystrokes can
  corrupt canvas text; it is rare over the HTTP transports' batched cadence
  and severe over websocket's per-keystroke cadence — benchmark the
  websocket transport under yjs-relay until it is fixed. The fix is a
  session/bridge redesign (capture against the editor's last-observed
  document state); see the `KNOWN LIMITATION` comment in
  `src/engines/intent-log-manager.ts` and the AGENTS.md known issue.
- **Intent-log has no collaborative undo yet** — for many editorial teams
  this is the biggest day-to-day parity gap.
- **The websocket transport is experimental** (one-time auth token travels
  as a URL query parameter; plaintext `ws://` must never leave a dev box).
- **yjs-relay quality is unmeasurable at the server** — that is a fact
  about the architecture, not a benchmark omission. A fair quality
  comparison would need a client-side Yjs oracle.
- **Abandoned yjs-relay rooms never compact.** For a host, that is an
  unbounded-growth liability that needs a cron/cleanup story before
  production use.

## Bottom line

- If the priority is **minimal server footprint** and refresh-proof
  clients, and silent auto-merge is acceptable, yjs-relay is hard to beat
  on cost — budget for the abandoned-room cleanup gap and the loss of
  server-side observability and capability enforcement.
- If the priority is **accountable merges** — no silently lost or
  silently merged work, conflicts reviewable by humans, quality metrics a
  host can actually monitor — intent-log buys that for roughly half a
  millisecond of serialized server work per edit, with collaborative undo
  and the echo-race fix as the open engineering items.
- Transport choice is independent and mostly a hosting decision: polling
  works everywhere at ~1.7 s perceived latency, long-polling roughly
  triples responsiveness for the price of held PHP workers, and websocket
  is effectively instant for the price of running a daemon.
