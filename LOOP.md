# LOOP.md — v1 loop ledger

Operational state for the v1 loop. `V1.md` is the frozen scope contract
(edit that only with the human); this file is working state — the
executor updates it every cycle and commits the update on the base
branch. Newest cycle-log entries go on top.

**Loop status:** NOT STARTED
**Base branch:** chriszarate/try-loop
**Current item:** none

## Queue

Ordered by intent: bank trust with low-risk, highly-verifiable items
first; the exit gate (A8) runs last. Lane B items may be worked any
time after the A-items above them; their end state is a proposal, never
a merge.

| Item | Lane | Status | Attempts | Branch | Notes |
| --- | --- | --- | --- | --- | --- |
| A7 websocket fencing coverage audit | A | queued | 0 | — | small; audit + missing regression tests |
| A6 phpcs burn-down | A | queued | 0 | — | one directory/sniff-family batch per cycle |
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

_No cycles yet._

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

_None yet._
