# LOOP.md — v1 loop ledger

Operational state for the v1 loop. `V1.md` is the frozen scope contract
(edit that only with the human); this file is working state — the
executor updates it every cycle and commits the update on the base
branch. Newest cycle-log entries go on top.

**Loop status:** COMPLETE (2026-08-20); INTEGRATION BRANCH READY —
`loop/integration` merges every item branch (including parked
loop/a12's fixes and v1-m1-maybe), carries the post-loop review's
improvements, and is fully verified as a whole. B6 (human sign-off) is
now: review `loop/integration` and merge IT — not the individual
branches. See the "Post-loop integration" cycle-log entry below for
the full evidence record and the two filed fuzzer signatures.
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
| A2 e2e flake stabilization | A | parked | 1 | loop/a2 | PARKED-BY-DEPENDENCY (cycle 30): the login-flake fix on the branch is real and merge-worthy, but the acceptance (3x full-suite green) is unreachable while parked A12 fires ~25-50% of full runs. Unblocks when the human decides A12 |
| A12 intent-log stale-base voids lose live edits | A | parked | 3 | loop/a12 | PARKED after 3 attempts (cycle 18) — see Parked section. Branch holds real, verified improvements worth merging |
| A13 de-rtc burst-eat recurrence under load | A | done | 1 | loop/a13 | verifier PASS (cycle 19); typing-quiet snapshot deferral. Base failed 3/10 under stress, branch 10/10 |
| A14 de-rtc session teardown hygiene | A | done | 1 | loop/a14 | verifier PASS (cycle 20); STACKED on loop/a13 — merge a13 then a14 |
| A3 websocket fixme re-enable | A | done | 1 | loop/a3 | verifier PASS (cycle 23) — real-daemon lane, suite 3x green retries=0. STACKED: merge a2 → a3 → a15 |
| A15 intent-log mid-burst remote pushes eat keystrokes | A | done | 1 | loop/a15 | verifier PASS (cycle 23): typing-quiet push gate + checkpoint interval 100→500 (live-authoring-sized). STACKED on loop/a3 |
| A8 full fuzzer matrix soak | A | parked | 0 | — | PARKED-BY-DEPENDENCY (cycle 30): the exit gate requires A1-A7 done incl. A2, which is parked on A12. Run `npm run fuzz` after sign-off merges; the harness and triage docs are ready |
| B1 yjs materialization (framework) | B | awaiting-human | 1 | loop/b1 | proposal ready (proposals/b1.md, cycle 27): _save mirror (ONE subtree file) + engine preference; e2e 54/55 (sole failure = parked A12 signature, unrelated); fuzz green. STACKED on loop/a4 |
| B3 pending-edit inline-card UI | B | awaiting-human | 1 | loop/b3 | proposal ready (proposals/b3.md, cycle 30); verifier PASS + addendum PASS. Inline merged Adopt/Reject cards, panel summary-only, de-rtc's first in-canvas anchoring (targetIndex). Branched from base (independent of the stacks) |
| B4 commit-cadence dial | B | awaiting-human | 1 | loop/b4 | proposal ready (proposals/b4.md, cycle 24): dial + soak A/B (−16% req, −29% up at dial 10). Default left 0 for the human. STACKED on loop/a14 |
| B5 review resolutions over REST | B | awaiting-human | 1 | loop/b5 | proposal ready (proposals/b5.md, cycle 28); verifier PASS. REST route + shared engine applier; legacy row path kept; client falls back on POST failure. STACKED on loop/b4 |

Status vocabulary: `queued`, `in-progress`, `verifying`, `done`
(verifier PASS recorded), `parked` (3 strikes or escalation trigger —
diagnosis required below), `awaiting-human` (Lane B proposal ready),
`blocked` (dependency not met).

## Parked / escalated

- **A12 (3 attempts, cycle 18).** Intent-log loses/mangles a live
  client's mid-burst edits when the room compacts UNDER the burst.
  Chain: coarse table captures write 3 rows per keystroke → the room
  crosses the 100-row checkpoint trim mid-burst → the client's
  in-flight intents void as stale-base while the same burst's later
  keystrokes land in a different frame → torn splices on the server
  and a diverged author canvas. Three layered fixes on loop/a12 (all
  Jest-pinned, suites green, each verified to help): (1) stale-void
  recovery re-captures the last editor-fed tree — closes the PEER-side
  silent loss and lets the server self-repair; (2) horizon resets with
  local canvas work recapture instead of clobbering the canvas;
  (3) the session DEFERS a horizon reset behind un-settled local work
  so server-visible frames never mix. The repetition hammer
  (`--repeat-each=8 -g "intent-log engine.*mix of block types"`) still
  fails ~4/8 with the same splice family. For the human: the branch is
  worth merging as-is (strictly better than base); the remaining
  schedule needs fresh per-build wire evidence, or attack the TRIGGER
  instead — batch coarse table captures (3 rows/keystroke is the row
  explosion driving mid-burst trims) or pace the checkpoint trim.

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

- **B1 — yjs materialization fidelity** (`loop/b1`, `proposals/b1.md`):
  every Y.Block carries a `_save` mirror (registered save() output,
  refreshed on attribute merges; one subtree file) and the engine
  prefers it over genesis wrappers — attribute-driven wrapper changes
  finally materialize; stale-text-proof pinned. Open questions: key
  naming, disable-flag for upstream, container edge cases.
- **B4 — de-rtc commit-cadence dial** (`loop/b4`, `proposals/b4.md`):
  settings field + client gate; soak A/B shows dial 10 cutting −16%
  requests / −29% upload per user-hour with full convergence. Open
  decisions: the default (0 vs 10), field visibility per engine, the
  300 s cap.
- **B5 — review resolutions over REST** (`loop/b5`, `proposals/b5.md`):
  the last mutating transport row moves to an authenticated route with
  transport-row fallback for old servers; legacy row path kept for old
  clients. Open questions: gate scope (commit-route types vs all),
  route namespace, legacy-row retirement timing.
- **B3 — pending-edit inline block cards** (`loop/b3`,
  `proposals/b3.md`): the prototype's decisions built — merged
  Adopt/Reject card per block (no chip), summary-only panel, de-rtc
  items anchoring in-canvas for the first time. Open questions:
  always-open card loudness, insertion-card vocabulary, merged
  resolution granularity, upstreaming shape.

## Cycle log

### Post-loop integration — 2026-08-20 — loop/integration built, verified whole, A2 met, A8 run
An independent post-loop review found the branches had only ever been
verified individually; per its findings (worked in order, at the
human's direction) `loop/integration` now exists, frozen at merge
commit 8ab1e26a66, ready for human review-and-merge.

**Branch contents.** All 16 branches merged in dependency order
(a6, a9, a1, a10, a4, a5, a7, a2→a3→a15, a13→a14→b4→b5, b1, b3,
v1-m1-maybe, and finally parked loop/a12's fixes). Two textual
conflicts (a1×a15 and integration×a12, both in
`src/engines/intent-log-manager.ts` — adjacent field insertions plus
one docblock seam) resolved keep-both. Integration-only commits:
- Positive B5 REST assertion in the de-rtc e2e walkthrough (the
  transport-row fallback could mask a broken route; this is the first
  tree where B3's cards and B5's REST lane coexist).
- Three phpcs violations visible only on the merged tree (branches
  based before A6's burn-down); `composer lint` clean again.
- CI: `composer lint` step, an e2e-websocket job (A3's lane ran
  nowhere in CI), and the subtree review-panel component Jest.
- B3 gaps closed: engine-level Jest for the contested→targetIndex
  mapping (real collision choreography through the session codec), and
  the de-rtc e2e conflict provocation made DETERMINISTIC (user two's
  sync polls held — never the commit lane — while user one's rewrite
  lands, forcing the stale-base proposal; decoded-URL matchers for
  plain-permalink envs).
- Proposal tweaks: B1's `_save` key hoisted to exported
  CRDT_BLOCK_SAVE_KEY; B4's cadence row hides live when the engine
  isn't de-rtc (hidden row, not disabled input — a disabled input
  drops from the POST and would reset the stored value); all four
  proposals stamped with recommended dispositions (pending sign-off).
- Docs sweep: AGENTS.md's websocket-lane section now describes A3's
  real-daemon lane; engine-comparison.md carries the 500-row
  intent-log checkpoint interval.
- TWO real integration bugs found and fixed by the whole-tree sweep:
  (1) full-suite PHPUnit failed — the storage's static post-id cache
  survives per-test DB rollbacks and the new test files changed class
  ordering; the WS transport suite now resets it in set_up like the
  polling suite always did; (2) the websocket e2e launcher's
  transport restore dies when Playwright SIGKILLs the webServer
  process group — a killed run left the site pinned to a daemon-less
  websocket transport and every later polling suite timed out at
  discovery (initially masquerading as an 8/8 A12-hammer failure).
  The launcher now persists the pre-suite selection to a state file
  and a globalTeardown replays the restore in the main process.

**Whole-tree verification (all on the frozen final tree).**
typecheck clean; Jest 537/537; subtree component Jest 11/11;
`composer lint` clean; PHPUnit 325/325; full default e2e 55/55
**three consecutive runs, retries=0** (13.1-13.7 min each);
e2e:websocket 1/1 against the real daemon (transport restore
verified); fuzz:quick 6/6.

**A2: acceptance MET** on the integration tree (the 3× retry-free
runs above). Recorded as evidence for the human — the loop's
maker-never-grader rule was not re-run for this (the whole branch is
under human review, which supersedes it).

**A12 re-measured** (the reviewer flagged that A15's checkpoint
interval change invalidated the old numbers): hammer
(`--repeat-each=8`, mix-of-block-types, intent-log) fails 1/8 WITHOUT
loop/a12's fixes and 2/8 WITH them (statistically noise at this
sample size) vs the recorded ~4/8 pre-A15. Signature unchanged
(a coarse-capture table-cell/quote edit voided stale-base). The a12
fixes are merged anyway — they close DIFFERENT sub-failures
(peer-side silent loss, reset clobbering), are Jest-pinned, and the
ledger already called them strictly better than base. The residual's
real fix stays the trigger: batch the 3-rows-per-keystroke coarse
captures (open, post-v1).

**A8 exit gate RUN: full fuzz matrix** (9 combos × 5 seeds, faults on
HTTP): intent-log 15/15; two signatures found and FILED, both
REPRODUCED IDENTICALLY ON THE BASE TREE (pre-existing, newly exposed
by seed/fault coverage the quick lane never ran — NOT integration
regressions):
1. `yjs-server seed 3` (all three transports, first-attempt fail,
   passes recheck): invalid-content recovery `core/group` with empty
   original after final reload. Replay:
   `npm run fuzz -- --combos=yjs-server/http-polling --seed-list=3`.
   Smells like the known genesis/isValid + container class; B1's
   container question is adjacent but NOT the cause (fails at base
   without B1).
2. `de-rtc seed 5` (both HTTP transports; faults enabled — websocket
   5/5): serialized state did not converge in 20 s (8 blocks, a
   `<marker>` paragraph). Reproduced on recheck in the matrix run but
   passed recheck in a standalone replay — fault-schedule-dependent.
   Replay: `npm run fuzz -- --combos=de-rtc/http-polling
   --seed-list=5 --steps=12 --trace=retain-on-failure`. Shrink was
   not possible (needs reproducibility).
   Per A8's rule these await explicit human acceptance (or new
   post-v1 items); the soak is otherwise green.

**For the human (B6).** Review and merge `loop/integration` (PR #35).
The per-branch merge orders in earlier entries are OBSOLETE — the
integration branch supersedes them.

**Decisions recorded (Chris, 2026-08-20):** all four Lane-B proposals
ACCEPTED per their recommended dispositions (stamped in
proposals/*.md); the loop/a12 merge KEPT; the M1 maybe-item ACCEPTED
into V1.md. Still open: accept-or-file for the two fuzzer signatures
above (yjs-server seed 3, de-rtc seed 5).

### Cycle 30 — 2026-08-20 — B3 proposal ready; LOOP COMPLETE
- Did: finished B3 on loop/b3 — subtree rebuilt on the branch, both
  engine e2e specs green in one run (29/29, no retries; includes the
  rewritten review-lane walkthroughs), fuzz:quick 6/6, proposals/b3.md
  written. Verifier: PASS, with two notes — (1) the executor's own e2e
  runs had used a bundle built before the plugin-side commit (the
  verifier rebuilt and independently reproduced everything green), and
  (2) the de-rtc spec never positively asserted its inline card
  rendered (silent panel-fallback could pass). Note 2 was closed the
  same cycle: the spec now asserts the card and its Reject verb are
  visible before resolving (5/5 green locally, incl. --repeat-each=4);
  the same verifier confirmed the addendum: PASS. Note 1 became moot
  with that re-run. Lesson (real): rebuild `build/sync-engines.js`
  after plugin src edits BEFORE browser-based evidence runs — Jest
  doesn't need it, e2e does.
- The conflict-provocation stage of the de-rtc review spec remains
  schedule-dependent (two local runs failed to escalate before the
  green streak) — a pre-existing trait of the racing-typists setup,
  absorbed by CI retries; recorded in proposals/b3.md.
- Queue state after B3: no queued items remain. A2 and A8 were
  reclassified blocked → PARKED-BY-DEPENDENCY (their acceptance is
  unreachable while A12 is parked; no cycle can advance them), so the
  completion condition holds: **loop COMPLETE**.

### FINAL SUMMARY (for B6 sign-off)
- 12 Lane-A items closed with verifier PASS: A1, A3, A4, A5, A6, A7,
  A9, A10, A13, A14, A15 (+ A11 closed-invalid with a corrected
  diagnosis). Parked: A12 (3 attempts, branch holds merge-worthy
  partial fixes), A2 and A8 (dependency-parked on A12).
- 4 Lane-B proposals awaiting review: B1 (yjs materialization,
  subtree), B4 (commit-cadence dial), B5 (REST review resolutions),
  B3 (inline pending-edit cards). Each has proposals/<id>.md with
  evidence and open questions.
- Merge orders (recorded across cycles): a6 before/with a9; a1
  before/with a10; a2 → a3 → a15 (stacked); a13 → a14 → b4 → b5
  (stacked); a4 → b1 (stacked); b3 independent of all stacks.
- Human decisions pending: A12 disposition (merge the partial fixes?
  attack the trigger?), the v1-m1-maybe V1.md addition, the four
  proposals' open questions, then A8 (full fuzz soak) as the exit
  gate after merges.

### Cycle 29 — 2026-08-20 — B3 implementation (wip on loop/b3)
- Preflight: the mapping-copy plugin activation trap had recurred (from
  cycle 28's PHPUnit/fuzz runs); fixed via the tests-env config
  (`wp-env --config .wp-env.tests.json run cli ...` — the doctor's
  suggested bare `wp-env run` targets the dev env in this worktree).
- Did: built B3 to the prototype's recorded decisions (V1.md B3 lists
  them; no richer prototype record exists — verified against
  gutenberg/prototypes/sync/). Subtree
  (collaboration-review-panel): the chip-and-expand ConflictMarker is
  now a directly-visible pending-edit card — ONE merged task per block,
  no count chip, verbs Adopt/Reject (requires-approval adoption stays
  capability-gated); the sidebar panel is a summary-only index
  (anchored groups: no verbs, navigate link; orphaned groups keep verbs
  so nothing is unresolvable; Discard-all retired). SyncReviewItem
  gains optional positional `targetIndex` (targetId wins). Plugin:
  de-rtc items now anchor (contests at their index, parked proposals at
  first changed block) — de-rtc previously NEVER had in-canvas review
  UI (no syncIds). Both engine e2e specs updated to the new surface.
- Evidence so far: subtree component Jest 11/11 (new block-card.js
  lifecycle suite: merged single verb pair, Adopt→restored all,
  Reject→dismissed all, local attribution, capability gate) and subtree
  lint clean; plugin Jest 526/526, typecheck clean, eslint clean.
- Lesson: don't `git checkout` away from a branch while a subtree
  build for that branch runs in the background — the build reads the
  switched working tree (killed and deferred to next cycle).
- Next: cycle 30 on loop/b3 — rebuild the subtree, run the two engine
  e2e specs + fuzz:quick, write proposals/b3.md, mark awaiting-human.

### Cycle 28 — 2026-08-20 — B5 proposal ready (awaiting human), verifier PASS
- Did: implemented the REST review lane on loop/b5 (stacked on b4).
  Server: extracted `apply_resolution()` shared by the legacy transport
  row path (behavior unchanged, kept for old clients) and a new public
  `resolve_proposal()`; new `WP_De_RTC_Review_Controller` registers
  POST `/wp-sync/v1/de-rtc/resolve` (edit_posts, engine-lineage 409
  fence, enum-validated resolution). Client: `setRestResolver()` on the
  review state; `resolve()` POSTs per id and falls back to the
  transport row only on rejection; the REST split follows the commit
  split (`hasDeRtcCommitRoute`) so collections keep the transport lane.
- Evidence: Jest 530/530 (2 new REST-lane tests; transport-lane tests
  unchanged on a non-commit-route type, pinning the split); PHPUnit
  317/317 (7 new route tests incl. resolvedBy stamp, idempotent
  double-resolve, 403/400/409); typecheck/eslint clean; phpcs zero
  net-new (one stranded docblock re-attached); build OK; fuzz:quick
  green 2/2 per engine.
- Verifier: PASS (base loop/b4). Notes: wire-trigger tripped BY DESIGN
  (this is the wire item, correctly held at proposal); `resolved` row
  payload byte-identical, route additive; its one evidence-gap note
  (dangling fuzz reference in the proposal) fixed in a follow-up commit
  on the branch (d8a9c93e).
- Open questions for the human recorded in proposals/b5.md: REST gate
  choice (commit-route types vs all), route namespace, legacy-row
  retirement timing.
- Next: cycle 29 claims B3 (pending-edit inline-card UI proposal), the
  last queued item.

### Cycles 26-27 — 2026-08-20 — B1 proposal ready (awaiting human)
- Did: evidence run + proposal. Full default e2e 54/55 retries=0 — all
  yjs-server specs green; the single failure is byte-for-byte parked
  A12's intent-log table-cell signature (this branch predates A12/A15
  and touches no intent-log code). fuzz:quick 2/2 per engine.
  proposals/b1.md written: design, why, legacy/mixed-session behavior,
  verification, the upstreaming note (constant naming, disable flag,
  the block.private bucket), and open questions.
- Ledger changes: B1 in-progress -> awaiting-human.

### Cycle 25 — 2026-08-20 — B1 built and unit-proven (proposal next)
- Did: the recorded design, both halves. SUBTREE (the one framework
  surface, core-data crdt-blocks.ts): every Y.Block carries `_save` —
  the block's registered save() output for its current attributes —
  written at creation, refreshed on attribute merges, excluded from
  block equality, stripped before blocks reach the editor. ENGINE:
  genesis decomposition extracted into decompose_inner_markup()
  (wrapper + A4's selector split, one source of truth) and
  to_serializable_block prefers `_save` over genesis wrappers/defaults;
  the mirror's embedded text is never read (live shared text wins —
  pinned by a stale-text PHPUnit test).
- Green: yjs suite 27/27 (round-trips intact through the refactor);
  full test:php 314; Jest 526; subtree rebuilt, exactly one tracked
  source file changed, no build side-effects.
- Next cycle: full e2e + fuzz:quick, proposals/b1.md (incl. the
  upstreaming note), mark awaiting-human.
- Lesson (shell): backticks inside a double-quoted fish commit message
  execute as command substitution and silently eat the quoted word —
  amended; use heredocs for commit messages with backticks.

### Cycle 24 — 2026-08-20 — B4 proposal ready (awaiting human)
- Did: implemented the commit-cadence dial end to end on loop/b4
  (stacked on loop/a14): a Settings → Collaboration field
  (`gutenberg_sync_engines_de_rtc_commit_interval`, 0-300 s, default
  0), plugin-owned script localization (framework announcement
  untouched), and a minimum-spacing gate in maybePropose downstream of
  every correctness guard, with one boundary timer and teardown
  cleanup. New Jest cadence test (immediate first commit; coalesced
  interim edits; one commit at the boundary); full Jest 528; phpcs
  clean.
- Evidence: 4-minute soak A/B on identical workloads — dial 10 vs 0:
  requests/user-hour 3675 vs 4350 (−16%), upload 4179 vs 5897 KB
  (−29%), download −14%, server dispatch ~par; both converged, zero
  skipped bursts. (V1.md's save-sync-session note interpreted in the
  proposal: the engine benchmark's scenario models cadence in-harness
  and cannot read a client dial; the browser A/B is the meaningful
  both-defaults run.)
- Ledger changes: B4 queued -> awaiting-human (proposals/b4.md).

### Cycle 23 — 2026-08-20 — A15 + A3 done (verifier PASS, one acceptance)
- Did: the instrumented hunt nailed the deterministic 48-of-60
  truncation in one traced run: all 60 keystrokes captured on both
  pages; the first ~48 dispositions applied; every later one voided
  stale-base on BOTH pages at once. Mechanism: the push gate pins each
  client's observed frame during the burst (it only advances past peer
  rows when a push confirms), and two concurrent typists burn ~2
  rows/keystroke — the room crossed the 100-row checkpoint trim
  mid-burst and the burst tails fell below the transform floor. Fix:
  intent-log's checkpoint interval default 100 → 500 (small JSON rows;
  bounded ≤1000 retained; filter unchanged; rationale in a code
  comment sized for live authoring frames, not just storage).
- Acceptance (both items, clean build): fixme grep empty; websocket
  suite (real daemon) 3x green retries=0 — verifier re-ran 3x green
  and demonstrated red-on-base (deterministic truncation on loop/a3
  without a15; the typing-delay change alone does not mask it); Jest
  527; sweep; full test:php 310; intent-log PHPUnit filter 40/40;
  fuzz:quick green.
- Verifier: PASS for both. Notes: all interval-dependent tests pin
  their own filters; __wpSyncWsState is browser-local observability,
  not wire.
- Ledger changes: A3 done, A15 done. Lane A now has no actionable
  items: A2 and A8 remain blocked on parked A12. Lane B next.

### Cycle 22 — 2026-08-20 — A15 (gate in; deterministic residual to hunt)
- Did: implemented the typing-quiet remote-push gate in the intent-log
  manager (onChange tail defers mid-burst arrivals to the settled
  editor sync; the sync timer re-checks hotness at fire time and
  reschedules while hot). New Jest regression test for the gate; the
  two echo-race tests flush the deferred push to keep their scenario;
  Jest 527, sweep, build green.
- WS suite: the variable keystroke losses became an EXACTLY-48-of-60
  truncation of both users' bursts, identical across 3 retries=0 runs.
  Interpretation: the canvas receives all 60 keystrokes (no more
  mid-burst remounts), the manager's captures stop at 48, and the
  final quiet push replaces the canvas with the 48-char document. A
  deterministic cutoff is a different, findable bug (a limit, not a
  race). Next cycle: console-marker + trace instrumentation of
  update()/capture during the WS run to see where keystrokes 49+ stop
  reaching the manager.
- Ledger changes: A15 queued -> in-progress (attempt 1, loop/a15,
  stacked on loop/a3).

### Cycle 21 — 2026-08-20 — A3 (real-daemon lane built; one flake from green)
- Did: replaced the y-websocket PEER-relay fixture lane with the REAL
  transport: a launcher (tests/e2e/bin/rtc-real-ws-daemon.mjs) selects
  the websocket transport on the tests site, runs the
  `wp collaboration sync-server` daemon with 8787 published, and
  restores everything at teardown; the config health-checks the
  daemon's own /health and clears stale port holders MAIN-PROCESS-ONLY
  (a Playwright worker reloading the config docker-rm'ed the live
  daemon mid-run — cost one diagnosis round). The websocket manager
  now publishes a `__wpSyncWsState` observability global, and the
  plugin-local fixtures' waitForSyncCycle waits on it under
  GUTENBERG_RTC_REAL_WS=1 (the subtree's wait knows only HTTP
  responses). test.fixme removed; typing tuned 1 ms -> 15 ms (the 1 ms
  assumption was relay-era local-first typing).
- Result: the full session lifecycle works over the real daemon —
  join, presence, typing, convergence. Residual: at speed a mid-burst
  remote push can remount the caret block and eat a keystroke at the
  canvas (1 char in 60 this run; both windows agree afterward, so the
  sync lane loses nothing). Filed as A15 (the A13 typing-quiet gate,
  applied to intent-log's remote-push path). A3 blocked on it.
- Branch: loop/a3 STACKED on loop/a2 (uses its fixtures module);
  merge order a2 then a3.
- Ledger changes: A3 queued -> in-progress (blocked on A15); A15 filed.

### Cycle 20 — 2026-08-20 — A14 done (verifier PASS)
- Did: the 5-line teardown cleanup on a branch STACKED on loop/a13
  (the fields live there): destroy() clears quietRetryTimer and drops
  deferredSnapshotRow, mirroring the commitRetryTimer block.
- Acceptance: typecheck clean; Jest 527. Verifier confirmed the diff
  is exactly the filing, verified against loop/a13 as base.
- Ledger changes: A14 queued -> done (1 commit). Next: A3.

### Cycle 19 — 2026-08-20 — A13 done (verifier PASS)
- Did: reproduced the burst collapse deterministically-enough with a
  6-way CPU stress + 8x repetition (3/8), then traced it with console
  markers in a Playwright trace: the victim window's doc-update feed
  goes SILENT right after its own proposal settles by hash mid-burst —
  the dirty/inFlight canonical deferral has a hole exactly one
  inter-keystroke gap wide, a snapshot applying in that window makes
  the framework push rewritten blocks, the caret block remounts, and
  the remaining keystrokes land in a detached node. Fix in
  src/engines/de-rtc/session.ts: canonical snapshots wait out a
  500 ms typing-quiet window (stashed newest-wins, re-injected through
  processRow at quiet; module-level window with a test-only setter).
  The announce suite pins the deferral; its same-tick cases run with
  the window at 0.
- Acceptance: stressed repetition 10/10 (verifier independently: base
  3 failed/7 passed with the exact signature, branch 10/10); full
  de-rtc e2e spec 5/5 retries=0; Jest 527; fuzz:quick green.
- Verifier: PASS. Filed its hygiene note as A14 (destroy() should
  clear the new timer/stash).
- Ledger changes: A13 queued -> done (1 commit); A14 filed.

### Cycle 18 — 2026-08-19 — A12 attempt 3; parked at 3 strikes
- Did: implemented the reset deferral (session buffers a horizon-reset
  snapshot and every row behind it while the outbox holds un-settled
  work; releases when dispositions drain it; manager skips stale-void
  recapture while deferred). Updated the session Jest test to pin the
  new contract (defer -> settle -> reset). Full Jest 527, sweep green.
  The 8x repetition still fails 4/8 with the same splice family —
  three attempts spent; parked per the 3-strike rule with the full
  diagnosis and a merge recommendation (the branch is strictly better
  than base).
- Ledger changes: A12 in-progress -> parked (3). A2 in-progress ->
  blocked (acceptance unreachable while A12 fires; A13 still pending).
  Next: A13.

### Cycle 17 — 2026-08-19 — A12 attempt 2 (tear root-caused; one attempt left)
- Did: capture-traced the failing schedule end to end. The full chain:
  the room compacts MID-BURST (the author's own coarse table captures
  write 3 rows per keystroke, crossing the 100-row trim), the session
  horizon-resets, the transport queue still carries pre-reset intents
  (voided stale-base — harmless), but the POST-reset keystrokes of the
  same burst capture incrementally against a baseline that does not
  match the canvas — their offsets apply TORN on the server (e.g.
  "Quoted texted by Bt") until the recapture repairs it; the author's
  canvas then fights the repair and can stall diverged. Extended the
  fix: onReset with local canvas work now recaptures the editor tree
  (shared scheduleTreeRecapture) instead of pushing the checkpoint doc
  over a live mid-burst canvas. Peers + server now always converge to
  clean content in observed runs; full Jest 527 green. The author-side
  tear persists 4/8 under the repetition hammer.
- Next (attempt 3, FINAL before parking per the 3-strike rule): defer
  applying a horizon-reset snapshot while the local typing burst is
  active (session-level buffering, non-frozen), so resets land on
  quiet clients and the existing re-derive contract holds.
- Ledger changes: A12 attempts 1 → 2.

### Cycle 16 — 2026-08-19 — A12 (recovery implemented; author-side residual)
- Did: manager now subscribes to session dispositions; on
  voided:stale-base it re-captures (one recovery per void burst) the
  last editor-fed block tree against the current document at the
  current seq. First attempt used core-data's getEditedRecord() — the
  browser trace showed the bridge's derive returning NULL wholesale
  for that block shape, so the recovery keeps a `lastEditorTree`
  reference from the ordinary update() feed instead. Includes the
  `const manager` refactor (byte-identical to loop/a1's, to ease the
  merge). Jest: regression test green; a shape-faithful table+quote
  scratch test re-authored set_attr x3 + replace_text.
- Result so far: the PEER-side silent loss is closed — in failing
  repetitions the second window now converges to the recovered
  content (the old first-assertion failure is gone). RESIDUAL (~2/8
  heavy repetitions): the AUTHOR's own canvas diverges — its cell
  edit reverts locally and the coarse replace_text splices at stale
  offsets ("Quoted texed by Bt") while peer + server hold clean
  content. Suspect: the recapture fires before the replica absorbs
  the post-trim rows, so the author's optimistic apply mispredicts
  and never reconciles. Resume: gate the recovery on catch-up (defer
  to the next clientReceive absorption), or force a re-bootstrap;
  then 8x repetition + full suites.
- Verifier: not yet requested (item mid-flight; wip committed).
- Ledger changes: A12 queued -> in-progress (attempt 1, loop/a12).

### Cycle 15 — 2026-08-19 — A2 run 4 (streak reset); A12 diagnosed and filed
- Did: run 4 came back 53/55 — the table-cell signature AGAIN plus a
  de-rtc "Second from two" → "Second " collapse. Repetition probes:
  de-rtc spec 6/6 solo (filed as A13, load-schedule-dependent); the
  table-cell spec 4-in-8 solo — reproducible. Ran the failing spec
  under `wp collaboration capture` and traced the lost edit end to
  end. DIAGNOSIS (A12): user B's cell edit authors a per-keystroke
  ladder of `set_attr(body)` intents at its observed seq (4). B's own
  earlier caption edit had burst ~75 rows into the log, pushing the
  room past the 100-row checkpoint+trim, so the retention horizon
  moved above 4 — the server voids the whole ladder as `stale-base`
  (engine line ~535). The engine's designed recovery ("the client
  re-derives from its editor tree after its reset") never fires: B's
  CURSOR was current, so no snapshot reset arrives — only voids. The
  session settles them, the replan drops the optimistic effect, and
  the manager pushes the REVERTED doc over B's canvas — the user
  watches their text vanish; nothing re-authors. Silent loss (P2
  violation) of edits made by a live, connected client. In passing
  runs the identical ladder lands before the trim.
- The fix direction (next cycle, non-frozen manager): on
  `voided:stale-base` dispositions, re-capture the CURRENT editor
  tree against the current document/seq (the A1 recovery pattern via
  handlers.getEditedRecord), instead of replan-and-revert. The frozen
  core needs no change (its contract already assumes a re-derive).
- Ledger changes: filed A12 + A13; A2 blocked on both (its 3-green
  streak cannot stand while a 4-in-8 loss bug fires). A2 stays
  in-progress; attempts unchanged.

### Cycle 14 — 2026-08-19 — A2 (first green run banked)
- Did: run 3 of the retry-free full suite: 55/55 GREEN in 13.1 min —
  counted green run 1 of 3 (runs count from the login fix onward).
  The table-cell signature did not recur (1 occurrence in 3 full
  runs). Launched counted run 2.
- Verifier: not yet requested.
- Ledger changes: none beyond status note.

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
