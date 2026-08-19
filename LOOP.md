# LOOP.md — v1 loop ledger

Operational state for the v1 loop. `V1.md` is the frozen scope contract
(edit that only with the human); this file is working state — the
executor updates it every cycle and commits the update on the base
branch. Newest cycle-log entries go on top.

**Loop status:** RUNNING
**Base branch:** chriszarate/loop-v1
**Current item:** none

## Queue

Ordered by intent: bank trust with low-risk, highly-verifiable items
first; the exit gate (A8) runs last. Lane B items may be worked any
time after the A-items above them; their end state is a proposal, never
a merge.

| Item | Lane | Status | Attempts | Branch | Notes |
| --- | --- | --- | --- | --- | --- |
| A7 websocket fencing coverage audit | A | done | 1 | loop/a7 | verifier PASS (cycle 1) |
| A6 phpcs burn-down | A | done | 1 | loop/a6 | verifier PASS (cycle 2); debt was 17E+26W at base, not ~275E |
| A9 stale phpcs-debt note in AGENTS.md | A | done | 1 | loop/a9 | verifier PASS (cycle 3); MERGE ORDER: loop/a6 must merge before or with loop/a9 |
| A1 intent-log empty-genesis reload stall | A | done | 1 | loop/a1 | verifier PASS (cycle 4); root cause: pre-init edits dropped |
| A10 stale A1-OPEN note in AGENTS.md | A | done | 1 | loop/a10 | verifier PASS (cycle 5); MERGE ORDER: loop/a1 before or with loop/a10 |
| A4 yjs genesis rich-text defect | A | done | 1 | loop/a4 | verifier PASS (cycle 6); selector-sourced rich text split |
| A5 announce-inversion verification debt | A | done | 1 | loop/a5 | verifier PASS (cycle 10); WS fuzz 0/5 → 5/5 via daemon room scan + engine cache flush |
| A11 de-rtc session request-rate runaway | A | parked | 1 | — | CLOSED-INVALID (cycle 9): the soak's minuteSamples are CUMULATIVE counters; the deltas are a flat ~75 req/min/window all hour. No runaway exists — cycle 8 misread the data. Server capture confirms flat sync-frame rate. Full diagnosis in the cycle-9 log |
| A2 e2e flake stabilization | A | in-progress | 1 | loop/a2 | login flake fixed (plugin-local fixture); NEW 4th signature: intent-log table-cell edit lost under load (run 2). Counted-green runs so far: 0 |
| A3 websocket fixme re-enable | A | queued | 0 | — | prefer the real-daemon lane |
| A8 full fuzzer matrix soak | A | blocked | 0 | — | exit gate; runs after A1–A7 |
| B1 yjs materialization (framework) | B | queued | 0 | — | proposal only; subtree edits |
| B3 pending-edit inline-card UI | B | queued | 0 | — | proposal only; build to prototype decisions |
| B4 commit-cadence dial | B | queued | 0 | — | proposal only; default is a product decision |
| B5 review resolutions over REST | B | queued | 0 | — | proposal only; wire-protocol change |

Status vocabulary: `queued`, `in-progress`, `verifying`, `done`
(verifier PASS recorded), `parked` (3 strikes or escalation trigger —
diagnosis required below), `awaiting-human` (Lane B proposal ready),
`blocked` (dependency not met).

## Parked / escalated

- **Foreign V1.md edit parked on `v1-m1-maybe` (cycle 4, for the
  human).** While cycle 4 was mid-flight, another session (commit
  co-authored "Claude Opus 5", `c117008153`, 2026-08-19 14:02) committed
  an 80-line V1.md addition ("maybe item M1": the unused-Automerge
  finding) directly onto this worktree's checked-out branch `loop/a1`.
  V1.md scope changes are Lane B / human-owned, and the first A1
  verification FAILED solely because that commit rode the item branch.
  The commit was NOT discarded: it now sits alone on branch
  `v1-m1-maybe`; `loop/a1` was rebased to contain only the A1 fix.
  Human decides whether to adopt M1 into V1.md.

