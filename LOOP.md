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
| A9 stale phpcs-debt note in AGENTS.md | A | queued | 0 | — | filed cycle 2: AGENTS.md "Known issues" still claims ~275 errors; false once loop/a6 merges. Doc-only |
| A1 intent-log empty-genesis reload stall | A | queued | 0 | — | open bug; replay command in V1.md |
| A4 yjs genesis rich-text defect | A | queued | 0 | — | plugin-side |
| A5 announce-inversion verification debt | A | queued | 0 | — | 3 sub-items; hour soak is wall-clock long |
| A2 e2e flake stabilization | A | queued | 0 | — | 3x consecutive retry-free full runs |
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

None.

## Proposals awaiting human review

None.

## Cycle log

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