## Proposals awaiting human review

None.

## Cycle log

### Cycles 12–13 — 2026-08-19 — A2 (login flake fixed; new signature found)
- Cycle 12: run 1 (retry-free full suite) came back 54/55 — the one
  failure was the KNOWN "fixture login navigation" flake, and its
  failure snapshot nailed the mechanism: wp-login's
  `wp_attempt_focus()` steals focus (selecting the username field) on
  a timer; under load it fires between the subtree fixture's two
  fill() calls, the password lands in the still-selected username
  field, the mangled form submits, and waitForURL times out on the
  re-rendered login page. The fixture is SUBTREE code, so the fix is
  plugin-local: `tests/e2e/config/collaboration-fixtures.ts`
  replicates the subtree's fixture wiring around a
  HardenedCollaborationUtils whose joinUser retries once from a fresh
  context (harness plumbing; assertion surfaces untouched; root-cause
  fixture fix filed as upstream/human-owned). All nine specs now
  import the local module. Smoke: the failing test 3/3 green.
  (Ledger entry deferred one cycle: run 2 was launched from loop/a2
  and a branch switch mid-run would have changed spec files on disk.)
- Cycle 13: run 2 came back 54/55 — login flake did NOT recur; a NEW
  fourth signature appeared: intent-log multi-client "mix of block
  types" — user B's table-CELL edit (" plus B") never reached user
  A's canvas in 15 s while B's caption/list/quote edits all arrived.
  Not yet classified (sync stall vs escalation vs register conflict).
  Launching run 3; if the signature recurs, next cycle dedicates a
  wire-instrumented diagnosis.
- Acceptance: 0 of 3 consecutive green runs so far (both runs 54/55
  with distinct single failures).
- Verifier: not yet requested.
- Ledger changes: A2 stays in-progress (1 commit on loop/a2).

### Cycle 11 — 2026-08-19 — A2 started (baseline run in flight)
- Did: claimed A2 on loop/a2. The recorded two-suite reproduction
  (yjs suite then http-only/collaboration-sync-body-size, retries=0,
  1 worker) PASSES in isolation today — 8/8 — so the known flakes are
  full-suite-load-dependent. Launched full default-suite run 1
  (retries=0) detached against the tests env to observe what actually
  fails under load; log at the session scratchpad `a2-full1.log`.
- Acceptance: pending — needs 3 consecutive green retry-free full
  runs, serialized, plus fixes for whatever run 1 surfaces.
- Verifier: not yet requested.
- Ledger changes: A2 queued → in-progress (attempt 1).

### Cycle 10 — 2026-08-19 — A5 completed (verifier PASS)
- Did: sub-item 2. First run: de-rtc/websocket fuzz 0/5 — every seed
  non-convergent. Control (intent-log/websocket 2/2) proved the env
  healthy, so the lane itself was broken. Root cause, two stacked
  daemon gaps, both from the announce inversion moving de-rtc's whole
  content flow OUT-OF-BAND (commits ride the autosave endpoint):
  (1) the daemon only pushed rows in reaction to socket messages, so
  stored announces never reached subscribers — fixed with a 1 Hz
  out-of-band room scan in tick(); (2) the daemon's lifetime engine
  instance served STALE cached canonical (fetch answers said "current",
  returned nothing) — fixed with an additive
  `flush_room_state_cache()` on the de-rtc engine, called by the
  daemon before every message-driven process_room_request and every
  broadcast read (restoring the web process's per-request boundary).
  Regression test `Tests_Collaboration_WpWebSocketRoomScan` (new file
  to avoid textual conflict with loop/a7's edits) pins push-on-scan,
  cursor advance, and quiet-room silence; it cannot run on base.
- Acceptance: de-rtc/websocket fuzz 5/5 (was 0/5); full test:php 311
  OK; test:js 526; fuzz:quick green (one non-reproducible intent-log
  flake — this branch predates loop/a1). Verifier independently re-ran
  the WS fuzz (5/5), recomputed the soak JSON's deltas (confirming
  cycle 9's flat-rate correction), and spot-checked every docs claim
  against the announce tests and engine code.
- Verifier: PASS — accepted cycle 9's gate ruling explicitly (cliff
  surfaces closed; per-incorporation scaling disclosed in the docs;
  B4 the lever). Noted the daemon scan's cost: one storage read per
  subscribed room per second.
- Ledger changes: A5 in-progress → done (branch loop/a5, 2 commits).
- Operational note (recurring): every `npm run test:php` run wipes the
  tests-site plugin activations — reactivate gutenberg + loop-v1
  before any fuzzer/daemon run, or the daemon fails its health check
  (`wp collaboration` unregistered).

### Cycle 9 — 2026-08-19 — A11 (closed invalid; cycle-8 correction)
- Did: reproduced with a 5-minute soak while `wp collaboration
  capture` recorded the full server-side request stream. VERDICT: the
  "runaway" does not exist. The soak's minuteSamples snapshot
  CUMULATIVE Playwright counters; cycle 8 read them as per-minute
  rates. The deltas are constant ~73-75 requests/min/window across the
  whole hour (≈55 polls + ~17 commits + auxiliary), and the capture's
  server-side sync frames are flat (~165/min total, 3 windows).
- What IS true at hour scale, correcting cycle 8's record: request
  rate flat; poll responses 2.5-4 KB avg; storage rows constant-size;
  peak PHP memory 9 MB (cliff closed). What grows with document size
  is the DESIGNED per-incorporation cost: 279 accepted commits in
  5.5 min carried whole content up (plus the autosave REST echo down),
  and behind peers downloaded ~1.1 synthesized snapshots per commit
  (554 fetch rows → 316 snapshot answers; hash matches suppress the
  rest). Wire download therefore scales with doc size × incorporation
  rate while actively co-editing — the announce model's intended
  trade, already documented in the A5.3 docs pass; the commit-cadence
  dial (B4) is the lever.
- A5.1 gate ruling recorded for the verifier: the byte CLIFF the gate
  targets (stored rows, join tail, PHP memory, poll payloads) is
  structurally closed — PASS. Per-incorporation content transfer is
  design, not the cliff.
- Ledger changes: A11 parked as CLOSED-INVALID (no code change, branch
  deleted). A5 sub-item 1 marked passed; sub-item 2 (WS fuzz) next.
- Observation, not filed: a few synthesized snapshot versions were
  delivered up to 5× (re-fetches during rapid incorporation) — minor
  wire inefficiency, post-v1 material at most.

### Cycle 8 — 2026-08-19 — A5 (soak collected; A11 filed)
- Did: collected the hour soak. GREEN gates: convergence (CONVERGED in
  3010 ms, 3 windows byte-identical at 28 298 content bytes), saves
  29/29 ok, zero console errors in all windows, no OOM/5xx (server
  per-request averages healthy: 18.8 ms dispatch, 3.4 ms CPU, 33 DB
  queries, 9 MB peak memory; probe latency n=222 p50 1278 ms p90
  1467 ms). Announce-model verification: per-request payload stays
  ~5.7 KB FLAT while the document grows to 28 KB — stored rows and
  poll responses no longer scale with document size (the old byte
  cliff is structurally gone at the row level).
- FAILED gate → new item: "download per user-hour" cannot be called
  non-scaling — minuteSamples show the sync request RATE growing
  linearly with session age: 85 → 4435 requests/min/window across the
  hour (~55 polls/s + ~19 commit POSTs/s per window at the end; upload
  ~24 MB/min/window ≈ whole-content commit bodies). Verified real sync
  traffic: the harness counters match only `wp-sync/v1` routes and
  proposal_id-tagged autosave commits. Filed as A11; A5.1's soak gate
  re-runs after A11 is fixed. Full JSON in the session scratchpad
  (`a5-soak-de-rtc.json`) — key numbers recorded here because the
  scratchpad is ephemeral.
- Acceptance: A5 remains in-progress (sub-item 2 WS fuzz not yet run;
  soak re-run blocked on A11).
- Verifier: not yet requested.
- Ledger changes: filed A11 (queued, above A1's old slot priority-wise
  — it blocks A5). A5 stays in-progress.

### Cycle 7 — 2026-08-19 — A5 announce-inversion verification debt (started)
- Did: preflight re-fixed the double-mount arrangement (browser suites
  re-activate the mapping copy every run — now a standing preflight
  step). Launched sub-item 1, the hour-scale soak
  (`soak-transport.mjs engine=de-rtc transport=http-polling windows=3
  soak=3600`), detached against the tests env (WP_BASE_URL :8894);
  startup healthy (probes ~1.3 s, first periodic save ok). While it
  runs, completed sub-item 3: the wire-consistency pass in
  `docs/engine-comparison.md` (scenarios A–G, resource-profile prose,
  wire-format parity cell, first known-gaps bullet) rewritten to the
  shipped announce/fetch/commit model, spot-checked against
  `wpDeRtcAnnounce.php` row types and AGENTS.md's inversion notes;
  Scenario C gained the commit-hold rule. Doc stays number-free.
- Acceptance: pending — soak must finish (convergence gate, no
  OOM/500s, saves green, download per user-hour independent of doc
  size; JSON summary to be recorded here) and sub-item 2
  (`npm run fuzz -- --combos=de-rtc/websocket`) runs after the soak
  frees the tests env. Verifier not yet requested.
- Verifier: not yet requested (item continues next cycle).
- Ledger changes: A5 queued → in-progress (branch loop/a5, 1 commit,
  attempt 1).

### Cycle 6 — 2026-08-19 — A4 yjs genesis rich-text defect
- Did: fixed genesis mapping in
  `includes/engines/yjs-server/class-wp-yjs-server-engine.php`. A
  selector-sourced rich-text attribute (image `caption` ← `figcaption`)
  now seeds only the sub-element's inner text; the surrounding markup
  and the sub-element's tags are recorded on the server-only genesis
  wrapper entry (additive keys), and materialization rebuilds exact
  bytes. In-session-added captions emit core's conventional
  `<figcaption class="wp-element-caption">`. Wrapper-sourced attributes
  (paragraph `content`; selectors matching the wrapper's own tag, e.g.
  quote `value` ← `blockquote`) keep the original mapping — deliberate,
  to bound the change. Three PHPUnit regression tests, all red on base
  (base failure output showed the live bug: a caption edit chewing into
  the `<img>` tag).
- Acceptance: yjs class filter OK (26 tests); full test:php OK (313);
  test:js 526 passed; fuzz:quick 2/2 per engine; verifier additionally
  ran the yjs e2e spec 7/7 retries=0 (save/reload convergence intact).
- Verifier: PASS — confirmed the new wrapper keys never reach the wire
  (server-only room meta) and pre-fix rooms keep their old
  materialization path. Noted non-blocking edge: greedy regex picks the
  LAST same-tag sub-element in a pathological multi-figcaption block.
- Ledger changes: A4 queued → done (branch loop/a4, 1 commit).
- Lesson applied the hard way: `git checkout <branch> -- <file>` to
  "restore" an uncommitted file restores the COMMITTED version — it
  wiped the in-progress engine edit mid-cycle (recovered from context).
  Commit (or stash-keep) before borrowing base versions of files.

### Cycle 5 — 2026-08-19 — A10 stale A1-OPEN note in AGENTS.md
- Did: preflight caught and fixed the worktree double-mount trap on the
  tests env (the fuzzer's global-setup activates the plugin by its
  `gutenberg-sync-engines` slug, leaving the mapping copy active — the
  arrangement that fatals the next wp-env start; swapped back to the
  directory-name copy `loop-v1`). Then the item: replaced AGENTS.md's
  "OPEN … V1.md A1" residual bullet with a FIXED entry describing the
  loop/a1 fix and the remaining residual (pre-init edits on non-empty
  bootstraps still clobbered by the reconciling push, as before).
- Acceptance: doc-only ledger item. The verifier cross-checked every
  claim in the new prose against loop/a1's diff, re-ran the manager
  Jest suite (51 passed) and the seed-6 fuzzer replay (1/1) on loop/a1.
- Verifier: PASS — merge-order constraint recorded (loop/a1 before or
  with loop/a10).
- Ledger changes: A10 queued → done (branch loop/a10, 1 commit).

### Cycle 4 — 2026-08-19 — A1 intent-log empty-genesis reload stall
- Did: reproduced with the V1.md replay command (deterministic on this
  host), diagnosed via temporary console instrumentation through the
  fuzzer's console capture: the reloaded participant inserts the block
  BEFORE the room snapshot arrives; `update()` drops pre-init trees,
  capture is edge-triggered, and the empty-genesis bootstrap skips the
  reconciling push — the intent never exists. Fix in
  `src/engines/intent-log-manager.ts` (non-frozen): buffer the latest
  pre-init tree; an empty-genesis bootstrap schedules a DEFERRED
  capture that runs only if the document is still empty after the
  delivery burst. The deferral matters: the first (synchronous) version
  of the fix made fuzz:quick fail with duplicated blocks on
  rejoin-with-history (empty genesis + history rows replaying behind
  it — capturing the buffered tree against the bare genesis fabricates
  every saved block). Two Jest regression tests pin both sides.
- Acceptance: seed-6 replay 1/1 pass; sweep all oracles green;
  test:js 528 passed (526 at base + 2 new); fuzz:quick 2/2 per engine.
- Verifier: FAIL then PASS. First FAIL was not about the fix: a
  FOREIGN commit (another session editing V1.md) had landed on the
  item branch — see Parked. After preserving it on `v1-m1-maybe` and
  rebasing `loop/a1` to the single A1 commit, PASS — the verifier
  independently re-ran all four acceptance commands and reproduced
  red-on-base for both the new Jest test and the fuzzer replay.
- Ledger changes: A1 queued → done (branch loop/a1, 1 commit). Filed
  A10 (stale A1-OPEN note in AGENTS.md). Parked the foreign V1.md
  edit for the human.

### Cycle 3 — 2026-08-19 — A9 stale phpcs-debt note in AGENTS.md
- Did: replaced AGENTS.md's "~275 errors + ~29 warnings" Known-issues
  bullet with the current contract (lint clean since A6; excludes stay
  as designed). V1.md:148 carries the same stale figure but V1.md is
  human-owned — flagged here for the human, not edited.
- Acceptance: doc-only ledger item (no V1.md commands). `grep 275
  AGENTS.md` empty; the verifier independently re-ran `composer lint`
  on loop/a6 (exit 0) to confirm the new claim reproduces.
- Verifier: PASS — one caveat for sign-off, recorded in the queue row:
  loop/a9's claim is only true once loop/a6 is merged, so loop/a6 must
  merge before or together with loop/a9.
- Ledger changes: A9 queued → done (branch loop/a9, 1 commit).

### Cycle 2 — 2026-08-19 — A6 phpcs burn-down
- Did: the whole item in one cycle — the "~275 errors" figure in
  V1.md/AGENTS.md was stale; real debt at base was 17 errors +
  26 warnings in 17 files. `composer format` auto-fixed 28 violations
  (alignment, array formatting, quotes); hand fixes for the rest:
  docblocks (intent-log `materialize()`, de-rtc `save_canonical()`
  `$advance`/bool return, rooms-CLI `$room`), Yoda flips in the de-rtc
  engine, websocket handshake ternary hoisted to named variables,
  `qm/debug` + hash-verified `base64_decode` phpcs:ignores matching
  existing repo convention, `$parent` → `$parent_id` in the base-seq
  preflight. No behavior changes; excludes untouched.
- Acceptance: `composer lint` exit 0 (0E/0W); `npm run test:php`
  OK (310 tests, 2142 assertions); `npm run test:js` 38 suites /
  526 tests passed.
- Verifier: PASS — confirmed base had exactly 17E/26W in the 17
  touched files, diff is style/docs-only, ignores are targeted
  suppressions not exclude-widening.
- Ledger changes: A6 queued → done (branch loop/a6, 1 commit). Filed
  A9 (stale phpcs-debt note in AGENTS.md, from the verifier's
  follow-up note).

### Cycle 1 — 2026-08-19 — A7 websocket fencing coverage audit
- Did: fresh-worktree setup (plugin bundle + subtree build, tests env
  started; doctor clean). Audited `tests/phpunit/wpWebSocketSyncTransport.php`
  against the three contracted behaviors: stamp forwarding and the basic
  stale-tab fence were covered; the engine-switch behaviors were not.
  Added three regression tests through the daemon's `validate_room_request()`
  seam: new-engine stamp heals (resets + re-genesises) a switched global
  collection room; a stale old-engine stamp fences without resetting;
  per-post entity rooms stay fenced even for new-engine clients. Also
  renamed the test class `Tests_Collaboration_WpWebSocketSyncTransport`
  → `Test_WP_WebSocket_Sync_Transport`: V1.md's acceptance filter used
  the latter name and selected ZERO tests (vacuous pass; nothing else
  referenced the old name).
- Acceptance: `npm run test:php -- --filter Test_WP_WebSocket_Sync_Transport`
  → OK (12 tests, 34 assertions). phpcs clean on the touched file.
- Verifier: FAIL then PASS. First verdict failed on the vacuous V1.md
  filter (its suggested fix: rename the class or amend V1.md); after the
  rename, PASS — the verifier also fault-injected the original
  stamp-stripping bug and confirmed the new tests catch it (1 error +
  2 failures), then reverted.
- Ledger changes: A7 queued → done (branch loop/a7, 2 commits). Base
  branch corrected to chriszarate/loop-v1 (ledger previously named
  chriszarate/try-loop, where the scaffolding was authored). Loop status
  NOT STARTED → RUNNING.

Format per entry:

```
### Cycle N — YYYY-MM-DD — <item>
- Did: <one bounded unit, in one or two sentences>
- Acceptance: <commands run and their results>
- Verifier: PASS/FAIL — <reasons if FAIL> (or "not yet requested")
- Ledger changes: <status transitions, new items filed>
```

## Lessons

Durable operational lessons learned by the loop (what failed, why, the
rule that prevents it next time). Distill anything cross-project into
the agent memory directory; keep repo-specific lessons here.

- Run each item's V1.md acceptance commands LITERALLY before requesting
  verification — a `--filter` that matches nothing exits 0 with "No
  tests executed!" and reads as green. A7's contract filter named a
  class that didn't exist; the verifier caught it, the executor's
  first run (with a corrected filter) did not. When the contract's
  command is wrong, prefer making the code match the contract (here: a
  test-class rename) over amending read-only V1.md.
- Check `git log <base>..<item-branch>` before requesting verification:
  other sessions may commit onto this worktree's checked-out branch
  mid-cycle (cycle 4: a foreign V1.md edit rode loop/a1 and failed
  verification for the whole item). Preserve foreign commits on their
  own branch, never discard them.
- The transport soak's `minuteSamples` snapshot CUMULATIVE counters —
  compute deltas before reasoning about rates. Cycle 8 filed a
  nonexistent "runaway" from reading them as per-minute rates; the
  correction cost a full diagnosis cycle. When a curve looks alarming,
  first ask whether the counter resets.
- A bootstrap-time recovery that syncs buffered pre-init work must not
  run synchronously on snapshot receipt: the genesis snapshot is only
  the FIRST row of its response, and a rejoiner's history replays right
  behind it. Defer past the delivery burst and re-check state (cycle 4:
  the synchronous version duplicated every saved block under
  fuzz:quick's rejoin schedule).
